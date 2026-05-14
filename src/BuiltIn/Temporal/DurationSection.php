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
 * Temporal.Duration type installer. Composed into TemporalObject
 * via `use Temporal\DurationSection;` — the per-section split is
 * purely organisational. `self::` references resolve into the
 * composing class so cross-section helpers continue to work.
 */
trait DurationSection
{
    // -----------------------------------------------------------------------
    // Temporal.Duration
    // -----------------------------------------------------------------------

    private static ?JsObject $durationProto = null;

    private static function installDuration(JsObject $temporal, Environment $env): JsObject
    {
        $proto = new JsObject();

        $fields = ['years', 'months', 'weeks', 'days', 'hours', 'minutes', 'seconds', 'milliseconds', 'microseconds', 'nanoseconds'];
        foreach ($fields as $field) {
            self::defineGetter($proto, $field, function (JsValue $this_) use ($field): JsValue {
                self::requireDuration($this_);
                // Read the float value directly to avoid int overflow for large values.
                $v = $this_->get("[[{$field}]]");
                return ($v instanceof JsNumber) ? $v : JsNumber::of(0.0);
            });
        }

        self::defineGetter($proto, 'sign', function (JsValue $this_): JsValue {
            self::requireDuration($this_);
            return JsNumber::of((float) self::durationSign($this_));
        });

        self::defineGetter($proto, 'blank', function (JsValue $this_): JsValue {
            self::requireDuration($this_);
            return new JsBoolean(self::durationSign($this_) === 0);
        });

        $d = self::protoHelper($proto);

        $d('with', function (JsValue $this_, array $args): JsValue {
            self::requireDuration($this_);
            $item = $args[0] ?? JsUndefined::instance();
            if (!$item instanceof JsObject) {
                throw new TypeError('argument must be an object');
            }
            // Read in alphabetical order per spec.
            $readOrder = ['days', 'hours', 'microseconds', 'milliseconds', 'minutes', 'months', 'nanoseconds', 'seconds', 'weeks', 'years'];
            $any = false;
            $vals = [];
            foreach ($readOrder as $f) {
                $v = $item->get($f);
                if ($v instanceof JsUndefined) {
                    $vals[$f] = self::getDurationField($this_, $f);
                } else {
                    $n = TypeConversion::toNumber($v);
                    if (!is_finite($n)) {
                        throw new RangeError("{$f} must be finite");
                    }
                    if (floor($n) !== $n) {
                        throw new RangeError("{$f} must be integer");
                    }
                    $vals[$f] = (int) $n;
                    $any = true;
                }
            }
            if (!$any) {
                throw new TypeError('at least one recognized property must be provided');
            }
            return self::createDurationObject(
                $vals['years'],
                $vals['months'],
                $vals['weeks'],
                $vals['days'],
                $vals['hours'],
                $vals['minutes'],
                $vals['seconds'],
                $vals['milliseconds'],
                $vals['microseconds'],
                $vals['nanoseconds'],
            );
        }, 1);

        $d('negated', function (JsValue $this_): JsValue {
            self::requireDuration($this_);
            $fields = ['years', 'months', 'weeks', 'days', 'hours', 'minutes', 'seconds', 'milliseconds', 'microseconds', 'nanoseconds'];
            $vals = [];
            foreach ($fields as $f) {
                $v = self::getDurationField($this_, $f);
                $vals[] = $v === 0 ? 0 : -$v;
            }
            return self::createDurationObject(...$vals);
        }, 0);

        $d('abs', function (JsValue $this_): JsValue {
            self::requireDuration($this_);
            $fields = ['years', 'months', 'weeks', 'days', 'hours', 'minutes', 'seconds', 'milliseconds', 'microseconds', 'nanoseconds'];
            $vals = [];
            foreach ($fields as $f) {
                $vals[] = abs(self::getDurationField($this_, $f));
            }
            return self::createDurationObject(...$vals);
        }, 0);

        $d('add', function (JsValue $this_, array $args): JsValue {
            self::requireDuration($this_);
            $other = self::toDuration($args[0] ?? JsUndefined::instance());
            return self::addDurations($this_, $other, 1);
        }, 1);

        $d('subtract', function (JsValue $this_, array $args): JsValue {
            self::requireDuration($this_);
            $other = self::toDuration($args[0] ?? JsUndefined::instance());
            return self::addDurations($this_, $other, -1);
        }, 1);

        $d('round', function (JsValue $this_, array $args): JsValue {
            self::requireDuration($this_);
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
            // Read options in SPEC order: largestUnit, relativeTo, roundingIncrement, roundingMode, smallestUnit.
            $allDurUnits = [
                'year', 'month', 'week', 'day', 'hour', 'minute',
                'second', 'millisecond', 'microsecond', 'nanosecond',
            ];
            // 1. largestUnit
            $largestUnit = 'auto';
            $largestUnitExplicit = false;
            $lu = $roundTo->get('largestUnit');
            if (!($lu instanceof JsUndefined)) {
                $largestUnitExplicit = true;
                $luStr = TypeConversion::toString($lu);
                if ($luStr !== 'auto') {
                    $largestUnit = self::canonicalTemporalUnit($luStr);
                    if (!in_array($largestUnit, $allDurUnits, true)) {
                        throw new RangeError("Invalid largestUnit: {$largestUnit}");
                    }
                }
            }
            // 2. relativeTo
            $relativeTo = null;
            $zonedRelativeTo = null;
            $rtv = $roundTo->get('relativeTo');
            if (!($rtv instanceof JsUndefined)) {
                // If string has bracket annotation, also parse as ZDT for validation.
                if ($rtv instanceof JsString) {
                    $rtStr = $rtv->value;
                    if (preg_match('/\[[^\]]+\]/', $rtStr) && !preg_match('/\[u-ca=/', $rtStr)) {
                        try {
                            $zonedRelativeTo = self::parseZonedDateTimeString($rtStr);
                        } catch (\Throwable) {
                            // fall through to PlainDate parsing below
                        }
                    }
                } elseif ($rtv instanceof JsObject && $rtv->has('[[IsZonedDateTime]]')) {
                    $zonedRelativeTo = $rtv;
                }
                $relativeTo = self::toRelativeToPlainDate($rtv);
                if (self::$relativeToZdtCache !== null) {
                    $zonedRelativeTo = self::$relativeToZdtCache;
                    self::$relativeToZdtCache = null;
                }
            }
            // 3. roundingIncrement
            $increment = self::getRoundingIncrement($roundTo);
            // 4. roundingMode
            $roundingMode = self::getRoundingMode($roundTo, 'halfExpand');
            // 5. smallestUnit
            $unit = self::getTemporalUnit($roundTo, 'smallestUnit', $allDurUnits, false);
            $suExplicit = $unit !== '';
            if (!$suExplicit) {
                $unit = 'nanosecond';
            }
            // At least one of smallestUnit or largestUnit must be provided.
            if (!$suExplicit && !$largestUnitExplicit) {
                throw new RangeError('at least one of smallestUnit or largestUnit is required');
            }
            // Validate smallestUnit <= largestUnit.
            if ($largestUnit !== 'auto') {
                $luRank = array_search($largestUnit, $allDurUnits);
                $suRank = array_search($unit, $allDurUnits);
                if ($luRank !== false && $suRank !== false && $suRank < $luRank) {
                    throw new RangeError('smallestUnit must not be larger than largestUnit');
                }
            }
            if ($increment > 1) {
                self::validateRoundingIncrement($unit, $increment);
                // Resolve "auto" for the increment validation.
                $effectiveLU = $largestUnit;
                if ($effectiveLU === 'auto') {
                    if (self::getDurationField($this_, 'years') !== 0) {
                        $effectiveLU = 'year';
                    } elseif (self::getDurationField($this_, 'months') !== 0) {
                        $effectiveLU = 'month';
                    } elseif (self::getDurationField($this_, 'weeks') !== 0) {
                        $effectiveLU = 'week';
                    } else {
                        $effectiveLU = self::defaultLargestUnit($this_);
                    }
                }
                // Cannot round to an increment > 1 of calendar units while also balancing to larger calendar units.
                $dateUnitsRank = ['day' => 3, 'week' => 2, 'month' => 1, 'year' => 0];
                if (isset($dateUnitsRank[$unit], $dateUnitsRank[$effectiveLU]) && $dateUnitsRank[$effectiveLU] < $dateUnitsRank[$unit]) {
                    throw new RangeError("Cannot round to an increment of {$unit}s while also balancing to {$effectiveLU}s");
                }
            }
            // Calendar units require relativeTo.
            $hasCalUnit = self::getDurationField($this_, 'years') !== 0
                || self::getDurationField($this_, 'months') !== 0
                || self::getDurationField($this_, 'weeks') !== 0;
            $calSmallest = in_array($unit, ['year', 'month', 'week'], true);
            $calLargest = in_array($largestUnit, ['year', 'month', 'week'], true);
            if (($hasCalUnit || $calSmallest || $calLargest) && $relativeTo === null) {
                throw new RangeError('relativeTo is required for rounding durations with calendar units');
            }
            // Per spec: validate target epoch ns when ZonedDateTime relativeTo.
            if ($zonedRelativeTo !== null) {
                $epochNs = self::getSlotString($zonedRelativeTo, '[[EpochNanoseconds]]');
                $durNs = self::durationToTotalNs($this_);
                $targetNs = bcadd($epochNs, $durNs, 0);
                self::validateInstantRange($targetNs);
                // When the algorithm needs day-boundary arithmetic (day largestUnit
                // with sub-day smallestUnit, or smallestUnit=day) the next or previous
                // day must also be representable (NudgeToDayOrTime in the spec).
                $timeUnitsBelowDay = ['hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];
                $needsDayBoundary = ($largestUnit === 'day' && in_array($unit, $timeUnitsBelowDay, true))
                    || $unit === 'day';
                if ($needsDayBoundary) {
                    $nsPerDay = '86400000000000';
                    $sign = self::durationSign($this_);
                    $direction = $sign < 0 ? bcsub($epochNs, $nsPerDay, 0) : bcadd($epochNs, $nsPerDay, 0);
                    self::validateInstantRange($direction);
                }
            } else {
                // For PlainDate relativeTo, validate midnight is in PDT range when duration is non-zero.
                self::validatePlainRelativeToRange($relativeTo, $this_);
            }
            $rtForRound = $zonedRelativeTo ?? $relativeTo;
            return self::roundDuration($this_, $unit, $roundingMode, $increment, $largestUnit, $rtForRound);
        }, 1);

        $d('total', function (JsValue $this_, array $args): JsValue {
            self::requireDuration($this_);
            $totalOf = $args[0] ?? JsUndefined::instance();
            if ($totalOf instanceof JsUndefined) {
                throw new TypeError('total requires a string or options object');
            }
            $plainRelativeToDate = null;
            if ($totalOf instanceof JsString) {
                $unit = $totalOf->value;
                $relativeTo = null;
            } elseif ($totalOf instanceof JsObject) {
                // Per spec: read relativeTo, resolve it, THEN read unit.
                $rtv = $totalOf->get('relativeTo');
                $relativeTo = ($rtv instanceof JsUndefined) ? null : $rtv;
                if ($relativeTo !== null) {
                    // Parse and validate relativeTo. If it's a ZDT string, keep the ZDT.
                    if ($relativeTo instanceof JsString) {
                        $rtStr = $relativeTo->value;
                        // Check if it's a ZDT string (has bracketed annotation).
                        if (preg_match('/\[[^\]]+\]/', $rtStr) && !preg_match('/\[u-ca=/', $rtStr)) {
                            try {
                                $relativeTo = self::parseZonedDateTimeString($rtStr);
                            } catch (\Throwable) {
                                $plainRelativeToDate = self::toRelativeToPlainDate($rtv);
                                $relativeTo = $plainRelativeToDate;
                            }
                        } else {
                            $plainRelativeToDate = self::toRelativeToPlainDate($relativeTo);
                        }
                    } else {
                        $plainRelativeToDate = self::toRelativeToPlainDate($relativeTo);
                    }
                    if (self::$relativeToZdtCache !== null) {
                        $relativeTo = self::$relativeToZdtCache;
                        self::$relativeToZdtCache = null;
                    }
                }
                $u = $totalOf->get('unit');
                if ($u instanceof JsUndefined) {
                    throw new RangeError('unit is required');
                }
                $unit = TypeConversion::toString($u);
            } else {
                throw new TypeError('total requires a string or options object');
            }
            $unit = self::canonicalTemporalUnit($unit);
            // Calendar units require relativeTo.
            $calUnits = ['year', 'month', 'week'];
            $hasCalUnit = self::getDurationField($this_, 'years') !== 0
                || self::getDurationField($this_, 'months') !== 0
                || self::getDurationField($this_, 'weeks') !== 0;
            if (($hasCalUnit || in_array($unit, $calUnits, true)) && $relativeTo === null) {
                throw new RangeError('relativeTo is required for calendar units');
            }
            // Per spec: when zonedRelativeTo is defined, validate target epoch ns.
            if (
                $relativeTo !== null
                && $relativeTo instanceof JsObject
                && $relativeTo->has('[[IsZonedDateTime]]')
            ) {
                $epochNs = self::getSlotString($relativeTo, '[[EpochNanoseconds]]');
                $durNs = self::durationToTotalNs($this_);
                $targetNs = bcadd($epochNs, $durNs, 0);
                self::validateInstantRange($targetNs);
            } else {
                // For PlainDate relativeTo, validate the midnight-of-relativeTo is in PDT range
                // when the duration is non-zero (spec: RejectDateTimeRange before computing total).
                self::validatePlainRelativeToRange($plainRelativeToDate, $this_);
            }
            // ZDT relativeTo with cal-unit duration and unit "day": use ZDT-aware
            // delta so DST transitions affect the day count.
            if (
                $relativeTo !== null
                && $relativeTo instanceof JsObject
                && $relativeTo->has('[[IsZonedDateTime]]')
                && $unit === 'day'
                && $hasCalUnit
            ) {
                $startNs = self::getSlotString($relativeTo, '[[EpochNanoseconds]]');
                $endNs = self::addDurationToZdt($relativeTo, $this_, 1, 'constrain');
                return JsNumber::of(self::zdtDeltaInDays($relativeTo, $endNs, $startNs));
            }
            if ($relativeTo !== null && in_array($unit, $calUnits, true)) {
                return JsNumber::of(self::durationTotalWithRelativeTo($this_, $unit, $relativeTo));
            }
            if ($relativeTo !== null && $hasCalUnit) {
                return JsNumber::of(self::durationTotalWithRelativeTo($this_, $unit, $relativeTo));
            }
            // For ZDT relativeTo with sub-day unit: compute via ZDT.add so DST
            // transitions affect the actual UTC ns delta.
            $hasDayUnit = self::getDurationField($this_, 'days') !== 0;
            $subDayUnits = ['day', 'hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];
            if (
                $relativeTo !== null
                && $relativeTo instanceof JsObject
                && $relativeTo->has('[[IsZonedDateTime]]')
                && in_array($unit, $subDayUnits, true)
                && ($hasDayUnit || $unit === 'day')
            ) {
                $startNs = self::getSlotString($relativeTo, '[[EpochNanoseconds]]');
                $endNs = self::addDurationToZdt($relativeTo, $this_, 1, 'constrain');
                $deltaNs = bcsub($endNs, $startNs, 0);
                if ($unit === 'day') {
                    return JsNumber::of(self::zdtDeltaInDays($relativeTo, $endNs, $startNs));
                }
                $unitToNs = [
                    'hour' => '3600000000000',
                    'minute' => '60000000000',
                    'second' => '1000000000',
                    'millisecond' => '1000000',
                    'microsecond' => '1000',
                    'nanosecond' => '1',
                ];
                return JsNumber::of((float) bcdiv($deltaNs, $unitToNs[$unit], 25));
            }
            return JsNumber::of(self::durationTotalNs($this_, $unit));
        }, 1);

        $d('toString', function (JsValue $this_, array $args): JsValue {
            self::requireDuration($this_);
            $options = self::getOptionsObject($args[0] ?? JsUndefined::instance());
            $fractionalSecondDigits = self::getFractionalSecondDigits($options);
            $roundingMode = self::getRoundingMode($options, 'trunc');
            $smallestUnit = null;
            if ($options instanceof JsObject) {
                $su = $options->get('smallestUnit');
                if (!($su instanceof JsUndefined)) {
                    $smallestUnit = TypeConversion::toString($su);
                    $smallestUnit = self::canonicalTemporalUnit($smallestUnit);
                    $validUnits = ['second', 'millisecond', 'microsecond', 'nanosecond'];
                    if (!in_array($smallestUnit, $validUnits, true)) {
                        throw new RangeError("Invalid smallestUnit for Duration.toString: {$smallestUnit}");
                    }
                    // smallestUnit determines fractionalSecondDigits.
                    $unitToDigits = [
                        'second' => 0,
                        'millisecond' => 3, 'microsecond' => 6, 'nanosecond' => 9,
                    ];
                    $fractionalSecondDigits = $unitToDigits[$smallestUnit];
                }
            }
            return new JsString(
                self::durationToString($this_, $fractionalSecondDigits, $roundingMode, $smallestUnit)
            );
        }, 0);

        $d('toJSON', function (JsValue $this_): JsValue {
            self::requireDuration($this_);
            return new JsString(self::durationToString($this_, 'auto', 'trunc'));
        }, 0);

        $d('toLocaleString', function (JsValue $this_, array $args): JsValue {
            self::requireDuration($this_);
            $fallback = self::durationToString($this_, 'auto', 'trunc');
            return self::durationToLocaleString($this_, $args, $fallback);
        }, 0);

        $d('valueOf', function (JsValue $this_): JsValue {
            throw new TypeError('Temporal.Duration does not implement valueOf');
        }, 0);

        self::setToStringTag($proto, 'Temporal.Duration');
        self::installTemporalToPrimitive($proto, 'Duration');

        // Constructor
        $ctor = JsFunction::fromCallable('Duration', function (JsValue $this_, array $args) use ($proto): JsValue {
            if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                throw new TypeError('Temporal.Duration must be called with new');
            }
            self::applyNewTargetPrototype($this_, $proto);
            $fields = [];
            $names = ['years', 'months', 'weeks', 'days', 'hours', 'minutes', 'seconds', 'milliseconds', 'microseconds', 'nanoseconds'];
            foreach ($names as $i => $name) {
                $v = $args[$i] ?? JsUndefined::instance();
                if ($v instanceof JsUndefined) {
                    $fields[] = 0;
                } else {
                    $n = TypeConversion::toNumber($v);
                    if (!is_finite($n)) {
                        throw new RangeError("infinite Duration field: {$name}");
                    }
                    if (floor($n) !== $n) {
                        throw new RangeError("fractional Duration field: {$name}");
                    }
                    // Reject values beyond the safe integer range (would overflow PHP int).
                    if (abs($n) > 9007199254740991.0) {
                        throw new RangeError("{$name} out of range");
                    }
                    $fields[] = (int) $n;
                }
            }
            self::validateDurationFields($fields, true);
            if (!self::objectInheritsFrom($this_, $proto)) {
                $this_->setPrototype($proto);
            }
            foreach ($names as $i => $name) {
                $this_->defineOwnProperty("[[{$name}]]", PropertyDescriptor::data(
                    JsNumber::of((float) $fields[$i]),
                    false,
                    false,
                    false,
                ));
            }
            $this_->defineOwnProperty('[[IsDuration]]', PropertyDescriptor::data(
                new JsBoolean(true),
                false,
                false,
                false,
            ));
            return $this_;
        }, 0);
        $ctor->setConstructable();

        // Static: Duration.from
        $ctor->defineOwnProperty('from', PropertyDescriptor::data(
            JsFunction::fromCallable('from', function (JsValue $this_, array $args): JsValue {
                return self::toDuration($args[0] ?? JsUndefined::instance(), true);
            }, 1),
            true,
            false,
            true,
        ));

        // Static: Duration.compare
        $ctor->defineOwnProperty('compare', PropertyDescriptor::data(
            JsFunction::fromCallable('compare', function (JsValue $this_, array $args): JsValue {
                $one = self::toDuration($args[0] ?? JsUndefined::instance());
                $two = self::toDuration($args[1] ?? JsUndefined::instance());
                // Check for options (relativeTo).
                $options = self::getOptionsObject($args[2] ?? JsUndefined::instance());
                $relativeTo = null;
                if ($options instanceof JsObject) {
                    $rtv = $options->get('relativeTo');
                    if (!($rtv instanceof JsUndefined)) {
                        $relativeTo = $rtv;
                    }
                }
                // Check if either duration has calendar units.
                $hasCalUnit = self::getDurationField($one, 'years') !== 0
                    || self::getDurationField($one, 'months') !== 0
                    || self::getDurationField($one, 'weeks') !== 0
                    || self::getDurationField($two, 'years') !== 0
                    || self::getDurationField($two, 'months') !== 0
                    || self::getDurationField($two, 'weeks') !== 0;
                // Per spec: if both durations have identical internal slots, return 0
                // without requiring relativeTo.
                if ($hasCalUnit && $relativeTo === null) {
                    $fields = ['years', 'months', 'weeks', 'days', 'hours', 'minutes', 'seconds', 'milliseconds', 'microseconds', 'nanoseconds'];
                    $identical = true;
                    foreach ($fields as $f) {
                        if (self::getDurationField($one, $f) !== self::getDurationField($two, $f)) {
                            $identical = false;
                            break;
                        }
                    }
                    if ($identical) {
                        return JsNumber::of(0.0);
                    }
                    throw new RangeError('relativeTo is required for comparing durations with calendar units');
                }
                $refDate = null;
                if ($relativeTo !== null) {
                    // If string with bracketed annotation, parse as ZDT; otherwise as PlainDate.
                    if ($relativeTo instanceof JsString) {
                        $rtStr = $relativeTo->value;
                        if (preg_match('/\[[^\]]+\]/', $rtStr) && !preg_match('/\[u-ca=/', $rtStr)) {
                            try {
                                $relativeTo = self::parseZonedDateTimeString($rtStr);
                            } catch (\Throwable) {
                                $refDate = self::toRelativeToPlainDate($relativeTo);
                            }
                        } else {
                            $refDate = self::toRelativeToPlainDate($relativeTo);
                        }
                    } else {
                        $refDate = self::toRelativeToPlainDate($relativeTo);
                    }
                    // Property bag with timeZone: toRelativeToPlainDate stashed
                    // the ZDT in a side-channel; promote relativeTo to that ZDT.
                    if (self::$relativeToZdtCache !== null) {
                        $relativeTo = self::$relativeToZdtCache;
                        self::$relativeToZdtCache = null;
                    }
                    if (
                        $refDate === null
                        && $relativeTo instanceof JsObject
                        && $relativeTo->has('[[IsZonedDateTime]]')
                    ) {
                        $parts = self::zonedDateTimeParts($relativeTo);
                        $refDate = self::createPlainDateObject(
                            $parts['year'],
                            $parts['month'],
                            $parts['day'],
                            self::getSlotString($relativeTo, '[[Calendar]]'),
                        );
                    }
                    // Per spec (sec-temporal.duration.compare step 12): ZDT validation
                    // applies when either duration has a date unit (year/month/week/day).
                    $hasDateUnit = $hasCalUnit
                        || self::getDurationField($one, 'days') !== 0
                        || self::getDurationField($two, 'days') !== 0;
                    if (
                        $hasDateUnit
                        && $relativeTo instanceof JsObject
                        && $relativeTo->has('[[IsZonedDateTime]]')
                    ) {
                        $epochNs = self::getSlotString($relativeTo, '[[EpochNanoseconds]]');
                        $durNs1 = self::durationToTotalNs($one);
                        $durNs2 = self::durationToTotalNs($two);
                        self::validateInstantRange(bcadd($epochNs, $durNs1, 0));
                        self::validateInstantRange(bcadd($epochNs, $durNs2, 0));
                    }
                }
                if (
                    $relativeTo !== null
                    && $hasCalUnit
                    && $relativeTo instanceof JsObject
                    && $relativeTo->has('[[IsZonedDateTime]]')
                ) {
                    // ZDT-aware compare: build end ZDTs respecting DST.
                    $end1 = self::addDurationToZdt($relativeTo, $one, 1, 'constrain');
                    $end2 = self::addDurationToZdt($relativeTo, $two, 1, 'constrain');
                    return JsNumber::of((float) bccomp($end1, $end2, 0));
                }
                if ($relativeTo !== null && $hasCalUnit) {
                    // Compare by adding both durations to relativeTo and comparing results.
                    $end1 = self::plainDateAdd($refDate, $one, 1);
                    $end2 = self::plainDateAdd($refDate, $two, 1);
                    $jd1 = self::isoToJulianDay(
                        self::getSlotInt($end1, '[[ISOYear]]'),
                        self::getSlotInt($end1, '[[ISOMonth]]'),
                        self::getSlotInt($end1, '[[ISODay]]'),
                    );
                    $jd2 = self::isoToJulianDay(
                        self::getSlotInt($end2, '[[ISOYear]]'),
                        self::getSlotInt($end2, '[[ISOMonth]]'),
                        self::getSlotInt($end2, '[[ISODay]]'),
                    );
                    // Add time parts.
                    $timeNs1 = self::durationToTotalNs(
                        self::createDurationObject(
                            0,
                            0,
                            0,
                            0,
                            self::getDurationField($one, 'hours'),
                            self::getDurationField($one, 'minutes'),
                            self::getDurationField($one, 'seconds'),
                            self::getDurationField($one, 'milliseconds'),
                            self::getDurationField($one, 'microseconds'),
                            self::getDurationField($one, 'nanoseconds'),
                        )
                    );
                    $timeNs2 = self::durationToTotalNs(
                        self::createDurationObject(
                            0,
                            0,
                            0,
                            0,
                            self::getDurationField($two, 'hours'),
                            self::getDurationField($two, 'minutes'),
                            self::getDurationField($two, 'seconds'),
                            self::getDurationField($two, 'milliseconds'),
                            self::getDurationField($two, 'microseconds'),
                            self::getDurationField($two, 'nanoseconds'),
                        )
                    );
                    $total1 = bcadd(bcmul((string) $jd1, '86400000000000', 0), $timeNs1, 0);
                    $total2 = bcadd(bcmul((string) $jd2, '86400000000000', 0), $timeNs2, 0);
                    return JsNumber::of((float) bccomp($total1, $total2, 0));
                }
                // ZDT relativeTo with day-bearing durations: compute end epochs
                // via ZDT-aware add so DST transitions affect the comparison.
                $oneHasDay = self::getDurationField($one, 'days') !== 0;
                $twoHasDay = self::getDurationField($two, 'days') !== 0;
                if (
                    $relativeTo !== null
                    && $relativeTo instanceof JsObject
                    && $relativeTo->has('[[IsZonedDateTime]]')
                    && ($oneHasDay || $twoHasDay)
                ) {
                    $end1 = self::addDurationToZdt($relativeTo, $one, 1, 'constrain');
                    $end2 = self::addDurationToZdt($relativeTo, $two, 1, 'constrain');
                    return JsNumber::of((float) bccomp($end1, $end2, 0));
                }
                $ns1 = self::durationToTotalNs($one);
                $ns2 = self::durationToTotalNs($two);
                return JsNumber::of((float) self::bigCmp($ns1, $ns2));
            }, 2),
            true,
            false,
            true,
        ));

        $ctor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($ctor, true, false, true));

        $temporal->defineOwnProperty('Duration', PropertyDescriptor::data($ctor, true, false, true));
        self::$durationProto = $proto;

        return $proto;
    }
}
