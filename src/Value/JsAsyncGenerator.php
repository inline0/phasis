<?php

declare(strict_types=1);

namespace PhpJs\Value;

use PhpJs\Exceptions\JsThrowable;
use PhpJs\Exceptions\RuntimeError;
use PhpJs\Runtime\Environment;

/**
 * Represents a JavaScript async generator object returned by calling an async generator function.
 *
 * Like JsGenerator, this uses a PHP Fiber to pause and resume the function body.
 * The key difference is that next(), return(), and throw() return JsPromise
 * instances that resolve to iterator result objects {value, done}.
 *
 * In our synchronous execution model, the promises resolve immediately,
 * matching the behavior of our Promise and async/await implementations.
 */
class JsAsyncGenerator extends JsObject
{
    /** %AsyncGeneratorPrototype%: the intrinsic default [[Prototype]] for async generator instances. */
    private static ?JsObject $asyncGeneratorPrototype = null;

    public static function setAsyncGeneratorPrototype(JsObject $proto): void
    {
        self::$asyncGeneratorPrototype = $proto;
    }

    /** @var \Fiber<mixed, mixed, mixed, mixed> */
    private \Fiber $fiber;
    private bool $done = false;

    /** True while the generator body is actively running (not suspended). */
    private bool $executing = false;

    /**
     * Queue of pending requests enqueued while the generator was executing.
     * Each entry is ['next'|'return'|'throw', JsValue, JsPromise].
     *
     * @var list<array{0: 'next'|'return'|'throw', 1: JsValue, 2: JsPromise}>
     */
    private array $requestQueue = [];

    /**
     * True when the request queue was populated while the fiber was running
     * and needs to be drained on the NEXT non-executing call.
     */
    private bool $queuePendingDrain = false;

    /**
     * True while the current yield is awaiting a pending promise.
     * In this state, new next/return/throw calls are queued rather than
     * resuming the fiber immediately (the fiber will resume when the
     * pending promise resolves).
     */
    private bool $awaitingYieldPromise = false;

    /** Optional closure to convert PHP exceptions to proper JS error objects. */
    private ?\Closure $errorConverter = null;

    /**
     * @param JsFunction $generatorFn The async generator function that created this generator.
     * @param JsValue $thisValue The this-value for the generator function call.
     * @param list<JsValue> $args Arguments passed to the generator function.
     * @param \Closure(JsFunction, JsValue, list<JsValue>): JsValue $executor
     *   Closure that executes the generator function body. Provided by the interpreter.
     * @param ?\Closure(\Throwable): JsValue $errorConverter
     *   Optional closure to convert PHP exceptions to JS error objects with proper prototypes.
     */
    public function __construct(
        JsFunction $generatorFn,
        JsValue $thisValue,
        array $args,
        \Closure $executor,
        ?\Closure $errorConverter = null,
    ) {
        $this->errorConverter = $errorConverter;
        // Per spec: the [[Prototype]] is generatorFn.prototype if it's an Object,
        // otherwise falls back to the intrinsic %AsyncGeneratorPrototype%.
        $instanceProto = null;
        $fnProto = $generatorFn->get('prototype');
        if ($fnProto instanceof JsObject) {
            $instanceProto = $fnProto;
        } else {
            $instanceProto = self::$asyncGeneratorPrototype;
        }
        parent::__construct($instanceProto);

        // Ensure the prototype chain is properly wired to Object.prototype.
        if ($instanceProto !== null) {
            $tail = $instanceProto;
            while ($tail->getPrototype() !== null) {
                $tail = $tail->getPrototype();
            }
            $globalProto = JsObject::getGlobalPrototype();
            if ($globalProto !== null && $tail !== $globalProto) {
                $tail->setPrototype($globalProto);
            }
        }

        $fn = $generatorFn;
        $thisVal = $thisValue;
        $fnArgs = $args;

        $this->fiber = new \Fiber(static function () use ($executor, $fn, $thisVal, $fnArgs): mixed {
            return $executor($fn, $thisVal, $fnArgs);
        });
    }

    /**
     * Resume the async generator, optionally passing a value as the result of
     * the yield expression that last paused execution.
     *
     * @return JsPromise A promise that resolves to an iterator result object {value, done}.
     */
    public function next(?JsValue $value = null): JsPromise
    {
        if ($this->done) {
            return JsPromise::resolved($this->makeResult(JsUndefined::instance(), true));
        }

        if ($this->executing || $this->awaitingYieldPromise) {
            // Per spec: enqueue the request and return a pending promise.
            $queued = new JsPromise();
            $this->requestQueue[] = ['next', $value ?? JsUndefined::instance(), $queued];
            return $queued;
        }

        // Drain any queue that was populated during the PREVIOUS execution.
        if ($this->queuePendingDrain) {
            $this->queuePendingDrain = false;
            $this->drainRequestQueue();
        }

        // Re-check done after the drain (draining may have terminated the generator).
        if ($this->done) {
            return JsPromise::resolved($this->makeResult(JsUndefined::instance(), true));
        }

        $request = new JsPromise();
        $this->stepFiber('next', $value ?? JsUndefined::instance(), $request);
        return $request;
    }

    /**
     * Drive the fiber for one step (start/resume/throw) and route the resulting
     * suspension shape (AwaitSuspension, YieldDelegateResult, plain yield value,
     * termination) into the supplied $request promise.
     */
    private function stepFiber(string $type, JsValue $value, JsPromise $request): void
    {
        $this->executing = true;
        $suspended = null;
        try {
            if (!$this->fiber->isStarted()) {
                $suspended = $this->fiber->start();
            } elseif ($type === 'throw') {
                $suspended = $this->fiber->throw(new JsThrowable($value));
            } elseif ($type === 'return') {
                $suspended = $this->fiber->throw(new GeneratorReturnSignal($value));
            } else {
                $suspended = $this->fiber->resume($value);
            }
            $this->executing = false;
        } catch (GeneratorReturnSignal $e) {
            $this->executing = false;
            $this->done = true;
            if ($this->requestQueue !== []) {
                $this->queuePendingDrain = true;
            }
            if ($e->awaited) {
                // The yield* delegate already performed the spec-required
                // Awaits before propagating. Skip AsyncGeneratorAwaitReturn
                // to avoid an extra microtask tick / `then` getter access.
                $request->resolve($this->makeResult($e->value, true));
                return;
            }
            $this->asyncGeneratorAwaitReturn($e->value, $request);
            return;
        } catch (GeneratorThrowSignal $e) {
            $this->executing = false;
            $this->done = true;
            if ($this->requestQueue !== []) {
                $this->queuePendingDrain = true;
            }
            $request->reject($e->jsValue);
            return;
        } catch (JsThrowable $e) {
            $this->executing = false;
            $this->done = true;
            if ($this->requestQueue !== []) {
                $this->queuePendingDrain = true;
            }
            $request->reject($e->jsValue);
            return;
        } catch (RuntimeError $e) {
            $this->executing = false;
            $this->done = true;
            if ($this->requestQueue !== []) {
                $this->queuePendingDrain = true;
            }
            $request->reject($this->convertError($e));
            return;
        } catch (\Throwable $e) {
            $this->executing = false;
            $this->done = true;
            if ($this->requestQueue !== []) {
                $this->queuePendingDrain = true;
            }
            $request->reject($this->convertError($e));
            return;
        }

        if ($this->fiber->isTerminated()) {
            $this->done = true;
            if ($this->requestQueue !== []) {
                $this->queuePendingDrain = true;
            }
            $returnValue = $this->fiber->getReturn();
            $rv = $returnValue instanceof JsValue ? $returnValue : JsUndefined::instance();
            $this->asyncGeneratorAwaitReturn($rv, $request);
            return;
        }

        // Fiber suspended. Handle the suspension shape.
        if ($suspended instanceof AwaitSuspension) {
            $this->handleAwaitSuspension($suspended->value, $request);
            return;
        }

        if ($this->requestQueue !== []) {
            $this->queuePendingDrain = true;
        }

        if ($suspended instanceof YieldDelegateResult) {
            $request->resolve($suspended->result);
            return;
        }
        $yieldedValue = $suspended instanceof JsValue ? $suspended : JsUndefined::instance();
        $this->asyncGeneratorYieldResult($yieldedValue, $request);
    }

    /** Subscribe to the awaited value and resume the fiber when it settles. */
    private function handleAwaitSuspension(JsValue $awaited, JsPromise $request): void
    {
        $this->awaitingYieldPromise = true;
        // PromiseResolve(awaited): if awaited is a Promise, adopt; if thenable,
        // queue PromiseResolveThenableJob; otherwise wrap as fulfilled.
        $resolver = new JsPromise();
        try {
            $resolver->resolve($awaited);
        } catch (\Throwable $e) {
            $reason = $e instanceof JsThrowable ? $e->jsValue : $this->convertError($e);
            $this->awaitingYieldPromise = false;
            $this->stepFiber('throw', $reason, $request);
            return;
        }
        $resolver->then([
            JsFunction::fromCallable('', function (JsValue $t, array $a) use ($request): JsValue {
                $this->awaitingYieldPromise = false;
                $this->stepFiber('next', $a[0] ?? JsUndefined::instance(), $request);
                $this->maybeDrainQueue();
                return JsUndefined::instance();
            }, 1),
            JsFunction::fromCallable('', function (JsValue $t, array $a) use ($request): JsValue {
                $this->awaitingYieldPromise = false;
                $this->stepFiber('throw', $a[0] ?? JsUndefined::instance(), $request);
                $this->maybeDrainQueue();
                return JsUndefined::instance();
            }, 1),
        ]);
    }

    private function maybeDrainQueue(): void
    {
        if ($this->queuePendingDrain && !$this->executing && !$this->awaitingYieldPromise) {
            $this->queuePendingDrain = false;
            $this->drainRequestQueue();
        }
    }

    /**
     * Force the async generator to return a specific value and mark it as done.
     *
     * @return JsPromise A promise that resolves to an iterator result object {value, done}.
     */
    public function returnValue(JsValue $value): JsPromise
    {
        if ($this->done) {
            // Per spec AsyncGeneratorAwaitReturn: PromiseResolve(Promise, value) then {value, done:true}.
            return $this->asyncGeneratorAwaitReturn($value);
        }

        if ($this->executing || $this->awaitingYieldPromise) {
            // Per spec: enqueue the request and return a pending promise.
            $queued = new JsPromise();
            $this->requestQueue[] = ['return', $value, $queued];
            return $queued;
        }

        // Drain any queue that was populated during the PREVIOUS execution.
        if ($this->queuePendingDrain) {
            $this->queuePendingDrain = false;
            $this->drainRequestQueue();
        }

        // Re-check done and terminated after the drain.
        if ($this->done || !$this->fiber->isStarted() || $this->fiber->isTerminated()) {
            $this->done = true;
            // Per spec AsyncGeneratorAwaitReturn: PromiseResolve(Promise, value) then {value, done:true}.
            return $this->asyncGeneratorAwaitReturn($value);
        }

        // Per spec AsyncGeneratorAwaitReturn (sec-asyncgeneratorawaitreturn):
        // PromiseResolve(%Promise%, value) is called. If accessing value.constructor
        // throws (broken promise), the error becomes a Throw completion that is
        // thrown INTO the generator body (not a direct rejection). This allows
        // try/catch in the generator to handle the broken-promise error.
        $constructorError = null;
        if ($value instanceof JsObject) {
            try {
                $value->get('constructor');
            } catch (\Throwable $e) {
                $constructorError = $e instanceof JsThrowable ? $e->jsValue : $this->convertError($e);
            }
        }

        $suspended = null;
        $this->executing = true;
        try {
            if ($constructorError !== null) {
                // Broken constructor: throw the error into the generator so it can
                // be caught by try/catch in the generator body.
                $suspended = $this->fiber->throw(new GeneratorThrowSignal($constructorError));
            } else {
                $suspended = $this->fiber->throw(new GeneratorReturnSignal($value));
            }
            $this->executing = false;
        } catch (GeneratorReturnSignal $e) {
            $this->executing = false;
            $this->done = true;
            if ($this->requestQueue !== []) {
                $this->queuePendingDrain = true;
            }
            return $this->asyncGeneratorAwaitReturn($e->value);
        } catch (GeneratorThrowSignal $e) {
            $this->executing = false;
            $this->done = true;
            if ($this->requestQueue !== []) {
                $this->queuePendingDrain = true;
            }
            return JsPromise::rejected($e->jsValue);
        } catch (RuntimeError $e) {
            $this->executing = false;
            $this->done = true;
            if ($this->requestQueue !== []) {
                $this->queuePendingDrain = true;
            }
            return $e instanceof JsThrowable
                ? JsPromise::rejected($e->jsValue)
                : JsPromise::rejected($this->convertError($e));
        } catch (\Throwable $e) {
            $this->executing = false;
            $this->done = true;
            if ($this->requestQueue !== []) {
                $this->queuePendingDrain = true;
            }
            return JsPromise::rejected($this->convertError($e));
        }

        if ($this->isFiberDone()) {
            $this->done = true;
            if ($this->requestQueue !== []) {
                $this->queuePendingDrain = true;
            }
            $returnValue = $this->fiber->getReturn();
            $rv = $returnValue instanceof JsValue ? $returnValue : JsUndefined::instance();
            return $this->asyncGeneratorAwaitReturn($rv);
        }

        if ($this->requestQueue !== []) {
            $this->queuePendingDrain = true;
        }
        if ($suspended instanceof AwaitSuspension) {
            $request = new JsPromise();
            $this->handleAwaitSuspension($suspended->value, $request);
            return $request;
        }
        if ($suspended instanceof YieldDelegateResult) {
            return JsPromise::resolved($suspended->result);
        }
        $yieldedValue = $suspended instanceof JsValue ? $suspended : JsUndefined::instance();
        return $this->asyncGeneratorYieldResult($yieldedValue);
    }

    /**
     * Implements AsyncGeneratorAwaitReturn per spec sec-asyncgeneratorawaitreturn.
     *
     * Calls PromiseResolve(Promise, value) to unwrap thenable values, then
     * resolves to {value: unwrappedValue, done: true} or rejects if the
     * promise constructor is broken.
     */
    private function asyncGeneratorAwaitReturn(JsValue $value, ?JsPromise $target = null): JsPromise
    {
        $outer = $target ?? new JsPromise();
        // Step 6: Let promise be PromiseResolve(%Promise%, completion.[[Value]]).
        // PromiseResolve checks if value.constructor === Promise; if the getter throws, reject.
        if ($value instanceof JsObject) {
            // Try to access .constructor - may throw for broken promises.
            try {
                $value->get('constructor');
            } catch (\Throwable $e) {
                // Step 7: If abrupt, reject.
                if ($e instanceof JsThrowable) {
                    $outer->reject($e->jsValue);
                } else {
                    $outer->reject($this->convertError($e));
                }
                return $outer;
            }
        }

        // If value is a pending JsPromise, chain on it: resolve to {value: innerVal, done: true}.
        if ($value instanceof JsPromise) {
            if ($value->getState() === JsPromise::STATE_PENDING) {
                $makeResult = fn (JsValue $v) => $this->makeResult($v, true);
                $value->addFulfillHandler(function (JsValue $resolved) use ($outer, $makeResult): void {
                    $outer->resolve($makeResult($resolved));
                });
                $value->addRejectHandler(function (JsValue $reason) use ($outer): void {
                    $outer->reject($reason);
                });
                return $outer;
            }
            if ($value->getState() === JsPromise::STATE_REJECTED) {
                $outer->reject($value->getResolvedValue());
                return $outer;
            }
            // Fulfilled: per spec AsyncGeneratorAwaitReturn, the resulting
            // promise is settled via PromiseThen which adds one microtask
            // tick before resolving to {value, done:true}. Defer through
            // scheduleCallback to match observable tick counts.
            $resolved = $value->getResolvedValue();
            $iterResult = $this->makeResult($resolved, true);
            JsPromise::scheduleCallback(static function () use ($outer, $iterResult): void {
                $outer->resolve($iterResult);
            });
            return $outer;
        }

        // Per spec AsyncGeneratorAwaitReturn step 6: PromiseResolve(%Promise%, value).
        // For thenable objects this triggers the .then getter (observable side
        // effect required by yield-return-then-getter-ticks tests). Subscribe
        // to the resulting promise and wrap the inner value as {value, done:true}.
        if ($value instanceof JsObject) {
            $resolver = new JsPromise();
            try {
                $resolver->resolve($value);
            } catch (\Throwable $e) {
                if ($e instanceof JsThrowable) {
                    $outer->reject($e->jsValue);
                } else {
                    $outer->reject($this->convertError($e));
                }
                return $outer;
            }
            $makeResult = fn (JsValue $v) => $this->makeResult($v, true);
            $resolver->addFulfillHandler(function (JsValue $resolved) use ($outer, $makeResult): void {
                $outer->resolve($makeResult($resolved));
            });
            $resolver->addRejectHandler(function (JsValue $reason) use ($outer): void {
                $outer->reject($reason);
            });
            return $outer;
        }

        // Non-object: resolve directly with {value, done:true}.
        $outer->resolve($this->makeResult($value, true));
        return $outer;
    }

    /**
     * Throw an exception into the async generator at the point where it last
     * yielded, as if the yield expression threw.
     *
     * @return JsPromise A promise that resolves to an iterator result object {value, done}.
     */
    public function throwValue(JsValue $value): JsPromise
    {
        if ($this->done) {
            return JsPromise::rejected($value);
        }

        if ($this->executing || $this->awaitingYieldPromise) {
            // Per spec: enqueue the request and return a pending promise.
            $queued = new JsPromise();
            $this->requestQueue[] = ['throw', $value, $queued];
            return $queued;
        }

        // Drain any queue that was populated during the PREVIOUS execution.
        if ($this->queuePendingDrain) {
            $this->queuePendingDrain = false;
            $this->drainRequestQueue();
        }

        // Re-check done and fiber state after the drain.
        if ($this->done) {
            return JsPromise::rejected($value);
        }
        if (!$this->fiber->isStarted() || $this->fiber->isTerminated()) {
            $this->done = true;
            return JsPromise::rejected($value);
        }

        $suspended = null;

        $this->executing = true;
        try {
            $suspended = $this->fiber->throw(new GeneratorThrowSignal($value));
            $this->executing = false;
        } catch (GeneratorThrowSignal $e) {
            $this->executing = false;
            $this->done = true;
            if ($this->requestQueue !== []) {
                $this->queuePendingDrain = true;
            }
            return JsPromise::rejected($e->jsValue);
        } catch (RuntimeError $e) {
            $this->executing = false;
            $this->done = true;
            if ($this->requestQueue !== []) {
                $this->queuePendingDrain = true;
            }
            return $e instanceof JsThrowable
                ? JsPromise::rejected($e->jsValue)
                : JsPromise::rejected($this->convertError($e));
        } catch (\Throwable $e) {
            $this->executing = false;
            $this->done = true;
            if ($this->requestQueue !== []) {
                $this->queuePendingDrain = true;
            }
            return JsPromise::rejected($this->convertError($e));
        }

        if ($this->isFiberDone()) {
            $this->done = true;
            if ($this->requestQueue !== []) {
                $this->queuePendingDrain = true;
            }
            $returnValue = $this->fiber->getReturn();
            $rv = $returnValue instanceof JsValue ? $returnValue : JsUndefined::instance();
            return $this->asyncGeneratorAwaitReturn($rv);
        }

        if ($this->requestQueue !== []) {
            $this->queuePendingDrain = true;
        }
        if ($suspended instanceof AwaitSuspension) {
            $request = new JsPromise();
            $this->handleAwaitSuspension($suspended->value, $request);
            return $request;
        }
        if ($suspended instanceof YieldDelegateResult) {
            return JsPromise::resolved($suspended->result);
        }
        $yieldedValue = $suspended instanceof JsValue ? $suspended : JsUndefined::instance();
        return $this->asyncGeneratorYieldResult($yieldedValue);
    }

    /**
     * Process any requests that were enqueued while the generator was executing.
     *
     * Each queued request gets processed in order: the generator is advanced
     * (or terminated/rejected) and the queued promise is settled accordingly.
     */
    private function drainRequestQueue(): void
    {
        while ($this->requestQueue !== [] && !$this->executing && !$this->awaitingYieldPromise) {
            [$type, $value, $queued] = array_shift($this->requestQueue);

            if ($this->done) {
                // Generator already done: settle appropriately.
                if ($type === 'throw') {
                    $queued->reject($value);
                } elseif ($type === 'return') {
                    $inner = $this->asyncGeneratorAwaitReturn($value);
                    if ($inner->getState() === JsPromise::STATE_FULFILLED) {
                        $queued->resolve($inner->getResolvedValue());
                    } elseif ($inner->getState() === JsPromise::STATE_REJECTED) {
                        $queued->reject($inner->getResolvedValue());
                    } else {
                        // Pending: chain
                        $inner->addFulfillHandler(fn ($v) => $queued->resolve($v));
                        $inner->addRejectHandler(fn ($v) => $queued->reject($v));
                    }
                } else {
                    $queued->resolve($this->makeResult(JsUndefined::instance(), true));
                }
                continue;
            }

            // Drive the generator for this queued request.
            $this->executing = true;
            $suspended = null;
            try {
                if ($type === 'next') {
                    if (!$this->fiber->isStarted()) {
                        $suspended = $this->fiber->start();
                    } elseif ($this->fiber->isTerminated()) {
                        $this->executing = false;
                        $this->done = true;
                        $queued->resolve($this->makeResult(JsUndefined::instance(), true));
                        continue;
                    } else {
                        $suspended = $this->fiber->resume($value);
                    }
                } elseif ($type === 'return') {
                    if (!$this->fiber->isStarted() || $this->fiber->isTerminated()) {
                        $this->executing = false;
                        $this->done = true;
                        $inner = $this->asyncGeneratorAwaitReturn($value);
                        if ($inner->getState() === JsPromise::STATE_FULFILLED) {
                            $queued->resolve($inner->getResolvedValue());
                        } elseif ($inner->getState() === JsPromise::STATE_REJECTED) {
                            $queued->reject($inner->getResolvedValue());
                        } else {
                            $inner->addFulfillHandler(fn ($v) => $queued->resolve($v));
                            $inner->addRejectHandler(fn ($v) => $queued->reject($v));
                        }
                        continue;
                    }
                    $suspended = $this->fiber->throw(new GeneratorReturnSignal($value));
                } else {
                    // throw
                    if (!$this->fiber->isStarted() || $this->fiber->isTerminated()) {
                        $this->executing = false;
                        $this->done = true;
                        $queued->reject($value);
                        continue;
                    }
                    $suspended = $this->fiber->throw(new GeneratorThrowSignal($value));
                }
                $this->executing = false;
            } catch (GeneratorReturnSignal $e) {
                $this->executing = false;
                $this->done = true;
                if ($e->awaited) {
                    // yield* already performed the spec-required Awaits on
                    // the value before propagating the return completion.
                    // Skip the outer AsyncGeneratorAwaitReturn so we don't
                    // observe a redundant `then` getter access.
                    $queued->resolve($this->makeResult($e->value, true));
                    continue;
                }
                $inner = $this->asyncGeneratorAwaitReturn($e->value);
                if ($inner->getState() === JsPromise::STATE_FULFILLED) {
                    $queued->resolve($inner->getResolvedValue());
                } elseif ($inner->getState() === JsPromise::STATE_REJECTED) {
                    $queued->reject($inner->getResolvedValue());
                } else {
                    $inner->addFulfillHandler(fn ($v) => $queued->resolve($v));
                    $inner->addRejectHandler(fn ($v) => $queued->reject($v));
                }
                continue;
            } catch (GeneratorThrowSignal $e) {
                $this->executing = false;
                $this->done = true;
                $queued->reject($e->jsValue);
                continue;
            } catch (RuntimeError $e) {
                $this->executing = false;
                $this->done = true;
                $jsErr = $e instanceof JsThrowable ? $e->jsValue : $this->convertError($e);
                $queued->reject($jsErr);
                continue;
            } catch (\Throwable $e) {
                $this->executing = false;
                $this->done = true;
                $queued->reject($this->convertError($e));
                continue;
            }

            if ($this->fiber->isTerminated()) {
                $this->done = true;
                $returnValue = $this->fiber->getReturn();
                $rv = $returnValue instanceof JsValue ? $returnValue : JsUndefined::instance();
                $awaitResult = $this->asyncGeneratorAwaitReturn($rv);
                if ($awaitResult->getState() === JsPromise::STATE_FULFILLED) {
                    $queued->resolve($awaitResult->getResolvedValue());
                } elseif ($awaitResult->getState() === JsPromise::STATE_REJECTED) {
                    $queued->reject($awaitResult->getResolvedValue());
                } else {
                    $awaitResult->addFulfillHandler(fn ($v) => $queued->resolve($v));
                    $awaitResult->addRejectHandler(fn ($v) => $queued->reject($v));
                }
                continue;
            }

            // Fiber suspended.
            if ($suspended instanceof AwaitSuspension) {
                $this->handleAwaitSuspension($suspended->value, $queued);
                continue;
            }
            if ($suspended instanceof YieldDelegateResult) {
                $queued->resolve($suspended->result);
            } else {
                $yieldedValue = $suspended instanceof JsValue ? $suspended : JsUndefined::instance();
                // Pass $queued as the resolution target so deferred yield
                // resolution settles the queued promise directly, avoiding an
                // extra chain microtask between the yield-deferred promise
                // and the queued request's promise.
                $yieldResult = $this->asyncGeneratorYieldResult($yieldedValue, $queued);
                if ($yieldResult === $queued) {
                    // The deferred path already targets $queued; nothing more to do.
                    continue;
                }
                if ($yieldResult->getState() === JsPromise::STATE_FULFILLED) {
                    $queued->resolve($yieldResult->getResolvedValue());
                } elseif ($yieldResult->getState() === JsPromise::STATE_REJECTED) {
                    $queued->reject($yieldResult->getResolvedValue());
                } else {
                    // Pending: chain to queued promise.
                    $yieldResult->addFulfillHandler(fn ($v) => $queued->resolve($v));
                    $yieldResult->addRejectHandler(fn ($v) => $queued->reject($v));
                }
            }
        }
    }

    /**
     * Per spec AsyncGeneratorYield: the yielded value is wrapped via
     * PromiseResolve(%Promise%, value). If the yielded value is a thenable,
     * the outer promise resolves to {value: inner, done: false} only AFTER
     * the inner promise resolves (or rejects).
     *
     * This handles `yield pendingPromise` in async generators correctly.
     * When the yielded value is a pending promise, sets $awaitingYieldPromise = true
     * so subsequent next/return/throw calls are queued until the promise resolves.
     */
    private function asyncGeneratorYieldResult(JsValue $value, ?JsPromise $target = null): JsPromise
    {
        if (!($value instanceof JsPromise)) {
            // Per AsyncGeneratorYield step 5, Await(value) runs. For thenables
            // this triggers PromiseResolve which chains via .then; for plain
            // values there is still a microtask tick. Wrap via Promise.resolve
            // semantics so thenables are properly awaited.
            if ($value instanceof JsObject) {
                $promise = new JsPromise();
                $promise->resolve($value);
                $value = $promise;
            } else {
                // Plain value: per AsyncGeneratorYield step 5, Await(value) is
                // performed which incurs one microtask tick before resolving
                // the request promise. Defer through the microtask queue and
                // mark the generator as awaiting so subsequent next/return
                // calls enqueue rather than re-entering the fiber. When called
                // from drainRequestQueue, $target is the queued request's
                // promise so we resolve it directly instead of chaining.
                $this->awaitingYieldPromise = true;
                $outer = $target ?? new JsPromise();
                $iterResult = $this->makeResult($value, false);
                JsPromise::scheduleCallback(function () use ($outer, $iterResult): void {
                    $this->awaitingYieldPromise = false;
                    $outer->resolve($iterResult);
                    if ($this->requestQueue !== [] && !$this->executing) {
                        $this->drainRequestQueue();
                    }
                });
                return $outer;
            }
        }

        if ($value->getState() === JsPromise::STATE_FULFILLED) {
            // Per AsyncGeneratorYield step 5, Await(value) adds one microtask
            // tick before resolving the request promise — even for fulfilled
            // promises. Defer through scheduleCallback so consumers observing
            // the request promise via .then see the spec-mandated tick.
            $this->awaitingYieldPromise = true;
            $outer = $target ?? new JsPromise();
            $iterResult = $this->makeResult($value->getResolvedValue(), false);
            JsPromise::scheduleCallback(function () use ($outer, $iterResult): void {
                $this->awaitingYieldPromise = false;
                $outer->resolve($iterResult);
                if ($this->requestQueue !== [] && !$this->executing) {
                    $this->drainRequestQueue();
                }
            });
            return $outer;
        }

        if ($value->getState() === JsPromise::STATE_REJECTED) {
            if ($target !== null) {
                $target->reject($value->getResolvedValue());
                return $target;
            }
            return JsPromise::rejected($value->getResolvedValue());
        }

        // Pending promise: suspend the generator until the promise settles.
        // New next/return/throw calls will be queued while awaiting.
        $this->awaitingYieldPromise = true;
        $outer = $target ?? new JsPromise();
        $makeResult = fn (JsValue $v) => $this->makeResult($v, false);
        $value->addFulfillHandler(function (JsValue $resolved) use ($outer, $makeResult): void {
            $this->awaitingYieldPromise = false;
            $outer->resolve($makeResult($resolved));
            // Drain any requests queued while we were awaiting.
            if ($this->requestQueue !== []) {
                $this->drainRequestQueue();
            }
        });
        $value->addRejectHandler(function (JsValue $reason) use ($outer): void {
            $this->awaitingYieldPromise = false;
            $outer->reject($reason);
            // Drain any requests queued while we were awaiting.
            if ($this->requestQueue !== []) {
                $this->drainRequestQueue();
            }
        });
        return $outer;
    }

    /**
     * Create an iterator result object: {value: V, done: D}.
     */
    private function makeResult(JsValue $value, bool $done): JsObject
    {
        $result = new JsObject();
        $result->set('value', $value);
        $result->set('done', new JsBoolean($done));
        return $result;
    }

    /**
     * Create a proper TypeError JsObject for incompatible receiver errors.
     * Uses the TypeError constructor from $env so that instanceof TypeError works.
     *
     * @param \PhpJs\Runtime\Environment $env
     */
    public static function makeIncompatibleReceiverError(\PhpJs\Runtime\Environment $env, string $method): JsValue
    {
        $message = "Method AsyncGenerator.prototype.{$method} called on incompatible receiver";
        try {
            $ctor = $env->get('TypeError');
            if ($ctor instanceof JsFunction) {
                $obj = new JsObject();
                $obj->set('[[NewTarget]]', $ctor);
                $proto = $ctor->get('prototype');
                if ($proto instanceof JsObject) {
                    $obj->setPrototype($proto);
                }
                return $ctor->call($obj, [new JsString($message)]);
            }
        } catch (\Throwable) {
            // Fall through to plain object.
        }
        $error = new JsObject();
        $error->set('message', new JsString($message));
        $error->set('name', new JsString('TypeError'));
        return $error;
    }

    /**
     * Read the fiber's post-throw termination state through a helper so
     * static analysis sees a fresh check, not the entry-guard narrowing.
     * Fiber::throw can transition the fiber from suspended to terminated
     * (when the body returns inside a finally) and that transition has to
     * be observable to the caller.
     */
    private function isFiberDone(): bool
    {
        return $this->fiber->isTerminated();
    }

    private function convertError(\Throwable $e): JsValue
    {
        if ($this->errorConverter !== null) {
            return ($this->errorConverter)($e);
        }
        $error = new JsObject();
        $error->set('message', new JsString($e->getMessage()));
        $error->set('name', new JsString('Error'));
        return $error;
    }

    public function typeof(): string
    {
        return 'object';
    }

    public function toJsString(): string
    {
        return '[object AsyncGenerator]';
    }

    public function display(): string
    {
        return '[object AsyncGenerator]';
    }
}
