<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Exceptions\TypeError;
use PhpJs\Runtime\Environment;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsArray;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsMap;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;
use PhpJs\Object\PropertyDescriptor;

/**
 * Map constructor and prototype methods.
 *
 * new Map() creates an empty map.
 * new Map(iterable) creates a map from [key, value] pairs.
 */
class MapConstructor
{
    public static function install(Environment $env): void
    {
        $proto = self::createPrototype();

        $constructor = JsFunction::fromCallable(
            'Map',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                // Map must be called with new
                if (!$this_ instanceof JsObject || $this_->get('[[NewTarget]]') instanceof JsUndefined) {
                    throw new TypeError('Constructor Map requires \'new\'');
                }
                $map = new JsMap($proto);
                self::populateFromArgs($map, $args);
                return $map;
            },
        );
        $constructor->setConstructable();

        $constructor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($constructor, true, false, true),
        );

        // Map.groupBy(items, callbackfn)
        $groupByFn = JsFunction::fromCallable('groupBy', self::groupBy($proto), 2);
        $groupByFn->setNonConstructable();
        $constructor->defineOwnProperty('groupBy', PropertyDescriptor::data($groupByFn, true, false, true));

        // Install next() on %MapIteratorPrototype% per spec.
        self::installNextOnMapIteratorPrototype();

        $env->defineVar('Map', $constructor);
    }

    /**
     * Map.groupBy(items, callbackfn) per spec.
     * Groups elements from an iterable using a callback, returning a Map.
     */
    private static function groupBy(JsObject $proto): \Closure
    {
        return function (JsValue $this_, array $args) use ($proto): JsValue {
            $items = $args[0] ?? JsUndefined::instance();
            $callbackfn = $args[1] ?? JsUndefined::instance();

            if (!$callbackfn instanceof JsFunction) {
                throw new TypeError(TypeConversion::toString($callbackfn) . ' is not a function');
            }

            // Get iterator from items. Strings need special handling.
            if ($items instanceof JsString) {
                $iterator = self::createStringIterator($items);
            } else {
                $iterSym = SymbolConstructor::iterator();
                if (!$items instanceof JsObject) {
                    $items = TypeConversion::toObject($items);
                }

                $iteratorMethod = $items->getBySymbol($iterSym);
                if (!$iteratorMethod instanceof JsFunction) {
                    throw new TypeError('object is not iterable');
                }

                $iterator = $iteratorMethod->call($items, []);
                if (!$iterator instanceof JsObject) {
                    throw new TypeError('object is not iterable');
                }
            }

            $nextMethod = $iterator->get('next');
            if (!$nextMethod instanceof JsFunction) {
                throw new TypeError('object is not iterable');
            }

            $map = new JsMap($proto);
            $k = 0;

            while (true) {
                $result = $nextMethod->call($iterator, []);
                if (!$result instanceof JsObject) {
                    break;
                }
                if (TypeConversion::toBoolean($result->get('done'))) {
                    break;
                }
                $value = $result->get('value');
                $key = $callbackfn->call(JsUndefined::instance(), [$value, new JsNumber((float) $k)]);

                // Normalize -0 to +0.
                if ($key instanceof JsNumber && $key->value === 0.0) {
                    $key = new JsNumber(0.0);
                }

                // Find or create the group array.
                $existing = $map->mapGet($key);
                if ($existing instanceof JsUndefined) {
                    $group = JsArray::fromArray([$value]);
                    $map->mapSet($key, $group);
                } else {
                    /** @var JsArray $existing */
                    $existing->set((string) $existing->getLength(), $value);
                }
                $k++;
            }

            return $map;
        };
    }

    /**
     * Populate a Map from constructor arguments (iterable of [key, value] pairs).
     *
     * @param list<JsValue> $args
     */
    private static function populateFromArgs(JsMap $map, array $args): void
    {
        if (empty($args)) {
            return;
        }

        $iterable = $args[0];
        if ($iterable instanceof JsUndefined || $iterable instanceof JsNull) {
            return;
        }

        // Per spec, get the `set` method before iterating.
        $adder = $map->get('set');
        if (!$adder instanceof JsFunction) {
            throw new TypeError('Map.prototype.set is not a function');
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
            $entry = $result->get('value');
            try {
                self::addEntryFromPair($map, $entry, $adder);
            } catch (\Throwable $e) {
                // Per spec, close the iterator on abrupt completion.
                self::closeIterator($iterator);
                throw $e;
            }
        }
    }

    /**
     * Per spec, Map constructor must call the `set` method on the map for each entry.
     * This allows subclasses and Proxy-wrapped maps to intercept the calls.
     */
    private static function addEntryFromPair(JsMap $map, JsValue $entry, JsFunction $adder): void
    {
        if (!$entry instanceof JsObject) {
            throw new TypeError('Iterator value is not an entry object');
        }
        $key = $entry->get('0');
        $value = $entry->get('1');
        $adder->call($map, [$key, $value]);
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

    /** Create a character iterator for a string value (Unicode codepoint iteration). */
    private static function createStringIterator(JsString $str): JsObject
    {
        $chars = [];
        $len = mb_strlen($str->value, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $chars[] = mb_substr($str->value, $i, 1, 'UTF-8');
        }
        $index = 0;
        $total = count($chars);

        $iterator = new JsObject();
        $nextFn = function () use (&$index, $total, &$chars): JsValue {
            $result = new JsObject();
            if ($index < $total) {
                $result->set('value', new JsString($chars[$index]));
                $result->set('done', new JsBoolean(false));
                $index++;
            } else {
                $result->set('value', JsUndefined::instance());
                $result->set('done', new JsBoolean(true));
            }
            return $result;
        };
        $iterator->set('next', JsFunction::fromCallable('next', $nextFn));
        return $iterator;
    }

    private static function createPrototype(): JsObject
    {
        $proto = new JsObject();

        // All prototype methods are non-enumerable per spec
        // (writable: true, enumerable: false, configurable: true).

        $getFn = JsFunction::fromCallable('get', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Method Map.prototype.get called on incompatible receiver');
            }
            $key = $args[0] ?? JsUndefined::instance();
            return $this_->mapGet($key);
        }, 1);
        $proto->defineOwnProperty('get', PropertyDescriptor::data($getFn, true, false, true));

        $setFn = JsFunction::fromCallable('set', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Method Map.prototype.set called on incompatible receiver');
            }
            $key = $args[0] ?? JsUndefined::instance();
            $value = $args[1] ?? JsUndefined::instance();
            $this_->mapSet($key, $value);
            return $this_;
        }, 2);
        $proto->defineOwnProperty('set', PropertyDescriptor::data($setFn, true, false, true));

        $hasFn = JsFunction::fromCallable('has', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Method Map.prototype.has called on incompatible receiver');
            }
            $key = $args[0] ?? JsUndefined::instance();
            return new JsBoolean($this_->mapHas($key));
        }, 1);
        $proto->defineOwnProperty('has', PropertyDescriptor::data($hasFn, true, false, true));

        $deleteFn = JsFunction::fromCallable('delete', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Method Map.prototype.delete called on incompatible receiver');
            }
            $key = $args[0] ?? JsUndefined::instance();
            return new JsBoolean($this_->mapDelete($key));
        }, 1);
        $proto->defineOwnProperty('delete', PropertyDescriptor::data($deleteFn, true, false, true));

        $clearFn = JsFunction::fromCallable('clear', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Method Map.prototype.clear called on incompatible receiver');
            }
            $this_->mapClear();
            return JsUndefined::instance();
        }, 0);
        $proto->defineOwnProperty('clear', PropertyDescriptor::data($clearFn, true, false, true));

        $forEachFn = JsFunction::fromCallable('forEach', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Method Map.prototype.forEach called on incompatible receiver');
            }
            $callback = $args[0] ?? null;
            if (!$callback instanceof JsFunction) {
                throw new TypeError('Map.prototype.forEach callback is not a function');
            }
            $thisArg = $args[1] ?? JsUndefined::instance();
            $this_->mapForEach($callback, $thisArg);
            return JsUndefined::instance();
        }, 1);
        $proto->defineOwnProperty('forEach', PropertyDescriptor::data($forEachFn, true, false, true));

        $keysFn = JsFunction::fromCallable('keys', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Method Map.prototype.keys called on incompatible receiver');
            }
            return self::createKeyIterator($this_);
        }, 0);
        $proto->defineOwnProperty('keys', PropertyDescriptor::data($keysFn, true, false, true));

        $valuesFn = JsFunction::fromCallable('values', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Method Map.prototype.values called on incompatible receiver');
            }
            return self::createValueIterator($this_);
        }, 0);
        $proto->defineOwnProperty('values', PropertyDescriptor::data($valuesFn, true, false, true));

        $entriesFn = JsFunction::fromCallable('entries', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Method Map.prototype.entries called on incompatible receiver');
            }
            return self::createEntryIterator($this_);
        }, 0);
        $proto->defineOwnProperty('entries', PropertyDescriptor::data($entriesFn, true, false, true));

        // Map.prototype.size is an accessor property (getter only).
        $sizeGetter = JsFunction::fromCallable('get size', function (JsValue $this_): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Method get Map.prototype.size called on incompatible receiver');
            }
            return new JsNumber((float) $this_->mapSize());
        }, 0);
        $proto->defineOwnProperty(
            'size',
            PropertyDescriptor::accessor($sizeGetter, null, false, true),
        );

        // Symbol.toStringTag = "Map".
        $toStringTagSym = SymbolConstructor::toStringTag();
        $proto->definePropertyBySymbol(
            $toStringTagSym,
            PropertyDescriptor::data(new JsString('Map'), false, false, true),
        );

        // Symbol.iterator points to entries method.
        $iterSym = SymbolConstructor::iterator();
        $proto->definePropertyBySymbol(
            $iterSym,
            PropertyDescriptor::data($entriesFn, true, false, true),
        );

        return $proto;
    }

    /**
     * Create a spec-compliant Map key iterator object with internal slots.
     */
    private static function createKeyIterator(JsMap $map): JsObject
    {
        return self::createMapIteratorObject($map, 'key');
    }

    /**
     * Create a spec-compliant Map value iterator object with internal slots.
     */
    private static function createValueIterator(JsMap $map): JsObject
    {
        return self::createMapIteratorObject($map, 'value');
    }

    /**
     * Create a spec-compliant Map entry iterator object with internal slots.
     */
    private static function createEntryIterator(JsMap $map): JsObject
    {
        return self::createMapIteratorObject($map, 'key+value');
    }

    /**
     * Create a Map Iterator object with internal slots per the ECMAScript spec.
     */
    private static function createMapIteratorObject(JsMap $map, string $kind): JsObject
    {
        $iterator = new JsObject(IteratorPrototypes::mapIteratorPrototype());
        $iterator->defineOwnProperty('[[MapIteratorBrand]]', PropertyDescriptor::data(
            new JsBoolean(true),
            false,
            false,
            false,
        ));
        $iterator->defineOwnProperty('[[IteratedMap]]', PropertyDescriptor::data(
            $map,
            true,
            false,
            false,
        ));
        $iterator->defineOwnProperty('[[MapNextIndex]]', PropertyDescriptor::data(
            new JsNumber(0.0),
            true,
            false,
            false,
        ));
        $iterator->defineOwnProperty('[[MapIterationKind]]', PropertyDescriptor::data(
            new JsString($kind),
            false,
            false,
            false,
        ));
        return $iterator;
    }

    /**
     * Install next() on %MapIteratorPrototype% per the ECMAScript spec.
     * Called once during install(). The next() method uses internal slots
     * ([[IteratedMap]], [[MapNextIndex]], [[MapIterationKind]]) and
     * performs brand checking via [[MapIteratorBrand]].
     */
    private static function installNextOnMapIteratorPrototype(): void
    {
        $proto = IteratorPrototypes::mapIteratorPrototype();
        if ($proto->get('next') instanceof JsFunction) {
            return; // Already installed.
        }

        $nextFn = JsFunction::fromCallable('next', function (JsValue $this_): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('%MapIteratorPrototype%.next called on incompatible receiver');
            }
            $brandDesc = $this_->getOwnPropertyDescriptor('[[MapIteratorBrand]]');
            if ($brandDesc === null || !$brandDesc->value instanceof JsBoolean || !$brandDesc->value->value) {
                throw new TypeError('%MapIteratorPrototype%.next called on incompatible receiver');
            }

            $mapDesc = $this_->getOwnPropertyDescriptor('[[IteratedMap]]');
            if ($mapDesc === null || !$mapDesc->value instanceof JsMap) {
                // Exhausted.
                $result = new JsObject();
                $result->set('value', JsUndefined::instance());
                $result->set('done', new JsBoolean(true));
                return $result;
            }

            $map = $mapDesc->value;
            $indexDesc = $this_->getOwnPropertyDescriptor('[[MapNextIndex]]');
            $index = $indexDesc !== null ? (int) TypeConversion::toNumber($indexDesc->value) : 0;

            $kindDesc = $this_->getOwnPropertyDescriptor('[[MapIterationKind]]');
            $kind = $kindDesc !== null ? TypeConversion::toString($kindDesc->value) : 'key+value';

            while ($index < $map->slotCount()) {
                $entry = $map->getSlot($index);
                $index++;
                if ($indexDesc !== null) {
                    $indexDesc->value = new JsNumber((float) $index);
                }
                if ($entry !== null) {
                    $result = new JsObject();
                    $result->set('done', new JsBoolean(false));
                    $result->set('value', match ($kind) {
                        'key' => $entry[0],
                        'value' => $entry[1],
                        default => JsArray::fromArray([$entry[0], $entry[1]]),
                    });
                    return $result;
                }
            }

            // Exhausted: clear the iterated map.
            $mapDesc->value = JsUndefined::instance();
            $result = new JsObject();
            $result->set('value', JsUndefined::instance());
            $result->set('done', new JsBoolean(true));
            return $result;
        }, 0);

        $proto->defineOwnProperty('next', PropertyDescriptor::data($nextFn, true, false, true));
    }
}
