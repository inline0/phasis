<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Exceptions\RangeError;
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
use PhpJs\Value\JsSet;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

/**
 * Set constructor and prototype methods.
 *
 * new Set() creates an empty set.
 * new Set(iterable) creates a set from iterable values.
 */
class SetConstructor
{
    /** %SetIteratorPrototype%: shared prototype for all set iterators. */
    private static ?JsObject $setIteratorPrototype = null;

    public static function install(Environment $env): void
    {
        self::resetSetIteratorPrototype();
        $iteratorPrototype = $env->has('__IteratorPrototype__')
            ? $env->get('__IteratorPrototype__')
            : null;
        self::getSetIteratorPrototype(
            $iteratorPrototype instanceof JsObject ? $iteratorPrototype : null,
        );

        $proto = self::createPrototype();

        $constructor = JsFunction::fromCallable(
            'Set',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                // Set must be called with new
                if (!$this_ instanceof JsObject || $this_->get('[[NewTarget]]') instanceof JsUndefined) {
                    throw new TypeError('Constructor Set requires \'new\'');
                }
                $set = new JsSet($proto);
                self::populateFromArgs($set, $args);
                return $set;
            },
        );
        $constructor->setConstructable();

        $constructor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($constructor, true, false, true),
        );

        // Set[@@species] per spec: accessor property, getter returns `this`.
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

        $env->defineVar('Set', $constructor);
    }

    /** Reset the shared set iterator prototype (for engine reset). */
    public static function resetSetIteratorPrototype(): void
    {
        self::$setIteratorPrototype = null;
    }

    /**
     * Get or create the %SetIteratorPrototype% intrinsic.
     */
    public static function getSetIteratorPrototype(?JsObject $iteratorPrototype = null): JsObject
    {
        if (self::$setIteratorPrototype !== null) {
            return self::$setIteratorPrototype;
        }

        $proto = new JsObject($iteratorPrototype);

        $nextFn = JsFunction::fromCallable('next', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError(
                    'Method Set Iterator.prototype.next called on incompatible receiver',
                );
            }
            $slotDesc = $this_->getOwnPropertyDescriptor('[[SetIteratorData]]');
            if ($slotDesc === null) {
                throw new TypeError(
                    'Method Set Iterator.prototype.next called on incompatible receiver',
                );
            }
            $data = $slotDesc->value;
            $isExhausted = $data instanceof JsObject
                && $data->get('exhausted') instanceof JsBoolean
                && $data->get('exhausted')->value;
            if (!$data instanceof JsObject || $isExhausted) {
                $result = new JsObject();
                $result->set('value', JsUndefined::instance());
                $result->set('done', new JsBoolean(true));
                return $result;
            }
            $set = $data->get('set');
            if (!$set instanceof JsSet) {
                $result = new JsObject();
                $result->set('value', JsUndefined::instance());
                $result->set('done', new JsBoolean(true));
                return $result;
            }
            $kind = ($data->get('kind') instanceof JsString) ? $data->get('kind')->value : 'value';
            $indexVal = $data->get('index');
            $index = ($indexVal instanceof JsNumber) ? (int) $indexVal->value : 0;

            $result = new JsObject();
            while ($index < $set->slotCount()) {
                $value = $set->getSlot($index);
                $index++;
                $data->set('index', new JsNumber((float) $index));
                if ($value !== null) {
                    $result->set('done', new JsBoolean(false));
                    $result->set('value', match ($kind) {
                        'key+value' => JsArray::fromArray([$value, $value]),
                        default => $value,
                    });
                    return $result;
                }
            }
            // Mark as exhausted so subsequent calls stay done.
            $data->set('exhausted', new JsBoolean(true));
            $result->set('value', JsUndefined::instance());
            $result->set('done', new JsBoolean(true));
            return $result;
        }, 0);
        $nextFn->setNonConstructable();
        $proto->defineOwnProperty('next', PropertyDescriptor::data($nextFn, true, false, true));

        // Symbol.toStringTag = "Set Iterator".
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Set Iterator'), false, false, true),
        );

        self::$setIteratorPrototype = $proto;
        return $proto;
    }

    /**
     * Populate a Set from constructor arguments (iterable of values).
     *
     * @param list<JsValue> $args
     */
    private static function populateFromArgs(JsSet $set, array $args): void
    {
        if (empty($args)) {
            return;
        }

        $iterable = $args[0];
        if ($iterable instanceof JsUndefined || $iterable instanceof JsNull) {
            return;
        }

        // Per spec, get the `add` method before iterating.
        $adder = $set->get('add');
        if (!$adder instanceof JsFunction) {
            throw new TypeError('Set.prototype.add is not a function');
        }

        // Per spec, get the iterator. Must throw TypeError if Symbol.iterator is not callable.
        $iterSym = SymbolConstructor::iterator();
        if ($iterable instanceof JsObject) {
            $iteratorMethod = $iterable->getBySymbol($iterSym);
        } else {
            throw new TypeError('object is not iterable');
        }

        if ($iteratorMethod instanceof JsUndefined || $iteratorMethod instanceof JsNull) {
            throw new TypeError('object is not iterable');
        }
        if (!$iteratorMethod instanceof JsFunction) {
            throw new TypeError('object is not iterable');
        }

        $iterator = $iteratorMethod->call($iterable, []);
        if (!$iterator instanceof JsObject) {
            throw new TypeError('object is not iterable');
        }

        $nextMethod = $iterator->get('next');
        if (!$nextMethod instanceof JsFunction) {
            throw new TypeError('object is not iterable');
        }

        while (true) {
            $result = $nextMethod->call($iterator, []);
            if (!$result instanceof JsObject) {
                break;
            }
            if (TypeConversion::toBoolean($result->get('done'))) {
                break;
            }
            try {
                $adder->call($set, [$result->get('value')]);
            } catch (\Throwable $e) {
                // Per spec, close the iterator on abrupt completion.
                self::closeIterator($iterator);
                throw $e;
            }
        }
    }

    /** Close an iterator by calling its return method if present. */
    private static function closeIterator(JsObject $iterator): void
    {
        $returnMethod = $iterator->get('return');
        if ($returnMethod instanceof JsFunction) {
            try {
                $returnMethod->call($iterator, []);
            } catch (\Throwable $e) {
                // Per spec, ignore errors from closing the iterator.
            }
        }
    }

    private static function createPrototype(): JsObject
    {
        $proto = new JsObject();

        // All prototype methods are non-enumerable per spec
        // (writable: true, enumerable: false, configurable: true).

        $addFn = JsFunction::fromCallable('add', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Method Set.prototype.add called on incompatible receiver');
            }
            $value = $args[0] ?? JsUndefined::instance();
            $this_->setAdd($value);
            return $this_;
        }, 1);
        $proto->defineOwnProperty('add', PropertyDescriptor::data($addFn, true, false, true));

        $hasFn = JsFunction::fromCallable('has', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Method Set.prototype.has called on incompatible receiver');
            }
            $value = $args[0] ?? JsUndefined::instance();
            return new JsBoolean($this_->setHas($value));
        }, 1);
        $proto->defineOwnProperty('has', PropertyDescriptor::data($hasFn, true, false, true));

        $deleteFn = JsFunction::fromCallable('delete', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Method Set.prototype.delete called on incompatible receiver');
            }
            $value = $args[0] ?? JsUndefined::instance();
            return new JsBoolean($this_->setDelete($value));
        }, 1);
        $proto->defineOwnProperty('delete', PropertyDescriptor::data($deleteFn, true, false, true));

        $clearFn = JsFunction::fromCallable('clear', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Method Set.prototype.clear called on incompatible receiver');
            }
            $this_->setClear();
            return JsUndefined::instance();
        }, 0);
        $proto->defineOwnProperty('clear', PropertyDescriptor::data($clearFn, true, false, true));

        $forEachFn = JsFunction::fromCallable('forEach', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Method Set.prototype.forEach called on incompatible receiver');
            }
            $callback = $args[0] ?? null;
            if (!$callback instanceof JsFunction) {
                throw new TypeError('Set.prototype.forEach callback is not a function');
            }
            $thisArg = $args[1] ?? JsUndefined::instance();
            $this_->setForEach($callback, $thisArg);
            return JsUndefined::instance();
        }, 1);
        $proto->defineOwnProperty('forEach', PropertyDescriptor::data($forEachFn, true, false, true));

        // Per spec, Set.prototype.keys === Set.prototype.values (same function object).
        $valuesFn = JsFunction::fromCallable('values', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Method Set.prototype.values called on incompatible receiver');
            }
            return self::createSetIterator($this_, 'value');
        }, 0);
        $proto->defineOwnProperty('values', PropertyDescriptor::data($valuesFn, true, false, true));
        $proto->defineOwnProperty('keys', PropertyDescriptor::data($valuesFn, true, false, true));

        $entriesFn = JsFunction::fromCallable('entries', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Method Set.prototype.entries called on incompatible receiver');
            }
            return self::createSetIterator($this_, 'key+value');
        }, 0);
        $proto->defineOwnProperty('entries', PropertyDescriptor::data($entriesFn, true, false, true));

        // Set.prototype.size is an accessor property (getter only).
        $sizeGetter = JsFunction::fromCallable('get size', function (JsValue $this_): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Method get Set.prototype.size called on incompatible receiver');
            }
            return new JsNumber((float) $this_->setSize());
        }, 0);
        $proto->defineOwnProperty(
            'size',
            PropertyDescriptor::accessor($sizeGetter, null, false, true),
        );

        // Set.prototype.union(other)
        $unionFn = JsFunction::fromCallable('union', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Method Set.prototype.union called on incompatible receiver');
            }
            $other = $args[0] ?? JsUndefined::instance();
            $rec = self::getSetRecord($other);
            $result = $this_->copy();
            self::iterateSetRecord($rec, function (JsValue $value) use ($result): void {
                $result->setAdd($value);
            });
            return $result;
        }, 1);
        $unionFn->setNonConstructable();
        $proto->defineOwnProperty('union', PropertyDescriptor::data($unionFn, true, false, true));

        // Set.prototype.intersection(other)
        $intersectionFn = JsFunction::fromCallable('intersection', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Method Set.prototype.intersection called on incompatible receiver');
            }
            $other = $args[0] ?? JsUndefined::instance();
            $rec = self::getSetRecord($other);
            $result = new JsSet($this_->getPrototype());
            $thisSize = $this_->setSize();
            if ($thisSize <= $rec['size']) {
                foreach ($this_->setValues() as $value) {
                    $inOther = $rec['has']->call($rec['obj'], [$value]);
                    if (TypeConversion::toBoolean($inOther)) {
                        $result->setAdd($value);
                    }
                }
            } else {
                self::iterateSetRecord($rec, function (JsValue $value) use ($this_, $result): void {
                    if ($this_->setHas($value)) {
                        $result->setAdd($value);
                    }
                });
            }
            return $result;
        }, 1);
        $intersectionFn->setNonConstructable();
        $proto->defineOwnProperty('intersection', PropertyDescriptor::data($intersectionFn, true, false, true));

        // Set.prototype.difference(other)
        $differenceFn = JsFunction::fromCallable('difference', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Method Set.prototype.difference called on incompatible receiver');
            }
            $other = $args[0] ?? JsUndefined::instance();
            $rec = self::getSetRecord($other);
            $result = $this_->copy();
            $thisSize = $this_->setSize();
            if ($thisSize <= $rec['size']) {
                foreach ($result->setValues() as $value) {
                    $inOther = $rec['has']->call($rec['obj'], [$value]);
                    if (TypeConversion::toBoolean($inOther)) {
                        $result->setDelete($value);
                    }
                }
            } else {
                self::iterateSetRecord($rec, function (JsValue $value) use ($result): void {
                    $result->setDelete($value);
                });
            }
            return $result;
        }, 1);
        $differenceFn->setNonConstructable();
        $proto->defineOwnProperty('difference', PropertyDescriptor::data($differenceFn, true, false, true));

        // Set.prototype.symmetricDifference(other)
        $symmetricDifferenceFn = JsFunction::fromCallable('symmetricDifference', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Method Set.prototype.symmetricDifference called on incompatible receiver');
            }
            $other = $args[0] ?? JsUndefined::instance();
            $rec = self::getSetRecord($other);
            $result = $this_->copy();
            self::iterateSetRecord($rec, function (JsValue $value) use ($result): void {
                if ($result->setHas($value)) {
                    $result->setDelete($value);
                } else {
                    $result->setAdd($value);
                }
            });
            return $result;
        }, 1);
        $symmetricDifferenceFn->setNonConstructable();
        $proto->defineOwnProperty('symmetricDifference', PropertyDescriptor::data($symmetricDifferenceFn, true, false, true));

        // Set.prototype.isSubsetOf(other)
        $isSubsetOfFn = JsFunction::fromCallable('isSubsetOf', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Method Set.prototype.isSubsetOf called on incompatible receiver');
            }
            $other = $args[0] ?? JsUndefined::instance();
            $rec = self::getSetRecord($other);
            $thisSize = $this_->setSize();
            if ($thisSize > $rec['size']) {
                return new JsBoolean(false);
            }
            foreach ($this_->setValues() as $value) {
                $inOther = $rec['has']->call($rec['obj'], [$value]);
                if (!TypeConversion::toBoolean($inOther)) {
                    return new JsBoolean(false);
                }
            }
            return new JsBoolean(true);
        }, 1);
        $isSubsetOfFn->setNonConstructable();
        $proto->defineOwnProperty('isSubsetOf', PropertyDescriptor::data($isSubsetOfFn, true, false, true));

        // Set.prototype.isSupersetOf(other)
        $isSupersetOfFn = JsFunction::fromCallable('isSupersetOf', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Method Set.prototype.isSupersetOf called on incompatible receiver');
            }
            $other = $args[0] ?? JsUndefined::instance();
            $rec = self::getSetRecord($other);
            $thisSize = $this_->setSize();
            if ($thisSize < $rec['size']) {
                return new JsBoolean(false);
            }
            $isSuperset = true;
            self::iterateSetRecord($rec, function (JsValue $value) use ($this_, &$isSuperset): void {
                if (!$this_->setHas($value)) {
                    $isSuperset = false;
                }
            });
            return new JsBoolean($isSuperset);
        }, 1);
        $isSupersetOfFn->setNonConstructable();
        $proto->defineOwnProperty('isSupersetOf', PropertyDescriptor::data($isSupersetOfFn, true, false, true));

        // Set.prototype.isDisjointFrom(other)
        $isDisjointFromFn = JsFunction::fromCallable('isDisjointFrom', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Method Set.prototype.isDisjointFrom called on incompatible receiver');
            }
            $other = $args[0] ?? JsUndefined::instance();
            $rec = self::getSetRecord($other);
            $thisSize = $this_->setSize();
            if ($thisSize <= $rec['size']) {
                foreach ($this_->setValues() as $value) {
                    $inOther = $rec['has']->call($rec['obj'], [$value]);
                    if (TypeConversion::toBoolean($inOther)) {
                        return new JsBoolean(false);
                    }
                }
            } else {
                $found = false;
                self::iterateSetRecord($rec, function (JsValue $value) use ($this_, &$found): void {
                    if ($this_->setHas($value)) {
                        $found = true;
                    }
                });
                if ($found) {
                    return new JsBoolean(false);
                }
            }
            return new JsBoolean(true);
        }, 1);
        $isDisjointFromFn->setNonConstructable();
        $proto->defineOwnProperty('isDisjointFrom', PropertyDescriptor::data($isDisjointFromFn, true, false, true));

        // Symbol.toStringTag = "Set".
        $toStringTagSym = SymbolConstructor::toStringTag();
        $proto->definePropertyBySymbol(
            $toStringTagSym,
            PropertyDescriptor::data(new JsString('Set'), false, false, true),
        );

        // Symbol.iterator points to values method per spec.
        $iterSym = SymbolConstructor::iterator();
        $proto->definePropertyBySymbol(
            $iterSym,
            PropertyDescriptor::data($valuesFn, true, false, true),
        );

        return $proto;
    }

    /**
     * Create a live Set iterator object with proper prototype chain.
     */
    private static function createSetIterator(JsSet $set, string $kind): JsObject
    {
        $proto = self::$setIteratorPrototype;
        $iterator = new JsObject($proto);

        $data = new JsObject();
        $data->set('set', $set);
        $data->set('kind', new JsString($kind));
        $data->set('index', new JsNumber(0.0));
        $iterator->defineOwnProperty(
            '[[SetIteratorData]]',
            PropertyDescriptor::data($data, false, false, false),
        );

        return $iterator;
    }

    /**
     * GetSetRecord(obj) per TC39 set-methods proposal.
     *
     * Validates that obj is set-like: an object with a numeric .size property,
     * a callable .has() method, and a callable .keys() method.
     *
     * @return array{obj: JsObject, size: int, has: JsFunction, keys: JsFunction}
     */
    private static function getSetRecord(JsValue $obj): array
    {
        if (!$obj instanceof JsObject) {
            throw new TypeError('The .size property is NaN');
        }

        $rawSize = $obj->get('size');
        $numSize = TypeConversion::toNumber($rawSize);
        if (is_nan($numSize)) {
            throw new TypeError('The .size property is NaN');
        }
        if ($numSize < 0) {
            throw new RangeError('The .size property is negative');
        }
        $intSize = (int) $numSize;

        $has = $obj->get('has');
        if (!$has instanceof JsFunction) {
            throw new TypeError('The .has property is not a function');
        }

        $keys = $obj->get('keys');
        if (!$keys instanceof JsFunction) {
            throw new TypeError('The .keys property is not a function');
        }

        return ['obj' => $obj, 'size' => $intSize, 'has' => $has, 'keys' => $keys];
    }

    /**
     * Iterate the keys of a set-record by calling its .keys() method
     * and consuming the resulting iterator.
     */
    private static function iterateSetRecord(array $rec, callable $callback): void
    {
        $keysIter = $rec['keys']->call($rec['obj'], []);
        if (!$keysIter instanceof JsObject) {
            throw new TypeError('The .keys() method must return an object');
        }
        $nextMethod = $keysIter->get('next');
        if (!$nextMethod instanceof JsFunction) {
            throw new TypeError('The iterator does not have a next method');
        }
        while (true) {
            $result = $nextMethod->call($keysIter, []);
            if (!$result instanceof JsObject) {
                break;
            }
            if (TypeConversion::toBoolean($result->get('done'))) {
                break;
            }
            $value = $result->get('value');
            if ($value instanceof JsNumber && $value->value === 0.0) {
                $value = new JsNumber(0.0);
            }
            $callback($value);
        }
    }
}
