<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Exceptions\TypeError;
use PhpJs\Runtime\Environment;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsArray;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsPromise;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;
use PhpJs\Object\PropertyDescriptor;

/**
 * Promise constructor and static methods.
 *
 * new Promise(executor) creates a promise and calls executor(resolve, reject)
 * synchronously. Since PHP is single-threaded, all resolution happens
 * immediately in the executor call.
 */
class PromiseConstructor
{
    public static function install(Environment $env): void
    {
        $proto = self::createPrototype();
        JsPromise::setPromisePrototype($proto);

        $constructor = JsFunction::fromCallable(
            'Promise',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                // Promise must be called with new (§25.6.3.1 step 1)
                if (!$this_ instanceof JsObject || $this_->get('[[NewTarget]]') instanceof JsUndefined) {
                    throw new TypeError('Promise constructor cannot be invoked without \'new\'');
                }
                $executor = $args[0] ?? JsUndefined::instance();
                if (!$executor instanceof JsFunction) {
                    throw new TypeError('Promise resolver is not a function');
                }

                // Per spec OrdinaryCreateFromConstructor: use NewTarget's prototype.
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsFunction) {
                    $ctorProto = $newTarget->get('prototype');
                    $useProto = $ctorProto instanceof JsObject ? $ctorProto : $proto;
                } else {
                    $useProto = $proto;
                }
                $promise = new JsPromise($useProto);

                $resolveHandler = function (JsValue $this_, array $args) use ($promise): JsValue {
                    $promise->resolve($args[0] ?? JsUndefined::instance());
                    return JsUndefined::instance();
                };
                // Per CreateResolvingFunctions, these are anonymous built-ins.
                $resolveFn = JsFunction::fromCallable('', $resolveHandler, 1);

                $rejectHandler = function (JsValue $this_, array $args) use ($promise): JsValue {
                    $promise->reject($args[0] ?? JsUndefined::instance());
                    return JsUndefined::instance();
                };
                $rejectFn = JsFunction::fromCallable('', $rejectHandler, 1);

                try {
                    $executor->call(JsUndefined::instance(), [$resolveFn, $rejectFn]);
                } catch (\PhpJs\Exceptions\JsThrowable $e) {
                    $promise->reject($e->jsValue);
                } catch (\PhpJs\Exceptions\RuntimeError $e) {
                    $promise->reject(new JsString($e->getMessage()));
                } catch (\Throwable $e) {
                    $promise->reject(new JsString($e->getMessage()));
                }

                return $promise;
            },
            1,
        );
        $constructor->setConstructable();

        // Promise.resolve(value) — per spec §25.6.4.5
        // 1. Let C be the this value
        // 2. If Type(C) is not Object, throw TypeError
        // 3. If IsPromise(x) and x.constructor === C, return x
        // 4. Let capability = NewPromiseCapability(C)
        // 5. Call capability.[[Resolve]](x)
        $resolveFn = JsFunction::fromCallable(
            'resolve',
            function (JsValue $this_, array $args) use ($constructor): JsValue {
            // this_ is the constructor value (Promise or subclass)
                if (!$this_ instanceof JsObject) {
                    throw new TypeError('Promise.resolve called on non-object');
                }
                $value = $args[0] ?? JsUndefined::instance();
            // If already a promise whose constructor matches, return directly
                if ($value instanceof JsPromise) {
                    $ctor = $value->get('constructor');
                    if ($ctor === $this_) {
                        return $value;
                    }
                }
            // If this_ is a custom constructor (not our Promise), use NewPromiseCapability
                if ($this_ instanceof JsFunction && $this_ !== $constructor) {
                    return self::newPromiseCapabilityResolve($this_, $value);
                }
                return JsPromise::resolved($value);
            },
            1,
        );
        $constructor->defineOwnProperty(
            'resolve',
            PropertyDescriptor::data($resolveFn, true, false, true),
        );

        // Promise.reject(reason) — per spec §25.6.4.4
        $rejectFn = JsFunction::fromCallable(
            'reject',
            function (JsValue $this_, array $args) use ($constructor): JsValue {
                if (!$this_ instanceof JsObject) {
                    throw new TypeError('Promise.reject called on non-object');
                }
                $reason = $args[0] ?? JsUndefined::instance();
                if ($this_ instanceof JsFunction && $this_ !== $constructor) {
                    return self::newPromiseCapabilityReject($this_, $reason);
                }
                return JsPromise::rejected($reason);
            },
            1,
        );
        $constructor->defineOwnProperty('reject', PropertyDescriptor::data($rejectFn, true, false, true));

        // Promise.all(iterable)
        $allFn = JsFunction::fromCallable('all', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsFunction) {
                throw new TypeError('Promise.all called on non-object');
            }
            [$promise, $resolve, $reject] = self::newPromiseCapability($this_);
            // Iterate lazily and invoke C.resolve per item. If the iterator
            // protocol throws — including the error-close test where
            // Promise.resolve is monkey-patched to throw — run IteratorClose
            // and reject. Doing this inline avoids collecting a
            // never-terminating iterable into memory first.
            try {
                $iterable = $args[0] ?? JsUndefined::instance();
                $iter = self::openIterator($iterable);
                $results = [];
                $remaining = 0;
                $index = 0;
                $started = false;
                while (true) {
                    $step = self::iteratorStep($iter);
                    if ($step === null) {
                        break;
                    }
                    $started = true;
                    $value = $step;
                    $i = $index++;
                    $results[$i] = JsUndefined::instance();
                    $remaining++;
                    try {
                        $resolveMethod = $this_->get('resolve');
                        if (!$resolveMethod instanceof JsFunction) {
                            throw new TypeError('Promise resolve is not a function');
                        }
                        $itemPromise = $resolveMethod->call($this_, [$value]);
                    } catch (\PhpJs\Exceptions\JsThrowable $e) {
                        self::iteratorCloseIgnore($iter);
                        $reject->call(JsUndefined::instance(), [$e->jsValue]);
                        return $promise;
                    }
                    $thenMethod = $itemPromise instanceof JsObject ? $itemPromise->get('then') : null;
                    if ($thenMethod instanceof JsFunction) {
                        $resolveElement = JsFunction::fromCallable(
                            '',
                            function (JsValue $this_, array $args) use ($i, &$results, &$remaining, $resolve): JsValue {
                                $results[$i] = $args[0] ?? JsUndefined::instance();
                                $remaining--;
                                if ($remaining === 0) {
                                    $arr = [];
                                    foreach ($results as $r) {
                                        $arr[] = $r;
                                    }
                                    $resolve->call(JsUndefined::instance(), [JsArray::fromArray($arr)]);
                                }
                                return JsUndefined::instance();
                            },
                            1,
                        );
                        try {
                            $thenMethod->call($itemPromise, [$resolveElement, $reject]);
                        } catch (\PhpJs\Exceptions\JsThrowable $e) {
                            self::iteratorCloseIgnore($iter);
                            $reject->call(JsUndefined::instance(), [$e->jsValue]);
                            return $promise;
                        }
                    } else {
                        $results[$i] = $itemPromise;
                        $remaining--;
                    }
                }
                if (!$started) {
                    $resolve->call(JsUndefined::instance(), [JsArray::fromArray([])]);
                    return $promise;
                }
                if ($remaining === 0) {
                    $arr = [];
                    foreach ($results as $r) {
                        $arr[] = $r;
                    }
                    $resolve->call(JsUndefined::instance(), [JsArray::fromArray($arr)]);
                }
                return $promise;
            } catch (\PhpJs\Exceptions\JsThrowable $e) {
                $reject->call(JsUndefined::instance(), [$e->jsValue]);
                return $promise;
            } catch (\PhpJs\Exceptions\RuntimeError $e) {
                $reject->call(JsUndefined::instance(), [self::phpErrorToJsValue($e)]);
                return $promise;
            } catch (\Throwable $e) {
                $reject->call(JsUndefined::instance(), [new JsString($e->getMessage())]);
                return $promise;
            }
        }, 1);
        $constructor->defineOwnProperty('all', PropertyDescriptor::data($allFn, true, false, true));

        // Promise.allSettled(iterable)
        $allSettledFn = JsFunction::fromCallable('allSettled', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsFunction) {
                throw new TypeError('Promise.allSettled called on non-object');
            }
            [$promise, $resolve, $reject] = self::newPromiseCapability($this_);
            try {
                $iter = self::openIterator($args[0] ?? JsUndefined::instance());
                $results = [];
                $remaining = 0;
                $index = 0;
                $started = false;
                while (true) {
                    $step = self::iteratorStep($iter);
                    if ($step === null) {
                        break;
                    }
                    $started = true;
                    $value = $step;
                    $i = $index++;
                    $results[$i] = JsUndefined::instance();
                    $remaining++;
                    try {
                        $resolveMethod = $this_->get('resolve');
                        if (!$resolveMethod instanceof JsFunction) {
                            throw new TypeError('Promise resolve is not a function');
                        }
                        $itemPromise = $resolveMethod->call($this_, [$value]);
                    } catch (\PhpJs\Exceptions\JsThrowable $e) {
                        self::iteratorCloseIgnore($iter);
                        $reject->call(JsUndefined::instance(), [$e->jsValue]);
                        return $promise;
                    }
                    $thenMethod = $itemPromise instanceof JsObject ? $itemPromise->get('then') : null;
                    if ($thenMethod instanceof JsFunction) {
                        $onFulfilled = JsFunction::fromCallable(
                            '',
                            function (JsValue $this_, array $args) use ($i, &$results, &$remaining, $resolve): JsValue {
                                $value = $args[0] ?? JsUndefined::instance();
                                $r = new JsObject();
                                $r->set('status', new JsString('fulfilled'));
                                $r->set('value', $value);
                                $results[$i] = $r;
                                $remaining--;
                                if ($remaining === 0) {
                                    $arr = [];
                                    foreach ($results as $rr) {
                                        $arr[] = $rr;
                                    }
                                    $resolve->call(JsUndefined::instance(), [JsArray::fromArray($arr)]);
                                }
                                return JsUndefined::instance();
                            },
                            1,
                        );
                        $onRejected = JsFunction::fromCallable(
                            '',
                            function (JsValue $this_, array $args) use ($i, &$results, &$remaining, $resolve): JsValue {
                                $reason = $args[0] ?? JsUndefined::instance();
                                $r = new JsObject();
                                $r->set('status', new JsString('rejected'));
                                $r->set('reason', $reason);
                                $results[$i] = $r;
                                $remaining--;
                                if ($remaining === 0) {
                                    $arr = [];
                                    foreach ($results as $rr) {
                                        $arr[] = $rr;
                                    }
                                    $resolve->call(JsUndefined::instance(), [JsArray::fromArray($arr)]);
                                }
                                return JsUndefined::instance();
                            },
                            1,
                        );
                        try {
                            $thenMethod->call($itemPromise, [$onFulfilled, $onRejected]);
                        } catch (\PhpJs\Exceptions\JsThrowable $e) {
                            self::iteratorCloseIgnore($iter);
                            $reject->call(JsUndefined::instance(), [$e->jsValue]);
                            return $promise;
                        }
                    } else {
                        $r = new JsObject();
                        $r->set('status', new JsString('fulfilled'));
                        $r->set('value', $itemPromise);
                        $results[$i] = $r;
                        $remaining--;
                    }
                }
                if (!$started) {
                    $resolve->call(JsUndefined::instance(), [JsArray::fromArray([])]);
                    return $promise;
                }
                if ($remaining === 0) {
                    $arr = [];
                    foreach ($results as $r) {
                        $arr[] = $r;
                    }
                    $resolve->call(JsUndefined::instance(), [JsArray::fromArray($arr)]);
                }
                return $promise;
            } catch (\PhpJs\Exceptions\JsThrowable $e) {
                $reject->call(JsUndefined::instance(), [$e->jsValue]);
                return $promise;
            } catch (\PhpJs\Exceptions\RuntimeError $e) {
                $reject->call(JsUndefined::instance(), [self::phpErrorToJsValue($e)]);
                return $promise;
            } catch (\Throwable $e) {
                $reject->call(JsUndefined::instance(), [new JsString($e->getMessage())]);
                return $promise;
            }
        }, 1);
        $constructor->defineOwnProperty('allSettled', PropertyDescriptor::data($allSettledFn, true, false, true));

        // Promise.race(iterable)
        $raceFn = JsFunction::fromCallable('race', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsFunction) {
                throw new TypeError('not a constructor');
            }

            [$promise, $resolve, $reject] = self::newPromiseCapability($this_);
            $iterable = $args[0] ?? JsUndefined::instance();
            $iterSym = SymbolConstructor::iterator();

            if ($iterable instanceof JsUndefined || $iterable instanceof JsNull) {
                throw new TypeError(
                    "Cannot read properties of " . TypeConversion::toString($iterable)
                    . " (reading 'Symbol(Symbol.iterator)')",
                );
            }

            if (!$iterable instanceof JsObject) {
                return $promise;
            }

            $iteratorMethod = $iterable->getBySymbol($iterSym);
            if (!$iteratorMethod instanceof JsFunction) {
                return $promise;
            }

            $iterator = $iteratorMethod->call($iterable, []);
            if (!$iterator instanceof JsObject) {
                throw new TypeError('Result of the Symbol.iterator method is not an object');
            }

            $nextMethod = $iterator->get('next');
            if (!$nextMethod instanceof JsFunction) {
                return $promise;
            }

            while (true) {
                $step = $nextMethod->call($iterator, []);
                if (!$step instanceof JsObject) {
                    break;
                }
                if (TypeConversion::toBoolean($step->get('done'))) {
                    break;
                }

                $nextValue = $step->get('value');

                try {
                    $resolveMethod = $this_->get('resolve');
                    if (!$resolveMethod instanceof JsFunction) {
                        throw new TypeError('Promise resolve is not a function');
                    }
                    $nextPromise = $resolveMethod->call($this_, [$nextValue]);
                } catch (\PhpJs\Exceptions\JsThrowable $e) {
                    self::closeIterator($iterator);
                    $reject->call(JsUndefined::instance(), [$e->jsValue]);
                    return $promise;
                } catch (\PhpJs\Exceptions\RuntimeError $e) {
                    self::closeIterator($iterator);
                    $reject->call(JsUndefined::instance(), [new JsString($e->getMessage())]);
                    return $promise;
                } catch (\Throwable $e) {
                    self::closeIterator($iterator);
                    $reject->call(JsUndefined::instance(), [new JsString($e->getMessage())]);
                    return $promise;
                }

                $coerced = $nextPromise instanceof JsPromise
                    ? $nextPromise
                    : self::coerceToPromise($nextPromise);

                if ($coerced->getState() === JsPromise::STATE_FULFILLED) {
                    $resolve->call(JsUndefined::instance(), [$coerced->getResolvedValue()]);
                    return $promise;
                }

                if ($coerced->getState() === JsPromise::STATE_REJECTED) {
                    $reject->call(JsUndefined::instance(), [$coerced->getResolvedValue()]);
                    return $promise;
                }
            }

            return $promise;
        }, 1);
        $constructor->defineOwnProperty('race', PropertyDescriptor::data($raceFn, true, false, true));

        // Promise.any(iterable)
        $anyFn = JsFunction::fromCallable('any', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsFunction) {
                throw new TypeError('Promise.any called on non-object');
            }
            [$promise, $resolve, $reject] = self::newPromiseCapability($this_);
            try {
                $iter = self::openIterator($args[0] ?? JsUndefined::instance());
                $errors = [];
                $remaining = 0;
                $index = 0;
                $started = false;
                while (true) {
                    $step = self::iteratorStep($iter);
                    if ($step === null) {
                        break;
                    }
                    $started = true;
                    $value = $step;
                    $i = $index++;
                    $errors[$i] = JsUndefined::instance();
                    $remaining++;
                    try {
                        $resolveMethod = $this_->get('resolve');
                        if (!$resolveMethod instanceof JsFunction) {
                            throw new TypeError('Promise resolve is not a function');
                        }
                        $itemPromise = $resolveMethod->call($this_, [$value]);
                    } catch (\PhpJs\Exceptions\JsThrowable $e) {
                        self::iteratorCloseIgnore($iter);
                        $reject->call(JsUndefined::instance(), [$e->jsValue]);
                        return $promise;
                    }
                    $thenMethod = $itemPromise instanceof JsObject ? $itemPromise->get('then') : null;
                    if ($thenMethod instanceof JsFunction) {
                        $onRejected = JsFunction::fromCallable(
                            '',
                            function (JsValue $this_, array $args) use ($i, &$errors, &$remaining, $reject): JsValue {
                                $errors[$i] = $args[0] ?? JsUndefined::instance();
                                $remaining--;
                                if ($remaining === 0) {
                                    $arr = [];
                                    foreach ($errors as $r) {
                                        $arr[] = $r;
                                    }
                                    $err = new JsObject();
                                    $err->set('name', new JsString('AggregateError'));
                                    $err->set('message', new JsString('All promises were rejected'));
                                    $err->set('errors', JsArray::fromArray($arr));
                                    $reject->call(JsUndefined::instance(), [$err]);
                                }
                                return JsUndefined::instance();
                            },
                            1,
                        );
                        try {
                            $thenMethod->call($itemPromise, [$resolve, $onRejected]);
                        } catch (\PhpJs\Exceptions\JsThrowable $e) {
                            self::iteratorCloseIgnore($iter);
                            $reject->call(JsUndefined::instance(), [$e->jsValue]);
                            return $promise;
                        }
                    } else {
                        $resolve->call(JsUndefined::instance(), [$itemPromise]);
                        return $promise;
                    }
                }
                if (!$started) {
                    $err = new JsObject();
                    $err->set('name', new JsString('AggregateError'));
                    $err->set('message', new JsString('All promises were rejected'));
                    $err->set('errors', JsArray::fromArray([]));
                    $reject->call(JsUndefined::instance(), [$err]);
                }
                return $promise;
            } catch (\PhpJs\Exceptions\JsThrowable $e) {
                $reject->call(JsUndefined::instance(), [$e->jsValue]);
                return $promise;
            } catch (\PhpJs\Exceptions\RuntimeError $e) {
                $reject->call(JsUndefined::instance(), [self::phpErrorToJsValue($e)]);
                return $promise;
            } catch (\Throwable $e) {
                $reject->call(JsUndefined::instance(), [new JsString($e->getMessage())]);
                return $promise;
            }
        }, 1);
        $constructor->defineOwnProperty('any', PropertyDescriptor::data($anyFn, true, false, true));

        // Promise.withResolvers() per spec sec-promise.withResolvers.
        $withResolversFn = JsFunction::fromCallable(
            'withResolvers',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsFunction) {
                    throw new TypeError('Promise.withResolvers called on non-constructor');
                }
                [$promise, $resolve, $reject] = self::newPromiseCapability($this_);
                $obj = new JsObject();
                $obj->defineOwnProperty(
                    'promise',
                    PropertyDescriptor::data($promise, true, true, true),
                );
                $obj->defineOwnProperty(
                    'resolve',
                    PropertyDescriptor::data($resolve, true, true, true),
                );
                $obj->defineOwnProperty(
                    'reject',
                    PropertyDescriptor::data($reject, true, true, true),
                );
                return $obj;
            },
            0,
        );
        $constructor->defineOwnProperty(
            'withResolvers',
            PropertyDescriptor::data($withResolversFn, true, false, true),
        );

        // Promise.try(callback, ...args) per spec §27.2.3.5.
        $tryFn = JsFunction::fromCallable(
            'try',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    throw new TypeError('Promise.try called on non-object');
                }
                $callback = $args[0] ?? JsUndefined::instance();
                $callArgs = array_slice($args, 1);
                if (!$this_ instanceof JsFunction) {
                    throw new TypeError('Promise.try called on non-constructor');
                }
                [$promise, $resolve, $reject] = self::newPromiseCapability($this_);
                try {
                    $result = $callback instanceof JsFunction
                        ? $callback->call(JsUndefined::instance(), $callArgs)
                        : throw new TypeError('Promise.try callback is not a function');
                    $resolve->call(JsUndefined::instance(), [$result]);
                } catch (\PhpJs\Exceptions\JsThrowable $e) {
                    $reject->call(JsUndefined::instance(), [$e->jsValue]);
                } catch (\PhpJs\Exceptions\RuntimeError $e) {
                    $reject->call(JsUndefined::instance(), [
                        self::phpErrorToJsValue($e),
                    ]);
                }
                return $promise;
            },
            1,
        );
        $constructor->defineOwnProperty(
            'try',
            PropertyDescriptor::data($tryFn, true, false, true),
        );

        // Wire constructor <-> prototype. Per §27.2.4.2, Promise.prototype
        // is a non-writable, non-enumerable, non-configurable data property.
        $constructor->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data($proto, false, false, false),
        );
        $proto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($constructor, true, false, true),
        );

        // Symbol.toStringTag = "Promise"
        $toStringTagSym = SymbolConstructor::toStringTag();
        $proto->definePropertyBySymbol(
            $toStringTagSym,
            PropertyDescriptor::data(new JsString('Promise'), false, false, true),
        );

        // Promise[@@species] per spec: accessor property, getter returns `this`.
        $speciesGetter = JsFunction::fromCallable('get [Symbol.species]', function (JsValue $this_): JsValue {
            return $this_;
        }, 0);
        $constructor->definePropertyBySymbol(
            SymbolConstructor::species(),
            PropertyDescriptor::accessor(
                get: $speciesGetter,
                set: null,
                enumerable: false,
                configurable: true,
            ),
        );

        $env->defineVar('Promise', $constructor);
    }

    private static function createPrototype(): JsObject
    {
        $proto = new JsObject();

        // then(onFulfilled, onRejected)
        $thenFn = JsFunction::fromCallable('then', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsPromise) {
                throw new TypeError('Method Promise.prototype.then called on incompatible receiver');
            }
            // Per spec (Promise.prototype.then step 3):
            //   Let C be ? SpeciesConstructor(promise, %Promise%).
            // If promise.constructor is non-undefined and non-object, throw
            // TypeError here rather than silently falling back to %Promise%.
            $ctor = $this_->get('constructor');
            if (
                !($ctor instanceof JsUndefined)
                && !($ctor instanceof JsObject)
            ) {
                throw new TypeError('Promise constructor is not an object');
            }
            return $this_->then($args);
        }, 2);
        $proto->defineOwnProperty('then', PropertyDescriptor::data($thenFn, true, false, true));

        // catch(onRejected) — invokes this.then(undefined, onRejected).
        $catchFn = JsFunction::fromCallable('catch', function (JsValue $this_, array $args): JsValue {
            if ($this_ instanceof JsPromise) {
                return $this_->catchHandler($args);
            }
            // Per spec §27.2.5.1: Promise.prototype.catch invokes
            // Invoke(this, "then", «undefined, onRejected»). Invoke uses
            // GetV which coerces `this` via ToObject. So boolean/number/
            // string receivers see their prototype's `then`.
            $obj = \PhpJs\Spec\TypeConversion::toObject($this_);
            $thenMethod = $obj->get('then');
            if (!$thenMethod instanceof JsFunction) {
                throw new TypeError('Promise.prototype.catch called on receiver without a `then` method');
            }
            return $thenMethod->call($this_, [JsUndefined::instance(), $args[0] ?? JsUndefined::instance()]);
        }, 1);
        $proto->defineOwnProperty('catch', PropertyDescriptor::data($catchFn, true, false, true));

        // finally(onFinally). Per §27.2.5.3, always route through Invoke
        // so a user-overridden `then` on the receiver is respected.
        $finallyFn = JsFunction::fromCallable('finally', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Promise.prototype.finally called on non-object');
            }
            $thenMethod = $this_->get('then');
            if (!$thenMethod instanceof JsFunction) {
                throw new TypeError('Promise.prototype.finally called on receiver without a `then` method');
            }
            $onFinally = $args[0] ?? JsUndefined::instance();
            if (!$onFinally instanceof JsFunction) {
                return $thenMethod->call($this_, [$onFinally, $onFinally]);
            }
            $thenFulfilled = JsFunction::fromCallable(
                '',
                function (JsValue $this_, array $args) use ($onFinally): JsValue {
                    $value = $args[0] ?? JsUndefined::instance();
                    $onFinally->call(JsUndefined::instance(), []);
                    return $value;
                },
                1,
            );
            $thenRejected = JsFunction::fromCallable(
                '',
                function (JsValue $this_, array $args) use ($onFinally): JsValue {
                    $reason = $args[0] ?? JsUndefined::instance();
                    $onFinally->call(JsUndefined::instance(), []);
                    throw new \PhpJs\Exceptions\JsThrowable($reason);
                },
                1,
            );
            return $thenMethod->call($this_, [$thenFulfilled, $thenRejected]);
        }, 1);
        $proto->defineOwnProperty('finally', PropertyDescriptor::data($finallyFn, true, false, true));

        return $proto;
    }

    /**
     * Convert an iterable argument to a PHP array of JsValue items.
     *
     * @return list<JsValue>
     */
    /**
     * Convert a PHP RuntimeError (TypeError, RangeError, etc.) to its JS
     * counterpart via the active interpreter so `instanceof TypeError`
     * works on the rejection value.
     */
    private static function phpErrorToJsValue(\PhpJs\Exceptions\RuntimeError $e): JsValue
    {
        $interp = JsFunction::getInterpreterInstance();
        if ($interp !== null) {
            $jv = $interp->phpExceptionToJsValue($e);
            if ($jv instanceof JsValue) {
                return $jv;
            }
        }
        $name = $e instanceof \PhpJs\Exceptions\TypeError ? 'TypeError'
            : ($e instanceof \PhpJs\Exceptions\RangeError ? 'RangeError'
                : ($e instanceof \PhpJs\Exceptions\ReferenceError ? 'ReferenceError' : 'Error'));
        $obj = new JsObject();
        $obj->set('name', new JsString($name));
        $obj->set('message', new JsString($e->getMessage()));
        return $obj;
    }

    /**
     * Open an iterator over an iterable. Returns an array with the next
     * method and iterator object so iteratorStep and iteratorCloseIgnore
     * can drive it step-by-step.
     *
     * @return array{iterator:JsObject,next:JsFunction,arrayFallback:?array<int,JsValue>}
     */
    private static function openIterator(JsValue $iterable): array
    {
        if ($iterable instanceof JsUndefined || $iterable instanceof JsNull) {
            $str = TypeConversion::toString($iterable);
            throw new TypeError(
                "Cannot read properties of {$str} (reading 'Symbol(Symbol.iterator)')",
            );
        }
        if ($iterable instanceof JsObject) {
            $iterSym = SymbolConstructor::iterator();
            $iteratorMethod = $iterable->getBySymbol($iterSym);
            if ($iteratorMethod instanceof JsFunction) {
                $iterator = $iteratorMethod->call($iterable, []);
                if ($iterator instanceof JsObject) {
                    $nextMethod = $iterator->get('next');
                    if ($nextMethod instanceof JsFunction) {
                        return [
                            'iterator' => $iterator,
                            'next' => $nextMethod,
                            'arrayFallback' => null,
                        ];
                    }
                }
                throw new TypeError('object is not iterable');
            }
        }
        if ($iterable instanceof JsString) {
            $values = [];
            $u16 = \PhpJs\Value\JsString::utf8ToUtf16LE($iterable->value);
            $len = (int) (strlen($u16) / 2);
            for ($i = 0; $i < $len; $i++) {
                $codeUnit = ord($u16[$i * 2]) | (ord($u16[$i * 2 + 1]) << 8);
                $values[] = new JsString(\PhpJs\Value\JsString::utf16CodeUnitToUtf8($codeUnit));
            }
            return self::syntheticArrayIterator($values);
        }
        throw new TypeError('object is not iterable');
    }

    /**
     * @param list<JsValue> $values
     * @return array{iterator:JsObject,next:JsFunction,arrayFallback:?array<int,JsValue>}
     */
    private static function syntheticArrayIterator(array $values): array
    {
        $iterator = new JsObject();
        $stub = JsFunction::fromCallable('next', static function (): JsValue {
            return new JsObject();
        }, 0);
        return [
            'iterator' => $iterator,
            'next' => $stub,
            'arrayFallback' => $values,
        ];
    }

    /**
     * Advance the iterator one step. Returns the next value, or null when
     * the iterator is exhausted.
     *
     * @param array{iterator:JsObject,next:JsFunction,arrayFallback:?array<int,JsValue>} $iter
     */
    private static function iteratorStep(array &$iter): ?JsValue
    {
        if ($iter['arrayFallback'] !== null) {
            if ($iter['arrayFallback'] === []) {
                return null;
            }
            return array_shift($iter['arrayFallback']);
        }
        $ir = $iter['next']->call($iter['iterator'], []);
        if (!$ir instanceof JsObject) {
            throw new TypeError('iterator result is not an object');
        }
        if (TypeConversion::toBoolean($ir->get('done'))) {
            return null;
        }
        return $ir->get('value');
    }

    /**
     * Per §7.4.7 IteratorClose(iterator, completion): invoke the iterator's
     * `return` method if present. Swallow any error — the caller already
     * owns the originating error.
     *
     * @param array{iterator:JsObject,next:JsFunction,arrayFallback:?array<int,JsValue>} $iter
     */
    private static function iteratorCloseIgnore(array $iter): void
    {
        if ($iter['arrayFallback'] !== null) {
            return;
        }
        try {
            $return = $iter['iterator']->get('return');
            if ($return instanceof JsFunction) {
                $return->call($iter['iterator'], []);
            }
        } catch (\Throwable) {
            // Ignore — original error wins.
        }
    }

    private static function iterableToArray(JsValue $iterable): array
    {
        if ($iterable instanceof JsArray) {
            $result = [];
            $len = $iterable->getLength();
            for ($i = 0; $i < $len; $i++) {
                $result[] = $iterable->get((string) $i);
            }
            return $result;
        }

        if ($iterable instanceof JsObject) {
            $iterSym = SymbolConstructor::iterator();
            $iteratorMethod = $iterable->getBySymbol($iterSym);
            if ($iteratorMethod instanceof JsFunction) {
                $iterator = $iteratorMethod->call($iterable, []);
                if ($iterator instanceof JsObject) {
                    $nextMethod = $iterator->get('next');
                    if ($nextMethod instanceof JsFunction) {
                        $result = [];
                        while (true) {
                            $ir = $nextMethod->call($iterator, []);
                            if (!$ir instanceof JsObject) {
                                break;
                            }
                            if (TypeConversion::toBoolean($ir->get('done'))) {
                                break;
                            }
                            $result[] = $ir->get('value');
                        }
                        return $result;
                    }
                }
            }
            // No Symbol.iterator method on this object — not iterable.
            throw new TypeError('object is not iterable');
        }

        if ($iterable instanceof JsUndefined || $iterable instanceof JsNull) {
            $str = TypeConversion::toString($iterable);
            throw new TypeError(
                "Cannot read properties of {$str} (reading 'Symbol(Symbol.iterator)')",
            );
        }

        if ($iterable instanceof JsString) {
            $result = [];
            $value = $iterable->value;
            $len = mb_strlen($value, 'UTF-8');
            for ($i = 0; $i < $len; $i++) {
                $result[] = new JsString(mb_substr($value, $i, 1, 'UTF-8'));
            }
            return $result;
        }

        // Numbers, booleans, symbols, bigints — none of these are iterable.
        throw new TypeError(TypeConversion::toString($iterable) . ' is not iterable');
    }

    /**
     * Coerce a value to a JsPromise. If already a promise, return it.
     * Otherwise, wrap in a resolved promise (Promise.resolve behavior).
     */
    private static function coerceToPromise(JsValue $value): JsPromise
    {
        if ($value instanceof JsPromise) {
            return $value;
        }
        return JsPromise::resolved($value);
    }

    private static function closeIterator(JsObject $iterator): void
    {
        $returnMethod = $iterator->get('return');
        if ($returnMethod instanceof JsFunction) {
            $returnMethod->call($iterator, []);
        }
    }

    /**
     * NewPromiseCapability(C) then resolve. Used when C is a custom constructor.
     */
    /**
     * NewPromiseCapability(C) — §25.6.1.5
     * Creates a new promise via the C constructor and extracts resolve/reject.
     *
     * @return array{0: JsValue, 1: ?JsFunction, 2: ?JsFunction} [promise, resolve, reject]
     */
    private static function newPromiseCapability(JsFunction $ctor): array
    {
        $resolve = null;
        $reject = null;
        // Per spec, GetCapabilitiesExecutor is an anonymous built-in function.
        $executor = JsFunction::fromCallable(
            '',
            function (JsValue $this_, array $args) use (&$resolve, &$reject): JsValue {
                $r = $args[0] ?? JsUndefined::instance();
                $j = $args[1] ?? JsUndefined::instance();
            // Per spec §25.6.1.5.1 GetCapabilitiesExecutor:
            // Only throw if resolve/reject are already set to non-undefined
                if ($resolve !== null && !$resolve instanceof JsUndefined) {
                    throw new TypeError('resolve function already set');
                }
                if ($reject !== null && !$reject instanceof JsUndefined) {
                    throw new TypeError('reject function already set');
                }
                $resolve = $r;
                $reject = $j;
                return JsUndefined::instance();
            },
            2,
        );

        $promise = self::constructWith($ctor, [$executor]);

        $resolveFn = ($resolve instanceof JsFunction) ? $resolve : null;
        $rejectFn = ($reject instanceof JsFunction) ? $reject : null;

        if ($resolveFn === null || $rejectFn === null) {
            throw new TypeError('Promise capability functions are not callable');
        }

        return [$promise, $resolveFn, $rejectFn];
    }

    private static function newPromiseCapabilityResolve(JsFunction $ctor, JsValue $value): JsValue
    {
        [$promise, $resolve] = self::newPromiseCapability($ctor);
        $resolve->call(JsUndefined::instance(), [$value]);
        return $promise;
    }

    /**
     * NewPromiseCapability(C) then reject. Used when C is a custom constructor.
     */
    private static function newPromiseCapabilityReject(JsFunction $ctor, JsValue $reason): JsValue
    {
        [$promise, , $reject] = self::newPromiseCapability($ctor);
        $reject->call(JsUndefined::instance(), [$reason]);
        return $promise;
    }

    /**
     * Construct an object using a JsFunction constructor (simulates `new C(args)`).
     *
     * @param list<JsValue> $args
     */
    private static function constructWith(JsFunction $ctor, array $args): JsValue
    {
        if ($ctor->isConstructable()) {
            // Set the prototype from the constructor's .prototype property,
            // matching evalNewExpression behavior.
            $proto = $ctor->get('prototype');
            $obj = new JsObject($proto instanceof JsObject ? $proto : null);
            $obj->defineOwnProperty(
                '[[NewTarget]]',
                PropertyDescriptor::data($ctor, false, false, false),
            );
            // Use the interpreter's callFunction for class constructors,
            // because their native callables expect 3 args (this, args, interp).
            $interp = JsFunction::getInterpreterInstance();
            if ($interp !== null) {
                $result = $interp->callFunction($ctor, $obj, $args);
            } else {
                $result = $ctor->call($obj, $args);
            }
            return $result instanceof JsObject ? $result : $obj;
        }
        throw new TypeError('not a constructor');
    }
}
