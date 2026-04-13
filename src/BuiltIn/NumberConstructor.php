<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Object\PropertyDescriptor;
use PhpJs\Runtime\Environment;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

class NumberConstructor
{
    public static function install(Environment $env): void
    {
        $proto = self::createPrototype();

        // The Number constructor is already installed by GlobalObject as a simple conversion
        // function. We retrieve it and add static properties and the prototype.
        $existing = $env->get('Number');
        if (!$existing instanceof JsFunction) {
            return;
        }

        // Static constants (non-enumerable in spec, but we use data descriptors for simplicity).
        $existing->defineOwnProperty('MAX_VALUE', PropertyDescriptor::data(
            new JsNumber(PHP_FLOAT_MAX),
            writable: false,
            enumerable: false,
            configurable: false,
        ));
        $existing->defineOwnProperty('MIN_VALUE', PropertyDescriptor::data(
            new JsNumber(5e-324), // Smallest positive subnormal double.
            writable: false,
            enumerable: false,
            configurable: false,
        ));
        $existing->defineOwnProperty('MAX_SAFE_INTEGER', PropertyDescriptor::data(
            new JsNumber(9007199254740991.0), // 2^53 - 1
            writable: false,
            enumerable: false,
            configurable: false,
        ));
        $existing->defineOwnProperty('MIN_SAFE_INTEGER', PropertyDescriptor::data(
            new JsNumber(-9007199254740991.0), // -(2^53 - 1)
            writable: false,
            enumerable: false,
            configurable: false,
        ));
        $existing->defineOwnProperty('POSITIVE_INFINITY', PropertyDescriptor::data(
            new JsNumber(INF),
            writable: false,
            enumerable: false,
            configurable: false,
        ));
        $existing->defineOwnProperty('NEGATIVE_INFINITY', PropertyDescriptor::data(
            new JsNumber(-INF),
            writable: false,
            enumerable: false,
            configurable: false,
        ));
        $existing->defineOwnProperty('NaN', PropertyDescriptor::data(
            new JsNumber(NAN),
            writable: false,
            enumerable: false,
            configurable: false,
        ));
        $existing->defineOwnProperty('EPSILON', PropertyDescriptor::data(
            new JsNumber(2.220446049250313e-16), // 2^-52
            writable: false,
            enumerable: false,
            configurable: false,
        ));

        // Static methods.
        $existing->set('isFinite', JsFunction::fromCallable('isFinite', self::isFiniteFn()));
        $existing->set('isInteger', JsFunction::fromCallable('isInteger', self::isInteger()));
        $existing->set('isNaN', JsFunction::fromCallable('isNaN', self::isNaNFn()));
        $existing->set('parseInt', JsFunction::fromCallable('parseInt', self::parseIntFn($env)));
        $existing->set('parseFloat', JsFunction::fromCallable('parseFloat', self::parseFloatFn($env)));

        // Prototype.
        $existing->set('prototype', $proto);
        $proto->set('constructor', $existing);
    }

    private static function createPrototype(): JsObject
    {
        $proto = new JsObject();

        $proto->set('toFixed', JsFunction::fromCallable('toFixed', self::toFixed()));
        $proto->set('toString', JsFunction::fromCallable('toString', self::toStringFn()));
        $proto->set('valueOf', JsFunction::fromCallable('valueOf', self::valueOf()));

        return $proto;
    }

    private static function isFiniteFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $v = $args[0] ?? JsUndefined::instance();
            // Number.isFinite: no coercion. Must be a JsNumber.
            if (!$v instanceof JsNumber) {
                return new JsBoolean(false);
            }
            return new JsBoolean(is_finite($v->value));
        };
    }

    private static function isInteger(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $v = $args[0] ?? JsUndefined::instance();
            if (!$v instanceof JsNumber) {
                return new JsBoolean(false);
            }
            if (is_nan($v->value) || is_infinite($v->value)) {
                return new JsBoolean(false);
            }
            return new JsBoolean(floor(abs($v->value)) === abs($v->value));
        };
    }

    private static function isNaNFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $v = $args[0] ?? JsUndefined::instance();
            // Number.isNaN: no coercion. Must be a JsNumber.
            if (!$v instanceof JsNumber) {
                return new JsBoolean(false);
            }
            return new JsBoolean(is_nan($v->value));
        };
    }

    private static function parseIntFn(Environment $env): \Closure
    {
        return function (JsValue $this_, array $args) use ($env): JsValue {
            // Delegate to the global parseInt.
            $fn = $env->get('parseInt');
            if ($fn instanceof JsFunction) {
                return $fn->call(JsUndefined::instance(), $args);
            }
            return new JsNumber(NAN);
        };
    }

    private static function parseFloatFn(Environment $env): \Closure
    {
        return function (JsValue $this_, array $args) use ($env): JsValue {
            // Delegate to the global parseFloat.
            $fn = $env->get('parseFloat');
            if ($fn instanceof JsFunction) {
                return $fn->call(JsUndefined::instance(), $args);
            }
            return new JsNumber(NAN);
        };
    }

    private static function toFixed(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            // Get the number value from `this`.
            $numValue = self::extractNumberValue($this_);
            $digits = isset($args[0]) ? (int) TypeConversion::toNumber($args[0]) : 0;

            if ($digits < 0 || $digits > 100) {
                throw new \PhpJs\Exceptions\RangeError('toFixed() digits argument must be between 0 and 100');
            }

            if (is_nan($numValue)) {
                return new JsString('NaN');
            }

            if (is_infinite($numValue)) {
                return new JsString($numValue > 0 ? 'Infinity' : '-Infinity');
            }

            return new JsString(number_format($numValue, $digits, '.', ''));
        };
    }

    private static function toStringFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $numValue = self::extractNumberValue($this_);
            $radix = isset($args[0]) ? (int) TypeConversion::toNumber($args[0]) : 10;

            if ($radix < 2 || $radix > 36) {
                throw new \PhpJs\Exceptions\RangeError('toString() radix must be between 2 and 36');
            }

            if (is_nan($numValue)) {
                return new JsString('NaN');
            }
            if (is_infinite($numValue)) {
                return new JsString($numValue > 0 ? 'Infinity' : '-Infinity');
            }

            if ($radix === 10) {
                return new JsString((new JsNumber($numValue))->toJsString());
            }

            // For integer values, use base conversion.
            if (floor($numValue) === $numValue) {
                $negative = $numValue < 0;
                $absVal = abs($numValue);
                $result = '';
                $chars = '0123456789abcdefghijklmnopqrstuvwxyz';
                if ($absVal === 0.0) {
                    $result = '0';
                } else {
                    $intVal = (int) $absVal;
                    while ($intVal > 0) {
                        $result = $chars[$intVal % $radix] . $result;
                        $intVal = intdiv($intVal, $radix);
                    }
                }
                return new JsString($negative ? '-' . $result : $result);
            }

            // For non-integer values with non-decimal radix, fall back to decimal representation.
            return new JsString((new JsNumber($numValue))->toJsString());
        };
    }

    private static function valueOf(): \Closure
    {
        return function (JsValue $this_): JsValue {
            if ($this_ instanceof JsNumber) {
                return $this_;
            }
            if ($this_ instanceof JsObject) {
                $prim = $this_->get('[[PrimitiveValue]]');
                if ($prim instanceof JsNumber) {
                    return $prim;
                }
            }
            return new JsNumber(NAN);
        };
    }

    private static function extractNumberValue(JsValue $this_): float
    {
        if ($this_ instanceof JsNumber) {
            return $this_->value;
        }
        if ($this_ instanceof JsObject) {
            $prim = $this_->get('[[PrimitiveValue]]');
            if ($prim instanceof JsNumber) {
                return $prim->value;
            }
        }
        return TypeConversion::toNumber($this_);
    }
}
