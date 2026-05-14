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
                $item->has('[[ISOYear]]') && !$item->has('[[IsPlainTime]]') && !$item->has('[[IsPlainDateTime]]')
                && !$item->has('[[IsPlainYearMonth]]') && !$item->has('[[IsPlainMonthDay]]')
                && !$item->has('[[IsZonedDateTime]]') && !$item->has('[[IsDuration]]') && !$item->has('[[EpochNanoseconds]]')
            ) {
                return self::createPlainDateObject(
                    self::getSlotInt($item, '[[ISOYear]]'),
                    self::getSlotInt($item, '[[ISOMonth]]'),
                    self::getSlotInt($item, '[[ISODay]]'),
                    self::getSlotString($item, '[[Calendar]]'),
                );
            }
            if ($item->has('[[IsPlainDateTime]]')) {
                return self::createPlainDateObject(
                    self::getSlotInt($item, '[[ISOYear]]'),
                    self::getSlotInt($item, '[[ISOMonth]]'),
                    self::getSlotInt($item, '[[ISODay]]'),
                    self::getSlotString($item, '[[Calendar]]'),
                );
            }
            if ($item->has('[[IsZonedDateTime]]')) {
                $parts = self::zonedDateTimeParts($item);
                return self::createPlainDateObject(
                    $parts['year'],
                    $parts['month'],
                    $parts['day'],
                    self::getSlotString($item, '[[Calendar]]'),
                );
            }
            // Property bag: read and convert fields in ALPHABETICAL order per spec.
            // Each field is get + valueOf/toString immediately.
            $calVal = $item->get('calendar');
            $cal = 'iso8601';
            if (!($calVal instanceof JsUndefined)) {
                $cal = self::toCalendarSlotValue($calVal);
            }
            $dayVal = $item->get('day');
            if ($dayVal instanceof JsUndefined) {
                throw new TypeError('missing required property: day');
            }
            $dNum = TypeConversion::toNumber($dayVal);
            // era/eraYear (non-ISO calendars only).
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
                // For calendars that use eras (gregory, japanese,
                // roc, etc.), the two fields must be both present
                // or both absent.
                static $erasUseEras = ['gregory', 'japanese', 'roc'];
                if (in_array($cal, $erasUseEras, true) && $eraSet !== $eraYearSet) {
                    throw new TypeError(
                        'era and eraYear must be provided together',
                    );
                }
            }
            $monthVal = $item->get('month');
            $hasMonth = !($monthVal instanceof JsUndefined);
            $mNum = $hasMonth ? TypeConversion::toNumber($monthVal) : null;
            $monthCodeVal = $item->get('monthCode');
            $hasMonthCode = !($monthCodeVal instanceof JsUndefined);
            $mcStr = $hasMonthCode ? TypeConversion::toString($monthCodeVal) : null;
            if ($hasMonthCode) {
                self::parseMonthCodeSyntax($mcStr);
            }
            $yearVal = $item->get('year');
            if ($yearVal instanceof JsUndefined) {
                static $pdEraCals = [
                    'gregory', 'japanese', 'roc',
                    'coptic', 'ethiopic', 'ethioaa',
                ];
                if (
                    $eraYearNum !== null
                    && in_array($cal, $pdEraCals, true)
                ) {
                    $eraLower = $eraStr === null ? '' : strtolower($eraStr);
                    if ($cal === 'japanese') {
                        $isoYear = self::japaneseEraToIsoYear($eraLower, (int) $eraYearNum);
                        if ($isoYear === null) {
                            // Unrecognized era: treat as gregory-style.
                            $yNum = in_array($eraLower, ['bc', 'bce', 'japanese-inverse'], true)
                                ? (1 - $eraYearNum)
                                : $eraYearNum;
                        } else {
                            $yNum = (float) $isoYear;
                        }
                    } elseif ($cal === 'roc') {
                        // ROC year fields are calendar-relative (year 1 = ISO
                        // 1912). For era="roc", the calendar year is the era
                        // year directly. For "roc-inverse"/"before-roc",
                        // calendar year 0 = ISO 1911 = before-roc year 1.
                        if ($eraLower === 'roc-inverse' || $eraLower === 'before-roc') {
                            $yNum = 1 - $eraYearNum;
                        } else {
                            $yNum = $eraYearNum;
                        }
                    } elseif ($cal === 'gregory') {
                        $yNum = in_array($eraLower, ['bc', 'bce', 'gregory-inverse'], true)
                            ? (1 - $eraYearNum)
                            : $eraYearNum;
                    } else {
                        // Other calendars (coptic, ethiopic, islamic, etc.)
                        // pass eraYear through as the calendar year, with
                        // -inverse / -bc style flipping the sign.
                        $yNum = (
                            $eraLower !== ''
                            && (str_ends_with($eraLower, '-inverse')
                                || in_array($eraLower, ['bc', 'bce', 'before-roc'], true))
                        )
                            ? (1 - $eraYearNum)
                            : $eraYearNum;
                    }
                } else {
                    throw new TypeError('missing required property: year');
                }
            } else {
                $yNum = TypeConversion::toNumber($yearVal);
            }
            // Now validate and resolve fields.
            if (!$hasMonth && !$hasMonthCode) {
                throw new TypeError('missing required property: month');
            }
            if (!is_finite($yNum)) {
                throw new RangeError('year must be finite');
            }
            if ($yNum === 0.0 && (unpack("H*", pack("d", $yNum))[1] ?? "") === "0000000000000080") {
                throw new RangeError('reject minus zero as extended year');
            }
            $y = (int) $yNum;
            if ($hasMonthCode) {
                $mcMonth = self::parseMonthCode($mcStr, $cal);
                if ($hasMonth) {
                    if (!is_finite($mNum)) {
                        throw new RangeError('month must be finite');
                    }
                    // For lunisolar calendars, the digit in the monthCode
                    // and the chronological month diverge once a leap
                    // month sits earlier in the year. Resolve the
                    // monthCode against the year and compare against the
                    // user's chronological month.
                    if (in_array($cal, ['hebrew', 'chinese', 'dangi'], true)) {
                        $resolved = self::calendarPartsToIso($cal, $y, $mcStr, null, 1);
                        if ($resolved !== null) {
                            $back = self::isoToCalendarParts(
                                $cal,
                                $resolved['year'],
                                $resolved['month'],
                                $resolved['day'],
                            );
                            if (
                                $back !== null
                                && $back['monthCode'] === $mcStr
                                && (int) $mNum !== $back['month']
                            ) {
                                throw new RangeError('month and monthCode must agree');
                            }
                        }
                    } elseif ((int) $mNum !== $mcMonth) {
                        throw new RangeError('month and monthCode must agree');
                    }
                }
                $m = $mcMonth;
            } else {
                if (!is_finite($mNum)) {
                    throw new RangeError('month must be finite');
                }
                $m = (int) $mNum;
            }
            if (!is_finite($dNum)) {
                throw new RangeError('day must be finite');
            }
            $d = (int) $dNum;
            if ($rawOptions !== null) {
                $options = self::getOptionsObject($rawOptions);
                $overflow = self::getOverflow($options);
            }
            // For non-ISO calendars, convert calendar-native (year, month, day)
            // to ISO via ICU before storing.
            if ($cal !== 'iso8601' && !in_array($cal, ['gregory', 'roc', 'japanese'], true)) {
                // Constrain day to the calendar month's max so ICU doesn't
                // silently roll over (e.g. hebrew Cheshvan 30 in a 29-day
                // year would otherwise become Kislev 1).
                if ($overflow === 'constrain') {
                    $maxD = self::calendarDaysInMonth(
                        $cal,
                        $y,
                        $hasMonthCode ? $mcStr : null,
                        $hasMonthCode ? null : $m,
                    );
                    if ($maxD !== null && $d > $maxD) {
                        $d = $maxD;
                    }
                } else {
                    $maxD = self::calendarDaysInMonth(
                        $cal,
                        $y,
                        $hasMonthCode ? $mcStr : null,
                        $hasMonthCode ? null : $m,
                    );
                    if ($maxD !== null && $d > $maxD) {
                        throw new RangeError(
                            "Invalid day {$d} for calendar '{$cal}' month",
                        );
                    }
                }
                $isoParts = self::calendarPartsToIso($cal, $y, $hasMonthCode ? $mcStr : null, $hasMonthCode ? null : $m, $d);
                if ($isoParts !== null) {
                    return self::createPlainDateObject($isoParts['year'], $isoParts['month'], $isoParts['day'], $cal);
                }
                // calendarPartsToIso couldn't form a valid date in this
                // calendar (e.g. invalid leap monthCode like M01L for
                // hebrew). Reject under "reject", and also reject for
                // "constrain" since silently falling back to ISO would
                // produce a date that doesn't even exist in the requested
                // calendar.
                if ($overflow === 'reject' || $hasMonthCode) {
                    throw new RangeError(
                        "Invalid date components for calendar '{$cal}'",
                    );
                }
            }
            // Translate roc calendar-year to ISO year. The calendarPartsToIso
            // path handles non-iso/non-gregory calendars; gregory and
            // japanese map year directly to ISO. ROC is the exception:
            // year fields are 1-based from 1912 ("民國" year 1).
            if ($cal === 'roc') {
                $y += 1911;
            }
            if ($overflow === 'constrain') {
                [$y, $m, $d] = self::constrainISODate($y, $m, $d);
            } else {
                self::validateISODate($y, $m, $d);
            }
            return self::createPlainDateObject($y, $m, $d, $cal);
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
                $item->has('[[IsPlainDate]]') || $item->has('[[IsPlainDateTime]]')
                || $item->has('[[IsZonedDateTime]]') || $item->has('[[ISOYear]]')
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
                static $relErasUseEras = ['gregory', 'japanese', 'roc'];
                if (in_array($cal, $relErasUseEras, true) && $eraSet !== $eraYearSet) {
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
                    if (!preg_match('/^[+-]\d{2}(?::?\d{2}(?::?\d{2}(?:\.\d{1,9})?)?)?$/', $offStr)) {
                        throw new RangeError("{$offStr} is not a valid offset string");
                    }
                }
            }
            // Required: year (or era+eraYear), day (and month or monthCode).
            if ($yearVal instanceof JsUndefined) {
                static $relEraDeriv = ['gregory', 'japanese', 'roc'];
                if ($eraYearNum !== null && in_array($cal, $relEraDeriv, true)) {
                    $eraLower = $eraStr === null ? '' : strtolower($eraStr);
                    $yNum = in_array($eraLower, ['bc', 'bce'], true)
                        ? (1 - $eraYearNum)
                        : $eraYearNum;
                } else {
                    throw new TypeError('missing required property: year');
                }
            }
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
        if ($item instanceof JsObject && $item->has('[[IsPlainTime]]')) {
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
        if ($item instanceof JsObject && $item->has('[[IsPlainDateTime]]')) {
            return self::createPlainTimeObject(
                self::getSlotInt($item, '[[ISOHour]]'),
                self::getSlotInt($item, '[[ISOMinute]]'),
                self::getSlotInt($item, '[[ISOSecond]]'),
                self::getSlotInt($item, '[[ISOMillisecond]]'),
                self::getSlotInt($item, '[[ISOMicrosecond]]'),
                self::getSlotInt($item, '[[ISONanosecond]]'),
            );
        }
        if ($item instanceof JsObject && $item->has('[[IsZonedDateTime]]')) {
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
        if ($item instanceof JsObject && $item->has('[[IsPlainDateTime]]')) {
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
        if ($item instanceof JsObject && $item->has('[[IsPlainDate]]')) {
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
            // Property bag: read ALL fields in ALPHABETICAL order per spec.
            // Each field is immediately coerced via valueOf/toString.
            $calVal = $item->get('calendar');
            $cal = 'iso8601';
            if (!($calVal instanceof JsUndefined)) {
                $cal = self::toCalendarSlotValue($calVal);
            }
            $dayVal = $item->get('day');
            if ($dayVal instanceof JsUndefined) {
                throw new TypeError('missing required property: day');
            }
            $dNum = TypeConversion::toNumber($dayVal);
            if (!is_finite($dNum)) {
                throw new RangeError('day must be finite');
            }
            $d = (int) $dNum;
            // Read era and eraYear (per spec alphabetical ordering)
            // only for non-ISO calendars; ISO doesn't have eras and
            // reading these properties on an ISO property bag would
            // disturb the canonical observable read order.
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
                static $pdtErasUseEras = ['gregory', 'japanese', 'roc'];
                if (in_array($cal, $pdtErasUseEras, true) && $eraSet !== $eraYearSet) {
                    throw new TypeError(
                        'era and eraYear must be provided together',
                    );
                }
            }
            // Read time fields (for PlainDateTime path).
            $h = 0;
            $min = 0;
            $s = 0;
            $ms = 0;
            $us = 0;
            $ns = 0;
            $hVal = $item->get('hour');
            if (!($hVal instanceof JsUndefined)) {
                $hNum = TypeConversion::toNumber($hVal);
                if (!is_finite($hNum)) {
                    throw new RangeError('hour must be finite');
                }
                $h = (int) $hNum;
            }
            $usVal = $item->get('microsecond');
            if (!($usVal instanceof JsUndefined)) {
                $usNum = TypeConversion::toNumber($usVal);
                if (!is_finite($usNum)) {
                    throw new RangeError('microsecond must be finite');
                }
                $us = (int) $usNum;
            }
            $msVal = $item->get('millisecond');
            if (!($msVal instanceof JsUndefined)) {
                $msNum = TypeConversion::toNumber($msVal);
                if (!is_finite($msNum)) {
                    throw new RangeError('millisecond must be finite');
                }
                $ms = (int) $msNum;
            }
            $minVal = $item->get('minute');
            if (!($minVal instanceof JsUndefined)) {
                $minNum = TypeConversion::toNumber($minVal);
                if (!is_finite($minNum)) {
                    throw new RangeError('minute must be finite');
                }
                $min = (int) $minNum;
            }
            $monthVal = $item->get('month');
            $monthExplicit = !($monthVal instanceof JsUndefined);
            $m = 0;
            if ($monthExplicit) {
                $mNum = TypeConversion::toNumber($monthVal);
                if (!is_finite($mNum)) {
                    throw new RangeError('month must be finite');
                }
                $m = (int) $mNum;
            }
            $monthCodeVal = $item->get('monthCode');
            $mcStr = null;
            if (!($monthCodeVal instanceof JsUndefined)) {
                $mcStr = TypeConversion::toString($monthCodeVal);
                self::parseMonthCodeSyntax($mcStr);
            }
            $nsVal = $item->get('nanosecond');
            if (!($nsVal instanceof JsUndefined)) {
                $nsNum = TypeConversion::toNumber($nsVal);
                if (!is_finite($nsNum)) {
                    throw new RangeError('nanosecond must be finite');
                }
                $ns = (int) $nsNum;
            }
            $sVal = $item->get('second');
            if (!($sVal instanceof JsUndefined)) {
                $sNum = TypeConversion::toNumber($sVal);
                if (!is_finite($sNum)) {
                    throw new RangeError('second must be finite');
                }
                $s = (int) $sNum;
            }
            $yearVal = $item->get('year');
            if ($yearVal instanceof JsUndefined) {
                // For era-using calendars, era+eraYear can
                // substitute for year (Gregorian: ce/ad → year=eraYear,
                // bc/bce → year = 1 - eraYear). For calendars that
                // don't use eras (hebrew/islamic/chinese), an absent
                // year is always a TypeError.
                static $pdtEraDerivCals = ['gregory', 'japanese', 'roc'];
                if (
                    $eraYearNum !== null
                    && in_array($cal, $pdtEraDerivCals, true)
                ) {
                    $eraLower = $eraStr === null ? '' : strtolower($eraStr);
                    if (in_array($eraLower, ['bc', 'bce'], true)) {
                        $y = 1 - (int) $eraYearNum;
                    } else {
                        $y = (int) $eraYearNum;
                    }
                } else {
                    throw new TypeError('missing required property: year');
                }
            } else {
                $yNum = TypeConversion::toNumber($yearVal);
                if (!is_finite($yNum)) {
                    throw new RangeError('year must be finite');
                }
                $y = (int) $yNum;
            }
            // Now validate monthCode suitability (after year type check).
            if ($mcStr !== null) {
                $validatedMonth = self::parseMonthCode($mcStr);
                if ($monthExplicit && $m !== $validatedMonth) {
                    throw new RangeError('month and monthCode must agree');
                }
                $m = $validatedMonth;
            } elseif (!$monthExplicit) {
                throw new TypeError('missing required property: month');
            }
            if ($rawOptions !== null) {
                $options = self::getOptionsObject($rawOptions);
                $overflow = self::getOverflow($options);
            }
            // For non-ISO calendars (excluding gregory/roc which use ISO
            // year/month/day directly), convert calendar-native fields to ISO
            // via ICU before storing.
            if ($cal !== 'iso8601' && !in_array($cal, ['gregory', 'roc', 'japanese'], true)) {
                $isoParts = self::calendarPartsToIso($cal, $y, $mcStr, $monthExplicit ? $m : null, $d);
                if ($isoParts !== null) {
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
                    return self::createPlainDateTimeObject(
                        $isoParts['year'],
                        $isoParts['month'],
                        $isoParts['day'],
                        $h,
                        $min,
                        $s,
                        $ms,
                        $us,
                        $ns,
                        $cal,
                    );
                }
            }
            if ($overflow === 'constrain') {
                [$y, $m, $d] = self::constrainISODate($y, $m, $d);
                $h = max(0, min(23, $h));
                $min = max(0, min(59, $min));
                $s = max(0, min(59, $s));
                $ms = max(0, min(999, $ms));
                $us = max(0, min(999, $us));
                $ns = max(0, min(999, $ns));
            } else {
                self::validateISODate($y, $m, $d);
                self::validateISOTime($h, $min, $s, $ms, $us, $ns);
            }
            return self::createPlainDateTimeObject($y, $m, $d, $h, $min, $s, $ms, $us, $ns, $cal);
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
        if (!$item instanceof JsObject || $item->has('[[IsPlainYearMonth]]')) {
            // Should not happen via from() since instances and primitives are handled before.
            $options = self::getOptionsObject($rawOptions);
            $overflow = self::getOverflow($options);
            return self::toPlainYearMonth($item, $overflow);
        }
        $cal = 'iso8601';
        $calVal = $item->get('calendar');
        if (!($calVal instanceof JsUndefined)) {
            $cal = self::toCalendarSlotValue($calVal);
        }
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
            static $pymLazyEraCals = ['gregory', 'japanese', 'roc'];
            if (in_array($cal, $pymLazyEraCals, true) && $eraSet !== $eraYearSet) {
                throw new TypeError('era and eraYear must be provided together');
            }
        }
        $month = $item->get('month');
        $mVal = null;
        if (!($month instanceof JsUndefined)) {
            $mNum = TypeConversion::toNumber($month);
            if (!is_finite($mNum)) {
                throw new RangeError('month must be finite');
            }
            $mVal = (int) $mNum;
        }
        $monthCode = $item->get('monthCode');
        $mcStr = null;
        $mcParsed = null;
        if (!($monthCode instanceof JsUndefined)) {
            $mcStr = TypeConversion::toString($monthCode);
            $mcParsed = self::parseMonthCodeSyntax($mcStr);
        }
        $year = $item->get('year');
        if ($year instanceof JsUndefined) {
            static $pymLazyDeriv = ['gregory', 'japanese', 'roc'];
            if ($eraYearNum !== null && in_array($cal, $pymLazyDeriv, true)) {
                $eraLower = $eraStr === null ? '' : strtolower($eraStr);
                if ($cal === 'japanese') {
                    $isoYear = self::japaneseEraToIsoYear($eraLower, (int) $eraYearNum);
                    $yNum = $isoYear !== null
                        ? (float) $isoYear
                        : (in_array($eraLower, ['japanese-inverse'], true) ? (1 - $eraYearNum) : $eraYearNum);
                } elseif ($cal === 'roc') {
                    $yNum = in_array($eraLower, ['roc-inverse', 'before-roc'], true)
                        ? (1912 - $eraYearNum)
                        : (1911 + $eraYearNum);
                } else {
                    $yNum = in_array($eraLower, ['bc', 'bce', 'gregory-inverse'], true)
                        ? (1 - $eraYearNum)
                        : $eraYearNum;
                }
            } else {
                throw new TypeError('missing required property: year');
            }
        } else {
            $yNum = TypeConversion::toNumber($year);
        }
        if (!is_finite($yNum)) {
            throw new RangeError('year must be finite');
        }
        $y = (int) $yNum;
        if ($mVal === null && $mcStr === null) {
            throw new TypeError('missing required property: month or monthCode');
        }
        // NOW read overflow from options (after all field reads).
        $options = self::getOptionsObject($rawOptions);
        $overflow = self::getOverflow($options);
        if ($mcParsed !== null) {
            [$mcMonth, $mcLeap] = $mcParsed;
            static $pymLazyLunisolar = ['hebrew', 'chinese', 'dangi'];
            if ($mcMonth < 1 || $mcMonth > 12) {
                throw new RangeError("monthCode '{$mcStr}' is not valid for ISO 8601 calendar");
            }
            if ($mcLeap && !in_array($cal, $pymLazyLunisolar, true)) {
                throw new RangeError("monthCode '{$mcStr}' leap-month suffix is not valid for calendar '{$cal}'");
            }
            // Hebrew M05L only exists in leap years.
            if ($mcLeap && $cal === 'hebrew' && !self::isHebrewLeapYear($y)) {
                if ($overflow === 'reject') {
                    throw new RangeError("monthCode '{$mcStr}' is not valid in Hebrew non-leap year {$y}");
                }
                // Constrain leaks down to Adar (M06).
                $mcMonth = 6;
                $mcLeap = false;
                $mcStr = 'M06';
            }
            $m = $mcMonth;
            if ($mVal !== null && $mVal !== $m) {
                throw new RangeError('month and monthCode disagree');
            }
        } else {
            $m = $mVal;
        }
        if ($m < 1) {
            throw new RangeError("month {$m} out of range");
        }
        // Hebrew leap years allow month=13.
        $maxMonth = ($cal === 'hebrew' && self::isHebrewLeapYear($y)) ? 13 : 12;
        if ($overflow === 'constrain') {
            $m = min($maxMonth, $m);
        } elseif ($m > $maxMonth) {
            throw new RangeError("month {$m} out of range");
        }
        // Non-ISO non-gregory calendars: convert calendar-native (year, month, day=1)
        // to ISO via ICU and store the resulting ISO date.
        if ($cal !== 'iso8601' && !in_array($cal, ['gregory', 'roc', 'japanese'], true)) {
            $isoParts = self::calendarPartsToIso($cal, $y, $mcStr, $mcStr === null ? $m : null, 1);
            // Chinese leap-month requested in a year that doesn't have it:
            // calendarPartsToIso returns null. Constrain to the next month
            // (M01L → M02, M04L → M05, etc.); reject throws RangeError.
            if ($isoParts === null && $mcStr !== null && in_array($cal, ['chinese', 'dangi'], true) && preg_match('/^M(\d{2})L$/', $mcStr, $mm)) {
                if ($overflow === 'reject') {
                    throw new RangeError("Chinese leap month {$mcStr} does not exist in year {$y}");
                }
                $base = (int) $mm[1];
                // Prefer the next non-leap month (M(NN+1)); if NN is 12, fall
                // back to the same-numbered non-leap month (M12).
                foreach ([$base + 1, $base] as $altNum) {
                    if ($altNum < 1 || $altNum > 12) {
                        continue;
                    }
                    $altMc = 'M' . str_pad((string) $altNum, 2, '0', STR_PAD_LEFT);
                    $candidate = self::calendarPartsToIso($cal, $y, $altMc, null, 1);
                    if ($candidate !== null) {
                        $isoParts = $candidate;
                        break;
                    }
                }
            }
            if ($isoParts !== null) {
                return self::createPlainYearMonthObject($isoParts['year'], $isoParts['month'], $isoParts['day'], $cal);
            }
        }
        return self::createPlainYearMonthObject($y, $m, 1, $cal);
    }

    private static function toPlainYearMonth(JsValue $item, string $overflow = 'constrain'): JsObject
    {
        if ($item instanceof JsUndefined || $item instanceof JsNull) {
            throw new TypeError('Cannot convert undefined/null to PlainYearMonth');
        }
        if ($item instanceof JsObject && $item->has('[[IsPlainYearMonth]]')) {
            return self::createPlainYearMonthObject(
                self::getSlotInt($item, '[[ISOYear]]'),
                self::getSlotInt($item, '[[ISOMonth]]'),
                self::getSlotInt($item, '[[ISODay]]'),
                self::getSlotString($item, '[[Calendar]]'),
            );
        }
        if ($item instanceof JsObject) {
            $cal = 'iso8601';
            $calVal = $item->get('calendar');
            if (!($calVal instanceof JsUndefined)) {
                $cal = self::toCalendarSlotValue($calVal);
            }
            // era/eraYear (non-ISO calendars only) per alphabetical
            // spec ordering. Validation happens immediately.
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
                static $pymErasUseEras = ['gregory', 'japanese', 'roc'];
                if (in_array($cal, $pymErasUseEras, true) && $eraSet !== $eraYearSet) {
                    throw new TypeError('era and eraYear must be provided together');
                }
            }
            $month = $item->get('month');
            $mVal = null;
            if (!($month instanceof JsUndefined)) {
                $mNum = TypeConversion::toNumber($month);
                if (!is_finite($mNum)) {
                    throw new RangeError('month must be finite');
                }
                $mVal = (int) $mNum;
            }
            $monthCode = $item->get('monthCode');
            $mcStr = null;
            $mcParsed = null;
            if (!($monthCode instanceof JsUndefined)) {
                $mcStr = TypeConversion::toString($monthCode);
                $mcParsed = self::parseMonthCodeSyntax($mcStr);
            }
            $year = $item->get('year');
            if ($year instanceof JsUndefined) {
                static $pymEraDerivCals = ['gregory', 'japanese', 'roc'];
                if (
                    $eraYearNum !== null
                    && in_array($cal, $pymEraDerivCals, true)
                ) {
                    $eraLower = $eraStr === null ? '' : strtolower($eraStr);
                    if ($cal === 'japanese') {
                        $isoYear = self::japaneseEraToIsoYear($eraLower, (int) $eraYearNum);
                        $yNum = $isoYear !== null
                            ? (float) $isoYear
                            : (in_array($eraLower, ['japanese-inverse'], true) ? (1 - $eraYearNum) : $eraYearNum);
                    } elseif ($cal === 'roc') {
                        $yNum = in_array($eraLower, ['roc-inverse', 'before-roc'], true)
                            ? (1912 - $eraYearNum)
                            : (1911 + $eraYearNum);
                    } else {
                        $yNum = in_array($eraLower, ['bc', 'bce', 'gregory-inverse'], true)
                            ? (1 - $eraYearNum)
                            : $eraYearNum;
                    }
                } else {
                    throw new TypeError('missing required property: year');
                }
            } else {
                $yNum = TypeConversion::toNumber($year);
            }
            if (!is_finite($yNum)) {
                throw new RangeError('year must be finite');
            }
            $y = (int) $yNum;
            if ($mVal === null && $mcStr === null) {
                throw new TypeError('missing required property: month or monthCode');
            }
            if ($mcParsed !== null) {
                [$mcMonth, $mcLeap] = $mcParsed;
                // Leap-month suffix 'L' is only allowed on lunisolar
                // calendars (Hebrew, Chinese, Dangi). Other calendars
                // reject monthCodes ending in L.
                static $pymLunisolar = ['hebrew', 'chinese', 'dangi'];
                if ($mcMonth < 1 || $mcMonth > 12) {
                    throw new RangeError("monthCode '{$mcStr}' is not valid for ISO 8601 calendar");
                }
                if ($mcLeap && !in_array($cal, $pymLunisolar, true)) {
                    throw new RangeError("monthCode '{$mcStr}' leap-month suffix is not valid for calendar '{$cal}'");
                }
                $m = $mcMonth;
                if ($mVal !== null && $mVal !== $m) {
                    throw new RangeError("month and monthCode disagree");
                }
            } elseif ($mVal !== null) {
                $m = $mVal;
            } else {
                throw new TypeError('missing required property: month or monthCode');
            }
            // Per spec: months < 1 always throw, even with constrain.
            if ($m < 1) {
                throw new RangeError("month {$m} out of range");
            }
            if ($overflow === 'constrain') {
                $m = min(12, $m);
            } elseif ($m > 12) {
                throw new RangeError("month {$m} out of range");
            }
            // Non-ISO non-gregory: convert calendar fields to ISO via ICU.
            if ($cal !== 'iso8601' && !in_array($cal, ['gregory', 'roc', 'japanese'], true)) {
                $isoParts = self::calendarPartsToIso($cal, $y, $mcStr, $mcStr === null ? $m : null, 1);
                if ($isoParts === null && $mcStr !== null && in_array($cal, ['chinese', 'dangi'], true) && preg_match('/^M(\d{2})L$/', $mcStr, $mm)) {
                    if ($overflow === 'reject') {
                        throw new RangeError("Chinese leap month {$mcStr} does not exist in year {$y}");
                    }
                    $nextNum = ((int) $mm[1]) + 1;
                    if ($nextNum >= 1 && $nextNum <= 12) {
                        $altMc = 'M' . str_pad((string) $nextNum, 2, '0', STR_PAD_LEFT);
                        $isoParts = self::calendarPartsToIso($cal, $y, $altMc, null, 1);
                    }
                }
                if ($isoParts !== null) {
                    return self::createPlainYearMonthObject($isoParts['year'], $isoParts['month'], $isoParts['day'], $cal);
                }
            }
            return self::createPlainYearMonthObject($y, $m, 1, $cal);
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
        // For non-ISO calendars, preserve the parsed day component as the
        // reference ISO day so getters land on the correct calendar M/d.
        $refDay = 1;
        if ($cal !== 'iso8601' && isset($m[3]) && $m[3] !== '') {
            $refDay = (int) $m[3];
        }
        return self::createPlainYearMonthObject($y, $mo, $refDay, $cal);
    }

    private static function toPlainMonthDay(JsValue $item, string $overflow = 'constrain'): JsObject
    {
        // Type validation: reject null, boolean, number, BigInt, symbol.
        if ($item instanceof JsUndefined || $item instanceof JsNull) {
            throw new TypeError('Cannot convert undefined or null to a Temporal.PlainMonthDay');
        }
        if ($item instanceof JsBoolean || $item instanceof JsNumber || $item instanceof JsBigInt) {
            throw new TypeError('Cannot convert primitive to a Temporal.PlainMonthDay');
        }

        if ($item instanceof JsObject && $item->has('[[IsPlainMonthDay]]')) {
            // Copy the PlainMonthDay, do not return the same object.
            return self::createPlainMonthDayObject(
                self::getSlotInt($item, '[[ISOMonth]]'),
                self::getSlotInt($item, '[[ISODay]]'),
                self::getSlotInt($item, '[[ISOYear]]'),
                self::getSlotString($item, '[[Calendar]]'),
            );
        }
        if ($item instanceof JsObject) {
            // Validate calendar property if present (read first per spec).
            $calVal = $item->get('calendar');
            $cal = 'iso8601';
            if (!($calVal instanceof JsUndefined)) {
                $cal = self::toCalendarSlotValue($calVal);
            }
            // PrepareTemporalFields: alphabetical with immediate
            // valueOf/toString.
            $day = $item->get('day');
            $hasDay = !($day instanceof JsUndefined);
            $dNum = null;
            if ($hasDay) {
                $dNum = TypeConversion::toNumber($day);
            }
            // era/eraYear (non-ISO calendars only).
            $eraStr = null;
            $eraYearNum = null;
            if ($cal !== 'iso8601') {
                $eraVal = $item->get('era');
                if (!($eraVal instanceof JsUndefined)) {
                    $eraStr = TypeConversion::toString($eraVal);
                }
                $eraYearVal = $item->get('eraYear');
                if (!($eraYearVal instanceof JsUndefined)) {
                    $eraYearNum = TypeConversion::toNumber($eraYearVal);
                    if (is_nan($eraYearNum) || !is_finite($eraYearNum)) {
                        throw new RangeError('eraYear must be finite');
                    }
                    if (floor($eraYearNum) !== $eraYearNum) {
                        throw new RangeError('eraYear must be an integer');
                    }
                }
            }
            $month = $item->get('month');
            $hasMonth = !($month instanceof JsUndefined);
            $mNumRaw = null;
            if ($hasMonth) {
                $mNumRaw = TypeConversion::toNumber($month);
            }
            $monthCode = $item->get('monthCode');
            $hasMonthCode = !($monthCode instanceof JsUndefined);
            $mcStrRaw = null;
            if ($hasMonthCode) {
                $mcStrRaw = TypeConversion::toString($monthCode);
            }
            $year = $item->get('year');
            $hasYear = !($year instanceof JsUndefined);
            $yValRaw = null;
            if ($hasYear) {
                $yValRaw = TypeConversion::toNumber($year);
            }
            // Validate required properties now that all reads are done.
            if (!$hasDay) {
                throw new TypeError('Required property day missing');
            }
            if (!$hasMonth && !$hasMonthCode) {
                throw new TypeError('Required property month or monthCode missing');
            }
            if (!is_finite($dNum)) {
                throw new RangeError('day must be finite');
            }
            $d = (int) $dNum;
            if ($hasMonthCode) {
                if (!preg_match('/^M(\d{2})(L?)$/', $mcStrRaw, $mcm)) {
                    throw new RangeError("Invalid monthCode: {$mcStrRaw}");
                }
                $m = (int) $mcm[1];
                $hasLeap = $mcm[2] === 'L';
                $mStr = $mcStrRaw;
            } else {
                if (!is_finite($mNumRaw)) {
                    throw new RangeError('month must be finite');
                }
                $m = (int) $mNumRaw;
                $hasLeap = false;
                $mStr = '';
            }
            $refYear = 1972;
            if ($hasYear) {
                if (!is_finite($yValRaw)) {
                    throw new RangeError('year must be finite');
                }
                $refYear = (int) $yValRaw;
                // When era+eraYear is also provided, validate consistency.
                if ($eraYearNum !== null) {
                    static $pmdEraDerivCals2 = ['gregory', 'japanese', 'roc'];
                    if (in_array($cal, $pmdEraDerivCals2, true)) {
                        $eraLower = $eraStr === null ? '' : strtolower($eraStr);
                        $derivedYear = null;
                        if ($cal === 'gregory') {
                            $derivedYear = in_array($eraLower, ['bc', 'bce', 'gregory-inverse'], true)
                                ? (1 - (int) $eraYearNum)
                                : (int) $eraYearNum;
                        } elseif ($cal === 'roc') {
                            $derivedYear = ($eraLower === 'roc-inverse' || $eraLower === 'before-roc')
                                ? (int) (1912 - $eraYearNum)
                                : (int) (1911 + $eraYearNum);
                        } elseif ($cal === 'japanese') {
                            $isoY = self::japaneseEraToIsoYear($eraLower, (int) $eraYearNum);
                            if ($isoY !== null) {
                                $derivedYear = $isoY;
                            }
                        }
                        if ($derivedYear !== null && $derivedYear !== $refYear) {
                            throw new RangeError(
                                "era/eraYear and year disagree: derived {$derivedYear} vs explicit {$refYear}",
                            );
                        }
                    }
                }
            } else {
                static $pmdEraDerivCals = ['gregory', 'japanese', 'roc'];
                if (
                    $eraYearNum !== null
                    && in_array($cal, $pmdEraDerivCals, true)
                ) {
                    $eraLower = $eraStr === null ? '' : strtolower($eraStr);
                    $refYear = in_array($eraLower, ['bc', 'bce'], true)
                        ? (1 - (int) $eraYearNum)
                        : (int) $eraYearNum;
                } elseif ($cal !== 'iso8601' && !$hasMonthCode) {
                    // Non-ISO calendars require year info when month is given numerically.
                    throw new TypeError('non-ISO calendar requires year (or era+eraYear)');
                }
            }

            // Now validate month code suitability (semantic check).
            if ($hasMonthCode) {
                if ($m < 1 || $m > 12) {
                    throw new RangeError("monthCode '{$mStr}' is not valid for ISO 8601 calendar");
                }
                static $pmdLunisolar = ['hebrew', 'chinese', 'dangi'];
                if ($hasLeap && !in_array($cal, $pmdLunisolar, true)) {
                    throw new RangeError("monthCode '{$mStr}' is not valid for ISO 8601 calendar");
                }
                // Check for monthCode/month conflict.
                if ($hasMonth) {
                    $monthNum = (int) TypeConversion::toNumber($month);
                    if ($monthNum !== $m) {
                        throw new RangeError("monthCode {$mStr} and month {$monthNum} conflict");
                    }
                }
            }

            if ($overflow === 'constrain') {
                // Months <= 0 are always invalid even with constrain.
                if ($m < 1) {
                    throw new RangeError("Invalid month: {$m}");
                }
                $m = min(12, $m);
                // Days <= 0 are always invalid even with constrain.
                if ($d < 1) {
                    throw new RangeError("Invalid day: {$d}");
                }
                $dim = self::isoDaysInMonth($refYear, $m);
                $d = min($dim, $d);
            } else {
                // reject: validate strictly.
                if ($m < 1 || $m > 12) {
                    throw new RangeError("Invalid month: {$m}");
                }
                $dim = self::isoDaysInMonth($refYear, $m);
                if ($d < 1 || $d > $dim) {
                    throw new RangeError("Invalid day: {$d} for month {$m} in year {$refYear}");
                }
            }
            // Non-ISO non-gregory: pick the reference ISO date for this calendar
            // M-d combo (latest ISO ≤ 1972).
            if ($cal !== 'iso8601' && !in_array($cal, ['gregory', 'roc', 'japanese'], true)) {
                $iso = self::pmdReferenceIsoFor($cal, $hasMonthCode ? $mStr : null, $hasMonthCode ? null : $m, $d);
                if ($iso !== null) {
                    return self::createPlainMonthDayObject($iso['month'], $iso['day'], $iso['year'], $cal);
                }
                // No valid reference ISO landed in calendar — invalid M-d
                // for this calendar (e.g. hebrew M01L, which is not a real
                // leap month).
                throw new RangeError(
                    "Invalid PlainMonthDay for calendar '{$cal}'",
                );
            }
            return self::createPlainMonthDayObject($m, $d, 1972, $cal);
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
        if ($item instanceof JsUndefined || $item instanceof JsNull) {
            throw new TypeError('Cannot convert undefined or null to a Temporal.PlainMonthDay');
        }
        if ($item instanceof JsBoolean || $item instanceof JsNumber || $item instanceof JsBigInt) {
            throw new TypeError('Cannot convert primitive to a Temporal.PlainMonthDay');
        }
        if ($item instanceof JsObject && $item->has('[[IsPlainMonthDay]]')) {
            $result = self::createPlainMonthDayObject(
                self::getSlotInt($item, '[[ISOMonth]]'),
                self::getSlotInt($item, '[[ISODay]]'),
                self::getSlotInt($item, '[[ISOYear]]'),
                self::getSlotString($item, '[[Calendar]]'),
            );
            self::getOverflow($options);
            return $result;
        }
        if ($item instanceof JsObject) {
            // Read fields in alphabetical order per spec, coercing immediately.
            // 1. calendar
            $calVal = $item->get('calendar');
            $cal = 'iso8601';
            if (!($calVal instanceof JsUndefined)) {
                $cal = self::toCalendarSlotValue($calVal);
            }

            // 2. day (read and coerce immediately)
            $dayVal = $item->get('day');
            $hasDay = !($dayVal instanceof JsUndefined);
            $d = 0;
            if ($hasDay) {
                $dNum = TypeConversion::toNumber($dayVal);
                if (!is_finite($dNum)) {
                    throw new RangeError('day must be finite');
                }
                $d = (int) $dNum;
            }

            // 3. era / eraYear (non-ISO calendars only).
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
                static $pmdLazyEraCals = ['gregory', 'japanese', 'roc'];
                if (in_array($cal, $pmdLazyEraCals, true) && $eraSet !== $eraYearSet) {
                    throw new TypeError('era and eraYear must be provided together');
                }
            }

            // 4. month (read and coerce immediately)
            $monthVal = $item->get('month');
            $hasMonth = !($monthVal instanceof JsUndefined);
            $monthNum = 0;
            if ($hasMonth) {
                $mVal = TypeConversion::toNumber($monthVal);
                if (!is_finite($mVal)) {
                    throw new RangeError('month must be finite');
                }
                $monthNum = (int) $mVal;
            }

            // 5. monthCode (read and coerce immediately)
            $monthCodeVal = $item->get('monthCode');
            $hasMonthCode = !($monthCodeVal instanceof JsUndefined);
            $m = 0;
            $hasLeap = false;
            $mStr = '';
            if ($hasMonthCode) {
                $mStr = TypeConversion::toString($monthCodeVal);
                if (!preg_match('/^M(\d{2})(L?)$/', $mStr, $mcm)) {
                    throw new RangeError("Invalid monthCode: {$mStr}");
                }
                $m = (int) $mcm[1];
                $hasLeap = $mcm[2] === 'L';
            }

            // 6. year (read and coerce immediately)
            $yearVal = $item->get('year');
            $hasYear = !($yearVal instanceof JsUndefined);
            $refYear = 1972;
            if ($hasYear) {
                $yVal = TypeConversion::toNumber($yearVal);
                if (!is_finite($yVal)) {
                    throw new RangeError('year must be finite');
                }
                // ROC field-bag year is 1-based from 1912 ("民國" 1 =
                // 1912 AD). All downstream day/month math wants the ISO
                // year, so translate up front.
                $refYear = $cal === 'roc' ? ((int) $yVal + 1911) : (int) $yVal;
                if ($eraYearNum !== null) {
                    static $pmdEraDerivCals3 = ['gregory', 'japanese', 'roc'];
                    if (in_array($cal, $pmdEraDerivCals3, true)) {
                        $eraLower = $eraStr === null ? '' : strtolower($eraStr);
                        $derivedYear = null;
                        if ($cal === 'gregory') {
                            $derivedYear = in_array($eraLower, ['bc', 'bce', 'gregory-inverse'], true)
                                ? (1 - (int) $eraYearNum)
                                : (int) $eraYearNum;
                        } elseif ($cal === 'roc') {
                            $derivedYear = ($eraLower === 'roc-inverse' || $eraLower === 'before-roc')
                                ? (int) (1912 - $eraYearNum)
                                : (int) (1911 + $eraYearNum);
                        } elseif ($cal === 'japanese') {
                            $isoY = self::japaneseEraToIsoYear($eraLower, (int) $eraYearNum);
                            if ($isoY !== null) {
                                $derivedYear = $isoY;
                            }
                        }
                        if ($derivedYear !== null && $derivedYear !== $refYear) {
                            throw new RangeError(
                                "era/eraYear and year disagree: derived {$derivedYear} vs explicit {$refYear}",
                            );
                        }
                    }
                }
            } else {
                static $pmdEraDerivCals2 = ['gregory', 'japanese', 'roc'];
                if (
                    $eraYearNum !== null
                    && in_array($cal, $pmdEraDerivCals2, true)
                ) {
                    $eraLower = $eraStr === null ? '' : strtolower($eraStr);
                    $refYear = in_array($eraLower, ['bc', 'bce'], true)
                        ? (1 - (int) $eraYearNum)
                        : (int) $eraYearNum;
                    $hasYear = true;
                }
            }

            // Validate required fields.
            if (!$hasDay) {
                throw new TypeError('Required property day missing');
            }
            if (!$hasMonthCode && !$hasMonth) {
                throw new TypeError('Required property month or monthCode missing');
            }
            // Non-ISO calendars require year info when month is given numerically.
            if ($cal !== 'iso8601' && !$hasYear && !$hasMonthCode) {
                throw new TypeError('non-ISO calendar requires year (or era+eraYear) when month is given numerically');
            }

            // Resolve month from monthCode or month.
            if (!$hasMonthCode) {
                $m = $monthNum;
            }

            // Validate month code suitability after year type validation.
            if ($hasMonthCode) {
                static $pmdHas13Month = ['coptic', 'ethioaa', 'ethiopic'];
                $maxMonthCode = in_array($cal, $pmdHas13Month, true) ? 13 : 12;
                if ($m < 1 || $m > $maxMonthCode) {
                    throw new RangeError("monthCode '{$mStr}' is not valid for ISO 8601 calendar");
                }
                static $pmdLunisolar2 = ['hebrew', 'chinese', 'dangi'];
                if ($hasLeap && !in_array($cal, $pmdLunisolar2, true)) {
                    throw new RangeError("monthCode '{$mStr}' is not valid for ISO 8601 calendar");
                }
                if ($hasMonth && $monthNum !== $m) {
                    throw new RangeError("monthCode {$mStr} and month {$monthNum} conflict");
                }
            }

            // NOW read overflow from options (after all field reads).
            $overflow = self::getOverflow($options);

            static $pmdHas13MonthsConstrain = ['coptic', 'ethioaa', 'ethiopic'];
            $maxMonth = in_array($cal, $pmdHas13MonthsConstrain, true) ? 13 : 12;
            // For lunisolar calendars (hebrew/chinese/dangi), the month
            // count varies year-to-year. With an explicit year, cap by
            // that year's actual monthsInYear so month:15 in a leap
            // hebrew year clamps to 13 (Elul) rather than 12 (Av).
            if (
                $hasYear
                && in_array($cal, ['hebrew', 'chinese', 'dangi'], true)
            ) {
                $isoForCount = self::calendarPartsToIso($cal, $refYear, null, 1, 1);
                if ($isoForCount !== null) {
                    $miyForRefYear = self::calendarMonthsInYear(
                        $cal,
                        $isoForCount['year'],
                        $isoForCount['month'],
                        $isoForCount['day'],
                    );
                    if ($miyForRefYear !== null) {
                        $maxMonth = $miyForRefYear;
                    }
                }
            }
            // When the user provided a year, the day limit is that year's
            // actual days-in-month (not the calendar-wide max).
            $useYearDim = $hasYear && in_array($cal, ['iso8601', 'gregory', 'roc', 'japanese'], true);
            if ($overflow === 'constrain') {
                if ($m < 1) {
                    throw new RangeError("Invalid month: {$m}");
                }
                $m = min($maxMonth, $m);
                if ($d < 1) {
                    throw new RangeError("Invalid day: {$d}");
                }
                if ($useYearDim) {
                    $dim = self::isoDaysInMonth($refYear, $m);
                } elseif ($cal === 'iso8601') {
                    $dim = self::isoDaysInMonth($refYear, $m);
                } else {
                    $dim = self::maxDaysInCalendarMonth($cal, $m, $hasLeap);
                }
                $d = min($dim, $d);
            } else {
                if ($m < 1 || $m > $maxMonth) {
                    throw new RangeError("Invalid month: {$m}");
                }
                if ($useYearDim) {
                    $dim = self::isoDaysInMonth($refYear, $m);
                } elseif ($cal === 'iso8601') {
                    $dim = self::isoDaysInMonth($refYear, $m);
                } else {
                    $dim = self::maxDaysInCalendarMonth($cal, $m, $hasLeap);
                }
                if ($d < 1 || $d > $dim) {
                    throw new RangeError("Invalid day: {$d}");
                }
            }
            // Non-ISO non-gregory: pick the reference ISO date for this calendar
            // M-d combo. For Hebrew with explicit year, also constrain day by
            // the actual days-in-month for that calendar year.
            if ($cal !== 'iso8601' && !in_array($cal, ['gregory', 'roc', 'japanese'], true)) {
                if ($hasYear) {
                    $maxD = self::calendarDaysInMonth($cal, $refYear, $hasMonthCode ? $mStr : null, $hasMonthCode ? null : $m);
                    if ($maxD !== null) {
                        if ($overflow === 'reject' && $d > $maxD) {
                            throw new RangeError("Invalid day: {$d} for {$mStr} in calendar year {$refYear}");
                        }
                        $d = min($d, $maxD);
                    }
                }
                // When the user supplied year+month (no monthCode) for a
                // lunisolar calendar, resolve the monthCode in that year's
                // space so a PMD constructed in a leap year carries the
                // correct ML / non-L suffix. Otherwise PMD throws away the
                // year and the search around 1972 may pick a non-leap year.
                $resolvedMonthCode = $hasMonthCode ? $mStr : null;
                if (
                    !$hasMonthCode
                    && $hasYear
                    && in_array($cal, ['hebrew', 'chinese', 'dangi'], true)
                ) {
                    $resolved = self::calendarPartsToIso($cal, $refYear, null, $m, $d);
                    if ($resolved !== null) {
                        $back = self::isoToCalendarParts($cal, $resolved['year'], $resolved['month'], $resolved['day']);
                        if ($back !== null) {
                            $resolvedMonthCode = $back['monthCode'];
                        }
                    }
                }
                $iso = self::pmdReferenceIsoFor(
                    $cal,
                    $resolvedMonthCode,
                    $resolvedMonthCode === null ? $m : null,
                    $d,
                );
                if ($iso !== null) {
                    return self::createPlainMonthDayObject($iso['month'], $iso['day'], $iso['year'], $cal);
                }
                throw new RangeError(
                    "Invalid PlainMonthDay for calendar '{$cal}'",
                );
            }
            return self::createPlainMonthDayObject($m, $d, 1972, $cal);
        }
        $str = TypeConversion::toString($item);
        return self::parsePlainMonthDayString($str);
    }

    private static function parsePlainMonthDayString(string $str): JsObject
    {
        [$str, $calFromAnnotation] = self::normalizeTemporalString($str);

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
