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
        return self::roundNs($value, $increment, $mode);
    }
}
