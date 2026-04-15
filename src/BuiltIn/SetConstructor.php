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

        $constructor = JsFunction::fromCallable('Set', function (JsValue $this_, array $args) use ($proto): JsValue {
            $set = new JsSet($proto);

            // If an iterable is provided, populate the set.
            if (!empty($args) && !$args[0] instanceof JsUndefined && !$args[0] instanceof JsNull) {
                $iterable = $args[0];
                if ($iterable instanceof JsArray) {
                    $length = $iterable->getLength();
                    for ($i = 0; $i < $length; $i++) {
                        $set->setAdd($iterable->get((string) $i));
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
                                    $set->setAdd($result->get('value'));
                                }
                            }
                        }
                    }
                }
            }

            return $set;
        });
        $constructor->setConstructable();

        $constructor->set('prototype', $proto);
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($constructor, true, false, true));

        $env->defineVar('Set', $constructor);
    }

    private static function createPrototype(): JsObject
    {
        $proto = new JsObject();

        // All prototype methods are non-enumerable per spec (writable: true, enumerable: false, configurable: true).

        $proto->defineOwnProperty('add', PropertyDescriptor::data(JsFunction::fromCallable('add', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Method Set.prototype.add called on incompatible receiver');
            }
            $value = $args[0] ?? JsUndefined::instance();
            $this_->setAdd($value);
            return $this_;
        }, 1), true, false, true));

        $proto->defineOwnProperty('has', PropertyDescriptor::data(JsFunction::fromCallable('has', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Method Set.prototype.has called on incompatible receiver');
            }
            $value = $args[0] ?? JsUndefined::instance();
            return new JsBoolean($this_->setHas($value));
        }, 1), true, false, true));

        $proto->defineOwnProperty('delete', PropertyDescriptor::data(JsFunction::fromCallable('delete', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Method Set.prototype.delete called on incompatible receiver');
            }
            $value = $args[0] ?? JsUndefined::instance();
            return new JsBoolean($this_->setDelete($value));
        }, 1), true, false, true));

        $proto->defineOwnProperty('clear', PropertyDescriptor::data(JsFunction::fromCallable('clear', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Method Set.prototype.clear called on incompatible receiver');
            }
            $this_->setClear();
            return JsUndefined::instance();
        }, 0), true, false, true));

        $proto->defineOwnProperty('forEach', PropertyDescriptor::data(JsFunction::fromCallable('forEach', function (JsValue $this_, array $args): JsValue {
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
        }, 1), true, false, true));

        $proto->defineOwnProperty('keys', PropertyDescriptor::data(JsFunction::fromCallable('keys', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Method Set.prototype.keys called on incompatible receiver');
            }
            return self::createValueIterator($this_);
        }, 0), true, false, true));

        $valuesDesc = PropertyDescriptor::data(JsFunction::fromCallable('values', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Method Set.prototype.values called on incompatible receiver');
            }
            return self::createValueIterator($this_);
        }, 0), true, false, true);
        $proto->defineOwnProperty('values', $valuesDesc);

        $proto->defineOwnProperty('entries', PropertyDescriptor::data(JsFunction::fromCallable('entries', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Method Set.prototype.entries called on incompatible receiver');
            }
            return self::createEntryIterator($this_);
        }, 0), true, false, true));

        // Set.prototype.size is an accessor property (getter only).
        $sizeGetter = JsFunction::fromCallable('get size', function (JsValue $this_): JsValue {
            if (!$this_ instanceof JsSet) {
                throw new TypeError('Method get Set.prototype.size called on incompatible receiver');
            }
            return new JsNumber((float) $this_->setSize());
        }, 0);
        $proto->defineOwnProperty('size', PropertyDescriptor::accessor($sizeGetter, null, false, true));

        // Symbol.toStringTag = "Set".
        $toStringTagSym = SymbolConstructor::toStringTag();
        $proto->definePropertyBySymbol($toStringTagSym, PropertyDescriptor::data(new JsString('Set'), false, false, true));

        // Symbol.iterator points to values method. Per spec, Set.prototype[@@iterator] === Set.prototype.values.
        $iterSym = SymbolConstructor::iterator();
        if ($valuesDesc->value !== null) {
            $proto->definePropertyBySymbol($iterSym, PropertyDescriptor::data($valuesDesc->value, true, false, true));
        }

        // Set.prototype.keys === Set.prototype.values per spec.
        // Already defined keys separately above with same behavior.

        return $proto;
    }

    /** Create a Set value iterator object. */
    private static function createValueIterator(JsSet $set): JsObject
    {
        $index = 0;
        $iterSym = SymbolConstructor::iterator();
        $iterator = new JsObject();
        $nextFn = function () use ($set, &$index): JsValue {
            $result = new JsObject();
            $values = $set->setValues();
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

    /** Create a Set entry iterator object (each entry is [value, value]). */
    private static function createEntryIterator(JsSet $set): JsObject
    {
        $index = 0;
        $iterSym = SymbolConstructor::iterator();
        $iterator = new JsObject();
        $nextFn = function () use ($set, &$index): JsValue {
            $result = new JsObject();
            $values = $set->setValues();
            if ($index < count($values)) {
                $value = $values[$index];
                $result->set('value', JsArray::fromArray([$value, $value]));
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
