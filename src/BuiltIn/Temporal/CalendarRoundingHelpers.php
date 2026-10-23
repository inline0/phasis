<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Temporal;

use Phasis\Exceptions\RangeError;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsValue;

/** Exact calendar intervals used for duration totals and rounding. */
trait CalendarRoundingHelpers
{
    private static function calendarUnitEpochNs(JsValue $reference, string $unit, int $count): string
    {
        $duration = self::createDurationObject(
            $unit === 'year' ? $count : 0,
            $unit === 'month' ? $count : 0,
            $unit === 'week' ? $count : 0,
            $unit === 'day' ? $count : 0,
            0,
            0,
            0,
            0,
            0,
            0,
        );
        if ($reference instanceof JsObject && $reference->has('[[IsZonedDateTime]]')) {
            $result = self::addDurationToZdt($reference, $duration, 1);
            self::validateInstantRange($result);
            return $result;
        }
        $result = self::plainDateAdd($reference, $duration, 1);
        return bcmul((string) self::isoDateToDays(
            self::getSlotInt($result, '[[ISOYear]]'),
            self::getSlotInt($result, '[[ISOMonth]]'),
            self::getSlotInt($result, '[[ISODay]]'),
        ), '86400000000000', 0);
    }

    private static function durationDestinationEpochNs(JsValue $reference, JsValue $duration): string
    {
        if ($reference instanceof JsObject && $reference->has('[[IsZonedDateTime]]')) {
            $result = self::addDurationToZdt($reference, $duration, 1);
            self::validateInstantRange($result);
            return $result;
        }
        $dateDuration = self::createDurationObject(
            self::getDurationField($duration, 'years'),
            self::getDurationField($duration, 'months'),
            self::getDurationField($duration, 'weeks'),
            self::getDurationField($duration, 'days'),
            0,
            0,
            0,
            0,
            0,
            0,
        );
        $date = self::plainDateAdd($reference, $dateDuration, 1);
        $dateNs = bcmul((string) self::isoDateToDays(
            self::getSlotInt($date, '[[ISOYear]]'),
            self::getSlotInt($date, '[[ISOMonth]]'),
            self::getSlotInt($date, '[[ISODay]]'),
        ), '86400000000000', 0);
        $time = self::createDurationObject(
            0,
            0,
            0,
            0,
            self::getDurationField($duration, 'hours'),
            self::getDurationField($duration, 'minutes'),
            self::getDurationField($duration, 'seconds'),
            self::getDurationField($duration, 'milliseconds'),
            self::getDurationField($duration, 'microseconds'),
            self::getDurationField($duration, 'nanoseconds'),
        );
        return bcadd($dateNs, self::durationToTotalNs($time), 0);
    }

    /** @return array{int, string, string, int} */
    private static function calendarUnitBounds(JsValue $reference, string $destination, string $unit, int $increment): array
    {
        $start = self::calendarUnitEpochNs($reference, $unit, 0);
        $direction = bccomp($destination, $start, 0) < 0 ? -1 : 1;
        $calendar = self::getSlotString($reference, '[[Calendar]]');
        $zone = $reference instanceof JsObject && $reference->has('[[IsZonedDateTime]]')
            ? self::getSlotString($reference, '[[TimeZone]]') : 'UTC';
        $baseParts = self::epochNsToISOParts($start, $zone);
        $endParts = self::epochNsToISOParts($destination, $zone);
        $baseDate = self::createPlainDateObject($baseParts['year'], $baseParts['month'], $baseParts['day'], $calendar);
        $endDate = self::createPlainDateObject($endParts['year'], $endParts['month'], $endParts['day'], $calendar);
        $options = JsObject::createNullPrototype();
        $options->set('largestUnit', new JsString($unit));
        $difference = self::plainDateDifference($baseDate, $endDate, $options, 1);
        $count = intdiv(abs(self::getDurationField($difference, $unit . 's')), $increment) * $increment;
        $lower = self::calendarUnitEpochNs($reference, $unit, $direction * $count);
        while ($count > 0 && bccomp($lower, $destination, 0) * $direction > 0) {
            $count -= $increment;
            $lower = self::calendarUnitEpochNs($reference, $unit, $direction * $count);
        }
        $upper = self::calendarUnitEpochNs($reference, $unit, $direction * ($count + $increment));
        // Constrained year/month addition can put the initial estimate one
        // interval behind the destination, such as January 31 plus one month.
        while (bccomp($destination, $upper, 0) * $direction >= 0) {
            $count += $increment;
            $lower = $upper;
            $upper = self::calendarUnitEpochNs($reference, $unit, $direction * ($count + $increment));
        }
        return [$direction * $count, $lower, $upper, $direction];
    }

    /** @param array{int, string, string, int} $bounds */
    private static function totalCalendarUnitFromBounds(array $bounds, string $destination, int $increment = 1): float
    {
        [$count, $lower, $upper, $direction] = $bounds;
        $length = bcsub($upper, $lower, 0);
        $progress = bcsub($destination, $lower, 0);
        $numerator = bcadd(bcmul((string) $count, $length, 0), bcmul($progress, (string) ($direction * $increment), 0), 0);
        return (float) bcdiv($numerator, $length, 30);
    }

    /** @param array{int, string, string, int} $bounds */
    private static function roundCalendarUnitFromBounds(array $bounds, string $destination, int $increment, string $mode): int
    {
        [$count, $lower, $upper, $direction] = $bounds;
        $length = bcsub($upper, $lower, 0);
        $progress = bcsub($destination, $lower, 0);
        if ($direction < 0) {
            $length = bcsub('0', $length, 0);
            $progress = bcsub('0', $progress, 0);
        }
        $numerator = bcadd(bcmul((string) abs($count), $length, 0), bcmul($progress, (string) $increment, 0), 0);
        if ($direction < 0) {
            $numerator = bcsub('0', $numerator, 0);
        }
        $rounded = self::roundNs($numerator, bcmul($length, (string) $increment, 0), $mode);
        return (int) bcdiv($rounded, $length, 0);
    }
}
