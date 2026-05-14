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
 * Temporal.PlainYearMonth type installer. Composed into TemporalObject
 * via `use Temporal\PlainYearMonthSection;` — the per-section split is
 * purely organisational. `self::` references resolve into the
 * composing class so cross-section helpers continue to work.
 */
trait PlainYearMonthSection
{
    // -----------------------------------------------------------------------
    // Temporal.PlainYearMonth
    // -----------------------------------------------------------------------

    private static ?JsObject $plainYearMonthProto = null;

    private static function installPlainYearMonth(JsObject $temporal, Environment $env): JsObject
    {
        $proto = new JsObject();

        self::defineGetter($proto, 'calendarId', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            return new JsString(self::getSlotString($this_, '[[Calendar]]'));
        });
        self::defineGetter($proto, 'year', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
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
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
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
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
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
        self::defineGetter($proto, 'daysInMonth', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $iy = self::getSlotInt($this_, '[[ISOYear]]');
            $im = self::getSlotInt($this_, '[[ISOMonth]]');
            $id = self::getSlotInt($this_, '[[ISODay]]');
            $count = self::calendarDaysInMonthForIso($cal, $iy, $im, $id);
            return JsNumber::of((float) ($count ?? self::isoDaysInMonth($iy, $im)));
        });
        self::defineGetter($proto, 'daysInYear', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $iy = self::getSlotInt($this_, '[[ISOYear]]');
            $im = self::getSlotInt($this_, '[[ISOMonth]]');
            $id = self::getSlotInt($this_, '[[ISODay]]');
            $count = self::calendarDaysInYearForIso($cal, $iy, $im, $id);
            return JsNumber::of((float) ($count ?? self::isoDaysInYear($iy)));
        });
        self::defineGetter($proto, 'monthsInYear', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
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
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            return new JsBoolean(self::isoIsLeapYear(self::getSlotInt($this_, '[[ISOYear]]')));
        });
        self::defineGetter($proto, 'era', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $y = self::getSlotInt($this_, '[[ISOYear]]');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            $d = self::getSlotInt($this_, '[[ISODay]]');
            $era = self::deriveEra($cal, $y, $m, $d);
            return $era === null ? JsUndefined::instance() : new JsString($era);
        });
        self::defineGetter($proto, 'eraYear', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $y = self::getSlotInt($this_, '[[ISOYear]]');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            $d = self::getSlotInt($this_, '[[ISODay]]');
            $eraYear = self::deriveEraYear($cal, $y, $m, $d);
            return $eraYear === null ? JsUndefined::instance() : JsNumber::of((float) $eraYear);
        });

        $d = self::protoHelper($proto);

        $d('toString', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
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
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $showCal = $calendarName === 'always' || $calendarName === 'critical'
                || ($calendarName !== 'never' && $cal !== 'iso8601');
            $base = self::padISOYear($y) . '-' . self::pad2($m);
            if ($showCal) {
                $base .= '-' . self::pad2($dd);
                $prefix = $calendarName === 'critical' ? '!' : '';
                $base .= "[{$prefix}u-ca={$cal}]";
            } elseif ($cal !== 'iso8601') {
                // Non-ISO calendar with calendarName="never": still
                // include the day so the year-month is unambiguous.
                $base .= '-' . self::pad2($dd);
            }
            return new JsString($base);
        }, 0);

        $d('toJSON', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            $y = self::getSlotInt($this_, '[[ISOYear]]');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            $dd = self::getSlotInt($this_, '[[ISODay]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            // Non-ISO calendars need the day in toJSON so the
            // resulting string can round-trip through PlainYearMonth.from.
            if ($cal !== 'iso8601') {
                return new JsString(
                    self::padISOYear($y) . '-' . self::pad2($m) . '-' . self::pad2($dd)
                    . "[u-ca={$cal}]"
                );
            }
            return new JsString(self::padISOYear($y) . '-' . self::pad2($m));
        }, 0);

        $d('toLocaleString', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            $y = self::getSlotInt($this_, '[[ISOYear]]');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            $fallback = self::padISOYear($y) . '-' . self::pad2($m);
            return self::temporalToLocaleString($this_, $args, $fallback);
        }, 0);

        $d('valueOf', function (JsValue $this_): JsValue {
            throw new TypeError('Temporal.PlainYearMonth does not implement valueOf');
        }, 0);

        $d('equals', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            $other = self::toPlainYearMonth($args[0] ?? JsUndefined::instance());
            return new JsBoolean(
                self::getSlotInt($this_, '[[ISOYear]]') === self::getSlotInt($other, '[[ISOYear]]')
                && self::getSlotInt($this_, '[[ISOMonth]]') === self::getSlotInt($other, '[[ISOMonth]]')
                && self::getSlotInt($this_, '[[ISODay]]') === self::getSlotInt($other, '[[ISODay]]')
                && self::getSlotString($this_, '[[Calendar]]') === self::getSlotString($other, '[[Calendar]]'),
            );
        }, 1);

        $d('toPlainDate', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            $item = $args[0] ?? JsUndefined::instance();
            if (!$item instanceof JsObject) {
                throw new TypeError('argument must be an object');
            }
            $dayVal = $item->get('day');
            if ($dayVal instanceof JsUndefined) {
                throw new TypeError('day is required');
            }
            $dayNum = TypeConversion::toNumber($dayVal);
            if (!is_finite($dayNum)) {
                throw new RangeError('day must be finite');
            }
            $dd = (int) $dayNum;
            $yy = self::getSlotInt($this_, '[[ISOYear]]');
            $mm = self::getSlotInt($this_, '[[ISOMonth]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            // Default overflow is constrain: clamp day to days-in-month.
            $dim = self::isoDaysInMonth($yy, $mm);
            if ($dd > $dim) {
                $dd = $dim;
            }
            if ($dd < 1) {
                $dd = 1;
            }
            return self::createPlainDateObject($yy, $mm, $dd, $cal);
        }, 1);

        $d('with', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            $item = $args[0] ?? JsUndefined::instance();
            if (!$item instanceof JsObject) {
                throw new TypeError('argument must be an object');
            }
            self::rejectObjectWithCalendarOrTimeZone($item);
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $useCalendarNative = $cal !== 'iso8601' && !in_array($cal, ['gregory', 'roc', 'japanese'], true);
            $instMonthCode = null;
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
                $refDay = $instParts['day'];
                $instMonthCode = $instParts['monthCode'];
            } else {
                $y = self::getSlotInt($this_, '[[ISOYear]]');
                $m = self::getSlotInt($this_, '[[ISOMonth]]');
                $refDay = self::getSlotInt($this_, '[[ISODay]]');
            }
            $any = false;
            $userMonthCode = null;
            $monthFromVal = null;
            // Read in alphabetical order: month, monthCode, year.
            $monthVal = $item->get('month');
            if (!($monthVal instanceof JsUndefined)) {
                $mNum = TypeConversion::toNumber($monthVal);
                if (!is_finite($mNum)) {
                    throw new RangeError('month must be finite');
                }
                $monthFromVal = (int) $mNum;
                $m = $monthFromVal;
                $any = true;
            }
            $monthCodeVal = $item->get('monthCode');
            if (!($monthCodeVal instanceof JsUndefined)) {
                $mc = TypeConversion::toString($monthCodeVal);
                $monthFromCode = self::parseMonthCode($mc, $cal);
                if ($monthFromVal !== null && $monthFromVal !== $monthFromCode && !$useCalendarNative) {
                    throw new RangeError("month and monthCode disagree");
                }
                if (!$useCalendarNative) {
                    $m = $monthFromCode;
                }
                $userMonthCode = $mc;
                $any = true;
            }
            $yearVal = $item->get('year');
            if (!($yearVal instanceof JsUndefined)) {
                $yNum = TypeConversion::toNumber($yearVal);
                if (!is_finite($yNum)) {
                    throw new RangeError('year must be finite');
                }
                $y = (int) $yNum;
                $any = true;
            }
            if (!$any) {
                throw new TypeError('At least one temporal property must be provided');
            }
            if ($m < 1) {
                throw new RangeError('month must be >= 1');
            }
            $options = self::getOptionsObject($args[1] ?? JsUndefined::instance());
            $overflow = self::getOverflow($options);
            if ($useCalendarNative) {
                $mcForIso = $userMonthCode ?? ($monthFromVal !== null ? null : $instMonthCode);
                $monthForIso = $userMonthCode === null ? $m : null;
                $isoParts = self::calendarPartsToIso($cal, $y, $mcForIso, $monthForIso, 1);
                if ($isoParts !== null) {
                    return self::createPlainYearMonthObject($isoParts['year'], $isoParts['month'], $isoParts['day'], $cal);
                }
            }
            if ($overflow === 'constrain') {
                $m = max(1, min(12, $m));
            } elseif ($m > 12) {
                throw new RangeError("month {$m} out of range");
            }
            return self::createPlainYearMonthObject($y, $m, $refDay, $cal);
        }, 1);

        $d('add', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            $dur = self::toDuration($args[0] ?? JsUndefined::instance());
            $y = self::getSlotInt($this_, '[[ISOYear]]');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $options = self::getOptionsObject($args[1] ?? JsUndefined::instance());
            $overflow = self::getOverflow($options);
            $result = self::addDurationToYearMonth(1, $y, $m, $cal, $dur, $overflow);
            return self::createPlainYearMonthObject($result[0], $result[1], 1, $cal);
        }, 1);

        $d('subtract', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            $dur = self::toDuration($args[0] ?? JsUndefined::instance());
            $y = self::getSlotInt($this_, '[[ISOYear]]');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $options = self::getOptionsObject($args[1] ?? JsUndefined::instance());
            $overflow = self::getOverflow($options);
            $result = self::addDurationToYearMonth(-1, $y, $m, $cal, $dur, $overflow);
            return self::createPlainYearMonthObject($result[0], $result[1], 1, $cal);
        }, 1);

        $d('until', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            $other = self::toPlainYearMonth($args[0] ?? JsUndefined::instance());
            return self::plainYearMonthDifference($this_, $other, $args[1] ?? JsUndefined::instance());
        }, 1);

        $d('since', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            $other = self::toPlainYearMonth($args[0] ?? JsUndefined::instance());
            return self::plainYearMonthDifference($other, $this_, $args[1] ?? JsUndefined::instance());
        }, 1);

        self::setToStringTag($proto, 'Temporal.PlainYearMonth');
        self::installTemporalToPrimitive($proto, 'PlainYearMonth');

        $ctor = JsFunction::fromCallable('PlainYearMonth', function (JsValue $this_, array $args) use ($proto): JsValue {
            if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                throw new TypeError('Temporal.PlainYearMonth must be called with new');
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
            $cal = 'iso8601';
            if (isset($args[2]) && !($args[2] instanceof JsUndefined)) {
                $cal = self::toCalendarSlotValue($args[2], false);
            }
            $refDay = isset($args[3]) && !($args[3] instanceof JsUndefined) ? (int) TypeConversion::toNumber($args[3]) : 1;
            // PYM constructor: validate month+day but NOT full ISO date range.
            // PYM allows boundary months that contain some valid dates.
            if ($m < 1 || $m > 12) {
                throw new RangeError("Invalid month: {$m}");
            }
            if ($refDay < 1 || $refDay > self::isoDaysInMonth($y, $m)) {
                throw new RangeError("Invalid day: {$refDay}");
            }
            if ($y < self::ISO_YEAR_MIN || $y > self::ISO_YEAR_MAX) {
                throw new RangeError("Year out of range: {$y}");
            }
            if ($y === self::ISO_YEAR_MIN && $m < 4) {
                throw new RangeError("YearMonth outside representable range");
            }
            if ($y === self::ISO_YEAR_MAX && $m > 9) {
                throw new RangeError("YearMonth outside representable range");
            }
            $p = $this_->getPrototype();
            $hasProto = false;
            while ($p !== null) {
                if ($p === $proto) {
                    $hasProto = true;
                    break;
                }
                $p = $p->getPrototype();
            }
            if (!$hasProto) {
                $this_->setPrototype($proto);
            }
            self::setDateSlots($this_, $y, $m, $refDay, $cal);
            $this_->defineOwnProperty('[[IsPlainYearMonth]]', PropertyDescriptor::data(new JsBoolean(true), false, false, false));
            return $this_;
        }, 2);
        $ctor->setConstructable();

        $ctor->defineOwnProperty('from', PropertyDescriptor::data(
            JsFunction::fromCallable('from', function (JsValue $this_, array $args): JsValue {
                $item = $args[0] ?? JsUndefined::instance();
                // Type check primitives BEFORE reading options.
                if (
                    $item instanceof JsUndefined || $item instanceof JsNull
                    || $item instanceof JsNumber || $item instanceof \Phasis\Value\JsBigInt
                    || $item instanceof JsBoolean || $item instanceof \Phasis\Value\JsSymbol
                ) {
                    return self::toPlainYearMonth($item);
                }
                if ($item instanceof JsObject && $item->has('[[IsPlainYearMonth]]')) {
                    $options = self::getOptionsObject($args[1] ?? JsUndefined::instance());
                    self::getOverflow($options);
                    return self::createPlainYearMonthObject(
                        self::getSlotInt($item, '[[ISOYear]]'),
                        self::getSlotInt($item, '[[ISOMonth]]'),
                        self::getSlotInt($item, '[[ISODay]]'),
                        self::getSlotString($item, '[[Calendar]]'),
                    );
                }
                if ($item instanceof JsObject) {
                    // Read fields first, then read overflow, per spec.
                    $rawOptions = $args[1] ?? JsUndefined::instance();
                    return self::toPlainYearMonthWithLazyOptions($item, $rawOptions);
                }
                $result = self::toPlainYearMonth($item);
                $options = self::getOptionsObject($args[1] ?? JsUndefined::instance());
                self::getOverflow($options);
                return $result;
            }, 1),
            true,
            false,
            true,
        ));

        $ctor->defineOwnProperty('compare', PropertyDescriptor::data(
            JsFunction::fromCallable('compare', function (JsValue $this_, array $args): JsValue {
                $one = self::toPlainYearMonth($args[0] ?? JsUndefined::instance());
                $two = self::toPlainYearMonth($args[1] ?? JsUndefined::instance());
                $c = self::getSlotInt($one, '[[ISOYear]]') <=> self::getSlotInt($two, '[[ISOYear]]');
                if ($c !== 0) {
                    return JsNumber::of((float) $c);
                }
                $cm = self::getSlotInt($one, '[[ISOMonth]]') <=> self::getSlotInt($two, '[[ISOMonth]]');
                if ($cm !== 0) {
                    return JsNumber::of((float) $cm);
                }
                return JsNumber::of((float) (self::getSlotInt($one, '[[ISODay]]') <=> self::getSlotInt($two, '[[ISODay]]')));
            }, 2),
            true,
            false,
            true,
        ));

        $ctor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($ctor, true, false, true));

        $temporal->defineOwnProperty('PlainYearMonth', PropertyDescriptor::data($ctor, true, false, true));
        self::$plainYearMonthProto = $proto;

        return $proto;
    }
}
