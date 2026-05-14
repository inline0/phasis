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
 * Temporal.PlainTime type installer. Composed into TemporalObject
 * via `use Temporal\PlainTimeSection;` — the per-section split is
 * purely organisational. `self::` references resolve into the
 * composing class so cross-section helpers continue to work.
 */
trait PlainTimeSection
{
    // -----------------------------------------------------------------------
    // Temporal.PlainTime
    // -----------------------------------------------------------------------

    private static ?JsObject $plainTimeProto = null;

    private static function installPlainTime(JsObject $temporal, Environment $env): JsObject
    {
        $proto = new JsObject();

        $timeGetters = [
            'hour' => '[[ISOHour]]',
            'minute' => '[[ISOMinute]]',
            'second' => '[[ISOSecond]]',
            'millisecond' => '[[ISOMillisecond]]',
            'microsecond' => '[[ISOMicrosecond]]',
            'nanosecond' => '[[ISONanosecond]]',
        ];
        foreach ($timeGetters as $name => $slot) {
            self::defineGetter($proto, $name, function (JsValue $this_) use ($slot): JsValue {
                self::requirePlainTime($this_);
                return JsNumber::of((float) self::getSlotInt($this_, $slot));
            });
        }

        $d = self::protoHelper($proto);

        $d('toString', function (JsValue $this_, array $args): JsValue {
            self::requirePlainTime($this_);
            $options = self::getOptionsObject($args[0] ?? JsUndefined::instance());
            $fractionalSecondDigits = self::getFractionalSecondDigits($options);
            $roundingMode = self::getRoundingMode($options, 'trunc');
            $smallestUnit = null;
            if ($options instanceof JsObject) {
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
            // Apply rounding.
            $time = $this_;
            if ($smallestUnit !== null && $smallestUnit !== 'nanosecond') {
                $unitNsMap = [
                    'minute' => 60000000000,
                    'second' => 1000000000,
                    'millisecond' => 1000000,
                    'microsecond' => 1000,
                ];
                $time = self::roundPlainTime($this_, $smallestUnit, $roundingMode, 1);
            } elseif ($smallestUnit === null && is_int($fractionalSecondDigits) && $fractionalSecondDigits < 9) {
                $digitsToUnit = [
                    0 => 'second', 1 => 'second', 2 => 'second',
                    3 => 'millisecond', 4 => 'millisecond', 5 => 'millisecond',
                    6 => 'microsecond', 7 => 'microsecond', 8 => 'microsecond',
                ];
                $digitsToIncr = [
                    0 => 1000000000, 1 => 100000000, 2 => 10000000,
                    3 => 1000000, 4 => 100000, 5 => 10000,
                    6 => 1000, 7 => 100, 8 => 10,
                ];
                $timeNs = self::timeToNs($this_);
                $rounded = self::roundToIncrement($timeNs, $digitsToIncr[$fractionalSecondDigits], $roundingMode);
                $rounded = $rounded % 86400000000000;
                if ($rounded < 0) {
                    $rounded += 86400000000000;
                }
                $time = self::createPlainTimeObject(
                    intdiv($rounded, 3600000000000),
                    intdiv($rounded % 3600000000000, 60000000000),
                    intdiv($rounded % 60000000000, 1000000000),
                    intdiv($rounded % 1000000000, 1000000),
                    intdiv($rounded % 1000000, 1000),
                    $rounded % 1000,
                );
            }
            if ($smallestUnit === 'minute') {
                $h = self::getSlotInt($time, '[[ISOHour]]');
                $min = self::getSlotInt($time, '[[ISOMinute]]');
                return new JsString(self::pad2($h) . ':' . self::pad2($min));
            }
            return new JsString(self::plainTimeToString($time, $fractionalSecondDigits, 'trunc'));
        }, 0);

        $d('toJSON', function (JsValue $this_): JsValue {
            self::requirePlainTime($this_);
            return new JsString(self::plainTimeToString($this_, 'auto', 'trunc'));
        }, 0);

        $d('toLocaleString', function (JsValue $this_, array $args): JsValue {
            self::requirePlainTime($this_);
            $fallback = self::plainTimeToString($this_, 'auto', 'trunc');
            return self::temporalToLocaleString($this_, $args, $fallback);
        }, 0);

        $d('valueOf', function (JsValue $this_): JsValue {
            throw new TypeError('Temporal.PlainTime does not implement valueOf');
        }, 0);

        $d('equals', function (JsValue $this_, array $args): JsValue {
            self::requirePlainTime($this_);
            $other = self::toPlainTime($args[0] ?? JsUndefined::instance());
            return new JsBoolean(
                self::getSlotInt($this_, '[[ISOHour]]') === self::getSlotInt($other, '[[ISOHour]]')
                && self::getSlotInt($this_, '[[ISOMinute]]') === self::getSlotInt($other, '[[ISOMinute]]')
                && self::getSlotInt($this_, '[[ISOSecond]]') === self::getSlotInt($other, '[[ISOSecond]]')
                && self::getSlotInt($this_, '[[ISOMillisecond]]') === self::getSlotInt($other, '[[ISOMillisecond]]')
                && self::getSlotInt($this_, '[[ISOMicrosecond]]') === self::getSlotInt($other, '[[ISOMicrosecond]]')
                && self::getSlotInt($this_, '[[ISONanosecond]]') === self::getSlotInt($other, '[[ISONanosecond]]'),
            );
        }, 1);

        $d('with', function (JsValue $this_, array $args): JsValue {
            self::requirePlainTime($this_);
            $item = $args[0] ?? JsUndefined::instance();
            if (!$item instanceof JsObject) {
                throw new TypeError('argument must be an object');
            }
            // RejectObjectWithCalendarOrTimeZone.
            self::rejectObjectWithCalendarOrTimeZone($item);
            $h = self::getSlotInt($this_, '[[ISOHour]]');
            $min = self::getSlotInt($this_, '[[ISOMinute]]');
            $s = self::getSlotInt($this_, '[[ISOSecond]]');
            $ms = self::getSlotInt($this_, '[[ISOMillisecond]]');
            $us = self::getSlotInt($this_, '[[ISOMicrosecond]]');
            $ns = self::getSlotInt($this_, '[[ISONanosecond]]');
            $any = false;
            // Read in alphabetical order per spec.
            $mapping = [
                'hour' => &$h, 'microsecond' => &$us,
                'millisecond' => &$ms, 'minute' => &$min,
                'nanosecond' => &$ns, 'second' => &$s,
            ];
            foreach ($mapping as $name => &$ref) {
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
                throw new TypeError('at least one time property required');
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
        }, 1);

        $d('add', function (JsValue $this_, array $args): JsValue {
            self::requirePlainTime($this_);
            $dur = self::toDuration($args[0] ?? JsUndefined::instance());
            return self::plainTimeAdd($this_, $dur, 1);
        }, 1);

        $d('subtract', function (JsValue $this_, array $args): JsValue {
            self::requirePlainTime($this_);
            $dur = self::toDuration($args[0] ?? JsUndefined::instance());
            return self::plainTimeAdd($this_, $dur, -1);
        }, 1);

        $d('until', function (JsValue $this_, array $args): JsValue {
            self::requirePlainTime($this_);
            $other = self::toPlainTime($args[0] ?? JsUndefined::instance());
            return self::plainTimeDifference($this_, $other, $args[1] ?? JsUndefined::instance());
        }, 1);

        $d('since', function (JsValue $this_, array $args): JsValue {
            self::requirePlainTime($this_);
            $other = self::toPlainTime($args[0] ?? JsUndefined::instance());
            return self::plainTimeDifference($other, $this_, $args[1] ?? JsUndefined::instance());
        }, 1);

        $d('round', function (JsValue $this_, array $args): JsValue {
            self::requirePlainTime($this_);
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
            $unit = self::getTemporalUnit($roundTo, 'smallestUnit', ['hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'], true);
            $roundingMode = self::getRoundingMode($roundTo, 'halfExpand');
            $increment = self::getRoundingIncrement($roundTo);
            if ($increment > 1) {
                self::validateRoundingIncrement($unit, $increment);
            }
            return self::roundPlainTime($this_, $unit, $roundingMode, $increment);
        }, 1);

        self::setToStringTag($proto, 'Temporal.PlainTime');
        self::installTemporalToPrimitive($proto, 'PlainTime');

        $ctor = JsFunction::fromCallable('PlainTime', function (JsValue $this_, array $args) use ($proto): JsValue {
            if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                throw new TypeError('Temporal.PlainTime must be called with new');
            }
            self::applyNewTargetPrototype($this_, $proto);
            $toInt = static function (JsValue $v, string $name): int {
                $n = TypeConversion::toNumber($v);
                if (!is_finite($n)) {
                    throw new RangeError("{$name} must be finite");
                }
                return (int) $n;
            };
            $h = isset($args[0]) && !($args[0] instanceof JsUndefined) ? $toInt($args[0], 'hour') : 0;
            $min = isset($args[1]) && !($args[1] instanceof JsUndefined) ? $toInt($args[1], 'minute') : 0;
            $s = isset($args[2]) && !($args[2] instanceof JsUndefined) ? $toInt($args[2], 'second') : 0;
            $ms = isset($args[3]) && !($args[3] instanceof JsUndefined) ? $toInt($args[3], 'millisecond') : 0;
            $us = isset($args[4]) && !($args[4] instanceof JsUndefined) ? $toInt($args[4], 'microsecond') : 0;
            $ns = isset($args[5]) && !($args[5] instanceof JsUndefined) ? $toInt($args[5], 'nanosecond') : 0;
            self::validateISOTime($h, $min, $s, $ms, $us, $ns);
            self::setTimeSlots($this_, $h, $min, $s, $ms, $us, $ns);
            $this_->defineOwnProperty('[[IsPlainTime]]', PropertyDescriptor::data(new JsBoolean(true), false, false, false));
            return $this_;
        }, 0);
        $ctor->setConstructable();

        $ctor->defineOwnProperty('from', PropertyDescriptor::data(
            JsFunction::fromCallable('from', function (JsValue $this_, array $args): JsValue {
                $item = $args[0] ?? JsUndefined::instance();
                if (
                    $item instanceof JsUndefined || $item instanceof JsNull
                    || $item instanceof JsNumber || $item instanceof \Phasis\Value\JsBigInt
                    || $item instanceof JsBoolean || $item instanceof \Phasis\Value\JsSymbol
                ) {
                    return self::toPlainTime($item);
                }
                // For strings/PlainTime/PlainDateTime/ZonedDateTime: convert first, then validate options.
                if (
                    $item instanceof JsString || ($item instanceof JsObject && (
                    $item->has('[[IsPlainTime]]') || $item->has('[[IsPlainDateTime]]') || $item->has('[[IsZonedDateTime]]')
                    ))
                ) {
                    $result = self::toPlainTime($item);
                    $options = self::getOptionsObject($args[1] ?? JsUndefined::instance());
                    self::getOverflow($options);
                    return $result;
                }
                // For property bags: read fields first, then options.
                // toPlainTime reads the fields, then we read overflow.
                $rawOpts = $args[1] ?? JsUndefined::instance();
                return self::toPlainTimeFromBag($item, $rawOpts);
            }, 1),
            true,
            false,
            true,
        ));

        $ctor->defineOwnProperty('compare', PropertyDescriptor::data(
            JsFunction::fromCallable('compare', function (JsValue $this_, array $args): JsValue {
                $one = self::toPlainTime($args[0] ?? JsUndefined::instance());
                $two = self::toPlainTime($args[1] ?? JsUndefined::instance());
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

        $temporal->defineOwnProperty('PlainTime', PropertyDescriptor::data($ctor, true, false, true));
        self::$plainTimeProto = $proto;

        return $proto;
    }
}
