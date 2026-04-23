<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Exceptions\TypeError;
use PhpJs\Object\PropertyDescriptor;
use PhpJs\Runtime\Environment;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

/**
 * Date constructor and prototype methods.
 *
 * Stores the internal time value as milliseconds since the Unix epoch (1970-01-01T00:00:00Z).
 * NaN means the Date is invalid. All arithmetic and accessors use this internal float.
 */
class DateConstructor
{
    public static function install(Environment $env): void
    {
        $proto = self::createPrototype();

        $constructor = JsFunction::fromCallable('Date', function (JsValue $this_, array $args) use ($proto): JsValue {
            // Called as a function (without new): always return the current date string.
            if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                return new JsString(self::toDateString(self::nowMs()));
            }

            // Called with new: construct a Date object. Do NOT override the prototype;
            // Reflect.construct/subclass callers set it on $this_ via new.target.
            $timeValue = self::constructTimeValue($args);
            $this_->defineOwnProperty(
                '[[DateValue]]',
                PropertyDescriptor::data(new JsNumber($timeValue), true, false, true),
            );
            $this_->defineOwnProperty('[[IsDate]]', PropertyDescriptor::data(new JsBoolean(true), false, false, false));
            return $this_;
        }, 7);
        $constructor->setConstructable();

        // Static methods.
        $constructor->defineOwnProperty('now', PropertyDescriptor::data(
            JsFunction::fromCallable('now', function (JsValue $this_, array $args): JsValue {
                return new JsNumber(self::nowMs());
            }, 0),
            true,
            false,
            true,
        ));

        $constructor->defineOwnProperty('parse', PropertyDescriptor::data(
            JsFunction::fromCallable('parse', function (JsValue $this_, array $args): JsValue {
                $str = isset($args[0]) ? TypeConversion::toString($args[0]) : '';
                return new JsNumber(self::parseDate($str));
            }, 1),
            true,
            false,
            true,
        ));

        $constructor->defineOwnProperty('UTC', PropertyDescriptor::data(
            JsFunction::fromCallable('UTC', function (JsValue $this_, array $args): JsValue {
                return new JsNumber(self::makeUtcMs($args));
            }, 7),
            true,
            false,
            true,
        ));

        $constructor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($constructor, true, false, true));

        $env->defineVar('Date', $constructor);
    }

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

            // If the argument is another Date object, copy its time value.
            if ($val instanceof JsObject && self::isDateObject($val)) {
                $inner = $val->get('[[DateValue]]');
                return $inner instanceof JsNumber ? $inner->value : NAN;
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
     * Parse a date string. Accepts ISO 8601 and common formats via PHP strtotime.
     * Returns NaN for unparseable strings.
     */
    private static function parseDate(string $str): float
    {
        $str = trim($str);
        if ($str === '') {
            return NAN;
        }

        // Reject -000000 (negative zero year) per spec: "The representation of the
        // year 0 as -000000 is invalid."
        if (preg_match('/^-0{6}/', $str)) {
            return NAN;
        }

        // Try extended year ISO format: [+-]YYYYYY-MM-DDTHH:MM:SS.sssZ
        $extPattern = '/^([+-]\d{6})-(\d{2})-(\d{2})'
            . '(?:T(\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,3}))?)?)?'
            . '([Z]|[+-]\d{2}:\d{2})?$/i';
        if (preg_match($extPattern, $str, $m)) {
            $year = (int) $m[1];
            $month = (int) $m[2];
            $day = (int) $m[3];
            $hour = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;
            $min = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : 0;
            $sec = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0;
            $millis = 0.0;
            if (isset($m[7]) && $m[7] !== '') {
                $millis = (float) str_pad($m[7], 3, '0');
            }

            $hasTime = isset($m[4]) && $m[4] !== '';
            $tz = ($m[8] ?? '') !== '' ? $m[8] : null;

            // Date-only forms are UTC; date-time without TZ is local.
            if (!$hasTime && $tz === null) {
                $tz = 'Z';
            }

            // Compute using gmmktime for UTC, then adjust for timezone offset.
            $ts = gmmktime($hour, $min, $sec, $month, $day, $year);
            if ($ts === false) {
                return NAN;
            }

            $offsetSec = 0;
            if ($tz !== null && strtoupper($tz) !== 'Z') {
                if (preg_match('/^([+-])(\d{2}):(\d{2})$/', $tz, $tzm)) {
                    $offsetSec = ((int) $tzm[2] * 3600 + (int) $tzm[3] * 60);
                    if ($tzm[1] === '+') {
                        $offsetSec = -$offsetSec;
                    }
                }
            } elseif ($tz === null) {
                // Local time: subtract local timezone offset.
                $dt = new \DateTimeImmutable('@' . $ts);
                $local = $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                $offsetSec = -(int) $local->format('Z');
            }

            $result = (float) $ts * 1000.0 + $millis + (float) $offsetSec * 1000.0;
            return self::timeClip($result);
        }

        // Try ISO 8601 first: YYYY-MM-DDTHH:MM:SS.sssZ or with offset.
        $isoPattern = '/^\d{4}(-\d{2}(-\d{2}(T\d{2}:\d{2}'
            . '(:\d{2}(\.\d{1,3})?)?)?)?)?([Z]|[+-]\d{2}:\d{2})?$/i';
        if (preg_match($isoPattern, $str)) {
            // ISO 8601 date-only forms (no time component) are treated as UTC by the spec.
            $hasTime = str_contains($str, 'T') || str_contains($str, 't');
            $hasTimezone = (bool) preg_match('/[Z]|[+-]\d{2}:\d{2}$/i', $str);

            if (!$hasTime && !$hasTimezone) {
                // Date-only ISO: treat as UTC.
                // Expand partial ISO forms: YYYY -> YYYY-01-01, YYYY-MM -> YYYY-MM-01
                if (preg_match('/^\d{4}$/', $str)) {
                    // Year only: YYYY -> YYYY-01-01T00:00:00Z
                    $str .= '-01-01T00:00:00Z';
                } elseif (preg_match('/^\d{4}-\d{2}$/', $str)) {
                    // Year-month: YYYY-MM -> YYYY-MM-01T00:00:00Z
                    $str .= '-01T00:00:00Z';
                } else {
                    $str .= 'T00:00:00Z';
                }
            } elseif ($hasTime && !$hasTimezone) {
                // Date-time without timezone: treat as local time per spec.
                // strtotime handles this correctly by default.
            }
        }

        $ts = strtotime($str);
        if ($ts === false) {
            return NAN;
        }

        // Extract sub-second precision from the string if present.
        $ms = 0.0;
        if (preg_match('/\.(\d{1,3})/', $str, $match)) {
            $frac = str_pad($match[1], 3, '0');
            $ms = (float) $frac;
        }

        return self::timeClip((float) $ts * 1000.0 + $ms);
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
        $marker = $value->get('[[IsDate]]');
        return $marker instanceof JsBoolean && $marker->value;
    }

    /** Extract the internal time value (ms since epoch) from a Date object. */
    private static function getTimeValue(JsValue $this_): float
    {
        if (!$this_ instanceof JsObject || !self::isDateObject($this_)) {
            throw new TypeError('this is not a Date object');
        }
        $tv = $this_->get('[[DateValue]]');
        return $tv instanceof JsNumber ? $tv->value : NAN;
    }

    /**
     * Format a timestamp as the V8 Date.prototype.toString() format:
     * "Tue Jan 02 2024 03:04:05 GMT+0100 (Central European Standard Time)"
     *
     * We produce the core portion without the parenthesized timezone name,
     * as PHP does not reliably produce the same long-form timezone name as V8.
     */
    private static function toDateString(float $tv): string
    {
        if (is_nan($tv)) {
            return 'Invalid Date';
        }

        $ts = (int) floor($tv / 1000);
        $dt = new \DateTimeImmutable('@' . $ts);
        $local = $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        return $local->format('D M d Y H:i:s \G\M\TO (T)');
    }

    /** Format as "Tue Jan 02 2024". */
    private static function toDateOnlyString(float $tv): string
    {
        if (is_nan($tv)) {
            return 'Invalid Date';
        }
        $ts = (int) floor($tv / 1000);
        $dt = new \DateTimeImmutable('@' . $ts);
        $local = $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return $local->format('D M d Y');
    }

    /** Format as "03:04:05 GMT+0100 (CET)". */
    private static function toTimeString(float $tv): string
    {
        if (is_nan($tv)) {
            return 'Invalid Date';
        }
        $ts = (int) floor($tv / 1000);
        $dt = new \DateTimeImmutable('@' . $ts);
        $local = $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return $local->format('H:i:s \G\M\TO (T)');
    }

    /** Format as ISO 8601: "2024-01-02T03:04:05.000Z". */
    private static function toISOString(float $tv): string
    {
        if (is_nan($tv)) {
            throw new \PhpJs\Exceptions\RangeError('Invalid time value');
        }

        $ms = (int) $tv;
        $seconds = intdiv($ms, 1000);
        $millis = abs($ms % 1000);

        $dt = new \DateTimeImmutable('@' . $seconds);
        $utc = $dt->setTimezone(new \DateTimeZone('UTC'));

        $year = (int) $utc->format('Y');
        // ES spec: years outside 0-9999 use expanded year format with 6 digits.
        if ($year >= 0 && $year <= 9999) {
            $yearStr = str_pad((string) $year, 4, '0', STR_PAD_LEFT);
        } else {
            $sign = $year >= 0 ? '+' : '-';
            $yearStr = $sign . str_pad((string) abs($year), 6, '0', STR_PAD_LEFT);
        }

        return $yearStr . $utc->format('-m-d\TH:i:s') . '.' . str_pad((string) $millis, 3, '0', STR_PAD_LEFT) . 'Z';
    }

    /** Format as UTC string: "Tue, 02 Jan 2024 02:04:05 GMT". */
    private static function toUTCString(float $tv): string
    {
        if (is_nan($tv)) {
            return 'Invalid Date';
        }
        $ts = (int) floor($tv / 1000);
        $dt = new \DateTimeImmutable('@' . $ts);
        $utc = $dt->setTimezone(new \DateTimeZone('UTC'));
        return $utc->format('D, d M Y H:i:s') . ' GMT';
    }

    /**
     * Get a DateTimeImmutable in local timezone from the internal timestamp.
     */
    private static function localDateTime(float $tv): \DateTimeImmutable
    {
        $ts = (int) floor($tv / 1000);
        $dt = new \DateTimeImmutable('@' . $ts);
        return $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    /**
     * Get a DateTimeImmutable in UTC from the internal timestamp.
     */
    private static function utcDateTime(float $tv): \DateTimeImmutable
    {
        $ts = (int) floor($tv / 1000);
        $dt = new \DateTimeImmutable('@' . $ts);
        return $dt->setTimezone(new \DateTimeZone('UTC'));
    }

    private static function createPrototype(): JsObject
    {
        $proto = new JsObject();

        $d = static fn (string $n, \Closure $fn, int $len = 0) => $proto->defineOwnProperty(
            $n,
            PropertyDescriptor::data(JsFunction::fromCallable($n, $fn, $len), true, false, true),
        );

        // --- Getters (local time) ---

        $d('getTime', function (JsValue $this_): JsValue {
            return new JsNumber(self::getTimeValue($this_));
        });

        $d('getFullYear', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return new JsNumber(NAN);
            }
            return new JsNumber((float) (int) self::localDateTime($tv)->format('Y'));
        });

        $d('getMonth', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return new JsNumber(NAN);
            }
            // JS months are 0-based.
            return new JsNumber((float) ((int) self::localDateTime($tv)->format('n') - 1));
        });

        $d('getDate', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return new JsNumber(NAN);
            }
            return new JsNumber((float) (int) self::localDateTime($tv)->format('j'));
        });

        $d('getDay', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return new JsNumber(NAN);
            }
            return new JsNumber((float) (int) self::localDateTime($tv)->format('w'));
        });

        $d('getHours', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return new JsNumber(NAN);
            }
            return new JsNumber((float) (int) self::localDateTime($tv)->format('G'));
        });

        $d('getMinutes', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return new JsNumber(NAN);
            }
            return new JsNumber((float) (int) self::localDateTime($tv)->format('i'));
        });

        $d('getSeconds', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return new JsNumber(NAN);
            }
            return new JsNumber((float) (int) self::localDateTime($tv)->format('s'));
        });

        $d('getMilliseconds', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return new JsNumber(NAN);
            }
            $ms = (int) $tv % 1000;
            if ($ms < 0) {
                $ms += 1000;
            }
            return new JsNumber((float) $ms);
        });

        $d('getTimezoneOffset', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return new JsNumber(NAN);
            }
            $local = self::localDateTime($tv);
            // Offset in seconds from UTC, JS returns minutes with sign inverted.
            $offsetSec = (int) $local->format('Z');
            return new JsNumber((float) (-$offsetSec / 60));
        });

        // --- Getters (UTC) ---

        $d('getUTCFullYear', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return new JsNumber(NAN);
            }
            return new JsNumber((float) (int) self::utcDateTime($tv)->format('Y'));
        });

        $d('getUTCMonth', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return new JsNumber(NAN);
            }
            return new JsNumber((float) ((int) self::utcDateTime($tv)->format('n') - 1));
        });

        $d('getUTCDate', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return new JsNumber(NAN);
            }
            return new JsNumber((float) (int) self::utcDateTime($tv)->format('j'));
        });

        $d('getUTCDay', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return new JsNumber(NAN);
            }
            return new JsNumber((float) (int) self::utcDateTime($tv)->format('w'));
        });

        $d('getUTCHours', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return new JsNumber(NAN);
            }
            return new JsNumber((float) (int) self::utcDateTime($tv)->format('G'));
        });

        $d('getUTCMinutes', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return new JsNumber(NAN);
            }
            return new JsNumber((float) (int) self::utcDateTime($tv)->format('i'));
        });

        $d('getUTCSeconds', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return new JsNumber(NAN);
            }
            return new JsNumber((float) (int) self::utcDateTime($tv)->format('s'));
        });

        $d('getUTCMilliseconds', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return new JsNumber(NAN);
            }
            $ms = (int) $tv % 1000;
            if ($ms < 0) {
                $ms += 1000;
            }
            return new JsNumber((float) $ms);
        });

        // --- Setters (local time) ---

        $d('setTime', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject || !self::isDateObject($this_)) {
                throw new TypeError('this is not a Date object');
            }
            $tv = isset($args[0]) ? TypeConversion::toNumber($args[0]) : NAN;
            $tv = self::timeClip($tv);
            $this_->set('[[DateValue]]', new JsNumber($tv));
            return new JsNumber($tv);
        }, 1);

        $d('setMilliseconds', function (JsValue $this_, array $args): JsValue {
            return self::setterLocal($this_, $args, 'ms');
        }, 1);

        $d('setSeconds', function (JsValue $this_, array $args): JsValue {
            return self::setterLocal($this_, $args, 'sec');
        }, 2);

        $d('setMinutes', function (JsValue $this_, array $args): JsValue {
            return self::setterLocal($this_, $args, 'min');
        }, 3);

        $d('setHours', function (JsValue $this_, array $args): JsValue {
            return self::setterLocal($this_, $args, 'hour');
        }, 4);

        $d('setDate', function (JsValue $this_, array $args): JsValue {
            return self::setterLocal($this_, $args, 'date');
        }, 1);

        $d('setMonth', function (JsValue $this_, array $args): JsValue {
            return self::setterLocal($this_, $args, 'month');
        }, 2);

        $d('setFullYear', function (JsValue $this_, array $args): JsValue {
            return self::setterLocal($this_, $args, 'year');
        }, 3);

        // --- Setters (UTC) ---

        $d('setUTCMilliseconds', function (JsValue $this_, array $args): JsValue {
            return self::setterUtc($this_, $args, 'ms');
        }, 1);

        $d('setUTCSeconds', function (JsValue $this_, array $args): JsValue {
            return self::setterUtc($this_, $args, 'sec');
        }, 2);

        $d('setUTCMinutes', function (JsValue $this_, array $args): JsValue {
            return self::setterUtc($this_, $args, 'min');
        }, 3);

        $d('setUTCHours', function (JsValue $this_, array $args): JsValue {
            return self::setterUtc($this_, $args, 'hour');
        }, 4);

        $d('setUTCDate', function (JsValue $this_, array $args): JsValue {
            return self::setterUtc($this_, $args, 'date');
        }, 1);

        $d('setUTCMonth', function (JsValue $this_, array $args): JsValue {
            return self::setterUtc($this_, $args, 'month');
        }, 2);

        $d('setUTCFullYear', function (JsValue $this_, array $args): JsValue {
            return self::setterUtc($this_, $args, 'year');
        }, 3);

        // --- Conversion methods ---

        $d('toString', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            return new JsString(self::toDateString($tv));
        });

        $d('toDateString', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            return new JsString(self::toDateOnlyString($tv));
        });

        $d('toTimeString', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            return new JsString(self::toTimeString($tv));
        });

        $d('toISOString', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            return new JsString(self::toISOString($tv));
        });

        $d('toUTCString', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            return new JsString(self::toUTCString($tv));
        });

        // Annex B: toGMTString is an alias for toUTCString
        $proto->defineOwnProperty(
            'toGMTString',
            PropertyDescriptor::data($proto->get('toUTCString'), true, false, true),
        );

        // Annex B: getYear() returns year - 1900
        $d('getYear', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return new JsNumber(NAN);
            }
            $local = self::localDateTime($tv);
            return new JsNumber((float) ((int) $local->format('Y') - 1900));
        }, 0);

        // Annex B: setYear(year) sets the year (adds 1900 if 0-99)
        $d('setYear', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject || !self::isDateObject($this_)) {
                throw new TypeError('this is not a Date object');
            }
            $yearArg = $args[0] ?? JsUndefined::instance();
            $y = TypeConversion::toNumber($yearArg);
            if (is_nan($y)) {
                $this_->set('[[DateValue]]', new JsNumber(NAN));
                return new JsNumber(NAN);
            }
            $yi = (int) $y;
            if ($yi >= 0 && $yi <= 99) {
                $yi += 1900;
            }
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                $tv = 0.0;
            }
            $local = self::localDateTime($tv);
            $ms = (int) $tv % 1000;
            if ($ms < 0) {
                $ms += 1000;
            }
            $newDate = $local->setDate(
                $yi,
                (int) $local->format('n'),
                (int) $local->format('j'),
            );
            $newTv = self::timeClip((float) $newDate->getTimestamp() * 1000 + $ms);
            $this_->set('[[DateValue]]', new JsNumber($newTv));
            return new JsNumber($newTv);
        }, 1);

        $d('toJSON', function (JsValue $this_): JsValue {
            // ES spec 21.4.4.24 Date.prototype.toJSON ( key )
            // 1. Let O be ? ToObject(this value).
            $o = TypeConversion::toObject($this_);

            // 2. Let tv be ? ToPrimitive(O, hint Number).
            $tv = TypeConversion::toPrimitive($o, 'number');

            // 3. If Type(tv) is Number and tv is not finite, return null.
            if ($tv instanceof JsNumber && !is_finite($tv->value)) {
                return JsNull::instance();
            }

            // 4. Return ? Invoke(O, "toISOString").
            $toISO = $o->get('toISOString');
            if (!$toISO instanceof JsFunction) {
                throw new TypeError('toISOString is not a function');
            }
            return $toISO->call($o, []);
        }, 1);

        $d('toLocaleDateString', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return new JsString('Invalid Date');
            }
            $local = self::localDateTime($tv);
            return new JsString($local->format('n/j/Y'));
        });

        $d('toLocaleTimeString', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return new JsString('Invalid Date');
            }
            $local = self::localDateTime($tv);
            $h = (int) $local->format('g');
            $min = $local->format('i');
            $sec = $local->format('s');
            $ampm = $local->format('A');
            return new JsString("{$h}:{$min}:{$sec} {$ampm}");
        });

        $d('toLocaleString', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return new JsString('Invalid Date');
            }
            $local = self::localDateTime($tv);
            $date = $local->format('n/j/Y');
            $h = (int) $local->format('g');
            $min = $local->format('i');
            $sec = $local->format('s');
            $ampm = $local->format('A');
            return new JsString("{$date}, {$h}:{$min}:{$sec} {$ampm}");
        });

        $d('valueOf', function (JsValue $this_): JsValue {
            return new JsNumber(self::getTimeValue($this_));
        });

        // Symbol.toPrimitive for Date: implements ES spec 21.4.4.45.
        // The hint argument must be a string primitive with value "string", "number", or "default".
        // "default" maps to "string" for Date. Then OrdinaryToPrimitive is called.
        $toPrimSym = SymbolConstructor::toPrimitive();
        $toPrimFn = JsFunction::fromCallable('[Symbol.toPrimitive]', function (JsValue $this_, array $args): JsValue {
            // Step 1: Let O be the this value.
            // Step 2: If Type(O) is not Object, throw a TypeError.
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Date.prototype[Symbol.toPrimitive] requires an object');
            }
            // The hint must be a JS string primitive. Do not coerce.
            $hintVal = $args[0] ?? JsUndefined::instance();
            if (!$hintVal instanceof JsString) {
                throw new TypeError('Invalid hint');
            }
            $hint = $hintVal->value;
            // Step 3: If hint is "string" or "default", let tryFirst be "string".
            if ($hint === 'string' || $hint === 'default') {
                $tryFirst = 'string';
            } elseif ($hint === 'number') {
                // Step 4: If hint is "number", let tryFirst be "number".
                $tryFirst = 'number';
            } else {
                // Step 5: Else, throw a TypeError.
                throw new TypeError('Invalid hint');
            }
            // Step 6: Return OrdinaryToPrimitive(O, tryFirst).
            $methodNames = $tryFirst === 'string'
                ? ['toString', 'valueOf']
                : ['valueOf', 'toString'];
            foreach ($methodNames as $methodName) {
                $method = $this_->get($methodName);
                if ($method instanceof JsFunction) {
                    $result = $method->call($this_, []);
                    if (!$result instanceof JsObject) {
                        return $result;
                    }
                }
            }
            throw new TypeError('Cannot convert object to primitive value');
        }, 1);
        $proto->definePropertyBySymbol($toPrimSym, PropertyDescriptor::data($toPrimFn, false, false, true));

        // Date.prototype.toTemporalInstant() per Temporal proposal.
        $proto->defineOwnProperty('toTemporalInstant', PropertyDescriptor::data(
            JsFunction::fromCallable('toTemporalInstant', function (JsValue $this_): JsValue {
                if (!$this_ instanceof JsObject || !self::isDateObject($this_)) {
                    throw new TypeError('this is not a Date object');
                }
                $dvVal = $this_->get('[[DateValue]]');
                $tv = ($dvVal instanceof JsNumber) ? $dvVal->value : NAN;
                if (is_nan($tv)) {
                    throw new \PhpJs\Exceptions\RangeError('Invalid time value');
                }
                $ms = (string) (int) $tv;
                $ns = bcmul($ms, '1000000');
                return TemporalObject::createInstantFromNs($ns);
            }, 0),
            true,
            false,
            true,
        ));

        // Set Symbol.toStringTag so Object.prototype.toString returns "[object Date]".
        $toStringTagSym = SymbolConstructor::toStringTag();
        $proto->setBySymbol($toStringTagSym, new JsString('Date'));

        return $proto;
    }

    /**
     * Generic setter for local-time-based set methods.
     *
     * Per the ES spec, the order of operations is:
     * 1. Get thisTimeValue
     * 2. Call ToNumber on all present arguments
     * 3. THEN check if time value is NaN (return NaN if so, except for year)
     *
     * @param list<JsValue> $args
     */
    private static function setterLocal(JsValue $this_, array $args, string $field): JsValue
    {
        if (!$this_ instanceof JsObject || !self::isDateObject($this_)) {
            throw new TypeError('this is not a Date object');
        }

        $tv = self::getTimeValue($this_);

        // Per spec: the first argument is always required (ToNumber(undefined) = NaN).
        // Coerce all present arguments to numbers BEFORE the NaN check.
        // If the first arg is missing, treat as ToNumber(undefined) = NaN.
        $coerced = [];
        if (empty($args)) {
            $coerced[] = NAN;
        } else {
            foreach ($args as $arg) {
                $coerced[] = TypeConversion::toNumber($arg);
            }
        }

        // If any coerced argument is NaN, the result is NaN
        foreach ($coerced as $c) {
            if (is_nan($c)) {
                $this_->set('[[DateValue]]', new JsNumber(NAN));
                return new JsNumber(NAN);
            }
        }

        // Per spec, "return NaN" without setting [[DateValue]] so that any
        // side effects from ToNumber (e.g. calling setTime) are preserved.
        if (is_nan($tv) && $field !== 'year') {
            return new JsNumber(NAN);
        }

        // For setFullYear on an invalid date, start from epoch.
        if (is_nan($tv) && $field === 'year') {
            $tv = 0.0;
        }

        $local = self::localDateTime($tv);
        $ms = (int) $tv % 1000;
        if ($ms < 0) {
            $ms += 1000;
        }
        $y = (int) $local->format('Y');
        $m = (int) $local->format('n') - 1; // 0-based
        $dt = (int) $local->format('j');
        $h = (int) $local->format('G');
        $min = (int) $local->format('i');
        $sec = (int) $local->format('s');

        $idx = 0;
        $getArg = static function () use ($coerced, &$idx): ?float {
            if (!isset($coerced[$idx])) {
                return null;
            }
            return $coerced[$idx++];
        };

        switch ($field) {
            case 'ms':
                $ms = (int) ($getArg() ?? $ms);
                break;
            case 'sec':
                $sec = (int) ($getArg() ?? $sec);
                $val = $getArg();
                if ($val !== null) {
                    $ms = (int) $val;
                }
                break;
            case 'min':
                $min = (int) ($getArg() ?? $min);
                $val = $getArg();
                if ($val !== null) {
                    $sec = (int) $val;
                }
                $val = $getArg();
                if ($val !== null) {
                    $ms = (int) $val;
                }
                break;
            case 'hour':
                $h = (int) ($getArg() ?? $h);
                $val = $getArg();
                if ($val !== null) {
                    $min = (int) $val;
                }
                $val = $getArg();
                if ($val !== null) {
                    $sec = (int) $val;
                }
                $val = $getArg();
                if ($val !== null) {
                    $ms = (int) $val;
                }
                break;
            case 'date':
                $dt = (int) ($getArg() ?? $dt);
                break;
            case 'month':
                $m = (int) ($getArg() ?? $m);
                $val = $getArg();
                if ($val !== null) {
                    $dt = (int) $val;
                }
                break;
            case 'year':
                $y = (int) ($getArg() ?? $y);
                $val = $getArg();
                if ($val !== null) {
                    $m = (int) $val;
                }
                $val = $getArg();
                if ($val !== null) {
                    $dt = (int) $val;
                }
                break;
        }

        // Reconstruct using DateTimeImmutable (local time).
        // We avoid mktime because it misinterprets years 0-99 (adds 1900 or 2000).
        $ts = self::composeLocalTimestamp($y, $m, $dt, $h, $min, $sec);
        if ($ts === null) {
            $this_->set('[[DateValue]]', new JsNumber(NAN));
            return new JsNumber(NAN);
        }
        $newTv = self::timeClip((float) $ts * 1000.0 + (float) $ms);
        $this_->set('[[DateValue]]', new JsNumber($newTv));
        return new JsNumber($newTv);
    }

    /**
     * Generic setter for UTC-based set methods.
     *
     * Per the ES spec, the order of operations is:
     * 1. Get thisTimeValue
     * 2. Call ToNumber on all present arguments
     * 3. THEN check if time value is NaN (return NaN if so, except for year)
     *
     * @param list<JsValue> $args
     */
    private static function setterUtc(JsValue $this_, array $args, string $field): JsValue
    {
        if (!$this_ instanceof JsObject || !self::isDateObject($this_)) {
            throw new TypeError('this is not a Date object');
        }

        $tv = self::getTimeValue($this_);

        // Coerce all present arguments to numbers BEFORE the NaN check.
        // This is required by the spec: ToNumber calls happen before the NaN guard.
        $coerced = [];
        foreach ($args as $arg) {
            $coerced[] = TypeConversion::toNumber($arg);
        }

        // Now check if the original time value was NaN (after coercing args).
        // Per spec, "return NaN" without setting [[DateValue]] so that any
        // side effects from ToNumber (e.g. calling setTime) are preserved.
        if (is_nan($tv) && $field !== 'year') {
            return new JsNumber(NAN);
        }

        if (is_nan($tv) && $field === 'year') {
            $tv = 0.0;
        }

        $utc = self::utcDateTime($tv);
        $ms = (int) $tv % 1000;
        if ($ms < 0) {
            $ms += 1000;
        }
        $y = (int) $utc->format('Y');
        $m = (int) $utc->format('n') - 1;
        $dt = (int) $utc->format('j');
        $h = (int) $utc->format('G');
        $min = (int) $utc->format('i');
        $sec = (int) $utc->format('s');

        $idx = 0;
        $getArg = static function () use ($coerced, &$idx): ?float {
            if (!isset($coerced[$idx])) {
                return null;
            }
            return $coerced[$idx++];
        };

        switch ($field) {
            case 'ms':
                $ms = (int) ($getArg() ?? $ms);
                break;
            case 'sec':
                $sec = (int) ($getArg() ?? $sec);
                $val = $getArg();
                if ($val !== null) {
                    $ms = (int) $val;
                }
                break;
            case 'min':
                $min = (int) ($getArg() ?? $min);
                $val = $getArg();
                if ($val !== null) {
                    $sec = (int) $val;
                }
                $val = $getArg();
                if ($val !== null) {
                    $ms = (int) $val;
                }
                break;
            case 'hour':
                $h = (int) ($getArg() ?? $h);
                $val = $getArg();
                if ($val !== null) {
                    $min = (int) $val;
                }
                $val = $getArg();
                if ($val !== null) {
                    $sec = (int) $val;
                }
                $val = $getArg();
                if ($val !== null) {
                    $ms = (int) $val;
                }
                break;
            case 'date':
                $dt = (int) ($getArg() ?? $dt);
                break;
            case 'month':
                $m = (int) ($getArg() ?? $m);
                $val = $getArg();
                if ($val !== null) {
                    $dt = (int) $val;
                }
                break;
            case 'year':
                $y = (int) ($getArg() ?? $y);
                $val = $getArg();
                if ($val !== null) {
                    $m = (int) $val;
                }
                $val = $getArg();
                if ($val !== null) {
                    $dt = (int) $val;
                }
                break;
        }

        // Reconstruct using DateTimeImmutable (UTC).
        // We avoid gmmktime because it misinterprets years 0-99 (adds 1900 or 2000).
        $ts = self::composeUtcTimestamp($y, $m, $dt, $h, $min, $sec);
        if ($ts === null) {
            $this_->set('[[DateValue]]', new JsNumber(NAN));
            return new JsNumber(NAN);
        }
        $newTv = self::timeClip((float) $ts * 1000.0 + (float) $ms);
        $this_->set('[[DateValue]]', new JsNumber($newTv));
        return new JsNumber($newTv);
    }

    /**
     * Compose a Unix timestamp from date/time components in local time.
     *
     * Uses DateTimeImmutable instead of mktime to correctly handle years < 100
     * (mktime adds 1900/2000 to years 0-99 which breaks setFullYear).
     *
     * @param int $y Year
     * @param int $m Month (0-based JS month)
     * @param int $d Day
     * @param int $h Hours
     * @param int $min Minutes
     * @param int $sec Seconds
     * @return int|null Unix timestamp, or null on failure
     */
    private static function composeLocalTimestamp(int $y, int $m, int $d, int $h, int $min, int $sec): ?int
    {
        return self::composeTimestamp($y, $m, $d, $h, $min, $sec, new \DateTimeZone(date_default_timezone_get()));
    }

    /**
     * Compose a Unix timestamp from date/time components in UTC.
     *
     * Uses DateTimeImmutable instead of gmmktime to correctly handle years < 100.
     *
     * @param int $y Year
     * @param int $m Month (0-based JS month)
     * @param int $d Day
     * @param int $h Hours
     * @param int $min Minutes
     * @param int $sec Seconds
     * @return int|null Unix timestamp, or null on failure
     */
    private static function composeUtcTimestamp(int $y, int $m, int $d, int $h, int $min, int $sec): ?int
    {
        return self::composeTimestamp($y, $m, $d, $h, $min, $sec, new \DateTimeZone('UTC'));
    }

    /**
     * Compose a Unix timestamp from date/time components in the given timezone.
     *
     * Handles month overflow/underflow, day overflow, and arbitrary years
     * (including years 0-99 which mktime/gmmktime misinterpret).
     *
     * @param int $y Year
     * @param int $m Month (0-based JS month)
     * @param int $d Day
     * @param int $h Hours
     * @param int $min Minutes
     * @param int $sec Seconds
     * @return int|null Unix timestamp, or null on failure
     */
    private static function composeTimestamp(
        int $y,
        int $m,
        int $d,
        int $h,
        int $min,
        int $sec,
        \DateTimeZone $tz,
    ): ?int {
        // Handle month overflow/underflow: JS months are 0-based.
        $adjustedYear = $y + intdiv($m, 12);
        $adjustedMonth = $m % 12;
        if ($adjustedMonth < 0) {
            $adjustedMonth += 12;
            $adjustedYear--;
        }
        $phpMonth = $adjustedMonth + 1; // Convert to 1-based

        try {
            // Use a reference date then setDate/setTime to avoid mktime's year bugs.
            $dt = new \DateTimeImmutable('2000-01-01 00:00:00', $tz);
            $dt = $dt->setDate($adjustedYear, $phpMonth, $d);
            $dt = $dt->setTime($h, $min, $sec);
            return (int) $dt->format('U');
        } catch (\Throwable) {
            return null;
        }
    }
}
