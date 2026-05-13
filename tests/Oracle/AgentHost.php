<?php

declare(strict_types=1);

namespace PhpJs\Tests\Oracle;

use PhpJs\BuiltIn\AtomicsObject;
use PhpJs\Engine;
use PhpJs\Value\JsArrayBuffer;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsSharedArrayBuffer;
use PhpJs\Value\JsString;
use PhpJs\Value\JsTypedArray;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

/**
 * Single-threaded cooperative simulation of the test262 `$262.agent` host.
 *
 * Real test262 hosts run each agent on a separate OS thread; this engine has
 * no preemption, so we approximate the behaviour with PHP Fibers:
 *
 *   - $262.agent.start(src)          captures worker source for later run
 *   - $262.agent.broadcast(buf)      starts each worker in its own Fiber and
 *                                    invokes its registered receiveBroadcast
 *                                    callback with the buffer. Each Fiber
 *                                    runs until it completes or hits
 *                                    Atomics.wait, at which point it
 *                                    suspends.
 *   - Atomics.wait                   when called from inside a worker fiber
 *                                    on a value that matches the expected
 *                                    word, suspends the fiber and waits to
 *                                    be resumed by Atomics.notify.
 *   - Atomics.notify                 wakes up the next N suspended fibers on
 *                                    the matching (buffer, index) and runs
 *                                    them until they next suspend or finish.
 *   - $262.agent.report(value)       enqueues a string for getReport.
 *   - $262.agent.getReport()         pops the next report (or null).
 *   - $262.agent.sleep(ms)           noop; we never block wall-clock.
 *
 * This covers the deterministic Atomics agent tests where:
 *
 *   - The main agent allocates a SharedArrayBuffer.
 *   - It spawns N workers that bump a "running" counter, then Atomics.wait.
 *   - It calls Atomics.notify or stores a different value to wake them.
 *   - It collects reports.
 *
 * It does not model preemption, fair scheduling, or wall-clock timeouts.
 * Finite-timeout waits resolve to "timed-out" only when no notify wakes them
 * before getReport drains the queue (we run any remaining workers to
 * completion at the start of getReport so their timed-out reports show up).
 */
final class AgentHost
{
    /** @var list<string> Pending agent source bodies, FIFO. */
    private array $pendingSources = [];

    /**
     * Active worker fibers paired with their receiveBroadcast callback. A
     * fiber is created in broadcast() but the callback may be registered
     * later from inside the fiber's body via $262.agent.receiveBroadcast,
     * so we store both: the fiber and the (initially null) callback.
     *
     * @var list<array{fiber: \Fiber, callback: ?JsFunction}>
     */
    private array $agents = [];

    /**
     * Suspended workers waiting on Atomics.wait. Each entry records the
     * shared buffer + word index + the fiber that is suspended.
     *
     * @var list<array{buffer: JsSharedArrayBuffer, index: int, fiber: \Fiber, timedOut: bool}>
     */
    private array $waiting = [];

    /**
     * Worker fibers cooperatively suspended inside a busy-spin Atomics.load
     * / compareExchange / exchange loop on a shared slot, waiting for any
     * write to (buffer, index) to wake them. Filled by onLoadSpin, drained
     * by onStoreNotify.
     *
     * @var list<array{buffer: JsSharedArrayBuffer, index: int, fiber: \Fiber}>
     */
    private array $spinSuspended = [];

    /**
     * Tracks the last value each worker fiber observed reading a given
     * (buffer, index) slot. A second consecutive load returning the same
     * value while inside the worker fiber is the signal that the fiber
     * is spinning, so onLoadSpin suspends it until a write fires
     * onStoreNotify for that slot.
     *
     * Keys: spl_object_id(fiber) . ':' . spl_object_id(buffer) . ':' . index
     * Values: serialised "last value" string (numeric or BigInt).
     *
     * @var array<string, string>
     */
    private array $lastLoadValue = [];

    /** @var list<string> FIFO of reports from $262.agent.report. */
    private array $reports = [];

    /**
     * The currently running worker fiber (or null when on the main agent).
     * Used by the Atomics.wait hook to decide whether to suspend.
     */
    private ?\Fiber $currentFiber = null;

    /** Track callback registration target per-fiber. */
    private ?\Fiber $callbackTarget = null;

    /**
     * Virtual monotonic clock value in milliseconds. We start at the
     * current wall time so absolute values look plausible, but advance
     * deterministically when a worker's wait times out so the post-wait
     * - pre-wait duration spans the requested timeout. Real wall time
     * cannot do this because our entire test runs in microseconds.
     */
    private float $virtualNowMs;

    /**
     * Pending suspended fibers' timeouts. When the host drains a worker
     * fiber via timeout, we advance virtualNowMs by the worker's
     * timeout so duration measurements reflect the simulated wait.
     *
     * @var \SplObjectStorage<\Fiber, float>
     */
    private \SplObjectStorage $fiberTimeouts;

    /**
     * Pending setTimeout entries on the virtual clock, ordered by fire time.
     * Used to satisfy the atomicsHelper.js getReportAsync polling loop, which
     * does setTimeout(loop, 1000) until getReport returns non-null. The
     * post-drain hook advances virtualNowMs to the earliest pending timer
     * and fires it once all microtasks have settled.
     *
     * The optional obsoleteCheck predicate is consulted by advance-time so
     * a stale Atomics.waitAsync timeout (whose promise already resolved via
     * notify) can be retired without advancing the clock.
     *
     * @var list<array{fireAt: float, seq: int, callback: JsFunction, obsoleteCheck?: \Closure(): bool}>
     */
    private array $timerQueue = [];

    private int $timerSeq = 0;

    public function __construct(private readonly Engine $engine)
    {
        $this->virtualNowMs = microtime(true) * 1000.0;
        $this->fiberTimeouts = new \SplObjectStorage();
    }

    /**
     * Install $262.agent and the cooperative Atomics hooks for this engine.
     */
    public function install(): void
    {
        $host = $this;

        // start(src): queue worker source for later broadcast.
        $startFn = JsFunction::fromCallable(
            'start',
            static function (JsValue $this_, array $args) use ($host): JsValue {
                $src = $args[0] ?? JsUndefined::instance();
                $srcStr = $src instanceof JsString ? $src->value : '';
                $host->pendingSources[] = $srcStr;
                return JsUndefined::instance();
            },
            1,
        );

        // broadcast(buffer, _int32): kick off pending workers.
        $broadcastFn = JsFunction::fromCallable(
            'broadcast',
            static function (JsValue $this_, array $args) use ($host): JsValue {
                $buf = $args[0] ?? JsUndefined::instance();
                $sab = self::resolveSharedBuffer($buf);
                if ($sab !== null) {
                    $host->doBroadcast($sab);
                }
                return JsUndefined::instance();
            },
            2,
        );

        // getReport(): pop next report (or null). When the report queue
        // is empty we first try to make forward progress: if the test
        // started workers without ever calling broadcast (some tests
        // have the worker allocate its own SharedArrayBuffer and report
        // directly), spawn them now. If that did not produce a report
        // either, drain any still-suspended worker fibers as
        // timed-out. We only drain when the queue is genuinely empty so
        // that notify+getReport pairs don't lose still-waiting workers.
        $getReportFn = JsFunction::fromCallable(
            'getReport',
            static function (JsValue $this_, array $args) use ($host): JsValue {
                if (count($host->reports) === 0 && count($host->pendingSources) > 0) {
                    $host->runStandalonePending();
                }
                if (count($host->reports) === 0) {
                    $host->drainTimedOutFibers();
                }
                if (count($host->reports) === 0) {
                    return \PhpJs\Value\JsNull::instance();
                }
                return new JsString((string) array_shift($host->reports));
            },
            0,
        );

        // sleep(ms): noop. We deliberately do NOT drain timed-out fibers
        // here, because atomicsHelper.js calls sleep() in spin loops
        // (e.g. tryYield, getReport's busy-poll) and prematurely waking
        // suspended worker fibers would resolve them as "timed-out"
        // before a subsequent Atomics.notify has a chance to wake them
        // as "ok". Real timeouts only fire from getReport() once we know
        // the main agent has finished issuing notify calls and is just
        // waiting on reports.
        $sleepFn = JsFunction::fromCallable(
            'sleep',
            static function (JsValue $this_, array $args): JsValue {
                return JsUndefined::instance();
            },
            1,
        );

        // monotonicNow(): use a virtual clock so that wait timeouts can
        // satisfy the duration assertions in no-spurious-wakeup tests
        // even though we never actually sleep.
        $monotonicFn = JsFunction::fromCallable(
            'monotonicNow',
            static function (JsValue $this_, array $args) use ($host): JsValue {
                return JsNumber::of($host->virtualNowMs);
            },
            0,
        );

        // leaving(): worker-side termination signal. Noop here.
        $leavingFn = JsFunction::fromCallable(
            'leaving',
            static function (JsValue $this_, array $args): JsValue {
                return JsUndefined::instance();
            },
            0,
        );

        // receiveBroadcast(cb): register a callback for the current worker.
        $receiveBroadcastFn = JsFunction::fromCallable(
            'receiveBroadcast',
            static function (JsValue $this_, array $args) use ($host): JsValue {
                $cb = $args[0] ?? JsUndefined::instance();
                if (!$cb instanceof JsFunction) {
                    return JsUndefined::instance();
                }
                // The currently running fiber is the worker whose body called
                // this. Attach the callback to that fiber's entry.
                $target = $host->callbackTarget;
                if ($target === null) {
                    return JsUndefined::instance();
                }
                foreach ($host->agents as $i => $entry) {
                    if ($entry['fiber'] === $target) {
                        $host->agents[$i]['callback'] = $cb;
                        break;
                    }
                }
                return JsUndefined::instance();
            },
            1,
        );

        // report(value): push a string to the report queue.
        $reportFn = JsFunction::fromCallable(
            'report',
            static function (JsValue $this_, array $args) use ($host): JsValue {
                $v = $args[0] ?? JsUndefined::instance();
                $s = $v instanceof JsString
                    ? $v->value
                    : \PhpJs\Spec\TypeConversion::toString($v);
                $host->reports[] = $s;
                return JsUndefined::instance();
            },
            1,
        );

        // Build the agent object with our methods.
        $agent = new JsObject();
        $agent->set('start', $startFn);
        $agent->set('broadcast', $broadcastFn);
        $agent->set('getReport', $getReportFn);
        $agent->set('sleep', $sleepFn);
        $agent->set('monotonicNow', $monotonicFn);
        $agent->set('leaving', $leavingFn);
        $agent->set('receiveBroadcast', $receiveBroadcastFn);
        $agent->set('report', $reportFn);

        // Install it onto the existing $262 object. We must read $262
        // directly from the global environment rather than via Engine::eval
        // (which would auto-convert it to a PHP array and drop the JsObject
        // identity we need to mutate).
        $existing = $this->engine->getGlobalEnv()->get('$262');
        if ($existing instanceof JsObject) {
            $existing->set('agent', $agent);
        }

        // Wire the cooperative Atomics hooks. These are installed for the
        // lifetime of this engine, so the AtomicsObject knows to defer the
        // current Atomics.wait inside a worker fiber and to wake fibers on
        // Atomics.notify.
        AtomicsObject::setSyncWaitHook(
            static fn(JsTypedArray $ta, int $i, float $t): ?JsValue => $host->onAtomicsWait($ta, $i, $t),
        );
        AtomicsObject::setSyncNotifyHook(
            static fn(JsArrayBuffer $b, int $i, int $c): int => $host->onAtomicsNotify($b, $i, $c),
        );
        AtomicsObject::setLoadSpinHook(
            static fn(JsTypedArray $ta, int $i, JsValue $v): ?JsValue => $host->onLoadSpin($ta, $i, $v),
        );
        AtomicsObject::setStoreNotifyHook(
            static function (JsTypedArray $ta, int $i) use ($host): void {
                $host->onStoreNotify($ta, $i);
            },
        );

        // Expose a virtual-clock setTimeout. atomicsHelper.js gates its
        // own polyfill on `this.setTimeout === undefined`, so installing a
        // real setTimeout bypasses the Promise-spin polyfill (which would
        // never make progress without an advancing Date.now).
        $setTimeoutFn = JsFunction::fromCallable(
            'setTimeout',
            static function (JsValue $this_, array $args) use ($host): JsValue {
                $cb = $args[0] ?? JsUndefined::instance();
                if (!$cb instanceof JsFunction) {
                    return JsNumber::of(0.0);
                }
                $delayArg = $args[1] ?? JsUndefined::instance();
                $delay = 0.0;
                if (!$delayArg instanceof JsUndefined) {
                    $n = \PhpJs\Spec\TypeConversion::toNumber($delayArg);
                    if (!is_nan($n) && $n > 0.0) {
                        $delay = $n;
                    }
                }
                // Per HTML spec the minimum clamp for setTimeout is 4ms once
                // a script has nested past five levels. We use 1ms uniformly
                // so a busy-poll setTimeout(fn, 0) still advances the virtual
                // clock — otherwise a poll loop deadlocks against any pending
                // longer-delay timer (e.g. Atomics.waitAsync(..., timeoutMs))
                // that needs the clock to march past its fireAt to fire.
                $delay = max($delay, 1.0);
                $id = ++$host->timerSeq;
                $host->timerQueue[] = [
                    'fireAt' => $host->virtualNowMs + $delay,
                    'seq' => $id,
                    'callback' => $cb,
                ];
                return JsNumber::of((float) $id);
            },
            2,
        );
        $clearTimeoutFn = JsFunction::fromCallable(
            'clearTimeout',
            static function (JsValue $this_, array $args) use ($host): JsValue {
                $idArg = $args[0] ?? JsUndefined::instance();
                if (!$idArg instanceof JsNumber) {
                    return JsUndefined::instance();
                }
                $id = (int) $idArg->value;
                $host->timerQueue = array_values(array_filter(
                    $host->timerQueue,
                    static fn(array $e): bool => $e['seq'] !== $id,
                ));
                return JsUndefined::instance();
            },
            1,
        );
        $env = $this->engine->getGlobalEnv();
        if ($env->has('setTimeout')) {
            $env->set('setTimeout', $setTimeoutFn, false);
        } else {
            $env->defineVar('setTimeout', $setTimeoutFn);
        }
        if ($env->has('clearTimeout')) {
            $env->set('clearTimeout', $clearTimeoutFn, false);
        } else {
            $env->defineVar('clearTimeout', $clearTimeoutFn);
        }

        // Atomics.waitAsync timeout: schedule the promise resolution on
        // the virtual clock so a same-turn Atomics.notify still has a
        // chance to wake the waiter before the timeout fires.
        AtomicsObject::setWaitAsyncTimeoutHook(
            static function (\PhpJs\Value\JsPromise $promise, float $timeoutMs) use ($host): void {
                $id = ++$host->timerSeq;
                $host->timerQueue[] = [
                    'fireAt' => $host->virtualNowMs + $timeoutMs,
                    'seq' => $id,
                    'callback' => JsFunction::fromCallable(
                        '<waitAsync-timeout>',
                        static function (JsValue $this_, array $args) use ($promise): JsValue {
                            if ($promise->getState() === \PhpJs\Value\JsPromise::STATE_PENDING) {
                                AtomicsObject::dropWaitAsyncEntry($promise);
                                $promise->resolve(new JsString('timed-out'));
                            }
                            return JsUndefined::instance();
                        },
                        0,
                    ),
                    // If a matching Atomics.notify already resolved the
                    // promise, the timer is a no-op. Drop it so the
                    // virtual clock doesn't advance past the original
                    // fireAt when there's nothing to dispatch.
                    'obsoleteCheck' => static function () use ($promise): bool {
                        return $promise->getState() !== \PhpJs\Value\JsPromise::STATE_PENDING;
                    },
                ];
            },
        );

        // Install a post-drain hook so once the microtask queue settles,
        // we advance the virtual clock to the next setTimeout and run any
        // workers still suspended on Atomics.wait (resolving them as
        // "timed-out" only when no more pending work — broadcasts, async
        // continuations — could resume them). Returns true when more work
        // was scheduled so the JsPromise drain loop keeps going.
        \PhpJs\Value\JsPromise::setPostDrainHook(static function () use ($host): bool {
            return $host->advanceVirtualClock();
        });
    }

    /**
     * Clear the global hooks. Idempotent — safe to call from a teardown
     * even if install() did not run.
     */
    public function uninstall(): void
    {
        AtomicsObject::setSyncWaitHook(null);
        AtomicsObject::setSyncNotifyHook(null);
        AtomicsObject::setWaitAsyncTimeoutHook(null);
        AtomicsObject::setLoadSpinHook(null);
        AtomicsObject::setStoreNotifyHook(null);
        \PhpJs\Value\JsPromise::setPostDrainHook(null);
        $this->pendingSources = [];
        $this->agents = [];
        $this->waiting = [];
        $this->spinSuspended = [];
        $this->lastLoadValue = [];
        $this->reports = [];
        $this->currentFiber = null;
        $this->callbackTarget = null;
        $this->fiberTimeouts = new \SplObjectStorage();
        $this->timerQueue = [];
    }

    /**
     * Hook called by AtomicsObject::waitFn after the value-match check.
     *
     * Return:
     *   - JsString "ok" / "timed-out" when this wait has been simulated.
     *   - null when the caller should fall through to the default
     *     "cannot suspend" TypeError (e.g. wait invoked from the main
     *     agent rather than from inside a worker fiber).
     */
    public function onAtomicsWait(JsTypedArray $ta, int $index, float $timeoutMs): ?JsValue
    {
        $fiber = $this->currentFiber;
        if ($fiber === null) {
            return null;
        }
        $buffer = $ta->getBuffer();
        if (!$buffer instanceof JsSharedArrayBuffer) {
            return null;
        }

        // Zero or negative timeout: don't suspend, return "timed-out".
        if (!is_nan($timeoutMs) && $timeoutMs <= 0.0) {
            return new JsString('timed-out');
        }

        // Record timeout (finite values only) so drainTimedOutFibers can
        // advance our virtual clock by the expected duration.
        if (!is_nan($timeoutMs) && is_finite($timeoutMs) && $timeoutMs > 0.0) {
            $this->fiberTimeouts[$fiber] = $timeoutMs;
        }

        // Register this fiber as waiting.
        $this->waiting[] = [
            'buffer' => $buffer,
            'index' => $index,
            'fiber' => $fiber,
            'timedOut' => false,
        ];

        // Suspend the worker. Resume returns "ok" (notified) or "timed-out".
        $resumed = \Fiber::suspend();
        if (is_string($resumed)) {
            return new JsString($resumed);
        }
        return new JsString('timed-out');
    }

    /**
     * Hook called by AtomicsObject::notifyFn to wake suspended workers.
     *
     * Returns the number of additional waiters woken (added to the
     * Atomics.waitAsync count handled in AtomicsObject itself).
     */
    public function onAtomicsNotify(JsArrayBuffer $buf, int $index, int $count): int
    {
        if (!$buf instanceof JsSharedArrayBuffer) {
            return 0;
        }
        $woken = 0;
        $remaining = [];
        foreach ($this->waiting as $entry) {
            if (
                $woken < $count
                && $entry['buffer'] === $buf
                && $entry['index'] === $index
            ) {
                $woken++;
                $fiber = $entry['fiber'];
                // Clear timeout tracking since this fiber was woken, not
                // timed out. virtualNowMs is therefore not advanced for
                // notified workers.
                if (isset($this->fiberTimeouts[$fiber])) {
                    unset($this->fiberTimeouts[$fiber]);
                }
                $this->resumeFiber($fiber, 'ok');
                continue;
            }
            $remaining[] = $entry;
        }
        $this->waiting = $remaining;
        return $woken;
    }

    /**
     * Hook called by AtomicsObject::loadFn / compareExchangeFn /
     * exchangeFn after reading the value from a shared slot. When called
     * from inside a worker fiber and a second consecutive load on the
     * same (buffer, index) returns the same value, suspend the fiber
     * until onStoreNotify fires for that slot. The returned value is the
     * value the caller should observe (either the freshly read value
     * passed in, or the post-resume value).
     */
    public function onLoadSpin(JsTypedArray $ta, int $index, JsValue $value): ?JsValue
    {
        $fiber = $this->currentFiber;
        if ($fiber === null) {
            return null;
        }
        $buffer = $ta->getBuffer();
        if (!$buffer instanceof JsSharedArrayBuffer) {
            return null;
        }

        $key = spl_object_id($fiber) . ':' . spl_object_id($buffer) . ':' . $index;
        $serial = self::serialiseAtomicValue($value);
        $previous = $this->lastLoadValue[$key] ?? null;

        if ($previous !== $serial) {
            // First load on this slot (or value differs from last load):
            // record + return as-is. The caller's loop will either exit
            // or come back here, at which point the second consecutive
            // identical load triggers the suspend below.
            $this->lastLoadValue[$key] = $serial;
            return null;
        }

        // Same value as last time: assume a busy spin and suspend. Do NOT
        // shortcut the loop by returning a different value — the caller's
        // operation already wrote (or skipped writing) before the hook
        // fired, so we have to let the loop iterate again so it can call
        // the atomic op afresh now that another agent has updated the
        // slot. We therefore return the originally-observed value and
        // clear the lastLoadValue tracker so the next iteration's first
        // call re-records.
        $this->spinSuspended[] = [
            'buffer' => $buffer,
            'index' => $index,
            'fiber' => $fiber,
        ];
        unset($this->lastLoadValue[$key]);
        \Fiber::suspend();
        return null;
    }

    /**
     * Hook called by AtomicsObject after every write to a shared slot.
     * Wakes any worker fibers spin-suspended on the same (buffer, index).
     */
    public function onStoreNotify(JsTypedArray $ta, int $index): void
    {
        $buffer = $ta->getBuffer();
        if (!$buffer instanceof JsSharedArrayBuffer) {
            return;
        }
        if (count($this->spinSuspended) === 0) {
            return;
        }
        $remaining = [];
        $toResume = [];
        foreach ($this->spinSuspended as $entry) {
            if ($entry['buffer'] === $buffer && $entry['index'] === $index) {
                $toResume[] = $entry['fiber'];
                continue;
            }
            $remaining[] = $entry;
        }
        $this->spinSuspended = $remaining;
        foreach ($toResume as $fiber) {
            $this->runFiber($fiber, null);
        }
    }

    /**
     * Stringify an atomic slot value for fiber-load deduplication. Plain
     * numbers and BigInts both reduce to a stable string.
     */
    private static function serialiseAtomicValue(JsValue $value): string
    {
        if ($value instanceof JsNumber) {
            return 'n:' . $value->value;
        }
        if ($value instanceof \PhpJs\Value\JsBigInt) {
            return 'b:' . $value->value;
        }
        return 'o:' . spl_object_id($value);
    }

    /**
     * Resolve the argument passed to $262.agent.broadcast into the shared
     * buffer the workers should see. Accepts either the SharedArrayBuffer
     * directly or an Int32Array / BigInt64Array view over one (the
     * atomicsHelper.js safeBroadcast wrapper hands us the view).
     */
    private static function resolveSharedBuffer(JsValue $arg): ?JsSharedArrayBuffer
    {
        if ($arg instanceof JsSharedArrayBuffer) {
            return $arg;
        }
        if ($arg instanceof JsTypedArray) {
            $buf = $arg->getBuffer();
            if ($buf instanceof JsSharedArrayBuffer) {
                return $buf;
            }
        }
        return null;
    }

    /**
     * Drain any pending worker sources that didn't go through a
     * broadcast. The worker is expected to allocate its own
     * SharedArrayBuffer (or report directly without using one).
     */
    public function runStandalonePending(): void
    {
        if (count($this->pendingSources) === 0) {
            return;
        }
        $sources = $this->pendingSources;
        $this->pendingSources = [];

        foreach ($sources as $src) {
            $engine = $this->engine;
            $fiber = new \Fiber(static function () use ($engine, $src): void {
                $engine->eval($src);
            });
            $this->agents[] = ['fiber' => $fiber, 'callback' => null];
            $this->runFiber($fiber, null);
        }
    }

    /**
     * Start each pending worker in its own fiber and run it until it
     * either suspends on Atomics.wait or completes.
     */
    private function doBroadcast(JsSharedArrayBuffer $sab): void
    {
        $sources = $this->pendingSources;
        $this->pendingSources = [];

        foreach ($sources as $src) {
            $host = $this;
            $engine = $this->engine;
            $fiber = new \Fiber(static function () use ($engine, $src, $host, $sab): void {
                // Evaluate the worker source. Inside it the worker will
                // call $262.agent.receiveBroadcast(fn) which registers a
                // callback on $host->callbackTarget (the current fiber).
                // After the source returns, we invoke that callback with
                // the shared buffer.
                $engine->eval($src);
                $cb = $host->getCurrentCallback();
                if ($cb instanceof JsFunction) {
                    $cb->call(JsUndefined::instance(), [$sab]);
                }
            });
            $this->agents[] = ['fiber' => $fiber, 'callback' => null];

            // The receiveBroadcast handler needs to know which fiber is
            // currently executing in order to attach the callback. We set
            // both currentFiber (used by Atomics.wait) and callbackTarget
            // (used by receiveBroadcast) to this fiber, then start it.
            $this->runFiber($fiber, null);
        }
    }

    /**
     * Start or resume a fiber with worker-context bookkeeping. When the
     * fiber suspends or finishes, the main agent context is restored.
     */
    private function runFiber(\Fiber $fiber, ?string $resumeValue): void
    {
        $prevFiber = $this->currentFiber;
        $prevTarget = $this->callbackTarget;
        $this->currentFiber = $fiber;
        $this->callbackTarget = $fiber;
        try {
            if (!$fiber->isStarted()) {
                $fiber->start();
            } elseif ($fiber->isSuspended()) {
                $fiber->resume($resumeValue);
            }
        } catch (\Throwable $e) {
            // Worker threw uncaught: silently swallow. The main agent's
            // assertions on the report queue will fail the test naturally.
        } finally {
            $this->currentFiber = $prevFiber;
            $this->callbackTarget = $prevTarget;
        }
    }

    /**
     * Resume a previously suspended fiber, signalling its wait result.
     */
    private function resumeFiber(\Fiber $fiber, string $resumeValue): void
    {
        $this->runFiber($fiber, $resumeValue);
    }

    /**
     * Time out any still-suspended worker fibers. Called from getReport so
     * tests that expect "timed-out" results eventually receive them, and
     * from sleep().
     */
    private function drainTimedOutFibers(): void
    {
        // First, surface any worker fibers still parked in a busy-spin
        // load by resuming them so they observe the latest value. This
        // lets a worker that's spinning while main has run out of writes
        // make forward progress (e.g. by hitting an Atomics.wait that
        // then gets resolved via the timeout path below).
        if (count($this->spinSuspended) > 0) {
            $entries = $this->spinSuspended;
            $this->spinSuspended = [];
            foreach ($entries as $entry) {
                $this->runFiber($entry['fiber'], null);
            }
        }
        if (count($this->waiting) === 0) {
            return;
        }
        $entries = $this->waiting;
        $this->waiting = [];
        // Advance the virtual clock by the longest pending timeout so any
        // duration measurements the worker performs after wait() satisfy
        // their (lapse >= TIMEOUT) assertions. If multiple fibers waited
        // with different timeouts, the conservative move is to take the
        // max — any individual fiber's reported duration will still be
        // >= its own timeout.
        $maxTimeout = 0.0;
        foreach ($entries as $entry) {
            $fiber = $entry['fiber'];
            if (isset($this->fiberTimeouts[$fiber])) {
                $maxTimeout = max($maxTimeout, $this->fiberTimeouts[$fiber]);
                unset($this->fiberTimeouts[$fiber]);
            }
        }
        if ($maxTimeout > 0.0) {
            $this->virtualNowMs += $maxTimeout;
        }
        foreach ($entries as $entry) {
            $this->resumeFiber($entry['fiber'], 'timed-out');
        }
    }

    /**
     * Return the callback registered by the currently running fiber, if
     * any.
     */
    public function getCurrentCallback(): ?JsFunction
    {
        $target = $this->callbackTarget;
        if ($target === null) {
            return null;
        }
        foreach ($this->agents as $entry) {
            if ($entry['fiber'] === $target) {
                return $entry['callback'];
            }
        }
        return null;
    }

    /**
     * Post-microtask-drain step: if any virtual timers are due, advance
     * the clock to the earliest one and fire it. Returns true when a
     * timer fired (i.e. more microtasks may have been scheduled), false
     * when there is no remaining work.
     *
     * Atomics.waitAsync's "value matches but pending" path stays parked
     * here too: with no timer pending and the report queue empty, drain
     * any still-suspended worker fibers as timed-out so async tests that
     * expected a finite-timeout resolution complete instead of hanging.
     */
    public function advanceVirtualClock(): bool
    {
        if (count($this->timerQueue) === 0) {
            return false;
        }
        // Only advance the clock from the outermost drain. Nested drains
        // (e.g. resolveAwaitedValue invoked from inside an async-arrow's
        // synchronous unwind path, or any other inner Engine::eval) are
        // intermediate steps in the same logical "turn"; firing a timer
        // there would race with code that's about to schedule more
        // microtasks once the inner call returns. Concretely, the
        // Atomics.waitAsync timeout we register on a worker fiber must
        // not fire before the main agent's notify gets a chance to run.
        if (\Fiber::getCurrent() !== null) {
            return false;
        }
        // Sort by fireAt ascending, stable by sequence.
        usort($this->timerQueue, static function (array $a, array $b): int {
            if ($a['fireAt'] === $b['fireAt']) {
                return $a['seq'] <=> $b['seq'];
            }
            return $a['fireAt'] <=> $b['fireAt'];
        });
        // Skip timers whose `obsoleteCheck` predicate (if set) reports
        // that the timer no longer needs to fire. This lets the
        // Atomics.waitAsync timeout shim retire its entry once the
        // promise has been resolved by a notify, without advancing the
        // virtual clock as a side effect. Walk the head of the queue
        // until we find a live timer or the queue is empty.
        while (count($this->timerQueue) > 0) {
            $head = $this->timerQueue[0];
            $obsolete = isset($head['obsoleteCheck'])
                && ($head['obsoleteCheck'])();
            if ($obsolete) {
                array_shift($this->timerQueue);
                continue;
            }
            break;
        }
        if (count($this->timerQueue) === 0) {
            return false;
        }
        $entry = array_shift($this->timerQueue);
        if ($entry['fireAt'] > $this->virtualNowMs) {
            $this->virtualNowMs = $entry['fireAt'];
        }
        try {
            $entry['callback']->call(JsUndefined::instance(), []);
        } catch (\Throwable) {
            // Best-effort: swallow timer-callback errors so a single
            // misbehaving timer doesn't tank the whole drain loop.
        }
        return true;
    }
}
