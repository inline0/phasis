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
            if (is_nan($numValue)) {
                return new JsString('NaN');
            }
            if (is_infinite($numValue)) {
                return new JsString($numValue > 0 ? 'Infinity' : '-Infinity');
            }
            return new JsString(self::numberToDecimalString($numValue));
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
                return new JsString(self::numberToDecimalString($numValue));
            }

            // PHP's number_format drops the sign for negative values that
            // round to zero, but spec §21.1.3.3 step 12 prepends "-" when
            // x < 0 (signed-zero semantics: -0 stays "0"; tiny negative
            // values like -Number.MIN_VALUE rounded to 0 still get "-").
            $formatted = number_format(abs($numValue), $digits, '.', '');
            if ($numValue < 0) {
                $formatted = '-' . $formatted;
            }
            return new JsString($formatted);
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
                return new JsString(self::numberToDecimalString($numValue));
            }

            // For non-decimal radix: use base conversion for the integer part,
            // then fractional expansion for the fractional part.
            return new JsString(self::numberToRadixString($numValue, $radix));
        };
    }

    /**
     * ES spec Number::toString for radix 10 (7.1.12.1).
     *
     * Uses json_encode for the shortest decimal representation (Grisu3/Ryu),
     * then formats according to ECMAScript rules.
     */
    private static function numberToDecimalString(float $value): string
    {
        if ($value === 0.0) {
            return '0';
        }

        $negative = $value < 0;
        $abs = abs($value);

        // Get shortest decimal representation via json_encode (implements Grisu3/Ryu).
        $json = json_encode($abs, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = sprintf('%.17g', $abs);
        }

        $json = strtolower($json);
        if (str_contains($json, 'e')) {
            [$mantissa, $exp] = explode('e', $json, 2);
            $phpExp = (int) $exp;
        } else {
            $mantissa = $json;
            $phpExp = 0;
        }

        if (str_contains($mantissa, '.')) {
            $dotPos = strpos($mantissa, '.');
            $digits = str_replace('.', '', $mantissa);
            $decimalPlaces = strlen($mantissa) - $dotPos - 1;
            $actualExp = $phpExp - $decimalPlaces;
        } else {
            $digits = $mantissa;
            $actualExp = $phpExp;
        }

        $digits = ltrim($digits, '0');
        if ($digits === '') {
            $digits = '0';
        }

        $trailingZeros = strlen($digits) - strlen(rtrim($digits, '0'));
        if ($trailingZeros > 0) {
            $digits = rtrim($digits, '0');
            $actualExp += $trailingZeros;
        }
        if ($digits === '') {
            $digits = '0';
        }

        $k = strlen($digits);
        $n = $actualExp + $k;

        if ($k <= $n && $n <= 21) {
            $result = $digits . str_repeat('0', $n - $k);
        } elseif (0 < $n && $n <= 21) {
            $result = substr($digits, 0, $n) . '.' . substr($digits, $n);
        } elseif (-6 < $n && $n <= 0) {
            $result = '0.' . str_repeat('0', -$n) . $digits;
        } elseif ($k === 1) {
            $e = $n - 1;
            $result = $digits . 'e' . ($e >= 0 ? '+' : '') . $e;
        } else {
            $e = $n - 1;
            $result = $digits[0] . '.' . substr($digits, 1) . 'e' . ($e >= 0 ? '+' : '') . $e;
        }

        return $negative ? '-' . $result : $result;
    }

    /**
     * Number::toString for non-decimal radix.
     *
     * Handles integer and fractional parts separately.
     */
    private static function numberToRadixString(float $value, int $radix): string
    {
        if ($value === 0.0) {
            return '0';
        }

        $negative = $value < 0;
        $abs = abs($value);
        $chars = '0123456789abcdefghijklmnopqrstuvwxyz';

        // Integer part.
        $intPart = floor($abs);
        $fracPart = $abs - $intPart;

        $intStr = '';
        if ($intPart === 0.0) {
            $intStr = '0';
        } else {
            while ($intPart > 0) {
                $digit = (int) fmod($intPart, $radix);
                $intStr = $chars[$digit] . $intStr;
                $intPart = floor($intPart / $radix);
            }
        }

        $result = $intStr;

        // Fractional part: expand by multiplying by radix repeatedly.
        if ($fracPart > 0) {
            $result .= '.';
            // Limit to ~64 digits to match V8 behavior and avoid infinite loops.
            $maxDigits = 64;
            for ($i = 0; $i < $maxDigits && $fracPart > 0; $i++) {
                $fracPart *= $radix;
                $digit = (int) $fracPart;
                $result .= $chars[$digit];
                $fracPart -= $digit;
            }
        }

        return $negative ? '-' . $result : $result;
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

            $absValue = abs($numValue);
            // Use the bcmath-based exact decimal expansion of the IEEE 754
            // double for the requested precision. libc printf rounds at
            // its requested precision, which can poison a subsequent
            // round-half-away-from-zero step on the same precision.
            if ($absValue === 0.0) {
                $digits = str_repeat('0', $precision);
                $exp = 0;
            } else {
                [$digits, $exp] = self::exactDecimalDigits($absValue, $precision);
            }
            if ($precision > 1) {
                $mantissa = $digits[0] . '.' . substr($digits, 1);
            } else {
                $mantissa = $digits[0];
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
        $needed = $fractionDigits + 1;
        // Always use the bcmath-based exact decimal expansion: it produces
        // the same digit stream as V8 / SpiderMonkey for any precision up
        // to 100, and avoids libc-printf rounding artefacts where it had
        // already rounded the requested-precision-th digit before our own
        // round-half-away-from-zero pass.
        [$digits, $e] = self::exactDecimalDigits($value, $needed);

        if ($fractionDigits > 0) {
            $mantissa = $digits[0] . '.' . substr($digits, 1);
        } else {
            $mantissa = $digits[0];
        }

        $expSign = $e >= 0 ? '+' : '-';
        return $mantissa . 'e' . $expSign . abs($e);
    }

    /**
     * Compute the exact decimal expansion of a positive finite double to
     * the given number of significant digits, using bcmath. Returns
     * [digits, decimalExponent] where digits is a string of $sigDigits
     * decimal digits and decimalExponent is the power-of-10 of the
     * leading digit (so digits[0] . '.' . digits[1..] × 10^exp == value).
     *
     * @return array{0: string, 1: int}
     */
    private static function exactDecimalDigits(float $value, int $sigDigits): array
    {
        // Decompose the IEEE 754 double into sign, biased exponent, and
        // 52-bit mantissa. Use little-endian pack ('e') so the byte order
        // is portable.
        $bytes = pack('e', $value);
        $lo = unpack('V', substr($bytes, 0, 4))[1];
        $hi = unpack('V', substr($bytes, 4, 4))[1];
        $expBiased = ($hi >> 20) & 0x7FF;
        $mantHi = $hi & 0xFFFFF;
        // Build full 53-bit mantissa: implicit leading 1 (or 0 for denormals).
        $impl = $expBiased === 0 ? 0 : 1;
        $mant = bcadd(
            bcadd(bcmul((string) $impl, '4503599627370496'), bcmul((string) $mantHi, '4294967296')),
            (string) $lo,
        );
        // Power-of-2 shift: value = mant * 2^shift.
        // Normal: shift = expBiased - 1023 - 52.
        // Denormal (expBiased=0): shift = -1074.
        $shift = $expBiased === 0 ? -1074 : ($expBiased - 1075);

        // Compute a decimal string for mant * 2^shift. For negative shift
        // the leading non-zero digit of 2^shift is roughly at position
        // shift * log10(2) ≈ shift * 0.30103, so the bcdiv scale must
        // cover that plus sigDigits + a rounding margin.
        if ($shift >= 0) {
            $exact = bcmul($mant, bcpow('2', (string) $shift), 0);
        } else {
            $magnitude = (int) ceil(-$shift * 0.30103);
            $scale = $magnitude + $sigDigits + 5;
            $exact = bcdiv($mant, bcpow('2', (string) (-$shift)), $scale);
        }

        // Strip a leading sign (defensive — value is positive here).
        if ($exact !== '' && $exact[0] === '-') {
            $exact = substr($exact, 1);
        }

        // Split on the decimal point.
        $dot = strpos($exact, '.');
        if ($dot === false) {
            $intPart = $exact;
            $fracPart = '';
        } else {
            $intPart = substr($exact, 0, $dot);
            $fracPart = substr($exact, $dot + 1);
        }
        $intPart = ltrim($intPart, '0');

        // Find the position of the first non-zero digit and build a
        // contiguous digit stream starting there.
        if ($intPart !== '') {
            $digitStream = $intPart . $fracPart;
            $decimalExponent = strlen($intPart) - 1;
        } else {
            // Value < 1: skip leading zero fraction digits.
            $skip = 0;
            $fracLen = strlen($fracPart);
            while ($skip < $fracLen && $fracPart[$skip] === '0') {
                $skip++;
            }
            if ($skip === $fracLen) {
                // Effectively zero in the precision we computed.
                return [str_repeat('0', $sigDigits), 0];
            }
            $digitStream = substr($fracPart, $skip);
            $decimalExponent = -1 - $skip;
        }

        // Take sigDigits + 1 leading digits for rounding. Pad with zeros
        // if the stream is shorter (very small denormals, etc.).
        $stream = str_pad($digitStream, $sigDigits + 1, '0');
        $taken = substr($stream, 0, $sigDigits);
        $nextDigit = (int) ($stream[$sigDigits] ?? '0');
        if ($nextDigit >= 5) {
            [$taken, $carry] = self::roundUpDigitString($taken);
            if ($carry) {
                $taken = '1' . substr($taken, 0, $sigDigits - 1);
                $decimalExponent++;
            }
        }

        return [$taken, $decimalExponent];
    }

    /**
     * Increment a decimal-digit string by one, returning [newDigits, carry].
     * Carry is 1 only when the entire string was 9s (e.g. "999" -> "000" + carry).
     *
     * @return array{0: string, 1: int}
     */
    private static function roundUpDigitString(string $s): array
    {
        $digits = str_split($s);
        $carry = 1;
        for ($i = count($digits) - 1; $i >= 0 && $carry; $i--) {
            $d = (int) $digits[$i] + $carry;
            $digits[$i] = (string) ($d % 10);
            $carry = (int) ($d >= 10);
        }
        return [implode('', $digits), $carry];
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
