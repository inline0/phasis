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
                if ($key instanceof JsSymbol) {
                    return $target->getBySymbol($key);
                }
                $propKey = TypeConversion::toString($key);
                // Use receiver-aware get when a receiver is provided.
                if ($receiver instanceof JsObject) {
                    return $target->internalGet($propKey, $receiver);
                }
                return $target->get($propKey);
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
                if ($key instanceof JsSymbol) {
                    return new JsBoolean($target->hasBySymbol($key));
                }
                return new JsBoolean($target->has(TypeConversion::toString($key)));
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

                // Implement [[SetPrototypeOf]] (9.1.2) spec algorithm.
                $current = $target->getPrototype();

                // Step 4: If SameValue(V, current), return true.
                if ($newProto === $current) {
                    return new JsBoolean(true);
                }

                // Step 5: If extensible is false, return false.
                if (!$target->isExtensible()) {
                    return new JsBoolean(false);
                }

                // Step 8: Cycle detection. Walk proto's chain looking for target.
                $p = $newProto;
                while ($p !== null) {
                    if ($p === $target) {
                        return new JsBoolean(false);
                    }
                    $p = $p->getPrototype();
                }

                $target->setPrototype($newProto);
                return new JsBoolean(true);
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
                // For Proxy objects, call internalPreventExtensions which returns bool.
                // For regular objects, call preventExtensions (always succeeds).
                if ($target instanceof JsProxy) {
                    return new JsBoolean($target->internalPreventExtensions());
                }
                $target->preventExtensions();
                return new JsBoolean(true);
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

                // For Proxy targets, the trap may throw (abrupt completion propagates)
                // or return false (Reflect returns false). Only TypeError from
                // defineOwnProperty internal rejection should yield false.
                if ($target instanceof JsProxy) {
                    // Let proxy throw through on trap errors; catch only
                    // the TypeError it throws when trap returns falsish.
                    try {
                        if ($propKey instanceof JsSymbol) {
                            $target->definePropertyBySymbol($propKey, $descriptor);
                        } else {
                            $target->defineOwnProperty($propKey->toJsString(), $descriptor);
                        }
                        return new JsBoolean(true);
                    } catch (TypeError $e) {
                        // Proxy trap returned falsish.
                        return new JsBoolean(false);
                    }
                }

                // Regular object: use spec-compliant OrdinaryDefineOwnProperty with bool return.
                if ($propKey instanceof JsSymbol) {
                    return new JsBoolean(
                        self::ordinaryDefineOwnPropertySymbol($target, $propKey, $descriptor)
                    );
                }
                return new JsBoolean(
                    self::ordinaryDefineOwnProperty($target, $propKey->toJsString(), $descriptor)
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
                if (!$target instanceof JsFunction) {
                    throw new TypeError('Reflect.apply: target must be a function');
                }
                $thisArg = $args[1] ?? JsUndefined::instance();
                $argsList = $args[2] ?? JsUndefined::instance();
                $callArgs = self::toArgumentsList($argsList, 'Reflect.apply');
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
                // Step 1: If IsConstructor(target) is false, throw a TypeError.
                if (!$target instanceof JsFunction || !$target->isConstructable()) {
                    throw new TypeError('Reflect.construct: target must be a constructor');
                }

                $argsList = $args[1] ?? JsUndefined::instance();
                // Step 4: CreateListFromArrayLike(argumentsList).
                $callArgs = self::toArgumentsList($argsList, 'Reflect.construct');

                // Step 2/3: newTarget defaults to target; must be a constructor.
                $newTarget = $args[2] ?? $target;
                if (!$newTarget instanceof JsFunction || !$newTarget->isConstructable()) {
                    throw new TypeError('Reflect.construct: newTarget must be a constructor');
                }

                // Use newTarget's prototype (not target's) per spec.
                $proto = $newTarget->get('prototype');
                $newObj = new JsObject($proto instanceof JsObject ? $proto : null);
                $result = $target->call($newObj, $callArgs);
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
            $target->defineOwnProperty($name, $desc);
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
            $target->definePropertyBySymbol($key, $desc);
            return true;
        }

        if (!self::isCompatiblePropertyDescriptor($target->isExtensible(), $desc, $current)) {
            return false;
        }

        $target->definePropertyBySymbol($key, self::mergeDescriptor($current, $desc));
        return true;
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
                if ($desc->value !== null && !self::sameValue($desc->value, $current->value ?? JsUndefined::instance())) {
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
