<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Object\PropertyDescriptor;
use PhpJs\Runtime\Environment;
use PhpJs\Spec\AbstractOperations;
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

class ObjectConstructor
{
    public static function install(Environment $env): void
    {
        $proto = self::createPrototype();

        $constructor = JsFunction::fromCallable('Object', function (JsValue $this_, array $args) use ($proto): JsValue {
            if (empty($args) || $args[0] instanceof JsUndefined || $args[0] instanceof JsNull) {
                $obj = new JsObject($proto);
                return $obj;
            }
            // If called with a value, convert to object.
            return TypeConversion::toObject($args[0]);
        });

        $constructor->set('prototype', $proto);
        $proto->set('constructor', $constructor);

        // Static methods.
        $constructor->set('keys', JsFunction::fromCallable('keys', self::keys()));
        $constructor->set('values', JsFunction::fromCallable('values', self::values()));
        $constructor->set('entries', JsFunction::fromCallable('entries', self::entries()));
        $constructor->set('assign', JsFunction::fromCallable('assign', self::assign()));
        $constructor->set('create', JsFunction::fromCallable('create', self::create($proto)));
        $constructor->set('defineProperty', JsFunction::fromCallable('defineProperty', self::definePropertyFn()));
        $constructor->set('getPrototypeOf', JsFunction::fromCallable('getPrototypeOf', self::getPrototypeOf()));
        $constructor->set('freeze', JsFunction::fromCallable('freeze', self::freeze()));
        $constructor->set('is', JsFunction::fromCallable('is', self::is()));
        $constructor->set('getOwnPropertyNames', JsFunction::fromCallable('getOwnPropertyNames', self::getOwnPropertyNamesFn()));

        $env->defineVar('Object', $constructor);
    }

    private static function createPrototype(): JsObject
    {
        $proto = new JsObject();

        $proto->set('hasOwnProperty', JsFunction::fromCallable(
            'hasOwnProperty',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return new JsBoolean(false);
                }
                $prop = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
                return new JsBoolean($this_->hasOwnProperty($prop));
            },
        ));

        $proto->set('toString', JsFunction::fromCallable(
            'toString',
            function (JsValue $this_): JsValue {
                if ($this_ instanceof JsUndefined) {
                    return new JsString('[object Undefined]');
                }
                if ($this_ instanceof JsNull) {
                    return new JsString('[object Null]');
                }
                return new JsString('[object Object]');
            },
        ));

        $proto->set('valueOf', JsFunction::fromCallable(
            'valueOf',
            function (JsValue $this_): JsValue {
                return $this_;
            },
        ));

        return $proto;
    }

    private static function keys(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $obj = $args[0] ?? JsUndefined::instance();
            if (!$obj instanceof JsObject) {
                return new JsArray();
            }
            $keys = $obj->getEnumerableKeys();
            $jsKeys = array_map(fn(string $k) => new JsString($k), $keys);
            return JsArray::fromArray($jsKeys);
        };
    }

    private static function values(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $obj = $args[0] ?? JsUndefined::instance();
            if (!$obj instanceof JsObject) {
                return new JsArray();
            }
            $keys = $obj->getEnumerableKeys();
            $values = array_map(fn(string $k) => $obj->get($k), $keys);
            return JsArray::fromArray($values);
        };
    }

    private static function entries(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $obj = $args[0] ?? JsUndefined::instance();
            if (!$obj instanceof JsObject) {
                return new JsArray();
            }
            $keys = $obj->getEnumerableKeys();
            $entries = [];
            foreach ($keys as $key) {
                $entries[] = JsArray::fromArray([new JsString($key), $obj->get($key)]);
            }
            return JsArray::fromArray($entries);
        };
    }

    private static function assign(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $target = $args[0] ?? JsUndefined::instance();
            if (!$target instanceof JsObject) {
                $target = TypeConversion::toObject($target);
            }

            for ($i = 1; $i < count($args); $i++) {
                $source = $args[$i];
                if ($source instanceof JsNull || $source instanceof JsUndefined) {
                    continue;
                }
                if (!$source instanceof JsObject) {
                    continue;
                }
                $keys = $source->getEnumerableKeys();
                foreach ($keys as $key) {
                    $target->set($key, $source->get($key));
                }
            }

            return $target;
        };
    }

    private static function create(JsObject $defaultProto): \Closure
    {
        return function (JsValue $this_, array $args) use ($defaultProto): JsValue {
            $proto = $args[0] ?? JsUndefined::instance();
            if ($proto instanceof JsNull) {
                return new JsObject(null);
            }
            if ($proto instanceof JsObject) {
                return new JsObject($proto);
            }
            if ($proto instanceof JsUndefined) {
                return new JsObject($defaultProto);
            }
            throw new \PhpJs\Exceptions\TypeError('Object prototype may only be an Object or null');
        };
    }

    private static function definePropertyFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $obj = $args[0] ?? JsUndefined::instance();
            if (!$obj instanceof JsObject) {
                throw new \PhpJs\Exceptions\TypeError('Object.defineProperty called on non-object');
            }

            $prop = isset($args[1]) ? TypeConversion::toString($args[1]) : 'undefined';
            $desc = $args[2] ?? JsUndefined::instance();

            if (!$desc instanceof JsObject) {
                throw new \PhpJs\Exceptions\TypeError('Property description must be an object');
            }

            $value = $desc->has('value') ? $desc->get('value') : null;
            $writable = $desc->has('writable') ? TypeConversion::toBoolean($desc->get('writable')) : null;
            $enumerable = $desc->has('enumerable') ? TypeConversion::toBoolean($desc->get('enumerable')) : null;
            $configurable = $desc->has('configurable') ? TypeConversion::toBoolean($desc->get('configurable')) : null;

            $getter = null;
            $setter = null;
            if ($desc->has('get')) {
                $g = $desc->get('get');
                if ($g instanceof JsFunction) {
                    $getter = $g;
                }
            }
            if ($desc->has('set')) {
                $s = $desc->get('set');
                if ($s instanceof JsFunction) {
                    $setter = $s;
                }
            }

            if ($getter !== null || $setter !== null) {
                $obj->defineOwnProperty($prop, PropertyDescriptor::accessor(
                    get: $getter,
                    set: $setter,
                    enumerable: $enumerable ?? false,
                    configurable: $configurable ?? false,
                ));
            } else {
                $obj->defineOwnProperty($prop, new PropertyDescriptor(
                    value: $value,
                    writable: $writable ?? false,
                    enumerable: $enumerable ?? false,
                    configurable: $configurable ?? false,
                ));
            }

            return $obj;
        };
    }

    private static function getPrototypeOf(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $obj = $args[0] ?? JsUndefined::instance();
            if (!$obj instanceof JsObject) {
                $obj = TypeConversion::toObject($obj);
            }
            $proto = $obj->getPrototype();
            return $proto ?? JsNull::instance();
        };
    }

    private static function freeze(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $obj = $args[0] ?? JsUndefined::instance();
            if (!$obj instanceof JsObject) {
                return $obj;
            }
            // Simplified: mark all own properties as non-writable and non-configurable.
            $keys = $obj->getOwnPropertyNames();
            foreach ($keys as $key) {
                $desc = $obj->getOwnPropertyDescriptor($key);
                if ($desc === null) {
                    continue;
                }
                if ($desc->isDataDescriptor()) {
                    $obj->defineOwnProperty($key, new PropertyDescriptor(
                        value: $desc->value,
                        writable: false,
                        enumerable: $desc->enumerable,
                        configurable: false,
                    ));
                } else {
                    $obj->defineOwnProperty($key, new PropertyDescriptor(
                        enumerable: $desc->enumerable,
                        configurable: false,
                        get: $desc->get,
                        set: $desc->set,
                    ));
                }
            }
            return $obj;
        };
    }

    private static function is(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $x = $args[0] ?? JsUndefined::instance();
            $y = $args[1] ?? JsUndefined::instance();
            return new JsBoolean(AbstractOperations::sameValue($x, $y));
        };
    }

    private static function getOwnPropertyNamesFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $obj = $args[0] ?? JsUndefined::instance();
            if (!$obj instanceof JsObject) {
                return new JsArray();
            }
            $names = $obj->getOwnPropertyNames();
            $jsNames = array_map(fn(string $n) => new JsString($n), $names);
            return JsArray::fromArray($jsNames);
        };
    }
}
