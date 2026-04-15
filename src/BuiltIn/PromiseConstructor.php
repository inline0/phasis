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

        // Promise.resolve(value)
        $resolveFn = JsFunction::fromCallable('resolve', function (JsValue $this_, array $args): JsValue {
            $value = $args[0] ?? JsUndefined::instance();
            // If already a promise, return it directly.
            if ($value instanceof JsPromise) {
                return $value;
            }
            return JsPromise::resolved($value);
        }, 1);
        $constructor->defineOwnProperty('resolve', PropertyDescriptor::data($resolveFn, true, false, true));

        // Promise.reject(reason)
        $rejectFn = JsFunction::fromCallable('reject', function (JsValue $this_, array $args): JsValue {
            $reason = $args[0] ?? JsUndefined::instance();
            return JsPromise::rejected($reason);
        }, 1);
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
            $items = self::iterableToArray($args[0] ?? JsUndefined::instance());
            if (empty($items)) {
                // Empty iterable: return a forever-pending promise.
                return new JsPromise();
            }
            // Return the first settled promise.
            $first = self::coerceToPromise($items[0]);
            if ($first->getState() === JsPromise::STATE_REJECTED) {
                return JsPromise::rejected($first->getResolvedValue());
            }
            return JsPromise::resolved($first->getResolvedValue());
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
}
