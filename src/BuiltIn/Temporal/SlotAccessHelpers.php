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
 * Temporal helper section (SlotAccessHelpers). Composed into TemporalObject
 * via `use Temporal\SlotAccessHelpers;`. `self::` references resolve into
 * the composing class.
 */
trait SlotAccessHelpers
{
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
        $obj->defineOwnProperty('[[ISOYear]]', PropertyDescriptor::data(JsNumber::of((float) $y), false, false, false));
        $obj->defineOwnProperty('[[ISOMonth]]', PropertyDescriptor::data(JsNumber::of((float) $m), false, false, false));
        $obj->defineOwnProperty('[[ISODay]]', PropertyDescriptor::data(JsNumber::of((float) $d), false, false, false));
        $obj->defineOwnProperty('[[Calendar]]', PropertyDescriptor::data(new JsString($cal), false, false, false));
    }

    private static function setTimeSlots(JsObject $obj, int $h, int $min, int $s, int $ms, int $us, int $ns): void
    {
        $obj->defineOwnProperty('[[ISOHour]]', PropertyDescriptor::data(JsNumber::of((float) $h), false, false, false));
        $obj->defineOwnProperty('[[ISOMinute]]', PropertyDescriptor::data(JsNumber::of((float) $min), false, false, false));
        $obj->defineOwnProperty('[[ISOSecond]]', PropertyDescriptor::data(JsNumber::of((float) $s), false, false, false));
        $obj->defineOwnProperty('[[ISOMillisecond]]', PropertyDescriptor::data(JsNumber::of((float) $ms), false, false, false));
        $obj->defineOwnProperty('[[ISOMicrosecond]]', PropertyDescriptor::data(JsNumber::of((float) $us), false, false, false));
        $obj->defineOwnProperty('[[ISONanosecond]]', PropertyDescriptor::data(JsNumber::of((float) $ns), false, false, false));
    }
}
