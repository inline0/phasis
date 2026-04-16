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

            // Step 1: Convert fractionDigits to integer.
            // Use ToIntegerOrInfinity: Infinity / -Infinity → RangeError before NaN check.
            $digitsRaw = isset($args[0]) && !($args[0] instanceof JsUndefined)
                ? TypeConversion::toNumber($args[0])
                : 0.0;
            if (is_infinite($digitsRaw)) {
                throw new \PhpJs\Exceptions\RangeError('toFixed() digits argument must be between 0 and 100');
            }
            $digits = is_nan($digitsRaw) ? 0 : (int) $digitsRaw;

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

            $fractionDigitsArg = $args[0] ?? null;
            $isUndefined = $fractionDigitsArg === null || $fractionDigitsArg instanceof JsUndefined;

            if (!$isUndefined) {
                $raw = TypeConversion::toNumber($fractionDigitsArg);
                if (is_infinite($raw)) {
                    throw new \PhpJs\Exceptions\RangeError('toExponential() argument must be between 0 and 100');
                }
                $fractionDigits = is_nan($raw) ? 0 : (int) $raw;
                if ($fractionDigits < 0 || $fractionDigits > 100) {
                    throw new \PhpJs\Exceptions\RangeError('toExponential() argument must be between 0 and 100');
                }
            } else {
                $fractionDigits = null;
            }

            $negative = $numValue < 0;
            $numValue = abs($numValue);

            if ($fractionDigits === null) {
                // No fractionDigits: use shortest round-trip representation.
                // Get the JS string for the absolute value using Grisu-like algorithm.
                $str = (new JsNumber($negative ? -$numValue : $numValue))->toJsString();
                if ($negative) {
                    $str = ltrim($str, '-');
                }
                // Convert the JS string representation to exponential form.
                $result = self::stringToExponential($str);
            } else {
                if ($numValue === 0.0) {
                    $m = '0' . ($fractionDigits > 0 ? '.' . str_repeat('0', $fractionDigits) : '');
                    return new JsString($m . 'e+0');
                }
                $result = sprintf('%.' . $fractionDigits . 'e', $numValue);
                // Normalize exponent: remove leading zeros, ensure sign.
                $result = preg_replace_callback('/e([+-])0*(\d+)/', function (array $m): string {
                    return 'e' . $m[1] . $m[2];
                }, $result) ?? $result;
            }

            return new JsString(($negative ? '-' : '') . $result);
        };
    }

    /**
     * Convert a JS number string (e.g. "123.456", "1e+20", "0.001") to exponential notation.
     * Used by toExponential() when fractionDigits is undefined.
     */
    private static function stringToExponential(string $str): string
    {
        if ($str === '0') {
            return '0e+0';
        }

        // Parse the string into significant digits and base-10 exponent.
        $str = strtolower($str);
        $e = 0;

        if (str_contains($str, 'e')) {
            [$mantissa, $expStr] = explode('e', $str, 2);
            $e = (int) $expStr;
        } else {
            $mantissa = $str;
        }

        // Extract all digit characters and locate where the decimal point is.
        if (str_contains($mantissa, '.')) {
            $dotPos = strpos($mantissa, '.');
            $allDigits = str_replace('.', '', $mantissa);
            // The exponent contribution from the decimal position:
            // value = (allDigits as integer) × 10^(e - (len(allDigits) - dotPos))
            // We want: significantDigits × 10^exp where significantDigits ∈ [1, 10)
            $e -= (strlen($allDigits) - (int) $dotPos);
        } else {
            $allDigits = $mantissa;
            // value = allDigits × 10^e
        }

        // Strip leading zeros from digits and adjust exponent.
        $leadingZeros = strlen($allDigits) - strlen(ltrim($allDigits, '0'));
        $digits = ltrim($allDigits, '0');
        if ($digits === '') {
            $digits = '0';
        }
        // Each leading zero removed means we need to subtract 1 from e.
        // (Because allDigits × 10^e = digits × 10^(e + leadingZeros)... wait, no.)
        // Actually: allDigits = 00001, e -= len(00001) - 4 = 1, so e was adjusted for len already.
        // Strip leading zeros: 00001 → 1, adjust e.
        // If allDigits = "00001" and e represents allDigits × 10^e: "1" × 10^(e + 4)
        // But we already adjusted e for the decimal, so:
        // If allDigits = "00001" and value = allDigits_int × 10^(e_adjusted):
        // allDigits_int = 1, so value = 1 × 10^(e_adjusted)
        // But we need to handle e += leadingZeros to get the right final exponent.
        // No wait: if value = "00001" × 10^X, then value = 1 × 10^X (since "00001" as int = 1)
        // The exponent X is already computed correctly above.
        // What changes when stripping leading zeros: the _digit representation_ changes,
        // but the value doesn't. The exponent for exponential notation is position of first digit.
        // After stripping leading zeros: digits = "1", and e = X - 0 (no change needed).
        // Because: allDigits_int × 10^X = digits_int × 10^X (same value).
        // The exponential exponent = X + len(digits) - 1.
        $e += strlen($digits) - 1;

        // Remove trailing zeros from digits.
        $digits = rtrim($digits, '0');
        if ($digits === '') {
            $digits = '0';
        }

        // Format: first digit, optional dot + rest, e+exp.
        if (strlen($digits) === 1) {
            $mantissaStr = $digits;
        } else {
            $mantissaStr = $digits[0] . '.' . substr($digits, 1);
        }

        return $mantissaStr . 'e' . ($e >= 0 ? '+' : '') . $e;
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
