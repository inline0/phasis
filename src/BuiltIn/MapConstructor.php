<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsMap;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * Map constructor and prototype methods.
 *
 * new Map() creates an empty map.
 * new Map(iterable) creates a map from [key, value] pairs.
 */
class MapConstructor
{
    /** %MapIteratorPrototype%: shared prototype for all map iterators. */
    private static ?JsObject $mapIteratorPrototype = null;

    public static function install(Environment $env): void
    {
        self::resetMapIteratorPrototype();
        $iteratorPrototype = $env->has('__IteratorPrototype__')
            ? $env->get('__IteratorPrototype__')
            : null;
        self::getMapIteratorPrototype(
            $iteratorPrototype instanceof JsObject ? $iteratorPrototype : null,
        );

        $proto = self::createPrototype();

        $constructor = JsFunction::fromCallable(
            'Map',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                // Map must be called with new
                if (!$this_ instanceof JsObject || $this_->get('[[NewTarget]]') instanceof JsUndefined) {
                    throw new TypeError('Constructor Map requires \'new\'');
                }
                // Use the subclass's prototype when new.target is not Map itself.
                $effectiveProto = $proto;
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsFunction) {
                    $ntProto = $newTarget->get('prototype');
                    if ($ntProto instanceof JsObject) {
                        $effectiveProto = $ntProto;
                    }
                }
                $map = new JsMap($effectiveProto);
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

        // Map[@@species] per spec: accessor property, getter returns `this`.
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

        $env->defineVar('Map', $constructor);
    }

    /** Reset the shared map iterator prototype (for engine reset). */
    public static function resetMapIteratorPrototype(): void
    {
        self::$mapIteratorPrototype = null;
    }

    /**
     * Get or create the %MapIteratorPrototype% intrinsic.
     */
    public static function getMapIteratorPrototype(?JsObject $iteratorPrototype = null): JsObject
    {
        if (self::$mapIteratorPrototype !== null) {
            return self::$mapIteratorPrototype;
        }

        $proto = new JsObject($iteratorPrototype);

        $nextFn = JsFunction::fromCallable('next', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError(
                    'Method Map Iterator.prototype.next called on incompatible receiver',
                );
            }
            $slotDesc = $this_->getOwnPropertyDescriptor('[[MapIteratorData]]');
            if ($slotDesc === null) {
                throw new TypeError(
                    'Method Map Iterator.prototype.next called on incompatible receiver',
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
            $map = $data->get('map');
            if (!$map instanceof JsMap) {
                $result = new JsObject();
                $result->set('value', JsUndefined::instance());
                $result->set('done', new JsBoolean(true));
                return $result;
            }
            $kind = ($data->get('kind') instanceof JsString) ? $data->get('kind')->value : 'key+value';
            $indexVal = $data->get('index');
            $index = ($indexVal instanceof JsNumber) ? (int) $indexVal->value : 0;

            $result = new JsObject();
            while ($index < $map->slotCount()) {
                $entry = $map->getSlot($index);
                $index++;
                $data->set('index', JsNumber::of((float) $index));
                if ($entry !== null) {
                    $result->set('done', new JsBoolean(false));
                    $result->set('value', match ($kind) {
                        'key' => $entry[0],
                        'value' => $entry[1],
                        default => JsArray::fromArray([$entry[0], $entry[1]]),
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

        // Symbol.toStringTag = "Map Iterator".
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Map Iterator'), false, false, true),
        );

        self::$mapIteratorPrototype = $proto;
        return $proto;
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
                $key = $callbackfn->call(JsUndefined::instance(), [$value, JsNumber::of((float) $k)]);

                // Normalize -0 to +0.
                if ($key instanceof JsNumber && $key->value === 0.0) {
                    $key = JsNumber::of(0.0);
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

        // Per spec GetIterator: ToObject the value for the lookup but pass
        // the original primitive as the call's `this`. This makes
        // Number.prototype[@@iterator] receive a number primitive rather
        // than a Number wrapper.
        $iterSym = SymbolConstructor::iterator();
        if ($iterable instanceof JsObject) {
            $iteratorMethod = $iterable->getBySymbol($iterSym);
            $thisArg = $iterable;
        } else {
            $wrapper = TypeConversion::toObject($iterable);
            $iteratorMethod = $wrapper->getBySymbolWithReceiver($iterSym, $iterable);
            $thisArg = $iterable;
        }

        if ($iteratorMethod instanceof JsUndefined || $iteratorMethod instanceof JsNull) {
            throw new TypeError('object is not iterable');
        }
        if (!$iteratorMethod instanceof JsFunction) {
            throw new TypeError('object is not iterable');
        }

        $iterator = $iteratorMethod->call($thisArg, []);
        if (!$iterator instanceof JsObject) {
            throw new TypeError('object is not iterable');
        }

        $nextMethod = $iterator->get('next');
        if (!$nextMethod instanceof JsFunction) {
            throw new TypeError('object is not iterable');
        }

        while (true) {
            $result = $nextMethod->call($iterator, []);
            // Per spec IteratorStep / IteratorNext: the value returned by
            // next() must be an Object. Anything else is a TypeError.
            if (!$result instanceof JsObject) {
                throw new TypeError('Iterator result is not an object');
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
        // Per spec 7.4.10 IteratorClose: this helper is only invoked when the
        // outer completion is a throw, so the original throw takes precedence
        // over anything that happens inside close — including a throwing
        // `return` accessor or call.
        try {
            $returnMethod = $iterator->get('return');
        } catch (\Throwable) {
            return;
        }
        if ($returnMethod instanceof JsFunction) {
            try {
                $returnMethod->call($iterator, []);
            } catch (\Throwable) {
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

        // Map.prototype.getOrInsert(key, value)
        $getOrInsertFn = JsFunction::fromCallable('getOrInsert', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Method Map.prototype.getOrInsert called on incompatible receiver');
            }
            $key = $args[0] ?? JsUndefined::instance();
            $value = $args[1] ?? JsUndefined::instance();
            if ($this_->mapHas($key)) {
                return $this_->mapGet($key);
            }
            $this_->mapSet($key, $value);
            return $value;
        }, 2);
        $proto->defineOwnProperty('getOrInsert', PropertyDescriptor::data($getOrInsertFn, true, false, true));

        // Map.prototype.getOrInsertComputed(key, callbackfn)
        $getOrInsertComputedFn = JsFunction::fromCallable('getOrInsertComputed', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Method Map.prototype.getOrInsertComputed called on incompatible receiver');
            }
            $key = $args[0] ?? JsUndefined::instance();
            $callbackfn = $args[1] ?? JsUndefined::instance();
            if (!$callbackfn instanceof JsFunction) {
                throw new TypeError('callbackfn is not a function');
            }
            if ($this_->mapHas($key)) {
                return $this_->mapGet($key);
            }
            $value = $callbackfn->call(JsUndefined::instance(), [$key]);
            $this_->mapSet($key, $value);
            return $value;
        }, 2);
        $proto->defineOwnProperty('getOrInsertComputed', PropertyDescriptor::data($getOrInsertComputedFn, true, false, true));

        $keysFn = JsFunction::fromCallable('keys', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Method Map.prototype.keys called on incompatible receiver');
            }
            return self::createMapIterator($this_, 'key');
        }, 0);
        $proto->defineOwnProperty('keys', PropertyDescriptor::data($keysFn, true, false, true));

        $valuesFn = JsFunction::fromCallable('values', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Method Map.prototype.values called on incompatible receiver');
            }
            return self::createMapIterator($this_, 'value');
        }, 0);
        $proto->defineOwnProperty('values', PropertyDescriptor::data($valuesFn, true, false, true));

        $entriesFn = JsFunction::fromCallable('entries', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Method Map.prototype.entries called on incompatible receiver');
            }
            return self::createMapIterator($this_, 'key+value');
        }, 0);
        $proto->defineOwnProperty('entries', PropertyDescriptor::data($entriesFn, true, false, true));

        // Map.prototype.size is an accessor property (getter only).
        $sizeGetter = JsFunction::fromCallable('get size', function (JsValue $this_): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Method get Map.prototype.size called on incompatible receiver');
            }
            return JsNumber::of((float) $this_->mapSize());
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
     * Create a live Map iterator object with proper prototype chain.
     */
    private static function createMapIterator(JsMap $map, string $kind): JsObject
    {
        $proto = self::$mapIteratorPrototype;
        $iterator = new JsObject($proto);

        $data = new JsObject();
        $data->set('map', $map);
        $data->set('kind', new JsString($kind));
        $data->set('index', JsNumber::of(0.0));
        $iterator->defineOwnProperty(
            '[[MapIteratorData]]',
            PropertyDescriptor::data($data, false, false, false),
        );

        return $iterator;
    }
}
