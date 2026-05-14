<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

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
    use Temporal\NowSection;
    use Temporal\InstantSection;
    use Temporal\DurationSection;
    use Temporal\PlainDateSection;
    use Temporal\PlainTimeSection;
    use Temporal\PlainDateTimeSection;
    use Temporal\PlainYearMonthSection;
    use Temporal\PlainMonthDaySection;
    use Temporal\ZonedDateTimeSection;
    use Temporal\SlotAccessHelpers;
    use Temporal\BrandCheckHelpers;
    use Temporal\IsoCalendarHelpers;
    use Temporal\BigIntNsHelpers;
    use Temporal\InstantParsingHelpers;
    use Temporal\ObjectCreationHelpers;
    use Temporal\DurationHelpers;
    use Temporal\TypeConversionHelpers;
    use Temporal\FormattingHelpers;
    use Temporal\TimezoneHelpers;
    use Temporal\IcuCalendarHelpers;
    use Temporal\ArithmeticHelpers;
    use Temporal\RoundingHelpers;
    use Temporal\TemporalUnitsHelpers;
    use Temporal\MethodRegistrationHelpers;

    // Nanosecond limits for Instant per spec: +/- 8.64e21 ns (100 million days).
    private const NS_MAX = '8640000000000000000000';
    private const NS_MIN = '-8640000000000000000000';

    // ISO year range for PlainDate etc.
    private const ISO_YEAR_MIN = -271821;
    private const ISO_YEAR_MAX = 275760;

    /**
     * Emulate OrdinaryCreateFromConstructor's prototype lookup: read the
     * pre-allocated receiver's [[NewTarget]], get newTarget.prototype, and
     * apply it to the receiver. Throws through to the caller if the getter
     * throws. Falls back to $defaultProto when newTarget.prototype is not
     * an object.
     */
    private static function applyNewTargetPrototype(JsObject $receiver, JsObject $defaultProto): void
    {
        $ntDesc = $receiver->getOwnPropertyDescriptor('[[NewTarget]]');
        if ($ntDesc !== null && $ntDesc->value instanceof JsFunction) {
            $ntProto = $ntDesc->value->get('prototype');
            $receiver->setPrototype(
                $ntProto instanceof JsObject ? $ntProto : $defaultProto,
            );
        }
    }

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
        $temporal->definePropertyBySymbol(
            $toStringTagSym,
            PropertyDescriptor::data(new JsString('Temporal'), false, false, true),
        );

        $env->defineVar('Temporal', $temporal);
    }
}
