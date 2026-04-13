<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Exceptions\TypeError;
use PhpJs\Runtime\Environment;
use PhpJs\Value\JsArray;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsSet;
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
    public static function install(Environment $env): void
    {
        $proto = self::createPrototype();

        $constructor = JsFunction::fromCallable('Set', function (JsValue $this_, array $args) use ($proto): JsValue {
            $set = new JsSet($proto);

            // If an iterable is provided, populate the set.
            if (!empty($args) && !$args[0] instanceof JsUndefined) {
                $iterable = $args[0];
                if ($iterable instanceof JsArray) {
                    $length = $iterable->getLength();
                    for ($i = 0; $i < $length; $i++) {
                        $set->setAdd($iterable->get((string) $i));
                    }
                }
            }

            return $set;
        });

        $constructor->set('prototype', $proto);
        $proto->set('constructor', $constructor);

        $env->defineVar('Set', $constructor);
    }

    private static function createPrototype(): JsObject
    {
        $proto = new JsObject();

        $proto->set('add', JsFunction::fromCallable('add', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Set.prototype.add called on non-Set object');
            }
            $value = $args[0] ?? JsUndefined::instance();
            $this_->setAdd($value);
            return $this_;
        }));

        $proto->set('has', JsFunction::fromCallable('has', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Set.prototype.has called on non-Set object');
            }
            $value = $args[0] ?? JsUndefined::instance();
            return new JsBoolean($this_->setHas($value));
        }));

        $proto->set('delete', JsFunction::fromCallable('delete', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Set.prototype.delete called on non-Set object');
            }
            $value = $args[0] ?? JsUndefined::instance();
            return new JsBoolean($this_->setDelete($value));
        }));

        $proto->set('clear', JsFunction::fromCallable('clear', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Set.prototype.clear called on non-Set object');
            }
            $this_->setClear();
            return JsUndefined::instance();
        }));

        $proto->set('forEach', JsFunction::fromCallable('forEach', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Set.prototype.forEach called on non-Set object');
            }
            $callback = $args[0] ?? null;
            if (!$callback instanceof JsFunction) {
                throw new TypeError('Set.prototype.forEach callback is not a function');
            }
            $thisArg = $args[1] ?? JsUndefined::instance();
            $this_->setForEach($callback, $thisArg);
            return JsUndefined::instance();
        }));

        $proto->set('keys', JsFunction::fromCallable('keys', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Set.prototype.keys called on non-Set object');
            }
            return JsArray::fromArray($this_->setValues());
        }));

        $proto->set('values', JsFunction::fromCallable('values', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Set.prototype.values called on non-Set object');
            }
            return JsArray::fromArray($this_->setValues());
        }));

        $proto->set('entries', JsFunction::fromCallable('entries', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Set.prototype.entries called on non-Set object');
            }
            $result = [];
            foreach ($this_->setValues() as $value) {
                $result[] = JsArray::fromArray([$value, $value]);
            }
            return JsArray::fromArray($result);
        }));

        return $proto;
    }
}
