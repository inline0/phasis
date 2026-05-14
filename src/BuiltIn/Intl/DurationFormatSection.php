<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Intl;

use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
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
 * Intl.DurationFormat section. Composed into IntlObject via
 * `use Intl\DurationFormatSection;`. `self::` references resolve into
 * the composing class so cross-section helpers continue to work.
 */
trait DurationFormatSection
{
    // ---------------------------------------------------------------
    // Intl.DurationFormat (minimal stub for structural compliance)
    // ---------------------------------------------------------------

    private static function installDurationFormat(JsObject $intl): void
    {
        $proto = new JsObject();

        $constructor = JsFunction::fromCallable(
            'DurationFormat',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (
                    !$this_ instanceof JsObject
                    || $this_->get('[[NewTarget]]') instanceof JsUndefined
                ) {
                    throw new TypeError('Constructor Intl.DurationFormat requires \'new\'');
                }
                $localesArg = $args[0] ?? JsUndefined::instance();
                $optionsArg = $args[1] ?? JsUndefined::instance();
                $locales = self::localesFromArg($localesArg);
                $options = self::getOptionsObject($optionsArg);
                self::validateLocaleMatcher($options);

                $obj = self::instanceFromConstructor($this_, $proto, 'DurationFormat');
                $obj->defineOwnProperty('[[InitializedDurationFormat]]', PropertyDescriptor::data(
                    new JsBoolean(true),
                    false,
                    false,
                    false,
                ));

                // numberingSystem: "latn" only (we support latn).
                $numberingSystem = 'latn';
                $nsVal = $options->get('numberingSystem');
                if (!$nsVal instanceof JsUndefined) {
                    $ns = TypeConversion::toString($nsVal);
                    if (!self::isValidUnicodeTypeValue($ns)) {
                        throw new RangeError("Invalid numberingSystem: {$ns}");
                    }
                    $numberingSystem = $ns;
                }
                $obj->defineOwnProperty('[[NumberingSystem]]', PropertyDescriptor::data(
                    new JsString($numberingSystem),
                    false,
                    false,
                    false,
                ));

                // style: "long" / "short" / "narrow" / "digital", default "short".
                $style = 'short';
                $styleVal = $options->get('style');
                if (!$styleVal instanceof JsUndefined) {
                    $s = TypeConversion::toString($styleVal);
                    if (!in_array($s, ['long', 'short', 'narrow', 'digital'], true)) {
                        throw new RangeError("Invalid style: {$s}");
                    }
                    $style = $s;
                }
                $obj->defineOwnProperty('[[Style]]', PropertyDescriptor::data(
                    new JsString($style),
                    false,
                    false,
                    false,
                ));

                $units = [
                    'years', 'months', 'weeks', 'days',
                    'hours', 'minutes', 'seconds',
                    'milliseconds', 'microseconds', 'nanoseconds',
                ];
                // GetDurationUnitOptions: track prevStyle so a
                // numeric / 2-digit run propagates downstream defaults.
                // - hours/minutes/seconds following numeric: "2-digit"
                // - everything else following numeric: "numeric"
                $prevStyle = null;
                foreach ($units as $u) {
                    $allowed = ($u === 'hours' || $u === 'minutes' || $u === 'seconds'
                        || $u === 'milliseconds' || $u === 'microseconds' || $u === 'nanoseconds')
                        ? ['long', 'short', 'narrow', 'numeric', '2-digit']
                        : ['long', 'short', 'narrow'];
                    $val = $options->get($u);
                    $explicitStyle = null;
                    if (!$val instanceof JsUndefined) {
                        $u2 = TypeConversion::toString($val);
                        if (!in_array($u2, $allowed, true)) {
                            throw new RangeError("Invalid {$u}: {$u2}");
                        }
                        $explicitStyle = $u2;
                    }
                    if ($explicitStyle !== null) {
                        $unitDisplay = $explicitStyle;
                    } elseif ($prevStyle === 'numeric' || $prevStyle === '2-digit') {
                        // Spec: numeric run continues; minutes/seconds
                        // default to 2-digit, others to numeric.
                        if ($u === 'minutes' || $u === 'seconds') {
                            $unitDisplay = '2-digit';
                        } else {
                            $unitDisplay = 'numeric';
                        }
                    } elseif (
                        $style === 'digital'
                        && ($u === 'hours' || $u === 'minutes' || $u === 'seconds')
                    ) {
                        $unitDisplay = 'numeric';
                    } else {
                        $unitDisplay = $style === 'digital' ? 'short' : $style;
                    }
                    // Spec: a "long"/"short"/"narrow" style after a
                    // numeric/2-digit predecessor is a RangeError
                    // regardless of which unit (the numeric run can't
                    // be interrupted).
                    if (
                        $explicitStyle !== null
                        && ($prevStyle === 'numeric' || $prevStyle === '2-digit')
                        && in_array($explicitStyle, ['long', 'short', 'narrow'], true)
                    ) {
                        throw new RangeError(
                            "{$u} style '{$explicitStyle}' incompatible with preceding numeric unit",
                        );
                    }
                    $slot = '[[' . ucfirst($u) . ']]';
                    $obj->defineOwnProperty($slot, PropertyDescriptor::data(
                        new JsString($unitDisplay),
                        false,
                        false,
                        false,
                    ));
                    // Per-unit display: "always" or "auto".
                    $displaySlot = '[[' . ucfirst($u) . 'Display]]';
                    $displayKey = $u . 'Display';
                    // Per spec: hours/minutes/seconds in "digital"
                    // base style default to display:"always" so the
                    // fixed-width clock layout always appears.
                    $isClockUnit = in_array($u, ['hours', 'minutes', 'seconds'], true);
                    $displayDefault = !$val instanceof JsUndefined
                        ? 'always'
                        : (($style === 'digital' && $isClockUnit) ? 'always' : 'auto');
                    $dVal = $options->get($displayKey);
                    if (!$dVal instanceof JsUndefined) {
                        $d = TypeConversion::toString($dVal);
                        if (!in_array($d, ['always', 'auto'], true)) {
                            throw new RangeError("Invalid {$displayKey}: {$d}");
                        }
                        $displayDefault = $d;
                    }
                    $obj->defineOwnProperty($displaySlot, PropertyDescriptor::data(
                        new JsString($displayDefault),
                        false,
                        false,
                        false,
                    ));
                    $prevStyle = $unitDisplay;
                }

                // fractionalDigits: 0-9 (or undefined).
                $fdVal = $options->get('fractionalDigits');
                if (!$fdVal instanceof JsUndefined) {
                    $n = TypeConversion::toNumber($fdVal);
                    if (is_nan($n) || $n < 0 || $n > 9) {
                        throw new RangeError("Invalid fractionalDigits: {$n}");
                    }
                    $obj->defineOwnProperty('[[FractionalDigits]]', PropertyDescriptor::data(
                        JsNumber::of((float) (int) floor($n)),
                        false,
                        false,
                        false,
                    ));
                }

                $resolvedLocale = self::resolveLocale($locales, ['nu']);
                $obj->defineOwnProperty('[[Locale]]', PropertyDescriptor::data(
                    new JsString($resolvedLocale),
                    false,
                    false,
                    false,
                ));

                return $obj;
            },
            0,
        );
        $constructor->setConstructable();

        $constructor->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data($proto, false, false, false),
        );
        $proto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($constructor, true, false, true),
        );

        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Intl.DurationFormat'), false, false, true),
        );

        // DurationFormat.prototype.format(duration)
        $formatFn = JsFunction::fromCallable('format', function (
            JsValue $this_,
            array $args,
        ): JsValue {
            if (
                !$this_ instanceof JsObject
                || $this_ instanceof \Phasis\Value\JsProxy
                || $this_->get('[[InitializedDurationFormat]]') instanceof JsUndefined
            ) {
                throw new TypeError('Intl.DurationFormat.prototype.format called on non-DurationFormat');
            }
            // Without full CLDR unit-pattern data, fall back to a
            // simple English-style rendering.
            $duration = $args[0] ?? JsUndefined::instance();
            return new JsString(self::durationFormatRender($this_, $duration));
        }, 1);
        $proto->defineOwnProperty(
            'format',
            PropertyDescriptor::data($formatFn, true, false, true),
        );

        $formatToParts = JsFunction::fromCallable('formatToParts', function (
            JsValue $this_,
            array $args,
        ): JsValue {
            if (
                !$this_ instanceof JsObject
                || $this_ instanceof \Phasis\Value\JsProxy
                || $this_->get('[[InitializedDurationFormat]]') instanceof JsUndefined
            ) {
                throw new TypeError(
                    'Intl.DurationFormat.prototype.formatToParts called on non-DurationFormat',
                );
            }
            $duration = $args[0] ?? JsUndefined::instance();
            // Run through the same validation as format() so invalid
            // input produces the spec-required TypeError / RangeError.
            // Use the partitioned representation rather than the
            // pre-joined string so the parts shape mirrors the spec.
            return self::durationFormatToParts($this_, $duration);
        }, 1);
        $proto->defineOwnProperty(
            'formatToParts',
            PropertyDescriptor::data($formatToParts, true, false, true),
        );

        $resolvedOptions = JsFunction::fromCallable('resolvedOptions', function (
            JsValue $this_,
        ): JsValue {
            if (
                !$this_ instanceof JsObject
                || $this_ instanceof \Phasis\Value\JsProxy
                || $this_->get('[[InitializedDurationFormat]]') instanceof JsUndefined
            ) {
                throw new TypeError(
                    'Intl.DurationFormat.prototype.resolvedOptions called on non-DurationFormat',
                );
            }
            $result = new JsObject();
            self::defineDataProp($result, 'locale', new JsString(
                self::extractInternalString($this_, '[[Locale]]', 'en'),
            ));
            self::defineDataProp($result, 'numberingSystem', new JsString(
                self::extractInternalString($this_, '[[NumberingSystem]]', 'latn'),
            ));
            self::defineDataProp($result, 'style', new JsString(
                self::extractInternalString($this_, '[[Style]]', 'short'),
            ));
            $units = [
                'years', 'months', 'weeks', 'days',
                'hours', 'minutes', 'seconds',
                'milliseconds', 'microseconds', 'nanoseconds',
            ];
            foreach ($units as $u) {
                $slot = '[[' . ucfirst($u) . ']]';
                self::defineDataProp($result, $u, new JsString(
                    self::extractInternalString($this_, $slot, 'short'),
                ));
                $displaySlot = '[[' . ucfirst($u) . 'Display]]';
                self::defineDataProp($result, $u . 'Display', new JsString(
                    self::extractInternalString($this_, $displaySlot, 'auto'),
                ));
            }
            $fdVal = $this_->get('[[FractionalDigits]]');
            if ($fdVal instanceof JsNumber) {
                self::defineDataProp($result, 'fractionalDigits', $fdVal);
            }
            return $result;
        }, 0);
        $proto->defineOwnProperty(
            'resolvedOptions',
            PropertyDescriptor::data($resolvedOptions, true, false, true),
        );

        $constructor->defineOwnProperty(
            'supportedLocalesOf',
            PropertyDescriptor::data(self::makeSupportedLocalesOf('DurationFormat'), true, false, true),
        );

        $intl->defineOwnProperty(
            'DurationFormat',
            PropertyDescriptor::data($constructor, true, false, true),
        );
    }

    /**
     * Render a Temporal-Duration-like JS object to a localised string.
     * Handles the common English-style rendering used by V8: each
     * non-zero unit becomes a "<value> <unit-label>" segment, joined
     * by a list separator. A best-effort fallback for locales we
     * don't have CLDR unit data for.
     */
    /**
     * Parse an ISO 8601 duration string into a unit-keyed array.
     * Returns null when the string isn't a well-formed duration.
     * The fractional-seconds portion (decimal after S) is split into
     * milliseconds / microseconds / nanoseconds at 3 / 6 / 9 digits.
     *
     * @return array<string, int>|null
     */
    private static function parseIsoDurationString(string $s): ?array
    {
        if (
            preg_match(
                '/^([+-])?P(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)(?:\.(\d{1,9}))?S)?)?$/',
                $s,
                $m,
            ) !== 1
        ) {
            return null;
        }
        $sign = ($m[1] ?? '') === '-' ? -1 : 1;
        $years = (int) ($m[2] !== '' ? $m[2] : 0);
        $months = (int) ($m[3] !== '' ? $m[3] : 0);
        $weeks = (int) ($m[4] !== '' ? $m[4] : 0);
        $days = (int) ($m[5] !== '' ? $m[5] : 0);
        $hours = (int) ($m[6] !== '' ? $m[6] : 0);
        $minutes = (int) ($m[7] !== '' ? $m[7] : 0);
        $seconds = (int) ($m[8] !== '' ? $m[8] : 0);
        $ms = 0;
        $us = 0;
        $ns = 0;
        $frac = $m[9] ?? '';
        if ($frac !== '') {
            $frac = str_pad($frac, 9, '0');
            $ms = (int) substr($frac, 0, 3);
            $us = (int) substr($frac, 3, 3);
            $ns = (int) substr($frac, 6, 3);
        }
        return [
            'years' => $sign * $years,
            'months' => $sign * $months,
            'weeks' => $sign * $weeks,
            'days' => $sign * $days,
            'hours' => $sign * $hours,
            'minutes' => $sign * $minutes,
            'seconds' => $sign * $seconds,
            'milliseconds' => $sign * $ms,
            'microseconds' => $sign * $us,
            'nanoseconds' => $sign * $ns,
        ];
    }

    /** Build a JS-side duration object from a parsed ISO record. */
    /**
     * @param array<mixed> $values
     */
    private static function durationRecordToObject(array $values): JsObject
    {
        $obj = new JsObject();
        foreach ($values as $key => $n) {
            $obj->set($key, JsNumber::of((float) $n));
        }
        return $obj;
    }

    private static function durationFormatToParts(JsObject $df, JsValue $duration): JsArray
    {
        // Validate the input through the same gate as format(): this
        // throws TypeError / RangeError for malformed durations.
        $values = self::durationToValues($df, $duration);
        $sign = self::durationOverallSign($values);
        $style = self::extractInternalString($df, '[[Style]]', 'short');
        $listSep = $style === 'narrow' ? ' ' : ', ';
        $units = [
            'years' => 'year', 'months' => 'month', 'weeks' => 'week',
            'days' => 'day', 'hours' => 'hour', 'minutes' => 'minute',
            'seconds' => 'second', 'milliseconds' => 'millisecond',
            'microseconds' => 'microsecond', 'nanoseconds' => 'nanosecond',
        ];
        $arr = new JsArray();
        $idx = 0;
        $emit = static function (
            string $type,
            string $value,
            ?string $unit = null,
        ) use (
            &$arr,
            &$idx
): void {
            if ($value === '') {
                return;
            }
            $part = new JsObject();
            self::defineDataProp($part, 'type', new JsString($type));
            self::defineDataProp($part, 'value', new JsString($value));
            if ($unit !== null) {
                self::defineDataProp($part, 'unit', new JsString($unit));
            }
            $arr->set((string) $idx++, $part);
        };
        $isFirstSegment = true;
        $signNeedsAttaching = $sign < 0;
        // Detect a clock run: the LONGEST trailing suffix of
        // hours/minutes/seconds whose unit styles are all numeric or
        // 2-digit. With at least two such units we render them
        // inline as a colon-joined clock segment (e.g. minutes &
        // seconds collapse to "M:SS" even when hours is short).
        $clockSkip = self::detectClockUnitsToSkip($df);
        $clockEmitAt = $clockSkip !== [] ? $clockSkip[0] : null;
        foreach ($units as $u => $singular) {
            if (in_array($u, $clockSkip, true)) {
                if ($u === $clockEmitAt) {
                    $sigEmitted = self::emitClockParts(
                        $df,
                        $values,
                        $emit,
                        $isFirstSegment,
                        $signNeedsAttaching,
                        $listSep,
                    );
                    if ($sigEmitted) {
                        $signNeedsAttaching = false;
                        $isFirstSegment = false;
                    }
                }
                continue;
            }
            $n = $values[$u];
            $displaySlot = '[[' . ucfirst($u) . 'Display]]';
            $display = self::extractInternalString($df, $displaySlot, 'auto');
            if ($n === 0.0 && $display !== 'always') {
                continue;
            }
            $unitSlot = '[[' . ucfirst($u) . ']]';
            $unitStyle = self::extractInternalString($df, $unitSlot, 'short');
            if (!$isFirstSegment) {
                $emit('literal', $listSep);
            }
            $renderInt = (int) abs($n);
            if ($isFirstSegment && $signNeedsAttaching) {
                $emit('minusSign', '-', $singular);
                $signNeedsAttaching = false;
            }
            $localeForLabel = self::extractInternalString($df, '[[Locale]]', 'en');
            // Use grouping for the integer portion when the segment
            // isn't narrow.
            $intStr = self::floatToIntegerString(abs($n));
            $intParts = $unitStyle !== 'narrow'
                ? self::splitIntegerWithGrouping($intStr, $localeForLabel)
                : [['type' => 'integer', 'value' => $intStr]];
            foreach ($intParts as $p) {
                $emit($p['type'], $p['value'], $singular);
            }
            $unitLabel = self::durationUnitLabelFor($u, $unitStyle, $singular, $localeForLabel, $renderInt);
            if ($unitStyle === 'narrow') {
                $emit('unit', $unitLabel, $singular);
            } else {
                // The intra-segment space carries the unit attribute,
                // since it's part of the unit's pattern (only the
                // inter-segment list separator is a bare literal).
                $emit('literal', ' ', $singular);
                $emit('unit', $unitLabel, $singular);
            }
            $isFirstSegment = false;
        }
        $arr->set('length', JsNumber::of((float) $idx));
        return $arr;
    }

    /**
     * Emit the H:MM:SS[.fff] parts sequence for a digital-style
     * clock run. Returns true when at least one clock part was
     * emitted.
     *
     * @param array<string, float> $values
     * @param callable(string, string, ?string=): void $emit
     */
    private static function emitClockParts(
        JsObject $df,
        array $values,
        callable $emit,
        bool $isFirstSegment,
        bool $signNeedsAttaching,
        string $listSep,
    ): bool {
        $hours = (int) abs($values['hours'] ?? 0.0);
        $minutes = (int) abs($values['minutes'] ?? 0.0);
        $seconds = (int) abs($values['seconds'] ?? 0.0);
        $hoursDisplay = self::extractInternalString($df, '[[HoursDisplay]]', 'auto');
        $minutesDisplay = self::extractInternalString($df, '[[MinutesDisplay]]', 'auto');
        $secondsDisplay = self::extractInternalString($df, '[[SecondsDisplay]]', 'auto');
        $hourStyle = self::extractInternalString($df, '[[Hours]]', 'numeric');
        $msVal = (int) abs($values['milliseconds'] ?? 0.0);
        $usVal = (int) abs($values['microseconds'] ?? 0.0);
        $nsVal = (int) abs($values['nanoseconds'] ?? 0.0);
        $fracTotalNs = $msVal * 1000000 + $usVal * 1000 + $nsVal;
        if ($fracTotalNs >= 1000000000) {
            $seconds += intdiv($fracTotalNs, 1000000000);
            $fracTotalNs %= 1000000000;
        }
        $fdVal = $df->get('[[FractionalDigits]]');
        $fdLimit = $fdVal instanceof JsNumber ? (int) $fdVal->value : null;
        $fracDigits = '';
        if ($fdLimit === null) {
            if ($fracTotalNs > 0) {
                $fracDigits = rtrim(
                    str_pad((string) $fracTotalNs, 9, '0', STR_PAD_LEFT),
                    '0',
                );
            }
        } elseif ($fdLimit > 0) {
            $fracDigits = substr(
                str_pad((string) $fracTotalNs, 9, '0', STR_PAD_LEFT),
                0,
                $fdLimit,
            );
        }
        // Sub-second values force the seconds slot to render even
        // when fractionalDigits is 0 (per spec note: "the
        // fractionalDigits option is not taken into account when
        // computing whether the seconds unit should appear in the
        // output"). The fractional STRING separately controls the
        // ".fff" trailing portion.
        $hasSubSecondValue = $fracTotalNs > 0;
        $showHours = $hours !== 0 || $hoursDisplay === 'always';
        $showMinutes = $minutes !== 0 || $minutesDisplay === 'always';
        $showSeconds = $seconds !== 0
            || $secondsDisplay === 'always'
            || $fracDigits !== ''
            || $hasSubSecondValue;
        if ($showSeconds) {
            $showMinutes = true;
        }
        $shownCount = (int) $showHours + (int) $showMinutes + (int) $showSeconds;
        if ($shownCount === 0) {
            return false;
        }
        if ($showHours && $showSeconds && !$showMinutes) {
            $showMinutes = true;
        }
        if (!$isFirstSegment) {
            $emit('literal', $listSep);
        }
        if ($signNeedsAttaching) {
            // The minus sign rides on the first clock unit that
            // actually appears (hour > minute > second).
            $signUnit = $showHours ? 'hour' : ($showMinutes ? 'minute' : 'second');
            $emit('minusSign', '-', $signUnit);
        }
        $emitted = false;
        if ($showHours) {
            $hourStr = $hourStyle === '2-digit'
                ? str_pad((string) $hours, 2, '0', STR_PAD_LEFT)
                : (string) $hours;
            $emit('integer', $hourStr, 'hour');
            $emitted = true;
        }
        if ($showMinutes) {
            if ($emitted) {
                // Per spec the inter-clock-unit colon is a bare
                // literal — it doesn't belong to either neighbour
                // unit, so omit the `unit` attribute.
                $emit('literal', ':', null);
            }
            $minStr = ($showHours || $showSeconds)
                ? str_pad((string) $minutes, 2, '0', STR_PAD_LEFT)
                : (string) $minutes;
            $emit('integer', $minStr, 'minute');
            $emitted = true;
        }
        if ($showSeconds) {
            if ($emitted) {
                $emit('literal', ':', null);
            }
            $secStr = ($showMinutes || $showHours)
                ? str_pad((string) $seconds, 2, '0', STR_PAD_LEFT)
                : (string) $seconds;
            $emit('integer', $secStr, 'second');
            if ($fracDigits !== '') {
                $emit('decimal', '.', 'second');
                $emit('fraction', $fracDigits, 'second');
            }
        }
        return true;
    }

    /**
     * Resolve the unit label string used in formatToParts. Pulls from
     * CLDR data via ResourceBundle so plural-awareness matches what
     * NumberFormat.formatToParts produces, then strips the leading
     * "{0}<sep>" placeholder so callers can splice in their own value.
     */
    private static function durationUnitLabelFor(
        string $unit,
        string $style,
        string $singular,
        string $locale = 'en',
        int $value = 0,
    ): string {
        $patterns = self::cldrDurationUnitPattern($locale, $singular, $style);
        if ($patterns !== []) {
            $plural = $value === 1 ? 'one' : 'other';
            $pat = $patterns[$plural] ?? $patterns['other'] ?? null;
            if ($pat !== null) {
                // Strip the "{0} " or "{0}" placeholder. Patterns look
                // like "{0} day", "{0} days", "{0}d", "{0} sec".
                $rest = ltrim(substr($pat, strpos($pat, '{0}') + 3));
                return $rest;
            }
        }
        static $fallbackShort = [
            'years' => 'yr', 'months' => 'mth', 'weeks' => 'wk',
            'days' => 'day', 'hours' => 'hr', 'minutes' => 'min',
            'seconds' => 'sec', 'milliseconds' => 'ms',
            'microseconds' => 'μs', 'nanoseconds' => 'ns',
        ];
        static $fallbackNarrow = [
            'years' => 'y', 'months' => 'm', 'weeks' => 'w',
            'days' => 'd', 'hours' => 'h', 'minutes' => 'm',
            'seconds' => 's', 'milliseconds' => 'ms',
            'microseconds' => 'μs', 'nanoseconds' => 'ns',
        ];
        static $fallbackLongPlural = [
            'years' => 'years', 'months' => 'months', 'weeks' => 'weeks',
            'days' => 'days', 'hours' => 'hours', 'minutes' => 'minutes',
            'seconds' => 'seconds', 'milliseconds' => 'milliseconds',
            'microseconds' => 'microseconds', 'nanoseconds' => 'nanoseconds',
        ];
        if ($style === 'long') {
            return $fallbackLongPlural[$unit] ?? $singular;
        }
        if ($style === 'narrow') {
            return $fallbackNarrow[$unit] ?? $singular;
        }
        return $fallbackShort[$unit] ?? $singular;
    }

    /**
     * Extract validated unit values from a duration object. Throws
     * the same TypeError / RangeError gates as format().
     *
     * @return array<string, float>
     */
    private static function durationToValues(JsObject $df, JsValue $duration): array
    {
        // String input: parse as ISO 8601 duration. Reuse the
        // record-to-object bridge so the rest of this function can
        // read property values uniformly.
        if ($duration instanceof JsString) {
            $parsed = self::parseIsoDurationString($duration->value);
            if ($parsed === null) {
                throw new RangeError('Invalid duration string');
            }
            $duration = self::durationRecordToObject($parsed);
        }
        if (
            $duration instanceof JsUndefined
            || $duration instanceof JsNull
            || $duration instanceof JsBoolean
            || $duration instanceof JsNumber
            || $duration instanceof \Phasis\Value\JsBigInt
            || $duration instanceof JsSymbol
        ) {
            throw new TypeError('Intl.DurationFormat requires a duration object');
        }
        if (!$duration instanceof JsObject) {
            throw new TypeError('Intl.DurationFormat requires a duration object');
        }
        $units = [
            'years', 'months', 'weeks', 'days',
            'hours', 'minutes', 'seconds',
            'milliseconds', 'microseconds', 'nanoseconds',
        ];
        // Per spec ToDurationRecord: when input is a Temporal.Duration,
        // bypass the prototype getters and read the internal slots
        // directly. Detected via the [[IsDuration]] brand.
        $isTemporalDuration = $duration->has('[[IsDuration]]');
        $readUnit = static function (string $u) use ($duration, $isTemporalDuration): JsValue {
            if ($isTemporalDuration) {
                return $duration->get('[[' . $u . ']]');
            }
            return $duration->get($u);
        };
        $hasAnyDurationProp = false;
        foreach ($units as $u) {
            if (!$readUnit($u) instanceof JsUndefined) {
                $hasAnyDurationProp = true;
                break;
            }
        }
        if (!$hasAnyDurationProp) {
            throw new TypeError('Duration record requires at least one duration property');
        }
        $values = [];
        $sign = 0;
        foreach ($units as $u) {
            $val = $readUnit($u);
            if ($val instanceof JsUndefined) {
                $values[$u] = 0.0;
                continue;
            }
            if (!$val instanceof JsNumber) {
                throw new TypeError("Duration {$u} must be a number");
            }
            $n = $val->value;
            if (is_nan($n) || !is_finite($n) || floor($n) !== $n) {
                throw new RangeError("Duration {$u} must be an integer");
            }
            if (in_array($u, ['years', 'months', 'weeks'], true) && abs($n) >= 4294967296.0) {
                throw new RangeError("Duration {$u} is out of range");
            }
            $values[$u] = $n;
            if ($n !== 0.0) {
                $thisSign = $n > 0 ? 1 : -1;
                if ($sign !== 0 && $thisSign !== $sign) {
                    throw new RangeError('Duration values must share a sign');
                }
                $sign = $thisSign;
            }
        }
        // IsValidDurationRecord step 16-17: total nanoseconds must
        // have abs < 2^53 × 10^9. Use BC math for precise summation
        // so the boundary case (max-time-duration just under 2^53
        // seconds) doesn't suffer from float rounding.
        if (function_exists('bcadd') && function_exists('bcmul')) {
            $totalNs = '0';
            $unitsToNs = [
                'days' => '86400000000000',
                'hours' => '3600000000000',
                'minutes' => '60000000000',
                'seconds' => '1000000000',
                'milliseconds' => '1000000',
                'microseconds' => '1000',
                'nanoseconds' => '1',
            ];
            foreach ($unitsToNs as $u => $factor) {
                $val = $values[$u] ?? 0.0;
                if ($val !== 0.0) {
                    $valStr = sprintf('%.0F', $val);
                    $totalNs = bcadd($totalNs, bcmul($valStr, $factor, 0), 0);
                }
            }
            $abs = ltrim($totalNs, '-');
            if (bccomp($abs, '9007199254740992000000000', 0) >= 0) {
                throw new RangeError('Duration normalized seconds out of range');
            }
        }
        return $values;
    }

    /**
     * @param array<string, float> $values
     */
    private static function durationOverallSign(array $values): int
    {
        foreach ($values as $n) {
            if ($n < 0) {
                return -1;
            }
            if ($n > 0) {
                return 1;
            }
        }
        return 0;
    }

    private static function durationFormatRender(JsObject $df, JsValue $duration): string
    {
        // Spec ToDurationRecord: a string input is parsed as an
        // ISO 8601 duration ("P[nY][nM][nW][nD][T[nH][nM][nS]]");
        // anything else non-Object throws TypeError.
        if ($duration instanceof JsString) {
            $parsed = self::parseIsoDurationString($duration->value);
            if ($parsed === null) {
                throw new RangeError('Invalid duration string');
            }
            $duration = self::durationRecordToObject($parsed);
        }
        if (
            $duration instanceof JsUndefined
            || $duration instanceof JsNull
            || $duration instanceof JsBoolean
            || $duration instanceof JsNumber
            || $duration instanceof \Phasis\Value\JsBigInt
            || $duration instanceof JsSymbol
        ) {
            throw new TypeError('Intl.DurationFormat.format requires a duration object');
        }
        if (!$duration instanceof JsObject) {
            throw new TypeError('Intl.DurationFormat.format requires a duration object');
        }
        $units = [
            'years' => 'year', 'months' => 'month', 'weeks' => 'week',
            'days' => 'day', 'hours' => 'hour', 'minutes' => 'minute',
            'seconds' => 'second', 'milliseconds' => 'millisecond',
            'microseconds' => 'microsecond', 'nanoseconds' => 'nanosecond',
        ];
        // ToDurationRecord: when input is a Temporal.Duration, bypass
        // the prototype's getters and read internal slots directly so
        // userland prototype tampering can't observe the read.
        $isTemporalDuration = $duration->has('[[IsDuration]]');
        $readUnit = static function (string $u) use ($duration, $isTemporalDuration): JsValue {
            if ($isTemporalDuration) {
                return $duration->get('[[' . $u . ']]');
            }
            return $duration->get($u);
        };
        // ToDurationRecord step 7-8: at least one duration property
        // must be present. A plain {} or {year: 1} (singular) throws
        // TypeError because no recognised unit keys are set.
        $hasAnyDurationProp = false;
        foreach (array_keys($units) as $u) {
            if (!$readUnit($u) instanceof JsUndefined) {
                $hasAnyDurationProp = true;
                break;
            }
        }
        if (!$hasAnyDurationProp) {
            throw new TypeError('Duration record requires at least one duration property');
        }
        // ToDurationRecord step 22 + IsValidDurationRecord:
        //   - Each unit value must be an integer (NaN/Infinity rejected).
        //   - All values must share a sign (or be zero).
        //   - years/months/weeks limited to abs < 2^32.
        $values = [];
        $sign = 0;
        foreach ($units as $u => $_singular) {
            $val = $readUnit($u);
            if ($val instanceof JsUndefined) {
                $values[$u] = 0.0;
                continue;
            }
            if (!$val instanceof JsNumber) {
                throw new TypeError("Duration {$u} must be a number");
            }
            $n = $val->value;
            if (is_nan($n) || !is_finite($n) || floor($n) !== $n) {
                throw new RangeError("Duration {$u} must be an integer");
            }
            if (in_array($u, ['years', 'months', 'weeks'], true) && abs($n) >= 4294967296.0) {
                throw new RangeError("Duration {$u} is out of range");
            }
            $values[$u] = $n;
            if ($n !== 0.0) {
                $thisSign = $n > 0 ? 1 : -1;
                if ($sign !== 0 && $thisSign !== $sign) {
                    throw new RangeError('Duration values must share a sign');
                }
                $sign = $thisSign;
            }
        }
        // IsValidDurationRecord step 16-17: total nanoseconds must
        // have abs < 2^53 × 10^9. Use BC math for precise summation
        // so the boundary case (max-time-duration just under 2^53
        // seconds) doesn't suffer from float rounding.
        if (function_exists('bcadd') && function_exists('bcmul')) {
            $totalNs = '0';
            $unitsToNs = [
                'days' => '86400000000000',
                'hours' => '3600000000000',
                'minutes' => '60000000000',
                'seconds' => '1000000000',
                'milliseconds' => '1000000',
                'microseconds' => '1000',
                'nanoseconds' => '1',
            ];
            foreach ($unitsToNs as $u => $factor) {
                $val = $values[$u] ?? 0.0;
                if ($val !== 0.0) {
                    $valStr = sprintf('%.0F', $val);
                    $totalNs = bcadd($totalNs, bcmul($valStr, $factor, 0), 0);
                }
            }
            $abs = ltrim($totalNs, '-');
            if (bccomp($abs, '9007199254740992000000000', 0) >= 0) {
                throw new RangeError('Duration normalized seconds out of range');
            }
        }
        $segments = [];
        $isFirstSegment = true;
        $signNeedsAttaching = $sign < 0;
        $locale = self::extractInternalString($df, '[[Locale]]', 'en');
        $overallStyle = self::extractInternalString($df, '[[Style]]', 'short');
        // Detect a "clock" run: when hours and minutes (or seconds)
        // share a numeric / 2-digit style, render them as
        // "H:MM[:SS]" with sub-second values fused into a fraction.
        $clockSkip = self::detectClockUnitsToSkip($df);
        $clockEmitAt = $clockSkip !== [] ? $clockSkip[0] : null;
        // Fractional sub-second absorber: when the unit immediately
        // after seconds, milliseconds, or microseconds has style
        // "numeric", that smaller unit (and everything below) is
        // absorbed into the current unit as a fractional value.
        $absorber = self::durationFractionalAbsorber($df, $clockSkip);
        foreach ($units as $u => $singular) {
            if (in_array($u, $clockSkip, true)) {
                if ($u === $clockEmitAt) {
                    // The clock segment appears at the position of
                    // its first unit (hour or minute).
                    $clockSeg = self::renderClockSegment(
                        $df,
                        $values,
                        $isFirstSegment && $signNeedsAttaching,
                        $clockSkip,
                    );
                    if ($clockSeg !== null) {
                        $segments[] = $clockSeg;
                        $signNeedsAttaching = false;
                        $isFirstSegment = false;
                    }
                }
                continue;
            }
            // Skip units strictly below the absorber: they're folded
            // into the absorber's fractional digits.
            if ($absorber !== null && self::isBelowAbsorber($u, $absorber)) {
                continue;
            }
            $n = $values[$u];
            $displaySlot = '[[' . ucfirst($u) . 'Display]]';
            $display = self::extractInternalString($df, $displaySlot, 'auto');
            $unitSlot = '[[' . ucfirst($u) . ']]';
            $unitStyle = self::extractInternalString($df, $unitSlot, 'short');
            $renderedSign = '';
            if ($isFirstSegment && $signNeedsAttaching) {
                $renderedSign = '-';
            }
            // Absorber: render integer + fractional sub-units in one
            // segment. When the absorber's own style is numeric or
            // 2-digit, render as a bare decimal (no unit pattern,
            // no grouping) per FormatNumericSeconds spec. Otherwise
            // wrap in the CLDR unit pattern.
            if ($u === $absorber) {
                [$intStr, $fracStr] = self::durationAbsorberDecimal($df, $values, $u);
                if ($intStr === '0' && $fracStr === '' && $display === 'auto') {
                    continue;
                }
                if ($unitStyle === 'numeric' || $unitStyle === '2-digit') {
                    $padded = $unitStyle === '2-digit' && strlen($intStr) < 2
                        ? str_pad($intStr, 2, '0', STR_PAD_LEFT)
                        : $intStr;
                    $segments[] = $renderedSign . $padded . $fracStr;
                } else {
                    $useGrouping = ($unitStyle !== 'narrow');
                    $segments[] = self::formatDurationSegment(
                        $intStr,
                        $u,
                        $singular,
                        $unitStyle,
                        $locale,
                        $useGrouping,
                        $fracStr,
                        $renderedSign,
                    );
                }
                if ($renderedSign !== '') {
                    $signNeedsAttaching = false;
                }
                $isFirstSegment = false;
                continue;
            }
            // Per spec: zero values are dropped when display is
            // "auto"; always rendered when display is "always".
            if ($n === 0.0 && $display !== 'always') {
                continue;
            }
            // Spec: when the overall duration is negative, the
            // leading rendered segment carries the "-" prefix and
            // every subsequent segment is rendered as the absolute
            // value. A "0 hours" segment from display:"always"
            // becomes "-0 hours" so the sign isn't lost.
            $intStr = self::floatToIntegerString(abs($n));
            // In digital base style, non-clock units default to
            // "short" but the visible label is plural-aware (V8
            // behavior). For "long"/"short"/"narrow" base styles,
            // the unit-level resolved style controls rendering.
            $segStyle = $unitStyle;
            if (
                $overallStyle === 'digital'
                && in_array($u, ['years', 'months', 'weeks', 'days'], true)
                && $unitStyle === 'short'
            ) {
                // "short" CLDR data for English non-sub-second time
                // units is already plural-aware ("day"/"days"), so
                // standard short rendering produces the correct form.
                $segStyle = 'short';
            }
            $useGrouping = ($segStyle !== 'narrow');
            $seg = self::formatDurationSegment(
                $intStr,
                $u,
                $singular,
                $segStyle,
                $locale,
                $useGrouping,
                '',
                $renderedSign,
            );
            $segments[] = $seg;
            if ($renderedSign !== '') {
                $signNeedsAttaching = false;
            }
            $isFirstSegment = false;
        }
        if (empty($segments)) {
            return '';
        }
        // The list separator depends on the overall style: "narrow"
        // uses a single space between segments, the rest use a comma
        // followed by a space, with a locale-specific connector
        // before the last item (Spanish "y", etc.).
        if ($overallStyle === 'narrow') {
            return implode(' ', $segments);
        }
        $count = count($segments);
        if ($count === 1) {
            return $segments[0];
        }
        // The locale connector ("y", "et", "und") is only used in
        // "long" style per CLDR; "short" / "default" use plain
        // comma-space separators.
        $listConnector = $overallStyle === 'long'
            ? self::durationFormatListConnector($locale)
            : null;
        if ($listConnector !== null) {
            $head = array_slice($segments, 0, $count - 1);
            $tail = $segments[$count - 1];
            return implode(', ', $head) . $listConnector . $tail;
        }
        return implode(', ', $segments);
    }

    /**
     * Detect the fractional sub-second absorber. Walks seconds → ms
     * → us; returns the first one whose next-unit's resolved style
     * is "numeric" (per the spec's PartitionDurationFormatPattern
     * AddFractionalDigits branch). Returns null when no absorption
     * applies, or when the seconds slot is part of a clock run
     * (then renderClockSegment handles fusion).
     *
     * @param list<string> $clockSkip
     */
    private static function durationFractionalAbsorber(JsObject $df, array $clockSkip): ?string
    {
        $secondsInClock = in_array('seconds', $clockSkip, true);
        $msStyle = self::extractInternalString($df, '[[Milliseconds]]', 'short');
        $usStyle = self::extractInternalString($df, '[[Microseconds]]', 'short');
        $nsStyle = self::extractInternalString($df, '[[Nanoseconds]]', 'short');
        if (!$secondsInClock && $msStyle === 'numeric') {
            return 'seconds';
        }
        if ($usStyle === 'numeric') {
            return 'milliseconds';
        }
        if ($nsStyle === 'numeric') {
            return 'microseconds';
        }
        return null;
    }

    private static function isBelowAbsorber(string $unit, string $absorber): bool
    {
        $rank = [
            'years' => 0, 'months' => 1, 'weeks' => 2, 'days' => 3,
            'hours' => 4, 'minutes' => 5, 'seconds' => 6,
            'milliseconds' => 7, 'microseconds' => 8, 'nanoseconds' => 9,
        ];
        if (!isset($rank[$unit]) || !isset($rank[$absorber])) {
            return false;
        }
        return $rank[$unit] > $rank[$absorber];
    }

    /**
     * Build the absorber's "<int>.<frac>" decimal from the duration
     * values. Uses BC math when available so values approaching
     * Number.MAX_SAFE_INTEGER don't lose precision.
     *
     * @param array<string, float> $values
     * @return array{0: string, 1: string} [intPart, ".fff" or ""]
     */
    private static function durationAbsorberDecimal(JsObject $df, array $values, string $absorber): array
    {
        $exponent = match ($absorber) {
            'seconds' => 9,
            'milliseconds' => 6,
            'microseconds' => 3,
            default => 0,
        };
        if ($exponent === 0) {
            return [self::floatToIntegerString(abs($values[$absorber] ?? 0.0)), ''];
        }
        // Build total nanoseconds (relative to the absorber unit).
        // For absorber=seconds: scale-by-10^9 (full ns from sub-units).
        // For absorber=ms:      scale-by-10^6 (us, ns added).
        // For absorber=us:      scale-by-10^3 (ns added).
        if (function_exists('bcadd') && function_exists('bcmul') && function_exists('bcdiv') && function_exists('bcmod')) {
            $total = '0';
            $base = self::floatToIntegerString(abs($values[$absorber] ?? 0.0));
            $total = bcmul($base, bcpow('10', (string) $exponent, 0), 0);
            if ($absorber === 'seconds') {
                $ms = self::floatToIntegerString(abs($values['milliseconds'] ?? 0.0));
                $us = self::floatToIntegerString(abs($values['microseconds'] ?? 0.0));
                $ns = self::floatToIntegerString(abs($values['nanoseconds'] ?? 0.0));
                $total = bcadd($total, bcmul($ms, '1000000', 0), 0);
                $total = bcadd($total, bcmul($us, '1000', 0), 0);
                $total = bcadd($total, $ns, 0);
            } elseif ($absorber === 'milliseconds') {
                $us = self::floatToIntegerString(abs($values['microseconds'] ?? 0.0));
                $ns = self::floatToIntegerString(abs($values['nanoseconds'] ?? 0.0));
                $total = bcadd($total, bcmul($us, '1000', 0), 0);
                $total = bcadd($total, $ns, 0);
            } else {
                $ns = self::floatToIntegerString(abs($values['nanoseconds'] ?? 0.0));
                $total = bcadd($total, $ns, 0);
            }
            $divisor = bcpow('10', (string) $exponent, 0);
            $q = bcdiv($total, $divisor, 0);
            $r = bcmod($total, $divisor);
            $rPadded = str_pad($r, $exponent, '0', STR_PAD_LEFT);
        } else {
            $base = (int) abs($values[$absorber] ?? 0.0);
            $sub = 0;
            if ($absorber === 'seconds') {
                $ms = (int) abs($values['milliseconds'] ?? 0.0);
                $us = (int) abs($values['microseconds'] ?? 0.0);
                $ns = (int) abs($values['nanoseconds'] ?? 0.0);
                $sub = $ms * 1000000 + $us * 1000 + $ns;
            } elseif ($absorber === 'milliseconds') {
                $us = (int) abs($values['microseconds'] ?? 0.0);
                $ns = (int) abs($values['nanoseconds'] ?? 0.0);
                $sub = $us * 1000 + $ns;
            } else {
                $sub = (int) abs($values['nanoseconds'] ?? 0.0);
            }
            $cap = (int) (10 ** $exponent);
            $base += intdiv($sub, $cap);
            $sub %= $cap;
            $q = (string) $base;
            $rPadded = str_pad((string) $sub, $exponent, '0', STR_PAD_LEFT);
        }
        // Apply [[FractionalDigits]] override or default {0..9, trunc}.
        $fdSlot = $df->get('[[FractionalDigits]]');
        $fdLimit = $fdSlot instanceof JsNumber ? (int) $fdSlot->value : null;
        if ($fdLimit !== null) {
            // Pad to fdLimit with zeros, then truncate to that count.
            $rPadded = str_pad(substr($rPadded, 0, $fdLimit), $fdLimit, '0', STR_PAD_RIGHT);
            $fracStr = $fdLimit > 0 ? '.' . $rPadded : '';
        } else {
            $rTrimmed = rtrim($rPadded, '0');
            $fracStr = $rTrimmed === '' ? '' : '.' . $rTrimmed;
        }
        return [$q, $fracStr];
    }

    /**
     * Split a decimal integer string into NumberFormat-style parts
     * (alternating integer and group literal parts) suitable for
     * formatToParts emission.
     *
     * @return list<array{type: string, value: string}>
     */
    private static function splitIntegerWithGrouping(string $intStr, string $locale): array
    {
        $sign = '';
        if ($intStr !== '' && $intStr[0] === '-') {
            $sign = '-';
            $intStr = substr($intStr, 1);
        }
        $len = strlen($intStr);
        if ($len <= 3) {
            return [['type' => 'integer', 'value' => $sign . $intStr]];
        }
        $sep = self::localeGroupingSeparator($locale);
        $first = $len % 3;
        $parts = [];
        if ($first > 0) {
            $parts[] = ['type' => 'integer', 'value' => $sign . substr($intStr, 0, $first)];
        }
        for ($i = $first; $i < $len; $i += 3) {
            if ($parts !== []) {
                $parts[] = ['type' => 'group', 'value' => $sep];
            } elseif ($sign !== '') {
                // Sign attaches to the first integer chunk.
                $parts[] = ['type' => 'integer', 'value' => $sign . substr($intStr, $i, 3)];
                continue;
            }
            $parts[] = ['type' => 'integer', 'value' => substr($intStr, $i, 3)];
        }
        return $parts;
    }

    /**
     * Convert a non-negative integer-valued float to its decimal
     * string form without scientific notation, even for values
     * above 2^53.
     */
    private static function floatToIntegerString(float $f): string
    {
        if ($f === 0.0) {
            return '0';
        }
        return sprintf('%.0F', $f);
    }

    /**
     * Locale-specific connector inserted before the last list item
     * in a DurationFormat output. Mirrors CLDR's listPatterns "end"
     * pattern. Returns null for locales that use a plain comma-space
     * (English).
     */
    private static function durationFormatListConnector(string $locale): ?string
    {
        $lang = strtolower(strtok($locale, '-_'));
        return match ($lang) {
            'es', 'gl', 'ca', 'eu' => ' y ',
            'pt' => ' e ',
            'fr' => ' et ',
            'de' => ' und ',
            'it' => ' e ',
            'nl' => ' en ',
            default => null,
        };
    }

    /**
     * Determine which units belong to the colon-joined clock
     * segment. Walks from seconds upward through the clock units,
     * keeping any that have numeric / 2-digit styles. Returns the
     * list of units (including milliseconds/microseconds/nanoseconds
     * when seconds is numeric) that the regular per-unit emission
     * loop should skip.
     *
     * @return list<string>
     */
    private static function detectClockUnitsToSkip(JsObject $df): array
    {
        $clockUnits = ['hours', 'minutes', 'seconds'];
        $isNumeric = static function (string $style): bool {
            return $style === 'numeric' || $style === '2-digit';
        };
        // Walk seconds → minutes → hours, accepting consecutive
        // numeric/2-digit units. Stop on the first non-numeric one
        // (everything above it stays out of the clock).
        $accepted = [];
        for ($i = count($clockUnits) - 1; $i >= 0; $i--) {
            $u = $clockUnits[$i];
            $unitSlot = '[[' . ucfirst($u) . ']]';
            $st = self::extractInternalString($df, $unitSlot, 'short');
            if (!$isNumeric($st)) {
                break;
            }
            $accepted[] = $u;
        }
        // Need at least two clock units to justify the colon-joined
        // form; a lone numeric "seconds" alone renders as a normal
        // segment.
        if (count($accepted) < 2) {
            return [];
        }
        $accepted = array_reverse($accepted);
        // Sub-second slots only join the clock when seconds is in
        // the run (the spec ties their fractional tail to seconds).
        if (in_array('seconds', $accepted, true)) {
            $accepted = array_merge(
                $accepted,
                ['milliseconds', 'microseconds', 'nanoseconds'],
            );
        }
        return $accepted;
    }

    /**
     * Render the H:MM:SS[.fff] clock-style segment used by the
     * "digital" DurationFormat style. Returns null when the
     * resulting segment would be empty (all clock units are zero
     * with display:"auto").
     *
     * @param array<string, float> $values
     * @param array<mixed> $clockSkip
     */
    private static function renderClockSegment(
        JsObject $df,
        array $values,
        bool $signNeedsAttaching,
        ?array $clockSkip = null,
    ): ?string {
        $clockSkip ??= ['hours', 'minutes', 'seconds'];
        $hourInClock = in_array('hours', $clockSkip, true);
        $minuteInClock = in_array('minutes', $clockSkip, true);
        $secondInClock = in_array('seconds', $clockSkip, true);
        $hoursStr = $hourInClock ? self::floatToIntegerString(abs($values['hours'] ?? 0.0)) : '0';
        $minutesStr = $minuteInClock ? self::floatToIntegerString(abs($values['minutes'] ?? 0.0)) : '0';
        $secondsStr = $secondInClock ? self::floatToIntegerString(abs($values['seconds'] ?? 0.0)) : '0';
        $hoursDisplay = self::extractInternalString($df, '[[HoursDisplay]]', 'auto');
        $minutesDisplay = self::extractInternalString($df, '[[MinutesDisplay]]', 'auto');
        $secondsDisplay = self::extractInternalString($df, '[[SecondsDisplay]]', 'auto');
        $hourStyle = self::extractInternalString($df, '[[Hours]]', 'numeric');
        // Combine sub-second units into total nanoseconds and carry
        // the integer-second portion up into the seconds slot, so
        // {seconds:56, milliseconds:1234567} renders as "1290.567"
        // not "56.1234567". BC math keeps precision for values
        // approaching Number.MAX_SAFE_INTEGER × 10^6.
        $msStr = self::floatToIntegerString(abs($values['milliseconds'] ?? 0.0));
        $usStr = self::floatToIntegerString(abs($values['microseconds'] ?? 0.0));
        $nsStr = self::floatToIntegerString(abs($values['nanoseconds'] ?? 0.0));
        $hasBc = function_exists('bcadd') && function_exists('bcmul') && function_exists('bcdiv') && function_exists('bcmod');
        if ($hasBc) {
            $fracTotalNs = bcadd(bcmul($msStr, '1000000', 0), bcmul($usStr, '1000', 0), 0);
            $fracTotalNs = bcadd($fracTotalNs, $nsStr, 0);
            if (bccomp($fracTotalNs, '1000000000', 0) >= 0) {
                $extra = bcdiv($fracTotalNs, '1000000000', 0);
                $fracTotalNs = bcmod($fracTotalNs, '1000000000');
                $secondsStr = bcadd($secondsStr, $extra, 0);
            }
            $fracTotalNsStr = $fracTotalNs;
        } else {
            $msVal = (int) (float) $msStr;
            $usVal = (int) (float) $usStr;
            $nsVal = (int) (float) $nsStr;
            $tot = $msVal * 1000000 + $usVal * 1000 + $nsVal;
            if ($tot >= 1000000000) {
                $extra = intdiv($tot, 1000000000);
                $tot %= 1000000000;
                $secondsStr = (string) ((int) $secondsStr + $extra);
            }
            $fracTotalNsStr = (string) $tot;
        }
        $fdVal = $df->get('[[FractionalDigits]]');
        $fdLimit = $fdVal instanceof JsNumber ? (int) $fdVal->value : null;
        $fracStr = '';
        $padded = str_pad($fracTotalNsStr, 9, '0', STR_PAD_LEFT);
        if ($fdLimit === null) {
            // Default: render only as many sub-second digits as needed.
            $trimmed = rtrim($padded, '0');
            if ($trimmed !== '') {
                $fracStr = '.' . $trimmed;
            }
        } elseif ($fdLimit > 0) {
            $fracStr = '.' . substr($padded, 0, $fdLimit);
        }
        $hasSubSecondNonZero = $fracTotalNsStr !== '0';
        // Decide whether each clock unit shows up. Each unit's
        // display flag is independent; we then bridge gaps so
        // colon-joined runs render contiguously. A non-zero
        // sub-second value forces seconds even when fractionalDigits
        // is 0 (per spec note: fractionalDigits doesn't gate the
        // seconds slot's appearance).
        $isNonZero = static fn(string $s): bool => $s !== '0' && $s !== '' && $s !== '-0';
        $hoursNonZero = $isNonZero($hoursStr);
        $minutesNonZero = $isNonZero($minutesStr);
        $secondsNonZero = $isNonZero($secondsStr);
        $showHours = $hourInClock && ($hoursNonZero || $hoursDisplay === 'always');
        $showMinutes = $minuteInClock
            && ($minutesNonZero || $minutesDisplay === 'always');
        $showSeconds = $secondInClock
            && ($secondsNonZero || $secondsDisplay === 'always'
                || $fracStr !== ''
                || $hasSubSecondNonZero);
        // V8 / spec: when seconds show, minutes appear too (for
        // ":SS" form). Then if minutes show, hours follow only if
        // hours has a non-zero value or always-display.
        if ($showSeconds && !$showMinutes && $minutesDisplay !== 'auto') {
            $showMinutes = true;
        }
        if ($showSeconds) {
            // The spec emits ":SS" form whenever seconds appear, so
            // promote minutes regardless of its display:auto flag.
            $showMinutes = true;
        }
        $shownCount = (int) $showHours + (int) $showMinutes + (int) $showSeconds;
        if ($shownCount === 0) {
            return null;
        }
        // Single-unit clock renders bare (no colons) for fixtures
        // like "{hours: 0}" with display:always returning just "0".
        if ($shownCount === 1) {
            $signPrefix = $signNeedsAttaching ? '-' : '';
            if ($showHours) {
                return $signPrefix . ($hourStyle === '2-digit'
                    ? str_pad($hoursStr, 2, '0', STR_PAD_LEFT)
                    : $hoursStr);
            }
            if ($showMinutes) {
                return $signPrefix . $minutesStr;
            }
            return $signPrefix . $secondsStr . $fracStr;
        }
        // Promote intermediate zero units so the colon-joined run
        // doesn't have gaps (e.g. h+s shown but not m → fill m).
        if ($showHours && $showSeconds && !$showMinutes) {
            $showMinutes = true;
        }
        $parts = [];
        if ($showHours) {
            $parts[] = $hourStyle === '2-digit'
                ? str_pad($hoursStr, 2, '0', STR_PAD_LEFT)
                : $hoursStr;
        }
        if ($showMinutes) {
            // Pad minutes when its own style is 2-digit OR when a
            // higher clock unit (hours) precedes it.
            $minStyle = self::extractInternalString($df, '[[Minutes]]', 'short');
            $padMin = $showHours || $minStyle === '2-digit';
            $parts[] = $padMin && strlen($minutesStr) < 2
                ? str_pad($minutesStr, 2, '0', STR_PAD_LEFT)
                : $minutesStr;
        }
        if ($showSeconds) {
            $secStyle = self::extractInternalString($df, '[[Seconds]]', 'short');
            $padSec = $showMinutes || $showHours || $secStyle === '2-digit';
            $secondsRendered = $padSec && strlen($secondsStr) < 2
                ? str_pad($secondsStr, 2, '0', STR_PAD_LEFT)
                : $secondsStr;
            $parts[] = $secondsRendered . $fracStr;
        }
        $rendered = implode(':', $parts);
        return ($signNeedsAttaching ? '-' : '') . $rendered;
    }

    private static function formatDurationSegment(
        string $intStr,
        string $unit,
        string $singular,
        string $unitStyle,
        string $locale,
        bool $useGrouping,
        string $fracStr = '',
        string $signPrefix = '',
    ): string {
        // Insert grouping separators on the integer portion when the
        // segment is non-clock and a thousands separator applies for
        // the locale. Clock segments (rendered numerically) bypass
        // this via $useGrouping=false.
        $intDisplay = $useGrouping
            ? self::applyDecimalGrouping($intStr, $locale)
            : $intStr;
        $numberStr = $signPrefix . $intDisplay . $fracStr;
        $patterns = self::cldrDurationUnitPattern($locale, $singular, $unitStyle);
        if ($patterns !== []) {
            $plural = $intStr === '1' ? 'one' : 'other';
            $pat = $patterns[$plural] ?? $patterns['other'] ?? null;
            if ($pat !== null) {
                return str_replace('{0}', $numberStr, $pat);
            }
        }
        // Fallback English labels keyed by style. CLDR data should
        // normally cover every locale we care about; this branch
        // protects against missing ResourceBundle data.
        static $fallbackShort = [
            'years' => 'yr', 'months' => 'mth', 'weeks' => 'wk',
            'days' => 'day', 'hours' => 'hr', 'minutes' => 'min',
            'seconds' => 'sec', 'milliseconds' => 'ms',
            'microseconds' => 'μs', 'nanoseconds' => 'ns',
        ];
        static $fallbackNarrow = [
            'years' => 'y', 'months' => 'm', 'weeks' => 'w',
            'days' => 'd', 'hours' => 'h', 'minutes' => 'm',
            'seconds' => 's', 'milliseconds' => 'ms',
            'microseconds' => 'μs', 'nanoseconds' => 'ns',
        ];
        static $fallbackLongSingular = [
            'years' => 'year', 'months' => 'month', 'weeks' => 'week',
            'days' => 'day', 'hours' => 'hour', 'minutes' => 'minute',
            'seconds' => 'second', 'milliseconds' => 'millisecond',
            'microseconds' => 'microsecond', 'nanoseconds' => 'nanosecond',
        ];
        static $fallbackLongPlural = [
            'years' => 'years', 'months' => 'months', 'weeks' => 'weeks',
            'days' => 'days', 'hours' => 'hours', 'minutes' => 'minutes',
            'seconds' => 'seconds', 'milliseconds' => 'milliseconds',
            'microseconds' => 'microseconds', 'nanoseconds' => 'nanoseconds',
        ];
        $isOne = $intStr === '1';
        if ($unitStyle === 'long') {
            $label = $isOne
                ? ($fallbackLongSingular[$unit] ?? $singular)
                : ($fallbackLongPlural[$unit] ?? $singular);
            return $numberStr . ' ' . $label;
        }
        if ($unitStyle === 'narrow') {
            return $numberStr . ($fallbackNarrow[$unit] ?? $singular);
        }
        return $numberStr . ' ' . ($fallbackShort[$unit] ?? $singular);
    }

    /**
     * CLDR duration unit pattern lookup via ResourceBundle. Returns
     * a map of plural category to "{0}<sep><label>" pattern.
     *
     * @return array<string, string>
     */
    private static function cldrDurationUnitPattern(string $locale, string $unit, string $display): array
    {
        return self::cldrUnitPatternForCategory($locale, 'duration', $unit, $display);
    }

    /**
     * Generic CLDR unit pattern lookup. Maps the unit (singular,
     * dash-separated as JS sees it, e.g. "kilometer-per-hour") to
     * its CLDR category and queries ResourceBundle.
     *
     * @return array<string, string>
     */
    private static function cldrUnitPattern(string $locale, string $unit, string $display): array
    {
        $cat = self::cldrUnitCategoryFor($unit);
        if ($cat === null) {
            return [];
        }
        return self::cldrUnitPatternForCategory($locale, $cat, $unit, $display);
    }

    /**
     * @return array<string, string>
     */
    private static function cldrUnitPatternForCategory(
        string $locale,
        string $category,
        string $unit,
        string $display,
    ): array {
        if (!class_exists('ResourceBundle', false)) {
            return [];
        }
        $key = match ($display) {
            'narrow' => 'unitsNarrow',
            'long' => 'units',
            default => 'unitsShort',
        };
        // CLDR allows entries to inherit from root when missing in
        // the target locale. PHP's ResourceBundle nested get() does
        // NOT follow that fallback chain automatically, so we walk
        // the locale's parent chain explicitly down to "root".
        $tries = self::cldrLocaleChain($locale);
        foreach ($tries as $loc) {
            $patterns = self::cldrUnitPatternsAtLocale($loc, $key, $category, $unit);
            if ($patterns !== []) {
                return $patterns;
            }
        }
        return [];
    }

    /**
     * @return list<string>
     */
    private static function cldrLocaleChain(string $locale): array
    {
        $icuLocale = str_replace('-', '_', $locale);
        $chain = [$icuLocale];
        $current = $icuLocale;
        while (true) {
            $pos = strrpos($current, '_');
            if ($pos === false) {
                break;
            }
            $current = substr($current, 0, $pos);
            $chain[] = $current;
        }
        if (!in_array('root', $chain, true)) {
            $chain[] = 'root';
        }
        return $chain;
    }

    /**
     * @return array<string, string>
     */
    private static function cldrUnitPatternsAtLocale(
        string $icuLocale,
        string $sectionKey,
        string $category,
        string $unit,
    ): array {
        try {
            $bundle = \ResourceBundle::create($icuLocale, 'ICUDATA-unit', false);
        } catch (\Throwable) {
            return [];
        }
        if ($bundle === null) {
            return [];
        }
        $unitsBundle = $bundle->get($sectionKey);
        if (!$unitsBundle instanceof \ResourceBundle) {
            return [];
        }
        $catBundle = $unitsBundle->get($category);
        if (!$catBundle instanceof \ResourceBundle) {
            return [];
        }
        $unitBundle = $catBundle->get($unit);
        if (!$unitBundle instanceof \ResourceBundle) {
            return [];
        }
        $patterns = [];
        foreach (['zero', 'one', 'two', 'few', 'many', 'other'] as $plural) {
            $p = $unitBundle->get($plural);
            if (is_string($p)) {
                $patterns[$plural] = $p;
            }
        }
        return $patterns;
    }

    private static function cldrUnitCategoryFor(string $unit): ?string
    {
        static $map = [
            'second' => 'duration', 'minute' => 'duration', 'hour' => 'duration',
            'day' => 'duration', 'week' => 'duration', 'month' => 'duration',
            'year' => 'duration', 'millisecond' => 'duration',
            'microsecond' => 'duration', 'nanosecond' => 'duration',
            'meter' => 'length', 'centimeter' => 'length', 'millimeter' => 'length',
            'kilometer' => 'length', 'mile' => 'length', 'inch' => 'length',
            'foot' => 'length', 'yard' => 'length',
            'mile-scandinavian' => 'length',
            'gram' => 'mass', 'kilogram' => 'mass', 'pound' => 'mass',
            'ounce' => 'mass', 'stone' => 'mass',
            'liter' => 'volume', 'milliliter' => 'volume', 'gallon' => 'volume',
            'fluid-ounce' => 'volume',
            'celsius' => 'temperature', 'fahrenheit' => 'temperature',
            'degree' => 'angle',
            'acre' => 'area', 'hectare' => 'area',
            'bit' => 'digital', 'byte' => 'digital',
            'kilobit' => 'digital', 'kilobyte' => 'digital',
            'megabit' => 'digital', 'megabyte' => 'digital',
            'gigabit' => 'digital', 'gigabyte' => 'digital',
            'terabit' => 'digital', 'terabyte' => 'digital',
            'petabyte' => 'digital',
            'percent' => 'concentr',
        ];
        return $map[$unit] ?? null;
    }

    /**
     * Apply locale-aware thousand grouping to an integer decimal
     * string. Used by DurationFormat when rendering non-clock unit
     * segments with grouping enabled.
     */
    private static function applyDecimalGrouping(string $intStr, string $locale): string
    {
        $sign = '';
        if ($intStr !== '' && $intStr[0] === '-') {
            $sign = '-';
            $intStr = substr($intStr, 1);
        }
        $len = strlen($intStr);
        if ($len <= 3) {
            return $sign . $intStr;
        }
        $sep = self::localeGroupingSeparator($locale);
        $first = $len % 3;
        $parts = [];
        if ($first > 0) {
            $parts[] = substr($intStr, 0, $first);
        }
        for ($i = $first; $i < $len; $i += 3) {
            $parts[] = substr($intStr, $i, 3);
        }
        return $sign . implode($sep, $parts);
    }
}
