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
 * Temporal helper section (IcuCalendarHelpers). Composed into TemporalObject
 * via `use Temporal\IcuCalendarHelpers;`. `self::` references resolve into
 * the composing class.
 */
trait IcuCalendarHelpers
{
    // -----------------------------------------------------------------------
    // Helpers: calendar arithmetic via ICU
    // -----------------------------------------------------------------------

    /**
     * Convert ISO (y,m,d) to calendar-specific (year, monthCode, day) using
     * ICU. Returns null if conversion is unavailable. Currently supports
     * hebrew, islamic*, persian, indian, ethioaa, ethiopic, coptic, buddhist,
     * japanese, roc, chinese, dangi.
     *
     * @return array<string, bool|int|string>|null
     */
    private static function isoToCalendarParts(string $calendar, int $y, int $m, int $d): ?array
    {
        if ($calendar === 'iso8601') {
            $monthCode = 'M' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
            return [
                'year' => $y,
                'month' => $m,
                'monthCode' => $monthCode,
                'day' => $d,
                'isLeapMonth' => false,
            ];
        }
        // Gregorian-like calendars use the ISO year/month/day directly (with
        // year 0 allowed). ICU here would shift across the Julian/Gregorian
        // boundary or into year 1 BCE, neither of which matches the spec.
        // For ROC the "year" is offset from 1911 (ROC year 1 = 1912 AD).
        // For Japanese the "year" stays Gregorian-equal because the era
        // disambiguates Heisei/Reiwa/etc., and the spec exposes the
        // Gregorian-like proleptic year via the .year getter.
        if (in_array($calendar, ['gregory', 'roc', 'japanese'], true)) {
            $monthCode = 'M' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
            $calYear = $calendar === 'roc' ? ($y - 1911) : $y;
            return [
                'year' => $calYear,
                'month' => $m,
                'monthCode' => $monthCode,
                'day' => $d,
                'isLeapMonth' => false,
            ];
        }
        // Hebrew: use the pure-PHP implementation. ICU's IntlCalendar gives
        // wrong day counts for Cheshvan/Kislev in some years.
        if ($calendar === 'hebrew') {
            $h = self::isoToHebrewDate($y, $m, $d);
            $hYear = $h['year'];
            $icuMonth = $h['icuMonth'];
            $hDay = $h['day'];
            $isLeap = self::isHebrewLeapYear($hYear);
            $isLeapMonth = $isLeap && $icuMonth === 5;
            $monthCode = self::calendarMonthToCode($calendar, $hYear, $icuMonth, $isLeapMonth);
            $monthOneBased = self::calendarMonthToOneBased($calendar, $hYear, $icuMonth, $isLeapMonth);
            return [
                'year' => $hYear,
                'month' => $monthOneBased,
                'monthCode' => $monthCode,
                'day' => $hDay,
                'isLeapMonth' => $isLeapMonth,
            ];
        }
        // Ethiopic / ethioaa: pure-PHP 13-month arithmetic (CI-independent).
        if ($calendar === 'ethiopic' || $calendar === 'ethioaa' || $calendar === 'ethiopic-amete-alem') {
            $e = self::isoToEthiopicDate($y, $m, $d);
            $eYear = $e['year'];
            $eMonth = $e['month'];
            $eDay = $e['day'];
            $monthCode = 'M' . str_pad((string) $eMonth, 2, '0', STR_PAD_LEFT);
            $userYear = self::ethiopicUserYear($calendar, $eYear);
            return [
                'year' => $userYear,
                'month' => $eMonth,
                'monthCode' => $monthCode,
                'day' => $eDay,
                'isLeapMonth' => false,
            ];
        }
        // Chinese / dangi: pure-PHP table lookup (CI-independent).
        if ($calendar === 'chinese' || $calendar === 'dangi') {
            self::$chineseDispatchCalendar = $calendar;
            $c = self::isoToChineseDate($y, $m, $d);
            if ($c !== null) {
                $monthCode = self::calendarMonthToCode($calendar, $c['year'], $c['icuMonth'], $c['isLeap']);
                $monthOneBased = self::chineseMonthOneBased($c['year'], $c['icuMonth'], $c['isLeap']);
                return [
                    'year' => $c['year'],
                    'month' => $monthOneBased,
                    'monthCode' => $monthCode,
                    'day' => $c['day'],
                    'isLeapMonth' => $c['isLeap'],
                ];
            }
            // Out of table range: fall through to ICU fallback.
        }
        if (!class_exists('IntlCalendar', false)) {
            return null;
        }
        try {
            $icuCal = $calendar;
            static $aliasMap = [
                'gregory' => 'gregorian',
                'islamic-civil' => 'islamic-civil',
                'islamicc' => 'islamic-civil',
                'ethioaa' => 'ethiopic-amete-alem',
            ];
            if (isset($aliasMap[$calendar])) {
                $icuCal = $aliasMap[$calendar];
            }
            // Cache an IntlCalendar per ICU calendar id. The instance is
            // stateful (time + fields) but the calendrical algorithm is a
            // pure function of (epoch ms, calendar id); a single shared
            // instance per id can be reused safely in PHP's
            // single-threaded model because we set the time and read all
            // fields before any other call site can touch it.
            // createInstance is ~4x slower than reuse on ICU 7x; this
            // shaves the dominant cost out of stress workloads like
            // staging/sm/Temporal/Calendar/compare-to-datetimeformat.js
            // and any other test that converts many ISO dates through the
            // same calendar.
            /** @var array<string,\IntlCalendar> $calCache */
            static $calCache = [];
            if (!isset($calCache[$icuCal])) {
                $calCache[$icuCal] = \IntlCalendar::createInstance('UTC', "en@calendar={$icuCal}");
            }
            $cal = $calCache[$icuCal];
            // Set the ICU calendar to the ISO date by epoch ms.
            $epochMs = self::isoDateToEpochMs($y, $m, $d);
            $cal->setTime($epochMs);
            // Chinese/Dangi have YEAR (1-60 sexagenary cycle) and
            // EXTENDED_YEAR (the actual year). The Temporal spec uses the
            // extended year. Coptic/Ethiopic likewise: FIELD_YEAR is the
            // era-relative year (positive in both eras), but Temporal
            // wants the proleptic / extended year (negative for ISO
            // dates predating year 1 of the positive era). EthioAA is
            // the inverse: its FIELD_YEAR already counts from the Amete
            // Alem epoch (year ~5500 BCE) so EXT_YEAR is wrong there.
            if (in_array($calendar, ['chinese', 'dangi', 'coptic', 'ethiopic'], true)) {
                $calY = $cal->get(\IntlCalendar::FIELD_EXTENDED_YEAR);
            } else {
                $calY = $cal->get(\IntlCalendar::FIELD_YEAR);
            }
            $calM = $cal->get(\IntlCalendar::FIELD_MONTH);
            $calD = $cal->get(\IntlCalendar::FIELD_DAY_OF_MONTH);
            $isLeapMonth = false;
            if (in_array($calendar, ['chinese', 'dangi'], true)) {
                $isLeapMonth = (bool) $cal->get(\IntlCalendar::FIELD_IS_LEAP_MONTH);
            }
        } catch (\Throwable) {
            return null;
        }
        $isLeapFromCalendar = self::calendarMonthIsLeap($calendar, $calY, $calM);
        $finalLeap = $isLeapMonth || $isLeapFromCalendar;
        $monthCode = self::calendarMonthToCode($calendar, $calY, $calM, $finalLeap);
        $monthOneBased = self::calendarMonthToOneBased($calendar, $calY, $calM, $finalLeap);
        $isLeapMonth = $finalLeap;
        return [
            'year' => $calY,
            'month' => $monthOneBased,
            'monthCode' => $monthCode,
            'day' => $calD,
            'isLeapMonth' => $isLeapMonth,
        ];
    }

    /** Days since unix epoch for an ISO date. */
    private static function isoDateToEpochMs(int $y, int $m, int $d): float
    {
        $days = self::isoDateToDays($y, $m, $d);
        return (float) ((int) $days * 86400 * 1000);
    }

    /**
     * Calendar-aware (years, months, days) for two ISO dates with sml ≤ lrg.
     * Walks via ICU add(YEAR/MONTH) so leap months and variable year lengths
     * are honored. Returns null when ICU is unavailable or arithmetic fails.
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function calendarYearsMonthsDaysBetween(
        string $calendar,
        int $smlY,
        int $smlM,
        int $smlD,
        int $lrgY,
        int $lrgM,
        int $lrgD,
        string $largestUnit,
    ): ?array {
        // Ethiopic / ethioaa: deterministic 13-month walk, no ICU needed.
        if ($calendar === 'ethiopic' || $calendar === 'ethioaa' || $calendar === 'ethiopic-amete-alem') {
            return self::ethiopicYearsMonthsDaysBetween(
                $smlY,
                $smlM,
                $smlD,
                $lrgY,
                $lrgM,
                $lrgD,
                $largestUnit,
            );
        }
        // Chinese / dangi: deterministic via pure-PHP table.
        if ($calendar === 'chinese' || $calendar === 'dangi') {
            self::$chineseDispatchCalendar = $calendar;
            $r = self::chineseYearsMonthsDaysBetween(
                $smlY,
                $smlM,
                $smlD,
                $lrgY,
                $lrgM,
                $lrgD,
                $largestUnit,
            );
            if ($r !== null) {
                return $r;
            }
            // Out of table range: fall through to ICU.
        }
        if (!class_exists('IntlCalendar', false)) {
            return null;
        }
        static $aliasMap = [
            'gregory' => 'gregorian',
            'islamicc' => 'islamic-civil',
            'ethioaa' => 'ethiopic-amete-alem',
        ];
        $icuCal = $aliasMap[$calendar] ?? $calendar;
        try {
            $startMs = self::isoDateToEpochMs($smlY, $smlM, $smlD);
            $endMs = self::isoDateToEpochMs($lrgY, $lrgM, $lrgD);
            $startCal = \IntlCalendar::createInstance('UTC', "en@calendar={$icuCal}");
            $startCal->setTime($startMs);
            $endCal = \IntlCalendar::createInstance('UTC', "en@calendar={$icuCal}");
            $endCal->setTime($endMs);
            $yearField = in_array($calendar, ['chinese', 'dangi'], true)
                ? \IntlCalendar::FIELD_EXTENDED_YEAR
                : \IntlCalendar::FIELD_YEAR;

            $years = 0;
            if ($largestUnit === 'year') {
                $bound = max(0, $endCal->get($yearField) - $startCal->get($yearField) + 1);
                $lo = 0;
                $hi = $bound;
                while ($lo < $hi) {
                    $mid = intdiv($lo + $hi + 1, 2);
                    $probe = clone $startCal;
                    $probe->add(\IntlCalendar::FIELD_YEAR, $mid);
                    if ($probe->getTime() <= $endMs) {
                        $lo = $mid;
                    } else {
                        $hi = $mid - 1;
                    }
                }
                $years = $lo;
            }

            $anchorCal = clone $startCal;
            if ($years > 0) {
                $anchorCal->add(\IntlCalendar::FIELD_YEAR, $years);
            }

            $months = 0;
            $bound = max(0, ($endCal->get($yearField) - $anchorCal->get($yearField) + 1) * 13);
            if ($bound === 0 && $anchorCal->getTime() < $endMs) {
                $bound = 13;
            }
            $lo = 0;
            $hi = $bound;
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi + 1, 2);
                $probe = clone $anchorCal;
                $probe->add(\IntlCalendar::FIELD_MONTH, $mid);
                if ($probe->getTime() <= $endMs) {
                    $lo = $mid;
                } else {
                    $hi = $mid - 1;
                }
            }
            $months = $lo;

            $finalCal = clone $anchorCal;
            if ($months > 0) {
                $finalCal->add(\IntlCalendar::FIELD_MONTH, $months);
            }
            $finalMs = $finalCal->getTime();
            $days = (int) round(($endMs - $finalMs) / 86400000);
            if ($days < 0) {
                return null;
            }
            return [$years, $months, $days];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * For chinese / dangi leap years, return the ICU MONTH index that has a
     * leap-month form (0..11). Returns null if no leap month exists.
     */
    private static function chineseLeapMonthIndex(string $calendar, int $extendedYear): ?int
    {
        if (!in_array($calendar, ['chinese', 'dangi'], true)) {
            return null;
        }
        // Pure-PHP lookup table (CI-independent). dangi has its own table
        // because Korean local time (UTC+9) vs Beijing (UTC+8) shifts a
        // few new-moon boundaries by one day.
        self::$chineseDispatchCalendar = $calendar;
        return self::chineseLeapMonthIcuFromTable($extendedYear);
    }

    /**
     * True when the calendar year (in calendar-native terms) is a leap year.
     */
    /**
     * 1-indexed day-of-year in the given calendar's space. Returns null
     * when ICU can't model the calendar; callers fall back to ISO
     * day-of-year. iso8601/gregory/roc/japanese/buddhist short-circuit
     * to the ISO value (their year boundaries match).
     */
    private static function calendarDayOfYearForIso(string $calendar, int $isoY, int $isoM, int $isoD): ?int
    {
        if (in_array($calendar, ['iso8601', 'gregory', 'roc', 'japanese', 'buddhist'], true)) {
            return self::isoDayOfYear($isoY, $isoM, $isoD);
        }
        if ($calendar === 'hebrew') {
            $h = self::isoToHebrewDate($isoY, $isoM, $isoD);
            $startDays = self::hebrewElapsedDaysToFirstTishrei($h['year']);
            $thisDays = self::isoDateToDays($isoY, $isoM, $isoD);
            return $thisDays - $startDays + 1;
        }
        if ($calendar === 'ethiopic' || $calendar === 'ethioaa' || $calendar === 'ethiopic-amete-alem') {
            $e = self::isoToEthiopicDate($isoY, $isoM, $isoD);
            $startDays = self::ethiopicNewYearDay($e['year']);
            $thisDays = self::isoDateToDays($isoY, $isoM, $isoD);
            return $thisDays - $startDays + 1;
        }
        if ($calendar === 'chinese' || $calendar === 'dangi') {
            self::$chineseDispatchCalendar = $calendar;
            $r = self::chineseDayOfYearForIso($isoY, $isoM, $isoD);
            if ($r !== null) {
                return $r;
            }
        }
        if (!class_exists('IntlCalendar', false)) {
            return null;
        }
        static $aliasMap = [
            'islamicc' => 'islamic-civil',
            'ethioaa' => 'ethiopic-amete-alem',
        ];
        $icuName = $aliasMap[$calendar] ?? $calendar;
        try {
            $cal = \IntlCalendar::createInstance(
                'UTC',
                "en@calendar={$icuName}",
            );
            $epochMs = self::isoDateToEpochMs($isoY, $isoM, $isoD);
            $cal->setTime($epochMs);
            return (int) $cal->get(\IntlCalendar::FIELD_DAY_OF_YEAR);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Number of days in the calendar month containing the given ISO
     * date. Returns null when ICU can't model the calendar; callers
     * fall back to ISO month length.
     */
    private static function calendarDaysInMonthForIso(string $calendar, int $isoY, int $isoM, int $isoD): ?int
    {
        if (in_array($calendar, ['iso8601', 'gregory', 'roc', 'japanese', 'buddhist'], true)) {
            return self::isoDaysInMonth($isoY, $isoM);
        }
        if ($calendar === 'hebrew') {
            $h = self::isoToHebrewDate($isoY, $isoM, $isoD);
            return self::hebrewDaysInMonth($h['year'], $h['icuMonth']);
        }
        if ($calendar === 'ethiopic' || $calendar === 'ethioaa' || $calendar === 'ethiopic-amete-alem') {
            $e = self::isoToEthiopicDate($isoY, $isoM, $isoD);
            return self::ethiopicDaysInMonth($e['year'], $e['month']);
        }
        if ($calendar === 'chinese' || $calendar === 'dangi') {
            self::$chineseDispatchCalendar = $calendar;
            $c = self::isoToChineseDate($isoY, $isoM, $isoD);
            if ($c !== null) {
                return self::chineseDaysInMonth($c['year'], $c['icuMonth'], $c['isLeap']);
            }
        }
        if (!class_exists('IntlCalendar', false)) {
            return null;
        }
        static $aliasMap = [
            'islamicc' => 'islamic-civil',
            'ethioaa' => 'ethiopic-amete-alem',
        ];
        $icuName = $aliasMap[$calendar] ?? $calendar;
        try {
            $cal = \IntlCalendar::createInstance(
                'UTC',
                "en@calendar={$icuName}",
            );
            $epochMs = self::isoDateToEpochMs($isoY, $isoM, $isoD);
            $cal->setTime($epochMs);
            return (int) $cal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_MONTH);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Number of days in the calendar year containing the given ISO
     * date. Returns null when ICU can't model the calendar.
     */
    private static function calendarDaysInYearForIso(string $calendar, int $isoY, int $isoM, int $isoD): ?int
    {
        if (in_array($calendar, ['iso8601', 'gregory', 'roc', 'japanese', 'buddhist'], true)) {
            return self::isoDaysInYear($isoY);
        }
        if ($calendar === 'hebrew') {
            $h = self::isoToHebrewDate($isoY, $isoM, $isoD);
            return self::hebrewYearLength($h['year']);
        }
        if ($calendar === 'ethiopic' || $calendar === 'ethioaa' || $calendar === 'ethiopic-amete-alem') {
            $e = self::isoToEthiopicDate($isoY, $isoM, $isoD);
            return self::ethiopicYearLength($e['year']);
        }
        if ($calendar === 'chinese' || $calendar === 'dangi') {
            self::$chineseDispatchCalendar = $calendar;
            $c = self::isoToChineseDate($isoY, $isoM, $isoD);
            if ($c !== null) {
                return self::chineseYearLength($c['year']);
            }
        }
        if (!class_exists('IntlCalendar', false)) {
            return null;
        }
        static $aliasMap = [
            'islamicc' => 'islamic-civil',
            'ethioaa' => 'ethiopic-amete-alem',
        ];
        $icuName = $aliasMap[$calendar] ?? $calendar;
        try {
            $cal = \IntlCalendar::createInstance(
                'UTC',
                "en@calendar={$icuName}",
            );
            $epochMs = self::isoDateToEpochMs($isoY, $isoM, $isoD);
            $cal->setTime($epochMs);
            return (int) $cal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_YEAR);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Number of months in the calendar year containing the given ISO date.
     * Returns null when ICU can't model the calendar; callers fall back
     * to 12 in that case.
     */
    private static function calendarMonthsInYear(string $calendar, int $isoY, int $isoM, int $isoD): ?int
    {
        if (in_array($calendar, ['iso8601', 'gregory', 'roc', 'japanese', 'buddhist'], true)) {
            return 12;
        }
        // Ethiopic / Coptic / EthioAA always have 13 months.
        if (in_array($calendar, ['coptic', 'ethiopic', 'ethioaa', 'ethiopic-amete-alem'], true)) {
            return 13;
        }
        // Chinese / Dangi: pure-PHP table lookup.
        if (in_array($calendar, ['chinese', 'dangi'], true)) {
            self::$chineseDispatchCalendar = $calendar;
            $c = self::isoToChineseDate($isoY, $isoM, $isoD);
            if ($c !== null) {
                $info = self::chineseYearInfo($c['year']);
                if ($info !== null) {
                    return $info['monthCount'];
                }
            }
            // Out of table range: fall through.
        }
        // Hebrew can be answered without ICU.
        if ($calendar === 'hebrew') {
            $leap = self::calendarInLeapYear($calendar, $isoY, $isoM, $isoD);
            return $leap === true ? 13 : 12;
        }
        if (!class_exists('IntlCalendar', false)) {
            return null;
        }
        if (in_array($calendar, ['chinese', 'dangi'], true)) {
            $leap = self::calendarInLeapYear($calendar, $isoY, $isoM, $isoD);
            return $leap === true ? 13 : 12;
        }
        // Islamic variants, persian, indian: 12.
        return 12;
    }

    private static function calendarInLeapYear(string $calendar, int $isoY, int $isoM, int $isoD): ?bool
    {
        if ($calendar === 'iso8601' || in_array($calendar, ['gregory', 'roc', 'japanese'], true)) {
            return self::isoIsLeapYear($isoY);
        }
        if ($calendar === 'hebrew') {
            $parts = self::isoToCalendarParts($calendar, $isoY, $isoM, $isoD);
            if ($parts === null) {
                return null;
            }
            return self::isHebrewLeapYear($parts['year']);
        }
        if ($calendar === 'ethiopic' || $calendar === 'ethioaa' || $calendar === 'ethiopic-amete-alem') {
            $e = self::isoToEthiopicDate($isoY, $isoM, $isoD);
            return self::isEthiopicLeapYear($e['year']);
        }
        if ($calendar === 'chinese' || $calendar === 'dangi') {
            self::$chineseDispatchCalendar = $calendar;
            $c = self::isoToChineseDate($isoY, $isoM, $isoD);
            if ($c !== null) {
                return self::chineseLeapMonthIcuFromTable($c['year']) !== null;
            }
        }
        if (!class_exists('IntlCalendar', false)) {
            return null;
        }
        try {
            $icuCalName = $calendar;
            static $aliasMapInLY = [
                'islamicc' => 'islamic-civil',
                'ethioaa' => 'ethiopic-amete-alem',
            ];
            if (isset($aliasMapInLY[$calendar])) {
                $icuCalName = $aliasMapInLY[$calendar];
            }
            $cal = \IntlCalendar::createInstance('UTC', "en@calendar={$icuCalName}");
            $cal->setTime(self::isoDateToEpochMs($isoY, $isoM, $isoD));
            // Chinese / Dangi leap years insert one leap month flagged via
            // IS_LEAP_MONTH (the MONTH field still ranges 0-11). Probe each
            // position with IS_LEAP_MONTH=1 and check whether ICU preserves
            // the flag.
            if (in_array($calendar, ['chinese', 'dangi'], true)) {
                $extYear = $cal->get(\IntlCalendar::FIELD_EXTENDED_YEAR);
                for ($m = 0; $m < 12; $m++) {
                    $probe = \IntlCalendar::createInstance('UTC', "en@calendar={$icuCalName}");
                    $probe->set(\IntlCalendar::FIELD_EXTENDED_YEAR, $extYear);
                    $probe->set(\IntlCalendar::FIELD_MONTH, $m);
                    $probe->set(\IntlCalendar::FIELD_IS_LEAP_MONTH, 1);
                    $probe->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);
                    $ms = $probe->getTime();
                    $verify = \IntlCalendar::createInstance('UTC', "en@calendar={$icuCalName}");
                    $verify->setTime($ms);
                    if (
                        $verify->get(\IntlCalendar::FIELD_EXTENDED_YEAR) === $extYear
                        && $verify->get(\IntlCalendar::FIELD_MONTH) === $m
                        && $verify->get(\IntlCalendar::FIELD_IS_LEAP_MONTH) === 1
                    ) {
                        return true;
                    }
                }
                return false;
            }
            // Coptic / Ethiopic leap year: every 4 years, M13 has 6 days.
            if (in_array($calendar, ['coptic', 'ethiopic', 'ethioaa'], true)) {
                return $cal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_YEAR) > 365;
            }
            // Persian: 365 vs 366 days.
            if ($calendar === 'persian' || $calendar === 'indian') {
                return $cal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_YEAR) > 365;
            }
            // Islamic variants: 354 vs 355 days (the "kabisat" extra day on
            // Dhu al-Hijjah). Treat any year exceeding 354 days as a leap.
            if (
                in_array(
                    $calendar,
                    ['islamic', 'islamic-civil', 'islamic-tbla', 'islamic-rgsa', 'islamic-umalqura', 'islamicc'],
                    true,
                )
            ) {
                return $cal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_YEAR) > 354;
            }
            return false;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Actual days-in-month for a calendar year/month combination via ICU.
     * Returns null if conversion is unavailable.
     */
    private static function calendarDaysInMonth(string $calendar, int $year, ?string $monthCode, ?int $monthNum): ?int
    {
        // Route through calendarPartsToIso so chinese/dangi extended_year +
        // leap_month resolution is applied (setDate alone uses FIELD_YEAR
        // which is the 60-cycle position, not the actual year). Ethiopic
        // and Hebrew never require ICU, so they work even without intl.
        $iso = self::calendarPartsToIso($calendar, $year, $monthCode, $monthNum, 1);
        if ($iso !== null) {
            return self::calendarDaysInMonthForIso(
                $calendar,
                $iso['year'],
                $iso['month'],
                $iso['day'],
            );
        }
        if (!class_exists('IntlCalendar', false)) {
            return null;
        }
        return null;
    }

    /**
     * Pick the ISO date that represents (calendar, monthCode|month, day) for
     * Temporal.PlainMonthDay's reference-year purposes. Per spec, this is the
     * largest ISO date <= 1972-12-31 whose calendar fields match. Returns
     * null when the calendar cannot be resolved.
     *
     * @return array{year: int, month: int, day: int}|null
     */
    private static function pmdReferenceIsoFor(string $cal, ?string $monthCode, ?int $monthNum, int $day): ?array
    {
        if ($cal === 'iso8601' || in_array($cal, ['gregory', 'roc', 'japanese'], true)) {
            $m = $monthNum ?? ($monthCode !== null && preg_match('/^M(\d{2})/', $monthCode, $mm) ? (int) $mm[1] : 0);
            return ['year' => 1972, 'month' => $m, 'day' => $day];
        }
        // Ethiopic / ethioaa: pure-PHP. Find the ethiopic year whose
        // M-d for ISO 1972-12-31 yields the largest ISO <= 1972-12-31.
        if ($cal === 'ethiopic' || $cal === 'ethioaa' || $cal === 'ethiopic-amete-alem') {
            $refE = self::isoToEthiopicDate(1972, 12, 31);
            $approxYear = self::ethiopicUserYear($cal, $refE['year']);
            for ($tryDay = $day; $tryDay >= 1; $tryDay--) {
                for ($delta = 1; $delta >= -8; $delta--) {
                    $tryYear = $approxYear + $delta;
                    $iso = self::calendarPartsToIso($cal, $tryYear, $monthCode, $monthNum, $tryDay);
                    if ($iso === null) {
                        continue;
                    }
                    if ($iso['year'] <= 1972) {
                        return $iso;
                    }
                }
            }
            return null;
        }
        // Chinese / dangi: pure-PHP approximate via the table.
        if (in_array($cal, ['chinese', 'dangi'], true)) {
            self::$chineseDispatchCalendar = $cal;
            $refC = self::isoToChineseDate(1972, 12, 31);
            if ($refC !== null) {
                $approxYear = $refC['year'];
            } elseif (class_exists('IntlCalendar', false)) {
                try {
                    $probe = \IntlCalendar::createInstance('UTC', "en@calendar={$cal}");
                    $probe->setTime(strtotime('1972-12-31 UTC') * 1000);
                    $approxYear = $probe->get(\IntlCalendar::FIELD_EXTENDED_YEAR);
                } catch (\Throwable) {
                    return null;
                }
            } else {
                return null;
            }
        } else {
            if (!class_exists('IntlCalendar', false)) {
                return null;
            }
            try {
                $icuCal = $cal;
                static $aliasMapPmd = [
                    'islamicc' => 'islamic-civil',
                    'ethioaa' => 'ethiopic-amete-alem',
                ];
                if (isset($aliasMapPmd[$cal])) {
                    $icuCal = $aliasMapPmd[$cal];
                }
                $probe = \IntlCalendar::createInstance('UTC', "en@calendar={$icuCal}");
                $probe->setTime(strtotime('1972-12-31 UTC') * 1000);
                $approxYear = $probe->get(\IntlCalendar::FIELD_YEAR);
            } catch (\Throwable) {
                return null;
            }
        }
        // Try a window of calendar years from approxYear and pick the largest
        // one whose ISO mapping for M-d lands in 1972 or earlier AND whose ICU
        // roundtrip (ISO->calendar) produces back the requested fields.
        // Lunisolar calendars can require longer search windows (M-d may exist
        // only every few years).
        $bestIso = null;
        // Chinese / Dangi need a wide window because some "uncommon"
        // leap months (M01L, M09L, M10L, M11L, M12L with 30 days)
        // recur only every several thousand years; SM's
        // `from-chinese-leap-month-uncommon` test asserts these exist
        // at ISO -6482 etc., which means our search must reach the
        // deep-past entries baked into the chinese / dangi tables
        // (start = -7500). Hebrew/etc. stay small.
        $maxLookback = in_array($cal, ['chinese', 'dangi'], true)
            ? 10000
            : (in_array($cal, ['hebrew'], true) ? 30 : 8);
        // When the requested day is more than the calendar month allows
        // (e.g. day 31 in islamic-civil M02 which has 29), step the day
        // down one at a time until the roundtrip lines up — that mirrors
        // the spec's "constrain" semantics for PlainMonthDay.from.
        for ($tryDay = $day; $tryDay >= 1; $tryDay--) {
            for ($delta = 1; $delta >= -$maxLookback; $delta--) {
                $tryYear = $approxYear + $delta;
                $iso = self::calendarPartsToIso($cal, $tryYear, $monthCode, $monthNum, $tryDay);
                if ($iso === null) {
                    continue;
                }
                // Roundtrip: ISO -> calendar should yield matching M-d.
                $back = self::isoToCalendarParts($cal, $iso['year'], $iso['month'], $iso['day']);
                if ($back === null) {
                    continue;
                }
                if ($monthCode !== null && $back['monthCode'] !== $monthCode) {
                    continue;
                }
                if ($monthNum !== null && $back['month'] !== $monthNum) {
                    continue;
                }
                if ($back['day'] !== $tryDay) {
                    continue;
                }
                if ($iso['year'] <= 1972) {
                    if (
                        $bestIso === null
                        || $iso['year'] > $bestIso['year']
                        || ($iso['year'] === $bestIso['year'] && $iso['month'] > $bestIso['month'])
                    ) {
                        $bestIso = $iso;
                    }
                }
            }
            if ($bestIso !== null) {
                return $bestIso;
            }
        }
        return $bestIso;
    }

    /**
     * Convert calendar-native (year, monthCode|month, day) to ISO (y, m, d)
     * via ICU. Returns null if conversion is unavailable. Caller is
     * responsible for choosing between monthCode and a 1-indexed month
     * number; for ICU we always need a 0-indexed integer month.
     *
     * @return array{year: int, month: int, day: int}|null
     */
    private static function calendarPartsToIso(string $calendar, int $year, ?string $monthCode, ?int $monthNum, int $day): ?array
    {
        if ($calendar === 'iso8601') {
            $m = $monthNum ?? ($monthCode !== null && preg_match('/^M(\d{2})/', $monthCode, $mm) ? (int) $mm[1] : 0);
            return ['year' => $year, 'month' => $m, 'day' => $day];
        }
        if (in_array($calendar, ['gregory', 'roc', 'japanese'], true)) {
            $m = $monthNum ?? ($monthCode !== null && preg_match('/^M(\d{2})/', $monthCode, $mm) ? (int) $mm[1] : 0);
            $isoYear = $calendar === 'roc' ? ($year + 1911) : $year;
            return ['year' => $isoYear, 'month' => $m, 'day' => $day];
        }
        // Ethiopic / hebrew / chinese / dangi do not require IntlCalendar.
        // Other calendars (islamic*, persian, indian, coptic, ...) still need
        // it, so the ICU class gate is deferred until after the pure-PHP
        // branches are tried.
        $purePhp = in_array(
            $calendar,
            ['ethiopic', 'ethioaa', 'ethiopic-amete-alem', 'hebrew', 'chinese', 'dangi'],
            true,
        );
        if (!$purePhp && !class_exists('IntlCalendar', false)) {
            return null;
        }
        // islamic-umalqura's astronomical lookup tables only span ~1300-1600 AH.
        // Outside a generous bound around that range ICU silently extrapolates
        // and produces results that diverge from the spec. SpiderMonkey's
        // tests reject such inputs explicitly (see icu4x #4914).
        if ($calendar === 'islamic-umalqura' && ($year < 1 || $year > 9999)) {
            return null;
        }
        // Resolve ICU 0-indexed month from monthCode (preferred) or month number.
        $icuMonth = null;
        $isLeapMonth = false;
        if ($monthCode !== null) {
            if (preg_match('/^M(\d{2})(L?)$/', $monthCode, $mm)) {
                $codeNum = (int) $mm[1];
                $isLeapMonth = $mm[2] === 'L';
                if ($calendar === 'hebrew') {
                    if ($codeNum >= 1 && $codeNum <= 5 && !$isLeapMonth) {
                        $icuMonth = $codeNum - 1;
                    } elseif ($codeNum === 5 && $isLeapMonth) {
                        $icuMonth = 5; // Adar I (only valid in leap year).
                    } elseif ($codeNum >= 6 && $codeNum <= 12 && !$isLeapMonth) {
                        $icuMonth = $codeNum;
                    }
                } else {
                    // Most calendars: M01..MNN → ICU 0..NN-1.
                    $icuMonth = $codeNum - 1;
                }
            }
        } elseif ($monthNum !== null) {
            // 1-indexed month → ICU 0-indexed (most calendars).
            if (in_array($calendar, ['chinese', 'dangi'], true)) {
                // In a leap year, month positions 1..13 chronologically include
                // the leap month between certain non-leap months.
                $leapIcu = self::chineseLeapMonthIndex($calendar, $year);
                if ($leapIcu === null) {
                    // Non-leap year: 1..12 → ICU 0..11.
                    $icuMonth = $monthNum - 1;
                } else {
                    // Leap year: chronologically months 1..(leapIcu+1) → non-leap
                    // ICU 0..leapIcu; month (leapIcu+2) → leap version of leapIcu;
                    // months (leapIcu+3)..13 → ICU (leapIcu+1)..11.
                    if ($monthNum >= 1 && $monthNum <= $leapIcu + 1) {
                        $icuMonth = $monthNum - 1;
                    } elseif ($monthNum === $leapIcu + 2) {
                        $icuMonth = $leapIcu;
                        $isLeapMonth = true;
                    } elseif ($monthNum >= $leapIcu + 3 && $monthNum <= 13) {
                        $icuMonth = $monthNum - 2;
                    }
                }
            } elseif ($calendar === 'hebrew') {
                $isLeap = self::isHebrewLeapYear($year);
                if ($isLeap) {
                    // Spec months: 1..5=Tishri..Shevat, 6=AdarI, 7=AdarII, ..., 13=Elul.
                    if ($monthNum >= 1 && $monthNum <= 5) {
                        $icuMonth = $monthNum - 1;
                    } elseif ($monthNum === 6) {
                        $icuMonth = 5; // Adar I
                    } elseif ($monthNum >= 7 && $monthNum <= 13) {
                        $icuMonth = $monthNum - 1;
                    }
                } else {
                    // Non-leap: 1..12.
                    if ($monthNum >= 1 && $monthNum <= 5) {
                        $icuMonth = $monthNum - 1;
                    } elseif ($monthNum >= 6 && $monthNum <= 12) {
                        $icuMonth = $monthNum;
                    }
                }
            } else {
                $icuMonth = $monthNum - 1;
            }
        }
        if ($icuMonth === null) {
            return null;
        }
        // Ethiopic / ethioaa: pure-PHP 13-month arithmetic.
        if ($calendar === 'ethiopic' || $calendar === 'ethioaa' || $calendar === 'ethiopic-amete-alem') {
            if ($isLeapMonth) {
                // Ethiopic has no leap months.
                return null;
            }
            // icuMonth resolved above is 0-indexed from monthCode/monthNum.
            // For ethiopic that maps 1:1 to spec month (M01..M13) → icuMonth 0..12.
            $eMonth = $icuMonth + 1;
            if ($eMonth < 1 || $eMonth > 13) {
                return null;
            }
            $canonYear = self::ethiopicCanonicalYear($calendar, $year);
            $dim = self::ethiopicDaysInMonth($canonYear, $eMonth);
            if ($day < 1 || $day > $dim) {
                return null;
            }
            return self::ethiopicToIsoDate($canonYear, $eMonth, $day);
        }
        // Hebrew: bypass ICU. ICU's getActualMaximum reports stale month
        // lengths for some years, which causes Cheshvan/Kislev to come back
        // as 29 even when Rosh Hashanah postponement makes them 30.
        if ($calendar === 'hebrew') {
            $isLeap = self::isHebrewLeapYear($year);
            // ICU month indices range 0..12 in both leap and non-leap years;
            // index 5 is Adar I in leap years and unused in non-leap years.
            if ($icuMonth < 0 || $icuMonth > 12) {
                return null;
            }
            if (!$isLeap && $icuMonth === 5) {
                return null;
            }
            // Validate leap-month placement: Adar I lives at icuMonth=5
            // and only exists in leap years (the !$isLeap+icuMonth=5 case
            // is already short-circuited above, so checking the index is
            // sufficient here).
            if ($isLeapMonth && $icuMonth !== 5) {
                return null;
            }
            $dim = self::hebrewDaysInMonth($year, $icuMonth);
            if ($day < 1 || $day > $dim) {
                return null;
            }
            return self::hebrewToIsoDate($year, $icuMonth, $day);
        }
        // Chinese / dangi: pure-PHP table lookup (CI-independent).
        if ($calendar === 'chinese' || $calendar === 'dangi') {
            self::$chineseDispatchCalendar = $calendar;
            if ($icuMonth < 0 || $icuMonth > 11) {
                return null;
            }
            if ($isLeapMonth) {
                $leapIcu = self::chineseLeapMonthIcuFromTable($year);
                if ($leapIcu !== $icuMonth) {
                    return null; // caller decides constrain vs reject.
                }
            }
            $chronoIdx = self::chineseChronoIdxFromIcu($year, $icuMonth, $isLeapMonth);
            if ($chronoIdx === null) {
                return null;
            }
            $dim = self::chineseDaysInMonthByChrono($year, $chronoIdx);
            if ($day < 1 || $day > $dim) {
                return null;
            }
            $iso = self::chineseToIsoDate($year, $icuMonth, $isLeapMonth, $day);
            if ($iso !== null) {
                return $iso;
            }
            // Out of table range: fall through to ICU fallback.
        }
        if (!class_exists('IntlCalendar', false)) {
            return null;
        }
        try {
            $icuCal = $calendar;
            static $aliasMap = [
                'islamicc' => 'islamic-civil',
                'ethioaa' => 'ethiopic-amete-alem',
            ];
            if (isset($aliasMap[$calendar])) {
                $icuCal = $aliasMap[$calendar];
            }
            $cal = \IntlCalendar::createInstance('UTC', "en@calendar={$icuCal}");
            // Chinese / Dangi use a 60-year sexagenary YEAR with a separate
            // EXTENDED_YEAR that holds the actual year. setDate sets YEAR; we
            // need to pre-set EXTENDED_YEAR to the spec year.
            if (in_array($calendar, ['chinese', 'dangi'], true)) {
                // If the user asked for a leap month, validate that the
                // year actually has its leap at this position before ICU
                // silently normalizes the invalid state.
                if ($isLeapMonth) {
                    $leapIcu = self::chineseLeapMonthIndex($calendar, $year);
                    if ($leapIcu !== $icuMonth) {
                        return null; // caller decides constrain vs reject.
                    }
                }
                $cal->set(\IntlCalendar::FIELD_EXTENDED_YEAR, $year);
                $cal->set(\IntlCalendar::FIELD_MONTH, $icuMonth);
                $cal->set(\IntlCalendar::FIELD_IS_LEAP_MONTH, $isLeapMonth ? 1 : 0);
                $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, $day);
            } else {
                $cal->setDate($year, $icuMonth, $day);
            }
            $epochMs = $cal->getTime();
            $epochSec = (int) ($epochMs / 1000);
            $isoStr = gmdate('Y-m-d', $epochSec);
            // gmdate prefixes negative ISO years with "-", so a naive
            // explode("-") splits it into ["", "Y", "m", "d"]. Match the
            // signed year explicitly.
            if (preg_match('/^(-?\d+)-(\d{2})-(\d{2})$/', $isoStr, $m) === 1) {
                return [
                    'year' => (int) $m[1],
                    'month' => (int) $m[2],
                    'day' => (int) $m[3],
                ];
            }
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** True if the Hebrew year has 13 months (leap). */
    private static function isHebrewLeapYear(int $year): bool
    {
        $r = (7 * $year + 1) % 19;
        if ($r < 0) {
            $r += 19;
        }
        return $r < 7;
    }

    /**
     * Calendar-elapsed-days through 1 Tishrei of the given AM year,
     * counted from the Hebrew epoch (1 Tishrei AM 1 = day 0).
     *
     * This is Reingold-Dershowitz "Calendrical Calculations" §8.2 step 1:
     * the molad-of-Tishri postponed only by the Lo ADU rule. Year-length
     * correction (the "no 356-day, no 382-day year" fix) is applied
     * separately in {@see hebrewNewYearDay}.
     */
    private static function hebrewCalendarElapsedDays(int $year): int
    {
        $monthsElapsed = intdiv(235 * $year - 234, 19);
        // Parts of the lunation: 24 hours/day * 1080 parts/hour = 25920 parts/day.
        // Each lunation: 29d 12h 793p.
        $partsElapsed = 12084 + 13753 * $monthsElapsed;
        $day = 29 * $monthsElapsed + intdiv($partsElapsed, 25920);
        // Lo ADU rosh: postpone by 1 if the molad would fall on Sun/Wed/Fri.
        // Reingold's compact form: if (3*(day+1)) mod 7 < 3, postpone.
        $r = (3 * ($day + 1)) % 7;
        if ($r < 0) {
            $r += 7;
        }
        if ($r < 3) {
            $day++;
        }
        return $day;
    }

    /**
     * Day count from the Hebrew epoch to 1 Tishrei of the given AM year,
     * including the year-length correction (Gatarad / Betutkpat fix-up).
     *
     * Per Reingold-Dershowitz, the postponement on year Y depends on both
     * the (Y-1, Y) gap and the (Y, Y+1) gap:
     *   - if (Y, Y+1) raw = 356, postpone year Y by 2 days,
     *   - if (Y-1, Y) raw = 382, postpone year Y by 1 day.
     */
    private static function hebrewNewYearDay(int $year): int
    {
        $day = self::hebrewCalendarElapsedDays($year);
        $prevGap = $day - self::hebrewCalendarElapsedDays($year - 1);
        $nextGap = self::hebrewCalendarElapsedDays($year + 1) - $day;
        if ($nextGap === 356) {
            $day += 2;
        } elseif ($prevGap === 382) {
            $day += 1;
        }
        return $day;
    }

    /**
     * Days from ISO 1970-01-01 to 1 Tishrei of the given Hebrew year.
     * Hebrew epoch = ISO -3760-09-07 proleptic Gregorian = day -2092590.
     */
    private static function hebrewElapsedDaysToFirstTishrei(int $year): int
    {
        return self::hebrewNewYearDay($year) - 2092590;
    }

    /** Number of days in a Hebrew year (353/354/355 regular, 383/384/385 leap). */
    private static function hebrewYearLength(int $year): int
    {
        return self::hebrewNewYearDay($year + 1) - self::hebrewNewYearDay($year);
    }

    /**
     * Days in the given Hebrew month using ICU's month indexing (which is
     * stable across leap and non-leap years):
     *   0=Tishrei, 1=Cheshvan, 2=Kislev, 3=Tevet, 4=Shevat,
     *   5=Adar I (leap years only), 6=Adar/Adar II,
     *   7=Nisan, 8=Iyar, 9=Sivan, 10=Tammuz, 11=Av, 12=Elul.
     * In non-leap years ICU index 5 is invalid (callers must skip it).
     */
    private static function hebrewDaysInMonth(int $year, int $icuMonth): int
    {
        $isLeap = self::isHebrewLeapYear($year);
        $yearLen = self::hebrewYearLength($year);
        // Cheshvan (ICU index 1): 30 only in long years (355 / 385).
        if ($icuMonth === 1) {
            return ($yearLen === 355 || $yearLen === 385) ? 30 : 29;
        }
        // Kislev (ICU index 2): 29 only in short years (353 / 383).
        if ($icuMonth === 2) {
            return ($yearLen === 353 || $yearLen === 383) ? 29 : 30;
        }
        // Fixed-length months by ICU index.
        static $fixed = [
            0 => 30,  // Tishrei
            3 => 29,  // Tevet
            4 => 30,  // Shevat
            6 => 29,  // Adar / Adar II
            7 => 30,  // Nisan
            8 => 29,  // Iyar
            9 => 30,  // Sivan
            10 => 29, // Tammuz
            11 => 30, // Av
            12 => 29, // Elul
        ];
        if (isset($fixed[$icuMonth])) {
            return $fixed[$icuMonth];
        }
        // Adar I (leap years only): 30 days.
        if ($icuMonth === 5 && $isLeap) {
            return 30;
        }
        return 0;
    }

    /**
     * ISO date (y/m/d) for the given Hebrew (year, ICU month index, day).
     * Returns ['year' => , 'month' => , 'day' => ]. ICU month index 5 is
     * Adar I (leap years only); in non-leap years the caller must NOT pass
     * icuMonth=5.
     *
     * @return array{year: int, month: int, day: int}
     */
    private static function hebrewToIsoDate(int $hYear, int $icuMonth, int $hDay): array
    {
        $isLeap = self::isHebrewLeapYear($hYear);
        if (!$isLeap && $icuMonth === 5) {
            // Treat as Adar (icuMonth=6) for safety.
            $icuMonth = 6;
        }
        if ($icuMonth < 0 || $icuMonth > 12) {
            $icuMonth = max(0, min(12, $icuMonth));
        }
        $epochDays = self::hebrewElapsedDaysToFirstTishrei($hYear);
        for ($m = 0; $m < $icuMonth; $m++) {
            // Skip the Adar I slot (5) in non-leap years; it has no days.
            if (!$isLeap && $m === 5) {
                continue;
            }
            $epochDays += self::hebrewDaysInMonth($hYear, $m);
        }
        $epochDays += $hDay - 1;
        return self::isoDateFromDays($epochDays);
    }

    /**
     * Convert days-since-1970-01-01 to an ISO date.
     *
     * @return array{year: int, month: int, day: int}
     */
    private static function isoDateFromDays(int $days): array
    {
        // Inverse of isoDateToDays (Howard Hinnant civil_from_days).
        $days += 719468;
        $era = intdiv($days >= 0 ? $days : $days - 146096, 146097);
        $doe = $days - $era * 146097;
        $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);
        $y = $yoe + $era * 400;
        $doy = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));
        $mp = intdiv(5 * $doy + 2, 153);
        $d = $doy - intdiv(153 * $mp + 2, 5) + 1;
        $m = $mp < 10 ? $mp + 3 : $mp - 9;
        $y += $m <= 2 ? 1 : 0;
        return ['year' => $y, 'month' => $m, 'day' => $d];
    }

    /**
     * Convert an ISO date to Hebrew (AM year, ICU month index, day).
     *
     * @return array{year: int, icuMonth: int, day: int}
     */
    private static function isoToHebrewDate(int $isoY, int $isoM, int $isoD): array
    {
        $days = self::isoDateToDays($isoY, $isoM, $isoD);
        // Estimate Hebrew year. Hebrew year ≈ iso + 3760 + (after Tishrei → +1).
        $approx = $isoY + 3761;
        // Walk down from approx until 1 Tishrei <= days.
        $year = $approx + 1;
        while (self::hebrewElapsedDaysToFirstTishrei($year) > $days) {
            $year--;
        }
        // Year now is the Hebrew year containing the date.
        $offset = $days - self::hebrewElapsedDaysToFirstTishrei($year);
        $isLeap = self::isHebrewLeapYear($year);
        $icuMonth = 0;
        while ($icuMonth <= 12) {
            // Skip the Adar I slot (5) in non-leap years.
            if (!$isLeap && $icuMonth === 5) {
                $icuMonth++;
                continue;
            }
            $dim = self::hebrewDaysInMonth($year, $icuMonth);
            if ($dim > 0 && $offset < $dim) {
                break;
            }
            $offset -= $dim;
            $icuMonth++;
        }
        return ['year' => $year, 'icuMonth' => $icuMonth, 'day' => $offset + 1];
    }

    /** Convert ICU 0-indexed month to spec monthCode for the given calendar. */
    private static function calendarMonthToCode(string $calendar, int $year, int $icuMonth, bool $isLeap = false): string
    {
        if ($calendar === 'hebrew') {
            if ($icuMonth >= 0 && $icuMonth <= 4) {
                return 'M' . str_pad((string) ($icuMonth + 1), 2, '0', STR_PAD_LEFT);
            }
            if ($icuMonth === 5) {
                return self::isHebrewLeapYear($year) ? 'M05L' : 'M06';
            }
            return 'M' . str_pad((string) $icuMonth, 2, '0', STR_PAD_LEFT);
        }
        if (in_array($calendar, ['chinese', 'dangi'], true)) {
            // ICU month is 0..11 for the non-leap month code; leap month
            // shares the same MONTH index but with IS_LEAP_MONTH=1, exposed
            // by the spec as M(NN)L.
            $base = 'M' . str_pad((string) ($icuMonth + 1), 2, '0', STR_PAD_LEFT);
            return $isLeap ? $base . 'L' : $base;
        }
        return 'M' . str_pad((string) ($icuMonth + 1), 2, '0', STR_PAD_LEFT);
    }

    /** Calendar-specific 1-indexed month number (matches the spec's `month` getter). */
    private static function calendarMonthToOneBased(string $calendar, int $year, int $icuMonth, bool $isLeap = false): int
    {
        if ($calendar === 'hebrew') {
            if (self::isHebrewLeapYear($year)) {
                if ($icuMonth >= 0 && $icuMonth <= 4) {
                    return $icuMonth + 1;
                }
                if ($icuMonth === 5) {
                    return 6;
                }
                return $icuMonth + 1;
            }
            if ($icuMonth >= 0 && $icuMonth <= 4) {
                return $icuMonth + 1;
            }
            if ($icuMonth === 5 || $icuMonth === 6) {
                return 6;
            }
            return $icuMonth;
        }
        if (in_array($calendar, ['chinese', 'dangi'], true)) {
            $leapIcu = self::chineseLeapMonthIndex($calendar, $year);
            if ($leapIcu === null) {
                return $icuMonth + 1;
            }
            // Leap year: chronological positions:
            //  ICU 0..leapIcu      → 1..(leapIcu+1)  (non-leap)
            //  ICU leapIcu (L)     → leapIcu+2       (leap)
            //  ICU (leapIcu+1)..11 → (leapIcu+3)..13 (non-leap)
            if ($isLeap) {
                return $leapIcu + 2;
            }
            if ($icuMonth <= $leapIcu) {
                return $icuMonth + 1;
            }
            return $icuMonth + 2;
        }
        return $icuMonth + 1;
    }

    /** True if ICU's month index represents a leap month for that year. */
    private static function calendarMonthIsLeap(string $calendar, int $year, int $icuMonth): bool
    {
        if ($calendar === 'hebrew') {
            return self::isHebrewLeapYear($year) && $icuMonth === 5;
        }
        return false;
    }

    // -----------------------------------------------------------------------
    // Ethiopic / ethioaa calendar (pure-PHP, ICU-independent)
    //
    // 13-month calendar: months 1..12 = 30 days each, month 13 (Pagume) =
    // 5 or 6 days. Leap rule: year y is leap when y mod 4 == 3
    // (Reingold-Dershowitz "Calendrical Calculations" §3). Ethiopic year 1
    // EE begins 1 Meskerem = ISO 8 AD Aug 27 Gregorian proleptic (= Julian
    // Aug 29, 8 AD). Days from ISO 1970-01-01 to that date = -716367.
    // ethioaa = ethiopic + 5500 (same arithmetic, shifted year label).
    // -----------------------------------------------------------------------

    /** Days from ISO 1970-01-01 to 1 Meskerem 1 EE (Aug 27, 8 AD Gregorian). */
    private const ETHIOPIC_EPOCH_DAYS = -716367;

    /** ethioaa = ethiopic + 5500 (Amete Alem precedes Amete Mihret by 5500 EE). */
    private const ETHIOAA_YEAR_OFFSET = 5500;

    /** True if the given ethiopic year is a leap year (y mod 4 == 3). */
    private static function isEthiopicLeapYear(int $year): bool
    {
        $r = $year % 4;
        if ($r < 0) {
            $r += 4;
        }
        return $r === 3;
    }

    /** Days in the given ethiopic year (365 or 366). */
    private static function ethiopicYearLength(int $year): int
    {
        return self::isEthiopicLeapYear($year) ? 366 : 365;
    }

    /** Days in the given ethiopic month: 30 for 1..12, 5 or 6 for 13. */
    private static function ethiopicDaysInMonth(int $year, int $month): int
    {
        if ($month >= 1 && $month <= 12) {
            return 30;
        }
        if ($month === 13) {
            return self::isEthiopicLeapYear($year) ? 6 : 5;
        }
        return 0;
    }

    /**
     * Days from ISO 1970-01-01 to 1 Meskerem of the given ethiopic year.
     * For 1 Meskerem 1 EE this is ETHIOPIC_EPOCH_DAYS.
     */
    private static function ethiopicNewYearDay(int $year): int
    {
        $y1 = $year - 1;
        if ($y1 >= 0) {
            // Number of leap years in [1..y1] is floor((y1 - 3) / 4) + 1
            // if y1 >= 3, else 0.
            $leaps = $y1 >= 3 ? intdiv($y1 - 3, 4) + 1 : 0;
            return self::ETHIOPIC_EPOCH_DAYS + 365 * $y1 + $leaps;
        }
        // year <= 0: walk forward summing year lengths.
        $days = self::ETHIOPIC_EPOCH_DAYS;
        for ($yy = $year; $yy < 1; $yy++) {
            $days -= self::ethiopicYearLength($yy);
        }
        return $days;
    }

    /**
     * Convert (ethiopic year, month 1..13, day 1..30) to ISO ['year','month','day'].
     *
     * @return array{year: int, month: int, day: int}
     */
    private static function ethiopicToIsoDate(int $year, int $month, int $day): array
    {
        $days = self::ethiopicNewYearDay($year);
        for ($m = 1; $m < $month; $m++) {
            $days += self::ethiopicDaysInMonth($year, $m);
        }
        $days += $day - 1;
        return self::isoDateFromDays($days);
    }

    /**
     * Convert ISO (y,m,d) to ['year' => ethiopicYear, 'month' => 1..13, 'day' => 1..30].
     *
     * @return array{year: int, month: int, day: int}
     */
    private static function isoToEthiopicDate(int $isoY, int $isoM, int $isoD): array
    {
        $days = self::isoDateToDays($isoY, $isoM, $isoD);
        // Approximate ethiopic year via 4-year cycle.
        $offset = $days - self::ETHIOPIC_EPOCH_DAYS;
        $year = intdiv($offset, 1461) * 4 + 1;
        // Refine.
        while (self::ethiopicNewYearDay($year + 1) <= $days) {
            $year++;
        }
        while (self::ethiopicNewYearDay($year) > $days) {
            $year--;
        }
        $dayOfYear = $days - self::ethiopicNewYearDay($year); // 0-indexed
        if ($dayOfYear < 360) {
            $month = intdiv($dayOfYear, 30) + 1;
            $dayOfMonth = ($dayOfYear % 30) + 1;
        } else {
            $month = 13;
            $dayOfMonth = $dayOfYear - 360 + 1;
        }
        return ['year' => $year, 'month' => $month, 'day' => $dayOfMonth];
    }

    /**
     * Resolve a user-supplied ethiopic / ethioaa year to the canonical
     * ethiopic year (ethioaa year y → ethiopic year y - 5500).
     */
    private static function ethiopicCanonicalYear(string $calendar, int $userYear): int
    {
        if ($calendar === 'ethioaa' || $calendar === 'ethiopic-amete-alem') {
            return $userYear - self::ETHIOAA_YEAR_OFFSET;
        }
        return $userYear;
    }

    /**
     * Map an internal ethiopic year to the user-visible year for the given
     * calendar (ethioaa users see year + 5500).
     */
    private static function ethiopicUserYear(string $calendar, int $ethiopicYear): int
    {
        if ($calendar === 'ethioaa' || $calendar === 'ethiopic-amete-alem') {
            return $ethiopicYear + self::ETHIOAA_YEAR_OFFSET;
        }
        return $ethiopicYear;
    }

    /**
     * Compute (years, months, days) between two ISO dates in the ethiopic
     * calendar's terms, where smlY/M/D <= lrgY/M/D. Mirrors the
     * spec's DifferenceISODate semantics for a 13-month calendar.
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function ethiopicYearsMonthsDaysBetween(
        int $smlY,
        int $smlM,
        int $smlD,
        int $lrgY,
        int $lrgM,
        int $lrgD,
        string $largestUnit,
    ): ?array {
        $smlE = self::isoToEthiopicDate($smlY, $smlM, $smlD);
        $endDays = self::isoDateToDays($lrgY, $lrgM, $lrgD);

        $years = 0;
        if ($largestUnit === 'year') {
            $cand = $smlE['year'];
            while (true) {
                $probeIso = self::ethiopicToIsoDate(
                    $cand + 1,
                    $smlE['month'],
                    min($smlE['day'], self::ethiopicDaysInMonth($cand + 1, $smlE['month'])),
                );
                $probeDays = self::isoDateToDays(
                    $probeIso['year'],
                    $probeIso['month'],
                    $probeIso['day'],
                );
                if ($probeDays > $endDays) {
                    break;
                }
                $cand++;
                $years++;
            }
        }

        $anchorY = $smlE['year'] + $years;
        $anchorM = $smlE['month'];
        $anchorD = min($smlE['day'], self::ethiopicDaysInMonth($anchorY, $anchorM));

        $months = 0;
        if ($largestUnit !== 'day' && $largestUnit !== 'week') {
            while (true) {
                $nextM = $anchorM + 1;
                $nextY = $anchorY;
                if ($nextM > 13) {
                    $nextM = 1;
                    $nextY++;
                }
                $nextD = min($smlE['day'], self::ethiopicDaysInMonth($nextY, $nextM));
                $probeIso = self::ethiopicToIsoDate($nextY, $nextM, $nextD);
                $probeDays = self::isoDateToDays(
                    $probeIso['year'],
                    $probeIso['month'],
                    $probeIso['day'],
                );
                if ($probeDays > $endDays) {
                    break;
                }
                $anchorY = $nextY;
                $anchorM = $nextM;
                $anchorD = $nextD;
                $months++;
            }
        }

        $anchorIso = self::ethiopicToIsoDate($anchorY, $anchorM, $anchorD);
        $anchorDays = self::isoDateToDays(
            $anchorIso['year'],
            $anchorIso['month'],
            $anchorIso['day'],
        );
        $days = $endDays - $anchorDays;
        if ($days < 0) {
            return null;
        }
        return [$years, $months, $days];
    }

    /**
     * Ethiopic addition: add (years, months) in ethiopic terms then constrain
     * the day. Returns [isoY, isoM, isoD] in the proleptic Gregorian calendar.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function ethiopicAddYearsMonthsIso(
        int $isoY,
        int $isoM,
        int $isoD,
        int $years,
        int $months,
        string $overflow,
    ): array {
        $e = self::isoToEthiopicDate($isoY, $isoM, $isoD);
        $startDay = $e['day'];
        $newY = $e['year'] + $years;
        $newM = $e['month'] + $months;
        // Normalize month overflow with 13 months per year.
        while ($newM > 13) {
            $newM -= 13;
            $newY++;
        }
        while ($newM < 1) {
            $newM += 13;
            $newY--;
        }
        $dim = self::ethiopicDaysInMonth($newY, $newM);
        $newD = $startDay;
        if ($newD > $dim) {
            if ($overflow === 'reject') {
                throw new RangeError("Day {$startDay} out of range after calendar arithmetic");
            }
            $newD = $dim;
        }
        $iso = self::ethiopicToIsoDate($newY, $newM, $newD);
        return [$iso['year'], $iso['month'], $iso['day']];
    }

    /**
     * Hebrew addition: add (years, months) in hebrew terms then constrain
     * the day. Avoids ICU's leap-month boundary errors on older ICU.
     *
     * Algorithm:
     *   1) Add `years` while preserving the monthCode. ICU month indices
     *      0..4 and 6..12 are stable across leap and non-leap years (they
     *      map to monthCodes M01..M05 and M06..M12 respectively). Only
     *      ICU index 5 (Adar I / M05L) is leap-only — when crossing into
     *      a non-leap year we constrain it to ICU 6 (M06 = Adar).
     *   2) Add `months` chronologically, treating leap years as having 13
     *      months and non-leap years as 12, so M05L counts as one month
     *      between Shevat and Adar.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function hebrewAddYearsMonthsIso(
        int $isoY,
        int $isoM,
        int $isoD,
        int $years,
        int $months,
        string $overflow,
    ): array {
        $h = self::isoToHebrewDate($isoY, $isoM, $isoD);
        $newY = $h['year'] + $years;
        $newIcuMonth = $h['icuMonth'];
        $startDay = $h['day'];

        // Constrain leap-only Adar I into Adar when crossing into a non-leap year.
        if (!self::isHebrewLeapYear($newY) && $newIcuMonth === 5) {
            $newIcuMonth = 6;
        }

        // Apply remaining month delta chronologically.
        if ($months !== 0) {
            $pos = self::hebrewChronoPosFromIcu($newY, $newIcuMonth);
            $pos += $months;
            while (true) {
                $monthsInY = self::isHebrewLeapYear($newY) ? 13 : 12;
                if ($pos > $monthsInY) {
                    $pos -= $monthsInY;
                    $newY++;
                    continue;
                }
                if ($pos < 1) {
                    $newY--;
                    $monthsInPrev = self::isHebrewLeapYear($newY) ? 13 : 12;
                    $pos += $monthsInPrev;
                    continue;
                }
                break;
            }
            $newIcuMonth = self::hebrewIcuFromChronoPos($newY, $pos);
        }

        $dim = self::hebrewDaysInMonth($newY, $newIcuMonth);
        $newD = $startDay;
        if ($newD > $dim) {
            if ($overflow === 'reject') {
                throw new RangeError("Day {$startDay} out of range after calendar arithmetic");
            }
            $newD = $dim;
        }
        $iso = self::hebrewToIsoDate($newY, $newIcuMonth, $newD);
        return [$iso['year'], $iso['month'], $iso['day']];
    }

    /**
     * Convert an ICU hebrew month index (0..12) to a chronological position
     * (1..N within the year, where N is 12 or 13 depending on leap status).
     *   Non-leap year: ICU 0..4 → pos 1..5; ICU 6..12 → pos 6..12.
     *   Leap year:     ICU 0..5 → pos 1..6 (5=AdarI=6); ICU 6 (AdarII) → 7;
     *                  ICU 7..12 → pos 8..13.
     */
    private static function hebrewChronoPosFromIcu(int $year, int $icuMonth): int
    {
        $isLeap = self::isHebrewLeapYear($year);
        if (!$isLeap) {
            if ($icuMonth <= 4) {
                return $icuMonth + 1; // 0..4 → 1..5
            }
            if ($icuMonth >= 6) {
                return $icuMonth; // 6..12 → 6..12
            }
            // ICU index 5 should not occur in non-leap years; clamp.
            return 6;
        }
        if ($icuMonth <= 5) {
            return $icuMonth + 1; // 0..5 → 1..6
        }
        return $icuMonth + 1; // 6..12 → 7..13
    }

    /**
     * Inverse of hebrewChronoPosFromIcu.
     */
    private static function hebrewIcuFromChronoPos(int $year, int $pos): int
    {
        $isLeap = self::isHebrewLeapYear($year);
        if (!$isLeap) {
            if ($pos >= 1 && $pos <= 5) {
                return $pos - 1;
            }
            // pos 6..12 → ICU 6..12. Drop pos 13 to Elul (12).
            if ($pos >= 6 && $pos <= 12) {
                return $pos;
            }
            return 12;
        }
        if ($pos >= 1 && $pos <= 6) {
            return $pos - 1; // 1..6 → ICU 0..5
        }
        if ($pos >= 7 && $pos <= 13) {
            return $pos - 1; // 7..13 → ICU 6..12
        }
        return 12;
    }

    // -----------------------------------------------------------------------
    // Chinese / Dangi calendar (pure-PHP, ICU-independent)
    //
    // The Chinese calendar is astronomically determined: each month begins
    // on the day of a new moon in Beijing local time, and a leap month is
    // inserted whenever a year between two winter-solstice-containing
    // months has 13 new moons. Reingold-Dershowitz "Calendrical
    // Calculations" §19 gives the algorithm in terms of solar longitude
    // and lunar phase, both of which require high-precision astronomy
    // (errors of a few minutes around midnight Beijing time shift a date
    // by one day).
    //
    // To stay independent of the host ICU version (Ubuntu CI ships ICU
    // 70/74 whose leap-month placements diverge from Unicode 16 / V8),
    // phasis ships a precomputed table generated from an R-D-equivalent
    // implementation (ICU 76+). The table is regenerated by
    // bin/gen-chinese-table.php and consumed here. The runtime never
    // calls IntlCalendar for chinese / dangi arithmetic.
    //
    // dangi is the Korean ICU calendar; the underlying month / leap-month
    // arithmetic is identical to chinese (only the era differs), so we
    // route both through the same table.
    // -----------------------------------------------------------------------

    /**
     * Lazily-decoded packed tables for chinese / dangi calendar lookups.
     * Indexed by calendar id ('chinese' or 'dangi'). Each entry is a
     * tuple [blob, start, end].
     *
     * @var array<string,array{blob:string,start:int,end:int}>
     */
    private static array $lunisolarTables = [];

    /** Decode the chinese / dangi calendar table for the given id on first use. */
    private static function loadLunisolarTable(string $calendar): void
    {
        if (isset(self::$lunisolarTables[$calendar])) {
            return;
        }
        // dirname(__DIR__) backs out one level to src/BuiltIn/ so the
        // data path resolves to src/BuiltIn/data/<cal>_calendar.php.
        // Originally this trait sat in src/BuiltIn/TemporalObject.php
        // where `__DIR__/data/` was correct; the trait extraction
        // pushed us one directory deeper.
        $path = dirname(__DIR__) . '/data/' . $calendar . '_calendar.php';
        if (!is_file($path)) {
            self::$lunisolarTables[$calendar] = ['blob' => '', 'start' => 0, 'end' => 0];
            return;
        }
        $data = require $path;
        $compressed = base64_decode($data['blob'], true);
        if ($compressed === false) {
            self::$lunisolarTables[$calendar] = ['blob' => '', 'start' => 0, 'end' => 0];
            return;
        }
        $blob = gzuncompress($compressed);
        if ($blob === false) {
            self::$lunisolarTables[$calendar] = ['blob' => '', 'start' => 0, 'end' => 0];
            return;
        }
        self::$lunisolarTables[$calendar] = [
            'blob' => $blob,
            'start' => (int) $data['start'],
            'end' => (int) $data['end'],
        ];
    }

    /**
     * Calendar context for the in-flight chinese / dangi operation. Set
     * by the dispatcher at entry to chineseDispatch() and consumed by
     * every internal helper, so we don't have to thread the calendar id
     * through ~14 helper signatures. PHP is single-threaded so the
     * static is safe; chineseDispatch() restores the previous value via
     * try/finally to keep nested calls correct (e.g. when dangi probes
     * during a chinese reference search, or vice versa).
     */
    private static string $chineseDispatchCalendar = 'chinese';

    /**
     * Look up the packed record for the in-flight calendar's extended-year.
     *
     * @return array{newYearDays:int,leapIcuMonth:int,monthLenBits:int,monthCount:int}|null
     */
    private static function chineseYearInfo(int $extYear): ?array
    {
        $calendar = self::$chineseDispatchCalendar;
        self::loadLunisolarTable($calendar);
        $tbl = self::$lunisolarTables[$calendar];
        if ($tbl['blob'] === '') {
            return null;
        }
        if ($extYear < $tbl['start'] || $extYear > $tbl['end']) {
            return null;
        }
        $offset = ($extYear - $tbl['start']) * 8;
        $record = substr($tbl['blob'], $offset, 8);
        if (strlen($record) !== 8) {
            return null;
        }
        $unpacked = unpack('lnewYearDays/cleapIcu/vmonthLenBits/CmonthCount', $record);
        if ($unpacked === false) {
            return null;
        }
        $monthCount = (int) $unpacked['monthCount'];
        if ($monthCount !== 12 && $monthCount !== 13) {
            return null;
        }
        return [
            'newYearDays' => (int) $unpacked['newYearDays'],
            'leapIcuMonth' => (int) $unpacked['leapIcu'],
            'monthLenBits' => (int) $unpacked['monthLenBits'],
            'monthCount' => $monthCount,
        ];
    }

    /** Days in the chinese month at chronological index (0-indexed) for the year. */
    private static function chineseDaysInMonthByChrono(int $extYear, int $chronoIdx): int
    {
        $info = self::chineseYearInfo($extYear);
        if ($info === null) {
            return 30;
        }
        if ($chronoIdx < 0 || $chronoIdx >= $info['monthCount']) {
            return 30;
        }
        return (($info['monthLenBits'] >> $chronoIdx) & 1) === 1 ? 30 : 29;
    }

    /** Days in chinese year (354..385). */
    private static function chineseYearLength(int $extYear): int
    {
        $info = self::chineseYearInfo($extYear);
        if ($info === null) {
            return 354;
        }
        $days = 0;
        for ($i = 0; $i < $info['monthCount']; $i++) {
            $days += (($info['monthLenBits'] >> $i) & 1) === 1 ? 30 : 29;
        }
        return $days;
    }

    /** Leap ICU-month index (0..11) for the chinese year, or null. */
    private static function chineseLeapMonthIcuFromTable(int $extYear): ?int
    {
        $info = self::chineseYearInfo($extYear);
        if ($info === null) {
            return null;
        }
        $l = $info['leapIcuMonth'];
        if ($l < 0 || $l > 11) {
            return null;
        }
        return $l;
    }

    /**
     * Convert ISO (y,m,d) to chinese ['year','icuMonth','isLeap','day'].
     *
     * @return array{year:int,icuMonth:int,isLeap:bool,day:int}|null
     */
    private static function isoToChineseDate(int $isoY, int $isoM, int $isoD): ?array
    {
        $days = self::isoDateToDays($isoY, $isoM, $isoD);
        $extYear = $isoY;
        $info = self::chineseYearInfo($extYear);
        if ($info === null) {
            return null;
        }
        $guardLimit = 6;
        while ($info !== null && $info['newYearDays'] > $days && $guardLimit-- > 0) {
            $extYear--;
            $info = self::chineseYearInfo($extYear);
        }
        if ($info === null) {
            return null;
        }
        $guardLimit = 6;
        while ($guardLimit-- > 0) {
            $next = self::chineseYearInfo($extYear + 1);
            if ($next === null) {
                break;
            }
            if ($next['newYearDays'] <= $days) {
                $extYear++;
                $info = $next;
                continue;
            }
            break;
        }
        if ($info['newYearDays'] > $days) {
            return null;
        }
        $offset = $days - $info['newYearDays'];
        $leapIcu = $info['leapIcuMonth'];
        $cursor = 0;
        for ($idx = 0; $idx < $info['monthCount']; $idx++) {
            $dim = (($info['monthLenBits'] >> $idx) & 1) === 1 ? 30 : 29;
            if ($offset < $cursor + $dim) {
                $dayOfMonth = $offset - $cursor + 1;
                if ($leapIcu === -1) {
                    return [
                        'year' => $extYear,
                        'icuMonth' => $idx,
                        'isLeap' => false,
                        'day' => $dayOfMonth,
                    ];
                }
                if ($idx <= $leapIcu) {
                    return [
                        'year' => $extYear,
                        'icuMonth' => $idx,
                        'isLeap' => false,
                        'day' => $dayOfMonth,
                    ];
                }
                if ($idx === $leapIcu + 1) {
                    return [
                        'year' => $extYear,
                        'icuMonth' => $leapIcu,
                        'isLeap' => true,
                        'day' => $dayOfMonth,
                    ];
                }
                return [
                    'year' => $extYear,
                    'icuMonth' => $idx - 1,
                    'isLeap' => false,
                    'day' => $dayOfMonth,
                ];
            }
            $cursor += $dim;
        }
        return null;
    }

    /**
     * Map chinese (icuMonth, isLeap) for the given extended-year to its
     * chronological index (0-indexed). Returns null if invalid.
     */
    private static function chineseChronoIdxFromIcu(int $extYear, int $icuMonth, bool $isLeap): ?int
    {
        $leapIcu = self::chineseLeapMonthIcuFromTable($extYear);
        if ($leapIcu === null) {
            if ($isLeap) {
                return null;
            }
            if ($icuMonth < 0 || $icuMonth > 11) {
                return null;
            }
            return $icuMonth;
        }
        if ($isLeap) {
            if ($icuMonth !== $leapIcu) {
                return null;
            }
            return $leapIcu + 1;
        }
        if ($icuMonth < 0 || $icuMonth > 11) {
            return null;
        }
        if ($icuMonth <= $leapIcu) {
            return $icuMonth;
        }
        return $icuMonth + 1;
    }

    /**
     * Map a chronological index (0-indexed) back to (icuMonth, isLeap).
     *
     * @return array{icuMonth: int, isLeap: bool}|null
     */
    private static function chineseIcuFromChronoIdx(int $extYear, int $chronoIdx): ?array
    {
        $info = self::chineseYearInfo($extYear);
        if ($info === null) {
            return null;
        }
        if ($chronoIdx < 0 || $chronoIdx >= $info['monthCount']) {
            return null;
        }
        $leapIcu = $info['leapIcuMonth'];
        if ($leapIcu === -1) {
            return ['icuMonth' => $chronoIdx, 'isLeap' => false];
        }
        if ($chronoIdx <= $leapIcu) {
            return ['icuMonth' => $chronoIdx, 'isLeap' => false];
        }
        if ($chronoIdx === $leapIcu + 1) {
            return ['icuMonth' => $leapIcu, 'isLeap' => true];
        }
        return ['icuMonth' => $chronoIdx - 1, 'isLeap' => false];
    }

    /**
     * Convert chinese (extYear, icuMonth, isLeap, day) to ISO.
     *
     * @return array{year:int,month:int,day:int}|null
     */
    private static function chineseToIsoDate(int $extYear, int $icuMonth, bool $isLeap, int $day): ?array
    {
        $info = self::chineseYearInfo($extYear);
        if ($info === null) {
            return null;
        }
        $chronoIdx = self::chineseChronoIdxFromIcu($extYear, $icuMonth, $isLeap);
        if ($chronoIdx === null) {
            return null;
        }
        $dim = (($info['monthLenBits'] >> $chronoIdx) & 1) === 1 ? 30 : 29;
        if ($day < 1 || $day > $dim) {
            return null;
        }
        $cursor = 0;
        for ($i = 0; $i < $chronoIdx; $i++) {
            $cursor += (($info['monthLenBits'] >> $i) & 1) === 1 ? 30 : 29;
        }
        $absDays = $info['newYearDays'] + $cursor + ($day - 1);
        return self::isoDateFromDays($absDays);
    }

    /** Days in a specific chinese (extYear, icuMonth, isLeap). */
    private static function chineseDaysInMonth(int $extYear, int $icuMonth, bool $isLeap): int
    {
        $chronoIdx = self::chineseChronoIdxFromIcu($extYear, $icuMonth, $isLeap);
        if ($chronoIdx === null) {
            return 30;
        }
        return self::chineseDaysInMonthByChrono($extYear, $chronoIdx);
    }

    /** Day-of-year (1-indexed) in chinese terms for an ISO date. */
    private static function chineseDayOfYearForIso(int $isoY, int $isoM, int $isoD): ?int
    {
        $c = self::isoToChineseDate($isoY, $isoM, $isoD);
        if ($c === null) {
            return null;
        }
        $info = self::chineseYearInfo($c['year']);
        if ($info === null) {
            return null;
        }
        $days = self::isoDateToDays($isoY, $isoM, $isoD);
        return $days - $info['newYearDays'] + 1;
    }

    /**
     * Add (years, months) to an ISO date in chinese terms then constrain
     * the day. Returns [isoY, isoM, isoD] in proleptic Gregorian.
     *
     * @return array{0:int,1:int,2:int}|null
     */
    private static function chineseAddYearsMonthsIso(
        int $isoY,
        int $isoM,
        int $isoD,
        int $years,
        int $months,
        string $overflow,
    ): ?array {
        $c = self::isoToChineseDate($isoY, $isoM, $isoD);
        if ($c === null) {
            return null;
        }
        $newY = $c['year'] + $years;
        $newIcuMonth = $c['icuMonth'];
        $newLeap = $c['isLeap'];
        $startDay = $c['day'];

        if ($newLeap) {
            $tgtLeap = self::chineseLeapMonthIcuFromTable($newY);
            if ($tgtLeap !== $newIcuMonth) {
                $newLeap = false;
            }
        }

        if ($months !== 0) {
            $pos = self::chineseChronoIdxFromIcu($newY, $newIcuMonth, $newLeap);
            if ($pos === null) {
                return null;
            }
            $pos += $months;
            $guard = 0;
            while (true) {
                $info = self::chineseYearInfo($newY);
                if ($info === null || $guard++ > 100000) {
                    return null;
                }
                if ($pos >= $info['monthCount']) {
                    $pos -= $info['monthCount'];
                    $newY++;
                    continue;
                }
                if ($pos < 0) {
                    $newY--;
                    $prev = self::chineseYearInfo($newY);
                    if ($prev === null) {
                        return null;
                    }
                    $pos += $prev['monthCount'];
                    continue;
                }
                break;
            }
            $resolved = self::chineseIcuFromChronoIdx($newY, $pos);
            if ($resolved === null) {
                return null;
            }
            $newIcuMonth = $resolved['icuMonth'];
            $newLeap = $resolved['isLeap'];
        }

        $dim = self::chineseDaysInMonth($newY, $newIcuMonth, $newLeap);
        $newD = $startDay;
        if ($newD > $dim) {
            if ($overflow === 'reject') {
                throw new RangeError("Day {$startDay} out of range after calendar arithmetic");
            }
            $newD = $dim;
        }
        $iso = self::chineseToIsoDate($newY, $newIcuMonth, $newLeap, $newD);
        if ($iso === null) {
            return null;
        }
        return [$iso['year'], $iso['month'], $iso['day']];
    }

    /**
     * (years, months, days) between two ISO dates in chinese terms,
     * with sml <= lrg.
     *
     * @return array{0:int,1:int,2:int}|null
     */
    private static function chineseYearsMonthsDaysBetween(
        int $smlY,
        int $smlM,
        int $smlD,
        int $lrgY,
        int $lrgM,
        int $lrgD,
        string $largestUnit,
    ): ?array {
        $smlC = self::isoToChineseDate($smlY, $smlM, $smlD);
        if ($smlC === null) {
            return null;
        }
        $endDays = self::isoDateToDays($lrgY, $lrgM, $lrgD);

        $years = 0;
        if ($largestUnit === 'year') {
            $cand = $smlC['year'];
            while (true) {
                $next = $cand + 1;
                $probe = self::chineseAddYearsMonthsIso(
                    $smlY,
                    $smlM,
                    $smlD,
                    $next - $smlC['year'],
                    0,
                    'constrain',
                );
                if ($probe === null) {
                    break;
                }
                $probeDays = self::isoDateToDays($probe[0], $probe[1], $probe[2]);
                if ($probeDays > $endDays) {
                    break;
                }
                $cand = $next;
                $years++;
            }
        }

        $anchorIso = $years > 0
            ? self::chineseAddYearsMonthsIso($smlY, $smlM, $smlD, $years, 0, 'constrain')
            : [$smlY, $smlM, $smlD];
        if ($anchorIso === null) {
            return null;
        }

        $months = 0;
        if ($largestUnit !== 'day' && $largestUnit !== 'week') {
            while (true) {
                $probe = self::chineseAddYearsMonthsIso(
                    $anchorIso[0],
                    $anchorIso[1],
                    $anchorIso[2],
                    0,
                    1,
                    'constrain',
                );
                if ($probe === null) {
                    break;
                }
                $probeDays = self::isoDateToDays($probe[0], $probe[1], $probe[2]);
                if ($probeDays > $endDays) {
                    break;
                }
                $anchorIso = $probe;
                $months++;
            }
        }

        $anchorDays = self::isoDateToDays($anchorIso[0], $anchorIso[1], $anchorIso[2]);
        $days = $endDays - $anchorDays;
        if ($days < 0) {
            return null;
        }
        return [$years, $months, $days];
    }

    /** 1-indexed chronological month for chinese (icuMonth, isLeap). */
    private static function chineseMonthOneBased(int $extYear, int $icuMonth, bool $isLeap): int
    {
        $idx = self::chineseChronoIdxFromIcu($extYear, $icuMonth, $isLeap);
        if ($idx === null) {
            return $icuMonth + 1;
        }
        return $idx + 1;
    }
}
