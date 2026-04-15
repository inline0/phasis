<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Exceptions\TypeError;
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
use PhpJs\Value\JsSymbol;
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
        $constructor->setConstructable();

        $constructor->set('prototype', $proto);
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($constructor, true, false, true));

        // Static methods. Per spec, built-in methods are writable, non-enumerable, configurable.
        $builtinMethod = static fn(string $n, callable $fn, int $len): PropertyDescriptor =>
            PropertyDescriptor::data(JsFunction::fromCallable($n, $fn, $len), true, false, true);

        $constructor->defineOwnProperty('keys', $builtinMethod('keys', self::keys(), 1));
        $constructor->defineOwnProperty('values', $builtinMethod('values', self::values(), 1));
        $constructor->defineOwnProperty('entries', $builtinMethod('entries', self::entries(), 1));
        $constructor->defineOwnProperty('assign', $builtinMethod('assign', self::assign(), 2));
        $constructor->defineOwnProperty('create', $builtinMethod('create', self::create($proto), 2));
        $constructor->defineOwnProperty('defineProperty', $builtinMethod('defineProperty', self::definePropertyFn(), 3));
        $constructor->defineOwnProperty('getPrototypeOf', $builtinMethod('getPrototypeOf', self::getPrototypeOf(), 1));
        $constructor->defineOwnProperty('freeze', $builtinMethod('freeze', self::freeze(), 1));
        $constructor->defineOwnProperty('is', $builtinMethod('is', self::is(), 2));
        $constructor->defineOwnProperty('getOwnPropertyNames', $builtinMethod('getOwnPropertyNames', self::getOwnPropertyNamesFn(), 1));
        $constructor->defineOwnProperty('defineProperties', $builtinMethod('defineProperties', self::definePropertiesFn(), 2));
        $constructor->defineOwnProperty('getOwnPropertyDescriptor', $builtinMethod('getOwnPropertyDescriptor', self::getOwnPropertyDescriptorFn(), 2));
        $constructor->defineOwnProperty('setPrototypeOf', $builtinMethod('setPrototypeOf', self::setPrototypeOf(), 2));
        $constructor->defineOwnProperty('isFrozen', $builtinMethod('isFrozen', self::isFrozen(), 1));
        $constructor->defineOwnProperty('isSealed', $builtinMethod('isSealed', self::isSealed(), 1));
        $constructor->defineOwnProperty('isExtensible', $builtinMethod('isExtensible', self::isExtensible(), 1));
        $constructor->defineOwnProperty('seal', $builtinMethod('seal', self::seal(), 1));
        $constructor->defineOwnProperty('preventExtensions', $builtinMethod('preventExtensions', self::preventExtensions(), 1));
        $constructor->defineOwnProperty('fromEntries', $builtinMethod('fromEntries', self::fromEntries($proto), 1));

        // Modern APIs (non-enumerable per spec)
        $hasOwnFn = JsFunction::fromCallable('hasOwn', function (JsValue $this_, array $args): JsValue {
            $obj = TypeConversion::toObject($args[0] ?? JsUndefined::instance());
            $key = TypeConversion::toPropertyKey($args[1] ?? JsUndefined::instance());
            if ($key instanceof JsSymbol) {
                return new JsBoolean($obj->hasBySymbol($key));
            }
            return new JsBoolean($obj->hasOwnProperty($key->toJsString()));
        }, 2);
        $hasOwnFn->setNonConstructable();
        $constructor->defineOwnProperty('hasOwn', PropertyDescriptor::data($hasOwnFn, true, false, true));

        $constructor->defineOwnProperty('getOwnPropertySymbols', $builtinMethod('getOwnPropertySymbols', function (JsValue $this_, array $args): JsValue {
            $obj = TypeConversion::toObject($args[0] ?? JsUndefined::instance());
            $symbols = [];
            foreach ($obj->getOwnSymbolProperties() as $id => $desc) {
                $sym = JsSymbol::fromId($id);
                if ($sym !== null) {
                    $symbols[] = $sym;
                }
            }
            return JsArray::fromArray($symbols);
        }, 1));

        $env->defineVar('Object', $constructor);

        // Store the prototype for auto-boxing and object literal creation.
        $env->defineVar('__ObjectPrototype__', $proto);

        // Set as global default prototype so all new JsObject() inherit valueOf/toString
        JsObject::setGlobalPrototype($proto);
    }

    private static function createPrototype(): JsObject
    {
        $proto = new JsObject();

        $proto->defineOwnProperty('hasOwnProperty', PropertyDescriptor::data(JsFunction::fromCallable(
            'hasOwnProperty',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return new JsBoolean(false);
                }
                $prop = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
                return new JsBoolean($this_->hasOwnProperty($prop));
            },
            1,
        ), true, false, true));

        $proto->defineOwnProperty('toString', PropertyDescriptor::data(JsFunction::fromCallable(
            'toString',
            function (JsValue $this_): JsValue {
                if ($this_ instanceof JsUndefined) {
                    return new JsString('[object Undefined]');
                }
                if ($this_ instanceof JsNull) {
                    return new JsString('[object Null]');
                }
                if ($this_ instanceof JsBoolean) {
                    return new JsString('[object Boolean]');
                }
                if ($this_ instanceof JsNumber) {
                    return new JsString('[object Number]');
                }
                if ($this_ instanceof JsString) {
                    return new JsString('[object String]');
                }
                if ($this_ instanceof JsSymbol) {
                    return new JsString('[object Symbol]');
                }
                if ($this_ instanceof JsFunction) {
                    return new JsString('[object Function]');
                }
                if ($this_ instanceof JsArray) {
                    return new JsString('[object Array]');
                }
                // Check for Symbol.toStringTag
                $tag = null;
                if ($this_ instanceof JsObject) {
                    $tagSym = \PhpJs\BuiltIn\SymbolConstructor::toStringTag();
                    $tagVal = $this_->getBySymbol($tagSym);
                    if ($tagVal instanceof JsString) {
                        $tag = $tagVal->value;
                    }
                }
                if ($tag !== null) {
                    return new JsString("[object {$tag}]");
                }
                // Check for RegExp-like (has source property)
                if ($this_ instanceof JsObject && $this_->has('source') && $this_->has('flags')) {
                    return new JsString('[object RegExp]');
                }
                return new JsString('[object Object]');
            },
            0,
        ), true, false, true));

        $proto->defineOwnProperty('valueOf', PropertyDescriptor::data(JsFunction::fromCallable(
            'valueOf',
            function (JsValue $this_): JsValue {
                return $this_;
            },
            0,
        ), true, false, true));

        $proto->defineOwnProperty('propertyIsEnumerable', PropertyDescriptor::data(JsFunction::fromCallable(
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
            1,
        ), true, false, true));

        $proto->defineOwnProperty('isPrototypeOf', PropertyDescriptor::data(JsFunction::fromCallable(
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
            1,
        ), true, false, true));

        // toLocaleString delegates to toString per ES spec 20.1.3.5.
        $proto->defineOwnProperty('toLocaleString', PropertyDescriptor::data(JsFunction::fromCallable(
            'toLocaleString',
            function (JsValue $this_): JsValue {
                if (!$this_ instanceof JsObject) {
                    // Auto-box primitive to object for method dispatch.
                    $obj = TypeConversion::toObject($this_);
                } else {
                    $obj = $this_;
                }
                $toStringFn = $obj->get('toString');
                if (!$toStringFn instanceof JsFunction) {
                    throw new TypeError('toLocaleString: toString is not a function');
                }
                return $toStringFn->call($this_, []);
            },
            0,
        ), true, false, true));

        return $proto;
    }

    private static function keys(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $obj = TypeConversion::toObject($args[0] ?? JsUndefined::instance());
            $keys = $obj->getOwnEnumerableKeys();
            $jsKeys = array_map(fn(string $k) => new JsString($k), $keys);
            return JsArray::fromArray($jsKeys);
        };
    }

    private static function values(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $obj = TypeConversion::toObject($args[0] ?? JsUndefined::instance());
            $keys = $obj->getOwnEnumerableKeys();
            $values = array_map(fn(string $k) => $obj->get($k), $keys);
            return JsArray::fromArray($values);
        };
    }

    private static function entries(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $obj = TypeConversion::toObject($args[0] ?? JsUndefined::instance());
            $keys = $obj->getOwnEnumerableKeys();
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
                $keys = $source->getOwnEnumerableKeys();
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
            $obj = null;
            if ($proto instanceof JsNull) {
                // Cannot pass null directly to JsObject constructor because
                // PHP's ?? operator falls through to the static globalPrototype.
                // Instead, create and then explicitly set null prototype.
                $obj = new JsObject();
                $obj->setPrototype(null);
            } elseif ($proto instanceof JsObject) {
                $obj = new JsObject($proto);
            } elseif ($proto instanceof JsUndefined) {
                $obj = new JsObject($defaultProto);
            } else {
                throw new \PhpJs\Exceptions\TypeError('Object prototype may only be an Object or null');
            }

            // Second argument: property descriptors
            if (isset($args[1]) && $args[1] instanceof JsObject) {
                $props = $args[1];
                foreach ($props->getOwnEnumerableKeys() as $key) {
                    $desc = $props->get($key);
                    if ($desc instanceof JsObject) {
                        $value = $desc->has('value') ? $desc->get('value') : null;
                        $writable = $desc->has('writable')
                            ? TypeConversion::toBoolean($desc->get('writable')) : true;
                        $enumerable = $desc->has('enumerable')
                            ? TypeConversion::toBoolean($desc->get('enumerable')) : false;
                        $configurable = $desc->has('configurable')
                            ? TypeConversion::toBoolean($desc->get('configurable')) : false;
                        $get = $desc->has('get') ? $desc->get('get') : null;
                        $set = $desc->has('set') ? $desc->get('set') : null;

                        if ($get instanceof JsFunction || $set instanceof JsFunction) {
                            $obj->defineOwnProperty($key, PropertyDescriptor::accessor(
                                $get instanceof JsFunction ? $get : null,
                                $set instanceof JsFunction ? $set : null,
                                $enumerable,
                                $configurable,
                            ));
                        } elseif ($value !== null) {
                            $obj->defineOwnProperty($key, new PropertyDescriptor(
                                value: $value,
                                writable: $writable,
                                enumerable: $enumerable,
                                configurable: $configurable,
                            ));
                        }
                    }
                }
            }

            return $obj;
        };
    }

    private static function definePropertyFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $obj = $args[0] ?? JsUndefined::instance();
            if (!$obj instanceof JsObject) {
                throw new TypeError('Object.defineProperty called on non-object');
            }

            $keyRaw = $args[1] ?? JsUndefined::instance();
            $propKey = TypeConversion::toPropertyKey($keyRaw);
            $desc = $args[2] ?? JsUndefined::instance();

            if (!$desc instanceof JsObject) {
                throw new TypeError('Property description must be an object');
            }

            $value = $desc->has('value') ? $desc->get('value') : null;
            $writable = $desc->has('writable') ? TypeConversion::toBoolean($desc->get('writable')) : null;
            $enumerable = $desc->has('enumerable') ? TypeConversion::toBoolean($desc->get('enumerable')) : null;
            $configurable = $desc->has('configurable') ? TypeConversion::toBoolean($desc->get('configurable')) : null;

            $getter = null;
            $setter = null;
            $hasGetOrSet = false;
            if ($desc->has('get')) {
                $hasGetOrSet = true;
                $g = $desc->get('get');
                if ($g instanceof JsFunction) {
                    $getter = $g;
                } elseif (!$g instanceof JsUndefined) {
                    throw new TypeError('Getter must be a function');
                }
            }
            if ($desc->has('set')) {
                $hasGetOrSet = true;
                $s = $desc->get('set');
                if ($s instanceof JsFunction) {
                    $setter = $s;
                } elseif (!$s instanceof JsUndefined) {
                    throw new TypeError('Setter must be a function');
                }
            }

            if ($hasGetOrSet) {
                $descriptor = PropertyDescriptor::accessor(
                    get: $getter,
                    set: $setter,
                    enumerable: $enumerable ?? false,
                    configurable: $configurable ?? false,
                );
            } else {
                $descriptor = new PropertyDescriptor(
                    value: $value,
                    writable: $writable ?? false,
                    enumerable: $enumerable ?? false,
                    configurable: $configurable ?? false,
                );
            }

            if ($propKey instanceof JsSymbol) {
                $obj->definePropertyBySymbol($propKey, $descriptor);
            } else {
                $obj->defineOwnProperty($propKey->toJsString(), $descriptor);
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
            // Per spec: freeze prevents extensions and marks all own properties non-writable, non-configurable.
            $obj->preventExtensions();
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

            $keys = $props->getOwnEnumerableKeys();
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
            $keyRaw = $args[1] ?? JsUndefined::instance();
            $propKey = TypeConversion::toPropertyKey($keyRaw);

            if ($propKey instanceof JsSymbol) {
                $desc = $obj->getSymbolPropertyDescriptor($propKey);
            } else {
                $desc = $obj->getOwnPropertyDescriptor($propKey->toJsString());
            }

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
            return new JsBoolean($obj->isExtensible());
        };
    }

    private static function seal(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $obj = $args[0] ?? JsUndefined::instance();
            if (!$obj instanceof JsObject) {
                return $obj;
            }
            $obj->preventExtensions();
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
            $obj->preventExtensions();
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
