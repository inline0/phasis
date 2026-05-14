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
 * Temporal.PlainDateTime type installer. Composed into TemporalObject
 * via `use Temporal\PlainDateTimeSection;` — the per-section split is
 * purely organisational. `self::` references resolve into the
 * composing class so cross-section helpers continue to work.
 */
trait PlainDateTimeSection
{
    // -----------------------------------------------------------------------
    // Temporal.PlainDateTime
    // -----------------------------------------------------------------------

    private static ?JsObject $plainDateTimeProto = null;

    private static function installPlainDateTime(JsObject $temporal, Environment $env): JsObject
    {
        $proto = new JsObject();

        // Date getters
        self::defineGetter($proto, 'calendarId', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
            return new JsString(self::getSlotString($this_, '[[Calendar]]'));
        });
        foreach (['year' => 'year', 'month' => 'month', 'day' => 'day'] as $name => $key) {
            self::defineGetter($proto, $name, function (JsValue $this_) use ($key): JsValue {
                self::requirePlainDateTime($this_);
                $cal = self::getSlotString($this_, '[[Calendar]]');
                if ($cal !== 'iso8601') {
                    $parts = self::isoToCalendarParts(
                        $cal,
                        self::getSlotInt($this_, '[[ISOYear]]'),
                        self::getSlotInt($this_, '[[ISOMonth]]'),
                        self::getSlotInt($this_, '[[ISODay]]'),
                    );
                    if ($parts !== null) {
                        return JsNumber::of((float) $parts[$key]);
                    }
                }
                $slotMap = ['year' => '[[ISOYear]]', 'month' => '[[ISOMonth]]', 'day' => '[[ISODay]]'];
                return JsNumber::of((float) self::getSlotInt($this_, $slotMap[$key]));
            });
        }
        self::defineGetter($proto, 'monthCode', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
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
        foreach (
            ['hour' => '[[ISOHour]]', 'minute' => '[[ISOMinute]]', 'second' => '[[ISOSecond]]',
            'millisecond' => '[[ISOMillisecond]]', 'microsecond' => '[[ISOMicrosecond]]', 'nanosecond' => '[[ISONanosecond]]'] as $name => $slot
        ) {
            self::defineGetter($proto, $name, function (JsValue $this_) use ($slot): JsValue {
                self::requirePlainDateTime($this_);
                return JsNumber::of((float) self::getSlotInt($this_, $slot));
            });
        }
        self::defineGetter($proto, 'dayOfWeek', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
            return JsNumber::of((float) self::isoDayOfWeek(
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
            ));
        });
        self::defineGetter($proto, 'dayOfYear', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $iy = self::getSlotInt($this_, '[[ISOYear]]');
            $im = self::getSlotInt($this_, '[[ISOMonth]]');
            $id = self::getSlotInt($this_, '[[ISODay]]');
            $doy = self::calendarDayOfYearForIso($cal, $iy, $im, $id);
            return JsNumber::of((float) ($doy ?? self::isoDayOfYear($iy, $im, $id)));
        });
        self::defineGetter($proto, 'weekOfYear', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
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
            self::requirePlainDateTime($this_);
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
            self::requirePlainDateTime($this_);
            return JsNumber::of(7.0);
        });
        self::defineGetter($proto, 'daysInMonth', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $iy = self::getSlotInt($this_, '[[ISOYear]]');
            $im = self::getSlotInt($this_, '[[ISOMonth]]');
            $id = self::getSlotInt($this_, '[[ISODay]]');
            $count = self::calendarDaysInMonthForIso($cal, $iy, $im, $id);
            return JsNumber::of((float) ($count ?? self::isoDaysInMonth($iy, $im)));
        });
        self::defineGetter($proto, 'daysInYear', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $iy = self::getSlotInt($this_, '[[ISOYear]]');
            $im = self::getSlotInt($this_, '[[ISOMonth]]');
            $id = self::getSlotInt($this_, '[[ISODay]]');
            $count = self::calendarDaysInYearForIso($cal, $iy, $im, $id);
            return JsNumber::of((float) ($count ?? self::isoDaysInYear($iy)));
        });
        self::defineGetter($proto, 'monthsInYear', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
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
            self::requirePlainDateTime($this_);
            return new JsBoolean(self::isoIsLeapYear(self::getSlotInt($this_, '[[ISOYear]]')));
        });
        self::defineGetter($proto, 'era', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $y = self::getSlotInt($this_, '[[ISOYear]]');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            $d = self::getSlotInt($this_, '[[ISODay]]');
            $era = self::deriveEra($cal, $y, $m, $d);
            return $era === null ? JsUndefined::instance() : new JsString($era);
        });
        self::defineGetter($proto, 'eraYear', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $y = self::getSlotInt($this_, '[[ISOYear]]');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            $d = self::getSlotInt($this_, '[[ISODay]]');
            $eraYear = self::deriveEraYear($cal, $y, $m, $d);
            return $eraYear === null ? JsUndefined::instance() : JsNumber::of((float) $eraYear);
        });

        $d = self::protoHelper($proto);

        $d('toString', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDateTime($this_);
            $options = self::getOptionsObject($args[0] ?? JsUndefined::instance());
            $calendarName = 'auto';
            $fractionalSecondDigits = 'auto';
            $roundingMode = 'trunc';
            $smallestUnit = null;
            if ($options instanceof JsObject) {
                // Read alphabetical: calendarName, fractionalSecondDigits, roundingMode, smallestUnit.
                $cn = $options->get('calendarName');
                if (!($cn instanceof JsUndefined)) {
                    $calendarName = TypeConversion::toString($cn);
                    if (!in_array($calendarName, ['auto', 'always', 'never', 'critical'], true)) {
                        throw new RangeError("Invalid calendarName: {$calendarName}");
                    }
                }
                $fractionalSecondDigits = self::getFractionalSecondDigits($options);
                $roundingMode = self::getRoundingMode($options, 'trunc');
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
            }
            // Apply rounding if needed.
            $dt = $this_;
            if ($smallestUnit !== null && $smallestUnit !== 'nanosecond') {
                $unitNsMap = [
                    'minute' => 60000000000,
                    'second' => 1000000000,
                    'millisecond' => 1000000,
                    'microsecond' => 1000,
                ];
                $dt = self::roundPlainDateTime($this_, $unitNsMap[$smallestUnit], $roundingMode);
            } elseif ($smallestUnit === null && is_int($fractionalSecondDigits) && $fractionalSecondDigits < 9) {
                $digitsToNs = [
                    0 => 1000000000, 1 => 100000000, 2 => 10000000,
                    3 => 1000000, 4 => 100000, 5 => 10000,
                    6 => 1000, 7 => 100, 8 => 10,
                ];
                if (isset($digitsToNs[$fractionalSecondDigits])) {
                    $dt = self::roundPlainDateTime($this_, $digitsToNs[$fractionalSecondDigits], $roundingMode);
                }
            }
            if ($smallestUnit === 'minute') {
                // Format without seconds.
                $y = self::getSlotInt($dt, '[[ISOYear]]');
                $m = self::getSlotInt($dt, '[[ISOMonth]]');
                $dd = self::getSlotInt($dt, '[[ISODay]]');
                $h = self::getSlotInt($dt, '[[ISOHour]]');
                $min = self::getSlotInt($dt, '[[ISOMinute]]');
                $result = self::padISOYear($y) . '-' . self::pad2($m) . '-' . self::pad2($dd) . 'T' . self::pad2($h) . ':' . self::pad2($min);
                $cal = self::getSlotString($dt, '[[Calendar]]');
                $showCal = $calendarName === 'always' || $calendarName === 'critical' || ($calendarName !== 'never' && $cal !== 'iso8601');
                if ($showCal) {
                    $prefix = $calendarName === 'critical' ? '!' : '';
                    $result .= "[{$prefix}u-ca={$cal}]";
                }
                return new JsString($result);
            }
            return new JsString(self::plainDateTimeToString($dt, $fractionalSecondDigits, 'trunc', $calendarName));
        }, 0);

        $d('toJSON', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
            return new JsString(self::plainDateTimeToString($this_, 'auto', 'trunc', 'auto'));
        }, 0);

        $d('toLocaleString', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDateTime($this_);
            $fallback = self::plainDateTimeToString($this_, 'auto', 'trunc', 'auto');
            return self::temporalToLocaleString($this_, $args, $fallback);
        }, 0);

        $d('valueOf', function (JsValue $this_): JsValue {
            throw new TypeError('Temporal.PlainDateTime does not implement valueOf');
        }, 0);

        $d('equals', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDateTime($this_);
            $other = self::toPlainDateTime($args[0] ?? JsUndefined::instance());
            $slots = [
                '[[ISOYear]]', '[[ISOMonth]]', '[[ISODay]]',
                '[[ISOHour]]', '[[ISOMinute]]', '[[ISOSecond]]',
                '[[ISOMillisecond]]', '[[ISOMicrosecond]]',
                '[[ISONanosecond]]',
            ];
            foreach ($slots as $s) {
                if (self::getSlotInt($this_, $s) !== self::getSlotInt($other, $s)) {
                    return new JsBoolean(false);
                }
            }
            return new JsBoolean(self::getSlotString($this_, '[[Calendar]]') === self::getSlotString($other, '[[Calendar]]'));
        }, 1);

        $d('toPlainDate', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
            return self::createPlainDateObject(
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
                self::getSlotString($this_, '[[Calendar]]'),
            );
        }, 0);

        $d('toPlainTime', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
            return self::createPlainTimeObject(
                self::getSlotInt($this_, '[[ISOHour]]'),
                self::getSlotInt($this_, '[[ISOMinute]]'),
                self::getSlotInt($this_, '[[ISOSecond]]'),
                self::getSlotInt($this_, '[[ISOMillisecond]]'),
                self::getSlotInt($this_, '[[ISOMicrosecond]]'),
                self::getSlotInt($this_, '[[ISONanosecond]]'),
            );
        }, 0);

        $d('add', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDateTime($this_);
            $dur = self::toDuration($args[0] ?? JsUndefined::instance());
            $opts = self::getOptionsObject($args[1] ?? JsUndefined::instance());
            if ($opts instanceof JsObject) {
                $ov = $opts->get('overflow');
                if (!($ov instanceof JsUndefined)) {
                    $ovStr = TypeConversion::toString($ov);
                    if ($ovStr !== 'constrain' && $ovStr !== 'reject') {
                        throw new RangeError("Invalid overflow: {$ovStr}");
                    }
                }
            }
            return self::plainDateTimeAdd($this_, $dur, 1, $ovStr ?? 'constrain');
        }, 1);

        $d('subtract', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDateTime($this_);
            $dur = self::toDuration($args[0] ?? JsUndefined::instance());
            $opts = self::getOptionsObject($args[1] ?? JsUndefined::instance());
            if ($opts instanceof JsObject) {
                $ov = $opts->get('overflow');
                if (!($ov instanceof JsUndefined)) {
                    $ovStr = TypeConversion::toString($ov);
                    if ($ovStr !== 'constrain' && $ovStr !== 'reject') {
                        throw new RangeError("Invalid overflow: {$ovStr}");
                    }
                }
            }
            return self::plainDateTimeAdd($this_, $dur, -1, $ovStr ?? 'constrain');
        }, 1);

        $d('withPlainTime', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDateTime($this_);
            $time = $args[0] ?? JsUndefined::instance();
            if ($time instanceof JsUndefined) {
                return self::createPlainDateTimeObject(
                    self::getSlotInt($this_, '[[ISOYear]]'),
                    self::getSlotInt($this_, '[[ISOMonth]]'),
                    self::getSlotInt($this_, '[[ISODay]]'),
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                    self::getSlotString($this_, '[[Calendar]]'),
                );
            }
            $t = self::toPlainTime($time);
            return self::createPlainDateTimeObject(
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
                self::getSlotInt($t, '[[ISOHour]]'),
                self::getSlotInt($t, '[[ISOMinute]]'),
                self::getSlotInt($t, '[[ISOSecond]]'),
                self::getSlotInt($t, '[[ISOMillisecond]]'),
                self::getSlotInt($t, '[[ISOMicrosecond]]'),
                self::getSlotInt($t, '[[ISONanosecond]]'),
                self::getSlotString($this_, '[[Calendar]]'),
            );
        }, 0);

        $d('with', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDateTime($this_);
            $item = $args[0] ?? JsUndefined::instance();
            if (!$item instanceof JsObject) {
                throw new TypeError('argument must be an object');
            }
            self::rejectObjectWithCalendarOrTimeZone($item);
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $useCalendarNative = $cal !== 'iso8601' && !in_array($cal, ['gregory', 'roc', 'japanese'], true);
            if ($useCalendarNative) {
                $iy = self::getSlotInt($this_, '[[ISOYear]]');
                $im = self::getSlotInt($this_, '[[ISOMonth]]');
                $id = self::getSlotInt($this_, '[[ISODay]]');
                $instParts = self::isoToCalendarParts($cal, $iy, $im, $id);
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
            $h = self::getSlotInt($this_, '[[ISOHour]]');
            $min = self::getSlotInt($this_, '[[ISOMinute]]');
            $s = self::getSlotInt($this_, '[[ISOSecond]]');
            $ms = self::getSlotInt($this_, '[[ISOMillisecond]]');
            $us = self::getSlotInt($this_, '[[ISOMicrosecond]]');
            $ns = self::getSlotInt($this_, '[[ISONanosecond]]');
            $any = false;
            $monthWasSet = false;
            $userMonthCode = null;
            // Read fields in ALPHABETICAL order per spec:
            // day, hour, microsecond, millisecond, minute, month, monthCode, nanosecond, second, year
            $allFields = [
                'day' => &$dd, 'hour' => &$h,
                'microsecond' => &$us, 'millisecond' => &$ms,
                'minute' => &$min, 'month' => &$m,
            ];
            foreach ($allFields as $name => &$ref) {
                $v = $item->get($name);
                if (!($v instanceof JsUndefined)) {
                    $n = TypeConversion::toNumber($v);
                    if (!is_finite($n)) {
                        throw new RangeError("{$name} must be finite");
                    }
                    $ref = (int) $n;
                    $any = true;
                    if ($name === 'month') {
                        $monthWasSet = true;
                    }
                }
            }
            unset($ref);
            $mcv = $item->get('monthCode');
            if (!($mcv instanceof JsUndefined)) {
                $mc = TypeConversion::toString($mcv);
                $mcMonth = self::parseMonthCode($mc, $cal);
                if ($monthWasSet && $m !== $mcMonth && !$useCalendarNative) {
                    throw new RangeError('month and monthCode must agree');
                }
                if (!$useCalendarNative) {
                    $m = $mcMonth;
                }
                $userMonthCode = $mc;
                $any = true;
            }
            $nsv = $item->get('nanosecond');
            if (!($nsv instanceof JsUndefined)) {
                $n = TypeConversion::toNumber($nsv);
                if (!is_finite($n)) {
                    throw new RangeError('nanosecond must be finite');
                }
                $ns = (int) $n;
                $any = true;
            }
            $sv = $item->get('second');
            if (!($sv instanceof JsUndefined)) {
                $n = TypeConversion::toNumber($sv);
                if (!is_finite($n)) {
                    throw new RangeError('second must be finite');
                }
                $s = (int) $n;
                $any = true;
            }
            $yv = $item->get('year');
            if (!($yv instanceof JsUndefined)) {
                $n = TypeConversion::toNumber($yv);
                if (!is_finite($n)) {
                    throw new RangeError('year must be finite');
                }
                $y = (int) $n;
                $any = true;
            }
            if (!$any) {
                throw new TypeError('at least one property required');
            }
            // Validate bounds before options (negative day/month).
            if ($m < 1 || $dd < 1) {
                throw new RangeError('month and day must be >= 1');
            }
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
                $mcForIso = $userMonthCode ?? ($monthWasSet ? null : $instMonthCode);
                $monthForIso = $userMonthCode === null ? $m : null;
                $isoParts = self::calendarPartsToIso($cal, $y, $mcForIso, $monthForIso, $dd);
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
                [$y, $m, $dd] = self::constrainISODate($y, $m, $dd);
                $h = max(0, min(23, $h));
                $min = max(0, min(59, $min));
                $s = max(0, min(59, $s));
                $ms = max(0, min(999, $ms));
                $us = max(0, min(999, $us));
                $ns = max(0, min(999, $ns));
            } else {
                self::validateISODate($y, $m, $dd);
                self::validateISOTime($h, $min, $s, $ms, $us, $ns);
            }
            return self::createPlainDateTimeObject(
                $y,
                $m,
                $dd,
                $h,
                $min,
                $s,
                $ms,
                $us,
                $ns,
                $cal,
            );
        }, 1);

        $d('until', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDateTime($this_);
            $other = self::toPlainDateTime($args[0] ?? JsUndefined::instance());
            $opts = self::getOptionsObject($args[1] ?? JsUndefined::instance());
            $newOpts = self::readDifferenceOptionsAlphabetical($opts, false);
            return self::plainDateTimeDifference($this_, $other, $newOpts);
        }, 1);

        $d('since', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDateTime($this_);
            $other = self::toPlainDateTime($args[0] ?? JsUndefined::instance());
            $opts = self::getOptionsObject($args[1] ?? JsUndefined::instance());
            $newOpts = self::readDifferenceOptionsAlphabetical($opts, true);
            $dur = self::plainDateTimeDifference($this_, $other, $newOpts, 1, $this_);
            return self::negateDuration($dur);
        }, 1);

        $d('round', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDateTime($this_);
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
            $unit = self::getTemporalUnit(
                $roundTo,
                'smallestUnit',
                ['day', 'hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'],
                true,
            );
            $roundingMode = self::getRoundingMode($roundTo, 'halfExpand');
            $increment = self::getRoundingIncrement($roundTo);
            if ($increment > 1) {
                if ($unit === 'day') {
                    throw new RangeError('roundingIncrement for day must be 1');
                }
                self::validateRoundingIncrement($unit, $increment);
            }
            // Convert to nanoseconds from midnight, round, convert back.
            $y = self::getSlotInt($this_, '[[ISOYear]]');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            $dd = self::getSlotInt($this_, '[[ISODay]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $timeNs = (self::getSlotInt($this_, '[[ISOHour]]') * 3600
                + self::getSlotInt($this_, '[[ISOMinute]]') * 60
                + self::getSlotInt($this_, '[[ISOSecond]]')) * 1000000000
                + self::getSlotInt($this_, '[[ISOMillisecond]]') * 1000000
                + self::getSlotInt($this_, '[[ISOMicrosecond]]') * 1000
                + self::getSlotInt($this_, '[[ISONanosecond]]');
            $unitNs = (int) self::temporalUnitToNs($unit);
            $incNs = $unitNs * $increment;
            $rounded = self::roundToIncrement($timeNs, $incNs, $roundingMode);
            // Handle day overflow.
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
            // Add extra days.
            if ($extraDays !== 0) {
                $dateObj = self::createPlainDateObject($y, $m, $dd, $cal);
                $durObj = self::createDurationObject(0, 0, 0, $extraDays, 0, 0, 0, 0, 0, 0);
                $newDate = self::plainDateAdd($dateObj, $durObj, 1);
                $y = self::getSlotInt($newDate, '[[ISOYear]]');
                $m = self::getSlotInt($newDate, '[[ISOMonth]]');
                $dd = self::getSlotInt($newDate, '[[ISODay]]');
            }
            return self::createPlainDateTimeObject($y, $m, $dd, $h, $min, $s, $ms, $us, $ns, $cal);
        }, 1);

        $d('toZonedDateTime', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDateTime($this_);
            $tzArg = $args[0] ?? JsUndefined::instance();
            $tzName = self::toTemporalTimeZoneIdentifier($tzArg);
            $options = self::getOptionsObject($args[1] ?? JsUndefined::instance());
            $disam = 'compatible';
            if ($options instanceof JsObject) {
                $dv = $options->get('disambiguation');
                if (!($dv instanceof JsUndefined)) {
                    $disam = TypeConversion::toString($dv);
                    $valid = ['compatible', 'earlier', 'later', 'reject'];
                    if (!in_array($disam, $valid, true)) {
                        throw new RangeError("Invalid disambiguation: {$disam}");
                    }
                }
            }
            $y = self::getSlotInt($this_, '[[ISOYear]]');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            $dd = self::getSlotInt($this_, '[[ISODay]]');
            $h = self::getSlotInt($this_, '[[ISOHour]]');
            $min = self::getSlotInt($this_, '[[ISOMinute]]');
            $s = self::getSlotInt($this_, '[[ISOSecond]]');
            $ms = self::getSlotInt($this_, '[[ISOMillisecond]]');
            $us = self::getSlotInt($this_, '[[ISOMicrosecond]]');
            $ns = self::getSlotInt($this_, '[[ISONanosecond]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $epochNs = self::isoDateTimeToEpochNsDisambiguated(
                $y,
                $m,
                $dd,
                $h,
                $min,
                $s,
                $ms,
                $us,
                $ns,
                $tzName,
                $disam,
            );
            return self::createZonedDateTimeObject($epochNs, $tzName, $cal);
        }, 1);

        $d('withCalendar', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDateTime($this_);
            $calArg = $args[0] ?? JsUndefined::instance();
            $cal = self::toCalendarSlotValue($calArg);
            return self::createPlainDateTimeObject(
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
                self::getSlotInt($this_, '[[ISOHour]]'),
                self::getSlotInt($this_, '[[ISOMinute]]'),
                self::getSlotInt($this_, '[[ISOSecond]]'),
                self::getSlotInt($this_, '[[ISOMillisecond]]'),
                self::getSlotInt($this_, '[[ISOMicrosecond]]'),
                self::getSlotInt($this_, '[[ISONanosecond]]'),
                $cal,
            );
        }, 1);

        self::setToStringTag($proto, 'Temporal.PlainDateTime');
        self::installTemporalToPrimitive($proto, 'PlainDateTime');

        $ctor = JsFunction::fromCallable('PlainDateTime', function (JsValue $this_, array $args) use ($proto): JsValue {
            if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                throw new TypeError('Temporal.PlainDateTime must be called with new');
            }
            self::applyNewTargetPrototype($this_, $proto);
            $toInt = static function (JsValue $v, string $name): int {
                $n = TypeConversion::toNumber($v);
                if (!is_finite($n)) {
                    throw new RangeError("{$name} must be finite");
                }
                return (int) $n;
            };
            $y = $toInt($args[0] ?? JsUndefined::instance(), 'year');
            $m = $toInt($args[1] ?? JsUndefined::instance(), 'month');
            $dd = $toInt($args[2] ?? JsUndefined::instance(), 'day');
            $h = isset($args[3]) && !($args[3] instanceof JsUndefined) ? $toInt($args[3], 'hour') : 0;
            $min = isset($args[4]) && !($args[4] instanceof JsUndefined) ? $toInt($args[4], 'minute') : 0;
            $s = isset($args[5]) && !($args[5] instanceof JsUndefined) ? $toInt($args[5], 'second') : 0;
            $ms = isset($args[6]) && !($args[6] instanceof JsUndefined) ? $toInt($args[6], 'millisecond') : 0;
            $us = isset($args[7]) && !($args[7] instanceof JsUndefined) ? $toInt($args[7], 'microsecond') : 0;
            $ns = isset($args[8]) && !($args[8] instanceof JsUndefined) ? $toInt($args[8], 'nanosecond') : 0;
            $cal = 'iso8601';
            if (isset($args[9]) && !($args[9] instanceof JsUndefined)) {
                $cal = self::toCalendarSlotValue($args[9], false);
            }
            self::validateISODate($y, $m, $dd);
            self::validateISOTime($h, $min, $s, $ms, $us, $ns);
            // Use createPlainDateTimeObject for range validation.
            $result = self::createPlainDateTimeObject(
                $y,
                $m,
                $dd,
                $h,
                $min,
                $s,
                $ms,
                $us,
                $ns,
                $cal,
            );
            // Copy slots to $this_.
            self::setDateSlots($this_, $y, $m, $dd, $cal);
            self::setTimeSlots($this_, $h, $min, $s, $ms, $us, $ns);
            $this_->defineOwnProperty('[[IsPlainDateTime]]', PropertyDescriptor::data(new JsBoolean(true), false, false, false));
            return $this_;
        }, 3);
        $ctor->setConstructable();

        $ctor->defineOwnProperty('from', PropertyDescriptor::data(
            JsFunction::fromCallable('from', function (JsValue $this_, array $args): JsValue {
                $item = $args[0] ?? JsUndefined::instance();
                $rawOptions = $args[1] ?? JsUndefined::instance();
                // Type check primitives BEFORE reading options per spec.
                if (
                    $item instanceof JsUndefined || $item instanceof JsNull
                    || $item instanceof JsNumber || $item instanceof \Phasis\Value\JsBigInt
                    || $item instanceof JsBoolean || $item instanceof \Phasis\Value\JsSymbol
                ) {
                    return self::toPlainDateTime($item);
                }
                if ($item instanceof JsString) {
                    $result = self::toPlainDateTime($item);
                    $options = self::getOptionsObject($rawOptions);
                    if ($options instanceof JsObject) {
                        $ov = $options->get('overflow');
                        if (!($ov instanceof JsUndefined)) {
                            $ovStr = TypeConversion::toString($ov);
                            if ($ovStr !== 'constrain' && $ovStr !== 'reject') {
                                throw new RangeError("Invalid overflow: {$ovStr}");
                            }
                        }
                    }
                    return $result;
                }
                if (
                    $item instanceof JsObject
                    && (
                        $item->has('[[IsPlainDateTime]]')
                        || $item->has('[[IsPlainDate]]')
                        || $item->has('[[IsZonedDateTime]]')
                    )
                ) {
                    $result = self::toPlainDateTime($item);
                    $options = self::getOptionsObject($rawOptions);
                    self::getOverflow($options);
                    return $result;
                }
                return self::toPlainDateTime($item, 'constrain', $rawOptions);
            }, 1),
            true,
            false,
            true,
        ));

        $ctor->defineOwnProperty('compare', PropertyDescriptor::data(
            JsFunction::fromCallable('compare', function (JsValue $this_, array $args): JsValue {
                $one = self::toPlainDateTime($args[0] ?? JsUndefined::instance());
                $two = self::toPlainDateTime($args[1] ?? JsUndefined::instance());
                $cmpDate = self::compareISODate(
                    self::getSlotInt($one, '[[ISOYear]]'),
                    self::getSlotInt($one, '[[ISOMonth]]'),
                    self::getSlotInt($one, '[[ISODay]]'),
                    self::getSlotInt($two, '[[ISOYear]]'),
                    self::getSlotInt($two, '[[ISOMonth]]'),
                    self::getSlotInt($two, '[[ISODay]]'),
                );
                if ($cmpDate !== 0) {
                    return JsNumber::of((float) $cmpDate);
                }
                return JsNumber::of((float) self::compareISOTime(
                    self::getSlotInt($one, '[[ISOHour]]'),
                    self::getSlotInt($one, '[[ISOMinute]]'),
                    self::getSlotInt($one, '[[ISOSecond]]'),
                    self::getSlotInt($one, '[[ISOMillisecond]]'),
                    self::getSlotInt($one, '[[ISOMicrosecond]]'),
                    self::getSlotInt($one, '[[ISONanosecond]]'),
                    self::getSlotInt($two, '[[ISOHour]]'),
                    self::getSlotInt($two, '[[ISOMinute]]'),
                    self::getSlotInt($two, '[[ISOSecond]]'),
                    self::getSlotInt($two, '[[ISOMillisecond]]'),
                    self::getSlotInt($two, '[[ISOMicrosecond]]'),
                    self::getSlotInt($two, '[[ISONanosecond]]'),
                ));
            }, 2),
            true,
            false,
            true,
        ));

        $ctor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($ctor, true, false, true));

        $temporal->defineOwnProperty('PlainDateTime', PropertyDescriptor::data($ctor, true, false, true));
        self::$plainDateTimeProto = $proto;

        return $proto;
    }
}
