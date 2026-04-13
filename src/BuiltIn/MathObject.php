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
        $math->set('abs', JsFunction::fromCallable('abs', self::singleArgFn('abs')));
        $math->set('ceil', JsFunction::fromCallable('ceil', self::singleArgFn('ceil')));
        $math->set('floor', JsFunction::fromCallable('floor', self::singleArgFn('floor')));
        $math->set('round', JsFunction::fromCallable('round', self::roundFn()));
        $math->set('trunc', JsFunction::fromCallable('trunc', self::truncFn()));
        $math->set('sqrt', JsFunction::fromCallable('sqrt', self::singleArgFn('sqrt')));
        $math->set('cbrt', JsFunction::fromCallable('cbrt', self::cbrtFn()));
        $math->set('log', JsFunction::fromCallable('log', self::singleArgFn('log')));
        $math->set('log2', JsFunction::fromCallable('log2', self::log2Fn()));
        $math->set('log10', JsFunction::fromCallable('log10', self::singleArgFn('log10')));
        $math->set('sin', JsFunction::fromCallable('sin', self::singleArgFn('sin')));
        $math->set('cos', JsFunction::fromCallable('cos', self::singleArgFn('cos')));
        $math->set('tan', JsFunction::fromCallable('tan', self::singleArgFn('tan')));
        $math->set('sign', JsFunction::fromCallable('sign', self::signFn()));
        $math->set('fround', JsFunction::fromCallable('fround', self::froundFn()));

        // Multi-argument or special functions.
        $math->set('pow', JsFunction::fromCallable('pow', self::powFn()));
        $math->set('max', JsFunction::fromCallable('max', self::maxFn()));
        $math->set('min', JsFunction::fromCallable('min', self::minFn()));
        $math->set('random', JsFunction::fromCallable('random', self::randomFn()));
        $math->set('hypot', JsFunction::fromCallable('hypot', self::hypotFn()));

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
            return new JsNumber(pow($base, $exp));
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
