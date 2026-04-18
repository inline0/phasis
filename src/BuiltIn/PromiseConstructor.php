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

                $promise = new JsPromise($proto);

                $resolveHandler = function (JsValue $this_, array $args) use ($promise): JsValue {
                    $promise->resolve($args[0] ?? JsUndefined::instance());
                    return JsUndefined::instance();
                };
                $resolveFn = JsFunction::fromCallable('resolve', $resolveHandler, 1);

                $rejectHandler = function (JsValue $this_, array $args) use ($promise): JsValue {
                    $promise->reject($args[0] ?? JsUndefined::instance());
                    return JsUndefined::instance();
                };
                $rejectFn = JsFunction::fromCallable('reject', $rejectHandler, 1);

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
            $items = self::iterableToArray($args[0] ?? JsUndefined::instance());
            $results = [];
            foreach ($items as $item) {
                $p = self::coerceToPromise($item);
                if ($p->getState() === JsPromise::STATE_REJECTED) {
                    return JsPromise::rejected($p->getResolvedValue());
                }
                $results[] = $p->getResolvedValue();
            }
            return JsPromise::resolved(JsArray::fromArray($results));
        }, 1);
        $constructor->defineOwnProperty('all', PropertyDescriptor::data($allFn, true, false, true));

        // Promise.allSettled(iterable)
        $allSettledFn = JsFunction::fromCallable('allSettled', function (JsValue $this_, array $args): JsValue {
            $items = self::iterableToArray($args[0] ?? JsUndefined::instance());
            $results = [];
            foreach ($items as $item) {
                $p = self::coerceToPromise($item);
                $result = new JsObject();
                if ($p->getState() === JsPromise::STATE_FULFILLED) {
                    $result->set('status', new JsString('fulfilled'));
                    $result->set('value', $p->getResolvedValue());
                } else {
                    $result->set('status', new JsString('rejected'));
                    $result->set('reason', $p->getResolvedValue());
                }
                $results[] = $result;
            }
            return JsPromise::resolved(JsArray::fromArray($results));
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
            $items = self::iterableToArray($args[0] ?? JsUndefined::instance());
            $errors = [];
            foreach ($items as $item) {
                $p = self::coerceToPromise($item);
                if ($p->getState() === JsPromise::STATE_FULFILLED) {
                    return JsPromise::resolved($p->getResolvedValue());
                }
                $errors[] = $p->getResolvedValue();
            }
            // All rejected: create AggregateError.
            $err = new JsObject();
            $err->set('name', new JsString('AggregateError'));
            $err->set('message', new JsString('All promises were rejected'));
            $err->set('errors', JsArray::fromArray($errors));
            return JsPromise::rejected($err);
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

        // Wire constructor <-> prototype
        $constructor->set('prototype', $proto);
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
            return $this_->then($args);
        }, 2);
        $proto->defineOwnProperty('then', PropertyDescriptor::data($thenFn, true, false, true));

        // catch(onRejected)
        $catchFn = JsFunction::fromCallable('catch', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsPromise) {
                throw new TypeError('Method Promise.prototype.catch called on incompatible receiver');
            }
            return $this_->catchHandler($args);
        }, 1);
        $proto->defineOwnProperty('catch', PropertyDescriptor::data($catchFn, true, false, true));

        // finally(onFinally)
        $finallyFn = JsFunction::fromCallable('finally', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsPromise) {
                throw new TypeError('Method Promise.prototype.finally called on incompatible receiver');
            }
            return $this_->finallyHandler($args);
        }, 1);
        $proto->defineOwnProperty('finally', PropertyDescriptor::data($finallyFn, true, false, true));

        return $proto;
    }

    /**
     * Convert an iterable argument to a PHP array of JsValue items.
     *
     * @return list<JsValue>
     */
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
        }

        if ($iterable instanceof JsUndefined || $iterable instanceof JsNull) {
            $str = TypeConversion::toString($iterable);
            throw new TypeError(
                "Cannot read properties of {$str} (reading 'Symbol(Symbol.iterator)')",
            );
        }

        return [];
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
            $result = $ctor->call($obj, $args);
            return $result instanceof JsObject ? $result : $obj;
        }
        throw new TypeError('not a constructor');
    }
}
