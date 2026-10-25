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
 * Temporal helper section (TypeConversionHelpers). Composed into TemporalObject
 * via `use Temporal\TypeConversionHelpers;`. `self::` references resolve into
 * the composing class.
 */
trait TypeConversionHelpers
{
    // -----------------------------------------------------------------------
    // Helpers: type conversion
    // -----------------------------------------------------------------------

    private static function toPlainDate(JsValue $item, string $overflow = 'constrain', ?JsValue $rawOptions = null): JsObject
    {
        if ($item instanceof JsObject) {
            if (
                $item->hasNativeSlot('[[ISOYear]]') && !$item->hasNativeSlot('[[IsPlainTime]]') && !$item->hasNativeSlot('[[IsPlainDateTime]]')
                && !$item->hasNativeSlot('[[IsPlainYearMonth]]') && !$item->hasNativeSlot('[[IsPlainMonthDay]]')
                && !$item->hasNativeSlot('[[IsZonedDateTime]]') && !$item->hasNativeSlot('[[IsDuration]]')
                && !$item->hasNativeSlot('[[EpochNanoseconds]]')
            ) {
                return self::createPlainDateObject(
                    self::getSlotInt($item, '[[ISOYear]]'),
                    self::getSlotInt($item, '[[ISOMonth]]'),
                    self::getSlotInt($item, '[[ISODay]]'),
                    self::getSlotString($item, '[[Calendar]]'),
                );
            }
            if ($item->hasNativeSlot('[[IsPlainDateTime]]')) {
                return self::createPlainDateObject(
                    self::getSlotInt($item, '[[ISOYear]]'),
                    self::getSlotInt($item, '[[ISOMonth]]'),
                    self::getSlotInt($item, '[[ISODay]]'),
                    self::getSlotString($item, '[[Calendar]]'),
                );
            }
            if ($item->hasNativeSlot('[[IsZonedDateTime]]')) {
                $parts = self::zonedDateTimeParts($item);
                return self::createPlainDateObject(
                    $parts['year'],
                    $parts['month'],
                    $parts['day'],
                    self::getSlotString($item, '[[Calendar]]'),
                );
            }
            $calValue = $item->get('calendar');
            $cal = $calValue instanceof JsUndefined ? 'iso8601' : self::toCalendarSlotValue($calValue);
            $fields = self::prepareCalendarFields($item, $cal, ['day', 'month', 'monthCode', 'year'], ['day']);
            if ($rawOptions !== null) {
                $overflow = self::getOverflow(self::getOptionsObject($rawOptions));
            }
            $date = self::calendarDateFromFields($cal, $fields, $overflow);
            return self::createPlainDateObject($date['year'], $date['month'], $date['day'], $cal);
        }
        // Per spec: reject non-string, non-object types directly.
        if ($item instanceof JsUndefined || $item instanceof JsNull) {
            throw new TypeError('Cannot convert undefined/null to a Temporal.PlainDate');
        }
        if ($item instanceof \Phasis\Value\JsSymbol) {
            throw new TypeError('Cannot convert a Symbol to a Temporal.PlainDate');
        }
        if ($item instanceof JsNumber || $item instanceof \Phasis\Value\JsBigInt) {
            throw new TypeError('Cannot convert a number/BigInt to a Temporal.PlainDate');
        }
        if ($item instanceof JsBoolean) {
            throw new TypeError('Cannot convert a boolean to a Temporal.PlainDate');
        }
        $str = TypeConversion::toString($item);
        return self::parsePlainDateString($str);
    }

    private static function parsePlainDateString(string $str): JsObject
    {
        [$str, $cal] = self::normalizeTemporalString($str);
        self::validateTemporalDatePrefix($str);
        // Reject UTC designator (Z) for PlainDate.
        $noAnnot = preg_replace('/\[.*?\]/', '', $str);
        if (preg_match('/[Zz]/', $noAnnot)) {
            throw new RangeError(
                "String with UTC designator should not be valid as a PlainDate"
            );
        }
        // Reject -000000 (minus zero year).
        if (preg_match('/^-0{4,6}[-\d]/', $str)) {
            throw new RangeError("reject minus zero as extended year: {$str}");
        }
        // Reject extended year without sign (5+ digits with dash = year).
        if (preg_match('/^\d{5,}-/', $str)) {
            throw new RangeError("Extended year requires + or - prefix: {$str}");
        }
        // YYYY-MM-DD or YYYYMMDD with optional time, offset, and annotations.
        // UTC offset accepts hh / hh:mm / hh:mm:ss / hh:mm:ss.sss (extended or basic).
        $offsetOpt = '(?:[+-]\d{2}(?::?\d{2}(?::?\d{2}(?:[.,]\d{1,9})?)?)?)?';
        $timeOpt = "(?:[Tt ](\d{2})(?::?(\d{2})(?::?(\d{2})(?:[.,]\d{1,9})?)?)?{$offsetOpt})?";
        $pattern = "/^([+-]?\\d{4,6})-?(\\d{2})-?(\\d{2}){$timeOpt}(?:\\[.*?\\])*\$/";
        if (!preg_match($pattern, $str, $m)) {
            throw new RangeError("Invalid PlainDate string: {$str}");
        }
        $y = (int) $m[1];
        $m2 = (int) $m[2];
        $d = (int) $m[3];
        // Validate time part if captured.
        if (isset($m[4]) && $m[4] !== '') {
            $th = (int) $m[4];
            $tmin = isset($m[5]) ? (int) $m[5] : 0;
            $ts = isset($m[6]) ? (int) $m[6] : 0;
            if ($ts === 60) {
                $ts = 59;
            } // leap second
            if ($th > 23 || $tmin > 59 || $ts > 59) {
                throw new RangeError("Invalid time in string: {$str}");
            }
        }
        self::validateISODate($y, $m2, $d);
        // Validate calendar if not default.
        if ($cal !== 'iso8601' && !self::isValidCalendar($cal)) {
            throw new RangeError("Invalid calendar: {$cal}");
        }
        return self::createPlainDateObject($y, $m2, $d, $cal);
    }

    /**
     * Parse a relativeTo value into a PlainDate.
     * Strings with bracketed IANA annotation are parsed as ZonedDateTime then date extracted.
     * Z offset without IANA annotation is rejected.
     */
    /** Side-channel: when toRelativeToPlainDate sees a timeZone in the
     * property bag, it stashes the corresponding ZDT here for the caller. */
    private static ?JsObject $relativeToZdtCache = null;

    private static function toRelativeToPlainDate(JsValue $item): JsObject
    {
        self::$relativeToZdtCache = null;
        if ($item instanceof JsObject) {
            if (
                $item->hasNativeSlot('[[IsPlainDate]]') || $item->hasNativeSlot('[[IsPlainDateTime]]')
                || $item->hasNativeSlot('[[IsZonedDateTime]]') || $item->hasNativeSlot('[[ISOYear]]')
            ) {
                return self::toPlainDate($item);
            }
            // Property bag: per spec, read and convert all fields in alphabetical order.
            // calendar
            $calVal = $item->get('calendar');
            $cal = 'iso8601';
            if (!($calVal instanceof JsUndefined)) {
                $cal = self::toCalendarSlotValue($calVal);
            }
            // day (with valueOf)
            $dayVal = $item->get('day');
            $dNum = NAN;
            if (!($dayVal instanceof JsUndefined)) {
                $dNum = TypeConversion::toNumber($dayVal);
                if (!is_finite($dNum)) {
                    throw new RangeError("day must be finite");
                }
            }
            // era / eraYear (non-ISO calendars only).
            $eraStr = null;
            $eraYearNum = null;
            $eraSet = false;
            $eraYearSet = false;
            if ($cal !== 'iso8601') {
                $eraVal = $item->get('era');
                if (!($eraVal instanceof JsUndefined)) {
                    $eraSet = true;
                    $eraStr = TypeConversion::toString($eraVal);
                }
                $eraYearVal = $item->get('eraYear');
                if (!($eraYearVal instanceof JsUndefined)) {
                    $eraYearSet = true;
                    $eraYearNum = TypeConversion::toNumber($eraYearVal);
                    if (is_nan($eraYearNum) || !is_finite($eraYearNum)) {
                        throw new RangeError('eraYear must be finite');
                    }
                    if (floor($eraYearNum) !== $eraYearNum) {
                        throw new RangeError('eraYear must be an integer');
                    }
                }
                if (self::calendarHasEras($cal) && $eraSet !== $eraYearSet) {
                    throw new TypeError('era and eraYear must be provided together');
                }
            }
            // hour
            $hourVal = $item->get('hour');
            $hourInt = 0;
            if (!($hourVal instanceof JsUndefined)) {
                $hn = TypeConversion::toNumber($hourVal);
                if (!is_finite($hn)) {
                    throw new RangeError("hour must be finite");
                }
                $hourInt = (int) $hn;
            }
            // microsecond
            $microVal = $item->get('microsecond');
            $microInt = 0;
            if (!($microVal instanceof JsUndefined)) {
                $n = TypeConversion::toNumber($microVal);
                if (!is_finite($n)) {
                    throw new RangeError("microsecond must be finite");
                }
                $microInt = (int) $n;
            }
            // millisecond
            $milliVal = $item->get('millisecond');
            $milliInt = 0;
            if (!($milliVal instanceof JsUndefined)) {
                $n = TypeConversion::toNumber($milliVal);
                if (!is_finite($n)) {
                    throw new RangeError("millisecond must be finite");
                }
                $milliInt = (int) $n;
            }
            // minute
            $minVal = $item->get('minute');
            $minInt = 0;
            if (!($minVal instanceof JsUndefined)) {
                $n = TypeConversion::toNumber($minVal);
                if (!is_finite($n)) {
                    throw new RangeError("minute must be finite");
                }
                $minInt = (int) $n;
            }
            // month (with valueOf)
            $monthVal = $item->get('month');
            $mNum = NAN;
            if (!($monthVal instanceof JsUndefined)) {
                $mNum = TypeConversion::toNumber($monthVal);
                if (!is_finite($mNum)) {
                    throw new RangeError("month must be finite");
                }
            }
            // monthCode (with toString)
            $mcVal = $item->get('monthCode');
            $mc = null;
            if (!($mcVal instanceof JsUndefined)) {
                $mc = TypeConversion::toString($mcVal);
            }
            // nanosecond
            $nanoVal = $item->get('nanosecond');
            $nanoInt = 0;
            if (!($nanoVal instanceof JsUndefined)) {
                $n = TypeConversion::toNumber($nanoVal);
                if (!is_finite($n)) {
                    throw new RangeError("nanosecond must be finite");
                }
                $nanoInt = (int) $n;
            }
            // offset: convert with ToString inline per spec.
            $offsetVal = $item->get('offset');
            $offStr = null;
            if (!($offsetVal instanceof JsUndefined)) {
                if (
                    $offsetVal instanceof JsNull || $offsetVal instanceof JsNumber
                    || $offsetVal instanceof JsBoolean || $offsetVal instanceof \Phasis\Value\JsBigInt
                ) {
                    throw new TypeError("offset must be a string");
                }
                if ($offsetVal instanceof \Phasis\Value\JsSymbol) {
                    throw new TypeError("Cannot convert a Symbol to a string");
                }
                $offStr = ($offsetVal instanceof JsString) ? $offsetVal->value : TypeConversion::toString($offsetVal);
            }
            // second
            $secVal = $item->get('second');
            $secInt = 0;
            if (!($secVal instanceof JsUndefined)) {
                $n = TypeConversion::toNumber($secVal);
                if (!is_finite($n)) {
                    throw new RangeError("second must be finite");
                }
                $secInt = (int) $n;
            }
            // timeZone
            $tzVal = $item->get('timeZone');
            // year (with valueOf)
            $yearVal = $item->get('year');
            $yNum = NAN;
            if (!($yearVal instanceof JsUndefined)) {
                $yNum = TypeConversion::toNumber($yearVal);
                if (!is_finite($yNum)) {
                    throw new RangeError("year must be finite");
                }
            }
            // Validate timeZone if present.
            $hasTz = !($tzVal instanceof JsUndefined);
            if ($hasTz) {
                self::toTemporalTimeZoneIdentifier($tzVal);
                // Validate offset format (already converted above).
                if ($offStr !== null) {
                    self::validateTemporalOffsetString($offStr);
                }
            }
            // Required: year (or era+eraYear), day (and month or monthCode).
            $yNum = self::calendarYearFromFields(
                $cal,
                $eraStr,
                $eraYearNum,
                $yearVal instanceof JsUndefined ? null : $yNum,
            );
            if ($dayVal instanceof JsUndefined) {
                throw new TypeError('missing required property: day');
            }
            // Resolve month from month or monthCode.
            if ($monthVal instanceof JsUndefined) {
                if ($mc === null) {
                    throw new TypeError('missing required property: month');
                }
                $mNum = (float) self::parseMonthCode($mc);
            } elseif ($mc !== null) {
                $mcMonth = self::parseMonthCode($mc);
                if ($mcMonth !== (int) $mNum) {
                    throw new RangeError("month and monthCode must agree");
                }
            }
            $y = (int) $yNum;
            $m = (int) $mNum;
            $d = (int) $dNum;
            // Check for -0 year.
            if ($yNum === 0.0 && (unpack("H*", pack("d", $yNum))[1] ?? "") === "0000000000000080") {
                throw new RangeError('reject minus zero as extended year');
            }
            if ($cal === 'roc') {
                $y += 1911;
            } elseif ($cal !== 'iso8601' && !in_array($cal, ['gregory', 'japanese'], true)) {
                $parts = self::calendarPartsToIso($cal, $y, $mc, $mc === null ? $m : null, $d);
                if ($parts === null) {
                    throw new RangeError('Invalid calendar date');
                }
                [$y, $m, $d] = [$parts['year'], $parts['month'], $parts['day']];
            }
            [$y, $m, $d] = self::constrainISODate($y, $m, $d);
            // If timeZone present, validate the offset (no fuzzy match in property
            // bags) and stash the resulting ZDT in the side-channel cache so the
            // caller can promote relativeTo to a ZDT without re-reading fields.
            if ($hasTz) {
                $timeZone = self::toTemporalTimeZoneIdentifier($tzVal);
                $epochFromWall = self::isoDateTimeToEpochNs($y, $m, $d, $hourInt, $minInt, $secInt, $milliInt, $microInt, $nanoInt, $timeZone);
                if ($offStr !== null) {
                    $actualOffsetNs = self::getUtcOffsetNsForTimestamp($timeZone, $epochFromWall);
                    $givenOffsetNs = self::parseOffsetToNs($offStr);
                    if ($givenOffsetNs !== $actualOffsetNs) {
                        throw new RangeError("offset property \"{$offStr}\" does not match time zone \"{$timeZone}\"");
                    }
                }
                self::$relativeToZdtCache = self::createZonedDateTimeObject($epochFromWall, $timeZone, $cal);
            }
            return self::createPlainDateObject($y, $m, $d, $cal);
        }
        // Reject non-string, non-object primitives per spec.
        if ($item instanceof JsUndefined || $item instanceof JsNull) {
            throw new TypeError('Cannot convert undefined/null to relativeTo');
        }
        if ($item instanceof \Phasis\Value\JsSymbol) {
            throw new TypeError('Cannot convert a Symbol to relativeTo');
        }
        if ($item instanceof JsNumber || $item instanceof \Phasis\Value\JsBigInt) {
            throw new TypeError('Cannot convert a number/BigInt to relativeTo');
        }
        if ($item instanceof JsBoolean) {
            throw new TypeError('Cannot convert a boolean to relativeTo');
        }
        if ($item instanceof JsString) {
            $str = $item->value;
        } else {
            $str = TypeConversion::toString($item);
        }
        // Check for bracketed timezone annotation -> ZonedDateTime -> extract date.
        if (preg_match('/\[([^\]=]+)\]/', $str, $annMatch)) {
            $ann = $annMatch[1];
            if (!str_contains($ann, '=')) {
                $zdt = self::parseZonedDateTimeString($str);
                $parts = self::zonedDateTimeParts($zdt);
                return self::createPlainDateObject($parts['year'], $parts['month'], $parts['day'], 'iso8601');
            }
        }
        // No bracketed tz annotation: check for Z offset -> RangeError.
        $noAnnot = preg_replace('/\[.*?\]/', '', $str);
        if (preg_match('/[Zz]/', $noAnnot)) {
            throw new RangeError("date-time + Z throws without an IANA annotation");
        }
        return self::parsePlainDateString($str);
    }

    private static function toPlainTime(
        JsValue $item,
        string $overflow = 'constrain',
    ): JsObject {
        if ($item instanceof JsUndefined || $item instanceof JsNull) {
            throw new TypeError('Cannot convert undefined/null to PlainTime');
        }
        if ($item instanceof JsObject && $item->hasNativeSlot('[[IsPlainTime]]')) {
            // Return a copy, not the same object.
            return self::createPlainTimeObject(
                self::getSlotInt($item, '[[ISOHour]]'),
                self::getSlotInt($item, '[[ISOMinute]]'),
                self::getSlotInt($item, '[[ISOSecond]]'),
                self::getSlotInt($item, '[[ISOMillisecond]]'),
                self::getSlotInt($item, '[[ISOMicrosecond]]'),
                self::getSlotInt($item, '[[ISONanosecond]]'),
            );
        }
        if ($item instanceof JsObject && $item->hasNativeSlot('[[IsPlainDateTime]]')) {
            return self::createPlainTimeObject(
                self::getSlotInt($item, '[[ISOHour]]'),
                self::getSlotInt($item, '[[ISOMinute]]'),
                self::getSlotInt($item, '[[ISOSecond]]'),
                self::getSlotInt($item, '[[ISOMillisecond]]'),
                self::getSlotInt($item, '[[ISOMicrosecond]]'),
                self::getSlotInt($item, '[[ISONanosecond]]'),
            );
        }
        if ($item instanceof JsObject && $item->hasNativeSlot('[[IsZonedDateTime]]')) {
            $parts = self::zonedDateTimeParts($item);
            return self::createPlainTimeObject(
                $parts['hour'],
                $parts['minute'],
                $parts['second'],
                $parts['millisecond'],
                $parts['microsecond'],
                $parts['nanosecond'],
            );
        }
        if ($item instanceof JsObject) {
            // Property bag.
            $h = 0;
            $min = 0;
            $s = 0;
            $ms = 0;
            $us = 0;
            $ns = 0;
            $any = false;
            // Read in alphabetical order per spec.
            $tBag = [
                'hour' => &$h, 'microsecond' => &$us,
                'millisecond' => &$ms, 'minute' => &$min,
                'nanosecond' => &$ns, 'second' => &$s,
            ];
            foreach ($tBag as $name => &$ref) {
                $v = $item->get($name);
                if (!($v instanceof JsUndefined)) {
                    $n = TypeConversion::toNumber($v);
                    if (!is_finite($n)) {
                        throw new RangeError("{$name} must be finite");
                    }
                    $ref = (int) $n;
                    $any = true;
                }
            }
            unset($ref);
            if (!$any) {
                throw new TypeError(
                    'missing required time property'
                );
            }
            if ($overflow === 'constrain') {
                $h = max(0, min(23, $h));
                $min = max(0, min(59, $min));
                $s = max(0, min(59, $s));
                $ms = max(0, min(999, $ms));
                $us = max(0, min(999, $us));
                $ns = max(0, min(999, $ns));
            } else {
                self::validateISOTime($h, $min, $s, $ms, $us, $ns);
            }
            return self::createPlainTimeObject($h, $min, $s, $ms, $us, $ns);
        }
        if ($item instanceof JsNumber || $item instanceof \Phasis\Value\JsBigInt) {
            throw new TypeError('Cannot convert number to Temporal.PlainTime');
        }
        if ($item instanceof JsBoolean) {
            throw new TypeError('Cannot convert boolean to Temporal.PlainTime');
        }
        if ($item instanceof \Phasis\Value\JsSymbol) {
            throw new TypeError('Cannot convert Symbol to Temporal.PlainTime');
        }
        $str = TypeConversion::toString($item);
        return self::parsePlainTimeString($str);
    }

    /** Create PlainTime from property bag with correct fields-before-options ordering. */
    private static function toPlainTimeFromBag(JsObject $item, JsValue $rawOpts): JsObject
    {
        $h = 0;
        $min = 0;
        $s = 0;
        $ms = 0;
        $us = 0;
        $ns = 0;
        $any = false;
        $tBag = [
            'hour' => &$h, 'microsecond' => &$us,
            'millisecond' => &$ms, 'minute' => &$min,
            'nanosecond' => &$ns, 'second' => &$s,
        ];
        foreach ($tBag as $name => &$ref) {
            $v = $item->get($name);
            if (!($v instanceof JsUndefined)) {
                $n = TypeConversion::toNumber($v);
                if (!is_finite($n)) {
                    throw new RangeError("{$name} must be finite");
                }
                $ref = (int) $n;
                $any = true;
            }
        }
        unset($ref);
        if (!$any) {
            throw new TypeError('missing required time property');
        }
        // Read options AFTER fields per spec.
        $options = self::getOptionsObject($rawOpts);
        $overflow = self::getOverflow($options);
        if ($overflow === 'constrain') {
            $h = max(0, min(23, $h));
            $min = max(0, min(59, $min));
            $s = max(0, min(59, $s));
            $ms = max(0, min(999, $ms));
            $us = max(0, min(999, $us));
            $ns = max(0, min(999, $ns));
        } else {
            self::validateISOTime($h, $min, $s, $ms, $us, $ns);
        }
        return self::createPlainTimeObject($h, $min, $s, $ms, $us, $ns);
    }

    private static function parsePlainTimeString(string $str): JsObject
    {
        [$str] = self::normalizeTemporalString($str);
        // Reject UTC designator (Z) for PlainTime.
        $noAnnot = preg_replace('/\[.*?\]/', '', $str);
        if (preg_match('/[Zz]/', $noAnnot)) {
            throw new RangeError(
                "String with UTC designator should not be valid as PlainTime"
            );
        }
        // Reject -000000 (minus zero year).
        if (preg_match('/^-0{4,6}[-\d]/', $str)) {
            throw new RangeError("reject minus zero as extended year: {$str}");
        }
        // Reject space-prefixed strings (space is not a substitute for T).
        if (preg_match('/^ /', $str)) {
            throw new RangeError("space is not accepted as a substitute for T prefix: '{$str}'");
        }
        // Strip annotations for ambiguity checking.
        $noAnnot2 = preg_replace('/(?:\[.*?\])+$/', '', $str);
        $hasT = (bool) preg_match('/^[Tt]/', $noAnnot2);
        // Reject ambiguous strings (could be date-like) without T prefix.
        // Per spec: YYYY-MM, MMDD, MM-DD, YYYYMM are ambiguous with time.
        // Check on annotation-stripped string BEFORE offset stripping.
        if (!$hasT) {
            // YYYY-MM or YYYY-MM[ann]: ambiguous only if MM is valid month.
            if (preg_match('/^(\d{4})-(\d{2})(?:$|\[)/', $noAnnot2, $ambM)) {
                $ambMonth = (int) $ambM[2];
                if ($ambMonth >= 1 && $ambMonth <= 12) {
                    throw new RangeError("'{$str}' is ambiguous and requires T prefix");
                }
            }
            // MM-DD or MM-DD[ann]: ambiguous only if MM is valid month.
            if (preg_match('/^(\d{2})-(\d{2})(?:$|\[)/', $noAnnot2, $ambMD)) {
                $ambMM = (int) $ambMD[1];
                if ($ambMM >= 1 && $ambMM <= 12) {
                    throw new RangeError("'{$str}' is ambiguous and requires T prefix");
                }
            }
            // MMDD (4-digit): ambiguous if it could be a valid date.
            if (preg_match('/^(\d{4})(?:$|\[)/', $noAnnot2, $amb4)) {
                $mmCandidate = (int) substr($amb4[1], 0, 2);
                $ddCandidate = (int) substr($amb4[1], 2, 2);
                // Max days per month (including leap year for Feb).
                $maxDays = [0, 31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
                if (
                    $mmCandidate >= 1 && $mmCandidate <= 12
                    && $ddCandidate >= 1 && $ddCandidate <= $maxDays[$mmCandidate]
                ) {
                    throw new RangeError("'{$str}' is ambiguous and requires T prefix");
                }
            }
            // YYYYMM (6-digit): ambiguous if last 2 digits are valid month.
            if (preg_match('/^(\d{6})(?:$|\[)/', $noAnnot2, $amb6)) {
                $mmPart = (int) substr($amb6[1], 4, 2);
                if ($mmPart >= 1 && $mmPart <= 12) {
                    throw new RangeError("'{$str}' is ambiguous and requires T prefix");
                }
            }
        }
        // Strip annotations for time-only parsing.
        $cleanStr = $noAnnot2;
        // Strip offset.
        $cleanStr = preg_replace('/[+\-]\d{2}(?::?\d{2}(?::?\d{2}(?:[.,]\d+)?)?)?$/', '', $cleanStr);
        // Strip leading T/t designator.
        if ($hasT) {
            $cleanStr = substr($cleanStr, 1);
        }
        $pattern = '/^(\d{2})(?::?(\d{2})(?::?(\d{2})(?:[.,](\d{1,9}))?)?)?$/';
        if (!preg_match($pattern, $cleanStr, $m)) {
            // Also try datetime string and extract time.
            if ($hasT) {
                throw new RangeError("Invalid PlainTime string: {$str}");
            }
            $pattern2 = '/[Tt ](\d{2})(?::?(\d{2})(?::?(\d{2})(?:[.,](\d{1,9}))?)?)?/';
            if (!preg_match($pattern2, $cleanStr, $m)) {
                throw new RangeError("Invalid PlainTime string: {$str}");
            }
        }
        $h = (int) $m[1];
        $min = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
        $s = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0;
        // Handle leap second: clamp 60 to 59 per spec.
        if ($s === 60) {
            $s = 59;
        }
        $frac = isset($m[4]) ? str_pad(substr($m[4], 0, 9), 9, '0') : '000000000';
        $ms = (int) substr($frac, 0, 3);
        $us = (int) substr($frac, 3, 3);
        $ns = (int) substr($frac, 6, 3);
        self::validateISOTime($h, $min, $s, $ms, $us, $ns);
        return self::createPlainTimeObject($h, $min, $s, $ms, $us, $ns);
    }

    private static function toPlainDateTime(JsValue $item, string $overflow = 'constrain', ?JsValue $rawOptions = null): JsObject
    {
        if ($item instanceof JsObject && $item->hasNativeSlot('[[IsPlainDateTime]]')) {
            // Return a copy per spec.
            return self::createPlainDateTimeObject(
                self::getSlotInt($item, '[[ISOYear]]'),
                self::getSlotInt($item, '[[ISOMonth]]'),
                self::getSlotInt($item, '[[ISODay]]'),
                self::getSlotInt($item, '[[ISOHour]]'),
                self::getSlotInt($item, '[[ISOMinute]]'),
                self::getSlotInt($item, '[[ISOSecond]]'),
                self::getSlotInt($item, '[[ISOMillisecond]]'),
                self::getSlotInt($item, '[[ISOMicrosecond]]'),
                self::getSlotInt($item, '[[ISONanosecond]]'),
                self::getSlotString($item, '[[Calendar]]'),
            );
        }
        if ($item instanceof JsObject && $item->hasNativeSlot('[[IsPlainDate]]')) {
            return self::createPlainDateTimeObject(
                self::getSlotInt($item, '[[ISOYear]]'),
                self::getSlotInt($item, '[[ISOMonth]]'),
                self::getSlotInt($item, '[[ISODay]]'),
                0,
                0,
                0,
                0,
                0,
                0,
                self::getSlotString($item, '[[Calendar]]'),
            );
        }
        if ($item instanceof JsObject) {
            $calValue = $item->get('calendar');
            $cal = $calValue instanceof JsUndefined ? 'iso8601' : self::toCalendarSlotValue($calValue);
            $fields = self::prepareCalendarFields($item, $cal, [
                'day', 'hour', 'microsecond', 'millisecond', 'minute', 'month',
                'monthCode', 'nanosecond', 'second', 'year',
            ], ['day']);
            if ($rawOptions !== null) {
                $overflow = self::getOverflow(self::getOptionsObject($rawOptions));
            }
            $date = self::calendarDateFromFields($cal, $fields, $overflow);
            [$h, $min, $s, $ms, $us, $ns] = self::timeFromFields($fields, $overflow);
            return self::createPlainDateTimeObject(
                $date['year'],
                $date['month'],
                $date['day'],
                $h,
                $min,
                $s,
                $ms,
                $us,
                $ns,
                $cal,
            );
        }
        if ($item instanceof JsUndefined || $item instanceof JsNull) {
            throw new TypeError('Cannot convert undefined/null to PlainDateTime');
        }
        if ($item instanceof JsNumber || $item instanceof \Phasis\Value\JsBigInt) {
            throw new TypeError('Cannot convert number to Temporal.PlainDateTime');
        }
        if ($item instanceof JsBoolean) {
            throw new TypeError('Cannot convert boolean to Temporal.PlainDateTime');
        }
        if ($item instanceof \Phasis\Value\JsSymbol) {
            throw new TypeError('Cannot convert Symbol to Temporal.PlainDateTime');
        }
        $str = TypeConversion::toString($item);
        return self::parsePlainDateTimeString($str);
    }

    private static function parsePlainDateTimeString(string $str): JsObject
    {
        [$str, $calFromAnnotation] = self::normalizeTemporalString($str);
        self::validateTemporalDatePrefix($str);
        // Reject extended year without sign (5+ digits followed by dash).
        if (preg_match('/^\d{5,}-/', $str)) {
            throw new RangeError("Extended year requires sign: {$str}");
        }
        // Reject UTC designator (Z) for PlainDateTime.
        $noAnnot = preg_replace('/\[.*?\]/', '', $str);
        if (preg_match('/[Zz]/', $noAnnot)) {
            throw new RangeError(
                "String with UTC designator should not be valid as PlainDateTime"
            );
        }
        // Reject -000000 (minus zero year).
        if (preg_match('/^-0{4,6}[-\d]/', $str)) {
            throw new RangeError("reject minus zero as extended year: {$str}");
        }
        $datePart = '([+-]?\d{4,6})-?(\d{2})-?(\d{2})';
        $timePart = '(\d{2})(?::?(\d{2})(?::?(\d{2})(?:[.,](\d{1,9}))?)?)?';
        // UTC offset can include seconds and fractional seconds (extended format).
        $tzPart = '(?:[+-]\d{2}(?::?\d{2}(?::?\d{2}(?:[.,]\d{1,9})?)?)?)?';
        $pattern = "/^{$datePart}[Tt ]{$timePart}{$tzPart}(?:\\[.*?\\])*\$/";
        if (!preg_match($pattern, $str, $m)) {
            // Fallback: date only (with or without dashes).
            $dateOnly = '/^([+-]?\d{4,6})-?(\d{2})-?(\d{2})(?:\[.*?\])*$/';
            if (preg_match($dateOnly, $str, $m)) {
                $y = (int) $m[1];
                $m2 = (int) $m[2];
                $d = (int) $m[3];
                self::validateISODate($y, $m2, $d);
                if ($calFromAnnotation !== 'iso8601' && !self::isValidCalendar($calFromAnnotation)) {
                    throw new RangeError("Invalid calendar: {$calFromAnnotation}");
                }
                return self::createPlainDateTimeObject(
                    $y,
                    $m2,
                    $d,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                    $calFromAnnotation,
                );
            }
            throw new RangeError("Invalid PlainDateTime string: {$str}");
        }
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $dd = (int) $m[3];
        $h = (int) $m[4];
        $min = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : 0;
        $s = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0;
        // Leap second: clamp 60 to 59.
        if ($s === 60) {
            $s = 59;
        }
        $frac = isset($m[7]) ? str_pad(substr($m[7], 0, 9), 9, '0') : '000000000';
        $ms = (int) substr($frac, 0, 3);
        $us = (int) substr($frac, 3, 3);
        $ns = (int) substr($frac, 6, 3);
        self::validateISODate($y, $mo, $dd);
        self::validateISOTime($h, $min, $s, $ms, $us, $ns);
        if ($calFromAnnotation !== 'iso8601' && !self::isValidCalendar($calFromAnnotation)) {
            throw new RangeError("Invalid calendar: {$calFromAnnotation}");
        }
        return self::createPlainDateTimeObject(
            $y,
            $mo,
            $dd,
            $h,
            $min,
            $s,
            $ms,
            $us,
            $ns,
            $calFromAnnotation,
        );
    }

    private static function toPlainYearMonthWithLazyOptions(JsValue $item, JsValue $rawOptions): JsObject
    {
        if (!$item instanceof JsObject || $item->hasNativeSlot('[[IsPlainYearMonth]]')) {
            // Should not happen via from() since instances and primitives are handled before.
            $options = self::getOptionsObject($rawOptions);
            $overflow = self::getOverflow($options);
            return self::toPlainYearMonth($item, $overflow);
        }
        $calValue = $item->get('calendar');
        $cal = $calValue instanceof JsUndefined ? 'iso8601' : self::toCalendarSlotValue($calValue);
        $fields = self::prepareCalendarFields($item, $cal, ['month', 'monthCode', 'year']);
        $overflow = self::getOverflow(self::getOptionsObject($rawOptions));
        $date = self::calendarDateFromFields($cal, $fields, $overflow);
        return self::createPlainYearMonthObject($date['year'], $date['month'], $date['day'], $cal);
    }

    private static function toPlainYearMonth(JsValue $item, string $overflow = 'constrain'): JsObject
    {
        if ($item instanceof JsUndefined || $item instanceof JsNull) {
            throw new TypeError('Cannot convert undefined/null to PlainYearMonth');
        }
        if ($item instanceof JsObject && $item->hasNativeSlot('[[IsPlainYearMonth]]')) {
            return self::createPlainYearMonthObject(
                self::getSlotInt($item, '[[ISOYear]]'),
                self::getSlotInt($item, '[[ISOMonth]]'),
                self::getSlotInt($item, '[[ISODay]]'),
                self::getSlotString($item, '[[Calendar]]'),
            );
        }
        if ($item instanceof JsObject) {
            $calValue = $item->get('calendar');
            $cal = $calValue instanceof JsUndefined ? 'iso8601' : self::toCalendarSlotValue($calValue);
            $fields = self::prepareCalendarFields($item, $cal, ['month', 'monthCode', 'year']);
            $date = self::calendarDateFromFields($cal, $fields, $overflow);
            return self::createPlainYearMonthObject($date['year'], $date['month'], $date['day'], $cal);
        }
        // Reject primitives per spec.
        if ($item instanceof JsNumber || $item instanceof \Phasis\Value\JsBigInt) {
            throw new TypeError('Cannot convert number to Temporal.PlainYearMonth');
        }
        if ($item instanceof JsBoolean) {
            throw new TypeError('Cannot convert boolean to Temporal.PlainYearMonth');
        }
        if ($item instanceof \Phasis\Value\JsSymbol) {
            throw new TypeError('Cannot convert Symbol to Temporal.PlainYearMonth');
        }
        $str = TypeConversion::toString($item);
        [$str, $cal] = self::normalizeTemporalString($str);
        $noAnnot = preg_replace('/\[.*?\]/', '', $str);
        if (preg_match('/[Zz]/', $noAnnot)) {
            throw new RangeError("String with UTC designator should not be valid as PlainYearMonth");
        }
        if (preg_match('/^-0{4,6}/', $str)) {
            throw new RangeError("reject minus zero as extended year: {$str}");
        }
        if (preg_match('/^\d{5,}-/', $str)) {
            throw new RangeError("Extended year requires sign: {$str}");
        }
        $hasTime = (bool) preg_match('/[Tt ]/', $noAnnot);
        // Reject UTC offset without time: matches date-only strings ending
        // with an offset like "+00:00", "-02:30", or "+0000".
        if (!$hasTime && preg_match('/[+-]\d{2}(?::?\d{2})?$/', $noAnnot)) {
            // Distinguish date separators from offsets: YYYY-MM and YYYY-MM-DD
            // use only "-" between fields and have exactly YYYY-MM or YYYY-MM-DD form.
            // Extended year: +YYYYYY-MM or -YYYYYY-MM.
            // If the trailing [+-]NN:?NN pattern comes after at least 3 date fields,
            // it is a UTC offset. Strip a well-formed date prefix and check remainder.
            $extY = '(?:[+-]\\d{6}|\\d{4})';
            if (preg_match('/^' . $extY . '-?\\d{2}-?\\d{2}[+-]\\d{2}/', $noAnnot)) {
                throw new RangeError("UTC offset without time is not valid for PlainYearMonth");
            }
        }
        // Per spec, a non-ISO calendar annotation requires the
        // string to either include time-of-day OR be a complete
        // YYYY-MM-DD date — V8 accepts the date-only form. A bare
        // YYYY-MM on a non-ISO calendar still throws.
        if (!$hasTime && $cal !== 'iso8601') {
            $isDateOnlyComplete = (bool) preg_match(
                '/^(?:[+-]\d{6}|\d{4})-?\d{2}-?\d{2}/',
                $noAnnot,
            );
            if (!$isDateOnlyComplete) {
                throw new RangeError(
                    "non-iso8601 calendar annotation requires year/month/day"
                );
            }
        }
        // Time part with optional UTC offset in extended form (hh[:mm[:ss[.fff]]]).
        $offsetOpt = '(?:[+-]\\d{2}(?::?\\d{2}(?::?\\d{2}(?:[.,]\\d{1,9})?)?)?)?';
        $tp = "(?:[Tt ](\\d{2})(?::?(\\d{2})(?::?(\\d{2})(?:[.,]\\d{1,9})?)?)?{$offsetOpt})?";
        $patterns = [
            "/^([+-]\\d{6})-(\\d{2})(?:-(\\d{2}))?{$tp}(?:\\[.*?\\])*\$/",
            "/^([+-]\\d{6})(\\d{2})(\\d{2}){$tp}(?:\\[.*?\\])*\$/",
            "/^([+-]\\d{6})(\\d{2})(?:\\[.*?\\])*\$/",
            "/^(\\d{4})-(\\d{2})(?:-(\\d{2}))?{$tp}(?:\\[.*?\\])*\$/",
            "/^(\\d{4})(\\d{2})(\\d{2}){$tp}(?:\\[.*?\\])*\$/",
            "/^(\\d{4})(\\d{2})(?:\\[.*?\\])*\$/",
        ];
        $m = null;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $str, $candidate)) {
                $m = $candidate;
                break;
            }
        }
        if ($m === null) {
            throw new RangeError("Invalid PlainYearMonth string: {$str}");
        }
        $timeIdx = (isset($m[3]) && $m[3] !== '') ? 4 : 3;
        if (isset($m[$timeIdx]) && $m[$timeIdx] !== '') {
            $th = (int) $m[$timeIdx];
            $tmin = isset($m[$timeIdx + 1]) && $m[$timeIdx + 1] !== '' ? (int) $m[$timeIdx + 1] : 0;
            $ts = isset($m[$timeIdx + 2]) && $m[$timeIdx + 2] !== '' ? (int) $m[$timeIdx + 2] : 0;
            if ($ts === 60) {
                $ts = 59;
            }
            if ($th > 23 || $tmin > 59 || $ts > 59) {
                throw new RangeError("Invalid time: {$str}");
            }
        }
        $y = (int) $m[1];
        $mo = (int) $m[2];
        if ($mo < 1 || $mo > 12) {
            throw new RangeError("month {$mo} out of range");
        }
        if ($cal !== 'iso8601' && !self::isValidCalendar($cal)) {
            throw new RangeError("Invalid calendar: {$cal}");
        }
        // A year-month refers to the first day of its native calendar month.
        $refDay = 1;
        if ($cal !== 'iso8601' && isset($m[3]) && $m[3] !== '') {
            $fields = self::calendarFieldsForIso($cal, $y, $mo, (int) $m[3]);
            $fields['day'] = 1;
            $first = self::calendarDateFromFields($cal, $fields, 'constrain');
            [$y, $mo, $refDay] = [$first['year'], $first['month'], $first['day']];
        }
        return self::createPlainYearMonthObject($y, $mo, $refDay, $cal);
    }

    private static function toPlainMonthDay(JsValue $item, string $overflow = 'constrain', ?JsValue $rawOptions = null): JsObject
    {
        // Type validation: reject null, boolean, number, BigInt, symbol.
        if ($item instanceof JsUndefined || $item instanceof JsNull) {
            throw new TypeError('Cannot convert undefined or null to a Temporal.PlainMonthDay');
        }
        if ($item instanceof JsBoolean || $item instanceof JsNumber || $item instanceof JsBigInt) {
            throw new TypeError('Cannot convert primitive to a Temporal.PlainMonthDay');
        }

        if ($item instanceof JsObject && $item->hasNativeSlot('[[IsPlainMonthDay]]')) {
            // Copy the PlainMonthDay, do not return the same object.
            return self::createPlainMonthDayObject(
                self::getSlotInt($item, '[[ISOMonth]]'),
                self::getSlotInt($item, '[[ISODay]]'),
                self::getSlotInt($item, '[[ISOYear]]'),
                self::getSlotString($item, '[[Calendar]]'),
            );
        }
        if ($item instanceof JsObject) {
            $calValue = $item->get('calendar');
            $cal = $calValue instanceof JsUndefined ? 'iso8601' : self::toCalendarSlotValue($calValue);
            $fields = self::prepareCalendarFields($item, $cal, ['day', 'month', 'monthCode', 'year'], ['day']);
            if ($rawOptions !== null) {
                $overflow = self::getOverflow(self::getOptionsObject($rawOptions));
            }
            $date = self::calendarMonthDayFromFields($cal, $fields, $overflow);
            return self::createPlainMonthDayObject($date['month'], $date['day'], $date['year'], $cal);
        }
        $str = TypeConversion::toString($item);
        return self::parsePlainMonthDayString($str);
    }

    /**
     * Like toPlainMonthDay but reads overflow from options AFTER reading fields from the item.
     * This is necessary to satisfy observable property access order per the spec.
     */
    private static function toPlainMonthDayWithLazyOptions(JsValue $item, JsValue $options): JsObject
    {
        return self::toPlainMonthDay($item, 'constrain', $options);
    }

    private static function parsePlainMonthDayString(string $str): JsObject
    {
        [$str, $calFromAnnotation] = self::normalizeTemporalString($str);
        if (!self::isValidCalendar($calFromAnnotation)) {
            throw new RangeError("Invalid calendar: {$calFromAnnotation}");
        }

        // Reject UTC designator (Z) for PlainMonthDay.
        // Check after date/time portion, not inside annotations.
        $noAnnotation = preg_replace('/\[.*?\]/', '', $str);
        if (preg_match('/[Zz]/', $noAnnotation)) {
            throw new RangeError("String with UTC designator should not be valid as a PlainMonthDay: {$str}");
        }

        // Collect all bracket annotations.
        preg_match_all('/\[(!?)([^\]]+)\]/', $str, $annotations, PREG_SET_ORDER);

        // Reject multiple calendar annotations if any is critical.
        $calAnnotations = [];
        $tzAnnotations = [];
        foreach ($annotations as $ann) {
            $critical = $ann[1] === '!';
            $content = $ann[2];
            if (str_starts_with($content, 'u-ca=')) {
                $calAnnotations[] = ['critical' => $critical, 'value' => substr($content, 5)];
            } elseif (str_contains($content, '=')) {
                // Key-value annotation. Keys must be lowercase.
                $eqPos = strpos($content, '=');
                $key = substr($content, 0, $eqPos);
                if ($key !== strtolower($key)) {
                    throw new RangeError("annotation keys must be lowercase: {$str}");
                }
                // Unknown key-value annotation. If critical, reject.
                if ($critical) {
                    throw new RangeError("reject unknown annotation with critical flag: {$str}");
                }
            } else {
                // Timezone annotation.
                if ($critical && !self::isValidTimeZoneAnnotation($content)) {
                    throw new RangeError("reject unknown annotation with critical flag: {$str}");
                }
                $tzAnnotations[] = $content;
            }
        }

        if (count($calAnnotations) > 1) {
            foreach ($calAnnotations as $ca) {
                if ($ca['critical']) {
                    throw new RangeError("reject more than one calendar annotation if any critical: {$str}");
                }
            }
        }

        // Reject multiple time zone annotations.
        if (count($tzAnnotations) > 1) {
            throw new RangeError("reject more than one time zone annotation: {$str}");
        }

        // Reject -000000 (minus zero year).
        if (preg_match('/^-0{4,6}-/', $str)) {
            throw new RangeError("reject minus zero as extended year: {$str}");
        }

        // Remove annotations for structural matching (already collected above).
        $cleanStr = preg_replace('/\[.*?\]/', '', $str);

        // MM-DD, --MM-DD, or MMDD format (with optional annotations).
        $pattern = '/^(?:--)?(\d{2})-?(\d{2})$/';
        if (preg_match($pattern, $cleanStr, $m)) {
            $mo = (int) $m[1];
            $dd = (int) $m[2];
            if ($mo < 1 || $mo > 12 || $dd < 1 || $dd > self::isoDaysInMonth(1972, $mo)) {
                throw new RangeError("Invalid PlainMonthDay: {$str}");
            }
            $cal = 'iso8601';
            if (!empty($calAnnotations)) {
                $cal = strtolower($calAnnotations[0]['value']);
                // For MM-DD format, only iso8601 calendar is valid.
                if ($cal !== 'iso8601') {
                    throw new RangeError("non-iso8601 calendar not valid with month-day format: {$str}");
                }
            }
            return self::createPlainMonthDayObject($mo, $dd, 1972, $cal);
        }

        // Check for UTC offset without time in MM-DD or --MM-DD format.
        if (preg_match('/^(?:--)?(\d{2})-(\d{2})[Zz+\-]/', $cleanStr)) {
            throw new RangeError("UTC offset without time is not valid for PlainMonthDay: {$str}");
        }

        self::validateTemporalDatePrefix($cleanStr);
        // Full ISO date with optional time and offset.
        // First check for date-only with offset (no time): reject.
        // Use greedy year match (\d{4,6}) to avoid backtracking into date.
        if (
            preg_match('/^([+-]?\d{4,6})-(\d{2})-(\d{2})[Zz+\-]/', $cleanStr)
            && !preg_match('/^([+-]?\d{4,6})-(\d{2})-(\d{2})[Tt ]/', $cleanStr)
        ) {
            throw new RangeError("UTC offset without time is not valid for PlainMonthDay: {$str}");
        }
        // Same for compact format YYYYMMDD.
        if (
            preg_match('/^([+-]?\d{4,6})(\d{2})(\d{2})[Zz+\-]/', $cleanStr)
            && !preg_match('/^([+-]?\d{4,6})(\d{2})(\d{2})[Tt ]/', $cleanStr)
        ) {
            throw new RangeError("UTC offset without time is not valid for PlainMonthDay: {$str}");
        }

        // Full ISO date with optional time (captured for validation).
        // UTC offset may include seconds and fractional seconds (extended format).
        $offsetOpt = '(?:[+-]\d{2}(?::?\d{2}(?::?\d{2}(?:[.,]\d{1,9})?)?)?)?';
        $timeOpt = "(?:[Tt ](\d{2})(?::?(\d{2})(?::?(\d{2})(?:[.,]\d{1,9})?)?)?{$offsetOpt})?";
        $pattern2 = "/^([+-]?\\d{4,6})-?(\\d{2})-?(\\d{2}){$timeOpt}\$/";
        if (preg_match($pattern2, $cleanStr, $m)) {
            // Validate time if present.
            if (isset($m[4]) && $m[4] !== '') {
                $th = (int) $m[4];
                $tmin = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : 0;
                $ts = isset($m[6]) ? (int) $m[6] : 0;
                if ($ts === 60) {
                    $ts = 59;
                }
                if ($th > 23 || $tmin > 59 || $ts > 59) {
                    throw new RangeError("Invalid time in string: {$str}");
                }
            }
            $mo = (int) $m[2];
            $dd = (int) $m[3];
            // Extract calendar annotation if present.
            $cal = 'iso8601';
            if (!empty($calAnnotations)) {
                $cal = strtolower($calAnnotations[0]['value']);
            }
            // Canonicalize CLDR aliases on the parsed calendar id
            // so islamicc resolves to islamic-civil etc.
            static $calAliasPmd = [
                'islamicc' => 'islamic-civil',
                'ethiopic-amete-alem' => 'ethioaa',
                'gregorian' => 'gregory',
            ];
            if (isset($calAliasPmd[$cal])) {
                $cal = $calAliasPmd[$cal];
            }
            // Validate the date.
            $y = (int) $m[1];
            if ($mo < 1 || $mo > 12) {
                throw new RangeError("Invalid PlainMonthDay: {$str}");
            }
            $dim = self::isoDaysInMonth($y, $mo);
            if ($dd < 1 || $dd > $dim) {
                throw new RangeError("Invalid PlainMonthDay: {$str}");
            }
            // Non-ISO calendars need a representable year for the
            // reference date; clamp to the ISO range and reject
            // anything beyond.
            if ($cal !== 'iso8601') {
                if ($y < self::ISO_YEAR_MIN || $y > self::ISO_YEAR_MAX) {
                    throw new RangeError(
                        "Year {$y} out of range for non-ISO calendar in PlainMonthDay: {$str}",
                    );
                }
                // Normalise to the spec's reference ISO year (≤ 1972) so the
                // PlainMonthDay round-trips through equals/since/until without
                // pinning a specific historical year.
                if (!in_array($cal, ['gregory', 'roc', 'japanese'], true)) {
                    $back = self::isoToCalendarParts($cal, $y, $mo, $dd);
                    if ($back !== null) {
                        $iso = self::pmdReferenceIsoFor(
                            $cal,
                            $back['monthCode'],
                            null,
                            $back['day'],
                        );
                        if ($iso !== null) {
                            return self::createPlainMonthDayObject($iso['month'], $iso['day'], $iso['year'], $cal);
                        }
                    }
                }
            }
            $refYear = $cal === 'iso8601' ? 1972 : $y;
            return self::createPlainMonthDayObject($mo, $dd, $refYear, $cal);
        }
        throw new RangeError("Invalid PlainMonthDay string: {$str}");
    }

    private static function isValidTimeZoneAnnotation(string $content): bool
    {
        // Annotations with = are key-value pairs (calendar, etc.), not timezone.
        if (str_contains($content, '=')) {
            return false;
        }
        // Valid timezone annotations are IANA names (e.g., "UTC", "America/New_York")
        // or numeric offsets (e.g., "+05:30", "-02:30").
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_+\-\/]*$/', $content)) {
            return true;
        }
        if (preg_match('/^[+-]\d{2}:?\d{2}$/', $content)) {
            return true;
        }
        return false;
    }
}
