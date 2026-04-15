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

        $constructor = JsFunction::fromCallable('Map', function (JsValue $this_, array $args) use ($proto): JsValue {
            $map = new JsMap($proto);

            // If an iterable of [key, value] pairs is provided, populate the map.
            if (!empty($args) && !$args[0] instanceof JsUndefined && !$args[0] instanceof JsNull) {
                $iterable = $args[0];
                if ($iterable instanceof JsArray) {
                    $length = $iterable->getLength();
                    for ($i = 0; $i < $length; $i++) {
                        $entry = $iterable->get((string) $i);
                        if ($entry instanceof JsArray && $entry->getLength() >= 2) {
                            $map->mapSet($entry->get('0'), $entry->get('1'));
                        } elseif ($entry instanceof JsObject) {
                            $map->mapSet($entry->get('0'), $entry->get('1'));
                        }
                    }
                } elseif ($iterable instanceof JsObject) {
                    // Generic iterable support via Symbol.iterator.
                    $iterSym = SymbolConstructor::iterator();
                    $iteratorMethod = $iterable->getBySymbol($iterSym);
                    if ($iteratorMethod instanceof JsFunction) {
                        $iterator = $iteratorMethod->call($iterable, []);
                        if ($iterator instanceof JsObject) {
                            $nextMethod = $iterator->get('next');
                            if ($nextMethod instanceof JsFunction) {
                                while (true) {
                                    $result = $nextMethod->call($iterator, []);
                                    if (!$result instanceof JsObject) {
                                        break;
                                    }
                                    if (TypeConversion::toBoolean($result->get('done'))) {
                                        break;
                                    }
                                    $entry = $result->get('value');
                                    if ($entry instanceof JsArray && $entry->getLength() >= 2) {
                                        $map->mapSet($entry->get('0'), $entry->get('1'));
                                    } elseif ($entry instanceof JsObject) {
                                        $map->mapSet($entry->get('0'), $entry->get('1'));
                                    }
                                }
                            }
                        }
                    }
                }
            }

            return $map;
        });
        $constructor->setConstructable();

        $constructor->set('prototype', $proto);
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($constructor, true, false, true));

        $env->defineVar('Map', $constructor);
    }

    private static function createPrototype(): JsObject
    {
        $proto = new JsObject();

        // All prototype methods are non-enumerable per spec (writable: true, enumerable: false, configurable: true).

        $proto->defineOwnProperty('get', PropertyDescriptor::data(JsFunction::fromCallable('get', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Method Map.prototype.get called on incompatible receiver');
            }
            $key = $args[0] ?? JsUndefined::instance();
            return $this_->mapGet($key);
        }, 1), true, false, true));

        $proto->defineOwnProperty('set', PropertyDescriptor::data(JsFunction::fromCallable('set', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Method Map.prototype.set called on incompatible receiver');
            }
            $key = $args[0] ?? JsUndefined::instance();
            $value = $args[1] ?? JsUndefined::instance();
            $this_->mapSet($key, $value);
            return $this_;
        }, 2), true, false, true));

        $proto->defineOwnProperty('has', PropertyDescriptor::data(JsFunction::fromCallable('has', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Method Map.prototype.has called on incompatible receiver');
            }
            $key = $args[0] ?? JsUndefined::instance();
            return new JsBoolean($this_->mapHas($key));
        }, 1), true, false, true));

        $proto->defineOwnProperty('delete', PropertyDescriptor::data(JsFunction::fromCallable('delete', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Method Map.prototype.delete called on incompatible receiver');
            }
            $key = $args[0] ?? JsUndefined::instance();
            return new JsBoolean($this_->mapDelete($key));
        }, 1), true, false, true));

        $proto->defineOwnProperty('clear', PropertyDescriptor::data(JsFunction::fromCallable('clear', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Method Map.prototype.clear called on incompatible receiver');
            }
            $this_->mapClear();
            return JsUndefined::instance();
        }, 0), true, false, true));

        $proto->defineOwnProperty('forEach', PropertyDescriptor::data(JsFunction::fromCallable('forEach', function (JsValue $this_, array $args): JsValue {
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
        }, 1), true, false, true));

        $proto->defineOwnProperty('keys', PropertyDescriptor::data(JsFunction::fromCallable('keys', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Method Map.prototype.keys called on incompatible receiver');
            }
            return self::createKeyIterator($this_);
        }, 0), true, false, true));

        $proto->defineOwnProperty('values', PropertyDescriptor::data(JsFunction::fromCallable('values', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Method Map.prototype.values called on incompatible receiver');
            }
            return self::createValueIterator($this_);
        }, 0), true, false, true));

        $proto->defineOwnProperty('entries', PropertyDescriptor::data(JsFunction::fromCallable('entries', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Method Map.prototype.entries called on incompatible receiver');
            }
            return self::createEntryIterator($this_);
        }, 0), true, false, true));

        // Map.prototype.size is an accessor property (getter only).
        $sizeGetter = JsFunction::fromCallable('get size', function (JsValue $this_): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Method get Map.prototype.size called on incompatible receiver');
            }
            return new JsNumber((float) $this_->mapSize());
        }, 0);
        $proto->defineOwnProperty('size', PropertyDescriptor::accessor($sizeGetter, null, false, true));

        // Symbol.toStringTag = "Map".
        $toStringTagSym = SymbolConstructor::toStringTag();
        $proto->definePropertyBySymbol($toStringTagSym, PropertyDescriptor::data(new JsString('Map'), false, false, true));

        // Symbol.iterator points to entries method.
        $iterSym = SymbolConstructor::iterator();
        $entriesFn = $proto->getOwnPropertyDescriptor('entries');
        if ($entriesFn !== null && $entriesFn->value !== null) {
            $proto->definePropertyBySymbol($iterSym, PropertyDescriptor::data($entriesFn->value, true, false, true));
        }

        return $proto;
    }

    /** Create a Map key iterator object. */
    private static function createKeyIterator(JsMap $map): JsObject
    {
        $index = 0;
        $iterSym = SymbolConstructor::iterator();
        $iterator = new JsObject();
        $nextFn = function () use ($map, &$index): JsValue {
            $result = new JsObject();
            $keys = $map->mapKeys();
            if ($index < count($keys)) {
                $result->set('value', $keys[$index]);
                $result->set('done', new JsBoolean(false));
                $index++;
            } else {
                $result->set('value', JsUndefined::instance());
                $result->set('done', new JsBoolean(true));
            }
            return $result;
        };
        $iterator->set('next', JsFunction::fromCallable('next', $nextFn));
        $iterator->setBySymbol($iterSym, JsFunction::fromCallable('[Symbol.iterator]', function (JsValue $self_): JsValue {
            return $self_;
        }));
        return $iterator;
    }

    /** Create a Map value iterator object. */
    private static function createValueIterator(JsMap $map): JsObject
    {
        $index = 0;
        $iterSym = SymbolConstructor::iterator();
        $iterator = new JsObject();
        $nextFn = function () use ($map, &$index): JsValue {
            $result = new JsObject();
            $values = $map->mapValues();
            if ($index < count($values)) {
                $result->set('value', $values[$index]);
                $result->set('done', new JsBoolean(false));
                $index++;
            } else {
                $result->set('value', JsUndefined::instance());
                $result->set('done', new JsBoolean(true));
            }
            return $result;
        };
        $iterator->set('next', JsFunction::fromCallable('next', $nextFn));
        $iterator->setBySymbol($iterSym, JsFunction::fromCallable('[Symbol.iterator]', function (JsValue $self_): JsValue {
            return $self_;
        }));
        return $iterator;
    }

    /** Create a Map entry iterator object. */
    private static function createEntryIterator(JsMap $map): JsObject
    {
        $index = 0;
        $iterSym = SymbolConstructor::iterator();
        $iterator = new JsObject();
        $nextFn = function () use ($map, &$index): JsValue {
            $result = new JsObject();
            $entries = $map->mapEntries();
            if ($index < count($entries)) {
                $entry = $entries[$index];
                $result->set('value', JsArray::fromArray([$entry[0], $entry[1]]));
                $result->set('done', new JsBoolean(false));
                $index++;
            } else {
                $result->set('value', JsUndefined::instance());
                $result->set('done', new JsBoolean(true));
            }
            return $result;
        };
        $iterator->set('next', JsFunction::fromCallable('next', $nextFn));
        $iterator->setBySymbol($iterSym, JsFunction::fromCallable('[Symbol.iterator]', function (JsValue $self_): JsValue {
            return $self_;
        }));
        return $iterator;
    }
}
