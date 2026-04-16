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

        $constructor = JsFunction::fromCallable('Object', function (JsValue $this_, array $args) use ($proto, $env): JsValue {
            if (empty($args) || $args[0] instanceof JsUndefined || $args[0] instanceof JsNull) {
                // When called via new with no arg / null / undefined, create new object
                if ($this_ instanceof JsObject && $this_->has('[[NewTarget]]')) {
                    return $this_;
                }
                $obj = new JsObject($proto);
                return $obj;
            }
            $val = $args[0];
            // If already an object, return it directly
            if ($val instanceof JsObject) {
                return $val;
            }
            // For primitives, create wrapper with correct prototype
            $wrapper = TypeConversion::toObject($val);
            // Try to set the correct prototype from the global constructor
            $ctorName = match (true) {
                $val instanceof JsString => 'String',
                $val instanceof JsNumber => 'Number',
                $val instanceof JsBoolean => 'Boolean',
                $val instanceof JsSymbol => 'Symbol',
                default => null,
            };
            if ($ctorName !== null && $env->has($ctorName)) {
                $ctor = $env->get($ctorName);
                if ($ctor instanceof JsFunction) {
                    $ctorProto = $ctor->get('prototype');
                    if ($ctorProto instanceof JsObject) {
                        $wrapper->setPrototype($ctorProto);
                    }
                }
            }
            return $wrapper;
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
            // Per spec, GetOwnPropertyKeys(O, Symbol) uses [[OwnPropertyKeys]] and filters for symbols.
            $allKeys = $obj->ordinaryOwnPropertyKeys();
            $symbols = [];
            foreach ($allKeys as $keyVal) {
                if ($keyVal instanceof JsSymbol) {
                    $symbols[] = $keyVal;
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
                $key = $args[0] ?? JsUndefined::instance();
                // Per spec, hasOwnProperty accepts symbol keys.
                if ($key instanceof \PhpJs\Value\JsSymbol) {
                    return new JsBoolean(
                        $this_->getSymbolPropertyDescriptor($key) !== null,
                    );
                }
                $prop = TypeConversion::toString($key);
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
                // Check wrapper objects with [[PrimitiveValue]] for Boolean/Number/String.
                if ($this_ instanceof JsObject && $this_->has('[[PrimitiveValue]]')) {
                    $prim = $this_->get('[[PrimitiveValue]]');
                    if ($prim instanceof JsBoolean) {
                        return new JsString('[object Boolean]');
                    }
                    if ($prim instanceof JsNumber) {
                        return new JsString('[object Number]');
                    }
                    if ($prim instanceof JsString) {
                        return new JsString('[object String]');
                    }
                }
                // Check for RegExp-like (has source property)
                if ($this_ instanceof JsObject && $this_->has('source') && $this_->has('flags')) {
                    return new JsString('[object RegExp]');
                }
                // Check for [[ErrorData]] — error objects have 'stack' and inherit from Error.prototype
                if ($this_ instanceof JsObject && $this_->has('stack')) {
                    $nameVal = $this_->get('name');
                    if ($nameVal instanceof JsString) {
                        $n = $nameVal->value;
                        if (in_array($n, ['Error', 'TypeError', 'RangeError', 'ReferenceError', 'SyntaxError', 'URIError', 'EvalError', 'AggregateError'], true)) {
                            return new JsString('[object Error]');
                        }
                    }
                }
                // Check for Date-like (has [[IsDate]] internal slot)
                if ($this_ instanceof JsObject && $this_->has('[[IsDate]]')) {
                    return new JsString('[object Date]');
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
                $key = $args[0] ?? JsUndefined::instance();
                // Per spec, propertyIsEnumerable accepts symbol keys.
                if ($key instanceof \PhpJs\Value\JsSymbol) {
                    $desc = $this_->getSymbolPropertyDescriptor($key);
                } else {
                    $prop = TypeConversion::toString($key);
                    $desc = $this_->getOwnPropertyDescriptor($prop);
                }
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

        // Annex B: __defineGetter__(prop, getter) - defines an accessor getter.
        $proto->defineOwnProperty('__defineGetter__', PropertyDescriptor::data(JsFunction::fromCallable(
            '__defineGetter__',
            function (JsValue $this_, array $args): JsValue {
                $obj = TypeConversion::toObject($this_);
                $getter = $args[1] ?? JsUndefined::instance();
                if (!$getter instanceof JsFunction) {
                    throw new TypeError('__defineGetter__: Argument is not a function');
                }
                $prop = TypeConversion::toString($args[0] ?? JsUndefined::instance());
                $desc = new PropertyDescriptor(
                    get: $getter,
                    enumerable: true,
                    configurable: true,
                );
                $result = $obj->defineOwnProperty($prop, $desc);
                if (!$result) {
                    throw new TypeError(
                        'Cannot redefine property: ' . $prop,
                    );
                }
                return JsUndefined::instance();
            },
            2,
        ), true, false, true));

        // Annex B: __defineSetter__(prop, setter) - defines an accessor setter.
        $proto->defineOwnProperty('__defineSetter__', PropertyDescriptor::data(JsFunction::fromCallable(
            '__defineSetter__',
            function (JsValue $this_, array $args): JsValue {
                $obj = TypeConversion::toObject($this_);
                $setter = $args[1] ?? JsUndefined::instance();
                if (!$setter instanceof JsFunction) {
                    throw new TypeError('__defineSetter__: Argument is not a function');
                }
                $prop = TypeConversion::toString($args[0] ?? JsUndefined::instance());
                $desc = new PropertyDescriptor(
                    set: $setter,
                    enumerable: true,
                    configurable: true,
                );
                $result = $obj->defineOwnProperty($prop, $desc);
                if (!$result) {
                    throw new TypeError(
                        'Cannot redefine property: ' . $prop,
                    );
                }
                return JsUndefined::instance();
            },
            2,
        ), true, false, true));

        // Annex B: __lookupGetter__(prop) - returns getter for prop walking prototype chain.
        $proto->defineOwnProperty('__lookupGetter__', PropertyDescriptor::data(JsFunction::fromCallable(
            '__lookupGetter__',
            function (JsValue $this_, array $args): JsValue {
                $obj = TypeConversion::toObject($this_);
                $prop = TypeConversion::toString($args[0] ?? JsUndefined::instance());
                $current = $obj;
                while ($current !== null) {
                    $desc = $current->getOwnPropertyDescriptor($prop);
                    if ($desc !== null) {
                        return $desc->get instanceof JsFunction ? $desc->get : JsUndefined::instance();
                    }
                    $current = $current->getPrototype();
                }
                return JsUndefined::instance();
            },
            1,
        ), true, false, true));

        // Annex B: __lookupSetter__(prop) - returns setter for prop walking prototype chain.
        $proto->defineOwnProperty('__lookupSetter__', PropertyDescriptor::data(JsFunction::fromCallable(
            '__lookupSetter__',
            function (JsValue $this_, array $args): JsValue {
                $obj = TypeConversion::toObject($this_);
                $prop = TypeConversion::toString($args[0] ?? JsUndefined::instance());
                $current = $obj;
                while ($current !== null) {
                    $desc = $current->getOwnPropertyDescriptor($prop);
                    if ($desc !== null) {
                        return $desc->set instanceof JsFunction ? $desc->set : JsUndefined::instance();
                    }
                    $current = $current->getPrototype();
                }
                return JsUndefined::instance();
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

    /**
     * EnumerableOwnPropertyNames per spec 7.3.23.
     *
     * Uses [[OwnPropertyKeys]] then for each key:
     * 1. [[GetOwnProperty]](key) to check enumerability
     * 2. If enumerable, optionally [[Get]](key) for value
     *
     * This interleaving order is observable through Proxy traps.
     *
     * @param JsObject $obj
     * @param string $kind 'key', 'value', or 'key+value'
     * @return list<JsValue>
     */
    private static function enumerableOwnPropertyNames(JsObject $obj, string $kind): array
    {
        // Get own string keys in spec order via [[OwnPropertyKeys]] (integer indices first).
        $ownKeys = $obj->getOwnPropertyNames();
        $result = [];
        foreach ($ownKeys as $key) {
            $desc = $obj->getOwnPropertyDescriptor($key);
            if ($desc !== null && $desc->enumerable === true) {
                if ($kind === 'key') {
                    $result[] = new JsString($key);
                } elseif ($kind === 'value') {
                    $result[] = $obj->get($key);
                } else {
                    $result[] = JsArray::fromArray([new JsString($key), $obj->get($key)]);
                }
            }
        }
        return $result;
    }

    private static function keys(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $obj = TypeConversion::toObject($args[0] ?? JsUndefined::instance());
            return JsArray::fromArray(self::enumerableOwnPropertyNames($obj, 'key'));
        };
    }

    private static function values(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $obj = TypeConversion::toObject($args[0] ?? JsUndefined::instance());
            return JsArray::fromArray(self::enumerableOwnPropertyNames($obj, 'value'));
        };
    }

    private static function entries(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $obj = TypeConversion::toObject($args[0] ?? JsUndefined::instance());
            return JsArray::fromArray(self::enumerableOwnPropertyNames($obj, 'key+value'));
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
                // Per spec: let from = ToObject(nextSource).
                // This handles string sources (string objects have index-keyed char properties).
                $from = $source instanceof JsObject ? $source : TypeConversion::toObject($source);
                // Iterate own property keys in spec order (integer indices, string keys, symbols).
                foreach ($from->ordinaryOwnPropertyKeys() as $key) {
                    if ($key instanceof \PhpJs\Value\JsSymbol) {
                        $desc = $from->getSymbolPropertyDescriptor($key);
                        if ($desc !== null && $desc->enumerable === true) {
                            $value = $from->getBySymbol($key);
                            $target->setBySymbol($key, $value, true);
                        }
                    } elseif ($key instanceof JsString) {
                        $keyStr = $key->value;
                        $desc = $from->getOwnPropertyDescriptor($keyStr);
                        if ($desc !== null && $desc->enumerable === true) {
                            // Use strict=true: Set(to, nextKey, propValue, true) per spec.
                            $target->set($keyStr, $from->get($keyStr), true);
                        }
                    }
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
                    enumerable: $enumerable,
                    configurable: $configurable,
                );
            } else {
                $descriptor = new PropertyDescriptor(
                    value: $value,
                    writable: $writable,
                    enumerable: $enumerable,
                    configurable: $configurable,
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
            // Per spec SetIntegrityLevel(O, "frozen"):
            // 1. preventExtensions
            // 2. Get all keys via [[OwnPropertyKeys]]
            // 3. For each key, get descriptor via [[GetOwnProperty]]
            // 4. DefinePropertyOrThrow with partial descriptor
            $obj->preventExtensions();

            $allKeys = $obj->ordinaryOwnPropertyKeys();
            foreach ($allKeys as $keyVal) {
                if ($keyVal instanceof JsSymbol) {
                    $desc = $obj->getSymbolPropertyDescriptor($keyVal);
                    if ($desc === null) {
                        continue;
                    }
                    if ($desc->isDataDescriptor()) {
                        $obj->definePropertyBySymbol($keyVal, new PropertyDescriptor(
                            writable: false,
                            configurable: false,
                        ));
                    } else {
                        $obj->definePropertyBySymbol($keyVal, new PropertyDescriptor(
                            configurable: false,
                        ));
                    }
                } else {
                    $key = $keyVal instanceof JsString ? $keyVal->value : TypeConversion::toString($keyVal);
                    $desc = $obj->getOwnPropertyDescriptor($key);
                    if ($desc === null) {
                        continue;
                    }
                    if ($desc->isDataDescriptor()) {
                        $obj->defineOwnProperty($key, new PropertyDescriptor(
                            writable: false,
                            configurable: false,
                        ));
                    } else {
                        $obj->defineOwnProperty($key, new PropertyDescriptor(
                            configurable: false,
                        ));
                    }
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
            $obj = TypeConversion::toObject($args[0] ?? JsUndefined::instance());
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
            $obj = TypeConversion::toObject($args[0] ?? JsUndefined::instance());
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

            // Per spec, an extensible object is never frozen.
            if ($obj->isExtensible()) {
                return new JsBoolean(false);
            }

            // Check all own properties (string and symbol) via [[OwnPropertyKeys]].
            $allKeys = $obj->ordinaryOwnPropertyKeys();
            foreach ($allKeys as $keyVal) {
                if ($keyVal instanceof JsSymbol) {
                    $desc = $obj->getSymbolPropertyDescriptor($keyVal);
                } else {
                    $key = $keyVal instanceof JsString ? $keyVal->value : TypeConversion::toString($keyVal);
                    $desc = $obj->getOwnPropertyDescriptor($key);
                }
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

            // Per spec, an extensible object is never sealed.
            if ($obj->isExtensible()) {
                return new JsBoolean(false);
            }

            // Check all own properties (string and symbol) via [[OwnPropertyKeys]].
            $allKeys = $obj->ordinaryOwnPropertyKeys();
            foreach ($allKeys as $keyVal) {
                if ($keyVal instanceof JsSymbol) {
                    $desc = $obj->getSymbolPropertyDescriptor($keyVal);
                } else {
                    $key = $keyVal instanceof JsString ? $keyVal->value : TypeConversion::toString($keyVal);
                    $desc = $obj->getOwnPropertyDescriptor($key);
                }
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
            // Per spec SetIntegrityLevel(O, "sealed"):
            // 1. preventExtensions
            // 2. Get all keys via [[OwnPropertyKeys]]
            // 3. For each key: DefinePropertyOrThrow(O, k, {[[Configurable]]: false})
            $obj->preventExtensions();

            $allKeys = $obj->ordinaryOwnPropertyKeys();
            foreach ($allKeys as $keyVal) {
                if ($keyVal instanceof JsSymbol) {
                    $obj->definePropertyBySymbol($keyVal, new PropertyDescriptor(
                        configurable: false,
                    ));
                } else {
                    $key = $keyVal instanceof JsString ? $keyVal->value : TypeConversion::toString($keyVal);
                    $obj->defineOwnProperty($key, new PropertyDescriptor(
                        configurable: false,
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
