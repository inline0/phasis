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
 * Temporal helper section (BrandCheckHelpers). Composed into TemporalObject
 * via `use Temporal\BrandCheckHelpers;`. `self::` references resolve into
 * the composing class.
 */
trait BrandCheckHelpers
{
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

    /**
     * @phpstan-assert JsObject $this_
     */
    private static function requireDuration(JsValue $this_): void
    {
        if (!$this_ instanceof JsObject || !$this_->has('[[IsDuration]]')) {
            throw new TypeError('this is not a Temporal.Duration');
        }
    }

    private static function requirePlainDate(JsValue $this_): void
    {
        $isPlainDate = $this_ instanceof JsObject
            && $this_->has('[[ISOYear]]')
            && !$this_->has('[[IsPlainTime]]')
            && !$this_->has('[[IsPlainDateTime]]')
            && !$this_->has('[[IsPlainYearMonth]]')
            && !$this_->has('[[IsPlainMonthDay]]')
            && !$this_->has('[[IsZonedDateTime]]')
            && !$this_->has('[[IsDuration]]')
            && !$this_->has('[[EpochNanoseconds]]');
        if (!$isPlainDate) {
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

    /** RejectObjectWithCalendarOrTimeZone per spec. */
    private static function rejectObjectWithCalendarOrTimeZone(JsObject $item): void
    {
        // Reject known Temporal types with brands.
        $brands = ['[[IsPlainDate]]', '[[IsPlainDateTime]]', '[[IsPlainMonthDay]]', '[[IsPlainTime]]', '[[IsPlainYearMonth]]', '[[IsZonedDateTime]]'];
        foreach ($brands as $brand) {
            if ($item->has($brand)) {
                throw new TypeError('Temporal object not allowed in with()');
            }
        }
        if (!($item->get('calendar') instanceof JsUndefined)) {
            throw new TypeError('calendar not allowed in with()');
        }
        if (!($item->get('timeZone') instanceof JsUndefined)) {
            throw new TypeError('timeZone not allowed in with()');
        }
    }
}
