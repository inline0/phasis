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
        $constructor->set('defineProperties', JsFunction::fromCallable(
            'defineProperties',
            self::definePropertiesFn(),
        ));
        $constructor->set('getOwnPropertyDescriptor', JsFunction::fromCallable(
            'getOwnPropertyDescriptor',
            self::getOwnPropertyDescriptorFn(),
        ));
        $constructor->set('setPrototypeOf', JsFunction::fromCallable('setPrototypeOf', self::setPrototypeOf()));
        $constructor->set('isFrozen', JsFunction::fromCallable('isFrozen', self::isFrozen()));
        $constructor->set('isSealed', JsFunction::fromCallable('isSealed', self::isSealed()));
        $constructor->set('isExtensible', JsFunction::fromCallable('isExtensible', self::isExtensible()));
        $constructor->set('seal', JsFunction::fromCallable('seal', self::seal()));
        $constructor->set('preventExtensions', JsFunction::fromCallable(
            'preventExtensions',
            self::preventExtensions(),
        ));
        $constructor->set('fromEntries', JsFunction::fromCallable('fromEntries', self::fromEntries($proto)));

        $env->defineVar('Object', $constructor);

        // Store the prototype for auto-boxing and object literal creation.
        $env->defineVar('__ObjectPrototype__', $proto);
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

        $proto->set('propertyIsEnumerable', JsFunction::fromCallable(
            'propertyIsEnumerable',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return new JsBoolean(false);
                }
                $prop = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
                $desc = $this_->getOwnPropertyDescriptor($prop);
                if ($desc === null) {
                    return new JsBoolean(false);
                }
                return new JsBoolean($desc->enumerable === true);
            },
        ));

        $proto->set('isPrototypeOf', JsFunction::fromCallable(
            'isPrototypeOf',
            function (JsValue $this_, array $args): JsValue {
                $v = $args[0] ?? JsUndefined::instance();
                if (!$v instanceof JsObject) {
                    return new JsBoolean(false);
                }
                if (!$this_ instanceof JsObject) {
                    return new JsBoolean(false);
                }
                $current = $v->getPrototype();
                while ($current !== null) {
                    if ($current === $this_) {
                        return new JsBoolean(true);
                    }
                    $current = $current->getPrototype();
                }
                return new JsBoolean(false);
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

    private static function definePropertiesFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $obj = $args[0] ?? JsUndefined::instance();
            if (!$obj instanceof JsObject) {
                throw new \PhpJs\Exceptions\TypeError('Object.defineProperties called on non-object');
            }

            $props = $args[1] ?? JsUndefined::instance();
            if (!$props instanceof JsObject) {
                throw new \PhpJs\Exceptions\TypeError('Property descriptors must be an object');
            }

            $keys = $props->getEnumerableKeys();
            foreach ($keys as $key) {
                $desc = $props->get($key);
                if (!$desc instanceof JsObject) {
                    continue;
                }

                $value = $desc->has('value') ? $desc->get('value') : null;
                $writable = $desc->has('writable') ? TypeConversion::toBoolean($desc->get('writable')) : null;
                $enumerable = $desc->has('enumerable') ? TypeConversion::toBoolean($desc->get('enumerable')) : null;
                $configurable = $desc->has('configurable')
                    ? TypeConversion::toBoolean($desc->get('configurable'))
                    : null;

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
                    $obj->defineOwnProperty($key, PropertyDescriptor::accessor(
                        get: $getter,
                        set: $setter,
                        enumerable: $enumerable ?? false,
                        configurable: $configurable ?? false,
                    ));
                } else {
                    $obj->defineOwnProperty($key, new PropertyDescriptor(
                        value: $value,
                        writable: $writable ?? false,
                        enumerable: $enumerable ?? false,
                        configurable: $configurable ?? false,
                    ));
                }
            }

            return $obj;
        };
    }

    private static function getOwnPropertyDescriptorFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $obj = $args[0] ?? JsUndefined::instance();
            if (!$obj instanceof JsObject) {
                return JsUndefined::instance();
            }
            $prop = isset($args[1]) ? TypeConversion::toString($args[1]) : 'undefined';
            $desc = $obj->getOwnPropertyDescriptor($prop);
            if ($desc === null) {
                return JsUndefined::instance();
            }

            $result = new JsObject();
            if ($desc->isAccessorDescriptor()) {
                $result->set('get', $desc->get ?? JsUndefined::instance());
                $result->set('set', $desc->set ?? JsUndefined::instance());
            } else {
                $result->set('value', $desc->value ?? JsUndefined::instance());
                $result->set('writable', new JsBoolean($desc->writable ?? false));
            }
            $result->set('enumerable', new JsBoolean($desc->enumerable ?? false));
            $result->set('configurable', new JsBoolean($desc->configurable ?? false));
            return $result;
        };
    }

    private static function setPrototypeOf(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $obj = $args[0] ?? JsUndefined::instance();
            $proto = $args[1] ?? JsUndefined::instance();
            if (!$obj instanceof JsObject) {
                return $obj;
            }
            if ($proto instanceof JsNull) {
                $obj->setPrototype(null);
            } elseif ($proto instanceof JsObject) {
                $obj->setPrototype($proto);
            } else {
                throw new \PhpJs\Exceptions\TypeError('Object prototype may only be an Object or null');
            }
            return $obj;
        };
    }

    private static function isFrozen(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $obj = $args[0] ?? JsUndefined::instance();
            if (!$obj instanceof JsObject) {
                return new JsBoolean(true);
            }
            $keys = $obj->getOwnPropertyNames();
            foreach ($keys as $key) {
                $desc = $obj->getOwnPropertyDescriptor($key);
                if ($desc === null) {
                    continue;
                }
                if ($desc->configurable === true) {
                    return new JsBoolean(false);
                }
                if ($desc->isDataDescriptor() && $desc->writable === true) {
                    return new JsBoolean(false);
                }
            }
            return new JsBoolean(true);
        };
    }

    private static function isSealed(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $obj = $args[0] ?? JsUndefined::instance();
            if (!$obj instanceof JsObject) {
                return new JsBoolean(true);
            }
            $keys = $obj->getOwnPropertyNames();
            foreach ($keys as $key) {
                $desc = $obj->getOwnPropertyDescriptor($key);
                if ($desc === null) {
                    continue;
                }
                if ($desc->configurable === true) {
                    return new JsBoolean(false);
                }
            }
            return new JsBoolean(true);
        };
    }

    private static function isExtensible(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $obj = $args[0] ?? JsUndefined::instance();
            if (!$obj instanceof JsObject) {
                return new JsBoolean(false);
            }
            // For now, all objects are extensible. Full support would require
            // an internal [[Extensible]] slot on JsObject.
            return new JsBoolean(true);
        };
    }

    private static function seal(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $obj = $args[0] ?? JsUndefined::instance();
            if (!$obj instanceof JsObject) {
                return $obj;
            }
            $keys = $obj->getOwnPropertyNames();
            foreach ($keys as $key) {
                $desc = $obj->getOwnPropertyDescriptor($key);
                if ($desc === null) {
                    continue;
                }
                if ($desc->isDataDescriptor()) {
                    $obj->defineOwnProperty($key, new PropertyDescriptor(
                        value: $desc->value,
                        writable: $desc->writable,
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

    private static function preventExtensions(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $obj = $args[0] ?? JsUndefined::instance();
            if (!$obj instanceof JsObject) {
                return $obj;
            }
            // Simplified: in a full implementation, this would set [[Extensible]] to false.
            return $obj;
        };
    }

    private static function fromEntries(JsObject $proto): \Closure
    {
        return function (JsValue $this_, array $args) use ($proto): JsValue {
            $iterable = $args[0] ?? JsUndefined::instance();
            $obj = new JsObject($proto);

            if ($iterable instanceof JsArray) {
                for ($i = 0; $i < $iterable->getLength(); $i++) {
                    $entry = $iterable->get((string) $i);
                    if ($entry instanceof JsArray && $entry->getLength() >= 2) {
                        $key = TypeConversion::toString($entry->get('0'));
                        $value = $entry->get('1');
                        $obj->set($key, $value);
                    }
                }
            }

            return $obj;
        };
    }
}
