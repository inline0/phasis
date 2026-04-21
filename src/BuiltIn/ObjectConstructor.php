<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Exceptions\TypeError;
use PhpJs\Object\PropertyDescriptor;
use PhpJs\Runtime\Environment;
use PhpJs\Spec\AbstractOperations;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsArray;
use PhpJs\Value\JsBigInt;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsProxy;
use PhpJs\Value\JsString;
use PhpJs\Value\JsSymbol;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

class ObjectConstructor
{
    public static function install(Environment $env): void
    {
        // Reset global prototype so a new engine instance does not inherit
        // stale prototypes from a previous Engine instance.
        JsObject::resetGlobalPrototype();
        $proto = self::createPrototype();

        $constructor = JsFunction::fromCallable(
            'Object',
            function (JsValue $this_, array $args) use ($proto, $env): JsValue {
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
            },
        );
        $constructor->setConstructable();

        // Per spec, Object.prototype is non-writable, non-enumerable, non-configurable.
        $constructor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($constructor, true, false, true));

        // Static methods. Per spec, built-in methods are writable, non-enumerable, configurable.
        $builtinMethod = static fn(string $n, callable $fn, int $len): PropertyDescriptor =>
            PropertyDescriptor::data(JsFunction::fromCallable($n, $fn, $len), true, false, true);

        $constructor->defineOwnProperty('keys', $builtinMethod('keys', self::keys(), 1));
        $constructor->defineOwnProperty('values', $builtinMethod('values', self::values(), 1));
        $constructor->defineOwnProperty('entries', $builtinMethod('entries', self::entries(), 1));
        $constructor->defineOwnProperty('assign', $builtinMethod('assign', self::assign(), 2));
        $constructor->defineOwnProperty('create', $builtinMethod('create', self::create($proto), 2));
        $constructor->defineOwnProperty(
            'defineProperty',
            $builtinMethod('defineProperty', self::definePropertyFn(), 3),
        );
        $constructor->defineOwnProperty(
            'getPrototypeOf',
            $builtinMethod('getPrototypeOf', self::getPrototypeOf(), 1),
        );
        $constructor->defineOwnProperty('freeze', $builtinMethod('freeze', self::freeze(), 1));
        $constructor->defineOwnProperty('is', $builtinMethod('is', self::is(), 2));
        $constructor->defineOwnProperty(
            'getOwnPropertyNames',
            $builtinMethod('getOwnPropertyNames', self::getOwnPropertyNamesFn(), 1),
        );
        $constructor->defineOwnProperty(
            'defineProperties',
            $builtinMethod('defineProperties', self::definePropertiesFn(), 2),
        );
        $constructor->defineOwnProperty(
            'getOwnPropertyDescriptor',
            $builtinMethod('getOwnPropertyDescriptor', self::getOwnPropertyDescriptorFn(), 2),
        );
        $constructor->defineOwnProperty('setPrototypeOf', $builtinMethod('setPrototypeOf', self::setPrototypeOf(), 2));
        $constructor->defineOwnProperty('isFrozen', $builtinMethod('isFrozen', self::isFrozen(), 1));
        $constructor->defineOwnProperty('isSealed', $builtinMethod('isSealed', self::isSealed(), 1));
        $constructor->defineOwnProperty('isExtensible', $builtinMethod('isExtensible', self::isExtensible(), 1));
        $constructor->defineOwnProperty('seal', $builtinMethod('seal', self::seal(), 1));
        $constructor->defineOwnProperty(
            'preventExtensions',
            $builtinMethod('preventExtensions', self::preventExtensions(), 1),
        );
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

        $getOwnSymbolsCb = function (JsValue $this_, array $args): JsValue {
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
        };
        $constructor->defineOwnProperty(
            'getOwnPropertySymbols',
            $builtinMethod('getOwnPropertySymbols', $getOwnSymbolsCb, 1),
        );

        $getOwnPropDescsCb = function (JsValue $this_, array $args): JsValue {
            $obj = TypeConversion::toObject($args[0] ?? JsUndefined::instance());
            $result = new JsObject();
            // Per spec, iterate [[OwnPropertyKeys]] (string + symbol).
            $allKeys = $obj->ordinaryOwnPropertyKeys();
            foreach ($allKeys as $keyVal) {
                if ($keyVal instanceof JsSymbol) {
                    $desc = $obj->getSymbolPropertyDescriptor($keyVal);
                } else {
                    $keyStr = $keyVal instanceof JsString ? $keyVal->value : (string) $keyVal;
                    $desc = $obj->getOwnPropertyDescriptor($keyStr);
                }
                if ($desc === null) {
                    continue;
                }
                $descObj = self::fromPropertyDescriptor($desc);
                if ($keyVal instanceof JsSymbol) {
                    $result->defineOwnSymbolProperty($keyVal, PropertyDescriptor::data($descObj));
                } else {
                    $keyStr = $keyVal instanceof JsString ? $keyVal->value : (string) $keyVal;
                    $result->set($keyStr, $descObj);
                }
            }
            return $result;
        };
        $constructor->defineOwnProperty(
            'getOwnPropertyDescriptors',
            $builtinMethod('getOwnPropertyDescriptors', $getOwnPropDescsCb, 1),
        );

        $groupByCb = function (JsValue $this_, array $args): JsValue {
            $items = $args[0] ?? JsUndefined::instance();
            $callbackfn = $args[1] ?? JsUndefined::instance();
            if (!$callbackfn instanceof JsFunction) {
                throw new TypeError(TypeConversion::toString($callbackfn) . ' is not a function');
            }
            // Per spec, result has null prototype.
            $obj = new JsObject();
            $obj->setPrototype(null);
            // Get iterator.
            if ($items instanceof JsUndefined || $items instanceof JsNull) {
                $val = TypeConversion::toString($items);
                throw new TypeError(
                    "Cannot read properties of {$val} (reading 'Symbol(Symbol.iterator)')"
                );
            }
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
            $nextMethod = $iterator->get('next');
            if (!$nextMethod instanceof JsFunction) {
                throw new TypeError('object is not iterable');
            }
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
                try {
                    $key = $callbackfn->call(JsUndefined::instance(), [$value, new JsNumber((float) $k)]);
                } catch (\Throwable $e) {
                    // Close iterator on abrupt callback.
                    $returnMethod = $iterator->get('return');
                    if ($returnMethod instanceof JsFunction) {
                        try {
                            $returnMethod->call($iterator, []);
                        } catch (\Throwable) {
                            // Ignore
                        }
                    }
                    throw $e;
                }
                $keyStr = TypeConversion::toPropertyKey($key);
                if ($keyStr instanceof JsSymbol) {
                    $existing = $obj->getBySymbol($keyStr);
                    if ($existing instanceof JsArray) {
                        $existing->push($value);
                    } else {
                        $obj->setBySymbol($keyStr, JsArray::fromArray([$value]));
                    }
                } else {
                    $propName = $keyStr->toJsString();
                    if ($obj->hasOwnProperty($propName)) {
                        $existing = $obj->get($propName);
                        if ($existing instanceof JsArray) {
                            $existing->push($value);
                        }
                    } else {
                        $obj->defineOwnProperty($propName, PropertyDescriptor::data(JsArray::fromArray([$value])));
                    }
                }
                $k++;
            }
            return $obj;
        };
        $constructor->defineOwnProperty(
            'groupBy',
            $builtinMethod('groupBy', $groupByCb, 2),
        );

        $env->defineVar('Object', $constructor);

        // Store the prototype for auto-boxing and object literal creation.
        $env->defineInternal('__ObjectPrototype__', $proto);

        // Set as global default prototype so all new JsObject() inherit valueOf/toString
        JsObject::setGlobalPrototype($proto);
    }

    private static function createPrototype(): JsObject
    {
        $proto = new JsObject();
        $proto->setPrototype(null);
        $proto->setImmutablePrototype();

        $proto->defineOwnProperty('hasOwnProperty', PropertyDescriptor::data(JsFunction::fromCallable(
            'hasOwnProperty',
            function (JsValue $this_, array $args): JsValue {
                // Per spec: 1. Let P = ToPropertyKey(V). 2. Let O = ToObject(this).
                $key = $args[0] ?? JsUndefined::instance();
                $propKey = TypeConversion::toPropertyKey($key);
                $obj = TypeConversion::toObject($this_);
                if ($propKey instanceof \PhpJs\Value\JsSymbol) {
                    return new JsBoolean(
                        $obj->getSymbolPropertyDescriptor($propKey) !== null,
                    );
                }
                return new JsBoolean($obj->hasOwnProperty($propKey->toJsString()));
            },
            1,
        ), true, false, true));

        $proto->defineOwnProperty('toString', PropertyDescriptor::data(JsFunction::fromCallable(
            'toString',
            function (JsValue $this_): JsValue {
                // Step 1-2: undefined and null handled before ToObject.
                if ($this_ instanceof JsUndefined) {
                    return new JsString('[object Undefined]');
                }
                if ($this_ instanceof JsNull) {
                    return new JsString('[object Null]');
                }

                // For primitives with spec-defined internal data slots, record the
                // builtinTag before ToObject wraps them. Per spec, only Boolean, Number,
                // and String have dedicated builtinTags. Symbol and BigInt rely on
                // their prototype's @@toStringTag for their tag.
                $primitiveTag = null;
                if ($this_ instanceof JsBoolean) {
                    $primitiveTag = 'Boolean';
                } elseif ($this_ instanceof JsNumber) {
                    $primitiveTag = 'Number';
                } elseif ($this_ instanceof JsString) {
                    $primitiveTag = 'String';
                }

                // Step 3: Let O = ToObject(this value).
                $o = ($this_ instanceof JsObject) ? $this_ : TypeConversion::toObject($this_);

                // Step 4-14: Determine builtinTag based on internal slots.
                if ($primitiveTag !== null) {
                    $builtinTag = $primitiveTag;
                } elseif (self::isArrayForToString($o)) {
                    $builtinTag = 'Array';
                } elseif ($o->hasOwnProperty('[[IsArguments]]')) {
                    $builtinTag = 'Arguments';
                } elseif (self::isCallableForToString($o)) {
                    $builtinTag = 'Function';
                } elseif ($o->hasOwnProperty('[[ErrorData]]')) {
                    $builtinTag = 'Error';
                } elseif ($o->has('[[PrimitiveValue]]')) {
                    $prim = $o->get('[[PrimitiveValue]]');
                    if ($prim instanceof JsBoolean) {
                        $builtinTag = 'Boolean';
                    } elseif ($prim instanceof JsNumber) {
                        $builtinTag = 'Number';
                    } elseif ($prim instanceof JsString) {
                        $builtinTag = 'String';
                    } else {
                        $builtinTag = 'Object';
                    }
                } elseif ($o->has('[[IsDate]]')) {
                    $builtinTag = 'Date';
                } elseif ($o->hasOwnProperty('[[PCREPattern]]')) {
                    $builtinTag = 'RegExp';
                } else {
                    $builtinTag = 'Object';
                }

                // Step 15: Let tag = Get(O, @@toStringTag).
                $tagSym = SymbolConstructor::toStringTag();
                $tagVal = $o->getBySymbol($tagSym);
                // Step 16: If tag is not a string, let tag = builtinTag.
                $tag = ($tagVal instanceof JsString) ? $tagVal->value : $builtinTag;
                // Step 17
                return new JsString("[object {$tag}]");
            },
            0,
        ), true, false, true));

        $proto->defineOwnProperty('valueOf', PropertyDescriptor::data(JsFunction::fromCallable(
            'valueOf',
            function (JsValue $this_): JsValue {
                // Per spec 20.1.3.7: return ToObject(this value).
                return TypeConversion::toObject($this_);
            },
            0,
        ), true, false, true));

        $proto->defineOwnProperty('propertyIsEnumerable', PropertyDescriptor::data(JsFunction::fromCallable(
            'propertyIsEnumerable',
            function (JsValue $this_, array $args): JsValue {
                // Per spec: 1. Let P = ToPropertyKey(V). 2. Let O = ToObject(this).
                $key = $args[0] ?? JsUndefined::instance();
                $propKey = TypeConversion::toPropertyKey($key);
                $obj = TypeConversion::toObject($this_);
                if ($propKey instanceof \PhpJs\Value\JsSymbol) {
                    $desc = $obj->getSymbolPropertyDescriptor($propKey);
                } else {
                    $desc = $obj->getOwnPropertyDescriptor($propKey->toJsString());
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
                // Step 1: If V is not an Object, return false.
                if (!$v instanceof JsObject) {
                    return new JsBoolean(false);
                }
                // Step 2: Let O = ToObject(this value). Throws for undefined/null.
                $o = TypeConversion::toObject($this_);
                // Step 3: Walk V's prototype chain, compare to O.
                $current = $v->getPrototype();
                while ($current !== null) {
                    if ($current === $o) {
                        return new JsBoolean(true);
                    }
                    $current = $current->getPrototype();
                }
                return new JsBoolean(false);
            },
            1,
        ), true, false, true));

        // toLocaleString delegates to toString per ES spec 20.1.3.5.
        // Implements Invoke(O, "toString") via GetV(O, "toString") then Call.
        $proto->defineOwnProperty('toLocaleString', PropertyDescriptor::data(JsFunction::fromCallable(
            'toLocaleString',
            function (JsValue $this_): JsValue {
                if (!$this_ instanceof JsObject) {
                    // Per GetV(V, P): Let O = ToObject(V), return O.[[Get]](P, V).
                    // The receiver for getter evaluation must be the original primitive.
                    $obj = TypeConversion::toObject($this_);
                    $toStringFn = $obj->getWithValueReceiver('toString', $this_);
                } else {
                    $toStringFn = $this_->get('toString');
                }
                if (!$toStringFn instanceof JsFunction) {
                    throw new TypeError('toLocaleString: toString is not a function');
                }
                return $toStringFn->call($this_, []);
            },
            0,
        ), true, false, true));

        // Annex B: __defineGetter__, __defineSetter__, __lookupGetter__, __lookupSetter__
        $proto->defineOwnProperty('__defineGetter__', PropertyDescriptor::data(JsFunction::fromCallable(
            '__defineGetter__',
            function (JsValue $this_, array $args): JsValue {
                $obj = TypeConversion::toObject($this_);
                $getter = $args[1] ?? JsUndefined::instance();
                if (!$getter instanceof JsFunction) {
                    throw new TypeError('Getter must be a function');
                }
                $keyVal = TypeConversion::toPropertyKey($args[0] ?? JsUndefined::instance());
                // Per spec: desc = {[[Get]]: getter, [[Enumerable]]: true, [[Configurable]]: true}
                $desc = PropertyDescriptor::accessor(
                    get: $getter,
                    enumerable: true,
                    configurable: true,
                );
                $desc->hasGet = true;
                if ($keyVal instanceof JsSymbol) {
                    $success = $obj->definePropertyBySymbol($keyVal, $desc);
                    if (!$success) {
                        throw new TypeError('Cannot redefine property: ' . ($keyVal->description ?? 'Symbol()'));
                    }
                } else {
                    $keyStr = $keyVal->toJsString();
                    $success = $obj->defineOwnProperty($keyStr, $desc);
                    if (!$success) {
                        throw new TypeError("Cannot redefine property: {$keyStr}");
                    }
                }
                return JsUndefined::instance();
            },
            2,
        ), true, false, true));

        $proto->defineOwnProperty('__defineSetter__', PropertyDescriptor::data(JsFunction::fromCallable(
            '__defineSetter__',
            function (JsValue $this_, array $args): JsValue {
                $obj = TypeConversion::toObject($this_);
                $setter = $args[1] ?? JsUndefined::instance();
                if (!$setter instanceof JsFunction) {
                    throw new TypeError('Setter must be a function');
                }
                $keyVal = TypeConversion::toPropertyKey($args[0] ?? JsUndefined::instance());
                // Per spec: desc = {[[Set]]: setter, [[Enumerable]]: true, [[Configurable]]: true}
                $desc = PropertyDescriptor::accessor(
                    set: $setter,
                    enumerable: true,
                    configurable: true,
                );
                $desc->hasSet = true;
                if ($keyVal instanceof JsSymbol) {
                    $success = $obj->definePropertyBySymbol($keyVal, $desc);
                    if (!$success) {
                        throw new TypeError('Cannot redefine property: ' . ($keyVal->description ?? 'Symbol()'));
                    }
                } else {
                    $keyStr = $keyVal->toJsString();
                    $success = $obj->defineOwnProperty($keyStr, $desc);
                    if (!$success) {
                        throw new TypeError("Cannot redefine property: {$keyStr}");
                    }
                }
                return JsUndefined::instance();
            },
            2,
        ), true, false, true));

        $proto->defineOwnProperty('__lookupGetter__', PropertyDescriptor::data(JsFunction::fromCallable(
            '__lookupGetter__',
            function (JsValue $this_, array $args): JsValue {
                $obj = TypeConversion::toObject($this_);
                $keyVal = TypeConversion::toPropertyKey($args[0] ?? JsUndefined::instance());
                $current = $obj;
                while ($current !== null) {
                    if ($keyVal instanceof JsSymbol) {
                        $desc = $current->getSymbolPropertyDescriptor($keyVal);
                    } else {
                        $desc = $current->getOwnPropertyDescriptor($keyVal->toJsString());
                    }
                    if ($desc !== null) {
                        if ($desc->isAccessorDescriptor()) {
                            return $desc->get ?? JsUndefined::instance();
                        }
                        return JsUndefined::instance();
                    }
                    $current = $current->getPrototype();
                }
                return JsUndefined::instance();
            },
            1,
        ), true, false, true));

        $proto->defineOwnProperty('__lookupSetter__', PropertyDescriptor::data(JsFunction::fromCallable(
            '__lookupSetter__',
            function (JsValue $this_, array $args): JsValue {
                $obj = TypeConversion::toObject($this_);
                $keyVal = TypeConversion::toPropertyKey($args[0] ?? JsUndefined::instance());
                $current = $obj;
                while ($current !== null) {
                    if ($keyVal instanceof JsSymbol) {
                        $desc = $current->getSymbolPropertyDescriptor($keyVal);
                    } else {
                        $desc = $current->getOwnPropertyDescriptor($keyVal->toJsString());
                    }
                    if ($desc !== null) {
                        if ($desc->isAccessorDescriptor()) {
                            return $desc->set ?? JsUndefined::instance();
                        }
                        return JsUndefined::instance();
                    }
                    $current = $current->getPrototype();
                }
                return JsUndefined::instance();
            },
            1,
        ), true, false, true));

        // Annex B: Object.prototype.__proto__ accessor property.
        $protoGetter = JsFunction::fromCallable('get __proto__', function (JsValue $this_): JsValue {
            $obj = TypeConversion::toObject($this_);
            $p = $obj->getPrototype();
            return $p ?? JsNull::instance();
        }, 0);
        $protoSetter = JsFunction::fromCallable('set __proto__', function (JsValue $this_, array $args): JsValue {
            // Per spec B.2.2.1.2: RequireObjectCoercible(O), then if O is not object, return undefined.
            $o = $this_;
            if ($o instanceof JsUndefined || $o instanceof JsNull) {
                throw new TypeError('Cannot set __proto__ of ' . TypeConversion::toString($o));
            }
            $newProto = $args[0] ?? JsUndefined::instance();
            // If proto is not Object and not null, return undefined.
            if (!$newProto instanceof JsObject && !$newProto instanceof JsNull) {
                return JsUndefined::instance();
            }
            if (!$o instanceof JsObject) {
                return JsUndefined::instance();
            }
            $proto = $newProto instanceof JsNull ? null : $newProto;
            $success = $o->trySetPrototype($proto);
            if (!$success) {
                throw new TypeError('Cyclic __proto__ value');
            }
            return JsUndefined::instance();
        }, 1);
        $proto->defineOwnProperty('__proto__', PropertyDescriptor::accessor(
            get: $protoGetter,
            set: $protoSetter,
            enumerable: false,
            configurable: true,
        ));

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
                // Per spec, coerce non-object sources to objects.
                $from = TypeConversion::toObject($source);
                // Per spec, use [[OwnPropertyKeys]] (includes symbols) and check enumerability.
                $allKeys = $from->ordinaryOwnPropertyKeys();
                foreach ($allKeys as $keyVal) {
                    if ($keyVal instanceof JsSymbol) {
                        $desc = $from->getSymbolPropertyDescriptor($keyVal);
                        if ($desc === null || $desc->enumerable !== true) {
                            continue;
                        }
                        $propValue = $from->getBySymbol($keyVal);
                        $target->setBySymbol($keyVal, $propValue, true);
                    } else {
                        $key = $keyVal instanceof JsString ? $keyVal->value : (string) $keyVal;
                        $desc = $from->getOwnPropertyDescriptor($key);
                        if ($desc === null || $desc->enumerable !== true) {
                            continue;
                        }
                        $propValue = $from->get($key);
                        $target->set($key, $propValue, true);
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
            } else {
                throw new \PhpJs\Exceptions\TypeError('Object prototype may only be an Object or null');
            }

            // Second argument: property descriptors (ObjectDefineProperties per spec).
            if (isset($args[1]) && !($args[1] instanceof JsUndefined)) {
                $props = TypeConversion::toObject($args[1]);
                self::objectDefineProperties($obj, $props);
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

            $descriptor = self::toPropertyDescriptor($desc);

            if ($propKey instanceof JsSymbol) {
                $success = $obj->definePropertyBySymbol($propKey, $descriptor);
                if (!$success) {
                    $keyStr = $propKey->description ?? 'Symbol()';
                    throw new TypeError("Cannot redefine property: {$keyStr}");
                }
            } else {
                $keyStr = $propKey->toJsString();
                $success = $obj->defineOwnProperty($keyStr, $descriptor);
                if (!$success) {
                    throw new TypeError("Cannot redefine property: {$keyStr}");
                }
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
                    $key = $keyVal instanceof JsString ? $keyVal->value : (string) $keyVal;
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
                $props = TypeConversion::toObject($props);
            }

            self::objectDefineProperties($obj, $props);

            return $obj;
        };
    }

    /**
     * ObjectDefineProperties per spec 20.1.2.3.1.
     *
     * Iterates own enumerable keys of $props (string and symbol),
     * converts each value to a property descriptor, and defines it on $obj
     * via DefinePropertyOrThrow (throws TypeError on failure).
     */
    public static function objectDefineProperties(JsObject $obj, JsObject $props): void
    {
        // Per spec: use [[OwnPropertyKeys]] to get all keys, then filter by enumerable.
        $allKeys = $props->ordinaryOwnPropertyKeys();
        /** @var list<array{0: JsValue, 1: PropertyDescriptor}> $descriptors */
        $descriptors = [];

        foreach ($allKeys as $keyVal) {
            if ($keyVal instanceof JsSymbol) {
                $propDesc = $props->getSymbolPropertyDescriptor($keyVal);
            } else {
                $keyStr = $keyVal instanceof JsString ? $keyVal->value : (string) $keyVal;
                $propDesc = $props->getOwnPropertyDescriptor($keyStr);
            }
            if ($propDesc === null || $propDesc->enumerable !== true) {
                continue;
            }
            // Get the value (which is a descriptor object).
            if ($keyVal instanceof JsSymbol) {
                $descObj = $props->getBySymbol($keyVal);
            } else {
                $keyStr = $keyVal instanceof JsString ? $keyVal->value : (string) $keyVal;
                $descObj = $props->get($keyStr);
            }
            if (!$descObj instanceof JsObject) {
                throw new TypeError('Property description must be an object');
            }
            $descriptor = self::toPropertyDescriptor($descObj);
            $descriptors[] = [$keyVal, $descriptor];
        }

        // Per spec, all descriptors are collected first, then applied.
        foreach ($descriptors as [$keyVal, $descriptor]) {
            if ($keyVal instanceof JsSymbol) {
                $success = $obj->definePropertyBySymbol($keyVal, $descriptor);
                if (!$success) {
                    $keyName = $keyVal->description ?? 'Symbol()';
                    throw new TypeError("Cannot redefine property: {$keyName}");
                }
            } else {
                $keyStr = $keyVal instanceof JsString ? $keyVal->value : (string) $keyVal;
                $success = $obj->defineOwnProperty($keyStr, $descriptor);
                if (!$success) {
                    throw new TypeError("Cannot redefine property: {$keyStr}");
                }
            }
        }
    }

    /**
     * IsArray for Object.prototype.toString: checks through Proxy chains.
     */
    private static function isArrayForToString(JsObject $o): bool
    {
        if ($o instanceof JsArray) {
            return true;
        }
        if ($o instanceof JsProxy) {
            if ($o->isRevoked()) {
                return false;
            }
            $target = $o->getTarget();
            return $target !== null && self::isArrayForToString($target);
        }
        return false;
    }

    /**
     * Check if object has [[Call]] for Object.prototype.toString: checks through Proxy chains.
     */
    private static function isCallableForToString(JsObject $o): bool
    {
        if ($o instanceof JsFunction) {
            return true;
        }
        if ($o instanceof JsProxy) {
            return $o->isCallable();
        }
        return false;
    }

    /**
     * ToPropertyDescriptor(Obj) — spec §10.1.6.3.
     *
     * Converts a JS object to a PropertyDescriptor. Throws TypeError if the
     * object is not a valid descriptor (e.g. mixing data and accessor fields).
     */
    public static function toPropertyDescriptor(JsObject $obj): PropertyDescriptor
    {
        $hasValue    = $obj->has('value');
        $hasWritable = $obj->has('writable');
        $hasGet      = $obj->has('get');
        $hasSet      = $obj->has('set');

        // Mixed data + accessor descriptor is invalid.
        if (($hasValue || $hasWritable) && ($hasGet || $hasSet)) {
            throw new TypeError('Invalid property descriptor: cannot specify both accessor and data properties');
        }

        $enumerable  = $obj->has('enumerable') ? TypeConversion::toBoolean($obj->get('enumerable')) : null;
        $configurable = $obj->has('configurable') ? TypeConversion::toBoolean($obj->get('configurable')) : null;

        if ($hasGet || $hasSet) {
            $getter = null;
            $setter = null;

            if ($hasGet) {
                $g = $obj->get('get');
                if ($g instanceof JsFunction) {
                    $getter = $g;
                } elseif (!$g instanceof JsUndefined) {
                    throw new TypeError('Getter must be a function or undefined');
                }
            }

            if ($hasSet) {
                $s = $obj->get('set');
                if ($s instanceof JsFunction) {
                    $setter = $s;
                } elseif (!$s instanceof JsUndefined) {
                    throw new TypeError('Setter must be a function or undefined');
                }
            }

            $desc = PropertyDescriptor::accessor(
                get: $getter,
                set: $setter,
                enumerable: $enumerable,
                configurable: $configurable,
            );
            // Track which fields were explicitly present in the source object.
            $desc->hasGet = $hasGet;
            $desc->hasSet = $hasSet;
            return $desc;
        }

        $value   = $hasValue ? $obj->get('value') : null;
        $writable = $hasWritable ? TypeConversion::toBoolean($obj->get('writable')) : null;

        return new PropertyDescriptor(
            value: $value,
            writable: $writable,
            enumerable: $enumerable,
            configurable: $configurable,
        );
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
            // Per spec step 1: RequireObjectCoercible(O).
            if ($obj instanceof JsUndefined || $obj instanceof JsNull) {
                throw new \PhpJs\Exceptions\TypeError(
                    'Object.setPrototypeOf called on null or undefined',
                );
            }
            $proto = $args[1] ?? JsUndefined::instance();
            if (!$obj instanceof JsObject) {
                return $obj;
            }
            if (!$proto instanceof JsNull && !$proto instanceof JsObject) {
                throw new \PhpJs\Exceptions\TypeError('Object prototype may only be an Object or null');
            }

            $newProto = $proto instanceof JsNull ? null : $proto;
            if (!$obj->trySetPrototype($newProto)) {
                throw new \PhpJs\Exceptions\TypeError('Object prototype may not be changed');
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
                    $key = $keyVal instanceof JsString ? $keyVal->value : (string) $keyVal;
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
                    $key = $keyVal instanceof JsString ? $keyVal->value : (string) $keyVal;
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
                    $key = $keyVal instanceof JsString ? $keyVal->value : (string) $keyVal;
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
            if ($iterable instanceof JsUndefined || $iterable instanceof JsNull) {
                $val = TypeConversion::toString($iterable);
                throw new TypeError(
                    "Cannot read properties of {$val} (reading 'Symbol(Symbol.iterator)')"
                );
            }
            $obj = new JsObject($proto);

            // Get iterator from iterable.
            if (!$iterable instanceof JsObject) {
                $iterable = TypeConversion::toObject($iterable);
            }
            $iterSym = SymbolConstructor::iterator();
            $iteratorMethod = $iterable->getBySymbol($iterSym);
            if (!$iteratorMethod instanceof JsFunction) {
                throw new TypeError('object is not iterable');
            }
            $iterator = $iteratorMethod->call($iterable, []);
            if (!$iterator instanceof JsObject) {
                throw new TypeError('Result of the Symbol.iterator method is not an object');
            }
            $nextMethod = $iterator->get('next');
            if (!$nextMethod instanceof JsFunction) {
                throw new TypeError('iterator.next is not a function');
            }

            while (true) {
                $result = $nextMethod->call($iterator, []);
                if (!$result instanceof JsObject) {
                    throw new TypeError('Iterator value is not an object');
                }
                if (TypeConversion::toBoolean($result->get('done'))) {
                    break;
                }
                $value = $result->get('value');
                // Per spec, each entry must be an object.
                if (!$value instanceof JsObject) {
                    // Close iterator, then throw TypeError.
                    $returnMethod = $iterator->get('return');
                    if ($returnMethod instanceof JsFunction) {
                        try {
                            $returnMethod->call($iterator, []);
                        } catch (\Throwable) {
                            // Ignore
                        }
                    }
                    $val = TypeConversion::toString($value);
                    throw new TypeError("Iterator value {$val} is not an entry object");
                }
                try {
                    $entryKey = $value->get('0');
                    $entryValue = $value->get('1');
                    $propKey = TypeConversion::toPropertyKey($entryKey);
                    if ($propKey instanceof JsSymbol) {
                        $obj->defineOwnSymbolProperty($propKey, PropertyDescriptor::data($entryValue));
                    } else {
                        // Per spec, use CreateDataPropertyOrThrow.
                        $obj->defineOwnProperty($propKey->toJsString(), PropertyDescriptor::data($entryValue));
                    }
                } catch (\Throwable $e) {
                    // Close iterator on abrupt completion.
                    $returnMethod = $iterator->get('return');
                    if ($returnMethod instanceof JsFunction) {
                        try {
                            $returnMethod->call($iterator, []);
                        } catch (\Throwable) {
                            // Ignore
                        }
                    }
                    throw $e;
                }
            }

            return $obj;
        };
    }

    /**
     * FromPropertyDescriptor per spec 6.2.6.4.
     *
     * Converts a PropertyDescriptor to a JS object suitable for
     * Object.getOwnPropertyDescriptor/getOwnPropertyDescriptors.
     */
    public static function fromPropertyDescriptor(PropertyDescriptor $desc): JsObject
    {
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
    }
}
