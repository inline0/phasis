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

    /**
     * Maximum possible days in a month for the given calendar. Used by
     * PlainMonthDay's "constrain" overflow path where the caller does not
     * know a specific year. Returns the largest plausible upper bound across
     * all years for that calendar's month code.
     */
    private static function maxDaysInCalendarMonth(string $calendar, int $month, bool $isLeapMonthCode = false): int
    {
        switch ($calendar) {
            case 'buddhist':
            case 'gregory':
            case 'japanese':
            case 'roc':
                return self::isoDaysInMonth(2000, $month); // leap-year max for Feb (29).
            case 'coptic':
            case 'ethioaa':
            case 'ethiopic':
                return $month === 13 ? 6 : 30;
            case 'hebrew':
                if ($isLeapMonthCode) {
                    return 30;
                }
                return match ($month) {
                    1, 3, 5, 7, 11 => 30,    // Tishri, Kislev (max), Shevat, Nisan, Av
                    2 => 30, // Cheshvan (max).
                    4, 6, 8, 10, 12 => 29,   // Tevet, Adar, Iyyar, Tammuz, Elul
                    9 => 30, // Sivan
                    default => 30,
                };
            case 'indian':
                return match ($month) {
                    1 => 31, // Chaitra (leap year), 30 otherwise
                    2, 3, 4, 5, 6 => 31,
                    7, 8, 9, 10, 11, 12 => 30,
                    default => 31,
                };
            case 'islamic':
            case 'islamic-civil':
            case 'islamic-tbla':
            case 'islamic-umalqura':
            case 'islamic-rgsa':
                return 30; // umalqura M12 can be 30.
            case 'persian':
                return match ($month) {
                    1, 2, 3, 4, 5, 6 => 31,
                    7, 8, 9, 10, 11 => 30,
                    12 => 30, // 30 in leap year, 29 otherwise.
                    default => 30,
                };
            case 'chinese':
            case 'dangi':
                return 30;
            default:
                return self::isoDaysInMonth(2000, $month);
        }
    }

    private static function isoDaysInYear(int $year): int
    {
        return self::isoIsLeapYear($year) ? 366 : 365;
    }

    /**
     * Compute a midpoint date for month-boundary clamping in date differences.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function computeMonthMidpoint(
        int $sign,
        int $y1,
        int $m1,
        int $y2,
        int $m2,
        int $anchorDay,
        int $monthCount,
    ): array {
        if ($sign < 0) {
            $mt = $y2 * 12 + ($m2 - 1) - $monthCount;
        } else {
            $mt = $y1 * 12 + ($m1 - 1) + $monthCount;
        }
        $my = intdiv($mt, 12);
        $mm = ($mt % 12) + 1;
        if ($mm < 1) {
            $mm += 12;
            $my--;
        }
        $md = min($anchorDay, self::isoDaysInMonth($my, $mm));
        return [$my, $mm, $md];
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
        // Use PHP's built-in ISO week calculation for reliability.
        try {
            $dt = new \DateTimeImmutable("{$year}-{$month}-{$day}", new \DateTimeZone('UTC'));
            $weekNum = (int) $dt->format('W');
            $yearOfWeek = (int) $dt->format('o');
            return [$weekNum, $yearOfWeek];
        } catch (\Throwable) {
            return [null, null];
        }
    }

    /**
     * Calendar-aware week of year.
     * - "iso8601": ISO 8601 week numbering.
     * - "gregory": Gregorian (locale-default) week numbering via ICU.
     * - Others: undefined (no well-defined week-numbering system).
     * @return array{0: ?int, 1: ?int} [weekOfYear, yearOfWeek]
     */
    private static function calendarWeekOfYear(string $calendar, int $year, int $month, int $day): array
    {
        if ($calendar === 'iso8601') {
            return self::isoWeekOfYear($year, $month, $day);
        }
        if ($calendar === 'gregory') {
            if (!class_exists('IntlCalendar', false)) {
                return self::isoWeekOfYear($year, $month, $day);
            }
            try {
                $cal = \IntlCalendar::createInstance('UTC', 'en@calendar=gregorian');
                $cal->setDateTime($year, $month - 1, $day, 0, 0, 0);
                $weekNum = $cal->get(\IntlCalendar::FIELD_WEEK_OF_YEAR);
                $yearWoY = $cal->get(\IntlCalendar::FIELD_YEAR_WOY);
                return [(int) $weekNum, (int) $yearWoY];
            } catch (\Throwable) {
                return self::isoWeekOfYear($year, $month, $day);
            }
        }
        return [null, null];
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
        // Precise range: -271821-04-19 to +275760-09-13 inclusive.
        if ($y === self::ISO_YEAR_MIN) {
            if ($m < 4 || ($m === 4 && $d < 19)) {
                throw new RangeError("Date is outside the representable range");
            }
        }
        if ($y === self::ISO_YEAR_MAX) {
            if ($m > 9 || ($m === 9 && $d > 13)) {
                throw new RangeError("Date is outside the representable range");
            }
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

    private static function compareISOTime(
        int $h1,
        int $m1,
        int $s1,
        int $ms1,
        int $us1,
        int $ns1,
        int $h2,
        int $m2,
        int $s2,
        int $ms2,
        int $us2,
        int $ns2,
    ): int {
        $pairs = [
            [$h1, $h2], [$m1, $m2], [$s1, $s2],
            [$ms1, $ms2], [$us1, $us2], [$ns1, $ns2],
        ];
        foreach ($pairs as [$a, $b]) {
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
                throw new \Phasis\Exceptions\SyntaxError("Cannot convert {$str} to a BigInt");
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
        if ($item instanceof JsUndefined || $item instanceof JsNull) {
            throw new TypeError('Cannot convert undefined/null to Instant');
        }
        if ($item instanceof JsNumber || $item instanceof \Phasis\Value\JsBigInt) {
            throw new TypeError('Cannot convert number to Temporal.Instant');
        }
        if ($item instanceof JsBoolean) {
            throw new TypeError('Cannot convert boolean to Temporal.Instant');
        }
        if ($item instanceof \Phasis\Value\JsSymbol) {
            throw new TypeError('Cannot convert Symbol to Temporal.Instant');
        }
        $str = TypeConversion::toString($item);
        return self::parseInstantString($str);
    }

    private static function parseInstantString(string $str): string
    {
        [$str] = self::normalizeTemporalString($str);
        // Reject extended year without sign.
        if (preg_match('/^\d{5,}-/', $str)) {
            throw new RangeError("Extended year requires sign: {$str}");
        }
        // Reject -000000.
        if (preg_match('/^-0{4,6}[-\d]/', $str)) {
            throw new RangeError("reject minus zero year: {$str}");
        }
        // ISO 8601 with required timezone offset.
        // Supports date with hyphens (YYYY-MM-DD) or without (YYYYMMDD).
        // Supports sub-minute offsets (+HH:MM:SS.fractional).
        $datePart = '([+-]?\d{4,6})(?:-(\d{2})-(\d{2})|(\d{2})(\d{2}))';
        $timePart = '(\d{2})(?::?(\d{2})(?::?(\d{2})(?:[.,](\d{1,9}))?)?)?' ;
        $tzPart = '([Zz]|[+-]\d{2}(?::?\d{2}(?::?\d{2}(?:[.,]\d{1,9})?)?)?)';
        $pattern = "/^{$datePart}[Tt ]{$timePart}{$tzPart}(?:\\[.*?\\])*\$/";
        if (!preg_match($pattern, $str, $m)) {
            throw new RangeError("Invalid Instant string: {$str}");
        }

        $year = (int) $m[1];
        // Hyphenated date or compact date.
        if ($m[2] !== '') {
            $month = (int) $m[2];
            $day = (int) $m[3];
        } else {
            $month = (int) $m[4];
            $day = (int) $m[5];
        }
        // Validate date.
        if ($month < 1 || $month > 12 || $day < 1 || $day > self::isoDaysInMonth($year, $month)) {
            throw new RangeError("Invalid Instant date: {$str}");
        }
        $hour = (int) $m[6];
        $min = $m[7] !== '' ? (int) $m[7] : 0;
        $sec = $m[8] !== '' ? (int) $m[8] : 0;
        // Validate time.
        if ($hour > 23 || $min > 59 || $sec > 60) {
            throw new RangeError("Invalid Instant time: {$str}");
        }
        // Handle leap second: clamp to 59.
        if ($sec === 60) {
            $sec = 59;
        }
        $frac = $m[9] !== '' ? str_pad($m[9], 9, '0') : '000000000';
        $tz = $m[10];

        // Offset nanoseconds (supports sub-minute offsets).
        $offsetNs = '0';
        if (strtoupper($tz) !== 'Z') {
            $sign = $tz[0] === '-' ? -1 : 1;
            $tzBody = preg_replace('/[^0-9.,]/', '', substr($tz, 1));
            // Re-parse with colons removed. Format: HHMMSS.fractional or HHMM or HH.
            $tzDigits = preg_replace('/[.,].*$/', '', $tzBody);
            $tzH = (int) substr($tzDigits, 0, 2);
            $tzM = strlen($tzDigits) >= 4 ? (int) substr($tzDigits, 2, 2) : 0;
            $tzS = strlen($tzDigits) >= 6 ? (int) substr($tzDigits, 4, 2) : 0;
            if ($tzH > 23 || $tzM > 59 || $tzS > 59) {
                throw new RangeError("Invalid UTC offset: {$str}");
            }
            // Sub-second fractional offset.
            $tzFrac = '0';
            if (preg_match('/[.,](\d+)/', $tzBody, $fm)) {
                $tzFrac = str_pad($fm[1], 9, '0');
            }
            $offsetSec = $sign * ($tzH * 3600 + $tzM * 60 + $tzS);
            $offsetNs = bcmul((string) $offsetSec, '1000000000', 0);
            if ($tzFrac !== '0' && $tzFrac !== '000000000') {
                $subNsOffset = $sign > 0 ? $tzFrac : '-' . $tzFrac;
                $offsetNs = bcadd($offsetNs, $subNsOffset, 0);
            }
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

        self::validateInstantRange($epochNs);

        return $epochNs;
    }

    private static function instantToString(
        string $ns,
        string|int $fractionalSecondDigits = 'auto',
        string $roundingMode = 'trunc',
        bool $omitSeconds = false,
    ): string {
        // Split ns into seconds and sub-second nanoseconds.
        $negative = isset($ns[0]) && $ns[0] === '-';
        $abs = $negative ? substr($ns, 1) : $ns;

        $sec = bcdiv($abs, '1000000000', 0);
        $subNs = bcsub($abs, bcmul($sec, '1000000000', 0), 0);

        if ($negative && $subNs !== '0') {
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
        if ($omitSeconds) {
            $dateStr = self::padISOYear($year) . $dt->format('-m-d\TH:i');
            return $dateStr . 'Z';
        }
        $dateStr = self::padISOYear($year) . $dt->format('-m-d\TH:i:s');

        $subNsPadded = str_pad($subNs, 9, '0', STR_PAD_LEFT);
        $fracStr = self::formatSubSecond($subNsPadded, $fractionalSecondDigits);

        return $dateStr . $fracStr . 'Z';
    }

    private static function instantToStringInZone(
        string $ns,
        string $timeZone,
        string|int $fractionalSecondDigits = 'auto',
        string $roundingMode = 'trunc',
    ): string {
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
    private static function validateDurationFields(array $fields, bool $checkRange = false): void
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
        if ($checkRange) {
            self::validateDurationRange($fields);
        }
    }

    /**
     * Per spec, validate Duration field ranges.
     * years/months/weeks: max 2^32 - 1
     * days: max ceil(2^53 / 86400)
     * hours: max ceil(2^53 / 3600)
     * minutes: max ceil(2^53 / 60)
     * seconds: max 2^53 - 1
     * ms/us/ns balance must not push seconds beyond 2^53.
     *
     * @param array<mixed> $fields
     */
    private static function validateDurationRange(array $fields): void
    {
        // Max values per field.
        $maxYMW = 4294967295; // 2^32 - 1
        $maxDays = 104249991374; // ceil(2^53 / 86400) - 1
        $maxHours = 2501999792983; // ceil(2^53 / 3600) - 1
        $maxMinutes = 150119987579016; // ceil(2^53 / 60) - 1
        $maxSeconds = 9007199254740991; // 2^53 - 1

        // [years, months, weeks, days, hours, minutes, seconds, ms, us, ns]
        $abs = array_map(fn ($v) => abs($v), $fields);
        // Convert to bc-safe strings.
        $toStr = fn ($v) => abs($v) < 1e15 ? (string) (int) abs($v) : number_format(abs($v), 0, '.', '');
        $absStr = array_map($toStr, $fields);

        if ($abs[0] > $maxYMW) {
            throw new RangeError('years out of range');
        }
        if ($abs[1] > $maxYMW) {
            throw new RangeError('months out of range');
        }
        if ($abs[2] > $maxYMW) {
            throw new RangeError('weeks out of range');
        }

        // Balance sub-second into seconds for range check (use bcmath for safety).
        $totalNs = bcadd(bcadd($absStr[9], bcmul($absStr[8], '1000', 0), 0), bcmul($absStr[7], '1000000', 0), 0);
        $extraSec = bcdiv($totalNs, '1000000000', 0);
        $balancedSec = bcadd($absStr[6], $extraSec, 0);

        // Balance seconds into minutes.
        $extraMin = bcdiv($balancedSec, '60', 0);
        $balancedMin = bcadd($absStr[5], $extraMin, 0);

        // Balance minutes into hours.
        $extraHours = bcdiv($balancedMin, '60', 0);
        $balancedHours = bcadd($absStr[4], $extraHours, 0);

        // Balance hours into days.
        $extraDays = bcdiv($balancedHours, '24', 0);
        $balancedDays = bcadd($absStr[3], $extraDays, 0);

        if (bccomp($balancedDays, (string) $maxDays, 0) > 0) {
            throw new RangeError('days out of range');
        }
        if (bccomp($balancedHours, (string) $maxHours, 0) > 0) {
            throw new RangeError('hours out of range');
        }
        if (bccomp($balancedMin, (string) $maxMinutes, 0) > 0) {
            throw new RangeError('minutes out of range');
        }
        if (bccomp($balancedSec, (string) $maxSeconds, 0) > 0) {
            throw new RangeError('seconds out of range');
        }
    }

    private static function toDuration(JsValue $item, bool $copy = false): JsObject
    {
        if ($item instanceof JsObject && $item->has('[[IsDuration]]')) {
            if ($copy) {
                // Duration.from must return a new copy.
                $fields = ['years', 'months', 'weeks', 'days', 'hours', 'minutes', 'seconds', 'milliseconds', 'microseconds', 'nanoseconds'];
                $vals = [];
                foreach ($fields as $f) {
                    $vals[] = self::getDurationField($item, $f);
                }
                return self::createDurationObject(...$vals);
            }
            return $item;
        }
        if ($item instanceof JsString) {
            return self::parseDurationString($item->value);
        }
        if ($item instanceof JsObject) {
            return self::durationFromObject($item);
        }
        if ($item instanceof JsUndefined || $item instanceof JsNull) {
            throw new TypeError('Cannot convert undefined or null to Duration');
        }
        if ($item instanceof JsNumber || $item instanceof \Phasis\Value\JsBigInt) {
            throw new TypeError('Cannot convert number to Duration');
        }
        if ($item instanceof JsBoolean) {
            throw new TypeError('Cannot convert boolean to Duration');
        }
        if ($item instanceof \Phasis\Value\JsSymbol) {
            throw new TypeError('Cannot convert Symbol to Duration');
        }
        // Try as string.
        $str = TypeConversion::toString($item);
        return self::parseDurationString($str);
    }

    private static function durationFromObject(JsObject $obj): JsObject
    {
        // Per spec: read properties in ALPHABETICAL order.
        $readOrder = ['days', 'hours', 'microseconds', 'milliseconds', 'minutes', 'months', 'nanoseconds', 'seconds', 'weeks', 'years'];
        $read = [];
        $any = false;
        foreach ($readOrder as $f) {
            $v = $obj->get($f);
            if ($v instanceof JsUndefined) {
                $read[$f] = 0;
            } else {
                $n = TypeConversion::toNumber($v);
                if (!is_finite($n)) {
                    throw new RangeError("infinite Duration field: {$f}");
                }
                if (floor($n) !== $n) {
                    throw new RangeError("fractional Duration field: {$f}");
                }
                $read[$f] = $n;
                $any = true;
            }
        }
        if (!$any) {
            throw new TypeError('at least one recognized property must be provided');
        }
        // createDurationObject expects: years, months, weeks, days, hours, minutes, seconds, ms, us, ns
        return self::createDurationObject(
            $read['years'],
            $read['months'],
            $read['weeks'],
            $read['days'],
            $read['hours'],
            $read['minutes'],
            $read['seconds'],
            $read['milliseconds'],
            $read['microseconds'],
            $read['nanoseconds'],
        );
    }

    private static function parseDurationString(string $str): JsObject
    {
        // ISO 8601 duration: [+-]P[nY][nM][nW][nD][T[nH][nM][n[.frac]S]]
        // Date components (Y/M/W/D) must be integers.
        // Only time components (H/M/S) can have fractions.
        $intNum = '(\d+)';
        $fracNum = '(\d+(?:[.,]\d{1,9})?)';
        $pattern = "/^([+-])?P(?:{$intNum}Y)?(?:{$intNum}M)?"
            . "(?:{$intNum}W)?(?:{$intNum}D)?"
            . "(?:T(?:{$fracNum}H)?(?:{$fracNum}M)?(?:{$fracNum}S)?)?\$/i";
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
                $f = (float) $val;
                if (!is_finite($f)) {
                    throw new RangeError("Duration field out of range: {$val}");
                }
                return [(int) $val, 0, 0, 0];
            }
            $parts = explode('.', $val);
            $f = (float) $parts[0];
            if (!is_finite($f)) {
                throw new RangeError("Duration field out of range: {$val}");
            }
            $whole = (int) $parts[0];
            $frac = $parts[1];

            // Convert fraction to sub-units using integer arithmetic.
            // Pad fraction to 9 digits for nanosecond precision.
            $frac9 = str_pad(substr($frac, 0, 9), 9, '0');
            $fracNs = (int) $frac9; // fractional part as nanoseconds of the unit
            switch ($unit) {
                case 'H':
                    // fracNs * 3600 gives total ns from fractional hours
                    $totalNs = $fracNs * 3600;
                    $minutes = intdiv($totalNs, 60000000000);
                    $remNs = $totalNs % 60000000000;
                    $secWhole = intdiv($remNs, 1000000000);
                    $subNs = $remNs % 1000000000;
                    return [$whole, $minutes, $secWhole, $subNs];
                case 'M': // minutes
                    $totalNs = $fracNs * 60;
                    $secWhole = intdiv($totalNs, 1000000000);
                    $subNs = $totalNs % 1000000000;
                    return [$whole, $secWhole, $subNs, 0];
                case 'S':
                    $ms = (int) substr($frac9, 0, 3);
                    $us = (int) substr($frac9, 3, 3);
                    $ns = (int) substr($frac9, 6, 3);
                    return [$whole, $ms, $us, $ns];
                default:
                    return [(int) $val, 0, 0, 0];
            }
        };

        $safeInt = static function (string $val): int {
            $f = (float) $val;
            if (!is_finite($f)) {
                throw new RangeError("Duration field out of range: {$val}");
            }
            return (int) $val;
        };

        $years = isset($m[2]) && $m[2] !== '' ? $safeInt($m[2]) : 0;
        $months = isset($m[3]) && $m[3] !== '' ? $safeInt($m[3]) : 0;
        $weeks = isset($m[4]) && $m[4] !== '' ? $safeInt($m[4]) : 0;
        $days = isset($m[5]) && $m[5] !== '' ? $safeInt($m[5]) : 0;

        $hours = 0;
        $minutes = 0;
        $seconds = 0;
        $milliseconds = 0;
        $microseconds = 0;
        $nanoseconds = 0;

        $hourHasFrac = false;
        $minHasFrac = false;
        if (isset($m[6]) && $m[6] !== '') {
            $hourHasFrac = str_contains(str_replace(',', '.', $m[6]), '.');
            [$hours, $fracMin, $fracSec, $fracSubNs] = $parseFrac($m[6], 'H');
            $minutes += $fracMin;
            $seconds += $fracSec;
            $milliseconds += intdiv($fracSubNs, 1000000);
            $microseconds += intdiv($fracSubNs % 1000000, 1000);
            $nanoseconds += $fracSubNs % 1000;
        }
        if (isset($m[7]) && $m[7] !== '') {
            if ($hourHasFrac) {
                throw new RangeError(
                    "fractional hours with minutes: {$str}"
                );
            }
            $minHasFrac = str_contains(str_replace(',', '.', $m[7]), '.');
            [$min2, $fracSec2, $fracSubNs2] = $parseFrac($m[7], 'M');
            $minutes += $min2;
            $seconds += $fracSec2;
            $milliseconds += intdiv($fracSubNs2, 1000000);
            $microseconds += intdiv($fracSubNs2 % 1000000, 1000);
            $nanoseconds += $fracSubNs2 % 1000;
        }
        if (isset($m[8])) {
            if ($hourHasFrac || $minHasFrac) {
                throw new RangeError(
                    "fractional hours/minutes with seconds: {$str}"
                );
            }
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

    private static function durationToString(
        JsValue $dur,
        string|int $fractionalSecondDigits = 'auto',
        string $roundingMode = 'trunc',
        ?string $smallestUnit = null,
    ): string {
        $years = self::getDurationField($dur, 'years');
        $months = self::getDurationField($dur, 'months');
        $weeks = self::getDurationField($dur, 'weeks');
        $days = self::getDurationField($dur, 'days');
        $hours = self::getDurationField($dur, 'hours');
        $minutes = self::getDurationField($dur, 'minutes');
        $seconds = self::getDurationField($dur, 'seconds');
        // Sub-second fields as strings to preserve precision for IEEE-754 floats
        // beyond 2^53 (e.g., microseconds > 9e15 where int cast would lose digits).
        $millisecondsStr = self::getDurationFieldStr($dur, 'milliseconds');
        $microsecondsStr = self::getDurationFieldStr($dur, 'microseconds');
        $nanosecondsStr = self::getDurationFieldStr($dur, 'nanoseconds');
        $absBc = fn (string $s) => str_starts_with($s, '-') ? substr($s, 1) : $s;

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
        // Days are appended after carry calculation below.

        // Time part: balance sub-seconds using bcmath to avoid float overflow.
        $bNs = $absBc($nanosecondsStr);
        $bUs = bcmul($absBc($microsecondsStr), '1000', 0);
        $bMs = bcmul($absBc($millisecondsStr), '1000000', 0);
        $totalNsBig = bcadd(bcadd($bNs, $bUs, 0), $bMs, 0);
        // Apply rounding if fractionalSecondDigits < 9.
        if (is_int($fractionalSecondDigits) && $fractionalSecondDigits < 9) {
            $digitsToIncr = [
                0 => '1000000000', 1 => '100000000', 2 => '10000000',
                3 => '1000000', 4 => '100000', 5 => '10000',
                6 => '1000', 7 => '100', 8 => '10',
            ];
            $incr = $digitsToIncr[$fractionalSecondDigits] ?? '1';
            $totalTimeNsBig = bcadd(bcmul((string) abs($seconds), '1000000000', 0), $totalNsBig, 0);
            $roundedBig = self::roundBigIntNs($totalTimeNsBig, $incr, $roundingMode);
            // After rounding, check if total time in seconds (including days/hours/minutes) exceeds MAX_SAFE_INTEGER.
            $roundedSecStr = bcdiv($roundedBig, '1000000000', 0);
            $allTimeSec = bcadd($roundedSecStr, (string) (abs($days) * 86400 + abs($hours) * 3600 + abs($minutes) * 60), 0);
            if (bccomp($allTimeSec, '9007199254740991', 0) > 0) {
                throw new RangeError('Duration time value out of range after rounding');
            }
            $totalNs = (int) bcmod($roundedBig, '1000000000', 0);
            $totalSec = (int) $roundedSecStr;
        } else {
            $totalSec = abs($seconds) + (int) bcdiv($totalNsBig, '1000000000', 0);
            $totalNs = (int) bcmod($totalNsBig, '1000000000', 0);
        }
        $remainNs = $totalNs;
        // Carry over from rounding: seconds -> minutes -> hours -> days.
        $displayMinutes = abs($minutes);
        $displayHours = abs($hours);
        $displayDays = abs($days);
        if ($smallestUnit === 'minute') {
            $totalSec += $remainNs > 0 ? 1 : 0;
            $remainNs = 0;
            $displayMinutes += intdiv($totalSec, 60);
            $totalSec = 0;
            $remainNs = 0;
        }
        // Per spec step 21: include seconds if precision is not auto, or if any time units are nonzero.
        $precisionNotAuto = $fractionalSecondDigits !== 'auto' || $smallestUnit !== null;
        // Carry seconds overflow into minutes, hours, and days (only when rounding was applied).
        // Per spec, carry only into units that were already present in the original duration.
        $wasRounded = is_int($fractionalSecondDigits) && $fractionalSecondDigits < 9;
        $origMinutes = abs($minutes);
        $origHours = abs($hours);
        if ($wasRounded && $totalSec >= 60 && ($origMinutes || $origHours || $displayDays)) {
            $displayMinutes += intdiv($totalSec, 60);
            $totalSec = $totalSec % 60;
        }
        if ($wasRounded && $displayMinutes >= 60 && ($origHours || $displayDays)) {
            $displayHours += intdiv($displayMinutes, 60);
            $displayMinutes = $displayMinutes % 60;
        }
        if ($wasRounded && $displayHours >= 24) {
            $displayDays += intdiv($displayHours, 24);
            $displayHours = $displayHours % 24;
        }
        // Now append days (after carry from hours).
        if ($displayDays) {
            $result .= $displayDays . 'D';
        }
        $hasTime = $displayHours || $displayMinutes || $totalSec || $remainNs || $precisionNotAuto;

        if ($hasTime) {
            $result .= 'T';
            if ($displayHours) {
                $result .= $displayHours . 'H';
            }
            if ($displayMinutes) {
                $result .= $displayMinutes . 'M';
            }
            if ($totalSec || $remainNs || $precisionNotAuto) {
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

        // If completely empty, output "PT0S" (with precision if specified).
        if ($result === 'P' || $result === '-P') {
            if ($fractionalSecondDigits !== 'auto' && is_int($fractionalSecondDigits) && $fractionalSecondDigits > 0) {
                $result = 'PT0.' . str_repeat('0', $fractionalSecondDigits) . 'S';
            } else {
                $result = 'PT0S';
            }
        }

        return $result;
    }

    private static function durationToTotalNs(JsValue $dur): string
    {
        // Convert weeks and days plus time components to nanoseconds.
        $weeks = self::getDurationFieldStr($dur, 'weeks');
        $days = bcadd(self::getDurationFieldStr($dur, 'days'), bcmul($weeks, '7', 0), 0);
        $hours = self::getDurationFieldStr($dur, 'hours');
        $minutes = self::getDurationFieldStr($dur, 'minutes');
        $seconds = self::getDurationFieldStr($dur, 'seconds');
        $milliseconds = self::getDurationFieldStr($dur, 'milliseconds');
        $microseconds = self::getDurationFieldStr($dur, 'microseconds');
        $nanoseconds = self::getDurationFieldStr($dur, 'nanoseconds');

        $totalNs = bcadd(
            bcadd(
                bcadd(
                    bcmul($days, '86400000000000', 0),
                    bcmul($hours, '3600000000000', 0),
                    0,
                ),
                bcadd(
                    bcmul($minutes, '60000000000', 0),
                    bcmul($seconds, '1000000000', 0),
                    0,
                ),
                0,
            ),
            bcadd(
                bcadd(
                    bcmul($milliseconds, '1000000', 0),
                    bcmul($microseconds, '1000', 0),
                    0,
                ),
                $nanoseconds,
                0,
            ),
            0,
        );
        return $totalNs;
    }

    /** Get a duration field as bcmath string to avoid PHP int overflow. */
    private static function getDurationFieldStr(JsValue $obj, string $field): string
    {
        if (!$obj instanceof JsObject) {
            return '0';
        }
        $v = $obj->get("[[{$field}]]");
        if ($v instanceof JsNumber) {
            if (abs($v->value) < 1e15) {
                return (string) (int) $v->value;
            }
            return number_format($v->value, 0, '.', '');
        }
        return '0';
    }

    /**
     * For a non-zero duration with a PlainDate relativeTo, validate that the
     * PlainDate at midnight UTC falls within the PlainDateTime representable
     * range (|ns| <= NS_MAX + nsPerDay - 1). This is the "RejectDateTimeRange"
     * check the spec applies inside DifferencePlainDateTimeWithTotal.
     */
    private static function validatePlainRelativeToRange(?JsObject $refDate, JsValue $dur): void
    {
        if ($refDate === null) {
            return;
        }
        if (self::durationSign($dur) === 0) {
            return;
        }
        $y = self::getSlotInt($refDate, '[[ISOYear]]');
        $m = self::getSlotInt($refDate, '[[ISOMonth]]');
        $d = self::getSlotInt($refDate, '[[ISODay]]');
        $originNs = self::isoDateTimeToEpochNs($y, $m, $d, 0, 0, 0, 0, 0, 0, 'UTC');
        $absNs = bccomp($originNs, '0', 0) < 0 ? bcsub('0', $originNs, 0) : $originNs;
        $pdtMax = bcsub(bcadd(self::NS_MAX, '86400000000000', 0), '1', 0);
        if (bccomp($absNs, $pdtMax, 0) > 0) {
            throw new RangeError(
                'relativeTo is outside the representable range for a relativeTo parameter after conversion to DateTime'
            );
        }
    }

    /** Compute Duration.total with a relativeTo reference point. */
    private static function durationTotalWithRelativeTo(JsValue $dur, string $unit, JsValue $relativeTo): float
    {
        // ZDT-aware totals: compute via actual epoch ns so that DST-shifted
        // wall days and months contribute their real (23/24/25h) length.
        if ($relativeTo instanceof JsObject && $relativeTo->has('[[IsZonedDateTime]]')) {
            $tzZdt = self::getSlotString($relativeTo, '[[TimeZone]]');
            $startNsZdt = self::getSlotString($relativeTo, '[[EpochNanoseconds]]');
            $endNsZdt = self::addDurationToZdt($relativeTo, $dur, 1, 'constrain');
            $deltaNsZdt = bcsub($endNsZdt, $startNsZdt, 0);
            $signZdt = bccomp($deltaNsZdt, '0', 0) >= 0 ? 1 : -1;
            $absDeltaZdt = $signZdt < 0 ? substr($deltaNsZdt, 1) : $deltaNsZdt;
            $timeUnits = ['hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];
            if (in_array($unit, $timeUnits, true)) {
                $unitNsStr = self::temporalUnitToNs($unit);
                $abs = $unitNsStr === '1'
                    ? $absDeltaZdt
                    : bcdiv($absDeltaZdt, $unitNsStr, 25);
                $signed = ($signZdt < 0 ? '-' : '') . $abs;
                return (float) $signed;
            }
            if ($unit === 'day' || $unit === 'week') {
                $stepUnit = 'day';
                $stepDays = 1;
                $daysWalked = 0;
                while (true) {
                    $stepDur = self::createDurationObject(
                        0,
                        0,
                        0,
                        $signZdt * ($daysWalked + 1),
                        0,
                        0,
                        0,
                        0,
                        0,
                        0,
                    );
                    $stepNs = self::addDurationToZdt($relativeTo, $stepDur, 1, 'constrain');
                    $cmp = bccomp($stepNs, $endNsZdt, 0);
                    if ($signZdt > 0 ? $cmp > 0 : $cmp < 0) {
                        break;
                    }
                    $daysWalked++;
                    if ($daysWalked > 100000000) {
                        break;
                    }
                }
                $startStepDur = self::createDurationObject(
                    0,
                    0,
                    0,
                    $signZdt * $daysWalked,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                );
                $startStepNs = self::addDurationToZdt($relativeTo, $startStepDur, 1, 'constrain');
                $nextStepDur = self::createDurationObject(
                    0,
                    0,
                    0,
                    $signZdt * ($daysWalked + 1),
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                );
                $nextStepNs = self::addDurationToZdt($relativeTo, $nextStepDur, 1, 'constrain');
                $dayLenNs = bcsub($nextStepNs, $startStepNs, 0);
                $absDayLen = bccomp($dayLenNs, '0', 0) < 0 ? substr($dayLenNs, 1) : $dayLenNs;
                $progressNs = bcsub($endNsZdt, $startStepNs, 0);
                $absProgress = bccomp($progressNs, '0', 0) < 0 ? substr($progressNs, 1) : $progressNs;
                $fracStr = bccomp($absDayLen, '0', 0) === 0
                    ? '0'
                    : bcdiv($absProgress, $absDayLen, 25);
                if ($unit === 'week') {
                    // Fold the integer day count and the fractional progress
                    // into a single nanosecond total before dividing by a
                    // week, so the result matches the PlainDate branch which
                    // does bcdiv directly on epoch ns / 604800e9.
                    $absDaysNs = bcmul((string) $daysWalked, $absDayLen, 0);
                    $absTotalNs = bcadd($absDaysNs, $absProgress, 0);
                    $weekNs = bcmul($absDayLen, '7', 0);
                    $absWeeks = (float) bcdiv($absTotalNs, $weekNs, 25);
                    return (float) $signZdt * $absWeeks;
                }
                $totalDays = (float) ((string) $daysWalked) + (float) $fracStr;
                return (float) $signZdt * $totalDays;
            }
            if ($unit === 'month' || $unit === 'year') {
                $stepCount = 0;
                $stepField = $unit === 'year' ? 'years' : 'months';
                while (true) {
                    $stepDur = $unit === 'year'
                        ? self::createDurationObject(
                            $signZdt * ($stepCount + 1),
                            0,
                            0,
                            0,
                            0,
                            0,
                            0,
                            0,
                            0,
                            0,
                        )
                        : self::createDurationObject(
                            0,
                            $signZdt * ($stepCount + 1),
                            0,
                            0,
                            0,
                            0,
                            0,
                            0,
                            0,
                            0,
                        );
                    $stepNs = self::addDurationToZdt($relativeTo, $stepDur, 1, 'constrain');
                    $cmp = bccomp($stepNs, $endNsZdt, 0);
                    if ($signZdt > 0 ? $cmp > 0 : $cmp < 0) {
                        break;
                    }
                    $stepCount++;
                    if ($stepCount > 100000000) {
                        break;
                    }
                }
                $startStepDur = $unit === 'year'
                    ? self::createDurationObject($signZdt * $stepCount, 0, 0, 0, 0, 0, 0, 0, 0, 0)
                    : self::createDurationObject(0, $signZdt * $stepCount, 0, 0, 0, 0, 0, 0, 0, 0);
                $startStepNs = self::addDurationToZdt($relativeTo, $startStepDur, 1, 'constrain');
                $nextStepDur = $unit === 'year'
                    ? self::createDurationObject($signZdt * ($stepCount + 1), 0, 0, 0, 0, 0, 0, 0, 0, 0)
                    : self::createDurationObject(0, $signZdt * ($stepCount + 1), 0, 0, 0, 0, 0, 0, 0, 0);
                $nextStepNs = self::addDurationToZdt($relativeTo, $nextStepDur, 1, 'constrain');
                $stepLenNs = bcsub($nextStepNs, $startStepNs, 0);
                $absStepLen = bccomp($stepLenNs, '0', 0) < 0 ? substr($stepLenNs, 1) : $stepLenNs;
                $progressNs = bcsub($endNsZdt, $startStepNs, 0);
                $absProgress = bccomp($progressNs, '0', 0) < 0 ? substr($progressNs, 1) : $progressNs;
                $fracStr = bccomp($absStepLen, '0', 0) === 0
                    ? '0'
                    : bcdiv($absProgress, $absStepLen, 25);
                $totalSteps = (float) ((string) $stepCount) + (float) $fracStr;
                return (float) $signZdt * $totalSteps;
            }
        }
        // Parse relativeTo as a PlainDate or PlainDateTime.
        $refDate = null;
        if ($relativeTo instanceof JsObject && $relativeTo->has('[[IsPlainDate]]')) {
            $refDate = $relativeTo;
        } elseif ($relativeTo instanceof JsObject && $relativeTo->has('[[IsPlainDateTime]]')) {
            $refDate = self::createPlainDateObject(
                self::getSlotInt($relativeTo, '[[ISOYear]]'),
                self::getSlotInt($relativeTo, '[[ISOMonth]]'),
                self::getSlotInt($relativeTo, '[[ISODay]]'),
                self::getSlotString($relativeTo, '[[Calendar]]'),
            );
        } elseif ($relativeTo instanceof JsObject && $relativeTo->has('[[IsZonedDateTime]]')) {
            $parts = self::zonedDateTimeParts($relativeTo);
            $refDate = self::createPlainDateObject(
                $parts['year'],
                $parts['month'],
                $parts['day'],
                self::getSlotString($relativeTo, '[[Calendar]]'),
            );
        } elseif ($relativeTo instanceof JsString) {
            $refDate = self::toRelativeToPlainDate($relativeTo);
        } elseif ($relativeTo instanceof JsObject) {
            $refDate = self::toRelativeToPlainDate($relativeTo);
        } else {
            throw new TypeError('relativeTo must be a Temporal object or string');
        }
        // Add only date-part of the duration to the reference date (avoids double-counting time).
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
        $endDate = self::plainDateAdd($refDate, $dateDur, 1);
        // Now compute difference in the target unit.
        $y1 = self::getSlotInt($refDate, '[[ISOYear]]');
        $m1 = self::getSlotInt($refDate, '[[ISOMonth]]');
        $d1 = self::getSlotInt($refDate, '[[ISODay]]');
        $y2 = self::getSlotInt($endDate, '[[ISOYear]]');
        $m2 = self::getSlotInt($endDate, '[[ISOMonth]]');
        $d2 = self::getSlotInt($endDate, '[[ISODay]]');
        // Add time component as fractional days.
        $timeNs = self::durationToTotalNs(
            self::createDurationObject(
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
            )
        );
        // Validate time component doesn't exceed representable range.
        $absTimeNs = bccomp($timeNs, '0', 0) < 0 ? bcsub('0', $timeNs, 0) : $timeNs;
        if (bccomp($absTimeNs, self::NS_MAX, 0) > 0) {
            throw new RangeError('Duration time component exceeds representable range');
        }
        // Validate that endDate + timeNs produces a valid epoch ns.
        $endDateNs = self::isoDateTimeToEpochNs($y2, $m2, $d2, 0, 0, 0, 0, 0, 0, 'UTC');
        $endWithTimeNs = bcadd($endDateNs, $timeNs, 0);
        if (bccomp($endWithTimeNs, self::NS_MAX, 0) > 0 || bccomp($endWithTimeNs, self::NS_MIN, 0) < 0) {
            throw new RangeError('Duration result exceeds representable range');
        }
        $fractionalDays = (float) $timeNs / 86400000000000.0;
        $jd1 = self::isoToJulianDay($y1, $m1, $d1);
        $jd2 = self::isoToJulianDay($y2, $m2, $d2);
        $totalDays = ($jd2 - $jd1) + $fractionalDays;
        if ($unit === 'day') {
            // Use exact bigint-ns division for float precision (spec: totalNs / nsPerDay).
            $jdDiffNs = bcmul((string) ($jd2 - $jd1), '86400000000000', 0);
            $totalNsExact = bcadd($jdDiffNs, $timeNs, 0);
            return (float) bcdiv($totalNsExact, '86400000000000', 25);
        }
        if ($unit === 'week') {
            $jdDiffNs = bcmul((string) ($jd2 - $jd1), '86400000000000', 0);
            $totalNsExact = bcadd($jdDiffNs, $timeNs, 0);
            return (float) bcdiv($totalNsExact, '604800000000000', 25);
        }
        if ($unit === 'month') {
            // Whole months from date diff.
            $totalMonths = ($y2 * 12 + $m2) - ($y1 * 12 + $m1);
            if ($d2 < $d1) {
                $totalMonths--;
            }
            // Per spec: fractional part is (days beyond midpoint) /
            // (days in the month spanning the midpoint through the next month
            // boundary anchored at d1). Compute midStart (refDate +
            // wholeMonths, constrained) and midEnd (refDate + wholeMonths + 1
            // month, constrained); monthLength is midEnd - midStart.
            $midTotalM = $y1 * 12 + ($m1 - 1) + $totalMonths;
            $midMY = intdiv($midTotalM, 12);
            $midMM = ($midTotalM % 12) + 1;
            $midD = min($d1, self::isoDaysInMonth($midMY, $midMM));
            $midStartJd = self::isoToJulianDay($midMY, $midMM, $midD);
            $nextTotalM = $midTotalM + 1;
            $nextMY = intdiv($nextTotalM, 12);
            $nextMM = ($nextTotalM % 12) + 1;
            $nextD = min($d1, self::isoDaysInMonth($nextMY, $nextMM));
            $midEndJd = self::isoToJulianDay($nextMY, $nextMM, $nextD);
            $monthLength = $midEndJd - $midStartJd;
            $remainDays = ($jd2 - $midStartJd) + $fractionalDays;
            return $totalMonths + ($monthLength > 0 ? $remainDays / $monthLength : 0);
        }
        if ($unit === 'year') {
            $years = $y2 - $y1;
            if ($m2 < $m1 || ($m2 === $m1 && $d2 < $d1)) {
                $years--;
            }
            // Per spec: compute remaining days / year-boundary days.
            // yearStart = ref + wholeYears.
            $ysD = min($d1, self::isoDaysInMonth($y1 + $years, $m1));
            $ysJd = self::isoToJulianDay($y1 + $years, $m1, $ysD);
            // yearEnd = ref + wholeYears + 1 year.
            $yeYear = $y1 + $years + 1;
            if ($yeYear > self::ISO_YEAR_MAX || $yeYear < self::ISO_YEAR_MIN) {
                throw new RangeError('Date outside representable range during total calculation');
            }
            $yeD = min($d1, self::isoDaysInMonth($yeYear, $m1));
            $yeJd = self::isoToJulianDay($yeYear, $m1, $yeD);
            $yearLengthDays = $yeJd - $ysJd;
            $remainDays = ($jd2 - $ysJd) + $fractionalDays;
            $frac = $yearLengthDays > 0 ? $remainDays / (float) $yearLengthDays : 0;
            return $years + $frac;
        }
        // For time units with a calendar-unit duration, compute total ns as
        // (endDate - startDate in ns) + time component, then divide by the unit.
        $jdDiffNs = bcmul((string) ($jd2 - $jd1), '86400000000000', 0);
        $totalNsExact = bcadd($jdDiffNs, $timeNs, 0);
        $unitNs = self::temporalUnitToNs($unit);
        if ($unitNs === '1') {
            return (float) $totalNsExact;
        }
        return (float) bcdiv($totalNsExact, $unitNs, 25);
    }

    private static function durationTotalNs(JsValue $dur, string $unit): float
    {
        $totalNs = self::durationToTotalNs($dur);
        $unitNs = self::temporalUnitToNs($unit);
        if ($unitNs === '1') {
            return (float) $totalNs;
        }
        // Per spec: divide the exact mathematical value, then convert to float64.
        // Use bcdiv with 25 decimal digits (well above float64's ~17) to preserve precision.
        $result = bcdiv($totalNs, $unitNs, 25);
        return (float) $result;
    }

    private static function addDurations(JsValue $a, JsValue $b, int $sign): JsObject
    {
        // Per spec: reject if either duration has years, months, or weeks.
        foreach (['years', 'months', 'weeks'] as $cu) {
            if (self::getDurationField($a, $cu) !== 0) {
                throw new RangeError("Cannot add/subtract duration with {$cu}");
            }
            if (self::getDurationField($b, $cu) !== 0) {
                throw new RangeError("Cannot add/subtract duration with {$cu}");
            }
        }
        // Determine the largest unit present in either duration (per spec: DefaultTemporalLargestUnit).
        $aDaysStr = self::getDurationFieldStr($a, 'days');
        $bDaysStr = self::getDurationFieldStr($b, 'days');
        $aDays = self::getDurationField($a, 'days');
        $bDays = self::getDurationField($b, 'days');
        $aLargest = self::defaultLargestUnit($a);
        $bLargest = self::defaultLargestUnit($b);
        $unitRanks = [
            'day' => 0, 'hour' => 1, 'minute' => 2, 'second' => 3,
            'millisecond' => 4, 'microsecond' => 5, 'nanosecond' => 6,
        ];
        $aRank = $unitRanks[$aLargest] ?? 6;
        $bRank = $unitRanks[$bLargest] ?? 6;
        $largestUnit = $aRank <= $bRank ? $aLargest : $bLargest;
        $aNs = bcadd(
            bcadd(
                bcmul(self::getDurationFieldStr($a, 'hours'), '3600000000000', 0),
                bcmul(self::getDurationFieldStr($a, 'minutes'), '60000000000', 0),
                0,
            ),
            bcadd(
                bcmul(self::getDurationFieldStr($a, 'seconds'), '1000000000', 0),
                bcadd(
                    bcmul(self::getDurationFieldStr($a, 'milliseconds'), '1000000', 0),
                    bcadd(
                        bcmul(self::getDurationFieldStr($a, 'microseconds'), '1000', 0),
                        self::getDurationFieldStr($a, 'nanoseconds'),
                        0,
                    ),
                    0,
                ),
                0,
            ),
            0,
        );
        $bNs = bcadd(
            bcadd(
                bcmul(self::getDurationFieldStr($b, 'hours'), '3600000000000', 0),
                bcmul(self::getDurationFieldStr($b, 'minutes'), '60000000000', 0),
                0,
            ),
            bcadd(
                bcmul(self::getDurationFieldStr($b, 'seconds'), '1000000000', 0),
                bcadd(
                    bcmul(self::getDurationFieldStr($b, 'milliseconds'), '1000000', 0),
                    bcadd(
                        bcmul(self::getDurationFieldStr($b, 'microseconds'), '1000', 0),
                        self::getDurationFieldStr($b, 'nanoseconds'),
                        0,
                    ),
                    0,
                ),
                0,
            ),
            0,
        );
        $totalDays = $aDays + $sign * $bDays;
        $totalNs = bcsub($aNs, bcmul((string) ($sign * -1 + 1), '0', 0), 0);
        if ($sign === 1) {
            $totalNs = bcadd($aNs, $bNs, 0);
        } else {
            $totalNs = bcsub($aNs, $bNs, 0);
        }
        // Balance ns into larger units up to the largestUnit.
        $dayNs = '86400000000000';
        $days = $totalDays;
        if ($largestUnit === 'day') {
            $extraDays = (int) bcdiv($totalNs, $dayNs, 0);
            $totalNs = bcmod($totalNs, $dayNs);
            // Ensure same sign.
            if (bccomp($totalNs, '0', 0) < 0 && ($days + $extraDays) > 0) {
                $extraDays--;
                $totalNs = bcadd($totalNs, $dayNs, 0);
            } elseif (bccomp($totalNs, '0', 0) > 0 && ($days + $extraDays) < 0) {
                $extraDays++;
                $totalNs = bcsub($totalNs, $dayNs, 0);
            }
            $days += $extraDays;
        }
        // Use nsToTimeDuration to balance the remaining nanoseconds.
        $result = self::nsToTimeDuration($totalNs, $largestUnit);
        return self::createDurationObject(
            0,
            0,
            0,
            $days,
            self::getDurationField($result, 'hours'),
            self::getDurationField($result, 'minutes'),
            self::getDurationField($result, 'seconds'),
            self::getDurationField($result, 'milliseconds'),
            self::getDurationField($result, 'microseconds'),
            self::getDurationField($result, 'nanoseconds'),
        );
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

    private static function roundDuration(
        JsValue $dur,
        string $unit,
        string $roundingMode,
        int $increment,
        string $largestUnit,
        ?JsValue $relativeTo = null,
    ): JsObject {
        $years = self::getDurationField($dur, 'years');
        $months = self::getDurationField($dur, 'months');
        $weeks = self::getDurationField($dur, 'weeks');

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
        // Per spec: if smallestUnit is larger than the resolved largestUnit,
        // bump largestUnit up to smallestUnit.
        $allUnitsRank = [
            'year' => 0, 'month' => 1, 'week' => 2, 'day' => 3,
            'hour' => 4, 'minute' => 5, 'second' => 6,
            'millisecond' => 7, 'microsecond' => 8, 'nanosecond' => 9,
        ];
        if (
            isset($allUnitsRank[$unit], $allUnitsRank[$largestUnit])
            && $allUnitsRank[$unit] < $allUnitsRank[$largestUnit]
        ) {
            $largestUnit = $unit;
        }

        // relativeTo is already resolved by the caller.

        // Calendar-aware rounding is needed when:
        // (a) the smallestUnit or largestUnit is a calendar unit (year/month/week), OR
        // (b) the duration itself has calendar units that need resolving via calendar.
        $calUnits = ['year', 'month', 'week'];
        $hasCalUnit = $years !== 0 || $months !== 0 || $weeks !== 0;
        $relIsZdt = $relativeTo instanceof JsObject
            && $relativeTo->has('[[IsZonedDateTime]]');
        $needsCalendar = in_array($unit, $calUnits, true)
            || in_array($largestUnit, $calUnits, true)
            || $hasCalUnit
            || $relIsZdt;
        if ($needsCalendar && $relativeTo !== null) {
            $refDate = $relativeTo;
            $isZdtRelativeTo = $refDate instanceof JsObject
                && $refDate->has('[[IsZonedDateTime]]');
            $zdtTzIsDst = false;
            if ($isZdtRelativeTo) {
                $tz = self::getSlotString($refDate, '[[TimeZone]]');
                $startEpochNs = self::getSlotString($refDate, '[[EpochNanoseconds]]');
                $endEpochNs = self::addDurationToZdt($refDate, $dur, 1, 'constrain');
                $zdtTzIsDst = !self::isFixedOffset($tz)
                    && self::tzHasTransitionBetween($tz, $startEpochNs, $endEpochNs);
                // plainDateAdd / plainDateDifference operate on PlainDate;
                // derive the wall-date counterpart.
                $parts = self::zonedDateTimeParts($refDate);
                $refDate = self::createPlainDateObject(
                    $parts['year'],
                    $parts['month'],
                    $parts['day'],
                    self::getSlotString($relativeTo, '[[Calendar]]'),
                );
            }
            // For DST-bearing ZDT with a non-negative duration, derive the
            // combined (days, time-remainder) from actual epoch ns rather
            // than from the input duration's hours/days at fixed 24h.
            // This is what the spec refers to as the "ZDT-aware" path:
            // wall-clock days vary in length under DST, so a 1-day input
            // may equal 23h or 25h, and a 25h input may equal 1 day exactly.
            $durSignForDst = self::durationSign($dur);
            $smallestNsForDst = self::temporalUnitToNs($unit);
            $incNsForDst = bcmul((string) $increment, $smallestNsForDst, 0);
            $isTrivialRound = bccomp($incNsForDst, '1', 0) <= 0;
            if (
                $isZdtRelativeTo
                && $zdtTzIsDst
                && $durSignForDst >= 0
                && !$isTrivialRound
            ) {
                $endParts = self::epochNsToISOParts($endEpochNs, $tz);
                $endDate = self::createPlainDateObject(
                    $endParts['year'],
                    $endParts['month'],
                    $endParts['day'],
                    self::getSlotString($relativeTo, '[[Calendar]]'),
                );
                $dateUnitsOrder = ['year', 'month', 'week', 'day'];
                $dateDiffLU = in_array($largestUnit, $dateUnitsOrder, true) ? $largestUnit : 'day';
                $dateDiffOpts = new JsObject();
                $dateDiffOpts->set('largestUnit', new JsString($dateDiffLU));
                $dateDiff = self::plainDateDifference($refDate, $endDate, $dateDiffOpts, 1);
                // Compute time remainder as (end - (start + dateDiff)) so that
                // date-line skips (e.g. Apia 2011-12-30) and DST half-hours
                // get accounted for correctly. start + dateDiff lands at the
                // calendar-equivalent wall-clock instant for that many days
                // forward, and the actual UTC delta to end is the leftover.
                if (!($relativeTo instanceof JsObject)) {
                    throw new \LogicException('isZdtRelativeTo implies JsObject');
                }
                $relZdt = $relativeTo;
                $rtAfterDates = self::addDurationToZdt($relZdt, $dateDiff, 1, 'constrain');
                $remNsBc = bcsub($endEpochNs, $rtAfterDates, 0);
                // Sign-disagreement (rare): if the calendar walk overshot,
                // back off one day and recompute. Mirrors the difference
                // logic in zonedDateTimeDifference.
                $ddSign = self::durationSign($dateDiff);
                $remSign = bccomp($remNsBc, '0', 0);
                if ($ddSign !== 0 && $remSign !== 0 && (($ddSign > 0) !== ($remSign > 0))) {
                    $adjDays = self::getDurationField($dateDiff, 'days') + ($ddSign > 0 ? -1 : 1);
                    $dateDiff = self::createDurationObject(
                        self::getDurationField($dateDiff, 'years'),
                        self::getDurationField($dateDiff, 'months'),
                        self::getDurationField($dateDiff, 'weeks'),
                        $adjDays,
                        0,
                        0,
                        0,
                        0,
                        0,
                        0,
                    );
                    $rtAfterDates = self::addDurationToZdt($relZdt, $dateDiff, 1, 'constrain');
                    $remNsBc = bcsub($endEpochNs, $rtAfterDates, 0);
                }
                $remTimeFromNs = self::nsToTimeDuration($remNsBc, 'hour');
                $combined = self::createDurationObject(
                    self::getDurationField($dateDiff, 'years'),
                    self::getDurationField($dateDiff, 'months'),
                    self::getDurationField($dateDiff, 'weeks'),
                    self::getDurationField($dateDiff, 'days'),
                    self::getDurationField($remTimeFromNs, 'hours'),
                    self::getDurationField($remTimeFromNs, 'minutes'),
                    self::getDurationField($remTimeFromNs, 'seconds'),
                    self::getDurationField($remTimeFromNs, 'milliseconds'),
                    self::getDurationField($remTimeFromNs, 'microseconds'),
                    self::getDurationField($remTimeFromNs, 'nanoseconds'),
                );
                return self::roundCalendarDuration($combined, $unit, $roundingMode, $increment, $largestUnit, $relativeTo);
            }
            if ($isZdtRelativeTo && $zdtTzIsDst) {
                $extraDays = 0;
                $remTime = self::createDurationObject(
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
            } else {
                $tNsBc = self::durationToTotalNs(self::createDurationObject(
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
                ));
                $dayNsBc = '86400000000000';
                $tsign = bccomp($tNsBc, '0', 0) < 0 ? -1 : 1;
                $absTNs = $tsign < 0 ? substr($tNsBc, 1) : $tNsBc;
                $extraDays = (int) bcdiv($absTNs, $dayNsBc, 0);
                $remTNs = bcsub($absTNs, bcmul((string) $extraDays, $dayNsBc, 0), 0);
                $extraDays *= $tsign;
                $remTime = self::nsToTimeDuration($tsign < 0 ? '-' . $remTNs : $remTNs, 'hour');
            }
            // Date-only duration with time-balanced extra days.
            $dateDur = self::createDurationObject(
                self::getDurationField($dur, 'years'),
                self::getDurationField($dur, 'months'),
                self::getDurationField($dur, 'weeks'),
                self::getDurationField($dur, 'days') + $extraDays,
                0,
                0,
                0,
                0,
                0,
                0,
            );
            $endDate = self::plainDateAdd($refDate, $dateDur, 1);
            // plainDateDifference only supports date units, so cap at day.
            $dateUnitsOrder = ['year', 'month', 'week', 'day'];
            $dateDiffLU = in_array($largestUnit, $dateUnitsOrder, true) ? $largestUnit : 'day';
            $dateDiffOpts = new JsObject();
            $dateDiffOpts->set('largestUnit', new JsString($dateDiffLU));
            $dateDiff = self::plainDateDifference($refDate, $endDate, $dateDiffOpts, 1);
            // Combine date diff with remainder time.
            $combined = self::createDurationObject(
                self::getDurationField($dateDiff, 'years'),
                self::getDurationField($dateDiff, 'months'),
                self::getDurationField($dateDiff, 'weeks'),
                self::getDurationField($dateDiff, 'days'),
                self::getDurationField($remTime, 'hours'),
                self::getDurationField($remTime, 'minutes'),
                self::getDurationField($remTime, 'seconds'),
                self::getDurationField($remTime, 'milliseconds'),
                self::getDurationField($remTime, 'microseconds'),
                self::getDurationField($remTime, 'nanoseconds'),
            );
            // Pass the original ZDT (when present) so DST-aware logic can act.
            // Fixed-offset zones don't need DST treatment; pass PlainDate to
            // keep the existing behavior.
            $refForRound = ($isZdtRelativeTo && $zdtTzIsDst) ? $relativeTo : $refDate;
            return self::roundCalendarDuration($combined, $unit, $roundingMode, $increment, $largestUnit, $refForRound);
        }

        // Time-only: convert to ns, round, redistribute.
        $totalNs = self::durationToTotalNs($dur);
        $unitNs = self::temporalUnitToNs($unit);
        $incNs = bcmul((string) $increment, $unitNs, 0);

        if ($incNs !== '0') {
            $totalNs = self::roundNs($totalNs, $incNs, $roundingMode);
        }

        return self::nsToTimeDuration($totalNs, $largestUnit);
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
        // Divide and compute remainder using bcmath for exact arithmetic.
        // Values are stored as float in the Duration object (per spec, converted to float64).
        $divRem = function (string $rem, string $divisor): array {
            $qStr = bcdiv($rem, $divisor, 0);
            $newRem = bcsub($rem, bcmul($qStr, $divisor, 0), 0);
            return [(float) $qStr, $newRem];
        };

        $days = 0.0;
        $hours = 0.0;
        $minutes = 0.0;
        $seconds = 0.0;
        $milliseconds = 0.0;
        $microseconds = 0.0;
        $nanoseconds = 0.0;

        $rem = $abs;
        if (in_array($largestUnit, ['year', 'month', 'week', 'day'], true)) {
            [$days, $rem] = $divRem($rem, '86400000000000');
        }
        if (in_array($largestUnit, ['year', 'month', 'week', 'day', 'hour'], true)) {
            [$hours, $rem] = $divRem($rem, '3600000000000');
        }
        if (in_array($largestUnit, ['year', 'month', 'week', 'day', 'hour', 'minute'], true)) {
            [$minutes, $rem] = $divRem($rem, '60000000000');
        }
        if (in_array($largestUnit, ['year', 'month', 'week', 'day', 'hour', 'minute', 'second'], true)) {
            [$seconds, $rem] = $divRem($rem, '1000000000');
        }
        if (in_array($largestUnit, ['year', 'month', 'week', 'day', 'hour', 'minute', 'second', 'millisecond'], true)) {
            [$milliseconds, $rem] = $divRem($rem, '1000000');
        }
        if (in_array($largestUnit, ['year', 'month', 'week', 'day', 'hour', 'minute', 'second', 'millisecond', 'microsecond'], true)) {
            [$microseconds, $rem] = $divRem($rem, '1000');
        }
        $nanoseconds = (float) $rem;

        // Avoid negative zero: $sign * 0.0 produces -0.0 which is incorrect for duration fields.
        $sv = fn ($v) => $v == 0 ? 0 : $sign * $v;
        return self::createDurationObject(
            $sv($years),
            $sv($months),
            $sv($weeks),
            $sv($days),
            $sv($hours),
            $sv($minutes),
            $sv($seconds),
            $sv($milliseconds),
            $sv($microseconds),
            $sv($nanoseconds),
        );
    }

    // -----------------------------------------------------------------------
    // Helpers: type conversion
    // -----------------------------------------------------------------------

    private static function toPlainDate(JsValue $item, string $overflow = 'constrain', ?JsValue $rawOptions = null): JsObject
    {
        if ($item instanceof JsObject) {
            if (
                $item->has('[[ISOYear]]') && !$item->has('[[IsPlainTime]]') && !$item->has('[[IsPlainDateTime]]')
                && !$item->has('[[IsPlainYearMonth]]') && !$item->has('[[IsPlainMonthDay]]')
                && !$item->has('[[IsZonedDateTime]]') && !$item->has('[[IsDuration]]') && !$item->has('[[EpochNanoseconds]]')
            ) {
                return self::createPlainDateObject(
                    self::getSlotInt($item, '[[ISOYear]]'),
                    self::getSlotInt($item, '[[ISOMonth]]'),
                    self::getSlotInt($item, '[[ISODay]]'),
                    self::getSlotString($item, '[[Calendar]]'),
                );
            }
            if ($item->has('[[IsPlainDateTime]]')) {
                return self::createPlainDateObject(
                    self::getSlotInt($item, '[[ISOYear]]'),
                    self::getSlotInt($item, '[[ISOMonth]]'),
                    self::getSlotInt($item, '[[ISODay]]'),
                    self::getSlotString($item, '[[Calendar]]'),
                );
            }
            if ($item->has('[[IsZonedDateTime]]')) {
                $parts = self::zonedDateTimeParts($item);
                return self::createPlainDateObject(
                    $parts['year'],
                    $parts['month'],
                    $parts['day'],
                    self::getSlotString($item, '[[Calendar]]'),
                );
            }
            // Property bag: read and convert fields in ALPHABETICAL order per spec.
            // Each field is get + valueOf/toString immediately.
            $calVal = $item->get('calendar');
            $cal = 'iso8601';
            if (!($calVal instanceof JsUndefined)) {
                $cal = self::toCalendarSlotValue($calVal);
            }
            $dayVal = $item->get('day');
            if ($dayVal instanceof JsUndefined) {
                throw new TypeError('missing required property: day');
            }
            $dNum = TypeConversion::toNumber($dayVal);
            // era/eraYear (non-ISO calendars only).
            $eraStr = null;
            $eraYearNum = null;
            $eraSet = false;
            $eraYearSet = false;
            if ($cal !== 'iso8601') {
                $eraVal = $item->get('era');
                if (!($eraVal instanceof JsUndefined)) {
                    $eraSet = true;
                    $eraStr = TypeConversion::toString($eraVal);
                }
                $eraYearVal = $item->get('eraYear');
                if (!($eraYearVal instanceof JsUndefined)) {
                    $eraYearSet = true;
                    $eraYearNum = TypeConversion::toNumber($eraYearVal);
                    if (is_nan($eraYearNum) || !is_finite($eraYearNum)) {
                        throw new RangeError('eraYear must be finite');
                    }
                    if (floor($eraYearNum) !== $eraYearNum) {
                        throw new RangeError('eraYear must be an integer');
                    }
                }
                // For calendars that use eras (gregory, japanese,
                // roc, etc.), the two fields must be both present
                // or both absent.
                static $erasUseEras = ['gregory', 'japanese', 'roc'];
                if (in_array($cal, $erasUseEras, true) && $eraSet !== $eraYearSet) {
                    throw new TypeError(
                        'era and eraYear must be provided together',
                    );
                }
            }
            $monthVal = $item->get('month');
            $hasMonth = !($monthVal instanceof JsUndefined);
            $mNum = $hasMonth ? TypeConversion::toNumber($monthVal) : null;
            $monthCodeVal = $item->get('monthCode');
            $hasMonthCode = !($monthCodeVal instanceof JsUndefined);
            $mcStr = $hasMonthCode ? TypeConversion::toString($monthCodeVal) : null;
            if ($hasMonthCode) {
                self::parseMonthCodeSyntax($mcStr);
            }
            $yearVal = $item->get('year');
            if ($yearVal instanceof JsUndefined) {
                static $pdEraCals = [
                    'gregory', 'japanese', 'roc',
                    'coptic', 'ethiopic', 'ethioaa',
                ];
                if (
                    $eraYearNum !== null
                    && in_array($cal, $pdEraCals, true)
                ) {
                    $eraLower = $eraStr === null ? '' : strtolower($eraStr);
                    if ($cal === 'japanese') {
                        $isoYear = self::japaneseEraToIsoYear($eraLower, (int) $eraYearNum);
                        if ($isoYear === null) {
                            // Unrecognized era: treat as gregory-style.
                            $yNum = in_array($eraLower, ['bc', 'bce', 'japanese-inverse'], true)
                                ? (1 - $eraYearNum)
                                : $eraYearNum;
                        } else {
                            $yNum = (float) $isoYear;
                        }
                    } elseif ($cal === 'roc') {
                        // ROC year fields are calendar-relative (year 1 = ISO
                        // 1912). For era="roc", the calendar year is the era
                        // year directly. For "roc-inverse"/"before-roc",
                        // calendar year 0 = ISO 1911 = before-roc year 1.
                        if ($eraLower === 'roc-inverse' || $eraLower === 'before-roc') {
                            $yNum = 1 - $eraYearNum;
                        } else {
                            $yNum = $eraYearNum;
                        }
                    } elseif ($cal === 'gregory') {
                        $yNum = in_array($eraLower, ['bc', 'bce', 'gregory-inverse'], true)
                            ? (1 - $eraYearNum)
                            : $eraYearNum;
                    } else {
                        // Other calendars (coptic, ethiopic, islamic, etc.)
                        // pass eraYear through as the calendar year, with
                        // -inverse / -bc style flipping the sign.
                        $yNum = (
                            $eraLower !== ''
                            && (str_ends_with($eraLower, '-inverse')
                                || in_array($eraLower, ['bc', 'bce', 'before-roc'], true))
                        )
                            ? (1 - $eraYearNum)
                            : $eraYearNum;
                    }
                } else {
                    throw new TypeError('missing required property: year');
                }
            } else {
                $yNum = TypeConversion::toNumber($yearVal);
            }
            // Now validate and resolve fields.
            if (!$hasMonth && !$hasMonthCode) {
                throw new TypeError('missing required property: month');
            }
            if (!is_finite($yNum)) {
                throw new RangeError('year must be finite');
            }
            if ($yNum === 0.0 && (unpack("H*", pack("d", $yNum))[1] ?? "") === "0000000000000080") {
                throw new RangeError('reject minus zero as extended year');
            }
            $y = (int) $yNum;
            if ($hasMonthCode) {
                $mcMonth = self::parseMonthCode($mcStr, $cal);
                if ($hasMonth) {
                    if (!is_finite($mNum)) {
                        throw new RangeError('month must be finite');
                    }
                    // For lunisolar calendars, the digit in the monthCode
                    // and the chronological month diverge once a leap
                    // month sits earlier in the year. Resolve the
                    // monthCode against the year and compare against the
                    // user's chronological month.
                    if (in_array($cal, ['hebrew', 'chinese', 'dangi'], true)) {
                        $resolved = self::calendarPartsToIso($cal, $y, $mcStr, null, 1);
                        if ($resolved !== null) {
                            $back = self::isoToCalendarParts(
                                $cal,
                                $resolved['year'],
                                $resolved['month'],
                                $resolved['day'],
                            );
                            if (
                                $back !== null
                                && $back['monthCode'] === $mcStr
                                && (int) $mNum !== $back['month']
                            ) {
                                throw new RangeError('month and monthCode must agree');
                            }
                        }
                    } elseif ((int) $mNum !== $mcMonth) {
                        throw new RangeError('month and monthCode must agree');
                    }
                }
                $m = $mcMonth;
            } else {
                if (!is_finite($mNum)) {
                    throw new RangeError('month must be finite');
                }
                $m = (int) $mNum;
            }
            if (!is_finite($dNum)) {
                throw new RangeError('day must be finite');
            }
            $d = (int) $dNum;
            if ($rawOptions !== null) {
                $options = self::getOptionsObject($rawOptions);
                $overflow = self::getOverflow($options);
            }
            // For non-ISO calendars, convert calendar-native (year, month, day)
            // to ISO via ICU before storing.
            if ($cal !== 'iso8601' && !in_array($cal, ['gregory', 'roc', 'japanese'], true)) {
                // Constrain day to the calendar month's max so ICU doesn't
                // silently roll over (e.g. hebrew Cheshvan 30 in a 29-day
                // year would otherwise become Kislev 1).
                if ($overflow === 'constrain') {
                    $maxD = self::calendarDaysInMonth(
                        $cal,
                        $y,
                        $hasMonthCode ? $mcStr : null,
                        $hasMonthCode ? null : $m,
                    );
                    if ($maxD !== null && $d > $maxD) {
                        $d = $maxD;
                    }
                } else {
                    $maxD = self::calendarDaysInMonth(
                        $cal,
                        $y,
                        $hasMonthCode ? $mcStr : null,
                        $hasMonthCode ? null : $m,
                    );
                    if ($maxD !== null && $d > $maxD) {
                        throw new RangeError(
                            "Invalid day {$d} for calendar '{$cal}' month",
                        );
                    }
                }
                $isoParts = self::calendarPartsToIso($cal, $y, $hasMonthCode ? $mcStr : null, $hasMonthCode ? null : $m, $d);
                if ($isoParts !== null) {
                    return self::createPlainDateObject($isoParts['year'], $isoParts['month'], $isoParts['day'], $cal);
                }
                // calendarPartsToIso couldn't form a valid date in this
                // calendar (e.g. invalid leap monthCode like M01L for
                // hebrew). Reject under "reject", and also reject for
                // "constrain" since silently falling back to ISO would
                // produce a date that doesn't even exist in the requested
                // calendar.
                if ($overflow === 'reject' || $hasMonthCode) {
                    throw new RangeError(
                        "Invalid date components for calendar '{$cal}'",
                    );
                }
            }
            // Translate roc calendar-year to ISO year. The calendarPartsToIso
            // path handles non-iso/non-gregory calendars; gregory and
            // japanese map year directly to ISO. ROC is the exception:
            // year fields are 1-based from 1912 ("民國" year 1).
            if ($cal === 'roc') {
                $y += 1911;
            }
            if ($overflow === 'constrain') {
                [$y, $m, $d] = self::constrainISODate($y, $m, $d);
            } else {
                self::validateISODate($y, $m, $d);
            }
            return self::createPlainDateObject($y, $m, $d, $cal);
        }
        // Per spec: reject non-string, non-object types directly.
        if ($item instanceof JsUndefined || $item instanceof JsNull) {
            throw new TypeError('Cannot convert undefined/null to a Temporal.PlainDate');
        }
        if ($item instanceof \Phasis\Value\JsSymbol) {
            throw new TypeError('Cannot convert a Symbol to a Temporal.PlainDate');
        }
        if ($item instanceof JsNumber || $item instanceof \Phasis\Value\JsBigInt) {
            throw new TypeError('Cannot convert a number/BigInt to a Temporal.PlainDate');
        }
        if ($item instanceof JsBoolean) {
            throw new TypeError('Cannot convert a boolean to a Temporal.PlainDate');
        }
        $str = TypeConversion::toString($item);
        return self::parsePlainDateString($str);
    }

    private static function parsePlainDateString(string $str): JsObject
    {
        [$str, $cal] = self::normalizeTemporalString($str);
        // Reject UTC designator (Z) for PlainDate.
        $noAnnot = preg_replace('/\[.*?\]/', '', $str);
        if (preg_match('/[Zz]/', $noAnnot)) {
            throw new RangeError(
                "String with UTC designator should not be valid as a PlainDate"
            );
        }
        // Reject -000000 (minus zero year).
        if (preg_match('/^-0{4,6}[-\d]/', $str)) {
            throw new RangeError("reject minus zero as extended year: {$str}");
        }
        // Reject extended year without sign (5+ digits with dash = year).
        if (preg_match('/^\d{5,}-/', $str)) {
            throw new RangeError("Extended year requires + or - prefix: {$str}");
        }
        // YYYY-MM-DD or YYYYMMDD with optional time, offset, and annotations.
        // UTC offset accepts hh / hh:mm / hh:mm:ss / hh:mm:ss.sss (extended or basic).
        $offsetOpt = '(?:[+-]\d{2}(?::?\d{2}(?::?\d{2}(?:[.,]\d{1,9})?)?)?)?';
        $timeOpt = "(?:[Tt ](\d{2})(?::?(\d{2})(?::?(\d{2})(?:[.,]\d{1,9})?)?)?{$offsetOpt})?";
        $pattern = "/^([+-]?\\d{4,6})-?(\\d{2})-?(\\d{2}){$timeOpt}(?:\\[.*?\\])*\$/";
        if (!preg_match($pattern, $str, $m)) {
            throw new RangeError("Invalid PlainDate string: {$str}");
        }
        $y = (int) $m[1];
        $m2 = (int) $m[2];
        $d = (int) $m[3];
        // Validate time part if captured.
        if (isset($m[4]) && $m[4] !== '') {
            $th = (int) $m[4];
            $tmin = isset($m[5]) ? (int) $m[5] : 0;
            $ts = isset($m[6]) ? (int) $m[6] : 0;
            if ($ts === 60) {
                $ts = 59;
            } // leap second
            if ($th > 23 || $tmin > 59 || $ts > 59) {
                throw new RangeError("Invalid time in string: {$str}");
            }
        }
        self::validateISODate($y, $m2, $d);
        // Validate calendar if not default.
        if ($cal !== 'iso8601' && !self::isValidCalendar($cal)) {
            throw new RangeError("Invalid calendar: {$cal}");
        }
        return self::createPlainDateObject($y, $m2, $d, $cal);
    }

    /**
     * Parse a relativeTo value into a PlainDate.
     * Strings with bracketed IANA annotation are parsed as ZonedDateTime then date extracted.
     * Z offset without IANA annotation is rejected.
     */
    /** Side-channel: when toRelativeToPlainDate sees a timeZone in the
     * property bag, it stashes the corresponding ZDT here for the caller. */
    private static ?JsObject $relativeToZdtCache = null;

    private static function toRelativeToPlainDate(JsValue $item): JsObject
    {
        self::$relativeToZdtCache = null;
        if ($item instanceof JsObject) {
            if (
                $item->has('[[IsPlainDate]]') || $item->has('[[IsPlainDateTime]]')
                || $item->has('[[IsZonedDateTime]]') || $item->has('[[ISOYear]]')
            ) {
                return self::toPlainDate($item);
            }
            // Property bag: per spec, read and convert all fields in alphabetical order.
            // calendar
            $calVal = $item->get('calendar');
            $cal = 'iso8601';
            if (!($calVal instanceof JsUndefined)) {
                $cal = self::toCalendarSlotValue($calVal);
            }
            // day (with valueOf)
            $dayVal = $item->get('day');
            $dNum = NAN;
            if (!($dayVal instanceof JsUndefined)) {
                $dNum = TypeConversion::toNumber($dayVal);
                if (!is_finite($dNum)) {
                    throw new RangeError("day must be finite");
                }
            }
            // era / eraYear (non-ISO calendars only).
            $eraStr = null;
            $eraYearNum = null;
            $eraSet = false;
            $eraYearSet = false;
            if ($cal !== 'iso8601') {
                $eraVal = $item->get('era');
                if (!($eraVal instanceof JsUndefined)) {
                    $eraSet = true;
                    $eraStr = TypeConversion::toString($eraVal);
                }
                $eraYearVal = $item->get('eraYear');
                if (!($eraYearVal instanceof JsUndefined)) {
                    $eraYearSet = true;
                    $eraYearNum = TypeConversion::toNumber($eraYearVal);
                    if (is_nan($eraYearNum) || !is_finite($eraYearNum)) {
                        throw new RangeError('eraYear must be finite');
                    }
                    if (floor($eraYearNum) !== $eraYearNum) {
                        throw new RangeError('eraYear must be an integer');
                    }
                }
                static $relErasUseEras = ['gregory', 'japanese', 'roc'];
                if (in_array($cal, $relErasUseEras, true) && $eraSet !== $eraYearSet) {
                    throw new TypeError('era and eraYear must be provided together');
                }
            }
            // hour
            $hourVal = $item->get('hour');
            $hourInt = 0;
            if (!($hourVal instanceof JsUndefined)) {
                $hn = TypeConversion::toNumber($hourVal);
                if (!is_finite($hn)) {
                    throw new RangeError("hour must be finite");
                }
                $hourInt = (int) $hn;
            }
            // microsecond
            $microVal = $item->get('microsecond');
            $microInt = 0;
            if (!($microVal instanceof JsUndefined)) {
                $n = TypeConversion::toNumber($microVal);
                if (!is_finite($n)) {
                    throw new RangeError("microsecond must be finite");
                }
                $microInt = (int) $n;
            }
            // millisecond
            $milliVal = $item->get('millisecond');
            $milliInt = 0;
            if (!($milliVal instanceof JsUndefined)) {
                $n = TypeConversion::toNumber($milliVal);
                if (!is_finite($n)) {
                    throw new RangeError("millisecond must be finite");
                }
                $milliInt = (int) $n;
            }
            // minute
            $minVal = $item->get('minute');
            $minInt = 0;
            if (!($minVal instanceof JsUndefined)) {
                $n = TypeConversion::toNumber($minVal);
                if (!is_finite($n)) {
                    throw new RangeError("minute must be finite");
                }
                $minInt = (int) $n;
            }
            // month (with valueOf)
            $monthVal = $item->get('month');
            $mNum = NAN;
            if (!($monthVal instanceof JsUndefined)) {
                $mNum = TypeConversion::toNumber($monthVal);
                if (!is_finite($mNum)) {
                    throw new RangeError("month must be finite");
                }
            }
            // monthCode (with toString)
            $mcVal = $item->get('monthCode');
            $mc = null;
            if (!($mcVal instanceof JsUndefined)) {
                $mc = TypeConversion::toString($mcVal);
            }
            // nanosecond
            $nanoVal = $item->get('nanosecond');
            $nanoInt = 0;
            if (!($nanoVal instanceof JsUndefined)) {
                $n = TypeConversion::toNumber($nanoVal);
                if (!is_finite($n)) {
                    throw new RangeError("nanosecond must be finite");
                }
                $nanoInt = (int) $n;
            }
            // offset: convert with ToString inline per spec.
            $offsetVal = $item->get('offset');
            $offStr = null;
            if (!($offsetVal instanceof JsUndefined)) {
                if (
                    $offsetVal instanceof JsNull || $offsetVal instanceof JsNumber
                    || $offsetVal instanceof JsBoolean || $offsetVal instanceof \Phasis\Value\JsBigInt
                ) {
                    throw new TypeError("offset must be a string");
                }
                if ($offsetVal instanceof \Phasis\Value\JsSymbol) {
                    throw new TypeError("Cannot convert a Symbol to a string");
                }
                $offStr = ($offsetVal instanceof JsString) ? $offsetVal->value : TypeConversion::toString($offsetVal);
            }
            // second
            $secVal = $item->get('second');
            $secInt = 0;
            if (!($secVal instanceof JsUndefined)) {
                $n = TypeConversion::toNumber($secVal);
                if (!is_finite($n)) {
                    throw new RangeError("second must be finite");
                }
                $secInt = (int) $n;
            }
            // timeZone
            $tzVal = $item->get('timeZone');
            // year (with valueOf)
            $yearVal = $item->get('year');
            $yNum = NAN;
            if (!($yearVal instanceof JsUndefined)) {
                $yNum = TypeConversion::toNumber($yearVal);
                if (!is_finite($yNum)) {
                    throw new RangeError("year must be finite");
                }
            }
            // Validate timeZone if present.
            $hasTz = !($tzVal instanceof JsUndefined);
            if ($hasTz) {
                self::toTemporalTimeZoneIdentifier($tzVal);
                // Validate offset format (already converted above).
                if ($offStr !== null) {
                    if (!preg_match('/^[+-]\d{2}(?::?\d{2}(?::?\d{2}(?:\.\d{1,9})?)?)?$/', $offStr)) {
                        throw new RangeError("{$offStr} is not a valid offset string");
                    }
                }
            }
            // Required: year (or era+eraYear), day (and month or monthCode).
            if ($yearVal instanceof JsUndefined) {
                static $relEraDeriv = ['gregory', 'japanese', 'roc'];
                if ($eraYearNum !== null && in_array($cal, $relEraDeriv, true)) {
                    $eraLower = $eraStr === null ? '' : strtolower($eraStr);
                    $yNum = in_array($eraLower, ['bc', 'bce'], true)
                        ? (1 - $eraYearNum)
                        : $eraYearNum;
                } else {
                    throw new TypeError('missing required property: year');
                }
            }
            if ($dayVal instanceof JsUndefined) {
                throw new TypeError('missing required property: day');
            }
            // Resolve month from month or monthCode.
            if ($monthVal instanceof JsUndefined) {
                if ($mc === null) {
                    throw new TypeError('missing required property: month');
                }
                $mNum = (float) self::parseMonthCode($mc);
            } elseif ($mc !== null) {
                $mcMonth = self::parseMonthCode($mc);
                if ($mcMonth !== (int) $mNum) {
                    throw new RangeError("month and monthCode must agree");
                }
            }
            $y = (int) $yNum;
            $m = (int) $mNum;
            $d = (int) $dNum;
            // Check for -0 year.
            if ($yNum === 0.0 && (unpack("H*", pack("d", $yNum))[1] ?? "") === "0000000000000080") {
                throw new RangeError('reject minus zero as extended year');
            }
            [$y, $m, $d] = self::constrainISODate($y, $m, $d);
            // If timeZone present, validate the offset (no fuzzy match in property
            // bags) and stash the resulting ZDT in the side-channel cache so the
            // caller can promote relativeTo to a ZDT without re-reading fields.
            if ($hasTz) {
                $timeZone = self::toTemporalTimeZoneIdentifier($tzVal);
                $epochFromWall = self::isoDateTimeToEpochNs($y, $m, $d, $hourInt, $minInt, $secInt, $milliInt, $microInt, $nanoInt, $timeZone);
                if ($offStr !== null) {
                    $actualOffsetNs = self::getUtcOffsetNsForTimestamp($timeZone, $epochFromWall);
                    $givenOffsetNs = self::parseOffsetToNs($offStr);
                    if ($givenOffsetNs !== $actualOffsetNs) {
                        throw new RangeError("offset property \"{$offStr}\" does not match time zone \"{$timeZone}\"");
                    }
                }
                self::$relativeToZdtCache = self::createZonedDateTimeObject($epochFromWall, $timeZone, $cal);
            }
            return self::createPlainDateObject($y, $m, $d, $cal);
        }
        // Reject non-string, non-object primitives per spec.
        if ($item instanceof JsUndefined || $item instanceof JsNull) {
            throw new TypeError('Cannot convert undefined/null to relativeTo');
        }
        if ($item instanceof \Phasis\Value\JsSymbol) {
            throw new TypeError('Cannot convert a Symbol to relativeTo');
        }
        if ($item instanceof JsNumber || $item instanceof \Phasis\Value\JsBigInt) {
            throw new TypeError('Cannot convert a number/BigInt to relativeTo');
        }
        if ($item instanceof JsBoolean) {
            throw new TypeError('Cannot convert a boolean to relativeTo');
        }
        if ($item instanceof JsString) {
            $str = $item->value;
        } else {
            $str = TypeConversion::toString($item);
        }
        // Check for bracketed timezone annotation -> ZonedDateTime -> extract date.
        if (preg_match('/\[([^\]=]+)\]/', $str, $annMatch)) {
            $ann = $annMatch[1];
            if (!str_contains($ann, '=')) {
                $zdt = self::parseZonedDateTimeString($str);
                $parts = self::zonedDateTimeParts($zdt);
                return self::createPlainDateObject($parts['year'], $parts['month'], $parts['day'], 'iso8601');
            }
        }
        // No bracketed tz annotation: check for Z offset -> RangeError.
        $noAnnot = preg_replace('/\[.*?\]/', '', $str);
        if (preg_match('/[Zz]/', $noAnnot)) {
            throw new RangeError("date-time + Z throws without an IANA annotation");
        }
        return self::parsePlainDateString($str);
    }

    private static function toPlainTime(
        JsValue $item,
        string $overflow = 'constrain',
    ): JsObject {
        if ($item instanceof JsUndefined || $item instanceof JsNull) {
            throw new TypeError('Cannot convert undefined/null to PlainTime');
        }
        if ($item instanceof JsObject && $item->has('[[IsPlainTime]]')) {
            // Return a copy, not the same object.
            return self::createPlainTimeObject(
                self::getSlotInt($item, '[[ISOHour]]'),
                self::getSlotInt($item, '[[ISOMinute]]'),
                self::getSlotInt($item, '[[ISOSecond]]'),
                self::getSlotInt($item, '[[ISOMillisecond]]'),
                self::getSlotInt($item, '[[ISOMicrosecond]]'),
                self::getSlotInt($item, '[[ISONanosecond]]'),
            );
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
        if ($item instanceof JsObject && $item->has('[[IsZonedDateTime]]')) {
            $parts = self::zonedDateTimeParts($item);
            return self::createPlainTimeObject(
                $parts['hour'],
                $parts['minute'],
                $parts['second'],
                $parts['millisecond'],
                $parts['microsecond'],
                $parts['nanosecond'],
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
            // Read in alphabetical order per spec.
            $tBag = [
                'hour' => &$h, 'microsecond' => &$us,
                'millisecond' => &$ms, 'minute' => &$min,
                'nanosecond' => &$ns, 'second' => &$s,
            ];
            foreach ($tBag as $name => &$ref) {
                $v = $item->get($name);
                if (!($v instanceof JsUndefined)) {
                    $n = TypeConversion::toNumber($v);
                    if (!is_finite($n)) {
                        throw new RangeError("{$name} must be finite");
                    }
                    $ref = (int) $n;
                    $any = true;
                }
            }
            unset($ref);
            if (!$any) {
                throw new TypeError(
                    'missing required time property'
                );
            }
            if ($overflow === 'constrain') {
                $h = max(0, min(23, $h));
                $min = max(0, min(59, $min));
                $s = max(0, min(59, $s));
                $ms = max(0, min(999, $ms));
                $us = max(0, min(999, $us));
                $ns = max(0, min(999, $ns));
            } else {
                self::validateISOTime($h, $min, $s, $ms, $us, $ns);
            }
            return self::createPlainTimeObject($h, $min, $s, $ms, $us, $ns);
        }
        if ($item instanceof JsNumber || $item instanceof \Phasis\Value\JsBigInt) {
            throw new TypeError('Cannot convert number to Temporal.PlainTime');
        }
        if ($item instanceof JsBoolean) {
            throw new TypeError('Cannot convert boolean to Temporal.PlainTime');
        }
        if ($item instanceof \Phasis\Value\JsSymbol) {
            throw new TypeError('Cannot convert Symbol to Temporal.PlainTime');
        }
        $str = TypeConversion::toString($item);
        return self::parsePlainTimeString($str);
    }

    /** Create PlainTime from property bag with correct fields-before-options ordering. */
    private static function toPlainTimeFromBag(JsObject $item, JsValue $rawOpts): JsObject
    {
        $h = 0;
        $min = 0;
        $s = 0;
        $ms = 0;
        $us = 0;
        $ns = 0;
        $any = false;
        $tBag = [
            'hour' => &$h, 'microsecond' => &$us,
            'millisecond' => &$ms, 'minute' => &$min,
            'nanosecond' => &$ns, 'second' => &$s,
        ];
        foreach ($tBag as $name => &$ref) {
            $v = $item->get($name);
            if (!($v instanceof JsUndefined)) {
                $n = TypeConversion::toNumber($v);
                if (!is_finite($n)) {
                    throw new RangeError("{$name} must be finite");
                }
                $ref = (int) $n;
                $any = true;
            }
        }
        unset($ref);
        if (!$any) {
            throw new TypeError('missing required time property');
        }
        // Read options AFTER fields per spec.
        $options = self::getOptionsObject($rawOpts);
        $overflow = self::getOverflow($options);
        if ($overflow === 'constrain') {
            $h = max(0, min(23, $h));
            $min = max(0, min(59, $min));
            $s = max(0, min(59, $s));
            $ms = max(0, min(999, $ms));
            $us = max(0, min(999, $us));
            $ns = max(0, min(999, $ns));
        } else {
            self::validateISOTime($h, $min, $s, $ms, $us, $ns);
        }
        return self::createPlainTimeObject($h, $min, $s, $ms, $us, $ns);
    }

    private static function parsePlainTimeString(string $str): JsObject
    {
        [$str] = self::normalizeTemporalString($str);
        // Reject UTC designator (Z) for PlainTime.
        $noAnnot = preg_replace('/\[.*?\]/', '', $str);
        if (preg_match('/[Zz]/', $noAnnot)) {
            throw new RangeError(
                "String with UTC designator should not be valid as PlainTime"
            );
        }
        // Reject -000000 (minus zero year).
        if (preg_match('/^-0{4,6}[-\d]/', $str)) {
            throw new RangeError("reject minus zero as extended year: {$str}");
        }
        // Reject space-prefixed strings (space is not a substitute for T).
        if (preg_match('/^ /', $str)) {
            throw new RangeError("space is not accepted as a substitute for T prefix: '{$str}'");
        }
        // Strip annotations for ambiguity checking.
        $noAnnot2 = preg_replace('/(?:\[.*?\])+$/', '', $str);
        $hasT = (bool) preg_match('/^[Tt]/', $noAnnot2);
        // Reject ambiguous strings (could be date-like) without T prefix.
        // Per spec: YYYY-MM, MMDD, MM-DD, YYYYMM are ambiguous with time.
        // Check on annotation-stripped string BEFORE offset stripping.
        if (!$hasT) {
            // YYYY-MM or YYYY-MM[ann]: ambiguous only if MM is valid month.
            if (preg_match('/^(\d{4})-(\d{2})(?:$|\[)/', $noAnnot2, $ambM)) {
                $ambMonth = (int) $ambM[2];
                if ($ambMonth >= 1 && $ambMonth <= 12) {
                    throw new RangeError("'{$str}' is ambiguous and requires T prefix");
                }
            }
            // MM-DD or MM-DD[ann]: ambiguous only if MM is valid month.
            if (preg_match('/^(\d{2})-(\d{2})(?:$|\[)/', $noAnnot2, $ambMD)) {
                $ambMM = (int) $ambMD[1];
                if ($ambMM >= 1 && $ambMM <= 12) {
                    throw new RangeError("'{$str}' is ambiguous and requires T prefix");
                }
            }
            // MMDD (4-digit): ambiguous if it could be a valid date.
            if (preg_match('/^(\d{4})(?:$|\[)/', $noAnnot2, $amb4)) {
                $mmCandidate = (int) substr($amb4[1], 0, 2);
                $ddCandidate = (int) substr($amb4[1], 2, 2);
                // Max days per month (including leap year for Feb).
                $maxDays = [0, 31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
                if (
                    $mmCandidate >= 1 && $mmCandidate <= 12
                    && $ddCandidate >= 1 && $ddCandidate <= $maxDays[$mmCandidate]
                ) {
                    throw new RangeError("'{$str}' is ambiguous and requires T prefix");
                }
            }
            // YYYYMM (6-digit): ambiguous if last 2 digits are valid month.
            if (preg_match('/^(\d{6})(?:$|\[)/', $noAnnot2, $amb6)) {
                $mmPart = (int) substr($amb6[1], 4, 2);
                if ($mmPart >= 1 && $mmPart <= 12) {
                    throw new RangeError("'{$str}' is ambiguous and requires T prefix");
                }
            }
        }
        // Strip annotations for time-only parsing.
        $cleanStr = $noAnnot2;
        // Strip offset.
        $cleanStr = preg_replace('/[+\-]\d{2}(?::?\d{2}(?::?\d{2}(?:[.,]\d+)?)?)?$/', '', $cleanStr);
        // Strip leading T/t designator.
        if ($hasT) {
            $cleanStr = substr($cleanStr, 1);
        }
        $pattern = '/^(\d{2})(?::?(\d{2})(?::?(\d{2})(?:[.,](\d{1,9}))?)?)?$/';
        if (!preg_match($pattern, $cleanStr, $m)) {
            // Also try datetime string and extract time.
            if ($hasT) {
                throw new RangeError("Invalid PlainTime string: {$str}");
            }
            $pattern2 = '/[Tt ](\d{2})(?::?(\d{2})(?::?(\d{2})(?:[.,](\d{1,9}))?)?)?/';
            if (!preg_match($pattern2, $cleanStr, $m)) {
                throw new RangeError("Invalid PlainTime string: {$str}");
            }
        }
        $h = (int) $m[1];
        $min = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
        $s = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0;
        // Handle leap second: clamp 60 to 59 per spec.
        if ($s === 60) {
            $s = 59;
        }
        $frac = isset($m[4]) ? str_pad(substr($m[4], 0, 9), 9, '0') : '000000000';
        $ms = (int) substr($frac, 0, 3);
        $us = (int) substr($frac, 3, 3);
        $ns = (int) substr($frac, 6, 3);
        self::validateISOTime($h, $min, $s, $ms, $us, $ns);
        return self::createPlainTimeObject($h, $min, $s, $ms, $us, $ns);
    }

    private static function toPlainDateTime(JsValue $item, string $overflow = 'constrain', ?JsValue $rawOptions = null): JsObject
    {
        if ($item instanceof JsObject && $item->has('[[IsPlainDateTime]]')) {
            // Return a copy per spec.
            return self::createPlainDateTimeObject(
                self::getSlotInt($item, '[[ISOYear]]'),
                self::getSlotInt($item, '[[ISOMonth]]'),
                self::getSlotInt($item, '[[ISODay]]'),
                self::getSlotInt($item, '[[ISOHour]]'),
                self::getSlotInt($item, '[[ISOMinute]]'),
                self::getSlotInt($item, '[[ISOSecond]]'),
                self::getSlotInt($item, '[[ISOMillisecond]]'),
                self::getSlotInt($item, '[[ISOMicrosecond]]'),
                self::getSlotInt($item, '[[ISONanosecond]]'),
                self::getSlotString($item, '[[Calendar]]'),
            );
        }
        if ($item instanceof JsObject && $item->has('[[IsPlainDate]]')) {
            return self::createPlainDateTimeObject(
                self::getSlotInt($item, '[[ISOYear]]'),
                self::getSlotInt($item, '[[ISOMonth]]'),
                self::getSlotInt($item, '[[ISODay]]'),
                0,
                0,
                0,
                0,
                0,
                0,
                self::getSlotString($item, '[[Calendar]]'),
            );
        }
        if ($item instanceof JsObject) {
            // Property bag: read ALL fields in ALPHABETICAL order per spec.
            // Each field is immediately coerced via valueOf/toString.
            $calVal = $item->get('calendar');
            $cal = 'iso8601';
            if (!($calVal instanceof JsUndefined)) {
                $cal = self::toCalendarSlotValue($calVal);
            }
            $dayVal = $item->get('day');
            if ($dayVal instanceof JsUndefined) {
                throw new TypeError('missing required property: day');
            }
            $dNum = TypeConversion::toNumber($dayVal);
            if (!is_finite($dNum)) {
                throw new RangeError('day must be finite');
            }
            $d = (int) $dNum;
            // Read era and eraYear (per spec alphabetical ordering)
            // only for non-ISO calendars; ISO doesn't have eras and
            // reading these properties on an ISO property bag would
            // disturb the canonical observable read order.
            $eraStr = null;
            $eraYearNum = null;
            $eraSet = false;
            $eraYearSet = false;
            if ($cal !== 'iso8601') {
                $eraVal = $item->get('era');
                if (!($eraVal instanceof JsUndefined)) {
                    $eraSet = true;
                    $eraStr = TypeConversion::toString($eraVal);
                }
                $eraYearVal = $item->get('eraYear');
                if (!($eraYearVal instanceof JsUndefined)) {
                    $eraYearSet = true;
                    $eraYearNum = TypeConversion::toNumber($eraYearVal);
                    if (is_nan($eraYearNum) || !is_finite($eraYearNum)) {
                        throw new RangeError('eraYear must be finite');
                    }
                    if (floor($eraYearNum) !== $eraYearNum) {
                        throw new RangeError('eraYear must be an integer');
                    }
                }
                static $pdtErasUseEras = ['gregory', 'japanese', 'roc'];
                if (in_array($cal, $pdtErasUseEras, true) && $eraSet !== $eraYearSet) {
                    throw new TypeError(
                        'era and eraYear must be provided together',
                    );
                }
            }
            // Read time fields (for PlainDateTime path).
            $h = 0;
            $min = 0;
            $s = 0;
            $ms = 0;
            $us = 0;
            $ns = 0;
            $hVal = $item->get('hour');
            if (!($hVal instanceof JsUndefined)) {
                $hNum = TypeConversion::toNumber($hVal);
                if (!is_finite($hNum)) {
                    throw new RangeError('hour must be finite');
                }
                $h = (int) $hNum;
            }
            $usVal = $item->get('microsecond');
            if (!($usVal instanceof JsUndefined)) {
                $usNum = TypeConversion::toNumber($usVal);
                if (!is_finite($usNum)) {
                    throw new RangeError('microsecond must be finite');
                }
                $us = (int) $usNum;
            }
            $msVal = $item->get('millisecond');
            if (!($msVal instanceof JsUndefined)) {
                $msNum = TypeConversion::toNumber($msVal);
                if (!is_finite($msNum)) {
                    throw new RangeError('millisecond must be finite');
                }
                $ms = (int) $msNum;
            }
            $minVal = $item->get('minute');
            if (!($minVal instanceof JsUndefined)) {
                $minNum = TypeConversion::toNumber($minVal);
                if (!is_finite($minNum)) {
                    throw new RangeError('minute must be finite');
                }
                $min = (int) $minNum;
            }
            $monthVal = $item->get('month');
            $monthExplicit = !($monthVal instanceof JsUndefined);
            $m = 0;
            if ($monthExplicit) {
                $mNum = TypeConversion::toNumber($monthVal);
                if (!is_finite($mNum)) {
                    throw new RangeError('month must be finite');
                }
                $m = (int) $mNum;
            }
            $monthCodeVal = $item->get('monthCode');
            $mcStr = null;
            if (!($monthCodeVal instanceof JsUndefined)) {
                $mcStr = TypeConversion::toString($monthCodeVal);
                self::parseMonthCodeSyntax($mcStr);
            }
            $nsVal = $item->get('nanosecond');
            if (!($nsVal instanceof JsUndefined)) {
                $nsNum = TypeConversion::toNumber($nsVal);
                if (!is_finite($nsNum)) {
                    throw new RangeError('nanosecond must be finite');
                }
                $ns = (int) $nsNum;
            }
            $sVal = $item->get('second');
            if (!($sVal instanceof JsUndefined)) {
                $sNum = TypeConversion::toNumber($sVal);
                if (!is_finite($sNum)) {
                    throw new RangeError('second must be finite');
                }
                $s = (int) $sNum;
            }
            $yearVal = $item->get('year');
            if ($yearVal instanceof JsUndefined) {
                // For era-using calendars, era+eraYear can
                // substitute for year (Gregorian: ce/ad → year=eraYear,
                // bc/bce → year = 1 - eraYear). For calendars that
                // don't use eras (hebrew/islamic/chinese), an absent
                // year is always a TypeError.
                static $pdtEraDerivCals = ['gregory', 'japanese', 'roc'];
                if (
                    $eraYearNum !== null
                    && in_array($cal, $pdtEraDerivCals, true)
                ) {
                    $eraLower = $eraStr === null ? '' : strtolower($eraStr);
                    if (in_array($eraLower, ['bc', 'bce'], true)) {
                        $y = 1 - (int) $eraYearNum;
                    } else {
                        $y = (int) $eraYearNum;
                    }
                } else {
                    throw new TypeError('missing required property: year');
                }
            } else {
                $yNum = TypeConversion::toNumber($yearVal);
                if (!is_finite($yNum)) {
                    throw new RangeError('year must be finite');
                }
                $y = (int) $yNum;
            }
            // Now validate monthCode suitability (after year type check).
            if ($mcStr !== null) {
                $validatedMonth = self::parseMonthCode($mcStr);
                if ($monthExplicit && $m !== $validatedMonth) {
                    throw new RangeError('month and monthCode must agree');
                }
                $m = $validatedMonth;
            } elseif (!$monthExplicit) {
                throw new TypeError('missing required property: month');
            }
            if ($rawOptions !== null) {
                $options = self::getOptionsObject($rawOptions);
                $overflow = self::getOverflow($options);
            }
            // For non-ISO calendars (excluding gregory/roc which use ISO
            // year/month/day directly), convert calendar-native fields to ISO
            // via ICU before storing.
            if ($cal !== 'iso8601' && !in_array($cal, ['gregory', 'roc', 'japanese'], true)) {
                $isoParts = self::calendarPartsToIso($cal, $y, $mcStr, $monthExplicit ? $m : null, $d);
                if ($isoParts !== null) {
                    if ($overflow === 'constrain') {
                        $h = max(0, min(23, $h));
                        $min = max(0, min(59, $min));
                        $s = max(0, min(59, $s));
                        $ms = max(0, min(999, $ms));
                        $us = max(0, min(999, $us));
                        $ns = max(0, min(999, $ns));
                    } else {
                        self::validateISOTime($h, $min, $s, $ms, $us, $ns);
                    }
                    return self::createPlainDateTimeObject(
                        $isoParts['year'],
                        $isoParts['month'],
                        $isoParts['day'],
                        $h,
                        $min,
                        $s,
                        $ms,
                        $us,
                        $ns,
                        $cal,
                    );
                }
            }
            if ($overflow === 'constrain') {
                [$y, $m, $d] = self::constrainISODate($y, $m, $d);
                $h = max(0, min(23, $h));
                $min = max(0, min(59, $min));
                $s = max(0, min(59, $s));
                $ms = max(0, min(999, $ms));
                $us = max(0, min(999, $us));
                $ns = max(0, min(999, $ns));
            } else {
                self::validateISODate($y, $m, $d);
                self::validateISOTime($h, $min, $s, $ms, $us, $ns);
            }
            return self::createPlainDateTimeObject($y, $m, $d, $h, $min, $s, $ms, $us, $ns, $cal);
        }
        if ($item instanceof JsUndefined || $item instanceof JsNull) {
            throw new TypeError('Cannot convert undefined/null to PlainDateTime');
        }
        if ($item instanceof JsNumber || $item instanceof \Phasis\Value\JsBigInt) {
            throw new TypeError('Cannot convert number to Temporal.PlainDateTime');
        }
        if ($item instanceof JsBoolean) {
            throw new TypeError('Cannot convert boolean to Temporal.PlainDateTime');
        }
        if ($item instanceof \Phasis\Value\JsSymbol) {
            throw new TypeError('Cannot convert Symbol to Temporal.PlainDateTime');
        }
        $str = TypeConversion::toString($item);
        return self::parsePlainDateTimeString($str);
    }

    private static function parsePlainDateTimeString(string $str): JsObject
    {
        [$str, $calFromAnnotation] = self::normalizeTemporalString($str);
        // Reject extended year without sign (5+ digits followed by dash).
        if (preg_match('/^\d{5,}-/', $str)) {
            throw new RangeError("Extended year requires sign: {$str}");
        }
        // Reject UTC designator (Z) for PlainDateTime.
        $noAnnot = preg_replace('/\[.*?\]/', '', $str);
        if (preg_match('/[Zz]/', $noAnnot)) {
            throw new RangeError(
                "String with UTC designator should not be valid as PlainDateTime"
            );
        }
        // Reject -000000 (minus zero year).
        if (preg_match('/^-0{4,6}[-\d]/', $str)) {
            throw new RangeError("reject minus zero as extended year: {$str}");
        }
        $datePart = '([+-]?\d{4,6})-?(\d{2})-?(\d{2})';
        $timePart = '(\d{2})(?::?(\d{2})(?::?(\d{2})(?:[.,](\d{1,9}))?)?)?';
        // UTC offset can include seconds and fractional seconds (extended format).
        $tzPart = '(?:[+-]\d{2}(?::?\d{2}(?::?\d{2}(?:[.,]\d{1,9})?)?)?)?';
        $pattern = "/^{$datePart}[Tt ]{$timePart}{$tzPart}(?:\\[.*?\\])*\$/";
        if (!preg_match($pattern, $str, $m)) {
            // Fallback: date only (with or without dashes).
            $dateOnly = '/^([+-]?\d{4,6})-?(\d{2})-?(\d{2})(?:\[.*?\])*$/';
            if (preg_match($dateOnly, $str, $m)) {
                $y = (int) $m[1];
                $m2 = (int) $m[2];
                $d = (int) $m[3];
                self::validateISODate($y, $m2, $d);
                if ($calFromAnnotation !== 'iso8601' && !self::isValidCalendar($calFromAnnotation)) {
                    throw new RangeError("Invalid calendar: {$calFromAnnotation}");
                }
                return self::createPlainDateTimeObject(
                    $y,
                    $m2,
                    $d,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                    $calFromAnnotation,
                );
            }
            throw new RangeError("Invalid PlainDateTime string: {$str}");
        }
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $dd = (int) $m[3];
        $h = (int) $m[4];
        $min = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : 0;
        $s = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0;
        // Leap second: clamp 60 to 59.
        if ($s === 60) {
            $s = 59;
        }
        $frac = isset($m[7]) ? str_pad(substr($m[7], 0, 9), 9, '0') : '000000000';
        $ms = (int) substr($frac, 0, 3);
        $us = (int) substr($frac, 3, 3);
        $ns = (int) substr($frac, 6, 3);
        self::validateISODate($y, $mo, $dd);
        self::validateISOTime($h, $min, $s, $ms, $us, $ns);
        if ($calFromAnnotation !== 'iso8601' && !self::isValidCalendar($calFromAnnotation)) {
            throw new RangeError("Invalid calendar: {$calFromAnnotation}");
        }
        return self::createPlainDateTimeObject(
            $y,
            $mo,
            $dd,
            $h,
            $min,
            $s,
            $ms,
            $us,
            $ns,
            $calFromAnnotation,
        );
    }

    private static function toPlainYearMonthWithLazyOptions(JsValue $item, JsValue $rawOptions): JsObject
    {
        if (!$item instanceof JsObject || $item->has('[[IsPlainYearMonth]]')) {
            // Should not happen via from() since instances and primitives are handled before.
            $options = self::getOptionsObject($rawOptions);
            $overflow = self::getOverflow($options);
            return self::toPlainYearMonth($item, $overflow);
        }
        $cal = 'iso8601';
        $calVal = $item->get('calendar');
        if (!($calVal instanceof JsUndefined)) {
            $cal = self::toCalendarSlotValue($calVal);
        }
        $eraStr = null;
        $eraYearNum = null;
        $eraSet = false;
        $eraYearSet = false;
        if ($cal !== 'iso8601') {
            $eraVal = $item->get('era');
            if (!($eraVal instanceof JsUndefined)) {
                $eraSet = true;
                $eraStr = TypeConversion::toString($eraVal);
            }
            $eraYearVal = $item->get('eraYear');
            if (!($eraYearVal instanceof JsUndefined)) {
                $eraYearSet = true;
                $eraYearNum = TypeConversion::toNumber($eraYearVal);
                if (is_nan($eraYearNum) || !is_finite($eraYearNum)) {
                    throw new RangeError('eraYear must be finite');
                }
                if (floor($eraYearNum) !== $eraYearNum) {
                    throw new RangeError('eraYear must be an integer');
                }
            }
            static $pymLazyEraCals = ['gregory', 'japanese', 'roc'];
            if (in_array($cal, $pymLazyEraCals, true) && $eraSet !== $eraYearSet) {
                throw new TypeError('era and eraYear must be provided together');
            }
        }
        $month = $item->get('month');
        $mVal = null;
        if (!($month instanceof JsUndefined)) {
            $mNum = TypeConversion::toNumber($month);
            if (!is_finite($mNum)) {
                throw new RangeError('month must be finite');
            }
            $mVal = (int) $mNum;
        }
        $monthCode = $item->get('monthCode');
        $mcStr = null;
        $mcParsed = null;
        if (!($monthCode instanceof JsUndefined)) {
            $mcStr = TypeConversion::toString($monthCode);
            $mcParsed = self::parseMonthCodeSyntax($mcStr);
        }
        $year = $item->get('year');
        if ($year instanceof JsUndefined) {
            static $pymLazyDeriv = ['gregory', 'japanese', 'roc'];
            if ($eraYearNum !== null && in_array($cal, $pymLazyDeriv, true)) {
                $eraLower = $eraStr === null ? '' : strtolower($eraStr);
                if ($cal === 'japanese') {
                    $isoYear = self::japaneseEraToIsoYear($eraLower, (int) $eraYearNum);
                    $yNum = $isoYear !== null
                        ? (float) $isoYear
                        : (in_array($eraLower, ['japanese-inverse'], true) ? (1 - $eraYearNum) : $eraYearNum);
                } elseif ($cal === 'roc') {
                    $yNum = in_array($eraLower, ['roc-inverse', 'before-roc'], true)
                        ? (1912 - $eraYearNum)
                        : (1911 + $eraYearNum);
                } else {
                    $yNum = in_array($eraLower, ['bc', 'bce', 'gregory-inverse'], true)
                        ? (1 - $eraYearNum)
                        : $eraYearNum;
                }
            } else {
                throw new TypeError('missing required property: year');
            }
        } else {
            $yNum = TypeConversion::toNumber($year);
        }
        if (!is_finite($yNum)) {
            throw new RangeError('year must be finite');
        }
        $y = (int) $yNum;
        if ($mVal === null && $mcStr === null) {
            throw new TypeError('missing required property: month or monthCode');
        }
        // NOW read overflow from options (after all field reads).
        $options = self::getOptionsObject($rawOptions);
        $overflow = self::getOverflow($options);
        if ($mcParsed !== null) {
            [$mcMonth, $mcLeap] = $mcParsed;
            static $pymLazyLunisolar = ['hebrew', 'chinese', 'dangi'];
            if ($mcMonth < 1 || $mcMonth > 12) {
                throw new RangeError("monthCode '{$mcStr}' is not valid for ISO 8601 calendar");
            }
            if ($mcLeap && !in_array($cal, $pymLazyLunisolar, true)) {
                throw new RangeError("monthCode '{$mcStr}' leap-month suffix is not valid for calendar '{$cal}'");
            }
            // Hebrew M05L only exists in leap years.
            if ($mcLeap && $cal === 'hebrew' && !self::isHebrewLeapYear($y)) {
                if ($overflow === 'reject') {
                    throw new RangeError("monthCode '{$mcStr}' is not valid in Hebrew non-leap year {$y}");
                }
                // Constrain leaks down to Adar (M06).
                $mcMonth = 6;
                $mcLeap = false;
                $mcStr = 'M06';
            }
            $m = $mcMonth;
            if ($mVal !== null && $mVal !== $m) {
                throw new RangeError('month and monthCode disagree');
            }
        } else {
            $m = $mVal;
        }
        if ($m < 1) {
            throw new RangeError("month {$m} out of range");
        }
        // Hebrew leap years allow month=13.
        $maxMonth = ($cal === 'hebrew' && self::isHebrewLeapYear($y)) ? 13 : 12;
        if ($overflow === 'constrain') {
            $m = min($maxMonth, $m);
        } elseif ($m > $maxMonth) {
            throw new RangeError("month {$m} out of range");
        }
        // Non-ISO non-gregory calendars: convert calendar-native (year, month, day=1)
        // to ISO via ICU and store the resulting ISO date.
        if ($cal !== 'iso8601' && !in_array($cal, ['gregory', 'roc', 'japanese'], true)) {
            $isoParts = self::calendarPartsToIso($cal, $y, $mcStr, $mcStr === null ? $m : null, 1);
            // Chinese leap-month requested in a year that doesn't have it:
            // calendarPartsToIso returns null. Constrain to the next month
            // (M01L → M02, M04L → M05, etc.); reject throws RangeError.
            if ($isoParts === null && $mcStr !== null && in_array($cal, ['chinese', 'dangi'], true) && preg_match('/^M(\d{2})L$/', $mcStr, $mm)) {
                if ($overflow === 'reject') {
                    throw new RangeError("Chinese leap month {$mcStr} does not exist in year {$y}");
                }
                $base = (int) $mm[1];
                // Prefer the next non-leap month (M(NN+1)); if NN is 12, fall
                // back to the same-numbered non-leap month (M12).
                foreach ([$base + 1, $base] as $altNum) {
                    if ($altNum < 1 || $altNum > 12) {
                        continue;
                    }
                    $altMc = 'M' . str_pad((string) $altNum, 2, '0', STR_PAD_LEFT);
                    $candidate = self::calendarPartsToIso($cal, $y, $altMc, null, 1);
                    if ($candidate !== null) {
                        $isoParts = $candidate;
                        break;
                    }
                }
            }
            if ($isoParts !== null) {
                return self::createPlainYearMonthObject($isoParts['year'], $isoParts['month'], $isoParts['day'], $cal);
            }
        }
        return self::createPlainYearMonthObject($y, $m, 1, $cal);
    }

    private static function toPlainYearMonth(JsValue $item, string $overflow = 'constrain'): JsObject
    {
        if ($item instanceof JsUndefined || $item instanceof JsNull) {
            throw new TypeError('Cannot convert undefined/null to PlainYearMonth');
        }
        if ($item instanceof JsObject && $item->has('[[IsPlainYearMonth]]')) {
            return self::createPlainYearMonthObject(
                self::getSlotInt($item, '[[ISOYear]]'),
                self::getSlotInt($item, '[[ISOMonth]]'),
                self::getSlotInt($item, '[[ISODay]]'),
                self::getSlotString($item, '[[Calendar]]'),
            );
        }
        if ($item instanceof JsObject) {
            $cal = 'iso8601';
            $calVal = $item->get('calendar');
            if (!($calVal instanceof JsUndefined)) {
                $cal = self::toCalendarSlotValue($calVal);
            }
            // era/eraYear (non-ISO calendars only) per alphabetical
            // spec ordering. Validation happens immediately.
            $eraStr = null;
            $eraYearNum = null;
            $eraSet = false;
            $eraYearSet = false;
            if ($cal !== 'iso8601') {
                $eraVal = $item->get('era');
                if (!($eraVal instanceof JsUndefined)) {
                    $eraSet = true;
                    $eraStr = TypeConversion::toString($eraVal);
                }
                $eraYearVal = $item->get('eraYear');
                if (!($eraYearVal instanceof JsUndefined)) {
                    $eraYearSet = true;
                    $eraYearNum = TypeConversion::toNumber($eraYearVal);
                    if (is_nan($eraYearNum) || !is_finite($eraYearNum)) {
                        throw new RangeError('eraYear must be finite');
                    }
                    if (floor($eraYearNum) !== $eraYearNum) {
                        throw new RangeError('eraYear must be an integer');
                    }
                }
                static $pymErasUseEras = ['gregory', 'japanese', 'roc'];
                if (in_array($cal, $pymErasUseEras, true) && $eraSet !== $eraYearSet) {
                    throw new TypeError('era and eraYear must be provided together');
                }
            }
            $month = $item->get('month');
            $mVal = null;
            if (!($month instanceof JsUndefined)) {
                $mNum = TypeConversion::toNumber($month);
                if (!is_finite($mNum)) {
                    throw new RangeError('month must be finite');
                }
                $mVal = (int) $mNum;
            }
            $monthCode = $item->get('monthCode');
            $mcStr = null;
            $mcParsed = null;
            if (!($monthCode instanceof JsUndefined)) {
                $mcStr = TypeConversion::toString($monthCode);
                $mcParsed = self::parseMonthCodeSyntax($mcStr);
            }
            $year = $item->get('year');
            if ($year instanceof JsUndefined) {
                static $pymEraDerivCals = ['gregory', 'japanese', 'roc'];
                if (
                    $eraYearNum !== null
                    && in_array($cal, $pymEraDerivCals, true)
                ) {
                    $eraLower = $eraStr === null ? '' : strtolower($eraStr);
                    if ($cal === 'japanese') {
                        $isoYear = self::japaneseEraToIsoYear($eraLower, (int) $eraYearNum);
                        $yNum = $isoYear !== null
                            ? (float) $isoYear
                            : (in_array($eraLower, ['japanese-inverse'], true) ? (1 - $eraYearNum) : $eraYearNum);
                    } elseif ($cal === 'roc') {
                        $yNum = in_array($eraLower, ['roc-inverse', 'before-roc'], true)
                            ? (1912 - $eraYearNum)
                            : (1911 + $eraYearNum);
                    } else {
                        $yNum = in_array($eraLower, ['bc', 'bce', 'gregory-inverse'], true)
                            ? (1 - $eraYearNum)
                            : $eraYearNum;
                    }
                } else {
                    throw new TypeError('missing required property: year');
                }
            } else {
                $yNum = TypeConversion::toNumber($year);
            }
            if (!is_finite($yNum)) {
                throw new RangeError('year must be finite');
            }
            $y = (int) $yNum;
            if ($mVal === null && $mcStr === null) {
                throw new TypeError('missing required property: month or monthCode');
            }
            if ($mcParsed !== null) {
                [$mcMonth, $mcLeap] = $mcParsed;
                // Leap-month suffix 'L' is only allowed on lunisolar
                // calendars (Hebrew, Chinese, Dangi). Other calendars
                // reject monthCodes ending in L.
                static $pymLunisolar = ['hebrew', 'chinese', 'dangi'];
                if ($mcMonth < 1 || $mcMonth > 12) {
                    throw new RangeError("monthCode '{$mcStr}' is not valid for ISO 8601 calendar");
                }
                if ($mcLeap && !in_array($cal, $pymLunisolar, true)) {
                    throw new RangeError("monthCode '{$mcStr}' leap-month suffix is not valid for calendar '{$cal}'");
                }
                $m = $mcMonth;
                if ($mVal !== null && $mVal !== $m) {
                    throw new RangeError("month and monthCode disagree");
                }
            } elseif ($mVal !== null) {
                $m = $mVal;
            } else {
                throw new TypeError('missing required property: month or monthCode');
            }
            // Per spec: months < 1 always throw, even with constrain.
            if ($m < 1) {
                throw new RangeError("month {$m} out of range");
            }
            if ($overflow === 'constrain') {
                $m = min(12, $m);
            } elseif ($m > 12) {
                throw new RangeError("month {$m} out of range");
            }
            // Non-ISO non-gregory: convert calendar fields to ISO via ICU.
            if ($cal !== 'iso8601' && !in_array($cal, ['gregory', 'roc', 'japanese'], true)) {
                $isoParts = self::calendarPartsToIso($cal, $y, $mcStr, $mcStr === null ? $m : null, 1);
                if ($isoParts === null && $mcStr !== null && in_array($cal, ['chinese', 'dangi'], true) && preg_match('/^M(\d{2})L$/', $mcStr, $mm)) {
                    if ($overflow === 'reject') {
                        throw new RangeError("Chinese leap month {$mcStr} does not exist in year {$y}");
                    }
                    $nextNum = ((int) $mm[1]) + 1;
                    if ($nextNum >= 1 && $nextNum <= 12) {
                        $altMc = 'M' . str_pad((string) $nextNum, 2, '0', STR_PAD_LEFT);
                        $isoParts = self::calendarPartsToIso($cal, $y, $altMc, null, 1);
                    }
                }
                if ($isoParts !== null) {
                    return self::createPlainYearMonthObject($isoParts['year'], $isoParts['month'], $isoParts['day'], $cal);
                }
            }
            return self::createPlainYearMonthObject($y, $m, 1, $cal);
        }
        // Reject primitives per spec.
        if ($item instanceof JsNumber || $item instanceof \Phasis\Value\JsBigInt) {
            throw new TypeError('Cannot convert number to Temporal.PlainYearMonth');
        }
        if ($item instanceof JsBoolean) {
            throw new TypeError('Cannot convert boolean to Temporal.PlainYearMonth');
        }
        if ($item instanceof \Phasis\Value\JsSymbol) {
            throw new TypeError('Cannot convert Symbol to Temporal.PlainYearMonth');
        }
        $str = TypeConversion::toString($item);
        [$str, $cal] = self::normalizeTemporalString($str);
        $noAnnot = preg_replace('/\[.*?\]/', '', $str);
        if (preg_match('/[Zz]/', $noAnnot)) {
            throw new RangeError("String with UTC designator should not be valid as PlainYearMonth");
        }
        if (preg_match('/^-0{4,6}/', $str)) {
            throw new RangeError("reject minus zero as extended year: {$str}");
        }
        if (preg_match('/^\d{5,}-/', $str)) {
            throw new RangeError("Extended year requires sign: {$str}");
        }
        $hasTime = (bool) preg_match('/[Tt ]/', $noAnnot);
        // Reject UTC offset without time: matches date-only strings ending
        // with an offset like "+00:00", "-02:30", or "+0000".
        if (!$hasTime && preg_match('/[+-]\d{2}(?::?\d{2})?$/', $noAnnot)) {
            // Distinguish date separators from offsets: YYYY-MM and YYYY-MM-DD
            // use only "-" between fields and have exactly YYYY-MM or YYYY-MM-DD form.
            // Extended year: +YYYYYY-MM or -YYYYYY-MM.
            // If the trailing [+-]NN:?NN pattern comes after at least 3 date fields,
            // it is a UTC offset. Strip a well-formed date prefix and check remainder.
            $extY = '(?:[+-]\\d{6}|\\d{4})';
            if (preg_match('/^' . $extY . '-?\\d{2}-?\\d{2}[+-]\\d{2}/', $noAnnot)) {
                throw new RangeError("UTC offset without time is not valid for PlainYearMonth");
            }
        }
        // Per spec, a non-ISO calendar annotation requires the
        // string to either include time-of-day OR be a complete
        // YYYY-MM-DD date — V8 accepts the date-only form. A bare
        // YYYY-MM on a non-ISO calendar still throws.
        if (!$hasTime && $cal !== 'iso8601') {
            $isDateOnlyComplete = (bool) preg_match(
                '/^(?:[+-]\d{6}|\d{4})-?\d{2}-?\d{2}/',
                $noAnnot,
            );
            if (!$isDateOnlyComplete) {
                throw new RangeError(
                    "non-iso8601 calendar annotation requires year/month/day"
                );
            }
        }
        // Time part with optional UTC offset in extended form (hh[:mm[:ss[.fff]]]).
        $offsetOpt = '(?:[+-]\\d{2}(?::?\\d{2}(?::?\\d{2}(?:[.,]\\d{1,9})?)?)?)?';
        $tp = "(?:[Tt ](\\d{2})(?::?(\\d{2})(?::?(\\d{2})(?:[.,]\\d{1,9})?)?)?{$offsetOpt})?";
        $patterns = [
            "/^([+-]\\d{6})-(\\d{2})(?:-(\\d{2}))?{$tp}(?:\\[.*?\\])*\$/",
            "/^([+-]\\d{6})(\\d{2})(\\d{2}){$tp}(?:\\[.*?\\])*\$/",
            "/^([+-]\\d{6})(\\d{2})(?:\\[.*?\\])*\$/",
            "/^(\\d{4})-(\\d{2})(?:-(\\d{2}))?{$tp}(?:\\[.*?\\])*\$/",
            "/^(\\d{4})(\\d{2})(\\d{2}){$tp}(?:\\[.*?\\])*\$/",
            "/^(\\d{4})(\\d{2})(?:\\[.*?\\])*\$/",
        ];
        $m = null;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $str, $candidate)) {
                $m = $candidate;
                break;
            }
        }
        if ($m === null) {
            throw new RangeError("Invalid PlainYearMonth string: {$str}");
        }
        $timeIdx = (isset($m[3]) && $m[3] !== '') ? 4 : 3;
        if (isset($m[$timeIdx]) && $m[$timeIdx] !== '') {
            $th = (int) $m[$timeIdx];
            $tmin = isset($m[$timeIdx + 1]) && $m[$timeIdx + 1] !== '' ? (int) $m[$timeIdx + 1] : 0;
            $ts = isset($m[$timeIdx + 2]) && $m[$timeIdx + 2] !== '' ? (int) $m[$timeIdx + 2] : 0;
            if ($ts === 60) {
                $ts = 59;
            }
            if ($th > 23 || $tmin > 59 || $ts > 59) {
                throw new RangeError("Invalid time: {$str}");
            }
        }
        $y = (int) $m[1];
        $mo = (int) $m[2];
        if ($mo < 1 || $mo > 12) {
            throw new RangeError("month {$mo} out of range");
        }
        if ($cal !== 'iso8601' && !self::isValidCalendar($cal)) {
            throw new RangeError("Invalid calendar: {$cal}");
        }
        // For non-ISO calendars, preserve the parsed day component as the
        // reference ISO day so getters land on the correct calendar M/d.
        $refDay = 1;
        if ($cal !== 'iso8601' && isset($m[3]) && $m[3] !== '') {
            $refDay = (int) $m[3];
        }
        return self::createPlainYearMonthObject($y, $mo, $refDay, $cal);
    }

    private static function toPlainMonthDay(JsValue $item, string $overflow = 'constrain'): JsObject
    {
        // Type validation: reject null, boolean, number, BigInt, symbol.
        if ($item instanceof JsUndefined || $item instanceof JsNull) {
            throw new TypeError('Cannot convert undefined or null to a Temporal.PlainMonthDay');
        }
        if ($item instanceof JsBoolean || $item instanceof JsNumber || $item instanceof JsBigInt) {
            throw new TypeError('Cannot convert primitive to a Temporal.PlainMonthDay');
        }

        if ($item instanceof JsObject && $item->has('[[IsPlainMonthDay]]')) {
            // Copy the PlainMonthDay, do not return the same object.
            return self::createPlainMonthDayObject(
                self::getSlotInt($item, '[[ISOMonth]]'),
                self::getSlotInt($item, '[[ISODay]]'),
                self::getSlotInt($item, '[[ISOYear]]'),
                self::getSlotString($item, '[[Calendar]]'),
            );
        }
        if ($item instanceof JsObject) {
            // Validate calendar property if present (read first per spec).
            $calVal = $item->get('calendar');
            $cal = 'iso8601';
            if (!($calVal instanceof JsUndefined)) {
                $cal = self::toCalendarSlotValue($calVal);
            }
            // PrepareTemporalFields: alphabetical with immediate
            // valueOf/toString.
            $day = $item->get('day');
            $hasDay = !($day instanceof JsUndefined);
            $dNum = null;
            if ($hasDay) {
                $dNum = TypeConversion::toNumber($day);
            }
            // era/eraYear (non-ISO calendars only).
            $eraStr = null;
            $eraYearNum = null;
            if ($cal !== 'iso8601') {
                $eraVal = $item->get('era');
                if (!($eraVal instanceof JsUndefined)) {
                    $eraStr = TypeConversion::toString($eraVal);
                }
                $eraYearVal = $item->get('eraYear');
                if (!($eraYearVal instanceof JsUndefined)) {
                    $eraYearNum = TypeConversion::toNumber($eraYearVal);
                    if (is_nan($eraYearNum) || !is_finite($eraYearNum)) {
                        throw new RangeError('eraYear must be finite');
                    }
                    if (floor($eraYearNum) !== $eraYearNum) {
                        throw new RangeError('eraYear must be an integer');
                    }
                }
            }
            $month = $item->get('month');
            $hasMonth = !($month instanceof JsUndefined);
            $mNumRaw = null;
            if ($hasMonth) {
                $mNumRaw = TypeConversion::toNumber($month);
            }
            $monthCode = $item->get('monthCode');
            $hasMonthCode = !($monthCode instanceof JsUndefined);
            $mcStrRaw = null;
            if ($hasMonthCode) {
                $mcStrRaw = TypeConversion::toString($monthCode);
            }
            $year = $item->get('year');
            $hasYear = !($year instanceof JsUndefined);
            $yValRaw = null;
            if ($hasYear) {
                $yValRaw = TypeConversion::toNumber($year);
            }
            // Validate required properties now that all reads are done.
            if (!$hasDay) {
                throw new TypeError('Required property day missing');
            }
            if (!$hasMonth && !$hasMonthCode) {
                throw new TypeError('Required property month or monthCode missing');
            }
            if (!is_finite($dNum)) {
                throw new RangeError('day must be finite');
            }
            $d = (int) $dNum;
            if ($hasMonthCode) {
                if (!preg_match('/^M(\d{2})(L?)$/', $mcStrRaw, $mcm)) {
                    throw new RangeError("Invalid monthCode: {$mcStrRaw}");
                }
                $m = (int) $mcm[1];
                $hasLeap = $mcm[2] === 'L';
                $mStr = $mcStrRaw;
            } else {
                if (!is_finite($mNumRaw)) {
                    throw new RangeError('month must be finite');
                }
                $m = (int) $mNumRaw;
                $hasLeap = false;
                $mStr = '';
            }
            $refYear = 1972;
            if ($hasYear) {
                if (!is_finite($yValRaw)) {
                    throw new RangeError('year must be finite');
                }
                $refYear = (int) $yValRaw;
                // When era+eraYear is also provided, validate consistency.
                if ($eraYearNum !== null) {
                    static $pmdEraDerivCals2 = ['gregory', 'japanese', 'roc'];
                    if (in_array($cal, $pmdEraDerivCals2, true)) {
                        $eraLower = $eraStr === null ? '' : strtolower($eraStr);
                        $derivedYear = null;
                        if ($cal === 'gregory') {
                            $derivedYear = in_array($eraLower, ['bc', 'bce', 'gregory-inverse'], true)
                                ? (1 - (int) $eraYearNum)
                                : (int) $eraYearNum;
                        } elseif ($cal === 'roc') {
                            $derivedYear = ($eraLower === 'roc-inverse' || $eraLower === 'before-roc')
                                ? (int) (1912 - $eraYearNum)
                                : (int) (1911 + $eraYearNum);
                        } elseif ($cal === 'japanese') {
                            $isoY = self::japaneseEraToIsoYear($eraLower, (int) $eraYearNum);
                            if ($isoY !== null) {
                                $derivedYear = $isoY;
                            }
                        }
                        if ($derivedYear !== null && $derivedYear !== $refYear) {
                            throw new RangeError(
                                "era/eraYear and year disagree: derived {$derivedYear} vs explicit {$refYear}",
                            );
                        }
                    }
                }
            } else {
                static $pmdEraDerivCals = ['gregory', 'japanese', 'roc'];
                if (
                    $eraYearNum !== null
                    && in_array($cal, $pmdEraDerivCals, true)
                ) {
                    $eraLower = $eraStr === null ? '' : strtolower($eraStr);
                    $refYear = in_array($eraLower, ['bc', 'bce'], true)
                        ? (1 - (int) $eraYearNum)
                        : (int) $eraYearNum;
                } elseif ($cal !== 'iso8601' && !$hasMonthCode) {
                    // Non-ISO calendars require year info when month is given numerically.
                    throw new TypeError('non-ISO calendar requires year (or era+eraYear)');
                }
            }

            // Now validate month code suitability (semantic check).
            if ($hasMonthCode) {
                if ($m < 1 || $m > 12) {
                    throw new RangeError("monthCode '{$mStr}' is not valid for ISO 8601 calendar");
                }
                static $pmdLunisolar = ['hebrew', 'chinese', 'dangi'];
                if ($hasLeap && !in_array($cal, $pmdLunisolar, true)) {
                    throw new RangeError("monthCode '{$mStr}' is not valid for ISO 8601 calendar");
                }
                // Check for monthCode/month conflict.
                if ($hasMonth) {
                    $monthNum = (int) TypeConversion::toNumber($month);
                    if ($monthNum !== $m) {
                        throw new RangeError("monthCode {$mStr} and month {$monthNum} conflict");
                    }
                }
            }

            if ($overflow === 'constrain') {
                // Months <= 0 are always invalid even with constrain.
                if ($m < 1) {
                    throw new RangeError("Invalid month: {$m}");
                }
                $m = min(12, $m);
                // Days <= 0 are always invalid even with constrain.
                if ($d < 1) {
                    throw new RangeError("Invalid day: {$d}");
                }
                $dim = self::isoDaysInMonth($refYear, $m);
                $d = min($dim, $d);
            } else {
                // reject: validate strictly.
                if ($m < 1 || $m > 12) {
                    throw new RangeError("Invalid month: {$m}");
                }
                $dim = self::isoDaysInMonth($refYear, $m);
                if ($d < 1 || $d > $dim) {
                    throw new RangeError("Invalid day: {$d} for month {$m} in year {$refYear}");
                }
            }
            // Non-ISO non-gregory: pick the reference ISO date for this calendar
            // M-d combo (latest ISO ≤ 1972).
            if ($cal !== 'iso8601' && !in_array($cal, ['gregory', 'roc', 'japanese'], true)) {
                $iso = self::pmdReferenceIsoFor($cal, $hasMonthCode ? $mStr : null, $hasMonthCode ? null : $m, $d);
                if ($iso !== null) {
                    return self::createPlainMonthDayObject($iso['month'], $iso['day'], $iso['year'], $cal);
                }
                // No valid reference ISO landed in calendar — invalid M-d
                // for this calendar (e.g. hebrew M01L, which is not a real
                // leap month).
                throw new RangeError(
                    "Invalid PlainMonthDay for calendar '{$cal}'",
                );
            }
            return self::createPlainMonthDayObject($m, $d, 1972, $cal);
        }
        $str = TypeConversion::toString($item);
        return self::parsePlainMonthDayString($str);
    }

    /**
     * Like toPlainMonthDay but reads overflow from options AFTER reading fields from the item.
     * This is necessary to satisfy observable property access order per the spec.
     */
    private static function toPlainMonthDayWithLazyOptions(JsValue $item, JsValue $options): JsObject
    {
        if ($item instanceof JsUndefined || $item instanceof JsNull) {
            throw new TypeError('Cannot convert undefined or null to a Temporal.PlainMonthDay');
        }
        if ($item instanceof JsBoolean || $item instanceof JsNumber || $item instanceof JsBigInt) {
            throw new TypeError('Cannot convert primitive to a Temporal.PlainMonthDay');
        }
        if ($item instanceof JsObject && $item->has('[[IsPlainMonthDay]]')) {
            $result = self::createPlainMonthDayObject(
                self::getSlotInt($item, '[[ISOMonth]]'),
                self::getSlotInt($item, '[[ISODay]]'),
                self::getSlotInt($item, '[[ISOYear]]'),
                self::getSlotString($item, '[[Calendar]]'),
            );
            self::getOverflow($options);
            return $result;
        }
        if ($item instanceof JsObject) {
            // Read fields in alphabetical order per spec, coercing immediately.
            // 1. calendar
            $calVal = $item->get('calendar');
            $cal = 'iso8601';
            if (!($calVal instanceof JsUndefined)) {
                $cal = self::toCalendarSlotValue($calVal);
            }

            // 2. day (read and coerce immediately)
            $dayVal = $item->get('day');
            $hasDay = !($dayVal instanceof JsUndefined);
            $d = 0;
            if ($hasDay) {
                $dNum = TypeConversion::toNumber($dayVal);
                if (!is_finite($dNum)) {
                    throw new RangeError('day must be finite');
                }
                $d = (int) $dNum;
            }

            // 3. era / eraYear (non-ISO calendars only).
            $eraStr = null;
            $eraYearNum = null;
            $eraSet = false;
            $eraYearSet = false;
            if ($cal !== 'iso8601') {
                $eraVal = $item->get('era');
                if (!($eraVal instanceof JsUndefined)) {
                    $eraSet = true;
                    $eraStr = TypeConversion::toString($eraVal);
                }
                $eraYearVal = $item->get('eraYear');
                if (!($eraYearVal instanceof JsUndefined)) {
                    $eraYearSet = true;
                    $eraYearNum = TypeConversion::toNumber($eraYearVal);
                    if (is_nan($eraYearNum) || !is_finite($eraYearNum)) {
                        throw new RangeError('eraYear must be finite');
                    }
                    if (floor($eraYearNum) !== $eraYearNum) {
                        throw new RangeError('eraYear must be an integer');
                    }
                }
                static $pmdLazyEraCals = ['gregory', 'japanese', 'roc'];
                if (in_array($cal, $pmdLazyEraCals, true) && $eraSet !== $eraYearSet) {
                    throw new TypeError('era and eraYear must be provided together');
                }
            }

            // 4. month (read and coerce immediately)
            $monthVal = $item->get('month');
            $hasMonth = !($monthVal instanceof JsUndefined);
            $monthNum = 0;
            if ($hasMonth) {
                $mVal = TypeConversion::toNumber($monthVal);
                if (!is_finite($mVal)) {
                    throw new RangeError('month must be finite');
                }
                $monthNum = (int) $mVal;
            }

            // 5. monthCode (read and coerce immediately)
            $monthCodeVal = $item->get('monthCode');
            $hasMonthCode = !($monthCodeVal instanceof JsUndefined);
            $m = 0;
            $hasLeap = false;
            $mStr = '';
            if ($hasMonthCode) {
                $mStr = TypeConversion::toString($monthCodeVal);
                if (!preg_match('/^M(\d{2})(L?)$/', $mStr, $mcm)) {
                    throw new RangeError("Invalid monthCode: {$mStr}");
                }
                $m = (int) $mcm[1];
                $hasLeap = $mcm[2] === 'L';
            }

            // 6. year (read and coerce immediately)
            $yearVal = $item->get('year');
            $hasYear = !($yearVal instanceof JsUndefined);
            $refYear = 1972;
            if ($hasYear) {
                $yVal = TypeConversion::toNumber($yearVal);
                if (!is_finite($yVal)) {
                    throw new RangeError('year must be finite');
                }
                // ROC field-bag year is 1-based from 1912 ("民國" 1 =
                // 1912 AD). All downstream day/month math wants the ISO
                // year, so translate up front.
                $refYear = $cal === 'roc' ? ((int) $yVal + 1911) : (int) $yVal;
                if ($eraYearNum !== null) {
                    static $pmdEraDerivCals3 = ['gregory', 'japanese', 'roc'];
                    if (in_array($cal, $pmdEraDerivCals3, true)) {
                        $eraLower = $eraStr === null ? '' : strtolower($eraStr);
                        $derivedYear = null;
                        if ($cal === 'gregory') {
                            $derivedYear = in_array($eraLower, ['bc', 'bce', 'gregory-inverse'], true)
                                ? (1 - (int) $eraYearNum)
                                : (int) $eraYearNum;
                        } elseif ($cal === 'roc') {
                            $derivedYear = ($eraLower === 'roc-inverse' || $eraLower === 'before-roc')
                                ? (int) (1912 - $eraYearNum)
                                : (int) (1911 + $eraYearNum);
                        } elseif ($cal === 'japanese') {
                            $isoY = self::japaneseEraToIsoYear($eraLower, (int) $eraYearNum);
                            if ($isoY !== null) {
                                $derivedYear = $isoY;
                            }
                        }
                        if ($derivedYear !== null && $derivedYear !== $refYear) {
                            throw new RangeError(
                                "era/eraYear and year disagree: derived {$derivedYear} vs explicit {$refYear}",
                            );
                        }
                    }
                }
            } else {
                static $pmdEraDerivCals2 = ['gregory', 'japanese', 'roc'];
                if (
                    $eraYearNum !== null
                    && in_array($cal, $pmdEraDerivCals2, true)
                ) {
                    $eraLower = $eraStr === null ? '' : strtolower($eraStr);
                    $refYear = in_array($eraLower, ['bc', 'bce'], true)
                        ? (1 - (int) $eraYearNum)
                        : (int) $eraYearNum;
                    $hasYear = true;
                }
            }

            // Validate required fields.
            if (!$hasDay) {
                throw new TypeError('Required property day missing');
            }
            if (!$hasMonthCode && !$hasMonth) {
                throw new TypeError('Required property month or monthCode missing');
            }
            // Non-ISO calendars require year info when month is given numerically.
            if ($cal !== 'iso8601' && !$hasYear && !$hasMonthCode) {
                throw new TypeError('non-ISO calendar requires year (or era+eraYear) when month is given numerically');
            }

            // Resolve month from monthCode or month.
            if (!$hasMonthCode) {
                $m = $monthNum;
            }

            // Validate month code suitability after year type validation.
            if ($hasMonthCode) {
                static $pmdHas13Month = ['coptic', 'ethioaa', 'ethiopic'];
                $maxMonthCode = in_array($cal, $pmdHas13Month, true) ? 13 : 12;
                if ($m < 1 || $m > $maxMonthCode) {
                    throw new RangeError("monthCode '{$mStr}' is not valid for ISO 8601 calendar");
                }
                static $pmdLunisolar2 = ['hebrew', 'chinese', 'dangi'];
                if ($hasLeap && !in_array($cal, $pmdLunisolar2, true)) {
                    throw new RangeError("monthCode '{$mStr}' is not valid for ISO 8601 calendar");
                }
                if ($hasMonth && $monthNum !== $m) {
                    throw new RangeError("monthCode {$mStr} and month {$monthNum} conflict");
                }
            }

            // NOW read overflow from options (after all field reads).
            $overflow = self::getOverflow($options);

            static $pmdHas13MonthsConstrain = ['coptic', 'ethioaa', 'ethiopic'];
            $maxMonth = in_array($cal, $pmdHas13MonthsConstrain, true) ? 13 : 12;
            // For lunisolar calendars (hebrew/chinese/dangi), the month
            // count varies year-to-year. With an explicit year, cap by
            // that year's actual monthsInYear so month:15 in a leap
            // hebrew year clamps to 13 (Elul) rather than 12 (Av).
            if (
                $hasYear
                && in_array($cal, ['hebrew', 'chinese', 'dangi'], true)
            ) {
                $isoForCount = self::calendarPartsToIso($cal, $refYear, null, 1, 1);
                if ($isoForCount !== null) {
                    $miyForRefYear = self::calendarMonthsInYear(
                        $cal,
                        $isoForCount['year'],
                        $isoForCount['month'],
                        $isoForCount['day'],
                    );
                    if ($miyForRefYear !== null) {
                        $maxMonth = $miyForRefYear;
                    }
                }
            }
            // When the user provided a year, the day limit is that year's
            // actual days-in-month (not the calendar-wide max).
            $useYearDim = $hasYear && in_array($cal, ['iso8601', 'gregory', 'roc', 'japanese'], true);
            if ($overflow === 'constrain') {
                if ($m < 1) {
                    throw new RangeError("Invalid month: {$m}");
                }
                $m = min($maxMonth, $m);
                if ($d < 1) {
                    throw new RangeError("Invalid day: {$d}");
                }
                if ($useYearDim) {
                    $dim = self::isoDaysInMonth($refYear, $m);
                } elseif ($cal === 'iso8601') {
                    $dim = self::isoDaysInMonth($refYear, $m);
                } else {
                    $dim = self::maxDaysInCalendarMonth($cal, $m, $hasLeap);
                }
                $d = min($dim, $d);
            } else {
                if ($m < 1 || $m > $maxMonth) {
                    throw new RangeError("Invalid month: {$m}");
                }
                if ($useYearDim) {
                    $dim = self::isoDaysInMonth($refYear, $m);
                } elseif ($cal === 'iso8601') {
                    $dim = self::isoDaysInMonth($refYear, $m);
                } else {
                    $dim = self::maxDaysInCalendarMonth($cal, $m, $hasLeap);
                }
                if ($d < 1 || $d > $dim) {
                    throw new RangeError("Invalid day: {$d}");
                }
            }
            // Non-ISO non-gregory: pick the reference ISO date for this calendar
            // M-d combo. For Hebrew with explicit year, also constrain day by
            // the actual days-in-month for that calendar year.
            if ($cal !== 'iso8601' && !in_array($cal, ['gregory', 'roc', 'japanese'], true)) {
                if ($hasYear) {
                    $maxD = self::calendarDaysInMonth($cal, $refYear, $hasMonthCode ? $mStr : null, $hasMonthCode ? null : $m);
                    if ($maxD !== null) {
                        if ($overflow === 'reject' && $d > $maxD) {
                            throw new RangeError("Invalid day: {$d} for {$mStr} in calendar year {$refYear}");
                        }
                        $d = min($d, $maxD);
                    }
                }
                // When the user supplied year+month (no monthCode) for a
                // lunisolar calendar, resolve the monthCode in that year's
                // space so a PMD constructed in a leap year carries the
                // correct ML / non-L suffix. Otherwise PMD throws away the
                // year and the search around 1972 may pick a non-leap year.
                $resolvedMonthCode = $hasMonthCode ? $mStr : null;
                if (
                    !$hasMonthCode
                    && $hasYear
                    && in_array($cal, ['hebrew', 'chinese', 'dangi'], true)
                ) {
                    $resolved = self::calendarPartsToIso($cal, $refYear, null, $m, $d);
                    if ($resolved !== null) {
                        $back = self::isoToCalendarParts($cal, $resolved['year'], $resolved['month'], $resolved['day']);
                        if ($back !== null) {
                            $resolvedMonthCode = $back['monthCode'];
                        }
                    }
                }
                $iso = self::pmdReferenceIsoFor(
                    $cal,
                    $resolvedMonthCode,
                    $resolvedMonthCode === null ? $m : null,
                    $d,
                );
                if ($iso !== null) {
                    return self::createPlainMonthDayObject($iso['month'], $iso['day'], $iso['year'], $cal);
                }
                throw new RangeError(
                    "Invalid PlainMonthDay for calendar '{$cal}'",
                );
            }
            return self::createPlainMonthDayObject($m, $d, 1972, $cal);
        }
        $str = TypeConversion::toString($item);
        return self::parsePlainMonthDayString($str);
    }

    private static function parsePlainMonthDayString(string $str): JsObject
    {
        [$str, $calFromAnnotation] = self::normalizeTemporalString($str);

        // Reject UTC designator (Z) for PlainMonthDay.
        // Check after date/time portion, not inside annotations.
        $noAnnotation = preg_replace('/\[.*?\]/', '', $str);
        if (preg_match('/[Zz]/', $noAnnotation)) {
            throw new RangeError("String with UTC designator should not be valid as a PlainMonthDay: {$str}");
        }

        // Collect all bracket annotations.
        preg_match_all('/\[(!?)([^\]]+)\]/', $str, $annotations, PREG_SET_ORDER);

        // Reject multiple calendar annotations if any is critical.
        $calAnnotations = [];
        $tzAnnotations = [];
        foreach ($annotations as $ann) {
            $critical = $ann[1] === '!';
            $content = $ann[2];
            if (str_starts_with($content, 'u-ca=')) {
                $calAnnotations[] = ['critical' => $critical, 'value' => substr($content, 5)];
            } elseif (str_contains($content, '=')) {
                // Key-value annotation. Keys must be lowercase.
                $eqPos = strpos($content, '=');
                $key = substr($content, 0, $eqPos);
                if ($key !== strtolower($key)) {
                    throw new RangeError("annotation keys must be lowercase: {$str}");
                }
                // Unknown key-value annotation. If critical, reject.
                if ($critical) {
                    throw new RangeError("reject unknown annotation with critical flag: {$str}");
                }
            } else {
                // Timezone annotation.
                if ($critical && !self::isValidTimeZoneAnnotation($content)) {
                    throw new RangeError("reject unknown annotation with critical flag: {$str}");
                }
                $tzAnnotations[] = $content;
            }
        }

        if (count($calAnnotations) > 1) {
            foreach ($calAnnotations as $ca) {
                if ($ca['critical']) {
                    throw new RangeError("reject more than one calendar annotation if any critical: {$str}");
                }
            }
        }

        // Reject multiple time zone annotations.
        if (count($tzAnnotations) > 1) {
            throw new RangeError("reject more than one time zone annotation: {$str}");
        }

        // Reject -000000 (minus zero year).
        if (preg_match('/^-0{4,6}-/', $str)) {
            throw new RangeError("reject minus zero as extended year: {$str}");
        }

        // Remove annotations for structural matching (already collected above).
        $cleanStr = preg_replace('/\[.*?\]/', '', $str);

        // MM-DD, --MM-DD, or MMDD format (with optional annotations).
        $pattern = '/^(?:--)?(\d{2})-?(\d{2})$/';
        if (preg_match($pattern, $cleanStr, $m)) {
            $mo = (int) $m[1];
            $dd = (int) $m[2];
            if ($mo < 1 || $mo > 12 || $dd < 1 || $dd > self::isoDaysInMonth(1972, $mo)) {
                throw new RangeError("Invalid PlainMonthDay: {$str}");
            }
            $cal = 'iso8601';
            if (!empty($calAnnotations)) {
                $cal = strtolower($calAnnotations[0]['value']);
                // For MM-DD format, only iso8601 calendar is valid.
                if ($cal !== 'iso8601') {
                    throw new RangeError("non-iso8601 calendar not valid with month-day format: {$str}");
                }
            }
            return self::createPlainMonthDayObject($mo, $dd, 1972, $cal);
        }

        // Check for UTC offset without time in MM-DD or --MM-DD format.
        if (preg_match('/^(?:--)?(\d{2})-(\d{2})[Zz+\-]/', $cleanStr)) {
            throw new RangeError("UTC offset without time is not valid for PlainMonthDay: {$str}");
        }

        // Full ISO date with optional time and offset.
        // First check for date-only with offset (no time): reject.
        // Use greedy year match (\d{4,6}) to avoid backtracking into date.
        if (
            preg_match('/^([+-]?\d{4,6})-(\d{2})-(\d{2})[Zz+\-]/', $cleanStr)
            && !preg_match('/^([+-]?\d{4,6})-(\d{2})-(\d{2})[Tt ]/', $cleanStr)
        ) {
            throw new RangeError("UTC offset without time is not valid for PlainMonthDay: {$str}");
        }
        // Same for compact format YYYYMMDD.
        if (
            preg_match('/^([+-]?\d{4,6})(\d{2})(\d{2})[Zz+\-]/', $cleanStr)
            && !preg_match('/^([+-]?\d{4,6})(\d{2})(\d{2})[Tt ]/', $cleanStr)
        ) {
            throw new RangeError("UTC offset without time is not valid for PlainMonthDay: {$str}");
        }

        // Full ISO date with optional time (captured for validation).
        // UTC offset may include seconds and fractional seconds (extended format).
        $offsetOpt = '(?:[+-]\d{2}(?::?\d{2}(?::?\d{2}(?:[.,]\d{1,9})?)?)?)?';
        $timeOpt = "(?:[Tt ](\d{2})(?::?(\d{2})(?::?(\d{2})(?:[.,]\d{1,9})?)?)?{$offsetOpt})?";
        $pattern2 = "/^([+-]?\\d{4,6})-?(\\d{2})-?(\\d{2}){$timeOpt}\$/";
        if (preg_match($pattern2, $cleanStr, $m)) {
            // Validate time if present.
            if (isset($m[4]) && $m[4] !== '') {
                $th = (int) $m[4];
                $tmin = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : 0;
                $ts = isset($m[6]) ? (int) $m[6] : 0;
                if ($ts === 60) {
                    $ts = 59;
                }
                if ($th > 23 || $tmin > 59 || $ts > 59) {
                    throw new RangeError("Invalid time in string: {$str}");
                }
            }
            $mo = (int) $m[2];
            $dd = (int) $m[3];
            // Extract calendar annotation if present.
            $cal = 'iso8601';
            if (!empty($calAnnotations)) {
                $cal = strtolower($calAnnotations[0]['value']);
            }
            // Canonicalize CLDR aliases on the parsed calendar id
            // so islamicc resolves to islamic-civil etc.
            static $calAliasPmd = [
                'islamicc' => 'islamic-civil',
                'ethiopic-amete-alem' => 'ethioaa',
                'gregorian' => 'gregory',
            ];
            if (isset($calAliasPmd[$cal])) {
                $cal = $calAliasPmd[$cal];
            }
            // Validate the date.
            $y = (int) $m[1];
            if ($mo < 1 || $mo > 12) {
                throw new RangeError("Invalid PlainMonthDay: {$str}");
            }
            $dim = self::isoDaysInMonth($y, $mo);
            if ($dd < 1 || $dd > $dim) {
                throw new RangeError("Invalid PlainMonthDay: {$str}");
            }
            // Non-ISO calendars need a representable year for the
            // reference date; clamp to the ISO range and reject
            // anything beyond.
            if ($cal !== 'iso8601') {
                if ($y < self::ISO_YEAR_MIN || $y > self::ISO_YEAR_MAX) {
                    throw new RangeError(
                        "Year {$y} out of range for non-ISO calendar in PlainMonthDay: {$str}",
                    );
                }
                // Normalise to the spec's reference ISO year (≤ 1972) so the
                // PlainMonthDay round-trips through equals/since/until without
                // pinning a specific historical year.
                if (!in_array($cal, ['gregory', 'roc', 'japanese'], true)) {
                    $back = self::isoToCalendarParts($cal, $y, $mo, $dd);
                    if ($back !== null) {
                        $iso = self::pmdReferenceIsoFor(
                            $cal,
                            $back['monthCode'],
                            null,
                            $back['day'],
                        );
                        if ($iso !== null) {
                            return self::createPlainMonthDayObject($iso['month'], $iso['day'], $iso['year'], $cal);
                        }
                    }
                }
            }
            $refYear = $cal === 'iso8601' ? 1972 : $y;
            return self::createPlainMonthDayObject($mo, $dd, $refYear, $cal);
        }
        throw new RangeError("Invalid PlainMonthDay string: {$str}");
    }

    private static function isValidTimeZoneAnnotation(string $content): bool
    {
        // Annotations with = are key-value pairs (calendar, etc.), not timezone.
        if (str_contains($content, '=')) {
            return false;
        }
        // Valid timezone annotations are IANA names (e.g., "UTC", "America/New_York")
        // or numeric offsets (e.g., "+05:30", "-02:30").
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_+\-\/]*$/', $content)) {
            return true;
        }
        if (preg_match('/^[+-]\d{2}:?\d{2}$/', $content)) {
            return true;
        }
        return false;
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

    /** Check if a calendar identifier is valid for our implementation. */
    private static function isValidCalendar(string $cal): bool
    {
        // We only support iso8601 and IANA calendar names.
        // Per spec, calendar identifiers must be ASCII lowercase
        // and match the syntax of Unicode BCP 47 type subtags.
        if ($cal === 'iso8601') {
            return true;
        }
        // Allow known IANA calendar names.
        $known = [
            'buddhist', 'chinese', 'coptic', 'dangi', 'ethioaa',
            'ethiopic', 'gregory', 'hebrew', 'indian', 'islamic',
            'islamic-umalqura', 'islamic-tbla', 'islamic-civil',
            'islamic-rgsa', 'islamicc', 'japanese', 'persian', 'roc',
        ];
        return in_array($cal, $known, true);
    }

    /**
     * Validate monthCode syntax only (M followed by 2 digits, optionally L).
     * Rejects purely malformed codes like "m01", "M1", "L99M".
     * Returns [month_number, is_leap] without checking calendar validity.
     *
     * @return array{0: int, 1: bool}
     */
    private static function parseMonthCodeSyntax(string $mc): array
    {
        if (!preg_match('/^M(\d{2})(L?)$/', $mc, $mcm)) {
            throw new RangeError("Invalid monthCode: {$mc}");
        }
        return [(int) $mcm[1], $mcm[2] === 'L'];
    }

    /**
     * Parse and fully validate a monthCode string for ISO 8601 calendar.
     * Returns month number 1-12.
     */
    private static function parseMonthCode(string $mc, string $cal = 'iso8601'): int
    {
        [$month, $isLeap] = self::parseMonthCodeSyntax($mc);
        // Coptic / Ethiopic / EthioAA have a 13th epagomenal month;
        // their valid monthCodes are M01..M13. Other calendars cap at 12.
        $maxMonth = in_array($cal, ['coptic', 'ethiopic', 'ethioaa'], true) ? 13 : 12;
        if ($month < 1 || $month > $maxMonth) {
            throw new RangeError("monthCode '{$mc}' is not valid for calendar '{$cal}'");
        }
        if ($isLeap) {
            // Leap months exist only in lunisolar calendars.
            static $lunisolarCals = ['hebrew', 'chinese', 'dangi'];
            if (!in_array($cal, $lunisolarCals, true)) {
                throw new RangeError(
                    "monthCode '{$mc}' leap-month suffix is not valid for calendar '{$cal}'",
                );
            }
            // Hebrew: only M05L (Adar I) exists. Reject M01L-M04L,
            // M06L-M12L unconditionally.
            if ($cal === 'hebrew' && $month !== 5) {
                throw new RangeError(
                    "monthCode '{$mc}' is not a valid hebrew leap-month code",
                );
            }
        }
        return $month;
    }

    private static function pad2(int $n): string
    {
        return str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Normalize a Temporal ISO string: replace Unicode minus, validate annotations.
     * Returns the normalized string. Throws RangeError for critical unknown annotations
     * or duplicate critical calendar annotations.
     *
     * @return array{0: string, 1: string} [normalized string, calendar ID]
     */
    private static function normalizeTemporalString(string $str): array
    {
        // Reject Unicode minus sign (U+2212) per spec.
        if (str_contains($str, "\xE2\x88\x92")) {
            throw new RangeError("Non-ASCII minus sign is not acceptable: {$str}");
        }
        // Parse annotations.
        $cal = 'iso8601';
        $calCount = 0;
        $hasCriticalCal = false;
        preg_match_all('/\[(!?)([^\]]+)\]/', $str, $anns, PREG_SET_ORDER);
        foreach ($anns as $ann) {
            $critical = $ann[1] === '!';
            $content = $ann[2];
            if (str_starts_with($content, 'u-ca=')) {
                $calCount++;
                if ($calCount === 1) {
                    // Use FIRST calendar annotation per spec.
                    $cal = strtolower(substr($content, 5));
                }
                if ($critical) {
                    $hasCriticalCal = true;
                }
            } elseif (str_contains($content, '=')) {
                // Per spec: annotation keys must be lowercase ASCII.
                $key = substr($content, 0, (int) strpos($content, '='));
                if ($key !== strtolower($key)) {
                    throw new RangeError(
                        "annotation keys must be lowercase: {$str}"
                        . " - invalid capitalized key"
                    );
                }
                if ($critical) {
                    throw new RangeError(
                        "reject unknown annotation with critical flag: {$str}"
                    );
                }
            }
        }
        if ($calCount > 1 && $hasCriticalCal) {
            throw new RangeError(
                "reject more than one calendar annotation if any critical: {$str}"
            );
        }
        // Canonicalize CLDR aliases on the parsed calendar id so
        // downstream "calendar must be iso8601" checks see the
        // resolved form (islamicc → islamic-civil, etc.).
        static $calAliasNorm = [
            'islamicc' => 'islamic-civil',
            'ethiopic-amete-alem' => 'ethioaa',
            'gregorian' => 'gregory',
        ];
        if (isset($calAliasNorm[$cal])) {
            $cal = $calAliasNorm[$cal];
        }
        // Count timezone annotations (non-key-value: no '=').
        $tzCount = 0;
        foreach ($anns as $ann) {
            $content = $ann[2];
            if (!str_contains($content, '=')) {
                $tzCount++;
                // Reject sub-minute offsets in timezone annotations.
                // Matches both colon-separated (+HH:MM:SS) and compact (+HHMMSS) forms.
                if (
                    preg_match('/^[+-]\d{2}:?\d{2}:?\d{2}/', $content)
                    || preg_match('/^[+-]\d{2}:?\d{2}[.,]/', $content)
                ) {
                    throw new RangeError(
                        "ISO strings cannot have sub-minute offsets in time zone annotations: {$str}"
                    );
                }
            }
        }
        if ($tzCount > 1) {
            throw new RangeError(
                "reject more than one time zone annotation: {$str}"
            );
        }
        return [$str, $cal];
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

    private static function formatISOTime(
        int $h,
        int $min,
        int $s,
        int $ms,
        int $us,
        int $ns,
        string|int $fractionalSecondDigits = 'auto',
        string $roundingMode = 'trunc',
    ): string {
        $nsPadded = str_pad((string) $ms, 3, '0', STR_PAD_LEFT)
            . str_pad((string) $us, 3, '0', STR_PAD_LEFT)
            . str_pad((string) $ns, 3, '0', STR_PAD_LEFT);
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

    private static function plainDateTimeToString(
        JsValue $this_,
        string|int $fractionalSecondDigits = 'auto',
        string $roundingMode = 'trunc',
        string $calendarName = 'auto',
    ): string {
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
        $showCal = $calendarName === 'always'
            || $calendarName === 'critical'
            || ($calendarName !== 'never' && $cal !== 'iso8601');
        if ($showCal) {
            $prefix = $calendarName === 'critical' ? '!' : '';
            $result .= "[{$prefix}u-ca={$cal}]";
        }
        return $result;
    }

    // -----------------------------------------------------------------------
    // Helpers: timezone
    // -----------------------------------------------------------------------

    /**
     * Resolve a timezone string to a PHP DateTimeZone.
     * Supports IANA names, UTC offsets, and ISO datetime strings with timezone annotations.
     */
    private static function resolveTimeZone(string $tz): \DateTimeZone
    {
        // IANA's "backward" file lists deprecated zone names as Link
        // entries pointing at a canonical zone. PHP's tzdata stores
        // both names with separate (often divergent pre-1970) offset
        // histories: e.g. Africa/Asmera carries the LMT for Addis
        // Ababa (+02:27:16) while the canonical Africa/Asmara uses
        // its own LMT (+02:35:20). Per the Temporal spec, the link
        // and target must produce identical offsets at every instant.
        // Resolve the canonical name and use its zone object for all
        // offset queries; the original [[TimeZone]] slot continues
        // to expose the input identifier.
        $canon = self::ianaLinkCanonical($tz);
        if ($canon !== null) {
            try {
                return new \DateTimeZone($canon);
            } catch (\Throwable) {
                // Fall through to direct lookup.
            }
        }
        // Try direct timezone ID first (IANA names, UTC, etc.).
        try {
            return new \DateTimeZone($tz);
        } catch (\Throwable) {
            // Fall through to parse as ISO string.
        }

        // Try to extract timezone from an ISO datetime string.
        $parsed = self::parseTimeZoneFromISOString($tz);
        if ($parsed !== null) {
            try {
                return new \DateTimeZone($parsed);
            } catch (\Throwable) {
                throw new RangeError("Invalid time zone: {$tz}");
            }
        }

        throw new RangeError("Invalid time zone: {$tz}");
    }

    /**
     * Return zone transitions within the [$start, $end] window. When
     * the zone is in the vendored tzdata snapshot bundle, consult the
     * bundle for host-independent transition values (test262 probes
     * specific historical and DST rows that lag on Ubuntu CI tzdata
     * but match macOS and the test reference). Falls through to PHP's
     * DateTimeZone::getTransitions for any zone not in the bundle.
     *
     * Output mirrors PHP's getTransitions shape: a synthetic
     * state-at-start row first (ts = $start, with the active offset
     * and isdst from the most recent earlier transition), followed by
     * the actual transitions in (start, end]. Each row carries at
     * minimum 'ts' (int), 'offset' (int), 'isdst' (bool).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function getTzTransitions(string $tz, \DateTimeZone $tzObj, int $start, int $end): array
    {
        static $bundle = null;
        if ($bundle === null) {
            $path = dirname(__DIR__, 2) . '/config/tzdata-snapshot.php';
            $bundle = is_file($path) ? require $path : [];
            if (!is_array($bundle)) {
                $bundle = [];
            }
        }
        // Resolve through the IANA link table so legacy names hit the
        // same bundle entry as their canonical target.
        $canon = self::ianaLinkCanonical($tz);
        $lookup = $canon ?? $tz;
        if (!isset($bundle[$lookup])) {
            return $tzObj->getTransitions($start, $end);
        }
        $rows = $bundle[$lookup];
        // Find the state-at-start row: the most recent transition at
        // or before $start. Bundle rows are sorted ascending by ts.
        $stateOffset = $rows[0]['offset'];
        $stateIsDst = $rows[0]['isdst'];
        $out = [];
        foreach ($rows as $r) {
            if ($r['ts'] <= $start) {
                $stateOffset = $r['offset'];
                $stateIsDst = $r['isdst'];
                continue;
            }
            if ($r['ts'] > $end) {
                break;
            }
            $out[] = ['ts' => $r['ts'], 'offset' => $r['offset'], 'isdst' => $r['isdst']];
        }
        array_unshift($out, ['ts' => $start, 'offset' => $stateOffset, 'isdst' => $stateIsDst]);
        return $out;
    }

    /**
     * Resolve a deprecated IANA Link name (from tzdata "backward")
     * to its canonical Zone target. Returns null when the input is
     * already canonical or unknown. Comparisons are case-insensitive
     * to match IANA's own case-folding rules. Tracks tzdata 2024b.
     */
    private static function ianaLinkCanonical(string $name): ?string
    {
        static $links = null;
        if ($links === null) {
            $links = [
                'africa/asmera' => 'Africa/Asmara',
                'africa/timbuktu' => 'Africa/Bamako',
                'america/argentina/comodrivadavia' => 'America/Argentina/Catamarca',
                'america/atka' => 'America/Adak',
                'america/buenos_aires' => 'America/Argentina/Buenos_Aires',
                'america/catamarca' => 'America/Argentina/Catamarca',
                'america/coral_harbour' => 'America/Atikokan',
                'america/cordoba' => 'America/Argentina/Cordoba',
                'america/ensenada' => 'America/Tijuana',
                'america/fort_wayne' => 'America/Indiana/Indianapolis',
                'america/godthab' => 'America/Nuuk',
                'america/indianapolis' => 'America/Indiana/Indianapolis',
                'america/jujuy' => 'America/Argentina/Jujuy',
                'america/knox_in' => 'America/Indiana/Knox',
                'america/louisville' => 'America/Kentucky/Louisville',
                'america/mendoza' => 'America/Argentina/Mendoza',
                'america/montreal' => 'America/Toronto',
                'america/nipigon' => 'America/Toronto',
                'america/pangnirtung' => 'America/Iqaluit',
                'america/porto_acre' => 'America/Rio_Branco',
                'america/rainy_river' => 'America/Winnipeg',
                'america/rosario' => 'America/Argentina/Cordoba',
                'america/santa_isabel' => 'America/Tijuana',
                'america/shiprock' => 'America/Denver',
                'america/thunder_bay' => 'America/Toronto',
                'america/virgin' => 'America/St_Thomas',
                'america/yellowknife' => 'America/Edmonton',
                'antarctica/south_pole' => 'Antarctica/McMurdo',
                'asia/ashkhabad' => 'Asia/Ashgabat',
                'asia/calcutta' => 'Asia/Kolkata',
                'asia/choibalsan' => 'Asia/Ulaanbaatar',
                'asia/chongqing' => 'Asia/Shanghai',
                'asia/chungking' => 'Asia/Shanghai',
                'asia/dacca' => 'Asia/Dhaka',
                'asia/harbin' => 'Asia/Shanghai',
                'asia/istanbul' => 'Europe/Istanbul',
                'asia/kashgar' => 'Asia/Urumqi',
                'asia/katmandu' => 'Asia/Kathmandu',
                'asia/macao' => 'Asia/Macau',
                'asia/rangoon' => 'Asia/Yangon',
                'asia/saigon' => 'Asia/Ho_Chi_Minh',
                'asia/tel_aviv' => 'Asia/Jerusalem',
                'asia/thimbu' => 'Asia/Thimphu',
                'asia/ujung_pandang' => 'Asia/Makassar',
                'asia/ulan_bator' => 'Asia/Ulaanbaatar',
                'atlantic/faeroe' => 'Atlantic/Faroe',
                'atlantic/jan_mayen' => 'Arctic/Longyearbyen',
                'australia/act' => 'Australia/Sydney',
                'australia/canberra' => 'Australia/Sydney',
                'australia/currie' => 'Australia/Hobart',
                'australia/lhi' => 'Australia/Lord_Howe',
                'australia/nsw' => 'Australia/Sydney',
                'australia/north' => 'Australia/Darwin',
                'australia/queensland' => 'Australia/Brisbane',
                'australia/south' => 'Australia/Adelaide',
                'australia/tasmania' => 'Australia/Hobart',
                'australia/victoria' => 'Australia/Melbourne',
                'australia/west' => 'Australia/Perth',
                'australia/yancowinna' => 'Australia/Broken_Hill',
                'brazil/acre' => 'America/Rio_Branco',
                'brazil/denoronha' => 'America/Noronha',
                'brazil/east' => 'America/Sao_Paulo',
                'brazil/west' => 'America/Manaus',
                'cet' => 'Europe/Brussels',
                'cst6cdt' => 'America/Chicago',
                'canada/atlantic' => 'America/Halifax',
                'canada/central' => 'America/Winnipeg',
                'canada/eastern' => 'America/Toronto',
                'canada/mountain' => 'America/Edmonton',
                'canada/newfoundland' => 'America/St_Johns',
                'canada/pacific' => 'America/Vancouver',
                'canada/saskatchewan' => 'America/Regina',
                'canada/yukon' => 'America/Whitehorse',
                'chile/continental' => 'America/Santiago',
                'chile/easterisland' => 'Pacific/Easter',
                'cuba' => 'America/Havana',
                'eet' => 'Europe/Athens',
                'est' => 'America/Panama',
                'est5edt' => 'America/New_York',
                'egypt' => 'Africa/Cairo',
                'eire' => 'Europe/Dublin',
                'europe/belfast' => 'Europe/London',
                'europe/kiev' => 'Europe/Kyiv',
                'europe/nicosia' => 'Asia/Nicosia',
                'europe/tiraspol' => 'Europe/Chisinau',
                'europe/uzhgorod' => 'Europe/Kyiv',
                'europe/zaporozhye' => 'Europe/Kyiv',
                'gb' => 'Europe/London',
                'gb-eire' => 'Europe/London',
                'hst' => 'Pacific/Honolulu',
                'hongkong' => 'Asia/Hong_Kong',
                'iceland' => 'Atlantic/Reykjavik',
                'iran' => 'Asia/Tehran',
                'israel' => 'Asia/Jerusalem',
                'jamaica' => 'America/Jamaica',
                'japan' => 'Asia/Tokyo',
                'kwajalein' => 'Pacific/Kwajalein',
                'libya' => 'Africa/Tripoli',
                'met' => 'Europe/Brussels',
                'mst' => 'America/Phoenix',
                'mst7mdt' => 'America/Denver',
                'mexico/bajanorte' => 'America/Tijuana',
                'mexico/bajasur' => 'America/Mazatlan',
                'mexico/general' => 'America/Mexico_City',
                'nz' => 'Pacific/Auckland',
                'nz-chat' => 'Pacific/Chatham',
                'navajo' => 'America/Denver',
                'prc' => 'Asia/Shanghai',
                'pst8pdt' => 'America/Los_Angeles',
                'pacific/enderbury' => 'Pacific/Kanton',
                'pacific/johnston' => 'Pacific/Honolulu',
                'pacific/ponape' => 'Pacific/Pohnpei',
                'pacific/samoa' => 'Pacific/Pago_Pago',
                'pacific/truk' => 'Pacific/Chuuk',
                'pacific/yap' => 'Pacific/Chuuk',
                'poland' => 'Europe/Warsaw',
                'portugal' => 'Europe/Lisbon',
                'roc' => 'Asia/Taipei',
                'rok' => 'Asia/Seoul',
                'singapore' => 'Asia/Singapore',
                'turkey' => 'Europe/Istanbul',
                'us/alaska' => 'America/Anchorage',
                'us/aleutian' => 'America/Adak',
                'us/arizona' => 'America/Phoenix',
                'us/central' => 'America/Chicago',
                'us/east-indiana' => 'America/Indiana/Indianapolis',
                'us/eastern' => 'America/New_York',
                'us/hawaii' => 'Pacific/Honolulu',
                'us/indiana-starke' => 'America/Indiana/Knox',
                'us/michigan' => 'America/Detroit',
                'us/mountain' => 'America/Denver',
                'us/pacific' => 'America/Los_Angeles',
                'us/samoa' => 'Pacific/Pago_Pago',
                'w-su' => 'Europe/Moscow',
                'wet' => 'Europe/Lisbon',
            ];
        }
        return $links[strtolower($name)] ?? null;
    }

    /**
     * Return true when the given name is the canonical target of one
     * or more deprecated Link entries. Used to bypass ICU's
     * canonicalization when ICU lags IANA's preferred direction.
     */
    private static function isIanaLinkTarget(string $name): bool
    {
        static $targets = null;
        if ($targets === null) {
            $targets = [
                'Africa/Asmara' => true, 'Africa/Bamako' => true,
                'Africa/Cairo' => true, 'Africa/Tripoli' => true,
                'America/Argentina/Buenos_Aires' => true, 'America/Argentina/Catamarca' => true,
                'America/Argentina/Cordoba' => true, 'America/Argentina/Jujuy' => true,
                'America/Argentina/Mendoza' => true,
                'America/Adak' => true, 'America/Atikokan' => true, 'America/Tijuana' => true,
                'America/Indiana/Indianapolis' => true, 'America/Indiana/Knox' => true,
                'America/Kentucky/Louisville' => true, 'America/Toronto' => true,
                'America/Iqaluit' => true, 'America/Rio_Branco' => true,
                'America/Winnipeg' => true, 'America/Edmonton' => true, 'America/Denver' => true,
                'America/Halifax' => true, 'America/St_Johns' => true, 'America/Vancouver' => true,
                'America/Regina' => true, 'America/Whitehorse' => true, 'America/Santiago' => true,
                'America/Havana' => true, 'America/Panama' => true, 'America/New_York' => true,
                'America/Nuuk' => true, 'America/St_Thomas' => true, 'America/Sao_Paulo' => true,
                'America/Manaus' => true, 'America/Noronha' => true, 'America/Mazatlan' => true,
                'America/Mexico_City' => true, 'America/Phoenix' => true, 'America/Chicago' => true,
                'America/Detroit' => true, 'America/Los_Angeles' => true, 'America/Anchorage' => true,
                'America/Jamaica' => true,
                'Antarctica/McMurdo' => true,
                'Asia/Ashgabat' => true, 'Asia/Kolkata' => true, 'Asia/Ulaanbaatar' => true,
                'Asia/Shanghai' => true, 'Asia/Dhaka' => true, 'Asia/Urumqi' => true,
                'Asia/Kathmandu' => true, 'Asia/Macau' => true, 'Asia/Yangon' => true,
                'Asia/Ho_Chi_Minh' => true, 'Asia/Jerusalem' => true, 'Asia/Thimphu' => true,
                'Asia/Makassar' => true, 'Asia/Hong_Kong' => true, 'Asia/Tehran' => true,
                'Asia/Tokyo' => true, 'Asia/Taipei' => true, 'Asia/Seoul' => true,
                'Asia/Singapore' => true, 'Asia/Nicosia' => true,
                'Atlantic/Faroe' => true, 'Atlantic/Reykjavik' => true,
                'Arctic/Longyearbyen' => true,
                'Australia/Sydney' => true, 'Australia/Hobart' => true, 'Australia/Lord_Howe' => true,
                'Australia/Darwin' => true, 'Australia/Brisbane' => true, 'Australia/Adelaide' => true,
                'Australia/Melbourne' => true, 'Australia/Perth' => true, 'Australia/Broken_Hill' => true,
                'Europe/Athens' => true, 'Europe/Brussels' => true, 'Europe/Dublin' => true,
                'Europe/London' => true, 'Europe/Kyiv' => true, 'Europe/Chisinau' => true,
                'Europe/Lisbon' => true, 'Europe/Moscow' => true, 'Europe/Warsaw' => true,
                'Europe/Istanbul' => true,
                'Pacific/Auckland' => true, 'Pacific/Chatham' => true, 'Pacific/Easter' => true,
                'Pacific/Honolulu' => true, 'Pacific/Kanton' => true, 'Pacific/Kwajalein' => true,
                'Pacific/Pago_Pago' => true, 'Pacific/Pohnpei' => true, 'Pacific/Chuuk' => true,
            ];
        }
        return isset($targets[$name]);
    }

    /**
     * Extract a timezone identifier from an ISO datetime string.
     * Returns an IANA name (from bracket annotation) or a UTC offset, or null if not a valid tz string.
     */
    private static function parseTimeZoneFromISOString(string $str): ?string
    {
        // Reject minus zero year.
        if (preg_match('/^-0{4,6}-/', $str)) {
            throw new RangeError("reject minus zero as extended year: {$str}");
        }

        // Must look like a datetime string.
        // Pattern: date T time [offset] [annotation]
        // Allow seconds of 60 for leap second in the time portion.
        $dateTime = '/^(?:[+-]?\d{4,6})(?:-\d{2}-\d{2}|\d{4})[T ]\d{2}:?\d{2}/';
        if (!preg_match($dateTime, $str)) {
            return null;
        }

        // Reject bare datetime strings with no offset and no annotation.
        // First check for annotation brackets.
        $hasAnnotation = str_contains($str, '[');
        // Check for offset: Z or +/- after the time portion.
        // Need to be careful not to match the sign in the year part.
        $afterTime = preg_replace('/^[^T ]+[T ]/', '', $str);
        $hasOffset = (bool) preg_match('/[Zz]|[+-]\d{2}/', $afterTime);
        if (!$hasOffset && !$hasAnnotation) {
            return null;
        }

        // If there is a bracket annotation, extract the IANA name or offset-based tz.
        if (preg_match('/\[([^\]]+)\]/', $str, $m)) {
            $annotation = $m[1];
            // Skip calendar annotations.
            if (!str_starts_with($annotation, 'u-ca=') && !str_starts_with($annotation, '!u-ca=')) {
                // Validate: if this is a numeric offset with seconds (e.g., +23:59:60), reject it.
                if (preg_match('/^[+-]\d{2}:\d{2}:\d{2}/', $annotation)) {
                    throw new RangeError("leap second in time zone name not valid: {$str}");
                }
                return $annotation;
            }
        }

        // Extract the offset from the string (after time portion).
        // Reject sub-minute offsets (seconds portion in offset).
        if (preg_match('/([Zz]|[+-]\d{2}:?\d{2}(?::?\d{2}(?:[.,]\d+)?)?)(?:\[|$)/', $afterTime, $om)) {
            $offset = $om[1];
            if (strtoupper($offset) === 'Z') {
                return 'UTC';
            }
            // Check for sub-minute offset: if offset has seconds part, reject it.
            $cleanOffset = preg_replace('/[^0-9+-]/', '', $offset);
            // After removing non-digits (except sign), format is: +HHMM or +HHMMSS...
            $digits = ltrim($cleanOffset, '+-');
            if (strlen($digits) > 4) {
                // Has seconds component: always invalid as timezone, even if zero.
                throw new RangeError("ISO string with a sub-minute offset is not a valid time zone: {$str}");
            }
            // Also check for fractional seconds in the offset.
            if (preg_match('/[.,]\d+/', $offset)) {
                throw new RangeError("ISO string with a sub-minute offset is not a valid time zone: {$str}");
            }
            // Normalize to +HH:MM format.
            $sign = $offset[0];
            $h = substr($digits, 0, 2);
            $min = strlen($digits) >= 4 ? substr($digits, 2, 2) : '00';
            return "{$sign}{$h}:{$min}";
        }

        return null;
    }

    /**
     * @return array{year: int, month: int, day: int, hour: int, minute: int, second: int, millisecond: int, microsecond: int, nanosecond: int}
     */
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

    /** Compute difference between two PlainDateTimes as a Duration. */
    private static function plainDateTimeDifference(
        JsValue $dt1,
        JsValue $dt2,
        JsValue $options,
        int $sign = 1,
        ?JsValue $anchor = null,
    ): JsObject {
        $cal1 = self::getSlotString($dt1, '[[Calendar]]');
        $cal2 = self::getSlotString($dt2, '[[Calendar]]');
        if ($cal1 !== $cal2) {
            throw new RangeError(
                "calendar IDs do not match: {$cal1} vs {$cal2}",
            );
        }
        $ns1 = self::isoDateTimeToEpochNs(
            self::getSlotInt($dt1, '[[ISOYear]]'),
            self::getSlotInt($dt1, '[[ISOMonth]]'),
            self::getSlotInt($dt1, '[[ISODay]]'),
            self::getSlotInt($dt1, '[[ISOHour]]'),
            self::getSlotInt($dt1, '[[ISOMinute]]'),
            self::getSlotInt($dt1, '[[ISOSecond]]'),
            self::getSlotInt($dt1, '[[ISOMillisecond]]'),
            self::getSlotInt($dt1, '[[ISOMicrosecond]]'),
            self::getSlotInt($dt1, '[[ISONanosecond]]'),
            'UTC',
        );
        $ns2 = self::isoDateTimeToEpochNs(
            self::getSlotInt($dt2, '[[ISOYear]]'),
            self::getSlotInt($dt2, '[[ISOMonth]]'),
            self::getSlotInt($dt2, '[[ISODay]]'),
            self::getSlotInt($dt2, '[[ISOHour]]'),
            self::getSlotInt($dt2, '[[ISOMinute]]'),
            self::getSlotInt($dt2, '[[ISOSecond]]'),
            self::getSlotInt($dt2, '[[ISOMillisecond]]'),
            self::getSlotInt($dt2, '[[ISOMicrosecond]]'),
            self::getSlotInt($dt2, '[[ISONanosecond]]'),
            'UTC',
        );
        $diffNs = bcsub($ns2, $ns1, 0);
        $largestUnit = 'day';
        $largestUnitExplicit = false;
        $smallestUnit = 'nanosecond';
        if ($options instanceof JsObject) {
            $lu = $options->get('largestUnit');
            if (!($lu instanceof JsUndefined)) {
                $largestUnitExplicit = true;
                $largestUnit = TypeConversion::toString($lu);
                if ($largestUnit === 'auto') {
                    $largestUnit = 'day';
                    $largestUnitExplicit = false;
                } else {
                    $largestUnit = self::canonicalTemporalUnit($largestUnit);
                }
            }
            // Alphabetical: roundingIncrement, roundingMode, smallestUnit.
            $ri = $options->get('roundingIncrement');
            if (!($ri instanceof JsUndefined)) {
                $riNum = TypeConversion::toNumber($ri);
                if (!is_finite($riNum)) {
                    throw new RangeError("Invalid roundingIncrement");
                }
                $riNum = (int) $riNum;
                if ($riNum < 1 || $riNum > 1_000_000_000) {
                    throw new RangeError("Invalid roundingIncrement");
                }
            }
            $rm = $options->get('roundingMode');
            if (!($rm instanceof JsUndefined)) {
                $rmStr = TypeConversion::toString($rm);
                $validRM = ['ceil', 'floor', 'expand', 'trunc', 'halfCeil', 'halfFloor', 'halfExpand', 'halfTrunc', 'halfEven'];
                if (!in_array($rmStr, $validRM, true)) {
                    throw new RangeError("Invalid roundingMode: {$rmStr}");
                }
            }
            $su = $options->get('smallestUnit');
            if (!($su instanceof JsUndefined)) {
                $smallestUnit = TypeConversion::toString($su);
                $smallestUnit = self::canonicalTemporalUnit($smallestUnit);
            }
            // Validate roundingIncrement divides evenly.
            if (isset($riNum) && $riNum > 1) {
                self::validateRoundingIncrement($smallestUnit, $riNum);
            }
            // Default largestUnit to smallestUnit if needed.
            $allUnits = ['year', 'month', 'week', 'day', 'hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];
            $liIdx = array_search($largestUnit, $allUnits);
            $siIdx = array_search($smallestUnit, $allUnits);
            if (!$largestUnitExplicit && $siIdx !== false && $liIdx !== false && $siIdx < $liIdx) {
                $largestUnit = $smallestUnit;
                $liIdx = $siIdx;
            }
            if ($liIdx !== false && $siIdx !== false && $liIdx > $siIdx) {
                throw new RangeError('largestUnit must be >= smallestUnit');
            }
        }
        // For calendar units (year, month, week), compute using date components.
        $calendarUnits = ['year', 'month', 'week'];
        if (in_array($largestUnit, $calendarUnits, true)) {
            $dur = self::calendarDateTimeDifference($dt1, $dt2, $largestUnit, 1, $anchor);
            // Apply rounding if smallestUnit is a calendar unit.
            $suFinal = $smallestUnit;
            $rmFinal = $rmStr ?? 'trunc';
            $riFinal = isset($riNum) ? (int) $riNum : 1;
            if (in_array($suFinal, ['year', 'month', 'week', 'day'], true)) {
                return self::roundCalendarDuration($dur, $suFinal, $rmFinal, $riFinal, $largestUnit, $dt1);
            }
            if ($suFinal !== 'nanosecond' || $riFinal !== 1) {
                return self::roundCalendarDuration($dur, $suFinal, $rmFinal, $riFinal, $largestUnit, $dt1);
            }
            return $dur;
        }
        // Apply rounding.
        $roundIncrement = isset($riNum) ? (int) $riNum : 1;
        $roundMode = $rmStr ?? 'trunc';
        if ($smallestUnit !== 'nanosecond' || $roundIncrement !== 1) {
            $unitNsMap = [
                'day' => '86400000000000',
                'hour' => '3600000000000',
                'minute' => '60000000000',
                'second' => '1000000000',
                'millisecond' => '1000000',
                'microsecond' => '1000',
                'nanosecond' => '1',
            ];
            $unitNs = $unitNsMap[$smallestUnit] ?? '1';
            $incrementNs = bcmul((string) $roundIncrement, $unitNs, 0);
            $diffNs = self::roundNs($diffNs, $incrementNs, $roundMode);
        }
        return self::nsToDateTimeDuration($diffNs, $largestUnit);
    }

    /** Round a calendar-unit Duration to the specified smallestUnit. */
    private static function roundCalendarDuration(
        JsObject $dur,
        string $smallestUnit,
        string $roundingMode,
        int $increment,
        string $largestUnit,
        JsValue $ref,
    ): JsObject {
        $years = self::getDurationField($dur, 'years');
        $months = self::getDurationField($dur, 'months');
        $weeks = self::getDurationField($dur, 'weeks');
        $days = self::getDurationField($dur, 'days');
        $hours = self::getDurationField($dur, 'hours');
        $minutes = self::getDurationField($dur, 'minutes');
        $seconds = self::getDurationField($dur, 'seconds');
        $ms = self::getDurationField($dur, 'milliseconds');
        $us = self::getDurationField($dur, 'microseconds');
        $ns = self::getDurationField($dur, 'nanoseconds');
        $sign = self::durationSign($dur);
        if ($sign === 0) {
            return $dur;
        }
        $absYears = abs($years);
        $absMonths = abs($months);
        $absWeeks = abs($weeks);
        $absDays = abs($days);
        // Rounding modes are defined relative to the number line (+inf/-inf).
        // When working on absolute values of negative durations, swap directional modes.
        $absRoundingMode = $roundingMode;
        if ($sign < 0) {
            $absRoundingMode = match ($roundingMode) {
                'ceil' => 'floor',
                'floor' => 'ceil',
                'halfCeil' => 'halfFloor',
                'halfFloor' => 'halfCeil',
                default => $roundingMode,
            };
        }
        // Resolve year/month/day for the ref calendar context. For a ZDT, use
        // its wall-time components.
        $refZdt = ($ref instanceof JsObject && $ref->has('[[IsZonedDateTime]]')) ? $ref : null;
        // DST-aware path is only needed when the ZDT's time zone can actually
        // produce non-24h days. UTC and fixed offset zones are always 24h.
        $refDstZdt = null;
        if ($refZdt !== null) {
            $refTz = self::getSlotString($refZdt, '[[TimeZone]]');
            if (!self::isFixedOffset($refTz)) {
                $refDstZdt = $refZdt;
            }
        }
        if ($refZdt !== null) {
            $refParts = self::zonedDateTimeParts($refZdt);
            $refY = $refParts['year'];
            $refM = $refParts['month'];
            $refD = $refParts['day'];
        } else {
            $refY = $ref instanceof JsObject && $ref->has('[[ISOYear]]') ? self::getSlotInt($ref, '[[ISOYear]]') : 2000;
            $refM = $ref instanceof JsObject && $ref->has('[[ISOMonth]]') ? self::getSlotInt($ref, '[[ISOMonth]]') : 1;
            $refD = $ref instanceof JsObject && $ref->has('[[ISODay]]') ? self::getSlotInt($ref, '[[ISODay]]') : 1;
        }
        if ($smallestUnit === 'year') {
            // Compute fractional year per spec: remaining days / year-boundary days.
            // yearStart = ref + absYears years (in the forward direction).
            $ysY = $refY + $absYears * $sign;
            $ysM = $refM;
            $ysD = min($refD, self::isoDaysInMonth($ysY, $ysM));
            // yearEnd = yearStart + 1 year.
            $yeY = $ysY + $sign;
            $yeM = $ysM;
            $yeD = min($refD, self::isoDaysInMonth($yeY, $yeM));
            $yearLengthDays = abs(self::isoToJulianDay($yeY, $yeM, $yeD) - self::isoToJulianDay($ysY, $ysM, $ysD));
            // Compute remaining days from yearStart to (yearStart + months + days + time).
            $midDur = self::createDurationObject(0, $absMonths, $absWeeks, $absDays, 0, 0, 0, 0, 0, 0);
            $midEnd = self::plainDateAdd(self::createPlainDateObject($ysY, $ysM, $ysD, 'iso8601'), $midDur, $sign);
            $remainDays = abs(self::isoToJulianDay(
                self::getSlotInt($midEnd, '[[ISOYear]]'),
                self::getSlotInt($midEnd, '[[ISOMonth]]'),
                self::getSlotInt($midEnd, '[[ISODay]]'),
            ) - self::isoToJulianDay($ysY, $ysM, $ysD));
            $timeFrac = (abs($hours) * 3600 + abs($minutes) * 60 + abs($seconds)) / 86400.0;
            $frac = $yearLengthDays > 0 ? ($remainDays + $timeFrac) / (float) $yearLengthDays : 0;
            $totalYears = $absYears + $frac;
            $rounded = self::roundToIncrement((int) round($totalYears * 1000000), $increment * 1000000, $absRoundingMode);
            $roundedYears = intdiv($rounded, 1000000);
            // Validate the ceil endpoint (rounded + increment) is representable.
            $ceilY = $refY + ($roundedYears + $increment) * $sign;
            if ($ceilY < self::ISO_YEAR_MIN || $ceilY > self::ISO_YEAR_MAX) {
                throw new RangeError('Rounded date outside valid ISO date range');
            }
            return self::createDurationObject($sign * $roundedYears, 0, 0, 0, 0, 0, 0, 0, 0, 0);
        }
        if ($smallestUnit === 'month') {
            // The fractional month comes from the "remainder" days. For positive durations,
            // $ref is the start, and the span goes forward $absMonths months then $absDays days.
            // The relevant daysInMonth is at ref + absMonths.
            // For negative durations, $ref is the end (later), and the absDays span ends at ref.
            // The relevant month is the one just before ref (ref.month - 1).
            if ($sign < 0) {
                $midTotalM = $refY * 12 + ($refM - 2); // zero-based months: refM-1 then subtract 1
                $midY2 = intdiv($midTotalM, 12);
                $midM2 = ($midTotalM % 12) + 1;
                if ($midM2 < 1) {
                    $midM2 += 12;
                    $midY2--;
                }
            } else {
                $midTotalM = ($refY * 12 + ($refM - 1)) + ($absYears * 12 + $absMonths);
                $midY2 = intdiv($midTotalM, 12);
                $midM2 = ($midTotalM % 12) + 1;
            }
            if ($refZdt !== null) {
                // ZDT-aware: month length and progress in epoch ns so that
                // DST transitions inside the span are accounted for.
                $absTotalMonthCount = $absYears * 12 + $absMonths;
                $midDur = self::createDurationObject(
                    0,
                    $sign * $absTotalMonthCount,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                );
                $nextDur = self::createDurationObject(
                    0,
                    $sign * ($absTotalMonthCount + 1),
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                );
                $midNsRef = self::addDurationToZdt($refZdt, $midDur, 1, 'constrain');
                $nextNsRef = self::addDurationToZdt($refZdt, $nextDur, 1, 'constrain');
                $monthLenNs = bcsub($nextNsRef, $midNsRef, 0);
                $absMonthLen = bccomp($monthLenNs, '0', 0) < 0 ? substr($monthLenNs, 1) : $monthLenNs;
                $fullDur = self::createDurationObject(
                    $sign * $absYears,
                    $sign * $absMonths,
                    $sign * $absWeeks,
                    $sign * $absDays,
                    (float) $hours,
                    (float) $minutes,
                    (float) $seconds,
                    (float) $ms,
                    (float) $us,
                    (float) $ns,
                );
                $endNsRef = self::addDurationToZdt($refZdt, $fullDur, 1, 'constrain');
                $progressNs = bcsub($endNsRef, $midNsRef, 0);
                $absProgress = bccomp($progressNs, '0', 0) < 0 ? substr($progressNs, 1) : $progressNs;
                if (bccomp($absMonthLen, '0', 0) === 0) {
                    $fracTimes1e9 = '0';
                } else {
                    $fracTimes1e9 = bcdiv(bcmul($absProgress, '1000000000', 0), $absMonthLen, 0);
                }
                $totalMonthsScaled = bcadd(
                    bcmul((string) $absTotalMonthCount, '1000000000', 0),
                    $fracTimes1e9,
                    0,
                );
                $rounded = self::roundToIncrement(
                    (int) $totalMonthsScaled,
                    $increment * 1000000000,
                    $absRoundingMode,
                );
                $rm = intdiv($rounded, 1000000000);
            } else {
                $daysInMonth = self::isoDaysInMonth($midY2, $midM2);
                $frac = $daysInMonth > 0 ? ($absDays + abs($hours) / 24.0) / $daysInMonth : 0;
                $totalMonths = $absYears * 12 + $absMonths + $frac;
                $rounded = self::roundToIncrement((int) round($totalMonths * 1000000), $increment * 1000000, $absRoundingMode);
                $rm = intdiv($rounded, 1000000);
            }
            // Validate both the rounded endpoint and ceil endpoint are representable.
            $ceilM = $rm + $increment;
            $ceilTotalM = $refY * 12 + ($refM - 1) + $ceilM * $sign;
            $ceilY = intdiv($ceilTotalM, 12);
            if ($ceilY < self::ISO_YEAR_MIN || $ceilY > self::ISO_YEAR_MAX) {
                throw new RangeError('Rounded date outside valid ISO date range');
            }
            if ($largestUnit === 'year') {
                return self::createDurationObject($sign * intdiv($rm, 12), $sign * ($rm % 12), 0, 0, 0, 0, 0, 0, 0, 0);
            }
            return self::createDurationObject(0, $sign * $rm, 0, 0, 0, 0, 0, 0, 0, 0);
        }
        if ($smallestUnit === 'week') {
            // Include sub-day time as fractional days for rounding purposes.
            $timeNs = abs($hours) * 3600000000000
                + abs($minutes) * 60000000000
                + abs($seconds) * 1000000000
                + abs($ms) * 1000000
                + abs($us) * 1000
                + abs($ns);
            $dayNs = 86400000000000;
            $weekNs = $dayNs * 7 * $increment;
            $totalNs = ($absWeeks * 7 + $absDays) * $dayNs + $timeNs;
            $roundedNs = self::roundNs((string) ($sign < 0 ? -$totalNs : $totalNs), (string) $weekNs, $roundingMode);
            $roundedWeeks = (int) (abs((int) $roundedNs) / $dayNs / 7);
            return self::createDurationObject($sign * $absYears, $sign * $absMonths, $sign * $roundedWeeks, 0, 0, 0, 0, 0, 0, 0);
        }
        if ($smallestUnit === 'day') {
            // Include sub-day time as fractional days for rounding purposes.
            // For ZDT relativeTo, the actual day length on the relevant date
            // may not be 24h (DST transitions); use the ZDT-aware length so
            // that 11.5h on a 23h day correctly equals exactly 0.5 day.
            if ($refZdt !== null) {
                $absTotalDays = $absDays + $absWeeks * 7;
                $baseDur = self::createDurationObject(0, 0, 0, $sign * $absTotalDays, 0, 0, 0, 0, 0, 0);
                $nextDur = self::createDurationObject(0, 0, 0, $sign * ($absTotalDays + 1), 0, 0, 0, 0, 0, 0);
                $startNsRef = self::getSlotString($refZdt, '[[EpochNanoseconds]]');
                $baseNsRef = self::addDurationToZdt($refZdt, $baseDur, 1, 'constrain');
                $nextNsRef = self::addDurationToZdt($refZdt, $nextDur, 1, 'constrain');
                $dayLenNs = bcsub($nextNsRef, $baseNsRef, 0);
                $absDayLen = bccomp($dayLenNs, '0', 0) < 0 ? substr($dayLenNs, 1) : $dayLenNs;
                $fullDur = self::createDurationObject(
                    $sign * $absYears,
                    $sign * $absMonths,
                    $sign * $absWeeks,
                    $sign * $absDays,
                    (float) $hours,
                    (float) $minutes,
                    (float) $seconds,
                    (float) $ms,
                    (float) $us,
                    (float) $ns,
                );
                $endNsRef = self::addDurationToZdt($refZdt, $fullDur, 1, 'constrain');
                $progressNs = bcsub($endNsRef, $baseNsRef, 0);
                $absProgress = bccomp($progressNs, '0', 0) < 0 ? substr($progressNs, 1) : $progressNs;
                if (bccomp($absDayLen, '0', 0) === 0) {
                    $fracTimes1e9 = '0';
                } else {
                    $fracTimes1e9 = bcdiv(bcmul($absProgress, '1000000000', 0), $absDayLen, 0);
                }
                $totalDaysScaled = bcadd(
                    bcmul((string) $absTotalDays, '1000000000', 0),
                    $fracTimes1e9,
                    0,
                );
                $roundedScaled = self::roundToIncrement(
                    (int) $totalDaysScaled,
                    $increment * 1000000000,
                    $absRoundingMode,
                );
                $rounded = intdiv($roundedScaled, 1000000000);
            } else {
                $timeNs = abs($hours) * 3600000000000
                    + abs($minutes) * 60000000000
                    + abs($seconds) * 1000000000
                    + abs($ms) * 1000000
                    + abs($us) * 1000
                    + abs($ns);
                $dayNs = 86400000000000;
                $totalNs = ($absWeeks * 7 + $absDays) * $dayNs + $timeNs;
                $incrNs = $dayNs * $increment;
                $roundedNs = self::roundNs((string) ($sign < 0 ? -$totalNs : $totalNs), (string) $incrNs, $roundingMode);
                $rounded = (int) (abs((int) $roundedNs) / $dayNs);
            }
            // Validate ceiling (rounded + increment) doesn't exceed 100M days.
            $ceilDays = $rounded + $increment;
            if ($ceilDays > 100000000) {
                throw new RangeError('Rounded date outside valid ISO date range');
            }
            if ($largestUnit === 'week') {
                return self::createDurationObject(
                    $sign * $absYears,
                    $sign * $absMonths,
                    $sign * intdiv($rounded, 7),
                    $sign * ($rounded % 7),
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                );
            }
            return self::createDurationObject($sign * $absYears, $sign * $absMonths, 0, $sign * $rounded, 0, 0, 0, 0, 0, 0);
        }
        // Time-unit smallestUnit: compute total time ns (including days if largestUnit is a time unit).
        $dayNs = '86400000000000';
        $timeNsBc = bcadd(
            bcadd(
                bcadd(
                    bcmul((string) abs($hours), '3600000000000', 0),
                    bcmul((string) abs($minutes), '60000000000', 0),
                    0,
                ),
                bcmul((string) abs($seconds), '1000000000', 0),
                0,
            ),
            bcadd(
                bcadd(bcmul((string) abs($ms), '1000000', 0), bcmul((string) abs($us), '1000', 0), 0),
                (string) abs($ns),
                0,
            ),
            0,
        );
        // If largestUnit is a time unit, fold days into time nanoseconds.
        // Under DST, a wall-clock day is 23/24/25 hours, not always 24 — fold
        // via actual epoch ns from ref so the conversion matches reality.
        $timeUnits = ['hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];
        if (in_array($largestUnit, $timeUnits, true)) {
            if (
                $refDstZdt !== null
                && ($absDays !== 0 || $absWeeks !== 0 || $absMonths !== 0 || $absYears !== 0)
            ) {
                $startNsRef = self::getSlotString($refDstZdt, '[[EpochNanoseconds]]');
                $dateOnlyDur = self::createDurationObject(
                    $sign * $absYears,
                    $sign * $absMonths,
                    $sign * $absWeeks,
                    $sign * $absDays,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                );
                $endDateNsRef = self::addDurationToZdt($refDstZdt, $dateOnlyDur, 1, 'constrain');
                $deltaNsRef = bcsub($endDateNsRef, $startNsRef, 0);
                if (bccomp($deltaNsRef, '0', 0) < 0) {
                    $deltaNsRef = substr($deltaNsRef, 1);
                }
                $timeNsBc = bcadd($timeNsBc, $deltaNsRef, 0);
            } else {
                $timeNsBc = bcadd($timeNsBc, bcmul((string) $absDays, $dayNs, 0), 0);
            }
            $absDays = 0;
            $absWeeks = 0;
            $absMonths = 0;
            $absYears = 0;
        }
        $unitNs = self::temporalUnitToNs($smallestUnit);
        $incNs = bcmul((string) $increment, $unitNs, 0);
        if ($incNs !== '0') {
            $signed = $sign < 0 ? '-' . $timeNsBc : $timeNsBc;
            $timeNsBc = self::roundNs($signed, $incNs, $roundingMode);
            if (bccomp($timeNsBc, '0', 0) < 0) {
                $timeNsBc = substr($timeNsBc, 1);
            }
        }
        // AdjustRoundedDurationDays per spec: under DST, when the rounded
        // time portion exceeds the actual wall day length, increment days
        // and subtract the day length (then re-round the remainder).
        if (
            $refDstZdt !== null
            && in_array($largestUnit, ['year', 'month', 'week', 'day'], true)
            && bccomp($timeNsBc, '0', 0) > 0
        ) {
            while (true) {
                $iterStartDur = self::createDurationObject(0, 0, 0, $sign * $absDays, 0, 0, 0, 0, 0, 0);
                $iterEndDur = self::createDurationObject(0, 0, 0, $sign * ($absDays + 1), 0, 0, 0, 0, 0, 0);
                $iterStartNs = self::addDurationToZdt($refDstZdt, $iterStartDur, 1, 'constrain');
                $iterEndNs = self::addDurationToZdt($refDstZdt, $iterEndDur, 1, 'constrain');
                $dayLenNs = bcsub($iterEndNs, $iterStartNs, 0);
                if (bccomp($dayLenNs, '0', 0) < 0) {
                    $dayLenNs = substr($dayLenNs, 1);
                }
                // For largestUnit=day, time exactly equal to the day
                // length still folds into a whole day (e.g. 25h on a 25h
                // wall day = exactly 1 day). For larger units (year /
                // month / week), keep "= dayLen" as time so a no-op
                // round of e.g. 1Y 24h doesn't bubble into 1Y 1D.
                // Special case: a zero-length wall day (e.g. Apia's
                // skipped 2011-12-30) should still consume as one day
                // when walking forward and there's any time left to
                // attribute, since the calendar advances even though no
                // UTC ns elapsed.
                if (bccomp($dayLenNs, '0', 0) === 0) {
                    if (bccomp($timeNsBc, '0', 0) > 0) {
                        $absDays++;
                        continue;
                    }
                    break;
                }
                $cmp = bccomp($timeNsBc, $dayLenNs, 0);
                if ($largestUnit === 'day') {
                    if ($cmp < 0) {
                        break;
                    }
                } else {
                    if ($cmp <= 0) {
                        break;
                    }
                }
                $timeNsBc = bcsub($timeNsBc, $dayLenNs, 0);
                $absDays++;
                if ($incNs !== '0') {
                    $signed = $sign < 0 ? '-' . $timeNsBc : $timeNsBc;
                    $timeNsBc = self::roundNs($signed, $incNs, $roundingMode);
                    if (bccomp($timeNsBc, '0', 0) < 0) {
                        $timeNsBc = substr($timeNsBc, 1);
                    }
                }
            }
        }
        // Balance time into days if largestUnit allows it. With ZDT relativeTo
        // we must NOT pre-collapse time to days at fixed 24h; under DST a
        // wall-clock day can be 23/24/25 hours, and the spec preserves whole
        // days in calendar arithmetic only.
        $extraDays = 0;
        if (in_array($largestUnit, ['year', 'month', 'week', 'day'], true) && $refDstZdt === null) {
            $extraDays = (int) bcdiv($timeNsBc, $dayNs, 0);
            $timeNsBc = bcsub($timeNsBc, bcmul((string) $extraDays, $dayNs, 0), 0);
        }
        // For ZDT context, treat the time portion at hour granularity so that
        // 24h doesn't silently turn into 1 day under DST when the wall day is
        // 23 or 25 hours.
        $timePartLU = $refDstZdt !== null && in_array($largestUnit, ['year', 'month', 'week', 'day'], true)
            ? 'hour'
            : $largestUnit;
        $timePart = self::nsToTimeDuration($timeNsBc, $timePartLU);
        $finalDays = $absDays + $extraDays;
        $finalWeeks = $absWeeks;
        $finalMonths = $absMonths;
        $finalYears = $absYears;
        if ($largestUnit === 'week') {
            $finalWeeks += intdiv($finalDays, 7);
            $finalDays = $finalDays % 7;
        }
        if (in_array($largestUnit, ['year', 'month'], true) && $finalDays > 0 && $ref instanceof JsObject) {
            $rY = $refY;
            $rM = $refM;
            $ctm = $rY * 12 + ($rM - 1) + ($finalYears * 12) + $finalMonths;
            $cY = intdiv($ctm, 12);
            $cM = ($ctm % 12) + 1;
            $dim = self::isoDaysInMonth($cY, $cM);
            while ($finalDays >= $dim) {
                $finalDays -= $dim;
                $finalMonths++;
                if ($largestUnit === 'year' && $finalMonths >= 12) {
                    $finalYears += intdiv($finalMonths, 12);
                    $finalMonths = $finalMonths % 12;
                }
                $ctm = $rY * 12 + ($rM - 1) + ($finalYears * 12) + $finalMonths;
                $cY = intdiv($ctm, 12);
                $cM = ($ctm % 12) + 1;
                $dim = self::isoDaysInMonth($cY, $cM);
            }
        }
        return self::createDurationObject(
            $sign * $finalYears,
            $sign * $finalMonths,
            $sign * $finalWeeks,
            $sign * $finalDays,
            $sign * self::getDurationField($timePart, 'hours'),
            $sign * self::getDurationField($timePart, 'minutes'),
            $sign * self::getDurationField($timePart, 'seconds'),
            $sign * self::getDurationField($timePart, 'milliseconds'),
            $sign * self::getDurationField($timePart, 'microseconds'),
            $sign * self::getDurationField($timePart, 'nanoseconds'),
        );
    }

    /** Calendar-aware difference for year/month/week largestUnit. */
    private static function calendarDateTimeDifference(
        JsValue $dt1,
        JsValue $dt2,
        string $largestUnit,
        int $signParam = 1,
        ?JsValue $anchorDt = null,
    ): JsObject {
        $y1 = self::getSlotInt($dt1, '[[ISOYear]]');
        $m1 = self::getSlotInt($dt1, '[[ISOMonth]]');
        $d1 = self::getSlotInt($dt1, '[[ISODay]]');
        $y2 = self::getSlotInt($dt2, '[[ISOYear]]');
        $m2 = self::getSlotInt($dt2, '[[ISOMonth]]');
        $d2 = self::getSlotInt($dt2, '[[ISODay]]');
        // Determine sign.
        $cmp = ($y2 <=> $y1) ?: ($m2 <=> $m1) ?: ($d2 <=> $d1);
        if ($cmp === 0) {
            // Same date, compute time diff.
            $ns1 = self::isoDateTimeToEpochNs(
                $y1,
                $m1,
                $d1,
                self::getSlotInt($dt1, '[[ISOHour]]'),
                self::getSlotInt($dt1, '[[ISOMinute]]'),
                self::getSlotInt($dt1, '[[ISOSecond]]'),
                self::getSlotInt($dt1, '[[ISOMillisecond]]'),
                self::getSlotInt($dt1, '[[ISOMicrosecond]]'),
                self::getSlotInt($dt1, '[[ISONanosecond]]'),
                'UTC'
            );
            $ns2 = self::isoDateTimeToEpochNs(
                $y2,
                $m2,
                $d2,
                self::getSlotInt($dt2, '[[ISOHour]]'),
                self::getSlotInt($dt2, '[[ISOMinute]]'),
                self::getSlotInt($dt2, '[[ISOSecond]]'),
                self::getSlotInt($dt2, '[[ISOMillisecond]]'),
                self::getSlotInt($dt2, '[[ISOMicrosecond]]'),
                self::getSlotInt($dt2, '[[ISONanosecond]]'),
                'UTC'
            );
            $diffNs = bcsub($ns2, $ns1, 0);
            return self::nsToTimeDuration($diffNs, 'hour');
        }
        $sign = $cmp > 0 ? 1 : -1;
        // Anchor on the provided anchor date, or dt1 by default.
        $anchorDay = $anchorDt !== null
            ? self::getSlotInt($anchorDt, '[[ISODay]]')
            : $d1;
        if ($sign < 0) {
            [$sY, $sM, $sD, $eY, $eM, $eD] = [$y2, $m2, $d2, $y1, $m1, $d1];
            $smlDt = $dt2;
            $lrgDt = $dt1;
        } else {
            [$sY, $sM, $sD, $eY, $eM, $eD] = [$y1, $m1, $d1, $y2, $m2, $d2];
            $smlDt = $dt1;
            $lrgDt = $dt2;
        }
        $years = 0;
        $months = 0;
        $weeks = 0;
        $days = 0;
        if ($largestUnit === 'year' || $largestUnit === 'month') {
            if ($sign > 0) {
                // Forward: date1 < date2. Add months from date1 toward date2.
                $totalMonths = ($eY * 12 + $eM) - ($sY * 12 + $sM);
                if ($eD < $anchorDay) {
                    $totalMonths--;
                }
                $mt = $sY * 12 + ($sM - 1) + $totalMonths;
                $midMY = intdiv($mt, 12);
                $midMM = ($mt % 12) + 1;
                $midD = min($anchorDay, self::isoDaysInMonth($midMY, $midMM));
                $days = self::isoToJulianDay($eY, $eM, $eD) - self::isoToJulianDay($midMY, $midMM, $midD);
                if ($days < 0) {
                    $totalMonths--;
                    $mt = $sY * 12 + ($sM - 1) + $totalMonths;
                    $midMY = intdiv($mt, 12);
                    $midMM = ($mt % 12) + 1;
                    $midD = min($anchorDay, self::isoDaysInMonth($midMY, $midMM));
                    $days = self::isoToJulianDay($eY, $eM, $eD) - self::isoToJulianDay($midMY, $midMM, $midD);
                }
            } else {
                // Backward: date1(eY,eM,eD) > date2(sY,sM,sD). Subtract months from date1.
                $totalMonths = ($eY * 12 + $eM) - ($sY * 12 + $sM);
                if ($sD > $anchorDay) {
                    $totalMonths--;
                }
                // Midpoint = date1 - totalMonths months.
                $mt = $eY * 12 + ($eM - 1) - $totalMonths;
                $midMY = intdiv($mt, 12);
                $midMM = ($mt % 12) + 1;
                if ($midMM < 1) {
                    $midMM += 12;
                    $midMY--;
                }
                $midD = min($anchorDay, self::isoDaysInMonth($midMY, $midMM));
                // Days from midpoint backward to date2.
                $days = self::isoToJulianDay($midMY, $midMM, $midD) - self::isoToJulianDay($sY, $sM, $sD);
                if ($days < 0) {
                    $totalMonths--;
                    $mt = $eY * 12 + ($eM - 1) - $totalMonths;
                    $midMY = intdiv($mt, 12);
                    $midMM = ($mt % 12) + 1;
                    if ($midMM < 1) {
                        $midMM += 12;
                        $midMY--;
                    }
                    $midD = min($anchorDay, self::isoDaysInMonth($midMY, $midMM));
                    $days = self::isoToJulianDay($midMY, $midMM, $midD) - self::isoToJulianDay($sY, $sM, $sD);
                }
            }
            if ($largestUnit === 'year') {
                $years = intdiv($totalMonths, 12);
                $months = $totalMonths - $years * 12;
            } else {
                $months = $totalMonths;
            }
        } elseif ($largestUnit === 'week') {
            $jd1 = self::isoToJulianDay($sY, $sM, $sD);
            $jd2 = self::isoToJulianDay($eY, $eM, $eD);
            $totalDays = $jd2 - $jd1;
            $weeks = intdiv($totalDays, 7);
            $days = $totalDays % 7;
        }
        // Compute time difference: always (larger_time - smaller_time).
        $timeNs1 = (self::getSlotInt($smlDt, '[[ISOHour]]') * 3600
            + self::getSlotInt($smlDt, '[[ISOMinute]]') * 60
            + self::getSlotInt($smlDt, '[[ISOSecond]]')) * 1000000000
            + self::getSlotInt($smlDt, '[[ISOMillisecond]]') * 1000000
            + self::getSlotInt($smlDt, '[[ISOMicrosecond]]') * 1000
            + self::getSlotInt($smlDt, '[[ISONanosecond]]');
        $timeNs2 = (self::getSlotInt($lrgDt, '[[ISOHour]]') * 3600
            + self::getSlotInt($lrgDt, '[[ISOMinute]]') * 60
            + self::getSlotInt($lrgDt, '[[ISOSecond]]')) * 1000000000
            + self::getSlotInt($lrgDt, '[[ISOMillisecond]]') * 1000000
            + self::getSlotInt($lrgDt, '[[ISOMicrosecond]]') * 1000
            + self::getSlotInt($lrgDt, '[[ISONanosecond]]');
        $timeDiffNs = (string) ($timeNs2 - $timeNs1);
        if ($timeNs2 < $timeNs1) {
            if ($days > 0) {
                $days--;
                $timeDiffNs = (string) ($timeNs2 - $timeNs1 + 86400000000000);
            } elseif ($months > 0) {
                $months--;
                if ($sign > 0) {
                    // Forward: recompute midpoint from sml + months.
                    $mt2 = $sY * 12 + ($sM - 1) + ($years * 12) + $months;
                    $midMY2 = intdiv($mt2, 12);
                    $midMM2 = ($mt2 % 12) + 1;
                    $midD2 = min($anchorDay, self::isoDaysInMonth($midMY2, $midMM2));
                    $days = self::isoToJulianDay($eY, $eM, $eD) - self::isoToJulianDay($midMY2, $midMM2, $midD2);
                } else {
                    // Backward: recompute midpoint from date1 - months.
                    $mt2 = $eY * 12 + ($eM - 1) - ($years * 12) - $months;
                    $midMY2 = intdiv($mt2, 12);
                    $midMM2 = ($mt2 % 12) + 1;
                    if ($midMM2 < 1) {
                        $midMM2 += 12;
                        $midMY2--;
                    }
                    $midD2 = min($anchorDay, self::isoDaysInMonth($midMY2, $midMM2));
                    $days = self::isoToJulianDay($midMY2, $midMM2, $midD2) - self::isoToJulianDay($sY, $sM, $sD);
                }
                if ($days > 0) {
                    $days--;
                }
                $timeDiffNs = (string) ($timeNs2 - $timeNs1 + 86400000000000);
            } elseif ($years > 0) {
                $years--;
                if ($sign > 0) {
                    $tempY = $sY + $years;
                    $totalM = ($eY * 12 + $eM) - ($tempY * 12 + $sM);
                    if ($eD < $anchorDay) {
                        $totalM--;
                    }
                    $months = $totalM;
                    $mt2 = $tempY * 12 + ($sM - 1) + $months;
                    $midMY2 = intdiv($mt2, 12);
                    $midMM2 = ($mt2 % 12) + 1;
                    $midD2 = min($anchorDay, self::isoDaysInMonth($midMY2, $midMM2));
                    $days = self::isoToJulianDay($eY, $eM, $eD) - self::isoToJulianDay($midMY2, $midMM2, $midD2);
                } else {
                    // Backward: years borrow.
                    $remMonths = ($eY * 12 + $eM) - ($sY * 12 + $sM) - ($years * 12);
                    if ($sD > $anchorDay) {
                        $remMonths--;
                    }
                    $months = $remMonths;
                    $mt2 = $eY * 12 + ($eM - 1) - ($years * 12) - $months;
                    $midMY2 = intdiv($mt2, 12);
                    $midMM2 = ($mt2 % 12) + 1;
                    if ($midMM2 < 1) {
                        $midMM2 += 12;
                        $midMY2--;
                    }
                    $midD2 = min($anchorDay, self::isoDaysInMonth($midMY2, $midMM2));
                    $days = self::isoToJulianDay($midMY2, $midMM2, $midD2) - self::isoToJulianDay($sY, $sM, $sD);
                }
                if ($days > 0) {
                    $days--;
                }
                $timeDiffNs = (string) ($timeNs2 - $timeNs1 + 86400000000000);
            }
        }
        $timeDur = self::nsToTimeDuration($timeDiffNs, 'hour');
        $hours = self::getDurationField($timeDur, 'hours');
        $minutes = self::getDurationField($timeDur, 'minutes');
        $seconds = self::getDurationField($timeDur, 'seconds');
        $ms = self::getDurationField($timeDur, 'milliseconds');
        $us = self::getDurationField($timeDur, 'microseconds');
        $ns = self::getDurationField($timeDur, 'nanoseconds');
        return self::createDurationObject(
            $sign * $years,
            $sign * $months,
            $sign * $weeks,
            $sign * $days,
            $sign * $hours,
            $sign * $minutes,
            $sign * $seconds,
            $sign * $ms,
            $sign * $us,
            $sign * $ns,
        );
    }

    /** Convert nanosecond diff to Duration with date+time units. */
    private static function nsToDateTimeDuration(string $ns, string $largestUnit): JsObject
    {
        $sign = bccomp($ns, '0', 0) < 0 ? -1 : 1;
        $abs = $sign < 0 ? bcsub('0', $ns, 0) : $ns;
        $days = 0;
        $hours = 0;
        $minutes = 0;
        $seconds = 0;
        $milliseconds = 0;
        $microseconds = 0;
        $nanoseconds = 0;
        $nsPerDay = '86400000000000';
        $nsPerHour = '3600000000000';
        $nsPerMin = '60000000000';
        $nsPerSec = '1000000000';
        $nsPerMs = '1000000';
        $nsPerUs = '1000';
        $allUnits = ['day', 'hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];
        $luIdx = array_search($largestUnit, $allUnits, true);
        if ($luIdx === false) {
            $luIdx = 0;
        }
        if ($luIdx <= 0) {
            $days = (int) bcdiv($abs, $nsPerDay, 0);
            $abs = bcmod($abs, $nsPerDay);
        }
        if ($luIdx <= 1) {
            $hours = (int) bcdiv($abs, $nsPerHour, 0);
            $abs = bcmod($abs, $nsPerHour);
        }
        if ($luIdx <= 2) {
            $minutes = (int) bcdiv($abs, $nsPerMin, 0);
            $abs = bcmod($abs, $nsPerMin);
        }
        if ($luIdx <= 3) {
            $seconds = (int) bcdiv($abs, $nsPerSec, 0);
            $abs = bcmod($abs, $nsPerSec);
        }
        if ($luIdx <= 4) {
            $milliseconds = (int) bcdiv($abs, $nsPerMs, 0);
            $abs = bcmod($abs, $nsPerMs);
        }
        if ($luIdx <= 5) {
            $microseconds = (int) bcdiv($abs, $nsPerUs, 0);
            $abs = bcmod($abs, $nsPerUs);
        }
        $nanoseconds = (int) $abs;
        return self::createDurationObject(
            0,
            0,
            0,
            $sign * $days,
            $sign * $hours,
            $sign * $minutes,
            $sign * $seconds,
            $sign * $milliseconds,
            $sign * $microseconds,
            $sign * $nanoseconds,
        );
    }

    private static function isoDateTimeToEpochNs(
        int $y,
        int $m,
        int $d,
        int $h,
        int $min,
        int $s,
        int $ms,
        int $us,
        int $ns,
        string $tz,
    ): string {
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

    /**
     * Convert wall-clock components to an epoch ns under the given
     * timezone, applying the disambiguation policy at DST gaps and
     * folds:
     *   - "earlier"   pick the earlier UTC moment.
     *   - "later"     pick the later UTC moment.
     *   - "compatible" forward-shift in gaps, earlier in folds.
     *   - "reject"    throw RangeError when ambiguous.
     */
    private static function isoDateTimeToEpochNsDisambiguated(
        int $y,
        int $m,
        int $d,
        int $h,
        int $min,
        int $s,
        int $ms,
        int $us,
        int $ns,
        string $tz,
        string $disam,
    ): string {
        $nsPart = (int) ($ms * 1000000 + $us * 1000 + $ns);
        // Wall-clock seconds, as if the timezone were UTC.
        $wallUtcSec = bcadd(
            bcmul((string) self::isoDateToDays($y, $m, $d), '86400', 0),
            (string) ($h * 3600 + $min * 60 + $s),
            0,
        );
        // Probe the timezone's offset 12h before and 12h after the
        // wall-clock instant to detect DST transitions in the
        // window. If both probes return the same offset there's no
        // ambiguity.
        try {
            $tzObj = self::resolveTimeZone($tz);
            $beforeSec = bcsub($wallUtcSec, '43200', 0);
            $afterSec = bcadd($wallUtcSec, '43200', 0);
            $offBefore = (int) (new \DateTimeImmutable('@' . $beforeSec))
                ->setTimezone($tzObj)->format('Z');
            $offAfter = (int) (new \DateTimeImmutable('@' . $afterSec))
                ->setTimezone($tzObj)->format('Z');
        } catch (\Throwable) {
            return self::isoDateTimeToEpochNs($y, $m, $d, $h, $min, $s, $ms, $us, $ns, $tz);
        }
        if ($offBefore === $offAfter) {
            $epochSec = bcsub($wallUtcSec, (string) $offBefore, 0);
            return bcadd(bcmul($epochSec, '1000000000', 0), (string) $nsPart, 0);
        }
        // Compute the two candidate epochs (as if each offset were
        // chosen) and verify which actually carry that offset at
        // their resolved instant. A "valid" candidate's actual
        // offset matches the offset used to compute it.
        $epochWithBefore = bcsub($wallUtcSec, (string) $offBefore, 0);
        $epochWithAfter = bcsub($wallUtcSec, (string) $offAfter, 0);
        try {
            $actualAtBefore = (int) (new \DateTimeImmutable('@' . $epochWithBefore))
                ->setTimezone($tzObj)->format('Z');
            $actualAtAfter = (int) (new \DateTimeImmutable('@' . $epochWithAfter))
                ->setTimezone($tzObj)->format('Z');
        } catch (\Throwable) {
            return self::isoDateTimeToEpochNs($y, $m, $d, $h, $min, $s, $ms, $us, $ns, $tz);
        }
        $beforeValid = $actualAtBefore === $offBefore;
        $afterValid = $actualAtAfter === $offAfter;
        if ($beforeValid && $afterValid) {
            // Fold: both interpretations are valid. Pick by UTC order.
            $earlierEpoch = bccomp($epochWithBefore, $epochWithAfter, 0) < 0
                ? $epochWithBefore : $epochWithAfter;
            $laterEpoch = bccomp($epochWithBefore, $epochWithAfter, 0) < 0
                ? $epochWithAfter : $epochWithBefore;
            if ($disam === 'reject') {
                throw new RangeError(
                    'wall-clock time is ambiguous in a DST fold and disambiguation is "reject"',
                );
            }
            $epochSec = ($disam === 'later') ? $laterEpoch : $earlierEpoch;
            return bcadd(bcmul($epochSec, '1000000000', 0), (string) $nsPart, 0);
        }
        if (!$beforeValid && !$afterValid) {
            // Gap: neither interpretation is valid. The wall-clock
            // time was skipped.
            if ($disam === 'reject') {
                throw new RangeError(
                    'wall-clock time falls in a DST gap and disambiguation is "reject"',
                );
            }
            $earlierEpoch = bccomp($epochWithBefore, $epochWithAfter, 0) < 0
                ? $epochWithBefore : $epochWithAfter;
            $laterEpoch = bccomp($epochWithBefore, $epochWithAfter, 0) < 0
                ? $epochWithAfter : $epochWithBefore;
            // For gap, "earlier" picks the moment with the wall-
            // clock interpretation that points BEFORE the gap;
            // "later"/"compatible" point AFTER. The "before" offset
            // applied to the wall lands AT or BEFORE the
            // transition; the "after" offset lands AT or AFTER.
            // "earlier" disambiguation -> use the BEFORE offset's
            // candidate, which lies AFTER the actual transition (so
            // ironically the LATER UTC). The spec is:
            //   earlier: pick offset that gives the earlier UTC
            //   later:   pick offset that gives the later UTC
            //   compatible (gap): later
            $epochSec = ($disam === 'earlier') ? $earlierEpoch : $laterEpoch;
            return bcadd(bcmul($epochSec, '1000000000', 0), (string) $nsPart, 0);
        }
        // Exactly one valid interpretation.
        $epochSec = $beforeValid ? $epochWithBefore : $epochWithAfter;
        return bcadd(bcmul($epochSec, '1000000000', 0), (string) $nsPart, 0);
    }

    /**
     * Days from the proleptic Gregorian epoch (1970-01-01) to the
     * given Y/M/D. Negative for dates before 1970. Used by the
     * disambiguation helper to compute wall-clock UTC seconds
     * without triggering DateTimeImmutable's timezone interpretation.
     */
    private static function isoDateToDays(int $y, int $m, int $d): int
    {
        // Howard Hinnant's days_from_civil algorithm.
        $y -= ($m <= 2) ? 1 : 0;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($m + ($m > 2 ? -3 : 9)) + 2, 5) + $d - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;
        return $era * 146097 + $doe - 719468;
    }

    /** Return the UTC offset in nanoseconds for the given timezone at a given epoch-ns instant. */
    private static function getUtcOffsetNsForTimestamp(string $tz, string $epochNs): int
    {
        $negative = isset($epochNs[0]) && $epochNs[0] === '-';
        $abs = $negative ? substr($epochNs, 1) : $epochNs;
        $secStr = bcdiv($abs, '1000000000', 0);
        $epochSec = $negative ? '-' . $secStr : $secStr;
        try {
            $dt = new \DateTimeImmutable('@' . $epochSec);
            $local = $dt->setTimezone(self::resolveTimeZone($tz));
            return (int) $local->format('Z') * 1_000_000_000;
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Parse an ISO offset string (e.g. "Z", "+05:30", "-01:00", "+01:35:00.000000000") to nanoseconds. */
    private static function parseOffsetToNs(string $offset): int
    {
        if (strtoupper($offset) === 'Z') {
            return 0;
        }
        // Extended format: +HH:MM[:SS[.fractional]]
        if (preg_match('/^([+-])(\d{2})(?::(\d{2})(?::(\d{2})(?:[.,](\d{1,9}))?)?)?$/', $offset, $m)) {
            $sign = $m[1] === '+' ? 1 : -1;
            $h = (int) $m[2];
            $min = isset($m[3]) ? (int) $m[3] : 0;
            $sec = isset($m[4]) ? (int) $m[4] : 0;
            return $sign * ($h * 3600 + $min * 60 + $sec) * 1_000_000_000;
        }
        // Basic format without colons: +HHMM or +HHMMss
        if (preg_match('/^([+-])(\d{2})(\d{2})(?:(\d{2})(?:[.,](\d{1,9}))?)?$/', $offset, $m)) {
            $sign = $m[1] === '+' ? 1 : -1;
            $h = (int) $m[2];
            $min = (int) $m[3];
            $sec = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;
            return $sign * ($h * 3600 + $min * 60 + $sec) * 1_000_000_000;
        }
        return 0;
    }

    /** Return true if the given string looks like a fixed UTC offset (not an IANA name). */
    private static function isFixedOffset(string $tz): bool
    {
        if (strtoupper($tz) === 'Z' || strtoupper($tz) === 'UTC' || strtoupper($tz) === 'GMT') {
            return true;
        }
        // Accept both colon-separated and compact ISO offset forms:
        //   ±HH, ±HHMM, ±HH:MM, ±HHMMSS, ±HH:MM:SS, plus optional
        //   fractional seconds. ±HHMM occurs in inputs like
        //   "2021-08-19T1730-0700" where the time-zone annotation
        //   strips the date prefix and leaves a compact offset.
        return (bool) preg_match(
            '/^[+-]\d{2}(?::?\d{2}(?::?\d{2}(?:[.,]\d+)?)?)?$/',
            $tz,
        );
    }

    /** Validate ZonedDateTime options in alphabetical order: disambiguation, offset, overflow. */
    private static function validateZonedDateTimeOptions(JsValue $options): void
    {
        if (!$options instanceof JsObject) {
            return;
        }
        $dv = $options->get('disambiguation');
        if (!($dv instanceof JsUndefined)) {
            $dis = TypeConversion::toString($dv);
            if (!in_array($dis, ['compatible', 'earlier', 'later', 'reject'], true)) {
                throw new RangeError("Invalid disambiguation: {$dis}");
            }
        }
        $offOpt = $options->get('offset');
        if (!($offOpt instanceof JsUndefined)) {
            $offStr = TypeConversion::toString($offOpt);
            if (!in_array($offStr, ['prefer', 'use', 'ignore', 'reject'], true)) {
                throw new RangeError("Invalid offset option: {$offStr}");
            }
        }
        self::getOverflow($options);
    }

    /** Validate a UTC offset string per the Temporal spec. Must be +/-HH:MM with optional seconds up to 9 fractional digits. */
    private static function isValidOffsetString(string $str): bool
    {
        // Must start with + or -.
        if ($str === '' || ($str[0] !== '+' && $str[0] !== '-')) {
            return false;
        }
        // Extended format: +HH:MM or +HH:MM:SS or +HH:MM:SS.fffffffff (up to 9 fractional digits).
        if (preg_match('/^[+-]\d{2}:\d{2}(?::\d{2}(?:[.,]\d{1,9})?)?$/', $str)) {
            return true;
        }
        return false;
    }

    private static function normalizeOffset(int $offsetNs): string
    {
        $sign = $offsetNs >= 0 ? '+' : '-';
        $absS = abs(intdiv($offsetNs, 1_000_000_000));
        $h = intdiv($absS, 3600);
        $m = intdiv($absS % 3600, 60);
        $s = $absS % 60;
        $result = $sign . sprintf('%02d', $h) . ':' . sprintf('%02d', $m);
        if ($s !== 0) {
            $result .= ':' . sprintf('%02d', $s);
        }
        return $result;
    }

    private static function timeZoneOffsetString(string $ns, string $tz, bool $roundToMinute = true): string
    {
        // Floor-divide ns to seconds so that sub-second amounts of negative ns
        // round toward -∞ (matching the actual epoch second).
        $secStr = bcdiv($ns, '1000000000', 0);
        $remainder = bcmod($ns, '1000000000');
        if (bccomp($ns, '0', 0) < 0 && $remainder !== '0') {
            $secStr = bcsub($secStr, '1', 0);
        }
        $epochSec = $secStr;

        try {
            $dt = new \DateTimeImmutable('@' . $epochSec);
            $local = $dt->setTimezone(self::resolveTimeZone($tz));
            $offset = (int) $local->format('Z'); // Offset in seconds.
        } catch (\Throwable) {
            return '+00:00';
        }

        $sign = $offset >= 0 ? '+' : '-';
        $absOffset = abs($offset);
        if ($roundToMinute) {
            // Spec FormatDateTimeUTCOffsetRounded: ISO 8601 string
            // serialization rounds sub-minute offsets to the nearest
            // minute (ties away from zero).
            $minutes = (int) round($absOffset / 60, 0, PHP_ROUND_HALF_UP);
            $h = intdiv($minutes, 60);
            $m = $minutes % 60;
            return $sign . self::pad2($h) . ':' . self::pad2($m);
        }
        // ZDT.offset getter preserves the exact sub-minute offset.
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
    // Helpers: calendar arithmetic via ICU
    // -----------------------------------------------------------------------

    /**
     * Convert ISO (y,m,d) to calendar-specific (year, monthCode, day) using
     * ICU. Returns null if conversion is unavailable. Currently supports
     * hebrew, islamic*, persian, indian, ethioaa, ethiopic, coptic, buddhist,
     * japanese, roc, chinese, dangi.
     *
     * @return array<string, bool|int|string>|null
     */
    private static function isoToCalendarParts(string $calendar, int $y, int $m, int $d): ?array
    {
        if ($calendar === 'iso8601') {
            $monthCode = 'M' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
            return [
                'year' => $y,
                'month' => $m,
                'monthCode' => $monthCode,
                'day' => $d,
                'isLeapMonth' => false,
            ];
        }
        // Gregorian-like calendars use the ISO year/month/day directly (with
        // year 0 allowed). ICU here would shift across the Julian/Gregorian
        // boundary or into year 1 BCE, neither of which matches the spec.
        // For ROC the "year" is offset from 1911 (ROC year 1 = 1912 AD).
        // For Japanese the "year" stays Gregorian-equal because the era
        // disambiguates Heisei/Reiwa/etc., and the spec exposes the
        // Gregorian-like proleptic year via the .year getter.
        if (in_array($calendar, ['gregory', 'roc', 'japanese'], true)) {
            $monthCode = 'M' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
            $calYear = $calendar === 'roc' ? ($y - 1911) : $y;
            return [
                'year' => $calYear,
                'month' => $m,
                'monthCode' => $monthCode,
                'day' => $d,
                'isLeapMonth' => false,
            ];
        }
        // Hebrew: use the pure-PHP implementation. ICU's IntlCalendar gives
        // wrong day counts for Cheshvan/Kislev in some years.
        if ($calendar === 'hebrew') {
            $h = self::isoToHebrewDate($y, $m, $d);
            $hYear = $h['year'];
            $icuMonth = $h['icuMonth'];
            $hDay = $h['day'];
            $isLeap = self::isHebrewLeapYear($hYear);
            $isLeapMonth = $isLeap && $icuMonth === 5;
            $monthCode = self::calendarMonthToCode($calendar, $hYear, $icuMonth, $isLeapMonth);
            $monthOneBased = self::calendarMonthToOneBased($calendar, $hYear, $icuMonth, $isLeapMonth);
            return [
                'year' => $hYear,
                'month' => $monthOneBased,
                'monthCode' => $monthCode,
                'day' => $hDay,
                'isLeapMonth' => $isLeapMonth,
            ];
        }
        // Ethiopic / ethioaa: pure-PHP 13-month arithmetic (CI-independent).
        if ($calendar === 'ethiopic' || $calendar === 'ethioaa' || $calendar === 'ethiopic-amete-alem') {
            $e = self::isoToEthiopicDate($y, $m, $d);
            $eYear = $e['year'];
            $eMonth = $e['month'];
            $eDay = $e['day'];
            $monthCode = 'M' . str_pad((string) $eMonth, 2, '0', STR_PAD_LEFT);
            $userYear = self::ethiopicUserYear($calendar, $eYear);
            return [
                'year' => $userYear,
                'month' => $eMonth,
                'monthCode' => $monthCode,
                'day' => $eDay,
                'isLeapMonth' => false,
            ];
        }
        // Chinese / dangi: pure-PHP table lookup (CI-independent).
        if ($calendar === 'chinese' || $calendar === 'dangi') {
            self::$chineseDispatchCalendar = $calendar;
            $c = self::isoToChineseDate($y, $m, $d);
            if ($c !== null) {
                $monthCode = self::calendarMonthToCode($calendar, $c['year'], $c['icuMonth'], $c['isLeap']);
                $monthOneBased = self::chineseMonthOneBased($c['year'], $c['icuMonth'], $c['isLeap']);
                return [
                    'year' => $c['year'],
                    'month' => $monthOneBased,
                    'monthCode' => $monthCode,
                    'day' => $c['day'],
                    'isLeapMonth' => $c['isLeap'],
                ];
            }
            // Out of table range: fall through to ICU fallback.
        }
        if (!class_exists('IntlCalendar', false)) {
            return null;
        }
        try {
            $icuCal = $calendar;
            static $aliasMap = [
                'gregory' => 'gregorian',
                'islamic-civil' => 'islamic-civil',
                'islamicc' => 'islamic-civil',
                'ethioaa' => 'ethiopic-amete-alem',
            ];
            if (isset($aliasMap[$calendar])) {
                $icuCal = $aliasMap[$calendar];
            }
            // Cache an IntlCalendar per ICU calendar id. The instance is
            // stateful (time + fields) but the calendrical algorithm is a
            // pure function of (epoch ms, calendar id); a single shared
            // instance per id can be reused safely in PHP's
            // single-threaded model because we set the time and read all
            // fields before any other call site can touch it.
            // createInstance is ~4x slower than reuse on ICU 7x; this
            // shaves the dominant cost out of stress workloads like
            // staging/sm/Temporal/Calendar/compare-to-datetimeformat.js
            // and any other test that converts many ISO dates through the
            // same calendar.
            /** @var array<string,\IntlCalendar> $calCache */
            static $calCache = [];
            if (!isset($calCache[$icuCal])) {
                $calCache[$icuCal] = \IntlCalendar::createInstance('UTC', "en@calendar={$icuCal}");
            }
            $cal = $calCache[$icuCal];
            // Set the ICU calendar to the ISO date by epoch ms.
            $epochMs = self::isoDateToEpochMs($y, $m, $d);
            $cal->setTime($epochMs);
            // Chinese/Dangi have YEAR (1-60 sexagenary cycle) and
            // EXTENDED_YEAR (the actual year). The Temporal spec uses the
            // extended year. Coptic/Ethiopic likewise: FIELD_YEAR is the
            // era-relative year (positive in both eras), but Temporal
            // wants the proleptic / extended year (negative for ISO
            // dates predating year 1 of the positive era). EthioAA is
            // the inverse: its FIELD_YEAR already counts from the Amete
            // Alem epoch (year ~5500 BCE) so EXT_YEAR is wrong there.
            if (in_array($calendar, ['chinese', 'dangi', 'coptic', 'ethiopic'], true)) {
                $calY = $cal->get(\IntlCalendar::FIELD_EXTENDED_YEAR);
            } else {
                $calY = $cal->get(\IntlCalendar::FIELD_YEAR);
            }
            $calM = $cal->get(\IntlCalendar::FIELD_MONTH);
            $calD = $cal->get(\IntlCalendar::FIELD_DAY_OF_MONTH);
            $isLeapMonth = false;
            if (in_array($calendar, ['chinese', 'dangi'], true)) {
                $isLeapMonth = (bool) $cal->get(\IntlCalendar::FIELD_IS_LEAP_MONTH);
            }
        } catch (\Throwable) {
            return null;
        }
        $isLeapFromCalendar = self::calendarMonthIsLeap($calendar, $calY, $calM);
        $finalLeap = $isLeapMonth || $isLeapFromCalendar;
        $monthCode = self::calendarMonthToCode($calendar, $calY, $calM, $finalLeap);
        $monthOneBased = self::calendarMonthToOneBased($calendar, $calY, $calM, $finalLeap);
        $isLeapMonth = $finalLeap;
        return [
            'year' => $calY,
            'month' => $monthOneBased,
            'monthCode' => $monthCode,
            'day' => $calD,
            'isLeapMonth' => $isLeapMonth,
        ];
    }

    /** Days since unix epoch for an ISO date. */
    private static function isoDateToEpochMs(int $y, int $m, int $d): float
    {
        $days = self::isoDateToDays($y, $m, $d);
        return (float) ((int) $days * 86400 * 1000);
    }

    /**
     * Calendar-aware (years, months, days) for two ISO dates with sml ≤ lrg.
     * Walks via ICU add(YEAR/MONTH) so leap months and variable year lengths
     * are honored. Returns null when ICU is unavailable or arithmetic fails.
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function calendarYearsMonthsDaysBetween(
        string $calendar,
        int $smlY,
        int $smlM,
        int $smlD,
        int $lrgY,
        int $lrgM,
        int $lrgD,
        string $largestUnit,
    ): ?array {
        // Ethiopic / ethioaa: deterministic 13-month walk, no ICU needed.
        if ($calendar === 'ethiopic' || $calendar === 'ethioaa' || $calendar === 'ethiopic-amete-alem') {
            return self::ethiopicYearsMonthsDaysBetween(
                $smlY,
                $smlM,
                $smlD,
                $lrgY,
                $lrgM,
                $lrgD,
                $largestUnit,
            );
        }
        // Chinese / dangi: deterministic via pure-PHP table.
        if ($calendar === 'chinese' || $calendar === 'dangi') {
            self::$chineseDispatchCalendar = $calendar;
            $r = self::chineseYearsMonthsDaysBetween(
                $smlY,
                $smlM,
                $smlD,
                $lrgY,
                $lrgM,
                $lrgD,
                $largestUnit,
            );
            if ($r !== null) {
                return $r;
            }
            // Out of table range: fall through to ICU.
        }
        if (!class_exists('IntlCalendar', false)) {
            return null;
        }
        static $aliasMap = [
            'gregory' => 'gregorian',
            'islamicc' => 'islamic-civil',
            'ethioaa' => 'ethiopic-amete-alem',
        ];
        $icuCal = $aliasMap[$calendar] ?? $calendar;
        try {
            $startMs = self::isoDateToEpochMs($smlY, $smlM, $smlD);
            $endMs = self::isoDateToEpochMs($lrgY, $lrgM, $lrgD);
            $startCal = \IntlCalendar::createInstance('UTC', "en@calendar={$icuCal}");
            $startCal->setTime($startMs);
            $endCal = \IntlCalendar::createInstance('UTC', "en@calendar={$icuCal}");
            $endCal->setTime($endMs);
            $yearField = in_array($calendar, ['chinese', 'dangi'], true)
                ? \IntlCalendar::FIELD_EXTENDED_YEAR
                : \IntlCalendar::FIELD_YEAR;

            $years = 0;
            if ($largestUnit === 'year') {
                $bound = max(0, $endCal->get($yearField) - $startCal->get($yearField) + 1);
                $lo = 0;
                $hi = $bound;
                while ($lo < $hi) {
                    $mid = intdiv($lo + $hi + 1, 2);
                    $probe = clone $startCal;
                    $probe->add(\IntlCalendar::FIELD_YEAR, $mid);
                    if ($probe->getTime() <= $endMs) {
                        $lo = $mid;
                    } else {
                        $hi = $mid - 1;
                    }
                }
                $years = $lo;
            }

            $anchorCal = clone $startCal;
            if ($years > 0) {
                $anchorCal->add(\IntlCalendar::FIELD_YEAR, $years);
            }

            $months = 0;
            $bound = max(0, ($endCal->get($yearField) - $anchorCal->get($yearField) + 1) * 13);
            if ($bound === 0 && $anchorCal->getTime() < $endMs) {
                $bound = 13;
            }
            $lo = 0;
            $hi = $bound;
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi + 1, 2);
                $probe = clone $anchorCal;
                $probe->add(\IntlCalendar::FIELD_MONTH, $mid);
                if ($probe->getTime() <= $endMs) {
                    $lo = $mid;
                } else {
                    $hi = $mid - 1;
                }
            }
            $months = $lo;

            $finalCal = clone $anchorCal;
            if ($months > 0) {
                $finalCal->add(\IntlCalendar::FIELD_MONTH, $months);
            }
            $finalMs = $finalCal->getTime();
            $days = (int) round(($endMs - $finalMs) / 86400000);
            if ($days < 0) {
                return null;
            }
            return [$years, $months, $days];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * For chinese / dangi leap years, return the ICU MONTH index that has a
     * leap-month form (0..11). Returns null if no leap month exists.
     */
    private static function chineseLeapMonthIndex(string $calendar, int $extendedYear): ?int
    {
        if (!in_array($calendar, ['chinese', 'dangi'], true)) {
            return null;
        }
        // Pure-PHP lookup table (CI-independent). dangi has its own table
        // because Korean local time (UTC+9) vs Beijing (UTC+8) shifts a
        // few new-moon boundaries by one day.
        self::$chineseDispatchCalendar = $calendar;
        return self::chineseLeapMonthIcuFromTable($extendedYear);
    }

    /**
     * True when the calendar year (in calendar-native terms) is a leap year.
     */
    /**
     * 1-indexed day-of-year in the given calendar's space. Returns null
     * when ICU can't model the calendar; callers fall back to ISO
     * day-of-year. iso8601/gregory/roc/japanese/buddhist short-circuit
     * to the ISO value (their year boundaries match).
     */
    private static function calendarDayOfYearForIso(string $calendar, int $isoY, int $isoM, int $isoD): ?int
    {
        if (in_array($calendar, ['iso8601', 'gregory', 'roc', 'japanese', 'buddhist'], true)) {
            return self::isoDayOfYear($isoY, $isoM, $isoD);
        }
        if ($calendar === 'hebrew') {
            $h = self::isoToHebrewDate($isoY, $isoM, $isoD);
            $startDays = self::hebrewElapsedDaysToFirstTishrei($h['year']);
            $thisDays = self::isoDateToDays($isoY, $isoM, $isoD);
            return $thisDays - $startDays + 1;
        }
        if ($calendar === 'ethiopic' || $calendar === 'ethioaa' || $calendar === 'ethiopic-amete-alem') {
            $e = self::isoToEthiopicDate($isoY, $isoM, $isoD);
            $startDays = self::ethiopicNewYearDay($e['year']);
            $thisDays = self::isoDateToDays($isoY, $isoM, $isoD);
            return $thisDays - $startDays + 1;
        }
        if ($calendar === 'chinese' || $calendar === 'dangi') {
            self::$chineseDispatchCalendar = $calendar;
            $r = self::chineseDayOfYearForIso($isoY, $isoM, $isoD);
            if ($r !== null) {
                return $r;
            }
        }
        if (!class_exists('IntlCalendar', false)) {
            return null;
        }
        static $aliasMap = [
            'islamicc' => 'islamic-civil',
            'ethioaa' => 'ethiopic-amete-alem',
        ];
        $icuName = $aliasMap[$calendar] ?? $calendar;
        try {
            $cal = \IntlCalendar::createInstance(
                'UTC',
                "en@calendar={$icuName}",
            );
            $epochMs = self::isoDateToEpochMs($isoY, $isoM, $isoD);
            $cal->setTime($epochMs);
            return (int) $cal->get(\IntlCalendar::FIELD_DAY_OF_YEAR);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Number of days in the calendar month containing the given ISO
     * date. Returns null when ICU can't model the calendar; callers
     * fall back to ISO month length.
     */
    private static function calendarDaysInMonthForIso(string $calendar, int $isoY, int $isoM, int $isoD): ?int
    {
        if (in_array($calendar, ['iso8601', 'gregory', 'roc', 'japanese', 'buddhist'], true)) {
            return self::isoDaysInMonth($isoY, $isoM);
        }
        if ($calendar === 'hebrew') {
            $h = self::isoToHebrewDate($isoY, $isoM, $isoD);
            return self::hebrewDaysInMonth($h['year'], $h['icuMonth']);
        }
        if ($calendar === 'ethiopic' || $calendar === 'ethioaa' || $calendar === 'ethiopic-amete-alem') {
            $e = self::isoToEthiopicDate($isoY, $isoM, $isoD);
            return self::ethiopicDaysInMonth($e['year'], $e['month']);
        }
        if ($calendar === 'chinese' || $calendar === 'dangi') {
            self::$chineseDispatchCalendar = $calendar;
            $c = self::isoToChineseDate($isoY, $isoM, $isoD);
            if ($c !== null) {
                return self::chineseDaysInMonth($c['year'], $c['icuMonth'], $c['isLeap']);
            }
        }
        if (!class_exists('IntlCalendar', false)) {
            return null;
        }
        static $aliasMap = [
            'islamicc' => 'islamic-civil',
            'ethioaa' => 'ethiopic-amete-alem',
        ];
        $icuName = $aliasMap[$calendar] ?? $calendar;
        try {
            $cal = \IntlCalendar::createInstance(
                'UTC',
                "en@calendar={$icuName}",
            );
            $epochMs = self::isoDateToEpochMs($isoY, $isoM, $isoD);
            $cal->setTime($epochMs);
            return (int) $cal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_MONTH);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Number of days in the calendar year containing the given ISO
     * date. Returns null when ICU can't model the calendar.
     */
    private static function calendarDaysInYearForIso(string $calendar, int $isoY, int $isoM, int $isoD): ?int
    {
        if (in_array($calendar, ['iso8601', 'gregory', 'roc', 'japanese', 'buddhist'], true)) {
            return self::isoDaysInYear($isoY);
        }
        if ($calendar === 'hebrew') {
            $h = self::isoToHebrewDate($isoY, $isoM, $isoD);
            return self::hebrewYearLength($h['year']);
        }
        if ($calendar === 'ethiopic' || $calendar === 'ethioaa' || $calendar === 'ethiopic-amete-alem') {
            $e = self::isoToEthiopicDate($isoY, $isoM, $isoD);
            return self::ethiopicYearLength($e['year']);
        }
        if ($calendar === 'chinese' || $calendar === 'dangi') {
            self::$chineseDispatchCalendar = $calendar;
            $c = self::isoToChineseDate($isoY, $isoM, $isoD);
            if ($c !== null) {
                return self::chineseYearLength($c['year']);
            }
        }
        if (!class_exists('IntlCalendar', false)) {
            return null;
        }
        static $aliasMap = [
            'islamicc' => 'islamic-civil',
            'ethioaa' => 'ethiopic-amete-alem',
        ];
        $icuName = $aliasMap[$calendar] ?? $calendar;
        try {
            $cal = \IntlCalendar::createInstance(
                'UTC',
                "en@calendar={$icuName}",
            );
            $epochMs = self::isoDateToEpochMs($isoY, $isoM, $isoD);
            $cal->setTime($epochMs);
            return (int) $cal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_YEAR);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Number of months in the calendar year containing the given ISO date.
     * Returns null when ICU can't model the calendar; callers fall back
     * to 12 in that case.
     */
    private static function calendarMonthsInYear(string $calendar, int $isoY, int $isoM, int $isoD): ?int
    {
        if (in_array($calendar, ['iso8601', 'gregory', 'roc', 'japanese', 'buddhist'], true)) {
            return 12;
        }
        // Ethiopic / Coptic / EthioAA always have 13 months.
        if (in_array($calendar, ['coptic', 'ethiopic', 'ethioaa', 'ethiopic-amete-alem'], true)) {
            return 13;
        }
        // Chinese / Dangi: pure-PHP table lookup.
        if (in_array($calendar, ['chinese', 'dangi'], true)) {
            self::$chineseDispatchCalendar = $calendar;
            $c = self::isoToChineseDate($isoY, $isoM, $isoD);
            if ($c !== null) {
                $info = self::chineseYearInfo($c['year']);
                if ($info !== null) {
                    return $info['monthCount'];
                }
            }
            // Out of table range: fall through.
        }
        // Hebrew can be answered without ICU.
        if ($calendar === 'hebrew') {
            $leap = self::calendarInLeapYear($calendar, $isoY, $isoM, $isoD);
            return $leap === true ? 13 : 12;
        }
        if (!class_exists('IntlCalendar', false)) {
            return null;
        }
        if (in_array($calendar, ['chinese', 'dangi'], true)) {
            $leap = self::calendarInLeapYear($calendar, $isoY, $isoM, $isoD);
            return $leap === true ? 13 : 12;
        }
        // Islamic variants, persian, indian: 12.
        return 12;
    }

    private static function calendarInLeapYear(string $calendar, int $isoY, int $isoM, int $isoD): ?bool
    {
        if ($calendar === 'iso8601' || in_array($calendar, ['gregory', 'roc', 'japanese'], true)) {
            return self::isoIsLeapYear($isoY);
        }
        if ($calendar === 'hebrew') {
            $parts = self::isoToCalendarParts($calendar, $isoY, $isoM, $isoD);
            if ($parts === null) {
                return null;
            }
            return self::isHebrewLeapYear($parts['year']);
        }
        if ($calendar === 'ethiopic' || $calendar === 'ethioaa' || $calendar === 'ethiopic-amete-alem') {
            $e = self::isoToEthiopicDate($isoY, $isoM, $isoD);
            return self::isEthiopicLeapYear($e['year']);
        }
        if ($calendar === 'chinese' || $calendar === 'dangi') {
            self::$chineseDispatchCalendar = $calendar;
            $c = self::isoToChineseDate($isoY, $isoM, $isoD);
            if ($c !== null) {
                return self::chineseLeapMonthIcuFromTable($c['year']) !== null;
            }
        }
        if (!class_exists('IntlCalendar', false)) {
            return null;
        }
        try {
            $icuCalName = $calendar;
            static $aliasMapInLY = [
                'islamicc' => 'islamic-civil',
                'ethioaa' => 'ethiopic-amete-alem',
            ];
            if (isset($aliasMapInLY[$calendar])) {
                $icuCalName = $aliasMapInLY[$calendar];
            }
            $cal = \IntlCalendar::createInstance('UTC', "en@calendar={$icuCalName}");
            $cal->setTime(self::isoDateToEpochMs($isoY, $isoM, $isoD));
            // Chinese / Dangi leap years insert one leap month flagged via
            // IS_LEAP_MONTH (the MONTH field still ranges 0-11). Probe each
            // position with IS_LEAP_MONTH=1 and check whether ICU preserves
            // the flag.
            if (in_array($calendar, ['chinese', 'dangi'], true)) {
                $extYear = $cal->get(\IntlCalendar::FIELD_EXTENDED_YEAR);
                for ($m = 0; $m < 12; $m++) {
                    $probe = \IntlCalendar::createInstance('UTC', "en@calendar={$icuCalName}");
                    $probe->set(\IntlCalendar::FIELD_EXTENDED_YEAR, $extYear);
                    $probe->set(\IntlCalendar::FIELD_MONTH, $m);
                    $probe->set(\IntlCalendar::FIELD_IS_LEAP_MONTH, 1);
                    $probe->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);
                    $ms = $probe->getTime();
                    $verify = \IntlCalendar::createInstance('UTC', "en@calendar={$icuCalName}");
                    $verify->setTime($ms);
                    if (
                        $verify->get(\IntlCalendar::FIELD_EXTENDED_YEAR) === $extYear
                        && $verify->get(\IntlCalendar::FIELD_MONTH) === $m
                        && $verify->get(\IntlCalendar::FIELD_IS_LEAP_MONTH) === 1
                    ) {
                        return true;
                    }
                }
                return false;
            }
            // Coptic / Ethiopic leap year: every 4 years, M13 has 6 days.
            if (in_array($calendar, ['coptic', 'ethiopic', 'ethioaa'], true)) {
                return $cal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_YEAR) > 365;
            }
            // Persian: 365 vs 366 days.
            if ($calendar === 'persian' || $calendar === 'indian') {
                return $cal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_YEAR) > 365;
            }
            // Islamic variants: 354 vs 355 days (the "kabisat" extra day on
            // Dhu al-Hijjah). Treat any year exceeding 354 days as a leap.
            if (
                in_array(
                    $calendar,
                    ['islamic', 'islamic-civil', 'islamic-tbla', 'islamic-rgsa', 'islamic-umalqura', 'islamicc'],
                    true,
                )
            ) {
                return $cal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_YEAR) > 354;
            }
            return false;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Actual days-in-month for a calendar year/month combination via ICU.
     * Returns null if conversion is unavailable.
     */
    private static function calendarDaysInMonth(string $calendar, int $year, ?string $monthCode, ?int $monthNum): ?int
    {
        // Route through calendarPartsToIso so chinese/dangi extended_year +
        // leap_month resolution is applied (setDate alone uses FIELD_YEAR
        // which is the 60-cycle position, not the actual year). Ethiopic
        // and Hebrew never require ICU, so they work even without intl.
        $iso = self::calendarPartsToIso($calendar, $year, $monthCode, $monthNum, 1);
        if ($iso !== null) {
            return self::calendarDaysInMonthForIso(
                $calendar,
                $iso['year'],
                $iso['month'],
                $iso['day'],
            );
        }
        if (!class_exists('IntlCalendar', false)) {
            return null;
        }
        return null;
    }

    /**
     * Pick the ISO date that represents (calendar, monthCode|month, day) for
     * Temporal.PlainMonthDay's reference-year purposes. Per spec, this is the
     * largest ISO date <= 1972-12-31 whose calendar fields match. Returns
     * null when the calendar cannot be resolved.
     *
     * @return array{year: int, month: int, day: int}|null
     */
    private static function pmdReferenceIsoFor(string $cal, ?string $monthCode, ?int $monthNum, int $day): ?array
    {
        if ($cal === 'iso8601' || in_array($cal, ['gregory', 'roc', 'japanese'], true)) {
            $m = $monthNum ?? ($monthCode !== null && preg_match('/^M(\d{2})/', $monthCode, $mm) ? (int) $mm[1] : 0);
            return ['year' => 1972, 'month' => $m, 'day' => $day];
        }
        // Ethiopic / ethioaa: pure-PHP. Find the ethiopic year whose
        // M-d for ISO 1972-12-31 yields the largest ISO <= 1972-12-31.
        if ($cal === 'ethiopic' || $cal === 'ethioaa' || $cal === 'ethiopic-amete-alem') {
            $refE = self::isoToEthiopicDate(1972, 12, 31);
            $approxYear = self::ethiopicUserYear($cal, $refE['year']);
            for ($tryDay = $day; $tryDay >= 1; $tryDay--) {
                for ($delta = 1; $delta >= -8; $delta--) {
                    $tryYear = $approxYear + $delta;
                    $iso = self::calendarPartsToIso($cal, $tryYear, $monthCode, $monthNum, $tryDay);
                    if ($iso === null) {
                        continue;
                    }
                    if ($iso['year'] <= 1972) {
                        return $iso;
                    }
                }
            }
            return null;
        }
        // Chinese / dangi: pure-PHP approximate via the table.
        if (in_array($cal, ['chinese', 'dangi'], true)) {
            self::$chineseDispatchCalendar = $cal;
            $refC = self::isoToChineseDate(1972, 12, 31);
            if ($refC !== null) {
                $approxYear = $refC['year'];
            } elseif (class_exists('IntlCalendar', false)) {
                try {
                    $probe = \IntlCalendar::createInstance('UTC', "en@calendar={$cal}");
                    $probe->setTime(strtotime('1972-12-31 UTC') * 1000);
                    $approxYear = $probe->get(\IntlCalendar::FIELD_EXTENDED_YEAR);
                } catch (\Throwable) {
                    return null;
                }
            } else {
                return null;
            }
        } else {
            if (!class_exists('IntlCalendar', false)) {
                return null;
            }
            try {
                $icuCal = $cal;
                static $aliasMapPmd = [
                    'islamicc' => 'islamic-civil',
                    'ethioaa' => 'ethiopic-amete-alem',
                ];
                if (isset($aliasMapPmd[$cal])) {
                    $icuCal = $aliasMapPmd[$cal];
                }
                $probe = \IntlCalendar::createInstance('UTC', "en@calendar={$icuCal}");
                $probe->setTime(strtotime('1972-12-31 UTC') * 1000);
                $approxYear = $probe->get(\IntlCalendar::FIELD_YEAR);
            } catch (\Throwable) {
                return null;
            }
        }
        // Try a window of calendar years from approxYear and pick the largest
        // one whose ISO mapping for M-d lands in 1972 or earlier AND whose ICU
        // roundtrip (ISO->calendar) produces back the requested fields.
        // Lunisolar calendars can require longer search windows (M-d may exist
        // only every few years).
        $bestIso = null;
        // Chinese / Dangi need a wide window because some "uncommon"
        // leap months (M01L, M09L, M10L, M11L, M12L with 30 days)
        // recur only every several thousand years; SM's
        // `from-chinese-leap-month-uncommon` test asserts these exist
        // at ISO -6482 etc., which means our search must reach the
        // deep-past entries baked into the chinese / dangi tables
        // (start = -7500). Hebrew/etc. stay small.
        $maxLookback = in_array($cal, ['chinese', 'dangi'], true)
            ? 10000
            : (in_array($cal, ['hebrew'], true) ? 30 : 8);
        // When the requested day is more than the calendar month allows
        // (e.g. day 31 in islamic-civil M02 which has 29), step the day
        // down one at a time until the roundtrip lines up — that mirrors
        // the spec's "constrain" semantics for PlainMonthDay.from.
        for ($tryDay = $day; $tryDay >= 1; $tryDay--) {
            for ($delta = 1; $delta >= -$maxLookback; $delta--) {
                $tryYear = $approxYear + $delta;
                $iso = self::calendarPartsToIso($cal, $tryYear, $monthCode, $monthNum, $tryDay);
                if ($iso === null) {
                    continue;
                }
                // Roundtrip: ISO -> calendar should yield matching M-d.
                $back = self::isoToCalendarParts($cal, $iso['year'], $iso['month'], $iso['day']);
                if ($back === null) {
                    continue;
                }
                if ($monthCode !== null && $back['monthCode'] !== $monthCode) {
                    continue;
                }
                if ($monthNum !== null && $back['month'] !== $monthNum) {
                    continue;
                }
                if ($back['day'] !== $tryDay) {
                    continue;
                }
                if ($iso['year'] <= 1972) {
                    if (
                        $bestIso === null
                        || $iso['year'] > $bestIso['year']
                        || ($iso['year'] === $bestIso['year'] && $iso['month'] > $bestIso['month'])
                    ) {
                        $bestIso = $iso;
                    }
                }
            }
            if ($bestIso !== null) {
                return $bestIso;
            }
        }
        return $bestIso;
    }

    /**
     * Convert calendar-native (year, monthCode|month, day) to ISO (y, m, d)
     * via ICU. Returns null if conversion is unavailable. Caller is
     * responsible for choosing between monthCode and a 1-indexed month
     * number; for ICU we always need a 0-indexed integer month.
     *
     * @return array{year: int, month: int, day: int}|null
     */
    private static function calendarPartsToIso(string $calendar, int $year, ?string $monthCode, ?int $monthNum, int $day): ?array
    {
        if ($calendar === 'iso8601') {
            $m = $monthNum ?? ($monthCode !== null && preg_match('/^M(\d{2})/', $monthCode, $mm) ? (int) $mm[1] : 0);
            return ['year' => $year, 'month' => $m, 'day' => $day];
        }
        if (in_array($calendar, ['gregory', 'roc', 'japanese'], true)) {
            $m = $monthNum ?? ($monthCode !== null && preg_match('/^M(\d{2})/', $monthCode, $mm) ? (int) $mm[1] : 0);
            $isoYear = $calendar === 'roc' ? ($year + 1911) : $year;
            return ['year' => $isoYear, 'month' => $m, 'day' => $day];
        }
        // Ethiopic / hebrew / chinese / dangi do not require IntlCalendar.
        // Other calendars (islamic*, persian, indian, coptic, ...) still need
        // it, so the ICU class gate is deferred until after the pure-PHP
        // branches are tried.
        $purePhp = in_array(
            $calendar,
            ['ethiopic', 'ethioaa', 'ethiopic-amete-alem', 'hebrew', 'chinese', 'dangi'],
            true,
        );
        if (!$purePhp && !class_exists('IntlCalendar', false)) {
            return null;
        }
        // islamic-umalqura's astronomical lookup tables only span ~1300-1600 AH.
        // Outside a generous bound around that range ICU silently extrapolates
        // and produces results that diverge from the spec. SpiderMonkey's
        // tests reject such inputs explicitly (see icu4x #4914).
        if ($calendar === 'islamic-umalqura' && ($year < 1 || $year > 9999)) {
            return null;
        }
        // Resolve ICU 0-indexed month from monthCode (preferred) or month number.
        $icuMonth = null;
        $isLeapMonth = false;
        if ($monthCode !== null) {
            if (preg_match('/^M(\d{2})(L?)$/', $monthCode, $mm)) {
                $codeNum = (int) $mm[1];
                $isLeapMonth = $mm[2] === 'L';
                if ($calendar === 'hebrew') {
                    if ($codeNum >= 1 && $codeNum <= 5 && !$isLeapMonth) {
                        $icuMonth = $codeNum - 1;
                    } elseif ($codeNum === 5 && $isLeapMonth) {
                        $icuMonth = 5; // Adar I (only valid in leap year).
                    } elseif ($codeNum >= 6 && $codeNum <= 12 && !$isLeapMonth) {
                        $icuMonth = $codeNum;
                    }
                } else {
                    // Most calendars: M01..MNN → ICU 0..NN-1.
                    $icuMonth = $codeNum - 1;
                }
            }
        } elseif ($monthNum !== null) {
            // 1-indexed month → ICU 0-indexed (most calendars).
            if (in_array($calendar, ['chinese', 'dangi'], true)) {
                // In a leap year, month positions 1..13 chronologically include
                // the leap month between certain non-leap months.
                $leapIcu = self::chineseLeapMonthIndex($calendar, $year);
                if ($leapIcu === null) {
                    // Non-leap year: 1..12 → ICU 0..11.
                    $icuMonth = $monthNum - 1;
                } else {
                    // Leap year: chronologically months 1..(leapIcu+1) → non-leap
                    // ICU 0..leapIcu; month (leapIcu+2) → leap version of leapIcu;
                    // months (leapIcu+3)..13 → ICU (leapIcu+1)..11.
                    if ($monthNum >= 1 && $monthNum <= $leapIcu + 1) {
                        $icuMonth = $monthNum - 1;
                    } elseif ($monthNum === $leapIcu + 2) {
                        $icuMonth = $leapIcu;
                        $isLeapMonth = true;
                    } elseif ($monthNum >= $leapIcu + 3 && $monthNum <= 13) {
                        $icuMonth = $monthNum - 2;
                    }
                }
            } elseif ($calendar === 'hebrew') {
                $isLeap = self::isHebrewLeapYear($year);
                if ($isLeap) {
                    // Spec months: 1..5=Tishri..Shevat, 6=AdarI, 7=AdarII, ..., 13=Elul.
                    if ($monthNum >= 1 && $monthNum <= 5) {
                        $icuMonth = $monthNum - 1;
                    } elseif ($monthNum === 6) {
                        $icuMonth = 5; // Adar I
                    } elseif ($monthNum >= 7 && $monthNum <= 13) {
                        $icuMonth = $monthNum - 1;
                    }
                } else {
                    // Non-leap: 1..12.
                    if ($monthNum >= 1 && $monthNum <= 5) {
                        $icuMonth = $monthNum - 1;
                    } elseif ($monthNum >= 6 && $monthNum <= 12) {
                        $icuMonth = $monthNum;
                    }
                }
            } else {
                $icuMonth = $monthNum - 1;
            }
        }
        if ($icuMonth === null) {
            return null;
        }
        // Ethiopic / ethioaa: pure-PHP 13-month arithmetic.
        if ($calendar === 'ethiopic' || $calendar === 'ethioaa' || $calendar === 'ethiopic-amete-alem') {
            if ($isLeapMonth) {
                // Ethiopic has no leap months.
                return null;
            }
            // icuMonth resolved above is 0-indexed from monthCode/monthNum.
            // For ethiopic that maps 1:1 to spec month (M01..M13) → icuMonth 0..12.
            $eMonth = $icuMonth + 1;
            if ($eMonth < 1 || $eMonth > 13) {
                return null;
            }
            $canonYear = self::ethiopicCanonicalYear($calendar, $year);
            $dim = self::ethiopicDaysInMonth($canonYear, $eMonth);
            if ($day < 1 || $day > $dim) {
                return null;
            }
            return self::ethiopicToIsoDate($canonYear, $eMonth, $day);
        }
        // Hebrew: bypass ICU. ICU's getActualMaximum reports stale month
        // lengths for some years, which causes Cheshvan/Kislev to come back
        // as 29 even when Rosh Hashanah postponement makes them 30.
        if ($calendar === 'hebrew') {
            $isLeap = self::isHebrewLeapYear($year);
            // ICU month indices range 0..12 in both leap and non-leap years;
            // index 5 is Adar I in leap years and unused in non-leap years.
            if ($icuMonth < 0 || $icuMonth > 12) {
                return null;
            }
            if (!$isLeap && $icuMonth === 5) {
                return null;
            }
            // Validate leap-month placement: Adar I lives at icuMonth=5
            // and only exists in leap years (the !$isLeap+icuMonth=5 case
            // is already short-circuited above, so checking the index is
            // sufficient here).
            if ($isLeapMonth && $icuMonth !== 5) {
                return null;
            }
            $dim = self::hebrewDaysInMonth($year, $icuMonth);
            if ($day < 1 || $day > $dim) {
                return null;
            }
            return self::hebrewToIsoDate($year, $icuMonth, $day);
        }
        // Chinese / dangi: pure-PHP table lookup (CI-independent).
        if ($calendar === 'chinese' || $calendar === 'dangi') {
            self::$chineseDispatchCalendar = $calendar;
            if ($icuMonth < 0 || $icuMonth > 11) {
                return null;
            }
            if ($isLeapMonth) {
                $leapIcu = self::chineseLeapMonthIcuFromTable($year);
                if ($leapIcu !== $icuMonth) {
                    return null; // caller decides constrain vs reject.
                }
            }
            $chronoIdx = self::chineseChronoIdxFromIcu($year, $icuMonth, $isLeapMonth);
            if ($chronoIdx === null) {
                return null;
            }
            $dim = self::chineseDaysInMonthByChrono($year, $chronoIdx);
            if ($day < 1 || $day > $dim) {
                return null;
            }
            $iso = self::chineseToIsoDate($year, $icuMonth, $isLeapMonth, $day);
            if ($iso !== null) {
                return $iso;
            }
            // Out of table range: fall through to ICU fallback.
        }
        if (!class_exists('IntlCalendar', false)) {
            return null;
        }
        try {
            $icuCal = $calendar;
            static $aliasMap = [
                'islamicc' => 'islamic-civil',
                'ethioaa' => 'ethiopic-amete-alem',
            ];
            if (isset($aliasMap[$calendar])) {
                $icuCal = $aliasMap[$calendar];
            }
            $cal = \IntlCalendar::createInstance('UTC', "en@calendar={$icuCal}");
            // Chinese / Dangi use a 60-year sexagenary YEAR with a separate
            // EXTENDED_YEAR that holds the actual year. setDate sets YEAR; we
            // need to pre-set EXTENDED_YEAR to the spec year.
            if (in_array($calendar, ['chinese', 'dangi'], true)) {
                // If the user asked for a leap month, validate that the
                // year actually has its leap at this position before ICU
                // silently normalizes the invalid state.
                if ($isLeapMonth) {
                    $leapIcu = self::chineseLeapMonthIndex($calendar, $year);
                    if ($leapIcu !== $icuMonth) {
                        return null; // caller decides constrain vs reject.
                    }
                }
                $cal->set(\IntlCalendar::FIELD_EXTENDED_YEAR, $year);
                $cal->set(\IntlCalendar::FIELD_MONTH, $icuMonth);
                $cal->set(\IntlCalendar::FIELD_IS_LEAP_MONTH, $isLeapMonth ? 1 : 0);
                $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, $day);
            } else {
                $cal->setDate($year, $icuMonth, $day);
            }
            $epochMs = $cal->getTime();
            $epochSec = (int) ($epochMs / 1000);
            $isoStr = gmdate('Y-m-d', $epochSec);
            // gmdate prefixes negative ISO years with "-", so a naive
            // explode("-") splits it into ["", "Y", "m", "d"]. Match the
            // signed year explicitly.
            if (preg_match('/^(-?\d+)-(\d{2})-(\d{2})$/', $isoStr, $m) === 1) {
                return [
                    'year' => (int) $m[1],
                    'month' => (int) $m[2],
                    'day' => (int) $m[3],
                ];
            }
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** True if the Hebrew year has 13 months (leap). */
    private static function isHebrewLeapYear(int $year): bool
    {
        $r = (7 * $year + 1) % 19;
        if ($r < 0) {
            $r += 19;
        }
        return $r < 7;
    }

    /**
     * Calendar-elapsed-days through 1 Tishrei of the given AM year,
     * counted from the Hebrew epoch (1 Tishrei AM 1 = day 0).
     *
     * This is Reingold-Dershowitz "Calendrical Calculations" §8.2 step 1:
     * the molad-of-Tishri postponed only by the Lo ADU rule. Year-length
     * correction (the "no 356-day, no 382-day year" fix) is applied
     * separately in {@see hebrewNewYearDay}.
     */
    private static function hebrewCalendarElapsedDays(int $year): int
    {
        $monthsElapsed = intdiv(235 * $year - 234, 19);
        // Parts of the lunation: 24 hours/day * 1080 parts/hour = 25920 parts/day.
        // Each lunation: 29d 12h 793p.
        $partsElapsed = 12084 + 13753 * $monthsElapsed;
        $day = 29 * $monthsElapsed + intdiv($partsElapsed, 25920);
        // Lo ADU rosh: postpone by 1 if the molad would fall on Sun/Wed/Fri.
        // Reingold's compact form: if (3*(day+1)) mod 7 < 3, postpone.
        $r = (3 * ($day + 1)) % 7;
        if ($r < 0) {
            $r += 7;
        }
        if ($r < 3) {
            $day++;
        }
        return $day;
    }

    /**
     * Day count from the Hebrew epoch to 1 Tishrei of the given AM year,
     * including the year-length correction (Gatarad / Betutkpat fix-up).
     *
     * Per Reingold-Dershowitz, the postponement on year Y depends on both
     * the (Y-1, Y) gap and the (Y, Y+1) gap:
     *   - if (Y, Y+1) raw = 356, postpone year Y by 2 days,
     *   - if (Y-1, Y) raw = 382, postpone year Y by 1 day.
     */
    private static function hebrewNewYearDay(int $year): int
    {
        $day = self::hebrewCalendarElapsedDays($year);
        $prevGap = $day - self::hebrewCalendarElapsedDays($year - 1);
        $nextGap = self::hebrewCalendarElapsedDays($year + 1) - $day;
        if ($nextGap === 356) {
            $day += 2;
        } elseif ($prevGap === 382) {
            $day += 1;
        }
        return $day;
    }

    /**
     * Days from ISO 1970-01-01 to 1 Tishrei of the given Hebrew year.
     * Hebrew epoch = ISO -3760-09-07 proleptic Gregorian = day -2092590.
     */
    private static function hebrewElapsedDaysToFirstTishrei(int $year): int
    {
        return self::hebrewNewYearDay($year) - 2092590;
    }

    /** Number of days in a Hebrew year (353/354/355 regular, 383/384/385 leap). */
    private static function hebrewYearLength(int $year): int
    {
        return self::hebrewNewYearDay($year + 1) - self::hebrewNewYearDay($year);
    }

    /**
     * Days in the given Hebrew month using ICU's month indexing (which is
     * stable across leap and non-leap years):
     *   0=Tishrei, 1=Cheshvan, 2=Kislev, 3=Tevet, 4=Shevat,
     *   5=Adar I (leap years only), 6=Adar/Adar II,
     *   7=Nisan, 8=Iyar, 9=Sivan, 10=Tammuz, 11=Av, 12=Elul.
     * In non-leap years ICU index 5 is invalid (callers must skip it).
     */
    private static function hebrewDaysInMonth(int $year, int $icuMonth): int
    {
        $isLeap = self::isHebrewLeapYear($year);
        $yearLen = self::hebrewYearLength($year);
        // Cheshvan (ICU index 1): 30 only in long years (355 / 385).
        if ($icuMonth === 1) {
            return ($yearLen === 355 || $yearLen === 385) ? 30 : 29;
        }
        // Kislev (ICU index 2): 29 only in short years (353 / 383).
        if ($icuMonth === 2) {
            return ($yearLen === 353 || $yearLen === 383) ? 29 : 30;
        }
        // Fixed-length months by ICU index.
        static $fixed = [
            0 => 30,  // Tishrei
            3 => 29,  // Tevet
            4 => 30,  // Shevat
            6 => 29,  // Adar / Adar II
            7 => 30,  // Nisan
            8 => 29,  // Iyar
            9 => 30,  // Sivan
            10 => 29, // Tammuz
            11 => 30, // Av
            12 => 29, // Elul
        ];
        if (isset($fixed[$icuMonth])) {
            return $fixed[$icuMonth];
        }
        // Adar I (leap years only): 30 days.
        if ($icuMonth === 5 && $isLeap) {
            return 30;
        }
        return 0;
    }

    /**
     * ISO date (y/m/d) for the given Hebrew (year, ICU month index, day).
     * Returns ['year' => , 'month' => , 'day' => ]. ICU month index 5 is
     * Adar I (leap years only); in non-leap years the caller must NOT pass
     * icuMonth=5.
     *
     * @return array{year: int, month: int, day: int}
     */
    private static function hebrewToIsoDate(int $hYear, int $icuMonth, int $hDay): array
    {
        $isLeap = self::isHebrewLeapYear($hYear);
        if (!$isLeap && $icuMonth === 5) {
            // Treat as Adar (icuMonth=6) for safety.
            $icuMonth = 6;
        }
        if ($icuMonth < 0 || $icuMonth > 12) {
            $icuMonth = max(0, min(12, $icuMonth));
        }
        $epochDays = self::hebrewElapsedDaysToFirstTishrei($hYear);
        for ($m = 0; $m < $icuMonth; $m++) {
            // Skip the Adar I slot (5) in non-leap years; it has no days.
            if (!$isLeap && $m === 5) {
                continue;
            }
            $epochDays += self::hebrewDaysInMonth($hYear, $m);
        }
        $epochDays += $hDay - 1;
        return self::isoDateFromDays($epochDays);
    }

    /**
     * Convert days-since-1970-01-01 to an ISO date.
     *
     * @return array{year: int, month: int, day: int}
     */
    private static function isoDateFromDays(int $days): array
    {
        // Inverse of isoDateToDays (Howard Hinnant civil_from_days).
        $days += 719468;
        $era = intdiv($days >= 0 ? $days : $days - 146096, 146097);
        $doe = $days - $era * 146097;
        $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);
        $y = $yoe + $era * 400;
        $doy = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));
        $mp = intdiv(5 * $doy + 2, 153);
        $d = $doy - intdiv(153 * $mp + 2, 5) + 1;
        $m = $mp < 10 ? $mp + 3 : $mp - 9;
        $y += $m <= 2 ? 1 : 0;
        return ['year' => $y, 'month' => $m, 'day' => $d];
    }

    /**
     * Convert an ISO date to Hebrew (AM year, ICU month index, day).
     *
     * @return array{year: int, icuMonth: int, day: int}
     */
    private static function isoToHebrewDate(int $isoY, int $isoM, int $isoD): array
    {
        $days = self::isoDateToDays($isoY, $isoM, $isoD);
        // Estimate Hebrew year. Hebrew year ≈ iso + 3760 + (after Tishrei → +1).
        $approx = $isoY + 3761;
        // Walk down from approx until 1 Tishrei <= days.
        $year = $approx + 1;
        while (self::hebrewElapsedDaysToFirstTishrei($year) > $days) {
            $year--;
        }
        // Year now is the Hebrew year containing the date.
        $offset = $days - self::hebrewElapsedDaysToFirstTishrei($year);
        $isLeap = self::isHebrewLeapYear($year);
        $icuMonth = 0;
        while ($icuMonth <= 12) {
            // Skip the Adar I slot (5) in non-leap years.
            if (!$isLeap && $icuMonth === 5) {
                $icuMonth++;
                continue;
            }
            $dim = self::hebrewDaysInMonth($year, $icuMonth);
            if ($dim > 0 && $offset < $dim) {
                break;
            }
            $offset -= $dim;
            $icuMonth++;
        }
        return ['year' => $year, 'icuMonth' => $icuMonth, 'day' => $offset + 1];
    }

    /** Convert ICU 0-indexed month to spec monthCode for the given calendar. */
    private static function calendarMonthToCode(string $calendar, int $year, int $icuMonth, bool $isLeap = false): string
    {
        if ($calendar === 'hebrew') {
            if ($icuMonth >= 0 && $icuMonth <= 4) {
                return 'M' . str_pad((string) ($icuMonth + 1), 2, '0', STR_PAD_LEFT);
            }
            if ($icuMonth === 5) {
                return self::isHebrewLeapYear($year) ? 'M05L' : 'M06';
            }
            return 'M' . str_pad((string) $icuMonth, 2, '0', STR_PAD_LEFT);
        }
        if (in_array($calendar, ['chinese', 'dangi'], true)) {
            // ICU month is 0..11 for the non-leap month code; leap month
            // shares the same MONTH index but with IS_LEAP_MONTH=1, exposed
            // by the spec as M(NN)L.
            $base = 'M' . str_pad((string) ($icuMonth + 1), 2, '0', STR_PAD_LEFT);
            return $isLeap ? $base . 'L' : $base;
        }
        return 'M' . str_pad((string) ($icuMonth + 1), 2, '0', STR_PAD_LEFT);
    }

    /** Calendar-specific 1-indexed month number (matches the spec's `month` getter). */
    private static function calendarMonthToOneBased(string $calendar, int $year, int $icuMonth, bool $isLeap = false): int
    {
        if ($calendar === 'hebrew') {
            if (self::isHebrewLeapYear($year)) {
                if ($icuMonth >= 0 && $icuMonth <= 4) {
                    return $icuMonth + 1;
                }
                if ($icuMonth === 5) {
                    return 6;
                }
                return $icuMonth + 1;
            }
            if ($icuMonth >= 0 && $icuMonth <= 4) {
                return $icuMonth + 1;
            }
            if ($icuMonth === 5 || $icuMonth === 6) {
                return 6;
            }
            return $icuMonth;
        }
        if (in_array($calendar, ['chinese', 'dangi'], true)) {
            $leapIcu = self::chineseLeapMonthIndex($calendar, $year);
            if ($leapIcu === null) {
                return $icuMonth + 1;
            }
            // Leap year: chronological positions:
            //  ICU 0..leapIcu      → 1..(leapIcu+1)  (non-leap)
            //  ICU leapIcu (L)     → leapIcu+2       (leap)
            //  ICU (leapIcu+1)..11 → (leapIcu+3)..13 (non-leap)
            if ($isLeap) {
                return $leapIcu + 2;
            }
            if ($icuMonth <= $leapIcu) {
                return $icuMonth + 1;
            }
            return $icuMonth + 2;
        }
        return $icuMonth + 1;
    }

    /** True if ICU's month index represents a leap month for that year. */
    private static function calendarMonthIsLeap(string $calendar, int $year, int $icuMonth): bool
    {
        if ($calendar === 'hebrew') {
            return self::isHebrewLeapYear($year) && $icuMonth === 5;
        }
        return false;
    }

    // -----------------------------------------------------------------------
    // Ethiopic / ethioaa calendar (pure-PHP, ICU-independent)
    //
    // 13-month calendar: months 1..12 = 30 days each, month 13 (Pagume) =
    // 5 or 6 days. Leap rule: year y is leap when y mod 4 == 3
    // (Reingold-Dershowitz "Calendrical Calculations" §3). Ethiopic year 1
    // EE begins 1 Meskerem = ISO 8 AD Aug 27 Gregorian proleptic (= Julian
    // Aug 29, 8 AD). Days from ISO 1970-01-01 to that date = -716367.
    // ethioaa = ethiopic + 5500 (same arithmetic, shifted year label).
    // -----------------------------------------------------------------------

    /** Days from ISO 1970-01-01 to 1 Meskerem 1 EE (Aug 27, 8 AD Gregorian). */
    private const ETHIOPIC_EPOCH_DAYS = -716367;

    /** ethioaa = ethiopic + 5500 (Amete Alem precedes Amete Mihret by 5500 EE). */
    private const ETHIOAA_YEAR_OFFSET = 5500;

    /** True if the given ethiopic year is a leap year (y mod 4 == 3). */
    private static function isEthiopicLeapYear(int $year): bool
    {
        $r = $year % 4;
        if ($r < 0) {
            $r += 4;
        }
        return $r === 3;
    }

    /** Days in the given ethiopic year (365 or 366). */
    private static function ethiopicYearLength(int $year): int
    {
        return self::isEthiopicLeapYear($year) ? 366 : 365;
    }

    /** Days in the given ethiopic month: 30 for 1..12, 5 or 6 for 13. */
    private static function ethiopicDaysInMonth(int $year, int $month): int
    {
        if ($month >= 1 && $month <= 12) {
            return 30;
        }
        if ($month === 13) {
            return self::isEthiopicLeapYear($year) ? 6 : 5;
        }
        return 0;
    }

    /**
     * Days from ISO 1970-01-01 to 1 Meskerem of the given ethiopic year.
     * For 1 Meskerem 1 EE this is ETHIOPIC_EPOCH_DAYS.
     */
    private static function ethiopicNewYearDay(int $year): int
    {
        $y1 = $year - 1;
        if ($y1 >= 0) {
            // Number of leap years in [1..y1] is floor((y1 - 3) / 4) + 1
            // if y1 >= 3, else 0.
            $leaps = $y1 >= 3 ? intdiv($y1 - 3, 4) + 1 : 0;
            return self::ETHIOPIC_EPOCH_DAYS + 365 * $y1 + $leaps;
        }
        // year <= 0: walk forward summing year lengths.
        $days = self::ETHIOPIC_EPOCH_DAYS;
        for ($yy = $year; $yy < 1; $yy++) {
            $days -= self::ethiopicYearLength($yy);
        }
        return $days;
    }

    /**
     * Convert (ethiopic year, month 1..13, day 1..30) to ISO ['year','month','day'].
     *
     * @return array{year: int, month: int, day: int}
     */
    private static function ethiopicToIsoDate(int $year, int $month, int $day): array
    {
        $days = self::ethiopicNewYearDay($year);
        for ($m = 1; $m < $month; $m++) {
            $days += self::ethiopicDaysInMonth($year, $m);
        }
        $days += $day - 1;
        return self::isoDateFromDays($days);
    }

    /**
     * Convert ISO (y,m,d) to ['year' => ethiopicYear, 'month' => 1..13, 'day' => 1..30].
     *
     * @return array{year: int, month: int, day: int}
     */
    private static function isoToEthiopicDate(int $isoY, int $isoM, int $isoD): array
    {
        $days = self::isoDateToDays($isoY, $isoM, $isoD);
        // Approximate ethiopic year via 4-year cycle.
        $offset = $days - self::ETHIOPIC_EPOCH_DAYS;
        $year = intdiv($offset, 1461) * 4 + 1;
        // Refine.
        while (self::ethiopicNewYearDay($year + 1) <= $days) {
            $year++;
        }
        while (self::ethiopicNewYearDay($year) > $days) {
            $year--;
        }
        $dayOfYear = $days - self::ethiopicNewYearDay($year); // 0-indexed
        if ($dayOfYear < 360) {
            $month = intdiv($dayOfYear, 30) + 1;
            $dayOfMonth = ($dayOfYear % 30) + 1;
        } else {
            $month = 13;
            $dayOfMonth = $dayOfYear - 360 + 1;
        }
        return ['year' => $year, 'month' => $month, 'day' => $dayOfMonth];
    }

    /**
     * Resolve a user-supplied ethiopic / ethioaa year to the canonical
     * ethiopic year (ethioaa year y → ethiopic year y - 5500).
     */
    private static function ethiopicCanonicalYear(string $calendar, int $userYear): int
    {
        if ($calendar === 'ethioaa' || $calendar === 'ethiopic-amete-alem') {
            return $userYear - self::ETHIOAA_YEAR_OFFSET;
        }
        return $userYear;
    }

    /**
     * Map an internal ethiopic year to the user-visible year for the given
     * calendar (ethioaa users see year + 5500).
     */
    private static function ethiopicUserYear(string $calendar, int $ethiopicYear): int
    {
        if ($calendar === 'ethioaa' || $calendar === 'ethiopic-amete-alem') {
            return $ethiopicYear + self::ETHIOAA_YEAR_OFFSET;
        }
        return $ethiopicYear;
    }

    /**
     * Compute (years, months, days) between two ISO dates in the ethiopic
     * calendar's terms, where smlY/M/D <= lrgY/M/D. Mirrors the
     * spec's DifferenceISODate semantics for a 13-month calendar.
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function ethiopicYearsMonthsDaysBetween(
        int $smlY,
        int $smlM,
        int $smlD,
        int $lrgY,
        int $lrgM,
        int $lrgD,
        string $largestUnit,
    ): ?array {
        $smlE = self::isoToEthiopicDate($smlY, $smlM, $smlD);
        $endDays = self::isoDateToDays($lrgY, $lrgM, $lrgD);

        $years = 0;
        if ($largestUnit === 'year') {
            $cand = $smlE['year'];
            while (true) {
                $probeIso = self::ethiopicToIsoDate(
                    $cand + 1,
                    $smlE['month'],
                    min($smlE['day'], self::ethiopicDaysInMonth($cand + 1, $smlE['month'])),
                );
                $probeDays = self::isoDateToDays(
                    $probeIso['year'],
                    $probeIso['month'],
                    $probeIso['day'],
                );
                if ($probeDays > $endDays) {
                    break;
                }
                $cand++;
                $years++;
            }
        }

        $anchorY = $smlE['year'] + $years;
        $anchorM = $smlE['month'];
        $anchorD = min($smlE['day'], self::ethiopicDaysInMonth($anchorY, $anchorM));

        $months = 0;
        if ($largestUnit !== 'day' && $largestUnit !== 'week') {
            while (true) {
                $nextM = $anchorM + 1;
                $nextY = $anchorY;
                if ($nextM > 13) {
                    $nextM = 1;
                    $nextY++;
                }
                $nextD = min($smlE['day'], self::ethiopicDaysInMonth($nextY, $nextM));
                $probeIso = self::ethiopicToIsoDate($nextY, $nextM, $nextD);
                $probeDays = self::isoDateToDays(
                    $probeIso['year'],
                    $probeIso['month'],
                    $probeIso['day'],
                );
                if ($probeDays > $endDays) {
                    break;
                }
                $anchorY = $nextY;
                $anchorM = $nextM;
                $anchorD = $nextD;
                $months++;
            }
        }

        $anchorIso = self::ethiopicToIsoDate($anchorY, $anchorM, $anchorD);
        $anchorDays = self::isoDateToDays(
            $anchorIso['year'],
            $anchorIso['month'],
            $anchorIso['day'],
        );
        $days = $endDays - $anchorDays;
        if ($days < 0) {
            return null;
        }
        return [$years, $months, $days];
    }

    /**
     * Ethiopic addition: add (years, months) in ethiopic terms then constrain
     * the day. Returns [isoY, isoM, isoD] in the proleptic Gregorian calendar.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function ethiopicAddYearsMonthsIso(
        int $isoY,
        int $isoM,
        int $isoD,
        int $years,
        int $months,
        string $overflow,
    ): array {
        $e = self::isoToEthiopicDate($isoY, $isoM, $isoD);
        $startDay = $e['day'];
        $newY = $e['year'] + $years;
        $newM = $e['month'] + $months;
        // Normalize month overflow with 13 months per year.
        while ($newM > 13) {
            $newM -= 13;
            $newY++;
        }
        while ($newM < 1) {
            $newM += 13;
            $newY--;
        }
        $dim = self::ethiopicDaysInMonth($newY, $newM);
        $newD = $startDay;
        if ($newD > $dim) {
            if ($overflow === 'reject') {
                throw new RangeError("Day {$startDay} out of range after calendar arithmetic");
            }
            $newD = $dim;
        }
        $iso = self::ethiopicToIsoDate($newY, $newM, $newD);
        return [$iso['year'], $iso['month'], $iso['day']];
    }

    /**
     * Hebrew addition: add (years, months) in hebrew terms then constrain
     * the day. Avoids ICU's leap-month boundary errors on older ICU.
     *
     * Algorithm:
     *   1) Add `years` while preserving the monthCode. ICU month indices
     *      0..4 and 6..12 are stable across leap and non-leap years (they
     *      map to monthCodes M01..M05 and M06..M12 respectively). Only
     *      ICU index 5 (Adar I / M05L) is leap-only — when crossing into
     *      a non-leap year we constrain it to ICU 6 (M06 = Adar).
     *   2) Add `months` chronologically, treating leap years as having 13
     *      months and non-leap years as 12, so M05L counts as one month
     *      between Shevat and Adar.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function hebrewAddYearsMonthsIso(
        int $isoY,
        int $isoM,
        int $isoD,
        int $years,
        int $months,
        string $overflow,
    ): array {
        $h = self::isoToHebrewDate($isoY, $isoM, $isoD);
        $newY = $h['year'] + $years;
        $newIcuMonth = $h['icuMonth'];
        $startDay = $h['day'];

        // Constrain leap-only Adar I into Adar when crossing into a non-leap year.
        if (!self::isHebrewLeapYear($newY) && $newIcuMonth === 5) {
            $newIcuMonth = 6;
        }

        // Apply remaining month delta chronologically.
        if ($months !== 0) {
            $pos = self::hebrewChronoPosFromIcu($newY, $newIcuMonth);
            $pos += $months;
            while (true) {
                $monthsInY = self::isHebrewLeapYear($newY) ? 13 : 12;
                if ($pos > $monthsInY) {
                    $pos -= $monthsInY;
                    $newY++;
                    continue;
                }
                if ($pos < 1) {
                    $newY--;
                    $monthsInPrev = self::isHebrewLeapYear($newY) ? 13 : 12;
                    $pos += $monthsInPrev;
                    continue;
                }
                break;
            }
            $newIcuMonth = self::hebrewIcuFromChronoPos($newY, $pos);
        }

        $dim = self::hebrewDaysInMonth($newY, $newIcuMonth);
        $newD = $startDay;
        if ($newD > $dim) {
            if ($overflow === 'reject') {
                throw new RangeError("Day {$startDay} out of range after calendar arithmetic");
            }
            $newD = $dim;
        }
        $iso = self::hebrewToIsoDate($newY, $newIcuMonth, $newD);
        return [$iso['year'], $iso['month'], $iso['day']];
    }

    /**
     * Convert an ICU hebrew month index (0..12) to a chronological position
     * (1..N within the year, where N is 12 or 13 depending on leap status).
     *   Non-leap year: ICU 0..4 → pos 1..5; ICU 6..12 → pos 6..12.
     *   Leap year:     ICU 0..5 → pos 1..6 (5=AdarI=6); ICU 6 (AdarII) → 7;
     *                  ICU 7..12 → pos 8..13.
     */
    private static function hebrewChronoPosFromIcu(int $year, int $icuMonth): int
    {
        $isLeap = self::isHebrewLeapYear($year);
        if (!$isLeap) {
            if ($icuMonth <= 4) {
                return $icuMonth + 1; // 0..4 → 1..5
            }
            if ($icuMonth >= 6) {
                return $icuMonth; // 6..12 → 6..12
            }
            // ICU index 5 should not occur in non-leap years; clamp.
            return 6;
        }
        if ($icuMonth <= 5) {
            return $icuMonth + 1; // 0..5 → 1..6
        }
        return $icuMonth + 1; // 6..12 → 7..13
    }

    /**
     * Inverse of hebrewChronoPosFromIcu.
     */
    private static function hebrewIcuFromChronoPos(int $year, int $pos): int
    {
        $isLeap = self::isHebrewLeapYear($year);
        if (!$isLeap) {
            if ($pos >= 1 && $pos <= 5) {
                return $pos - 1;
            }
            // pos 6..12 → ICU 6..12. Drop pos 13 to Elul (12).
            if ($pos >= 6 && $pos <= 12) {
                return $pos;
            }
            return 12;
        }
        if ($pos >= 1 && $pos <= 6) {
            return $pos - 1; // 1..6 → ICU 0..5
        }
        if ($pos >= 7 && $pos <= 13) {
            return $pos - 1; // 7..13 → ICU 6..12
        }
        return 12;
    }

    // -----------------------------------------------------------------------
    // Chinese / Dangi calendar (pure-PHP, ICU-independent)
    //
    // The Chinese calendar is astronomically determined: each month begins
    // on the day of a new moon in Beijing local time, and a leap month is
    // inserted whenever a year between two winter-solstice-containing
    // months has 13 new moons. Reingold-Dershowitz "Calendrical
    // Calculations" §19 gives the algorithm in terms of solar longitude
    // and lunar phase, both of which require high-precision astronomy
    // (errors of a few minutes around midnight Beijing time shift a date
    // by one day).
    //
    // To stay independent of the host ICU version (Ubuntu CI ships ICU
    // 70/74 whose leap-month placements diverge from Unicode 16 / V8),
    // phasis ships a precomputed table generated from an R-D-equivalent
    // implementation (ICU 76+). The table is regenerated by
    // bin/gen-chinese-table.php and consumed here. The runtime never
    // calls IntlCalendar for chinese / dangi arithmetic.
    //
    // dangi is the Korean ICU calendar; the underlying month / leap-month
    // arithmetic is identical to chinese (only the era differs), so we
    // route both through the same table.
    // -----------------------------------------------------------------------

    /**
     * Lazily-decoded packed tables for chinese / dangi calendar lookups.
     * Indexed by calendar id ('chinese' or 'dangi'). Each entry is a
     * tuple [blob, start, end].
     *
     * @var array<string,array{blob:string,start:int,end:int}>
     */
    private static array $lunisolarTables = [];

    /** Decode the chinese / dangi calendar table for the given id on first use. */
    private static function loadLunisolarTable(string $calendar): void
    {
        if (isset(self::$lunisolarTables[$calendar])) {
            return;
        }
        $path = __DIR__ . '/data/' . $calendar . '_calendar.php';
        if (!is_file($path)) {
            self::$lunisolarTables[$calendar] = ['blob' => '', 'start' => 0, 'end' => 0];
            return;
        }
        $data = require $path;
        $compressed = base64_decode($data['blob'], true);
        if ($compressed === false) {
            self::$lunisolarTables[$calendar] = ['blob' => '', 'start' => 0, 'end' => 0];
            return;
        }
        $blob = gzuncompress($compressed);
        if ($blob === false) {
            self::$lunisolarTables[$calendar] = ['blob' => '', 'start' => 0, 'end' => 0];
            return;
        }
        self::$lunisolarTables[$calendar] = [
            'blob' => $blob,
            'start' => (int) $data['start'],
            'end' => (int) $data['end'],
        ];
    }

    /**
     * Calendar context for the in-flight chinese / dangi operation. Set
     * by the dispatcher at entry to chineseDispatch() and consumed by
     * every internal helper, so we don't have to thread the calendar id
     * through ~14 helper signatures. PHP is single-threaded so the
     * static is safe; chineseDispatch() restores the previous value via
     * try/finally to keep nested calls correct (e.g. when dangi probes
     * during a chinese reference search, or vice versa).
     */
    private static string $chineseDispatchCalendar = 'chinese';

    /**
     * Look up the packed record for the in-flight calendar's extended-year.
     *
     * @return array{newYearDays:int,leapIcuMonth:int,monthLenBits:int,monthCount:int}|null
     */
    private static function chineseYearInfo(int $extYear): ?array
    {
        $calendar = self::$chineseDispatchCalendar;
        self::loadLunisolarTable($calendar);
        $tbl = self::$lunisolarTables[$calendar];
        if ($tbl['blob'] === '') {
            return null;
        }
        if ($extYear < $tbl['start'] || $extYear > $tbl['end']) {
            return null;
        }
        $offset = ($extYear - $tbl['start']) * 8;
        $record = substr($tbl['blob'], $offset, 8);
        if (strlen($record) !== 8) {
            return null;
        }
        $unpacked = unpack('lnewYearDays/cleapIcu/vmonthLenBits/CmonthCount', $record);
        if ($unpacked === false) {
            return null;
        }
        $monthCount = (int) $unpacked['monthCount'];
        if ($monthCount !== 12 && $monthCount !== 13) {
            return null;
        }
        return [
            'newYearDays' => (int) $unpacked['newYearDays'],
            'leapIcuMonth' => (int) $unpacked['leapIcu'],
            'monthLenBits' => (int) $unpacked['monthLenBits'],
            'monthCount' => $monthCount,
        ];
    }

    /** Days in the chinese month at chronological index (0-indexed) for the year. */
    private static function chineseDaysInMonthByChrono(int $extYear, int $chronoIdx): int
    {
        $info = self::chineseYearInfo($extYear);
        if ($info === null) {
            return 30;
        }
        if ($chronoIdx < 0 || $chronoIdx >= $info['monthCount']) {
            return 30;
        }
        return (($info['monthLenBits'] >> $chronoIdx) & 1) === 1 ? 30 : 29;
    }

    /** Days in chinese year (354..385). */
    private static function chineseYearLength(int $extYear): int
    {
        $info = self::chineseYearInfo($extYear);
        if ($info === null) {
            return 354;
        }
        $days = 0;
        for ($i = 0; $i < $info['monthCount']; $i++) {
            $days += (($info['monthLenBits'] >> $i) & 1) === 1 ? 30 : 29;
        }
        return $days;
    }

    /** Leap ICU-month index (0..11) for the chinese year, or null. */
    private static function chineseLeapMonthIcuFromTable(int $extYear): ?int
    {
        $info = self::chineseYearInfo($extYear);
        if ($info === null) {
            return null;
        }
        $l = $info['leapIcuMonth'];
        if ($l < 0 || $l > 11) {
            return null;
        }
        return $l;
    }

    /**
     * Convert ISO (y,m,d) to chinese ['year','icuMonth','isLeap','day'].
     *
     * @return array{year:int,icuMonth:int,isLeap:bool,day:int}|null
     */
    private static function isoToChineseDate(int $isoY, int $isoM, int $isoD): ?array
    {
        $days = self::isoDateToDays($isoY, $isoM, $isoD);
        $extYear = $isoY;
        $info = self::chineseYearInfo($extYear);
        if ($info === null) {
            return null;
        }
        $guardLimit = 6;
        while ($info !== null && $info['newYearDays'] > $days && $guardLimit-- > 0) {
            $extYear--;
            $info = self::chineseYearInfo($extYear);
        }
        if ($info === null) {
            return null;
        }
        $guardLimit = 6;
        while ($guardLimit-- > 0) {
            $next = self::chineseYearInfo($extYear + 1);
            if ($next === null) {
                break;
            }
            if ($next['newYearDays'] <= $days) {
                $extYear++;
                $info = $next;
                continue;
            }
            break;
        }
        if ($info['newYearDays'] > $days) {
            return null;
        }
        $offset = $days - $info['newYearDays'];
        $leapIcu = $info['leapIcuMonth'];
        $cursor = 0;
        for ($idx = 0; $idx < $info['monthCount']; $idx++) {
            $dim = (($info['monthLenBits'] >> $idx) & 1) === 1 ? 30 : 29;
            if ($offset < $cursor + $dim) {
                $dayOfMonth = $offset - $cursor + 1;
                if ($leapIcu === -1) {
                    return [
                        'year' => $extYear,
                        'icuMonth' => $idx,
                        'isLeap' => false,
                        'day' => $dayOfMonth,
                    ];
                }
                if ($idx <= $leapIcu) {
                    return [
                        'year' => $extYear,
                        'icuMonth' => $idx,
                        'isLeap' => false,
                        'day' => $dayOfMonth,
                    ];
                }
                if ($idx === $leapIcu + 1) {
                    return [
                        'year' => $extYear,
                        'icuMonth' => $leapIcu,
                        'isLeap' => true,
                        'day' => $dayOfMonth,
                    ];
                }
                return [
                    'year' => $extYear,
                    'icuMonth' => $idx - 1,
                    'isLeap' => false,
                    'day' => $dayOfMonth,
                ];
            }
            $cursor += $dim;
        }
        return null;
    }

    /**
     * Map chinese (icuMonth, isLeap) for the given extended-year to its
     * chronological index (0-indexed). Returns null if invalid.
     */
    private static function chineseChronoIdxFromIcu(int $extYear, int $icuMonth, bool $isLeap): ?int
    {
        $leapIcu = self::chineseLeapMonthIcuFromTable($extYear);
        if ($leapIcu === null) {
            if ($isLeap) {
                return null;
            }
            if ($icuMonth < 0 || $icuMonth > 11) {
                return null;
            }
            return $icuMonth;
        }
        if ($isLeap) {
            if ($icuMonth !== $leapIcu) {
                return null;
            }
            return $leapIcu + 1;
        }
        if ($icuMonth < 0 || $icuMonth > 11) {
            return null;
        }
        if ($icuMonth <= $leapIcu) {
            return $icuMonth;
        }
        return $icuMonth + 1;
    }

    /**
     * Map a chronological index (0-indexed) back to (icuMonth, isLeap).
     *
     * @return array{icuMonth: int, isLeap: bool}|null
     */
    private static function chineseIcuFromChronoIdx(int $extYear, int $chronoIdx): ?array
    {
        $info = self::chineseYearInfo($extYear);
        if ($info === null) {
            return null;
        }
        if ($chronoIdx < 0 || $chronoIdx >= $info['monthCount']) {
            return null;
        }
        $leapIcu = $info['leapIcuMonth'];
        if ($leapIcu === -1) {
            return ['icuMonth' => $chronoIdx, 'isLeap' => false];
        }
        if ($chronoIdx <= $leapIcu) {
            return ['icuMonth' => $chronoIdx, 'isLeap' => false];
        }
        if ($chronoIdx === $leapIcu + 1) {
            return ['icuMonth' => $leapIcu, 'isLeap' => true];
        }
        return ['icuMonth' => $chronoIdx - 1, 'isLeap' => false];
    }

    /**
     * Convert chinese (extYear, icuMonth, isLeap, day) to ISO.
     *
     * @return array{year:int,month:int,day:int}|null
     */
    private static function chineseToIsoDate(int $extYear, int $icuMonth, bool $isLeap, int $day): ?array
    {
        $info = self::chineseYearInfo($extYear);
        if ($info === null) {
            return null;
        }
        $chronoIdx = self::chineseChronoIdxFromIcu($extYear, $icuMonth, $isLeap);
        if ($chronoIdx === null) {
            return null;
        }
        $dim = (($info['monthLenBits'] >> $chronoIdx) & 1) === 1 ? 30 : 29;
        if ($day < 1 || $day > $dim) {
            return null;
        }
        $cursor = 0;
        for ($i = 0; $i < $chronoIdx; $i++) {
            $cursor += (($info['monthLenBits'] >> $i) & 1) === 1 ? 30 : 29;
        }
        $absDays = $info['newYearDays'] + $cursor + ($day - 1);
        return self::isoDateFromDays($absDays);
    }

    /** Days in a specific chinese (extYear, icuMonth, isLeap). */
    private static function chineseDaysInMonth(int $extYear, int $icuMonth, bool $isLeap): int
    {
        $chronoIdx = self::chineseChronoIdxFromIcu($extYear, $icuMonth, $isLeap);
        if ($chronoIdx === null) {
            return 30;
        }
        return self::chineseDaysInMonthByChrono($extYear, $chronoIdx);
    }

    /** Day-of-year (1-indexed) in chinese terms for an ISO date. */
    private static function chineseDayOfYearForIso(int $isoY, int $isoM, int $isoD): ?int
    {
        $c = self::isoToChineseDate($isoY, $isoM, $isoD);
        if ($c === null) {
            return null;
        }
        $info = self::chineseYearInfo($c['year']);
        if ($info === null) {
            return null;
        }
        $days = self::isoDateToDays($isoY, $isoM, $isoD);
        return $days - $info['newYearDays'] + 1;
    }

    /**
     * Add (years, months) to an ISO date in chinese terms then constrain
     * the day. Returns [isoY, isoM, isoD] in proleptic Gregorian.
     *
     * @return array{0:int,1:int,2:int}|null
     */
    private static function chineseAddYearsMonthsIso(
        int $isoY,
        int $isoM,
        int $isoD,
        int $years,
        int $months,
        string $overflow,
    ): ?array {
        $c = self::isoToChineseDate($isoY, $isoM, $isoD);
        if ($c === null) {
            return null;
        }
        $newY = $c['year'] + $years;
        $newIcuMonth = $c['icuMonth'];
        $newLeap = $c['isLeap'];
        $startDay = $c['day'];

        if ($newLeap) {
            $tgtLeap = self::chineseLeapMonthIcuFromTable($newY);
            if ($tgtLeap !== $newIcuMonth) {
                $newLeap = false;
            }
        }

        if ($months !== 0) {
            $pos = self::chineseChronoIdxFromIcu($newY, $newIcuMonth, $newLeap);
            if ($pos === null) {
                return null;
            }
            $pos += $months;
            $guard = 0;
            while (true) {
                $info = self::chineseYearInfo($newY);
                if ($info === null || $guard++ > 100000) {
                    return null;
                }
                if ($pos >= $info['monthCount']) {
                    $pos -= $info['monthCount'];
                    $newY++;
                    continue;
                }
                if ($pos < 0) {
                    $newY--;
                    $prev = self::chineseYearInfo($newY);
                    if ($prev === null) {
                        return null;
                    }
                    $pos += $prev['monthCount'];
                    continue;
                }
                break;
            }
            $resolved = self::chineseIcuFromChronoIdx($newY, $pos);
            if ($resolved === null) {
                return null;
            }
            $newIcuMonth = $resolved['icuMonth'];
            $newLeap = $resolved['isLeap'];
        }

        $dim = self::chineseDaysInMonth($newY, $newIcuMonth, $newLeap);
        $newD = $startDay;
        if ($newD > $dim) {
            if ($overflow === 'reject') {
                throw new RangeError("Day {$startDay} out of range after calendar arithmetic");
            }
            $newD = $dim;
        }
        $iso = self::chineseToIsoDate($newY, $newIcuMonth, $newLeap, $newD);
        if ($iso === null) {
            return null;
        }
        return [$iso['year'], $iso['month'], $iso['day']];
    }

    /**
     * (years, months, days) between two ISO dates in chinese terms,
     * with sml <= lrg.
     *
     * @return array{0:int,1:int,2:int}|null
     */
    private static function chineseYearsMonthsDaysBetween(
        int $smlY,
        int $smlM,
        int $smlD,
        int $lrgY,
        int $lrgM,
        int $lrgD,
        string $largestUnit,
    ): ?array {
        $smlC = self::isoToChineseDate($smlY, $smlM, $smlD);
        if ($smlC === null) {
            return null;
        }
        $endDays = self::isoDateToDays($lrgY, $lrgM, $lrgD);

        $years = 0;
        if ($largestUnit === 'year') {
            $cand = $smlC['year'];
            while (true) {
                $next = $cand + 1;
                $probe = self::chineseAddYearsMonthsIso(
                    $smlY,
                    $smlM,
                    $smlD,
                    $next - $smlC['year'],
                    0,
                    'constrain',
                );
                if ($probe === null) {
                    break;
                }
                $probeDays = self::isoDateToDays($probe[0], $probe[1], $probe[2]);
                if ($probeDays > $endDays) {
                    break;
                }
                $cand = $next;
                $years++;
            }
        }

        $anchorIso = $years > 0
            ? self::chineseAddYearsMonthsIso($smlY, $smlM, $smlD, $years, 0, 'constrain')
            : [$smlY, $smlM, $smlD];
        if ($anchorIso === null) {
            return null;
        }

        $months = 0;
        if ($largestUnit !== 'day' && $largestUnit !== 'week') {
            while (true) {
                $probe = self::chineseAddYearsMonthsIso(
                    $anchorIso[0],
                    $anchorIso[1],
                    $anchorIso[2],
                    0,
                    1,
                    'constrain',
                );
                if ($probe === null) {
                    break;
                }
                $probeDays = self::isoDateToDays($probe[0], $probe[1], $probe[2]);
                if ($probeDays > $endDays) {
                    break;
                }
                $anchorIso = $probe;
                $months++;
            }
        }

        $anchorDays = self::isoDateToDays($anchorIso[0], $anchorIso[1], $anchorIso[2]);
        $days = $endDays - $anchorDays;
        if ($days < 0) {
            return null;
        }
        return [$years, $months, $days];
    }

    /** 1-indexed chronological month for chinese (icuMonth, isLeap). */
    private static function chineseMonthOneBased(int $extYear, int $icuMonth, bool $isLeap): int
    {
        $idx = self::chineseChronoIdxFromIcu($extYear, $icuMonth, $isLeap);
        if ($idx === null) {
            return $icuMonth + 1;
        }
        return $idx + 1;
    }

    // -----------------------------------------------------------------------
    // Helpers: arithmetic
    // -----------------------------------------------------------------------

    private static function instantAddDuration(string $ns, JsValue $durationArg, int $sign): JsObject
    {
        $dur = self::toDuration($durationArg);
        // Instant only supports time components.
        $hasCalUnit = self::getDurationField($dur, 'years') !== 0
            || self::getDurationField($dur, 'months') !== 0
            || self::getDurationField($dur, 'weeks') !== 0
            || self::getDurationField($dur, 'days') !== 0;
        if ($hasCalUnit) {
            throw new RangeError('Instant arithmetic does not support years, months, weeks, or days');
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
        $opts = self::getOptionsObject($options);
        $diffNs = bcsub($ns2, $ns1, 0);
        $largestUnit = 'second';
        $largestUnitExplicit = false;
        $smallestUnit = 'nanosecond';
        if ($opts instanceof JsObject) {
            $lu = $opts->get('largestUnit');
            if (!($lu instanceof JsUndefined)) {
                $largestUnitExplicit = true;
                $largestUnit = TypeConversion::toString($lu);
                $largestUnit = self::canonicalTemporalUnit($largestUnit);
                $instantLU = ['hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];
                if (!in_array($largestUnit, $instantLU, true)) {
                    throw new RangeError("Invalid largest unit for Instant: {$largestUnit}");
                }
            }
            // Read options in ALPHABETICAL order per spec.
            $ri = $opts->get('roundingIncrement');
            if (!($ri instanceof JsUndefined)) {
                $riNum = TypeConversion::toNumber($ri);
                if (!is_finite($riNum)) {
                    throw new RangeError("Invalid roundingIncrement");
                }
                $riNum = (int) $riNum;
                if ($riNum < 1 || $riNum > 1_000_000_000) {
                    throw new RangeError("Invalid roundingIncrement");
                }
            }
            $rm = $opts->get('roundingMode');
            if (!($rm instanceof JsUndefined)) {
                $rmStr = TypeConversion::toString($rm);
                $validRM = [
                    'ceil', 'floor', 'expand', 'trunc',
                    'halfCeil', 'halfFloor', 'halfExpand',
                    'halfTrunc', 'halfEven',
                ];
                if (!in_array($rmStr, $validRM, true)) {
                    throw new RangeError("Invalid roundingMode: {$rmStr}");
                }
            }
            $smallestUnit = 'nanosecond';
            $su = $opts->get('smallestUnit');
            if (!($su instanceof JsUndefined)) {
                $smallestUnit = TypeConversion::toString($su);
                $smallestUnit = self::canonicalTemporalUnit($smallestUnit);
                $instantUnits = ['hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];
                if (!in_array($smallestUnit, $instantUnits, true)) {
                    throw new RangeError("Invalid smallest unit for Instant: {$smallestUnit}");
                }
            }
            // Validate roundingIncrement divides evenly into next unit.
            if (isset($riNum) && $riNum > 1) {
                self::validateRoundingIncrement($smallestUnit, $riNum);
            }
            // Default largestUnit to smallestUnit if smallestUnit is larger.
            $unitOrder = ['hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];
            $liIdx = array_search($largestUnit, $unitOrder);
            $siIdx = array_search($smallestUnit, $unitOrder);
            if (!$largestUnitExplicit && $siIdx !== false && $liIdx !== false && $siIdx < $liIdx) {
                $largestUnit = $smallestUnit;
                $liIdx = $siIdx;
            }
            if ($liIdx !== false && $siIdx !== false && $liIdx > $siIdx) {
                throw new RangeError("largestUnit must be >= smallestUnit");
            }
        }
        // Apply rounding if smallestUnit != nanosecond or increment != 1.
        $roundIncrement = isset($riNum) ? (int) $riNum : 1;
        $roundMode = $rmStr ?? 'trunc';
        if ($smallestUnit !== 'nanosecond' || $roundIncrement !== 1) {
            $unitNsMap = [
                'hour' => '3600000000000',
                'minute' => '60000000000',
                'second' => '1000000000',
                'millisecond' => '1000000',
                'microsecond' => '1000',
                'nanosecond' => '1',
            ];
            $unitNs = $unitNsMap[$smallestUnit];
            $incrementNs = bcmul((string) $roundIncrement, $unitNs, 0);
            $diffNs = self::roundNs($diffNs, $incrementNs, $roundMode);
        }
        return self::nsToTimeDuration($diffNs, $largestUnit);
    }

    /** Validate that roundingIncrement divides evenly into the next highest unit. */
    private static function validateRoundingIncrement(string $unit, int $increment): void
    {
        $maxIncrements = [
            'hour' => 24,
            'minute' => 60,
            'second' => 60,
            'millisecond' => 1000,
            'microsecond' => 1000,
            'nanosecond' => 1000,
        ];
        $max = $maxIncrements[$unit] ?? null;
        if ($max !== null) {
            if ($increment >= $max || $max % $increment !== 0) {
                throw new RangeError("Invalid roundingIncrement for {$unit}: {$increment}");
            }
        }
    }

    /** Round a nanosecond value to the nearest increment. */
    /**
     * Round a sub-day ns remainder to whole days using the actual calendar
     * day length (which can be 23h or 25h around DST transitions, or 24h+24h
     * around Samoa-style date-line jumps). Returns the rounded value as ns
     * (always a whole number of days).
     */
    private static function roundDaysWithCalendarLength(
        string $remainingNs,
        JsObject $startObj,
        JsObject $dateOnly,
        int $increment,
        string $mode,
    ): string {
        $sign = bccomp($remainingNs, '0', 0);
        if ($sign === 0) {
            return '0';
        }
        // Compute the next-day boundary length: add (sign * 1 day) to the
        // already-walked dateOnly and measure the delta in ns.
        $nextDate = self::createDurationObject(
            self::getDurationField($dateOnly, 'years'),
            self::getDurationField($dateOnly, 'months'),
            self::getDurationField($dateOnly, 'weeks'),
            self::getDurationField($dateOnly, 'days') + ($sign > 0 ? 1 : -1),
            0,
            0,
            0,
            0,
            0,
            0,
        );
        $afterDates = self::addDurationToZdt($startObj, $dateOnly, 1, 'constrain');
        $afterNext = self::addDurationToZdt($startObj, $nextDate, 1, 'constrain');
        $dayLenNs = bcsub($afterNext, $afterDates, 0);
        $absDayLen = bccomp($dayLenNs, '0', 0) < 0 ? bcsub('0', $dayLenNs, 0) : $dayLenNs;
        if (bccomp($absDayLen, '0', 0) === 0) {
            return '0';
        }
        $absRem = $sign < 0 ? bcsub('0', $remainingNs, 0) : $remainingNs;
        // Fractional days: $absRem / $absDayLen, rounded by $mode.
        $cmp = bccomp($absRem, $absDayLen, 0);
        if ($cmp >= 0) {
            // Spec invariant: |remainingNs| < dayLength after the back-off
            // step. If we reach here, the back-off didn't fully resolve;
            // round to a whole day in the right direction.
            $whole = bcdiv($absRem, $absDayLen, 0);
            $remainder = bcmod($absRem, $absDayLen);
            if ($remainder !== '0') {
                if ($mode === 'expand' || ($mode === 'ceil' && $sign > 0) || ($mode === 'floor' && $sign < 0) || $mode === 'halfExpand') {
                    $whole = bcadd($whole, '1', 0);
                }
            }
            $resultDays = $whole;
        } else {
            // Standard case: 0 ≤ |rem| < dayLength.
            $half = bcdiv($absDayLen, '2', 0);
            $cmpHalf = bccomp($absRem, $half, 0);
            $up = false;
            switch ($mode) {
                case 'trunc':
                    $up = false;
                    break;
                case 'ceil':
                    $up = $sign > 0;
                    break;
                case 'floor':
                    $up = $sign < 0;
                    break;
                case 'expand':
                    $up = true;
                    break;
                case 'halfExpand':
                    $up = $cmpHalf >= 0;
                    break;
                case 'halfCeil':
                    $up = $cmpHalf > 0 || ($cmpHalf === 0 && $sign > 0);
                    break;
                case 'halfFloor':
                    $up = $cmpHalf > 0 || ($cmpHalf === 0 && $sign < 0);
                    break;
                case 'halfTrunc':
                    $up = $cmpHalf > 0;
                    break;
                case 'halfEven':
                    $up = $cmpHalf > 0;
                    break;
            }
            $resultDays = $up ? '1' : '0';
        }
        // Apply increment (only ever 1 for day-level here).
        if ($increment > 1) {
            $resultDays = bcmul($resultDays, (string) $increment, 0);
        }
        $resultNs = bcmul($resultDays, '86400000000000', 0);
        return $sign < 0 ? bcsub('0', $resultNs, 0) : $resultNs;
    }

    private static function roundNs(string $ns, string $incrementNs, string $mode): string
    {
        $sign = bccomp($ns, '0', 0) < 0 ? -1 : 1;
        $abs = $sign < 0 ? bcsub('0', $ns, 0) : $ns;
        $quotient = bcdiv($abs, $incrementNs, 0);
        $remainder = bcmod($abs, $incrementNs);
        if ($remainder === '0') {
            return $ns;
        }
        $rounded = $quotient;
        switch ($mode) {
            case 'trunc':
                // Already truncated.
                break;
            case 'ceil':
                if ($sign > 0) {
                    $rounded = bcadd($quotient, '1', 0);
                }
                break;
            case 'floor':
                if ($sign < 0) {
                    $rounded = bcadd($quotient, '1', 0);
                }
                break;
            case 'expand':
                $rounded = bcadd($quotient, '1', 0);
                break;
            case 'halfExpand':
            case 'halfCeil':
            case 'halfFloor':
            case 'halfTrunc':
            case 'halfEven':
                $half = bcdiv($incrementNs, '2', 0);
                $cmp = bccomp($remainder, $half, 0);
                $isExact = bccomp(bcmul($half, '2', 0), $incrementNs, 0) === 0;
                if ($cmp > 0 || ($cmp === 0 && !$isExact)) {
                    $rounded = bcadd($quotient, '1', 0);
                } elseif ($cmp === 0 && $isExact) {
                    // Exact tie-breaking per mode.
                    if ($mode === 'halfExpand') {
                        $rounded = bcadd($quotient, '1', 0);
                    } elseif ($mode === 'halfCeil') {
                        if ($sign > 0) {
                            $rounded = bcadd($quotient, '1', 0);
                        }
                    } elseif ($mode === 'halfFloor') {
                        if ($sign < 0) {
                            $rounded = bcadd($quotient, '1', 0);
                        }
                    } elseif ($mode === 'halfEven') {
                        if (bcmod($quotient, '2') !== '0') {
                            $rounded = bcadd($quotient, '1', 0);
                        }
                    }
                    // halfTrunc: stay at quotient.
                }
                break;
        }
        $result = bcmul($rounded, $incrementNs, 0);
        return $sign < 0 ? bcsub('0', $result, 0) : $result;
    }

    /**
     * Round Instant epoch-nanoseconds per the Temporal spec RoundTemporalInstant.
     */
    private static function roundInstantNs(string $ns, string $incrementNs, string $mode): string
    {
        $truncQ = bcdiv($ns, $incrementNs, 0);
        $truncR = bcsub($ns, bcmul($truncQ, $incrementNs, 0), 0);
        if (bccomp($truncR, '0', 0) < 0) {
            $floorQ = bcsub($truncQ, '1', 0);
            $floorR = bcadd($truncR, $incrementNs, 0);
        } else {
            $floorQ = $truncQ;
            $floorR = $truncR;
        }
        if ($floorR === '0') {
            return $ns;
        }
        $doubled = bcmul($floorR, '2', 0);
        $cmp = bccomp($doubled, $incrementNs, 0);
        $rounded = match ($mode) {
            'floor', 'trunc' => $floorQ,
            'ceil', 'expand' => bcadd($floorQ, '1', 0),
            'halfExpand' => $cmp >= 0 ? bcadd($floorQ, '1', 0) : $floorQ,
            'halfTrunc', 'halfFloor' => $cmp > 0 ? bcadd($floorQ, '1', 0) : $floorQ,
            'halfCeil' => $cmp >= 0 ? bcadd($floorQ, '1', 0) : $floorQ,
            'halfEven' => ($cmp > 0 || ($cmp === 0 && bcmod($floorQ, '2') !== '0'))
                ? bcadd($floorQ, '1', 0) : $floorQ,
            default => $floorQ,
        };
        return bcmul($rounded, $incrementNs, 0);
    }

    /**
     * Round an ISO date-time (wall-clock) to the given increment.
     * Rounding is applied to the time-of-day nanoseconds, then carried into the date if needed.
     *
     * @param array<string, int> $parts ISO date-time parts (year, month, day, hour, minute, second, millisecond, microsecond, nanosecond)
     * @param string $incrementNs The rounding increment in nanoseconds
     * @param string $mode The rounding mode
     * @param string $tz The time zone (unused but kept for signature consistency)
     * @return array<string, int> Rounded ISO date-time parts
     */
    private static function roundISODateTime(array $parts, string $incrementNs, string $mode, string $tz): array
    {
        // Convert time-of-day to nanoseconds from midnight.
        $timeNs = bcadd(
            bcadd(
                bcadd(
                    bcmul((string) $parts['hour'], '3600000000000', 0),
                    bcmul((string) $parts['minute'], '60000000000', 0),
                    0,
                ),
                bcadd(
                    bcmul((string) $parts['second'], '1000000000', 0),
                    bcmul((string) $parts['millisecond'], '1000000', 0),
                    0,
                ),
                0,
            ),
            bcadd(
                bcmul((string) $parts['microsecond'], '1000', 0),
                (string) $parts['nanosecond'],
                0,
            ),
            0,
        );
        // Round the time-of-day ns (always positive, 0..86399999999999).
        $rounded = self::roundNs($timeNs, $incrementNs, $mode);
        // Check for day overflow.
        $dayNs = '86400000000000';
        $dayCarry = 0;
        if (bccomp($rounded, $dayNs, 0) >= 0) {
            $dayCarry = 1;
            $rounded = bcsub($rounded, $dayNs, 0);
        } elseif (bccomp($rounded, '0', 0) < 0) {
            $dayCarry = -1;
            $rounded = bcadd($rounded, $dayNs, 0);
        }
        // Decompose rounded ns back to time parts.
        $h = (int) bcdiv($rounded, '3600000000000', 0);
        $rem = bcmod($rounded, '3600000000000');
        $mi = (int) bcdiv($rem, '60000000000', 0);
        $rem = bcmod($rem, '60000000000');
        $s = (int) bcdiv($rem, '1000000000', 0);
        $rem = bcmod($rem, '1000000000');
        $ms = (int) bcdiv($rem, '1000000', 0);
        $rem = bcmod($rem, '1000000');
        $us = (int) bcdiv($rem, '1000', 0);
        $nns = (int) bcmod($rem, '1000');

        $year = $parts['year'];
        $month = $parts['month'];
        $day = $parts['day'] + $dayCarry;
        // Handle day overflow into month/year.
        if ($dayCarry !== 0) {
            $dim = self::isoDaysInMonth($year, $month);
            if ($day > $dim) {
                $day = 1;
                $month++;
                if ($month > 12) {
                    $month = 1;
                    $year++;
                }
            } elseif ($day < 1) {
                $month--;
                if ($month < 1) {
                    $month = 12;
                    $year--;
                }
                $day = self::isoDaysInMonth($year, $month);
            }
        }

        return [
            'year' => $year, 'month' => $month, 'day' => $day,
            'hour' => $h, 'minute' => $mi, 'second' => $s,
            'millisecond' => $ms, 'microsecond' => $us, 'nanosecond' => $nns,
        ];
    }

    /**
     * Per spec AddDurationToYearMonth. Computes the effective sign of the
     * duration, picks the correct reference day (1 or end-of-month), then
     * adds the full duration via plainDateAdd.
     *
     * @return array{int, int} [year, month]
     */
    private static function addDurationToYearMonth(int $sign, int $y, int $m, string $cal, JsObject $dur, string $overflow): array
    {
        $dY = $sign * self::getDurationField($dur, 'years');
        $dMo = $sign * self::getDurationField($dur, 'months');
        $dW = $sign * self::getDurationField($dur, 'weeks');
        $dD = $sign * self::getDurationField($dur, 'days');
        $dH = $sign * self::getDurationField($dur, 'hours');
        $dMi = $sign * self::getDurationField($dur, 'minutes');
        $dS = $sign * self::getDurationField($dur, 'seconds');
        $dMs = $sign * self::getDurationField($dur, 'milliseconds');
        $dUs = $sign * self::getDurationField($dur, 'microseconds');
        $dNs = $sign * self::getDurationField($dur, 'nanoseconds');

        // Per spec step 4: compute the overall duration sign.
        $effectiveSign = 0;
        foreach ([$dY, $dMo, $dW, $dD, $dH, $dMi, $dS, $dMs, $dUs, $dNs] as $v) {
            if ($v > 0) {
                $effectiveSign = 1;
                break;
            }
            if ($v < 0) {
                $effectiveSign = -1;
                break;
            }
        }

        // Per spec step 7-9: create intermediate date with day=1, validate.
        self::validateISODate($y, $m, 1);

        if ($effectiveSign < 0) {
            // Per spec steps 10a-d: use end of month as reference day.
            // Compute nextMonth = (y, m+1), validate it, then end-of-month = days-in-month(y, m).
            $nextM = $m + 1;
            $nextY = $y;
            if ($nextM > 12) {
                $nextM = 1;
                $nextY++;
            }
            self::validateISODate($nextY, $nextM, 1);
            $refDay = self::isoDaysInMonth($y, $m);
        } else {
            $refDay = 1;
        }

        // Build the effective duration and add it via plainDateAdd.
        $fullDur = self::createDurationObject($dY, $dMo, $dW, $dD, $dH, $dMi, $dS, $dMs, $dUs, $dNs);
        $date = self::createPlainDateObject($y, $m, $refDay, $cal);
        $result = self::plainDateAdd($date, $fullDur, 1, $overflow);
        return [self::getSlotInt($result, '[[ISOYear]]'), self::getSlotInt($result, '[[ISOMonth]]')];
    }

    private static function plainDateAdd(JsValue $date, JsObject $dur, int $sign, string $overflow = 'constrain'): JsObject
    {
        $y = self::getSlotInt($date, '[[ISOYear]]');
        $m = self::getSlotInt($date, '[[ISOMonth]]');
        $d = self::getSlotInt($date, '[[ISODay]]');
        $cal = self::getSlotString($date, '[[Calendar]]');

        $years = $sign * self::getDurationField($dur, 'years');
        $months = $sign * self::getDurationField($dur, 'months');
        $weeks = $sign * self::getDurationField($dur, 'weeks');
        $days = $sign * self::getDurationField($dur, 'days');
        // Per spec: balance time components into days for PlainDate.
        $hours = $sign * self::getDurationField($dur, 'hours');
        $minutes = $sign * self::getDurationField($dur, 'minutes');
        $seconds = $sign * self::getDurationField($dur, 'seconds');
        $ms = $sign * self::getDurationField($dur, 'milliseconds');
        $us = $sign * self::getDurationField($dur, 'microseconds');
        $ns = $sign * self::getDurationField($dur, 'nanoseconds');
        // Total nanoseconds from time units, convert to extra days.
        $totalTimeNs = bcadd(
            bcadd(
                bcadd(
                    bcmul((string) $hours, '3600000000000', 0),
                    bcmul((string) $minutes, '60000000000', 0),
                    0,
                ),
                bcmul((string) $seconds, '1000000000', 0),
                0,
            ),
            bcadd(
                bcadd(bcmul((string) $ms, '1000000', 0), bcmul((string) $us, '1000', 0), 0),
                (string) $ns,
                0,
            ),
            0,
        );
        $extraDays = (int) bcdiv($totalTimeNs, '86400000000000', 0);
        $days += $extraDays;

        // For non-iso/gregory-like calendars, route year/month addition
        // through the calendar-aware adder so the calendar's actual
        // month/year boundaries (e.g. coptic's 13-month year, hebrew's
        // 12 vs 13, chinese sexagenary leap months) are honoured. For
        // ISO/gregory/roc/japanese, fall through to ISO arithmetic.
        // Hebrew and Ethiopic have pure-PHP paths that don't need intl;
        // every other calendar still requires the intl extension.
        $isPureCal = $cal === 'hebrew'
            || $cal === 'ethiopic'
            || $cal === 'ethioaa'
            || $cal === 'ethiopic-amete-alem';
        $useCalendarMath = $cal !== 'iso8601'
            && !in_array($cal, ['gregory', 'roc', 'japanese'], true)
            && ($isPureCal || extension_loaded('intl'))
            && ($years !== 0 || $months !== 0);
        if ($useCalendarMath) {
            $isoAfter = self::calendarAddYearsMonthsIso($cal, $y, $m, $d, $years, $months, $overflow);
            if ($isoAfter !== null) {
                [$y, $m, $d] = $isoAfter;
                $years = 0;
                $months = 0;
            }
        }

        // Add years and months first (ISO path).
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

        // Clamp or reject day based on overflow.
        $dim = self::isoDaysInMonth($y, $m);
        if ($d > $dim) {
            if ($overflow === 'reject') {
                throw new RangeError("Day {$d} out of range for month");
            }
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

    /**
     * Add `years` years and `months` months in the given calendar's
     * native month/year space, then return the resulting [iso year,
     * iso month, iso day]. Day clamps to the resulting month's max
     * unless `overflow === 'reject'`. Returns null when ICU can't
     * model the calendar.
     *
     * @return array{0:int,1:int,2:int}|null
     */
    private static function calendarAddYearsMonthsIso(
        string $calendar,
        int $isoY,
        int $isoM,
        int $isoD,
        int $years,
        int $months,
        string $overflow,
    ): ?array {
        // Ethiopic / ethioaa: deterministic 13-month add (no ICU).
        if ($calendar === 'ethiopic' || $calendar === 'ethioaa' || $calendar === 'ethiopic-amete-alem') {
            return self::ethiopicAddYearsMonthsIso(
                $isoY,
                $isoM,
                $isoD,
                $years,
                $months,
                $overflow,
            );
        }
        // Hebrew: deterministic via pure-PHP isoToHebrewDate / hebrewToIsoDate.
        if ($calendar === 'hebrew') {
            return self::hebrewAddYearsMonthsIso(
                $isoY,
                $isoM,
                $isoD,
                $years,
                $months,
                $overflow,
            );
        }
        // Chinese / dangi: deterministic via pure-PHP table.
        if ($calendar === 'chinese' || $calendar === 'dangi') {
            self::$chineseDispatchCalendar = $calendar;
            $r = self::chineseAddYearsMonthsIso(
                $isoY,
                $isoM,
                $isoD,
                $years,
                $months,
                $overflow,
            );
            if ($r !== null) {
                return $r;
            }
            // Out of table range: fall through to ICU.
        }
        if (!class_exists('IntlCalendar', false)) {
            return null;
        }
        static $aliasMap = [
            'gregory' => 'gregorian',
            'islamicc' => 'islamic-civil',
            'ethioaa' => 'ethiopic-amete-alem',
        ];
        $icuName = $aliasMap[$calendar] ?? $calendar;
        try {
            $cal = \IntlCalendar::createInstance(
                'UTC',
                "en@calendar={$icuName}",
            );
            $epochMs = self::isoDateToEpochMs($isoY, $isoM, $isoD);
            $cal->setTime($epochMs);
            $startDay = $cal->get(\IntlCalendar::FIELD_DAY_OF_MONTH);
            if ($years !== 0) {
                $cal->add(\IntlCalendar::FIELD_YEAR, $years);
            }
            if ($months !== 0) {
                $cal->add(\IntlCalendar::FIELD_MONTH, $months);
            }
            // ICU's add() already constrains to the month max if the
            // start day exceeds it, but that's the implicit "constrain"
            // mode we want anyway. For "reject", check the final day.
            $finalDay = $cal->get(\IntlCalendar::FIELD_DAY_OF_MONTH);
            if ($overflow === 'reject' && $finalDay !== $startDay) {
                throw new RangeError("Day {$startDay} out of range after calendar arithmetic");
            }
            $resultMs = $cal->getTime();
            $secs = (int) ($resultMs / 1000.0);
            $dt = (new \DateTimeImmutable('@' . $secs))->setTimezone(new \DateTimeZone('UTC'));
            return [
                (int) $dt->format('Y'),
                (int) $dt->format('n'),
                (int) $dt->format('j'),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function plainYearMonthDifference(JsValue $ym1, JsValue $ym2, JsValue $options): JsObject
    {
        $cal1 = self::getSlotString($ym1, '[[Calendar]]');
        $cal2 = self::getSlotString($ym2, '[[Calendar]]');
        if ($cal1 !== $cal2) {
            throw new RangeError(
                "calendar IDs do not match: {$cal1} vs {$cal2}",
            );
        }
        $opts = self::getOptionsObject($options);
        $y1 = self::getSlotInt($ym1, '[[ISOYear]]');
        $m1 = self::getSlotInt($ym1, '[[ISOMonth]]');
        $y2 = self::getSlotInt($ym2, '[[ISOYear]]');
        $m2 = self::getSlotInt($ym2, '[[ISOMonth]]');
        $largestUnit = 'year';
        $riFinal = 1;
        $rmFinal = 'trunc';
        $suFinal = 'month';
        if ($opts instanceof JsObject) {
            $lu = $opts->get('largestUnit');
            if (!($lu instanceof JsUndefined)) {
                $largestUnit = TypeConversion::toString($lu);
                if ($largestUnit === 'auto') {
                    $largestUnit = 'year';
                } else {
                    $largestUnit = self::canonicalTemporalUnit($largestUnit);
                }
                if (!in_array($largestUnit, ['year', 'month'], true)) {
                    throw new RangeError("Invalid largest unit for PlainYearMonth: {$largestUnit}");
                }
            }
            $ri = $opts->get('roundingIncrement');
            if (!($ri instanceof JsUndefined)) {
                $riNum = TypeConversion::toNumber($ri);
                if (!is_finite($riNum)) {
                    throw new RangeError('roundingIncrement must be finite');
                }
                $riFinal = (int) $riNum;
                if ($riFinal < 1 || $riFinal > 1_000_000_000) {
                    throw new RangeError('roundingIncrement out of range');
                }
            }
            $rm = $opts->get('roundingMode');
            if (!($rm instanceof JsUndefined)) {
                $rmFinal = TypeConversion::toString($rm);
                $validRM = [
                    'ceil', 'floor', 'expand', 'trunc',
                    'halfCeil', 'halfFloor', 'halfExpand', 'halfTrunc', 'halfEven',
                ];
                if (!in_array($rmFinal, $validRM, true)) {
                    throw new RangeError("Invalid roundingMode: {$rmFinal}");
                }
            }
            $su = $opts->get('smallestUnit');
            if (!($su instanceof JsUndefined)) {
                $suStr = TypeConversion::toString($su);
                $suFinal = self::canonicalTemporalUnit($suStr);
                if (!in_array($suFinal, ['year', 'month'], true)) {
                    throw new RangeError("Invalid smallest unit for PlainYearMonth: {$suFinal}");
                }
            }
            $allU = ['year', 'month'];
            $liIdx = array_search($largestUnit, $allU);
            $siIdx = array_search($suFinal, $allU);
            if ($liIdx !== false && $siIdx !== false && $liIdx > $siIdx) {
                throw new RangeError('largestUnit must be >= smallestUnit');
            }
        }
        if ($y1 !== $y2 || $m1 !== $m2) {
            self::validateISODate($y1, $m1, 1);
            self::validateISODate($y2, $m2, 1);
        }
        $totalMonths = ($y2 * 12 + $m2) - ($y1 * 12 + $m1);
        if ($largestUnit === 'year') {
            $years = intdiv($totalMonths, 12);
            $months = $totalMonths - $years * 12;
        } else {
            $years = 0;
            $months = $totalMonths;
        }
        if ($suFinal === 'year' && $months !== 0) {
            $sign = $totalMonths >= 0 ? 1 : -1;
            $absYears = abs($years);
            $absMonths = abs($months);
            $frac = $absMonths / 12.0;
            $totalYearsFloat = $absYears + $frac;
            $absRm = $rmFinal;
            if ($sign < 0) {
                $absRm = match ($rmFinal) {
                    'ceil' => 'floor', 'floor' => 'ceil',
                    'halfCeil' => 'halfFloor', 'halfFloor' => 'halfCeil',
                    default => $rmFinal,
                };
            }
            $rounded = self::roundToIncrement(
                (int) round($totalYearsFloat * 1000000),
                $riFinal * 1000000,
                $absRm,
            );
            $years = $sign * intdiv($rounded, 1000000);
            $months = 0;
        } elseif ($suFinal === 'month' && $riFinal > 1) {
            $sign = $totalMonths >= 0 ? 1 : -1;
            $absRm = $rmFinal;
            if ($sign < 0) {
                $absRm = match ($rmFinal) {
                    'ceil' => 'floor', 'floor' => 'ceil',
                    'halfCeil' => 'halfFloor', 'halfFloor' => 'halfCeil',
                    default => $rmFinal,
                };
            }
            if ($largestUnit === 'year') {
                $absMonths = abs($months);
                $roundedMonths = self::roundToIncrement($absMonths, $riFinal, $absRm);
                if ($roundedMonths >= 12) {
                    $years += $sign * intdiv($roundedMonths, 12);
                    $roundedMonths = $roundedMonths % 12;
                }
                $months = $sign * $roundedMonths;
            } else {
                $rounded = self::roundToIncrement(abs($totalMonths), $riFinal, $absRm);
                $years = 0;
                $months = $sign * $rounded;
            }
        }
        if ($riFinal > 1 || $suFinal === 'year') {
            $cY = $y1 + $years;
            $cM = $m1 + $months;
            while ($cM > 12) {
                $cM -= 12;
                $cY++;
            }
            while ($cM < 1) {
                $cM += 12;
                $cY--;
            }
            if (
                $cY < self::ISO_YEAR_MIN || $cY > self::ISO_YEAR_MAX
                || ($cY === self::ISO_YEAR_MIN && $cM < 4)
                || ($cY === self::ISO_YEAR_MAX && $cM > 9)
            ) {
                throw new RangeError('rounded date is outside the representable range');
            }
            $sign = $totalMonths >= 0 ? 1 : -1;
            $endMonths = ($suFinal === 'year')
                ? $sign * ((abs($years) + $riFinal) * 12)
                : $sign * (abs($totalMonths) + $riFinal);
            $eY = $y1;
            $eM = $m1 + $endMonths;
            while ($eM > 12) {
                $eM -= 12;
                $eY++;
            }
            while ($eM < 1) {
                $eM += 12;
                $eY--;
            }
            if (
                $eY < self::ISO_YEAR_MIN || $eY > self::ISO_YEAR_MAX
                || ($eY === self::ISO_YEAR_MIN && $eM < 4)
                || ($eY === self::ISO_YEAR_MAX && $eM > 9)
            ) {
                throw new RangeError('rounded date is outside the representable range');
            }
        }
        return self::createDurationObject($years, $months, 0, 0, 0, 0, 0, 0, 0, 0);
    }

    private static function plainDateDifference(JsValue $date1, JsValue $date2, JsValue $options, int $sign): JsObject
    {
        // Spec: calendar IDs of both operands must match for since/until.
        $cal1 = self::getSlotString($date1, '[[Calendar]]');
        $cal2 = self::getSlotString($date2, '[[Calendar]]');
        if ($cal1 !== $cal2) {
            throw new RangeError(
                "calendar IDs do not match: {$cal1} vs {$cal2}",
            );
        }
        $opts = self::getOptionsObject($options);
        $y1 = self::getSlotInt($date1, '[[ISOYear]]');
        $m1 = self::getSlotInt($date1, '[[ISOMonth]]');
        $d1 = self::getSlotInt($date1, '[[ISODay]]');
        $y2 = self::getSlotInt($date2, '[[ISOYear]]');
        $m2 = self::getSlotInt($date2, '[[ISOMonth]]');
        $d2 = self::getSlotInt($date2, '[[ISODay]]');

        $largestUnit = 'day';
        $largestUnitExplicit = false;
        if ($opts instanceof JsObject) {
            $lu = $opts->get('largestUnit');
            if (!($lu instanceof JsUndefined)) {
                $largestUnitExplicit = true;
                $largestUnit = TypeConversion::toString($lu);
                if ($largestUnit === 'auto') {
                    $largestUnit = 'day';
                    $largestUnitExplicit = false;
                } else {
                    $largestUnit = self::canonicalTemporalUnit($largestUnit);
                    $dateUnitsLU = ['year', 'month', 'week', 'day'];
                    if (!in_array($largestUnit, $dateUnitsLU, true)) {
                        throw new RangeError("Invalid largest unit for date: {$largestUnit}");
                    }
                }
            }
            // Read options in ALPHABETICAL order per spec.
            $ri = $opts->get('roundingIncrement');
            if (!($ri instanceof JsUndefined)) {
                $riNum = TypeConversion::toNumber($ri);
                if (!is_finite($riNum)) {
                    throw new RangeError("Invalid roundingIncrement");
                }
                // Truncate to integer per spec (ToTemporalRoundingIncrement step 3).
                $riNum = (int) $riNum;
                // Step 4: must be in [1, 1e9].
                if ($riNum < 1 || $riNum > 1_000_000_000) {
                    throw new RangeError("Invalid roundingIncrement");
                }
            }
            $rm = $opts->get('roundingMode');
            if (!($rm instanceof JsUndefined)) {
                $rmStr = TypeConversion::toString($rm);
                $validRM = ['ceil', 'floor', 'expand', 'trunc', 'halfCeil', 'halfFloor', 'halfExpand', 'halfTrunc', 'halfEven'];
                if (!in_array($rmStr, $validRM, true)) {
                    throw new RangeError("Invalid roundingMode: {$rmStr}");
                }
            }
            $su = $opts->get('smallestUnit');
            if (!($su instanceof JsUndefined)) {
                $smallestUnit = TypeConversion::toString($su);
                $smallestUnit = self::canonicalTemporalUnit($smallestUnit);
                $dateUnits = ['year', 'month', 'week', 'day'];
                if (!in_array($smallestUnit, $dateUnits, true)) {
                    throw new RangeError("Invalid smallest unit for date: {$smallestUnit}");
                }
            }
            // Default largestUnit to smallestUnit if needed.
            if (isset($smallestUnit)) {
                $allU = ['year', 'month', 'week', 'day', 'hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];
                $liIdx = array_search($largestUnit, $allU);
                $siIdx = array_search($smallestUnit, $allU);
                if (!$largestUnitExplicit && $siIdx !== false && $liIdx !== false && $siIdx < $liIdx) {
                    $largestUnit = $smallestUnit;
                    $liIdx = $siIdx;
                }
                if ($liIdx !== false && $siIdx !== false && $liIdx > $siIdx) {
                    throw new RangeError('largestUnit must be >= smallestUnit');
                }
            }
        }

        // DifferenceISODate per spec: date1 is the anchor.
        // until(this, other): sign=1, anchor=date1 (the earlier date)
        // since(this, other): sign=-1, anchor=date1 (the later date)
        $cmp = ($y2 <=> $y1) ?: ($m2 <=> $m1) ?: ($d2 <=> $d1);
        if ($cmp === 0) {
            return self::createDurationObject(0, 0, 0, 0, 0, 0, 0, 0, 0, 0);
        }
        $natSign = $cmp > 0 ? 1 : -1;
        $anchorDay = $d1;
        if ($natSign < 0) {
            [$smlY, $smlM, $smlD, $lrgY, $lrgM, $lrgD] = [$y2, $m2, $d2, $y1, $m1, $d1];
        } else {
            [$smlY, $smlM, $smlD, $lrgY, $lrgM, $lrgD] = [$y1, $m1, $d1, $y2, $m2, $d2];
        }
        $years = 0;
        $months = 0;
        $weeks = 0;
        $days = 0;
        $skipIsoYearMonth = false;
        if (
            ($largestUnit === 'year' || $largestUnit === 'month')
            && $natSign > 0
            && !in_array($cal1, ['iso8601', 'gregory', 'roc', 'japanese'], true)
        ) {
            $calRes = self::calendarYearsMonthsDaysBetween(
                $cal1,
                $smlY,
                $smlM,
                $smlD,
                $lrgY,
                $lrgM,
                $lrgD,
                $largestUnit,
            );
            if ($calRes !== null) {
                [$years, $months, $days] = $calRes;
                $skipIsoYearMonth = true;
            }
        }
        if ($skipIsoYearMonth) {
            // years/months/days already set by calendar-aware helper.
        } elseif ($largestUnit === 'year' || $largestUnit === 'month') {
            if ($natSign > 0) {
                // Forward: anchor = date1 (smaller). Add months, clamp to anchor day.
                $totalMonths = ($lrgY * 12 + $lrgM) - ($smlY * 12 + $smlM);
                // If target day < anchor day, the last month step is incomplete.
                if ($lrgD < $anchorDay) {
                    $totalMonths--;
                }
                [$midMY, $midMM, $midD] = self::computeMonthMidpoint(1, $smlY, $smlM, $lrgY, $lrgM, $anchorDay, $totalMonths);
                $days = self::isoToJulianDay($lrgY, $lrgM, $lrgD) - self::isoToJulianDay($midMY, $midMM, $midD);
                if ($days < 0) {
                    $totalMonths--;
                    [$midMY, $midMM, $midD] = self::computeMonthMidpoint(1, $smlY, $smlM, $lrgY, $lrgM, $anchorDay, $totalMonths);
                    $days = self::isoToJulianDay($lrgY, $lrgM, $lrgD) - self::isoToJulianDay($midMY, $midMM, $midD);
                }
            } else {
                // Backward: anchor = date1 (larger). Subtract months, clamp to anchor day.
                $totalMonths = ($lrgY * 12 + $lrgM) - ($smlY * 12 + $smlM);
                $mt = $lrgY * 12 + ($lrgM - 1) - $totalMonths;
                $midMY = intdiv($mt, 12);
                $midMM = ($mt % 12) + 1;
                if ($midMM < 1) {
                    $midMM += 12;
                    $midMY--;
                }
                $midD = min($anchorDay, self::isoDaysInMonth($midMY, $midMM));
                $days = self::isoToJulianDay($midMY, $midMM, $midD) - self::isoToJulianDay($smlY, $smlM, $smlD);
                if ($days < 0) {
                    $totalMonths--;
                    $mt = $lrgY * 12 + ($lrgM - 1) - $totalMonths;
                    $midMY = intdiv($mt, 12);
                    $midMM = ($mt % 12) + 1;
                    if ($midMM < 1) {
                        $midMM += 12;
                        $midMY--;
                    }
                    $midD = min($anchorDay, self::isoDaysInMonth($midMY, $midMM));
                    $days = self::isoToJulianDay($midMY, $midMM, $midD) - self::isoToJulianDay($smlY, $smlM, $smlD);
                }
            }
            if ($largestUnit === 'year') {
                $years = intdiv($totalMonths, 12);
                $months = $totalMonths - $years * 12;
            } else {
                $months = $totalMonths;
            }
        } elseif ($largestUnit === 'week') {
            $jd1 = self::isoToJulianDay($smlY, $smlM, $smlD);
            $jd2 = self::isoToJulianDay($lrgY, $lrgM, $lrgD);
            $totalDays = $jd2 - $jd1;
            $weeks = intdiv($totalDays, 7);
            $days = $totalDays - $weeks * 7;
        } else {
            $jd1 = self::isoToJulianDay($smlY, $smlM, $smlD);
            $jd2 = self::isoToJulianDay($lrgY, $lrgM, $lrgD);
            $days = $jd2 - $jd1;
        }
        $roundedSU = $smallestUnit ?? 'day';
        $roundMode = $rmStr ?? 'trunc';
        $roundInc = isset($riNum) ? (int) $riNum : 1;
        $effectiveSign = $sign * $natSign;
        if ($effectiveSign < 0) {
            $roundMode = match ($roundMode) {
                'ceil' => 'floor',
                'floor' => 'ceil',
                'halfCeil' => 'halfFloor',
                'halfFloor' => 'halfCeil',
                default => $roundMode,
            };
        }
        if ($roundedSU === 'year' && ($months !== 0 || $days !== 0)) {
            $totalMonths = $months;
            if ($natSign > 0) {
                $midTotalM = $smlY * 12 + ($smlM - 1) + ($years * 12) + $months;
            } else {
                $midTotalM = $lrgY * 12 + ($lrgM - 1) - ($years * 12) - $months;
                if (($midTotalM % 12) + 1 < 1) {
                    $midTotalM += 12;
                }
            }
            $midMY = intdiv($midTotalM, 12);
            $midMM = ($midTotalM % 12) + 1;
            $nextMonthDays = self::isoDaysInMonth($midMY, $midMM);
            $frac = ($totalMonths + ($nextMonthDays > 0 ? $days / $nextMonthDays : 0)) / 12.0;
            $totalYearsFloat = $years + $frac;
            $roundedYears = self::roundToIncrement(
                (int) round($totalYearsFloat * 1000000),
                $roundInc * 1000000,
                $roundMode,
            );
            $years = intdiv($roundedYears, 1000000);
            $months = 0;
            $weeks = 0;
            $days = 0;
        } elseif ($roundedSU === 'month' && $days !== 0) {
            // For the fractional month: find the length of the month step that contains the remainder days.
            // Forward: midpoint is at sml + years*12 + months, next month step goes forward.
            // Backward: midpoint is at lrg - years*12 - months, next month step goes backward.
            if ($natSign > 0) {
                $midTotalM = $smlY * 12 + ($smlM - 1) + ($years * 12) + $months;
                $midMY = intdiv($midTotalM, 12);
                $midMM = ($midTotalM % 12) + 1;
            } else {
                // Go backward from anchor (lrg). The midpoint is lrg - totalMonths months.
                $midTotalM = $lrgY * 12 + ($lrgM - 1) - ($years * 12) - $months;
                $midMY = intdiv($midTotalM, 12);
                $midMM = ($midTotalM % 12) + 1;
                if ($midMM < 1) {
                    $midMM += 12;
                    $midMY--;
                }
                // The "next step" for backward is one more month back.
                $prevM = $midMM - 1;
                $prevY = $midMY;
                if ($prevM < 1) {
                    $prevM = 12;
                    $prevY--;
                }
                $midMY = $prevY;
                $midMM = $prevM;
            }
            $daysInMonth = self::isoDaysInMonth($midMY, $midMM);
            $frac = $daysInMonth > 0 ? $days / $daysInMonth : 0;
            $totalMonthsFloat = ($years * 12 + $months) + $frac;
            $roundedMonths = self::roundToIncrement(
                (int) round($totalMonthsFloat * 1000000),
                $roundInc * 1000000,
                $roundMode,
            );
            $totalMonthsRounded = intdiv($roundedMonths, 1000000);
            $years = intdiv($totalMonthsRounded, 12);
            $months = $totalMonthsRounded - $years * 12;
            if ($largestUnit === 'month') {
                $months = $totalMonthsRounded;
                $years = 0;
            }
            $weeks = 0;
            $days = 0;
        } elseif ($roundedSU === 'week') {
            $totalDays = $weeks * 7 + $days;
            $roundedDays = self::roundToIncrement($totalDays, $roundInc * 7, $roundMode);
            $weeks = intdiv($roundedDays, 7);
            $days = 0;
        } elseif ($roundedSU === 'day' && $roundInc > 1) {
            $totalDays = $weeks * 7 + $days;
            $roundedDays = self::roundToIncrement($totalDays, $roundInc, $roundMode);
            if ($largestUnit === 'week') {
                $weeks = intdiv($roundedDays, 7);
                $days = $roundedDays - $weeks * 7;
            } else {
                $days = $roundedDays;
            }
        }
        // Validate: per NudgeToCalendarUnit, both the floor and ceiling (floor + increment)
        // must produce valid ISO dates when added to the reference date.
        if (in_array($roundedSU, ['year', 'month', 'week'], true) && $roundInc > 1) {
            // Compute the ceiling value (one increment beyond the rounded result).
            $ceilYears = $years;
            $ceilMonths = $months;
            $ceilWeeks = $weeks;
            $ceilDays = $days;
            if ($roundedSU === 'year') {
                $ceilYears += $roundInc;
            } elseif ($roundedSU === 'month') {
                $ceilMonths += $roundInc;
            } elseif ($roundedSU === 'week') {
                $ceilWeeks += $roundInc;
            }
            try {
                $refDate = self::createPlainDateObject($y1, $m1, $d1, 'iso8601');
                $addSign = $natSign > 0 ? 1 : -1;
                $ceilDur = self::createDurationObject($ceilYears, $ceilMonths, $ceilWeeks, $ceilDays, 0, 0, 0, 0, 0, 0);
                self::plainDateAdd($refDate, $ceilDur, $addSign);
            } catch (\Throwable) {
                throw new RangeError('Rounded date outside valid ISO date range');
            }
        }
        return self::createDurationObject(
            $effectiveSign * $years,
            $effectiveSign * $months,
            $effectiveSign * $weeks,
            $effectiveSign * $days,
            0,
            0,
            0,
            0,
            0,
            0,
        );
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

    /** @var int $overflowDays Set by plainTimeAdd for PlainDateTime to use. */
    private static int $lastTimeAddOverflowDays = 0;

    private static function plainTimeAdd(JsValue $time, JsObject $dur, int $sign): JsObject
    {
        $h = self::getSlotInt($time, '[[ISOHour]]');
        $min = self::getSlotInt($time, '[[ISOMinute]]');
        $s = self::getSlotInt($time, '[[ISOSecond]]');
        $ms = self::getSlotInt($time, '[[ISOMillisecond]]');
        $us = self::getSlotInt($time, '[[ISOMicrosecond]]');
        $ns = self::getSlotInt($time, '[[ISONanosecond]]');

        // Use bcmath for large values.
        $totalNs = bcadd(
            bcadd(bcmul((string) ($h * 3600 + $min * 60 + $s), '1000000000', 0), (string) ($ms * 1000000 + $us * 1000 + $ns), 0),
            '0',
            0,
        );
        $durH = (string) ($sign * self::getDurationField($dur, 'hours'));
        $durMin = (string) ($sign * self::getDurationField($dur, 'minutes'));
        $durS = (string) ($sign * self::getDurationField($dur, 'seconds'));
        $durMs = (string) ($sign * self::getDurationField($dur, 'milliseconds'));
        $durUs = (string) ($sign * self::getDurationField($dur, 'microseconds'));
        $durNsV = (string) ($sign * self::getDurationField($dur, 'nanoseconds'));
        $durNs = bcadd(
            bcadd(bcmul($durH, '3600000000000', 0), bcmul($durMin, '60000000000', 0), 0),
            bcadd(bcmul($durS, '1000000000', 0), bcadd(bcmul($durMs, '1000000', 0), bcadd(bcmul($durUs, '1000', 0), $durNsV, 0), 0), 0),
            0,
        );

        $result = bcadd($totalNs, $durNs, 0);
        $dayNs = '86400000000000';
        // Calculate overflow days.
        if (bccomp($result, '0', 0) < 0) {
            $overflowDays = (int) bcsub(bcdiv($result, $dayNs, 0), '1', 0);
            $result = bcsub($result, bcmul((string) $overflowDays, $dayNs, 0), 0);
        } else {
            $overflowDays = (int) bcdiv($result, $dayNs, 0);
            $result = bcmod($result, $dayNs);
        }
        self::$lastTimeAddOverflowDays = $overflowDays;

        $resultInt = (int) (string) $result;
        if ($resultInt < 0) {
            $resultInt += 86400000000000;
        }
        $ns2 = $resultInt % 1000;
        $resultInt = intdiv($resultInt, 1000);
        $us2 = $resultInt % 1000;
        $resultInt = intdiv($resultInt, 1000);
        $ms2 = $resultInt % 1000;
        $resultInt = intdiv($resultInt, 1000);
        $s2 = $resultInt % 60;
        $resultInt = intdiv($resultInt, 60);
        $min2 = $resultInt % 60;
        $h2 = intdiv($resultInt, 60);

        return self::createPlainTimeObject($h2, $min2, $s2, $ms2, $us2, $ns2);
    }

    private static function plainTimeDifference(JsValue $time1, JsValue $time2, JsValue $options): JsObject
    {
        $opts = self::getOptionsObject($options);
        $ns1 = self::timeToNs($time1);
        $ns2 = self::timeToNs($time2);
        $diffNs = (string) ($ns2 - $ns1);
        $largestUnit = 'hour';
        $largestUnitExplicit = false;
        $validTimeUnits = ['hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];
        if ($opts instanceof JsObject) {
            $lu = $opts->get('largestUnit');
            if (!($lu instanceof JsUndefined)) {
                $largestUnitExplicit = true;
                $largestUnit = TypeConversion::toString($lu);
                if ($largestUnit === 'auto') {
                    $largestUnit = 'hour';
                    $largestUnitExplicit = false;
                } else {
                    $largestUnit = self::canonicalTemporalUnit($largestUnit);
                    if (!in_array($largestUnit, $validTimeUnits, true)) {
                        throw new RangeError("Invalid largest unit for time: {$largestUnit}");
                    }
                }
            }
            // Alphabetical order: roundingIncrement, roundingMode, smallestUnit.
            $ri = $opts->get('roundingIncrement');
            if (!($ri instanceof JsUndefined)) {
                $riNum = TypeConversion::toNumber($ri);
                if (!is_finite($riNum)) {
                    throw new RangeError("Invalid roundingIncrement");
                }
                $riNum = (int) $riNum;
                if ($riNum < 1 || $riNum > 1_000_000_000) {
                    throw new RangeError("Invalid roundingIncrement");
                }
            }
            $rm = $opts->get('roundingMode');
            if (!($rm instanceof JsUndefined)) {
                $rmStr = TypeConversion::toString($rm);
                $validRM = ['ceil', 'floor', 'expand', 'trunc', 'halfCeil', 'halfFloor', 'halfExpand', 'halfTrunc', 'halfEven'];
                if (!in_array($rmStr, $validRM, true)) {
                    throw new RangeError("Invalid roundingMode: {$rmStr}");
                }
            }
            $su = $opts->get('smallestUnit');
            if (!($su instanceof JsUndefined)) {
                $suStr = TypeConversion::toString($su);
                $suCanon = self::canonicalTemporalUnit($suStr);
                if (!in_array($suCanon, $validTimeUnits, true)) {
                    throw new RangeError("Invalid smallest unit for time: {$suStr}");
                }
            }
            // Validate roundingIncrement divides evenly.
            if (isset($riNum) && $riNum > 1) {
                self::validateRoundingIncrement($suCanon ?? 'nanosecond', $riNum);
            }
            // Default largestUnit to smallestUnit if needed.
            if (isset($suCanon)) {
                $luIdx = array_search($largestUnit, $validTimeUnits);
                $suIdx = array_search($suCanon, $validTimeUnits);
                if (!$largestUnitExplicit && $suIdx < $luIdx) {
                    $largestUnit = $suCanon;
                    $luIdx = $suIdx;
                }
                if ($luIdx !== false && $suIdx !== false && $luIdx > $suIdx) {
                    throw new RangeError('largestUnit must be >= smallestUnit');
                }
            }
        }
        // Apply rounding.
        $roundIncrement = isset($riNum) ? (int) $riNum : 1;
        $roundMode = $rmStr ?? 'trunc';
        $suFinal = $suCanon ?? 'nanosecond';
        if ($suFinal !== 'nanosecond' || $roundIncrement !== 1) {
            $unitNsMap = [
                'hour' => '3600000000000',
                'minute' => '60000000000',
                'second' => '1000000000',
                'millisecond' => '1000000',
                'microsecond' => '1000',
                'nanosecond' => '1',
            ];
            $unitNs = $unitNsMap[$suFinal];
            $incrementNs = bcmul((string) $roundIncrement, $unitNs, 0);
            $diffNs = self::roundNs($diffNs, $incrementNs, $roundMode);
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

    /** Round a PlainDateTime's time component by increment in ns, returning a new PlainDateTime. */
    private static function roundPlainDateTime(JsValue $dt, int $incrementNs, string $roundingMode): JsObject
    {
        $timeNs = (self::getSlotInt($dt, '[[ISOHour]]') * 3600
            + self::getSlotInt($dt, '[[ISOMinute]]') * 60
            + self::getSlotInt($dt, '[[ISOSecond]]')) * 1000000000
            + self::getSlotInt($dt, '[[ISOMillisecond]]') * 1000000
            + self::getSlotInt($dt, '[[ISOMicrosecond]]') * 1000
            + self::getSlotInt($dt, '[[ISONanosecond]]');
        $rounded = self::roundToIncrement($timeNs, $incrementNs, $roundingMode);
        $dayNs = 86400000000000;
        $extraDays = intdiv($rounded, $dayNs);
        $rounded = $rounded % $dayNs;
        if ($rounded < 0) {
            $rounded += $dayNs;
            $extraDays--;
        }
        $h = intdiv($rounded, 3600000000000);
        $rounded %= 3600000000000;
        $min = intdiv($rounded, 60000000000);
        $rounded %= 60000000000;
        $s = intdiv($rounded, 1000000000);
        $rounded %= 1000000000;
        $ms = intdiv($rounded, 1000000);
        $rounded %= 1000000;
        $us = intdiv($rounded, 1000);
        $ns = $rounded % 1000;
        $y = self::getSlotInt($dt, '[[ISOYear]]');
        $m = self::getSlotInt($dt, '[[ISOMonth]]');
        $dd = self::getSlotInt($dt, '[[ISODay]]');
        $cal = self::getSlotString($dt, '[[Calendar]]');
        if ($extraDays !== 0) {
            $dateObj = self::createPlainDateObject($y, $m, $dd, $cal);
            $durObj = self::createDurationObject(0, 0, 0, $extraDays, 0, 0, 0, 0, 0, 0);
            $newDate = self::plainDateAdd($dateObj, $durObj, 1);
            $y = self::getSlotInt($newDate, '[[ISOYear]]');
            $m = self::getSlotInt($newDate, '[[ISOMonth]]');
            $dd = self::getSlotInt($newDate, '[[ISODay]]');
        }
        return self::createPlainDateTimeObject($y, $m, $dd, $h, $min, $s, $ms, $us, $ns, $cal);
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

    private static function plainDateTimeAdd(JsValue $dt, JsObject $dur, int $sign, string $overflow = 'constrain'): JsObject
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
        $newDate = self::plainDateAdd($dateObj, $dateDur, $sign, $overflow);

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
        // Add overflow days from time addition to the date.
        $overflowDays = self::$lastTimeAddOverflowDays;
        if ($overflowDays !== 0) {
            $extraDur = self::createDurationObject(0, 0, 0, $overflowDays, 0, 0, 0, 0, 0, 0);
            $newDate = self::plainDateAdd($newDate, $extraDur, 1);
        }

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
        $sign = $value >= 0 ? 1 : -1;
        $abs = abs($value);
        $q = intdiv($abs, $increment);
        $r = $abs % $increment;
        if ($r === 0) {
            return $value;
        }
        $rounded = match ($mode) {
            'ceil' => $sign > 0 ? $q + 1 : $q,
            'floor' => $sign < 0 ? $q + 1 : $q,
            'trunc' => $q,
            'expand' => $q + 1,
            'halfExpand' => $r * 2 >= $increment ? $q + 1 : $q,
            'halfTrunc' => $r * 2 > $increment ? $q + 1 : $q,
            'halfCeil' => $r * 2 > $increment || ($r * 2 === $increment && $sign > 0) ? $q + 1 : $q,
            'halfFloor' => $r * 2 > $increment || ($r * 2 === $increment && $sign < 0) ? $q + 1 : $q,
            'halfEven' => $r * 2 > $increment || ($r * 2 === $increment && $q % 2 !== 0) ? $q + 1 : $q,
            default => $r * 2 >= $increment ? $q + 1 : $q,
        };
        return $sign * $rounded * $increment;
    }

    private static function roundBigIntNs(string $value, string $increment, string $mode): string
    {
        if ($increment === '0') {
            return $value;
        }
        // quotient = value / increment, round according to mode.
        $q = bcdiv($value, $increment, 20);
        $truncQ = bcdiv($value, $increment, 0);
        $isNonNeg = bccomp($value, '0', 0) >= 0;
        $rounded = match ($mode) {
            'ceil' => bcadd($q, '0', 0) === $q
                ? $q
                : ($isNonNeg ? bcadd($truncQ, '1', 0) : $truncQ),
            'floor' => $isNonNeg
                ? $truncQ
                : (bcsub($value, bcsub($increment, '1', 0), 0) !== $value
                    ? bcsub($truncQ, '1', 0) : $truncQ),
            'trunc' => $truncQ,
            default => bcdiv(
                bcadd(
                    bcmul($value, '2', 0),
                    $isNonNeg ? $increment : bcsub('0', $increment, 0),
                    0,
                ),
                bcmul($increment, '2', 0),
                0,
            ),
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

    /**
     * @param array<mixed> $allowed
     */
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

    /**
     * Read difference options (since/until) in alphabetical order and return a
     * fresh options object with coerced primitive values. If $swapRoundingMode
     * is true, invert directional rounding modes for since()'s negate-after-compute
     * pattern. Reading each option in strict alphabetical order, with coercion
     * inline, is required by spec order-of-operations tests.
     */
    private static function readDifferenceOptionsAlphabetical(JsValue $options, bool $swapRoundingMode): JsObject
    {
        $newOpts = new JsObject();
        if (!$options instanceof JsObject) {
            return $newOpts;
        }
        $lu = $options->get('largestUnit');
        if (!($lu instanceof JsUndefined)) {
            $luStr = TypeConversion::toString($lu);
            $newOpts->set('largestUnit', new JsString($luStr));
        }
        $ri = $options->get('roundingIncrement');
        if (!($ri instanceof JsUndefined)) {
            $riNum = TypeConversion::toNumber($ri);
            $newOpts->set('roundingIncrement', JsNumber::of($riNum));
        }
        $rm = $options->get('roundingMode');
        if (!($rm instanceof JsUndefined)) {
            $rmStr = TypeConversion::toString($rm);
            if ($swapRoundingMode) {
                $rmStr = match ($rmStr) {
                    'ceil' => 'floor',
                    'floor' => 'ceil',
                    'halfCeil' => 'halfFloor',
                    'halfFloor' => 'halfCeil',
                    default => $rmStr,
                };
            }
            $newOpts->set('roundingMode', new JsString($rmStr));
        }
        $su = $options->get('smallestUnit');
        if (!($su instanceof JsUndefined)) {
            $suStr = TypeConversion::toString($su);
            $newOpts->set('smallestUnit', new JsString($suStr));
        }
        return $newOpts;
    }

    private static function getFractionalSecondDigits(JsValue $options): string|int
    {
        if (!$options instanceof JsObject) {
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
            if (!is_finite($v->value) || is_nan($v->value)) {
                throw new RangeError('fractionalSecondDigits must be 0-9 or auto');
            }
            $n = (int) floor($v->value);
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
        if (!$options instanceof JsObject) {
            return $fallback;
        }
        $v = $options->get('roundingMode');
        if ($v instanceof JsUndefined) {
            return $fallback;
        }
        $mode = TypeConversion::toString($v);
        $valid = [
            'ceil', 'floor', 'expand', 'trunc',
            'halfCeil', 'halfFloor', 'halfExpand', 'halfTrunc', 'halfEven',
        ];
        if (!in_array($mode, $valid, true)) {
            throw new RangeError("Invalid roundingMode: {$mode}");
        }
        return $mode;
    }

    private static function getRoundingIncrement(JsObject $options): int
    {
        $v = $options->get('roundingIncrement');
        if ($v instanceof JsUndefined) {
            return 1;
        }
        $num = TypeConversion::toNumber($v);
        if (!is_finite($num)) {
            throw new RangeError('roundingIncrement must be finite');
        }
        $n = (int) $num;
        if ($n < 1) {
            throw new RangeError('roundingIncrement must be a positive integer');
        }
        if ($n > 1000000000) {
            throw new RangeError('roundingIncrement out of range');
        }
        return $n;
    }

    private static function getOverflow(JsValue $options): string
    {
        if (!$options instanceof JsObject) {
            return 'constrain';
        }
        $v = $options->get('overflow');
        if ($v instanceof JsUndefined) {
            return 'constrain';
        }
        $str = TypeConversion::toString($v);
        if ($str !== 'constrain' && $str !== 'reject') {
            throw new RangeError("Invalid overflow: {$str}");
        }
        return $str;
    }

    /**
     * Resolve a calendar identifier string. Accepts IANA calendar names or ISO datetime strings
     * (from which the calendar defaults to 'iso8601'). Returns the resolved calendar ID.
     */
    private static function resolveCalendarId(string $cal, bool $allowAnnotations = false): string
    {
        if ($cal === '') {
            throw new RangeError('empty string is not a valid calendar ID');
        }
        // Known valid calendars from the Unicode CLDR.
        $known = [
            'iso8601', 'gregory', 'japanese', 'buddhist', 'chinese', 'coptic',
            'dangi', 'ethioaa', 'ethiopic', 'hebrew', 'indian', 'islamic',
            'islamic-umalqura', 'islamic-tbla', 'islamic-civil', 'islamic-rgsa',
            'islamicc', 'persian', 'roc',
        ];
        // Canonicalize CLDR aliases to their preferred form so the
        // resolved [[Calendar]] slot matches what V8 returns.
        static $aliases = [
            'islamicc' => 'islamic-civil',
            'ethiopic-amete-alem' => 'ethioaa',
            'gregorian' => 'gregory',
        ];
        if (isset($aliases[$cal])) {
            return $aliases[$cal];
        }
        if (in_array($cal, $known, true)) {
            return $cal;
        }
        // Try to parse as ISO datetime string. If it parses, extract calendar (default iso8601).
        if (preg_match('/^\d{4}/', $cal) || preg_match('/^[+-]\d{4,6}/', $cal)) {
            // Reject minus zero year.
            if (preg_match('/^-0{4,6}-/', $cal)) {
                throw new RangeError("reject minus zero as extended year: {$cal}");
            }
            // Per spec: ISO strings with annotations are NOT valid as direct calendar IDs
            // (constructor args), but are valid in property bags.
            if (preg_match('/\[/', $cal)) {
                if (!$allowAnnotations) {
                    throw new RangeError(
                        "ISO string with annotations is not a valid calendar: {$cal}"
                    );
                }
                // Extract calendar from annotation.
                if (preg_match('/\[u-ca=([^\]]+)\]/', $cal, $cm)) {
                    $extracted = strtolower($cm[1]);
                    if (in_array($extracted, $known, true)) {
                        return $extracted;
                    }
                    throw new RangeError("Invalid calendar: {$extracted}");
                }
                return 'iso8601';
            }
            // Default to iso8601 for valid-looking date strings without annotations.
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $cal) || preg_match('/^[+-]\d{4,6}-\d{2}-\d{2}/', $cal)) {
                return 'iso8601';
            }
        }
        // Also accept MM-DD, --MM-DD, YYYY-MM as valid temporal strings -> iso8601.
        if (preg_match('/^\d{2}-\d{2}/', $cal) || preg_match('/^--\d{2}-\d{2}/', $cal)) {
            if (preg_match('/\[u-ca=([^\]]+)\]/', $cal, $cm)) {
                return strtolower($cm[1]);
            }
            return 'iso8601';
        }
        if (preg_match('/^\d{4}-\d{2}$/', $cal) || preg_match('/^\d{4}-\d{2}\[/', $cal)) {
            if (preg_match('/\[u-ca=([^\]]+)\]/', $cal, $cm)) {
                return strtolower($cm[1]);
            }
            return 'iso8601';
        }
        throw new RangeError("Invalid calendar: {$cal}");
    }


    /**
     * Convert a JsValue from a property bag's 'calendar' property to a calendar ID string.
     * Per spec, null/boolean/number/bigint/symbol/object throw TypeError.
     */
    private static function toCalendarSlotValue(JsValue $calVal, bool $allowAnnotations = true): string
    {
        // Temporal objects with [[Calendar]] slot: extract directly.
        if ($calVal instanceof JsObject && $calVal->has('[[Calendar]]')) {
            return self::getSlotString($calVal, '[[Calendar]]');
        }
        if ($calVal instanceof JsNull) {
            throw new TypeError('null is not a valid calendar');
        }
        if ($calVal instanceof JsBoolean) {
            throw new TypeError('boolean is not a valid calendar');
        }
        if ($calVal instanceof JsNumber) {
            throw new TypeError('number is not a valid calendar');
        }
        if ($calVal instanceof JsBigInt) {
            throw new TypeError('bigint is not a valid calendar');
        }
        if ($calVal instanceof JsObject) {
            throw new TypeError('object is not a valid calendar');
        }
        if (!$calVal instanceof JsString) {
            throw new TypeError('Cannot convert value to a valid calendar string');
        }
        $cal = strtolower(TypeConversion::toString($calVal));
        if ($cal === '') {
            throw new RangeError('empty string is not a valid calendar ID');
        }
        return self::resolveCalendarId($cal, $allowAnnotations);
    }

    /** Convert a JsValue to an integer for Temporal fields, rejecting Infinity and NaN. */
    private static function toTemporalInteger(JsValue $v, string $name): int
    {
        $num = TypeConversion::toNumber($v);
        if (is_nan($num) || is_infinite($num)) {
            throw new RangeError("{$name} property cannot be " . ($num > 0 ? 'Infinity' : ($num < 0 ? '-Infinity' : 'NaN')));
        }
        return (int) $num;
    }

    /**
     * Constrain time fields to valid ranges.
     *
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}
     */
    private static function constrainISOTime(int $h, int $min, int $s, int $ms, int $us, int $ns): array
    {
        $h = max(0, min(23, $h));
        $min = max(0, min(59, $min));
        $s = max(0, min(59, $s));
        $ms = max(0, min(999, $ms));
        $us = max(0, min(999, $us));
        $ns = max(0, min(999, $ns));
        return [$h, $min, $s, $ms, $us, $ns];
    }

    /** Reject time fields outside valid ranges. */
    private static function rejectISOTime(int $h, int $min, int $s, int $ms, int $us, int $ns): void
    {
        if ($h < 0 || $h > 23) {
            throw new RangeError("Invalid hour: {$h}");
        }
        if ($min < 0 || $min > 59) {
            throw new RangeError("Invalid minute: {$min}");
        }
        if ($s < 0 || $s > 59) {
            throw new RangeError("Invalid second: {$s}");
        }
        if ($ms < 0 || $ms > 999) {
            throw new RangeError("Invalid millisecond: {$ms}");
        }
        if ($us < 0 || $us > 999) {
            throw new RangeError("Invalid microsecond: {$us}");
        }
        if ($ns < 0 || $ns > 999) {
            throw new RangeError("Invalid nanosecond: {$ns}");
        }
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function constrainISODate(int $y, int $m, int $d): array
    {
        // Months <= 0 are always invalid even with constrain.
        if ($m < 1) {
            throw new RangeError("Invalid month: {$m}");
        }
        $m = min(12, $m);
        // Days <= 0 are always invalid even with constrain.
        if ($d < 1) {
            throw new RangeError("Invalid day: {$d}");
        }
        $dim = self::isoDaysInMonth($y, $m);
        $d = min($dim, $d);
        // Even with constrain, must be in representable range.
        if ($y < self::ISO_YEAR_MIN || $y > self::ISO_YEAR_MAX) {
            throw new RangeError("Year out of range: {$y}");
        }
        if ($y === self::ISO_YEAR_MIN && ($m < 4 || ($m === 4 && $d < 19))) {
            throw new RangeError("Date outside representable range");
        }
        if ($y === self::ISO_YEAR_MAX && ($m > 9 || ($m === 9 && $d > 13))) {
            throw new RangeError("Date outside representable range");
        }
        return [$y, $m, $d];
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

    /** @return \Closure(string, \Closure, int=): bool */
    private static function protoHelper(JsObject $proto): \Closure
    {
        return static fn (string $n, \Closure $fn, int $len = 0): bool => $proto->defineOwnProperty(
            $n,
            PropertyDescriptor::data(JsFunction::fromCallable($n, $fn, $len), true, false, true),
        );
    }

    /**
     * Build the options object used by ZonedDateTime.toLocaleString.
     * Per spec the formatter inherits the ZDT's time zone (and
     * throws on a mismatch), the calendar must agree, and when no
     * date/time components are explicit defaults add a full
     * datetime + timeZoneName: "short".
     */
    private static function resolveZonedDateTimeOptions(
        JsValue $options,
        string $zdtTz,
        string $zdtCal,
        ?JsValue $localeArg = null,
    ): JsValue {
        if ($options instanceof JsUndefined) {
            $resolved = JsObject::createNullPrototype();
        } elseif ($options instanceof JsObject) {
            $resolved = new JsObject($options->getPrototype());
            foreach ($options->getOwnPropertyNames() as $name) {
                $val = $options->get($name);
                if (!$val instanceof JsUndefined) {
                    $resolved->set($name, $val);
                }
            }
        } else {
            return $options;
        }
        // Calendar mismatch is rejected per spec.
        $optCal = $resolved->get('calendar');
        if ($optCal instanceof JsString) {
            $optCalStr = strtolower($optCal->value);
            $optCalNorm = match ($optCalStr) {
                'islamicc' => 'islamic-civil',
                'gregorian' => 'gregory',
                'ethiopic-amete-alem' => 'ethioaa',
                default => $optCalStr,
            };
            if ($optCalNorm !== $zdtCal && $zdtCal !== 'iso8601') {
                throw new RangeError('calendar option does not match Temporal.ZonedDateTime calendar');
            }
            if ($zdtCal === 'iso8601') {
                $resolved->set('calendar', new JsString($optCalNorm));
            }
        } elseif ($zdtCal !== 'iso8601') {
            // Probe the locale's resolved calendar: if it doesn't
            // match the ZDT's, throw — Intl.DateTimeFormat would
            // otherwise silently re-interpret the date.
            $resolvedLocaleCal = self::resolveLocaleCalendar($localeArg);
            if (
                $resolvedLocaleCal !== null
                && $resolvedLocaleCal !== 'iso8601'
                && $resolvedLocaleCal !== $zdtCal
            ) {
                throw new RangeError(
                    'Temporal.ZonedDateTime calendar does not match locale calendar',
                );
            }
            $resolved->set('calendar', new JsString($zdtCal));
        }
        // TimeZone option: spec rejects any user-supplied timeZone
        // (even one matching the ZDT) so the formatter inherits
        // the instance's zone unambiguously.
        $optTz = $resolved->get('timeZone');
        if (!$optTz instanceof JsUndefined) {
            throw new TypeError(
                'Intl.DateTimeFormat options must not have a timeZone property when formatting Temporal.ZonedDateTime',
            );
        }
        $resolved->set('timeZone', new JsString($zdtTz));
        // Default skeleton when nothing explicit was set.
        $relevant = [
            'weekday', 'year', 'month', 'day', 'dayPeriod', 'hour',
            'minute', 'second', 'fractionalSecondDigits', 'dateStyle',
            'timeStyle',
        ];
        $needDefaults = true;
        foreach ($relevant as $k) {
            if (!$resolved->get($k) instanceof JsUndefined) {
                $needDefaults = false;
                break;
            }
        }
        if ($needDefaults) {
            foreach (['year', 'month', 'day', 'hour', 'minute', 'second'] as $k) {
                $resolved->set($k, new JsString('numeric'));
            }
            if ($resolved->get('timeZoneName') instanceof JsUndefined) {
                $resolved->set('timeZoneName', new JsString('short'));
            }
        }
        return $resolved;
    }

    /**
     * Probe the resolved calendar for the given locale argument by
     * constructing an Intl.DateTimeFormat and reading
     * resolvedOptions().calendar. Returns null if Intl isn't loaded.
     */
    private static function resolveLocaleCalendar(?JsValue $localeArg): ?string
    {
        if (!extension_loaded('intl')) {
            return null;
        }
        $env = \Phasis\Engine::getCurrentInterpreter()?->getGlobalEnv();
        $intlObj = $env?->get('Intl', false);
        if (!$intlObj instanceof JsObject) {
            return null;
        }
        $dtfCtor = $intlObj->get('DateTimeFormat');
        if (!$dtfCtor instanceof JsFunction) {
            return null;
        }
        $proto = $dtfCtor->get('prototype');
        $obj = new JsObject($proto instanceof JsObject ? $proto : null);
        $obj->defineOwnProperty(
            '[[NewTarget]]',
            PropertyDescriptor::data($dtfCtor, false, false, false),
        );
        ($dtfCtor->getNativeCallable())($obj, [
            $localeArg ?? JsUndefined::instance(),
            JsUndefined::instance(),
        ]);
        $resolved = $obj->get('[[Calendar]]');
        return $resolved instanceof JsString ? $resolved->value : null;
    }

    private static function zonedDateTimeIsoFallback(string $ns, string $tz, string $cal): string
    {
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
        return $result;
    }

    /**
     * Delegate Temporal.Duration.prototype.toLocaleString to a
     * freshly constructed Intl.DurationFormat instance, calling
     * its format(this) so locale-aware output matches V8.
     *
     * @param array<mixed> $args
     */
    private static function durationToLocaleString(
        JsValue $this_,
        array $args,
        string $fallback,
    ): JsString {
        if (!extension_loaded('intl')) {
            return new JsString($fallback);
        }
        $env = \Phasis\Engine::getCurrentInterpreter()?->getGlobalEnv();
        $intlObj = $env?->get('Intl', false);
        if (!$intlObj instanceof JsObject) {
            return new JsString($fallback);
        }
        $dfCtor = $intlObj->get('DurationFormat');
        if (!$dfCtor instanceof JsFunction) {
            return new JsString($fallback);
        }
        $proto = $dfCtor->get('prototype');
        $obj = new JsObject($proto instanceof JsObject ? $proto : null);
        $obj->defineOwnProperty(
            '[[NewTarget]]',
            PropertyDescriptor::data($dfCtor, false, false, false),
        );
        ($dfCtor->getNativeCallable())($obj, [
            $args[0] ?? JsUndefined::instance(),
            $args[1] ?? JsUndefined::instance(),
        ]);
        $interp = \Phasis\Engine::getCurrentInterpreter();
        $formatFn = $proto instanceof JsObject ? $proto->get('format') : null;
        if ($interp !== null && $formatFn instanceof JsFunction) {
            $result = $interp->callFunction($formatFn, $obj, [$this_]);
            if ($result instanceof JsString) {
                return $result;
            }
        }
        return new JsString($fallback);
    }

    /**
     * Mirror ToDateTimeOptions(options, "all"): when no date or time
     * component is set on the options object (and no dateStyle/
     * timeStyle), add default {year/month/day/hour/minute/second}.
     * Returns a fresh options object so the caller's input isn't
     * mutated.
     */
    private static function ensureFullDateTimeOptions(JsValue $options): JsValue
    {
        if ($options instanceof JsUndefined) {
            $result = JsObject::createNullPrototype();
        } elseif ($options instanceof JsObject) {
            $result = new JsObject($options->getPrototype());
            foreach ($options->getOwnPropertyNames() as $name) {
                $val = $options->get($name);
                if (!$val instanceof JsUndefined) {
                    $result->set($name, $val);
                }
            }
        } else {
            return $options;
        }
        $relevantDate = ['weekday', 'year', 'month', 'day'];
        $relevantTime = ['dayPeriod', 'hour', 'minute', 'second', 'fractionalSecondDigits'];
        $needDefaults = true;
        foreach (array_merge($relevantDate, $relevantTime) as $k) {
            if (!$result->get($k) instanceof JsUndefined) {
                $needDefaults = false;
                break;
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
        foreach (['year', 'month', 'day', 'hour', 'minute', 'second'] as $k) {
            $result->set($k, new JsString('numeric'));
        }
        return $result;
    }

    /**
     * Derive the era string for a Temporal type's getter from
     * its ISO year and calendar id. Returns null when the calendar
     * doesn't carry eras.
     */
    /**
     * Japanese imperial era table: each entry is [era, startY, startM, startD,
     * baseISOYear]. The last era continues forward; eras before meiji fall
     * back to japanese-inverse (BCE-style).
     *
     * @return list<array{0: string, 1: int, 2: int, 3: int, 4: int}>
     */
    private static function japaneseEras(): array
    {
        // Each row: [era name, ISO start (Y, M, D), eraYear=1 base ISO year]
        return [
            ['reiwa',  2019, 5,  1, 2019],
            ['heisei', 1989, 1,  8, 1989],
            ['showa',  1926, 12, 25, 1926],
            ['taisho', 1912, 7,  30, 1912],
            ['meiji',  1868, 9,  8,  1868],
        ];
    }

    /**
     * Convert (eraName, eraYear) into an ISO year using the Japanese era table.
     * Returns null when the era is not recognized.
     */
    private static function japaneseEraToIsoYear(string $era, int $eraYear): ?int
    {
        foreach (self::japaneseEras() as $row) {
            if ($row[0] === $era) {
                return $row[4] + $eraYear - 1;
            }
        }
        return null;
    }

    /**
     * @return array{0: string, 1: int, 2: int, 3: int, 4: int}|null
     */
    private static function japaneseEraFor(int $y, int $m, int $d): ?array
    {
        foreach (self::japaneseEras() as $era) {
            [, $sy, $sm, $sd] = $era;
            if (
                $y > $sy
                || ($y === $sy && $m > $sm)
                || ($y === $sy && $m === $sm && $d >= $sd)
            ) {
                return $era;
            }
        }
        return null;
    }

    private static function deriveEra(string $cal, int $isoYear, int $isoMonth = 1, int $isoDay = 1): ?string
    {
        switch ($cal) {
            case 'gregory':
                return $isoYear >= 1 ? 'gregory' : 'gregory-inverse';
            case 'roc':
                return $isoYear >= 1912 ? 'roc' : 'roc-inverse';
            case 'japanese':
                $era = self::japaneseEraFor($isoYear, $isoMonth, $isoDay);
                if ($era !== null) {
                    return $era[0];
                }
                return $isoYear >= 1 ? 'japanese' : 'japanese-inverse';
            case 'coptic':
                $eraIdx = self::icuEraIndexForIso($cal, $isoYear, $isoMonth, $isoDay);
                if ($eraIdx === 1) {
                    return 'coptic';
                }
                if ($eraIdx === 0) {
                    return 'coptic-inverse';
                }
                return null;
            case 'ethiopic':
                // Pure-PHP: ethiopic (Amete Mihret) for canonical year >= 1,
                // ethioaa (Amete Alem) for canonical year < 1. ICU 70 disagrees
                // with the spec on the boundary so don't use it.
                $e = self::isoToEthiopicDate($isoYear, $isoMonth, $isoDay);
                return $e['year'] >= 1 ? 'ethiopic' : 'ethioaa';
            case 'ethioaa':
            case 'ethiopic-amete-alem':
                // Ethiopic Amete Alem has only one era (always positive
                // counting from BC 5500); Temporal exposes era /
                // eraYear as undefined for these single-era calendars.
                return null;
        }
        return null;
    }

    /**
     * Look up the ICU era index for an ISO date in the given calendar.
     * Used by deriveEra to flip coptic-inverse / ethioaa for ISO dates
     * predating the calendar's positive epoch.
     */
    private static function icuEraIndexForIso(string $cal, int $isoYear, int $isoMonth, int $isoDay): ?int
    {
        if (!extension_loaded('intl')) {
            return null;
        }
        $icuName = $cal === 'ethioaa' ? 'ethiopic-amete-alem' : $cal;
        try {
            $icuCal = \IntlCalendar::createInstance(
                'UTC',
                "@calendar={$icuName}",
            );
            $sec = self::isoToUnixSeconds($isoYear, $isoMonth, $isoDay);
            $icuCal->setTime($sec * 1000.0);
            return (int) $icuCal->get(\IntlCalendar::FIELD_ERA);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Convert an ISO calendar date to Unix epoch seconds at midnight UTC.
     * Avoids gmmktime which is locale-quirk for negative years.
     */
    private static function isoToUnixSeconds(int $year, int $month, int $day): int
    {
        $a = (14 - $month) > 0 ? intdiv(14 - $month, 12) : -intdiv($month - 14, 12);
        $y = $year + 4800 - $a;
        $m = $month + 12 * $a - 3;
        $jdn = $day + intdiv(153 * $m + 2, 5) + 365 * $y + intdiv($y, 4) - intdiv($y, 100) + intdiv($y, 400) - 32045;
        return ($jdn - 2440588) * 86400;
    }

    /**
     * Derive the eraYear value for a Temporal type's getter.
     */
    private static function deriveEraYear(string $cal, int $isoYear, int $isoMonth = 1, int $isoDay = 1): ?int
    {
        switch ($cal) {
            case 'gregory':
                return $isoYear >= 1 ? $isoYear : (1 - $isoYear);
            case 'roc':
                return $isoYear >= 1912
                    ? ($isoYear - 1911)
                    : (1912 - $isoYear);
            case 'japanese':
                $era = self::japaneseEraFor($isoYear, $isoMonth, $isoDay);
                if ($era !== null) {
                    return $isoYear - $era[4] + 1;
                }
                return $isoYear >= 1 ? $isoYear : (1 - $isoYear);
            case 'coptic':
                $year = self::icuYearForIso($cal, $isoYear, $isoMonth, $isoDay);
                return $year;
            case 'ethiopic':
                $e = self::isoToEthiopicDate($isoYear, $isoMonth, $isoDay);
                if ($e['year'] >= 1) {
                    return $e['year'];
                }
                // Pre-EE 1: era flips to ethioaa; eraYear is the AA year
                // (AA year = ethiopic year + 5500).
                return $e['year'] + self::ETHIOAA_YEAR_OFFSET;
            case 'ethioaa':
            case 'ethiopic-amete-alem':
                // Ethiopic Amete Alem: era / eraYear are undefined.
                return null;
        }
        return null;
    }

    /**
     * Look up the ICU YEAR field (not extended year) for an ISO date.
     * For coptic/ethiopic/ethioaa the YEAR field already counts within
     * the active era (era 0 yields a positive year flipping at the
     * inverse epoch boundary).
     */
    private static function icuYearForIso(string $cal, int $isoYear, int $isoMonth, int $isoDay): ?int
    {
        if (!extension_loaded('intl')) {
            return null;
        }
        $icuName = $cal === 'ethioaa' ? 'ethiopic-amete-alem' : $cal;
        try {
            $icuCal = \IntlCalendar::createInstance(
                'UTC',
                "@calendar={$icuName}",
            );
            $sec = self::isoToUnixSeconds($isoYear, $isoMonth, $isoDay);
            $icuCal->setTime($sec * 1000.0);
            return (int) $icuCal->get(\IntlCalendar::FIELD_YEAR);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Throw RangeError when two Temporal operands carry different
     * calendar IDs, used as a guard for since/until/equals.
     */
    private static function requireMatchingCalendars(JsValue $a, JsValue $b): void
    {
        if (!$a instanceof JsObject || !$b instanceof JsObject) {
            return;
        }
        $cal1 = self::getSlotString($a, '[[Calendar]]');
        $cal2 = self::getSlotString($b, '[[Calendar]]');
        if ($cal1 !== $cal2) {
            throw new RangeError(
                "calendar IDs do not match: {$cal1} vs {$cal2}",
            );
        }
    }

    /**
     * For ZDT.since/until: when the requested largestUnit is a
     * calendar unit (year/month/week/day), the two operands' time
     * zones must canonicalize to the same id, since otherwise the
     * day-boundary alignment isn't well defined.
     */
    private static function requireMatchingTimeZonesForCalendarUnits(
        JsValue $a,
        JsValue $b,
        ?JsValue $opts,
    ): void {
        if (!$opts instanceof JsObject) {
            return;
        }
        $lu = $opts->get('largestUnit');
        $luStr = $lu instanceof JsString ? $lu->value : 'hour';
        $calendarUnits = ['year', 'years', 'month', 'months', 'week', 'weeks', 'day', 'days', 'auto'];
        if (!in_array($luStr, $calendarUnits, true)) {
            return;
        }
        if (!$a instanceof JsObject || !$b instanceof JsObject) {
            return;
        }
        $tz1 = self::getSlotString($a, '[[TimeZone]]');
        $tz2 = self::getSlotString($b, '[[TimeZone]]');
        $canon1 = self::normalizeTimeZoneId($tz1);
        $canon2 = self::normalizeTimeZoneId($tz2);
        if ($canon1 !== $canon2) {
            throw new RangeError(
                "time zones do not canonicalize to the same id: {$tz1} vs {$tz2}",
            );
        }
    }

    private static function setToStringTag(JsObject $obj, string $tag): void
    {
        $sym = SymbolConstructor::toStringTag();
        $obj->definePropertyBySymbol(
            $sym,
            PropertyDescriptor::data(new JsString($tag), false, false, true),
        );
    }

    /**
     * Install Temporal.<Type>.prototype[@@toPrimitive] on the given
     * prototype. Per spec, every Temporal type's @@toPrimitive returns
     * the result of toString() for the "string" and "default" hints and
     * throws TypeError for "number". Without this hook, default
     * ToPrimitive walks ["valueOf", "toString"] and invokes valueOf,
     * which every Temporal type's spec-mandated stub explicitly throws
     * — breaking template-literal interpolation `${plainDateTime}` etc.
     *
     * The conversion delegates to the prototype's own `toString`
     * descriptor so each type's specific zero-argument toString form
     * (with calendar suffix etc.) is reused verbatim.
     */
    private static function installTemporalToPrimitive(
        JsObject $proto,
        string $typeName,
    ): void {
        $sym = SymbolConstructor::toPrimitive();
        $fn = JsFunction::fromCallable(
            '[Symbol.toPrimitive]',
            function (JsValue $this_, array $args) use ($proto, $typeName): JsValue {
                $hint = $args[0] ?? JsUndefined::instance();
                $hintStr = $hint instanceof JsString ? $hint->value : '';
                if ($hintStr !== 'string' && $hintStr !== 'default') {
                    throw new TypeError(
                        "Temporal.{$typeName}.prototype[Symbol.toPrimitive]: hint must be 'string' or 'default'"
                    );
                }
                $toStr = $proto->get('toString');
                if (!$toStr instanceof JsFunction) {
                    throw new TypeError(
                        "Temporal.{$typeName}.prototype.toString is not callable"
                    );
                }
                return $toStr->call($this_, []);
            },
            1,
        );
        $proto->definePropertyBySymbol(
            $sym,
            PropertyDescriptor::data($fn, false, false, true),
        );
    }

    /**
     * Delegate Temporal.<Type>.prototype.toLocaleString to a freshly
     * constructed Intl.DateTimeFormat instance, calling its bound
     * format(this). Used by all Temporal types whose toLocaleString
     * should produce a localised rendering instead of the ISO string.
     *
     * For Instant inputs, mirror Date.prototype.toLocaleString by
     * defaulting the date+time components when no relevant option
     * was supplied, so a lone {timeZoneName: "short"} still emits
     * the full datetime context around the zone label.
     *
     * @param array<mixed> $args
     */
    private static function temporalToLocaleString(
        JsValue $this_,
        array $args,
        string $fallback,
    ): JsString {
        if (!extension_loaded('intl')) {
            return new JsString($fallback);
        }
        $env = \Phasis\Engine::getCurrentInterpreter()?->getGlobalEnv();
        $intlObj = $env?->get('Intl', false);
        if (!$intlObj instanceof JsObject) {
            return new JsString($fallback);
        }
        $dtfCtor = $intlObj->get('DateTimeFormat');
        if (!$dtfCtor instanceof JsFunction) {
            return new JsString($fallback);
        }
        // For Temporal.Instant only, augment options with default
        // date+time components (per spec ToDateTimeOptions("all")
        // semantics). Plain types are already adapted through
        // temporalFormatterFor's default-skeleton path.
        $optionsArg = $args[1] ?? JsUndefined::instance();
        if (
            $this_ instanceof JsObject
            && $this_->has('[[EpochNanoseconds]]')
            && !$this_->has('[[IsZonedDateTime]]')
        ) {
            $optionsArg = self::ensureFullDateTimeOptions($optionsArg);
        }
        // Calendar mismatch: Plain types whose calendar isn't iso8601
        // and disagrees with the resolved locale's calendar must
        // throw per spec.
        if (
            $this_ instanceof JsObject
            && (
                $this_->has('[[IsPlainDate]]')
                || $this_->has('[[IsPlainDateTime]]')
                || $this_->has('[[IsPlainYearMonth]]')
                || $this_->has('[[IsPlainMonthDay]]')
            )
        ) {
            $instCal = $this_->get('[[Calendar]]');
            $instCalStr = $instCal instanceof JsString ? $instCal->value : 'iso8601';
            // PlainMonthDay (no year) and PlainYearMonth (no day) require
            // the formatter's calendar to match the instance's calendar:
            // even ISO instances reject a non-ISO locale calendar. Plain
            // date/datetime accept ISO instances on any locale.
            $isStrict = $this_->has('[[IsPlainMonthDay]]')
                || $this_->has('[[IsPlainYearMonth]]');
            if ($instCalStr !== 'iso8601' || $isStrict) {
                // Resolve the formatter's effective calendar by
                // accounting for an explicit options.calendar
                // override; without it, fall back to the locale's
                // default.
                $effectiveCal = null;
                if ($optionsArg instanceof JsObject) {
                    $calOpt = $optionsArg->get('calendar');
                    if ($calOpt instanceof JsString) {
                        $effectiveCal = strtolower($calOpt->value);
                        // Apply CLDR aliases.
                        static $calAliasLs = [
                            'islamicc' => 'islamic-civil',
                            'gregorian' => 'gregory',
                            'ethiopic-amete-alem' => 'ethioaa',
                        ];
                        if (isset($calAliasLs[$effectiveCal])) {
                            $effectiveCal = $calAliasLs[$effectiveCal];
                        }
                    }
                }
                if ($effectiveCal === null) {
                    $effectiveCal = self::resolveLocaleCalendar(
                        $args[0] ?? JsUndefined::instance(),
                    );
                }
                if (
                    $effectiveCal !== null
                    && $effectiveCal !== $instCalStr
                    && (
                        $effectiveCal !== 'iso8601'
                        || $instCalStr !== 'iso8601'
                    )
                ) {
                    throw new RangeError(
                        'Temporal type calendar does not match locale calendar',
                    );
                }
            }
        }
        $proto = $dtfCtor->get('prototype');
        $obj = new JsObject($proto instanceof JsObject ? $proto : null);
        $obj->defineOwnProperty(
            '[[NewTarget]]',
            PropertyDescriptor::data($dtfCtor, false, false, false),
        );
        ($dtfCtor->getNativeCallable())($obj, [
            $args[0] ?? JsUndefined::instance(),
            $optionsArg,
        ]);
        $interp = \Phasis\Engine::getCurrentInterpreter();
        $formatGetter = $proto instanceof JsObject
            ? $proto->getOwnPropertyDescriptor('format')
            : null;
        if (
            $interp !== null
            && $formatGetter !== null
            && $formatGetter->get instanceof JsFunction
        ) {
            $bound = $interp->callFunction($formatGetter->get, $obj, []);
            if ($bound instanceof JsFunction) {
                $result = $interp->callFunction(
                    $bound,
                    JsUndefined::instance(),
                    [$this_],
                );
                if ($result instanceof JsString) {
                    return $result;
                }
            }
        }
        return new JsString($fallback);
    }
}
