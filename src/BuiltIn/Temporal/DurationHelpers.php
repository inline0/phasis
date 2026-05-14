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
 * Temporal helper section (DurationHelpers). Composed into TemporalObject
 * via `use Temporal\DurationHelpers;`. `self::` references resolve into
 * the composing class.
 */
trait DurationHelpers
{
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
}
