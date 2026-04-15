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
                if ($key instanceof JsSymbol) {
                    return $target->getBySymbol($key);
                }
                return $target->get(TypeConversion::toString($key));
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
                if ($key instanceof JsSymbol) {
                    $target->setBySymbol($key, $value);
                } else {
                    $target->set(TypeConversion::toString($key), $value);
                }
                return new JsBoolean(true);
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
                return new JsBoolean($target->delete(TypeConversion::toString($key)));
            }, 2),
            true,
            false,
            true,
        ));

        // Reflect.ownKeys(target)
        $reflect->defineOwnProperty('ownKeys', PropertyDescriptor::data(
            JsFunction::fromCallable('ownKeys', function (JsValue $this_, array $args): JsValue {
                $target = self::requireObject($args, 'Reflect.ownKeys');
                $names = $target->getOwnPropertyNames();
                $jsNames = array_map(fn(string $n) => new JsString($n), $names);
                return JsArray::fromArray($jsNames);
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
                if ($proto instanceof JsNull) {
                    $target->setPrototype(null);
                } elseif ($proto instanceof JsObject) {
                    $target->setPrototype($proto);
                } else {
                    throw new TypeError('Object prototype may only be an Object or null');
                }
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
                $desc = $args[2] ?? JsUndefined::instance();
                if (!$desc instanceof JsObject) {
                    throw new TypeError('Property description must be an object');
                }

                $propKey = TypeConversion::toPropertyKey($keyRaw);
                $descriptor = self::toPropertyDescriptor($desc);

                try {
                    if ($propKey instanceof JsSymbol) {
                        $target->definePropertyBySymbol($propKey, $descriptor);
                    } else {
                        $target->defineOwnProperty($propKey->toJsString(), $descriptor);
                    }
                    return new JsBoolean(true);
                } catch (\Throwable) {
                    return new JsBoolean(false);
                }
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
                $callArgs = self::toArgumentsList($argsList);
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
                if (!$target instanceof JsFunction) {
                    throw new TypeError('Reflect.construct: target must be a constructor');
                }
                $argsList = $args[1] ?? JsUndefined::instance();
                $callArgs = self::toArgumentsList($argsList);

                $proto = $target->get('prototype');
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
     * Convert a JsValue argumentsList to a PHP array of JsValues.
     *
     * @return list<JsValue>
     */
    private static function toArgumentsList(JsValue $argsList): array
    {
        if ($argsList instanceof JsArray) {
            $result = [];
            $len = $argsList->getLength();
            for ($i = 0; $i < $len; $i++) {
                $result[] = $argsList->get((string) $i);
            }
            return $result;
        }
        if ($argsList instanceof JsObject) {
            $result = [];
            $lenVal = $argsList->get('length');
            $len = (int) TypeConversion::toNumber($lenVal);
            for ($i = 0; $i < $len; $i++) {
                $result[] = $argsList->get((string) $i);
            }
            return $result;
        }
        return [];
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
        if ($obj->has('get')) {
            $g = $obj->get('get');
            if ($g instanceof JsFunction) {
                $getter = $g;
            }
        }
        if ($obj->has('set')) {
            $s = $obj->get('set');
            if ($s instanceof JsFunction) {
                $setter = $s;
            }
        }

        if ($getter !== null || $setter !== null) {
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
