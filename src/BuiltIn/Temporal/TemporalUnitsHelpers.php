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
 * Temporal helper section (TemporalUnitsHelpers). Composed into TemporalObject
 * via `use Temporal\TemporalUnitsHelpers;`. `self::` references resolve into
 * the composing class.
 */
trait TemporalUnitsHelpers
{
    // -----------------------------------------------------------------------
    // Helpers: temporal units
    // -----------------------------------------------------------------------

    private static function canonicalTemporalUnit(string $unit): string
    {
        return match ($unit) {
            'years', 'year' => 'year',
            'months', 'month' => 'month',
            'weeks', 'week' => 'week',
            'days', 'day' => 'day',
            'hours', 'hour' => 'hour',
            'minutes', 'minute' => 'minute',
            'seconds', 'second' => 'second',
            'milliseconds', 'millisecond' => 'millisecond',
            'microseconds', 'microsecond' => 'microsecond',
            'nanoseconds', 'nanosecond' => 'nanosecond',
            default => throw new RangeError("Invalid unit: {$unit}"),
        };
    }

    private static function temporalUnitToNs(string $unit): string
    {
        return match ($unit) {
            'hour' => '3600000000000',
            'minute' => '60000000000',
            'second' => '1000000000',
            'millisecond' => '1000000',
            'microsecond' => '1000',
            'nanosecond' => '1',
            'day' => '86400000000000',
            default => '1',
        };
    }

    /**
     * @param array<mixed> $allowed
     */
    private static function getTemporalUnit(JsObject $options, string $key, array $allowed, bool $required): string
    {
        $val = $options->get($key);
        if ($val instanceof JsUndefined) {
            if ($required) {
                throw new RangeError("{$key} is required");
            }
            return '';
        }
        $str = TypeConversion::toString($val);
        $canonical = self::canonicalTemporalUnit($str);
        if (!in_array($canonical, $allowed, true)) {
            throw new RangeError("Invalid {$key}: {$str}");
        }
        return $canonical;
    }

    private static function getOptionsObject(JsValue $options): JsValue
    {
        if ($options instanceof JsUndefined) {
            return new JsObject();
        }
        if ($options instanceof JsObject) {
            return $options;
        }
        throw new TypeError('options must be an object');
    }

    /**
     * Read difference options (since/until) in alphabetical order and return a
     * fresh options object with coerced primitive values. If $swapRoundingMode
     * is true, invert directional rounding modes for since()'s negate-after-compute
     * pattern. Reading each option in strict alphabetical order, with coercion
     * inline, is required by spec order-of-operations tests.
     */
    private static function readDifferenceOptionsAlphabetical(JsValue $options, bool $swapRoundingMode): JsObject
    {
        $newOpts = new JsObject();
        if (!$options instanceof JsObject) {
            return $newOpts;
        }
        $lu = $options->get('largestUnit');
        if (!($lu instanceof JsUndefined)) {
            $luStr = TypeConversion::toString($lu);
            $newOpts->set('largestUnit', new JsString($luStr));
        }
        $ri = $options->get('roundingIncrement');
        if (!($ri instanceof JsUndefined)) {
            $riNum = TypeConversion::toNumber($ri);
            $newOpts->set('roundingIncrement', JsNumber::of($riNum));
        }
        $rm = $options->get('roundingMode');
        if (!($rm instanceof JsUndefined)) {
            $rmStr = TypeConversion::toString($rm);
            if ($swapRoundingMode) {
                $rmStr = match ($rmStr) {
                    'ceil' => 'floor',
                    'floor' => 'ceil',
                    'halfCeil' => 'halfFloor',
                    'halfFloor' => 'halfCeil',
                    default => $rmStr,
                };
            }
            $newOpts->set('roundingMode', new JsString($rmStr));
        }
        $su = $options->get('smallestUnit');
        if (!($su instanceof JsUndefined)) {
            $suStr = TypeConversion::toString($su);
            $newOpts->set('smallestUnit', new JsString($suStr));
        }
        return $newOpts;
    }

    private static function getFractionalSecondDigits(JsValue $options): string|int
    {
        if (!$options instanceof JsObject) {
            return 'auto';
        }
        $v = $options->get('fractionalSecondDigits');
        if ($v instanceof JsUndefined) {
            return 'auto';
        }
        if ($v instanceof JsString && $v->value === 'auto') {
            return 'auto';
        }
        if ($v instanceof JsNumber) {
            if (!is_finite($v->value) || is_nan($v->value)) {
                throw new RangeError('fractionalSecondDigits must be 0-9 or auto');
            }
            $n = (int) floor($v->value);
            if ($n < 0 || $n > 9) {
                throw new RangeError('fractionalSecondDigits must be 0-9 or auto');
            }
            return $n;
        }
        $str = TypeConversion::toString($v);
        if ($str === 'auto') {
            return 'auto';
        }
        throw new RangeError('fractionalSecondDigits must be 0-9 or auto');
    }

    private static function getRoundingMode(JsValue $options, string $fallback): string
    {
        if (!$options instanceof JsObject) {
            return $fallback;
        }
        $v = $options->get('roundingMode');
        if ($v instanceof JsUndefined) {
            return $fallback;
        }
        $mode = TypeConversion::toString($v);
        $valid = [
            'ceil', 'floor', 'expand', 'trunc',
            'halfCeil', 'halfFloor', 'halfExpand', 'halfTrunc', 'halfEven',
        ];
        if (!in_array($mode, $valid, true)) {
            throw new RangeError("Invalid roundingMode: {$mode}");
        }
        return $mode;
    }

    private static function getRoundingIncrement(JsObject $options): int
    {
        $v = $options->get('roundingIncrement');
        if ($v instanceof JsUndefined) {
            return 1;
        }
        $num = TypeConversion::toNumber($v);
        if (!is_finite($num)) {
            throw new RangeError('roundingIncrement must be finite');
        }
        $n = (int) $num;
        if ($n < 1) {
            throw new RangeError('roundingIncrement must be a positive integer');
        }
        if ($n > 1000000000) {
            throw new RangeError('roundingIncrement out of range');
        }
        return $n;
    }

    private static function getOverflow(JsValue $options): string
    {
        if (!$options instanceof JsObject) {
            return 'constrain';
        }
        $v = $options->get('overflow');
        if ($v instanceof JsUndefined) {
            return 'constrain';
        }
        $str = TypeConversion::toString($v);
        if ($str !== 'constrain' && $str !== 'reject') {
            throw new RangeError("Invalid overflow: {$str}");
        }
        return $str;
    }

    /**
     * Resolve a calendar identifier string. Accepts IANA calendar names or ISO datetime strings
     * (from which the calendar defaults to 'iso8601'). Returns the resolved calendar ID.
     */
    private static function resolveCalendarId(string $cal, bool $allowAnnotations = false): string
    {
        if ($cal === '') {
            throw new RangeError('empty string is not a valid calendar ID');
        }
        // Known valid calendars from the Unicode CLDR.
        $known = [
            'iso8601', 'gregory', 'japanese', 'buddhist', 'chinese', 'coptic',
            'dangi', 'ethioaa', 'ethiopic', 'hebrew', 'indian', 'islamic',
            'islamic-umalqura', 'islamic-tbla', 'islamic-civil', 'islamic-rgsa',
            'islamicc', 'persian', 'roc',
        ];
        // Canonicalize CLDR aliases to their preferred form so the
        // resolved [[Calendar]] slot matches what V8 returns.
        static $aliases = [
            'islamicc' => 'islamic-civil',
            'ethiopic-amete-alem' => 'ethioaa',
            'gregorian' => 'gregory',
        ];
        if (isset($aliases[$cal])) {
            return $aliases[$cal];
        }
        if (in_array($cal, $known, true)) {
            return $cal;
        }
        // Try to parse as ISO datetime string. If it parses, extract calendar (default iso8601).
        if (preg_match('/^\d{4}/', $cal) || preg_match('/^[+-]\d{4,6}/', $cal)) {
            // Reject minus zero year.
            if (preg_match('/^-0{4,6}-/', $cal)) {
                throw new RangeError("reject minus zero as extended year: {$cal}");
            }
            // Per spec: ISO strings with annotations are NOT valid as direct calendar IDs
            // (constructor args), but are valid in property bags.
            if (preg_match('/\[/', $cal)) {
                if (!$allowAnnotations) {
                    throw new RangeError(
                        "ISO string with annotations is not a valid calendar: {$cal}"
                    );
                }
                // Extract calendar from annotation.
                if (preg_match('/\[u-ca=([^\]]+)\]/', $cal, $cm)) {
                    $extracted = strtolower($cm[1]);
                    if (in_array($extracted, $known, true)) {
                        return $extracted;
                    }
                    throw new RangeError("Invalid calendar: {$extracted}");
                }
                return 'iso8601';
            }
            // Default to iso8601 for valid-looking date strings without annotations.
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $cal) || preg_match('/^[+-]\d{4,6}-\d{2}-\d{2}/', $cal)) {
                return 'iso8601';
            }
        }
        // Also accept MM-DD, --MM-DD, YYYY-MM as valid temporal strings -> iso8601.
        if (preg_match('/^\d{2}-\d{2}/', $cal) || preg_match('/^--\d{2}-\d{2}/', $cal)) {
            if (preg_match('/\[u-ca=([^\]]+)\]/', $cal, $cm)) {
                return strtolower($cm[1]);
            }
            return 'iso8601';
        }
        if (preg_match('/^\d{4}-\d{2}$/', $cal) || preg_match('/^\d{4}-\d{2}\[/', $cal)) {
            if (preg_match('/\[u-ca=([^\]]+)\]/', $cal, $cm)) {
                return strtolower($cm[1]);
            }
            return 'iso8601';
        }
        throw new RangeError("Invalid calendar: {$cal}");
    }


    /**
     * Convert a JsValue from a property bag's 'calendar' property to a calendar ID string.
     * Per spec, null/boolean/number/bigint/symbol/object throw TypeError.
     */
    private static function toCalendarSlotValue(JsValue $calVal, bool $allowAnnotations = true): string
    {
        // Temporal objects with [[Calendar]] slot: extract directly.
        if ($calVal instanceof JsObject && $calVal->has('[[Calendar]]')) {
            return self::getSlotString($calVal, '[[Calendar]]');
        }
        if ($calVal instanceof JsNull) {
            throw new TypeError('null is not a valid calendar');
        }
        if ($calVal instanceof JsBoolean) {
            throw new TypeError('boolean is not a valid calendar');
        }
        if ($calVal instanceof JsNumber) {
            throw new TypeError('number is not a valid calendar');
        }
        if ($calVal instanceof JsBigInt) {
            throw new TypeError('bigint is not a valid calendar');
        }
        if ($calVal instanceof JsObject) {
            throw new TypeError('object is not a valid calendar');
        }
        if (!$calVal instanceof JsString) {
            throw new TypeError('Cannot convert value to a valid calendar string');
        }
        $cal = strtolower(TypeConversion::toString($calVal));
        if ($cal === '') {
            throw new RangeError('empty string is not a valid calendar ID');
        }
        return self::resolveCalendarId($cal, $allowAnnotations);
    }

    /** Convert a JsValue to an integer for Temporal fields, rejecting Infinity and NaN. */
    private static function toTemporalInteger(JsValue $v, string $name): int
    {
        $num = TypeConversion::toNumber($v);
        if (is_nan($num) || is_infinite($num)) {
            throw new RangeError("{$name} property cannot be " . ($num > 0 ? 'Infinity' : ($num < 0 ? '-Infinity' : 'NaN')));
        }
        return (int) $num;
    }

    /**
     * Constrain time fields to valid ranges.
     *
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}
     */
    private static function constrainISOTime(int $h, int $min, int $s, int $ms, int $us, int $ns): array
    {
        $h = max(0, min(23, $h));
        $min = max(0, min(59, $min));
        $s = max(0, min(59, $s));
        $ms = max(0, min(999, $ms));
        $us = max(0, min(999, $us));
        $ns = max(0, min(999, $ns));
        return [$h, $min, $s, $ms, $us, $ns];
    }

    /** Reject time fields outside valid ranges. */
    private static function rejectISOTime(int $h, int $min, int $s, int $ms, int $us, int $ns): void
    {
        if ($h < 0 || $h > 23) {
            throw new RangeError("Invalid hour: {$h}");
        }
        if ($min < 0 || $min > 59) {
            throw new RangeError("Invalid minute: {$min}");
        }
        if ($s < 0 || $s > 59) {
            throw new RangeError("Invalid second: {$s}");
        }
        if ($ms < 0 || $ms > 999) {
            throw new RangeError("Invalid millisecond: {$ms}");
        }
        if ($us < 0 || $us > 999) {
            throw new RangeError("Invalid microsecond: {$us}");
        }
        if ($ns < 0 || $ns > 999) {
            throw new RangeError("Invalid nanosecond: {$ns}");
        }
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function constrainISODate(int $y, int $m, int $d): array
    {
        // Months <= 0 are always invalid even with constrain.
        if ($m < 1) {
            throw new RangeError("Invalid month: {$m}");
        }
        $m = min(12, $m);
        // Days <= 0 are always invalid even with constrain.
        if ($d < 1) {
            throw new RangeError("Invalid day: {$d}");
        }
        $dim = self::isoDaysInMonth($y, $m);
        $d = min($dim, $d);
        // Even with constrain, must be in representable range.
        if ($y < self::ISO_YEAR_MIN || $y > self::ISO_YEAR_MAX) {
            throw new RangeError("Year out of range: {$y}");
        }
        if ($y === self::ISO_YEAR_MIN && ($m < 4 || ($m === 4 && $d < 19))) {
            throw new RangeError("Date outside representable range");
        }
        if ($y === self::ISO_YEAR_MAX && ($m > 9 || ($m === 9 && $d > 13))) {
            throw new RangeError("Date outside representable range");
        }
        return [$y, $m, $d];
    }
}
