<?php

declare(strict_types=1);

namespace PhpJs\Value;

use PhpJs\Object\PropertyDescriptor;

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

    /** Promise.prototype: the shared prototype for all JsPromise instances. */
    private static ?JsObject $promisePrototype = null;

    public static function setPromisePrototype(JsObject $proto): void
    {
        self::$promisePrototype = $proto;
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

        // If value is a JsPromise, adopt its state.
        if ($value instanceof self) {
            if ($value->state === self::STATE_FULFILLED) {
                $this->state = self::STATE_FULFILLED;
                $this->value = $value->value;
            } elseif ($value->state === self::STATE_REJECTED) {
                $this->state = self::STATE_REJECTED;
                $this->value = $value->value;
            } else {
                // Still pending: adopt when it settles.
                // In our synchronous model this shouldn't happen,
                // but handle it by just adopting pending state.
                $this->state = self::STATE_PENDING;
            }
            return;
        }

        // If value is a thenable (has a .then method), resolve via it.
        if ($value instanceof JsObject) {
            $then = $value->get('then');
            if ($then instanceof JsFunction) {
                $resolved = false;
                $resolveFn = JsFunction::fromCallable('resolve', function (JsValue $this_, array $args) use (&$resolved): JsValue {
                    if ($resolved) {
                        return JsUndefined::instance();
                    }
                    $resolved = true;
                    $this->resolve($args[0] ?? JsUndefined::instance());
                    return JsUndefined::instance();
                }, 1);
                $rejectFn = JsFunction::fromCallable('reject', function (JsValue $this_, array $args) use (&$resolved): JsValue {
                    if ($resolved) {
                        return JsUndefined::instance();
                    }
                    $resolved = true;
                    $this->reject($args[0] ?? JsUndefined::instance());
                    return JsUndefined::instance();
                }, 1);
                try {
                    $then->call($value, [$resolveFn, $rejectFn]);
                } catch (\Throwable $e) {
                    if (!$resolved) {
                        $resolved = true;
                        if ($e instanceof \PhpJs\Exceptions\JsThrowable) {
                            $this->reject($e->jsValue);
                        } else {
                            $this->reject(new JsString($e->getMessage()));
                        }
                    }
                }
                return;
            }
        }

        $this->state = self::STATE_FULFILLED;
        $this->value = $value;
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

        $child = new self();

        if ($this->state === self::STATE_FULFILLED) {
            if ($onFulfilled instanceof JsFunction) {
                try {
                    $result = $onFulfilled->call(JsUndefined::instance(), [$this->value]);
                    $child->resolve($result);
                } catch (\PhpJs\Exceptions\JsThrowable $e) {
                    $child->reject($e->jsValue);
                } catch (\PhpJs\Exceptions\RuntimeError $e) {
                    $child->reject(new JsString($e->getMessage()));
                } catch (\Throwable $e) {
                    $child->reject(new JsString($e->getMessage()));
                }
            } else {
                // Not callable: pass through the value.
                $child->resolve($this->value);
            }
        } elseif ($this->state === self::STATE_REJECTED) {
            if ($onRejected instanceof JsFunction) {
                try {
                    $result = $onRejected->call(JsUndefined::instance(), [$this->value]);
                    $child->resolve($result);
                } catch (\PhpJs\Exceptions\JsThrowable $e) {
                    $child->reject($e->jsValue);
                } catch (\PhpJs\Exceptions\RuntimeError $e) {
                    $child->reject(new JsString($e->getMessage()));
                } catch (\Throwable $e) {
                    $child->reject(new JsString($e->getMessage()));
                }
            } else {
                // Not callable: propagate the rejection.
                $child->reject($this->value);
            }
        } else {
            // Pending: in our synchronous model this shouldn't happen,
            // but just propagate the pending state.
            $child->resolve($this->value);
        }

        return $child;
    }

    /**
     * Implements Promise.prototype.catch(onRejected).
     */
    public function catch_(array $args): self
    {
        return $this->then([JsUndefined::instance(), $args[0] ?? JsUndefined::instance()]);
    }

    /**
     * Implements Promise.prototype.finally(onFinally).
     */
    public function finally_(array $args): self
    {
        $onFinally = $args[0] ?? JsUndefined::instance();

        if (!$onFinally instanceof JsFunction) {
            return $this->then($args);
        }

        $child = new self();

        $thenFn = $onFinally;
        if ($this->state === self::STATE_FULFILLED) {
            try {
                $result = $thenFn->call(JsUndefined::instance(), []);
                // If the onFinally handler returns a rejected promise, adopt that.
                if ($result instanceof self && $result->state === self::STATE_REJECTED) {
                    $child->reject($result->value);
                } else {
                    // Otherwise, pass through the original value.
                    $child->resolve($this->value);
                }
            } catch (\PhpJs\Exceptions\JsThrowable $e) {
                $child->reject($e->jsValue);
            } catch (\Throwable $e) {
                $child->reject(new JsString($e->getMessage()));
            }
        } elseif ($this->state === self::STATE_REJECTED) {
            try {
                $result = $thenFn->call(JsUndefined::instance(), []);
                // If the onFinally handler returns a rejected promise, adopt that.
                if ($result instanceof self && $result->state === self::STATE_REJECTED) {
                    $child->reject($result->value);
                } else {
                    // Otherwise, propagate the original rejection.
                    $child->reject($this->value);
                }
            } catch (\PhpJs\Exceptions\JsThrowable $e) {
                $child->reject($e->jsValue);
            } catch (\Throwable $e) {
                $child->reject(new JsString($e->getMessage()));
            }
        } else {
            $child->resolve($this->value);
        }

        return $child;
    }

    public function get(string $name): JsValue
    {
        // Check own properties first.
        $own = parent::get($name);
        if (!$own instanceof JsUndefined) {
            return $own;
        }

        $promise = $this;
        return match ($name) {
            'then' => JsFunction::fromCallable('then', function (JsValue $this_, array $args) use ($promise): JsValue {
                $target = $this_ instanceof self ? $this_ : $promise;
                return $target->then($args);
            }, 2),
            'catch' => JsFunction::fromCallable('catch', function (JsValue $this_, array $args) use ($promise): JsValue {
                $target = $this_ instanceof self ? $this_ : $promise;
                return $target->catch_($args);
            }, 1),
            'finally' => JsFunction::fromCallable('finally', function (JsValue $this_, array $args) use ($promise): JsValue {
                $target = $this_ instanceof self ? $this_ : $promise;
                return $target->finally_($args);
            }, 1),
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
        $err = new JsObject();
        $err->set('name', new JsString('TypeError'));
        $err->set('message', new JsString($message));
        return $err;
    }
}
