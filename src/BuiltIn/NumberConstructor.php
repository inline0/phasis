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
        $proto->defineOwnProperty(
            '[[PrimitiveValue]]',
            PropertyDescriptor::data(new JsNumber(0.0), false, false, false),
        );

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

            // Per spec step 2: ToIntegerOrInfinity(fractionDigits).
            // Must be done before NaN/Infinity checks on the number value.
            $fdRaw = isset($args[0]) ? TypeConversion::toIntegerOrInfinity($args[0]) : 0.0;

            // Infinity/NaN from ToIntegerOrInfinity means out of range.
            if (is_infinite($fdRaw) || is_nan($fdRaw) || $fdRaw < 0 || $fdRaw > 100) {
                throw new \PhpJs\Exceptions\RangeError('toFixed() digits argument must be between 0 and 100');
            }
            $digits = (int) $fdRaw;

            if (is_nan($numValue)) {
                return new JsString('NaN');
            }

            if (is_infinite($numValue)) {
                return new JsString($numValue > 0 ? 'Infinity' : '-Infinity');
            }

            // Per spec step 9: if abs(x) >= 10^21, return ToString(x).
            if (abs($numValue) >= 1e21) {
                return new JsString((new \PhpJs\Value\JsNumber($numValue))->toJsString());
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

            // Use sprintf to get the correctly-rounded scientific notation.
            // sprintf handles the rounding to p significant digits properly.
            $absValue = abs($numValue);
            $sprintfDigits = min($precision - 1, 53);
            $result = @sprintf('%.' . $sprintfDigits . 'e', $absValue);
            $parts = explode('e', $result);
            $mantissa = $parts[0];
            $exp = (int) $parts[1];

            // If precision exceeds sprintf capacity (53), pad the mantissa.
            if ($precision - 1 > $sprintfDigits) {
                if (!str_contains($mantissa, '.')) {
                    $mantissa .= '.';
                }
                $dotPos = strpos($mantissa, '.');
                $currentDecimals = strlen($mantissa) - $dotPos - 1;
                $mantissa = str_pad($mantissa, $dotPos + 1 + ($precision - 1), '0');
            }

            $prefix = $numValue < 0 ? '-' : '';

            // Per spec step 10c: use exponential only if e < -6 or e >= p
            if ($exp >= -6 && $exp < $precision) {
                // Use fixed notation: rebuild from the mantissa digits.
                // Remove the decimal point from mantissa to get the digit string.
                $digits = str_replace('.', '', $mantissa);
                // $digits has $precision significant digits. The decimal point goes after
                // position (exp + 1) from the left.
                $intPartLen = $exp + 1;
                if ($intPartLen >= $precision) {
                    // All digits are before the decimal point, pad with zeros if needed.
                    $formatted = $digits . str_repeat('0', $intPartLen - $precision);
                } else {
                    $intPart = substr($digits, 0, $intPartLen);
                    $fracPart = substr($digits, $intPartLen);
                    if ($intPartLen <= 0) {
                        // Number like 0.00123: intPartLen is 0 or negative
                        $intPart = '0';
                        $fracPart = str_repeat('0', -$exp - 1) . $digits;
                    }
                    $formatted = $intPart . '.' . $fracPart;
                }
                return new JsString($prefix . $formatted);
            }

            // Exponential form: use the mantissa string directly from sprintf.
            $expSign = $exp >= 0 ? '+' : '-';
            return new JsString($prefix . $mantissa . 'e' . $expSign . abs($exp));
        };
    }

    private static function toExponential(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $numValue = self::extractNumberValue($this_);

            // Per spec step 2: ToIntegerOrInfinity(fractionDigits) BEFORE NaN/Infinity check.
            // This allows valueOf/toString/Symbol coercions to throw.
            $fdArg = $args[0] ?? JsUndefined::instance();
            $fdIsUndefined = $fdArg instanceof JsUndefined;
            if (!$fdIsUndefined) {
                $fdNum = TypeConversion::toIntegerOrInfinity($fdArg);
            }

            if (is_nan($numValue)) {
                return new JsString('NaN');
            }
            if (is_infinite($numValue)) {
                return new JsString($numValue > 0 ? 'Infinity' : '-Infinity');
            }

            $fractionDigits = $fdIsUndefined ? null : (int) $fdNum;

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
                // Use custom rounding to avoid PHP's banker's rounding.
                // JavaScript uses round-half-away-from-zero.
                $result = self::formatExponential($numValue, $fractionDigits);
            } else {
                // Undefined fractionDigits: use the minimal representation.
                // Find the shortest representation such that parsing it back
                // gives the same IEEE 754 double.
                $result = self::formatExponentialMinimal($numValue);
            }

            $result = preg_replace_callback('/e([+-])0*(\d+)/', function (array $m): string {
                return 'e' . $m[1] . $m[2];
            }, $result) ?? $result;

            return new JsString(($negative ? '-' : '') . $result);
        };
    }

    /**
     * Format a positive number in exponential notation with the given
     * number of fraction digits, using round-half-away-from-zero.
     */
    private static function formatExponential(float $value, int $fractionDigits): string
    {
        // Use sprintf with extra precision to get enough digits, then
        // apply JavaScript-style rounding (round half away from zero).
        $extraDigits = min($fractionDigits + 5, 53);
        $raw = @sprintf('%.' . $extraDigits . 'e', $value);
        $parts = explode('e', $raw);
        $rawMantissa = $parts[0];
        $e = (int) $parts[1];

        // Remove the decimal point to get a digit string.
        $rawDigits = str_replace('.', '', $rawMantissa);
        // rawDigits has (extraDigits + 1) significant digits.
        // We need (fractionDigits + 1) significant digits.
        $needed = $fractionDigits + 1;

        if (strlen($rawDigits) > $needed) {
            // Round the digit string to $needed digits.
            // JavaScript uses round-half-away-from-zero.
            $truncated = substr($rawDigits, 0, $needed);
            $nextDigit = (int) ($rawDigits[$needed] ?? '0');
            if ($nextDigit >= 5) {
                // Round up.
                $carry = 1;
                $digits = str_split($truncated);
                for ($i = count($digits) - 1; $i >= 0 && $carry; $i--) {
                    $d = (int) $digits[$i] + $carry;
                    $digits[$i] = (string) ($d % 10);
                    $carry = (int) ($d >= 10);
                }
                $nStr = implode('', $digits);
                if ($carry) {
                    // Rounding caused overflow (e.g. 999 -> 1000).
                    $nStr = '1' . substr($nStr, 0, $needed - 1);
                    $e++;
                }
            } else {
                $nStr = $truncated;
            }
        } else {
            $nStr = str_pad($rawDigits, $needed, '0');
        }

        if ($fractionDigits > 0) {
            $mantissa = $nStr[0] . '.' . substr($nStr, 1);
        } else {
            $mantissa = $nStr[0];
        }

        $expSign = $e >= 0 ? '+' : '-';
        return $mantissa . 'e' . $expSign . abs($e);
    }

    /**
     * Format a positive number in minimal exponential notation.
     * Uses the fewest fraction digits such that the representation
     * is unique (parsing back gives the same double).
     */
    private static function formatExponentialMinimal(float $value): string
    {
        // Use PHP's serialize-quality formatting to get exact representation.
        // sprintf with %.17g gives enough digits, but we need exponential form.
        // Strategy: use sprintf to get enough precision, then strip trailing zeros.
        $e = (int) floor(log10($value));
        // Try increasing precision until roundtrip is exact.
        for ($digits = 0; $digits <= 20; $digits++) {
            $formatted = self::formatExponential($value, $digits);
            // Parse back to verify roundtrip.
            if ((float) $formatted === $value) {
                return $formatted;
            }
        }
        // Fallback: 20 digits should always be enough.
        return self::formatExponential($value, 20);
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
