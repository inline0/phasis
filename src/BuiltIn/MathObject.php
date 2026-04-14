<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Object\PropertyDescriptor;
use PhpJs\Runtime\Environment;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

class MathObject
{
    public static function install(Environment $env): void
    {
        $math = new JsObject();

        // Constants.
        $math->defineOwnProperty('PI', PropertyDescriptor::data(new JsNumber(M_PI), writable: false, enumerable: false, configurable: false));
        $math->defineOwnProperty('E', PropertyDescriptor::data(new JsNumber(M_E), writable: false, enumerable: false, configurable: false));
        $math->defineOwnProperty('LN2', PropertyDescriptor::data(new JsNumber(M_LN2), writable: false, enumerable: false, configurable: false));
        $math->defineOwnProperty('LN10', PropertyDescriptor::data(new JsNumber(M_LN10), writable: false, enumerable: false, configurable: false));
        $math->defineOwnProperty('LOG2E', PropertyDescriptor::data(new JsNumber(M_LOG2E), writable: false, enumerable: false, configurable: false));
        $math->defineOwnProperty('LOG10E', PropertyDescriptor::data(new JsNumber(M_LOG10E), writable: false, enumerable: false, configurable: false));
        $math->defineOwnProperty('SQRT2', PropertyDescriptor::data(new JsNumber(M_SQRT2), writable: false, enumerable: false, configurable: false));
        $math->defineOwnProperty('SQRT1_2', PropertyDescriptor::data(
            new JsNumber(M_SQRT1_2),
            writable: false,
            enumerable: false,
            configurable: false,
        ));

        // Single-argument math functions.
        $math->set('abs', JsFunction::fromCallable('abs', self::singleArgFn('abs'), 1));
        $math->set('ceil', JsFunction::fromCallable('ceil', self::singleArgFn('ceil'), 1));
        $math->set('floor', JsFunction::fromCallable('floor', self::singleArgFn('floor'), 1));
        $math->set('round', JsFunction::fromCallable('round', self::roundFn(), 1));
        $math->set('trunc', JsFunction::fromCallable('trunc', self::truncFn(), 1));
        $math->set('sqrt', JsFunction::fromCallable('sqrt', self::singleArgFn('sqrt'), 1));
        $math->set('cbrt', JsFunction::fromCallable('cbrt', self::cbrtFn(), 1));
        $math->set('log', JsFunction::fromCallable('log', self::singleArgFn('log'), 1));
        $math->set('log2', JsFunction::fromCallable('log2', self::log2Fn(), 1));
        $math->set('log10', JsFunction::fromCallable('log10', self::singleArgFn('log10'), 1));
        $math->set('sin', JsFunction::fromCallable('sin', self::singleArgFn('sin'), 1));
        $math->set('cos', JsFunction::fromCallable('cos', self::singleArgFn('cos'), 1));
        $math->set('tan', JsFunction::fromCallable('tan', self::singleArgFn('tan'), 1));
        $math->set('sign', JsFunction::fromCallable('sign', self::signFn(), 1));
        $math->set('fround', JsFunction::fromCallable('fround', self::froundFn(), 1));

        $math->set('exp', JsFunction::fromCallable('exp', self::singleArgFn('exp'), 1));
        $math->set('expm1', JsFunction::fromCallable('expm1', self::singleArgFn('expm1'), 1));
        $math->set('asin', JsFunction::fromCallable('asin', self::singleArgFn('asin'), 1));
        $math->set('acos', JsFunction::fromCallable('acos', self::singleArgFn('acos'), 1));
        $math->set('atan', JsFunction::fromCallable('atan', self::singleArgFn('atan'), 1));
        $math->set('sinh', JsFunction::fromCallable('sinh', self::singleArgFn('sinh'), 1));
        $math->set('cosh', JsFunction::fromCallable('cosh', self::singleArgFn('cosh'), 1));
        $math->set('tanh', JsFunction::fromCallable('tanh', self::singleArgFn('tanh'), 1));
        $math->set('asinh', JsFunction::fromCallable('asinh', self::singleArgFn('asinh'), 1));
        $math->set('acosh', JsFunction::fromCallable('acosh', self::singleArgFn('acosh'), 1));
        $math->set('atanh', JsFunction::fromCallable('atanh', self::singleArgFn('atanh'), 1));
        $math->set('log1p', JsFunction::fromCallable('log1p', self::singleArgFn('log1p'), 1));
        $math->set('atan2', JsFunction::fromCallable('atan2', function (JsValue $this_, array $args): JsValue {
            $y = isset($args[0]) ? TypeConversion::toNumber($args[0]) : NAN;
            $x = isset($args[1]) ? TypeConversion::toNumber($args[1]) : NAN;
            return new JsNumber(atan2($y, $x));
        }, 2));
        $math->set('clz32', JsFunction::fromCallable('clz32', function (JsValue $this_, array $args): JsValue {
            $x = isset($args[0]) ? TypeConversion::toUint32($args[0]) : 0;
            if ($x === 0) {
                return new JsNumber(32.0);
            }
            return new JsNumber((float) (31 - (int) floor(log($x, 2))));
        }, 1));
        $math->set('imul', JsFunction::fromCallable('imul', function (JsValue $this_, array $args): JsValue {
            $a = isset($args[0]) ? TypeConversion::toInt32($args[0]) : 0;
            $b = isset($args[1]) ? TypeConversion::toInt32($args[1]) : 0;
            $result = (int) (($a * $b) % 4294967296);
            if ($result >= 2147483648) {
                $result -= 4294967296;
            }
            return new JsNumber((float) $result);
        }, 2));

        // Multi-argument or special functions.
        $math->set('pow', JsFunction::fromCallable('pow', self::powFn(), 2));
        $math->set('max', JsFunction::fromCallable('max', self::maxFn(), 2));
        $math->set('min', JsFunction::fromCallable('min', self::minFn(), 2));
        $math->set('random', JsFunction::fromCallable('random', self::randomFn(), 0));
        $math->set('hypot', JsFunction::fromCallable('hypot', self::hypotFn(), 2));

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
            $floored = floor($x);
            $frac = $x - $floored;
            if ($frac === 0.5) {
                return new JsNumber($floored + 1);
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
            if ($base === 0.0 && $exp < 0) {
                return new JsNumber(INF);
            }
            return new JsNumber(@pow($base, $exp));
        };
    }

    private static function maxFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (empty($args)) {
                return new JsNumber(-INF);
            }
            $result = -INF;
            foreach ($args as $arg) {
                $n = TypeConversion::toNumber($arg);
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
            $result = INF;
            foreach ($args as $arg) {
                $n = TypeConversion::toNumber($arg);
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
            $hasInf = false;
            $sum = 0.0;
            foreach ($args as $arg) {
                $n = TypeConversion::toNumber($arg);
                if (is_nan($n)) {
                    return new JsNumber(NAN);
                }
                if (is_infinite($n)) {
                    $hasInf = true;
                }
                $sum += $n * $n;
            }
            if ($hasInf) {
                return new JsNumber(INF);
            }
            return new JsNumber(sqrt($sum));
        };
    }
}
