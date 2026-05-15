<?php

declare(strict_types=1);

namespace Phasis\Value;

use Phasis\Object\PropertyDescriptor;

/**
 * Represents a JavaScript Promise.
 *
 * Since PHP is single-threaded, this is a synchronous implementation.
 * The executor runs immediately, and then/catch/finally handlers run
 * synchronously when called. This passes basic Promise tests while
 * maintaining correct value propagation semantics.
 */
class JsPromise extends JsObject
{
    public const STATE_PENDING = 'pending';
    public const STATE_FULFILLED = 'fulfilled';
    public const STATE_REJECTED = 'rejected';

    private string $state = self::STATE_PENDING;
    private JsValue $value;

    /**
     * Pending handlers queued while this promise is unresolved.
     * Each entry is [onFulfilled|null, onRejected|null, childPromise|null].
     * The child promise is null for subscription-only handlers attached
     * via Promise resolution (see resolveValue's thenable adoption path).
     *
     * @var list<array{0: JsFunction|null, 1: JsFunction|null, 2: self|null}>
     */
    private array $pendingHandlers = [];

    /**
     * Global microtask queue: deferred callbacks scheduled by scheduleCallback().
     * Drained by drainMicrotasks(), which is called after top-level JS evaluation.
     *
     * @var list<\Closure(): void>
     */
    private static array $microtaskQueue = [];

    /**
     * True while the microtask queue is being drained (prevents re-entrancy).
     */
    private static bool $drainingMicrotasks = false;

    /**
     * Optional "post-drain" hook invoked once the microtask queue is empty.
     * Returning true signals that the hook produced more work (e.g. fired a
     * virtual setTimeout callback that scheduled microtasks), in which case
     * the drain loop continues; returning false ends the drain.
     *
     * Used by the test262 AgentHost to advance the virtual setTimeout clock
     * after each microtask burst so polyfilled $262.agent.setTimeout-based
     * polling loops in atomicsHelper.js can make forward progress.
     *
     * @var \Closure(): bool|null
     */
    private static ?\Closure $postDrainHook = null;

    /**
     * Schedule a callback to run in the microtask queue.
     * Used to defer promise handler execution so that handlers fire in
     * FIFO order relative to when their promises were resolved, matching
     * JavaScript's microtask semantics.
     *
     * AsyncContext propagation: the active AsyncContext storage is
     * snapshotted at schedule time and restored around the callback
     * invocation, so context values flow naturally across the
     * Promise.then / queueMicrotask boundary (TC39 Stage 3
     * AsyncContext proposal).
     *
     * @param \Closure(): void $cb
     */
    public static function scheduleCallback(\Closure $cb): void
    {
        $storage = \Phasis\Runtime\AsyncContextStorage::active();
        $captured = $storage->snapshot();
        self::$microtaskQueue[] = static function () use ($cb, $captured): void {
            $storage = \Phasis\Runtime\AsyncContextStorage::active();
            $previous = $storage->snapshot();
            $storage->restore($captured);
            try {
                $cb();
            } finally {
                $storage->restore($previous);
            }
        };
    }

    /**
     * Install a post-drain hook (or null to clear it). The hook is invoked
     * once the microtask queue has been fully drained; if it returns true
     * the drain loop iterates again to pick up any microtasks the hook
     * produced (e.g. timer callbacks).
     */
    public static function setPostDrainHook(?\Closure $hook): void
    {
        self::$postDrainHook = $hook;
    }

    /**
     * Drain the microtask queue, running all scheduled callbacks.
     * Callbacks added during draining are also processed. When a
     * post-drain hook is installed, it is invoked after each empty queue
     * and the loop continues while it reports more work was scheduled.
     */
    public static function drainMicrotasks(): void
    {
        if (self::$drainingMicrotasks) {
            return;
        }
        self::$drainingMicrotasks = true;
        try {
            do {
                while (self::$microtaskQueue !== []) {
                    $cb = array_shift(self::$microtaskQueue);
                    $cb();
                }
                $hook = self::$postDrainHook;
                if ($hook === null) {
                    break;
                }
                $progressed = $hook();
            } while ($progressed === true);
        } finally {
            self::$drainingMicrotasks = false;
        }
    }

    /**
     * Clear the microtask queue (used on engine reset).
     */
    public static function clearMicrotasks(): void
    {
        self::$microtaskQueue = [];
        self::$drainingMicrotasks = false;
        self::$postDrainHook = null;
    }

    /** Promise.prototype: the shared prototype for all JsPromise instances. */
    private static ?JsObject $promisePrototype = null;
    /**
     * The intrinsic Promise.prototype.then captured at install time. Used to
     * decide whether JsPromise::resolve can take the fast adopt-state path
     * or must go through the spec's PromiseResolveThenableJob (which runs
     * whatever `then` now resolves to, including overrides).
     */
    private static ?JsValue $intrinsicThen = null;

    public static function setPromisePrototype(JsObject $proto): void
    {
        self::$promisePrototype = $proto;
        // Snapshot the then value at install. Anything that later replaces
        // Promise.prototype.then is observable via our shortcut check.
        $descriptor = $proto->getOwnPropertyDescriptor('then');
        self::$intrinsicThen = $descriptor?->value;
    }

    public function __construct(?JsObject $prototype = null)
    {
        parent::__construct($prototype ?? self::$promisePrototype);
        $this->value = JsUndefined::instance();
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getResolvedValue(): JsValue
    {
        return $this->value;
    }

    /**
     * Resolve (fulfill) this promise with a value.
     * If value is a thenable, adopt its state.
     */
    public function resolve(JsValue $value): void
    {
        if ($this->state !== self::STATE_PENDING) {
            return;
        }

        // If resolving with itself, throw TypeError per spec.
        if ($value === $this) {
            $this->reject(self::createTypeError('Chaining cycle detected for promise'));
            return;
        }

        // If value is a JsPromise whose `then` method resolves to the
        // intrinsic Promise.prototype.then (walking the prototype chain), we
        // can shortcut. If `then` has been overridden on the instance or on
        // any prototype in the chain, fall through so the overridden method
        // fires via the PromiseResolveThenableJob path (spec §27.2.1.3.2
        // steps 8–12 don't short-circuit for IsPromise — only the `then`
        // method's identity decides behavior).
        //
        // Also fall through when the value is a Promise SUBCLASS instance:
        // PromiseResolveThenableJob calls value.then(...), which goes
        // through species and creates a new subclass instance — observable
        // via test262's subclass-resolve-count tests that assert the exact
        // number of derived promises.
        if ($value instanceof self) {
            $thenLookup = $value->get('then');
            $thenUnchanged = self::$intrinsicThen !== null && $thenLookup === self::$intrinsicThen;
            $isPlainPromise = self::$promisePrototype !== null
                && $value->getPrototype() === self::$promisePrototype;
        } else {
            $thenUnchanged = false;
            $isPlainPromise = false;
        }
        if ($value instanceof self && $thenUnchanged && $isPlainPromise) {
            if ($value->state === self::STATE_FULFILLED) {
                $this->state = self::STATE_FULFILLED;
                $this->value = $value->value;
                $this->drainPendingHandlers();
            } elseif ($value->state === self::STATE_REJECTED) {
                $this->state = self::STATE_REJECTED;
                $this->value = $value->value;
                $this->drainPendingHandlers();
            } else {
                // Still pending: subscribe to it so we settle when it does.
                $outer = $this;
                $value->pendingHandlers[] = [null, null, null];
                // Use a direct subscription via the inner promise's pending handler list.
                $resolved = false;
                $resolveFn = JsFunction::fromCallable('resolve', function (JsValue $this_, array $args) use ($outer, &$resolved): JsValue {
                    if ($resolved) {
                        return JsUndefined::instance();
                    }
                    $resolved = true;
                    $outer->resolve($args[0] ?? JsUndefined::instance());
                    return JsUndefined::instance();
                }, 1);
                $rejectFn = JsFunction::fromCallable('reject', function (JsValue $this_, array $args) use ($outer, &$resolved): JsValue {
                    if ($resolved) {
                        return JsUndefined::instance();
                    }
                    $resolved = true;
                    $outer->reject($args[0] ?? JsUndefined::instance());
                    return JsUndefined::instance();
                }, 1);
                // Register directly on the inner promise's handler list.
                array_pop($value->pendingHandlers); // remove the placeholder we added above
                $value->pendingHandlers[] = [$resolveFn, $rejectFn, null];
            }
            return;
        }

        // If value is a thenable (has a .then method), resolve via it.
        if ($value instanceof JsObject) {
            $then = null;
            try {
                $then = $value->get('then');
            } catch (\Throwable $e) {
                if ($e instanceof \Phasis\Exceptions\JsThrowable) {
                    $this->reject($e->jsValue);
                } else {
                    $this->reject(new JsString($e->getMessage()));
                }
                return;
            }
            if ($then instanceof JsFunction) {
                // Per spec, thenable resolution is a PromiseResolveThenableJob
                // that is enqueued on the microtask queue rather than run
                // synchronously. This ensures `.then()` on the outer promise
                // observes correct tick ordering relative to other handlers.
                $promise = $this;
                $thenRef = $then;
                $valueRef = $value;
                self::scheduleCallback(static function () use ($promise, $thenRef, $valueRef): void {
                    $resolved = false;
                    $resolveHandler = function (JsValue $this_, array $args) use (&$resolved, $promise): JsValue {
                        if ($resolved) {
                            return JsUndefined::instance();
                        }
                        $resolved = true;
                        $promise->resolve($args[0] ?? JsUndefined::instance());
                        return JsUndefined::instance();
                    };
                    $rejectHandler = function (JsValue $this_, array $args) use (&$resolved, $promise): JsValue {
                        if ($resolved) {
                            return JsUndefined::instance();
                        }
                        $resolved = true;
                        $promise->reject($args[0] ?? JsUndefined::instance());
                        return JsUndefined::instance();
                    };
                    // Per CreateResolvingFunctions, these are anonymous built-ins.
                    $resolveFn = JsFunction::fromCallable('', $resolveHandler, 1);
                    $rejectFn = JsFunction::fromCallable('', $rejectHandler, 1);
                    try {
                        $thenRef->call($valueRef, [$resolveFn, $rejectFn]);
                    } catch (\Throwable $e) {
                        if (!$resolved) {
                            $resolved = true;
                            if ($e instanceof \Phasis\Exceptions\JsThrowable) {
                                $promise->reject($e->jsValue);
                            } else {
                                $promise->reject(new JsString($e->getMessage()));
                            }
                        }
                    }
                });
                return;
            }
        }

        $this->state = self::STATE_FULFILLED;
        $this->value = $value;
        $this->drainPendingHandlers();
    }

    /**
     * Reject this promise with a reason.
     */
    public function reject(JsValue $reason): void
    {
        if ($this->state !== self::STATE_PENDING) {
            return;
        }

        $this->state = self::STATE_REJECTED;
        $this->value = $reason;
        $this->drainPendingHandlers();
    }

    /**
     * Run all pending handlers now that this promise has settled.
     */
    private function drainPendingHandlers(): void
    {
        $handlers = $this->pendingHandlers;
        $this->pendingHandlers = [];
        $self = $this;
        foreach ($handlers as [$onFulfilled, $onRejected, $child]) {
            if ($child === null) {
                // Subscription-only handler (no child promise). Still runs as
                // a microtask so handler order matches spec PromiseReactions.
                $handler = $this->state === self::STATE_FULFILLED ? $onFulfilled : $onRejected;
                if ($handler instanceof JsFunction) {
                    $value = $this->value;
                    self::scheduleCallback(static function () use ($handler, $value): void {
                        try {
                            $handler->call(JsUndefined::instance(), [$value]);
                        } catch (\Throwable) {
                            // Ignore errors in subscription handlers.
                        }
                    });
                }
                continue;
            }
            // Queue each handler as its own microtask so long .then() chains
            // interleave with siblings per spec PromiseReactions enqueue order.
            self::scheduleCallback(static function () use ($self, $onFulfilled, $onRejected, $child): void {
                $self->runHandler($onFulfilled, $onRejected, $child);
            });
        }
    }

    /**
     * Execute a single then-handler pair and settle the child promise.
     */
    private function runHandler(?JsFunction $onFulfilled, ?JsFunction $onRejected, self $child): void
    {
        if ($this->state === self::STATE_FULFILLED) {
            if ($onFulfilled instanceof JsFunction) {
                try {
                    $result = $onFulfilled->call(JsUndefined::instance(), [$this->value]);
                    $child->resolve($result);
                } catch (\Phasis\Exceptions\JsThrowable $e) {
                    $child->reject($e->jsValue);
                } catch (\Phasis\Exceptions\RuntimeError $e) {
                    $child->reject(new JsString($e->getMessage()));
                } catch (\Throwable $e) {
                    $child->reject(new JsString($e->getMessage()));
                }
            } else {
                $child->resolve($this->value);
            }
        } elseif ($this->state === self::STATE_REJECTED) {
            if ($onRejected instanceof JsFunction) {
                try {
                    $result = $onRejected->call(JsUndefined::instance(), [$this->value]);
                    $child->resolve($result);
                } catch (\Phasis\Exceptions\JsThrowable $e) {
                    $child->reject($e->jsValue);
                } catch (\Phasis\Exceptions\RuntimeError $e) {
                    $child->reject(new JsString($e->getMessage()));
                } catch (\Throwable $e) {
                    $child->reject(new JsString($e->getMessage()));
                }
            } else {
                $child->reject($this->value);
            }
        }
    }

    /**
     * Register a fulfill callback to run when this promise settles with fulfillment.
     * If already fulfilled, runs immediately.
     *
     * @param \Closure(JsValue): void $cb
     */
    public function addFulfillHandler(\Closure $cb): void
    {
        if ($this->state === self::STATE_FULFILLED) {
            $cb($this->value);
            return;
        }
        if ($this->state === self::STATE_PENDING) {
            $fn = JsFunction::fromCallable('', function (JsValue $this_, array $args) use ($cb): JsValue {
                $cb($args[0] ?? JsUndefined::instance());
                return JsUndefined::instance();
            }, 1);
            $this->pendingHandlers[] = [$fn, null, null];
        }
    }

    /**
     * Register a reject callback to run when this promise settles with rejection.
     * If already rejected, runs immediately.
     *
     * @param \Closure(JsValue): void $cb
     */
    public function addRejectHandler(\Closure $cb): void
    {
        if ($this->state === self::STATE_REJECTED) {
            $cb($this->value);
            return;
        }
        if ($this->state === self::STATE_PENDING) {
            $fn = JsFunction::fromCallable('', function (JsValue $this_, array $args) use ($cb): JsValue {
                $cb($args[0] ?? JsUndefined::instance());
                return JsUndefined::instance();
            }, 1);
            $this->pendingHandlers[] = [null, $fn, null];
        }
    }

    /**
     * Create a fulfilled promise.
     */
    public static function resolved(JsValue $value): self
    {
        $p = new self();
        $p->resolve($value);
        return $p;
    }

    /**
     * Create a rejected promise.
     */
    public static function rejected(JsValue $reason): self
    {
        $p = new self();
        $p->reject($reason);
        return $p;
    }

    /**
     * Implements Promise.prototype.then(onFulfilled, onRejected).
     * Returns a new promise resolved with the result of the handler.
     *
     * @param list<JsValue> $args
     */
    public function then(array $args): self
    {
        $onFulfilled = $args[0] ?? JsUndefined::instance();
        $onRejected = $args[1] ?? JsUndefined::instance();

        $fulfillFn = $onFulfilled instanceof JsFunction ? $onFulfilled : null;
        $rejectFn = $onRejected instanceof JsFunction ? $onRejected : null;

        $child = new self();

        if ($this->state === self::STATE_PENDING) {
            // Queue the handler to run when this promise settles.
            $this->pendingHandlers[] = [$fulfillFn, $rejectFn, $child];
            return $child;
        }

        // Promise is already settled: schedule the handler as a microtask so that
        // handlers attached in reverse order still fire in resolution order.
        $parent = $this;
        self::scheduleCallback(static function () use ($parent, $fulfillFn, $rejectFn, $child): void {
            $parent->runHandler($fulfillFn, $rejectFn, $child);
        });
        return $child;
    }

    /**
     * Implements Promise.prototype.catch(onRejected).
     *
     * @param array<mixed> $args
     */
    public function catchHandler(array $args): self
    {
        return $this->then([JsUndefined::instance(), $args[0] ?? JsUndefined::instance()]);
    }

    /**
     * Implements Promise.prototype.finally(onFinally).
     *
     * @param array<mixed> $args
     */
    public function finallyHandler(array $args): self
    {
        $onFinally = $args[0] ?? JsUndefined::instance();

        if (!$onFinally instanceof JsFunction) {
            return $this->then($args);
        }

        $thenFn = $onFinally;
        $child = new self();

        if ($this->state === self::STATE_PENDING) {
            // Build synthetic fulfill/reject handlers for the queue.
            $originalValue = null;
            $fulfilled = JsFunction::fromCallable('', function (JsValue $this_, array $args) use ($thenFn, $child, &$originalValue): JsValue {
                $originalValue = $args[0] ?? JsUndefined::instance();
                try {
                    $result = $thenFn->call(JsUndefined::instance(), []);
                    if ($result instanceof self && $result->state === self::STATE_REJECTED) {
                        $child->reject($result->value);
                    } else {
                        $child->resolve($originalValue);
                    }
                } catch (\Phasis\Exceptions\JsThrowable $e) {
                    $child->reject($e->jsValue);
                } catch (\Throwable $e) {
                    $child->reject(new JsString($e->getMessage()));
                }
                return JsUndefined::instance();
            }, 1);
            $rejected = JsFunction::fromCallable('', function (JsValue $this_, array $args) use ($thenFn, $child): JsValue {
                $reason = $args[0] ?? JsUndefined::instance();
                try {
                    $result = $thenFn->call(JsUndefined::instance(), []);
                    if ($result instanceof self && $result->state === self::STATE_REJECTED) {
                        $child->reject($result->value);
                    } else {
                        $child->reject($reason);
                    }
                } catch (\Phasis\Exceptions\JsThrowable $e) {
                    $child->reject($e->jsValue);
                } catch (\Throwable $e) {
                    $child->reject(new JsString($e->getMessage()));
                }
                return JsUndefined::instance();
            }, 1);
            $this->pendingHandlers[] = [$fulfilled, $rejected, null];
            return $child;
        }

        if ($this->state === self::STATE_FULFILLED) {
            try {
                $result = $thenFn->call(JsUndefined::instance(), []);
                if ($result instanceof self && $result->state === self::STATE_REJECTED) {
                    $child->reject($result->value);
                } else {
                    $child->resolve($this->value);
                }
            } catch (\Phasis\Exceptions\JsThrowable $e) {
                $child->reject($e->jsValue);
            } catch (\Throwable $e) {
                $child->reject(new JsString($e->getMessage()));
            }
        } elseif ($this->state === self::STATE_REJECTED) {
            try {
                $result = $thenFn->call(JsUndefined::instance(), []);
                if ($result instanceof self && $result->state === self::STATE_REJECTED) {
                    $child->reject($result->value);
                } else {
                    $child->reject($this->value);
                }
            } catch (\Phasis\Exceptions\JsThrowable $e) {
                $child->reject($e->jsValue);
            } catch (\Throwable $e) {
                $child->reject(new JsString($e->getMessage()));
            }
        }

        return $child;
    }

    public function get(string $name): JsValue
    {
        // If the property exists anywhere on the chain (own or inherited),
        // honor that value. Specifically, an own-undefined shadow set via
        // `p.then = undefined` must not fall through to the synthetic method.
        if ($this->has($name)) {
            return parent::get($name);
        }

        $promise = $this;
        $thenCb = function (JsValue $this_, array $args) use ($promise): JsValue {
            $target = $this_ instanceof self ? $this_ : $promise;
            return $target->then($args);
        };
        $catchCb = function (JsValue $this_, array $args) use ($promise): JsValue {
            $target = $this_ instanceof self ? $this_ : $promise;
            return $target->catchHandler($args);
        };
        $finallyCb = function (JsValue $this_, array $args) use ($promise): JsValue {
            $target = $this_ instanceof self ? $this_ : $promise;
            return $target->finallyHandler($args);
        };
        return match ($name) {
            'then' => JsFunction::fromCallable('then', $thenCb, 2),
            'catch' => JsFunction::fromCallable('catch', $catchCb, 1),
            'finally' => JsFunction::fromCallable('finally', $finallyCb, 1),
            default => JsUndefined::instance(),
        };
    }

    public function typeof(): string
    {
        return 'object';
    }

    public function toJsString(): string
    {
        return '[object Promise]';
    }

    public function display(): string
    {
        return '[object Promise]';
    }

    /**
     * Helper to create a TypeError JsObject.
     */
    private static function createTypeError(string $message): JsObject
    {
        // Prefer using the active interpreter's TypeError constructor so the
        // returned error is an instanceof TypeError with the right prototype
        // chain (per spec, selfResolutionError is a newly created TypeError).
        $interp = JsFunction::getInterpreterInstance();
        if ($interp !== null) {
            $err = $interp->phpExceptionToJsValue(
                new \Phasis\Exceptions\TypeError($message),
            );
            if ($err instanceof JsObject) {
                return $err;
            }
        }
        $err = new JsObject();
        $err->set('name', new JsString('TypeError'));
        $err->set('message', new JsString($message));
        return $err;
    }
}
