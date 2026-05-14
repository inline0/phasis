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
 * Date trait part: DateFormatting. Composed into DateConstructor via
 * `use Date\DateFormatting;`. `self::`/`$this->` resolve into the
 * composing class so static-property + cross-trait calls work.
 */
trait DateFormatting
{
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
            throw new \Phasis\Exceptions\RangeError('Invalid time value');
        }

        $ms = (int) $tv;
        // Floor division so negative time values (years before 1970) split
        // into the correct (seconds, millis) pair: ms in [0, 999].
        $seconds = (int) floor($tv / 1000);
        $millis = $ms - $seconds * 1000;

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
     * Apply ToDateTimeOptions defaults: when no explicit
     * date/time component or dateStyle/timeStyle is supplied,
     * fill in the appropriate skeleton ("date" -> year/month/day,
     * "time" -> hour/minute/second, "all" -> both). Returns the
     * augmented options object as a JsObject.
     */
    private static function toDateTimeOptions(JsValue $options, string $required): JsObject
    {
        if ($options instanceof JsObject) {
            $result = new JsObject($options->getPrototype());
            foreach ($options->getOwnPropertyNames() as $name) {
                $val = $options->get($name);
                if (!$val instanceof JsUndefined) {
                    $result->set($name, $val);
                }
            }
        } elseif ($options instanceof JsUndefined) {
            // Per spec ToDateTimeOptions step 1: ObjectCreate(undefined)
            // gives a null-prototype object, so polluting Object.prototype
            // with default options doesn't bleed into Date.toLocaleString.
            $result = JsObject::createNullPrototype();
        } else {
            $result = TypeConversion::toObject($options);
        }
        // Per spec ToDateTimeOptions, needDefaults is a single
        // flag: any explicit component (date or time) OR an explicit
        // dateStyle / timeStyle disables defaulting altogether.
        $needDefaults = true;
        $relevantDate = ['weekday', 'year', 'month', 'day'];
        $relevantTime = ['dayPeriod', 'hour', 'minute', 'second', 'fractionalSecondDigits'];
        if ($required === 'date' || $required === 'all' || $required === 'any') {
            foreach ($relevantDate as $k) {
                if (!$result->get($k) instanceof JsUndefined) {
                    $needDefaults = false;
                    break;
                }
            }
        }
        if (
            $needDefaults
            && ($required === 'time' || $required === 'all' || $required === 'any')
        ) {
            foreach ($relevantTime as $k) {
                if (!$result->get($k) instanceof JsUndefined) {
                    $needDefaults = false;
                    break;
                }
            }
        }
        if (
            !$result->get('dateStyle') instanceof JsUndefined
            || !$result->get('timeStyle') instanceof JsUndefined
        ) {
            $needDefaults = false;
        }
        if (!$needDefaults) {
            return $result;
        }
        // Default the appropriate skeleton.
        if ($required === 'all' || $required === 'any' || $required === 'date') {
            foreach (['year', 'month', 'day'] as $k) {
                if ($result->get($k) instanceof JsUndefined) {
                    $result->set($k, new JsString('numeric'));
                }
            }
        }
        if ($required === 'all' || $required === 'any' || $required === 'time') {
            foreach (['hour', 'minute', 'second'] as $k) {
                if ($result->get($k) instanceof JsUndefined) {
                    $result->set($k, new JsString('numeric'));
                }
            }
        }
        return $result;
    }
}
