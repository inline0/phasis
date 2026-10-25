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
 * Temporal.PlainMonthDay type installer. Composed into TemporalObject
 * via `use Temporal\PlainMonthDaySection;` — the per-section split is
 * purely organisational. `self::` references resolve into the
 * composing class so cross-section helpers continue to work.
 */
trait PlainMonthDaySection
{
    // -----------------------------------------------------------------------
    // Temporal.PlainMonthDay
    // -----------------------------------------------------------------------

    private static ?JsObject $plainMonthDayProto = null;

    private static function installPlainMonthDay(JsObject $temporal, Environment $env): JsObject
    {
        $proto = new JsObject();

        self::defineGetter($proto, 'calendarId', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainMonthDay]]', 'Temporal.PlainMonthDay');
            return new JsString(self::getSlotString($this_, '[[Calendar]]'));
        });
        self::defineGetter($proto, 'monthCode', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainMonthDay]]', 'Temporal.PlainMonthDay');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            if ($cal !== 'iso8601' && !in_array($cal, ['gregory', 'roc', 'japanese'], true)) {
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
            self::requireBrand($this_, '[[IsPlainMonthDay]]', 'Temporal.PlainMonthDay');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            if ($cal !== 'iso8601' && !in_array($cal, ['gregory', 'roc', 'japanese'], true)) {
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

        $d = self::protoHelper($proto);

        $d('toString', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsPlainMonthDay]]', 'Temporal.PlainMonthDay');
            $options = self::getOptionsObject($args[0] ?? JsUndefined::instance());
            $calendarName = 'auto';
            if ($options instanceof JsObject) {
                $cn = $options->get('calendarName');
                if (!($cn instanceof JsUndefined)) {
                    $calendarName = TypeConversion::toString($cn);
                    $validCN = ['auto', 'always', 'never', 'critical'];
                    if (!in_array($calendarName, $validCN, true)) {
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
            if ($showCal) {
                $prefix = $calendarName === 'critical' ? '!' : '';
                return new JsString(self::padISOYear($y) . '-' . self::pad2($m) . '-' . self::pad2($dd) . "[{$prefix}u-ca={$cal}]");
            }
            // Non-ISO calendar with "never" still emits the full
            // YYYY-MM-DD form so the day is unambiguous (the year
            // anchors the date for calendar conversion). ISO
            // calendar collapses to just MM-DD.
            if ($cal !== 'iso8601') {
                return new JsString(self::padISOYear($y) . '-' . self::pad2($m) . '-' . self::pad2($dd));
            }
            return new JsString(self::pad2($m) . '-' . self::pad2($dd));
        }, 0);

        $d('toJSON', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainMonthDay]]', 'Temporal.PlainMonthDay');
            $y = self::getSlotInt($this_, '[[ISOYear]]');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            $dd = self::getSlotInt($this_, '[[ISODay]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            // When calendar is non-ISO, emit the full reference-year
            // form so the calendar annotation has context to anchor
            // against. ISO-only retains the bare MM-DD form.
            if ($cal !== 'iso8601') {
                return new JsString(
                    self::padISOYear($y) . '-' . self::pad2($m) . '-' . self::pad2($dd)
                    . "[u-ca={$cal}]"
                );
            }
            return new JsString(self::pad2($m) . '-' . self::pad2($dd));
        }, 0);

        $d('toLocaleString', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsPlainMonthDay]]', 'Temporal.PlainMonthDay');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            $dd = self::getSlotInt($this_, '[[ISODay]]');
            $fallback = self::pad2($m) . '-' . self::pad2($dd);
            return self::temporalToLocaleString($this_, $args, $fallback);
        }, 0);

        $d('valueOf', function (JsValue $this_): JsValue {
            throw new TypeError('Temporal.PlainMonthDay does not implement valueOf');
        }, 0);

        $d('equals', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsPlainMonthDay]]', 'Temporal.PlainMonthDay');
            $other = self::toPlainMonthDay($args[0] ?? JsUndefined::instance());
            return new JsBoolean(
                self::getSlotInt($this_, '[[ISOMonth]]') === self::getSlotInt($other, '[[ISOMonth]]')
                && self::getSlotInt($this_, '[[ISODay]]') === self::getSlotInt($other, '[[ISODay]]')
                && self::getSlotInt($this_, '[[ISOYear]]') === self::getSlotInt($other, '[[ISOYear]]')
                && self::getSlotString($this_, '[[Calendar]]') === self::getSlotString($other, '[[Calendar]]'),
            );
        }, 1);

        $d('with', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsPlainMonthDay]]', 'Temporal.PlainMonthDay');
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
            unset($fields['year']);
            $additional = self::prepareCalendarFields($item, $cal, ['day', 'month', 'monthCode', 'year']);
            if ($additional === []) {
                throw new TypeError('at least one temporal property required');
            }
            $fields = self::mergeCalendarFields($fields, $additional);
            $overflow = self::getOverflow(self::getOptionsObject($args[1] ?? JsUndefined::instance()));
            $date = self::calendarMonthDayFromFields($cal, $fields, $overflow);
            return self::createPlainMonthDayObject($date['month'], $date['day'], $date['year'], $cal);
        }, 1);

        $d('toPlainDate', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsPlainMonthDay]]', 'Temporal.PlainMonthDay');
            $item = $args[0] ?? JsUndefined::instance();
            if (!$item instanceof JsObject) {
                throw new TypeError('argument must be an object');
            }
            $calendar = self::getSlotString($this_, '[[Calendar]]');
            $fields = self::calendarFieldsForIso(
                $calendar,
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
            );
            unset($fields['year']);
            $fields = self::mergeCalendarFields($fields, self::prepareCalendarFields($item, $calendar, ['year']));
            $date = self::calendarDateFromFields($calendar, $fields, 'constrain');
            return self::createPlainDateObject($date['year'], $date['month'], $date['day'], $calendar);
        }, 1);

        self::setToStringTag($proto, 'Temporal.PlainMonthDay');
        self::installTemporalToPrimitive($proto, 'PlainMonthDay');

        $ctor = JsFunction::fromCallable('PlainMonthDay', function (JsValue $this_, array $args) use ($proto): JsValue {
            if (!$this_ instanceof JsObject || !$this_->hasNativeSlot('[[NewTarget]]')) {
                throw new TypeError('Temporal.PlainMonthDay must be called with new');
            }
            self::applyNewTargetPrototype($this_, $proto);
            $mNum = TypeConversion::toNumber($args[0] ?? JsUndefined::instance());
            if (!is_finite($mNum)) {
                throw new RangeError('month must be finite');
            }
            $m = (int) $mNum;
            $dNum = TypeConversion::toNumber($args[1] ?? JsUndefined::instance());
            if (!is_finite($dNum)) {
                throw new RangeError('day must be finite');
            }
            $dd = (int) $dNum;
            $cal = 'iso8601';
            if (isset($args[2]) && !($args[2] instanceof JsUndefined)) {
                $calArg = $args[2];
                if ($calArg instanceof JsNull) {
                    throw new TypeError('null is not a valid calendar');
                }
                if ($calArg instanceof JsBoolean) {
                    throw new TypeError('boolean is not a valid calendar');
                }
                if ($calArg instanceof JsNumber) {
                    throw new TypeError('number is not a valid calendar');
                }
                if ($calArg instanceof \Phasis\Value\JsBigInt) {
                    throw new TypeError('bigint is not a valid calendar');
                }
                if ($calArg instanceof \Phasis\Value\JsSymbol) {
                    throw new TypeError('Symbol is not a valid calendar');
                }
                if ($calArg instanceof JsObject) {
                    throw new TypeError('object is not a valid calendar');
                }
                $cal = strtolower(TypeConversion::toString($calArg));
                $cal = self::resolveCalendarId($cal);
            }
            $refYear = 1972;
            if (isset($args[3]) && !($args[3] instanceof JsUndefined)) {
                $ryNum = TypeConversion::toNumber($args[3]);
                if (!is_finite($ryNum)) {
                    throw new RangeError('referenceISOYear must be finite');
                }
                $refYear = (int) $ryNum;
            }
            self::validateISODate($refYear, $m, $dd);
            self::setDateSlots($this_, $refYear, $m, $dd, $cal);
            $this_->defineOwnProperty('[[IsPlainMonthDay]]', PropertyDescriptor::data(new JsBoolean(true), false, false, false));
            return $this_;
        }, 2);
        $ctor->setConstructable();

        $ctor->defineOwnProperty('from', PropertyDescriptor::data(
            JsFunction::fromCallable('from', function (JsValue $this_, array $args): JsValue {
                $item = $args[0] ?? JsUndefined::instance();
                $rawOptions = $args[1] ?? JsUndefined::instance();
                // For strings and PlainMonthDay instances, process first then validate options.
                if ($item instanceof JsString || ($item instanceof JsObject && $item->hasNativeSlot('[[IsPlainMonthDay]]'))) {
                    $result = self::toPlainMonthDay($item);
                    $options = self::getOptionsObject($rawOptions);
                    self::getOverflow($options);
                    return $result;
                }
                // For property bags, per spec: read fields first, then read overflow.
                // We need to extract fields first, then read overflow, then apply overflow.
                // Pass options lazily to toPlainMonthDay.
                return self::toPlainMonthDayWithLazyOptions($item, $rawOptions);
            }, 1),
            true,
            false,
            true,
        ));

        $ctor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($ctor, true, false, true));

        $temporal->defineOwnProperty('PlainMonthDay', PropertyDescriptor::data($ctor, true, false, true));
        self::$plainMonthDayProto = $proto;

        return $proto;
    }
}
