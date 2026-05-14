<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Date;

use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;
use Phasis\BuiltIn\SymbolConstructor;

/**
 * Date trait part: DateParsing. Composed into DateConstructor via
 * `use Date\DateParsing;`. `self::`/`$this->` resolve into the
 * composing class so static-property + cross-trait calls work.
 */
trait DateParsing
{
    /**
     * Parse a date string. Accepts ISO 8601 and common formats via PHP strtotime.
     * Returns NaN for unparseable strings.
     */
    private static function parseDate(string $str): float
    {
        $str = trim($str);
        if ($str === '') {
            return NAN;
        }

        // Reject -000000 (negative zero year) per spec: "The representation of the
        // year 0 as -000000 is invalid."
        if (preg_match('/^-0{6}/', $str)) {
            return NAN;
        }

        // Try extended year ISO format: [+-]YYYYYY-MM-DDTHH:MM:SS.sssZ
        $extPattern = '/^([+-]\d{6})-(\d{2})-(\d{2})'
            . '(?:T(\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,3}))?)?)?'
            . '([Z]|[+-]\d{2}:\d{2})?$/i';
        if (preg_match($extPattern, $str, $m)) {
            $year = (int) $m[1];
            $month = (int) $m[2];
            $day = (int) $m[3];
            $hour = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;
            $min = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : 0;
            $sec = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0;
            $millis = 0.0;
            if (isset($m[7]) && $m[7] !== '') {
                $millis = (float) str_pad($m[7], 3, '0');
            }

            $hasTime = isset($m[4]) && $m[4] !== '';
            $tz = ($m[8] ?? '') !== '' ? $m[8] : null;

            // Date-only forms are UTC; date-time without TZ is local.
            if (!$hasTime && $tz === null) {
                $tz = 'Z';
            }

            // Compute using gmmktime for UTC, then adjust for timezone offset.
            $ts = gmmktime($hour, $min, $sec, $month, $day, $year);
            if ($ts === false) {
                return NAN;
            }

            $offsetSec = 0;
            if ($tz !== null && strtoupper($tz) !== 'Z') {
                if (preg_match('/^([+-])(\d{2}):(\d{2})$/', $tz, $tzm)) {
                    $offsetSec = ((int) $tzm[2] * 3600 + (int) $tzm[3] * 60);
                    if ($tzm[1] === '+') {
                        $offsetSec = -$offsetSec;
                    }
                }
            } elseif ($tz === null) {
                // Local time: subtract local timezone offset.
                $dt = new \DateTimeImmutable('@' . $ts);
                $local = $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                $offsetSec = -(int) $local->format('Z');
            }

            $result = (float) $ts * 1000.0 + $millis + (float) $offsetSec * 1000.0;
            return self::timeClip($result);
        }

        // Lenient extended-year non-ISO form: [+-]YYYYYY-M-D[ HH:MM[:SS]]
        // matches SpiderMonkey's date heuristic where the space separator
        // and single-digit month/day are accepted alongside the extended
        // year prefix. Used when callers write "+001997-3-8 11:19:20" and
        // expect the same epoch as "1997-03-08T11:19:20".
        $extLenient = '/^([+-]\d{6})-(\d{1,2})-(\d{1,2})'
            . '(?:[ ](\d{1,2}):(\d{1,2})(?::(\d{1,2})(?:\.(\d{1,3}))?)?)?'
            . '([Z]|[+-]\d{2}:?\d{2})?$/i';
        if (preg_match($extLenient, $str, $m)) {
            $year = (int) $m[1];
            $month = (int) $m[2];
            $day = (int) $m[3];
            $hour = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;
            $min = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : 0;
            $sec = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0;
            $millis = 0.0;
            if (isset($m[7]) && $m[7] !== '') {
                $millis = (float) str_pad($m[7], 3, '0');
            }
            $hasTime = isset($m[4]) && $m[4] !== '';
            $tz = ($m[8] ?? '') !== '' ? $m[8] : null;
            if (!$hasTime && $tz === null) {
                $tz = 'Z';
            }
            $ts = gmmktime($hour, $min, $sec, $month, $day, $year);
            if ($ts === false) {
                return NAN;
            }
            $offsetSec = 0;
            if ($tz !== null && strtoupper($tz) !== 'Z') {
                if (preg_match('/^([+-])(\d{2}):?(\d{2})$/', $tz, $tzm)) {
                    $offsetSec = ((int) $tzm[2] * 3600 + (int) $tzm[3] * 60);
                    if ($tzm[1] === '+') {
                        $offsetSec = -$offsetSec;
                    }
                }
            } elseif ($tz === null) {
                $dt = new \DateTimeImmutable('@' . $ts);
                $local = $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                $offsetSec = -(int) $local->format('Z');
            }
            $result = (float) $ts * 1000.0 + $millis + (float) $offsetSec * 1000.0;
            return self::timeClip($result);
        }

        // Try ISO 8601 first: YYYY-MM-DDTHH:MM:SS.sssZ or with offset.
        $isoPattern = '/^\d{4}(-\d{2}(-\d{2}(T\d{2}:\d{2}'
            . '(:\d{2}(\.\d{1,3})?)?)?)?)?([Z]|[+-]\d{2}:\d{2})?$/i';
        if (preg_match($isoPattern, $str)) {
            // ISO 8601 date-only forms (no time component) are treated as UTC by the spec.
            $hasTime = str_contains($str, 'T') || str_contains($str, 't');
            $hasTimezone = (bool) preg_match('/[Z]|[+-]\d{2}:\d{2}$/i', $str);

            if (!$hasTime && !$hasTimezone) {
                // Date-only ISO: treat as UTC.
                // Expand partial ISO forms: YYYY -> YYYY-01-01, YYYY-MM -> YYYY-MM-01
                if (preg_match('/^\d{4}$/', $str)) {
                    // Year only: YYYY -> YYYY-01-01T00:00:00Z
                    $str .= '-01-01T00:00:00Z';
                } elseif (preg_match('/^\d{4}-\d{2}$/', $str)) {
                    // Year-month: YYYY-MM -> YYYY-MM-01T00:00:00Z
                    $str .= '-01T00:00:00Z';
                } else {
                    $str .= 'T00:00:00Z';
                }
            } elseif ($hasTime && !$hasTimezone) {
                // Date-time without timezone: treat as local time per spec.
                // strtotime handles this correctly by default.
            }
        } elseif (
            (str_contains($str, 'T') || str_contains($str, 't'))
            && preg_match('/^[+-]?\d/', $str)
        ) {
            // Looks ISO-ish (starts with a digit / signed year) AND contains
            // 'T' but didn't match the strict ISO pattern. Per spec, malformed
            // ISO strings (trailing 'T', single-digit components after 'T',
            // etc.) must return NaN — strtotime would otherwise lenient-parse
            // them as valid. Space-separated non-ISO forms (e.g. SpiderMonkey
            // 'YYYY-M-D HH:MM:SS') do not have a 'T' and continue to parse.
            return NAN;
        }

        // Mozilla / V8 share a non-spec heuristic for two-digit years that
        // PHP's strtotime gets wrong: years 0-49 expand to 2000-2049 and
        // 50-99 expand to 1950-1999. PHP treats 50-69 as 2050-2069 instead.
        // Pre-process common date forms so the heuristic matches the
        // SpiderMonkey/V8 behavior tested by sm/Date/two-digit-years.
        $str = self::expandTwoDigitYear($str);

        $ts = strtotime($str);
        if ($ts === false) {
            return NAN;
        }

        // Extract sub-second precision from the string if present.
        $ms = 0.0;
        if (preg_match('/\.(\d{1,3})/', $str, $match)) {
            $frac = str_pad($match[1], 3, '0');
            $ms = (float) $frac;
        }

        return self::timeClip((float) $ts * 1000.0 + $ms);
    }

    /**
     * Apply the SpiderMonkey/V8 two-digit-year heuristic to common date
     * forms before strtotime sees them. Years 0-49 expand to 2000-2049,
     * 50-99 expand to 1950-1999. Patterns we touch:
     *
     *   mm/dd/yy   — US-style numeric (year is the trailing component).
     *   yy/mm/dd   — leading 2-digit year, only when year > 31. For
     *                12 < year <= 31, the form is ambiguous between
     *                month/day/year and year/month/day, so we let
     *                strtotime fail and the result becomes NaN per spec.
     *   <month> day yy / day <month> yy / day yy <month> — written months.
     *
     * Anything else (4-digit years, ISO forms) is returned untouched.
     */
    private static function expandTwoDigitYear(string $str): string
    {
        $expand = static function (int $y): int {
            return $y >= 50 ? 1900 + $y : 2000 + $y;
        };

        // 1-2 digit components separated by '/'. Disambiguate which field
        // is the year by Mozilla/V8's heuristic:
        //   - first > 31  → yy/mm/dd  (unambiguously a year)
        //   - first <= 12 → mm/dd/yy  (US-style; first is month)
        //   - 12 < first <= 31 → ambiguous — leave for strtotime, which
        //     produces NaN (matching the test's expectation).
        if (preg_match('@^(\d{1,2})/(\d{1,2})/(\d{1,2})$@', $str, $m)) {
            $first = (int) $m[1];
            $second = (int) $m[2];
            $third = (int) $m[3];
            if ($first > 31) {
                // yy/mm/dd. Reject obviously-invalid month/day.
                if ($second < 1 || $second > 12 || $third < 1 || $third > 31) {
                    return 'invalid-date-form';
                }
                return $expand($first) . '/' . $m[2] . '/' . $m[3];
            }
            if ($first <= 12) {
                // mm/dd/yy. Reject obviously-invalid month/day.
                if ($first < 1 || $second < 1 || $second > 31) {
                    return 'invalid-date-form';
                }
                return $m[1] . '/' . $m[2] . '/' . $expand($third);
            }
            // 12 < first <= 31 → ambiguous; force NaN regardless of what
            // strtotime might do with PHP's d/m/y locale heuristic.
            return 'invalid-date-form';
        }
        // Written month forms: `may 1 99`, `1 may 99`, `1 99 may`.
        $monthAlt = '(jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may'
            . '|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?'
            . '|nov(?:ember)?|dec(?:ember)?)';
        // SpiderMonkey accepts many `<month-name> <num> <num>` permutations.
        // strtotime can read `<month> <day> <year>` and `<day> <month> <year>`,
        // but balks on permutations where the year sits between the month
        // name and the day. Normalise everything into `<month> <day> <year>`
        // with a 4-digit year so the underlying parse succeeds.
        //
        // For the year, year > 31 is unambiguously a year. year <= 31 is a
        // day (or could be a year if it happens to look like one — but we
        // pick the unambiguous answer first). When both numbers fit in [1,31]
        // we treat the trailing one (closest to the month name's far side)
        // as the year, matching SpiderMonkey's tests.
        // Returns [day, year], or null when both values are obvious years
        // (i.e. neither could be a day-of-month). The latter case maps to
        // a SyntaxError in V8/SpiderMonkey, so callers force NaN.
        $disambiguate = static function (int $a, int $b): ?array {
            $aIsDay = $a >= 1 && $a <= 31;
            $bIsDay = $b >= 1 && $b <= 31;
            if (!$aIsDay && !$bIsDay) {
                return null;
            }
            if ($a > 31 && $bIsDay) {
                return [$b, $a];
            }
            if ($b > 31 && $aIsDay) {
                return [$a, $b];
            }
            return [$a, $b];
        };

        // <month> <num> <num>
        if (preg_match("@^{$monthAlt}\\s+(\\d{1,4})\\s+(\\d{1,4})$@i", $str, $m)) {
            $pair = $disambiguate((int) $m[2], (int) $m[3]);
            if ($pair === null) {
                return 'invalid-date-form';
            }
            [$day, $year] = $pair;
            $year = $year < 100 ? $expand($year) : $year;
            return $m[1] . ' ' . $day . ' ' . $year;
        }
        // <num> <month> <num>
        if (preg_match("@^(\\d{1,4})\\s+{$monthAlt}\\s+(\\d{1,4})$@i", $str, $m)) {
            $pair = $disambiguate((int) $m[1], (int) $m[3]);
            if ($pair === null) {
                return 'invalid-date-form';
            }
            [$day, $year] = $pair;
            $year = $year < 100 ? $expand($year) : $year;
            return $m[2] . ' ' . $day . ' ' . $year;
        }
        // <num> <num> <month>
        if (preg_match("@^(\\d{1,4})\\s+(\\d{1,4})\\s+{$monthAlt}$@i", $str, $m)) {
            $pair = $disambiguate((int) $m[1], (int) $m[2]);
            if ($pair === null) {
                return 'invalid-date-form';
            }
            [$day, $year] = $pair;
            $year = $year < 100 ? $expand($year) : $year;
            return $m[3] . ' ' . $day . ' ' . $year;
        }
        return $str;
    }
}
