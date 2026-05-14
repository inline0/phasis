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
 * Temporal helper section (IsoCalendarHelpers). Composed into TemporalObject
 * via `use Temporal\IsoCalendarHelpers;`. `self::` references resolve into
 * the composing class.
 */
trait IsoCalendarHelpers
{
    // -----------------------------------------------------------------------
    // Helpers: ISO calendar
    // -----------------------------------------------------------------------

    private static function isoIsLeapYear(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || ($year % 400 === 0);
    }

    private static function isoDaysInMonth(int $year, int $month): int
    {
        return match ($month) {
            1, 3, 5, 7, 8, 10, 12 => 31,
            4, 6, 9, 11 => 30,
            2 => self::isoIsLeapYear($year) ? 29 : 28,
            default => 30,
        };
    }

    /**
     * Maximum possible days in a month for the given calendar. Used by
     * PlainMonthDay's "constrain" overflow path where the caller does not
     * know a specific year. Returns the largest plausible upper bound across
     * all years for that calendar's month code.
     */
    private static function maxDaysInCalendarMonth(string $calendar, int $month, bool $isLeapMonthCode = false): int
    {
        switch ($calendar) {
            case 'buddhist':
            case 'gregory':
            case 'japanese':
            case 'roc':
                return self::isoDaysInMonth(2000, $month); // leap-year max for Feb (29).
            case 'coptic':
            case 'ethioaa':
            case 'ethiopic':
                return $month === 13 ? 6 : 30;
            case 'hebrew':
                if ($isLeapMonthCode) {
                    return 30;
                }
                return match ($month) {
                    1, 3, 5, 7, 11 => 30,    // Tishri, Kislev (max), Shevat, Nisan, Av
                    2 => 30, // Cheshvan (max).
                    4, 6, 8, 10, 12 => 29,   // Tevet, Adar, Iyyar, Tammuz, Elul
                    9 => 30, // Sivan
                    default => 30,
                };
            case 'indian':
                return match ($month) {
                    1 => 31, // Chaitra (leap year), 30 otherwise
                    2, 3, 4, 5, 6 => 31,
                    7, 8, 9, 10, 11, 12 => 30,
                    default => 31,
                };
            case 'islamic':
            case 'islamic-civil':
            case 'islamic-tbla':
            case 'islamic-umalqura':
            case 'islamic-rgsa':
                return 30; // umalqura M12 can be 30.
            case 'persian':
                return match ($month) {
                    1, 2, 3, 4, 5, 6 => 31,
                    7, 8, 9, 10, 11 => 30,
                    12 => 30, // 30 in leap year, 29 otherwise.
                    default => 30,
                };
            case 'chinese':
            case 'dangi':
                return 30;
            default:
                return self::isoDaysInMonth(2000, $month);
        }
    }

    private static function isoDaysInYear(int $year): int
    {
        return self::isoIsLeapYear($year) ? 366 : 365;
    }

    /**
     * Compute a midpoint date for month-boundary clamping in date differences.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function computeMonthMidpoint(
        int $sign,
        int $y1,
        int $m1,
        int $y2,
        int $m2,
        int $anchorDay,
        int $monthCount,
    ): array {
        if ($sign < 0) {
            $mt = $y2 * 12 + ($m2 - 1) - $monthCount;
        } else {
            $mt = $y1 * 12 + ($m1 - 1) + $monthCount;
        }
        $my = intdiv($mt, 12);
        $mm = ($mt % 12) + 1;
        if ($mm < 1) {
            $mm += 12;
            $my--;
        }
        $md = min($anchorDay, self::isoDaysInMonth($my, $mm));
        return [$my, $mm, $md];
    }

    private static function isoDayOfYear(int $year, int $month, int $day): int
    {
        $total = 0;
        for ($m = 1; $m < $month; $m++) {
            $total += self::isoDaysInMonth($year, $m);
        }
        return $total + $day;
    }

    private static function isoDayOfWeek(int $year, int $month, int $day): int
    {
        // Zeller-like formula. PHP's mktime can handle years < 100 badly, so use formula.
        // Tomohiko Sakamoto's algorithm: returns 0 = Sunday, 1 = Monday, ... 6 = Saturday.
        // ISO weekday: 1 = Monday, 7 = Sunday.
        $t = [0, 3, 2, 5, 0, 3, 5, 1, 4, 6, 2, 4];
        $y = $year;
        if ($month < 3) {
            $y--;
        }
        $dow = ($y + intdiv($y, 4) - intdiv($y, 100) + intdiv($y, 400) + $t[$month - 1] + $day) % 7;
        // Convert from 0=Sun to ISO 1=Mon..7=Sun.
        return $dow === 0 ? 7 : $dow;
    }

    /**
     * ISO week of year per ISO 8601 (week starts Monday, first week contains Jan 4).
     * @return array{0: ?int, 1: ?int} [weekOfYear, yearOfWeek]
     */
    private static function isoWeekOfYear(int $year, int $month, int $day): array
    {
        // Use PHP's built-in ISO week calculation for reliability.
        try {
            $dt = new \DateTimeImmutable("{$year}-{$month}-{$day}", new \DateTimeZone('UTC'));
            $weekNum = (int) $dt->format('W');
            $yearOfWeek = (int) $dt->format('o');
            return [$weekNum, $yearOfWeek];
        } catch (\Throwable) {
            return [null, null];
        }
    }

    /**
     * Calendar-aware week of year.
     * - "iso8601": ISO 8601 week numbering.
     * - "gregory": Gregorian (locale-default) week numbering via ICU.
     * - Others: undefined (no well-defined week-numbering system).
     * @return array{0: ?int, 1: ?int} [weekOfYear, yearOfWeek]
     */
    private static function calendarWeekOfYear(string $calendar, int $year, int $month, int $day): array
    {
        if ($calendar === 'iso8601') {
            return self::isoWeekOfYear($year, $month, $day);
        }
        if ($calendar === 'gregory') {
            if (!class_exists('IntlCalendar', false)) {
                return self::isoWeekOfYear($year, $month, $day);
            }
            try {
                $cal = \IntlCalendar::createInstance('UTC', 'en@calendar=gregorian');
                $cal->setDateTime($year, $month - 1, $day, 0, 0, 0);
                $weekNum = $cal->get(\IntlCalendar::FIELD_WEEK_OF_YEAR);
                $yearWoY = $cal->get(\IntlCalendar::FIELD_YEAR_WOY);
                return [(int) $weekNum, (int) $yearWoY];
            } catch (\Throwable) {
                return self::isoWeekOfYear($year, $month, $day);
            }
        }
        return [null, null];
    }

    private static function validateISODate(int $y, int $m, int $d): void
    {
        if ($m < 1 || $m > 12) {
            throw new RangeError("Invalid month: {$m}");
        }
        $dim = self::isoDaysInMonth($y, $m);
        if ($d < 1 || $d > $dim) {
            throw new RangeError("Invalid day: {$d}");
        }
        if ($y < self::ISO_YEAR_MIN || $y > self::ISO_YEAR_MAX) {
            throw new RangeError("Invalid year: {$y}");
        }
        // Precise range: -271821-04-19 to +275760-09-13 inclusive.
        if ($y === self::ISO_YEAR_MIN) {
            if ($m < 4 || ($m === 4 && $d < 19)) {
                throw new RangeError("Date is outside the representable range");
            }
        }
        if ($y === self::ISO_YEAR_MAX) {
            if ($m > 9 || ($m === 9 && $d > 13)) {
                throw new RangeError("Date is outside the representable range");
            }
        }
    }

    private static function validateISOTime(int $h, int $m, int $s, int $ms, int $us, int $ns): void
    {
        if (
            $h < 0 || $h > 23 || $m < 0 || $m > 59 || $s < 0 || $s > 59
            || $ms < 0 || $ms > 999 || $us < 0 || $us > 999 || $ns < 0 || $ns > 999
        ) {
            throw new RangeError('Invalid time');
        }
    }

    private static function compareISODate(int $y1, int $m1, int $d1, int $y2, int $m2, int $d2): int
    {
        if ($y1 !== $y2) {
            return $y1 < $y2 ? -1 : 1;
        }
        if ($m1 !== $m2) {
            return $m1 < $m2 ? -1 : 1;
        }
        if ($d1 !== $d2) {
            return $d1 < $d2 ? -1 : 1;
        }
        return 0;
    }

    private static function compareISOTime(
        int $h1,
        int $m1,
        int $s1,
        int $ms1,
        int $us1,
        int $ns1,
        int $h2,
        int $m2,
        int $s2,
        int $ms2,
        int $us2,
        int $ns2,
    ): int {
        $pairs = [
            [$h1, $h2], [$m1, $m2], [$s1, $s2],
            [$ms1, $ms2], [$us1, $us2], [$ns1, $ns2],
        ];
        foreach ($pairs as [$a, $b]) {
            if ($a !== $b) {
                return $a < $b ? -1 : 1;
            }
        }
        return 0;
    }
}
