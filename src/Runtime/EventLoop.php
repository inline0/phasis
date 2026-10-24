<?php

declare(strict_types=1);

namespace Phasis\Runtime;

use Phasis\Engine;
use Phasis\Exceptions\JsThrowable;
use Phasis\Value\JsFunction;
use Phasis\Value\JsPromise;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * JavaScript event-loop driver — the macrotask side. Microtasks
 * (Promise resolutions, queueMicrotask) continue to live on
 * `JsPromise` and flow through `JsPromise::drainMicrotasks()`. The
 * EventLoop layers timers (setTimeout / setInterval) on top:
 *
 *   1. The owning Engine registers `tickDueTasks()` as JsPromise's
 *      post-drain hook. So every time the microtask queue empties,
 *      the loop checks the timer queue for tasks whose deadline has
 *      already passed and fires them. Each fired task usually
 *      schedules more microtasks, which JsPromise then drains before
 *      checking the hook again. This matches the HTML spec's
 *      "event loop iteration" shape.
 *
 *   2. `runUntilEmpty()` is the embedder-facing "drain everything"
 *      call. It loops: drain microtasks → fire all due timers → if
 *      any timer remains in the queue, advance the virtual clock to
 *      that timer's deadline and repeat. This is what test runners
 *      want — `setTimeout(cb, 5000)` resolves instantly because the
 *      virtual clock jumps. Real-time embedders use `tick()` instead
 *      and drive their own scheduling.
 *
 * Time model: the loop holds a `nowMs` cursor. By default it tracks
 * wall-clock (microtime); `runUntilEmpty()` may advance it past wall
 * time to drain pending timers. Tasks are stored in an array indexed
 * by id; firing scans for the earliest due task. The array is small
 * in practice (almost no JS code schedules more than a handful of
 * timers at once), so a heap is unnecessary.
 */
final class EventLoop
{
    /** @var array<int, EventLoopTask> */
    private array $tasks = [];

    /** @var array<int, EventLoopTask> */
    private array $immediates = [];

    private int $nextId = 1;

    /**
     * Current virtual time in milliseconds. Initialized to wall time
     * and re-synced on each tick() so wall-clock deadlines are
     * meaningful; advanced past wall time only during runUntilEmpty()
     * when the queue would otherwise stall.
     */
    private float $nowMs;

    /** Whether timers fire; while off, setTimeout and setInterval still return ids but never run. */
    private bool $timersEnabled = true;

    /** @var ?\Closure(int): bool */
    private ?\Closure $ioPoll = null;

    /** @var ?\Closure(): bool */
    private ?\Closure $ioReferences = null;

    /** @var array<string, array{0: \Closure(int): bool, 1: \Closure(): bool}> */
    private array $ioDrivers = [];

    private bool $realTime = false;

    /** Install the realm's nonblocking I/O driver. The timeout is in milliseconds. */
    public function setIoDriver(?\Closure $poll, ?\Closure $hasReferences): void
    {
        if ($poll === null || $hasReferences === null) {
            $this->removeIoDriver('default');
        } else {
            $this->addIoDriver('default', $poll, $hasReferences);
        }
    }

    /**
     * Register a host resource source alongside sockets and other drivers.
     * @param \Closure(int): bool $poll
     * @param \Closure(): bool $hasReferences
     */
    public function addIoDriver(string $name, \Closure $poll, \Closure $hasReferences): void
    {
        $this->ioDrivers[$name] = [$poll, $hasReferences];
        $this->ioReferences = function (): bool {
            foreach ($this->ioDrivers as [, $hasReferences]) {
                if ($hasReferences()) {
                    return true;
                }
            }
            return false;
        };
        $this->ioPoll = function (int $timeout): bool {
            $progress = false;
            foreach ($this->ioDrivers as [$poll]) {
                $progress = $poll(0) || $progress;
            }
            if (!$progress && $timeout > 0) {
                $first = array_key_first($this->ioDrivers);
                if ($first !== null) {
                    $progress = ($this->ioDrivers[$first][0])($timeout);
                    foreach ($this->ioDrivers as $key => [$poll]) {
                        if ($key !== $first) {
                            $progress = $poll(0) || $progress;
                        }
                    }
                }
            }
            return $progress;
        };
    }

    /** Unregister a driver once its owning host resource group closes. */
    public function removeIoDriver(string $name): void
    {
        unset($this->ioDrivers[$name]);
        if ($this->ioDrivers === []) {
            $this->ioPoll = null;
            $this->ioReferences = null;
        }
    }

    /** Node timers follow elapsed host time; the default ECMAScript runner uses virtual time. */
    public function setRealTime(bool $enabled): void
    {
        $this->realTime = $enabled;
        $this->nowMs = self::wallTimeMs();
    }

    public function setTimersEnabled(bool $enabled): void
    {
        $this->timersEnabled = $enabled;
    }

    public function __construct(private readonly Engine $engine)
    {
        $this->nowMs = self::wallTimeMs();
        JsPromise::setPostDrainHook(function (): bool {
            // Node macrotasks run in runUntilEmpty's I/O phases. Draining
            // newly due timers here can starve ready sockets indefinitely.
            return !$this->realTime && $this->fireDueTasks();
        });
    }

    /** Detach our post-drain hook. Called on Engine::reset(). */
    public function detach(): void
    {
        JsPromise::setPostDrainHook(null);
        $this->ioDrivers = [];
        $this->ioPoll = $this->ioReferences = null;
    }

    /**
     * Schedule a one-shot task. Returns an opaque id usable with clear().
     *
     * @param list<\Phasis\Value\JsValue> $callbackArgs Extra args from
     *        `setTimeout(cb, delay, ...args)` forwarded to the callback.
     */
    public function setTimeout(JsFunction $cb, float $delayMs, array $callbackArgs = []): int
    {
        $this->advanceClock();
        // Per spec WindowOrWorkerGlobalScope §timers: negative or NaN
        // delays clamp to zero. Sub-millisecond floats are honored.
        $delay = $delayMs > 0 ? $delayMs : 0.0;
        $id = $this->nextId++;
        $this->tasks[$id] = new EventLoopTask(
            id: $id,
            deadlineMs: $this->nowMs + $delay,
            callback: $cb,
            repeating: false,
            intervalMs: 0.0,
            contextSnapshot: AsyncContextStorage::active()->snapshot(),
            callbackArgs: $callbackArgs,
        );
        return $id;
    }

    /**
     * Schedule a repeating task.
     *
     * @param list<\Phasis\Value\JsValue> $callbackArgs Extra args from
     *        `setInterval(cb, delay, ...args)` forwarded on every firing.
     */
    public function setInterval(JsFunction $cb, float $intervalMs, array $callbackArgs = []): int
    {
        $this->advanceClock();
        // Per HTML spec, intervals < 4ms clamp to 4ms once the same
        // page has nested 5 deep, but for a non-browser engine the
        // minimum-of-1ms rule from Node is the practical guard. We
        // keep it permissive — sub-ms intervals just fire every tick.
        $interval = $intervalMs > 0 ? $intervalMs : 0.0;
        $id = $this->nextId++;
        $this->tasks[$id] = new EventLoopTask(
            id: $id,
            deadlineMs: $this->nowMs + $interval,
            callback: $cb,
            repeating: true,
            intervalMs: $interval,
            contextSnapshot: AsyncContextStorage::active()->snapshot(),
            callbackArgs: $callbackArgs,
        );
        return $id;
    }

    /** Schedule a Node check-phase callback, after the next I/O poll. */
    public function setImmediate(JsFunction $callback): int
    {
        $id = $this->nextId++;
        $this->immediates[$id] = new EventLoopTask($id, 0, $callback, false, 0, AsyncContextStorage::active()->snapshot());
        return $id;
    }

    /** Control whether a timer keeps the Node event loop alive. */
    public function reference(int $id, bool $referenced): void
    {
        $task = $this->tasks[$id] ?? $this->immediates[$id] ?? null;
        if ($task !== null) {
            $task->referenced = $referenced;
        }
    }

    private function fireImmediate(): bool
    {
        $key = array_key_first($this->immediates);
        if ($key === null) {
            return false;
        }
        $task = $this->immediates[$key];
        unset($this->immediates[$key]);
        $this->invoke($task);
        return true;
    }

    private function hasTimerReferences(): bool
    {
        foreach ([$this->tasks, $this->immediates] as $queue) {
            foreach ($queue as $task) {
                if ($task->referenced && !$task->cancelled) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Cancel a scheduled task. Returns true if the task was pending,
     * false if the id was unknown or the task already cancelled.
     */
    public function clear(int $id): bool
    {
        $task = $this->tasks[$id] ?? $this->immediates[$id] ?? null;
        if ($task === null || $task->cancelled) {
            return false;
        }
        $task->cancelled = true;
        unset($this->tasks[$id], $this->immediates[$id]);
        return true;
    }

    /** Cancel all tasks when the owning realm closes or resets. */
    public function clearAll(): void
    {
        foreach ([$this->tasks, $this->immediates] as $queue) {
            foreach ($queue as $task) {
                $task->cancelled = true;
            }
        }
        $this->tasks = $this->immediates = [];
    }

    /**
     * Fire one task whose deadline is at or before the current
     * virtual clock. Returns true if at least one task fired (signal
     * to the microtask drain loop that more work may now be pending).
     */
    public function fireDueTasks(): bool
    {
        if (!$this->timersEnabled) {
            return false;
        }
        if ($this->tasks === []) {
            return false;
        }
        if ($this->realTime && !$this->hasTimerReferences() && !($this->ioReferences !== null && ($this->ioReferences)())) {
            return false;
        }
        $this->advanceClock();
        $task = $this->peekEarliestDue();
        if ($task === null) {
            return false;
        }
        // A microtask checkpoint is required between callbacks, including
        // timers scheduled by another timer. Otherwise polling loops can
        // starve the Promise continuation that would terminate them.
        $this->invoke($task);
        if ($task->cancelled || !$task->repeating) {
            unset($this->tasks[$task->id]);
        } else {
            $task->deadlineMs = $this->nowMs + $task->intervalMs;
        }
        return true;
    }

    /**
     * Drain microtasks + macrotasks until both queues are empty. When
     * timers remain but their deadlines are in the future, advance
     * the virtual clock to the earliest deadline and continue. This
     * is the "run my program to completion" entry point.
     *
     * @param int $maxIters Safety cap on full microtask+timer cycles.
     */
    public function runUntilEmpty(int $maxIters = 100_000): void
    {
        for ($i = 0; $this->realTime || $i < $maxIters; $i++) {
            JsPromise::drainMicrotasks();
            $hasIo = $this->ioReferences !== null && ($this->ioReferences)();
            $hasTimers = $this->timersEnabled && ($this->realTime ? $this->hasTimerReferences() : $this->tasks !== []);
            if (!$hasIo && !$hasTimers) {
                return;
            }
            if ($this->realTime || $hasIo) {
                $this->engine->getInterpreter()->checkBudget();
                $delay = $hasTimers ? max(0, (int) ceil($this->nextDelayMs() ?? 50)) : 50;
                $delay = min(50, $delay);
                if ($this->immediates !== []) {
                    $delay = 0;
                }
                $this->pollReadyIo($delay);
                $this->fireImmediate();
                JsPromise::drainMicrotasks();
                $this->fireDueTasks();
                continue;
            }
            // Microtasks emptied + no due timer + queue not empty
            // means every pending task is in the future. Jump the
            // virtual clock so they become due, then loop. The
            // post-drain hook will fire them on the next drain.
            $next = $this->earliestDeadline();
            if ($next === null) {
                return;
            }
            if ($next > $this->nowMs) {
                $this->nowMs = $next;
            }
            // Force one more drain pass; if fireDueTasks() schedules
            // microtasks, drainMicrotasks() on the next iteration
            // processes them.
            $this->fireDueTasks();
        }
        throw new \RuntimeException(
            "EventLoop::runUntilEmpty exceeded {$maxIters} iterations — likely a runaway setInterval"
        );
    }

    /**
     * Run a single tick: drain microtasks, fire any due timers, then
     * return. For real-time embedders driving the loop from PHP — no
     * virtual clock jump. Returns true if any work was done.
     */
    public function tick(): bool
    {
        JsPromise::drainMicrotasks();
        $progress = $this->pollReadyIo(0);
        $progress = $this->fireImmediate() || $progress;
        JsPromise::drainMicrotasks();
        return $this->fireDueTasks() || $progress;
    }

    private function pollReadyIo(int $timeout): bool
    {
        if ($this->ioPoll === null) {
            if ($timeout > 0) {
                usleep($timeout * 1000);
            }
            JsPromise::drainMicrotasks();
            return false;
        }
        $progress = false;
        // I/O callbacks and their Promise continuations can make more host
        // work ready. Drain a bounded batch before the check/timer phases,
        // without waiting again or starving timers under sustained traffic.
        for ($round = 0; $round < 8; $round++) {
            $ready = ($this->ioPoll)($round === 0 ? $timeout : 0);
            $progress = $ready || $progress;
            JsPromise::drainMicrotasks();
            if (!$ready || $this->ioPoll === null) {
                break;
            }
            $this->engine->getInterpreter()->checkBudget();
        }
        return $progress;
    }

    /** Number of pending tasks (excluding cancelled). */
    public function pendingTaskCount(): int
    {
        return count($this->tasks) + count($this->immediates);
    }

    /** True when no tasks are pending. */
    public function isEmpty(): bool
    {
        return $this->tasks === [] && $this->immediates === [];
    }

    /**
     * Milliseconds until the earliest pending task's deadline. Null
     * when the queue is empty. Negative when at least one task is
     * already overdue.
     */
    public function nextDelayMs(): ?float
    {
        $next = $this->earliestDeadline();
        if ($next === null) {
            return null;
        }
        return $next - self::wallTimeMs();
    }

    private function earliestDeadline(): ?float
    {
        $min = null;
        foreach ($this->tasks as $task) {
            if ($task->cancelled) {
                continue;
            }
            if ($min === null || $task->deadlineMs < $min) {
                $min = $task->deadlineMs;
            }
        }
        return $min;
    }

    private function peekEarliestDue(): ?EventLoopTask
    {
        $bestId = null;
        $bestDeadline = INF;
        foreach ($this->tasks as $id => $task) {
            if ($task->cancelled) {
                continue;
            }
            if ($task->deadlineMs <= $this->nowMs && $task->deadlineMs < $bestDeadline) {
                $bestId = $id;
                $bestDeadline = $task->deadlineMs;
            }
        }
        return $bestId === null ? null : $this->tasks[$bestId];
    }

    private function advanceClock(): void
    {
        $wall = self::wallTimeMs();
        if ($wall > $this->nowMs) {
            $this->nowMs = $wall;
        }
    }

    private function invoke(EventLoopTask $task): void
    {
        // AsyncContext propagation: restore the storage snapshot
        // captured at setTimeout/setInterval time, run the callback,
        // and restore whatever was active before. setInterval re-arms
        // with the SAME snapshot — every firing sees the context that
        // was active when the interval was first scheduled, matching
        // the spec.
        $storage = AsyncContextStorage::active();
        $previous = $storage->snapshot();
        $storage->restore($task->contextSnapshot);
        try {
            $this->engine->getInterpreter()->callFunction(
                $task->callback,
                JsUndefined::instance(),
                $task->callbackArgs,
            );
        } catch (\Throwable $e) {
            if ($this->engine->getNodeRuntime() !== null) {
                throw $e;
            }
            // Per HTML spec, uncaught errors from a timer callback go
            // to the "report an error" algorithm — which in a host
            // context fires the global error event. Phasis has no
            // such surface; surface via PHP error_log so embedders
            // can see it, then keep the loop running so a single
            // throwing timer doesn't kill the program.
            error_log("Phasis: uncaught in setTimeout/setInterval: " . $e->getMessage());
        } finally {
            $storage->restore($previous);
        }
    }

    private static function wallTimeMs(): float
    {
        return microtime(true) * 1000.0;
    }

    /**
     * Test-only: pretend the clock advanced. Used by tests that want
     * to simulate timer expiry without sleeping. Embedders should use
     * runUntilEmpty().
     *
     * @internal
     */
    public function advanceVirtualClock(float $deltaMs): void
    {
        $this->nowMs += $deltaMs;
    }
}
