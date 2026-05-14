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
 * Temporal helper section (InstantParsingHelpers). Composed into TemporalObject
 * via `use Temporal\InstantParsingHelpers;`. `self::` references resolve into
 * the composing class.
 */
trait InstantParsingHelpers
{
    // -----------------------------------------------------------------------
    // Helpers: Instant string parsing
    // -----------------------------------------------------------------------

    private static function toInstantNs(JsValue $item): string
    {
        if ($item instanceof JsObject && $item->has('[[EpochNanoseconds]]')) {
            return self::requireInstant($item);
        }
        if ($item instanceof JsObject && $item->has('[[IsZonedDateTime]]')) {
            return self::getSlotString($item, '[[EpochNanoseconds]]');
        }
        if ($item instanceof JsUndefined || $item instanceof JsNull) {
            throw new TypeError('Cannot convert undefined/null to Instant');
        }
        if ($item instanceof JsNumber || $item instanceof \Phasis\Value\JsBigInt) {
            throw new TypeError('Cannot convert number to Temporal.Instant');
        }
        if ($item instanceof JsBoolean) {
            throw new TypeError('Cannot convert boolean to Temporal.Instant');
        }
        if ($item instanceof \Phasis\Value\JsSymbol) {
            throw new TypeError('Cannot convert Symbol to Temporal.Instant');
        }
        $str = TypeConversion::toString($item);
        return self::parseInstantString($str);
    }

    private static function parseInstantString(string $str): string
    {
        [$str] = self::normalizeTemporalString($str);
        // Reject extended year without sign.
        if (preg_match('/^\d{5,}-/', $str)) {
            throw new RangeError("Extended year requires sign: {$str}");
        }
        // Reject -000000.
        if (preg_match('/^-0{4,6}[-\d]/', $str)) {
            throw new RangeError("reject minus zero year: {$str}");
        }
        // ISO 8601 with required timezone offset.
        // Supports date with hyphens (YYYY-MM-DD) or without (YYYYMMDD).
        // Supports sub-minute offsets (+HH:MM:SS.fractional).
        $datePart = '([+-]?\d{4,6})(?:-(\d{2})-(\d{2})|(\d{2})(\d{2}))';
        $timePart = '(\d{2})(?::?(\d{2})(?::?(\d{2})(?:[.,](\d{1,9}))?)?)?' ;
        $tzPart = '([Zz]|[+-]\d{2}(?::?\d{2}(?::?\d{2}(?:[.,]\d{1,9})?)?)?)';
        $pattern = "/^{$datePart}[Tt ]{$timePart}{$tzPart}(?:\\[.*?\\])*\$/";
        if (!preg_match($pattern, $str, $m)) {
            throw new RangeError("Invalid Instant string: {$str}");
        }

        $year = (int) $m[1];
        // Hyphenated date or compact date.
        if ($m[2] !== '') {
            $month = (int) $m[2];
            $day = (int) $m[3];
        } else {
            $month = (int) $m[4];
            $day = (int) $m[5];
        }
        // Validate date.
        if ($month < 1 || $month > 12 || $day < 1 || $day > self::isoDaysInMonth($year, $month)) {
            throw new RangeError("Invalid Instant date: {$str}");
        }
        $hour = (int) $m[6];
        $min = $m[7] !== '' ? (int) $m[7] : 0;
        $sec = $m[8] !== '' ? (int) $m[8] : 0;
        // Validate time.
        if ($hour > 23 || $min > 59 || $sec > 60) {
            throw new RangeError("Invalid Instant time: {$str}");
        }
        // Handle leap second: clamp to 59.
        if ($sec === 60) {
            $sec = 59;
        }
        $frac = $m[9] !== '' ? str_pad($m[9], 9, '0') : '000000000';
        $tz = $m[10];

        // Offset nanoseconds (supports sub-minute offsets).
        $offsetNs = '0';
        if (strtoupper($tz) !== 'Z') {
            $sign = $tz[0] === '-' ? -1 : 1;
            $tzBody = preg_replace('/[^0-9.,]/', '', substr($tz, 1));
            // Re-parse with colons removed. Format: HHMMSS.fractional or HHMM or HH.
            $tzDigits = preg_replace('/[.,].*$/', '', $tzBody);
            $tzH = (int) substr($tzDigits, 0, 2);
            $tzM = strlen($tzDigits) >= 4 ? (int) substr($tzDigits, 2, 2) : 0;
            $tzS = strlen($tzDigits) >= 6 ? (int) substr($tzDigits, 4, 2) : 0;
            if ($tzH > 23 || $tzM > 59 || $tzS > 59) {
                throw new RangeError("Invalid UTC offset: {$str}");
            }
            // Sub-second fractional offset.
            $tzFrac = '0';
            if (preg_match('/[.,](\d+)/', $tzBody, $fm)) {
                $tzFrac = str_pad($fm[1], 9, '0');
            }
            $offsetSec = $sign * ($tzH * 3600 + $tzM * 60 + $tzS);
            $offsetNs = bcmul((string) $offsetSec, '1000000000', 0);
            if ($tzFrac !== '0' && $tzFrac !== '000000000') {
                $subNsOffset = $sign > 0 ? $tzFrac : '-' . $tzFrac;
                $offsetNs = bcadd($offsetNs, $subNsOffset, 0);
            }
        }

        // Convert to epoch nanoseconds.
        try {
            $dt = new \DateTimeImmutable('2000-01-01 00:00:00', new \DateTimeZone('UTC'));
            $dt = $dt->setDate($year, $month, $day);
            $dt = $dt->setTime($hour, $min, $sec);
            $epochSec = $dt->format('U');
        } catch (\Throwable) {
            throw new RangeError("Invalid Instant string: {$str}");
        }

        $epochNs = bcmul($epochSec, '1000000000', 0);
        // Add fractional nanoseconds.
        $subSecNs = ltrim($frac, '0') ?: '0';
        if ($subSecNs !== '0') {
            $subSecNs = $frac; // Use the full padded string.
            $epochNs = bcadd($epochNs, $subSecNs, 0);
        }
        // Subtract the offset.
        $epochNs = bcsub($epochNs, $offsetNs, 0);

        self::validateInstantRange($epochNs);

        return $epochNs;
    }

    private static function instantToString(
        string $ns,
        string|int $fractionalSecondDigits = 'auto',
        string $roundingMode = 'trunc',
        bool $omitSeconds = false,
    ): string {
        // Split ns into seconds and sub-second nanoseconds.
        $negative = isset($ns[0]) && $ns[0] === '-';
        $abs = $negative ? substr($ns, 1) : $ns;

        $sec = bcdiv($abs, '1000000000', 0);
        $subNs = bcsub($abs, bcmul($sec, '1000000000', 0), 0);

        if ($negative && $subNs !== '0') {
            $sec = bcadd($sec, '1', 0);
            $subNs = bcsub('1000000000', $subNs, 0);
        }

        $epochSec = $negative ? '-' . $sec : $sec;

        try {
            $dt = new \DateTimeImmutable('@' . $epochSec);
            $dt = $dt->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return 'Invalid Instant';
        }

        $year = (int) $dt->format('Y');
        if ($omitSeconds) {
            $dateStr = self::padISOYear($year) . $dt->format('-m-d\TH:i');
            return $dateStr . 'Z';
        }
        $dateStr = self::padISOYear($year) . $dt->format('-m-d\TH:i:s');

        $subNsPadded = str_pad($subNs, 9, '0', STR_PAD_LEFT);
        $fracStr = self::formatSubSecond($subNsPadded, $fractionalSecondDigits);

        return $dateStr . $fracStr . 'Z';
    }

    private static function instantToStringInZone(
        string $ns,
        string $timeZone,
        string|int $fractionalSecondDigits = 'auto',
        string $roundingMode = 'trunc',
    ): string {
        $parts = self::epochNsToISOParts($ns, $timeZone);
        $dateStr = self::padISOYear($parts['year']) . '-' . self::pad2($parts['month']) . '-' . self::pad2($parts['day']);
        $timeStr = self::formatISOTime(
            $parts['hour'],
            $parts['minute'],
            $parts['second'],
            $parts['millisecond'],
            $parts['microsecond'],
            $parts['nanosecond'],
            $fractionalSecondDigits,
            $roundingMode
        );
        $offsetStr = self::timeZoneOffsetString($ns, $timeZone);
        return "{$dateStr}T{$timeStr}{$offsetStr}";
    }
}
