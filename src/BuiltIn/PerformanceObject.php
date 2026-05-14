<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsValue;

/**
 * Web Performance API surface — `globalThis.performance` exposing
 * `performance.now()` and `performance.timeOrigin`.
 *
 * Spec: https://w3c.github.io/hr-time/
 *
 * Notes:
 *  - `timeOrigin` is captured at install time (engine boot) as a Unix
 *    millisecond value with sub-millisecond fractional precision.
 *  - `now()` is monotonic and based on PHP's `hrtime(true)` (nanoseconds),
 *    so it is unaffected by wall-clock adjustments. The returned value is
 *    milliseconds elapsed since `timeOrigin`, as a double.
 */
class PerformanceObject
{
    public static function install(Environment $env): void
    {
        // Capture both clocks at engine start so timeOrigin and now() share
        // a consistent reference point.
        $startNs = hrtime(true);
        // microtime(true) returns a float-seconds Unix timestamp with
        // microsecond resolution — convert to milliseconds.
        $timeOriginMs = microtime(true) * 1000.0;

        $perf = new JsObject();

        $perf->defineOwnProperty(
            'timeOrigin',
            PropertyDescriptor::data(
                JsNumber::of($timeOriginMs),
                false,
                true,
                false,
            ),
        );

        $nowFn = JsFunction::fromCallable(
            'now',
            static function (JsValue $this_, array $args) use ($startNs): JsValue {
                $nowNs = hrtime(true);
                // Nanoseconds -> milliseconds as a double. PHP's int handles
                // 64-bit values on 64-bit builds; on 32-bit builds hrtime(true)
                // already promotes to float, so the subtraction stays safe.
                $deltaNs = $nowNs - $startNs;
                $ms = ((float) $deltaNs) / 1_000_000.0;
                return JsNumber::of($ms);
            },
            0,
        );

        $perf->defineOwnProperty(
            'now',
            PropertyDescriptor::data($nowFn, true, false, true),
        );

        $env->defineVar('performance', $perf);
    }
}
