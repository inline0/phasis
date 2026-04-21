<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Exceptions\TypeError;
use PhpJs\Object\PropertyDescriptor;
use PhpJs\Runtime\Environment;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsArray;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

/**
 * Iterator constructor and Iterator.prototype helper methods.
 *
 * Per the iterator-helpers proposal:
 * - Iterator is an abstract constructor: calling it directly or via new Iterator() throws TypeError.
 * - It is designed to be subclassable: class Sub extends Iterator {} works.
 * - Iterator.prototype has lazy methods (map, filter, take, drop, flatMap) that return
 *   IteratorHelper objects, and eager methods (reduce, toArray, forEach, some, every, find).
 * - Iterator.from(obj) wraps iterables or iterator-like objects into proper Iterator instances.
 */
class IteratorConstructor
{
    private static ?JsObject $iteratorHelperPrototype = null;
    private static ?JsObject $wrapForValidIteratorPrototype = null;

    public static function install(Environment $env): void
    {
        $iteratorPrototype = $env->has('__IteratorPrototype__')
            ? $env->get('__IteratorPrototype__')
            : null;
        if (!$iteratorPrototype instanceof JsObject) {
            return;
        }

        $iteratorCtor = JsFunction::fromCallable(
            'Iterator',
            function (JsValue $this_, array $args) use (&$iteratorCtor): JsValue {
                if (!$this_ instanceof JsObject || $this_->get('[[NewTarget]]') instanceof JsUndefined) {
                    throw new TypeError('Iterator is not callable');
                }
                $ntDesc = $this_->getOwnPropertyDescriptor('[[NewTarget]]');
                if ($ntDesc !== null && $ntDesc->value === $iteratorCtor) {
                    throw new TypeError('Abstract class Iterator not directly constructable');
                }
                return $this_;
            },
        );
        $iteratorCtor->setConstructable();

        $iteratorCtor->defineOwnProperty('prototype', PropertyDescriptor::data(
            $iteratorPrototype,
            false,
            false,
            false,
        ));

        $iteratorPrototype->defineOwnProperty('constructor', PropertyDescriptor::data(
            $iteratorCtor,
            true,
            false,
            true,
        ));

        $iteratorPrototype->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Iterator'), true, false, true),
        );

        self::$iteratorHelperPrototype = new JsObject($iteratorPrototype);
        self::$iteratorHelperPrototype->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Iterator Helper'), false, false, true),
        );

        self::$wrapForValidIteratorPrototype = new JsObject($iteratorPrototype);

        self::installMethod($iteratorPrototype, 'map', self::mapMethod(), 1);
        self::installMethod($iteratorPrototype, 'filter', self::filterMethod(), 1);
        self::installMethod($iteratorPrototype, 'take', self::takeMethod(), 1);
        self::installMethod($iteratorPrototype, 'drop', self::dropMethod(), 1);
        self::installMethod($iteratorPrototype, 'flatMap', self::flatMapMethod(), 1);

        self::installMethod($iteratorPrototype, 'reduce', self::reduceMethod(), 1);
        self::installMethod($iteratorPrototype, 'toArray', self::toArrayMethod(), 0);
        self::installMethod($iteratorPrototype, 'forEach', self::forEachMethod(), 1);
        self::installMethod($iteratorPrototype, 'some', self::someMethod(), 1);
        self::installMethod($iteratorPrototype, 'every', self::everyMethod(), 1);
        self::installMethod($iteratorPrototype, 'find', self::findMethod(), 1);

        $fromFn = JsFunction::fromCallable('from', self::fromMethod($iteratorPrototype), 1);
        $fromFn->setNonConstructable();
        $iteratorCtor->defineOwnProperty('from', PropertyDescriptor::data(
            $fromFn,
            true,
            false,
            true,
        ));

        $env->defineVar('Iterator', $iteratorCtor);
    }

    private static function installMethod(
        JsObject $proto,
        string $name,
        \Closure $cb,
        int $length,
    ): void {
        $fn = JsFunction::fromCallable($name, $cb, $length);
        $fn->setNonConstructable();
        $proto->defineOwnProperty($name, PropertyDescriptor::data($fn, true, false, true));
    }

    /**
     * GetIteratorDirect(O): per spec, gets O.next and returns the iterator record.
     * Does NOT validate that next is callable here; that is deferred for lazy methods.
     *
     * @return array{JsObject, JsValue} [iterator, nextMethod]
     */
    private static function getIteratorDirect(JsObject $obj): array
    {
        $nextMethod = $obj->get('next');
        return [$obj, $nextMethod];
    }

    private static function createIteratorHelper(
        JsObject $underlyingIterator,
        JsValue $underlyingNext,
        \Closure $step,
    ): JsObject {
        $helper = new JsObject(self::$iteratorHelperPrototype);
        $done = false;
        $alive = true;
        $running = false;

        $nextFn = JsFunction::fromCallable('next', function (
            JsValue $this_,
            array $args,
        ) use (
            &$done,
            &$alive,
            &$running,
            $step,
        ): JsValue {
            if ($running) {
                throw new TypeError('Cannot call next on a running iterator helper');
            }
            if (!$alive || $done) {
                return self::iterResult(JsUndefined::instance(), true);
            }
            $running = true;
            try {
                $result = $step($done, $alive);
            } catch (\Throwable $e) {
                $done = true;
                $alive = false;
                $running = false;
                throw $e;
            }
            $running = false;
            return $result;
        }, 0);

        $returnFn = JsFunction::fromCallable('return', function (
            JsValue $this_,
            array $args,
        ) use (
            $underlyingIterator,
            &$done,
            &$alive,
            &$running,
        ): JsValue {
            if ($running) {
                throw new TypeError('Cannot call return on a running iterator helper');
            }
            $alive = false;
            if (!$done) {
                $done = true;
                $returnMethod = $underlyingIterator->get('return');
                if ($returnMethod instanceof JsFunction) {
                    return $returnMethod->call($underlyingIterator, []);
                }
            }
            return self::iterResult(JsUndefined::instance(), true);
        }, 0);

        $helper->defineOwnProperty('next', PropertyDescriptor::data($nextFn, true, false, true));
        $helper->defineOwnProperty('return', PropertyDescriptor::data($returnFn, true, false, true));

        return $helper;
    }

    private static function iteratorStep(
        JsObject $iterator,
        JsValue $nextMethod,
        bool &$done,
    ): array {
        if ($done) {
            return [JsUndefined::instance(), true];
        }
        if (!$nextMethod instanceof JsFunction) {
            $done = true;
            throw new TypeError('Iterator next is not a function');
        }
        $result = $nextMethod->call($iterator, []);
        if (!$result instanceof JsObject) {
            $done = true;
            throw new TypeError('Iterator result is not an object');
        }
        if (TypeConversion::toBoolean($result->get('done'))) {
            $done = true;
            return [JsUndefined::instance(), true];
        }
        return [$result->get('value'), false];
    }

    private static function iterResult(JsValue $value, bool $done): JsObject
    {
        $result = new JsObject();
        $result->set('value', $value);
        $result->set('done', new JsBoolean($done));
        return $result;
    }

    private static function closeIterator(JsObject $iterator): void
    {
        $returnMethod = $iterator->get('return');
        if ($returnMethod instanceof JsFunction) {
            $returnMethod->call($iterator, []);
        }
    }

    /**
     * Per spec, converts limit to a non-negative integer or +Infinity.
     * Throws RangeError for NaN or negative values.
     */
    private static function toIntegerOrInfinityNonNegative(JsValue $limitArg): float
    {
        $numLimit = TypeConversion::toNumber($limitArg);
        if (is_nan($numLimit)) {
            throw new \PhpJs\Exceptions\RangeError('Invalid limit');
        }
        if ($numLimit === INF) {
            return INF;
        }
        $intLimit = $numLimit >= 0 ? floor($numLimit) : ceil($numLimit);
        if ($intLimit < 0) {
            throw new \PhpJs\Exceptions\RangeError('Invalid limit');
        }
        return $intLimit;
    }

    // -------------------------------------------------------------------------
    // Lazy methods: per spec, argument validation happens BEFORE GetIteratorDirect.
    // The next method is obtained lazily; its callability is checked on first use.
    // -------------------------------------------------------------------------

    private static function mapMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            // 1. Let O be the this value.
            // 2. If O is not an Object, throw TypeError.
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Iterator.prototype.map called on non-object');
            }
            $mapper = $args[0] ?? JsUndefined::instance();
            // 3. If IsCallable(mapper) is false, throw TypeError.
            if (!$mapper instanceof JsFunction) {
                throw new TypeError('Iterator.prototype.map callback is not a function');
            }
            // 4. Let iterated be ? GetIteratorDirect(O).
            [$itObj, $nextMethod] = self::getIteratorDirect($this_);
            $counter = 0;
            $done = false;

            return self::createIteratorHelper($itObj, $nextMethod, function (
                bool &$done,
                bool &$alive,
            ) use (
                $itObj,
                &$nextMethod,
                $mapper,
                &$counter,
            ): JsObject {
                [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                if ($isDone) {
                    $alive = false;
                    return self::iterResult(JsUndefined::instance(), true);
                }
                try {
                    $mapped = $mapper->call(
                        JsUndefined::instance(),
                        [$value, new JsNumber((float) $counter)],
                    );
                } catch (\Throwable $e) {
                    // Close the underlying iterator on mapper throw.
                    self::closeIterator($itObj);
                    throw $e;
                }
                $counter++;
                return self::iterResult($mapped, false);
            });
        };
    }

    private static function filterMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Iterator.prototype.filter called on non-object');
            }
            $predicate = $args[0] ?? JsUndefined::instance();
            if (!$predicate instanceof JsFunction) {
                throw new TypeError('Iterator.prototype.filter callback is not a function');
            }
            [$itObj, $nextMethod] = self::getIteratorDirect($this_);
            $counter = 0;
            $done = false;

            return self::createIteratorHelper($itObj, $nextMethod, function (
                bool &$done,
                bool &$alive,
            ) use (
                $itObj,
                &$nextMethod,
                $predicate,
                &$counter,
            ): JsObject {
                while (true) {
                    [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                    if ($isDone) {
                        $alive = false;
                        return self::iterResult(JsUndefined::instance(), true);
                    }
                    try {
                        $selected = $predicate->call(
                            JsUndefined::instance(),
                            [$value, new JsNumber((float) $counter)],
                        );
                    } catch (\Throwable $e) {
                        self::closeIterator($itObj);
                        throw $e;
                    }
                    $counter++;
                    if (TypeConversion::toBoolean($selected)) {
                        return self::iterResult($value, false);
                    }
                }
            });
        };
    }

    private static function takeMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Iterator.prototype.take called on non-object');
            }
            // Per spec: ToNumber(limit) happens before GetIteratorDirect.
            $limitArg = $args[0] ?? JsUndefined::instance();
            $numLimit = self::toIntegerOrInfinityNonNegative($limitArg);
            [$itObj, $nextMethod] = self::getIteratorDirect($this_);
            $remaining = $numLimit;
            $done = false;

            return self::createIteratorHelper($itObj, $nextMethod, function (
                bool &$done,
                bool &$alive,
            ) use (
                $itObj,
                &$nextMethod,
                &$remaining,
            ): JsObject {
                if ($remaining <= 0) {
                    $done = true;
                    $alive = false;
                    self::closeIterator($itObj);
                    return self::iterResult(JsUndefined::instance(), true);
                }
                [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                if ($isDone) {
                    $alive = false;
                    return self::iterResult(JsUndefined::instance(), true);
                }
                $remaining--;
                return self::iterResult($value, false);
            });
        };
    }

    private static function dropMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Iterator.prototype.drop called on non-object');
            }
            $limitArg = $args[0] ?? JsUndefined::instance();
            $numLimit = self::toIntegerOrInfinityNonNegative($limitArg);
            [$itObj, $nextMethod] = self::getIteratorDirect($this_);
            $toDrop = $numLimit;
            $dropped = false;
            $done = false;

            return self::createIteratorHelper($itObj, $nextMethod, function (
                bool &$done,
                bool &$alive,
            ) use (
                $itObj,
                &$nextMethod,
                &$toDrop,
                &$dropped,
            ): JsObject {
                while (!$dropped) {
                    if ($toDrop <= 0) {
                        $dropped = true;
                        break;
                    }
                    [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                    if ($isDone) {
                        $alive = false;
                        return self::iterResult(JsUndefined::instance(), true);
                    }
                    $toDrop--;
                }
                [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                if ($isDone) {
                    $alive = false;
                    return self::iterResult(JsUndefined::instance(), true);
                }
                return self::iterResult($value, false);
            });
        };
    }

    private static function flatMapMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Iterator.prototype.flatMap called on non-object');
            }
            $mapper = $args[0] ?? JsUndefined::instance();
            if (!$mapper instanceof JsFunction) {
                throw new TypeError('Iterator.prototype.flatMap callback is not a function');
            }
            [$itObj, $nextMethod] = self::getIteratorDirect($this_);
            $counter = 0;
            $done = false;
            $innerIterator = null;
            $innerNext = null;
            $innerDone = true;

            return self::createIteratorHelper($itObj, $nextMethod, function (
                bool &$done,
                bool &$alive,
            ) use (
                $itObj,
                &$nextMethod,
                $mapper,
                &$counter,
                &$innerIterator,
                &$innerNext,
                &$innerDone,
            ): JsObject {
                while (true) {
                    if ($innerIterator !== null && !$innerDone) {
                        $innerResult = $innerNext->call($innerIterator, []);
                        if (!$innerResult instanceof JsObject) {
                            $innerDone = true;
                            throw new TypeError('Iterator result is not an object');
                        }
                        if (!TypeConversion::toBoolean($innerResult->get('done'))) {
                            return self::iterResult($innerResult->get('value'), false);
                        }
                        $innerIterator = null;
                        $innerNext = null;
                        $innerDone = true;
                    }

                    [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                    if ($isDone) {
                        $alive = false;
                        return self::iterResult(JsUndefined::instance(), true);
                    }

                    try {
                        $mapped = $mapper->call(
                            JsUndefined::instance(),
                            [$value, new JsNumber((float) $counter)],
                        );
                    } catch (\Throwable $e) {
                        self::closeIterator($itObj);
                        throw $e;
                    }
                    $counter++;

                    // GetIteratorFlattenable: strings are not flattenable.
                    if ($mapped instanceof JsString) {
                        throw new TypeError(
                            'Iterator.prototype.flatMap mapper returned a string',
                        );
                    }
                    if (!$mapped instanceof JsObject) {
                        throw new TypeError(
                            'Iterator.prototype.flatMap mapper must return an object',
                        );
                    }

                    // Check for Symbol.iterator (iterable protocol) first.
                    $iterSym = SymbolConstructor::iterator();
                    $iterMethod = $mapped->getBySymbol($iterSym);
                    if ($iterMethod instanceof JsFunction) {
                        $innerObj = $iterMethod->call($mapped, []);
                        if (!$innerObj instanceof JsObject) {
                            throw new TypeError(
                                'Result of the Symbol.iterator method is not an object',
                            );
                        }
                        $innerIterator = $innerObj;
                    } else {
                        $innerIterator = $mapped;
                    }

                    $nxMethod = $innerIterator->get('next');
                    if (!$nxMethod instanceof JsFunction) {
                        throw new TypeError('flatMap inner value does not have a next method');
                    }
                    $innerNext = $nxMethod;
                    $innerDone = false;
                }
            });
        };
    }

    // -------------------------------------------------------------------------
    // Eager methods: per spec order is:
    // 1. Let O be the this value. If not Object, throw TypeError.
    // 2. If IsCallable(fn) is false, throw TypeError (close O first).
    // 3. Let iterated be ? GetIteratorDirect(O).
    //
    // When callback throws during iteration, close the underlying iterator.
    // -------------------------------------------------------------------------

    /**
     * Validate args for eager methods. Per spec:
     * 1. Check O is object.
     * 2. Check callback is callable; if not, close O and throw.
     * 3. GetIteratorDirect(O).
     *
     * @return array{JsObject, JsFunction, JsFunction} [itObj, nextMethod, callback]
     */
    private static function validateEager(
        JsValue $this_,
        string $methodName,
        JsValue $fn,
        bool $requireCallable = true,
    ): array {
        if (!$this_ instanceof JsObject) {
            throw new TypeError("Iterator.prototype.{$methodName} called on non-object");
        }
        if ($requireCallable && !$fn instanceof JsFunction) {
            // Per spec: close the iterator before throwing.
            self::closeIterator($this_);
            throw new TypeError("Iterator.prototype.{$methodName} callback is not a function");
        }
        [$itObj, $nextMethod] = self::getIteratorDirect($this_);
        if (!$nextMethod instanceof JsFunction) {
            throw new TypeError("Iterator next is not a function");
        }
        return [$itObj, $nextMethod, $fn instanceof JsFunction ? $fn : null];
    }

    private static function reduceMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $reducer = $args[0] ?? JsUndefined::instance();
            [$itObj, $nextMethod] = self::validateEager($this_, 'reduce', $reducer);
            /** @var JsFunction $reducer */
            $hasInitial = count($args) >= 2;
            $accumulator = $hasInitial ? $args[1] : null;
            $done = false;
            $counter = 0;

            if (!$hasInitial) {
                [$first, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                if ($isDone) {
                    throw new TypeError('Reduce of empty iterator with no initial value');
                }
                $accumulator = $first;
                $counter = 1;
            }

            while (true) {
                [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                if ($isDone) {
                    break;
                }
                try {
                    $accumulator = $reducer->call(
                        JsUndefined::instance(),
                        [$accumulator, $value, new JsNumber((float) $counter)],
                    );
                } catch (\Throwable $e) {
                    self::closeIterator($itObj);
                    throw $e;
                }
                $counter++;
            }

            return $accumulator;
        };
    }

    private static function toArrayMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Iterator.prototype.toArray called on non-object');
            }
            [$itObj, $nextMethod] = self::getIteratorDirect($this_);
            if (!$nextMethod instanceof JsFunction) {
                throw new TypeError('Iterator next is not a function');
            }
            $done = false;
            $items = [];

            while (true) {
                [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                if ($isDone) {
                    break;
                }
                $items[] = $value;
            }

            return JsArray::fromArray($items);
        };
    }

    private static function forEachMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $fn = $args[0] ?? JsUndefined::instance();
            [$itObj, $nextMethod] = self::validateEager($this_, 'forEach', $fn);
            /** @var JsFunction $fn */
            $done = false;
            $counter = 0;

            while (true) {
                [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                if ($isDone) {
                    break;
                }
                try {
                    $fn->call(JsUndefined::instance(), [$value, new JsNumber((float) $counter)]);
                } catch (\Throwable $e) {
                    self::closeIterator($itObj);
                    throw $e;
                }
                $counter++;
            }

            return JsUndefined::instance();
        };
    }

    private static function someMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $predicate = $args[0] ?? JsUndefined::instance();
            [$itObj, $nextMethod] = self::validateEager($this_, 'some', $predicate);
            /** @var JsFunction $predicate */
            $done = false;
            $counter = 0;

            while (true) {
                [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                if ($isDone) {
                    return new JsBoolean(false);
                }
                try {
                    $result = $predicate->call(
                        JsUndefined::instance(),
                        [$value, new JsNumber((float) $counter)],
                    );
                } catch (\Throwable $e) {
                    self::closeIterator($itObj);
                    throw $e;
                }
                $counter++;
                if (TypeConversion::toBoolean($result)) {
                    self::closeIterator($itObj);
                    return new JsBoolean(true);
                }
            }
        };
    }

    private static function everyMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $predicate = $args[0] ?? JsUndefined::instance();
            [$itObj, $nextMethod] = self::validateEager($this_, 'every', $predicate);
            /** @var JsFunction $predicate */
            $done = false;
            $counter = 0;

            while (true) {
                [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                if ($isDone) {
                    return new JsBoolean(true);
                }
                try {
                    $result = $predicate->call(
                        JsUndefined::instance(),
                        [$value, new JsNumber((float) $counter)],
                    );
                } catch (\Throwable $e) {
                    self::closeIterator($itObj);
                    throw $e;
                }
                $counter++;
                if (!TypeConversion::toBoolean($result)) {
                    self::closeIterator($itObj);
                    return new JsBoolean(false);
                }
            }
        };
    }

    private static function findMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $predicate = $args[0] ?? JsUndefined::instance();
            [$itObj, $nextMethod] = self::validateEager($this_, 'find', $predicate);
            /** @var JsFunction $predicate */
            $done = false;
            $counter = 0;

            while (true) {
                [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                if ($isDone) {
                    return JsUndefined::instance();
                }
                try {
                    $result = $predicate->call(
                        JsUndefined::instance(),
                        [$value, new JsNumber((float) $counter)],
                    );
                } catch (\Throwable $e) {
                    self::closeIterator($itObj);
                    throw $e;
                }
                $counter++;
                if (TypeConversion::toBoolean($result)) {
                    self::closeIterator($itObj);
                    return $value;
                }
            }
        };
    }

    // -------------------------------------------------------------------------
    // Iterator.from
    // -------------------------------------------------------------------------

    private static function fromMethod(JsObject $iteratorPrototype): \Closure
    {
        return function (JsValue $this_, array $args) use ($iteratorPrototype): JsValue {
            $obj = $args[0] ?? JsUndefined::instance();

            // Per spec: If O is a String, set O to ! ToObject(O).
            // Other primitives are NOT auto-boxed; they throw TypeError.
            if ($obj instanceof JsString) {
                $obj = TypeConversion::toObject($obj);
            } elseif (!$obj instanceof JsObject) {
                throw new TypeError('Iterator.from requires an object argument');
            }

            // GetIteratorFlattenable(O, iterate-strings).
            // Check for Symbol.iterator (iterable protocol).
            $iterSym = SymbolConstructor::iterator();
            $iterMethod = $obj->getBySymbol($iterSym);

            if ($iterMethod instanceof JsFunction) {
                $iterator = $iterMethod->call($obj, []);
                if (!$iterator instanceof JsObject) {
                    throw new TypeError('Result of the Symbol.iterator method is not an object');
                }
                // If the iterator already inherits from Iterator.prototype, return it as-is.
                if (self::isIteratorInstance($iterator, $iteratorPrototype)) {
                    return $iterator;
                }
                return self::createWrapForValidIterator($iterator);
            }

            // Iterator-like: wrap it regardless of whether it has a next method.
            // The wrapper's next() will throw if next is not callable.
            return self::createWrapForValidIterator($obj);
        };
    }

    private static function isIteratorInstance(JsObject $obj, JsObject $iteratorPrototype): bool
    {
        $proto = $obj->getPrototype();
        while ($proto !== null) {
            if ($proto === $iteratorPrototype) {
                return true;
            }
            $proto = $proto->getPrototype();
        }
        return false;
    }

    private static function createWrapForValidIterator(JsObject $iterator): JsObject
    {
        $wrapper = new JsObject(self::$wrapForValidIteratorPrototype);
        $nextMethod = $iterator->get('next');

        $nextFn = JsFunction::fromCallable('next', function (
            JsValue $this_,
            array $args,
        ) use (
            $iterator,
            $nextMethod,
        ): JsValue {
            if (!$nextMethod instanceof JsFunction) {
                throw new TypeError('Iterator next is not a function');
            }
            $result = $nextMethod->call($iterator, $args);
            if (!$result instanceof JsObject) {
                throw new TypeError('Iterator result is not an object');
            }
            return $result;
        }, 0);

        $returnFn = JsFunction::fromCallable('return', function (
            JsValue $this_,
            array $args,
        ) use (
            $iterator,
        ): JsValue {
            $returnMethod = $iterator->get('return');
            if ($returnMethod instanceof JsFunction) {
                return $returnMethod->call($iterator, []);
            }
            return self::iterResult(JsUndefined::instance(), true);
        }, 0);

        $wrapper->defineOwnProperty('next', PropertyDescriptor::data($nextFn, true, false, true));
        $wrapper->defineOwnProperty('return', PropertyDescriptor::data($returnFn, true, false, true));

        return $wrapper;
    }
}
