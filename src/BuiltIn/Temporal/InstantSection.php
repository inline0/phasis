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
 * Temporal.Instant type installer. Composed into TemporalObject
 * via `use Temporal\InstantSection;` — the per-section split is
 * purely organisational. `self::` references resolve into the
 * composing class so cross-section helpers continue to work.
 */
trait InstantSection
{
    // -----------------------------------------------------------------------
    // Temporal.Instant
    // -----------------------------------------------------------------------

    private static function installInstant(JsObject $temporal, Environment $env): JsObject
    {
        $proto = new JsObject();

        // Prototype getters via accessor properties.
        self::defineGetter($proto, 'epochMilliseconds', function (JsValue $this_): JsValue {
            $ns = self::requireInstant($this_);
            return JsNumber::of(self::bigFloorDiv($ns, '1000000'));
        });

        self::defineGetter($proto, 'epochNanoseconds', function (JsValue $this_): JsValue {
            $ns = self::requireInstant($this_);
            return new JsBigInt($ns);
        });

        $d = self::protoHelper($proto);

        $d('toString', function (JsValue $this_, array $args): JsValue {
            $ns = self::requireInstant($this_);
            $options = self::getOptionsObject($args[0] ?? JsUndefined::instance());
            $fractionalSecondDigits = self::getFractionalSecondDigits($options);
            $roundingMode = self::getRoundingMode($options, 'trunc');
            $smallestUnit = null;
            if ($options instanceof JsObject) {
                $su = $options->get('smallestUnit');
                if (!($su instanceof JsUndefined)) {
                    $smallestUnit = TypeConversion::toString($su);
                    $smallestUnit = self::canonicalTemporalUnit($smallestUnit);
                    $validUnits = ['minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];
                    if (!in_array($smallestUnit, $validUnits, true)) {
                        throw new RangeError("Invalid smallestUnit for toString: {$smallestUnit}");
                    }
                    // smallestUnit overrides fractionalSecondDigits.
                    $unitToDigits = [
                        'minute' => 0, 'second' => 0,
                        'millisecond' => 3, 'microsecond' => 6, 'nanosecond' => 9,
                    ];
                    $fractionalSecondDigits = $unitToDigits[$smallestUnit];
                }
            }
            // Round the ns if needed.
            if ($smallestUnit !== null && $smallestUnit !== 'nanosecond') {
                $unitNsMap = [
                    'minute' => '60000000000',
                    'second' => '1000000000',
                    'millisecond' => '1000000',
                    'microsecond' => '1000',
                ];
                $incrementNs = $unitNsMap[$smallestUnit];
                $ns = self::roundInstantNs($ns, $incrementNs, $roundingMode);
            } elseif ($smallestUnit === null && is_int($fractionalSecondDigits) && $fractionalSecondDigits < 9) {
                // Round to the specified number of fractional digits.
                $digitsToNs = [
                    0 => '1000000000', 1 => '100000000', 2 => '10000000',
                    3 => '1000000', 4 => '100000', 5 => '10000',
                    6 => '1000', 7 => '100', 8 => '10',
                ];
                if (isset($digitsToNs[$fractionalSecondDigits])) {
                    $ns = self::roundInstantNs($ns, $digitsToNs[$fractionalSecondDigits], $roundingMode);
                }
            }
            $timeZone = null;
            if ($options instanceof JsObject) {
                $tz = $options->get('timeZone');
                if (!($tz instanceof JsUndefined)) {
                    $timeZone = self::toTemporalTimeZoneIdentifier($tz);
                }
            }
            $omitSec = $smallestUnit === 'minute';
            if ($timeZone !== null) {
                return new JsString(self::instantToStringInZone($ns, $timeZone, $fractionalSecondDigits, $roundingMode));
            }
            return new JsString(self::instantToString($ns, $fractionalSecondDigits, 'trunc', $omitSec));
        }, 0);

        $d('toJSON', function (JsValue $this_): JsValue {
            $ns = self::requireInstant($this_);
            return new JsString(self::instantToString($ns, 'auto', 'trunc'));
        }, 0);

        $d('toLocaleString', function (JsValue $this_, array $args): JsValue {
            $ns = self::requireInstant($this_);
            $fallback = self::instantToString($ns, 'auto', 'trunc');
            return self::temporalToLocaleString($this_, $args, $fallback);
        }, 0);

        $d('valueOf', function (JsValue $this_): JsValue {
            throw new TypeError('Temporal.Instant does not implement valueOf. Use compare or equals instead.');
        }, 0);

        $d('equals', function (JsValue $this_, array $args): JsValue {
            $ns = self::requireInstant($this_);
            $other = self::toInstantNs($args[0] ?? JsUndefined::instance());
            return new JsBoolean($ns === $other);
        }, 1);

        $d('add', function (JsValue $this_, array $args): JsValue {
            $ns = self::requireInstant($this_);
            return self::instantAddDuration($ns, $args[0] ?? JsUndefined::instance(), 1);
        }, 1);

        $d('subtract', function (JsValue $this_, array $args): JsValue {
            $ns = self::requireInstant($this_);
            return self::instantAddDuration($ns, $args[0] ?? JsUndefined::instance(), -1);
        }, 1);

        $d('until', function (JsValue $this_, array $args): JsValue {
            $ns = self::requireInstant($this_);
            $other = self::toInstantNs($args[0] ?? JsUndefined::instance());
            return self::instantDifference($ns, $other, $args[1] ?? JsUndefined::instance());
        }, 1);

        $d('since', function (JsValue $this_, array $args): JsValue {
            $ns = self::requireInstant($this_);
            $other = self::toInstantNs($args[0] ?? JsUndefined::instance());
            return self::instantDifference($other, $ns, $args[1] ?? JsUndefined::instance());
        }, 1);

        $d('round', function (JsValue $this_, array $args): JsValue {
            $ns = self::requireInstant($this_);
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
            $validUnits = [
                'hour', 'minute', 'second',
                'millisecond', 'microsecond', 'nanosecond',
            ];
            $unit = self::getTemporalUnit($roundTo, 'smallestUnit', $validUnits, true);
            $roundingMode = self::getRoundingMode($roundTo, 'halfExpand');
            $increment = self::getRoundingIncrement($roundTo);
            if ($increment > 1) {
                // Instant uses solar day (86400s) as the max for all units.
                $maxForSolarDay = [
                    'hour' => 24,
                    'minute' => 1440,
                    'second' => 86400,
                    'millisecond' => 86400000,
                    'microsecond' => 86400000000,
                    'nanosecond' => 86400000000000,
                ];
                $max = $maxForSolarDay[$unit] ?? 1;
                if ($increment > $max || $max % $increment !== 0) {
                    throw new RangeError("Invalid roundingIncrement for {$unit}: {$increment}");
                }
            }
            $unitNs = self::temporalUnitToNs($unit);
            $incrementNs = bcmul((string) $increment, $unitNs, 0);
            $rounded = self::roundInstantNs($ns, $incrementNs, $roundingMode);
            self::validateInstantRange($rounded);
            return self::createInstantObject($rounded);
        }, 1);

        $d('toZonedDateTimeISO', function (JsValue $this_, array $args): JsValue {
            $ns = self::requireInstant($this_);
            $item = $args[0] ?? JsUndefined::instance();
            $timeZone = self::toTemporalTimeZoneIdentifier($item);
            return self::createZonedDateTimeObject($ns, $timeZone, 'iso8601');
        }, 1);

        // Symbol.toStringTag = "Temporal.Instant"
        self::setToStringTag($proto, 'Temporal.Instant');
        self::installTemporalToPrimitive($proto, 'Instant');

        $proto->defineOwnProperty('constructor', PropertyDescriptor::data(JsUndefined::instance(), true, false, true));

        // Constructor
        $ctor = JsFunction::fromCallable('Instant', function (JsValue $this_, array $args) use ($proto): JsValue {
            if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                throw new TypeError('Temporal.Instant must be called with new');
            }
            self::applyNewTargetPrototype($this_, $proto);
            $arg = $args[0] ?? JsUndefined::instance();
            $ns = self::toBigIntNsFromArg($arg);
            self::validateInstantRange($ns);
            $this_->defineOwnProperty('[[EpochNanoseconds]]', PropertyDescriptor::data(
                new JsString($ns),
                false,
                false,
                false,
            ));
            return $this_;
        }, 1);
        $ctor->setConstructable();

        // Static methods.
        $ctor->defineOwnProperty('from', PropertyDescriptor::data(
            JsFunction::fromCallable('from', function (JsValue $this_, array $args): JsValue {
                $item = $args[0] ?? JsUndefined::instance();
                if ($item instanceof JsObject && $item->has('[[EpochNanoseconds]]')) {
                    $ns = self::requireInstant($item);
                    return self::createInstantObject($ns);
                }
                $ns = self::toInstantNs($item);
                return self::createInstantObject($ns);
            }, 1),
            true,
            false,
            true,
        ));

        $ctor->defineOwnProperty('fromEpochMilliseconds', PropertyDescriptor::data(
            JsFunction::fromCallable('fromEpochMilliseconds', function (JsValue $this_, array $args): JsValue {
                $ms = $args[0] ?? JsUndefined::instance();
                $n = TypeConversion::toNumber($ms);
                if (!is_finite($n) || floor($n) !== $n) {
                    throw new RangeError('fromEpochMilliseconds requires an integer');
                }
                $ns = bcmul(number_format($n, 0, '.', ''), '1000000', 0);
                self::validateInstantRange($ns);
                return self::createInstantObject($ns);
            }, 1),
            true,
            false,
            true,
        ));

        $ctor->defineOwnProperty('fromEpochNanoseconds', PropertyDescriptor::data(
            JsFunction::fromCallable('fromEpochNanoseconds', function (JsValue $this_, array $args): JsValue {
                $arg = $args[0] ?? JsUndefined::instance();
                if (!$arg instanceof JsBigInt) {
                    throw new TypeError('fromEpochNanoseconds requires a BigInt');
                }
                $ns = $arg->value;
                self::validateInstantRange($ns);
                return self::createInstantObject($ns);
            }, 1),
            true,
            false,
            true,
        ));

        $ctor->defineOwnProperty('compare', PropertyDescriptor::data(
            JsFunction::fromCallable('compare', function (JsValue $this_, array $args): JsValue {
                $one = self::toInstantNs($args[0] ?? JsUndefined::instance());
                $two = self::toInstantNs($args[1] ?? JsUndefined::instance());
                return JsNumber::of((float) self::bigCmp($one, $two));
            }, 2),
            true,
            false,
            true,
        ));

        $ctor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($ctor, true, false, true));

        $temporal->defineOwnProperty('Instant', PropertyDescriptor::data($ctor, true, false, true));

        // Store prototype for createInstantObject to find.
        self::$instantProto = $proto;

        return $proto;
    }

    private static ?JsObject $instantProto = null;

    private static function createInstantObject(string $ns): JsObject
    {
        self::validateInstantRange($ns);
        $obj = new JsObject(self::$instantProto);
        $obj->defineOwnProperty('[[EpochNanoseconds]]', PropertyDescriptor::data(
            new JsString($ns),
            false,
            false,
            false,
        ));
        return $obj;
    }

    /** Public factory for Date.prototype.toTemporalInstant. */
    public static function createInstantFromNs(string $ns): JsObject
    {
        return self::createInstantObject($ns);
    }
}
