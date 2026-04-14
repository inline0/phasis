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
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
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
            if (!empty($args) && !$args[0] instanceof JsUndefined) {
                $iterable = $args[0];
                if ($iterable instanceof JsArray) {
                    $length = $iterable->getLength();
                    for ($i = 0; $i < $length; $i++) {
                        $entry = $iterable->get((string) $i);
                        if ($entry instanceof JsArray && $entry->getLength() >= 2) {
                            $map->mapSet($entry->get('0'), $entry->get('1'));
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

        $proto->set('get', JsFunction::fromCallable('get', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Map.prototype.get called on non-Map object');
            }
            $key = $args[0] ?? JsUndefined::instance();
            return $this_->mapGet($key);
        }));

        $proto->set('set', JsFunction::fromCallable('set', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Map.prototype.set called on non-Map object');
            }
            $key = $args[0] ?? JsUndefined::instance();
            $value = $args[1] ?? JsUndefined::instance();
            $this_->mapSet($key, $value);
            return $this_;
        }));

        $proto->set('has', JsFunction::fromCallable('has', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Map.prototype.has called on non-Map object');
            }
            $key = $args[0] ?? JsUndefined::instance();
            return new JsBoolean($this_->mapHas($key));
        }));

        $proto->set('delete', JsFunction::fromCallable('delete', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Map.prototype.delete called on non-Map object');
            }
            $key = $args[0] ?? JsUndefined::instance();
            return new JsBoolean($this_->mapDelete($key));
        }));

        $proto->set('clear', JsFunction::fromCallable('clear', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Map.prototype.clear called on non-Map object');
            }
            $this_->mapClear();
            return JsUndefined::instance();
        }));

        $proto->set('forEach', JsFunction::fromCallable('forEach', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Map.prototype.forEach called on non-Map object');
            }
            $callback = $args[0] ?? null;
            if (!$callback instanceof JsFunction) {
                throw new TypeError('Map.prototype.forEach callback is not a function');
            }
            $thisArg = $args[1] ?? JsUndefined::instance();
            $this_->mapForEach($callback, $thisArg);
            return JsUndefined::instance();
        }));

        $proto->set('keys', JsFunction::fromCallable('keys', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Map.prototype.keys called on non-Map object');
            }
            return JsArray::fromArray($this_->mapKeys());
        }));

        $proto->set('values', JsFunction::fromCallable('values', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Map.prototype.values called on non-Map object');
            }
            return JsArray::fromArray($this_->mapValues());
        }));

        $proto->set('entries', JsFunction::fromCallable('entries', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsMap) {
                throw new TypeError('Map.prototype.entries called on non-Map object');
            }
            $result = [];
            foreach ($this_->mapEntries() as $entry) {
                $result[] = JsArray::fromArray([$entry[0], $entry[1]]);
            }
            return JsArray::fromArray($result);
        }));

        return $proto;
    }
}
