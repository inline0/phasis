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
use PhpJs\Value\JsSet;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;
use PhpJs\Object\PropertyDescriptor;

/**
 * Set constructor and prototype methods.
 *
 * new Set() creates an empty set.
 * new Set(iterable) creates a set from iterable values.
 */
class SetConstructor
{
    public static function install(Environment $env): void
    {
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

        // Install next() on %SetIteratorPrototype% per spec.
        self::installNextOnSetIteratorPrototype();

        $env->defineVar('Set', $constructor);
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
            return self::createValueIterator($this_);
        }, 0);
        $proto->defineOwnProperty('values', PropertyDescriptor::data($valuesFn, true, false, true));
        $proto->defineOwnProperty('keys', PropertyDescriptor::data($valuesFn, true, false, true));

        $entriesFn = JsFunction::fromCallable('entries', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Method Set.prototype.entries called on incompatible receiver');
            }
            return self::createEntryIterator($this_);
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
     * Create a spec-compliant Set value iterator object with internal slots.
     */
    private static function createValueIterator(JsSet $set): JsObject
    {
        return self::createSetIteratorObject($set, 'value');
    }

    /**
     * Create a spec-compliant Set entry iterator object with internal slots.
     */
    private static function createEntryIterator(JsSet $set): JsObject
    {
        return self::createSetIteratorObject($set, 'key+value');
    }

    /**
     * Create a Set Iterator object with internal slots per the ECMAScript spec.
     */
    private static function createSetIteratorObject(JsSet $set, string $kind): JsObject
    {
        $iterator = new JsObject(IteratorPrototypes::setIteratorPrototype());
        $iterator->defineOwnProperty('[[SetIteratorBrand]]', PropertyDescriptor::data(
            new JsBoolean(true),
            false,
            false,
            false,
        ));
        $iterator->defineOwnProperty('[[IteratedSet]]', PropertyDescriptor::data(
            $set,
            true,
            false,
            false,
        ));
        $iterator->defineOwnProperty('[[SetNextIndex]]', PropertyDescriptor::data(
            new JsNumber(0.0),
            true,
            false,
            false,
        ));
        $iterator->defineOwnProperty('[[SetIterationKind]]', PropertyDescriptor::data(
            new JsString($kind),
            false,
            false,
            false,
        ));
        return $iterator;
    }

    /**
     * Install next() on %SetIteratorPrototype% per the ECMAScript spec.
     * Called once during install(). Uses internal slots and brand checking.
     */
    private static function installNextOnSetIteratorPrototype(): void
    {
        $proto = IteratorPrototypes::setIteratorPrototype();
        if ($proto->get('next') instanceof JsFunction) {
            return; // Already installed.
        }

        $nextFn = JsFunction::fromCallable('next', function (JsValue $this_): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('%SetIteratorPrototype%.next called on incompatible receiver');
            }
            $brandDesc = $this_->getOwnPropertyDescriptor('[[SetIteratorBrand]]');
            if ($brandDesc === null || !$brandDesc->value instanceof JsBoolean || !$brandDesc->value->value) {
                throw new TypeError('%SetIteratorPrototype%.next called on incompatible receiver');
            }

            $setDesc = $this_->getOwnPropertyDescriptor('[[IteratedSet]]');
            if ($setDesc === null || !$setDesc->value instanceof JsSet) {
                // Exhausted.
                $result = new JsObject();
                $result->set('value', JsUndefined::instance());
                $result->set('done', new JsBoolean(true));
                return $result;
            }

            $set = $setDesc->value;
            $indexDesc = $this_->getOwnPropertyDescriptor('[[SetNextIndex]]');
            $index = $indexDesc !== null ? (int) TypeConversion::toNumber($indexDesc->value) : 0;

            $kindDesc = $this_->getOwnPropertyDescriptor('[[SetIterationKind]]');
            $kind = $kindDesc !== null ? TypeConversion::toString($kindDesc->value) : 'value';

            while ($index < $set->slotCount()) {
                $value = $set->getSlot($index);
                $index++;
                if ($indexDesc !== null) {
                    $indexDesc->value = new JsNumber((float) $index);
                }
                if ($value !== null) {
                    $result = new JsObject();
                    $result->set('done', new JsBoolean(false));
                    $result->set('value', $kind === 'key+value'
                        ? JsArray::fromArray([$value, $value])
                        : $value);
                    return $result;
                }
            }

            // Exhausted: clear the iterated set.
            $setDesc->value = JsUndefined::instance();
            $result = new JsObject();
            $result->set('value', JsUndefined::instance());
            $result->set('done', new JsBoolean(true));
            return $result;
        }, 0);

        $proto->defineOwnProperty('next', PropertyDescriptor::data($nextFn, true, false, true));
    }
}
