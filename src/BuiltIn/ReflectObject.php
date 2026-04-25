<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Exceptions\TypeError;
use PhpJs\Object\PropertyDescriptor;
use PhpJs\Runtime\Environment;
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
use PhpJs\Value\JsProxy;
use PhpJs\Value\JsValue;

/**
 * The Reflect built-in object.
 *
 * Provides static methods that mirror the Proxy handler traps.
 * All methods throw TypeError when the target is not an object.
 * Reflect is not a constructor and cannot be called with new.
 */
class ReflectObject
{
    public static function install(Environment $env): void
    {
        $reflect = new JsObject();

        // Reflect.get(target, propertyKey [, receiver])
        $reflect->defineOwnProperty('get', PropertyDescriptor::data(
            JsFunction::fromCallable('get', function (JsValue $this_, array $args): JsValue {
                $target = self::requireObject($args, 'Reflect.get');
                $key = $args[1] ?? JsUndefined::instance();
                $receiver = $args[2] ?? $target;
                // Spec §28.1.6 step 2: ToPropertyKey(propertyKey). A Symbol
                // wrapper unboxes to its primitive Symbol via @@toPrimitive.
                $propKey = TypeConversion::toPropertyKey($key);
                if ($propKey instanceof JsSymbol) {
                    return $target->getBySymbol($propKey);
                }
                $name = $propKey instanceof JsString ? $propKey->value : TypeConversion::toString($propKey);
                // TypedArray exotic [[Get]] handles numeric indices directly;
                // its prototype-chain hop happens inside ::get(), so use the
                // public get() rather than the JsObject internalGet path.
                if ($target instanceof \PhpJs\Value\JsTypedArray) {
                    return $target->get($name);
                }
                // Spec [[Get]] accepts any value as receiver. For non-object
                // receivers, route through getWithValueReceiver so any
                // accessors (including proxy traps) see the original value
                // rather than $target.
                return $target->getWithValueReceiver($name, $receiver);
            }, 2),
            true,
            false,
            true,
        ));

        // Reflect.set(target, propertyKey, value [, receiver])
        $reflect->defineOwnProperty('set', PropertyDescriptor::data(
            JsFunction::fromCallable('set', function (JsValue $this_, array $args): JsValue {
                $target = self::requireObject($args, 'Reflect.set');
                $key = $args[1] ?? JsUndefined::instance();
                $value = $args[2] ?? JsUndefined::instance();
                $receiver = $args[3] ?? $target;
                $propKey = TypeConversion::toPropertyKey($key);

                if ($propKey instanceof JsSymbol) {
                    return new JsBoolean(
                        self::ordinarySetSymbol($target, $propKey, $value, $receiver)
                    );
                }
                // Typed-array exotic [[Set]]: for a CanonicalNumericIndexString
                // the spec short-circuits for invalid indices with return true
                // and without calling valueOf on the value. Delegate to the
                // typed array's [[Set]] when the receiver is the typed array
                // itself; otherwise, if the index is invalid, return true.
                if ($target instanceof \PhpJs\Value\JsTypedArray) {
                    $indexStr = $propKey->toJsString();
                    $isCanonical = $indexStr === '-0'
                        || $indexStr === 'NaN'
                        || $indexStr === 'Infinity'
                        || $indexStr === '-Infinity'
                        || (
                            is_numeric($indexStr)
                            && (new \PhpJs\Value\JsNumber((float) $indexStr))->toJsString() === $indexStr
                        );
                    if ($isCanonical) {
                        $isValidIntegerIndex = ctype_digit($indexStr)
                            && (int) $indexStr < $target->getLength()
                            && !$target->getBuffer()->isDetached();
                        if (!$isValidIntegerIndex) {
                            return new JsBoolean(true);
                        }
                        if ($receiver === $target) {
                            $target->set($indexStr, $value);
                            return new JsBoolean(true);
                        }
                        // Valid index + different receiver: fall through.
                    }
                }

                // Per spec, [[Set]] passes receiver through, even if it is not an object.
                // OrdinarySetWithOwnDescriptor returns false when Type(Receiver) is not Object.
                if (!$receiver instanceof JsObject) {
                    // Walk to find own descriptor, then apply spec step 5b.
                    $ownDesc = $target->getOwnPropertyDescriptor($propKey->toJsString());
                    if ($ownDesc === null) {
                        $parent = $target->getPrototype();
                        if ($parent !== null) {
                            // Inherit from parent, but receiver is still non-object.
                            return new JsBoolean(false);
                        }
                        // No own, no parent: would create on receiver, but receiver is not object.
                        return new JsBoolean(false);
                    }
                    if ($ownDesc->isDataDescriptor()) {
                        // Step 5b: If Type(Receiver) is not Object, return false.
                        return new JsBoolean(false);
                    }
                    // Accessor: call setter if present.
                    if ($ownDesc->set !== null) {
                        $ownDesc->set->call($target, [$value]);
                        return new JsBoolean(true);
                    }
                    return new JsBoolean(false);
                }
                $success = $target->internalSet($propKey->toJsString(), $value, $receiver);
                return new JsBoolean($success);
            }, 3),
            true,
            false,
            true,
        ));

        // Reflect.has(target, propertyKey)
        $reflect->defineOwnProperty('has', PropertyDescriptor::data(
            JsFunction::fromCallable('has', function (JsValue $this_, array $args): JsValue {
                $target = self::requireObject($args, 'Reflect.has');
                $key = $args[1] ?? JsUndefined::instance();
                $propKey = TypeConversion::toPropertyKey($key);
                if ($propKey instanceof JsSymbol) {
                    return new JsBoolean($target->hasBySymbol($propKey));
                }
                $name = $propKey instanceof JsString ? $propKey->value : TypeConversion::toString($propKey);
                return new JsBoolean($target->has($name));
            }, 2),
            true,
            false,
            true,
        ));

        // Reflect.deleteProperty(target, propertyKey)
        $reflect->defineOwnProperty('deleteProperty', PropertyDescriptor::data(
            JsFunction::fromCallable('deleteProperty', function (JsValue $this_, array $args): JsValue {
                $target = self::requireObject($args, 'Reflect.deleteProperty');
                $key = $args[1] ?? JsUndefined::instance();
                $propKey = TypeConversion::toPropertyKey($key);
                if ($propKey instanceof JsSymbol) {
                    return new JsBoolean($target->deleteBySymbol($propKey));
                }
                return new JsBoolean($target->delete($propKey->toJsString()));
            }, 2),
            true,
            false,
            true,
        ));

        // Reflect.ownKeys(target)
        $reflect->defineOwnProperty('ownKeys', PropertyDescriptor::data(
            JsFunction::fromCallable('ownKeys', function (JsValue $this_, array $args): JsValue {
                $target = self::requireObject($args, 'Reflect.ownKeys');
                // Use ordinaryOwnPropertyKeys which returns keys in the
                // correct order: integer indices (ascending), then string
                // keys (insertion order), then symbol keys (insertion order).
                // For Proxy objects, this calls the proxy's overridden method
                // which invokes the ownKeys trap if present.
                $keys = $target->ordinaryOwnPropertyKeys();
                return JsArray::fromArray($keys);
            }, 1),
            true,
            false,
            true,
        ));

        // Reflect.getPrototypeOf(target)
        $reflect->defineOwnProperty('getPrototypeOf', PropertyDescriptor::data(
            JsFunction::fromCallable('getPrototypeOf', function (JsValue $this_, array $args): JsValue {
                $target = self::requireObject($args, 'Reflect.getPrototypeOf');
                $proto = $target->getPrototype();
                return $proto ?? JsNull::instance();
            }, 1),
            true,
            false,
            true,
        ));

        // Reflect.setPrototypeOf(target, proto)
        $reflect->defineOwnProperty('setPrototypeOf', PropertyDescriptor::data(
            JsFunction::fromCallable('setPrototypeOf', function (JsValue $this_, array $args): JsValue {
                $target = self::requireObject($args, 'Reflect.setPrototypeOf');
                $proto = $args[1] ?? JsUndefined::instance();
                if (!$proto instanceof JsNull && !$proto instanceof JsObject) {
                    throw new TypeError('Object prototype may only be an Object or null');
                }
                $newProto = $proto instanceof JsNull ? null : $proto;
                return new JsBoolean($target->trySetPrototype($newProto));
            }, 2),
            true,
            false,
            true,
        ));

        // Reflect.isExtensible(target)
        $reflect->defineOwnProperty('isExtensible', PropertyDescriptor::data(
            JsFunction::fromCallable('isExtensible', function (JsValue $this_, array $args): JsValue {
                $target = self::requireObject($args, 'Reflect.isExtensible');
                return new JsBoolean($target->isExtensible());
            }, 1),
            true,
            false,
            true,
        ));

        // Reflect.preventExtensions(target)
        $reflect->defineOwnProperty('preventExtensions', PropertyDescriptor::data(
            JsFunction::fromCallable('preventExtensions', function (JsValue $this_, array $args): JsValue {
                $target = self::requireObject($args, 'Reflect.preventExtensions');
                if ($target instanceof JsProxy) {
                    return new JsBoolean($target->internalPreventExtensions());
                }
                return new JsBoolean($target->preventExtensions());
            }, 1),
            true,
            false,
            true,
        ));

        // Reflect.defineProperty(target, propertyKey, attributes)
        $reflect->defineOwnProperty('defineProperty', PropertyDescriptor::data(
            JsFunction::fromCallable('defineProperty', function (JsValue $this_, array $args): JsValue {
                $target = self::requireObject($args, 'Reflect.defineProperty');
                $keyRaw = $args[1] ?? JsUndefined::instance();
                // Step 2: ToPropertyKey. Let abrupt completions propagate.
                $propKey = TypeConversion::toPropertyKey($keyRaw);

                $desc = $args[2] ?? JsUndefined::instance();
                if (!$desc instanceof JsObject) {
                    throw new TypeError('Property description must be an object');
                }

                $descriptor = self::toPropertyDescriptor($desc);

                // For Proxy targets, defineOwnProperty returns bool:
                // true if trap succeeded, false if trap returned falsish.
                // Invariant violations throw TypeError which must propagate.
                if ($target instanceof JsProxy) {
                    if ($propKey instanceof JsSymbol) {
                        return new JsBoolean(
                            $target->definePropertyBySymbol($propKey, $descriptor)
                        );
                    }
                    return new JsBoolean(
                        $target->defineOwnProperty($propKey->toJsString(), $descriptor)
                    );
                }

                // Call the target's own [[DefineOwnProperty]] internal method.
                // This is important: exotic objects (e.g., JsArray) override
                // defineOwnProperty for special handling (e.g., ArraySetLength).
                if ($propKey instanceof JsSymbol) {
                    return new JsBoolean(
                        self::ordinaryDefineOwnPropertySymbol($target, $propKey, $descriptor)
                    );
                }
                return new JsBoolean(
                    $target->defineOwnProperty($propKey->toJsString(), $descriptor)
                );
            }, 3),
            true,
            false,
            true,
        ));

        // Reflect.getOwnPropertyDescriptor(target, propertyKey)
        $reflect->defineOwnProperty('getOwnPropertyDescriptor', PropertyDescriptor::data(
            JsFunction::fromCallable('getOwnPropertyDescriptor', function (JsValue $this_, array $args): JsValue {
                $target = self::requireObject($args, 'Reflect.getOwnPropertyDescriptor');
                $keyRaw = $args[1] ?? JsUndefined::instance();
                $propKey = TypeConversion::toPropertyKey($keyRaw);

                if ($propKey instanceof JsSymbol) {
                    $desc = $target->getSymbolPropertyDescriptor($propKey);
                } else {
                    $desc = $target->getOwnPropertyDescriptor($propKey->toJsString());
                }

                if ($desc === null) {
                    return JsUndefined::instance();
                }

                return self::fromPropertyDescriptor($desc);
            }, 2),
            true,
            false,
            true,
        ));

        // Reflect.apply(target, thisArgument, argumentsList)
        $reflect->defineOwnProperty('apply', PropertyDescriptor::data(
            JsFunction::fromCallable('apply', function (JsValue $this_, array $args): JsValue {
                $target = $args[0] ?? JsUndefined::instance();
                $isCallable = $target instanceof JsFunction
                    || ($target instanceof \PhpJs\Value\JsProxy && $target->isCallable());
                if (!$isCallable) {
                    throw new TypeError('Reflect.apply: target must be a function');
                }
                $thisArg = $args[1] ?? JsUndefined::instance();
                $argsList = $args[2] ?? JsUndefined::instance();
                $callArgs = self::toArgumentsList($argsList, 'Reflect.apply');
                if ($target instanceof \PhpJs\Value\JsProxy) {
                    return $target->apply($thisArg, $callArgs);
                }
                return $target->call($thisArg, $callArgs);
            }, 3),
            true,
            false,
            true,
        ));

        // Reflect.construct(target, argumentsList [, newTarget])
        $reflect->defineOwnProperty('construct', PropertyDescriptor::data(
            JsFunction::fromCallable('construct', function (JsValue $this_, array $args): JsValue {
                $target = $args[0] ?? JsUndefined::instance();
                // Step 1: IsConstructor(target) -- JsFunction that is constructable, or Proxy wrapping one.
                $isConstructor = ($target instanceof JsFunction && $target->isConstructable())
                    || ($target instanceof JsProxy && $target->isConstructable());
                if (!$isConstructor) {
                    throw new TypeError('Reflect.construct: target must be a constructor');
                }

                $argsList = $args[1] ?? JsUndefined::instance();
                // Step 4: CreateListFromArrayLike(argumentsList).
                $callArgs = self::toArgumentsList($argsList, 'Reflect.construct');

                // Step 2/3: newTarget defaults to target; must be a constructor.
                $newTarget = $args[2] ?? $target;
                $ntIsConstructor = ($newTarget instanceof JsFunction && $newTarget->isConstructable())
                    || ($newTarget instanceof JsProxy && $newTarget->isConstructable());
                if (!$ntIsConstructor) {
                    throw new TypeError('Reflect.construct: newTarget must be a constructor');
                }

                // For Proxy targets, delegate to the proxy's construct method.
                if ($target instanceof JsProxy) {
                    return $target->construct($callArgs, $newTarget);
                }

                // Per spec 9.1.13 OrdinaryCreateFromConstructor: for user-
                // defined constructors we eagerly access newTarget.prototype
                // so Object.getPrototypeOf(this) inside the body observes
                // the correct prototype. Native built-in constructors
                // perform that access themselves at the spec-mandated time
                // (often after argument validation, as in ArrayBuffer), so
                // we pre-allocate with the target's own prototype and let
                // the native body fix up the prototype and propagate any
                // getter error itself.
                $targetIsNative = $target instanceof JsFunction && $target->isNative();
                if ($targetIsNative) {
                    $targetProto = $target->get('prototype');
                    $useProto = $targetProto instanceof JsObject ? $targetProto : null;
                } else {
                    $ntProto = $newTarget->get('prototype');
                    $useProto = $ntProto instanceof JsObject ? $ntProto : null;
                    if ($useProto === null) {
                        $targetProto = $target->get('prototype');
                        $useProto = $targetProto instanceof JsObject ? $targetProto : null;
                    }
                }
                $newObj = new JsObject($useProto);
                $ntValue = ($newTarget instanceof JsFunction || $newTarget instanceof JsProxy)
                    ? $newTarget
                    : $target;
                $newObj->defineOwnProperty(
                    '[[NewTarget]]',
                    PropertyDescriptor::data($ntValue, false, false, false),
                );
                $result = $target->call($newObj, $callArgs);
                if ($result instanceof JsObject && $result !== $newObj) {
                    return $result;
                }
                return $result instanceof JsObject ? $result : $newObj;
            }, 2),
            true,
            false,
            true,
        ));

        // Symbol.toStringTag = "Reflect"
        $toStringTagSym = SymbolConstructor::toStringTag();
        $reflect->definePropertyBySymbol(
            $toStringTagSym,
            PropertyDescriptor::data(new JsString('Reflect'), false, false, true),
        );

        $env->defineVar('Reflect', $reflect);
    }

    /**
     * OrdinaryDefineOwnProperty per spec 9.1.6.1, returning bool.
     *
     * Returns true if the property was successfully defined, false if rejected
     * by validation (non-extensible, non-configurable conflicts, etc.).
     */
    private static function ordinaryDefineOwnProperty(
        JsObject $target,
        string $name,
        PropertyDescriptor $desc,
    ): bool {
        $current = $target->getOwnPropertyDescriptor($name);

        if ($current === null) {
            // Property does not exist. Only create if extensible.
            if (!$target->isExtensible()) {
                return false;
            }
            // Per spec, unspecified attributes default to false/undefined for new properties.
            $target->defineOwnProperty($name, self::completeDescriptor($desc));
            return true;
        }

        // Validate changes against current descriptor.
        if (!self::isCompatiblePropertyDescriptor($target->isExtensible(), $desc, $current)) {
            return false;
        }

        $target->defineOwnProperty($name, self::mergeDescriptor($current, $desc));
        return true;
    }

    /**
     * OrdinaryDefineOwnProperty for symbol keys, returning bool.
     */
    private static function ordinaryDefineOwnPropertySymbol(
        JsObject $target,
        JsSymbol $key,
        PropertyDescriptor $desc,
    ): bool {
        $current = $target->getSymbolPropertyDescriptor($key);

        if ($current === null) {
            if (!$target->isExtensible()) {
                return false;
            }
            $target->definePropertyBySymbol($key, self::completeDescriptor($desc));
            return true;
        }

        if (!self::isCompatiblePropertyDescriptor($target->isExtensible(), $desc, $current)) {
            return false;
        }

        $target->definePropertyBySymbol($key, self::mergeDescriptor($current, $desc));
        return true;
    }

    /**
     * Complete a new property descriptor by filling in default values.
     *
     * Per spec, when creating a new own property, unspecified attributes
     * default to false (writable, enumerable, configurable) and undefined (value).
     */
    private static function completeDescriptor(PropertyDescriptor $desc): PropertyDescriptor
    {
        if ($desc->isAccessorDescriptor()) {
            return PropertyDescriptor::accessor(
                get: $desc->get,
                set: $desc->set,
                enumerable: $desc->enumerable ?? false,
                configurable: $desc->configurable ?? false,
            );
        }

        return new PropertyDescriptor(
            value: $desc->value ?? JsUndefined::instance(),
            writable: $desc->writable ?? false,
            enumerable: $desc->enumerable ?? false,
            configurable: $desc->configurable ?? false,
        );
    }

    /**
     * ES spec: IsCompatiblePropertyDescriptor / ValidateAndApplyPropertyDescriptor.
     *
     * Returns true if Desc can be applied to Current.
     */
    private static function isCompatiblePropertyDescriptor(
        bool $extensible,
        PropertyDescriptor $desc,
        PropertyDescriptor $current,
    ): bool {
        // Step 2: If current is not configurable...
        if ($current->configurable === false) {
            // Cannot make it configurable.
            if ($desc->configurable === true) {
                return false;
            }
            // Cannot change enumerable if not configurable.
            if ($desc->enumerable !== null && $desc->enumerable !== ($current->enumerable ?? false)) {
                return false;
            }
        }

        // Step 4: If IsGenericDescriptor(Desc), no further validation needed.
        if (!$desc->isDataDescriptor() && !$desc->isAccessorDescriptor()) {
            return true;
        }

        // Step 5: Switching between data and accessor when not configurable.
        $currentIsData = $current->isDataDescriptor();
        $descIsData = $desc->isDataDescriptor();
        if ($currentIsData !== $descIsData) {
            if ($current->configurable === false) {
                return false;
            }
            return true;
        }

        // Step 6: Both data descriptors.
        if ($currentIsData && $descIsData) {
            if ($current->configurable === false && $current->writable === false) {
                if ($desc->writable === true) {
                    return false;
                }
                $curVal = $current->value ?? JsUndefined::instance();
                if ($desc->value !== null && !self::sameValue($desc->value, $curVal)) {
                    return false;
                }
            }
            return true;
        }

        // Step 7: Both accessor descriptors.
        if ($current->configurable === false) {
            if ($desc->set !== null && $desc->set !== $current->set) {
                return false;
            }
            if ($desc->get !== null && $desc->get !== $current->get) {
                return false;
            }
        }
        return true;
    }

    /**
     * Merge new descriptor fields into current descriptor.
     */
    private static function mergeDescriptor(
        PropertyDescriptor $current,
        PropertyDescriptor $desc,
    ): PropertyDescriptor {
        // If switching from accessor to data or vice versa, use the new descriptor type.
        if ($desc->isAccessorDescriptor() && !$current->isAccessorDescriptor()) {
            return new PropertyDescriptor(
                get: $desc->get,
                set: $desc->set,
                enumerable: $desc->enumerable ?? $current->enumerable,
                configurable: $desc->configurable ?? $current->configurable,
            );
        }
        if ($desc->isDataDescriptor() && !$current->isDataDescriptor()) {
            return new PropertyDescriptor(
                value: $desc->value ?? JsUndefined::instance(),
                writable: $desc->writable ?? false,
                enumerable: $desc->enumerable ?? $current->enumerable,
                configurable: $desc->configurable ?? $current->configurable,
            );
        }

        // Merge field by field, keeping current for unspecified fields.
        if ($current->isAccessorDescriptor()) {
            return new PropertyDescriptor(
                get: $desc->get ?? $current->get,
                set: $desc->set ?? $current->set,
                enumerable: $desc->enumerable ?? $current->enumerable,
                configurable: $desc->configurable ?? $current->configurable,
            );
        }

        return new PropertyDescriptor(
            value: $desc->value ?? $current->value,
            writable: $desc->writable ?? $current->writable,
            enumerable: $desc->enumerable ?? $current->enumerable,
            configurable: $desc->configurable ?? $current->configurable,
        );
    }

    /**
     * SameValue comparison (used for property descriptor validation).
     */
    private static function sameValue(JsValue $a, JsValue $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if ($a instanceof JsNumber && $b instanceof JsNumber) {
            if (is_nan($a->value) && is_nan($b->value)) {
                return true;
            }
            return $a->value === $b->value;
        }
        if ($a instanceof JsString && $b instanceof JsString) {
            return $a->value === $b->value;
        }
        if ($a instanceof JsBoolean && $b instanceof JsBoolean) {
            return $a->value === $b->value;
        }
        return false;
    }

    /**
     * OrdinarySet for symbol-keyed properties with receiver support.
     *
     * Mirrors the OrdinarySetWithOwnDescriptor algorithm for symbol keys.
     */
    private static function ordinarySetSymbol(
        JsObject $target,
        JsSymbol $key,
        JsValue $value,
        JsValue $receiver,
    ): bool {
        $ownDesc = $target->getSymbolPropertyDescriptor($key);

        if ($ownDesc === null) {
            // Walk prototype chain.
            $parent = $target->getPrototype();
            if ($parent !== null) {
                return self::ordinarySetSymbol($parent, $key, $value, $receiver);
            }
            // No own, no parent: create writable data descriptor on receiver.
            $ownDesc = PropertyDescriptor::data(JsUndefined::instance());
        }

        if ($ownDesc->isDataDescriptor()) {
            if ($ownDesc->writable === false) {
                return false;
            }
            if (!$receiver instanceof JsObject) {
                return false;
            }
            $existingDesc = $receiver->getSymbolPropertyDescriptor($key);
            if ($existingDesc !== null) {
                if ($existingDesc->isAccessorDescriptor()) {
                    return false;
                }
                if ($existingDesc->writable === false) {
                    return false;
                }
                $receiver->definePropertyBySymbol($key, new PropertyDescriptor(
                    value: $value,
                    writable: $existingDesc->writable,
                    enumerable: $existingDesc->enumerable,
                    configurable: $existingDesc->configurable,
                ));
                return true;
            }
            if (!$receiver->isExtensible()) {
                return false;
            }
            $receiver->definePropertyBySymbol($key, PropertyDescriptor::data($value));
            return true;
        }

        // Accessor descriptor.
        if ($ownDesc->set !== null) {
            $ownDesc->set->call($receiver instanceof JsObject ? $receiver : $target, [$value]);
            return true;
        }
        return false;
    }

    /** Validate that the first argument is an object. */
    private static function requireObject(array $args, string $method): JsObject
    {
        $target = $args[0] ?? JsUndefined::instance();
        if (!$target instanceof JsObject) {
            throw new TypeError("{$method}: target must be an object");
        }
        return $target;
    }

    /**
     * CreateListFromArrayLike: convert a JsValue argumentsList to a PHP array.
     *
     * Per spec (7.3.17), throws TypeError if obj is not an Object.
     *
     * @return list<JsValue>
     */
    private static function toArgumentsList(JsValue $argsList, string $caller = ''): array
    {
        // CreateListFromArrayLike step 3: If Type(obj) is not Object, throw TypeError.
        if (!$argsList instanceof JsObject) {
            $msg = $caller !== ''
                ? "{$caller}: argumentsList must be an object"
                : 'CreateListFromArrayLike called on non-object';
            throw new TypeError($msg);
        }
        if ($argsList instanceof JsArray) {
            $result = [];
            $len = $argsList->getLength();
            for ($i = 0; $i < $len; $i++) {
                $result[] = $argsList->get((string) $i);
            }
            return $result;
        }
        $result = [];
        $lenVal = $argsList->get('length');
        $len = (int) TypeConversion::toNumber($lenVal);
        for ($i = 0; $i < $len; $i++) {
            $result[] = $argsList->get((string) $i);
        }
        return $result;
    }

    /** Convert a JsObject descriptor to a PropertyDescriptor. */
    private static function toPropertyDescriptor(JsObject $obj): PropertyDescriptor
    {
        $value = $obj->has('value') ? $obj->get('value') : null;
        $writable = $obj->has('writable')
            ? TypeConversion::toBoolean($obj->get('writable'))
            : null;
        $enumerable = $obj->has('enumerable')
            ? TypeConversion::toBoolean($obj->get('enumerable'))
            : null;
        $configurable = $obj->has('configurable')
            ? TypeConversion::toBoolean($obj->get('configurable'))
            : null;

        $getter = null;
        $setter = null;
        $hasGetOrSet = false;
        if ($obj->has('get')) {
            $hasGetOrSet = true;
            $g = $obj->get('get');
            if ($g instanceof JsFunction) {
                $getter = $g;
            }
        }
        if ($obj->has('set')) {
            $hasGetOrSet = true;
            $s = $obj->get('set');
            if ($s instanceof JsFunction) {
                $setter = $s;
            }
        }

        if ($hasGetOrSet) {
            return PropertyDescriptor::accessor(
                get: $getter,
                set: $setter,
                enumerable: $enumerable ?? false,
                configurable: $configurable ?? false,
            );
        }

        return new PropertyDescriptor(
            value: $value,
            writable: $writable,
            enumerable: $enumerable,
            configurable: $configurable,
        );
    }

    /** Convert a PropertyDescriptor to a JsObject. */
    private static function fromPropertyDescriptor(PropertyDescriptor $desc): JsObject
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
