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

            while (true) {
                $result = $nextMethod->call($iterator, []);
                if (!$result instanceof \PhpJs\Value\JsObject) {
                    break;
                }
                if (TypeConversion::toBoolean($result->get('done'))) {
                    break;
                }
                $val = $result->get('value');
                $n = TypeConversion::toNumber($val);
                $values[] = (float) $n;
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

            // Compensated summation (Neumaier variant).
            $sum = 0.0;
            $compensation = 0.0;
            foreach ($values as $n) {
                $t = $sum + $n;
                if (abs($sum) >= abs($n)) {
                    $compensation += ($sum - $t) + $n;
                } else {
                    $compensation += ($n - $t) + $sum;
                }
                $sum = $t;
            }
            $finalResult = $sum + $compensation;

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
}
