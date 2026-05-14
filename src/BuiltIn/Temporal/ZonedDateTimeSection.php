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
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;
use Phasis\BuiltIn\SymbolConstructor;

/**
 * Temporal.ZonedDateTime type installer. Composed into TemporalObject
 * via `use Temporal\ZonedDateTimeSection;` — the per-section split is
 * purely organisational. `self::` references resolve into the
 * composing class so cross-section helpers continue to work.
 */
trait ZonedDateTimeSection
{
    // -----------------------------------------------------------------------
    // Temporal.ZonedDateTime (minimal)
    // -----------------------------------------------------------------------

    private static ?JsObject $zonedDateTimeProto = null;

    private static function installZonedDateTime(JsObject $temporal, Environment $env): JsObject
    {
        $proto = new JsObject();

        self::defineGetter($proto, 'epochNanoseconds', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            return new JsBigInt(self::getSlotString($this_, '[[EpochNanoseconds]]'));
        });
        self::defineGetter($proto, 'epochMilliseconds', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            return JsNumber::of(self::bigFloorDiv($ns, '1000000'));
        });
        self::defineGetter($proto, 'timeZoneId', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            return new JsString(self::getSlotString($this_, '[[TimeZone]]'));
        });
        self::defineGetter($proto, 'calendarId', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            return new JsString(self::getSlotString($this_, '[[Calendar]]'));
        });

        // Date/time getters for ZonedDateTime: derive from epoch ns + timezone.
        $dtFields = ['year', 'month', 'day', 'hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];
        $calFields = ['year' => true, 'month' => true, 'day' => true];
        foreach ($dtFields as $field) {
            self::defineGetter($proto, $field, function (JsValue $this_) use ($field, $calFields): JsValue {
                self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
                $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
                $tz = self::getSlotString($this_, '[[TimeZone]]');
                $cal = self::getSlotString($this_, '[[Calendar]]');
                $parts = self::epochNsToISOParts($ns, $tz);
                if (isset($calFields[$field]) && $cal !== 'iso8601') {
                    $cp = self::isoToCalendarParts($cal, $parts['year'], $parts['month'], $parts['day']);
                    if ($cp !== null) {
                        return JsNumber::of((float) $cp[$field]);
                    }
                }
                return JsNumber::of((float) $parts[$field]);
            });
        }
        self::defineGetter($proto, 'monthCode', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            if ($cal !== 'iso8601') {
                $cp = self::isoToCalendarParts($cal, $parts['year'], $parts['month'], $parts['day']);
                if ($cp !== null) {
                    return new JsString($cp['monthCode']);
                }
            }
            return new JsString('M' . str_pad((string) $parts['month'], 2, '0', STR_PAD_LEFT));
        });
        self::defineGetter($proto, 'dayOfWeek', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            return JsNumber::of((float) self::isoDayOfWeek($parts['year'], $parts['month'], $parts['day']));
        });
        self::defineGetter($proto, 'dayOfYear', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            $doy = self::calendarDayOfYearForIso($cal, $parts['year'], $parts['month'], $parts['day']);
            return JsNumber::of((float) ($doy ?? self::isoDayOfYear($parts['year'], $parts['month'], $parts['day'])));
        });
        self::defineGetter($proto, 'daysInMonth', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            $count = self::calendarDaysInMonthForIso($cal, $parts['year'], $parts['month'], $parts['day']);
            return JsNumber::of((float) ($count ?? self::isoDaysInMonth($parts['year'], $parts['month'])));
        });
        self::defineGetter($proto, 'daysInYear', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            $count = self::calendarDaysInYearForIso($cal, $parts['year'], $parts['month'], $parts['day']);
            return JsNumber::of((float) ($count ?? self::isoDaysInYear($parts['year'])));
        });
        self::defineGetter($proto, 'inLeapYear', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            return new JsBoolean(self::isoIsLeapYear($parts['year']));
        });
        self::defineGetter($proto, 'weekOfYear', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            [$week] = self::calendarWeekOfYear($cal, $parts['year'], $parts['month'], $parts['day']);
            return $week === null ? JsUndefined::instance() : JsNumber::of((float) $week);
        });
        self::defineGetter($proto, 'yearOfWeek', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            [, $yearOfWeek] = self::calendarWeekOfYear($cal, $parts['year'], $parts['month'], $parts['day']);
            return $yearOfWeek === null ? JsUndefined::instance() : JsNumber::of((float) $yearOfWeek);
        });
        self::defineGetter($proto, 'monthsInYear', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            $count = self::calendarMonthsInYear($cal, $parts['year'], $parts['month'], $parts['day']);
            return JsNumber::of((float) ($count ?? 12));
        });
        self::defineGetter($proto, 'daysInWeek', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            return JsNumber::of(7.0);
        });
        self::defineGetter($proto, 'hoursInDay', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            $startNs = self::startOfDayInTimeZone($parts['year'], $parts['month'], $parts['day'], $tz);
            self::validateInstantRange($startNs);
            // Next day's start is also the actual start-of-day in the zone.
            $ny = $parts['year'];
            $nm = $parts['month'];
            $nd = $parts['day'] + 1;
            $dim = self::isoDaysInMonth($ny, $nm);
            if ($nd > $dim) {
                $nd = 1;
                $nm += 1;
                if ($nm > 12) {
                    $nm = 1;
                    $ny += 1;
                }
            }
            $nextDate = self::startOfDayInTimeZone($ny, $nm, $nd, $tz);
            self::validateInstantRange($nextDate);
            $dayNs = bcsub($nextDate, $startNs, 0);
            // 20 decimal places gives enough precision for float
            // round-trip: 23.6666666666... lands on the closest float
            // (23.666666666666668) instead of being truncated.
            return JsNumber::of((float) bcdiv($dayNs, '3600000000000', 20));
        });
        self::defineGetter($proto, 'offsetNanoseconds', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            $wallNs = self::isoDateTimeToEpochNs(
                $parts['year'],
                $parts['month'],
                $parts['day'],
                $parts['hour'],
                $parts['minute'],
                $parts['second'],
                $parts['millisecond'],
                $parts['microsecond'],
                $parts['nanosecond'],
                'UTC',
            );
            return JsNumber::of((float) bcsub($wallNs, $ns, 0));
        });
        self::defineGetter($proto, 'offset', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            // Preserve the exact sub-minute offset (no rounding)
            // for the .offset getter; ISO string output uses the
            // rounded form.
            return new JsString(self::timeZoneOffsetString($ns, $tz, false));
        });
        self::defineGetter($proto, 'era', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            $era = self::deriveEra($cal, $parts['year'], $parts['month'], $parts['day']);
            return $era === null ? JsUndefined::instance() : new JsString($era);
        });
        self::defineGetter($proto, 'eraYear', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            $eraYear = self::deriveEraYear($cal, $parts['year'], $parts['month'], $parts['day']);
            return $eraYear === null ? JsUndefined::instance() : JsNumber::of((float) $eraYear);
        });

        $d = self::protoHelper($proto);

        $d('toString', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $options = self::getOptionsObject($args[0] ?? JsUndefined::instance());

            // Read options in alphabetical order per spec.
            $calendarName = 'auto';
            $fractionalSecondDigits = 'auto';
            $offset = 'auto';
            $roundingMode = 'trunc';
            $smallestUnit = null;
            $timeZoneName = 'auto';

            if ($options instanceof JsObject) {
                // calendarName
                $cn = $options->get('calendarName');
                if (!($cn instanceof JsUndefined)) {
                    $calendarName = TypeConversion::toString($cn);
                    $validCN = ['auto', 'always', 'never', 'critical'];
                    if (!in_array($calendarName, $validCN, true)) {
                        throw new RangeError("Invalid calendarName: {$calendarName}");
                    }
                }
                // fractionalSecondDigits
                $fsd = $options->get('fractionalSecondDigits');
                if (!($fsd instanceof JsUndefined)) {
                    if ($fsd instanceof JsNumber) {
                        if (!is_finite($fsd->value) || is_nan($fsd->value)) {
                            throw new RangeError('fractionalSecondDigits must be 0-9 or auto');
                        }
                        $n = (int) floor($fsd->value);
                        if ($n < 0 || $n > 9) {
                            throw new RangeError('fractionalSecondDigits must be 0-9 or auto');
                        }
                        $fractionalSecondDigits = $n;
                    } else {
                        $str = TypeConversion::toString($fsd);
                        if ($str !== 'auto') {
                            throw new RangeError('fractionalSecondDigits must be 0-9 or auto');
                        }
                    }
                }
                // offset
                $offOpt = $options->get('offset');
                if (!($offOpt instanceof JsUndefined)) {
                    $offset = TypeConversion::toString($offOpt);
                    $validOff = ['auto', 'never'];
                    if (!in_array($offset, $validOff, true)) {
                        throw new RangeError("Invalid offset option: {$offset}");
                    }
                }
                // roundingMode
                $rmv = $options->get('roundingMode');
                if (!($rmv instanceof JsUndefined)) {
                    $roundingMode = TypeConversion::toString($rmv);
                    $validRM = [
                        'ceil', 'floor', 'expand', 'trunc',
                        'halfCeil', 'halfFloor', 'halfExpand', 'halfTrunc', 'halfEven',
                    ];
                    if (!in_array($roundingMode, $validRM, true)) {
                        throw new RangeError("Invalid roundingMode: {$roundingMode}");
                    }
                }
                // smallestUnit
                $su = $options->get('smallestUnit');
                if (!($su instanceof JsUndefined)) {
                    $smallestUnit = TypeConversion::toString($su);
                    $smallestUnit = self::canonicalTemporalUnit($smallestUnit);
                    $valid = ['minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];
                    if (!in_array($smallestUnit, $valid, true)) {
                        throw new RangeError("Invalid smallestUnit: {$smallestUnit}");
                    }
                    $unitToDigits = [
                        'minute' => 0, 'second' => 0,
                        'millisecond' => 3, 'microsecond' => 6, 'nanosecond' => 9,
                    ];
                    $fractionalSecondDigits = $unitToDigits[$smallestUnit];
                }
                // timeZoneName
                $tzn = $options->get('timeZoneName');
                if (!($tzn instanceof JsUndefined)) {
                    $timeZoneName = TypeConversion::toString($tzn);
                    $validTZN = ['auto', 'never', 'critical'];
                    if (!in_array($timeZoneName, $validTZN, true)) {
                        throw new RangeError("Invalid timeZoneName: {$timeZoneName}");
                    }
                }
            }
            // Apply rounding: get wall-clock parts, then round ISO date-time.
            $parts = self::epochNsToISOParts($ns, $tz);
            $rounded = false;
            if ($smallestUnit !== null && $smallestUnit !== 'nanosecond') {
                $unitNsMap = [
                    'minute' => '60000000000', 'second' => '1000000000',
                    'millisecond' => '1000000', 'microsecond' => '1000',
                ];
                $parts = self::roundISODateTime($parts, $unitNsMap[$smallestUnit], $roundingMode, $tz);
                $rounded = true;
            } elseif ($smallestUnit === null && is_int($fractionalSecondDigits) && $fractionalSecondDigits < 9) {
                $digitsToNs = [
                    0 => '1000000000', 1 => '100000000', 2 => '10000000',
                    3 => '1000000', 4 => '100000', 5 => '10000',
                    6 => '1000', 7 => '100', 8 => '10',
                ];
                $parts = self::roundISODateTime($parts, $digitsToNs[$fractionalSecondDigits], $roundingMode, $tz);
                $rounded = true;
            }
            // After rounding, re-resolve the rounded wall-clock to an
            // instant in the zone. This matters for DST-forward gaps:
            // rounding 01:59:59.999...9 up by a nanosecond produces a
            // wall time of 02:00:00 that doesn't exist in the zone, so
            // we need to push it forward to 03:00 PDT and update the
            // offset to match.
            if ($rounded) {
                $newNs = self::isoDateTimeToEpochNs(
                    $parts['year'],
                    $parts['month'],
                    $parts['day'],
                    $parts['hour'],
                    $parts['minute'],
                    $parts['second'],
                    $parts['millisecond'],
                    $parts['microsecond'],
                    $parts['nanosecond'],
                    $tz,
                );
                $parts = self::epochNsToISOParts($newNs, $tz);
                $ns = $newNs;
            }
            // Use the (possibly post-round) ns to compute the offset so
            // the disambiguation choice (e.g. fall-back DST where the
            // wall-clock time exists twice) matches the stored instant.
            $timeStr = self::formatISOTime(
                $parts['hour'],
                $parts['minute'],
                $parts['second'],
                $parts['millisecond'],
                $parts['microsecond'],
                $parts['nanosecond'],
                $fractionalSecondDigits,
                'trunc',
            );
            $dateStr = self::padISOYear($parts['year']) . '-' . self::pad2($parts['month']) . '-' . self::pad2($parts['day']);
            $offsetStr = self::timeZoneOffsetString($ns, $tz);
            // Handle smallestUnit=minute: omit seconds from time.
            if ($smallestUnit === 'minute') {
                $timeStr = self::pad2($parts['hour']) . ':' . self::pad2($parts['minute']);
            }
            // Build result.
            if ($offset === 'never') {
                $result = "{$dateStr}T{$timeStr}";
            } else {
                $result = "{$dateStr}T{$timeStr}{$offsetStr}";
            }
            if ($timeZoneName === 'critical') {
                $result .= "[!{$tz}]";
            } elseif ($timeZoneName !== 'never') {
                $result .= "[{$tz}]";
            }
            if (
                $calendarName === 'always' || ($calendarName === 'auto' && $cal !== 'iso8601')
                || $calendarName === 'critical'
            ) {
                $prefix = $calendarName === 'critical' ? '!' : '';
                $result .= "[{$prefix}u-ca={$cal}]";
            }
            return new JsString($result);
        }, 0);

        $d('toJSON', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            $timeStr = self::formatISOTime(
                $parts['hour'],
                $parts['minute'],
                $parts['second'],
                $parts['millisecond'],
                $parts['microsecond'],
                $parts['nanosecond'],
                'auto',
                'trunc'
            );
            $dateStr = self::padISOYear($parts['year']) . '-' . self::pad2($parts['month']) . '-' . self::pad2($parts['day']);
            $offsetStr = self::timeZoneOffsetString($ns, $tz);
            $result = "{$dateStr}T{$timeStr}{$offsetStr}[{$tz}]";
            if ($cal !== 'iso8601') {
                $result .= "[u-ca={$cal}]";
            }
            return new JsString($result);
        }, 0);

        $d('toLocaleString', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            // Per spec ZonedDateTime.toLocaleString resolves the
            // formatter's timeZone to the ZDT's own zone, requires
            // matching calendar, and adds timeZoneName when defaults
            // apply. Format the underlying instant via Intl.DateTimeFormat.
            $instant = self::createInstantFromNs($ns);
            $optionsArg = self::resolveZonedDateTimeOptions(
                $args[1] ?? JsUndefined::instance(),
                $tz,
                $cal,
                $args[0] ?? JsUndefined::instance(),
            );
            $argsForward = [$args[0] ?? JsUndefined::instance(), $optionsArg];
            $fallback = self::zonedDateTimeIsoFallback($ns, $tz, $cal);
            return self::temporalToLocaleString($instant, $argsForward, $fallback);
        }, 0);

        $d('valueOf', function (JsValue $this_): JsValue {
            throw new TypeError('Temporal.ZonedDateTime does not implement valueOf');
        }, 0);

        $d('toInstant', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            return self::createInstantObject(self::getSlotString($this_, '[[EpochNanoseconds]]'));
        }, 0);

        $d('toPlainDate', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            return self::createPlainDateObject($parts['year'], $parts['month'], $parts['day'], $cal);
        }, 0);

        $d('toPlainTime', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            return self::createPlainTimeObject(
                $parts['hour'],
                $parts['minute'],
                $parts['second'],
                $parts['millisecond'],
                $parts['microsecond'],
                $parts['nanosecond']
            );
        }, 0);

        $d('toPlainDateTime', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            return self::createPlainDateTimeObject(
                $parts['year'],
                $parts['month'],
                $parts['day'],
                $parts['hour'],
                $parts['minute'],
                $parts['second'],
                $parts['millisecond'],
                $parts['microsecond'],
                $parts['nanosecond'],
                $cal
            );
        }, 0);

        $d('withTimeZone', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $tzArg = $args[0] ?? JsUndefined::instance();
            $timeZone = self::toTemporalTimeZoneIdentifier($tzArg);
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            return self::createZonedDateTimeObject($ns, $timeZone, $cal);
        }, 1);

        $d('withCalendar', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $calArg = $args[0] ?? JsUndefined::instance();
            $cal = self::toCalendarSlotValue($calArg);
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            return self::createZonedDateTimeObject($ns, $tz, $cal);
        }, 1);

        $d('withPlainTime', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            $timeArg = $args[0] ?? JsUndefined::instance();
            if ($timeArg instanceof JsUndefined) {
                // Use the actual start-of-day in the time zone (may not be 00:00 if midnight is in a DST gap).
                $newNs = self::startOfDayInTimeZone($parts['year'], $parts['month'], $parts['day'], $tz);
                return self::createZonedDateTimeObject($newNs, $tz, $cal);
            }
            $t = self::toPlainTime($timeArg);
            $h = self::getSlotInt($t, '[[ISOHour]]');
            $min = self::getSlotInt($t, '[[ISOMinute]]');
            $s = self::getSlotInt($t, '[[ISOSecond]]');
            $ms = self::getSlotInt($t, '[[ISOMillisecond]]');
            $us = self::getSlotInt($t, '[[ISOMicrosecond]]');
            $nsPart = self::getSlotInt($t, '[[ISONanosecond]]');
            $newNs = self::isoDateTimeToEpochNs(
                $parts['year'],
                $parts['month'],
                $parts['day'],
                $h,
                $min,
                $s,
                $ms,
                $us,
                $nsPart,
                $tz,
            );
            return self::createZonedDateTimeObject($newNs, $tz, $cal);
        }, 0);

        $d('with', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $item = $args[0] ?? JsUndefined::instance();
            if (!$item instanceof JsObject) {
                throw new TypeError('argument must be an object');
            }
            self::rejectObjectWithCalendarOrTimeZone($item);
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            $useCalendarNative = $cal !== 'iso8601' && !in_array($cal, ['gregory', 'roc', 'japanese'], true);
            $instMonthCode = null;
            if ($useCalendarNative) {
                $cp = self::isoToCalendarParts($cal, $parts['year'], $parts['month'], $parts['day']);
                if ($cp === null) {
                    $useCalendarNative = false;
                }
            }
            if ($useCalendarNative) {
                $y = $cp['year'];
                $m = $cp['month'];
                $dd = $cp['day'];
                $instMonthCode = $cp['monthCode'];
            } else {
                $y = $parts['year'];
                $m = $parts['month'];
                $dd = $parts['day'];
            }
            $h = $parts['hour'];
            $min = $parts['minute'];
            $s = $parts['second'];
            $ms = $parts['millisecond'];
            $us = $parts['microsecond'];
            $nsPart = $parts['nanosecond'];
            $any = false;
            $monthChanged = false;
            $monthCodeChanged = false;
            $userMonthCode = null;
            $origMonth = $m;
            // Read fields in alphabetical order, converting each immediately.
            $dv = $item->get('day');
            if (!($dv instanceof JsUndefined)) {
                $dd = self::toTemporalInteger($dv, 'day');
                if ($dd < 1) {
                    throw new RangeError("day must be >= 1, got {$dd}");
                }
                $any = true;
            }
            $hv = $item->get('hour');
            if (!($hv instanceof JsUndefined)) {
                $h = self::toTemporalInteger($hv, 'hour');
                $any = true;
            }
            $usv = $item->get('microsecond');
            if (!($usv instanceof JsUndefined)) {
                $us = self::toTemporalInteger($usv, 'microsecond');
                $any = true;
            }
            $msv = $item->get('millisecond');
            if (!($msv instanceof JsUndefined)) {
                $ms = self::toTemporalInteger($msv, 'millisecond');
                $any = true;
            }
            $minv = $item->get('minute');
            if (!($minv instanceof JsUndefined)) {
                $min = self::toTemporalInteger($minv, 'minute');
                $any = true;
            }
            $mv = $item->get('month');
            if (!($mv instanceof JsUndefined)) {
                $m = self::toTemporalInteger($mv, 'month');
                $any = true;
                $monthChanged = true;
            }
            $mcv = $item->get('monthCode');
            if (!($mcv instanceof JsUndefined)) {
                $mcStr0 = TypeConversion::toString($mcv);
                $mcMonth = self::parseMonthCode($mcStr0, $cal);
                $any = true;
                $monthCodeChanged = true;
                if ($monthChanged && $m !== $mcMonth && !$useCalendarNative) {
                    throw new RangeError('month and monthCode must agree');
                }
                if (!$useCalendarNative) {
                    $m = $mcMonth;
                }
                $userMonthCode = $mcStr0;
            }
            $nsv = $item->get('nanosecond');
            if (!($nsv instanceof JsUndefined)) {
                $nsPart = self::toTemporalInteger($nsv, 'nanosecond');
                $any = true;
            }
            // Read offset property from fields. Non-string types throw TypeError per spec.
            $offsetFieldV = $item->get('offset');
            $offsetFieldStr = null;
            if (!($offsetFieldV instanceof JsUndefined)) {
                if (
                    $offsetFieldV instanceof JsNumber
                    || $offsetFieldV instanceof JsBoolean
                    || $offsetFieldV instanceof JsNull
                    || $offsetFieldV instanceof JsBigInt
                ) {
                    throw new TypeError('ZonedDateTime offset property must be a string');
                }
                $offsetFieldStr = TypeConversion::toString($offsetFieldV);
                if (!self::isValidOffsetString($offsetFieldStr)) {
                    throw new RangeError("Invalid offset string: {$offsetFieldStr}");
                }
                $any = true;
            }
            $sv = $item->get('second');
            if (!($sv instanceof JsUndefined)) {
                $s = self::toTemporalInteger($sv, 'second');
                $any = true;
            }
            $yv = $item->get('year');
            if (!($yv instanceof JsUndefined)) {
                $y = self::toTemporalInteger($yv, 'year');
                $any = true;
            }
            if (!$any) {
                throw new TypeError('at least one property required');
            }
            $options = self::getOptionsObject($args[1] ?? JsUndefined::instance());
            $disam = 'compatible';
            $offsetOpt = 'prefer';
            if ($options instanceof JsObject) {
                $dv = $options->get('disambiguation');
                if (!($dv instanceof JsUndefined)) {
                    $disam = TypeConversion::toString($dv);
                    $valid = ['compatible', 'earlier', 'later', 'reject'];
                    if (!in_array($disam, $valid, true)) {
                        throw new RangeError("Invalid disambiguation: {$disam}");
                    }
                }
                $offOpt = $options->get('offset');
                if (!($offOpt instanceof JsUndefined)) {
                    $offsetOpt = TypeConversion::toString($offOpt);
                    $validOff = ['prefer', 'use', 'ignore', 'reject'];
                    if (!in_array($offsetOpt, $validOff, true)) {
                        throw new RangeError("Invalid offset option: {$offsetOpt}");
                    }
                }
            }
            $overflow = self::getOverflow($options);
            // For non-ISO non-gregory calendars, convert calendar-native fields
            // back to ISO via ICU before applying time overflow.
            if ($useCalendarNative) {
                $mcForIso = $userMonthCode ?? ($monthChanged ? null : $instMonthCode);
                $monthForIso = $userMonthCode === null ? $m : null;
                $isoParts = self::calendarPartsToIso($cal, $y, $mcForIso, $monthForIso, $dd);
                if ($isoParts !== null) {
                    $y = $isoParts['year'];
                    $m = $isoParts['month'];
                    $dd = $isoParts['day'];
                }
            }
            if ($overflow === 'constrain') {
                [$y, $m, $dd] = self::constrainISODate($y, $m, $dd);
                $h = max(0, min(23, $h));
                $min = max(0, min(59, $min));
                $s = max(0, min(59, $s));
                $ms = max(0, min(999, $ms));
                $us = max(0, min(999, $us));
                $nsPart = max(0, min(999, $nsPart));
            } else {
                self::validateISODate($y, $m, $dd);
                self::validateISOTime($h, $min, $s, $ms, $us, $nsPart);
            }
            // If an offset was provided in the property bag, use it according to the offset option.
            if ($offsetFieldStr !== null && $offsetOpt !== 'ignore') {
                $givenOffsetNs = self::parseOffsetToNs($offsetFieldStr);
                if ($offsetOpt === 'use') {
                    $normalizedOffset = self::normalizeOffset($givenOffsetNs);
                    $newNs = self::isoDateTimeToEpochNs($y, $m, $dd, $h, $min, $s, $ms, $us, $nsPart, $normalizedOffset);
                    return self::createZonedDateTimeObject($newNs, $tz, $cal);
                }
                // 'prefer' and 'reject': check exact match against any candidate.
                $candidates = self::getPossibleEpochNanoseconds($y, $m, $dd, $h, $min, $s, $ms, $us, $nsPart, $tz);
                $wallUtcNs = self::isoDateTimeToEpochNs($y, $m, $dd, $h, $min, $s, $ms, $us, $nsPart, 'UTC');
                $exactCandidate = null;
                foreach ($candidates as $candNs) {
                    $candOffsetNs = (int) bcsub($wallUtcNs, $candNs, 0);
                    if ($givenOffsetNs === $candOffsetNs) {
                        $exactCandidate = $candNs;
                        break;
                    }
                }
                if ($exactCandidate !== null) {
                    return self::createZonedDateTimeObject($exactCandidate, $tz, $cal);
                }
                if ($offsetOpt === 'reject') {
                    throw new RangeError("offset property does not match any valid offset for the time zone");
                }
                // prefer: fall through to disambiguated wall-time interpretation.
            } elseif ($offsetOpt !== 'ignore') {
                // No offset field supplied: prefer the instance's
                // existing offset to disambiguate (per spec, "use",
                // "prefer", and "reject" all keep the original offset
                // when no field-bag offset is present and a candidate
                // matches; "use" additionally falls back to the raw
                // offset even with no matching candidate).
                $instOffsetNs = self::getUtcOffsetNsForTimestamp($tz, $ns);
                $candidates = self::getPossibleEpochNanoseconds($y, $m, $dd, $h, $min, $s, $ms, $us, $nsPart, $tz);
                $wallUtcNs = self::isoDateTimeToEpochNs($y, $m, $dd, $h, $min, $s, $ms, $us, $nsPart, 'UTC');
                $exactCandidate = null;
                foreach ($candidates as $candNs) {
                    $candOffsetNs = (int) bcsub($wallUtcNs, $candNs, 0);
                    if ($instOffsetNs === $candOffsetNs) {
                        $exactCandidate = $candNs;
                        break;
                    }
                }
                if ($exactCandidate !== null) {
                    return self::createZonedDateTimeObject($exactCandidate, $tz, $cal);
                }
                if ($offsetOpt === 'use') {
                    $normalizedOffset = self::normalizeOffset($instOffsetNs);
                    $newNs = self::isoDateTimeToEpochNs($y, $m, $dd, $h, $min, $s, $ms, $us, $nsPart, $normalizedOffset);
                    return self::createZonedDateTimeObject($newNs, $tz, $cal);
                }
                // prefer / reject: fall through to disambiguation.
            }
            $newNs = self::isoDateTimeToEpochNsDisambiguated(
                $y,
                $m,
                $dd,
                $h,
                $min,
                $s,
                $ms,
                $us,
                $nsPart,
                $tz,
                $disam,
            );
            return self::createZonedDateTimeObject($newNs, $tz, $cal);
        }, 1);

        $d('startOfDay', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            $startNs = self::startOfDayInTimeZone($parts['year'], $parts['month'], $parts['day'], $tz);
            return self::createZonedDateTimeObject($startNs, $tz, $cal);
        }, 0);

        $d('round', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $roundTo = $args[0] ?? JsUndefined::instance();
            if ($roundTo instanceof JsUndefined) {
                throw new TypeError('options parameter is required');
            }
            if ($roundTo instanceof JsString) {
                $opt = new JsObject();
                $opt->set('smallestUnit', $roundTo);
                $roundTo = $opt;
            }
            if (!$roundTo instanceof JsObject) {
                throw new TypeError('options must be an object');
            }
            // Read options in alphabetical order: roundingIncrement, roundingMode, smallestUnit.
            $increment = self::getRoundingIncrement($roundTo);
            $roundingMode = self::getRoundingMode($roundTo, 'halfExpand');
            $unit = self::getTemporalUnit(
                $roundTo,
                'smallestUnit',
                ['day', 'hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'],
                true,
            );
            if ($unit === 'day') {
                if ($increment !== 1) {
                    throw new RangeError('roundingIncrement for day must be 1');
                }
            } elseif ($increment > 1) {
                self::validateRoundingIncrement($unit, $increment);
            }
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            // Round using wall-clock time, not epoch ns.
            if ($unit === 'day') {
                // Round to actual day boundaries in the timezone (which may
                // not be 00:00 if midnight falls in a DST gap).
                $parts = self::epochNsToISOParts($ns, $tz);
                $startNs = self::startOfDayInTimeZone($parts['year'], $parts['month'], $parts['day'], $tz);
                self::validateInstantRange($startNs);
                $ny = $parts['year'];
                $nm = $parts['month'];
                $nd = $parts['day'] + 1;
                $dim = self::isoDaysInMonth($ny, $nm);
                if ($nd > $dim) {
                    $nd = 1;
                    $nm++;
                    if ($nm > 12) {
                        $nm = 1;
                        $ny++;
                    }
                }
                $endNs = self::startOfDayInTimeZone($ny, $nm, $nd, $tz);
                self::validateInstantRange($endNs);
                $dayNs = bcsub($endNs, $startNs, 0);
                $timeInDay = bcsub($ns, $startNs, 0);
                $rounded = self::roundNs($timeInDay, bcmul((string) $increment, $dayNs, 0), $roundingMode);
                $result = bcadd($startNs, $rounded, 0);
            } else {
                $unitNs = self::temporalUnitToNs($unit);
                $incrementNs = bcmul((string) $increment, $unitNs, 0);
                // Round wall-clock time, then convert back to epoch ns.
                $parts = self::epochNsToISOParts($ns, $tz);
                $roundedParts = self::roundISODateTime($parts, $incrementNs, $roundingMode, $tz);
                $result = self::isoDateTimeToEpochNs(
                    $roundedParts['year'],
                    $roundedParts['month'],
                    $roundedParts['day'],
                    $roundedParts['hour'],
                    $roundedParts['minute'],
                    $roundedParts['second'],
                    $roundedParts['millisecond'],
                    $roundedParts['microsecond'],
                    $roundedParts['nanosecond'],
                    $tz,
                );
            }
            self::validateInstantRange($result);
            return self::createZonedDateTimeObject($result, $tz, $cal);
        }, 1);

        $d('until', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $other = self::toZonedDateTime($args[0] ?? JsUndefined::instance());
            self::requireMatchingCalendars($this_, $other);
            $ns1 = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $ns2 = self::getSlotString($other, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $opts = self::getOptionsObject($args[1] ?? JsUndefined::instance());
            $newOpts = self::readDifferenceOptionsAlphabetical($opts, false);
            self::requireMatchingTimeZonesForCalendarUnits($this_, $other, $newOpts);
            return self::zonedDateTimeDifference($ns1, $ns2, $tz, $newOpts);
        }, 1);

        $d('since', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $other = self::toZonedDateTime($args[0] ?? JsUndefined::instance());
            self::requireMatchingCalendars($this_, $other);
            $ns1 = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $ns2 = self::getSlotString($other, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $opts = self::getOptionsObject($args[1] ?? JsUndefined::instance());
            $newOpts = self::readDifferenceOptionsAlphabetical($opts, true);
            self::requireMatchingTimeZonesForCalendarUnits($this_, $other, $newOpts);
            $dur = self::zonedDateTimeDifference($ns1, $ns2, $tz, $newOpts);
            return self::negateDuration($dur);
        }, 1);

        $d('equals', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $other = self::toZonedDateTime($args[0] ?? JsUndefined::instance());
            $ns1 = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $ns2 = self::getSlotString($other, '[[EpochNanoseconds]]');
            $tz1 = self::getSlotString($this_, '[[TimeZone]]');
            $tz2 = self::getSlotString($other, '[[TimeZone]]');
            $cal1 = self::getSlotString($this_, '[[Calendar]]');
            $cal2 = self::getSlotString($other, '[[Calendar]]');
            return new JsBoolean(
                bccomp($ns1, $ns2, 0) === 0
                && self::normalizeTimeZoneId($tz1) === self::normalizeTimeZoneId($tz2)
                && $cal1 === $cal2,
            );
        }, 1);

        $d('add', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $dur = self::toDuration($args[0] ?? JsUndefined::instance());
            $opts = self::getOptionsObject($args[1] ?? JsUndefined::instance());
            $overflow = self::getOverflow($opts);
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $result = self::zdtAddOrSubtract($ns, $tz, $dur, 1, $overflow);
            self::validateInstantRange($result);
            return self::createZonedDateTimeObject($result, $tz, $cal);
        }, 1);

        $d('subtract', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $dur = self::toDuration($args[0] ?? JsUndefined::instance());
            $opts = self::getOptionsObject($args[1] ?? JsUndefined::instance());
            $overflow = self::getOverflow($opts);
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $result = self::zdtAddOrSubtract($ns, $tz, $dur, -1, $overflow);
            self::validateInstantRange($result);
            return self::createZonedDateTimeObject($result, $tz, $cal);
        }, 1);

        // ZonedDateTime.prototype.toPlainYearMonth / toPlainMonthDay were
        // removed from the Temporal proposal in the June 2024 TC39 meeting.

        $d('getTimeZoneTransition', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $directionParam = $args[0] ?? JsUndefined::instance();
            // Per spec: undefined throws TypeError (direction is required).
            if ($directionParam instanceof JsUndefined) {
                throw new TypeError('getTimeZoneTransition requires a direction option');
            }
            // If it's a string, use it directly as direction.
            if ($directionParam instanceof JsString) {
                $dir = $directionParam->value;
                if ($dir !== 'next' && $dir !== 'previous') {
                    throw new RangeError("Invalid direction: {$dir}");
                }
            } else {
                // Otherwise it must be an object (GetOptionsObject).
                if (!$directionParam instanceof JsObject) {
                    throw new TypeError('getTimeZoneTransition options must be a string or object');
                }
                $dirV = $directionParam->get('direction');
                if ($dirV instanceof JsUndefined) {
                    throw new RangeError('direction is required');
                }
                if ($dirV instanceof \Phasis\Value\JsSymbol) {
                    throw new TypeError('direction cannot be a Symbol');
                }
                $dir = TypeConversion::toString($dirV);
                if ($dir !== 'next' && $dir !== 'previous') {
                    throw new RangeError("Invalid direction: {$dir}");
                }
            }
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            // Numeric offsets and UTC have no transitions.
            if ($tz === 'UTC' || $tz === 'GMT' || preg_match('/^[+-]\d{2}:\d{2}/', $tz)) {
                return JsNull::instance();
            }
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            // Truncate-toward-floor for negative ns so the epoch
            // second comparison remains correct under sub-second
            // offsets.
            $epochSecStr = bcdiv($ns, '1000000000', 0);
            $remainder = bcmod($ns, '1000000000');
            if (bccomp($ns, '0', 0) < 0 && $remainder !== '0') {
                $epochSecStr = bcsub($epochSecStr, '1', 0);
            }
            $epochSec = (int) $epochSecStr;
            try {
                $tzObj = self::resolveTimeZone($tz);
            } catch (\Throwable) {
                return JsNull::instance();
            }
            $found = null;
            // The Temporal.Instant range is ±8.64e21 ns ≈ ±274,000y.
            // We walk in 200-year chunks until we find a transition
            // or run off the end of the Instant range. Practically,
            // PHP's TZDB only has transitions back to ~1700, but
            // capping at ±5,000 years ensures the loop terminates
            // even for zones with no recorded transitions.
            $maxSec = 5000 * 365 * 86400;
            // For nanosecond-precision input, "next" means strictly
            // later than the input ns; "previous" means strictly
            // earlier. A transition at ts=N is at ns N*1e9, which
            // equals our input only when remainder is 0.
            // - For "next": skip transitions with ts <= epochSec.
            // - For "previous": skip transitions with ts >= epochSec
            //   (when remainder is 0); when remainder > 0 the same
            //   ts is technically less than our ns so it qualifies.
            if ($dir === 'next') {
                // Use a hard floor of year 1700 → +∞ since there are
                // no recorded transitions before that for any zone.
                $minProbeSec = -8520336000; // ~1700-01-01T00:00:00Z
                // For sub-second remainders we want transitions at ts >= epochSec+1.
                // For exact-second inputs we want ts > epochSec.
                // Probe starting at epochSec - 1 so PHP's "state-at-start" entry sits
                // before any transition AT epochSec, then transitions[1+] include it.
                $cur = max($epochSec - 1, $minProbeSec);
                $chunk = 200 * 365 * 86400;
                $bound = min(max($epochSec, 0) + $maxSec, PHP_INT_MAX - $chunk);
                while ($cur < $bound) {
                    $end = min($cur + $chunk, $bound);
                    $transitions = self::getTzTransitions($tz, $tzObj, $cur, $end);
                    if (count($transitions) > 1) {
                        for ($j = 1; $j < count($transitions); $j++) {
                            $t = $transitions[$j];
                            $tsNs = bcmul((string) $t['ts'], '1000000000', 0);
                            if (bccomp($tsNs, $ns, 0) <= 0) {
                                continue;
                            }
                            // Skip pseudo-transitions where the offset doesn't actually change.
                            $prev = $transitions[$j - 1];
                            if ($prev['offset'] === $t['offset']) {
                                continue;
                            }
                            $found = $t['ts'];
                            break 2;
                        }
                    }
                    $cur = $end + 1;
                }
            } else {
                // Probe window upper bound includes the input second
                // unconditionally. Strict "earlier than $ns" is
                // enforced by the full-nanosecond comparison below,
                // not by the second-grain window cutoff. The previous
                // version used a seconds-only predicate gated on
                // hasNonZeroSubSec, which collapsed three distinct
                // sub-second positions into two cases and let the
                // next transition leak in for the +1ns case crossing
                // a DST boundary (Europe/Berlin 2021 spring returned
                // for an input at 2020 autumn + 1ns).
                $cur = $epochSec;
                $chunk = 200 * 365 * 86400;
                $bound = max($epochSec - $maxSec, PHP_INT_MIN + $chunk);
                while ($cur > $bound) {
                    $start = max($cur - $chunk, $bound);
                    $transitions = self::getTzTransitions($tz, $tzObj, $start, $cur);
                    if (count($transitions) > 1) {
                        $candidate = null;
                        for ($j = 1; $j < count($transitions); $j++) {
                            $t = $transitions[$j];
                            $tsNs = bcmul((string) $t['ts'], '1000000000', 0);
                            if (bccomp($tsNs, $ns, 0) >= 0) {
                                continue;
                            }
                            $prev = $transitions[$j - 1];
                            if ($prev['offset'] === $t['offset']) {
                                continue;
                            }
                            $candidate = $t['ts'];
                        }
                        if ($candidate !== null) {
                            $found = $candidate;
                            break;
                        }
                    }
                    $cur = $start - 1;
                }
            }
            if ($found === null) {
                return JsNull::instance();
            }
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $foundNs = bcmul((string) $found, '1000000000', 0);
            // Reject if the found transition lies outside the
            // Instant range; per spec there's no transition beyond
            // the representable range.
            if (
                bccomp($foundNs, self::NS_MAX, 0) > 0
                || bccomp($foundNs, self::NS_MIN, 0) < 0
            ) {
                return JsNull::instance();
            }
            return self::createZonedDateTimeObject($foundNs, $tz, $cal);
        }, 1);

        self::setToStringTag($proto, 'Temporal.ZonedDateTime');
        self::installTemporalToPrimitive($proto, 'ZonedDateTime');

        $ctor = JsFunction::fromCallable('ZonedDateTime', function (JsValue $this_, array $args) use ($proto): JsValue {
            if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                throw new TypeError('Temporal.ZonedDateTime must be called with new');
            }
            self::applyNewTargetPrototype($this_, $proto);
            $nsArg = $args[0] ?? JsUndefined::instance();
            if (!$nsArg instanceof JsBigInt) {
                throw new TypeError('ZonedDateTime requires a BigInt epochNanoseconds');
            }
            $ns = $nsArg->value;
            self::validateInstantRange($ns);
            $tzArg = $args[1] ?? JsUndefined::instance();
            // Constructor uses canonicalizeTimeZone (rejects ISO strings), not toTemporalTimeZoneIdentifier.
            if (!($tzArg instanceof JsString)) {
                if ($tzArg instanceof JsObject && $tzArg->has('[[IsZonedDateTime]]')) {
                    $timeZone = self::getSlotString($tzArg, '[[TimeZone]]');
                } else {
                    throw new TypeError('Expected a string for TimeZone');
                }
            } else {
                $timeZone = self::canonicalizeTimeZone($tzArg->value);
            }
            $cal = 'iso8601';
            if (isset($args[2]) && !($args[2] instanceof JsUndefined)) {
                $cal = self::toCalendarSlotValue($args[2], false);
            }
            $this_->defineOwnProperty('[[EpochNanoseconds]]', PropertyDescriptor::data(new JsString($ns), false, false, false));
            $this_->defineOwnProperty('[[TimeZone]]', PropertyDescriptor::data(new JsString($timeZone), false, false, false));
            $this_->defineOwnProperty('[[Calendar]]', PropertyDescriptor::data(new JsString($cal), false, false, false));
            $this_->defineOwnProperty('[[IsZonedDateTime]]', PropertyDescriptor::data(new JsBoolean(true), false, false, false));
            return $this_;
        }, 2);
        $ctor->setConstructable();

        // ZonedDateTime.from(item [, options])
        $ctor->defineOwnProperty('from', PropertyDescriptor::data(
            JsFunction::fromCallable('from', function (JsValue $this_, array $args): JsValue {
                $item = $args[0] ?? JsUndefined::instance();
                $rawOptions = $args[1] ?? JsUndefined::instance();
                // For ZDT or string: parse first, then validate options.
                if ($item instanceof JsObject && $item->has('[[IsZonedDateTime]]')) {
                    $result = self::createZonedDateTimeObject(
                        self::getSlotString($item, '[[EpochNanoseconds]]'),
                        self::getSlotString($item, '[[TimeZone]]'),
                        self::getSlotString($item, '[[Calendar]]'),
                    );
                    $options = self::getOptionsObject($rawOptions);
                    // Validate options in alphabetical order: disambiguation, offset, overflow.
                    self::validateZonedDateTimeOptions($options);
                    return $result;
                }
                if ($item instanceof JsString) {
                    return self::zdtFromString($item->value, $rawOptions);
                }
                return self::toZonedDateTime($item, $rawOptions);
            }, 1),
            true,
            false,
            true,
        ));

        // ZonedDateTime.compare(one, two)
        $ctor->defineOwnProperty('compare', PropertyDescriptor::data(
            JsFunction::fromCallable('compare', function (JsValue $this_, array $args): JsValue {
                $one = self::toZonedDateTimeNs($args[0] ?? JsUndefined::instance());
                $two = self::toZonedDateTimeNs($args[1] ?? JsUndefined::instance());
                $cmp = bccomp($one, $two, 0);
                return JsNumber::of((float) $cmp);
            }, 2),
            true,
            false,
            true,
        ));

        $ctor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($ctor, true, false, true));

        $temporal->defineOwnProperty('ZonedDateTime', PropertyDescriptor::data($ctor, true, false, true));
        self::$zonedDateTimeProto = $proto;

        return $proto;
    }

    /** Convert an item to a ZonedDateTime. */
    private static function toZonedDateTime(JsValue $item, ?JsValue $rawOptions = null): JsObject
    {
        if ($item instanceof JsObject && $item->has('[[IsZonedDateTime]]')) {
            return $item;
        }
        if ($item instanceof JsString) {
            return self::parseZonedDateTimeString($item->value);
        }
        if ($item instanceof JsObject) {
            return self::zonedDateTimeFromPropertyBag($item, $rawOptions);
        }
        if ($item instanceof JsUndefined || $item instanceof JsNull) {
            throw new TypeError('Cannot convert undefined/null to ZonedDateTime');
        }
        if ($item instanceof JsNumber || $item instanceof \Phasis\Value\JsBigInt) {
            throw new TypeError('Cannot convert number to ZonedDateTime');
        }
        if ($item instanceof JsBoolean) {
            throw new TypeError('Cannot convert boolean to ZonedDateTime');
        }
        if ($item instanceof \Phasis\Value\JsSymbol) {
            throw new TypeError('Cannot convert Symbol to ZonedDateTime');
        }
        $str = TypeConversion::toString($item);
        return self::parseZonedDateTimeString($str);
    }

    /** Build a ZonedDateTime from a property bag object, reading fields in spec order. */
    private static function zonedDateTimeFromPropertyBag(JsObject $item, ?JsValue $rawOptions = null): JsObject
    {
        // Per spec: read calendar first, then fields in alphabetical order.
        // Each field is read and converted immediately (interleaved get + valueOf/toString).
        $cal = 'iso8601';
        $calV = $item->get('calendar');
        if (!($calV instanceof JsUndefined)) {
            $cal = self::toCalendarSlotValue($calV);
        }
        // PrepareTemporalFields: read and convert each field in alphabetical order.
        $dayV = $item->get('day');
        $d = ($dayV instanceof JsUndefined) ? null : self::toTemporalInteger($dayV, 'day');
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
            static $zdtErasUseEras = ['gregory', 'japanese', 'roc'];
            if (in_array($cal, $zdtErasUseEras, true) && $eraSet !== $eraYearSet) {
                throw new TypeError('era and eraYear must be provided together');
            }
        }
        $hourV = $item->get('hour');
        $h = ($hourV instanceof JsUndefined) ? 0 : self::toTemporalInteger($hourV, 'hour');
        $microsecondV = $item->get('microsecond');
        $us = ($microsecondV instanceof JsUndefined) ? 0 : self::toTemporalInteger($microsecondV, 'microsecond');
        $millisecondV = $item->get('millisecond');
        $ms = ($millisecondV instanceof JsUndefined) ? 0 : self::toTemporalInteger($millisecondV, 'millisecond');
        $minuteV = $item->get('minute');
        $min = ($minuteV instanceof JsUndefined) ? 0 : self::toTemporalInteger($minuteV, 'minute');
        $monthV = $item->get('month');
        $monthNum = ($monthV instanceof JsUndefined) ? null : self::toTemporalInteger($monthV, 'month');
        $monthCodeV = $item->get('monthCode');
        $mcStr = null;
        $mcParsed = null;
        if (!($monthCodeV instanceof JsUndefined)) {
            $mcStr = TypeConversion::toString($monthCodeV);
            $mcParsed = self::parseMonthCodeSyntax($mcStr);
        }
        $nanosecondV = $item->get('nanosecond');
        $ns = ($nanosecondV instanceof JsUndefined) ? 0 : self::toTemporalInteger($nanosecondV, 'nanosecond');
        $offsetProp = $item->get('offset');
        $offsetStr = null;
        if (!($offsetProp instanceof JsUndefined)) {
            if (
                $offsetProp instanceof JsNumber
                || $offsetProp instanceof JsBoolean
                || $offsetProp instanceof JsNull
                || $offsetProp instanceof \Phasis\Value\JsBigInt
            ) {
                throw new TypeError('ZonedDateTime offset property must be a string');
            }
            $offsetStr = TypeConversion::toString($offsetProp);
            if (!self::isValidOffsetString($offsetStr)) {
                throw new RangeError("Invalid offset string: {$offsetStr}");
            }
        }
        $secondV = $item->get('second');
        $s = ($secondV instanceof JsUndefined) ? 0 : self::toTemporalInteger($secondV, 'second');
        $tzV = $item->get('timeZone');
        $yearV = $item->get('year');
        $y = ($yearV instanceof JsUndefined) ? null : self::toTemporalInteger($yearV, 'year');
        // Validate required fields.
        if ($tzV instanceof JsUndefined) {
            throw new TypeError('ZonedDateTime from object requires timeZone');
        }
        $timeZone = self::toTemporalTimeZoneIdentifier($tzV);
        static $zdtEraDerivCals = ['gregory', 'japanese', 'roc'];
        if (
            $y === null
            && $eraYearNum !== null
            && in_array($cal, $zdtEraDerivCals, true)
        ) {
            $eraLower = $eraStr === null ? '' : strtolower($eraStr);
            $y = in_array($eraLower, ['bc', 'bce'], true)
                ? (1 - (int) $eraYearNum)
                : (int) $eraYearNum;
        }
        if ($y === null || $d === null) {
            throw new TypeError('ZonedDateTime from object requires year and day');
        }
        if ($monthNum === null && $mcParsed === null) {
            throw new TypeError('ZonedDateTime from object requires month or monthCode');
        }
        // Full monthCode range/calendar validation (after year type check).
        $mo = null;
        if ($mcParsed !== null) {
            [$mcMonth, $mcIsLeap] = $mcParsed;
            if ($mcMonth < 1 || $mcMonth > 12 || $mcIsLeap) {
                throw new RangeError("monthCode '{$mcStr}' is not valid for ISO 8601 calendar");
            }
            $mo = $mcMonth;
        }
        if ($mo === null) {
            $mo = $monthNum;
        } else {
            // If both month and monthCode are present, they must agree.
            if ($monthNum !== null && $monthNum !== $mo) {
                throw new RangeError("monthCode and month conflict: M" . str_pad((string) $mo, 2, '0', STR_PAD_LEFT) . " vs month {$monthNum}");
            }
        }
        // Validate options (in alphabetical order: disambiguation, offset, overflow).
        $overflow = 'constrain';
        $options = null;
        $offsetOpt = 'reject';
        $dis = 'compatible';
        if ($rawOptions !== null) {
            $options = self::getOptionsObject($rawOptions);
            if ($options instanceof JsObject) {
                $dv = $options->get('disambiguation');
                if (!($dv instanceof JsUndefined)) {
                    $dis = TypeConversion::toString($dv);
                    if (!in_array($dis, ['compatible', 'earlier', 'later', 'reject'], true)) {
                        throw new RangeError("Invalid disambiguation: {$dis}");
                    }
                }
                $offOpt = $options->get('offset');
                if (!($offOpt instanceof JsUndefined)) {
                    $offsetOpt = TypeConversion::toString($offOpt);
                    if (!in_array($offsetOpt, ['prefer', 'use', 'ignore', 'reject'], true)) {
                        throw new RangeError("Invalid offset option: {$offsetOpt}");
                    }
                }
                $overflow = self::getOverflow($options);
            }
        }
        // For non-ISO non-gregory calendars, convert calendar-native fields to
        // ISO via ICU before applying overflow / wall-time math.
        if ($cal !== 'iso8601' && !in_array($cal, ['gregory', 'roc', 'japanese'], true)) {
            $isoParts = self::calendarPartsToIso($cal, $y, $mcStr, $mo, $d);
            if ($isoParts !== null) {
                $y = $isoParts['year'];
                $mo = $isoParts['month'];
                $d = $isoParts['day'];
            }
        }
        // Apply overflow (constrain or reject) to date and time fields.
        if ($overflow === 'constrain') {
            [$y, $mo, $d] = self::constrainISODate($y, $mo, $d);
            [$h, $min, $s, $ms, $us, $ns] = self::constrainISOTime($h, $min, $s, $ms, $us, $ns);
        } else {
            if ($mo < 1 || $mo > 12) {
                throw new RangeError("Invalid month: {$mo}");
            }
            $dim = self::isoDaysInMonth($y, $mo);
            if ($d < 1 || $d > $dim) {
                throw new RangeError("Invalid day: {$d}");
            }
            self::rejectISOTime($h, $min, $s, $ms, $us, $ns);
        }
        if ($offsetStr !== null && $offsetOpt !== 'ignore') {
            $givenOffsetNs = self::parseOffsetToNs($offsetStr);
            // Find ANY candidate epoch ns where wall time + given offset
            // yields a valid time zone occurrence; this lets DST-fallback
            // pick the second occurrence when the user supplied its
            // offset.
            $candidates = self::getPossibleEpochNanoseconds($y, $mo, $d, $h, $min, $s, $ms, $us, $ns, $timeZone);
            $wallUtcNs = self::isoDateTimeToEpochNs($y, $mo, $d, $h, $min, $s, $ms, $us, $ns, 'UTC');
            $exactCandidate = null;
            foreach ($candidates as $candNs) {
                $candOffsetNs = (int) bcsub($wallUtcNs, $candNs, 0);
                if ($givenOffsetNs === $candOffsetNs) {
                    $exactCandidate = $candNs;
                    break;
                }
            }
            if ($exactCandidate !== null) {
                return self::createZonedDateTimeObject($exactCandidate, $timeZone, $cal);
            }
            if ($offsetOpt === 'reject') {
                throw new RangeError("offset property \"{$offsetStr}\" does not match time zone \"{$timeZone}\"");
            }
            if ($offsetOpt === 'use') {
                $normalizedOffset = self::normalizeOffset($givenOffsetNs);
                $epochNs = self::isoDateTimeToEpochNs($y, $mo, $d, $h, $min, $s, $ms, $us, $ns, $normalizedOffset);
                return self::createZonedDateTimeObject($epochNs, $timeZone, $cal);
            }
            // prefer: fall through to disambiguation in the named zone.
        }
        $epochFromWall = self::isoDateTimeToEpochNsDisambiguated(
            $y,
            $mo,
            $d,
            $h,
            $min,
            $s,
            $ms,
            $us,
            $ns,
            $timeZone,
            $dis,
        );
        return self::createZonedDateTimeObject($epochFromWall, $timeZone, $cal);
    }

    private static function toZonedDateTimeNs(JsValue $item): string
    {
        if ($item instanceof JsObject && $item->has('[[IsZonedDateTime]]')) {
            return self::getSlotString($item, '[[EpochNanoseconds]]');
        }
        $zdt = self::toZonedDateTime($item);
        return self::getSlotString($zdt, '[[EpochNanoseconds]]');
    }

    /** Parse a ZDT string from the from() method: parse first, then read options, then resolve. */
    private static function zdtFromString(string $str, JsValue $rawOptions): JsObject
    {
        // Phase 1: parse and validate syntax only (use 'ignore' to skip offset mismatch check).
        // This throws on invalid ISO strings BEFORE reading options (per spec).
        $result = self::parseZonedDateTimeString($str, 'ignore');
        // Phase 2: now read and validate options.
        $options = self::getOptionsObject($rawOptions);
        $offOptStr = 'reject';
        $disStr = 'compatible';
        if ($options instanceof JsObject) {
            $dv = $options->get('disambiguation');
            if (!($dv instanceof JsUndefined)) {
                $disStr = TypeConversion::toString($dv);
                if (!in_array($disStr, ['compatible', 'earlier', 'later', 'reject'], true)) {
                    throw new RangeError("Invalid disambiguation: {$disStr}");
                }
            }
            $offOpt = $options->get('offset');
            if (!($offOpt instanceof JsUndefined)) {
                $offOptStr = TypeConversion::toString($offOpt);
                if (!in_array($offOptStr, ['prefer', 'use', 'ignore', 'reject'], true)) {
                    throw new RangeError("Invalid offset option: {$offOptStr}");
                }
            }
            self::getOverflow($options);
        }
        // Phase 3: re-parse with the actual offset option (handles reject/ignore/use/prefer).
        if ($offOptStr !== 'ignore' || $disStr !== 'compatible') {
            $result = self::parseZonedDateTimeString($str, $offOptStr, $disStr);
        }
        // Wall-clock range validation for prefer/reject is handled by the
        // parseZonedDateTimeString + validateInstantRange chain.
        return $result;
    }

    private static function parseZonedDateTimeString(string $str, string $offsetOption = 'reject', string $disambiguation = 'compatible'): JsObject
    {
        [$str, $cal] = self::normalizeTemporalString($str);
        // Parse the datetime part, optional offset, and timezone annotation.
        $datePart = '([+-]?\d{4,6})(?:-(\d{2})-(\d{2})|(\d{2})(\d{2}))';
        $timePart = '(?:[Tt ](\d{2})(?::?(\d{2})(?::?(\d{2})(?:[.,](\d{1,9}))?)?)?)?';
        $tzPart = '([Zz]|[+-]\d{2}(?::?\d{2}(?::?\d{2}(?:[.,]\d{1,9})?)?)?)?';
        $annPart = '(?:\\[([^\\]]+)\\])?';
        $annsEnd = '(?:\\[[^\\]]+\\])*$';
        $pattern = "/^{$datePart}{$timePart}{$tzPart}{$annPart}{$annsEnd}/";
        if (!preg_match($pattern, $str, $m)) {
            throw new RangeError("Invalid ZonedDateTime string: {$str}");
        }
        $yearStr = $m[1];
        // Reject minus zero year (-000000).
        if (preg_match('/^-0{4,6}$/', $yearStr)) {
            throw new RangeError("Negative zero year is not allowed: {$str}");
        }
        $year = (int) $yearStr;
        $month = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : (int) ($m[4] ?? 0);
        $day = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (int) ($m[5] ?? 0);
        $hour = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0;
        $min = isset($m[7]) && $m[7] !== '' ? (int) $m[7] : 0;
        $sec = isset($m[8]) && $m[8] !== '' ? (int) $m[8] : 0;
        if ($sec === 60) {
            $sec = 59;
        }
        // Validate ISO date/time ranges for string input (overflow constrain does not apply to strings).
        if ($month < 1 || $month > 12) {
            throw new RangeError("Invalid month {$month} in ZonedDateTime string: {$str}");
        }
        $dim = self::isoDaysInMonth($year, $month);
        if ($day < 1 || $day > $dim) {
            throw new RangeError("Invalid day {$day} in ZonedDateTime string: {$str}");
        }
        if ($hour > 23 || $min > 59 || $sec > 59) {
            throw new RangeError("Invalid time in ZonedDateTime string: {$str}");
        }
        $frac = isset($m[9]) && $m[9] !== '' ? str_pad($m[9], 9, '0') : '000000000';
        $ms = (int) substr($frac, 0, 3);
        $us = (int) substr($frac, 3, 3);
        $ns = (int) substr($frac, 6, 3);
        $offset = isset($m[10]) && $m[10] !== '' ? $m[10] : null;
        $hasTimePart = isset($m[6]) && $m[6] !== '';
        if ($offset !== null && !$hasTimePart) {
            throw new RangeError("UTC offset without time is not valid for ZonedDateTime: {$str}");
        }
        $annotation = $m[11] ?? null;
        // ZonedDateTime strings require a bracketed timezone annotation.
        if ($annotation === null || str_contains($annotation, '=')) {
            throw new RangeError("Invalid ZonedDateTime string (no bracketed timezone annotation): {$str}");
        }
        // Strip the critical flag '!' prefix from the annotation.
        if (str_starts_with($annotation, '!')) {
            $annotation = substr($annotation, 1);
        }
        // Normalize the annotation to a timezone identifier.
        $upper = strtoupper($annotation);
        if ($upper === 'UTC' || $upper === 'GMT') {
            $timeZone = $upper;
        } else {
            // Resolve case-insensitively against the full TZDB list
            // (including legacy Links like GMT+0, Zulu) BEFORE
            // calling new DateTimeZone(), since PHP would interpret
            // "GMT+0" as the +00:00 offset.
            $caseMatched = self::canonicalizeIanaTimeZoneCase($annotation);
            if ($caseMatched !== $annotation || self::ianaTimeZoneExists($annotation)) {
                $timeZone = $caseMatched;
            } else {
                try {
                    $tzObj = new \DateTimeZone($annotation);
                    $timeZone = self::canonicalizeIanaTimeZoneCase($tzObj->getName());
                } catch (\Throwable) {
                    $timeZone = $annotation;
                }
            }
        }
        if ($offset !== null && $offsetOption !== 'ignore') {
            $isZ = strtoupper($offset) === 'Z';
            if ($isZ) {
                // Z means exact UTC instant; annotation is the named timezone. No offset validation.
                $epochNs = self::isoDateTimeToEpochNs($year, $month, $day, $hour, $min, $sec, $ms, $us, $ns, 'UTC');
            } else {
                // Non-Z numeric offset: validate or use based on offsetOption.
                $givenOffsetNs = self::parseOffsetToNs($offset);
                if (self::isFixedOffset($timeZone)) {
                    $annotationOffsetNs = self::parseOffsetToNs($timeZone);
                    if ($givenOffsetNs !== $annotationOffsetNs) {
                        if ($offsetOption === 'reject') {
                            throw new RangeError("offset does not match the time zone annotation for ZonedDateTime string: {$str}");
                        }
                    }
                    if ($offsetOption === 'prefer' || $offsetOption === 'reject') {
                        $wallUtcNs = self::isoDateTimeToEpochNs($year, $month, $day, $hour, $min, $sec, $ms, $us, $ns, 'UTC');
                        $nsPerDayMinusOne = bcsub('86400000000000', '1', 0);
                        $upperBound = bcadd(self::NS_MAX, $nsPerDayMinusOne, 0);
                        if (bccomp($wallUtcNs, self::NS_MIN, 0) < 0 || bccomp($wallUtcNs, $upperBound, 0) > 0) {
                            throw new RangeError("wall-clock time of \"{$str}\" is outside the representable range of ZonedDateTime");
                        }
                    }
                    $normalizedOffset = self::normalizeOffset($givenOffsetNs);
                    $epochNs = self::isoDateTimeToEpochNs($year, $month, $day, $hour, $min, $sec, $ms, $us, $ns, $normalizedOffset);
                } else {
                    // Named time zone: check if offset matches any wall-time interpretation in the zone.
                    $wallUtcNs = self::isoDateTimeToEpochNs($year, $month, $day, $hour, $min, $sec, $ms, $us, $ns, 'UTC');
                    $candidates = self::getPossibleEpochNanoseconds($year, $month, $day, $hour, $min, $sec, $ms, $us, $ns, $timeZone);
                    $minutePrecision = (bool) preg_match('/^[+-]\d{2}:\d{2}$/', $offset)
                        || (bool) preg_match('/^[+-]\d{4}$/', $offset);
                    $exactCandidate = null;
                    $fuzzyCandidate = null;
                    foreach ($candidates as $candNs) {
                        $candOffsetNs = (int) bcsub($wallUtcNs, $candNs, 0);
                        if ($givenOffsetNs === $candOffsetNs) {
                            $exactCandidate = $candNs;
                            break;
                        }
                        if ($minutePrecision && $fuzzyCandidate === null) {
                            $absSec = abs(intdiv($candOffsetNs, 1_000_000_000));
                            $sign = $candOffsetNs >= 0 ? 1 : -1;
                            $roundedSec = (int) round($absSec / 60, 0, PHP_ROUND_HALF_UP) * 60;
                            $roundedNs = $sign * $roundedSec * 1_000_000_000;
                            if ($givenOffsetNs === $roundedNs) {
                                $fuzzyCandidate = $candNs;
                            }
                        }
                    }
                    $matchCandidate = $exactCandidate ?? $fuzzyCandidate;
                    if ($matchCandidate === null && $offsetOption === 'reject') {
                        throw new RangeError("offset does not match the time zone annotation for ZonedDateTime string: {$str}");
                    }
                    if ($offsetOption === 'prefer' || $offsetOption === 'reject') {
                        $nsPerDayMinusOne = bcsub('86400000000000', '1', 0);
                        $upperBound = bcadd(self::NS_MAX, $nsPerDayMinusOne, 0);
                        if (bccomp($wallUtcNs, self::NS_MIN, 0) < 0 || bccomp($wallUtcNs, $upperBound, 0) > 0) {
                            throw new RangeError("wall-clock time of \"{$str}\" is outside the representable range of ZonedDateTime");
                        }
                    }
                    if ($offsetOption === 'use') {
                        $normalizedOffset = self::normalizeOffset($givenOffsetNs);
                        $epochNs = self::isoDateTimeToEpochNs($year, $month, $day, $hour, $min, $sec, $ms, $us, $ns, $normalizedOffset);
                    } elseif ($matchCandidate !== null) {
                        $epochNs = $matchCandidate;
                    } else {
                        // No matching offset under prefer: spec says
                        // fall back to wall-time disambiguation in the
                        // named time zone (rather than apply the bogus
                        // offset directly).
                        $epochNs = self::isoDateTimeToEpochNsDisambiguated(
                            $year,
                            $month,
                            $day,
                            $hour,
                            $min,
                            $sec,
                            $ms,
                            $us,
                            $ns,
                            $timeZone,
                            $disambiguation,
                        );
                    }
                }
            }
        } else {
            // No offset or ignore: use wall time in the annotation timezone.
            // Date-only strings: use the actual start of day in the zone, which
            // may not be 00:00 if midnight falls in a DST gap (e.g. America/Toronto
            // 1919-03-31, where the day starts at 00:30).
            if (!$hasTimePart) {
                $epochNs = self::startOfDayInTimeZone($year, $month, $day, $timeZone);
            } else {
                $epochNs = self::isoDateTimeToEpochNsDisambiguated(
                    $year,
                    $month,
                    $day,
                    $hour,
                    $min,
                    $sec,
                    $ms,
                    $us,
                    $ns,
                    $timeZone,
                    $disambiguation,
                );
            }
        }
        return self::createZonedDateTimeObject($epochNs, $timeZone, $cal);
    }

    /**
     * Apply a Duration to a ZonedDateTime using calendar-then-time semantics.
     * Returns the resulting epoch ns. Sign +1 adds, -1 subtracts. Years,
     * months, weeks, and days are calendar arithmetic on the wall time;
     * hours and below are added as nanoseconds afterward.
     */
    /**
     * ZDT.add / ZDT.subtract shared core. Applies years/months/weeks/days
     * as wall-time calendar arithmetic (with disambiguation), then
     * applies time fields (hours and below) as nanosecond offsets.
     * Without this, days collapse to 24-hour blocks and DST gaps /
     * date-line transitions (Pacific/Apia 2011) skew by one day.
     */
    private static function zdtAddOrSubtract(string $epochNs, string $tz, JsValue $dur, int $sign, string $overflow): string
    {
        $parts = self::epochNsToISOParts($epochNs, $tz);
        $y = $parts['year'] + $sign * self::getDurationField($dur, 'years');
        $m = $parts['month'] + $sign * self::getDurationField($dur, 'months');
        while ($m > 12) {
            $m -= 12;
            $y++;
        }
        while ($m < 1) {
            $m += 12;
            $y--;
        }
        $weeksDays = $sign * (
            self::getDurationField($dur, 'weeks') * 7
            + self::getDurationField($dur, 'days')
        );
        $dd = $parts['day'] + $weeksDays;
        // Constrain day to the new month's max BEFORE the day-shift loop
        // so years/months arithmetic from a 31-day month into a 30-day
        // month doesn't carry the extra day forward.
        $maxDay = self::isoDaysInMonth($y, $m);
        if ($parts['day'] > $maxDay) {
            if ($overflow === 'reject') {
                throw new RangeError("Day {$parts['day']} is out of range for month {$m} in year {$y}");
            }
            $dd = $maxDay + $weeksDays;
        }
        // Normalize day overflow into the calendar.
        while (true) {
            if ($dd < 1) {
                $m--;
                if ($m < 1) {
                    $m = 12;
                    $y--;
                }
                $dd += self::isoDaysInMonth($y, $m);
                continue;
            }
            $dim = self::isoDaysInMonth($y, $m);
            if ($dd > $dim) {
                $dd -= $dim;
                $m++;
                if ($m > 12) {
                    $m = 1;
                    $y++;
                }
                continue;
            }
            break;
        }
        $intermediateNs = self::isoDateTimeToEpochNs(
            $y,
            $m,
            $dd,
            $parts['hour'],
            $parts['minute'],
            $parts['second'],
            $parts['millisecond'],
            $parts['microsecond'],
            $parts['nanosecond'],
            $tz,
        );
        $subDayNs = self::durationToTotalNs(
            self::createDurationObject(
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
            )
        );
        if ($sign > 0) {
            return bcadd($intermediateNs, $subDayNs, 0);
        }
        return bcsub($intermediateNs, $subDayNs, 0);
    }

    private static function addDurationToZdt(JsObject $zdt, JsValue $dur, int $sign, string $overflow = 'constrain'): string
    {
        $ns = self::getSlotString($zdt, '[[EpochNanoseconds]]');
        $tz = self::getSlotString($zdt, '[[TimeZone]]');
        $parts = self::epochNsToISOParts($ns, $tz);
        $y = $parts['year'] + $sign * self::getDurationField($dur, 'years');
        $m = $parts['month'] + $sign * self::getDurationField($dur, 'months');
        while ($m > 12) {
            $m -= 12;
            $y++;
        }
        while ($m < 1) {
            $m += 12;
            $y--;
        }
        $weeksDays = $sign * (
            self::getDurationField($dur, 'weeks') * 7
            + self::getDurationField($dur, 'days')
        );
        $dd = $parts['day'] + $weeksDays;
        // Normalize day overflow into the calendar.
        while (true) {
            if ($dd < 1) {
                $m--;
                if ($m < 1) {
                    $m = 12;
                    $y--;
                }
                $dd += self::isoDaysInMonth($y, $m);
                continue;
            }
            $dim = self::isoDaysInMonth($y, $m);
            if ($dd > $dim) {
                $dd -= $dim;
                $m++;
                if ($m > 12) {
                    $m = 1;
                    $y++;
                }
                continue;
            }
            break;
        }
        // Constrain only when years/months arithmetic produced a day past the
        // new month's end (the day-shifting loop already left $dd in range,
        // so this is a no-op except when no weeks/days were applied and the
        // year/month change pushed us to a shorter month).
        $maxDay = self::isoDaysInMonth($y, $m);
        if ($dd > $maxDay) {
            if ($overflow === 'reject') {
                throw new RangeError("Day {$dd} is out of range for month {$m} in year {$y}");
            }
            $dd = $maxDay;
        }
        $intermediateNs = self::isoDateTimeToEpochNs(
            $y,
            $m,
            $dd,
            $parts['hour'],
            $parts['minute'],
            $parts['second'],
            $parts['millisecond'],
            $parts['microsecond'],
            $parts['nanosecond'],
            $tz,
        );
        $subDayNs = self::durationToTotalNs(
            self::createDurationObject(
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
            )
        );
        if ($sign > 0) {
            $result = bcadd($intermediateNs, $subDayNs, 0);
        } else {
            $result = bcsub($intermediateNs, $subDayNs, 0);
        }
        self::validateInstantRange($result);
        return $result;
    }

    /**
     * Compute ZDT-relative day count between two epochs. Whole days are
     * advanced by adding one calendar day at a time (preserving wall
     * time, so each step is variable-length on DST days). The fractional
     * remainder is divided by the wall-day length of the target's
     * calendar date (00:00 of its date to 00:00 of the next date).
     */
    private static function zdtDeltaInDays(JsObject $zdt, string $endNs, string $startNs): float
    {
        $sign = bccomp($endNs, $startNs, 0);
        if ($sign === 0) {
            return 0.0;
        }
        $signMul = $sign > 0 ? 1 : -1;
        // Walk integer cal days from $zdt with wall-time preserved each step,
        // so that DST gaps/folds change the boundary lengths but the anchor
        // wall doesn't drift. The previous chain-of-cur approach inherited
        // the constrained wall and produced a 24h boundary on a 23h day.
        $maxIter = 400 * 366;
        $days = 0;
        for ($i = 1; $i < $maxIter; $i++) {
            $stepDur = self::createDurationObject(
                0,
                0,
                0,
                $signMul * $i,
                0,
                0,
                0,
                0,
                0,
                0,
            );
            $stepNs = self::addDurationToZdt($zdt, $stepDur, 1, 'constrain');
            $cmp = bccomp($stepNs, $endNs, 0);
            $passes = $signMul > 0 ? $cmp > 0 : $cmp < 0;
            if ($passes) {
                break;
            }
            $days = $i;
            if ($cmp === 0) {
                return (float) ($signMul * $days);
            }
        }
        $signedDays = $signMul * $days;
        $startStepDur = self::createDurationObject(
            0,
            0,
            0,
            $signedDays,
            0,
            0,
            0,
            0,
            0,
            0,
        );
        $startStepNs = self::addDurationToZdt($zdt, $startStepDur, 1, 'constrain');
        $nextStepDur = self::createDurationObject(
            0,
            0,
            0,
            $signedDays + $signMul,
            0,
            0,
            0,
            0,
            0,
            0,
        );
        $nextStepNs = self::addDurationToZdt($zdt, $nextStepDur, 1, 'constrain');
        $dayLenNs = bcsub($nextStepNs, $startStepNs, 0);
        $absDayLen = bccomp($dayLenNs, '0', 0) < 0 ? substr($dayLenNs, 1) : $dayLenNs;
        if (bccomp($absDayLen, '0', 0) === 0) {
            return (float) $signedDays;
        }
        $progressNs = bcsub($endNs, $startStepNs, 0);
        $absProgress = bccomp($progressNs, '0', 0) < 0 ? substr($progressNs, 1) : $progressNs;
        $frac = (float) bcdiv($absProgress, $absDayLen, 25);
        return (float) $signedDays + (float) $signMul * $frac;
    }

    /** True if the given IANA zone has a real (offset-changing) transition
     * within (startNs, endNs]. Used to decide whether DST-aware day arithmetic
     * is needed; unchanged offsets bypass the slow path. */
    private static function tzHasTransitionBetween(string $tz, string $startNs, string $endNs): bool
    {
        // Fixed-offset zones (e.g. "-0700", "+05:30") have no DST, so no
        // transitions can occur. Skip the lookup — DateTimeZone::getTransitions
        // returns false for these and would crash count().
        if (self::isFixedOffset($tz)) {
            return false;
        }
        try {
            $tzObj = self::resolveTimeZone($tz);
        } catch (\Throwable) {
            return false;
        }
        $startSec = (int) bcdiv($startNs, '1000000000', 0);
        $endSec = (int) bcdiv($endNs, '1000000000', 0);
        if ($startSec > $endSec) {
            $tmp = $startSec;
            $startSec = $endSec;
            $endSec = $tmp;
        }
        // Widen by 1 second on each side: getTransitions excludes transitions
        // that fall exactly on the boundary, but we still need to detect a
        // DST flip whose instant is the user-supplied ns1 or ns2.
        $transitions = $tzObj->getTransitions($startSec - 1, $endSec + 1);
        if (count($transitions) < 2) {
            return false;
        }
        for ($j = 1; $j < count($transitions); $j++) {
            $prev = $transitions[$j - 1];
            $cur = $transitions[$j];
            if ($cur['offset'] !== $prev['offset']) {
                return true;
            }
        }
        return false;
    }

    /** Find the first instant on the given calendar day in the time zone. */
    private static function startOfDayInTimeZone(int $year, int $month, int $day, string $timeZone): string
    {
        if (self::isFixedOffset($timeZone)) {
            return self::isoDateTimeToEpochNs($year, $month, $day, 0, 0, 0, 0, 0, 0, $timeZone);
        }
        $candidates = self::getPossibleEpochNanoseconds($year, $month, $day, 0, 0, 0, 0, 0, 0, $timeZone);
        if (count($candidates) > 0) {
            // Return the earliest instant.
            $first = $candidates[0];
            foreach ($candidates as $c) {
                if (bccomp($c, $first, 0) < 0) {
                    $first = $c;
                }
            }
            return $first;
        }
        // No valid midnight (gap). Find the transition that ended the gap; that's the start of day.
        try {
            $tz = self::resolveTimeZone($timeZone);
        } catch (\Throwable) {
            return self::isoDateTimeToEpochNsDisambiguated($year, $month, $day, 0, 0, 0, 0, 0, 0, $timeZone, 'compatible');
        }
        // Search for transitions in (-12h, +12h) window around midnight.
        $dayUtcSec = bcadd(bcmul((string) self::isoDateToDays($year, $month, $day), '86400', 0), '0', 0);
        $startSec = (int) bcsub($dayUtcSec, '43200', 0);
        $endSec = (int) bcadd($dayUtcSec, '43200', 0);
        $transitions = $tz->getTransitions($startSec, $endSec);
        // Find a transition whose post-transition wall time falls within this calendar day.
        foreach ($transitions as $t) {
            if ($t['ts'] >= $startSec && $t['ts'] <= $endSec) {
                $tns = bcmul((string) $t['ts'], '1000000000', 0);
                $offsetAtTNs = $t['offset'] * 1_000_000_000;
                $wallUtcEquivNs = bcadd($tns, (string) $offsetAtTNs, 0);
                $dayStartUtcNs = bcmul($dayUtcSec, '1000000000', 0);
                $dayEndUtcNs = bcadd($dayStartUtcNs, '86400000000000', 0);
                if (bccomp($wallUtcEquivNs, $dayStartUtcNs, 0) >= 0 && bccomp($wallUtcEquivNs, $dayEndUtcNs, 0) < 0) {
                    return $tns;
                }
            }
        }
        return self::isoDateTimeToEpochNsDisambiguated($year, $month, $day, 0, 0, 0, 0, 0, 0, $timeZone, 'compatible');
    }

    /**
     * Return all possible epoch nanoseconds for the given wall clock components
     * in the given time zone. Empty when the wall time falls in a DST gap; one
     * entry for normal times; two for folds.
     *
     * @return list<string>
     */
    private static function getPossibleEpochNanoseconds(
        int $y,
        int $m,
        int $d,
        int $h,
        int $min,
        int $s,
        int $ms,
        int $us,
        int $ns,
        string $tz,
    ): array {
        $nsPart = (int) ($ms * 1000000 + $us * 1000 + $ns);
        $wallUtcSec = bcadd(
            bcmul((string) self::isoDateToDays($y, $m, $d), '86400', 0),
            (string) ($h * 3600 + $min * 60 + $s),
            0,
        );
        try {
            $tzObj = self::resolveTimeZone($tz);
            $beforeSec = bcsub($wallUtcSec, '43200', 0);
            $afterSec = bcadd($wallUtcSec, '43200', 0);
            $offBefore = (int) (new \DateTimeImmutable('@' . $beforeSec))
                ->setTimezone($tzObj)->format('Z');
            $offAfter = (int) (new \DateTimeImmutable('@' . $afterSec))
                ->setTimezone($tzObj)->format('Z');
        } catch (\Throwable) {
            return [self::isoDateTimeToEpochNs($y, $m, $d, $h, $min, $s, $ms, $us, $ns, $tz)];
        }
        if ($offBefore === $offAfter) {
            $epochSec = bcsub($wallUtcSec, (string) $offBefore, 0);
            return [bcadd(bcmul($epochSec, '1000000000', 0), (string) $nsPart, 0)];
        }
        $epochWithBefore = bcsub($wallUtcSec, (string) $offBefore, 0);
        $epochWithAfter = bcsub($wallUtcSec, (string) $offAfter, 0);
        try {
            $actualAtBefore = (int) (new \DateTimeImmutable('@' . $epochWithBefore))
                ->setTimezone($tzObj)->format('Z');
            $actualAtAfter = (int) (new \DateTimeImmutable('@' . $epochWithAfter))
                ->setTimezone($tzObj)->format('Z');
        } catch (\Throwable) {
            return [];
        }
        $beforeValid = $actualAtBefore === $offBefore;
        $afterValid = $actualAtAfter === $offAfter;
        $candidates = [];
        if ($beforeValid) {
            $candidates[] = bcadd(bcmul($epochWithBefore, '1000000000', 0), (string) $nsPart, 0);
        }
        if ($afterValid) {
            $candidates[] = bcadd(bcmul($epochWithAfter, '1000000000', 0), (string) $nsPart, 0);
        }
        return $candidates;
    }

    private static function createZonedDateTimeObject(string $ns, string $timeZone, string $cal): JsObject
    {
        self::validateInstantRange($ns);
        $obj = new JsObject(self::$zonedDateTimeProto);
        $obj->defineOwnProperty('[[EpochNanoseconds]]', PropertyDescriptor::data(new JsString($ns), false, false, false));
        $obj->defineOwnProperty('[[TimeZone]]', PropertyDescriptor::data(new JsString($timeZone), false, false, false));
        $obj->defineOwnProperty('[[Calendar]]', PropertyDescriptor::data(new JsString($cal), false, false, false));
        $obj->defineOwnProperty('[[IsZonedDateTime]]', PropertyDescriptor::data(new JsBoolean(true), false, false, false));
        return $obj;
    }

    /** Convert a timezone argument (string, Temporal type, or string-convertible) to a timezone identifier. */
    private static function toTemporalTimeZoneIdentifier(JsValue $item): string
    {
        // Per spec: if it's an Object, check for ZonedDateTime brand, otherwise throw.
        if ($item instanceof JsObject) {
            if ($item->has('[[IsZonedDateTime]]')) {
                return self::getSlotString($item, '[[TimeZone]]');
            }
            throw new TypeError('Expected a string for TimeZone');
        }
        // If it's not a String, throw TypeError.
        if (!($item instanceof JsString)) {
            throw new TypeError('Expected a string for TimeZone');
        }
        $str = $item->value;
        return self::parseTemporalTimeZoneString($str);
    }

    /** Canonicalize a time zone identifier. Only accepts IANA names and UTC offsets. Rejects ISO datetime strings. */
    private static function canonicalizeTimeZone(string $str): string
    {
        if ($str === '') {
            throw new RangeError('empty string does not convert to a valid time zone');
        }
        if (str_contains($str, "\xE2\x88\x92")) {
            throw new RangeError("Non-ASCII minus sign is not acceptable");
        }
        // IANA timezone name.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_+\-\/]*$/', $str)) {
            $upper = strtoupper($str);
            if ($upper === 'UTC' || $upper === 'GMT') {
                return $upper;
            }
            // Reject the legacy 3-letter abbreviations that ICU/Java
            // accept but IANA doesn't (ACT, AET, BST, etc.). These
            // would otherwise be interpreted by PHP DateTimeZone as
            // historical BC entries.
            static $invalidLegacy = [
                'ACT', 'AET', 'AGT', 'ART', 'AST', 'BET', 'BST',
                'CAT', 'CNT', 'CST', 'CTT', 'EAT', 'ECT', 'IET',
                'IST', 'JST', 'MIT', 'NET', 'NST', 'PLT', 'PNT',
                'PRT', 'PST', 'SST', 'VST',
            ];
            if (in_array($upper, $invalidLegacy, true)) {
                throw new RangeError("Invalid time zone: {$str}");
            }
            // Resolve case-insensitively against the full TZDB list
            // (including legacy Links like GMT+0, Zulu) BEFORE
            // calling new DateTimeZone($str), since PHP's parser
            // would interpret "GMT+0" as the +00:00 offset.
            $caseMatched = self::canonicalizeIanaTimeZoneCase($str);
            if ($caseMatched !== $str || self::ianaTimeZoneExists($str)) {
                return $caseMatched;
            }
            try {
                $tz = new \DateTimeZone($str);
                $name = $tz->getName();
                return self::canonicalizeIanaTimeZoneCase($name);
            } catch (\Throwable) {
                throw new RangeError("Invalid time zone: {$str}");
            }
        }
        // UTC offset: +HH, +HH:MM or -HH:MM (no seconds).
        if (preg_match('/^[+-](\d{2})(?::?(\d{2}))?$/', $str, $m)) {
            $h = (int) $m[1];
            $min = isset($m[2]) ? (int) $m[2] : 0;
            if ($h > 23 || $min > 59) {
                throw new RangeError("Invalid UTC offset: {$str}");
            }
            $sign = $str[0];
            return "{$sign}" . str_pad((string) $h, 2, '0', STR_PAD_LEFT)
                . ':' . str_pad((string) $min, 2, '0', STR_PAD_LEFT);
        }
        throw new RangeError("Invalid time zone: {$str}");
    }

    private static function ianaTimeZoneExists(string $name): bool
    {
        return self::canonicalizeIanaTimeZoneCase($name) !== $name
            || self::canonicalizeIanaTimeZoneCase(strtolower($name)) !== strtolower($name);
    }

    /**
     * Map a case-insensitive IANA timezone name to its canonical
     * form. Walks `DateTimeZone::listIdentifiers(ALL_WITH_BC)` so
     * legacy Link names ("GMT+0", "Zulu", "America/Buenos_Aires"
     * etc.) round-trip with their input casing.
     */
    private static function canonicalizeIanaTimeZoneCase(string $name): string
    {
        static $caseMap = null;
        if ($caseMap === null) {
            $caseMap = [];
            $allConst = defined('DateTimeZone::ALL_WITH_BC')
                ? \DateTimeZone::ALL_WITH_BC
                : 4095;
            foreach (\DateTimeZone::listIdentifiers($allConst) as $id) {
                $caseMap[strtolower($id)] = $id;
            }
            // Etc/GMT±N for offsets aren't always exposed via
            // listIdentifiers, so add them explicitly.
            for ($n = 1; $n <= 14; $n++) {
                $plus = "Etc/GMT+{$n}";
                $minus = "Etc/GMT-{$n}";
                $caseMap[strtolower($plus)] = $plus;
                $caseMap[strtolower($minus)] = $minus;
            }
            $caseMap['etc/gmt'] = 'Etc/GMT';
            $caseMap['utc'] = 'UTC';
            $caseMap['gmt'] = 'GMT';
        }
        $lower = strtolower($name);
        return $caseMap[$lower] ?? $name;
    }

    /** Parse a timezone string. Accepts IANA names, UTC offsets, or datetime strings with TZ annotation. */
    private static function parseTemporalTimeZoneString(string $str): string
    {
        if ($str === '') {
            throw new RangeError('empty string does not convert to a valid ISO string');
        }
        // Reject Unicode minus sign.
        if (str_contains($str, "\xE2\x88\x92")) {
            throw new RangeError("Non-ASCII minus sign is not acceptable");
        }
        // Simple IANA timezone name (e.g. UTC, America/New_York).
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_+\-\/]*$/', $str)) {
            // Case-insensitive match for UTC/GMT.
            $upper = strtoupper($str);
            if ($upper === 'UTC' || $upper === 'GMT') {
                return $upper;
            }
            // Reject ICU/Java-only legacy 3-letter abbreviations.
            static $invalidLegacyAbbr = [
                'ACT', 'AET', 'AGT', 'ART', 'AST', 'BET', 'BST',
                'CAT', 'CNT', 'CST', 'CTT', 'EAT', 'ECT', 'IET',
                'IST', 'JST', 'MIT', 'NET', 'NST', 'PLT', 'PNT',
                'PRT', 'PST', 'SST', 'VST',
            ];
            if (in_array($upper, $invalidLegacyAbbr, true)) {
                throw new RangeError("Invalid time zone: {$str}");
            }
            // Resolve case-insensitively against the TZDB ALL_WITH_BC
            // list before delegating to DateTimeZone (so 'GMT+0',
            // 'Zulu', etc. don't fall through to numeric parsing).
            $caseMatched = self::canonicalizeIanaTimeZoneCase($str);
            if ($caseMatched !== $str || self::ianaTimeZoneExists($str)) {
                return $caseMatched;
            }
            try {
                $tz = new \DateTimeZone($str);
                return self::canonicalizeIanaTimeZoneCase($tz->getName());
            } catch (\Throwable) {
                throw new RangeError("Invalid time zone: {$str}");
            }
        }
        // UTC offset: +HH, +HH:MM or -HH:MM (no seconds).
        if (preg_match('/^[+-](\d{2})(?::?(\d{2}))?$/', $str, $m)) {
            $h = (int) $m[1];
            $min = isset($m[2]) ? (int) $m[2] : 0;
            if ($h > 23 || $min > 59) {
                throw new RangeError("Invalid UTC offset: {$str}");
            }
            // Normalize to +HH:MM.
            $sign = $str[0];
            return "{$sign}" . str_pad((string) $h, 2, '0', STR_PAD_LEFT)
                . ':' . str_pad((string) $min, 2, '0', STR_PAD_LEFT);
        }
        // Reject sub-minute offsets.
        if (preg_match('/^[+-]\d{2}:?\d{2}:?\d{2}/', $str)) {
            throw new RangeError("{$str} is not a valid time zone string");
        }
        // Try as datetime string with TZ annotation.
        [$cleaned] = self::normalizeTemporalString($str);
        // Extract timezone annotation.
        if (preg_match('/\[(!?)([^\]=]+)\]/', $str, $annM)) {
            $tzName = $annM[2];
            if (!str_contains($tzName, '=')) {
                // It's a timezone annotation, not a key=value.
                if (preg_match('/^[+-]\d{2}:?\d{2}$/', $tzName)) {
                    return $tzName;
                }
                $upper = strtoupper($tzName);
                if ($upper === 'UTC' || $upper === 'GMT') {
                    return $upper;
                }
                try {
                    $tzObj = new \DateTimeZone($tzName);
                    return $tzObj->getName();
                } catch (\Throwable) {
                    throw new RangeError("Invalid time zone: {$tzName}");
                }
            }
        }
        // Try parsing as a datetime string and extract the offset.
        // Use a full offset regex that captures sub-minute parts for rejection.
        $datePart = '([+-]?\d{4,6})(?:-(\d{2})-(\d{2})|(\d{2})(\d{2}))';
        $timePart = '(\d{2})(?::?(\d{2})(?::?(\d{2})(?:[.,](\d{1,9}))?)?)?';
        $tzFull = '([Zz]|[+-]\d{2}(?::?\d{2}(?::?\d{2}(?:[.,]\d+)?)?)?)';
        $pattern = "/^{$datePart}[Tt ]{$timePart}{$tzFull}/";
        if (preg_match($pattern, $cleaned, $dtM)) {
            $offset = $dtM[10];
            if (strtoupper($offset) === 'Z') {
                return 'UTC';
            }
            // Reject sub-minute offsets (seconds or fractional).
            if (preg_match('/^[+-]\d{2}:?\d{2}:?\d{2}/', $offset)) {
                throw new RangeError("{$str} is not a valid time zone string");
            }
            return $offset;
        }
        throw new RangeError("Invalid time zone string: {$str}");
    }

    /** Compute difference between two ZonedDateTime epoch ns values. */
    private static function zonedDateTimeDifference(
        string $ns1,
        string $ns2,
        string $tz,
        JsValue $opts,
    ): JsObject {
        $largestUnit = 'hour';
        $largestUnitExplicit = false;
        $smallestUnit = null;
        if ($opts instanceof JsObject) {
            $lu = $opts->get('largestUnit');
            if (!($lu instanceof JsUndefined)) {
                $largestUnitExplicit = true;
                $largestUnit = TypeConversion::toString($lu);
                if ($largestUnit === 'auto') {
                    $largestUnit = 'hour';
                    $largestUnitExplicit = false;
                } else {
                    $largestUnit = self::canonicalTemporalUnit($largestUnit);
                }
            }
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
            $su = $opts->get('smallestUnit');
            if (!($su instanceof JsUndefined)) {
                $smallestUnit = TypeConversion::toString($su);
                $smallestUnit = self::canonicalTemporalUnit($smallestUnit);
            }
        }
        // Validate largestUnit >= smallestUnit.
        $suFinal = $smallestUnit ?? 'nanosecond';
        $allU = ['year', 'month', 'week', 'day', 'hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];
        $liIdx = array_search($largestUnit, $allU);
        $siIdx = array_search($suFinal, $allU);
        if (!$largestUnitExplicit && $siIdx !== false && $liIdx !== false && $siIdx < $liIdx) {
            $largestUnit = $suFinal;
            $liIdx = $siIdx;
        }
        if ($liIdx !== false && $siIdx !== false && $liIdx > $siIdx) {
            throw new RangeError('largestUnit must be >= smallestUnit');
        }
        // Validate roundingIncrement.
        if (isset($riNum) && $riNum > 1) {
            self::validateRoundingIncrement($suFinal, $riNum);
        }
        $calendarUnits = ['year', 'month', 'week', 'day'];
        if (in_array($largestUnit, $calendarUnits, true)) {
            // Calendar-aware difference using date parts.
            $parts1 = self::epochNsToISOParts($ns1, $tz);
            $parts2 = self::epochNsToISOParts($ns2, $tz);
            // Create PlainDateTimes and delegate.
            $dt1 = self::createPlainDateTimeObject(
                $parts1['year'],
                $parts1['month'],
                $parts1['day'],
                $parts1['hour'],
                $parts1['minute'],
                $parts1['second'],
                $parts1['millisecond'],
                $parts1['microsecond'],
                $parts1['nanosecond'],
                'iso8601',
            );
            $dt2 = self::createPlainDateTimeObject(
                $parts2['year'],
                $parts2['month'],
                $parts2['day'],
                $parts2['hour'],
                $parts2['minute'],
                $parts2['second'],
                $parts2['millisecond'],
                $parts2['microsecond'],
                $parts2['nanosecond'],
                'iso8601',
            );
            // Create resolved options to avoid double-reading from observers.
            $resolvedOpts = new JsObject();
            $resolvedOpts->set('largestUnit', new JsString($largestUnit));
            if (isset($riNum)) {
                $resolvedOpts->set('roundingIncrement', JsNumber::of((float) $riNum));
            }
            if (isset($rmStr)) {
                $resolvedOpts->set('roundingMode', new JsString($rmStr));
            }
            if (isset($smallestUnit)) {
                $resolvedOpts->set('smallestUnit', new JsString($smallestUnit));
            }
            $dur = self::plainDateTimeDifference($dt1, $dt2, $resolvedOpts);
            // For ZDT difference where the timezone has a DST transition
            // inside the duration window, replace the sub-day portion of the
            // result with the real UTC elapsed ns from "start + date duration"
            // → target so DST gaps/folds shorten or lengthen it.
            if (
                !self::isFixedOffset($tz)
                && self::tzHasTransitionBetween($tz, $ns1, $ns2)
            ) {
                $years = self::getDurationField($dur, 'years');
                $months = self::getDurationField($dur, 'months');
                $weeks = self::getDurationField($dur, 'weeks');
                $days = self::getDurationField($dur, 'days');
                $dateOnly = self::createDurationObject(
                    $years,
                    $months,
                    $weeks,
                    $days,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                );
                $startObj = self::createZonedDateTimeObject($ns1, $tz, 'iso8601');
                $afterDates = self::addDurationToZdt($startObj, $dateOnly, 1, 'constrain');
                $subDayNs = bcsub($ns2, $afterDates, 0);
                $dateSign = self::durationSign($dateOnly);
                $subSign = bccomp($subDayNs, '0', 0);
                // Sign-disagreement case: the calendar walk overshot because
                // a DST transition or date-line jump made the destination land
                // past ns2. Per spec DifferenceZonedDateTime, back off one day
                // and recompute the sub-day portion.
                if ($dateSign !== 0 && $subSign !== 0 && (($dateSign > 0) !== ($subSign > 0))) {
                    if ($dateSign > 0) {
                        $days -= 1;
                    } else {
                        $days += 1;
                    }
                    $dateOnly = self::createDurationObject(
                        $years,
                        $months,
                        $weeks,
                        $days,
                        0,
                        0,
                        0,
                        0,
                        0,
                        0,
                    );
                    $afterDates = self::addDurationToZdt($startObj, $dateOnly, 1, 'constrain');
                    $subDayNs = bcsub($ns2, $afterDates, 0);
                }
                $unitNsMap = [
                    'hour' => '3600000000000',
                    'minute' => '60000000000',
                    'second' => '1000000000',
                    'millisecond' => '1000000',
                    'microsecond' => '1000',
                    'nanosecond' => '1',
                    'day' => '86400000000000',
                ];
                $rounded = $subDayNs;
                $smallest = $smallestUnit ?? 'nanosecond';
                if (isset($unitNsMap[$smallest])) {
                    $unitNs = $unitNsMap[$smallest];
                    $incNs = bcmul((string) (isset($riNum) ? $riNum : 1), $unitNs, 0);
                    if ($incNs !== '0' && $incNs !== '1') {
                        // For day-level rounding, fractional day uses the
                        // actual calendar day length (which may differ from
                        // 24h around DST starts/ends or offset transitions).
                        if ($smallest === 'day') {
                            $rounded = self::roundDaysWithCalendarLength(
                                $subDayNs,
                                $startObj,
                                $dateOnly,
                                isset($riNum) ? $riNum : 1,
                                $rmStr ?? 'trunc',
                            );
                        } else {
                            $rounded = self::roundNs($subDayNs, $incNs, $rmStr ?? 'trunc');
                        }
                    }
                }
                if ($smallest === 'day') {
                    // After day rounding, $rounded is an integer number of days.
                    $extraDays = (int) bcdiv($rounded, '86400000000000', 0);
                    $days += $extraDays;
                    $dur = self::createDurationObject(
                        $years,
                        $months,
                        $weeks,
                        $days,
                        0,
                        0,
                        0,
                        0,
                        0,
                        0,
                    );
                } else {
                    $timePart = self::nsToTimeDuration($rounded, 'hour');
                    $dur = self::createDurationObject(
                        $years,
                        $months,
                        $weeks,
                        $days,
                        self::getDurationField($timePart, 'hours'),
                        self::getDurationField($timePart, 'minutes'),
                        self::getDurationField($timePart, 'seconds'),
                        self::getDurationField($timePart, 'milliseconds'),
                        self::getDurationField($timePart, 'microseconds'),
                        self::getDurationField($timePart, 'nanoseconds'),
                    );
                }
            }
            // Validate ceiling for day-level increments.
            if (isset($riNum) && $riNum > 1 && ($smallestUnit === 'day' || $smallestUnit === 'days')) {
                $incrNsForCheck = bcmul((string) $riNum, '86400000000000', 0);
                $ceilPos = bcadd($ns1, $incrNsForCheck, 0);
                $ceilNeg = bcsub($ns1, $incrNsForCheck, 0);
                $diffSign = bccomp($ns2, $ns1, 0);
                if ($diffSign >= 0 && bccomp($ceilPos, self::NS_MAX, 0) > 0) {
                    throw new RangeError('Rounded date outside valid ISO date range');
                }
                if ($diffSign <= 0 && bccomp($ceilNeg, self::NS_MIN, 0) < 0) {
                    throw new RangeError('Rounded date outside valid ISO date range');
                }
            }
            return $dur;
        }
        // Time-only difference using epoch ns.
        $diffNs = bcsub($ns2, $ns1, 0);
        $roundIncrement = isset($riNum) ? $riNum : 1;
        $roundMode = $rmStr ?? 'trunc';
        $suFinal = $smallestUnit ?? 'nanosecond';
        if ($suFinal !== 'nanosecond' || $roundIncrement !== 1) {
            $unitNsMap = [
                'hour' => '3600000000000',
                'minute' => '60000000000',
                'second' => '1000000000',
                'millisecond' => '1000000',
                'microsecond' => '1000',
                'nanosecond' => '1',
            ];
            $unitNs = $unitNsMap[$suFinal] ?? '1';
            $incrementNs = bcmul((string) $roundIncrement, $unitNs, 0);
            // Validate ceiling based on diff direction.
            $diffSign = bccomp($diffNs, '0', 0);
            if ($diffSign >= 0) {
                // Positive diff: check ns1 + increment.
                $ceil = bcadd($ns1, $incrementNs, 0);
                if (bccomp($ceil, self::NS_MAX, 0) > 0) {
                    throw new RangeError('Rounded date outside valid ISO date range');
                }
            }
            if ($diffSign <= 0) {
                // Negative diff: check ns1 - increment.
                $ceil = bcsub($ns1, $incrementNs, 0);
                if (bccomp($ceil, self::NS_MIN, 0) < 0) {
                    throw new RangeError('Rounded date outside valid ISO date range');
                }
            }
            $diffNs = self::roundNs($diffNs, $incrementNs, $roundMode);
        }
        return self::nsToTimeDuration($diffNs, $largestUnit);
    }

    /** Normalize a timezone ID to a canonical form for comparison. */
    private static function normalizeTimeZoneId(string $tz): string
    {
        $upper = strtoupper($tz);
        if ($upper === 'UTC' || $upper === 'GMT') {
            return 'UTC';
        }
        // Normalize numeric offsets to +HH:MM form.
        if (preg_match('/^([+-])(\d{2}):?(\d{2})?$/', $tz, $m)) {
            $sign = $m[1];
            $h = $m[2];
            $min = $m[3] ?? '00';
            return "{$sign}{$h}:{$min}";
        }
        // For named IANA zones, resolve to the primary (canonical)
        // identifier so Link names compare equal to their target.
        // Prefer our local backward-link map (tracks tzdata 2024b)
        // because ICU's canonical mapping lags or diverges from
        // IANA on several entries (e.g. Antarctica/South_Pole and
        // Africa/Asmara, where ICU still points the canonical at
        // the deprecated Asmera form).
        $linkTarget = self::ianaLinkCanonical($tz);
        if ($linkTarget !== null) {
            $canonical = $linkTarget;
        } elseif (self::isIanaLinkTarget($tz)) {
            $canonical = $tz;
        } else {
            $canonical = $tz;
            if (class_exists('IntlTimeZone', false)) {
                try {
                    $icuCanonical = \IntlTimeZone::getCanonicalID($tz);
                    if ($icuCanonical !== '') {
                        $icuLinkTarget = self::ianaLinkCanonical($icuCanonical);
                        $canonical = $icuLinkTarget ?? $icuCanonical;
                    }
                } catch (\Throwable) {
                }
            }
        }
        // Per the IANA backzone override, some Pacific zones canonicalize differently
        // from ICU's defaults.
        static $backzoneOverrides = [
            'Pacific/Truk' => 'Pacific/Chuuk',
            'Pacific/Ponape' => 'Pacific/Pohnpei',
        ];
        if (isset($backzoneOverrides[$canonical])) {
            $canonical = $backzoneOverrides[$canonical];
        }
        // Per spec, zones whose primary identifier is Etc/UTC, Etc/GMT, or GMT
        // are remapped to "UTC".
        if ($canonical === 'Etc/UTC' || $canonical === 'Etc/GMT' || $canonical === 'GMT') {
            return 'UTC';
        }
        return $canonical;
    }

    /**
     * @return array<string, int|string>
     */
    private static function zonedDateTimeParts(JsValue $zdt): array
    {
        $ns = self::getSlotString($zdt, '[[EpochNanoseconds]]');
        $tz = self::getSlotString($zdt, '[[TimeZone]]');
        return self::epochNsToISOParts($ns, $tz);
    }
}
