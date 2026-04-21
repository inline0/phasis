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
    /** %IteratorHelperPrototype%: prototype for lazy wrapper iterators. */
    private static ?JsObject $iteratorHelperPrototype = null;

    /** %WrapForValidIteratorPrototype%: prototype for Iterator.from wrappers. */
    private static ?JsObject $wrapForValidIteratorPrototype = null;

    public static function install(Environment $env): void
    {
        $iteratorPrototype = $env->has('__IteratorPrototype__')
            ? $env->get('__IteratorPrototype__')
            : null;
        if (!$iteratorPrototype instanceof JsObject) {
            return;
        }

        // The Iterator constructor function.
        // Per spec: if NewTarget is undefined or the active function object, throw TypeError.
        // This means: Iterator() throws, new Iterator() throws, but class Sub extends Iterator {}
        // followed by new Sub() works.
        $iteratorCtor = JsFunction::fromCallable(
            'Iterator',
            function (JsValue $this_, array $args) use (&$iteratorCtor): JsValue {
                // Called without new: throw.
                if (!$this_ instanceof JsObject || $this_->get('[[NewTarget]]') instanceof JsUndefined) {
                    throw new TypeError('Iterator is not callable');
                }
                // Called with new Iterator() directly: throw.
                $ntDesc = $this_->getOwnPropertyDescriptor('[[NewTarget]]');
                if ($ntDesc !== null && $ntDesc->value === $iteratorCtor) {
                    throw new TypeError('Abstract class Iterator not directly constructable');
                }
                // Subclass: return the this object.
                return $this_;
            },
        );
        $iteratorCtor->setConstructable();

        // Iterator.prototype is the existing %IteratorPrototype%.
        // Per spec: non-writable, non-enumerable, non-configurable.
        $iteratorCtor->defineOwnProperty('prototype', PropertyDescriptor::data(
            $iteratorPrototype,
            false,
            false,
            false,
        ));

        // Iterator.prototype.constructor = Iterator
        // Per spec: writable true, enumerable false, configurable true.
        $iteratorPrototype->defineOwnProperty('constructor', PropertyDescriptor::data(
            $iteratorCtor,
            true,
            false,
            true,
        ));

        // Iterator.prototype[Symbol.toStringTag] = "Iterator"
        // Per spec: writable true, enumerable false, configurable true.
        $iteratorPrototype->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Iterator'), true, false, true),
        );

        // Create %IteratorHelperPrototype%.
        // Its [[Prototype]] is Iterator.prototype (%IteratorPrototype%).
        self::$iteratorHelperPrototype = new JsObject($iteratorPrototype);
        self::$iteratorHelperPrototype->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Iterator Helper'), false, false, true),
        );

        // Create %WrapForValidIteratorPrototype%.
        // Its [[Prototype]] is Iterator.prototype (%IteratorPrototype%).
        self::$wrapForValidIteratorPrototype = new JsObject($iteratorPrototype);

        // Install lazy prototype methods.
        self::installMethod($iteratorPrototype, 'map', self::mapMethod(), 1);
        self::installMethod($iteratorPrototype, 'filter', self::filterMethod(), 1);
        self::installMethod($iteratorPrototype, 'take', self::takeMethod(), 1);
        self::installMethod($iteratorPrototype, 'drop', self::dropMethod(), 1);
        self::installMethod($iteratorPrototype, 'flatMap', self::flatMapMethod(), 1);

        // Install eager prototype methods.
        self::installMethod($iteratorPrototype, 'reduce', self::reduceMethod(), 1);
        self::installMethod($iteratorPrototype, 'toArray', self::toArrayMethod(), 0);
        self::installMethod($iteratorPrototype, 'forEach', self::forEachMethod(), 1);
        self::installMethod($iteratorPrototype, 'some', self::someMethod(), 1);
        self::installMethod($iteratorPrototype, 'every', self::everyMethod(), 1);
        self::installMethod($iteratorPrototype, 'find', self::findMethod(), 1);

        // Iterator.from static method.
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

    /**
     * Install a method on the given prototype with correct property attributes.
     * Per spec: writable true, enumerable false, configurable true.
     * The function is non-constructable.
     */
    private static function installMethod(JsObject $proto, string $name, \Closure $cb, int $length): void
    {
        $fn = JsFunction::fromCallable($name, $cb, $length);
        $fn->setNonConstructable();
        $proto->defineOwnProperty($name, PropertyDescriptor::data($fn, true, false, true));
    }

    /**
     * Create an IteratorHelper object (lazy wrapper).
     *
     * The returned object has:
     * - [[Prototype]] = %IteratorHelperPrototype%
     * - next() that advances the underlying iterator through the given step closure
     * - return() that closes the underlying iterator
     *
     * @param JsObject $underlyingIterator The source iterator object.
     * @param JsFunction $underlyingNext The next method of the source iterator.
     * @param \Closure $step Called on each next(); receives (&$counter, &$done, &$alive) and returns {value, done} object.
     */
    private static function createIteratorHelper(
        JsObject $underlyingIterator,
        JsFunction $underlyingNext,
        \Closure $step,
    ): JsObject {
        $helper = new JsObject(self::$iteratorHelperPrototype);
        $done = false;
        $alive = true; // Tracks whether return() has been called.
        $running = false; // Prevents re-entrancy.

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
                $result = new JsObject();
                $result->set('value', JsUndefined::instance());
                $result->set('done', new JsBoolean(true));
                return $result;
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
            $result = new JsObject();
            $result->set('value', JsUndefined::instance());
            $result->set('done', new JsBoolean(true));
            if (!$done) {
                $done = true;
                // Call return on underlying iterator if it exists.
                $returnMethod = $underlyingIterator->get('return');
                if ($returnMethod instanceof JsFunction) {
                    return $returnMethod->call($underlyingIterator, []);
                }
            }
            return $result;
        }, 0);

        $helper->defineOwnProperty('next', PropertyDescriptor::data($nextFn, true, false, true));
        $helper->defineOwnProperty('return', PropertyDescriptor::data($returnFn, true, false, true));

        return $helper;
    }

    /**
     * Advance the underlying iterator by one step.
     * Returns [value, isDone]. If done, value is undefined.
     */
    private static function iteratorStep(
        JsObject $iterator,
        JsFunction $nextMethod,
        bool &$done,
    ): array {
        if ($done) {
            return [JsUndefined::instance(), true];
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

    /**
     * Create a {value, done} result object.
     */
    private static function iterResult(JsValue $value, bool $done): JsObject
    {
        $result = new JsObject();
        $result->set('value', $value);
        $result->set('done', new JsBoolean($done));
        return $result;
    }

    /**
     * Validate that the `this` value is an object and get its `next` method.
     * Per spec step 1-2 of each method: Let O = this. If O is not an Object, throw TypeError.
     * Step 3: Let nextMethod = ? GetMethod(O, "next").
     *
     * @return array{JsObject, JsFunction}
     */
    private static function validateThis(JsValue $this_, string $methodName): array
    {
        if (!$this_ instanceof JsObject) {
            throw new TypeError(
                "Iterator.prototype.{$methodName} called on non-object",
            );
        }
        $nextMethod = $this_->get('next');
        if (!$nextMethod instanceof JsFunction) {
            throw new TypeError(
                "{$methodName} requires that this has a callable next method",
            );
        }
        return [$this_, $nextMethod];
    }

    // -------------------------------------------------------------------------
    // Lazy methods (return IteratorHelper objects)
    // -------------------------------------------------------------------------

    private static function mapMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            [$itObj, $nextMethod] = self::validateThis($this_, 'map');
            $mapper = $args[0] ?? JsUndefined::instance();
            if (!$mapper instanceof JsFunction) {
                throw new TypeError('Iterator.prototype.map callback is not a function');
            }
            $counter = 0;
            $done = false;

            return self::createIteratorHelper($itObj, $nextMethod, function (
                bool &$done,
                bool &$alive,
            ) use (
                $itObj,
                $nextMethod,
                $mapper,
                &$counter,
            ): JsObject {
                [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                if ($isDone) {
                    $alive = false;
                    return self::iterResult(JsUndefined::instance(), true);
                }
                $mapped = $mapper->call(JsUndefined::instance(), [$value, new JsNumber((float) $counter)]);
                $counter++;
                return self::iterResult($mapped, false);
            });
        };
    }

    private static function filterMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            [$itObj, $nextMethod] = self::validateThis($this_, 'filter');
            $predicate = $args[0] ?? JsUndefined::instance();
            if (!$predicate instanceof JsFunction) {
                throw new TypeError('Iterator.prototype.filter callback is not a function');
            }
            $counter = 0;
            $done = false;

            return self::createIteratorHelper($itObj, $nextMethod, function (
                bool &$done,
                bool &$alive,
            ) use (
                $itObj,
                $nextMethod,
                $predicate,
                &$counter,
            ): JsObject {
                while (true) {
                    [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                    if ($isDone) {
                        $alive = false;
                        return self::iterResult(JsUndefined::instance(), true);
                    }
                    $selected = $predicate->call(
                        JsUndefined::instance(),
                        [$value, new JsNumber((float) $counter)],
                    );
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
            [$itObj, $nextMethod] = self::validateThis($this_, 'take');
            $limitArg = $args[0] ?? JsUndefined::instance();
            $numLimit = TypeConversion::toNumber($limitArg);
            if (is_nan($numLimit)) {
                throw new \PhpJs\Exceptions\RangeError('take argument must be a number');
            }
            $intLimit = (int) $numLimit;
            if ($intLimit < 0) {
                throw new \PhpJs\Exceptions\RangeError('take argument must be >= 0');
            }
            $remaining = $intLimit;
            $done = false;

            return self::createIteratorHelper($itObj, $nextMethod, function (
                bool &$done,
                bool &$alive,
            ) use (
                $itObj,
                $nextMethod,
                &$remaining,
            ): JsObject {
                if ($remaining <= 0) {
                    $done = true;
                    $alive = false;
                    // Close the underlying iterator.
                    $returnMethod = $itObj->get('return');
                    if ($returnMethod instanceof JsFunction) {
                        $returnMethod->call($itObj, []);
                    }
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
            [$itObj, $nextMethod] = self::validateThis($this_, 'drop');
            $limitArg = $args[0] ?? JsUndefined::instance();
            $numLimit = TypeConversion::toNumber($limitArg);
            if (is_nan($numLimit)) {
                throw new \PhpJs\Exceptions\RangeError('drop argument must be a number');
            }
            $intLimit = (int) $numLimit;
            if ($intLimit < 0) {
                throw new \PhpJs\Exceptions\RangeError('drop argument must be >= 0');
            }
            $toDrop = $intLimit;
            $dropped = false;
            $done = false;

            return self::createIteratorHelper($itObj, $nextMethod, function (
                bool &$done,
                bool &$alive,
            ) use (
                $itObj,
                $nextMethod,
                &$toDrop,
                &$dropped,
            ): JsObject {
                // Drop the first N elements.
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
            [$itObj, $nextMethod] = self::validateThis($this_, 'flatMap');
            $mapper = $args[0] ?? JsUndefined::instance();
            if (!$mapper instanceof JsFunction) {
                throw new TypeError('Iterator.prototype.flatMap callback is not a function');
            }
            $counter = 0;
            $done = false;

            // State for the inner iterator of the current mapped value.
            $innerIterator = null;
            $innerNext = null;
            $innerDone = true;

            return self::createIteratorHelper($itObj, $nextMethod, function (
                bool &$done,
                bool &$alive,
            ) use (
                $itObj,
                $nextMethod,
                $mapper,
                &$counter,
                &$innerIterator,
                &$innerNext,
                &$innerDone,
            ): JsObject {
                while (true) {
                    // If we have an active inner iterator, drain it.
                    if ($innerIterator !== null && !$innerDone) {
                        $innerResult = $innerNext->call($innerIterator, []);
                        if (!$innerResult instanceof JsObject) {
                            $innerDone = true;
                            throw new TypeError('Iterator result is not an object');
                        }
                        if (!TypeConversion::toBoolean($innerResult->get('done'))) {
                            return self::iterResult($innerResult->get('value'), false);
                        }
                        // Inner iterator exhausted, move to next outer value.
                        $innerIterator = null;
                        $innerNext = null;
                        $innerDone = true;
                    }

                    // Get next value from the outer iterator.
                    [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                    if ($isDone) {
                        $alive = false;
                        return self::iterResult(JsUndefined::instance(), true);
                    }

                    // Call the mapper.
                    $mapped = $mapper->call(
                        JsUndefined::instance(),
                        [$value, new JsNumber((float) $counter)],
                    );
                    $counter++;

                    // GetIteratorFlattenable(mapped): strings are not flattened.
                    // If mapped is an object with Symbol.iterator, use that.
                    // If mapped is an object with next, use it as iterator-like.
                    // If mapped is not an object, throw TypeError.
                    if ($mapped instanceof JsString) {
                        throw new TypeError(
                            'Iterator.prototype.flatMap mapper returned a string (not flattenable)',
                        );
                    }
                    if (!$mapped instanceof JsObject) {
                        throw new TypeError(
                            'Iterator.prototype.flatMap mapper must return an object or iterable',
                        );
                    }

                    // Try Symbol.iterator first (iterable protocol).
                    $iterSym = SymbolConstructor::iterator();
                    $iterMethod = $mapped->getBySymbol($iterSym);
                    if ($iterMethod instanceof JsFunction) {
                        $innerObj = $iterMethod->call($mapped, []);
                        if (!$innerObj instanceof JsObject) {
                            throw new TypeError('Result of the Symbol.iterator method is not an object');
                        }
                        $innerIterator = $innerObj;
                    } else {
                        // Fallback: treat as iterator-like (has next method).
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
    // Eager methods (consume the iterator and return a value)
    // -------------------------------------------------------------------------

    private static function reduceMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            [$itObj, $nextMethod] = self::validateThis($this_, 'reduce');
            $reducer = $args[0] ?? JsUndefined::instance();
            if (!$reducer instanceof JsFunction) {
                throw new TypeError('Iterator.prototype.reduce callback is not a function');
            }
            $hasInitial = count($args) >= 2;
            $accumulator = $hasInitial ? $args[1] : null;
            $done = false;
            $counter = 0;

            if (!$hasInitial) {
                // Use the first element as the initial value.
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
                $accumulator = $reducer->call(
                    JsUndefined::instance(),
                    [$accumulator, $value, new JsNumber((float) $counter)],
                );
                $counter++;
            }

            return $accumulator;
        };
    }

    private static function toArrayMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            [$itObj, $nextMethod] = self::validateThis($this_, 'toArray');
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
            [$itObj, $nextMethod] = self::validateThis($this_, 'forEach');
            $fn = $args[0] ?? JsUndefined::instance();
            if (!$fn instanceof JsFunction) {
                throw new TypeError('Iterator.prototype.forEach callback is not a function');
            }
            $done = false;
            $counter = 0;

            while (true) {
                [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                if ($isDone) {
                    break;
                }
                $fn->call(JsUndefined::instance(), [$value, new JsNumber((float) $counter)]);
                $counter++;
            }

            return JsUndefined::instance();
        };
    }

    private static function someMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            [$itObj, $nextMethod] = self::validateThis($this_, 'some');
            $predicate = $args[0] ?? JsUndefined::instance();
            if (!$predicate instanceof JsFunction) {
                throw new TypeError('Iterator.prototype.some callback is not a function');
            }
            $done = false;
            $counter = 0;

            while (true) {
                [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                if ($isDone) {
                    return new JsBoolean(false);
                }
                $result = $predicate->call(
                    JsUndefined::instance(),
                    [$value, new JsNumber((float) $counter)],
                );
                $counter++;
                if (TypeConversion::toBoolean($result)) {
                    // Close the underlying iterator.
                    self::closeIterator($itObj);
                    return new JsBoolean(true);
                }
            }
        };
    }

    private static function everyMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            [$itObj, $nextMethod] = self::validateThis($this_, 'every');
            $predicate = $args[0] ?? JsUndefined::instance();
            if (!$predicate instanceof JsFunction) {
                throw new TypeError('Iterator.prototype.every callback is not a function');
            }
            $done = false;
            $counter = 0;

            while (true) {
                [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                if ($isDone) {
                    return new JsBoolean(true);
                }
                $result = $predicate->call(
                    JsUndefined::instance(),
                    [$value, new JsNumber((float) $counter)],
                );
                $counter++;
                if (!TypeConversion::toBoolean($result)) {
                    // Close the underlying iterator.
                    self::closeIterator($itObj);
                    return new JsBoolean(false);
                }
            }
        };
    }

    private static function findMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            [$itObj, $nextMethod] = self::validateThis($this_, 'find');
            $predicate = $args[0] ?? JsUndefined::instance();
            if (!$predicate instanceof JsFunction) {
                throw new TypeError('Iterator.prototype.find callback is not a function');
            }
            $done = false;
            $counter = 0;

            while (true) {
                [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                if ($isDone) {
                    return JsUndefined::instance();
                }
                $result = $predicate->call(
                    JsUndefined::instance(),
                    [$value, new JsNumber((float) $counter)],
                );
                $counter++;
                if (TypeConversion::toBoolean($result)) {
                    // Close the underlying iterator.
                    self::closeIterator($itObj);
                    return $value;
                }
            }
        };
    }

    // -------------------------------------------------------------------------
    // Iterator.from static method
    // -------------------------------------------------------------------------

    /**
     * Iterator.from(obj):
     * - If obj is already an Iterator (instanceof Iterator), return it.
     * - If obj is iterable (has Symbol.iterator), get iterator, if it's already an Iterator return it,
     *   otherwise wrap it.
     * - If obj is iterator-like (has next), wrap it.
     * - Otherwise throw TypeError.
     */
    private static function fromMethod(JsObject $iteratorPrototype): \Closure
    {
        return function (JsValue $this_, array $args) use ($iteratorPrototype): JsValue {
            $obj = $args[0] ?? JsUndefined::instance();

            // Step 1: If Type(O) is not Object, throw a TypeError.
            // Per spec: "Let O be ? GetIteratorDirect(obj)" but that includes
            // checking for primitive iterables by wrapping via ToObject.
            // Actually the spec says:
            //   1. If O is a String, let O = ? ToObject(O).
            // Let's handle strings and other primitives.
            if ($obj instanceof JsString) {
                $obj = TypeConversion::toObject($obj);
            } elseif (!$obj instanceof JsObject) {
                throw new TypeError('Iterator.from requires an object or iterable');
            }

            // Check if obj has Symbol.iterator (iterable protocol).
            $iterSym = SymbolConstructor::iterator();
            $iterMethod = $obj->getBySymbol($iterSym);

            if ($iterMethod instanceof JsFunction) {
                // Get the iterator.
                $iterator = $iterMethod->call($obj, []);
                if (!$iterator instanceof JsObject) {
                    throw new TypeError('Result of the Symbol.iterator method is not an object');
                }
                // If the iterator already inherits from Iterator.prototype, return it as-is.
                if (self::isIteratorInstance($iterator, $iteratorPrototype)) {
                    return $iterator;
                }
                // Wrap it.
                return self::createWrapForValidIterator($iterator);
            }

            // Iterator-like: has a next method.
            $nextMethod = $obj->get('next');
            if ($nextMethod instanceof JsFunction) {
                return self::createWrapForValidIterator($obj);
            }

            throw new TypeError('Iterator.from requires an iterable or iterator-like object');
        };
    }

    /**
     * Check if an object inherits from %IteratorPrototype% (Iterator.prototype).
     */
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

    /**
     * Create a WrapForValidIterator object.
     * Its [[Prototype]] is %WrapForValidIteratorPrototype%.
     * It delegates next() and return() to the underlying iterator.
     */
    private static function createWrapForValidIterator(JsObject $iterator): JsObject
    {
        $wrapper = new JsObject(self::$wrapForValidIteratorPrototype);

        // Get the next method once, per spec.
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
            $result = new JsObject();
            $result->set('value', JsUndefined::instance());
            $result->set('done', new JsBoolean(true));
            return $result;
        }, 0);

        $wrapper->defineOwnProperty('next', PropertyDescriptor::data($nextFn, true, false, true));
        $wrapper->defineOwnProperty('return', PropertyDescriptor::data($returnFn, true, false, true));

        return $wrapper;
    }

    /**
     * Close an iterator by calling its return() method if it exists.
     * Used by eager methods (some, every, find) when short-circuiting.
     */
    private static function closeIterator(JsObject $iterator): void
    {
        $returnMethod = $iterator->get('return');
        if ($returnMethod instanceof JsFunction) {
            $returnMethod->call($iterator, []);
        }
    }
}
