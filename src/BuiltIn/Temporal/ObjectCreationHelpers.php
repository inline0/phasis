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
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;
use Phasis\BuiltIn\SymbolConstructor;

/**
 * Temporal helper section (ObjectCreationHelpers). Composed into TemporalObject
 * via `use Temporal\ObjectCreationHelpers;`. `self::` references resolve into
 * the composing class.
 */
trait ObjectCreationHelpers
{
    // -----------------------------------------------------------------------
    // Helpers: object creation
    // -----------------------------------------------------------------------

    private static function createPlainDateObject(int $y, int $m, int $d, string $cal): JsObject
    {
        // Validate range.
        if ($y < self::ISO_YEAR_MIN || $y > self::ISO_YEAR_MAX) {
            throw new RangeError("Year out of range: {$y}");
        }
        if ($y === self::ISO_YEAR_MIN && ($m < 4 || ($m === 4 && $d < 19))) {
            throw new RangeError("Date outside representable range");
        }
        if ($y === self::ISO_YEAR_MAX && ($m > 9 || ($m === 9 && $d > 13))) {
            throw new RangeError("Date outside representable range");
        }
        $obj = new JsObject(self::$plainDateProto);
        self::setDateSlots($obj, $y, $m, $d, $cal);
        $obj->defineOwnProperty('[[IsPlainDate]]', PropertyDescriptor::data(new JsBoolean(true), false, false, false));
        return $obj;
    }

    private static function createPlainTimeObject(int $h, int $min, int $s, int $ms, int $us, int $ns): JsObject
    {
        $obj = new JsObject(self::$plainTimeProto);
        self::setTimeSlots($obj, $h, $min, $s, $ms, $us, $ns);
        $obj->defineOwnProperty('[[IsPlainTime]]', PropertyDescriptor::data(new JsBoolean(true), false, false, false));
        return $obj;
    }

    private static function createPlainDateTimeObject(
        int $y,
        int $m,
        int $d,
        int $h,
        int $min,
        int $s,
        int $ms,
        int $us,
        int $ns,
        string $cal,
    ): JsObject {
        // Validate PlainDateTime range: -271821-04-19T00:00:00.000000001 to +275760-09-13T23:59:59.999999999
        if ($y === self::ISO_YEAR_MIN && $m === 4 && $d === 19) {
            // At the minimum date, time must be > 00:00:00.000000000
            if ($h === 0 && $min === 0 && $s === 0 && $ms === 0 && $us === 0 && $ns === 0) {
                throw new RangeError("PlainDateTime outside representable range");
            }
        }
        $obj = new JsObject(self::$plainDateTimeProto);
        self::setDateSlots($obj, $y, $m, $d, $cal);
        self::setTimeSlots($obj, $h, $min, $s, $ms, $us, $ns);
        $obj->defineOwnProperty('[[IsPlainDateTime]]', PropertyDescriptor::data(new JsBoolean(true), false, false, false));
        return $obj;
    }

    private static function createPlainYearMonthObject(int $y, int $m, int $refDay, string $cal): JsObject
    {
        // Validate range: the YearMonth must contain at least one in-range date.
        if ($y < self::ISO_YEAR_MIN || $y > self::ISO_YEAR_MAX) {
            throw new RangeError("Year out of range: {$y}");
        }
        if ($y === self::ISO_YEAR_MIN && $m < 4) {
            throw new RangeError("YearMonth outside representable range");
        }
        if ($y === self::ISO_YEAR_MAX && $m > 9) {
            throw new RangeError("YearMonth outside representable range");
        }
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



    private static function createDurationObject(
        int|float $years,
        int|float $months,
        int|float $weeks,
        int|float $days,
        int|float $hours,
        int|float $minutes,
        int|float $seconds,
        int|float $milliseconds,
        int|float $microseconds,
        int|float $nanoseconds,
    ): JsObject {
        $fields = [$years, $months, $weeks, $days, $hours, $minutes, $seconds, $milliseconds, $microseconds, $nanoseconds];
        self::validateDurationFields($fields, true);
        $obj = new JsObject(self::$durationProto);
        $names = ['years', 'months', 'weeks', 'days', 'hours', 'minutes', 'seconds', 'milliseconds', 'microseconds', 'nanoseconds'];
        foreach ($names as $i => $name) {
            $obj->defineOwnProperty("[[{$name}]]", PropertyDescriptor::data(JsNumber::of((float) $fields[$i]), false, false, false));
        }
        $obj->defineOwnProperty('[[IsDuration]]', PropertyDescriptor::data(new JsBoolean(true), false, false, false));
        return $obj;
    }

    /** Check whether $obj's prototype chain includes $proto. */
    private static function objectInheritsFrom(JsObject $obj, JsObject $proto): bool
    {
        $p = $obj->getPrototype();
        while ($p instanceof JsObject) {
            if ($p === $proto) {
                return true;
            }
            $p = $p->getPrototype();
        }
        return false;
    }
}
