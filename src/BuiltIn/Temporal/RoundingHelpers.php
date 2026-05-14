<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Temporal;

use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsBigInt;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;
use Phasis\BuiltIn\SymbolConstructor;

/**
 * Temporal helper section (RoundingHelpers). Composed into TemporalObject
 * via `use Temporal\RoundingHelpers;`. `self::` references resolve into
 * the composing class.
 */
trait RoundingHelpers
{
    // -----------------------------------------------------------------------
    // Helpers: rounding
    // -----------------------------------------------------------------------

    private static function roundToIncrement(int $value, int $increment, string $mode): int
    {
        if ($increment === 0) {
            return $value;
        }
        $sign = $value >= 0 ? 1 : -1;
        $abs = abs($value);
        $q = intdiv($abs, $increment);
        $r = $abs % $increment;
        if ($r === 0) {
            return $value;
        }
        $rounded = match ($mode) {
            'ceil' => $sign > 0 ? $q + 1 : $q,
            'floor' => $sign < 0 ? $q + 1 : $q,
            'trunc' => $q,
            'expand' => $q + 1,
            'halfExpand' => $r * 2 >= $increment ? $q + 1 : $q,
            'halfTrunc' => $r * 2 > $increment ? $q + 1 : $q,
            'halfCeil' => $r * 2 > $increment || ($r * 2 === $increment && $sign > 0) ? $q + 1 : $q,
            'halfFloor' => $r * 2 > $increment || ($r * 2 === $increment && $sign < 0) ? $q + 1 : $q,
            'halfEven' => $r * 2 > $increment || ($r * 2 === $increment && $q % 2 !== 0) ? $q + 1 : $q,
            default => $r * 2 >= $increment ? $q + 1 : $q,
        };
        return $sign * $rounded * $increment;
    }

    private static function roundBigIntNs(string $value, string $increment, string $mode): string
    {
        if ($increment === '0') {
            return $value;
        }
        // quotient = value / increment, round according to mode.
        $q = bcdiv($value, $increment, 20);
        $truncQ = bcdiv($value, $increment, 0);
        $isNonNeg = bccomp($value, '0', 0) >= 0;
        $rounded = match ($mode) {
            'ceil' => bcadd($q, '0', 0) === $q
                ? $q
                : ($isNonNeg ? bcadd($truncQ, '1', 0) : $truncQ),
            'floor' => $isNonNeg
                ? $truncQ
                : (bcsub($value, bcsub($increment, '1', 0), 0) !== $value
                    ? bcsub($truncQ, '1', 0) : $truncQ),
            'trunc' => $truncQ,
            default => bcdiv(
                bcadd(
                    bcmul($value, '2', 0),
                    $isNonNeg ? $increment : bcsub('0', $increment, 0),
                    0,
                ),
                bcmul($increment, '2', 0),
                0,
            ),
        };
        return bcmul($rounded, $increment, 0);
    }
}
