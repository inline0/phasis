<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Date;

use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
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
 * Date trait part: DateConstruction. Composed into DateConstructor via
 * `use Date\DateConstruction;`. `self::`/`$this->` resolve into the
 * composing class so static-property + cross-trait calls work.
 */
trait DateConstruction
{
    /**
     * Determine the internal time value from constructor arguments.
     *
     * new Date()                          -> current time
     * new Date(value)                     -> if number, use as ms; if string, parse it
     * new Date(year, month, ...)          -> compose from components (local time)
     *
     * @param list<JsValue> $args
     */
    private static function constructTimeValue(array $args): float
    {
        $argc = count($args);

        // No arguments: current time.
        if ($argc === 0) {
            return self::nowMs();
        }

        // Single argument.
        if ($argc === 1) {
            $val = $args[0];

            // Fast path: numeric argument (the common case, and the path
            // the SpiderMonkey DST stress harness exercises via
            // new Date(NaN) on every probe). Skips the full
            // ToPrimitive / ToNumber dance.
            if ($val instanceof JsNumber) {
                $tv = $val->value;
                if (!is_finite($tv)) {
                    return NAN;
                }
                return self::timeClip($tv);
            }

            // If the argument is another Date object, copy its time value.
            if ($val instanceof JsObject && self::isDateObject($val)) {
                return self::dateValueOf($val);
            }

            $prim = TypeConversion::toPrimitive($val);
            if ($prim instanceof JsString) {
                return self::parseDate($prim->value);
            }

            $tv = TypeConversion::toNumber($prim);
            if (!is_finite($tv)) {
                return NAN;
            }
            return self::timeClip($tv);
        }

        // Two or more arguments: year, month [, day [, hours [, minutes [, seconds [, ms]]]]].
        return self::makeLocalMs($args);
    }

    /**
     * Compose a time value from component arguments in local time.
     *
     * @param list<JsValue> $args
     */
    private static function makeLocalMs(array $args): float
    {
        $y = TypeConversion::toNumber($args[0] ?? JsUndefined::instance());
        $m = TypeConversion::toNumber($args[1] ?? JsUndefined::instance());
        $dt = isset($args[2]) ? TypeConversion::toNumber($args[2]) : 1.0;
        $h = isset($args[3]) ? TypeConversion::toNumber($args[3]) : 0.0;
        $min = isset($args[4]) ? TypeConversion::toNumber($args[4]) : 0.0;
        $s = isset($args[5]) ? TypeConversion::toNumber($args[5]) : 0.0;
        $milli = isset($args[6]) ? TypeConversion::toNumber($args[6]) : 0.0;

        if (
            !is_finite($y) || !is_finite($m) || !is_finite($dt)
            || !is_finite($h) || !is_finite($min) || !is_finite($s) || !is_finite($milli)
        ) {
            return NAN;
        }
        // Per spec MakeDay: ym = year + floor(month/12). If ym overflows to
        // ±Infinity (tc39/ecma262#1087), return NaN before any further math.
        if (!is_finite($y + floor($m / 12.0))) {
            return NAN;
        }

        // ES spec: if 0 <= ToInteger(year) <= 99, yearValue = 1900 + ToInteger(year).
        $yi = (int) $y;
        if ($yi >= 0 && $yi <= 99) {
            $yi += 1900;
        }

        $mi = (int) $m;
        $di = (int) $dt;
        $hi = (int) $h;
        $mini = (int) $min;
        $si = (int) $s;
        $msi = (int) $milli;

        // Use DateTimeImmutable to correctly handle all years including < 100.
        $ts = self::composeLocalTimestamp($yi, $mi, $di, $hi, $mini, $si);
        if ($ts === null) {
            return NAN;
        }

        $ms = (float) $ts * 1000.0 + (float) $msi;
        return self::timeClip($ms);
    }

    /**
     * Compose a time value from component arguments in UTC.
     *
     * Uses the spec's MakeDay/MakeTime/MakeDate algorithms with IEEE 754 float
     * arithmetic to match V8's precision behavior.
     *
     * @param list<JsValue> $args
     */
    private static function makeUtcMs(array $args): float
    {
        $y = TypeConversion::toNumber($args[0] ?? JsUndefined::instance());
        $m = isset($args[1]) ? TypeConversion::toNumber($args[1]) : 0.0;
        $dt = isset($args[2]) ? TypeConversion::toNumber($args[2]) : 1.0;
        $h = isset($args[3]) ? TypeConversion::toNumber($args[3]) : 0.0;
        $min = isset($args[4]) ? TypeConversion::toNumber($args[4]) : 0.0;
        $s = isset($args[5]) ? TypeConversion::toNumber($args[5]) : 0.0;
        $milli = isset($args[6]) ? TypeConversion::toNumber($args[6]) : 0.0;

        if (
            !is_finite($y) || !is_finite($m) || !is_finite($dt)
            || !is_finite($h) || !is_finite($min) || !is_finite($s) || !is_finite($milli)
        ) {
            return NAN;
        }

        $yi = self::toInteger($y);
        if ($yi >= 0 && $yi <= 99) {
            $yi += 1900;
        }

        $mi = self::toInteger($m);
        $di = self::toInteger($dt);
        $hi = self::toInteger($h);
        $mini = self::toInteger($min);
        $si = self::toInteger($s);
        $msi = self::toInteger($milli);

        // Use MakeDay/MakeTime/MakeDate per spec (IEEE 754 float arithmetic).
        $day = self::makeDay($yi, $mi, $di);
        if (!is_finite($day)) {
            return NAN;
        }
        $time = self::makeTime($hi, $mini, $si, $msi);
        $date = self::makeDate($day, $time);
        return self::timeClip($date);
    }

    /**
     * ES spec 21.4.1.11 MakeTime(hour, min, sec, ms).
     *
     * Computes time value in ms using IEEE 754 float arithmetic.
     * The order of operations must match the spec exactly for precision.
     */
    private static function makeTime(float $hour, float $min, float $sec, float $ms): float
    {
        if (!is_finite($hour) || !is_finite($min) || !is_finite($sec) || !is_finite($ms)) {
            return NAN;
        }
        // Per spec: ((h * msPerHour + m * msPerMinute) + s * msPerSecond) + milli
        return (($hour * 3600000.0 + $min * 60000.0) + $sec * 1000.0) + $ms;
    }

    /**
     * ES spec 21.4.1.12 MakeDay(year, month, date).
     *
     * Computes the day number from year/month/date components.
     */
    private static function makeDay(float $year, float $month, float $date): float
    {
        if (!is_finite($year) || !is_finite($month) || !is_finite($date)) {
            return NAN;
        }
        $y = $year + floor($month / 12.0);
        // Per spec, the intermediate sum may overflow to ±Infinity even when
        // both operands are finite. tc39/ecma262#1087 makes this a NaN.
        if (!is_finite($y)) {
            return NAN;
        }
        $m = fmod($month, 12.0);
        if ($m < 0) {
            $m += 12.0;
        }
        // Find the day number for the first day of the given year/month in UTC.
        // Use DateTimeImmutable to correctly handle years 0-99 (gmmktime misinterprets them).
        $yi = (int) $y;
        $mi = (int) $m;
        try {
            $dt = new \DateTimeImmutable('2000-01-01 00:00:00', new \DateTimeZone('UTC'));
            $dt = $dt->setDate($yi, $mi + 1, 1);
            $dt = $dt->setTime(0, 0, 0);
            $ts = (int) $dt->format('U');
        } catch (\Throwable) {
            return NAN;
        }
        $dayStart = floor($ts / 86400.0);
        return $dayStart + $date - 1.0;
    }

    /**
     * ES spec 21.4.1.13 MakeDate(day, time).
     *
     * Combines day number and time-of-day into a time value (ms since epoch).
     */
    private static function makeDate(float $day, float $time): float
    {
        if (!is_finite($day) || !is_finite($time)) {
            return NAN;
        }
        return $day * 86400000.0 + $time;
    }

    /**
     * ES spec ToInteger: truncate toward zero.
     */
    private static function toInteger(float $value): float
    {
        if (is_nan($value) || $value === 0.0) {
            return 0.0;
        }
        return ($value > 0 ? 1 : -1) * floor(abs($value));
    }

    /** Current time in milliseconds since epoch, truncated to integer. */
    private static function nowMs(): float
    {
        return (float) (int) (microtime(true) * 1000);
    }

    /**
     * TimeClip: clamp to the valid range, return NaN if out of bounds.
     * ES spec 21.4.1.13. Absolute value must be <= 8.64e15.
     */
    private static function timeClip(float $tv): float
    {
        if (!is_finite($tv)) {
            return NAN;
        }
        if (abs($tv) > 8.64e15) {
            return NAN;
        }
        return (float) (int) $tv;
    }

    /** Check if a JsObject is a Date instance by looking for the [[IsDate]] marker. */
    public static function isDateObject(JsValue $value): bool
    {
        if (!$value instanceof JsObject) {
            return false;
        }
        // Per spec, [[DateValue]] is an internal slot of the original Date
        // object. A Proxy wrapping a Date does NOT have the slot itself, so
        // thisTimeValue(proxy) must throw TypeError. Internal slots bypass
        // Proxy traps via the target, but here we want to reject Proxy
        // receivers explicitly.
        if ($value instanceof \Phasis\Value\JsProxy) {
            return false;
        }
        // Fast path: the internal-slot table is a direct array lookup,
        // significantly cheaper than walking the property map. Falls back
        // to the property-keyed marker for Dates produced before the fast
        // path existed (or via the slow init path).
        if ($value->getInternalProperty('[[IsDate]]') === true) {
            return true;
        }
        $marker = $value->get('[[IsDate]]');
        return $marker instanceof JsBoolean && $marker->value;
    }

    /**
     * Store the canonical [[DateValue]] internal slot. The SM dst-offset-
     * caching stress harness drives this and {@see dateValueOf()} multiple
     * millions of times per fraction, so both write and read are inlined
     * as direct `$internalSlots[$name]` operations instead of full
     * property-map descriptor sets/gets.
     */
    private static function setDateValue(JsObject $obj, float $tv): void
    {
        $obj->setInternalProperty('[[DateValue]]', $tv);
    }

    /**
     * Read the canonical [[DateValue]] internal slot. Returns NAN when the
     * slot has never been written (defensive; a Date constructed through
     * any of the documented paths always has it).
     */
    private static function dateValueOf(JsObject $obj): float
    {
        $tv = $obj->getInternalProperty('[[DateValue]]');
        if (is_float($tv) || is_int($tv)) {
            return (float) $tv;
        }
        if ($tv instanceof JsNumber) {
            return $tv->value;
        }
        // Legacy path: Dates constructed via setters that wrote
        // [[DateValue]] as a real property descriptor still round-trip
        // through here. Keep both fallbacks so existing flows stay
        // correct.
        $legacy = $obj->get('[[DateValue]]');
        return $legacy instanceof JsNumber ? $legacy->value : NAN;
    }

    /** Extract the internal time value (ms since epoch) from a Date object. */
    private static function getTimeValue(JsValue $this_): float
    {
        if (!$this_ instanceof JsObject || !self::isDateObject($this_)) {
            throw new TypeError('this is not a Date object');
        }
        return self::dateValueOf($this_);
    }

    /**
     * VM fast path for `date.setTime(<number>)`. Returns null if the
     * receiver is not a real Date (so the slow path can raise the
     * spec-mandated TypeError) or the argument is not numeric (so
     * ToNumber on a JS object can still run with proper observable
     * side effects). The SM dst-offset-caching stress harness drives
     * this with plain numbers; sliding past the closure dispatch and
     * the property-key strncmp drops ~1µs per call.
     *
     * @param list<JsValue> $args
     */
    public static function vmFastDateSetTime(JsValue $receiver, array $args): ?JsValue
    {
        if (!$receiver instanceof JsObject) {
            return null;
        }
        if ($receiver->getInternalProperty('[[IsDate]]') !== true) {
            return null;
        }
        $arg = $args[0] ?? null;
        if ($arg === null) {
            $tv = NAN;
        } elseif ($arg instanceof JsNumber) {
            $tv = $arg->value;
        } else {
            return null;
        }
        $tv = self::timeClip($tv);
        $receiver->setInternalProperty('[[DateValue]]', $tv);
        return JsNumber::of($tv);
    }

    /**
     * VM fast path for `date.getTimezoneOffset()` on a real Date.
     * Returns null when the receiver does not match (slow path then
     * raises the spec TypeError).
     */
    public static function vmFastDateGetTimezoneOffset(JsValue $receiver): ?JsValue
    {
        if (!$receiver instanceof JsObject) {
            return null;
        }
        $tv = $receiver->getInternalProperty('[[DateValue]]');
        if (!is_float($tv) && !is_int($tv)) {
            // Legacy descriptor-stored value: defer to the slow path
            // which knows how to unwrap both shapes.
            return null;
        }
        if ($receiver->getInternalProperty('[[IsDate]]') !== true) {
            return null;
        }
        $tvF = (float) $tv;
        if (is_nan($tvF)) {
            return JsNumber::of(NAN);
        }
        $offsetSec = self::localOffsetSeconds($tvF);
        return JsNumber::of((float) (-$offsetSec / 60));
    }

    /**
     * VM fast path for `new Date(<ms>)` with a numeric or absent
     * argument. Returns null when the input does not match the fast
     * shape so the VM falls back to vmNewExpression.
     *
     * The SM dst-offset-caching stress harness drives this ~2.7M times
     * per fraction (4-deep loop over ~38 timestamps); skipping
     * [[NewTarget]] property set/get/delete, the prototype lookup, and
     * the callFunction trampoline collapses per-call cost to a single
     * JsObject allocation plus two internal-slot writes.
     *
     * @param list<JsValue> $args
     */
    public static function vmFastNewDate(JsFunction $callee, array $args): ?JsObject
    {
        if ($callee !== self::$dateConstructor) {
            return null;
        }
        $argc = count($args);
        if ($argc === 0) {
            $tv = self::nowMs();
        } elseif ($argc === 1) {
            $v = $args[0];
            if (!$v instanceof JsNumber) {
                return null;
            }
            $val = $v->value;
            $tv = is_finite($val) ? self::timeClip($val) : NAN;
        } else {
            return null;
        }
        $obj = new JsObject(self::$datePrototype);
        $obj->setInternalProperty('[[DateValue]]', $tv);
        $obj->setInternalProperty('[[IsDate]]', true);
        return $obj;
    }
}
