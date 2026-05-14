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
 * Temporal helper section (FormattingHelpers). Composed into TemporalObject
 * via `use Temporal\FormattingHelpers;`. `self::` references resolve into
 * the composing class.
 */
trait FormattingHelpers
{
    // -----------------------------------------------------------------------
    // Helpers: formatting
    // -----------------------------------------------------------------------

    private static function padISOYear(int $year): string
    {
        if ($year >= 0 && $year <= 9999) {
            return str_pad((string) $year, 4, '0', STR_PAD_LEFT);
        }
        $sign = $year >= 0 ? '+' : '-';
        return $sign . str_pad((string) abs($year), 6, '0', STR_PAD_LEFT);
    }

    /** Check if a calendar identifier is valid for our implementation. */
    private static function isValidCalendar(string $cal): bool
    {
        // We only support iso8601 and IANA calendar names.
        // Per spec, calendar identifiers must be ASCII lowercase
        // and match the syntax of Unicode BCP 47 type subtags.
        if ($cal === 'iso8601') {
            return true;
        }
        // Allow known IANA calendar names.
        $known = [
            'buddhist', 'chinese', 'coptic', 'dangi', 'ethioaa',
            'ethiopic', 'gregory', 'hebrew', 'indian', 'islamic',
            'islamic-umalqura', 'islamic-tbla', 'islamic-civil',
            'islamic-rgsa', 'islamicc', 'japanese', 'persian', 'roc',
        ];
        return in_array($cal, $known, true);
    }

    /**
     * Validate monthCode syntax only (M followed by 2 digits, optionally L).
     * Rejects purely malformed codes like "m01", "M1", "L99M".
     * Returns [month_number, is_leap] without checking calendar validity.
     *
     * @return array{0: int, 1: bool}
     */
    private static function parseMonthCodeSyntax(string $mc): array
    {
        if (!preg_match('/^M(\d{2})(L?)$/', $mc, $mcm)) {
            throw new RangeError("Invalid monthCode: {$mc}");
        }
        return [(int) $mcm[1], $mcm[2] === 'L'];
    }

    /**
     * Parse and fully validate a monthCode string for ISO 8601 calendar.
     * Returns month number 1-12.
     */
    private static function parseMonthCode(string $mc, string $cal = 'iso8601'): int
    {
        [$month, $isLeap] = self::parseMonthCodeSyntax($mc);
        // Coptic / Ethiopic / EthioAA have a 13th epagomenal month;
        // their valid monthCodes are M01..M13. Other calendars cap at 12.
        $maxMonth = in_array($cal, ['coptic', 'ethiopic', 'ethioaa'], true) ? 13 : 12;
        if ($month < 1 || $month > $maxMonth) {
            throw new RangeError("monthCode '{$mc}' is not valid for calendar '{$cal}'");
        }
        if ($isLeap) {
            // Leap months exist only in lunisolar calendars.
            static $lunisolarCals = ['hebrew', 'chinese', 'dangi'];
            if (!in_array($cal, $lunisolarCals, true)) {
                throw new RangeError(
                    "monthCode '{$mc}' leap-month suffix is not valid for calendar '{$cal}'",
                );
            }
            // Hebrew: only M05L (Adar I) exists. Reject M01L-M04L,
            // M06L-M12L unconditionally.
            if ($cal === 'hebrew' && $month !== 5) {
                throw new RangeError(
                    "monthCode '{$mc}' is not a valid hebrew leap-month code",
                );
            }
        }
        return $month;
    }

    private static function pad2(int $n): string
    {
        return str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Normalize a Temporal ISO string: replace Unicode minus, validate annotations.
     * Returns the normalized string. Throws RangeError for critical unknown annotations
     * or duplicate critical calendar annotations.
     *
     * @return array{0: string, 1: string} [normalized string, calendar ID]
     */
    private static function normalizeTemporalString(string $str): array
    {
        // Reject Unicode minus sign (U+2212) per spec.
        if (str_contains($str, "\xE2\x88\x92")) {
            throw new RangeError("Non-ASCII minus sign is not acceptable: {$str}");
        }
        // Parse annotations.
        $cal = 'iso8601';
        $calCount = 0;
        $hasCriticalCal = false;
        preg_match_all('/\[(!?)([^\]]+)\]/', $str, $anns, PREG_SET_ORDER);
        foreach ($anns as $ann) {
            $critical = $ann[1] === '!';
            $content = $ann[2];
            if (str_starts_with($content, 'u-ca=')) {
                $calCount++;
                if ($calCount === 1) {
                    // Use FIRST calendar annotation per spec.
                    $cal = strtolower(substr($content, 5));
                }
                if ($critical) {
                    $hasCriticalCal = true;
                }
            } elseif (str_contains($content, '=')) {
                // Per spec: annotation keys must be lowercase ASCII.
                $key = substr($content, 0, (int) strpos($content, '='));
                if ($key !== strtolower($key)) {
                    throw new RangeError(
                        "annotation keys must be lowercase: {$str}"
                        . " - invalid capitalized key"
                    );
                }
                if ($critical) {
                    throw new RangeError(
                        "reject unknown annotation with critical flag: {$str}"
                    );
                }
            }
        }
        if ($calCount > 1 && $hasCriticalCal) {
            throw new RangeError(
                "reject more than one calendar annotation if any critical: {$str}"
            );
        }
        // Canonicalize CLDR aliases on the parsed calendar id so
        // downstream "calendar must be iso8601" checks see the
        // resolved form (islamicc → islamic-civil, etc.).
        static $calAliasNorm = [
            'islamicc' => 'islamic-civil',
            'ethiopic-amete-alem' => 'ethioaa',
            'gregorian' => 'gregory',
        ];
        if (isset($calAliasNorm[$cal])) {
            $cal = $calAliasNorm[$cal];
        }
        // Count timezone annotations (non-key-value: no '=').
        $tzCount = 0;
        foreach ($anns as $ann) {
            $content = $ann[2];
            if (!str_contains($content, '=')) {
                $tzCount++;
                // Reject sub-minute offsets in timezone annotations.
                // Matches both colon-separated (+HH:MM:SS) and compact (+HHMMSS) forms.
                if (
                    preg_match('/^[+-]\d{2}:?\d{2}:?\d{2}/', $content)
                    || preg_match('/^[+-]\d{2}:?\d{2}[.,]/', $content)
                ) {
                    throw new RangeError(
                        "ISO strings cannot have sub-minute offsets in time zone annotations: {$str}"
                    );
                }
            }
        }
        if ($tzCount > 1) {
            throw new RangeError(
                "reject more than one time zone annotation: {$str}"
            );
        }
        return [$str, $cal];
    }

    private static function formatSubSecond(string $nsPadded, string|int $fractionalSecondDigits): string
    {
        if ($fractionalSecondDigits === 'auto') {
            $trimmed = rtrim($nsPadded, '0');
            if ($trimmed === '') {
                return '';
            }
            return '.' . $trimmed;
        }
        if (is_int($fractionalSecondDigits) || is_numeric($fractionalSecondDigits)) {
            $digits = (int) $fractionalSecondDigits;
            if ($digits === 0) {
                return '';
            }
            return '.' . substr($nsPadded, 0, $digits);
        }
        // Fallback to auto.
        $trimmed = rtrim($nsPadded, '0');
        return $trimmed === '' ? '' : '.' . $trimmed;
    }

    private static function formatISOTime(
        int $h,
        int $min,
        int $s,
        int $ms,
        int $us,
        int $ns,
        string|int $fractionalSecondDigits = 'auto',
        string $roundingMode = 'trunc',
    ): string {
        $nsPadded = str_pad((string) $ms, 3, '0', STR_PAD_LEFT)
            . str_pad((string) $us, 3, '0', STR_PAD_LEFT)
            . str_pad((string) $ns, 3, '0', STR_PAD_LEFT);
        $fracStr = self::formatSubSecond($nsPadded, $fractionalSecondDigits);
        return self::pad2($h) . ':' . self::pad2($min) . ':' . self::pad2($s) . $fracStr;
    }

    private static function plainTimeToString(JsValue $this_, string|int $fractionalSecondDigits = 'auto', string $roundingMode = 'trunc'): string
    {
        return self::formatISOTime(
            self::getSlotInt($this_, '[[ISOHour]]'),
            self::getSlotInt($this_, '[[ISOMinute]]'),
            self::getSlotInt($this_, '[[ISOSecond]]'),
            self::getSlotInt($this_, '[[ISOMillisecond]]'),
            self::getSlotInt($this_, '[[ISOMicrosecond]]'),
            self::getSlotInt($this_, '[[ISONanosecond]]'),
            $fractionalSecondDigits,
            $roundingMode,
        );
    }

    private static function plainDateTimeToString(
        JsValue $this_,
        string|int $fractionalSecondDigits = 'auto',
        string $roundingMode = 'trunc',
        string $calendarName = 'auto',
    ): string {
        $y = self::getSlotInt($this_, '[[ISOYear]]');
        $m = self::getSlotInt($this_, '[[ISOMonth]]');
        $dd = self::getSlotInt($this_, '[[ISODay]]');
        $dateStr = self::padISOYear($y) . '-' . self::pad2($m) . '-' . self::pad2($dd);
        $timeStr = self::formatISOTime(
            self::getSlotInt($this_, '[[ISOHour]]'),
            self::getSlotInt($this_, '[[ISOMinute]]'),
            self::getSlotInt($this_, '[[ISOSecond]]'),
            self::getSlotInt($this_, '[[ISOMillisecond]]'),
            self::getSlotInt($this_, '[[ISOMicrosecond]]'),
            self::getSlotInt($this_, '[[ISONanosecond]]'),
            $fractionalSecondDigits,
            $roundingMode,
        );
        $result = "{$dateStr}T{$timeStr}";
        $cal = self::getSlotString($this_, '[[Calendar]]');
        $showCal = $calendarName === 'always'
            || $calendarName === 'critical'
            || ($calendarName !== 'never' && $cal !== 'iso8601');
        if ($showCal) {
            $prefix = $calendarName === 'critical' ? '!' : '';
            $result .= "[{$prefix}u-ca={$cal}]";
        }
        return $result;
    }
}
