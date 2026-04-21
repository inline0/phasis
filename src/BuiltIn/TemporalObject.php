<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Exceptions\RangeError;
use PhpJs\Exceptions\TypeError;
use PhpJs\Object\PropertyDescriptor;
use PhpJs\Runtime\Environment;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsBigInt;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

/**
 * Temporal namespace object and all Temporal type constructors.
 *
 * Implements the TC39 Temporal proposal:
 *   Temporal.Now, Temporal.Instant, Temporal.Duration,
 *   Temporal.PlainDate, Temporal.PlainTime, Temporal.PlainDateTime,
 *   Temporal.PlainYearMonth, Temporal.PlainMonthDay,
 *   Temporal.ZonedDateTime.
 */
class TemporalObject
{
    // Nanosecond limits for Instant per spec: +/- 8.64e21 ns (100 million days).
    private const NS_MAX = '8640000000000000000000';
    private const NS_MIN = '-8640000000000000000000';

    // ISO year range for PlainDate etc.
    private const ISO_YEAR_MIN = -271821;
    private const ISO_YEAR_MAX = 275760;

    public static function install(Environment $env): void
    {
        $temporal = new JsObject();

        // Install each Temporal type.
        $instantProto = self::installInstant($temporal, $env);
        $durationProto = self::installDuration($temporal, $env);
        $plainDateProto = self::installPlainDate($temporal, $env);
        $plainTimeProto = self::installPlainTime($temporal, $env);
        $plainDateTimeProto = self::installPlainDateTime($temporal, $env);
        $plainYearMonthProto = self::installPlainYearMonth($temporal, $env);
        $plainMonthDayProto = self::installPlainMonthDay($temporal, $env);
        $zonedDateTimeProto = self::installZonedDateTime($temporal, $env);
        self::installNow($temporal, $instantProto, $plainDateProto, $plainTimeProto, $plainDateTimeProto);

        // Symbol.toStringTag = "Temporal"
        $toStringTagSym = SymbolConstructor::toStringTag();
        if ($toStringTagSym !== null) {
            $temporal->definePropertyBySymbol(
                $toStringTagSym,
                PropertyDescriptor::data(new JsString('Temporal'), false, false, true),
            );
        }

        $env->defineVar('Temporal', $temporal);
    }

    // -----------------------------------------------------------------------
    // Temporal.Instant
    // -----------------------------------------------------------------------

    private static function installInstant(JsObject $temporal, Environment $env): JsObject
    {
        $proto = new JsObject();

        // Prototype getters via accessor properties.
        self::defineGetter($proto, 'epochMilliseconds', function (JsValue $this_): JsValue {
            $ns = self::requireInstant($this_);
            return new JsNumber(self::bigFloorDiv($ns, '1000000'));
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
            $timeZone = null;
            if ($options instanceof JsObject && $options->has('timeZone')) {
                $tz = $options->get('timeZone');
                if (!($tz instanceof JsUndefined)) {
                    $timeZone = TypeConversion::toString($tz);
                }
            }
            if ($timeZone !== null) {
                return new JsString(self::instantToStringInZone($ns, $timeZone, $fractionalSecondDigits, $roundingMode));
            }
            return new JsString(self::instantToString($ns, $fractionalSecondDigits, $roundingMode));
        }, 0);

        $d('toJSON', function (JsValue $this_): JsValue {
            $ns = self::requireInstant($this_);
            return new JsString(self::instantToString($ns, 'auto', 'trunc'));
        }, 0);

        $d('toLocaleString', function (JsValue $this_): JsValue {
            $ns = self::requireInstant($this_);
            return new JsString(self::instantToString($ns, 'auto', 'trunc'));
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
            $unit = self::getTemporalUnit($roundTo, 'smallestUnit', ['hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'], true);
            $roundingMode = self::getRoundingMode($roundTo, 'halfExpand');
            $increment = self::getRoundingIncrement($roundTo);
            $unitNs = self::temporalUnitToNs($unit);
            $rounded = self::roundBigIntNs($ns, bcmul((string) $increment, $unitNs, 0), $roundingMode);
            self::validateInstantRange($rounded);
            return self::createInstantObject($rounded);
        }, 1);

        $d('toZonedDateTimeISO', function (JsValue $this_, array $args): JsValue {
            $ns = self::requireInstant($this_);
            $item = $args[0] ?? JsUndefined::instance();
            if ($item instanceof JsString) {
                $timeZone = $item->value;
            } elseif ($item instanceof JsObject) {
                $tz = $item->get('timeZone');
                if ($tz instanceof JsUndefined) {
                    throw new TypeError('missing timeZone property');
                }
                $timeZone = TypeConversion::toString($tz);
            } else {
                throw new TypeError('Expected a string or an object with a timeZone property');
            }
            return self::createZonedDateTimeObject($ns, $timeZone, 'iso8601');
        }, 1);

        // Symbol.toStringTag = "Temporal.Instant"
        self::setToStringTag($proto, 'Temporal.Instant');

        $proto->defineOwnProperty('constructor', PropertyDescriptor::data(JsUndefined::instance(), true, false, true));

        // Constructor
        $ctor = JsFunction::fromCallable('Instant', function (JsValue $this_, array $args) use ($proto): JsValue {
            if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                throw new TypeError('Temporal.Instant must be called with new');
            }
            $arg = $args[0] ?? JsUndefined::instance();
            $ns = self::toBigIntNsFromArg($arg);
            self::validateInstantRange($ns);
            $this_->setPrototype($proto);
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
                return new JsNumber((float) self::bigCmp($one, $two));
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
        $obj = new JsObject(self::$instantProto);
        $obj->defineOwnProperty('[[EpochNanoseconds]]', PropertyDescriptor::data(
            new JsString($ns),
            false,
            false,
            false,
        ));
        return $obj;
    }

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
                return new JsNumber((float) self::getDurationField($this_, $field));
            });
        }

        self::defineGetter($proto, 'sign', function (JsValue $this_): JsValue {
            self::requireDuration($this_);
            return new JsNumber((float) self::durationSign($this_));
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
            $fields = ['years', 'months', 'weeks', 'days', 'hours', 'minutes', 'seconds', 'milliseconds', 'microseconds', 'nanoseconds'];
            $any = false;
            $vals = [];
            foreach ($fields as $f) {
                $v = $item->get($f);
                if ($v instanceof JsUndefined) {
                    $vals[$f] = self::getDurationField($this_, $f);
                } else {
                    $vals[$f] = (int) TypeConversion::toNumber($v);
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
            // For Duration.round, date/time only units allowed.
            $unit = self::getTemporalUnit($roundTo, 'smallestUnit', [
                'year', 'month', 'week', 'day', 'hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond',
            ], true);
            $roundingMode = self::getRoundingMode($roundTo, 'halfExpand');
            $increment = self::getRoundingIncrement($roundTo);
            $largestUnit = 'auto';
            if ($roundTo->has('largestUnit')) {
                $lu = $roundTo->get('largestUnit');
                if (!($lu instanceof JsUndefined)) {
                    $largestUnit = TypeConversion::toString($lu);
                    $largestUnit = self::canonicalTemporalUnit($largestUnit);
                }
            }
            return self::roundDuration($this_, $unit, $roundingMode, $increment, $largestUnit);
        }, 1);

        $d('total', function (JsValue $this_, array $args): JsValue {
            self::requireDuration($this_);
            $totalOf = $args[0] ?? JsUndefined::instance();
            if ($totalOf instanceof JsString) {
                $unit = $totalOf->value;
            } elseif ($totalOf instanceof JsObject) {
                $u = $totalOf->get('unit');
                if ($u instanceof JsUndefined) {
                    throw new RangeError('unit is required');
                }
                $unit = TypeConversion::toString($u);
            } else {
                throw new TypeError('total requires a string or options object');
            }
            $unit = self::canonicalTemporalUnit($unit);
            return new JsNumber(self::durationTotalNs($this_, $unit));
        }, 1);

        $d('toString', function (JsValue $this_, array $args): JsValue {
            self::requireDuration($this_);
            $options = self::getOptionsObject($args[0] ?? JsUndefined::instance());
            $fractionalSecondDigits = self::getFractionalSecondDigits($options);
            $roundingMode = self::getRoundingMode($options, 'trunc');
            return new JsString(self::durationToString($this_, $fractionalSecondDigits, $roundingMode));
        }, 0);

        $d('toJSON', function (JsValue $this_): JsValue {
            self::requireDuration($this_);
            return new JsString(self::durationToString($this_, 'auto', 'trunc'));
        }, 0);

        $d('toLocaleString', function (JsValue $this_): JsValue {
            self::requireDuration($this_);
            return new JsString(self::durationToString($this_, 'auto', 'trunc'));
        }, 0);

        $d('valueOf', function (JsValue $this_): JsValue {
            throw new TypeError('Temporal.Duration does not implement valueOf');
        }, 0);

        self::setToStringTag($proto, 'Temporal.Duration');

        // Constructor
        $ctor = JsFunction::fromCallable('Duration', function (JsValue $this_, array $args) use ($proto): JsValue {
            if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                throw new TypeError('Temporal.Duration must be called with new');
            }
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
                    $fields[] = (int) $n;
                }
            }
            self::validateDurationFields($fields);
            $this_->setPrototype($proto);
            foreach ($names as $i => $name) {
                $this_->defineOwnProperty("[[{$name}]]", PropertyDescriptor::data(
                    new JsNumber((float) $fields[$i]),
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
                return self::toDuration($args[0] ?? JsUndefined::instance());
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
                $ns1 = self::durationToTotalNs($one);
                $ns2 = self::durationToTotalNs($two);
                return new JsNumber((float) self::bigCmp($ns1, $ns2));
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
            return new JsNumber((float) self::getSlotInt($this_, '[[ISOYear]]'));
        });
        self::defineGetter($proto, 'month', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            return new JsNumber((float) self::getSlotInt($this_, '[[ISOMonth]]'));
        });
        self::defineGetter($proto, 'monthCode', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            return new JsString('M' . str_pad((string) $m, 2, '0', STR_PAD_LEFT));
        });
        self::defineGetter($proto, 'day', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            return new JsNumber((float) self::getSlotInt($this_, '[[ISODay]]'));
        });
        self::defineGetter($proto, 'dayOfWeek', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            return new JsNumber((float) self::isoDayOfWeek(
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
            ));
        });
        self::defineGetter($proto, 'dayOfYear', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            return new JsNumber((float) self::isoDayOfYear(
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
            ));
        });
        self::defineGetter($proto, 'weekOfYear', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            [$week] = self::isoWeekOfYear(
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
            );
            return $week === null ? JsUndefined::instance() : new JsNumber((float) $week);
        });
        self::defineGetter($proto, 'yearOfWeek', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            [, $yearOfWeek] = self::isoWeekOfYear(
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
            );
            return $yearOfWeek === null ? JsUndefined::instance() : new JsNumber((float) $yearOfWeek);
        });
        self::defineGetter($proto, 'daysInWeek', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            return new JsNumber(7.0);
        });
        self::defineGetter($proto, 'daysInMonth', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            return new JsNumber((float) self::isoDaysInMonth(
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
            ));
        });
        self::defineGetter($proto, 'daysInYear', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            return new JsNumber((float) self::isoDaysInYear(self::getSlotInt($this_, '[[ISOYear]]')));
        });
        self::defineGetter($proto, 'monthsInYear', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            return new JsNumber(12.0);
        });
        self::defineGetter($proto, 'inLeapYear', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            return new JsBoolean(self::isoIsLeapYear(self::getSlotInt($this_, '[[ISOYear]]')));
        });
        self::defineGetter($proto, 'era', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            return JsUndefined::instance();
        });
        self::defineGetter($proto, 'eraYear', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            return JsUndefined::instance();
        });

        $d = self::protoHelper($proto);

        $d('toString', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDate($this_);
            $options = self::getOptionsObject($args[0] ?? JsUndefined::instance());
            $calendarName = 'auto';
            if ($options instanceof JsObject && $options->has('calendarName')) {
                $cn = $options->get('calendarName');
                if (!($cn instanceof JsUndefined)) {
                    $calendarName = TypeConversion::toString($cn);
                }
            }
            $y = self::getSlotInt($this_, '[[ISOYear]]');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            $dd = self::getSlotInt($this_, '[[ISODay]]');
            $result = self::padISOYear($y) . '-' . self::pad2($m) . '-' . self::pad2($dd);
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $showCal = $calendarName === 'always'
                || ($calendarName === 'critical' && $cal !== 'iso8601')
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

        $d('toLocaleString', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            $y = self::getSlotInt($this_, '[[ISOYear]]');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            $dd = self::getSlotInt($this_, '[[ISODay]]');
            return new JsString(self::padISOYear($y) . '-' . self::pad2($m) . '-' . self::pad2($dd));
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
            $y = self::getSlotInt($this_, '[[ISOYear]]');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            $dd = self::getSlotInt($this_, '[[ISODay]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $yv = $item->get('year');
            if (!($yv instanceof JsUndefined)) {
                $y = (int) TypeConversion::toNumber($yv);
            }
            $mv = $item->get('month');
            if (!($mv instanceof JsUndefined)) {
                $m = (int) TypeConversion::toNumber($mv);
            }
            $dv = $item->get('day');
            if (!($dv instanceof JsUndefined)) {
                $dd = (int) TypeConversion::toNumber($dv);
            }
            self::validateISODate($y, $m, $dd);
            return self::createPlainDateObject($y, $m, $dd, $cal);
        }, 1);

        $d('add', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDate($this_);
            $dur = self::toDuration($args[0] ?? JsUndefined::instance());
            return self::plainDateAdd($this_, $dur, 1);
        }, 1);

        $d('subtract', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDate($this_);
            $dur = self::toDuration($args[0] ?? JsUndefined::instance());
            return self::plainDateAdd($this_, $dur, -1);
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
                self::getSlotInt($this_, '[[ISODay]]'),
                self::getSlotString($this_, '[[Calendar]]'),
            );
        }, 0);

        $d('toPlainMonthDay', function (JsValue $this_): JsValue {
            self::requirePlainDate($this_);
            return self::createPlainMonthDayObject(
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotString($this_, '[[Calendar]]'),
            );
        }, 0);

        $d('toZonedDateTime', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDate($this_);
            $item = $args[0] ?? JsUndefined::instance();
            if ($item instanceof JsString) {
                $timeZone = $item->value;
            } elseif ($item instanceof JsObject) {
                $tz = $item->get('timeZone');
                if ($tz instanceof JsUndefined) {
                    throw new TypeError('missing timeZone property');
                }
                $timeZone = TypeConversion::toString($tz);
            } else {
                throw new TypeError('Expected a string or an object with a timeZone property');
            }
            $y = self::getSlotInt($this_, '[[ISOYear]]');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            $dd = self::getSlotInt($this_, '[[ISODay]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            // Convert PlainDate at midnight in the given timezone to epoch nanoseconds.
            $ns = self::isoDateTimeToEpochNs($y, $m, $dd, 0, 0, 0, 0, 0, 0, $timeZone);
            return self::createZonedDateTimeObject($ns, $timeZone, $cal);
        }, 1);

        self::setToStringTag($proto, 'Temporal.PlainDate');

        // Constructor
        $ctor = JsFunction::fromCallable('PlainDate', function (JsValue $this_, array $args) use ($proto): JsValue {
            if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                throw new TypeError('Temporal.PlainDate must be called with new');
            }
            $y = (int) TypeConversion::toNumber($args[0] ?? JsUndefined::instance());
            $m = (int) TypeConversion::toNumber($args[1] ?? JsUndefined::instance());
            $dd = (int) TypeConversion::toNumber($args[2] ?? JsUndefined::instance());
            $cal = 'iso8601';
            if (isset($args[3]) && !($args[3] instanceof JsUndefined)) {
                $cal = TypeConversion::toString($args[3]);
                $cal = strtolower($cal);
            }
            self::validateISODate($y, $m, $dd);
            $this_->setPrototype($proto);
            self::setDateSlots($this_, $y, $m, $dd, $cal);
            return $this_;
        }, 3);
        $ctor->setConstructable();

        // Static: PlainDate.from
        $ctor->defineOwnProperty('from', PropertyDescriptor::data(
            JsFunction::fromCallable('from', function (JsValue $this_, array $args): JsValue {
                return self::toPlainDate($args[0] ?? JsUndefined::instance());
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
                return new JsNumber((float) self::compareISODate(
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
                return new JsNumber((float) self::getSlotInt($this_, $slot));
            });
        }

        $d = self::protoHelper($proto);

        $d('toString', function (JsValue $this_, array $args): JsValue {
            self::requirePlainTime($this_);
            $options = self::getOptionsObject($args[0] ?? JsUndefined::instance());
            $fractionalSecondDigits = self::getFractionalSecondDigits($options);
            $roundingMode = self::getRoundingMode($options, 'trunc');
            return new JsString(self::plainTimeToString($this_, $fractionalSecondDigits, $roundingMode));
        }, 0);

        $d('toJSON', function (JsValue $this_): JsValue {
            self::requirePlainTime($this_);
            return new JsString(self::plainTimeToString($this_, 'auto', 'trunc'));
        }, 0);

        $d('toLocaleString', function (JsValue $this_): JsValue {
            self::requirePlainTime($this_);
            return new JsString(self::plainTimeToString($this_, 'auto', 'trunc'));
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
            $h = self::getSlotInt($this_, '[[ISOHour]]');
            $min = self::getSlotInt($this_, '[[ISOMinute]]');
            $s = self::getSlotInt($this_, '[[ISOSecond]]');
            $ms = self::getSlotInt($this_, '[[ISOMillisecond]]');
            $us = self::getSlotInt($this_, '[[ISOMicrosecond]]');
            $ns = self::getSlotInt($this_, '[[ISONanosecond]]');
            $mapping = [
                'hour' => &$h, 'minute' => &$min,
                'second' => &$s, 'millisecond' => &$ms,
                'microsecond' => &$us, 'nanosecond' => &$ns,
            ];
            foreach ($mapping as $name => &$ref) {
                $v = $item->get($name);
                if (!($v instanceof JsUndefined)) {
                    $ref = (int) TypeConversion::toNumber($v);
                }
            }
            unset($ref);
            self::validateISOTime($h, $min, $s, $ms, $us, $ns);
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
            $dur = self::plainTimeDifference($this_, $other, $args[1] ?? JsUndefined::instance());
            // Negate for since
            return self::negateDuration($dur);
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
            return self::roundPlainTime($this_, $unit, $roundingMode, $increment);
        }, 1);

        self::setToStringTag($proto, 'Temporal.PlainTime');

        $ctor = JsFunction::fromCallable('PlainTime', function (JsValue $this_, array $args) use ($proto): JsValue {
            if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                throw new TypeError('Temporal.PlainTime must be called with new');
            }
            $h = isset($args[0]) && !($args[0] instanceof JsUndefined) ? (int) TypeConversion::toNumber($args[0]) : 0;
            $min = isset($args[1]) && !($args[1] instanceof JsUndefined) ? (int) TypeConversion::toNumber($args[1]) : 0;
            $s = isset($args[2]) && !($args[2] instanceof JsUndefined) ? (int) TypeConversion::toNumber($args[2]) : 0;
            $ms = isset($args[3]) && !($args[3] instanceof JsUndefined) ? (int) TypeConversion::toNumber($args[3]) : 0;
            $us = isset($args[4]) && !($args[4] instanceof JsUndefined) ? (int) TypeConversion::toNumber($args[4]) : 0;
            $ns = isset($args[5]) && !($args[5] instanceof JsUndefined) ? (int) TypeConversion::toNumber($args[5]) : 0;
            self::validateISOTime($h, $min, $s, $ms, $us, $ns);
            $this_->setPrototype($proto);
            self::setTimeSlots($this_, $h, $min, $s, $ms, $us, $ns);
            $this_->defineOwnProperty('[[IsPlainTime]]', PropertyDescriptor::data(new JsBoolean(true), false, false, false));
            return $this_;
        }, 0);
        $ctor->setConstructable();

        $ctor->defineOwnProperty('from', PropertyDescriptor::data(
            JsFunction::fromCallable('from', function (JsValue $this_, array $args): JsValue {
                return self::toPlainTime($args[0] ?? JsUndefined::instance());
            }, 1),
            true,
            false,
            true,
        ));

        $ctor->defineOwnProperty('compare', PropertyDescriptor::data(
            JsFunction::fromCallable('compare', function (JsValue $this_, array $args): JsValue {
                $one = self::toPlainTime($args[0] ?? JsUndefined::instance());
                $two = self::toPlainTime($args[1] ?? JsUndefined::instance());
                return new JsNumber((float) self::compareISOTime(
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
        foreach (['year' => '[[ISOYear]]', 'month' => '[[ISOMonth]]', 'day' => '[[ISODay]]'] as $name => $slot) {
            self::defineGetter($proto, $name, function (JsValue $this_) use ($slot): JsValue {
                self::requirePlainDateTime($this_);
                return new JsNumber((float) self::getSlotInt($this_, $slot));
            });
        }
        self::defineGetter($proto, 'monthCode', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            return new JsString('M' . str_pad((string) $m, 2, '0', STR_PAD_LEFT));
        });
        foreach (
            ['hour' => '[[ISOHour]]', 'minute' => '[[ISOMinute]]', 'second' => '[[ISOSecond]]',
            'millisecond' => '[[ISOMillisecond]]', 'microsecond' => '[[ISOMicrosecond]]', 'nanosecond' => '[[ISONanosecond]]'] as $name => $slot
        ) {
            self::defineGetter($proto, $name, function (JsValue $this_) use ($slot): JsValue {
                self::requirePlainDateTime($this_);
                return new JsNumber((float) self::getSlotInt($this_, $slot));
            });
        }
        self::defineGetter($proto, 'dayOfWeek', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
            return new JsNumber((float) self::isoDayOfWeek(
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
            ));
        });
        self::defineGetter($proto, 'dayOfYear', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
            return new JsNumber((float) self::isoDayOfYear(
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
            ));
        });
        self::defineGetter($proto, 'weekOfYear', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
            [$week] = self::isoWeekOfYear(
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
            );
            return $week === null ? JsUndefined::instance() : new JsNumber((float) $week);
        });
        self::defineGetter($proto, 'yearOfWeek', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
            [, $yearOfWeek] = self::isoWeekOfYear(
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
            );
            return $yearOfWeek === null ? JsUndefined::instance() : new JsNumber((float) $yearOfWeek);
        });
        self::defineGetter($proto, 'daysInWeek', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
            return new JsNumber(7.0);
        });
        self::defineGetter($proto, 'daysInMonth', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
            return new JsNumber((float) self::isoDaysInMonth(
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
            ));
        });
        self::defineGetter($proto, 'daysInYear', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
            return new JsNumber(
                (float) self::isoDaysInYear(self::getSlotInt($this_, '[[ISOYear]]')),
            );
        });
        self::defineGetter($proto, 'monthsInYear', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
            return new JsNumber(12.0);
        });
        self::defineGetter($proto, 'inLeapYear', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
            return new JsBoolean(self::isoIsLeapYear(self::getSlotInt($this_, '[[ISOYear]]')));
        });
        self::defineGetter($proto, 'era', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
            return JsUndefined::instance();
        });
        self::defineGetter($proto, 'eraYear', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
            return JsUndefined::instance();
        });

        $d = self::protoHelper($proto);

        $d('toString', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDateTime($this_);
            $options = self::getOptionsObject($args[0] ?? JsUndefined::instance());
            $fractionalSecondDigits = self::getFractionalSecondDigits($options);
            $roundingMode = self::getRoundingMode($options, 'trunc');
            $calendarName = 'auto';
            if ($options instanceof JsObject && $options->has('calendarName')) {
                $cn = $options->get('calendarName');
                if (!($cn instanceof JsUndefined)) {
                    $calendarName = TypeConversion::toString($cn);
                }
            }
            return new JsString(self::plainDateTimeToString($this_, $fractionalSecondDigits, $roundingMode, $calendarName));
        }, 0);

        $d('toJSON', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
            return new JsString(self::plainDateTimeToString($this_, 'auto', 'trunc', 'auto'));
        }, 0);

        $d('toLocaleString', function (JsValue $this_): JsValue {
            self::requirePlainDateTime($this_);
            return new JsString(self::plainDateTimeToString($this_, 'auto', 'trunc', 'auto'));
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
            return self::plainDateTimeAdd($this_, $dur, 1);
        }, 1);

        $d('subtract', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDateTime($this_);
            $dur = self::toDuration($args[0] ?? JsUndefined::instance());
            return self::plainDateTimeAdd($this_, $dur, -1);
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

        $d('withCalendar', function (JsValue $this_, array $args): JsValue {
            self::requirePlainDateTime($this_);
            $cal = TypeConversion::toString($args[0] ?? JsUndefined::instance());
            $cal = strtolower($cal);
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

        $ctor = JsFunction::fromCallable('PlainDateTime', function (JsValue $this_, array $args) use ($proto): JsValue {
            if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                throw new TypeError('Temporal.PlainDateTime must be called with new');
            }
            $y = (int) TypeConversion::toNumber($args[0] ?? JsUndefined::instance());
            $m = (int) TypeConversion::toNumber($args[1] ?? JsUndefined::instance());
            $dd = (int) TypeConversion::toNumber($args[2] ?? JsUndefined::instance());
            $h = isset($args[3]) && !($args[3] instanceof JsUndefined) ? (int) TypeConversion::toNumber($args[3]) : 0;
            $min = isset($args[4]) && !($args[4] instanceof JsUndefined) ? (int) TypeConversion::toNumber($args[4]) : 0;
            $s = isset($args[5]) && !($args[5] instanceof JsUndefined) ? (int) TypeConversion::toNumber($args[5]) : 0;
            $ms = isset($args[6]) && !($args[6] instanceof JsUndefined) ? (int) TypeConversion::toNumber($args[6]) : 0;
            $us = isset($args[7]) && !($args[7] instanceof JsUndefined) ? (int) TypeConversion::toNumber($args[7]) : 0;
            $ns = isset($args[8]) && !($args[8] instanceof JsUndefined) ? (int) TypeConversion::toNumber($args[8]) : 0;
            $cal = 'iso8601';
            if (isset($args[9]) && !($args[9] instanceof JsUndefined)) {
                $cal = strtolower(TypeConversion::toString($args[9]));
            }
            self::validateISODate($y, $m, $dd);
            self::validateISOTime($h, $min, $s, $ms, $us, $ns);
            $this_->setPrototype($proto);
            self::setDateSlots($this_, $y, $m, $dd, $cal);
            self::setTimeSlots($this_, $h, $min, $s, $ms, $us, $ns);
            $this_->defineOwnProperty('[[IsPlainDateTime]]', PropertyDescriptor::data(new JsBoolean(true), false, false, false));
            return $this_;
        }, 3);
        $ctor->setConstructable();

        $ctor->defineOwnProperty('from', PropertyDescriptor::data(
            JsFunction::fromCallable('from', fn (JsValue $this_, array $args): JsValue => self::toPlainDateTime($args[0] ?? JsUndefined::instance()), 1),
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
                    return new JsNumber((float) $cmpDate);
                }
                return new JsNumber((float) self::compareISOTime(
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
            return new JsNumber((float) self::getSlotInt($this_, '[[ISOYear]]'));
        });
        self::defineGetter($proto, 'month', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            return new JsNumber((float) self::getSlotInt($this_, '[[ISOMonth]]'));
        });
        self::defineGetter($proto, 'monthCode', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            return new JsString('M' . str_pad((string) $m, 2, '0', STR_PAD_LEFT));
        });
        self::defineGetter($proto, 'daysInMonth', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            return new JsNumber((float) self::isoDaysInMonth(self::getSlotInt($this_, '[[ISOYear]]'), self::getSlotInt($this_, '[[ISOMonth]]')));
        });
        self::defineGetter($proto, 'daysInYear', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            return new JsNumber((float) self::isoDaysInYear(self::getSlotInt($this_, '[[ISOYear]]')));
        });
        self::defineGetter($proto, 'monthsInYear', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            return new JsNumber(12.0);
        });
        self::defineGetter($proto, 'inLeapYear', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            return new JsBoolean(self::isoIsLeapYear(self::getSlotInt($this_, '[[ISOYear]]')));
        });
        self::defineGetter($proto, 'era', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            return JsUndefined::instance();
        });
        self::defineGetter($proto, 'eraYear', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            return JsUndefined::instance();
        });

        $d = self::protoHelper($proto);

        $d('toString', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            $y = self::getSlotInt($this_, '[[ISOYear]]');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            return new JsString(self::padISOYear($y) . '-' . self::pad2($m));
        }, 0);

        $d('toJSON', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            $y = self::getSlotInt($this_, '[[ISOYear]]');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            return new JsString(self::padISOYear($y) . '-' . self::pad2($m));
        }, 0);

        $d('toLocaleString', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainYearMonth]]', 'Temporal.PlainYearMonth');
            $y = self::getSlotInt($this_, '[[ISOYear]]');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            return new JsString(self::padISOYear($y) . '-' . self::pad2($m));
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
            $dd = (int) TypeConversion::toNumber($dayVal);
            return self::createPlainDateObject(
                self::getSlotInt($this_, '[[ISOYear]]'),
                self::getSlotInt($this_, '[[ISOMonth]]'),
                $dd,
                self::getSlotString($this_, '[[Calendar]]'),
            );
        }, 1);

        self::setToStringTag($proto, 'Temporal.PlainYearMonth');

        $ctor = JsFunction::fromCallable('PlainYearMonth', function (JsValue $this_, array $args) use ($proto): JsValue {
            if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                throw new TypeError('Temporal.PlainYearMonth must be called with new');
            }
            $y = (int) TypeConversion::toNumber($args[0] ?? JsUndefined::instance());
            $m = (int) TypeConversion::toNumber($args[1] ?? JsUndefined::instance());
            $cal = 'iso8601';
            if (isset($args[2]) && !($args[2] instanceof JsUndefined)) {
                $cal = strtolower(TypeConversion::toString($args[2]));
            }
            $refDay = isset($args[3]) && !($args[3] instanceof JsUndefined) ? (int) TypeConversion::toNumber($args[3]) : 1;
            self::validateISODate($y, $m, $refDay);
            $this_->setPrototype($proto);
            self::setDateSlots($this_, $y, $m, $refDay, $cal);
            $this_->defineOwnProperty('[[IsPlainYearMonth]]', PropertyDescriptor::data(new JsBoolean(true), false, false, false));
            return $this_;
        }, 2);
        $ctor->setConstructable();

        $ctor->defineOwnProperty('from', PropertyDescriptor::data(
            JsFunction::fromCallable('from', fn (JsValue $this_, array $args): JsValue => self::toPlainYearMonth($args[0] ?? JsUndefined::instance()), 1),
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
                    return new JsNumber((float) $c);
                }
                return new JsNumber((float) (self::getSlotInt($one, '[[ISOMonth]]') <=> self::getSlotInt($two, '[[ISOMonth]]')));
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
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            return new JsString('M' . str_pad((string) $m, 2, '0', STR_PAD_LEFT));
        });
        self::defineGetter($proto, 'day', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainMonthDay]]', 'Temporal.PlainMonthDay');
            return new JsNumber((float) self::getSlotInt($this_, '[[ISODay]]'));
        });

        $d = self::protoHelper($proto);

        $d('toString', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainMonthDay]]', 'Temporal.PlainMonthDay');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            $dd = self::getSlotInt($this_, '[[ISODay]]');
            return new JsString(self::pad2($m) . '-' . self::pad2($dd));
        }, 0);

        $d('toJSON', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainMonthDay]]', 'Temporal.PlainMonthDay');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            $dd = self::getSlotInt($this_, '[[ISODay]]');
            return new JsString(self::pad2($m) . '-' . self::pad2($dd));
        }, 0);

        $d('toLocaleString', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsPlainMonthDay]]', 'Temporal.PlainMonthDay');
            $m = self::getSlotInt($this_, '[[ISOMonth]]');
            $dd = self::getSlotInt($this_, '[[ISODay]]');
            return new JsString(self::pad2($m) . '-' . self::pad2($dd));
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

        $d('toPlainDate', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsPlainMonthDay]]', 'Temporal.PlainMonthDay');
            $item = $args[0] ?? JsUndefined::instance();
            if (!$item instanceof JsObject) {
                throw new TypeError('argument must be an object');
            }
            $yearVal = $item->get('year');
            if ($yearVal instanceof JsUndefined) {
                throw new TypeError('year is required');
            }
            $y = (int) TypeConversion::toNumber($yearVal);
            return self::createPlainDateObject(
                $y,
                self::getSlotInt($this_, '[[ISOMonth]]'),
                self::getSlotInt($this_, '[[ISODay]]'),
                self::getSlotString($this_, '[[Calendar]]'),
            );
        }, 1);

        self::setToStringTag($proto, 'Temporal.PlainMonthDay');

        $ctor = JsFunction::fromCallable('PlainMonthDay', function (JsValue $this_, array $args) use ($proto): JsValue {
            if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                throw new TypeError('Temporal.PlainMonthDay must be called with new');
            }
            $m = (int) TypeConversion::toNumber($args[0] ?? JsUndefined::instance());
            $dd = (int) TypeConversion::toNumber($args[1] ?? JsUndefined::instance());
            $cal = 'iso8601';
            if (isset($args[2]) && !($args[2] instanceof JsUndefined)) {
                $cal = strtolower(TypeConversion::toString($args[2]));
            }
            $refYear = isset($args[3]) && !($args[3] instanceof JsUndefined) ? (int) TypeConversion::toNumber($args[3]) : 1972;
            self::validateISODate($refYear, $m, $dd);
            $this_->setPrototype($proto);
            self::setDateSlots($this_, $refYear, $m, $dd, $cal);
            $this_->defineOwnProperty('[[IsPlainMonthDay]]', PropertyDescriptor::data(new JsBoolean(true), false, false, false));
            return $this_;
        }, 2);
        $ctor->setConstructable();

        $ctor->defineOwnProperty('from', PropertyDescriptor::data(
            JsFunction::fromCallable('from', fn (JsValue $this_, array $args): JsValue => self::toPlainMonthDay($args[0] ?? JsUndefined::instance()), 1),
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
            return new JsNumber(self::bigFloorDiv($ns, '1000000'));
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
        foreach ($dtFields as $field) {
            self::defineGetter($proto, $field, function (JsValue $this_) use ($field): JsValue {
                self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
                $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
                $tz = self::getSlotString($this_, '[[TimeZone]]');
                $parts = self::epochNsToISOParts($ns, $tz);
                return new JsNumber((float) ($parts[$field] ?? 0));
            });
        }
        self::defineGetter($proto, 'monthCode', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            return new JsString('M' . str_pad((string) $parts['month'], 2, '0', STR_PAD_LEFT));
        });
        self::defineGetter($proto, 'dayOfWeek', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            return new JsNumber((float) self::isoDayOfWeek($parts['year'], $parts['month'], $parts['day']));
        });
        self::defineGetter($proto, 'dayOfYear', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            return new JsNumber((float) self::isoDayOfYear($parts['year'], $parts['month'], $parts['day']));
        });
        self::defineGetter($proto, 'daysInMonth', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            return new JsNumber((float) self::isoDaysInMonth($parts['year'], $parts['month']));
        });
        self::defineGetter($proto, 'daysInYear', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            return new JsNumber((float) self::isoDaysInYear($parts['year']));
        });
        self::defineGetter($proto, 'inLeapYear', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            return new JsBoolean(self::isoIsLeapYear($parts['year']));
        });
        self::defineGetter($proto, 'era', fn (JsValue $this_): JsValue => (self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime') ? JsUndefined::instance() : JsUndefined::instance()) ?: JsUndefined::instance());
        self::defineGetter($proto, 'eraYear', fn (JsValue $this_): JsValue => (self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime') ? JsUndefined::instance() : JsUndefined::instance()) ?: JsUndefined::instance());

        $d = self::protoHelper($proto);

        $d('toString', function (JsValue $this_, array $args): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            $ns = self::getSlotString($this_, '[[EpochNanoseconds]]');
            $tz = self::getSlotString($this_, '[[TimeZone]]');
            $cal = self::getSlotString($this_, '[[Calendar]]');
            $parts = self::epochNsToISOParts($ns, $tz);
            $options = self::getOptionsObject($args[0] ?? JsUndefined::instance());
            $fractionalSecondDigits = self::getFractionalSecondDigits($options);
            $roundingMode = self::getRoundingMode($options, 'trunc');

            $timeStr = self::formatISOTime(
                $parts['hour'],
                $parts['minute'],
                $parts['second'],
                $parts['millisecond'],
                $parts['microsecond'],
                $parts['nanosecond'],
                $fractionalSecondDigits,
                $roundingMode
            );
            $dateStr = self::padISOYear($parts['year']) . '-' . self::pad2($parts['month']) . '-' . self::pad2($parts['day']);

            // Compute offset string.
            $offsetStr = self::timeZoneOffsetString($ns, $tz);

            $result = "{$dateStr}T{$timeStr}{$offsetStr}[{$tz}]";
            if ($cal !== 'iso8601') {
                $result .= "[u-ca={$cal}]";
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

        $d('toLocaleString', function (JsValue $this_): JsValue {
            self::requireBrand($this_, '[[IsZonedDateTime]]', 'Temporal.ZonedDateTime');
            // Fallback to toString.
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

        self::setToStringTag($proto, 'Temporal.ZonedDateTime');

        $ctor = JsFunction::fromCallable('ZonedDateTime', function (JsValue $this_, array $args) use ($proto): JsValue {
            if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                throw new TypeError('Temporal.ZonedDateTime must be called with new');
            }
            $nsArg = $args[0] ?? JsUndefined::instance();
            if (!$nsArg instanceof JsBigInt) {
                throw new TypeError('ZonedDateTime requires a BigInt epochNanoseconds');
            }
            $ns = $nsArg->value;
            self::validateInstantRange($ns);
            $tzArg = $args[1] ?? JsUndefined::instance();
            $timeZone = TypeConversion::toString($tzArg);
            $cal = 'iso8601';
            if (isset($args[2]) && !($args[2] instanceof JsUndefined)) {
                $cal = strtolower(TypeConversion::toString($args[2]));
            }
            $this_->setPrototype($proto);
            $this_->defineOwnProperty('[[EpochNanoseconds]]', PropertyDescriptor::data(new JsString($ns), false, false, false));
            $this_->defineOwnProperty('[[TimeZone]]', PropertyDescriptor::data(new JsString($timeZone), false, false, false));
            $this_->defineOwnProperty('[[Calendar]]', PropertyDescriptor::data(new JsString($cal), false, false, false));
            $this_->defineOwnProperty('[[IsZonedDateTime]]', PropertyDescriptor::data(new JsBoolean(true), false, false, false));
            return $this_;
        }, 2);
        $ctor->setConstructable();

        $ctor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($ctor, true, false, true));

        $temporal->defineOwnProperty('ZonedDateTime', PropertyDescriptor::data($ctor, true, false, true));
        self::$zonedDateTimeProto = $proto;

        return $proto;
    }

    // -----------------------------------------------------------------------
    // Temporal.Now
    // -----------------------------------------------------------------------

    private static function installNow(
        JsObject $temporal,
        JsObject $instantProto,
        JsObject $plainDateProto,
        JsObject $plainTimeProto,
        JsObject $plainDateTimeProto,
    ): void {
        $now = new JsObject();

        $m = static fn (string $n, \Closure $fn, int $len = 0) => $now->defineOwnProperty(
            $n,
            PropertyDescriptor::data(JsFunction::fromCallable($n, $fn, $len), true, false, true),
        );

        $m('instant', function (JsValue $this_): JsValue {
            $ms = (int) (microtime(true) * 1000);
            $ns = bcmul((string) $ms, '1000000', 0);
            return self::createInstantObject($ns);
        });

        $m('timeZoneId', function (JsValue $this_): JsValue {
            return new JsString(date_default_timezone_get());
        });

        $m('plainDateISO', function (JsValue $this_, array $args): JsValue {
            $tz = date_default_timezone_get();
            if (isset($args[0]) && !($args[0] instanceof JsUndefined)) {
                $tz = TypeConversion::toString($args[0]);
            }
            $dt = new \DateTimeImmutable('now', self::resolveTimeZone($tz));
            return self::createPlainDateObject(
                (int) $dt->format('Y'),
                (int) $dt->format('n'),
                (int) $dt->format('j'),
                'iso8601',
            );
        }, 0);

        $m('plainTimeISO', function (JsValue $this_, array $args): JsValue {
            $tz = date_default_timezone_get();
            if (isset($args[0]) && !($args[0] instanceof JsUndefined)) {
                $tz = TypeConversion::toString($args[0]);
            }
            $dt = new \DateTimeImmutable('now', self::resolveTimeZone($tz));
            return self::createPlainTimeObject(
                (int) $dt->format('G'),
                (int) $dt->format('i'),
                (int) $dt->format('s'),
                0,
                0,
                0,
            );
        }, 0);

        $m('plainDateTimeISO', function (JsValue $this_, array $args): JsValue {
            $tz = date_default_timezone_get();
            if (isset($args[0]) && !($args[0] instanceof JsUndefined)) {
                $tz = TypeConversion::toString($args[0]);
            }
            $dt = new \DateTimeImmutable('now', self::resolveTimeZone($tz));
            return self::createPlainDateTimeObject(
                (int) $dt->format('Y'),
                (int) $dt->format('n'),
                (int) $dt->format('j'),
                (int) $dt->format('G'),
                (int) $dt->format('i'),
                (int) $dt->format('s'),
                0,
                0,
                0,
                'iso8601',
            );
        }, 0);

        $m('zonedDateTimeISO', function (JsValue $this_, array $args): JsValue {
            $tz = date_default_timezone_get();
            if (isset($args[0]) && !($args[0] instanceof JsUndefined)) {
                $tz = TypeConversion::toString($args[0]);
            }
            $ms = (int) (microtime(true) * 1000);
            $ns = bcmul((string) $ms, '1000000', 0);
            return self::createZonedDateTimeObject($ns, $tz, 'iso8601');
        }, 0);

        self::setToStringTag($now, 'Temporal.Now');

        $temporal->defineOwnProperty('Now', PropertyDescriptor::data($now, true, false, true));
    }

    // -----------------------------------------------------------------------
    // Helpers: slot access
    // -----------------------------------------------------------------------

    private static function getSlotInt(JsValue $obj, string $slot): int
    {
        if (!$obj instanceof JsObject) {
            return 0;
        }
        $v = $obj->get($slot);
        if ($v instanceof JsNumber) {
            return (int) $v->value;
        }
        if ($v instanceof JsString) {
            return (int) $v->value;
        }
        return 0;
    }

    private static function getSlotString(JsValue $obj, string $slot): string
    {
        if (!$obj instanceof JsObject) {
            return '';
        }
        $v = $obj->get($slot);
        if ($v instanceof JsString) {
            return $v->value;
        }
        return '';
    }

    private static function setDateSlots(JsObject $obj, int $y, int $m, int $d, string $cal): void
    {
        $obj->defineOwnProperty('[[ISOYear]]', PropertyDescriptor::data(new JsNumber((float) $y), false, false, false));
        $obj->defineOwnProperty('[[ISOMonth]]', PropertyDescriptor::data(new JsNumber((float) $m), false, false, false));
        $obj->defineOwnProperty('[[ISODay]]', PropertyDescriptor::data(new JsNumber((float) $d), false, false, false));
        $obj->defineOwnProperty('[[Calendar]]', PropertyDescriptor::data(new JsString($cal), false, false, false));
    }

    private static function setTimeSlots(JsObject $obj, int $h, int $min, int $s, int $ms, int $us, int $ns): void
    {
        $obj->defineOwnProperty('[[ISOHour]]', PropertyDescriptor::data(new JsNumber((float) $h), false, false, false));
        $obj->defineOwnProperty('[[ISOMinute]]', PropertyDescriptor::data(new JsNumber((float) $min), false, false, false));
        $obj->defineOwnProperty('[[ISOSecond]]', PropertyDescriptor::data(new JsNumber((float) $s), false, false, false));
        $obj->defineOwnProperty('[[ISOMillisecond]]', PropertyDescriptor::data(new JsNumber((float) $ms), false, false, false));
        $obj->defineOwnProperty('[[ISOMicrosecond]]', PropertyDescriptor::data(new JsNumber((float) $us), false, false, false));
        $obj->defineOwnProperty('[[ISONanosecond]]', PropertyDescriptor::data(new JsNumber((float) $ns), false, false, false));
    }

    // -----------------------------------------------------------------------
    // Helpers: brand checks
    // -----------------------------------------------------------------------

    private static function requireInstant(JsValue $this_): string
    {
        if (!$this_ instanceof JsObject || !$this_->has('[[EpochNanoseconds]]')) {
            throw new TypeError('this is not a Temporal.Instant');
        }
        $v = $this_->get('[[EpochNanoseconds]]');
        return $v instanceof JsString ? $v->value : '0';
    }

    private static function requireDuration(JsValue $this_): void
    {
        if (!$this_ instanceof JsObject || !$this_->has('[[IsDuration]]')) {
            throw new TypeError('this is not a Temporal.Duration');
        }
    }

    private static function requirePlainDate(JsValue $this_): void
    {
        if (!$this_ instanceof JsObject || !$this_->has('[[ISOYear]]') || $this_->has('[[IsPlainTime]]') || $this_->has('[[IsPlainDateTime]]') || $this_->has('[[IsPlainYearMonth]]') || $this_->has('[[IsPlainMonthDay]]') || $this_->has('[[IsZonedDateTime]]') || $this_->has('[[IsDuration]]') || $this_->has('[[EpochNanoseconds]]')) {
            throw new TypeError('this is not a Temporal.PlainDate');
        }
    }

    private static function requirePlainTime(JsValue $this_): bool
    {
        if (!$this_ instanceof JsObject || !$this_->has('[[IsPlainTime]]')) {
            throw new TypeError('this is not a Temporal.PlainTime');
        }
        return true;
    }

    private static function requirePlainDateTime(JsValue $this_): bool
    {
        if (!$this_ instanceof JsObject || !$this_->has('[[IsPlainDateTime]]')) {
            throw new TypeError('this is not a Temporal.PlainDateTime');
        }
        return true;
    }

    private static function requireBrand(JsValue $this_, string $brand, string $typeName): bool
    {
        if (!$this_ instanceof JsObject || !$this_->has($brand)) {
            throw new TypeError("this is not a {$typeName}");
        }
        return true;
    }

    // -----------------------------------------------------------------------
    // Helpers: ISO calendar
    // -----------------------------------------------------------------------

    private static function isoIsLeapYear(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || ($year % 400 === 0);
    }

    private static function isoDaysInMonth(int $year, int $month): int
    {
        return match ($month) {
            1, 3, 5, 7, 8, 10, 12 => 31,
            4, 6, 9, 11 => 30,
            2 => self::isoIsLeapYear($year) ? 29 : 28,
            default => 30,
        };
    }

    private static function isoDaysInYear(int $year): int
    {
        return self::isoIsLeapYear($year) ? 366 : 365;
    }

    private static function isoDayOfYear(int $year, int $month, int $day): int
    {
        $total = 0;
        for ($m = 1; $m < $month; $m++) {
            $total += self::isoDaysInMonth($year, $m);
        }
        return $total + $day;
    }

    private static function isoDayOfWeek(int $year, int $month, int $day): int
    {
        // Zeller-like formula. PHP's mktime can handle years < 100 badly, so use formula.
        // Tomohiko Sakamoto's algorithm: returns 0 = Sunday, 1 = Monday, ... 6 = Saturday.
        // ISO weekday: 1 = Monday, 7 = Sunday.
        $t = [0, 3, 2, 5, 0, 3, 5, 1, 4, 6, 2, 4];
        $y = $year;
        if ($month < 3) {
            $y--;
        }
        $dow = ($y + intdiv($y, 4) - intdiv($y, 100) + intdiv($y, 400) + $t[$month - 1] + $day) % 7;
        // Convert from 0=Sun to ISO 1=Mon..7=Sun.
        return $dow === 0 ? 7 : $dow;
    }

    /**
     * ISO week of year per ISO 8601 (week starts Monday, first week contains Jan 4).
     * @return array{0: ?int, 1: ?int} [weekOfYear, yearOfWeek]
     */
    private static function isoWeekOfYear(int $year, int $month, int $day): array
    {
        $dayOfYear = self::isoDayOfYear($year, $month, $day);
        $dow = self::isoDayOfWeek($year, $month, $day); // 1=Mon..7=Sun
        // ISO week number: the week containing Jan 4 is week 1.
        $jan4Dow = self::isoDayOfWeek($year, 1, 4);
        $startOfWeek1 = 4 - $jan4Dow + 1; // Day of year of Monday of week 1
        if ($startOfWeek1 > 1) {
            $startOfWeek1 -= 7;
        }
        $weekNum = intdiv($dayOfYear - $startOfWeek1, 7) + 1;
        $yearOfWeek = $year;
        if ($weekNum < 1) {
            $yearOfWeek = $year - 1;
            // Recalculate with previous year.
            $dec31DayOfYear = self::isoDaysInYear($yearOfWeek);
            $jan4DowPrev = self::isoDayOfWeek($yearOfWeek, 1, 4);
            $startOfWeek1Prev = 4 - $jan4DowPrev + 1;
            if ($startOfWeek1Prev > 1) {
                $startOfWeek1Prev -= 7;
            }
            $weekNum = intdiv($dec31DayOfYear + ($dayOfYear - self::isoDaysInYear($year)) - $startOfWeek1Prev, 7) + 1;
        } elseif ($weekNum > 52) {
            // Check if it belongs to next year.
            $dec31Dow = self::isoDayOfWeek($year, 12, 31);
            if ($dec31Dow < 4) {
                // Belongs to week 1 of next year.
                $yearOfWeek = $year + 1;
                $weekNum = 1;
            }
        }
        return [$weekNum, $yearOfWeek];
    }

    private static function validateISODate(int $y, int $m, int $d): void
    {
        if ($m < 1 || $m > 12) {
            throw new RangeError("Invalid month: {$m}");
        }
        $dim = self::isoDaysInMonth($y, $m);
        if ($d < 1 || $d > $dim) {
            throw new RangeError("Invalid day: {$d}");
        }
        if ($y < self::ISO_YEAR_MIN || $y > self::ISO_YEAR_MAX) {
            throw new RangeError("Invalid year: {$y}");
        }
    }

    private static function validateISOTime(int $h, int $m, int $s, int $ms, int $us, int $ns): void
    {
        if (
            $h < 0 || $h > 23 || $m < 0 || $m > 59 || $s < 0 || $s > 59
            || $ms < 0 || $ms > 999 || $us < 0 || $us > 999 || $ns < 0 || $ns > 999
        ) {
            throw new RangeError('Invalid time');
        }
    }

    private static function compareISODate(int $y1, int $m1, int $d1, int $y2, int $m2, int $d2): int
    {
        if ($y1 !== $y2) {
            return $y1 < $y2 ? -1 : 1;
        }
        if ($m1 !== $m2) {
            return $m1 < $m2 ? -1 : 1;
        }
        if ($d1 !== $d2) {
            return $d1 < $d2 ? -1 : 1;
        }
        return 0;
    }

    private static function compareISOTime(int $h1, int $m1, int $s1, int $ms1, int $us1, int $ns1, int $h2, int $m2, int $s2, int $ms2, int $us2, int $ns2): int
    {
        foreach ([[$h1, $h2], [$m1, $m2], [$s1, $s2], [$ms1, $ms2], [$us1, $us2], [$ns1, $ns2]] as [$a, $b]) {
            if ($a !== $b) {
                return $a < $b ? -1 : 1;
            }
        }
        return 0;
    }

    // -----------------------------------------------------------------------
    // Helpers: BigInt nanosecond arithmetic via bcmath
    // -----------------------------------------------------------------------

    private static function bigCmp(string $a, string $b): int
    {
        return bccomp($a, $b, 0);
    }

    private static function bigFloorDiv(string $ns, string $divisor): float
    {
        // Floor division toward negative infinity for epoch milliseconds.
        $neg = (isset($ns[0]) && $ns[0] === '-');
        if (!$neg) {
            $q = bcdiv($ns, $divisor, 0);
            return (float) $q;
        }
        // For negative: floor division.
        $abs = substr($ns, 1);
        $q = bcdiv($abs, $divisor, 0);
        $rem = bcsub($abs, bcmul($q, $divisor, 0), 0);
        if ($rem !== '0') {
            $q = bcadd($q, '1', 0);
        }
        return -1.0 * (float) $q;
    }

    private static function validateInstantRange(string $ns): void
    {
        if (bccomp($ns, self::NS_MAX, 0) > 0 || bccomp($ns, self::NS_MIN, 0) < 0) {
            throw new RangeError('Instant outside representable range');
        }
    }

    private static function toBigIntNsFromArg(JsValue $arg): string
    {
        if ($arg instanceof JsBigInt) {
            return $arg->value;
        }
        if ($arg instanceof JsString) {
            // Parse as BigInt string.
            $str = trim($arg->value);
            if (!preg_match('/^-?[0-9]+$/', $str)) {
                throw new \PhpJs\Exceptions\SyntaxError("Cannot convert {$str} to a BigInt");
            }
            return (new JsBigInt($str))->value;
        }
        if ($arg instanceof JsNumber) {
            throw new TypeError('Temporal.Instant requires a BigInt, not a Number');
        }
        throw new TypeError('Temporal.Instant requires a BigInt');
    }

    // -----------------------------------------------------------------------
    // Helpers: Instant string parsing
    // -----------------------------------------------------------------------

    private static function toInstantNs(JsValue $item): string
    {
        if ($item instanceof JsObject && $item->has('[[EpochNanoseconds]]')) {
            return self::requireInstant($item);
        }
        if ($item instanceof JsObject && $item->has('[[IsZonedDateTime]]')) {
            return self::getSlotString($item, '[[EpochNanoseconds]]');
        }
        $str = TypeConversion::toString($item);
        return self::parseInstantString($str);
    }

    private static function parseInstantString(string $str): string
    {
        // ISO 8601 with required timezone offset: YYYY-MM-DDTHH:MM[:SS[.fractional]]Z or +/-HH:MM
        $pattern = '/^([+-]?\d{4,6})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2})(?:[.,](\d{1,9}))?)?([Zz]|[+-]\d{2}:?\d{2})(?:\[.*?\])*$/';
        if (!preg_match($pattern, $str, $m)) {
            throw new RangeError("Invalid Instant string: {$str}");
        }

        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        $hour = (int) $m[4];
        $min = (int) $m[5];
        $sec = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0;
        $frac = isset($m[7]) && $m[7] !== '' ? str_pad($m[7], 9, '0') : '000000000';
        $tz = $m[8];

        // Offset seconds.
        $offsetNs = '0';
        if (strtoupper($tz) !== 'Z') {
            $sign = $tz[0] === '-' ? -1 : 1;
            $tzPart = preg_replace('/[^0-9]/', '', substr($tz, 1));
            $tzH = (int) substr($tzPart, 0, 2);
            $tzM = strlen($tzPart) >= 4 ? (int) substr($tzPart, 2, 2) : 0;
            $offsetSec = $sign * ($tzH * 3600 + $tzM * 60);
            $offsetNs = bcmul((string) $offsetSec, '1000000000', 0);
        }

        // Convert to epoch nanoseconds.
        try {
            $dt = new \DateTimeImmutable('2000-01-01 00:00:00', new \DateTimeZone('UTC'));
            $dt = $dt->setDate($year, $month, $day);
            $dt = $dt->setTime($hour, $min, $sec);
            $epochSec = $dt->format('U');
        } catch (\Throwable) {
            throw new RangeError("Invalid Instant string: {$str}");
        }

        $epochNs = bcmul($epochSec, '1000000000', 0);
        // Add fractional nanoseconds.
        $subSecNs = ltrim($frac, '0') ?: '0';
        if ($subSecNs !== '0') {
            $subSecNs = $frac; // Use the full padded string.
            $epochNs = bcadd($epochNs, $subSecNs, 0);
        }
        // Subtract the offset.
        $epochNs = bcsub($epochNs, $offsetNs, 0);

        return $epochNs;
    }

    private static function instantToString(string $ns, string|int $fractionalSecondDigits = 'auto', string $roundingMode = 'trunc'): string
    {
        // Split ns into seconds and sub-second nanoseconds.
        $negative = isset($ns[0]) && $ns[0] === '-';
        $abs = $negative ? substr($ns, 1) : $ns;

        // seconds = abs / 1e9, subNs = abs % 1e9
        $sec = bcdiv($abs, '1000000000', 0);
        $subNs = bcsub($abs, bcmul($sec, '1000000000', 0), 0);

        if ($negative && $subNs !== '0') {
            // For negative timestamps with fractional part: adjust.
            $sec = bcadd($sec, '1', 0);
            $subNs = bcsub('1000000000', $subNs, 0);
        }

        $epochSec = $negative ? '-' . $sec : $sec;

        try {
            $dt = new \DateTimeImmutable('@' . $epochSec);
            $dt = $dt->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return 'Invalid Instant';
        }

        $year = (int) $dt->format('Y');
        $dateStr = self::padISOYear($year) . $dt->format('-m-d\TH:i:s');

        $subNsPadded = str_pad($subNs, 9, '0', STR_PAD_LEFT);
        $fracStr = self::formatSubSecond($subNsPadded, $fractionalSecondDigits);

        return $dateStr . $fracStr . 'Z';
    }

    private static function instantToStringInZone(string $ns, string $timeZone, string|int $fractionalSecondDigits = 'auto', string $roundingMode = 'trunc'): string
    {
        $parts = self::epochNsToISOParts($ns, $timeZone);
        $dateStr = self::padISOYear($parts['year']) . '-' . self::pad2($parts['month']) . '-' . self::pad2($parts['day']);
        $timeStr = self::formatISOTime(
            $parts['hour'],
            $parts['minute'],
            $parts['second'],
            $parts['millisecond'],
            $parts['microsecond'],
            $parts['nanosecond'],
            $fractionalSecondDigits,
            $roundingMode
        );
        $offsetStr = self::timeZoneOffsetString($ns, $timeZone);
        return "{$dateStr}T{$timeStr}{$offsetStr}";
    }

    // -----------------------------------------------------------------------
    // Helpers: object creation
    // -----------------------------------------------------------------------

    private static function createPlainDateObject(int $y, int $m, int $d, string $cal): JsObject
    {
        $obj = new JsObject(self::$plainDateProto);
        self::setDateSlots($obj, $y, $m, $d, $cal);
        return $obj;
    }

    private static function createPlainTimeObject(int $h, int $min, int $s, int $ms, int $us, int $ns): JsObject
    {
        $obj = new JsObject(self::$plainTimeProto);
        self::setTimeSlots($obj, $h, $min, $s, $ms, $us, $ns);
        $obj->defineOwnProperty('[[IsPlainTime]]', PropertyDescriptor::data(new JsBoolean(true), false, false, false));
        return $obj;
    }

    private static function createPlainDateTimeObject(int $y, int $m, int $d, int $h, int $min, int $s, int $ms, int $us, int $ns, string $cal): JsObject
    {
        $obj = new JsObject(self::$plainDateTimeProto);
        self::setDateSlots($obj, $y, $m, $d, $cal);
        self::setTimeSlots($obj, $h, $min, $s, $ms, $us, $ns);
        $obj->defineOwnProperty('[[IsPlainDateTime]]', PropertyDescriptor::data(new JsBoolean(true), false, false, false));
        return $obj;
    }

    private static function createPlainYearMonthObject(int $y, int $m, int $refDay, string $cal): JsObject
    {
        $obj = new JsObject(self::$plainYearMonthProto);
        self::setDateSlots($obj, $y, $m, $refDay, $cal);
        $obj->defineOwnProperty('[[IsPlainYearMonth]]', PropertyDescriptor::data(new JsBoolean(true), false, false, false));
        return $obj;
    }

    private static function createPlainMonthDayObject(int $m, int $d, int $refYear, string $cal): JsObject
    {
        $obj = new JsObject(self::$plainMonthDayProto);
        self::setDateSlots($obj, $refYear, $m, $d, $cal);
        $obj->defineOwnProperty('[[IsPlainMonthDay]]', PropertyDescriptor::data(new JsBoolean(true), false, false, false));
        return $obj;
    }

    private static function createZonedDateTimeObject(string $ns, string $tz, string $cal): JsObject
    {
        $obj = new JsObject(self::$zonedDateTimeProto);
        $obj->defineOwnProperty('[[EpochNanoseconds]]', PropertyDescriptor::data(new JsString($ns), false, false, false));
        $obj->defineOwnProperty('[[TimeZone]]', PropertyDescriptor::data(new JsString($tz), false, false, false));
        $obj->defineOwnProperty('[[Calendar]]', PropertyDescriptor::data(new JsString($cal), false, false, false));
        $obj->defineOwnProperty('[[IsZonedDateTime]]', PropertyDescriptor::data(new JsBoolean(true), false, false, false));
        return $obj;
    }

    private static function createDurationObject(int $years, int $months, int $weeks, int $days, int $hours, int $minutes, int $seconds, int $milliseconds, int $microseconds, int $nanoseconds): JsObject
    {
        $fields = [$years, $months, $weeks, $days, $hours, $minutes, $seconds, $milliseconds, $microseconds, $nanoseconds];
        self::validateDurationFields($fields);
        $obj = new JsObject(self::$durationProto);
        $names = ['years', 'months', 'weeks', 'days', 'hours', 'minutes', 'seconds', 'milliseconds', 'microseconds', 'nanoseconds'];
        foreach ($names as $i => $name) {
            $obj->defineOwnProperty("[[{$name}]]", PropertyDescriptor::data(new JsNumber((float) $fields[$i]), false, false, false));
        }
        $obj->defineOwnProperty('[[IsDuration]]', PropertyDescriptor::data(new JsBoolean(true), false, false, false));
        return $obj;
    }

    // -----------------------------------------------------------------------
    // Helpers: Duration
    // -----------------------------------------------------------------------

    private static function getDurationField(JsValue $obj, string $field): int
    {
        if (!$obj instanceof JsObject) {
            return 0;
        }
        $v = $obj->get("[[{$field}]]");
        if ($v instanceof JsNumber) {
            return (int) $v->value;
        }
        return 0;
    }

    private static function durationSign(JsValue $obj): int
    {
        $fields = ['years', 'months', 'weeks', 'days', 'hours', 'minutes', 'seconds', 'milliseconds', 'microseconds', 'nanoseconds'];
        foreach ($fields as $f) {
            $v = self::getDurationField($obj, $f);
            if ($v > 0) {
                return 1;
            }
            if ($v < 0) {
                return -1;
            }
        }
        return 0;
    }

    /** @param list<int> $fields */
    private static function validateDurationFields(array $fields): void
    {
        $hasPositive = false;
        $hasNegative = false;
        foreach ($fields as $v) {
            if ($v > 0) {
                $hasPositive = true;
            }
            if ($v < 0) {
                $hasNegative = true;
            }
        }
        if ($hasPositive && $hasNegative) {
            throw new RangeError('Duration fields must not have mixed signs');
        }
    }

    private static function toDuration(JsValue $item): JsObject
    {
        if ($item instanceof JsObject && $item->has('[[IsDuration]]')) {
            return $item;
        }
        if ($item instanceof JsString) {
            return self::parseDurationString($item->value);
        }
        if ($item instanceof JsObject) {
            return self::durationFromObject($item);
        }
        if ($item instanceof JsUndefined || $item instanceof JsNull) {
            throw new TypeError('Cannot convert undefined or null to a Temporal.Duration');
        }
        // Try as string.
        $str = TypeConversion::toString($item);
        return self::parseDurationString($str);
    }

    private static function durationFromObject(JsObject $obj): JsObject
    {
        $fields = ['years', 'months', 'weeks', 'days', 'hours', 'minutes', 'seconds', 'milliseconds', 'microseconds', 'nanoseconds'];
        $vals = [];
        $any = false;
        foreach ($fields as $f) {
            $v = $obj->get($f);
            if ($v instanceof JsUndefined) {
                $vals[] = 0;
            } else {
                $n = TypeConversion::toNumber($v);
                if (!is_finite($n)) {
                    throw new RangeError("infinite Duration field: {$f}");
                }
                if (floor($n) !== $n) {
                    throw new RangeError("fractional Duration field: {$f}");
                }
                $vals[] = (int) $n;
                $any = true;
            }
        }
        if (!$any) {
            throw new TypeError('at least one recognized property must be provided');
        }
        return self::createDurationObject(...$vals);
    }

    private static function parseDurationString(string $str): JsObject
    {
        // ISO 8601 duration: [+-]P[nY][nM][nW][nD][T[nH][nM][n[.frac]S]]
        $pattern = '/^([+-])?P(?:(\d+(?:[.,]\d+)?)Y)?(?:(\d+(?:[.,]\d+)?)M)?(?:(\d+(?:[.,]\d+)?)W)?(?:(\d+(?:[.,]\d+)?)D)?(?:T(?:(\d+(?:[.,]\d+)?)H)?(?:(\d+(?:[.,]\d+)?)M)?(?:(\d+(?:[.,]\d+)?)S)?)?$/i';
        if (!preg_match($pattern, $str, $m)) {
            throw new RangeError("Invalid Duration string: {$str}");
        }

        // Must have at least one component.
        $hasAny = false;
        for ($i = 2; $i <= 8; $i++) {
            if (isset($m[$i]) && $m[$i] !== '') {
                $hasAny = true;
                break;
            }
        }
        if (!$hasAny) {
            throw new RangeError("Invalid Duration string: {$str}");
        }

        $sign = (isset($m[1]) && $m[1] === '-') ? -1 : 1;

        $parseFrac = static function (string $val, string $unit): array {
            $val = str_replace(',', '.', $val);
            if (!str_contains($val, '.')) {
                return [(int) $val, 0, 0, 0];
            }
            $parts = explode('.', $val);
            $whole = (int) $parts[0];
            $frac = $parts[1];

            // Convert fraction to sub-units.
            switch ($unit) {
                case 'H':
                    $fracVal = (float) ('0.' . $frac);
                    $totalMinutes = $fracVal * 60;
                    $minutes = (int) floor($totalMinutes);
                    $remSeconds = ($totalMinutes - $minutes) * 60;
                    $seconds = (int) round($remSeconds * 1e9) / 1e9;
                    $secWhole = (int) floor($seconds);
                    $subSecNs = (int) round(($seconds - $secWhole) * 1e9);
                    $msFromSub = intdiv($subSecNs, 1000000);
                    $usFromSub = intdiv($subSecNs % 1000000, 1000);
                    $nsFromSub = $subSecNs % 1000;
                    return [$whole, $minutes, $secWhole, $msFromSub * 1000000 + $usFromSub * 1000 + $nsFromSub];
                case 'M': // minutes
                    $fracVal = (float) ('0.' . $frac);
                    $totalSeconds = $fracVal * 60;
                    $secWhole = (int) floor($totalSeconds);
                    $subSecNs = (int) round(($totalSeconds - $secWhole) * 1e9);
                    $msFromSub = intdiv($subSecNs, 1000000);
                    $usFromSub = intdiv($subSecNs % 1000000, 1000);
                    $nsFromSub = $subSecNs % 1000;
                    return [$whole, $secWhole, $msFromSub * 1000000 + $usFromSub * 1000 + $nsFromSub, 0];
                case 'S':
                    // Pad to 9 digits for nanosecond precision.
                    $frac = str_pad(substr($frac, 0, 9), 9, '0');
                    $ms = (int) substr($frac, 0, 3);
                    $us = (int) substr($frac, 3, 3);
                    $ns = (int) substr($frac, 6, 3);
                    return [$whole, $ms, $us, $ns];
                default:
                    return [(int) $val, 0, 0, 0];
            }
        };

        $years = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
        $months = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0;
        $weeks = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;
        $days = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : 0;

        $hours = 0;
        $minutes = 0;
        $seconds = 0;
        $milliseconds = 0;
        $microseconds = 0;
        $nanoseconds = 0;

        if (isset($m[6]) && $m[6] !== '') {
            [$hours, $fracMin, $fracSec, $fracSubNs] = $parseFrac($m[6], 'H');
            $minutes += $fracMin;
            $seconds += $fracSec;
            $nanoseconds += $fracSubNs;
        }
        if (isset($m[7]) && $m[7] !== '') {
            [$min2, $fracSec2, $fracSubNs2] = $parseFrac($m[7], 'M');
            $minutes += $min2;
            $seconds += $fracSec2;
            $nanoseconds += $fracSubNs2;
        }
        if (isset($m[8]) && $m[8] !== '') {
            [$sec3, $ms3, $us3, $ns3] = $parseFrac($m[8], 'S');
            $seconds += $sec3;
            $milliseconds += $ms3;
            $microseconds += $us3;
            $nanoseconds += $ns3;
        }

        return self::createDurationObject(
            $sign * $years,
            $sign * $months,
            $sign * $weeks,
            $sign * $days,
            $sign * $hours,
            $sign * $minutes,
            $sign * $seconds,
            $sign * $milliseconds,
            $sign * $microseconds,
            $sign * $nanoseconds,
        );
    }

    private static function durationToString(JsValue $dur, string|int $fractionalSecondDigits = 'auto', string $roundingMode = 'trunc'): string
    {
        $years = self::getDurationField($dur, 'years');
        $months = self::getDurationField($dur, 'months');
        $weeks = self::getDurationField($dur, 'weeks');
        $days = self::getDurationField($dur, 'days');
        $hours = self::getDurationField($dur, 'hours');
        $minutes = self::getDurationField($dur, 'minutes');
        $seconds = self::getDurationField($dur, 'seconds');
        $milliseconds = self::getDurationField($dur, 'milliseconds');
        $microseconds = self::getDurationField($dur, 'microseconds');
        $nanoseconds = self::getDurationField($dur, 'nanoseconds');

        $sign = self::durationSign($dur);
        $prefix = $sign < 0 ? '-' : '';

        $result = $prefix . 'P';
        if (abs($years)) {
            $result .= abs($years) . 'Y';
        }
        if (abs($months)) {
            $result .= abs($months) . 'M';
        }
        if (abs($weeks)) {
            $result .= abs($weeks) . 'W';
        }
        if (abs($days)) {
            $result .= abs($days) . 'D';
        }

        // Time part: balance sub-seconds.
        $totalNs = abs($nanoseconds) + abs($microseconds) * 1000 + abs($milliseconds) * 1000000;
        $totalSec = abs($seconds) + intdiv($totalNs, 1000000000);
        $remainNs = $totalNs % 1000000000;

        $hasTime = abs($hours) || abs($minutes) || $totalSec || $remainNs;

        if ($hasTime) {
            $result .= 'T';
            if (abs($hours)) {
                $result .= abs($hours) . 'H';
            }
            if (abs($minutes)) {
                $result .= abs($minutes) . 'M';
            }
            if ($totalSec || $remainNs) {
                $secStr = (string) $totalSec;
                if ($remainNs > 0) {
                    $nsPadded = str_pad((string) $remainNs, 9, '0', STR_PAD_LEFT);
                    $fracStr = self::formatSubSecond($nsPadded, $fractionalSecondDigits);
                    $secStr .= $fracStr;
                } elseif ($fractionalSecondDigits !== 'auto' && is_int($fractionalSecondDigits) && $fractionalSecondDigits > 0) {
                    $secStr .= '.' . str_repeat('0', $fractionalSecondDigits);
                }
                $result .= $secStr . 'S';
            }
        }

        // If completely empty, output "PT0S".
        if ($result === 'P' || $result === '-P') {
            $result = 'PT0S';
        }

        return $result;
    }

    private static function durationToTotalNs(JsValue $dur): string
    {
        // Only time components can be converted to nanoseconds without a reference point.
        $days = self::getDurationField($dur, 'days');
        $hours = self::getDurationField($dur, 'hours');
        $minutes = self::getDurationField($dur, 'minutes');
        $seconds = self::getDurationField($dur, 'seconds');
        $milliseconds = self::getDurationField($dur, 'milliseconds');
        $microseconds = self::getDurationField($dur, 'microseconds');
        $nanoseconds = self::getDurationField($dur, 'nanoseconds');

        $totalNs = bcadd(
            bcadd(
                bcadd(
                    bcmul((string) $days, '86400000000000', 0),
                    bcmul((string) $hours, '3600000000000', 0),
                    0,
                ),
                bcadd(
                    bcmul((string) $minutes, '60000000000', 0),
                    bcmul((string) $seconds, '1000000000', 0),
                    0,
                ),
                0,
            ),
            bcadd(
                bcadd(
                    bcmul((string) $milliseconds, '1000000', 0),
                    bcmul((string) $microseconds, '1000', 0),
                    0,
                ),
                (string) $nanoseconds,
                0,
            ),
            0,
        );
        return $totalNs;
    }

    private static function durationTotalNs(JsValue $dur, string $unit): float
    {
        $totalNs = self::durationToTotalNs($dur);
        $unitNs = self::temporalUnitToNs($unit);
        if ($unitNs === '1') {
            return (float) $totalNs;
        }
        // Use float division for the total.
        return (float) $totalNs / (float) $unitNs;
    }

    private static function addDurations(JsValue $a, JsValue $b, int $sign): JsObject
    {
        $fields = ['years', 'months', 'weeks', 'days', 'hours', 'minutes', 'seconds', 'milliseconds', 'microseconds', 'nanoseconds'];
        $vals = [];
        foreach ($fields as $f) {
            $vals[] = self::getDurationField($a, $f) + $sign * self::getDurationField($b, $f);
        }
        return self::createDurationObject(...$vals);
    }

    private static function negateDuration(JsObject $dur): JsObject
    {
        $fields = ['years', 'months', 'weeks', 'days', 'hours', 'minutes', 'seconds', 'milliseconds', 'microseconds', 'nanoseconds'];
        $vals = [];
        foreach ($fields as $f) {
            $v = self::getDurationField($dur, $f);
            $vals[] = $v === 0 ? 0 : -$v;
        }
        return self::createDurationObject(...$vals);
    }

    private static function roundDuration(JsValue $dur, string $unit, string $roundingMode, int $increment, string $largestUnit): JsObject
    {
        // Simplified rounding: convert to total nanoseconds, round, then redistribute.
        // This works for time-only durations. Calendar durations with year/month need more work.
        $totalNs = self::durationToTotalNs($dur);
        $years = self::getDurationField($dur, 'years');
        $months = self::getDurationField($dur, 'months');
        $weeks = self::getDurationField($dur, 'weeks');

        $unitNs = self::temporalUnitToNs($unit);
        $incNs = bcmul((string) $increment, $unitNs, 0);

        if ($incNs !== '0') {
            $totalNs = self::roundBigIntNs($totalNs, $incNs, $roundingMode);
        }

        // Determine largest unit.
        if ($largestUnit === 'auto') {
            if ($years !== 0) {
                $largestUnit = 'year';
            } elseif ($months !== 0) {
                $largestUnit = 'month';
            } elseif ($weeks !== 0) {
                $largestUnit = 'week';
            } else {
                $largestUnit = self::defaultLargestUnit($dur);
            }
        }

        return self::nsToTimeDuration($totalNs, $largestUnit, $years, $months, $weeks);
    }

    private static function defaultLargestUnit(JsValue $dur): string
    {
        if (self::getDurationField($dur, 'days') !== 0) {
            return 'day';
        }
        if (self::getDurationField($dur, 'hours') !== 0) {
            return 'hour';
        }
        if (self::getDurationField($dur, 'minutes') !== 0) {
            return 'minute';
        }
        if (self::getDurationField($dur, 'seconds') !== 0) {
            return 'second';
        }
        if (self::getDurationField($dur, 'milliseconds') !== 0) {
            return 'millisecond';
        }
        if (self::getDurationField($dur, 'microseconds') !== 0) {
            return 'microsecond';
        }
        return 'nanosecond';
    }

    private static function nsToTimeDuration(string $totalNs, string $largestUnit, int $years = 0, int $months = 0, int $weeks = 0): JsObject
    {
        $sign = bccomp($totalNs, '0', 0) < 0 ? -1 : 1;
        $abs = bccomp($totalNs, '0', 0) < 0 ? substr($totalNs, 1) : $totalNs;

        $days = 0;
        $hours = 0;
        $minutes = 0;
        $seconds = 0;
        $milliseconds = 0;
        $microseconds = 0;
        $nanoseconds = 0;

        $rem = $abs;
        if (in_array($largestUnit, ['year', 'month', 'week', 'day'], true)) {
            $days = (int) bcdiv($rem, '86400000000000', 0);
            $rem = bcsub($rem, bcmul((string) $days, '86400000000000', 0), 0);
        }
        if (in_array($largestUnit, ['year', 'month', 'week', 'day', 'hour'], true)) {
            $hours = (int) bcdiv($rem, '3600000000000', 0);
            $rem = bcsub($rem, bcmul((string) $hours, '3600000000000', 0), 0);
        }
        if (in_array($largestUnit, ['year', 'month', 'week', 'day', 'hour', 'minute'], true)) {
            $minutes = (int) bcdiv($rem, '60000000000', 0);
            $rem = bcsub($rem, bcmul((string) $minutes, '60000000000', 0), 0);
        }
        if (in_array($largestUnit, ['year', 'month', 'week', 'day', 'hour', 'minute', 'second'], true)) {
            $seconds = (int) bcdiv($rem, '1000000000', 0);
            $rem = bcsub($rem, bcmul((string) $seconds, '1000000000', 0), 0);
        }
        if (in_array($largestUnit, ['year', 'month', 'week', 'day', 'hour', 'minute', 'second', 'millisecond'], true)) {
            $milliseconds = (int) bcdiv($rem, '1000000', 0);
            $rem = bcsub($rem, bcmul((string) $milliseconds, '1000000', 0), 0);
        }
        if (in_array($largestUnit, ['year', 'month', 'week', 'day', 'hour', 'minute', 'second', 'millisecond', 'microsecond'], true)) {
            $microseconds = (int) bcdiv($rem, '1000', 0);
            $rem = bcsub($rem, bcmul((string) $microseconds, '1000', 0), 0);
        }
        $nanoseconds = (int) $rem;

        return self::createDurationObject(
            $sign * $years,
            $sign * $months,
            $sign * $weeks,
            $sign * $days,
            $sign * $hours,
            $sign * $minutes,
            $sign * $seconds,
            $sign * $milliseconds,
            $sign * $microseconds,
            $sign * $nanoseconds,
        );
    }

    // -----------------------------------------------------------------------
    // Helpers: type conversion
    // -----------------------------------------------------------------------

    private static function toPlainDate(JsValue $item): JsObject
    {
        if ($item instanceof JsObject) {
            if (
                $item->has('[[ISOYear]]') && !$item->has('[[IsPlainTime]]') && !$item->has('[[IsPlainDateTime]]')
                && !$item->has('[[IsPlainYearMonth]]') && !$item->has('[[IsPlainMonthDay]]')
                && !$item->has('[[IsZonedDateTime]]') && !$item->has('[[IsDuration]]') && !$item->has('[[EpochNanoseconds]]')
            ) {
                return $item;
            }
            if ($item->has('[[IsPlainDateTime]]')) {
                return self::createPlainDateObject(
                    self::getSlotInt($item, '[[ISOYear]]'),
                    self::getSlotInt($item, '[[ISOMonth]]'),
                    self::getSlotInt($item, '[[ISODay]]'),
                    self::getSlotString($item, '[[Calendar]]'),
                );
            }
            // Property bag with year, month, day.
            $year = $item->get('year');
            $month = $item->get('month');
            $day = $item->get('day');
            if (!($year instanceof JsUndefined) && !($month instanceof JsUndefined) && !($day instanceof JsUndefined)) {
                $y = (int) TypeConversion::toNumber($year);
                $m = (int) TypeConversion::toNumber($month);
                $d = (int) TypeConversion::toNumber($day);
                $cal = 'iso8601';
                $calVal = $item->get('calendar');
                if (!($calVal instanceof JsUndefined)) {
                    $cal = strtolower(TypeConversion::toString($calVal));
                }
                self::validateISODate($y, $m, $d);
                return self::createPlainDateObject($y, $m, $d, $cal);
            }
        }
        $str = TypeConversion::toString($item);
        return self::parsePlainDateString($str);
    }

    private static function parsePlainDateString(string $str): JsObject
    {
        // YYYY-MM-DD with optional calendar annotation.
        $pattern = '/^([+-]?\d{4,6})-(\d{2})-(\d{2})(?:T.*)?(?:\[.*?\])*$/';
        if (!preg_match($pattern, $str, $m)) {
            throw new RangeError("Invalid PlainDate string: {$str}");
        }
        $y = (int) $m[1];
        $m2 = (int) $m[2];
        $d = (int) $m[3];
        $cal = 'iso8601';
        // Extract calendar annotation.
        if (preg_match('/\[u-ca=([^\]]+)\]/', $str, $cm)) {
            $cal = strtolower($cm[1]);
        }
        self::validateISODate($y, $m2, $d);
        return self::createPlainDateObject($y, $m2, $d, $cal);
    }

    private static function toPlainTime(JsValue $item): JsObject
    {
        if ($item instanceof JsObject && $item->has('[[IsPlainTime]]')) {
            return $item;
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
        if ($item instanceof JsObject) {
            // Property bag.
            $h = 0;
            $min = 0;
            $s = 0;
            $ms = 0;
            $us = 0;
            $ns = 0;
            $any = false;
            foreach (['hour' => &$h, 'minute' => &$min, 'second' => &$s, 'millisecond' => &$ms, 'microsecond' => &$us, 'nanosecond' => &$ns] as $name => &$ref) {
                $v = $item->get($name);
                if (!($v instanceof JsUndefined)) {
                    $ref = (int) TypeConversion::toNumber($v);
                    $any = true;
                }
            }
            unset($ref);
            if ($any) {
                self::validateISOTime($h, $min, $s, $ms, $us, $ns);
                return self::createPlainTimeObject($h, $min, $s, $ms, $us, $ns);
            }
        }
        $str = TypeConversion::toString($item);
        return self::parsePlainTimeString($str);
    }

    private static function parsePlainTimeString(string $str): JsObject
    {
        $pattern = '/^(\d{2}):(\d{2})(?::(\d{2})(?:[.,](\d{1,9}))?)?$/';
        if (!preg_match($pattern, $str, $m)) {
            // Also try datetime string and extract time.
            $pattern2 = '/T(\d{2}):(\d{2})(?::(\d{2})(?:[.,](\d{1,9}))?)?/';
            if (!preg_match($pattern2, $str, $m)) {
                throw new RangeError("Invalid PlainTime string: {$str}");
            }
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        $s = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0;
        $frac = isset($m[4]) && $m[4] !== '' ? str_pad(substr($m[4], 0, 9), 9, '0') : '000000000';
        $ms = (int) substr($frac, 0, 3);
        $us = (int) substr($frac, 3, 3);
        $ns = (int) substr($frac, 6, 3);
        self::validateISOTime($h, $min, $s, $ms, $us, $ns);
        return self::createPlainTimeObject($h, $min, $s, $ms, $us, $ns);
    }

    private static function toPlainDateTime(JsValue $item): JsObject
    {
        if ($item instanceof JsObject && $item->has('[[IsPlainDateTime]]')) {
            return $item;
        }
        if ($item instanceof JsObject) {
            // Property bag.
            $year = $item->get('year');
            $month = $item->get('month');
            $day = $item->get('day');
            if (!($year instanceof JsUndefined) && !($month instanceof JsUndefined) && !($day instanceof JsUndefined)) {
                $y = (int) TypeConversion::toNumber($year);
                $m = (int) TypeConversion::toNumber($month);
                $d = (int) TypeConversion::toNumber($day);
                $h = 0;
                $min = 0;
                $s = 0;
                $ms = 0;
                $us = 0;
                $ns = 0;
                foreach (['hour' => &$h, 'minute' => &$min, 'second' => &$s, 'millisecond' => &$ms, 'microsecond' => &$us, 'nanosecond' => &$ns] as $name => &$ref) {
                    $v = $item->get($name);
                    if (!($v instanceof JsUndefined)) {
                        $ref = (int) TypeConversion::toNumber($v);
                    }
                }
                unset($ref);
                $cal = 'iso8601';
                $calVal = $item->get('calendar');
                if (!($calVal instanceof JsUndefined)) {
                    $cal = strtolower(TypeConversion::toString($calVal));
                }
                self::validateISODate($y, $m, $d);
                self::validateISOTime($h, $min, $s, $ms, $us, $ns);
                return self::createPlainDateTimeObject($y, $m, $d, $h, $min, $s, $ms, $us, $ns, $cal);
            }
        }
        $str = TypeConversion::toString($item);
        return self::parsePlainDateTimeString($str);
    }

    private static function parsePlainDateTimeString(string $str): JsObject
    {
        $pattern = '/^([+-]?\d{4,6})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2})(?:[.,](\d{1,9}))?)?(?:\[.*?\])*$/';
        if (!preg_match($pattern, $str, $m)) {
            // Fallback: date only.
            $dateOnly = '/^([+-]?\d{4,6})-(\d{2})-(\d{2})(?:\[.*?\])*$/';
            if (preg_match($dateOnly, $str, $m)) {
                $y = (int) $m[1];
                $m2 = (int) $m[2];
                $d = (int) $m[3];
                $cal = 'iso8601';
                if (preg_match('/\[u-ca=([^\]]+)\]/', $str, $cm)) {
                    $cal = strtolower($cm[1]);
                }
                self::validateISODate($y, $m2, $d);
                return self::createPlainDateTimeObject($y, $m2, $d, 0, 0, 0, 0, 0, 0, $cal);
            }
            throw new RangeError("Invalid PlainDateTime string: {$str}");
        }
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $dd = (int) $m[3];
        $h = (int) $m[4];
        $min = (int) $m[5];
        $s = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0;
        $frac = isset($m[7]) && $m[7] !== '' ? str_pad(substr($m[7], 0, 9), 9, '0') : '000000000';
        $ms = (int) substr($frac, 0, 3);
        $us = (int) substr($frac, 3, 3);
        $ns = (int) substr($frac, 6, 3);
        $cal = 'iso8601';
        if (preg_match('/\[u-ca=([^\]]+)\]/', $str, $cm)) {
            $cal = strtolower($cm[1]);
        }
        self::validateISODate($y, $mo, $dd);
        self::validateISOTime($h, $min, $s, $ms, $us, $ns);
        return self::createPlainDateTimeObject($y, $mo, $dd, $h, $min, $s, $ms, $us, $ns, $cal);
    }

    private static function toPlainYearMonth(JsValue $item): JsObject
    {
        if ($item instanceof JsObject && $item->has('[[IsPlainYearMonth]]')) {
            return $item;
        }
        if ($item instanceof JsObject) {
            $year = $item->get('year');
            $month = $item->get('month');
            if (!($year instanceof JsUndefined) && !($month instanceof JsUndefined)) {
                $y = (int) TypeConversion::toNumber($year);
                $m = (int) TypeConversion::toNumber($month);
                $cal = 'iso8601';
                $calVal = $item->get('calendar');
                if (!($calVal instanceof JsUndefined)) {
                    $cal = strtolower(TypeConversion::toString($calVal));
                }
                return self::createPlainYearMonthObject($y, $m, 1, $cal);
            }
        }
        $str = TypeConversion::toString($item);
        $pattern = '/^([+-]?\d{4,6})-(\d{2})(?:-\d{2})?(?:T.*)?(?:\[.*?\])*$/';
        if (!preg_match($pattern, $str, $m)) {
            throw new RangeError("Invalid PlainYearMonth string: {$str}");
        }
        $y = (int) $m[1];
        $mo = (int) $m[2];
        return self::createPlainYearMonthObject($y, $mo, 1, 'iso8601');
    }

    private static function toPlainMonthDay(JsValue $item): JsObject
    {
        if ($item instanceof JsObject && $item->has('[[IsPlainMonthDay]]')) {
            return $item;
        }
        if ($item instanceof JsObject) {
            $month = $item->get('monthCode');
            $day = $item->get('day');
            if (!($month instanceof JsUndefined) && !($day instanceof JsUndefined)) {
                $mStr = TypeConversion::toString($month);
                $m = (int) substr($mStr, 1);
                $d = (int) TypeConversion::toNumber($day);
                return self::createPlainMonthDayObject($m, $d, 1972, 'iso8601');
            }
            $month = $item->get('month');
            $day = $item->get('day');
            if (!($month instanceof JsUndefined) && !($day instanceof JsUndefined)) {
                $m = (int) TypeConversion::toNumber($month);
                $d = (int) TypeConversion::toNumber($day);
                return self::createPlainMonthDayObject($m, $d, 1972, 'iso8601');
            }
        }
        $str = TypeConversion::toString($item);
        $pattern = '/^(?:--)?(\d{2})-(\d{2})$/';
        if (!preg_match($pattern, $str, $m)) {
            // Also try full ISO date.
            $pattern2 = '/^(?:[+-]?\d{4,6})-(\d{2})-(\d{2})(?:T.*)?(?:\[.*?\])*$/';
            if (!preg_match($pattern2, $str, $m)) {
                throw new RangeError("Invalid PlainMonthDay string: {$str}");
            }
        }
        $mo = (int) $m[1];
        $dd = (int) $m[2];
        return self::createPlainMonthDayObject($mo, $dd, 1972, 'iso8601');
    }

    // -----------------------------------------------------------------------
    // Helpers: formatting
    // -----------------------------------------------------------------------

    private static function padISOYear(int $year): string
    {
        if ($year >= 0 && $year <= 9999) {
            return str_pad((string) $year, 4, '0', STR_PAD_LEFT);
        }
        $sign = $year >= 0 ? '+' : '-';
        return $sign . str_pad((string) abs($year), 6, '0', STR_PAD_LEFT);
    }

    private static function pad2(int $n): string
    {
        return str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    }

    private static function formatSubSecond(string $nsPadded, string|int $fractionalSecondDigits): string
    {
        if ($fractionalSecondDigits === 'auto') {
            $trimmed = rtrim($nsPadded, '0');
            if ($trimmed === '') {
                return '';
            }
            return '.' . $trimmed;
        }
        if (is_int($fractionalSecondDigits) || is_numeric($fractionalSecondDigits)) {
            $digits = (int) $fractionalSecondDigits;
            if ($digits === 0) {
                return '';
            }
            return '.' . substr($nsPadded, 0, $digits);
        }
        // Fallback to auto.
        $trimmed = rtrim($nsPadded, '0');
        return $trimmed === '' ? '' : '.' . $trimmed;
    }

    private static function formatISOTime(int $h, int $min, int $s, int $ms, int $us, int $ns, string|int $fractionalSecondDigits = 'auto', string $roundingMode = 'trunc'): string
    {
        $nsPadded = str_pad((string) $ms, 3, '0', STR_PAD_LEFT) . str_pad((string) $us, 3, '0', STR_PAD_LEFT) . str_pad((string) $ns, 3, '0', STR_PAD_LEFT);
        $fracStr = self::formatSubSecond($nsPadded, $fractionalSecondDigits);
        return self::pad2($h) . ':' . self::pad2($min) . ':' . self::pad2($s) . $fracStr;
    }

    private static function plainTimeToString(JsValue $this_, string|int $fractionalSecondDigits = 'auto', string $roundingMode = 'trunc'): string
    {
        return self::formatISOTime(
            self::getSlotInt($this_, '[[ISOHour]]'),
            self::getSlotInt($this_, '[[ISOMinute]]'),
            self::getSlotInt($this_, '[[ISOSecond]]'),
            self::getSlotInt($this_, '[[ISOMillisecond]]'),
            self::getSlotInt($this_, '[[ISOMicrosecond]]'),
            self::getSlotInt($this_, '[[ISONanosecond]]'),
            $fractionalSecondDigits,
            $roundingMode,
        );
    }

    private static function plainDateTimeToString(JsValue $this_, string|int $fractionalSecondDigits = 'auto', string $roundingMode = 'trunc', string $calendarName = 'auto'): string
    {
        $y = self::getSlotInt($this_, '[[ISOYear]]');
        $m = self::getSlotInt($this_, '[[ISOMonth]]');
        $dd = self::getSlotInt($this_, '[[ISODay]]');
        $dateStr = self::padISOYear($y) . '-' . self::pad2($m) . '-' . self::pad2($dd);
        $timeStr = self::formatISOTime(
            self::getSlotInt($this_, '[[ISOHour]]'),
            self::getSlotInt($this_, '[[ISOMinute]]'),
            self::getSlotInt($this_, '[[ISOSecond]]'),
            self::getSlotInt($this_, '[[ISOMillisecond]]'),
            self::getSlotInt($this_, '[[ISOMicrosecond]]'),
            self::getSlotInt($this_, '[[ISONanosecond]]'),
            $fractionalSecondDigits,
            $roundingMode,
        );
        $result = "{$dateStr}T{$timeStr}";
        $cal = self::getSlotString($this_, '[[Calendar]]');
        if ($calendarName === 'always' || ($calendarName !== 'never' && $cal !== 'iso8601')) {
            $result .= "[u-ca={$cal}]";
        }
        return $result;
    }

    // -----------------------------------------------------------------------
    // Helpers: timezone
    // -----------------------------------------------------------------------

    private static function resolveTimeZone(string $tz): \DateTimeZone
    {
        try {
            return new \DateTimeZone($tz);
        } catch (\Throwable) {
            throw new RangeError("Invalid time zone: {$tz}");
        }
    }

    private static function epochNsToISOParts(string $ns, string $tz): array
    {
        // Convert epoch nanoseconds to date/time parts in the given timezone.
        $negative = isset($ns[0]) && $ns[0] === '-';
        $abs = $negative ? substr($ns, 1) : $ns;

        $secStr = bcdiv($abs, '1000000000', 0);
        $subNs = bcsub($abs, bcmul($secStr, '1000000000', 0), 0);

        if ($negative && $subNs !== '0') {
            $secStr = bcadd($secStr, '1', 0);
            $subNs = bcsub('1000000000', $subNs, 0);
        }

        $epochSec = $negative ? '-' . $secStr : $secStr;

        try {
            $dt = new \DateTimeImmutable('@' . $epochSec);
            $local = $dt->setTimezone(self::resolveTimeZone($tz));
        } catch (\Throwable) {
            return ['year' => 1970, 'month' => 1, 'day' => 1, 'hour' => 0, 'minute' => 0, 'second' => 0,
                'millisecond' => 0, 'microsecond' => 0, 'nanosecond' => 0];
        }

        $subNsPadded = str_pad($subNs, 9, '0', STR_PAD_LEFT);

        return [
            'year' => (int) $local->format('Y'),
            'month' => (int) $local->format('n'),
            'day' => (int) $local->format('j'),
            'hour' => (int) $local->format('G'),
            'minute' => (int) $local->format('i'),
            'second' => (int) $local->format('s'),
            'millisecond' => (int) substr($subNsPadded, 0, 3),
            'microsecond' => (int) substr($subNsPadded, 3, 3),
            'nanosecond' => (int) substr($subNsPadded, 6, 3),
        ];
    }

    private static function isoDateTimeToEpochNs(int $y, int $m, int $d, int $h, int $min, int $s, int $ms, int $us, int $ns, string $tz): string
    {
        try {
            $dt = new \DateTimeImmutable('2000-01-01 00:00:00', self::resolveTimeZone($tz));
            $dt = $dt->setDate($y, $m, $d);
            $dt = $dt->setTime($h, $min, $s);
            $epochSec = $dt->format('U');
        } catch (\Throwable) {
            return '0';
        }
        $epochNs = bcmul($epochSec, '1000000000', 0);
        $subNs = (string) ($ms * 1000000 + $us * 1000 + $ns);
        return bcadd($epochNs, $subNs, 0);
    }

    private static function timeZoneOffsetString(string $ns, string $tz): string
    {
        $negative = isset($ns[0]) && $ns[0] === '-';
        $abs = $negative ? substr($ns, 1) : $ns;
        $secStr = bcdiv($abs, '1000000000', 0);
        $epochSec = $negative ? '-' . $secStr : $secStr;

        try {
            $dt = new \DateTimeImmutable('@' . $epochSec);
            $local = $dt->setTimezone(self::resolveTimeZone($tz));
            $offset = (int) $local->format('Z'); // Offset in seconds.
        } catch (\Throwable) {
            return '+00:00';
        }

        $sign = $offset >= 0 ? '+' : '-';
        $absOffset = abs($offset);
        $h = intdiv($absOffset, 3600);
        $m = intdiv($absOffset % 3600, 60);
        $s = $absOffset % 60;

        $result = $sign . self::pad2($h) . ':' . self::pad2($m);
        if ($s !== 0) {
            $result .= ':' . self::pad2($s);
        }
        return $result;
    }

    // -----------------------------------------------------------------------
    // Helpers: arithmetic
    // -----------------------------------------------------------------------

    private static function instantAddDuration(string $ns, JsValue $durationArg, int $sign): JsObject
    {
        $dur = self::toDuration($durationArg);
        // Instant only supports time components.
        if (self::getDurationField($dur, 'years') !== 0 || self::getDurationField($dur, 'months') !== 0 || self::getDurationField($dur, 'weeks') !== 0) {
            throw new RangeError('Instant arithmetic does not support years, months, or weeks');
        }
        $totalNs = self::durationToTotalNs($dur);
        if ($sign < 0) {
            $totalNs = bcsub('0', $totalNs, 0);
        }
        $result = bcadd($ns, $totalNs, 0);
        self::validateInstantRange($result);
        return self::createInstantObject($result);
    }

    private static function instantDifference(string $ns1, string $ns2, JsValue $options): JsObject
    {
        $diffNs = bcsub($ns2, $ns1, 0);
        $largestUnit = 'second';
        if ($options instanceof JsObject && $options->has('largestUnit')) {
            $lu = $options->get('largestUnit');
            if (!($lu instanceof JsUndefined)) {
                $largestUnit = TypeConversion::toString($lu);
                $largestUnit = self::canonicalTemporalUnit($largestUnit);
            }
        }
        return self::nsToTimeDuration($diffNs, $largestUnit);
    }

    private static function plainDateAdd(JsValue $date, JsObject $dur, int $sign): JsObject
    {
        $y = self::getSlotInt($date, '[[ISOYear]]');
        $m = self::getSlotInt($date, '[[ISOMonth]]');
        $d = self::getSlotInt($date, '[[ISODay]]');
        $cal = self::getSlotString($date, '[[Calendar]]');

        $years = $sign * self::getDurationField($dur, 'years');
        $months = $sign * self::getDurationField($dur, 'months');
        $weeks = $sign * self::getDurationField($dur, 'weeks');
        $days = $sign * self::getDurationField($dur, 'days');

        // Add years and months first.
        $y += $years;
        $m += $months;

        // Normalize month overflow.
        while ($m > 12) {
            $m -= 12;
            $y++;
        }
        while ($m < 1) {
            $m += 12;
            $y--;
        }

        // Clamp day to valid range.
        $dim = self::isoDaysInMonth($y, $m);
        if ($d > $dim) {
            $d = $dim;
        }

        // Add weeks and days.
        $totalDays = $days + $weeks * 7;
        if ($totalDays !== 0) {
            try {
                $dt = new \DateTimeImmutable('2000-01-01', new \DateTimeZone('UTC'));
                $dt = $dt->setDate($y, $m, $d);
                $dt = $dt->modify("{$totalDays} days");
                $y = (int) $dt->format('Y');
                $m = (int) $dt->format('n');
                $d = (int) $dt->format('j');
            } catch (\Throwable) {
                throw new RangeError('Date arithmetic overflow');
            }
        }

        self::validateISODate($y, $m, $d);
        return self::createPlainDateObject($y, $m, $d, $cal);
    }

    private static function plainDateDifference(JsValue $date1, JsValue $date2, JsValue $options, int $sign): JsObject
    {
        $y1 = self::getSlotInt($date1, '[[ISOYear]]');
        $m1 = self::getSlotInt($date1, '[[ISOMonth]]');
        $d1 = self::getSlotInt($date1, '[[ISODay]]');
        $y2 = self::getSlotInt($date2, '[[ISOYear]]');
        $m2 = self::getSlotInt($date2, '[[ISOMonth]]');
        $d2 = self::getSlotInt($date2, '[[ISODay]]');

        $largestUnit = 'day';
        if ($options instanceof JsObject && $options->has('largestUnit')) {
            $lu = $options->get('largestUnit');
            if (!($lu instanceof JsUndefined)) {
                $largestUnit = TypeConversion::toString($lu);
                $largestUnit = self::canonicalTemporalUnit($largestUnit);
            }
        }

        // Julian day difference.
        $jd1 = self::isoToJulianDay($y1, $m1, $d1);
        $jd2 = self::isoToJulianDay($y2, $m2, $d2);
        $diffDays = $jd2 - $jd1;

        $years = 0;
        $months = 0;
        $weeks = 0;
        $days = $diffDays;

        if ($largestUnit === 'year' || $largestUnit === 'month') {
            // Calculate year and month difference.
            $years = $y2 - $y1;
            $months = $m2 - $m1;
            $days = $d2 - $d1;

            if ($days < 0) {
                $months--;
                $dim = self::isoDaysInMonth($y2, $m2 === 1 ? 12 : $m2 - 1);
                $days += $dim;
            }
            if ($months < 0) {
                $years--;
                $months += 12;
            }
            if ($largestUnit === 'month') {
                $months += $years * 12;
                $years = 0;
            }
        } elseif ($largestUnit === 'week') {
            $weeks = intdiv($diffDays, 7);
            $days = $diffDays - $weeks * 7;
        }

        $dur = self::createDurationObject($sign * $years, $sign * $months, $sign * $weeks, $sign * $days, 0, 0, 0, 0, 0, 0);
        return $dur;
    }

    private static function isoToJulianDay(int $y, int $m, int $d): int
    {
        // Compute days since epoch (simple days count using PHP).
        try {
            $dt = new \DateTimeImmutable('2000-01-01', new \DateTimeZone('UTC'));
            $dt = $dt->setDate($y, $m, $d);
            return (int) floor((int) $dt->format('U') / 86400);
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function plainTimeAdd(JsValue $time, JsObject $dur, int $sign): JsObject
    {
        $h = self::getSlotInt($time, '[[ISOHour]]');
        $min = self::getSlotInt($time, '[[ISOMinute]]');
        $s = self::getSlotInt($time, '[[ISOSecond]]');
        $ms = self::getSlotInt($time, '[[ISOMillisecond]]');
        $us = self::getSlotInt($time, '[[ISOMicrosecond]]');
        $ns = self::getSlotInt($time, '[[ISONanosecond]]');

        // Convert to total nanoseconds.
        $totalNs = ($h * 3600 + $min * 60 + $s) * 1000000000 + $ms * 1000000 + $us * 1000 + $ns;
        $durNs = $sign * (
            self::getDurationField($dur, 'hours') * 3600000000000
            + self::getDurationField($dur, 'minutes') * 60000000000
            + self::getDurationField($dur, 'seconds') * 1000000000
            + self::getDurationField($dur, 'milliseconds') * 1000000
            + self::getDurationField($dur, 'microseconds') * 1000
            + self::getDurationField($dur, 'nanoseconds')
        );

        $result = $totalNs + $durNs;
        // Wrap to 0-86399999999999 range.
        $dayNs = 86400000000000;
        $result = $result % $dayNs;
        if ($result < 0) {
            $result += $dayNs;
        }

        $ns2 = $result % 1000;
        $result = intdiv($result, 1000);
        $us2 = $result % 1000;
        $result = intdiv($result, 1000);
        $ms2 = $result % 1000;
        $result = intdiv($result, 1000);
        $s2 = $result % 60;
        $result = intdiv($result, 60);
        $min2 = $result % 60;
        $h2 = intdiv($result, 60);

        return self::createPlainTimeObject($h2, $min2, $s2, $ms2, $us2, $ns2);
    }

    private static function plainTimeDifference(JsValue $time1, JsValue $time2, JsValue $options): JsObject
    {
        $ns1 = self::timeToNs($time1);
        $ns2 = self::timeToNs($time2);
        $diffNs = (string) ($ns2 - $ns1);
        $largestUnit = 'hour';
        if ($options instanceof JsObject && $options->has('largestUnit')) {
            $lu = $options->get('largestUnit');
            if (!($lu instanceof JsUndefined)) {
                $largestUnit = TypeConversion::toString($lu);
                $largestUnit = self::canonicalTemporalUnit($largestUnit);
            }
        }
        return self::nsToTimeDuration($diffNs, $largestUnit);
    }

    private static function timeToNs(JsValue $time): int
    {
        $h = self::getSlotInt($time, '[[ISOHour]]');
        $min = self::getSlotInt($time, '[[ISOMinute]]');
        $s = self::getSlotInt($time, '[[ISOSecond]]');
        $ms = self::getSlotInt($time, '[[ISOMillisecond]]');
        $us = self::getSlotInt($time, '[[ISOMicrosecond]]');
        $ns = self::getSlotInt($time, '[[ISONanosecond]]');
        return ($h * 3600 + $min * 60 + $s) * 1000000000 + $ms * 1000000 + $us * 1000 + $ns;
    }

    private static function roundPlainTime(JsValue $time, string $unit, string $roundingMode, int $increment): JsObject
    {
        $totalNs = self::timeToNs($time);
        $unitNs = (int) self::temporalUnitToNs($unit);
        $incNs = $unitNs * $increment;
        if ($incNs > 0) {
            $totalNs = self::roundToIncrement($totalNs, $incNs, $roundingMode);
        }
        // Wrap to day.
        $dayNs = 86400000000000;
        $totalNs = $totalNs % $dayNs;
        if ($totalNs < 0) {
            $totalNs += $dayNs;
        }

        $ns = $totalNs % 1000;
        $totalNs = intdiv($totalNs, 1000);
        $us = $totalNs % 1000;
        $totalNs = intdiv($totalNs, 1000);
        $ms = $totalNs % 1000;
        $totalNs = intdiv($totalNs, 1000);
        $s = $totalNs % 60;
        $totalNs = intdiv($totalNs, 60);
        $min = $totalNs % 60;
        $h = intdiv($totalNs, 60);

        return self::createPlainTimeObject($h, $min, $s, $ms, $us, $ns);
    }

    private static function plainDateTimeAdd(JsValue $dt, JsObject $dur, int $sign): JsObject
    {
        $y = self::getSlotInt($dt, '[[ISOYear]]');
        $m = self::getSlotInt($dt, '[[ISOMonth]]');
        $d = self::getSlotInt($dt, '[[ISODay]]');
        $h = self::getSlotInt($dt, '[[ISOHour]]');
        $min = self::getSlotInt($dt, '[[ISOMinute]]');
        $s = self::getSlotInt($dt, '[[ISOSecond]]');
        $ms = self::getSlotInt($dt, '[[ISOMillisecond]]');
        $us = self::getSlotInt($dt, '[[ISOMicrosecond]]');
        $ns = self::getSlotInt($dt, '[[ISONanosecond]]');
        $cal = self::getSlotString($dt, '[[Calendar]]');

        // Add date part.
        $dateObj = self::createPlainDateObject($y, $m, $d, $cal);
        $dateDur = self::createDurationObject(
            self::getDurationField($dur, 'years'),
            self::getDurationField($dur, 'months'),
            self::getDurationField($dur, 'weeks'),
            self::getDurationField($dur, 'days'),
            0,
            0,
            0,
            0,
            0,
            0,
        );
        $newDate = self::plainDateAdd($dateObj, $dateDur, $sign);

        // Add time part.
        $timeObj = self::createPlainTimeObject($h, $min, $s, $ms, $us, $ns);
        $timeDur = self::createDurationObject(
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
        );
        $newTime = self::plainTimeAdd($timeObj, $timeDur, $sign);

        return self::createPlainDateTimeObject(
            self::getSlotInt($newDate, '[[ISOYear]]'),
            self::getSlotInt($newDate, '[[ISOMonth]]'),
            self::getSlotInt($newDate, '[[ISODay]]'),
            self::getSlotInt($newTime, '[[ISOHour]]'),
            self::getSlotInt($newTime, '[[ISOMinute]]'),
            self::getSlotInt($newTime, '[[ISOSecond]]'),
            self::getSlotInt($newTime, '[[ISOMillisecond]]'),
            self::getSlotInt($newTime, '[[ISOMicrosecond]]'),
            self::getSlotInt($newTime, '[[ISONanosecond]]'),
            self::getSlotString($newDate, '[[Calendar]]'),
        );
    }

    // -----------------------------------------------------------------------
    // Helpers: rounding
    // -----------------------------------------------------------------------

    private static function roundToIncrement(int $value, int $increment, string $mode): int
    {
        if ($increment === 0) {
            return $value;
        }
        $quotient = $value / $increment;
        return match ($mode) {
            'ceil' => (int) ceil($quotient) * $increment,
            'floor' => (int) floor($quotient) * $increment,
            'trunc' => (int) (($value >= 0 ? floor($quotient) : ceil($quotient))) * $increment,
            'expand' => (int) (($value >= 0 ? ceil($quotient) : floor($quotient))) * $increment,
            default => (int) round($quotient) * $increment, // halfExpand
        };
    }

    private static function roundBigIntNs(string $value, string $increment, string $mode): string
    {
        if ($increment === '0') {
            return $value;
        }
        // quotient = value / increment, round according to mode.
        $q = bcdiv($value, $increment, 20);
        $rounded = match ($mode) {
            'ceil' => bcadd($q, '0', 0) === $q ? $q : (bccomp($q, '0', 0) >= 0 ? bcadd(bcdiv($value, $increment, 0), '1', 0) : bcdiv($value, $increment, 0)),
            'floor' => bccomp($value, '0', 0) >= 0 ? bcdiv($value, $increment, 0) : (bcsub($value, bcsub($increment, '1', 0), 0) !== $value ? bcsub(bcdiv($value, $increment, 0), '1', 0) : bcdiv($value, $increment, 0)),
            'trunc' => bcdiv($value, $increment, 0),
            default => bcdiv(bcadd(bcmul($value, '2', 0), (bccomp($value, '0', 0) >= 0 ? $increment : bcsub('0', $increment, 0)), 0), bcmul($increment, '2', 0), 0),
        };
        return bcmul($rounded, $increment, 0);
    }

    // -----------------------------------------------------------------------
    // Helpers: temporal units
    // -----------------------------------------------------------------------

    private static function canonicalTemporalUnit(string $unit): string
    {
        return match ($unit) {
            'years', 'year' => 'year',
            'months', 'month' => 'month',
            'weeks', 'week' => 'week',
            'days', 'day' => 'day',
            'hours', 'hour' => 'hour',
            'minutes', 'minute' => 'minute',
            'seconds', 'second' => 'second',
            'milliseconds', 'millisecond' => 'millisecond',
            'microseconds', 'microsecond' => 'microsecond',
            'nanoseconds', 'nanosecond' => 'nanosecond',
            default => throw new RangeError("Invalid unit: {$unit}"),
        };
    }

    private static function temporalUnitToNs(string $unit): string
    {
        return match ($unit) {
            'hour' => '3600000000000',
            'minute' => '60000000000',
            'second' => '1000000000',
            'millisecond' => '1000000',
            'microsecond' => '1000',
            'nanosecond' => '1',
            'day' => '86400000000000',
            default => '1',
        };
    }

    private static function getTemporalUnit(JsObject $options, string $key, array $allowed, bool $required): string
    {
        $val = $options->get($key);
        if ($val instanceof JsUndefined) {
            if ($required) {
                throw new RangeError("{$key} is required");
            }
            return '';
        }
        $str = TypeConversion::toString($val);
        $canonical = self::canonicalTemporalUnit($str);
        if (!in_array($canonical, $allowed, true)) {
            throw new RangeError("Invalid {$key}: {$str}");
        }
        return $canonical;
    }

    private static function getOptionsObject(JsValue $options): JsValue
    {
        if ($options instanceof JsUndefined) {
            return new JsObject();
        }
        if ($options instanceof JsObject) {
            return $options;
        }
        throw new TypeError('options must be an object');
    }

    private static function getFractionalSecondDigits(JsValue $options): string|int
    {
        if (!$options instanceof JsObject || !$options->has('fractionalSecondDigits')) {
            return 'auto';
        }
        $v = $options->get('fractionalSecondDigits');
        if ($v instanceof JsUndefined) {
            return 'auto';
        }
        if ($v instanceof JsString && $v->value === 'auto') {
            return 'auto';
        }
        if ($v instanceof JsNumber) {
            $n = (int) $v->value;
            if ($n < 0 || $n > 9) {
                throw new RangeError('fractionalSecondDigits must be 0-9 or auto');
            }
            return $n;
        }
        $str = TypeConversion::toString($v);
        if ($str === 'auto') {
            return 'auto';
        }
        throw new RangeError('fractionalSecondDigits must be 0-9 or auto');
    }

    private static function getRoundingMode(JsValue $options, string $fallback): string
    {
        if (!$options instanceof JsObject || !$options->has('roundingMode')) {
            return $fallback;
        }
        $v = $options->get('roundingMode');
        if ($v instanceof JsUndefined) {
            return $fallback;
        }
        return TypeConversion::toString($v);
    }

    private static function getRoundingIncrement(JsObject $options): int
    {
        if (!$options->has('roundingIncrement')) {
            return 1;
        }
        $v = $options->get('roundingIncrement');
        if ($v instanceof JsUndefined) {
            return 1;
        }
        $n = (int) TypeConversion::toNumber($v);
        if ($n < 1) {
            throw new RangeError('roundingIncrement must be at least 1');
        }
        return $n;
    }

    // -----------------------------------------------------------------------
    // Helpers: prototype method registration
    // -----------------------------------------------------------------------

    private static function defineGetter(JsObject $proto, string $name, \Closure $getter): void
    {
        $fn = JsFunction::fromCallable("get {$name}", $getter, 0);
        $proto->defineOwnProperty($name, PropertyDescriptor::accessor(
            get: $fn,
            set: null,
            enumerable: false,
            configurable: true,
        ));
    }

    /** @return \Closure(string, \Closure, int): void */
    private static function protoHelper(JsObject $proto): \Closure
    {
        return static fn (string $n, \Closure $fn, int $len = 0) => $proto->defineOwnProperty(
            $n,
            PropertyDescriptor::data(JsFunction::fromCallable($n, $fn, $len), true, false, true),
        );
    }

    private static function setToStringTag(JsObject $obj, string $tag): void
    {
        $sym = SymbolConstructor::toStringTag();
        if ($sym !== null) {
            $obj->definePropertyBySymbol(
                $sym,
                PropertyDescriptor::data(new JsString($tag), false, false, true),
            );
        }
    }
}
