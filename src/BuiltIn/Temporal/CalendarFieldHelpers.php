<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Temporal;

use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\TypeError;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;

/** Shared calendar field preparation, merging, and resolution. */
trait CalendarFieldHelpers
{
    /**
     * @param list<string> $names
     * @param list<string> $required
     * @return array<string, int|string>
     */
    private static function prepareCalendarFields(
        JsObject $item,
        string $calendar,
        array $names,
        array $required = [],
    ): array {
        if (self::calendarHasEras($calendar)) {
            $names[] = 'era';
            $names[] = 'eraYear';
        }
        sort($names);
        $fields = [];
        foreach ($names as $name) {
            $value = $item->get($name);
            if ($value instanceof JsUndefined) {
                if (in_array($name, $required, true)) {
                    throw new TypeError("missing required property: {$name}");
                }
                continue;
            }
            if ($name === 'monthCode' || $name === 'offset') {
                $primitive = TypeConversion::toPrimitive($value, 'string');
                if (!$primitive instanceof JsString) {
                    throw new TypeError("{$name} must be a string");
                }
                $fields[$name] = $primitive->value;
                if ($name === 'monthCode') {
                    self::parseMonthCodeSyntax($primitive->value);
                } elseif (!self::isValidOffsetString($primitive->value)) {
                    throw new RangeError('Invalid offset string');
                }
            } elseif ($name === 'era') {
                $fields[$name] = TypeConversion::toString($value);
            } elseif ($name === 'timeZone') {
                $fields[$name] = self::toTemporalTimeZoneIdentifier($value);
            } else {
                $fields[$name] = self::toTemporalInteger($value, $name);
                if (($name === 'month' || $name === 'day') && $fields[$name] < 1) {
                    throw new RangeError("{$name} must be positive");
                }
            }
        }
        return $fields;
    }

    /**
     * @param array<string, int|string> $fields
     * @param array<string, int|string> $additional
     * @return array<string, int|string>
     */
    private static function mergeCalendarFields(array $fields, array $additional): array
    {
        if (isset($additional['month']) || isset($additional['monthCode'])) {
            unset($fields['month'], $fields['monthCode']);
        }
        if (isset($additional['year']) || isset($additional['era']) || isset($additional['eraYear'])) {
            unset($fields['year'], $fields['era'], $fields['eraYear']);
        }
        return array_replace($fields, $additional);
    }

    /**
     * @param array<string, int|string> $fields
     * @return array{year: int, month: int, day: int}
     */
    private static function calendarDateFromFields(string $calendar, array $fields, string $overflow): array
    {
        if (isset($fields['era']) !== isset($fields['eraYear'])) {
            throw new TypeError('era and eraYear must be provided together');
        }
        $year = (int) self::calendarYearFromFields(
            $calendar,
            isset($fields['era']) ? (string) $fields['era'] : null,
            isset($fields['eraYear']) ? (float) $fields['eraYear'] : null,
            isset($fields['year']) ? (float) $fields['year'] : null,
        );
        $monthCode = isset($fields['monthCode']) ? (string) $fields['monthCode'] : null;
        $month = isset($fields['month']) ? (int) $fields['month'] : null;
        $day = isset($fields['day']) ? (int) $fields['day'] : 1;
        if ($monthCode === null && $month === null) {
            throw new TypeError('missing required property: month or monthCode');
        }
        $first = self::calendarPartsToIso($calendar, $year, 'M01', null, 1);
        if ($first === null) {
            throw new RangeError('Calendar year is outside the supported range');
        }
        $maxMonth = self::calendarMonthsInYear($calendar, $first['year'], $first['month'], $first['day']) ?? 12;
        if ($monthCode !== null) {
            self::parseMonthCode($monthCode, $calendar);
            $start = self::calendarPartsToIso($calendar, $year, $monthCode, null, 1);
            if ($start === null && str_ends_with($monthCode, 'L') && $overflow === 'constrain') {
                $monthCode = $calendar === 'hebrew' ? 'M06' : substr($monthCode, 0, -1);
                $start = self::calendarPartsToIso($calendar, $year, $monthCode, null, 1);
            }
            if ($start === null) {
                throw new RangeError('Month code does not exist in calendar year');
            }
            $resolved = self::isoToCalendarParts($calendar, $start['year'], $start['month'], $start['day']);
            if ($month !== null && ($resolved === null || $month !== $resolved['month'])) {
                throw new RangeError('month and monthCode must agree');
            }
        } else {
            if ($month < 1 || ($overflow === 'reject' && $month > $maxMonth)) {
                throw new RangeError('month is out of range');
            }
            $month = min($month, $maxMonth);
            $start = self::calendarPartsToIso($calendar, $year, null, $month, 1);
            if ($start === null) {
                throw new RangeError('Month does not exist in calendar year');
            }
        }
        $maxDay = self::calendarDaysInMonthForIso($calendar, $start['year'], $start['month'], $start['day']);
        if ($maxDay === null) {
            throw new RangeError('Cannot resolve calendar month');
        }
        if ($day < 1 || ($overflow === 'reject' && $day > $maxDay)) {
            throw new RangeError('day is out of range');
        }
        return self::isoDateFromDays(
            self::isoDateToDays($start['year'], $start['month'], $start['day']) + min($day, $maxDay) - 1,
        );
    }

    /**
     * @param array<string, int|string> $fields
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}
     */
    private static function timeFromFields(array $fields, string $overflow): array
    {
        $time = [];
        foreach (['hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'] as $name) {
            $time[] = (int) ($fields[$name] ?? 0);
        }
        if ($overflow === 'constrain') {
            return self::constrainISOTime(...$time);
        }
        self::validateISOTime(...$time);
        return $time;
    }

    /** @return array<string, int|string> */
    private static function calendarFieldsForIso(string $calendar, int $year, int $month, int $day): array
    {
        $parts = self::isoToCalendarParts($calendar, $year, $month, $day);
        if ($parts === null) {
            throw new RangeError('Cannot resolve calendar fields');
        }
        return [
            'year' => (int) $parts['year'],
            'monthCode' => (string) $parts['monthCode'],
            'day' => (int) $parts['day'],
        ];
    }
    /**
     * @param array<string, int|string> $fields
     * @return array{year: int, month: int, day: int}
     */
    private static function calendarMonthDayFromFields(string $calendar, array $fields, string $overflow): array
    {
        if ($calendar === 'iso8601') {
            $fields['year'] ??= 1972;
            $date = self::calendarDateFromFields($calendar, $fields, $overflow);
            $date['year'] = 1972;
            return $date;
        }
        if (isset($fields['era']) !== isset($fields['eraYear'])) {
            throw new TypeError('era and eraYear must be provided together');
        }
        $hasYear = isset($fields['year']) || isset($fields['era']);
        if (isset($fields['month']) && !$hasYear) {
            throw new TypeError('month requires a calendar year');
        }
        if (!isset($fields['monthCode']) && !isset($fields['month'])) {
            throw new TypeError('month or monthCode is required');
        }
        if ($hasYear) {
            $year = self::calendarYearFromFields(
                $calendar,
                isset($fields['era']) ? (string) $fields['era'] : null,
                isset($fields['eraYear']) ? (float) $fields['eraYear'] : null,
                isset($fields['year']) ? (float) $fields['year'] : null,
            );
            $start = self::calendarPartsToIso($calendar, (int) $year, 'M01', null, 1);
            if ($start === null || $start['year'] < self::ISO_YEAR_MIN - 1 || $start['year'] > self::ISO_YEAR_MAX) {
                throw new RangeError('Calendar year is outside the supported range');
            }
            $date = self::calendarDateFromFields($calendar, $fields, $overflow);
            $fields = self::calendarFieldsForIso($calendar, $date['year'], $date['month'], $date['day']);
        }
        $monthCode = (string) $fields['monthCode'];
        $month = self::parseMonthCode($monthCode, $calendar);
        $day = (int) $fields['day'];
        $maxDay = self::maxDaysInCalendarMonth($calendar, $month, str_ends_with($monthCode, 'L'));
        if ($day > $maxDay && $overflow === 'reject') {
            throw new RangeError('day is out of range');
        }
        $day = min($day, $maxDay);
        $date = self::pmdReferenceIsoFor($calendar, $monthCode, null, $day);
        if ($date === null && in_array($calendar, ['chinese', 'dangi'], true) && str_ends_with($monthCode, 'L')) {
            if ($overflow === 'reject') {
                throw new RangeError('Month code has no supported reference date');
            }
            $date = self::pmdReferenceIsoFor($calendar, substr($monthCode, 0, -1), null, $day);
        }
        if ($date === null) {
            throw new RangeError('Cannot resolve a reference date');
        }
        return $date;
    }
}
