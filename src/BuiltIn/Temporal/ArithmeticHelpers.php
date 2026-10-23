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
 * Temporal helper section (ArithmeticHelpers). Composed into TemporalObject
 * via `use Temporal\ArithmeticHelpers;`. `self::` references resolve into
 * the composing class.
 */
trait ArithmeticHelpers
{
    // -----------------------------------------------------------------------
    // Helpers: arithmetic
    // -----------------------------------------------------------------------

    private static function instantAddDuration(string $ns, JsValue $durationArg, int $sign): JsObject
    {
        $dur = self::toDuration($durationArg);
        // Instant only supports time components.
        $hasCalUnit = self::getDurationField($dur, 'years') !== 0
            || self::getDurationField($dur, 'months') !== 0
            || self::getDurationField($dur, 'weeks') !== 0
            || self::getDurationField($dur, 'days') !== 0;
        if ($hasCalUnit) {
            throw new RangeError('Instant arithmetic does not support years, months, weeks, or days');
        }
        $totalNs = self::durationToTotalNs($dur);
        if ($sign < 0) {
            $totalNs = bcsub('0', $totalNs, 0);
        }
        $result = bcadd($ns, $totalNs, 0);
        self::validateInstantRange($result);
        return self::createInstantObject($result);
    }

    private static function instantDifference(string $ns1, string $ns2, JsValue $options): JsObject
    {
        $opts = self::readDifferenceOptionsAlphabetical(self::getOptionsObject($options), false);
        $diffNs = bcsub($ns2, $ns1, 0);
        $largestUnit = 'second';
        $largestUnitExplicit = false;
        $smallestUnit = 'nanosecond';
        $lu = $opts->get('largestUnit');
        if (!($lu instanceof JsUndefined)) {
            $largestUnitExplicit = true;
            $largestUnit = TypeConversion::toString($lu);
            $largestUnit = self::canonicalTemporalUnit($largestUnit);
            $instantLU = ['hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];
            if (!in_array($largestUnit, $instantLU, true)) {
                throw new RangeError("Invalid largest unit for Instant: {$largestUnit}");
            }
        }
        // Read options in ALPHABETICAL order per spec.
        $ri = $opts->get('roundingIncrement');
        if (!($ri instanceof JsUndefined)) {
            $riNum = TypeConversion::toNumber($ri);
            if (!is_finite($riNum)) {
                throw new RangeError("Invalid roundingIncrement");
            }
            $riNum = (int) $riNum;
            if ($riNum < 1 || $riNum > 1_000_000_000) {
                throw new RangeError("Invalid roundingIncrement");
            }
        }
        $rm = $opts->get('roundingMode');
        if (!($rm instanceof JsUndefined)) {
            $rmStr = TypeConversion::toString($rm);
            $validRM = [
                'ceil', 'floor', 'expand', 'trunc',
                'halfCeil', 'halfFloor', 'halfExpand',
                'halfTrunc', 'halfEven',
            ];
            if (!in_array($rmStr, $validRM, true)) {
                throw new RangeError("Invalid roundingMode: {$rmStr}");
            }
        }
        $smallestUnit = 'nanosecond';
        $su = $opts->get('smallestUnit');
        if (!($su instanceof JsUndefined)) {
            $smallestUnit = TypeConversion::toString($su);
            $smallestUnit = self::canonicalTemporalUnit($smallestUnit);
            $instantUnits = ['hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];
            if (!in_array($smallestUnit, $instantUnits, true)) {
                throw new RangeError("Invalid smallest unit for Instant: {$smallestUnit}");
            }
        }
        // Validate roundingIncrement divides evenly into next unit.
        if (isset($riNum) && $riNum > 1) {
            self::validateRoundingIncrement($smallestUnit, $riNum);
        }
        // Default largestUnit to smallestUnit if smallestUnit is larger.
        $unitOrder = ['hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];
        $liIdx = array_search($largestUnit, $unitOrder);
        $siIdx = array_search($smallestUnit, $unitOrder);
        if (!$largestUnitExplicit && $siIdx !== false && $liIdx !== false && $siIdx < $liIdx) {
            $largestUnit = $smallestUnit;
            $liIdx = $siIdx;
        }
        if ($liIdx !== false && $siIdx !== false && $liIdx > $siIdx) {
            throw new RangeError("largestUnit must be >= smallestUnit");
        }
        // Apply rounding if smallestUnit != nanosecond or increment != 1.
        $roundIncrement = isset($riNum) ? (int) $riNum : 1;
        $roundMode = $rmStr ?? 'trunc';
        if ($smallestUnit !== 'nanosecond' || $roundIncrement !== 1) {
            $unitNsMap = [
                'hour' => '3600000000000',
                'minute' => '60000000000',
                'second' => '1000000000',
                'millisecond' => '1000000',
                'microsecond' => '1000',
                'nanosecond' => '1',
            ];
            $unitNs = $unitNsMap[$smallestUnit];
            $incrementNs = bcmul((string) $roundIncrement, $unitNs, 0);
            $diffNs = self::roundNs($diffNs, $incrementNs, $roundMode);
        }
        return self::nsToTimeDuration($diffNs, $largestUnit);
    }

    /** Validate that roundingIncrement divides evenly into the next highest unit. */
    private static function validateRoundingIncrement(string $unit, int $increment): void
    {
        $maxIncrements = [
            'hour' => 24,
            'minute' => 60,
            'second' => 60,
            'millisecond' => 1000,
            'microsecond' => 1000,
            'nanosecond' => 1000,
        ];
        $max = $maxIncrements[$unit] ?? null;
        if ($max !== null) {
            if ($increment >= $max || $max % $increment !== 0) {
                throw new RangeError("Invalid roundingIncrement for {$unit}: {$increment}");
            }
        }
    }

    /** Round a nanosecond value to the nearest increment. */
    /**
     * Round a sub-day ns remainder to whole days using the actual calendar
     * day length (which can be 23h or 25h around DST transitions, or 24h+24h
     * around Samoa-style date-line jumps). Returns the rounded value as ns
     * (always a whole number of days).
     */
    private static function roundDaysWithCalendarLength(
        string $remainingNs,
        JsObject $startObj,
        JsObject $dateOnly,
        int $increment,
        string $mode,
    ): string {
        $sign = bccomp($remainingNs, '0', 0);
        if ($sign === 0) {
            return '0';
        }
        // Compute the next-day boundary length: add (sign * 1 day) to the
        // already-walked dateOnly and measure the delta in ns.
        $nextDate = self::createDurationObject(
            self::getDurationField($dateOnly, 'years'),
            self::getDurationField($dateOnly, 'months'),
            self::getDurationField($dateOnly, 'weeks'),
            self::getDurationField($dateOnly, 'days') + ($sign > 0 ? 1 : -1),
            0,
            0,
            0,
            0,
            0,
            0,
        );
        $afterDates = self::addDurationToZdt($startObj, $dateOnly, 1, 'constrain');
        $afterNext = self::addDurationToZdt($startObj, $nextDate, 1, 'constrain');
        $dayLenNs = bcsub($afterNext, $afterDates, 0);
        $absDayLen = bccomp($dayLenNs, '0', 0) < 0 ? bcsub('0', $dayLenNs, 0) : $dayLenNs;
        if (bccomp($absDayLen, '0', 0) === 0) {
            return '0';
        }
        $absRem = $sign < 0 ? bcsub('0', $remainingNs, 0) : $remainingNs;
        // Fractional days: $absRem / $absDayLen, rounded by $mode.
        $cmp = bccomp($absRem, $absDayLen, 0);
        if ($cmp >= 0) {
            // Spec invariant: |remainingNs| < dayLength after the back-off
            // step. If we reach here, the back-off didn't fully resolve;
            // round to a whole day in the right direction.
            $whole = bcdiv($absRem, $absDayLen, 0);
            $remainder = bcmod($absRem, $absDayLen);
            if ($remainder !== '0') {
                if ($mode === 'expand' || ($mode === 'ceil' && $sign > 0) || ($mode === 'floor' && $sign < 0) || $mode === 'halfExpand') {
                    $whole = bcadd($whole, '1', 0);
                }
            }
            $resultDays = $whole;
        } else {
            // Standard case: 0 ≤ |rem| < dayLength.
            $half = bcdiv($absDayLen, '2', 0);
            $cmpHalf = bccomp($absRem, $half, 0);
            $up = false;
            switch ($mode) {
                case 'trunc':
                    $up = false;
                    break;
                case 'ceil':
                    $up = $sign > 0;
                    break;
                case 'floor':
                    $up = $sign < 0;
                    break;
                case 'expand':
                    $up = true;
                    break;
                case 'halfExpand':
                    $up = $cmpHalf >= 0;
                    break;
                case 'halfCeil':
                    $up = $cmpHalf > 0 || ($cmpHalf === 0 && $sign > 0);
                    break;
                case 'halfFloor':
                    $up = $cmpHalf > 0 || ($cmpHalf === 0 && $sign < 0);
                    break;
                case 'halfTrunc':
                    $up = $cmpHalf > 0;
                    break;
                case 'halfEven':
                    $up = $cmpHalf > 0;
                    break;
            }
            $resultDays = $up ? '1' : '0';
        }
        // Apply increment (only ever 1 for day-level here).
        if ($increment > 1) {
            $resultDays = bcmul($resultDays, (string) $increment, 0);
        }
        $resultNs = bcmul($resultDays, '86400000000000', 0);
        return $sign < 0 ? bcsub('0', $resultNs, 0) : $resultNs;
    }

    private static function roundNs(string $ns, string $incrementNs, string $mode): string
    {
        $sign = bccomp($ns, '0', 0) < 0 ? -1 : 1;
        $abs = $sign < 0 ? bcsub('0', $ns, 0) : $ns;
        $quotient = bcdiv($abs, $incrementNs, 0);
        $remainder = bcmod($abs, $incrementNs);
        if ($remainder === '0') {
            return $ns;
        }
        $rounded = $quotient;
        switch ($mode) {
            case 'trunc':
                // Already truncated.
                break;
            case 'ceil':
                if ($sign > 0) {
                    $rounded = bcadd($quotient, '1', 0);
                }
                break;
            case 'floor':
                if ($sign < 0) {
                    $rounded = bcadd($quotient, '1', 0);
                }
                break;
            case 'expand':
                $rounded = bcadd($quotient, '1', 0);
                break;
            case 'halfExpand':
            case 'halfCeil':
            case 'halfFloor':
            case 'halfTrunc':
            case 'halfEven':
                $cmp = bccomp(bcmul($remainder, '2', 0), $incrementNs, 0);
                if ($cmp > 0) {
                    $rounded = bcadd($quotient, '1', 0);
                } elseif ($cmp === 0) {
                    // Exact tie-breaking per mode.
                    if ($mode === 'halfExpand') {
                        $rounded = bcadd($quotient, '1', 0);
                    } elseif ($mode === 'halfCeil') {
                        if ($sign > 0) {
                            $rounded = bcadd($quotient, '1', 0);
                        }
                    } elseif ($mode === 'halfFloor') {
                        if ($sign < 0) {
                            $rounded = bcadd($quotient, '1', 0);
                        }
                    } elseif ($mode === 'halfEven') {
                        if (bcmod($quotient, '2') !== '0') {
                            $rounded = bcadd($quotient, '1', 0);
                        }
                    }
                    // halfTrunc: stay at quotient.
                }
                break;
        }
        $result = bcmul($rounded, $incrementNs, 0);
        return $sign < 0 ? bcsub('0', $result, 0) : $result;
    }

    /**
     * Round Instant epoch-nanoseconds per the Temporal spec RoundTemporalInstant.
     */
    private static function roundInstantNs(string $ns, string $incrementNs, string $mode): string
    {
        $truncQ = bcdiv($ns, $incrementNs, 0);
        $truncR = bcsub($ns, bcmul($truncQ, $incrementNs, 0), 0);
        if (bccomp($truncR, '0', 0) < 0) {
            $floorQ = bcsub($truncQ, '1', 0);
            $floorR = bcadd($truncR, $incrementNs, 0);
        } else {
            $floorQ = $truncQ;
            $floorR = $truncR;
        }
        if ($floorR === '0') {
            return $ns;
        }
        $doubled = bcmul($floorR, '2', 0);
        $cmp = bccomp($doubled, $incrementNs, 0);
        $rounded = match ($mode) {
            'floor', 'trunc' => $floorQ,
            'ceil', 'expand' => bcadd($floorQ, '1', 0),
            'halfExpand' => $cmp >= 0 ? bcadd($floorQ, '1', 0) : $floorQ,
            'halfTrunc', 'halfFloor' => $cmp > 0 ? bcadd($floorQ, '1', 0) : $floorQ,
            'halfCeil' => $cmp >= 0 ? bcadd($floorQ, '1', 0) : $floorQ,
            'halfEven' => ($cmp > 0 || ($cmp === 0 && bcmod($floorQ, '2') !== '0'))
                ? bcadd($floorQ, '1', 0) : $floorQ,
            default => $floorQ,
        };
        return bcmul($rounded, $incrementNs, 0);
    }

    /**
     * Round an ISO date-time (wall-clock) to the given increment.
     * Rounding is applied to the time-of-day nanoseconds, then carried into the date if needed.
     *
     * @param array<string, int> $parts ISO date-time parts (year, month, day, hour, minute, second, millisecond, microsecond, nanosecond)
     * @param string $incrementNs The rounding increment in nanoseconds
     * @param string $mode The rounding mode
     * @param string $tz The time zone (unused but kept for signature consistency)
     * @return array<string, int> Rounded ISO date-time parts
     */
    private static function roundISODateTime(array $parts, string $incrementNs, string $mode, string $tz): array
    {
        // Convert time-of-day to nanoseconds from midnight.
        $timeNs = bcadd(
            bcadd(
                bcadd(
                    bcmul((string) $parts['hour'], '3600000000000', 0),
                    bcmul((string) $parts['minute'], '60000000000', 0),
                    0,
                ),
                bcadd(
                    bcmul((string) $parts['second'], '1000000000', 0),
                    bcmul((string) $parts['millisecond'], '1000000', 0),
                    0,
                ),
                0,
            ),
            bcadd(
                bcmul((string) $parts['microsecond'], '1000', 0),
                (string) $parts['nanosecond'],
                0,
            ),
            0,
        );
        // Round the time-of-day ns (always positive, 0..86399999999999).
        $rounded = self::roundNs($timeNs, $incrementNs, $mode);
        // Check for day overflow.
        $dayNs = '86400000000000';
        $dayCarry = 0;
        if (bccomp($rounded, $dayNs, 0) >= 0) {
            $dayCarry = 1;
            $rounded = bcsub($rounded, $dayNs, 0);
        } elseif (bccomp($rounded, '0', 0) < 0) {
            $dayCarry = -1;
            $rounded = bcadd($rounded, $dayNs, 0);
        }
        // Decompose rounded ns back to time parts.
        $h = (int) bcdiv($rounded, '3600000000000', 0);
        $rem = bcmod($rounded, '3600000000000');
        $mi = (int) bcdiv($rem, '60000000000', 0);
        $rem = bcmod($rem, '60000000000');
        $s = (int) bcdiv($rem, '1000000000', 0);
        $rem = bcmod($rem, '1000000000');
        $ms = (int) bcdiv($rem, '1000000', 0);
        $rem = bcmod($rem, '1000000');
        $us = (int) bcdiv($rem, '1000', 0);
        $nns = (int) bcmod($rem, '1000');

        $year = $parts['year'];
        $month = $parts['month'];
        $day = $parts['day'] + $dayCarry;
        // Handle day overflow into month/year.
        if ($dayCarry !== 0) {
            $dim = self::isoDaysInMonth($year, $month);
            if ($day > $dim) {
                $day = 1;
                $month++;
                if ($month > 12) {
                    $month = 1;
                    $year++;
                }
            } elseif ($day < 1) {
                $month--;
                if ($month < 1) {
                    $month = 12;
                    $year--;
                }
                $day = self::isoDaysInMonth($year, $month);
            }
        }

        return [
            'year' => $year, 'month' => $month, 'day' => $day,
            'hour' => $h, 'minute' => $mi, 'second' => $s,
            'millisecond' => $ms, 'microsecond' => $us, 'nanosecond' => $nns,
        ];
    }

    /**
     * Add calendar years and months from the first native day of the month.
     *
     * @return array{int, int, int} ISO year, month, and reference day.
     */
    private static function addDurationToYearMonth(
        int $sign,
        int $y,
        int $m,
        string $cal,
        JsObject $dur,
        string $overflow,
        int $referenceDay = 1,
    ): array {
        foreach (['weeks', 'days', 'hours', 'minutes', 'seconds', 'milliseconds', 'microseconds', 'nanoseconds'] as $unit) {
            if (self::getDurationField($dur, $unit) !== 0) {
                throw new RangeError('PlainYearMonth arithmetic only accepts years and months');
            }
        }
        $fields = self::calendarFieldsForIso($cal, $y, $m, $referenceDay);
        $fields['day'] = 1;
        $date = self::calendarDateFromFields($cal, $fields, 'constrain');
        self::validateISODate($date['year'], $date['month'], $date['day']);
        $result = self::calendarAddYearsMonthsIso(
            $cal,
            $date['year'],
            $date['month'],
            $date['day'],
            $sign * self::getDurationField($dur, 'years'),
            $sign * self::getDurationField($dur, 'months'),
            $overflow,
        );
        if ($result === null) {
            throw new RangeError('Date arithmetic overflow');
        }
        return $result;
    }

    private static function plainDateAdd(JsValue $date, JsObject $dur, int $sign, string $overflow = 'constrain'): JsObject
    {
        $y = self::getSlotInt($date, '[[ISOYear]]');
        $m = self::getSlotInt($date, '[[ISOMonth]]');
        $d = self::getSlotInt($date, '[[ISODay]]');
        $cal = self::getSlotString($date, '[[Calendar]]');

        $years = $sign * self::getDurationField($dur, 'years');
        $months = $sign * self::getDurationField($dur, 'months');
        $weeks = $sign * self::getDurationField($dur, 'weeks');
        $days = $sign * self::getDurationField($dur, 'days');
        // Per spec: balance time components into days for PlainDate.
        $hours = $sign * self::getDurationField($dur, 'hours');
        $minutes = $sign * self::getDurationField($dur, 'minutes');
        $seconds = $sign * self::getDurationField($dur, 'seconds');
        $ms = $sign * self::getDurationField($dur, 'milliseconds');
        $us = $sign * self::getDurationField($dur, 'microseconds');
        $ns = $sign * self::getDurationField($dur, 'nanoseconds');
        $totalTimeNs = self::durationToTotalNs(self::createDurationObject(
            0,
            0,
            0,
            0,
            $hours,
            $minutes,
            $seconds,
            $ms,
            $us,
            $ns,
        ));
        $extraDays = (int) bcdiv($totalTimeNs, '86400000000000', 0);
        $days += $extraDays;

        // For non-iso/gregory-like calendars, route year/month addition
        // through the calendar-aware adder so the calendar's actual
        // month/year boundaries (e.g. coptic's 13-month year, hebrew's
        // 12 vs 13, chinese sexagenary leap months) are honoured. For
        // ISO/gregory/roc/japanese, fall through to ISO arithmetic.
        // Hebrew and Ethiopic have pure-PHP paths that don't need intl;
        // every other calendar still requires the intl extension.
        $isPureCal = $cal === 'hebrew'
            || $cal === 'ethiopic'
            || $cal === 'ethioaa'
            || $cal === 'ethiopic-amete-alem';
        $useCalendarMath = $cal !== 'iso8601'
            && !in_array($cal, ['gregory', 'roc', 'japanese'], true)
            && ($isPureCal || extension_loaded('intl'))
            && ($years !== 0 || $months !== 0);
        if ($useCalendarMath) {
            $isoAfter = self::calendarAddYearsMonthsIso($cal, $y, $m, $d, $years, $months, $overflow);
            if ($isoAfter !== null) {
                [$y, $m, $d] = $isoAfter;
                $years = 0;
                $months = 0;
            }
        }

        // Add years and months first (ISO path).
        $y += $years;
        $m += $months;

        // Normalize month overflow.
        while ($m > 12) {
            $m -= 12;
            $y++;
        }
        while ($m < 1) {
            $m += 12;
            $y--;
        }

        // Clamp or reject day based on overflow.
        $dim = self::isoDaysInMonth($y, $m);
        if ($d > $dim) {
            if ($overflow === 'reject') {
                throw new RangeError("Day {$d} out of range for month");
            }
            $d = $dim;
        }

        // Add weeks and days.
        $totalDays = $days + $weeks * 7;
        if ($totalDays !== 0) {
            try {
                $dt = new \DateTimeImmutable('2000-01-01', new \DateTimeZone('UTC'));
                $dt = $dt->setDate($y, $m, $d);
                $dt = $dt->modify("{$totalDays} days");
                $y = (int) $dt->format('Y');
                $m = (int) $dt->format('n');
                $d = (int) $dt->format('j');
            } catch (\Throwable) {
                throw new RangeError('Date arithmetic overflow');
            }
        }

        self::validateISODate($y, $m, $d);
        return self::createPlainDateObject($y, $m, $d, $cal);
    }

    /**
     * Add `years` years and `months` months in the given calendar's
     * native month/year space, then return the resulting [iso year,
     * iso month, iso day]. Day clamps to the resulting month's max
     * unless `overflow === 'reject'`. Returns null when ICU can't
     * model the calendar.
     *
     * @return array{0:int,1:int,2:int}|null
     */
    private static function calendarAddYearsMonthsIso(
        string $calendar,
        int $isoY,
        int $isoM,
        int $isoD,
        int $years,
        int $months,
        string $overflow,
    ): ?array {
        $fields = self::calendarFieldsForIso($calendar, $isoY, $isoM, $isoD);
        $year = (int) $fields['year'] + $years;
        if (abs($year) > 1000000 || abs($months) > 12000000) {
            throw new RangeError('Date arithmetic overflow');
        }
        $start = self::calendarDateFromFields($calendar, [
            'year' => $year, 'monthCode' => $fields['monthCode'], 'day' => 1,
        ], $overflow);
        $parts = self::isoToCalendarParts($calendar, $start['year'], $start['month'], $start['day']);
        if ($parts === null) {
            return null;
        }
        $month = (int) $parts['month'] + $months;
        $lunisolar = in_array($calendar, ['chinese', 'dangi', 'hebrew'], true);
        if (!$lunisolar) {
            $count = in_array($calendar, ['coptic', 'ethiopic', 'ethioaa'], true) ? 13 : 12;
            $year += (int) floor(($month - 1) / $count);
            $month = (($month - 1) % $count + $count) % $count + 1;
        } else {
            while (true) {
                $first = self::calendarPartsToIso($calendar, $year, 'M01', null, 1);
                if ($first === null) {
                    throw new RangeError('Date arithmetic overflow');
                }
                $count = self::calendarMonthsInYear($calendar, $first['year'], $first['month'], $first['day']) ?? 12;
                if ($month > $count) {
                    $month -= $count;
                    $year++;
                } elseif ($month < 1) {
                    $year--;
                    $first = self::calendarPartsToIso($calendar, $year, 'M01', null, 1);
                    if ($first === null) {
                        throw new RangeError('Date arithmetic overflow');
                    }
                    $month += self::calendarMonthsInYear($calendar, $first['year'], $first['month'], $first['day']) ?? 12;
                } else {
                    break;
                }
            }
        }
        $date = self::calendarDateFromFields($calendar, [
            'year' => $year, 'month' => $month, 'day' => $fields['day'],
        ], $overflow);
        return [$date['year'], $date['month'], $date['day']];
    }

    private static function plainYearMonthDifference(JsValue $ym1, JsValue $ym2, JsValue $options): JsObject
    {
        $cal1 = self::getSlotString($ym1, '[[Calendar]]');
        $cal2 = self::getSlotString($ym2, '[[Calendar]]');
        if ($cal1 !== $cal2) {
            throw new RangeError(
                "calendar IDs do not match: {$cal1} vs {$cal2}",
            );
        }
        $opts = self::readDifferenceOptionsAlphabetical(self::getOptionsObject($options), false);
        $y1 = self::getSlotInt($ym1, '[[ISOYear]]');
        $m1 = self::getSlotInt($ym1, '[[ISOMonth]]');
        $y2 = self::getSlotInt($ym2, '[[ISOYear]]');
        $m2 = self::getSlotInt($ym2, '[[ISOMonth]]');
        $largestUnit = 'year';
        $riFinal = 1;
        $rmFinal = 'trunc';
        $suFinal = 'month';
        $lu = $opts->get('largestUnit');
        if (!($lu instanceof JsUndefined)) {
            $largestUnit = TypeConversion::toString($lu);
            if ($largestUnit === 'auto') {
                $largestUnit = 'year';
            } else {
                $largestUnit = self::canonicalTemporalUnit($largestUnit);
            }
            if (!in_array($largestUnit, ['year', 'month'], true)) {
                throw new RangeError("Invalid largest unit for PlainYearMonth: {$largestUnit}");
            }
        }
        $ri = $opts->get('roundingIncrement');
        if (!($ri instanceof JsUndefined)) {
            $riNum = TypeConversion::toNumber($ri);
            if (!is_finite($riNum)) {
                throw new RangeError('roundingIncrement must be finite');
            }
            $riFinal = (int) $riNum;
            if ($riFinal < 1 || $riFinal > 1_000_000_000) {
                throw new RangeError('roundingIncrement out of range');
            }
        }
        $rm = $opts->get('roundingMode');
        if (!($rm instanceof JsUndefined)) {
            $rmFinal = TypeConversion::toString($rm);
            $validRM = [
                'ceil', 'floor', 'expand', 'trunc',
                'halfCeil', 'halfFloor', 'halfExpand', 'halfTrunc', 'halfEven',
            ];
            if (!in_array($rmFinal, $validRM, true)) {
                throw new RangeError("Invalid roundingMode: {$rmFinal}");
            }
        }
        $su = $opts->get('smallestUnit');
        if (!($su instanceof JsUndefined)) {
            $suStr = TypeConversion::toString($su);
            $suFinal = self::canonicalTemporalUnit($suStr);
            if (!in_array($suFinal, ['year', 'month'], true)) {
                throw new RangeError("Invalid smallest unit for PlainYearMonth: {$suFinal}");
            }
        }
        $allU = ['year', 'month'];
        $liIdx = array_search($largestUnit, $allU);
        $siIdx = array_search($suFinal, $allU);
        if ($liIdx !== false && $siIdx !== false && $liIdx > $siIdx) {
            throw new RangeError('largestUnit must be >= smallestUnit');
        }
        if ($cal1 !== 'iso8601' && $suFinal === 'month' && $riFinal === 1) {
            $firstFields = self::calendarFieldsForIso($cal1, $y1, $m1, self::getSlotInt($ym1, '[[ISODay]]'));
            $secondFields = self::calendarFieldsForIso($cal1, $y2, $m2, self::getSlotInt($ym2, '[[ISODay]]'));
            $firstFields['day'] = 1;
            $secondFields['day'] = 1;
            $first = self::calendarDateFromFields($cal1, $firstFields, 'constrain');
            $second = self::calendarDateFromFields($cal1, $secondFields, 'constrain');
            $difference = self::calendarYearsMonthsDaysBetween(
                $cal1,
                $first['year'],
                $first['month'],
                $first['day'],
                $second['year'],
                $second['month'],
                $second['day'],
                $largestUnit,
            );
            if ($difference !== null) {
                return self::createDurationObject($difference[0], $difference[1], 0, 0, 0, 0, 0, 0, 0, 0);
            }
        }
        if ($y1 !== $y2 || $m1 !== $m2) {
            self::validateISODate($y1, $m1, 1);
            self::validateISODate($y2, $m2, 1);
        }
        $totalMonths = ($y2 * 12 + $m2) - ($y1 * 12 + $m1);
        if ($largestUnit === 'year') {
            $years = intdiv($totalMonths, 12);
            $months = $totalMonths - $years * 12;
        } else {
            $years = 0;
            $months = $totalMonths;
        }
        if ($suFinal === 'year' && $months !== 0) {
            $sign = $totalMonths >= 0 ? 1 : -1;
            $absYears = abs($years);
            $absMonths = abs($months);
            $frac = $absMonths / 12.0;
            $totalYearsFloat = $absYears + $frac;
            $absRm = $rmFinal;
            if ($sign < 0) {
                $absRm = match ($rmFinal) {
                    'ceil' => 'floor', 'floor' => 'ceil',
                    'halfCeil' => 'halfFloor', 'halfFloor' => 'halfCeil',
                    default => $rmFinal,
                };
            }
            $rounded = self::roundToIncrement(
                (int) round($totalYearsFloat * 1000000),
                $riFinal * 1000000,
                $absRm,
            );
            $years = $sign * intdiv($rounded, 1000000);
            $months = 0;
        } elseif ($suFinal === 'month' && $riFinal > 1) {
            $sign = $totalMonths >= 0 ? 1 : -1;
            $absRm = $rmFinal;
            if ($sign < 0) {
                $absRm = match ($rmFinal) {
                    'ceil' => 'floor', 'floor' => 'ceil',
                    'halfCeil' => 'halfFloor', 'halfFloor' => 'halfCeil',
                    default => $rmFinal,
                };
            }
            if ($largestUnit === 'year') {
                $absMonths = abs($months);
                $roundedMonths = self::roundToIncrement($absMonths, $riFinal, $absRm);
                if ($roundedMonths >= 12) {
                    $years += $sign * intdiv($roundedMonths, 12);
                    $roundedMonths = $roundedMonths % 12;
                }
                $months = $sign * $roundedMonths;
            } else {
                $rounded = self::roundToIncrement(abs($totalMonths), $riFinal, $absRm);
                $years = 0;
                $months = $sign * $rounded;
            }
        }
        if ($riFinal > 1 || $suFinal === 'year') {
            $cY = $y1 + $years;
            $cM = $m1 + $months;
            while ($cM > 12) {
                $cM -= 12;
                $cY++;
            }
            while ($cM < 1) {
                $cM += 12;
                $cY--;
            }
            if (
                $cY < self::ISO_YEAR_MIN || $cY > self::ISO_YEAR_MAX
                || ($cY === self::ISO_YEAR_MIN && $cM < 4)
                || ($cY === self::ISO_YEAR_MAX && $cM > 9)
            ) {
                throw new RangeError('rounded date is outside the representable range');
            }
            $sign = $totalMonths >= 0 ? 1 : -1;
            $endMonths = ($suFinal === 'year')
                ? $sign * ((abs($years) + $riFinal) * 12)
                : $sign * (abs($totalMonths) + $riFinal);
            $eY = $y1;
            $eM = $m1 + $endMonths;
            while ($eM > 12) {
                $eM -= 12;
                $eY++;
            }
            while ($eM < 1) {
                $eM += 12;
                $eY--;
            }
            if (
                $eY < self::ISO_YEAR_MIN || $eY > self::ISO_YEAR_MAX
                || ($eY === self::ISO_YEAR_MIN && $eM < 4)
                || ($eY === self::ISO_YEAR_MAX && $eM > 9)
            ) {
                throw new RangeError('rounded date is outside the representable range');
            }
        }
        return self::createDurationObject($years, $months, 0, 0, 0, 0, 0, 0, 0, 0);
    }

    private static function plainDateDifference(JsValue $date1, JsValue $date2, JsValue $options, int $sign): JsObject
    {
        // Spec: calendar IDs of both operands must match for since/until.
        $cal1 = self::getSlotString($date1, '[[Calendar]]');
        $cal2 = self::getSlotString($date2, '[[Calendar]]');
        if ($cal1 !== $cal2) {
            throw new RangeError(
                "calendar IDs do not match: {$cal1} vs {$cal2}",
            );
        }
        $opts = self::readDifferenceOptionsAlphabetical(self::getOptionsObject($options), false);
        $y1 = self::getSlotInt($date1, '[[ISOYear]]');
        $m1 = self::getSlotInt($date1, '[[ISOMonth]]');
        $d1 = self::getSlotInt($date1, '[[ISODay]]');
        $y2 = self::getSlotInt($date2, '[[ISOYear]]');
        $m2 = self::getSlotInt($date2, '[[ISOMonth]]');
        $d2 = self::getSlotInt($date2, '[[ISODay]]');

        $largestUnit = 'day';
        $largestUnitExplicit = false;
        $lu = $opts->get('largestUnit');
        if (!($lu instanceof JsUndefined)) {
            $largestUnitExplicit = true;
            $largestUnit = TypeConversion::toString($lu);
            if ($largestUnit === 'auto') {
                $largestUnit = 'day';
                $largestUnitExplicit = false;
            } else {
                $largestUnit = self::canonicalTemporalUnit($largestUnit);
                $dateUnitsLU = ['year', 'month', 'week', 'day'];
                if (!in_array($largestUnit, $dateUnitsLU, true)) {
                    throw new RangeError("Invalid largest unit for date: {$largestUnit}");
                }
            }
        }
        // Read options in ALPHABETICAL order per spec.
        $ri = $opts->get('roundingIncrement');
        if (!($ri instanceof JsUndefined)) {
            $riNum = TypeConversion::toNumber($ri);
            if (!is_finite($riNum)) {
                throw new RangeError("Invalid roundingIncrement");
            }
            // Truncate to integer per spec (ToTemporalRoundingIncrement step 3).
            $riNum = (int) $riNum;
            // Step 4: must be in [1, 1e9].
            if ($riNum < 1 || $riNum > 1_000_000_000) {
                throw new RangeError("Invalid roundingIncrement");
            }
        }
        $rm = $opts->get('roundingMode');
        if (!($rm instanceof JsUndefined)) {
            $rmStr = TypeConversion::toString($rm);
            $validRM = ['ceil', 'floor', 'expand', 'trunc', 'halfCeil', 'halfFloor', 'halfExpand', 'halfTrunc', 'halfEven'];
            if (!in_array($rmStr, $validRM, true)) {
                throw new RangeError("Invalid roundingMode: {$rmStr}");
            }
        }
        $su = $opts->get('smallestUnit');
        if (!($su instanceof JsUndefined)) {
            $smallestUnit = TypeConversion::toString($su);
            $smallestUnit = self::canonicalTemporalUnit($smallestUnit);
            $dateUnits = ['year', 'month', 'week', 'day'];
            if (!in_array($smallestUnit, $dateUnits, true)) {
                throw new RangeError("Invalid smallest unit for date: {$smallestUnit}");
            }
        }
        // Default largestUnit to smallestUnit if needed.
        if (isset($smallestUnit)) {
            $allU = ['year', 'month', 'week', 'day', 'hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];
            $liIdx = array_search($largestUnit, $allU);
            $siIdx = array_search($smallestUnit, $allU);
            if (!$largestUnitExplicit && $siIdx !== false && $liIdx !== false && $siIdx < $liIdx) {
                $largestUnit = $smallestUnit;
                $liIdx = $siIdx;
            }
            if ($liIdx !== false && $siIdx !== false && $liIdx > $siIdx) {
                throw new RangeError('largestUnit must be >= smallestUnit');
            }
        }

        // DifferenceISODate per spec: date1 is the anchor.
        // until(this, other): sign=1, anchor=date1 (the earlier date)
        // since(this, other): sign=-1, anchor=date1 (the later date)
        $cmp = ($y2 <=> $y1) ?: ($m2 <=> $m1) ?: ($d2 <=> $d1);
        if ($cmp === 0) {
            return self::createDurationObject(0, 0, 0, 0, 0, 0, 0, 0, 0, 0);
        }
        $natSign = $cmp > 0 ? 1 : -1;
        $anchorDay = $d1;
        if ($natSign < 0) {
            [$smlY, $smlM, $smlD, $lrgY, $lrgM, $lrgD] = [$y2, $m2, $d2, $y1, $m1, $d1];
        } else {
            [$smlY, $smlM, $smlD, $lrgY, $lrgM, $lrgD] = [$y1, $m1, $d1, $y2, $m2, $d2];
        }
        $years = 0;
        $months = 0;
        $weeks = 0;
        $days = 0;
        $skipIsoYearMonth = false;
        if (
            ($largestUnit === 'year' || $largestUnit === 'month')
            && !in_array($cal1, ['iso8601', 'gregory', 'roc', 'japanese'], true)
        ) {
            $calRes = self::calendarYearsMonthsDaysBetween(
                $cal1,
                $y1,
                $m1,
                $d1,
                $y2,
                $m2,
                $d2,
                $largestUnit,
            );
            if ($calRes !== null) {
                [$years, $months, $days] = array_map('abs', $calRes);
                $skipIsoYearMonth = true;
            }
        }
        if ($skipIsoYearMonth) {
            // years/months/days already set by calendar-aware helper.
        } elseif ($largestUnit === 'year' || $largestUnit === 'month') {
            if ($natSign > 0) {
                // Forward: anchor = date1 (smaller). Add months, clamp to anchor day.
                $totalMonths = ($lrgY * 12 + $lrgM) - ($smlY * 12 + $smlM);
                // If target day < anchor day, the last month step is incomplete.
                if ($lrgD < $anchorDay) {
                    $totalMonths--;
                }
                [$midMY, $midMM, $midD] = self::computeMonthMidpoint(1, $smlY, $smlM, $lrgY, $lrgM, $anchorDay, $totalMonths);
                $days = self::isoToJulianDay($lrgY, $lrgM, $lrgD) - self::isoToJulianDay($midMY, $midMM, $midD);
                if ($days < 0) {
                    $totalMonths--;
                    [$midMY, $midMM, $midD] = self::computeMonthMidpoint(1, $smlY, $smlM, $lrgY, $lrgM, $anchorDay, $totalMonths);
                    $days = self::isoToJulianDay($lrgY, $lrgM, $lrgD) - self::isoToJulianDay($midMY, $midMM, $midD);
                }
            } else {
                // Backward: anchor = date1 (larger). Subtract months, clamp to anchor day.
                $totalMonths = ($lrgY * 12 + $lrgM) - ($smlY * 12 + $smlM);
                $mt = $lrgY * 12 + ($lrgM - 1) - $totalMonths;
                $midMY = intdiv($mt, 12);
                $midMM = ($mt % 12) + 1;
                if ($midMM < 1) {
                    $midMM += 12;
                    $midMY--;
                }
                $midD = min($anchorDay, self::isoDaysInMonth($midMY, $midMM));
                $days = self::isoToJulianDay($midMY, $midMM, $midD) - self::isoToJulianDay($smlY, $smlM, $smlD);
                if ($days < 0) {
                    $totalMonths--;
                    $mt = $lrgY * 12 + ($lrgM - 1) - $totalMonths;
                    $midMY = intdiv($mt, 12);
                    $midMM = ($mt % 12) + 1;
                    if ($midMM < 1) {
                        $midMM += 12;
                        $midMY--;
                    }
                    $midD = min($anchorDay, self::isoDaysInMonth($midMY, $midMM));
                    $days = self::isoToJulianDay($midMY, $midMM, $midD) - self::isoToJulianDay($smlY, $smlM, $smlD);
                }
            }
            if ($largestUnit === 'year') {
                $years = intdiv($totalMonths, 12);
                $months = $totalMonths - $years * 12;
            } else {
                $months = $totalMonths;
            }
        } elseif ($largestUnit === 'week') {
            $jd1 = self::isoToJulianDay($smlY, $smlM, $smlD);
            $jd2 = self::isoToJulianDay($lrgY, $lrgM, $lrgD);
            $totalDays = $jd2 - $jd1;
            $weeks = intdiv($totalDays, 7);
            $days = $totalDays - $weeks * 7;
        } else {
            $jd1 = self::isoToJulianDay($smlY, $smlM, $smlD);
            $jd2 = self::isoToJulianDay($lrgY, $lrgM, $lrgD);
            $days = $jd2 - $jd1;
        }
        $roundedSU = $smallestUnit ?? 'day';
        $roundMode = $rmStr ?? 'trunc';
        $roundInc = isset($riNum) ? (int) $riNum : 1;
        $effectiveSign = $sign * $natSign;
        if ($effectiveSign < 0) {
            $roundMode = match ($roundMode) {
                'ceil' => 'floor',
                'floor' => 'ceil',
                'halfCeil' => 'halfFloor',
                'halfFloor' => 'halfCeil',
                default => $roundMode,
            };
        }
        if ($roundedSU === 'year' || $roundedSU === 'month') {
            $destination = bcmul((string) self::isoDateToDays($y2, $m2, $d2), '86400000000000', 0);
            $bounds = self::calendarUnitBounds($date1, $destination, $roundedSU, $roundInc);
            $rounded = abs(self::roundCalendarUnitFromBounds(
                $bounds,
                $destination,
                $roundInc,
                $natSign < 0 ? match ($roundMode) {
                    'ceil' => 'floor', 'floor' => 'ceil',
                    'halfCeil' => 'halfFloor', 'halfFloor' => 'halfCeil', default => $roundMode,
                } : $roundMode,
            ));
            if ($roundedSU === 'year') {
                $years = $rounded;
                $months = 0;
            } elseif ($largestUnit === 'year') {
                $years = intdiv($rounded, 12);
                $months = $rounded % 12;
            } else {
                $years = 0;
                $months = $rounded;
            }
            $weeks = 0;
            $days = 0;
        } elseif ($roundedSU === 'week') {
            $totalDays = $weeks * 7 + $days;
            $roundedDays = self::roundToIncrement($totalDays, $roundInc * 7, $roundMode);
            $weeks = intdiv($roundedDays, 7);
            $days = 0;
        } elseif ($roundInc > 1) {
            $totalDays = $weeks * 7 + $days;
            $roundedDays = self::roundToIncrement($totalDays, $roundInc, $roundMode);
            if ($largestUnit === 'week') {
                $weeks = intdiv($roundedDays, 7);
                $days = $roundedDays - $weeks * 7;
            } else {
                $days = $roundedDays;
            }
        }
        // Validate: per NudgeToCalendarUnit, both the floor and ceiling (floor + increment)
        // must produce valid ISO dates when added to the reference date.
        if (in_array($roundedSU, ['year', 'month', 'week'], true) && $roundInc > 1) {
            // Compute the ceiling value (one increment beyond the rounded result).
            $ceilYears = $years;
            $ceilMonths = $months;
            $ceilWeeks = $weeks;
            $ceilDays = $days;
            if ($roundedSU === 'year') {
                $ceilYears += $roundInc;
            } elseif ($roundedSU === 'month') {
                $ceilMonths += $roundInc;
            } elseif ($roundedSU === 'week') {
                $ceilWeeks += $roundInc;
            }
            try {
                $refDate = self::createPlainDateObject($y1, $m1, $d1, 'iso8601');
                $addSign = $natSign > 0 ? 1 : -1;
                $ceilDur = self::createDurationObject($ceilYears, $ceilMonths, $ceilWeeks, $ceilDays, 0, 0, 0, 0, 0, 0);
                self::plainDateAdd($refDate, $ceilDur, $addSign);
            } catch (\Throwable) {
                throw new RangeError('Rounded date outside valid ISO date range');
            }
        }
        return self::createDurationObject(
            $effectiveSign * $years,
            $effectiveSign * $months,
            $effectiveSign * $weeks,
            $effectiveSign * $days,
            0,
            0,
            0,
            0,
            0,
            0,
        );
    }

    private static function isoToJulianDay(int $y, int $m, int $d): int
    {
        // Compute days since epoch (simple days count using PHP).
        try {
            $dt = new \DateTimeImmutable('2000-01-01', new \DateTimeZone('UTC'));
            $dt = $dt->setDate($y, $m, $d);
            return (int) floor((int) $dt->format('U') / 86400);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @var int $overflowDays Set by plainTimeAdd for PlainDateTime to use. */
    private static int $lastTimeAddOverflowDays = 0;

    private static function plainTimeAdd(JsValue $time, JsObject $dur, int $sign): JsObject
    {
        $h = self::getSlotInt($time, '[[ISOHour]]');
        $min = self::getSlotInt($time, '[[ISOMinute]]');
        $s = self::getSlotInt($time, '[[ISOSecond]]');
        $ms = self::getSlotInt($time, '[[ISOMillisecond]]');
        $us = self::getSlotInt($time, '[[ISOMicrosecond]]');
        $ns = self::getSlotInt($time, '[[ISONanosecond]]');

        // Use bcmath for large values.
        $totalNs = bcadd(
            bcadd(bcmul((string) ($h * 3600 + $min * 60 + $s), '1000000000', 0), (string) ($ms * 1000000 + $us * 1000 + $ns), 0),
            '0',
            0,
        );
        $durNs = self::durationToTotalNs(self::createDurationObject(
            0,
            0,
            0,
            0,
            self::getDurationField($dur, 'hours'),
            self::getDurationField($dur, 'minutes'),
            self::getDurationField($dur, 'seconds'),
            self::getDurationField($dur, 'milliseconds'),
            self::getDurationField($dur, 'microseconds'),
            self::getDurationField($dur, 'nanoseconds'),
        ));
        if ($sign < 0) {
            $durNs = bcsub('0', $durNs, 0);
        }

        $result = bcadd($totalNs, $durNs, 0);
        $dayNs = '86400000000000';
        // Calculate overflow days.
        if (bccomp($result, '0', 0) < 0) {
            $overflowDays = (int) bcdiv($result, $dayNs, 0);
            if (bcmod($result, $dayNs) !== '0') {
                $overflowDays--;
            }
            $result = bcsub($result, bcmul((string) $overflowDays, $dayNs, 0), 0);
        } else {
            $overflowDays = (int) bcdiv($result, $dayNs, 0);
            $result = bcmod($result, $dayNs);
        }
        self::$lastTimeAddOverflowDays = $overflowDays;

        $resultInt = (int) (string) $result;
        if ($resultInt < 0) {
            $resultInt += 86400000000000;
        }
        $ns2 = $resultInt % 1000;
        $resultInt = intdiv($resultInt, 1000);
        $us2 = $resultInt % 1000;
        $resultInt = intdiv($resultInt, 1000);
        $ms2 = $resultInt % 1000;
        $resultInt = intdiv($resultInt, 1000);
        $s2 = $resultInt % 60;
        $resultInt = intdiv($resultInt, 60);
        $min2 = $resultInt % 60;
        $h2 = intdiv($resultInt, 60);

        return self::createPlainTimeObject($h2, $min2, $s2, $ms2, $us2, $ns2);
    }

    private static function plainTimeDifference(JsValue $time1, JsValue $time2, JsValue $options): JsObject
    {
        $opts = self::readDifferenceOptionsAlphabetical(self::getOptionsObject($options), false);
        $ns1 = self::timeToNs($time1);
        $ns2 = self::timeToNs($time2);
        $diffNs = (string) ($ns2 - $ns1);
        $largestUnit = 'hour';
        $largestUnitExplicit = false;
        $validTimeUnits = ['hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];
        $lu = $opts->get('largestUnit');
        if (!($lu instanceof JsUndefined)) {
            $largestUnitExplicit = true;
            $largestUnit = TypeConversion::toString($lu);
            if ($largestUnit === 'auto') {
                $largestUnit = 'hour';
                $largestUnitExplicit = false;
            } else {
                $largestUnit = self::canonicalTemporalUnit($largestUnit);
                if (!in_array($largestUnit, $validTimeUnits, true)) {
                    throw new RangeError("Invalid largest unit for time: {$largestUnit}");
                }
            }
        }
        // Alphabetical order: roundingIncrement, roundingMode, smallestUnit.
        $ri = $opts->get('roundingIncrement');
        if (!($ri instanceof JsUndefined)) {
            $riNum = TypeConversion::toNumber($ri);
            if (!is_finite($riNum)) {
                throw new RangeError("Invalid roundingIncrement");
            }
            $riNum = (int) $riNum;
            if ($riNum < 1 || $riNum > 1_000_000_000) {
                throw new RangeError("Invalid roundingIncrement");
            }
        }
        $rm = $opts->get('roundingMode');
        if (!($rm instanceof JsUndefined)) {
            $rmStr = TypeConversion::toString($rm);
            $validRM = ['ceil', 'floor', 'expand', 'trunc', 'halfCeil', 'halfFloor', 'halfExpand', 'halfTrunc', 'halfEven'];
            if (!in_array($rmStr, $validRM, true)) {
                throw new RangeError("Invalid roundingMode: {$rmStr}");
            }
        }
        $su = $opts->get('smallestUnit');
        if (!($su instanceof JsUndefined)) {
            $suStr = TypeConversion::toString($su);
            $suCanon = self::canonicalTemporalUnit($suStr);
            if (!in_array($suCanon, $validTimeUnits, true)) {
                throw new RangeError("Invalid smallest unit for time: {$suStr}");
            }
        }
        // Validate roundingIncrement divides evenly.
        if (isset($riNum) && $riNum > 1) {
            self::validateRoundingIncrement($suCanon ?? 'nanosecond', $riNum);
        }
        // Default largestUnit to smallestUnit if needed.
        if (isset($suCanon)) {
            $luIdx = array_search($largestUnit, $validTimeUnits);
            $suIdx = array_search($suCanon, $validTimeUnits);
            if (!$largestUnitExplicit && $suIdx < $luIdx) {
                $largestUnit = $suCanon;
                $luIdx = $suIdx;
            }
            if ($luIdx !== false && $suIdx !== false && $luIdx > $suIdx) {
                throw new RangeError('largestUnit must be >= smallestUnit');
            }
        }
        // Apply rounding.
        $roundIncrement = isset($riNum) ? (int) $riNum : 1;
        $roundMode = $rmStr ?? 'trunc';
        $suFinal = $suCanon ?? 'nanosecond';
        if ($suFinal !== 'nanosecond' || $roundIncrement !== 1) {
            $unitNsMap = [
                'hour' => '3600000000000',
                'minute' => '60000000000',
                'second' => '1000000000',
                'millisecond' => '1000000',
                'microsecond' => '1000',
                'nanosecond' => '1',
            ];
            $unitNs = $unitNsMap[$suFinal];
            $incrementNs = bcmul((string) $roundIncrement, $unitNs, 0);
            $diffNs = self::roundNs($diffNs, $incrementNs, $roundMode);
        }
        return self::nsToTimeDuration($diffNs, $largestUnit);
    }

    private static function timeToNs(JsValue $time): int
    {
        $h = self::getSlotInt($time, '[[ISOHour]]');
        $min = self::getSlotInt($time, '[[ISOMinute]]');
        $s = self::getSlotInt($time, '[[ISOSecond]]');
        $ms = self::getSlotInt($time, '[[ISOMillisecond]]');
        $us = self::getSlotInt($time, '[[ISOMicrosecond]]');
        $ns = self::getSlotInt($time, '[[ISONanosecond]]');
        return ($h * 3600 + $min * 60 + $s) * 1000000000 + $ms * 1000000 + $us * 1000 + $ns;
    }

    /** Round a PlainDateTime's time component by increment in ns, returning a new PlainDateTime. */
    private static function roundPlainDateTime(JsValue $dt, int $incrementNs, string $roundingMode): JsObject
    {
        $timeNs = (self::getSlotInt($dt, '[[ISOHour]]') * 3600
            + self::getSlotInt($dt, '[[ISOMinute]]') * 60
            + self::getSlotInt($dt, '[[ISOSecond]]')) * 1000000000
            + self::getSlotInt($dt, '[[ISOMillisecond]]') * 1000000
            + self::getSlotInt($dt, '[[ISOMicrosecond]]') * 1000
            + self::getSlotInt($dt, '[[ISONanosecond]]');
        $rounded = self::roundToIncrement($timeNs, $incrementNs, $roundingMode);
        $dayNs = 86400000000000;
        $extraDays = intdiv($rounded, $dayNs);
        $rounded = $rounded % $dayNs;
        if ($rounded < 0) {
            $rounded += $dayNs;
            $extraDays--;
        }
        $h = intdiv($rounded, 3600000000000);
        $rounded %= 3600000000000;
        $min = intdiv($rounded, 60000000000);
        $rounded %= 60000000000;
        $s = intdiv($rounded, 1000000000);
        $rounded %= 1000000000;
        $ms = intdiv($rounded, 1000000);
        $rounded %= 1000000;
        $us = intdiv($rounded, 1000);
        $ns = $rounded % 1000;
        $y = self::getSlotInt($dt, '[[ISOYear]]');
        $m = self::getSlotInt($dt, '[[ISOMonth]]');
        $dd = self::getSlotInt($dt, '[[ISODay]]');
        $cal = self::getSlotString($dt, '[[Calendar]]');
        if ($extraDays !== 0) {
            $dateObj = self::createPlainDateObject($y, $m, $dd, $cal);
            $durObj = self::createDurationObject(0, 0, 0, $extraDays, 0, 0, 0, 0, 0, 0);
            $newDate = self::plainDateAdd($dateObj, $durObj, 1);
            $y = self::getSlotInt($newDate, '[[ISOYear]]');
            $m = self::getSlotInt($newDate, '[[ISOMonth]]');
            $dd = self::getSlotInt($newDate, '[[ISODay]]');
        }
        return self::createPlainDateTimeObject($y, $m, $dd, $h, $min, $s, $ms, $us, $ns, $cal);
    }

    private static function roundPlainTime(JsValue $time, string $unit, string $roundingMode, int $increment): JsObject
    {
        $totalNs = self::timeToNs($time);
        $unitNs = (int) self::temporalUnitToNs($unit);
        $incNs = $unitNs * $increment;
        if ($incNs > 0) {
            $totalNs = self::roundToIncrement($totalNs, $incNs, $roundingMode);
        }
        // Wrap to day.
        $dayNs = 86400000000000;
        $totalNs = $totalNs % $dayNs;
        if ($totalNs < 0) {
            $totalNs += $dayNs;
        }

        $ns = $totalNs % 1000;
        $totalNs = intdiv($totalNs, 1000);
        $us = $totalNs % 1000;
        $totalNs = intdiv($totalNs, 1000);
        $ms = $totalNs % 1000;
        $totalNs = intdiv($totalNs, 1000);
        $s = $totalNs % 60;
        $totalNs = intdiv($totalNs, 60);
        $min = $totalNs % 60;
        $h = intdiv($totalNs, 60);

        return self::createPlainTimeObject($h, $min, $s, $ms, $us, $ns);
    }

    private static function plainDateTimeAdd(JsValue $dt, JsObject $dur, int $sign, string $overflow = 'constrain'): JsObject
    {
        $y = self::getSlotInt($dt, '[[ISOYear]]');
        $m = self::getSlotInt($dt, '[[ISOMonth]]');
        $d = self::getSlotInt($dt, '[[ISODay]]');
        $h = self::getSlotInt($dt, '[[ISOHour]]');
        $min = self::getSlotInt($dt, '[[ISOMinute]]');
        $s = self::getSlotInt($dt, '[[ISOSecond]]');
        $ms = self::getSlotInt($dt, '[[ISOMillisecond]]');
        $us = self::getSlotInt($dt, '[[ISOMicrosecond]]');
        $ns = self::getSlotInt($dt, '[[ISONanosecond]]');
        $cal = self::getSlotString($dt, '[[Calendar]]');

        // Add date part.
        $dateObj = self::createPlainDateObject($y, $m, $d, $cal);
        $dateDur = self::createDurationObject(
            self::getDurationField($dur, 'years'),
            self::getDurationField($dur, 'months'),
            self::getDurationField($dur, 'weeks'),
            self::getDurationField($dur, 'days'),
            0,
            0,
            0,
            0,
            0,
            0,
        );
        $newDate = self::plainDateAdd($dateObj, $dateDur, $sign, $overflow);

        // Add time part.
        $timeObj = self::createPlainTimeObject($h, $min, $s, $ms, $us, $ns);
        $timeDur = self::createDurationObject(
            0,
            0,
            0,
            0,
            self::getDurationField($dur, 'hours'),
            self::getDurationField($dur, 'minutes'),
            self::getDurationField($dur, 'seconds'),
            self::getDurationField($dur, 'milliseconds'),
            self::getDurationField($dur, 'microseconds'),
            self::getDurationField($dur, 'nanoseconds'),
        );
        $newTime = self::plainTimeAdd($timeObj, $timeDur, $sign);
        // Add overflow days from time addition to the date.
        $overflowDays = self::$lastTimeAddOverflowDays;
        if ($overflowDays !== 0) {
            $extraDur = self::createDurationObject(0, 0, 0, $overflowDays, 0, 0, 0, 0, 0, 0);
            $newDate = self::plainDateAdd($newDate, $extraDur, 1);
        }

        return self::createPlainDateTimeObject(
            self::getSlotInt($newDate, '[[ISOYear]]'),
            self::getSlotInt($newDate, '[[ISOMonth]]'),
            self::getSlotInt($newDate, '[[ISODay]]'),
            self::getSlotInt($newTime, '[[ISOHour]]'),
            self::getSlotInt($newTime, '[[ISOMinute]]'),
            self::getSlotInt($newTime, '[[ISOSecond]]'),
            self::getSlotInt($newTime, '[[ISOMillisecond]]'),
            self::getSlotInt($newTime, '[[ISOMicrosecond]]'),
            self::getSlotInt($newTime, '[[ISONanosecond]]'),
            self::getSlotString($newDate, '[[Calendar]]'),
        );
    }
}
