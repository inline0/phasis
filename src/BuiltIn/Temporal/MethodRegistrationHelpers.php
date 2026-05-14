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
 * Temporal helper section (MethodRegistrationHelpers). Composed into TemporalObject
 * via `use Temporal\MethodRegistrationHelpers;`. `self::` references resolve into
 * the composing class.
 */
trait MethodRegistrationHelpers
{
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
