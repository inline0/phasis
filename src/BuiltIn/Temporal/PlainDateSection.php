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
            // RejectObjectWithCalendarOrTimeZone.
            self::rejectObjectWithCalendarOrTimeZone($item);
            $cal = self::getSlotString($this_, '[[Calendar]]');
            // For non-ISO non-gregory calendars, work in the calendar's
            // native field space. Defaults come from the ICU-derived parts
            // of the stored ISO date; user overrides replace those fields;
            // the result is converted back to ISO via ICU.
            $useCalendarNative = $cal !== 'iso8601' && !in_array($cal, ['gregory', 'roc', 'japanese'], true);
            if ($useCalendarNative) {
                $isoY0 = self::getSlotInt($this_, '[[ISOYear]]');
                $isoM0 = self::getSlotInt($this_, '[[ISOMonth]]');
                $isoD0 = self::getSlotInt($this_, '[[ISODay]]');
                $instParts = self::isoToCalendarParts($cal, $isoY0, $isoM0, $isoD0);
                if ($instParts === null) {
                    $useCalendarNative = false;
                }
            }
            if ($useCalendarNative) {
                $y = $instParts['year'];
                $m = $instParts['month'];
                $dd = $instParts['day'];
                $instMonthCode = $instParts['monthCode'];
            } else {
                $y = self::getSlotInt($this_, '[[ISOYear]]');
                $m = self::getSlotInt($this_, '[[ISOMonth]]');
                $dd = self::getSlotInt($this_, '[[ISODay]]');
                $instMonthCode = null;
            }
            $any = false;
            $userMonthCode = null;
            $userMonthSet = false;
            // Read fields in ALPHABETICAL order per spec: day, era, eraYear, month, monthCode, year.
            $dv = $item->get('day');
            if (!($dv instanceof JsUndefined)) {
                $n = TypeConversion::toNumber($dv);
                if (!is_finite($n)) {
                    throw new RangeError('day must be finite');
                }
                $dd = (int) $n;
                $any = true;
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
                    $any = true;
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
                    $any = true;
                }
                static $pdWithEras = ['gregory', 'japanese', 'roc'];
                if (in_array($cal, $pdWithEras, true) && $eraSet !== $eraYearSet) {
                    throw new TypeError('era and eraYear must be provided together');
                }
            }
            $mv = $item->get('month');
            if (!($mv instanceof JsUndefined)) {
                $n = TypeConversion::toNumber($mv);
                if (!is_finite($n)) {
                    throw new RangeError('month must be finite');
                }
                $m = (int) $n;
                $userMonthSet = true;
                $any = true;
            }
            $mcv = $item->get('monthCode');
            if (!($mcv instanceof JsUndefined)) {
                $mc = TypeConversion::toString($mcv);
                $mcMonth = self::parseMonthCode($mc, $cal);
                if (!($mv instanceof JsUndefined) && $m !== $mcMonth && !$useCalendarNative) {
                    throw new RangeError('month and monthCode disagree');
                }
                if (!$useCalendarNative) {
                    $m = $mcMonth;
                }
                $userMonthCode = $mc;
                $any = true;
            }
            $yv = $item->get('year');
            $yearProvided = !($yv instanceof JsUndefined);
            if ($yearProvided) {
                $n = TypeConversion::toNumber($yv);
                if (!is_finite($n)) {
                    throw new RangeError('year must be finite');
                }
                // For ROC the field-bag year is in calendar units
                // ("民國" year 1 = 1912 AD); translate to ISO so the
                // rest of the path sees the ISO year.
                $y = $cal === 'roc' ? ((int) $n + 1911) : (int) $n;
                $any = true;
            } elseif ($eraYearNum !== null) {
                $eraLower = $eraStr === null ? '' : strtolower($eraStr);
                if ($cal === 'japanese') {
                    $isoYear = self::japaneseEraToIsoYear($eraLower, (int) $eraYearNum);
                    if ($isoYear !== null) {
                        $y = $isoYear;
                    } elseif (in_array($eraLower, ['bc', 'bce', 'japanese-inverse'], true)) {
                        $y = (int) (1 - $eraYearNum);
                    } else {
                        $y = (int) $eraYearNum;
                    }
                } elseif ($cal === 'roc') {
                    $y = ($eraLower === 'roc-inverse' || $eraLower === 'before-roc')
                        ? (int) (1912 - $eraYearNum)
                        : (int) (1911 + $eraYearNum);
                } elseif ($cal === 'gregory') {
                    $y = in_array($eraLower, ['bc', 'bce', 'gregory-inverse'], true)
                        ? (int) (1 - $eraYearNum)
                        : (int) $eraYearNum;
                } elseif ($cal === 'coptic' || $cal === 'ethiopic' || $cal === 'ethioaa') {
                    // ICU handles these eras inside the calendar field
                    // mapping; pass eraYear through as the calendar year
                    // (with -inverse flipping the sign).
                    $y = ($eraLower !== '' && str_ends_with($eraLower, '-inverse'))
                        ? (int) (1 - $eraYearNum)
                        : (int) $eraYearNum;
                }
            }
            if (!$any) {
                throw new TypeError('at least one date property required');
            }
            // Validate basic bounds before reading options (negative day/month always invalid).
            if ($m < 1 || $dd < 1) {
                throw new RangeError('month and day must be >= 1');
            }
            // Read options AFTER fields per spec.
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
            if ($useCalendarNative) {
                // monthCode default carries over from the instance unless the
                // user supplied month/monthCode this call.
                $mcForIso = $userMonthCode ?? ($userMonthSet ? null : $instMonthCode);
                $monthForIso = $userMonthCode === null ? $m : null;
                // Constrain or reject day-out-of-range BEFORE letting ICU
                // roll over to the next month. Without this, with({day:32})
                // on a 31-day month silently advances to month+1, day:1
                // instead of clamping to the actual month-max.
                $maxDay = self::calendarDaysInMonth($cal, $y, $mcForIso, $monthForIso);
                if ($maxDay !== null && $dd > $maxDay) {
                    if ($overflow === 'reject') {
                        throw new RangeError("day {$dd} is out of range for month");
                    }
                    $dd = $maxDay;
                }
                if ($maxDay !== null && $dd < 1) {
                    if ($overflow === 'reject') {
                        throw new RangeError("day {$dd} is out of range for month");
                    }
                    $dd = 1;
                }
                $isoParts = self::calendarPartsToIso($cal, $y, $mcForIso, $monthForIso, $dd);
                if ($isoParts !== null) {
                    return self::createPlainDateObject($isoParts['year'], $isoParts['month'], $isoParts['day'], $cal);
                }
                // calendarPartsToIso returned null: the requested
                // year + monthCode combination doesn't exist in this
                // calendar (e.g. M07L in a chinese year whose leap is
                // M03). The spec rejects this even under default
                // "constrain" because there's no clear non-leap fallback.
                if ($mcForIso !== null && str_ends_with($mcForIso, 'L')) {
                    throw new RangeError(
                        "monthCode {$mcForIso} is not valid for {$cal} year {$y}",
                    );
                }
                // Fall through if conversion failed for non-leap codes.
            }
            if ($overflow === 'constrain') {
                [$y, $m, $dd] = self::constrainISODate($y, $m, $dd);
            } else {
                self::validateISODate($y, $m, $dd);
            }
            return self::createPlainDateObject($y, $m, $dd, $cal);
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
            return self::createPlainYearMonthObject(
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
                1,
                self::getSlotString($this_, '[[Calendar]]'),
            );
        }, 0);

        $d('toPlainMonthDay', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            return self::createPlainMonthDayObject(
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
                1972, // Reference year per spec
                self::getSlotString($this_, '[[Calendar]]'),
            );
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
