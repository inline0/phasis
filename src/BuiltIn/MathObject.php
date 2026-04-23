<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Object\PropertyDescriptor;
use PhpJs\Runtime\Environment;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

class MathObject
{
    public static function install(Environment $env): void
    {
        $math = new JsObject();

        // Constants.
        $c = static fn (float $v) => PropertyDescriptor::data(
            new JsNumber($v),
            writable: false,
            enumerable: false,
            configurable: false,
        );
        $math->defineOwnProperty('PI', $c(M_PI));
        $math->defineOwnProperty('E', $c(M_E));
        $math->defineOwnProperty('LN2', $c(M_LN2));
        $math->defineOwnProperty('LN10', $c(M_LN10));
        $math->defineOwnProperty('LOG2E', $c(M_LOG2E));
        $math->defineOwnProperty('LOG10E', $c(M_LOG10E));
        $math->defineOwnProperty('SQRT2', $c(M_SQRT2));
        $math->defineOwnProperty('SQRT1_2', $c(M_SQRT1_2));

        // Single-argument math functions.
        // Helper to install a Math method (writable, non-enumerable, configurable).
        $m = static fn (string $n, \Closure $fn, int $len = 1) => $math->defineOwnProperty(
            $n,
            PropertyDescriptor::data(JsFunction::fromCallable($n, $fn, $len), true, false, true),
        );

        $m('abs', self::singleArgFn('abs'));
        $m('ceil', self::singleArgFn('ceil'));
        $m('floor', self::singleArgFn('floor'));
        $m('round', self::roundFn());
        $m('trunc', self::truncFn());
        $m('sqrt', self::singleArgFn('sqrt'));
        $m('cbrt', self::cbrtFn());
        $m('log', self::singleArgFn('log'));
        $m('log2', self::log2Fn());
        $m('log10', self::singleArgFn('log10'));
        $m('sin', self::singleArgFn('sin'));
        $m('cos', self::singleArgFn('cos'));
        $m('tan', self::singleArgFn('tan'));
        $m('sign', self::signFn());
        $m('fround', self::froundFn());
        $m('f16round', self::f16roundFn());
        $m('exp', self::singleArgFn('exp'));
        $m('expm1', self::singleArgFn('expm1'));
        $m('asin', self::singleArgFn('asin'));
        $m('acos', self::singleArgFn('acos'));
        $m('atan', self::singleArgFn('atan'));
        $m('sinh', self::singleArgFn('sinh'));
        $m('cosh', self::singleArgFn('cosh'));
        $m('tanh', self::singleArgFn('tanh'));
        $m('asinh', self::singleArgFn('asinh'));
        $m('acosh', self::singleArgFn('acosh'));
        $m('atanh', self::singleArgFn('atanh'));
        $m('log1p', self::singleArgFn('log1p'));
        $m('atan2', function (JsValue $this_, array $args): JsValue {
            $y = isset($args[0]) ? TypeConversion::toNumber($args[0]) : NAN;
            $x = isset($args[1]) ? TypeConversion::toNumber($args[1]) : NAN;
            return new JsNumber(atan2($y, $x));
        }, 2);
        $m('clz32', function (JsValue $this_, array $args): JsValue {
            $x = isset($args[0]) ? TypeConversion::toUint32($args[0]) : 0;
            if ($x === 0) {
                return new JsNumber(32.0);
            }
            return new JsNumber((float) (31 - (int) floor(log($x, 2))));
        });
        $m('imul', function (JsValue $this_, array $args): JsValue {
            $a = isset($args[0]) ? TypeConversion::toUint32($args[0]) : 0;
            $b = isset($args[1]) ? TypeConversion::toUint32($args[1]) : 0;
            // Split into 16-bit halves to avoid 64-bit overflow
            $al = $a & 0xFFFF;
            $ah = ($a >> 16) & 0xFFFF;
            $bl = $b & 0xFFFF;
            $bh = ($b >> 16) & 0xFFFF;
            $product = ($al * $bl) + ((($ah * $bl + $al * $bh) & 0xFFFF) << 16);
            // Wrap to signed 32-bit
            $product = $product & 0xFFFFFFFF;
            if ($product >= 0x80000000) {
                $product -= 0x100000000;
            }
            return new JsNumber((float) $product);
        }, 2);

        // Multi-argument or special functions.
        $m('pow', self::powFn(), 2);
        $m('max', self::maxFn(), 2);
        $m('min', self::minFn(), 2);
        $m('random', self::randomFn(), 0);
        $m('hypot', self::hypotFn(), 2);
        $m('sumPrecise', self::sumPreciseFn(), 1);

        // Symbol.toStringTag = "Math" (non-writable, non-enumerable, configurable)
        $toStringTagSym = \PhpJs\BuiltIn\SymbolConstructor::toStringTag();
        $math->definePropertyBySymbol(
            $toStringTagSym,
            PropertyDescriptor::data(new JsString('Math'), false, false, true),
        );

        $env->defineVar('Math', $math);
    }

    private static function singleArgFn(string $phpFn): \Closure
    {
        return function (JsValue $this_, array $args) use ($phpFn): JsValue {
            $x = isset($args[0]) ? TypeConversion::toNumber($args[0]) : NAN;
            return new JsNumber($phpFn($x));
        };
    }

    private static function roundFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $x = isset($args[0]) ? TypeConversion::toNumber($args[0]) : NAN;
            if (is_nan($x) || is_infinite($x) || $x === 0.0) {
                return new JsNumber($x);
            }
            // JS Math.round uses "round half to positive infinity" (banker's rounding up).
            // PHP round uses "round half away from zero" by default.
            // The difference: Math.round(-0.5) === -0, Math.round(0.5) === 1.
            // For the general case, the JS spec says: if fractional part is exactly 0.5,
            // round toward positive infinity.
            // Per spec: if x is between -0.5 (inclusive) and +0 (exclusive), return -0.
            if ($x > -0.5 && $x < 0.0) {
                return new JsNumber(-0.0);
            }
            $floored = floor($x);
            $frac = $x - $floored;
            if ($frac === 0.5) {
                $result = $floored + 1;
                // Preserve -0 for the case where x === -0.5.
                return new JsNumber($result == 0 && $x < 0 ? -0.0 : $result);
            }
            return new JsNumber(round($x));
        };
    }

    private static function truncFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $x = isset($args[0]) ? TypeConversion::toNumber($args[0]) : NAN;
            if (is_nan($x) || is_infinite($x) || $x === 0.0) {
                return new JsNumber($x);
            }
            return new JsNumber($x > 0 ? floor($x) : ceil($x));
        };
    }

    private static function cbrtFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $x = isset($args[0]) ? TypeConversion::toNumber($args[0]) : NAN;
            if (is_nan($x) || is_infinite($x) || $x === 0.0) {
                return new JsNumber($x);
            }
            // PHP does not have a built-in cbrt, use pow.
            $sign = $x < 0 ? -1.0 : 1.0;
            return new JsNumber($sign * pow(abs($x), 1.0 / 3.0));
        };
    }

    private static function log2Fn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $x = isset($args[0]) ? TypeConversion::toNumber($args[0]) : NAN;
            return new JsNumber(log($x, 2));
        };
    }

    private static function signFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $x = isset($args[0]) ? TypeConversion::toNumber($args[0]) : NAN;
            if (is_nan($x)) {
                return new JsNumber(NAN);
            }
            if ($x === 0.0) {
                // Preserve sign of zero.
                return new JsNumber($x);
            }
            return new JsNumber($x > 0 ? 1.0 : -1.0);
        };
    }

    private static function froundFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $x = isset($args[0]) ? TypeConversion::toNumber($args[0]) : NAN;
            // Convert to 32-bit float and back to simulate fround.
            $packed = pack('f', $x);
            $unpacked = unpack('f', $packed);
            return new JsNumber($unpacked !== false ? (float) $unpacked[1] : $x);
        };
    }

    /** IEEE 754 binary16 (half-precision) round. */
    private static function f16roundFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $x = isset($args[0]) ? TypeConversion::toNumber($args[0]) : NAN;
            if (is_nan($x) || is_infinite($x) || $x === 0.0) {
                return new JsNumber($x);
            }
            $sign = $x < 0 ? -1.0 : 1.0;
            $abs = abs($x);
            // Overflow to Infinity: >= 65520 rounds up per IEEE.
            if ($abs >= 65520.0) {
                return new JsNumber($sign * INF);
            }
            // Extract 64-bit IEEE representation.
            $bits = unpack('Q', pack('d', $abs));
            $raw = $bits[1];
            $exp64 = ($raw >> 52) & 0x7FF;
            $mant64 = $raw & 0xFFFFFFFFFFFFF;
            $unbiased = $exp64 - 1023;
            $exp16 = $unbiased + 15;
            if ($exp16 <= 0) {
                // Subnormal in binary16.
                $sig53 = (1 << 52) | $mant64;
                $shift = 43 - $exp16;
                if ($shift >= 64) {
                    return new JsNumber($sign * 0.0);
                }
                $mant16 = $sig53 >> $shift;
                $rem = $sig53 & ((1 << $shift) - 1);
                $half = 1 << ($shift - 1);
                if ($rem > $half || ($rem === $half && ($mant16 & 1))) {
                    $mant16++;
                }
                return new JsNumber($sign * $mant16 * (2.0 ** -24));
            }
            // Normal: drop low 42 bits with rounding.
            $mant16 = $mant64 >> 42;
            $rem = $mant64 & 0x3FFFFFFFFFF;
            $half = 0x20000000000;
            if ($rem > $half || ($rem === $half && ($mant16 & 1))) {
                $mant16++;
                if ($mant16 >= 1024) {
                    $mant16 = 0;
                    $exp16++;
                    if ($exp16 >= 31) {
                        return new JsNumber($sign * INF);
                    }
                }
            }
            $val = (2.0 ** ($exp16 - 15)) * (1.0 + $mant16 / 1024.0);
            return new JsNumber($sign * $val);
        };
    }

    private static function powFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $base = isset($args[0]) ? TypeConversion::toNumber($args[0]) : NAN;
            $exp = isset($args[1]) ? TypeConversion::toNumber($args[1]) : NAN;
            // ES spec 6.1.6.1.3: special cases
            if (is_nan($exp)) {
                return new JsNumber(NAN);
            }
            if ($exp === 0.0) {
                return new JsNumber(1.0);
            }
            if (is_nan($base)) {
                return new JsNumber(NAN);
            }
            // abs(base) === 1 and exp is infinite -> NaN
            if (abs($base) === 1.0 && is_infinite($exp)) {
                return new JsNumber(NAN);
            }
            if ($base === 0.0 && $exp < 0) {
                // -0 to odd negative integer -> -Infinity, otherwise Infinity
                if (JsNumber::isNegativeZero($base) && fmod($exp, 2) === -1.0) {
                    return new JsNumber(-INF);
                }
                return new JsNumber(INF);
            }
            return new JsNumber(@($base ** $exp));
        };
    }

    private static function maxFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (empty($args)) {
                return new JsNumber(-INF);
            }
            // Per spec: coerce ALL arguments first, then find max
            $coerced = [];
            foreach ($args as $arg) {
                $coerced[] = TypeConversion::toNumber($arg);
            }
            $result = -INF;
            foreach ($coerced as $n) {
                if (is_nan($n)) {
                    return new JsNumber(NAN);
                }
                if ($n > $result || ($result === -INF && $n === -INF)) {
                    $result = $n;
                }
                // Handle +0 > -0.
                if ($result === 0.0 && $n === 0.0 && !JsNumber::isNegativeZero($n)) {
                    $result = 0.0;
                }
            }
            return new JsNumber($result);
        };
    }

    private static function minFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (empty($args)) {
                return new JsNumber(INF);
            }
            // Per spec: coerce ALL arguments first, then find min
            $coerced = [];
            foreach ($args as $arg) {
                $coerced[] = TypeConversion::toNumber($arg);
            }
            $result = INF;
            foreach ($coerced as $n) {
                if (is_nan($n)) {
                    return new JsNumber(NAN);
                }
                if ($n < $result || ($result === INF && $n === INF)) {
                    $result = $n;
                }
                // Handle -0 < +0.
                if ($result === 0.0 && $n === 0.0 && JsNumber::isNegativeZero($n)) {
                    $result = $n;
                }
            }
            return new JsNumber($result);
        };
    }

    private static function randomFn(): \Closure
    {
        return function (): JsValue {
            return new JsNumber(mt_rand() / mt_getrandmax());
        };
    }

    private static function hypotFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (empty($args)) {
                return new JsNumber(0.0);
            }
            // Per spec: coerce ALL arguments to numbers first (for error propagation),
            // then check for Infinity (takes precedence over NaN).
            $numbers = [];
            foreach ($args as $arg) {
                $numbers[] = TypeConversion::toNumber($arg);
            }
            $hasInf = false;
            $hasNaN = false;
            $sum = 0.0;
            foreach ($numbers as $n) {
                if (is_infinite($n)) {
                    $hasInf = true;
                } elseif (is_nan($n)) {
                    $hasNaN = true;
                } else {
                    $sum += $n * $n;
                }
            }
            if ($hasInf) {
                return new JsNumber(INF);
            }
            if ($hasNaN) {
                return new JsNumber(NAN);
            }
            return new JsNumber(sqrt($sum));
        };
    }

    private static function sumPreciseFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $iterable = $args[0] ?? JsUndefined::instance();
            if ($iterable instanceof JsUndefined || $iterable instanceof \PhpJs\Value\JsNull) {
                throw new \PhpJs\Exceptions\TypeError('Math.sumPrecise requires an iterable argument');
            }
            if (!$iterable instanceof \PhpJs\Value\JsObject) {
                throw new \PhpJs\Exceptions\TypeError(
                    TypeConversion::toString($iterable) . ' is not iterable',
                );
            }

            $values = [];
            $iterSym = \PhpJs\BuiltIn\SymbolConstructor::iterator();
            $iteratorMethod = $iterable->getBySymbol($iterSym);
            if (!$iteratorMethod instanceof \PhpJs\Value\JsFunction) {
                throw new \PhpJs\Exceptions\TypeError('object is not iterable');
            }

            $iterator = $iteratorMethod->call($iterable, []);
            if (!$iterator instanceof \PhpJs\Value\JsObject) {
                throw new \PhpJs\Exceptions\TypeError('object is not iterable');
            }

            $nextMethod = $iterator->get('next');
            if (!$nextMethod instanceof \PhpJs\Value\JsFunction) {
                throw new \PhpJs\Exceptions\TypeError('object is not iterable');
            }

            $typeErr = null;
            while (true) {
                $result = $nextMethod->call($iterator, []);
                if (!$result instanceof \PhpJs\Value\JsObject) {
                    break;
                }
                if (TypeConversion::toBoolean($result->get('done'))) {
                    break;
                }
                $val = $result->get('value');
                // Per spec: value must be a Number; reject all other types.
                if (!$val instanceof \PhpJs\Value\JsNumber) {
                    $typeErr = new \PhpJs\Exceptions\TypeError(
                        'Math.sumPrecise elements must be numbers',
                    );
                    break;
                }
                $values[] = (float) $val->value;
            }
            if ($typeErr !== null) {
                // IteratorClose: invoke iterator.return if present, then re-throw.
                $returnMethod = $iterator->get('return');
                if ($returnMethod instanceof \PhpJs\Value\JsFunction) {
                    try {
                        $returnMethod->call($iterator, []);
                    } catch (\Throwable) {
                        // Ignore close errors; spec says original error wins.
                    }
                }
                throw $typeErr;
            }

            if (empty($values)) {
                return new JsNumber(-0.0);
            }

            $hasNaN = false;
            $hasPosInf = false;
            $hasNegInf = false;
            $allNegZero = true;

            foreach ($values as $n) {
                if (is_nan($n)) {
                    $hasNaN = true;
                } elseif ($n === INF) {
                    $hasPosInf = true;
                } elseif ($n === -INF) {
                    $hasNegInf = true;
                }
                if (!($n === 0.0 && JsNumber::isNegativeZero($n))) {
                    $allNegZero = false;
                }
            }

            if ($hasNaN) {
                return new JsNumber(NAN);
            }
            if ($hasPosInf && $hasNegInf) {
                return new JsNumber(NAN);
            }
            if ($hasPosInf) {
                return new JsNumber(INF);
            }
            if ($hasNegInf) {
                return new JsNumber(-INF);
            }
            if ($allNegZero) {
                return new JsNumber(-0.0);
            }

            // Per spec: compute the exact mathematical sum, then round to nearest
            // double. We represent each finite double as an exact rational using
            // its IEEE-754 encoding, sum using bcmath, and convert back.
            $numerator = '0';
            $denominator = '1'; // always a power of 2.
            foreach ($values as $n) {
                [$num, $den] = self::doubleToRational($n);
                // sum + num/den = (sum*den + num*denominator) / (denominator*den)
                $newNum = bcadd(bcmul($numerator, $den, 0), bcmul($num, $denominator, 0), 0);
                $newDen = bcmul($denominator, $den, 0);
                $numerator = $newNum;
                $denominator = $newDen;
            }
            $finalResult = self::rationalToDouble($numerator, $denominator);

            if ($finalResult === 0.0) {
                $hasPositive = false;
                foreach ($values as $n) {
                    if ($n > 0.0 || ($n === 0.0 && !JsNumber::isNegativeZero($n))) {
                        $hasPositive = true;
                        break;
                    }
                }
                if (!$hasPositive) {
                    return new JsNumber(-0.0);
                }
            }

            return new JsNumber($finalResult);
        };
    }

    /**
     * Decompose a finite IEEE-754 double into an exact rational numerator/denominator.
     * Returns [numeratorStr, denominatorStr] where both are bcmath-safe integer strings.
     * denominator is always a power of two. Caller must handle NaN/Inf/-0 separately.
     */
    private static function doubleToRational(float $x): array
    {
        if ($x === 0.0) {
            return ['0', '1'];
        }
        $bits = unpack('Q', pack('d', $x))[1];
        $sign = ($bits >> 63) & 1;
        $exp = ($bits >> 52) & 0x7FF;
        $mantissa = $bits & 0xFFFFFFFFFFFFF;
        if ($exp === 0) {
            $trueExp = -1074;
            $trueM = $mantissa;
        } else {
            $trueExp = $exp - 1075;
            $trueM = 0x10000000000000 + $mantissa;
        }
        $mStr = (string) $trueM;
        if ($sign === 1) {
            $mStr = '-' . $mStr;
        }
        if ($trueExp >= 0) {
            $numerator = bcmul($mStr, bcpow('2', (string) $trueExp, 0), 0);
            return [$numerator, '1'];
        }
        $denominator = bcpow('2', (string) (-$trueExp), 0);
        return [$mStr, $denominator];
    }

    /**
     * Convert an exact rational numerator/denominator to the nearest IEEE-754 double,
     * with round-to-nearest-even. Uses round-to-nearest-even via a bit-exact pathway:
     * compute q = floor(|num| * 2^k / den) for large enough k, then normalize to 53
     * significant bits with proper tie-break.
     */
    private static function rationalToDouble(string $num, string $den): float
    {
        if ($num === '0') {
            return 0.0;
        }
        $negative = false;
        if ($num[0] === '-') {
            $negative = true;
            $num = substr($num, 1);
        }
        // Compute |num|/|den| with enough binary precision: shift num left by a large
        // constant K, compute q = floor(num << K / den) and the exact remainder.
        // K = 1200 comfortably covers the full double exponent range plus guard bits.
        $K = 1200;
        $shift = bcpow('2', (string) $K, 0);
        $scaled = bcmul($num, $shift, 0);
        $q = bcdiv($scaled, $den, 0);
        $rem = bcsub($scaled, bcmul($q, $den, 0), 0);
        $qBits = self::bcBitLength($q);
        // Normalize so q has exactly 53 bits.
        $binaryExp = $qBits - 53 - $K;
        if ($qBits > 53) {
            $drop = $qBits - 53;
            $dropPow = bcpow('2', (string) $drop, 0);
            $lowBits = bcmod($q, $dropPow, 0);
            $q = bcdiv($q, $dropPow, 0);
            // Round-to-nearest-even using the dropped low bits (plus the exact remainder).
            $half = bcdiv($dropPow, '2', 0);
            $cmp = bccomp($lowBits, $half, 0);
            $roundUp = false;
            if ($cmp > 0) {
                $roundUp = true;
            } elseif ($cmp === 0) {
                if (bccomp($rem, '0', 0) > 0 || bcmod($q, '2', 0) !== '0') {
                    $roundUp = true;
                }
            }
            if ($roundUp) {
                $q = bcadd($q, '1', 0);
                if (bccomp($q, bcpow('2', '53', 0), 0) >= 0) {
                    $q = bcdiv($q, '2', 0);
                    $binaryExp++;
                }
            }
        } elseif ($qBits < 53) {
            // Shift up: for a proper normalized double at this exponent the rational
            // must be small enough that q still fits in fewer bits — i.e. a subnormal.
            $up = 53 - $qBits;
            $q = bcmul($q, bcpow('2', (string) $up, 0), 0);
        }
        $trueExp = $binaryExp + 52;
        if ($trueExp >= 1024) {
            return $negative ? -INF : INF;
        }
        if ($trueExp < -1074) {
            return $negative ? -0.0 : 0.0;
        }
        if ($trueExp >= -1022) {
            $biasedExp = $trueExp + 1023;
            $stored = bcsub($q, bcpow('2', '52', 0), 0);
            $bits = ((int) $biasedExp << 52) | intval($stored);
        } else {
            $shiftDown = -1022 - $trueExp;
            $stored = bcdiv($q, bcpow('2', (string) $shiftDown, 0), 0);
            $bits = intval($stored);
        }
        if ($negative) {
            $bits |= (1 << 63);
        }
        return unpack('d', pack('Q', $bits))[1];
    }

    /** Bit length of a nonnegative bcmath integer string. */
    private static function bcBitLength(string $n): int
    {
        if ($n === '0') {
            return 0;
        }
        $len = 0;
        while (bccomp($n, '0', 0) > 0) {
            $n = bcdiv($n, '2', 0);
            $len++;
        }
        return $len;
    }
}
