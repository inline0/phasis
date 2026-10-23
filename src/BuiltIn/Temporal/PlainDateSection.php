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
 * Temporal.PlainDate type installer. Composed into TemporalObject
 * via `use Temporal\PlainDateSection;` — the per-section split is
 * purely organisational. `self::` references resolve into the
 * composing class so cross-section helpers continue to work.
 */
trait PlainDateSection
{
    // -----------------------------------------------------------------------
    // Temporal.PlainDate
    // -----------------------------------------------------------------------

    private static ?JsObject $plainDateProto = null;

    private static function installPlainDate(JsObject $temporal, Environment $env): JsObject
    {
        $proto = new JsObject();

        // Date getters.
        self::defineGetter($proto, 'calendarId', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            return new JsString(self::getSlotString($this_, '[[Calendar]]'));
        });
        self::defineGetter($proto, 'year', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            $cal = self::getSlotString($this_, '[[Calendar]]');
            if ($cal !== 'iso8601') {
                $parts = self::isoToCalendarParts(
                    $cal,
                    self::getSlotInt($this_, '[[ISOYear]]'),
                    self::getSlotInt($this_, '[[ISOMonth]]'),
                    self::getSlotInt($this_, '[[ISODay]]'),
                );
                if ($parts !== null) {
                    return JsNumber::of((float) $parts['year']);
                }
            }
            return JsNumber::of((float) self::getSlotInt($this_, '[[ISOYear]]'));
        });
        self::defineGetter($proto, 'month', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            $cal = self::getSlotString($this_, '[[Calendar]]');
            if ($cal !== 'iso8601') {
                $parts = self::isoToCalendarParts(
                    $cal,
                    self::getSlotInt($this_, '[[ISOYear]]'),
                    self::getSlotInt($this_, '[[ISOMonth]]'),
                    self::getSlotInt($this_, '[[ISODay]]'),
                );
                if ($parts !== null) {
                    return JsNumber::of((float) $parts['month']);
                }
            }
            return JsNumber::of((float) self::getSlotInt($this_, '[[ISOMonth]]'));
        });
        self::defineGetter($proto, 'monthCode', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            $cal = self::getSlotString($this_, '[[Calendar]]');
            if ($cal !== 'iso8601') {
                $parts = self::isoToCalendarParts(
                    $cal,
                    self::getSlotInt($this_, '[[ISOYear]]'),
                    self::getSlotInt($this_, '[[ISOMonth]]'),
                    self::getSlotInt($this_, '[[ISODay]]'),
                );
                if ($parts !== null) {
                    return new JsString($parts['monthCode']);
                }
            }
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            return new JsString('M' . str_pad((string) $m, 2, '0', STR_PAD_LEFT));
        });
        self::defineGetter($proto, 'day', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            $cal = self::getSlotString($this_, '[[Calendar]]');
            if ($cal !== 'iso8601') {
                $parts = self::isoToCalendarParts(
                    $cal,
                    self::getSlotInt($this_, '[[ISOYear]]'),
                    self::getSlotInt($this_, '[[ISOMonth]]'),
                    self::getSlotInt($this_, '[[ISODay]]'),
                );
                if ($parts !== null) {
                    return JsNumber::of((float) $parts['day']);
                }
            }
            return JsNumber::of((float) self::getSlotInt($this_, '[[ISODay]]'));
        });
        self::defineGetter($proto, 'dayOfWeek', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            return JsNumber::of((float) self::isoDayOfWeek(
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
            ));
        });
        self::defineGetter($proto, 'dayOfYear', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $iy = self::getSlotInt($this_, '[[ISOYear]]');
            $im = self::getSlotInt($this_, '[[ISOMonth]]');
            $id = self::getSlotInt($this_, '[[ISODay]]');
            $doy = self::calendarDayOfYearForIso($cal, $iy, $im, $id);
            return JsNumber::of((float) ($doy ?? self::isoDayOfYear($iy, $im, $id)));
        });
        self::defineGetter($proto, 'weekOfYear', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            $cal = self::getSlotString($this_, '[[Calendar]]');
            [$week] = self::calendarWeekOfYear(
                $cal,
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
            );
            return $week === null ? JsUndefined::instance() : JsNumber::of((float) $week);
        });
        self::defineGetter($proto, 'yearOfWeek', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            $cal = self::getSlotString($this_, '[[Calendar]]');
            [, $yearOfWeek] = self::calendarWeekOfYear(
                $cal,
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
            );
            return $yearOfWeek === null ? JsUndefined::instance() : JsNumber::of((float) $yearOfWeek);
        });
        self::defineGetter($proto, 'daysInWeek', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            return JsNumber::of(7.0);
        });
        self::defineGetter($proto, 'daysInMonth', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $iy = self::getSlotInt($this_, '[[ISOYear]]');
            $im = self::getSlotInt($this_, '[[ISOMonth]]');
            $id = self::getSlotInt($this_, '[[ISODay]]');
            $count = self::calendarDaysInMonthForIso($cal, $iy, $im, $id);
            return JsNumber::of((float) ($count ?? self::isoDaysInMonth($iy, $im)));
        });
        self::defineGetter($proto, 'daysInYear', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $iy = self::getSlotInt($this_, '[[ISOYear]]');
            $im = self::getSlotInt($this_, '[[ISOMonth]]');
            $id = self::getSlotInt($this_, '[[ISODay]]');
            $count = self::calendarDaysInYearForIso($cal, $iy, $im, $id);
            return JsNumber::of((float) ($count ?? self::isoDaysInYear($iy)));
        });
        self::defineGetter($proto, 'monthsInYear', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $count = self::calendarMonthsInYear(
                $cal,
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
            );
            return JsNumber::of((float) ($count ?? 12));
        });
        self::defineGetter($proto, 'inLeapYear', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $leap = self::calendarInLeapYear(
                $cal,
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
            );
            if ($leap !== null) {
                return new JsBoolean($leap);
            }
            return new JsBoolean(self::isoIsLeapYear(self::getSlotInt($this_, '[[ISOYear]]')));
        });
        self::defineGetter($proto, 'era', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $y = self::getSlotInt($this_, '[[ISOYear]]');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            $d = self::getSlotInt($this_, '[[ISODay]]');
            $era = self::deriveEra($cal, $y, $m, $d);
            return $era === null ? JsUndefined::instance() : new JsString($era);
        });
        self::defineGetter($proto, 'eraYear', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $y = self::getSlotInt($this_, '[[ISOYear]]');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            $d = self::getSlotInt($this_, '[[ISODay]]');
            $eraYear = self::deriveEraYear($cal, $y, $m, $d);
            return $eraYear === null ? JsUndefined::instance() : JsNumber::of((float) $eraYear);
        });

        $d = self::protoHelper($proto);

        $d('toString', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDate($this_);
            $options = self::getOptionsObject($args[0] ?? JsUndefined::instance());
            $calendarName = 'auto';
            if ($options instanceof JsObject) {
                $cn = $options->get('calendarName');
                if (!($cn instanceof JsUndefined)) {
                    $calendarName = TypeConversion::toString($cn);
                    if (!in_array($calendarName, ['auto', 'always', 'never', 'critical'], true)) {
                        throw new RangeError("Invalid calendarName: {$calendarName}");
                    }
                }
            }
            $y = self::getSlotInt($this_, '[[ISOYear]]');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            $dd = self::getSlotInt($this_, '[[ISODay]]');
            $result = self::padISOYear($y) . '-' . self::pad2($m) . '-' . self::pad2($dd);
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $showCal = $calendarName === 'always'
                || $calendarName === 'critical'
                || ($calendarName !== 'never' && $cal !== 'iso8601');
            if ($showCal) {
                $prefix = $calendarName === 'critical' ? '!' : '';
                $result .= "[{$prefix}u-ca={$cal}]";
            }
            return new JsString($result);
        }, 0);

        $d('toJSON', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            $y = self::getSlotInt($this_, '[[ISOYear]]');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            $dd = self::getSlotInt($this_, '[[ISODay]]');
            return new JsString(self::padISOYear($y) . '-' . self::pad2($m) . '-' . self::pad2($dd));
        }, 0);

        $d('toLocaleString', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDate($this_);
            $y = self::getSlotInt($this_, '[[ISOYear]]');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            $dd = self::getSlotInt($this_, '[[ISODay]]');
            $fallback = self::padISOYear($y) . '-' . self::pad2($m) . '-' . self::pad2($dd);
            return self::temporalToLocaleString($this_, $args, $fallback);
        }, 0);

        $d('valueOf', function (JsValue $this_): JsValue {
            throw new TypeError('Temporal.PlainDate does not implement valueOf');
        }, 0);

        $d('equals', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDate($this_);
            $other = self::toPlainDate($args[0] ?? JsUndefined::instance());
            return new JsBoolean(
                self::getSlotInt($this_, '[[ISOYear]]') === self::getSlotInt($other, '[[ISOYear]]')
                && self::getSlotInt($this_, '[[ISOMonth]]') === self::getSlotInt($other, '[[ISOMonth]]')
                && self::getSlotInt($this_, '[[ISODay]]') === self::getSlotInt($other, '[[ISODay]]')
                && self::getSlotString($this_, '[[Calendar]]') === self::getSlotString($other, '[[Calendar]]'),
            );
        }, 1);

        $d('with', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDate($this_);
            $item = $args[0] ?? JsUndefined::instance();
            if (!$item instanceof JsObject) {
                throw new TypeError('argument must be an object');
            }
            self::rejectObjectWithCalendarOrTimeZone($item);
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $fields = self::calendarFieldsForIso(
                $cal,
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
            );

            $additional = self::prepareCalendarFields($item, $cal, ['day', 'month', 'monthCode', 'year']);
            if ($additional === []) {
                throw new TypeError('at least one temporal property required');
            }
            $fields = self::mergeCalendarFields($fields, $additional);
            $overflow = self::getOverflow(self::getOptionsObject($args[1] ?? JsUndefined::instance()));

            $date = self::calendarDateFromFields($cal, $fields, $overflow);
            return self::createPlainDateObject($date['year'], $date['month'], $date['day'], $cal);
        }, 1);

        $d('add', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDate($this_);
            $dur = self::toDuration($args[0] ?? JsUndefined::instance());
            $options = self::getOptionsObject($args[1] ?? JsUndefined::instance());
            $overflow = 'constrain';
            if ($options instanceof JsObject) {
                $ov = $options->get('overflow');
                if (!($ov instanceof JsUndefined)) {
                    $overflow = TypeConversion::toString($ov);
                    if ($overflow !== 'constrain' && $overflow !== 'reject') {
                        throw new RangeError("Invalid overflow: {$overflow}");
                    }
                }
            }
            return self::plainDateAdd($this_, $dur, 1, $overflow);
        }, 1);

        $d('subtract', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDate($this_);
            $dur = self::toDuration($args[0] ?? JsUndefined::instance());
            $options = self::getOptionsObject($args[1] ?? JsUndefined::instance());
            $overflow = 'constrain';
            if ($options instanceof JsObject) {
                $ov = $options->get('overflow');
                if (!($ov instanceof JsUndefined)) {
                    $overflow = TypeConversion::toString($ov);
                    if ($overflow !== 'constrain' && $overflow !== 'reject') {
                        throw new RangeError("Invalid overflow: {$overflow}");
                    }
                }
            }
            return self::plainDateAdd($this_, $dur, -1, $overflow);
        }, 1);

        $d('until', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDate($this_);
            $other = self::toPlainDate($args[0] ?? JsUndefined::instance());
            return self::plainDateDifference($this_, $other, $args[1] ?? JsUndefined::instance(), 1);
        }, 1);

        $d('since', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDate($this_);
            $other = self::toPlainDate($args[0] ?? JsUndefined::instance());
            return self::plainDateDifference($this_, $other, $args[1] ?? JsUndefined::instance(), -1);
        }, 1);

        $d('toPlainDateTime', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDate($this_);
            $time = $args[0] ?? JsUndefined::instance();
            $h = 0;
            $min = 0;
            $s = 0;
            $ms = 0;
            $us = 0;
            $ns = 0;
            if (!($time instanceof JsUndefined)) {
                $t = self::toPlainTime($time);
                $h = self::getSlotInt($t, '[[ISOHour]]');
                $min = self::getSlotInt($t, '[[ISOMinute]]');
                $s = self::getSlotInt($t, '[[ISOSecond]]');
                $ms = self::getSlotInt($t, '[[ISOMillisecond]]');
                $us = self::getSlotInt($t, '[[ISOMicrosecond]]');
                $ns = self::getSlotInt($t, '[[ISONanosecond]]');
            }
            return self::createPlainDateTimeObject(
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
                $h,
                $min,
                $s,
                $ms,
                $us,
                $ns,
                self::getSlotString($this_, '[[Calendar]]'),
            );
        }, 0);

        $d('toPlainYearMonth', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            $calendar = self::getSlotString($this_, '[[Calendar]]');
            $fields = self::calendarFieldsForIso(
                $calendar,
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
            );
            $fields['day'] = 1;
            $date = self::calendarDateFromFields($calendar, $fields, 'constrain');
            return self::createPlainYearMonthObject($date['year'], $date['month'], $date['day'], $calendar);
        }, 0);

        $d('toPlainMonthDay', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            $calendar = self::getSlotString($this_, '[[Calendar]]');
            $fields = self::calendarFieldsForIso(
                $calendar,
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
            );
            $date = self::calendarMonthDayFromFields($calendar, $fields, 'constrain');
            return self::createPlainMonthDayObject($date['month'], $date['day'], $date['year'], $calendar);
        }, 0);

        $d('withCalendar', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDate($this_);
            $calArg = $args[0] ?? JsUndefined::instance();
            $cal = self::toCalendarSlotValue($calArg);
            return self::createPlainDateObject(
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
                $cal,
            );
        }, 1);

        $d('toZonedDateTime', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDate($this_);
            $item = $args[0] ?? JsUndefined::instance();
            $useStartOfDay = false;
            $h = 0;
            $min = 0;
            $s = 0;
            $ms = 0;
            $us = 0;
            $nsPart = 0;
            if ($item instanceof JsString) {
                $timeZone = self::toTemporalTimeZoneIdentifier($item);
                $useStartOfDay = true;
            } elseif ($item instanceof JsObject) {
                $tz = $item->get('timeZone');
                if ($tz instanceof JsUndefined) {
                    throw new TypeError('missing timeZone property');
                }
                $timeZone = self::toTemporalTimeZoneIdentifier($tz);
                $ptArg = $item->get('plainTime');
                if ($ptArg instanceof JsUndefined) {
                    $useStartOfDay = true;
                } else {
                    $t = self::toPlainTime($ptArg);
                    $h = self::getSlotInt($t, '[[ISOHour]]');
                    $min = self::getSlotInt($t, '[[ISOMinute]]');
                    $s = self::getSlotInt($t, '[[ISOSecond]]');
                    $ms = self::getSlotInt($t, '[[ISOMillisecond]]');
                    $us = self::getSlotInt($t, '[[ISOMicrosecond]]');
                    $nsPart = self::getSlotInt($t, '[[ISONanosecond]]');
                }
            } else {
                throw new TypeError('Expected a string or an object with a timeZone property');
            }
            $y = self::getSlotInt($this_, '[[ISOYear]]');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            $dd = self::getSlotInt($this_, '[[ISODay]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            if ($useStartOfDay) {
                $ns = self::startOfDayInTimeZone($y, $m, $dd, $timeZone);
            } else {
                $ns = self::isoDateTimeToEpochNsDisambiguated(
                    $y,
                    $m,
                    $dd,
                    $h,
                    $min,
                    $s,
                    $ms,
                    $us,
                    $nsPart,
                    $timeZone,
                    'compatible',
                );
            }
            return self::createZonedDateTimeObject($ns, $timeZone, $cal);
        }, 1);

        self::setToStringTag($proto, 'Temporal.PlainDate');
        self::installTemporalToPrimitive($proto, 'PlainDate');

        // Constructor
        $ctor = JsFunction::fromCallable('PlainDate', function (JsValue $this_, array $args) use ($proto): JsValue {
            if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                throw new TypeError('Temporal.PlainDate must be called with new');
            }
            self::applyNewTargetPrototype($this_, $proto);
            $yNum = TypeConversion::toNumber($args[0] ?? JsUndefined::instance());
            if (!is_finite($yNum)) {
                throw new RangeError('year must be finite');
            }
            $y = (int) $yNum;
            $mNum = TypeConversion::toNumber($args[1] ?? JsUndefined::instance());
            if (!is_finite($mNum)) {
                throw new RangeError('month must be finite');
            }
            $m = (int) $mNum;
            $ddNum = TypeConversion::toNumber($args[2] ?? JsUndefined::instance());
            if (!is_finite($ddNum)) {
                throw new RangeError('day must be finite');
            }
            $dd = (int) $ddNum;
            $cal = 'iso8601';
            if (isset($args[3]) && !($args[3] instanceof JsUndefined)) {
                $cal = self::toCalendarSlotValue($args[3], false);
            }
            self::validateISODate($y, $m, $dd);
            self::setDateSlots($this_, $y, $m, $dd, $cal);
            $this_->defineOwnProperty('[[IsPlainDate]]', PropertyDescriptor::data(new JsBoolean(true), false, false, false));
            return $this_;
        }, 3);
        $ctor->setConstructable();

        // Static: PlainDate.from
        $ctor->defineOwnProperty('from', PropertyDescriptor::data(
            JsFunction::fromCallable('from', function (JsValue $this_, array $args): JsValue {
                $item = $args[0] ?? JsUndefined::instance();
                // Per spec: reject non-string/non-object BEFORE reading options.
                if (
                    $item instanceof JsUndefined || $item instanceof JsNull
                    || $item instanceof JsNumber || $item instanceof \Phasis\Value\JsBigInt
                    || $item instanceof JsBoolean || $item instanceof \Phasis\Value\JsSymbol
                ) {
                    return self::toPlainDate($item, 'constrain');
                }
                if ($item instanceof JsString) {
                    // For strings: parse first, then validate options.
                    $result = self::toPlainDate($item, 'constrain');
                    $options = self::getOptionsObject($args[1] ?? JsUndefined::instance());
                    self::getOverflow($options);
                    return $result;
                }
                // For known Temporal types: convert first, then read options.
                if (
                    $item instanceof JsObject && (
                    ($item->has('[[ISOYear]]') && !$item->has('[[IsPlainTime]]') && !$item->has('[[IsPlainDateTime]]')
                        && !$item->has('[[IsPlainYearMonth]]') && !$item->has('[[IsPlainMonthDay]]')
                        && !$item->has('[[IsZonedDateTime]]') && !$item->has('[[IsDuration]]') && !$item->has('[[EpochNanoseconds]]'))
                    || $item->has('[[IsPlainDateTime]]')
                    || $item->has('[[IsZonedDateTime]]')
                    )
                ) {
                    $result = self::toPlainDate($item, 'constrain');
                    $options = self::getOptionsObject($args[1] ?? JsUndefined::instance());
                    self::getOverflow($options);
                    return $result;
                }
                // For property bags: fields read inside toPlainDate, options read after.
                return self::toPlainDate($item, 'constrain', $args[1] ?? JsUndefined::instance());
            }, 1),
            true,
            false,
            true,
        ));

        // Static: PlainDate.compare
        $ctor->defineOwnProperty('compare', PropertyDescriptor::data(
            JsFunction::fromCallable('compare', function (JsValue $this_, array $args): JsValue {
                $one = self::toPlainDate($args[0] ?? JsUndefined::instance());
                $two = self::toPlainDate($args[1] ?? JsUndefined::instance());
                return JsNumber::of((float) self::compareISODate(
                    self::getSlotInt($one, '[[ISOYear]]'),
                    self::getSlotInt($one, '[[ISOMonth]]'),
                    self::getSlotInt($one, '[[ISODay]]'),
                    self::getSlotInt($two, '[[ISOYear]]'),
                    self::getSlotInt($two, '[[ISOMonth]]'),
                    self::getSlotInt($two, '[[ISODay]]'),
                ));
            }, 2),
            true,
            false,
            true,
        ));

        $ctor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($ctor, true, false, true));

        $temporal->defineOwnProperty('PlainDate', PropertyDescriptor::data($ctor, true, false, true));
        self::$plainDateProto = $proto;

        return $proto;
    }
}
