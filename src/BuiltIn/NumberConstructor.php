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

        // Static methods (non-enumerable per spec).
        $dm = static fn (string $n, \Closure $fn, int $len) => $existing->defineOwnProperty(
            $n,
            PropertyDescriptor::data(JsFunction::fromCallable($n, $fn, $len), true, false, true),
        );
        $dm('isFinite', self::isFiniteFn(), 1);
        $dm('isInteger', self::isInteger(), 1);
        $dm('isNaN', self::isNaNFn(), 1);
        $dm('isSafeInteger', function (JsValue $this_, array $args): JsValue {
            $val = $args[0] ?? JsUndefined::instance();
            if (!$val instanceof JsNumber) {
                return new JsBoolean(false);
            }
            $n = $val->value;
            if (!is_finite($n) || floor($n) !== $n) {
                return new JsBoolean(false);
            }
            return new JsBoolean(abs($n) <= 9007199254740991);
        }, 1);
        // Per spec, Number.parseInt === parseInt and Number.parseFloat === parseFloat
        $parseIntFn = $env->get('parseInt');
        if ($parseIntFn instanceof JsFunction) {
            $existing->defineOwnProperty('parseInt', PropertyDescriptor::data($parseIntFn, true, false, true));
        }
        $parseFloatFn = $env->get('parseFloat');
        if ($parseFloatFn instanceof JsFunction) {
            $existing->defineOwnProperty('parseFloat', PropertyDescriptor::data($parseFloatFn, true, false, true));
        }

        // Prototype (non-enumerable, non-writable, non-configurable per spec).
        $existing->defineOwnProperty('prototype', PropertyDescriptor::data(
            $proto,
            writable: false,
            enumerable: false,
            configurable: false,
        ));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($existing, true, false, true));

        // Register the prototype so TypeConversion::toObject can link Number wrapper objects.
        \PhpJs\Value\JsNumber::resetNumberPrototype();
        \PhpJs\Value\JsNumber::setNumberPrototype($proto);
    }

    private static function createPrototype(): JsObject
    {
        $proto = new JsObject();
        // Per spec 21.1.4, Number.prototype.[[NumberData]] is +0.
        $proto->defineOwnProperty('[[PrimitiveValue]]', PropertyDescriptor::data(new JsNumber(0.0), false, false, false));

        $d = static fn (string $n, \Closure $fn, int $len) => $proto->defineOwnProperty(
            $n,
            PropertyDescriptor::data(JsFunction::fromCallable($n, $fn, $len), true, false, true),
        );

        $d('toFixed', self::toFixed(), 1);
        $d('toPrecision', self::toPrecision(), 1);
        $d('toExponential', self::toExponential(), 1);
        $d('toString', self::toStringFn(), 1);
        $d('valueOf', self::valueOf(), 0);
        // toLocaleString: defaults to toString behavior
        $d('toLocaleString', function (JsValue $this_, array $args): JsValue {
            $numValue = self::extractNumberValue($this_);
            return new JsString((new JsNumber($numValue))->toJsString());
        }, 0);

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

    // parseInt/parseFloat now use the same function object from global scope

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
            $radixArg = $args[0] ?? JsUndefined::instance();
            $radix = ($radixArg instanceof JsUndefined) ? 10 : (int) TypeConversion::toNumber($radixArg);

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
            throw new \PhpJs\Exceptions\TypeError('Number.prototype.valueOf requires that \'this\' be a Number');
        };
    }

    private static function toPrecision(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $numValue = self::extractNumberValue($this_);

            if (!isset($args[0]) || $args[0] instanceof JsUndefined) {
                return new JsString((new JsNumber($numValue))->toJsString());
            }

            $precision = (int) TypeConversion::toNumber($args[0]);

            // NaN/Infinity check BEFORE range check per spec
            if (is_nan($numValue)) {
                return new JsString('NaN');
            }
            if (is_infinite($numValue)) {
                return new JsString($numValue > 0 ? 'Infinity' : '-Infinity');
            }

            if ($precision < 1 || $precision > 100) {
                throw new \PhpJs\Exceptions\RangeError('toPrecision() argument must be between 1 and 100');
            }

            $result = @sprintf('%.' . min($precision - 1, 53) . 'e', $numValue);
            $parts = explode('e', $result);
            $exp = (int) $parts[1];

            // Per spec step 10c: use exponential only if e < -6 or e >= p
            if ($exp >= -6 && $exp < $precision) {
                // Use fixed notation
                $decimalPlaces = max(0, $precision - $exp - 1);
                $formatted = number_format(abs($numValue), $decimalPlaces, '.', '');
                $prefix = $numValue < 0 ? '-' : '';
                return new JsString($prefix . $formatted);
            }

            // In exponential form, keep all precision digits (don't strip trailing zeros)
            if ($precision === 1) {
                $formatted = number_format((float) $parts[0], 0, '.', '');
            } else {
                $formatted = number_format((float) $parts[0], $precision - 1, '.', '');
            }
            $expSign = $exp >= 0 ? '+' : '-';
            return new JsString($formatted . 'e' . $expSign . abs($exp));
        };
    }

    private static function toExponential(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $numValue = self::extractNumberValue($this_);

            if (is_nan($numValue)) {
                return new JsString('NaN');
            }
            if (is_infinite($numValue)) {
                return new JsString($numValue > 0 ? 'Infinity' : '-Infinity');
            }

            $fractionDigits = isset($args[0]) && !($args[0] instanceof JsUndefined)
                ? (int) TypeConversion::toNumber($args[0])
                : null;

            if ($fractionDigits !== null && ($fractionDigits < 0 || $fractionDigits > 100)) {
                throw new \PhpJs\Exceptions\RangeError('toExponential() argument must be between 0 and 100');
            }

            if ($numValue === 0.0) {
                if ($fractionDigits !== null) {
                    $m = '0' . ($fractionDigits > 0 ? '.' . str_repeat('0', $fractionDigits) : '');
                } else {
                    $m = '0';
                }
                return new JsString($m . 'e+0');
            }

            $negative = $numValue < 0;
            $numValue = abs($numValue);

            if ($fractionDigits !== null) {
                $formatDigits = min($fractionDigits, 53);
                $result = sprintf('%.' . $formatDigits . 'e', $numValue);

                if ($fractionDigits > $formatDigits) {
                    [$mantissa, $exponent] = explode('e', $result);
                    if (!str_contains($mantissa, '.')) {
                        $mantissa .= '.';
                    }
                    [$integer, $fraction] = explode('.', $mantissa, 2);
                    $fraction = str_pad($fraction, $fractionDigits, '0');
                    $result = $integer . '.' . $fraction . 'e' . $exponent;
                }
            } else {
                $result = sprintf('%.20e', $numValue);
                $parts = explode('e', $result);
                $mantissa = rtrim(rtrim($parts[0], '0'), '.');
                $result = $mantissa . 'e' . $parts[1];
            }

            $result = preg_replace_callback('/e([+-])0*(\d+)/', function (array $m): string {
                return 'e' . $m[1] . $m[2];
            }, $result) ?? $result;

            return new JsString(($negative ? '-' : '') . $result);
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
        throw new \PhpJs\Exceptions\TypeError('Number.prototype method requires that \'this\' be a Number');
    }
}
