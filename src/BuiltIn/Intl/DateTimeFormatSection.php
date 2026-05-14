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
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;
use Phasis\BuiltIn\SymbolConstructor;

/**
 * Intl.DateTimeFormat section. Composed into IntlObject via
 * `use Intl\DateTimeFormatSection;`. `self::` references resolve into
 * the composing class so cross-section helpers continue to work.
 */
trait DateTimeFormatSection
{
    // ---------------------------------------------------------------
    // Intl.DateTimeFormat
    // ---------------------------------------------------------------

    private static function installDateTimeFormat(JsObject $intl): void
    {
        $proto = new JsObject();

        $constructor = JsFunction::fromCallable(
            'DateTimeFormat',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                $localesArg = $args[0] ?? JsUndefined::instance();
                $optionsArg = $args[1] ?? JsUndefined::instance();

                $locales = self::localesFromArg($localesArg);
                $options = self::coerceOptions($optionsArg);
                self::validateLocaleMatcher($options);

                $obj = self::instanceFromConstructor($this_, $proto, 'DateTimeFormat');
                $obj->defineOwnProperty('[[InitializedDateTimeFormat]]', PropertyDescriptor::data(
                    new JsBoolean(true),
                    false,
                    false,
                    false,
                ));

                // Resolve the locale with all DTF-relevant `-u-`
                // keywords kept; the per-option overrides are
                // detected later (after the option reads happen
                // in the spec-mandated order) and applied via a
                // post-pass that strips any keyword the user
                // overrode through a constructor option. The final
                // [[Locale]] write happens after all option reads.
                $resolvedLocale = self::resolveLocale($locales, ["ca", "nu", "hc"]);

                // calendar
                $calendar = 'gregory';
                $userCalendar = false;
                $calVal = $options->get('calendar');
                if (!$calVal instanceof JsUndefined) {
                    $userCalendar = true;
                    $calRaw = TypeConversion::toString($calVal);
                    // The identifier grammar is ASCII alphanum / '-'
                    // only; capital dotted I and similar non-ASCII
                    // letters are rejected.
                    if (!self::isValidUnicodeTypeValue($calRaw)) {
                        throw new RangeError("Invalid calendar: {$calRaw}");
                    }
                    $calendar = strtolower($calRaw);
                    static $calAliases = [
                        'islamicc' => 'islamic-civil',
                        'ethiopic-amete-alem' => 'ethioaa',
                        'gregorian' => 'gregory',
                    ];
                    if (isset($calAliases[$calendar])) {
                        $calendar = $calAliases[$calendar];
                    }
                } elseif (preg_match('/-u-(?:[a-wy-z0-9]{2,8}-)*?ca-([a-z0-9]{3,8}(?:-[a-z0-9]{3,8})*)/i', $resolvedLocale, $m) === 1) {
                    // -u-ca-<calendar> extension when no explicit
                    // option was passed.
                    $calendar = strtolower($m[1]);
                    static $calAliasesExt = [
                        'islamicc' => 'islamic-civil',
                        'ethiopic-amete-alem' => 'ethioaa',
                        'gregorian' => 'gregory',
                    ];
                    if (isset($calAliasesExt[$calendar])) {
                        $calendar = $calAliasesExt[$calendar];
                    }
                }
                $obj->defineOwnProperty('[[Calendar]]', PropertyDescriptor::data(
                    new JsString($calendar),
                    false,
                    false,
                    false,
                ));

                // numberingSystem: only keep ICU-supported numeric
                // systems (drop algorithmic ones like armn / hebr).
                $numberingSystem = 'latn';
                $userNumberingSystem = false;
                $nsVal = $options->get('numberingSystem');
                if (!$nsVal instanceof JsUndefined) {
                    $userNumberingSystem = true;
                    $nsRaw = TypeConversion::toString($nsVal);
                    if (!self::isValidUnicodeTypeValue($nsRaw)) {
                        throw new RangeError("Invalid numberingSystem: {$nsRaw}");
                    }
                    if (in_array($nsRaw, self::getSupportedNumberingSystems(), true)) {
                        $numberingSystem = $nsRaw;
                    }
                } elseif (preg_match('/-u-(?:[a-wy-z0-9]{2,8}-)*?nu-([a-z0-9]{3,8}(?:-[a-z0-9]{3,8})*)/i', $resolvedLocale, $nsMatch) === 1) {
                    // -u-nu-<numberingSystem> extension when no explicit
                    // option was passed. Filter against ICU-supported set
                    // so algorithmic systems (armn, hebr, ...) fall back.
                    $nsExt = strtolower($nsMatch[1]);
                    if (in_array($nsExt, self::getSupportedNumberingSystems(), true)) {
                        $numberingSystem = $nsExt;
                    }
                }
                $obj->defineOwnProperty('[[NumberingSystem]]', PropertyDescriptor::data(
                    new JsString($numberingSystem),
                    false,
                    false,
                    false,
                ));

                // hour12 / hourCycle reads happen BEFORE timeZone per
                // CreateDateTimeFormat steps 12-13 / 29 (in that order).
                $h12Val = $options->get('hour12');
                $hour12 = null;
                $userHour12 = false;
                if (!$h12Val instanceof JsUndefined) {
                    $userHour12 = true;
                    $hour12 = TypeConversion::toBoolean($h12Val);
                }
                $hourCycle = null;
                $userHourCycle = false;
                $hcVal = $options->get('hourCycle');
                if (!$hcVal instanceof JsUndefined) {
                    $userHourCycle = true;
                    $hc = TypeConversion::toString($hcVal);
                    if (!in_array($hc, ['h11', 'h12', 'h23', 'h24'], true)) {
                        throw new RangeError("Invalid hourCycle: {$hc}");
                    }
                    $hourCycle = $hc;
                }
                // If a constructor option overrode any of the
                // relevant -u- keywords, strip them from the
                // resolved locale before storing [[Locale]].
                $overrideKeys = [];
                if ($userCalendar) {
                    $overrideKeys[] = 'ca';
                }
                if ($userNumberingSystem) {
                    $overrideKeys[] = 'nu';
                }
                if ($userHour12 || $userHourCycle) {
                    $overrideKeys[] = 'hc';
                }
                if (!empty($overrideKeys)) {
                    $remaining = array_values(array_diff(['ca', 'nu', 'hc'], $overrideKeys));
                    $resolvedLocale = self::filterUnicodeExtensions($resolvedLocale, $remaining);
                }
                $obj->defineOwnProperty('[[Locale]]', PropertyDescriptor::data(
                    new JsString($resolvedLocale),
                    false,
                    false,
                    false,
                ));

                // timeZone: must be a recognized identifier per the IANA
                // tz database (or a UTC offset). Identifiers are
                // case-insensitive; canonicalise by matching the host's
                // case-preserved list.
                $timeZone = 'UTC';
                $tzVal = $options->get('timeZone');
                if (!$tzVal instanceof JsUndefined) {
                    $tz = TypeConversion::toString($tzVal);
                    // Spec offset grammar: '+' / '-' followed by HH (2
                    // digits, 00-23) and optional MM (2 digits 00-59,
                    // optionally separated by a colon). One-digit
                    // hours like "+3" are NOT valid.
                    $isOffset = false;
                    if (preg_match('/^([+-])(\d{2})(?::?(\d{2}))?$/', $tz, $offMatch) === 1) {
                        $hh = (int) $offMatch[2];
                        $mm = isset($offMatch[3]) ? (int) $offMatch[3] : 0;
                        if ($hh <= 23 && $mm <= 59) {
                            $isOffset = true;
                        }
                    }
                    $canonical = null;
                    if (!$isOffset) {
                        $canonical = self::resolveTimeZoneIdentifier($tz);
                    }
                    if (!$isOffset && $canonical === null) {
                        throw new RangeError("Invalid timeZone: {$tz}");
                    }
                    $timeZone = $canonical ?? self::canonicalizeOffsetTimeZone($tz);
                }
                $obj->defineOwnProperty('[[TimeZone]]', PropertyDescriptor::data(
                    new JsString($timeZone),
                    false,
                    false,
                    false,
                ));
                // Pull `-u-hc-…` from the requested locale as a default
                // when the constructor doesn't override it.
                if ($hourCycle === null && $hour12 === null) {
                    foreach ($locales as $candidate) {
                        $parsedTag = self::parseLocaleTag($candidate);
                        if (
                            $parsedTag !== null
                            && isset($parsedTag['hourCycle'])
                            && $parsedTag['hourCycle'] !== ''
                        ) {
                            $hourCycle = $parsedTag['hourCycle'];
                            break;
                        }
                    }
                }
                // Per spec step 24-26, hour12 takes precedence over the
                // unicode extension `hc` and any constructor-supplied
                // hourCycle. The locale data picks h11 vs h12 (and
                // h23 vs h24); ICU's English/most-locale default is
                // h12, with ja-JP using h11.
                if ($hour12 !== null) {
                    $localeLang = strtolower(strtok($resolvedLocale, '-_'));
                    if ($hour12) {
                        $hourCycle = $localeLang === 'ja' ? 'h11' : 'h12';
                    } else {
                        $hourCycle = 'h23';
                    }
                }
                // Defer writing [[HourCycle]] / [[Hour12]] until we
                // know whether a hour component is actually present
                // -- the slots only matter when the formatted output
                // includes hours, so resolvedOptions never returns
                // hourCycle / hour12 for a date-only formatter.

                // Date/time component options.
                $components = [
                    'weekday' => ['narrow', 'short', 'long'],
                    'era' => ['narrow', 'short', 'long'],
                    'year' => ['2-digit', 'numeric'],
                    'month' => ['2-digit', 'numeric', 'narrow', 'short', 'long'],
                    'day' => ['2-digit', 'numeric'],
                    'dayPeriod' => ['narrow', 'short', 'long'],
                    'hour' => ['2-digit', 'numeric'],
                    'minute' => ['2-digit', 'numeric'],
                    'second' => ['2-digit', 'numeric'],
                    'fractionalSecondDigits' => null, // 1, 2, or 3
                    'timeZoneName' => ['short', 'long', 'shortOffset', 'longOffset', 'shortGeneric', 'longGeneric'],
                ];
                $hasExplicitFormatComponents = false;
                $hasHour = false;
                foreach ($components as $prop => $validValues) {
                    $val = $options->get($prop);
                    if (!$val instanceof JsUndefined) {
                        // fractionalSecondDigits accepts integers in
                        // [1, 3]; ToNumber + range check happens
                        // before stringifying for the [[…]] slot.
                        if ($prop === 'fractionalSecondDigits') {
                            $fsd = TypeConversion::toNumber($val);
                            if (is_nan($fsd) || $fsd < 1 || $fsd > 3) {
                                throw new RangeError(
                                    "Invalid fractionalSecondDigits: {$fsd}"
                                );
                            }
                            $intVal = (int) floor($fsd);
                            $hasExplicitFormatComponents = true;
                            $obj->defineOwnProperty("[[{$prop}]]", PropertyDescriptor::data(
                                JsNumber::of((float) $intVal),
                                false,
                                false,
                                false,
                            ));
                            continue;
                        }
                        $str = TypeConversion::toString($val);
                        if ($validValues !== null && !in_array($str, $validValues, true)) {
                            throw new RangeError("Invalid {$prop}: {$str}");
                        }
                        $hasExplicitFormatComponents = true;
                        if ($prop === 'hour') {
                            $hasHour = true;
                        }
                        $obj->defineOwnProperty("[[{$prop}]]", PropertyDescriptor::data(
                            new JsString($str),
                            false,
                            false,
                            false,
                        ));
                    }
                }
                // formatMatcher: "basic" or "best fit" (default).
                $fmVal = $options->get('formatMatcher');
                if (!$fmVal instanceof JsUndefined) {
                    $fm = TypeConversion::toString($fmVal);
                    if (!in_array($fm, ['basic', 'best fit'], true)) {
                        throw new RangeError("Invalid formatMatcher: {$fm}");
                    }
                }

                // dateStyle / timeStyle (mutually exclusive with individual components per spec).
                $dateStyle = null;
                $dsVal = $options->get('dateStyle');
                if (!$dsVal instanceof JsUndefined) {
                    $ds = TypeConversion::toString($dsVal);
                    if (!in_array($ds, ['full', 'long', 'medium', 'short'], true)) {
                        throw new RangeError("Invalid dateStyle: {$ds}");
                    }
                    $dateStyle = $ds;
                }
                $timeStyle = null;
                $tsVal = $options->get('timeStyle');
                if (!$tsVal instanceof JsUndefined) {
                    $ts = TypeConversion::toString($tsVal);
                    if (!in_array($ts, ['full', 'long', 'medium', 'short'], true)) {
                        throw new RangeError("Invalid timeStyle: {$ts}");
                    }
                    $timeStyle = $ts;
                }
                // Spec step 43: dateStyle / timeStyle are mutually
                // exclusive with explicit format components.
                if (
                    ($dateStyle !== null || $timeStyle !== null)
                    && $hasExplicitFormatComponents
                ) {
                    throw new TypeError(
                        'dateStyle and timeStyle cannot be combined with explicit format components'
                    );
                }
                if ($dateStyle !== null) {
                    $obj->defineOwnProperty('[[DateStyle]]', PropertyDescriptor::data(
                        new JsString($dateStyle),
                        false,
                        false,
                        false,
                    ));
                }
                if ($timeStyle !== null) {
                    $obj->defineOwnProperty('[[TimeStyle]]', PropertyDescriptor::data(
                        new JsString($timeStyle),
                        false,
                        false,
                        false,
                    ));
                }

                // Per spec sec-initializedatetimeformat steps 38-40:
                // when neither dateStyle/timeStyle nor any explicit
                // format component is supplied, default to a
                // year/month/day skeleton so resolvedOptions exposes
                // the implied components and the formatter still
                // emits a date.
                if (
                    !$hasExplicitFormatComponents
                    && $dateStyle === null
                    && $timeStyle === null
                ) {
                    foreach (['year', 'month', 'day'] as $comp) {
                        $obj->defineOwnProperty("[[{$comp}]]", PropertyDescriptor::data(
                            new JsString('numeric'),
                            false,
                            false,
                            false,
                        ));
                    }
                    // Mark these defaults so Temporal augmentation
                    // can distinguish user-explicit options from
                    // implicit fallbacks.
                    $obj->defineOwnProperty('[[ComponentsDefaulted]]', PropertyDescriptor::data(
                        new JsBoolean(true),
                        false,
                        false,
                        false,
                    ));
                }

                // Now that dateStyle/timeStyle are known, decide
                // whether to expose [[HourCycle]] / [[Hour12]] (only
                // when the formatter will emit hours -- explicit
                // hour component or via timeStyle).
                $impliesHour = $hasHour || $timeStyle !== null;
                if ($impliesHour) {
                    if ($hourCycle === null) {
                        $hourCycle = self::resolveLocaleHourCycle($resolvedLocale)
                            ?? 'h12';
                    }
                    $obj->defineOwnProperty('[[HourCycle]]', PropertyDescriptor::data(
                        new JsString($hourCycle),
                        false,
                        false,
                        false,
                    ));
                    if ($hour12 !== null) {
                        $obj->defineOwnProperty('[[Hour12]]', PropertyDescriptor::data(
                            new JsBoolean($hour12),
                            false,
                            false,
                            false,
                        ));
                    }
                    // Track whether the hour-cycle came from an option
                    // override vs. the locale default so we can rewrite
                    // CLDR's locale-derived timeStyle pattern when the
                    // user supplied hour12 / hourCycle explicitly.
                    $obj->defineOwnProperty('[[HourCycleSource]]', PropertyDescriptor::data(
                        new JsString(($userHour12 || $userHourCycle) ? 'option' : 'locale'),
                        false,
                        false,
                        false,
                    ));
                }

                return $obj;
            },
            0,
        );
        $constructor->setConstructable();

        $constructor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($constructor, true, false, true));

        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Intl.DateTimeFormat'), false, false, true),
        );

        // DateTimeFormat.prototype.format: getter per spec.
        $formatFn = JsFunction::fromCallable('format', function (
            JsValue $this_,
            array $args,
        ): JsValue {
            $dateArg = $args[0] ?? JsUndefined::instance();

            // Temporal type dispatch: PlainDate/PlainDateTime/PlainTime/
            // PlainYearMonth/PlainMonthDay/Instant/ZonedDateTime get
            // their own validation + UTC-rendering paths so a plain
            // type formats exactly the components it carries (the
            // formatter's [[TimeZone]] is ignored for the plain types).
            if ($dateArg instanceof JsObject && self::isTemporalDateLike($dateArg)) {
                if (!$this_ instanceof JsObject) {
                    throw new TypeError('Intl.DateTimeFormat.format requires a DateTimeFormat');
                }
                return new JsString(self::formatTemporal($this_, $dateArg));
            }

            // Get timestamp from Date object or number. Per spec, the
            // value goes through ToNumber and is then validated via
            // TimeClip; non-finite numbers throw RangeError.
            $timestampMs = null;
            if ($dateArg instanceof JsObject && $dateArg->has('getTime')) {
                $getTime = $dateArg->get('getTime');
                if ($getTime instanceof JsFunction) {
                    $interp = \Phasis\Engine::getCurrentInterpreter();
                    if ($interp !== null) {
                        $result = $interp->callFunction($getTime, $dateArg, []);
                        $timestampMs = $result instanceof JsNumber ? $result->value : NAN;
                    }
                }
            } elseif ($dateArg instanceof JsUndefined) {
                $timestampMs = (float) (time() * 1000);
            } else {
                $timestampMs = TypeConversion::toNumber($dateArg);
            }
            if ($timestampMs === null || is_nan($timestampMs) || !is_finite($timestampMs)) {
                throw new RangeError('Invalid time value');
            }
            // TimeClip rejects values exceeding the JS Date range
            // (±8.64e15). Mirror the spec so the
            // time-clip-near-time-boundaries test passes.
            if (abs($timestampMs) > 8.64e15) {
                throw new RangeError('Time value out of range');
            }
            $timestamp = (int) ($timestampMs / 1000);

            if ($this_ instanceof JsObject && extension_loaded('intl')) {
                $formatted = self::formatDateTimeMs($this_, (float) $timestampMs);
                return new JsString($formatted);
            }

            return new JsString(date('n/j/Y, g:i:s A', $timestamp));
        }, 1);
        $formatGetter = JsFunction::fromCallable('get format', function (
            JsValue $this_,
        ) use ($formatFn): JsValue {
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedDateTimeFormat]]') instanceof JsUndefined
            ) {
                throw new TypeError('Intl.DateTimeFormat.prototype.format called on non-DateTimeFormat');
            }
            $boundFormat = JsFunction::fromCallable('', function (
                JsValue $unused,
                array $innerArgs,
            ) use (
                $this_,
                $formatFn
): JsValue {
                return ($formatFn->getNativeCallable())($this_, $innerArgs);
            }, 1);
            return $boundFormat;
        }, 0);
        $proto->defineOwnProperty('format', PropertyDescriptor::accessor(
            get: $formatGetter,
            set: null,
            enumerable: false,
            configurable: true,
        ));

        // DateTimeFormat.prototype.formatToParts(date)
        $formatToParts = JsFunction::fromCallable('formatToParts', function (
            JsValue $this_,
            array $args,
        ): JsValue {
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedDateTimeFormat]]') instanceof JsUndefined
            ) {
                throw new TypeError(
                    'Intl.DateTimeFormat.prototype.formatToParts called on non-DateTimeFormat',
                );
            }
            $dateArg = $args[0] ?? JsUndefined::instance();
            // Temporal types route through formatTemporal (UTC-locked
            // formatter, plain-type pattern narrowing).
            if ($dateArg instanceof JsObject && self::isTemporalDateLike($dateArg)) {
                $formatted = self::formatTemporal($this_, $dateArg);
                $timestamp = 0;
                return self::dateTimeFormatToParts($this_, $formatted, $timestamp, $dateArg);
            }
            $timestampMs = null;
            if ($dateArg instanceof JsObject && $dateArg->has('getTime')) {
                $getTime = $dateArg->get('getTime');
                if ($getTime instanceof JsFunction) {
                    $interp = \Phasis\Engine::getCurrentInterpreter();
                    if ($interp !== null) {
                        $r = $interp->callFunction($getTime, $dateArg, []);
                        $timestampMs = $r instanceof JsNumber ? $r->value : NAN;
                    }
                }
            } elseif ($dateArg instanceof JsUndefined) {
                $timestampMs = (float) (time() * 1000);
            } else {
                $timestampMs = TypeConversion::toNumber($dateArg);
            }
            if ($timestampMs === null || is_nan($timestampMs) || !is_finite($timestampMs)) {
                throw new RangeError('Invalid time value');
            }
            if (abs($timestampMs) > 8.64e15) {
                throw new RangeError('Time value out of range');
            }
            $timestamp = (int) ($timestampMs / 1000);
            $formatted = extension_loaded('intl')
                ? self::formatDateTimeMs($this_, (float) $timestampMs)
                : date('n/j/Y, g:i:s A', $timestamp);
            // Decompose the formatted output into spec-shaped parts
            // by walking the underlying ICU pattern in lockstep.
            return self::dateTimeFormatToParts($this_, $formatted, $timestamp);
        }, 1);
        $proto->defineOwnProperty(
            'formatToParts',
            PropertyDescriptor::data($formatToParts, true, false, true),
        );

        // DateTimeFormat.prototype.formatRange(startDate, endDate)
        $formatRange = JsFunction::fromCallable('formatRange', function (
            JsValue $this_,
            array $args,
        ): JsValue {
            self::dateTimeFormatRangeReceiverCheck($this_, 'formatRange');
            $startVal = $args[0] ?? JsUndefined::instance();
            $endVal = $args[1] ?? JsUndefined::instance();
            // Spec step 4: BOTH undefined checks happen before
            // ToNumber, so a poisoned `valueOf` on one side never
            // runs when the other is undefined.
            if ($startVal instanceof JsUndefined || $endVal instanceof JsUndefined) {
                throw new TypeError('formatRange arguments cannot be undefined');
            }
            // ToDateTimeFormattable per spec: keep Temporal types,
            // ToNumber everything else. ToNumber happens BEFORE
            // SameTemporalType checks so a poisoned valueOf still
            // fires when one arg is Temporal and the other isn't.
            // The NaN/range check is deferred until AFTER the kind
            // check so a same-kind mismatch surfaces as TypeError,
            // not RangeError.
            $startTemp = $startVal instanceof JsObject
                && self::isTemporalDateLike($startVal)
                ? $startVal : null;
            $endTemp = $endVal instanceof JsObject
                && self::isTemporalDateLike($endVal)
                ? $endVal : null;
            $startNum = $startTemp === null ? TypeConversion::toNumber($startVal) : 0.0;
            $endNum = $endTemp === null ? TypeConversion::toNumber($endVal) : 0.0;
            if (($startTemp !== null) !== ($endTemp !== null)) {
                throw new TypeError('formatRange arguments must be the same kind');
            }
            $startMs = 0.0;
            $endMs = 0.0;
            if ($startTemp === null) {
                if (is_nan($startNum) || !is_finite($startNum) || abs($startNum) > 8.64e15) {
                    throw new RangeError('Invalid startDate');
                }
                if (is_nan($endNum) || !is_finite($endNum) || abs($endNum) > 8.64e15) {
                    throw new RangeError('Invalid endDate');
                }
                $startMs = $startNum;
                $endMs = $endNum;
            }
            $startStr = '';
            $endStr = '';
            if ($startTemp !== null && $endTemp !== null) {
                self::checkSameTemporalType($startTemp, $endTemp);
                $startStr = self::formatTemporal($this_, $startTemp);
                $endStr = self::formatTemporal($this_, $endTemp);
            } elseif (extension_loaded('intl')) {
                $startStr = self::formatDateTimeMs($this_, (float) $startMs);
                $endStr = self::formatDateTimeMs($this_, (float) $endMs);
            } else {
                $startStr = (string) $startMs;
                $endStr = (string) $endMs;
            }
            if ($startStr === $endStr) {
                return new JsString($startStr);
            }
            $collapsed = self::collapseDateRange($startStr, $endStr);
            return new JsString($collapsed);
        }, 2);
        $proto->defineOwnProperty(
            'formatRange',
            PropertyDescriptor::data($formatRange, true, false, true),
        );

        // DateTimeFormat.prototype.formatRangeToParts(startDate, endDate)
        $formatRangeToParts = JsFunction::fromCallable('formatRangeToParts', function (
            JsValue $this_,
            array $args,
        ): JsValue {
            self::dateTimeFormatRangeReceiverCheck($this_, 'formatRangeToParts');
            $startVal = $args[0] ?? JsUndefined::instance();
            $endVal = $args[1] ?? JsUndefined::instance();
            if ($startVal instanceof JsUndefined || $endVal instanceof JsUndefined) {
                throw new TypeError('formatRangeToParts arguments cannot be undefined');
            }
            // Mirror formatRange: ToNumber the non-Temporal args
            // BEFORE SameTemporalType so a poisoned valueOf is
            // observed even when the kinds differ; defer NaN/range
            // until after the kind check.
            $startTemp = $startVal instanceof JsObject
                && self::isTemporalDateLike($startVal)
                ? $startVal : null;
            $endTemp = $endVal instanceof JsObject
                && self::isTemporalDateLike($endVal)
                ? $endVal : null;
            $startNum = $startTemp === null ? TypeConversion::toNumber($startVal) : 0.0;
            $endNum = $endTemp === null ? TypeConversion::toNumber($endVal) : 0.0;
            if (($startTemp !== null) !== ($endTemp !== null)) {
                throw new TypeError('formatRangeToParts arguments must be the same kind');
            }
            $startMs = 0.0;
            $endMs = 0.0;
            if ($startTemp === null) {
                if (is_nan($startNum) || !is_finite($startNum) || abs($startNum) > 8.64e15) {
                    throw new RangeError('Invalid startDate');
                }
                if (is_nan($endNum) || !is_finite($endNum) || abs($endNum) > 8.64e15) {
                    throw new RangeError('Invalid endDate');
                }
                $startMs = $startNum;
                $endMs = $endNum;
            }
            $startStr = '';
            $endStr = '';
            $temporalArg = null;
            if ($startTemp !== null && $endTemp !== null) {
                self::checkSameTemporalType($startTemp, $endTemp);
                $startStr = self::formatTemporal($this_, $startTemp);
                $endStr = self::formatTemporal($this_, $endTemp);
                $temporalArg = $startTemp;
            } elseif (extension_loaded('intl')) {
                $startStr = self::formatDateTimeMs($this_, (float) $startMs);
                $endStr = self::formatDateTimeMs($this_, (float) $endMs);
            }
            $result = new JsArray();
            $idx = 0;
            $emit = static function (
                string $type,
                string $value,
                string $source
            ) use (
                &$result,
                &$idx,
            ): void {
                $part = new JsObject();
                self::defineDataProp($part, 'type', new JsString($type));
                self::defineDataProp($part, 'value', new JsString($value));
                self::defineDataProp($part, 'source', new JsString($source));
                $result->set((string) $idx++, $part);
            };
            // Decompose start (and end, if different) into typed
            // parts so consumers can read individual fields.
            $appendTyped = static function (
                JsArray $partsArr,
                string $source,
            ) use (&$emit): void {
                $count = (int) ($partsArr->get('length') instanceof JsNumber
                    ? $partsArr->get('length')->value
                    : 0);
                for ($k = 0; $k < $count; $k++) {
                    $p = $partsArr->get((string) $k);
                    if (!$p instanceof JsObject) {
                        continue;
                    }
                    $type = $p->get('type');
                    $value = $p->get('value');
                    if ($type instanceof JsString && $value instanceof JsString) {
                        $emit($type->value, $value->value, $source);
                    }
                }
            };
            $startParts = self::dateTimeFormatToParts(
                $this_,
                $startStr,
                (int) round($startMs / 1000),
                $temporalArg,
            );
            if ($startStr === $endStr) {
                $appendTyped($startParts, 'shared');
            } else {
                $endParts = self::dateTimeFormatToParts(
                    $this_,
                    $endStr,
                    (int) round($endMs / 1000),
                    $temporalArg,
                );
                // Walk start/end parts in lockstep to find a shared
                // prefix and shared suffix (where every (type, value)
                // pair matches). Collapse those into 'shared' parts;
                // emit the differing middle as 'startRange' /
                // 'endRange' separated by the range literal.
                $startPartsArr = self::partsArrayToList($startParts);
                $endPartsArr = self::partsArrayToList($endParts);
                $useCollapse = self::dateTimeFormatRangeShouldCollapse($startStr, $endStr);
                $sLen = count($startPartsArr);
                $eLen = count($endPartsArr);
                $prefixLen = 0;
                $suffixLen = 0;
                if ($useCollapse) {
                    while (
                        $prefixLen < $sLen
                        && $prefixLen < $eLen
                        && self::dateTimePartsMatch(
                            $startPartsArr[$prefixLen],
                            $endPartsArr[$prefixLen],
                        )
                    ) {
                        $prefixLen++;
                    }
                    while (
                        $sLen - $suffixLen > $prefixLen
                        && $eLen - $suffixLen > $prefixLen
                        && self::dateTimePartsMatch(
                            $startPartsArr[$sLen - $suffixLen - 1],
                            $endPartsArr[$eLen - $suffixLen - 1],
                        )
                    ) {
                        $suffixLen++;
                    }
                    // CLDR's interval pattern only collapses when
                    // the differing range stops short of the year.
                    // If year (or any field strictly higher than the
                    // narrowest differing field) is in the diff,
                    // V8 emits both endpoints in full.
                    $diffRanks = [];
                    for ($i = $prefixLen; $i < $sLen - $suffixLen; $i++) {
                        $diffRanks[] = self::dateTimePartRank($startPartsArr[$i]['type']);
                    }
                    for ($i = $prefixLen; $i < $eLen - $suffixLen; $i++) {
                        $diffRanks[] = self::dateTimePartRank($endPartsArr[$i]['type']);
                    }
                    $hasYearDiff = in_array(self::dateTimePartRank('year'), $diffRanks, true);
                    if ($hasYearDiff) {
                        $useCollapse = false;
                    }
                    // CLDR's dateTimeFormat repeats the dayPeriod
                    // (AM/PM) on both sides when the diff is purely
                    // within the time fields. If the collapsed suffix
                    // includes a dayPeriod part, V8 emits it on both
                    // sides instead. Shrink the suffix so any
                    // dayPeriod (and the preceding literal that
                    // separates it from the seconds) stay per-side.
                    if ($useCollapse && $suffixLen > 0) {
                        $hasTimeDiff = false;
                        $timeRanks = [
                            self::dateTimePartRank('hour'),
                            self::dateTimePartRank('minute'),
                            self::dateTimePartRank('second'),
                            self::dateTimePartRank('fractionalSecond'),
                        ];
                        foreach ($diffRanks as $r) {
                            if (in_array($r, $timeRanks, true)) {
                                $hasTimeDiff = true;
                                break;
                            }
                        }
                        if ($hasTimeDiff) {
                            // Find the smallest index within the
                            // currently-collapsed suffix whose part
                            // is a dayPeriod, and shrink the suffix
                            // so it begins strictly after that
                            // dayPeriod. The dayPeriod (and any
                            // separator literal before it) end up in
                            // the per-side middle on both sides.
                            $suffixStart = $sLen - $suffixLen;
                            $dropAt = -1;
                            for ($i = $suffixStart; $i < $sLen; $i++) {
                                if ($startPartsArr[$i]['type'] === 'dayPeriod') {
                                    $dropAt = $i;
                                    break;
                                }
                            }
                            if ($dropAt >= 0) {
                                $newSuffixLen = $sLen - $dropAt - 1;
                                $suffixLen = max(0, min($newSuffixLen, $suffixLen));
                            }
                        }
                    }
                }
                if ($useCollapse) {
                    // Emit shared prefix.
                    for ($i = 0; $i < $prefixLen; $i++) {
                        $p = $startPartsArr[$i];
                        $emit($p['type'], $p['value'], 'shared');
                    }
                    // Emit start-range middle.
                    for ($i = $prefixLen; $i < $sLen - $suffixLen; $i++) {
                        $p = $startPartsArr[$i];
                        $emit($p['type'], $p['value'], 'startRange');
                    }
                    $emit('literal', " \u{2013} ", 'shared');
                    // Emit end-range middle.
                    for ($i = $prefixLen; $i < $eLen - $suffixLen; $i++) {
                        $p = $endPartsArr[$i];
                        $emit($p['type'], $p['value'], 'endRange');
                    }
                    // Emit shared suffix.
                    for ($i = $sLen - $suffixLen; $i < $sLen; $i++) {
                        $p = $startPartsArr[$i];
                        $emit($p['type'], $p['value'], 'shared');
                    }
                } else {
                    // de-AT (and similar) PlainMonthDay format ends in
                    // a trailing "." literal. CLDR's Md interval
                    // pattern emits it once at the end of the range
                    // ("D.M – D.M.") rather than twice. When both
                    // start and end strings match that shape, drop the
                    // trailing literal from each side and emit a
                    // single shared "." after the end-range parts.
                    $mdTrailing = '/^(\d{1,2})\.(\d{1,2})\.$/';
                    $bothMdTrailing = preg_match($mdTrailing, $startStr) === 1
                        && preg_match($mdTrailing, $endStr) === 1
                        && $sLen > 0
                        && $eLen > 0
                        && $startPartsArr[$sLen - 1]['type'] === 'literal'
                        && $startPartsArr[$sLen - 1]['value'] === '.'
                        && $endPartsArr[$eLen - 1]['type'] === 'literal'
                        && $endPartsArr[$eLen - 1]['value'] === '.';
                    // de-AT PlainDate range "D.M.Y – D.M.Y": when
                    // year is in the diff (no collapse), CLDR / V8
                    // pad the month to a uniform 2-digit width on
                    // both sides ("18.11.1976 – 20.02.2020"). Detect
                    // by full-date numeric pattern with no trailing
                    // dot.
                    $fullDateNum = '/^\d{1,2}\.\d{1,2}\.\d{4}$/';
                    $bothFullDateNum = preg_match($fullDateNum, $startStr) === 1
                        && preg_match($fullDateNum, $endStr) === 1;
                    if ($bothMdTrailing) {
                        // CLDR's Md interval pattern uses MM (2-digit
                        // month) on both sides when month widths
                        // would otherwise differ. Pad both start and
                        // end month parts so the range output keeps
                        // a uniform month width.
                        $maxMonthLenIn = static function (array $arr): int {
                            $w = 0;
                            foreach ($arr as $p) {
                                if ($p['type'] === 'month') {
                                    $w = max($w, strlen($p['value']));
                                }
                            }
                            return $w;
                        };
                        $startMonthLen = $maxMonthLenIn($startPartsArr);
                        $endMonthLen = $maxMonthLenIn($endPartsArr);
                        $monthWidth = max($startMonthLen, $endMonthLen, 2);
                        for ($i = 0; $i < $sLen - 1; $i++) {
                            $p = $startPartsArr[$i];
                            $val = $p['value'];
                            if ($p['type'] === 'month') {
                                $val = str_pad($val, $monthWidth, '0', STR_PAD_LEFT);
                            }
                            $emit($p['type'], $val, 'startRange');
                        }
                        $emit('literal', " \u{2013} ", 'shared');
                        for ($i = 0; $i < $eLen - 1; $i++) {
                            $p = $endPartsArr[$i];
                            $val = $p['value'];
                            if ($p['type'] === 'month') {
                                $val = str_pad($val, $monthWidth, '0', STR_PAD_LEFT);
                            }
                            $emit($p['type'], $val, 'endRange');
                        }
                        $emit('literal', '.', 'shared');
                    } elseif ($bothFullDateNum) {
                        // Pad the month part on both sides so the
                        // emitted parts share a consistent width
                        // matching the formatRange string output.
                        $maxMonthLenIn2 = static function (array $arr): int {
                            $w = 0;
                            foreach ($arr as $p) {
                                if ($p['type'] === 'month') {
                                    $w = max($w, strlen($p['value']));
                                }
                            }
                            return $w;
                        };
                        $width = max(
                            $maxMonthLenIn2($startPartsArr),
                            $maxMonthLenIn2($endPartsArr),
                            2,
                        );
                        foreach ($startPartsArr as $p) {
                            $val = $p['value'];
                            if ($p['type'] === 'month') {
                                $val = str_pad($val, $width, '0', STR_PAD_LEFT);
                            }
                            $emit($p['type'], $val, 'startRange');
                        }
                        $emit('literal', " \u{2013} ", 'shared');
                        foreach ($endPartsArr as $p) {
                            $val = $p['value'];
                            if ($p['type'] === 'month') {
                                $val = str_pad($val, $width, '0', STR_PAD_LEFT);
                            }
                            $emit($p['type'], $val, 'endRange');
                        }
                    } else {
                        $appendTyped($startParts, 'startRange');
                        $emit('literal', " \u{2013} ", 'shared');
                        $appendTyped($endParts, 'endRange');
                    }
                }
            }
            $result->set('length', JsNumber::of((float) $idx));
            return $result;
        }, 2);
        $proto->defineOwnProperty(
            'formatRangeToParts',
            PropertyDescriptor::data($formatRangeToParts, true, false, true),
        );

        // DateTimeFormat.prototype.resolvedOptions()
        $resolvedOptions = JsFunction::fromCallable('resolvedOptions', function (
            JsValue $this_,
        ): JsValue {
            // Spec brand check: proxies don't have internal slots, so
            // a proxy wrapping a DateTimeFormat must NOT be accepted
            // unless we implemented the normative-optional fallback
            // symbol unwrap. Throw TypeError to match the
            // "non-implementor" branch the test allows.
            if (
                !$this_ instanceof JsObject
                || $this_ instanceof \Phasis\Value\JsProxy
                || $this_->get('[[InitializedDateTimeFormat]]') instanceof JsUndefined
            ) {
                throw new TypeError('Intl.DateTimeFormat.prototype.resolvedOptions called on non-DateTimeFormat');
            }
            $result = new JsObject();
            self::defineDataProp($result, 'locale', new JsString(
                self::extractInternalString($this_, '[[Locale]]', 'en'),
            ));
            self::defineDataProp($result, 'calendar', new JsString(
                self::extractInternalString($this_, '[[Calendar]]', 'gregory'),
            ));
            self::defineDataProp($result, 'numberingSystem', new JsString(
                self::extractInternalString($this_, '[[NumberingSystem]]', 'latn'),
            ));
            self::defineDataProp($result, 'timeZone', new JsString(
                self::extractInternalString($this_, '[[TimeZone]]', 'UTC'),
            ));
            $hcVal = $this_->get('[[HourCycle]]');
            if (!$hcVal instanceof JsUndefined) {
                $hcStr = TypeConversion::toString($hcVal);
                self::defineDataProp($result, 'hourCycle', new JsString($hcStr));
                // Spec: hour12 is true for h11/h12, false for h23/h24.
                self::defineDataProp(
                    $result,
                    'hour12',
                    new JsBoolean(in_array($hcStr, ['h11', 'h12'], true)),
                );
            }

            // Component options. fractionalSecondDigits is the only
            // numeric one; the rest are strings from a finite set.
            foreach (
                ['weekday', 'era', 'year', 'month', 'day', 'dayPeriod',
                'hour', 'minute', 'second', 'fractionalSecondDigits', 'timeZoneName'] as $comp
            ) {
                $val = $this_->get("[[{$comp}]]");
                if ($val instanceof JsUndefined) {
                    continue;
                }
                if ($comp === 'fractionalSecondDigits') {
                    $num = $val instanceof JsNumber
                        ? $val->value
                        : TypeConversion::toNumber($val);
                    self::defineDataProp($result, $comp, JsNumber::of((float) $num));
                } else {
                    self::defineDataProp($result, $comp, new JsString(TypeConversion::toString($val)));
                }
            }

            $dsVal = $this_->get('[[DateStyle]]');
            if (!$dsVal instanceof JsUndefined) {
                self::defineDataProp($result, 'dateStyle', new JsString(TypeConversion::toString($dsVal)));
            }
            $tsVal = $this_->get('[[TimeStyle]]');
            if (!$tsVal instanceof JsUndefined) {
                self::defineDataProp($result, 'timeStyle', new JsString(TypeConversion::toString($tsVal)));
            }
            return $result;
        }, 0);
        $proto->defineOwnProperty(
            'resolvedOptions',
            PropertyDescriptor::data($resolvedOptions, true, false, true),
        );

        // DateTimeFormat.supportedLocalesOf
        $constructor->defineOwnProperty(
            'supportedLocalesOf',
            PropertyDescriptor::data(self::makeSupportedLocalesOf('DateTimeFormat'), true, false, true),
        );

        $intl->defineOwnProperty(
            'DateTimeFormat',
            PropertyDescriptor::data($constructor, true, false, true),
        );
    }

    /**
     * Brand-check the receiver of DateTimeFormat range methods. Spec:
     * if `this` doesn't have [[InitializedDateTimeFormat]], throw
     * TypeError.
     */
    /**
     * @phpstan-assert JsObject $this_
     */
    private static function dateTimeFormatRangeReceiverCheck(JsValue $this_, string $name): void
    {
        if (
            !$this_ instanceof JsObject
            || $this_->get('[[InitializedDateTimeFormat]]') instanceof JsUndefined
        ) {
            throw new TypeError(
                "Intl.DateTimeFormat.prototype.{$name} called on non-DateTimeFormat",
            );
        }
    }

    /**
     * Coerce a `startDate` / `endDate` argument to a millisecond
     * timestamp. Mirrors HandleDateTimeValue from the spec: undefined
     * is a TypeError, invalid Dates / NaN / Infinity are a RangeError.
     */
    /**
     * Convert a JsArray of DateTimeFormat parts into a PHP list of
     * {type, value} associative arrays.
     *
     * @return list<array{type: string, value: string}>
     */
    private static function partsArrayToList(JsArray $parts): array
    {
        $out = [];
        $count = (int) ($parts->get('length') instanceof JsNumber
            ? $parts->get('length')->value
            : 0);
        for ($i = 0; $i < $count; $i++) {
            $p = $parts->get((string) $i);
            if (!$p instanceof JsObject) {
                continue;
            }
            $type = $p->get('type');
            $value = $p->get('value');
            if ($type instanceof JsString && $value instanceof JsString) {
                $out[] = ['type' => $type->value, 'value' => $value->value];
            }
        }
        return $out;
    }

    /**
     * Whether two parts share both type and value (used by formatRangeToParts
     * to identify shared prefix/suffix runs).
     *
     * @param array{type: string, value: string} $a
     * @param array{type: string, value: string} $b
     */
    private static function dateTimePartsMatch(array $a, array $b): bool
    {
        return $a['type'] === $b['type'] && $a['value'] === $b['value'];
    }

    /**
     * Rank ordering of DateTimeFormat part types from coarsest
     * (era) to finest (fractionalSecond). Used by formatRangeToParts
     * to detect which is the largest differing field — when it's
     * year (or coarser) the spec keeps both endpoints in full.
     */
    private static function dateTimePartRank(string $type): int
    {
        $ranks = [
            'era' => 0,
            'relatedYear' => 1,
            'year' => 2,
            'yearName' => 2,
            'month' => 3,
            'weekday' => 3,
            'day' => 4,
            'dayPeriod' => 5,
            'hour' => 6,
            'minute' => 7,
            'second' => 8,
            'fractionalSecond' => 9,
            'timeZoneName' => 10,
        ];
        return $ranks[$type] ?? 999;
    }

    /**
     * Same heuristic as collapseDateRange: only collapse the parts
     * sequence when at least one alphabetic token (month name,
     * weekday) is present in either formatted string.
     */
    private static function dateTimeFormatRangeShouldCollapse(string $start, string $end): bool
    {
        return preg_match('/[A-Za-z]/u', $start) === 1
            || preg_match('/[A-Za-z]/u', $end) === 1;
    }

    /**
     * Collapse a DateTimeFormat formatRange pair by stripping the
     * shared prefix and suffix between the two formatted strings,
     * leaving only the differing middle portions joined by " – ".
     * Walks UTF-8 boundary-by-boundary so multi-byte characters
     * stay intact.
     */
    private static function collapseDateRange(string $start, string $end): string
    {
        // Find UTF-8-aware shared prefix.
        $sLen = strlen($start);
        $eLen = strlen($end);
        $prefixEnd = 0;
        $i = 0;
        while ($i < $sLen && $i < $eLen) {
            $sByte = ord($start[$i]);
            $charLen = $sByte >= 0xF0 ? 4 : ($sByte >= 0xE0 ? 3 : ($sByte >= 0xC0 ? 2 : 1));
            if ($i + $charLen > $sLen || $i + $charLen > $eLen) {
                break;
            }
            if (substr($start, $i, $charLen) !== substr($end, $i, $charLen)) {
                break;
            }
            $i += $charLen;
            $prefixEnd = $i;
        }
        // Find UTF-8-aware shared suffix.
        $j = 0;
        $startTail = $sLen;
        $endTail = $eLen;
        while (
            $startTail - 1 > $prefixEnd
            && $endTail - 1 > $prefixEnd
        ) {
            // Find the start of the LAST UTF-8 char in start.
            $sChStart = $startTail - 1;
            while ($sChStart > $prefixEnd && (ord($start[$sChStart]) & 0xC0) === 0x80) {
                $sChStart--;
            }
            $eChStart = $endTail - 1;
            while ($eChStart > $prefixEnd && (ord($end[$eChStart]) & 0xC0) === 0x80) {
                $eChStart--;
            }
            $sCh = substr($start, $sChStart, $startTail - $sChStart);
            $eCh = substr($end, $eChStart, $endTail - $eChStart);
            if ($sCh !== $eCh) {
                break;
            }
            $startTail = $sChStart;
            $endTail = $eChStart;
        }
        $sharedPrefix = substr($start, 0, $prefixEnd);
        $startDiff = substr($start, $prefixEnd, $startTail - $prefixEnd);
        $endDiff = substr($end, $prefixEnd, $endTail - $prefixEnd);
        $sharedSuffix = substr($start, $startTail);
        // Don't collapse if the differing slice ends mid-token (e.g.
        // common prefix runs into the digits of a year). When the
        // shared prefix ends with a digit, walk back through digits
        // to find a clean boundary (whitespace or punctuation that
        // signals a field separator). For "8/4/2021, 1" → shared
        // becomes "8/4/2021, " (clean ", " boundary). For "Mar 4, 2"
        // walking back lands on "Mar 4, " — but the trailing "20"
        // is the start of the year, and the differing portion would
        // become "2019"/"2020" (year-only diff). To avoid collapsing
        // in that case, only walk back past digits that immediately
        // follow whitespace + a comma (the date+time separator
        // pattern in CLDR).
        if ($sharedPrefix !== '' && ctype_digit(substr($sharedPrefix, -1))) {
            // Walk back over the digit run so the differing slice
            // starts at a clean token boundary. Require ", " before
            // the digits (CLDR's date+time separator) AND require
            // the differing slice to contain alphabetic content
            // (AM/PM, weekday name, era) — without that, the diff
            // is a pure-digit field like a year and V8 emits both
            // endpoints in full instead of collapsing.
            $back = strlen($sharedPrefix) - 1;
            while ($back >= 0 && ctype_digit($sharedPrefix[$back])) {
                $back--;
            }
            $boundaryOk = $back >= 1
                && $sharedPrefix[$back] === ' '
                && $sharedPrefix[$back - 1] === ',';
            if (!$boundaryOk) {
                return $start . " \u{2013} " . $end;
            }
            $newPrefixEnd = $back + 1;
            $extraStart = substr($sharedPrefix, $newPrefixEnd);
            $tentativeStartDiff = $extraStart . $startDiff;
            $tentativeEndDiff = $extraStart . $endDiff;
            $diffHasAlpha = preg_match('/[A-Za-z]/u', $tentativeStartDiff) === 1
                || preg_match('/[A-Za-z]/u', $tentativeEndDiff) === 1;
            if (!$diffHasAlpha) {
                return $start . " \u{2013} " . $end;
            }
            $startDiff = $tentativeStartDiff;
            $endDiff = $tentativeEndDiff;
            $sharedPrefix = substr($sharedPrefix, 0, $newPrefixEnd);
        }
        if ($sharedSuffix !== '' && ctype_digit($sharedSuffix[0])) {
            return $start . " \u{2013} " . $end;
        }
        // If the shared suffix begins mid-token (alphabetic char with
        // an alphabetic neighbour on either side), it's collapsing
        // through what is actually different content (e.g. "AM"/"PM"
        // share a trailing "M" but the "A"/"P" before it differ).
        if ($sharedSuffix !== '' && ctype_alpha($sharedSuffix[0])) {
            $startEnd = $startDiff !== '' ? substr($startDiff, -1) : '';
            $endEnd = $endDiff !== '' ? substr($endDiff, -1) : '';
            if (
                ($startEnd !== '' && ctype_alpha($startEnd))
                || ($endEnd !== '' && ctype_alpha($endEnd))
            ) {
                // Walk forward through the alphabetic suffix until
                // we hit non-alpha; merge those alpha bytes into
                // each diff so the comparison is whole-token.
                $forward = 0;
                $sLenSuffix = strlen($sharedSuffix);
                while ($forward < $sLenSuffix && ctype_alpha($sharedSuffix[$forward])) {
                    $forward++;
                }
                $extraEnd = substr($sharedSuffix, 0, $forward);
                $startDiff .= $extraEnd;
                $endDiff .= $extraEnd;
                $sharedSuffix = substr($sharedSuffix, $forward);
            }
        }
        // Only collapse when the format has at least one alphabetic
        // token somewhere (month name or weekday). Numeric-only
        // formats like "1/3/2019" stay expanded since CLDR's range
        // pattern for short-style numeric dates emits both
        // endpoints in full.
        $hasAlphaAnywhere = preg_match('/[A-Za-z]/u', $start) === 1
            || preg_match('/[A-Za-z]/u', $end) === 1;
        if (!$hasAlphaAnywhere) {
            // For European numeric date format "D.M.Y", V8 / CLDR's
            // formatRange uses 2-digit month consistently when ranges
            // span months or years. Pad asymmetric month widths.
            $padded = self::padDateRangeMonth($start, $end);
            if ($padded !== null) {
                [$start, $end] = $padded;
            }
            // de-AT (and similar locales) format PlainMonthDay as
            // "D.M." with a trailing period. CLDR's Md interval
            // pattern, however, drops the trailing period from the
            // start side: "D.M – D.M.". Mirror that here so the
            // string matches the pieces emitted by formatRangeToParts
            // (which marks the trailing "." as shared).
            $mdTrailing = '/^(\d{1,2})\.(\d{1,2})\.$/';
            if (
                preg_match($mdTrailing, $start) === 1
                && preg_match($mdTrailing, $end) === 1
            ) {
                $startNoTail = substr($start, 0, -1);
                return $startNoTail . " \u{2013} " . $end;
            }
            return $start . " \u{2013} " . $end;
        }
        // V8 / CLDR's dateTimeFormat repeats AM/PM (and other alphabetic
        // dayPeriod tokens) on both sides of a range when the diffs are
        // distinct full date / time fields. Detect that case and emit
        // the suffix on both sides instead of collapsing.
        if (
            $sharedSuffix !== ''
            && preg_match('/^\s+[A-Za-z]/u', $sharedSuffix) === 1
            && (
                str_contains($startDiff, ', ')
                || str_contains($endDiff, ', ')
                || preg_match('/\d:\d/', $startDiff) === 1
                || preg_match('/\d:\d/', $endDiff) === 1
            )
        ) {
            return $sharedPrefix . $startDiff . $sharedSuffix . " \u{2013} " . $endDiff . $sharedSuffix;
        }
        // When both diffs encode a "D.M.Y" / "DD.MM.YYYY" date with
        // mismatched month digit widths (e.g. "18.11.1976" vs "20.2.2020"),
        // pad the shorter month to two digits so the range output mirrors
        // V8's CLDR formatRange. Without this, the output reads
        // "20.2.2020" while V8 emits "20.02.2020".
        $padded = self::padDateRangeMonth($startDiff, $endDiff);
        if ($padded !== null) {
            [$startDiff, $endDiff] = $padded;
        }
        return $sharedPrefix . $startDiff . " \u{2013} " . $endDiff . $sharedSuffix;
    }

    /**
     * Detect a "D.M.Y" / "DD.MM.YYYY" or "DD.M" / "DD.MM" PMD-style
     * range where one side has 1-digit month and the other 2-digit;
     * return both sides with consistent 2-digit months. Returns null
     * when the heuristic doesn't apply.
     *
     * @return array{0:string,1:string}|null
     */
    private static function padDateRangeMonth(string $a, string $b): ?array
    {
        // Full date pattern: D.M.Y
        $full = '/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/';
        if (
            preg_match($full, $a, $am) === 1
            && preg_match($full, $b, $bm) === 1
        ) {
            if (strlen($am[2]) === strlen($bm[2])) {
                return null;
            }
            $aMonth = str_pad($am[2], 2, '0', STR_PAD_LEFT);
            $bMonth = str_pad($bm[2], 2, '0', STR_PAD_LEFT);
            return [
                $am[1] . '.' . $aMonth . '.' . $am[3],
                $bm[1] . '.' . $bMonth . '.' . $bm[3],
            ];
        }
        // PlainMonthDay-style range: DD.M / DD.MM with an optional
        // trailing dot. The DD field's dot is the field separator;
        // the optional trailing one is the locale's "after-month"
        // marker (de-AT renders MonthDay as "20.02." for example).
        $md = '/^(\d{1,2})\.(\d{1,2})(\.?)$/';
        if (
            preg_match($md, $a, $am) === 1
            && preg_match($md, $b, $bm) === 1
        ) {
            if (strlen($am[2]) === strlen($bm[2])) {
                return null;
            }
            $aMonth = str_pad($am[2], 2, '0', STR_PAD_LEFT);
            $bMonth = str_pad($bm[2], 2, '0', STR_PAD_LEFT);
            return [
                $am[1] . '.' . $aMonth . $am[3],
                $bm[1] . '.' . $bMonth . $bm[3],
            ];
        }
        return null;
    }

    /**
     * Decompose a formatted date-time output into spec-shaped parts
     * by walking ICU's underlying pattern in lockstep with the
     * formatted text. Each pattern token maps onto a typed part:
     * "y" -> year, "M" -> month, "d" -> day, "h"/"H" -> hour,
     * "m" -> minute, "s" -> second, "a"/"b" -> dayPeriod,
     * "z"/"v"/"O"/"X" -> timeZoneName, "E"/"c" -> weekday,
     * "G" -> era, "S" -> fractionalSecond. Unknown tokens collapse
     * to literal.
     */
    private static function dateTimeFormatToParts(
        JsObject $dtf,
        string $formatted,
        int $timestamp,
        ?JsObject $temporal = null,
    ): JsArray {
        $parts = new JsArray();
        $idx = 0;
        $calendar = self::extractInternalString($dtf, '[[Calendar]]', 'gregory');
        $emit = static function (string $type, string $value) use (&$parts, &$idx, $calendar): void {
            if ($value === '') {
                return;
            }
            if ($type === 'era') {
                $value = self::mapEraToIcu4xCode($calendar, $value);
            }
            $part = new JsObject();
            self::defineDataProp($part, 'type', new JsString($type));
            self::defineDataProp($part, 'value', new JsString($value));
            $parts->set((string) $idx++, $part);
        };
        // Without intl we already only have a literal output.
        if (!extension_loaded('intl')) {
            $emit('literal', $formatted);
            $parts->set('length', JsNumber::of((float) $idx));
            return $parts;
        }
        // Reuse the same formatter the format() pipeline used so
        // `getPattern()` reflects the same skeleton. For Temporal
        // plain types, narrow the pattern the same way formatTemporal
        // does so the parts walk against the actual emitted glyphs.
        $formatter = $temporal !== null
            ? self::temporalFormatterFor($dtf, $temporal)
            : self::dateTimeFormatterFor($dtf);
        $pattern = $formatter->getPattern();
        if ($pattern === false || $pattern === '') {
            $emit('literal', $formatted);
            $parts->set('length', JsNumber::of((float) $idx));
            return $parts;
        }

        // Tokenise the pattern: a run of identical letters is one
        // token; anything else (literal text, quoted strings) is
        // a literal run.
        $tokens = [];
        $patLen = strlen($pattern);
        $p = 0;
        while ($p < $patLen) {
            $ch = $pattern[$p];
            if ($ch === "'") {
                // Quoted literal — supports doubled '' for an apostrophe.
                $p++;
                $literal = '';
                while ($p < $patLen) {
                    if ($pattern[$p] === "'") {
                        if ($p + 1 < $patLen && $pattern[$p + 1] === "'") {
                            $literal .= "'";
                            $p += 2;
                            continue;
                        }
                        $p++;
                        break;
                    }
                    $literal .= $pattern[$p];
                    $p++;
                }
                $tokens[] = ['type' => 'literal', 'len' => 0, 'value' => $literal];
                continue;
            }
            // Only ASCII letters are CLDR pattern letters; high-bit
            // bytes (UTF-8 continuation, U+202F, etc.) are literal
            // text. ctype_alpha is locale-dependent and accepts
            // \xE2 as alpha on macOS, which previously corrupted
            // multi-byte literal tokens.
            $isAscii = ord($ch) < 0x80;
            $isAsciiAlpha = $isAscii && ctype_alpha($ch);
            if ($isAsciiAlpha) {
                $j = $p;
                while ($j < $patLen && $pattern[$j] === $ch) {
                    $j++;
                }
                $tokens[] = ['type' => self::patternLetterToPartType($ch), 'len' => $j - $p, 'letter' => $ch];
                $p = $j;
                continue;
            }
            // Run of non-letter literal chars.
            $j = $p;
            while ($j < $patLen) {
                $b = $pattern[$j];
                $isB = ord($b) < 0x80 && ctype_alpha($b);
                if ($isB || $b === "'") {
                    break;
                }
                $j++;
            }
            $tokens[] = ['type' => 'literal', 'len' => 0, 'value' => substr($pattern, $p, $j - $p)];
            $p = $j;
        }

        // Walk the formatted output one character cluster at a time,
        // consuming tokens. For literal tokens the cluster must match
        // the literal verbatim; for typed tokens we accept any run of
        // characters until the next literal lookahead matches.
        $cursor = 0;
        $outLen = strlen($formatted);
        for ($ti = 0; $ti < count($tokens); $ti++) {
            $tok = $tokens[$ti];
            if ($tok['type'] === 'literal') {
                $lit = $tok['value'] ?? '';
                if ($lit === '') {
                    continue;
                }
                // ICU formatters often substitute the ASCII space in
                // the pattern with U+00A0 / U+202F, so allow any
                // whitespace cluster to match a pattern space.
                if (substr($formatted, $cursor, strlen($lit)) === $lit) {
                    $emit('literal', $lit);
                    $cursor += strlen($lit);
                } elseif (
                    preg_match('/^[\s\x{00A0}\x{202F}]/u', $lit) === 1
                    && preg_match(
                        '/^[\s\x{00A0}\x{202F}]+/u',
                        substr($formatted, $cursor),
                        $wsMatch,
                    ) === 1
                ) {
                    $emit('literal', $wsMatch[0]);
                    $cursor += strlen($wsMatch[0]);
                } else {
                    if ($cursor < $outLen) {
                        $emit('literal', substr($formatted, $cursor));
                        $cursor = $outLen;
                    }
                    break;
                }
                continue;
            }
            // Typed token: consume up to the next literal lookahead.
            $lookahead = '';
            for ($k = $ti + 1; $k < count($tokens); $k++) {
                if (
                    $tokens[$k]['type'] === 'literal'
                    && isset($tokens[$k]['value'])
                    && $tokens[$k]['value'] !== ''
                ) {
                    $lookahead = $tokens[$k]['value'];
                    break;
                }
            }
            $endPos = $outLen;
            if ($lookahead !== '') {
                $found = strpos($formatted, $lookahead, $cursor);
                if ($found === false && preg_match('/^[\s\x{00A0}\x{202F}]/u', $lookahead) === 1) {
                    if (
                        preg_match(
                            '/[\s\x{00A0}\x{202F}]/u',
                            substr($formatted, $cursor),
                            $wsAfter,
                            PREG_OFFSET_CAPTURE,
                        ) === 1
                    ) {
                        $found = $cursor + $wsAfter[0][1];
                    }
                }
                if ($found !== false) {
                    $endPos = $found;
                }
            }
            // When the next token is also typed (no literal between),
            // split the consumed run at a transition boundary: digit
            // → non-digit for relatedYear → yearName ("2019己亥").
            $nextTok = $tokens[$ti + 1] ?? null;
            if (
                $nextTok !== null
                && $nextTok['type'] !== 'literal'
                && $tok['type'] !== $nextTok['type']
            ) {
                $splitPos = self::findTypedTokenSplit(
                    $formatted,
                    $cursor,
                    $endPos,
                    $tok['type'],
                    $nextTok['type'],
                );
                if ($splitPos !== null && $splitPos > $cursor && $splitPos < $endPos) {
                    $endPos = $splitPos;
                }
            }
            $value = substr($formatted, $cursor, $endPos - $cursor);
            if ($value !== '') {
                $emit($tok['type'], $value);
            } elseif (
                $tok['type'] === 'era'
                && self::calendarHasIcu4xPreEra($calendar)
            ) {
                // ICU4C does not emit an era marker for dates before
                // the Coptic/Ethiopic epoch (year 1 AM), but ICU4X (V8)
                // emits the spec-defined ERA0 code. Inject it so
                // formatToParts matches V8/test262 expectations.
                $emit('era', 'ERA0');
            }
            $cursor = $endPos;
        }
        if ($cursor < $outLen) {
            $emit('literal', substr($formatted, $cursor));
        }
        // iso8601 calendar parts: ICU 74 emits single-digit month/day
        // values from the default short pattern ("M" / "d") while
        // ICU 76+ emits two-digit ("MM" / "dd"). Test262 (and the spec
        // in general) expect two-digit. Zero-pad the iso8601 month/day
        // parts so the engine output is consistent across host ICU
        // versions.
        if (strtolower($calendar) === 'iso8601') {
            for ($pi = 0; $pi < $idx; $pi++) {
                $part = $parts->get((string) $pi);
                if (!$part instanceof JsObject) {
                    continue;
                }
                $typeVal = $part->get('type');
                if (!$typeVal instanceof JsString) {
                    continue;
                }
                if ($typeVal->value !== 'month' && $typeVal->value !== 'day') {
                    continue;
                }
                $valueVal = $part->get('value');
                if (!$valueVal instanceof JsString) {
                    continue;
                }
                $v = $valueVal->value;
                if (strlen($v) === 1 && ctype_digit($v)) {
                    self::defineDataProp($part, 'value', new JsString('0' . $v));
                }
            }
        }
        $parts->set('length', JsNumber::of((float) $idx));
        return $parts;
    }

    /**
     * Normalise the era abbreviation emitted by ICU4C to the
     * spec-aligned ICU4X identifier (ERA0/ERA1/...) for non-ISO
     * calendars. ICU4C ships localized era names like "AM" for
     * coptic/ethiopic; ICU4X (which V8 ships in Node 22+) uses
     * stable identifiers. Mirror V8's mapping so test262 fixtures
     * authored against ICU4X output keep passing.
     */
    private static function mapEraToIcu4xCode(string $calendar, string $era): string
    {
        $normCalendar = $calendar;
        if ($normCalendar === 'ethiopic-amete-alem') {
            $normCalendar = 'ethioaa';
        }
        static $map = [
            'coptic' => [
                'AM' => 'ERA1',
                'A.M.' => 'ERA1',
                'Anno Martyrum' => 'ERA1',
                'BAM' => 'ERA0',
                'A.M.E.' => 'ERA1',
            ],
            'ethiopic' => [
                'AM' => 'ERA1',
                'A.M.' => 'ERA1',
                'Amete Mihret' => 'ERA1',
                'ERA0' => 'ERA0',
            ],
            'ethioaa' => [
                'AA' => 'ERA0',
                'A.A.' => 'ERA0',
                'Amete Alem' => 'ERA0',
                'AM' => 'ERA0',
            ],
            'indian' => [
                // ICU4C ships the indian-era abbreviation with the
                // Latin Capital Letter S With Acute (U+015A); V8 /
                // ICU4X drop the diacritic.
                "\u{015A}aka" => 'Saka',
            ],
        ];
        if (!isset($map[$normCalendar])) {
            return $era;
        }
        return $map[$normCalendar][$era] ?? $era;
    }

    /**
     * Calendars where ICU4C reports an empty era for dates before
     * the calendar epoch but ICU4X (V8) emits "ERA0".
     */
    private static function calendarHasIcu4xPreEra(string $calendar): bool
    {
        $normCalendar = $calendar;
        if ($normCalendar === 'ethiopic-amete-alem') {
            $normCalendar = 'ethioaa';
        }
        return in_array(
            $normCalendar,
            ['coptic', 'ethiopic'],
            true,
        );
    }

    /**
     * Find a byte offset that splits two adjacent typed pattern
     * tokens in the formatted output. For digit-bearing → non-digit
     * boundaries (e.g. relatedYear "2019" → yearName "己亥"), walk
     * forward through digits until the first non-digit byte. Returns
     * null when no clean split exists.
     */
    private static function findTypedTokenSplit(
        string $formatted,
        int $cursor,
        int $endPos,
        string $prevType,
        string $nextType,
    ): ?int {
        $digitTypes = ['year', 'relatedYear', 'day', 'hour', 'minute', 'second', 'fractionalSecond'];
        $alphaTypes = ['yearName', 'month', 'weekday', 'dayPeriod', 'era', 'timeZoneName'];
        $prevIsDigit = in_array($prevType, $digitTypes, true);
        $nextIsAlpha = in_array($nextType, $alphaTypes, true);
        if (!$prevIsDigit || !$nextIsAlpha) {
            return null;
        }
        $i = $cursor;
        while ($i < $endPos) {
            $b = ord($formatted[$i]);
            if ($b < 0x80) {
                if (!ctype_digit($formatted[$i])) {
                    return $i;
                }
                $i++;
                continue;
            }
            // Multi-byte UTF-8 start: clearly non-digit.
            return $i;
        }
        return null;
    }

    /** Map a CLDR pattern letter to the spec's part-type. */
    private static function patternLetterToPartType(string $letter): string
    {
        return match ($letter) {
            'y', 'Y', 'u' => 'year',
            'U' => 'yearName',
            'r' => 'relatedYear',
            'M', 'L' => 'month',
            'd' => 'day',
            'D' => 'day',
            'E', 'c', 'e' => 'weekday',
            'a', 'b', 'B' => 'dayPeriod',
            'h', 'H', 'k', 'K' => 'hour',
            'm' => 'minute',
            's' => 'second',
            'S', 'A' => 'fractionalSecond',
            'z', 'Z', 'v', 'V', 'O', 'X', 'x' => 'timeZoneName',
            'G' => 'era',
            'q', 'Q' => 'quarter',
            'w', 'W' => 'weekOfYear',
            default => 'literal',
        };
    }

    /**
     * Millisecond-precision variant. Used by callers that have
     * fractional-second inputs (Date.format, formatToParts) so the
     * CLDR `S`/`SS`/`SSS` pattern letters resolve correctly.
     */
    private static function formatDateTimeMs(JsObject $dtf, float $timestampMs): string
    {
        $formatter = self::dateTimeFormatterFor($dtf);
        $dt = self::dateTimeFromTimestampMs($timestampMs);
        $result = $formatter->format($dt);
        $secFallback = (int) floor($timestampMs / 1000);
        if ($result === false) {
            return date('Y-m-d H:i:s', $secFallback);
        }
        $result = self::normalizeDateTimeSpaces($result);
        $calendar = self::extractInternalString($dtf, '[[Calendar]]', 'gregory');
        return self::rewriteEraForIcu4x($formatter, $result, $calendar);
    }

    /**
     * Replace ICU4C-style era abbreviations in the formatted output
     * with the ICU4X identifiers that Node 22+ / V8 emit. Match the
     * era position via the formatter pattern's `G` token so the
     * substitution doesn't accidentally rewrite other glyphs.
     */
    private static function rewriteEraForIcu4x(
        \IntlDateFormatter $formatter,
        string $formatted,
        string $calendar,
    ): string {
        $normCalendar = $calendar;
        if ($normCalendar === 'ethiopic-amete-alem') {
            $normCalendar = 'ethioaa';
        }
        if (
            !in_array(
                $normCalendar,
                ['coptic', 'ethiopic', 'ethioaa', 'indian'],
                true,
            )
        ) {
            return $formatted;
        }
        $pattern = $formatter->getPattern();
        if ($pattern === false || strpos($pattern, 'G') === false) {
            return $formatted;
        }
        $eraSlice = self::extractEraSubstring($pattern, $formatted);
        if ($eraSlice === null) {
            return $formatted;
        }
        [$start, $length, $value] = $eraSlice;
        $mapped = self::mapEraToIcu4xCode($calendar, $value);
        if ($mapped === $value && $value !== '') {
            return $formatted;
        }
        if (
            $value === ''
            && self::calendarHasIcu4xPreEra($calendar)
        ) {
            $mapped = 'ERA0';
        }
        if ($mapped === $value) {
            return $formatted;
        }
        return substr($formatted, 0, $start) . $mapped . substr($formatted, $start + $length);
    }

    /**
     * Locate the era substring in $formatted by walking the same
     * CLDR pattern tokens that formatToParts uses. Returns
     * [startOffset, length, value] for the era run, or null when
     * the pattern has no era token or alignment fails. The value
     * may be the empty string when ICU emitted no era glyphs.
     *
     * @return array{0:int,1:int,2:string}|null
     */
    private static function extractEraSubstring(string $pattern, string $formatted): ?array
    {
        $tokens = [];
        $patLen = strlen($pattern);
        $p = 0;
        while ($p < $patLen) {
            $ch = $pattern[$p];
            if ($ch === "'") {
                $p++;
                $literal = '';
                while ($p < $patLen) {
                    if ($pattern[$p] === "'") {
                        if ($p + 1 < $patLen && $pattern[$p + 1] === "'") {
                            $literal .= "'";
                            $p += 2;
                            continue;
                        }
                        $p++;
                        break;
                    }
                    $literal .= $pattern[$p];
                    $p++;
                }
                $tokens[] = ['type' => 'literal', 'value' => $literal];
                continue;
            }
            $isAscii = ord($ch) < 0x80;
            $isAsciiAlpha = $isAscii && ctype_alpha($ch);
            if ($isAsciiAlpha) {
                $j = $p;
                while ($j < $patLen && $pattern[$j] === $ch) {
                    $j++;
                }
                $tokens[] = ['type' => self::patternLetterToPartType($ch), 'letter' => $ch];
                $p = $j;
                continue;
            }
            $j = $p;
            while ($j < $patLen) {
                $b = $pattern[$j];
                $isB = ord($b) < 0x80 && ctype_alpha($b);
                if ($isB || $b === "'") {
                    break;
                }
                $j++;
            }
            $tokens[] = ['type' => 'literal', 'value' => substr($pattern, $p, $j - $p)];
            $p = $j;
        }
        $cursor = 0;
        $outLen = strlen($formatted);
        for ($ti = 0; $ti < count($tokens); $ti++) {
            $tok = $tokens[$ti];
            if ($tok['type'] === 'literal') {
                $lit = $tok['value'] ?? '';
                if ($lit === '') {
                    continue;
                }
                if (substr($formatted, $cursor, strlen($lit)) === $lit) {
                    $cursor += strlen($lit);
                } elseif (
                    preg_match('/^[\s\x{00A0}\x{202F}]/u', $lit) === 1
                    && preg_match(
                        '/^[\s\x{00A0}\x{202F}]+/u',
                        substr($formatted, $cursor),
                        $wsMatch,
                    ) === 1
                ) {
                    $cursor += strlen($wsMatch[0]);
                } else {
                    return null;
                }
                continue;
            }
            $lookahead = '';
            for ($k = $ti + 1; $k < count($tokens); $k++) {
                if (
                    $tokens[$k]['type'] === 'literal'
                    && isset($tokens[$k]['value'])
                    && $tokens[$k]['value'] !== ''
                ) {
                    $lookahead = $tokens[$k]['value'];
                    break;
                }
            }
            $endPos = $outLen;
            if ($lookahead !== '') {
                $found = strpos($formatted, $lookahead, $cursor);
                if ($found === false && preg_match('/^[\s\x{00A0}\x{202F}]/u', $lookahead) === 1) {
                    if (
                        preg_match(
                            '/[\s\x{00A0}\x{202F}]/u',
                            substr($formatted, $cursor),
                            $wsAfter,
                            PREG_OFFSET_CAPTURE,
                        ) === 1
                    ) {
                        $found = $cursor + $wsAfter[0][1];
                    }
                }
                if ($found !== false) {
                    $endPos = $found;
                }
            }
            if ($tok['type'] === 'era') {
                return [$cursor, $endPos - $cursor, substr($formatted, $cursor, $endPos - $cursor)];
            }
            $cursor = $endPos;
        }
        return null;
    }

    /**
     * Replace ICU's narrow no-break space (U+202F) before AM/PM and
     * other day-period markers with a regular space. CLDR ships en-US
     * (and many other locales') day-period separators as U+202F, but
     * V8 normalises this to U+0020 in the output so spec test fixtures
     * (which assert on plain ASCII spaces around AM/PM) pass.
     */
    private static function normalizeDateTimeSpaces(string $formatted): string
    {
        if ($formatted === '') {
            return $formatted;
        }
        // U+202F = "\xE2\x80\xAF".
        return strtr($formatted, [
            "\xE2\x80\xAF" => ' ',
        ]);
    }

    /**
     * Detect whether the given object is one of the Temporal types
     * that DateTimeFormat should handle directly (PlainDate,
     * PlainDateTime, PlainTime, PlainYearMonth, PlainMonthDay,
     * Instant, ZonedDateTime). Identification is by internal slot
     * since the prototype getters can be tampered with.
     */
    /**
     * Throw if two Temporal objects don't share the same brand
     * (per spec, formatRange requires both args to be the same
     * kind: both PlainDate, both Instant, etc.).
     */
    private static function checkSameTemporalType(JsValue $a, JsValue $b): void
    {
        if (!$a instanceof JsObject || !$b instanceof JsObject) {
            return;
        }
        $brands = [
            '[[IsPlainDate]]', '[[IsPlainDateTime]]', '[[IsPlainTime]]',
            '[[IsPlainYearMonth]]', '[[IsPlainMonthDay]]',
            '[[IsZonedDateTime]]',
        ];
        foreach ($brands as $brand) {
            if ($a->has($brand) !== $b->has($brand)) {
                throw new TypeError(
                    'formatRange Temporal arguments must be the same type',
                );
            }
        }
        // Instant: detected by [[EpochNanoseconds]] without ZDT.
        $aIsInstant = $a->has('[[EpochNanoseconds]]') && !$a->has('[[IsZonedDateTime]]');
        $bIsInstant = $b->has('[[EpochNanoseconds]]') && !$b->has('[[IsZonedDateTime]]');
        if ($aIsInstant !== $bIsInstant) {
            throw new TypeError(
                'formatRange Temporal arguments must be the same type',
            );
        }
        // Spec: when both arguments are calendar-bearing Temporal
        // types (PlainDate, PlainDateTime, PlainYearMonth, PlainMonthDay,
        // ZonedDateTime), their [[Calendar]] internal slots must match.
        // Mismatched calendars throw RangeError, not TypeError.
        $calendarBearing = [
            '[[IsPlainDate]]', '[[IsPlainDateTime]]',
            '[[IsPlainYearMonth]]', '[[IsPlainMonthDay]]',
            '[[IsZonedDateTime]]',
        ];
        $aHasCal = false;
        $bHasCal = false;
        foreach ($calendarBearing as $brand) {
            if ($a->has($brand)) {
                $aHasCal = true;
            }
            if ($b->has($brand)) {
                $bHasCal = true;
            }
        }
        if ($aHasCal && $bHasCal) {
            $calA = $a->get('[[Calendar]]');
            $calB = $b->get('[[Calendar]]');
            $calAStr = $calA instanceof JsString ? $calA->value : '';
            $calBStr = $calB instanceof JsString ? $calB->value : '';
            if ($calAStr !== '' && $calBStr !== '' && $calAStr !== $calBStr) {
                throw new RangeError(
                    'formatRange arguments must use the same calendar',
                );
            }
        }
    }

    private static function isTemporalDateLike(JsObject $obj): bool
    {
        return $obj->has('[[IsPlainDate]]')
            || $obj->has('[[IsPlainDateTime]]')
            || $obj->has('[[IsPlainTime]]')
            || $obj->has('[[IsPlainYearMonth]]')
            || $obj->has('[[IsPlainMonthDay]]')
            || $obj->has('[[IsZonedDateTime]]')
            || (
                $obj->has('[[EpochNanoseconds]]')
                && !$obj->has('[[IsZonedDateTime]]')
            );
    }

    /**
     * Format a Temporal object using the DateTimeFormat options.
     * Plain types render with a UTC IntlDateFormatter so the
     * formatter's [[TimeZone]] option doesn't shift the components;
     * Instant/ZonedDateTime use their epoch nanoseconds directly.
     */
    private static function formatTemporal(JsObject $dtf, JsObject $obj): string
    {
        if ($obj->has('[[IsZonedDateTime]]')) {
            // Spec: Temporal.ZonedDateTime is rejected by format()
            // and formatToParts(); the user must call its
            // toLocaleString instead.
            throw new TypeError('Temporal.ZonedDateTime is not supported in DateTimeFormat.format');
        }
        // Validate: PlainTime can't be used with date-only options;
        // PlainDate can't be used with time-only options; etc.
        self::checkTemporalOptionsCompat($dtf, $obj);

        if ($obj->has('[[EpochNanoseconds]]')) {
            // Instant: uses epoch nanoseconds with the formatter's
            // own time zone. Use temporalFormatterFor so default
            // formatters get augmented to include both date AND
            // time fields (per spec, Instant.toLocaleString
            // defaults to a full date-time render).
            $epochNsStr = self::extractInternalString($obj, '[[EpochNanoseconds]]', '0');
            $tsMs = self::epochNsToMs($epochNsStr);
            $formatter = self::temporalFormatterFor($dtf, $obj);
            $dt = self::dateTimeFromTimestampMs($tsMs);
            $result = $formatter->format($dt);
            return $result === false ? '' : self::normalizeDateTimeSpaces($result);
        }
        // Plain types: assemble a UTC timestamp from the date/time
        // slots, then format with a UTC-locked formatter whose
        // pattern has been narrowed to the unit's representable
        // fields (e.g. PlainDate strips time tokens; PlainTime
        // strips date tokens).
        [$y, $m, $d, $h, $min, $s, $ms] = self::temporalPlainComponents($obj);
        // Per spec, PlainDateTime.toLocaleString with a formatter that
        // declares an explicit timeZone runs the wall time through
        // "compatible" disambiguation in that zone. For a date that
        // falls in a DST gap (e.g. 2020-03-08 02:30 in LA), this
        // shifts the rendered time forward into the post-gap offset.
        if ($obj->has('[[IsPlainDateTime]]')) {
            $tzVal = self::extractInternalString($dtf, '[[TimeZone]]', 'UTC');
            if ($tzVal !== 'UTC' && extension_loaded('intl')) {
                $shifted = self::compatibleAdjustForTimeZone($y, $m, $d, $h, $min, $s, $ms, $tzVal);
                if ($shifted !== null) {
                    [$y, $m, $d, $h, $min, $s, $ms] = $shifted;
                }
            }
        }
        $dt = new \DateTimeImmutable(
            sprintf('%04d-%02d-%02dT%02d:%02d:%02d.%06dZ', $y, $m, $d, $h, $min, $s, $ms * 1000),
            new \DateTimeZone('UTC'),
        );
        $formatter = self::temporalFormatterFor($dtf, $obj);
        $result = $formatter->format($dt);
        return $result === false ? '' : self::normalizeDateTimeSpaces($result);
    }

    /**
     * Apply "compatible" disambiguation: if the wall time is skipped
     * by a forward DST transition in the named time zone, shift to
     * the later side; if it's repeated by a back transition, keep the
     * earlier side. Returns adjusted components or null if intl can't
     * resolve the zone. This mirrors Temporal's GetEpochNanosecondsFor
     * with disambiguation: "compatible".
     *
     * @return array{0:int,1:int,2:int,3:int,4:int,5:int,6:int}|null
     */
    private static function compatibleAdjustForTimeZone(
        int $y,
        int $mo,
        int $d,
        int $h,
        int $min,
        int $s,
        int $ms,
        string $tz,
    ): ?array {
        try {
            $zone = new \DateTimeZone($tz);
        } catch (\Throwable) {
            return null;
        }
        // Build a wall-time DateTime in the named zone to detect a gap.
        // PHP normalises a non-existent local time forward (the same as
        // "compatible"), so format() round-trips to the post-gap clock.
        try {
            $dt = new \DateTimeImmutable(
                sprintf('%04d-%02d-%02dT%02d:%02d:%02d', $y, $mo, $d, $h, $min, $s),
                $zone,
            );
        } catch (\Throwable) {
            return null;
        }
        $rh = (int) $dt->format('H');
        $rmin = (int) $dt->format('i');
        $rs = (int) $dt->format('s');
        $ry = (int) $dt->format('Y');
        $rmo = (int) $dt->format('n');
        $rd = (int) $dt->format('j');
        if ($rh === $h && $rmin === $min && $rs === $s) {
            return null;
        }
        return [$ry, $rmo, $rd, $rh, $rmin, $rs, $ms];
    }

    /**
     * Build an IntlDateFormatter scoped to the fields a Temporal
     * plain type can render. PlainDate keeps date pattern letters
     * (yMd, weekday, era), drops time letters (hms, S, a). PlainTime
     * is the inverse. Forces the time zone to UTC so the assembled
     * components don't shift.
     */
    private static function temporalFormatterFor(JsObject $dtf, JsObject $obj): \IntlDateFormatter
    {
        $base = self::dateTimeFormatterFor($dtf);
        // Plain types render with a UTC formatter so component
        // values aren't shifted by the formatter's TimeZone option.
        // Instant uses the formatter's own zone (it carries an
        // absolute moment, so the zone is meaningful).
        $isInstantBrand = $obj->has('[[EpochNanoseconds]]') && !$obj->has('[[IsZonedDateTime]]');
        if (!$isInstantBrand) {
            $base->setTimeZone(new \DateTimeZone('UTC'));
        }
        $pattern = $base->getPattern();
        if (!is_string($pattern) || $pattern === '') {
            return $base;
        }
        $isPlainDate = $obj->has('[[IsPlainDate]]');
        $isPlainDateTime = $obj->has('[[IsPlainDateTime]]');
        $isPlainTime = $obj->has('[[IsPlainTime]]');
        $isPlainYearMonth = $obj->has('[[IsPlainYearMonth]]');
        $isPlainMonthDay = $obj->has('[[IsPlainMonthDay]]');
        $isInstant = $obj->has('[[EpochNanoseconds]]') && !$obj->has('[[IsZonedDateTime]]');
        $timeLetters = ['h', 'H', 'K', 'k', 'm', 's', 'S', 'a', 'B', 'z', 'Z', 'O', 'v', 'V', 'X', 'x'];
        $dateLetters = ['G', 'y', 'Y', 'u', 'r', 'M', 'L', 'd', 'D', 'F', 'g', 'E', 'e', 'c', 'w', 'W', 'Q', 'q', 'U'];
        $tzLetters = ['z', 'Z', 'O', 'v', 'V', 'X', 'x'];
        $weekdayLetters = ['E', 'e', 'c'];
        $stripLetters = [];
        $augmentSkeleton = '';
        $forceSkeleton = '';
        $userExplicit = self::dtfHasExplicitOptions($dtf);
        if ($isPlainDate) {
            $stripLetters = $timeLetters;
            if (!$userExplicit) {
                $forceSkeleton = 'yMd';
            }
        } elseif ($isPlainTime) {
            // Plain types carry no time zone; strip date letters AND
            // timezone letters so timeStyle=full doesn't tack on a
            // timezone name from the formatter's [[TimeZone]]. The "j"
            // skeleton in jms tells ICU to pick the locale's preferred
            // hour cycle (h vs H) instead of forcing 12-hour everywhere.
            $stripLetters = array_merge($dateLetters, $tzLetters);
            if (!$userExplicit) {
                $forceSkeleton = 'jms';
            }
        } elseif ($isPlainYearMonth) {
            $stripLetters = array_merge($timeLetters, ['d', 'D', 'F', 'g', 'E', 'e', 'c', 'w', 'W']);
            if (!$userExplicit) {
                $forceSkeleton = 'yM';
            }
        } elseif ($isPlainMonthDay) {
            // PlainMonthDay has no year, no time zone, and no weekday;
            // strip the weekday letters so dateStyle=full doesn't add
            // a "Friday" prefix that PlainMonthDay can't render.
            $stripLetters = array_merge(
                $timeLetters,
                ['G', 'y', 'Y', 'u', 'r', 'U'],
                $weekdayLetters,
            );
            if (!$userExplicit) {
                $forceSkeleton = 'Md';
            }
        } elseif ($isPlainDateTime || $isInstant) {
            // PlainDateTime/Instant: when the formatter has NO
            // explicit options (default {year, month, day}), augment
            // so the full datetime renders. If the user set
            // dateStyle/timeStyle or any individual component,
            // respect that exactly.
            if ($userExplicit) {
                if ($isPlainDateTime) {
                    // PlainDateTime carries no time zone; strip the
                    // timezone letters so timeStyle=full doesn't add
                    // a name for the formatter's [[TimeZone]] option.
                    $hadZone = self::patternHasAnyOf($pattern, $tzLetters);
                    $newPattern = self::stripPatternLetters($pattern, $tzLetters);
                    // When the source pattern had timezone letters
                    // (timeStyle=long or =full), expand the dateStyle's
                    // 2-digit "yy" to 4-digit "y". Spec-test fixtures
                    // assert a 4-digit year in short+long and short+full
                    // PlainDateTime renders even though ICU's combined
                    // pattern keeps the dateStyle's "yy".
                    if ($hadZone) {
                        $newPattern = preg_replace(
                            "/(?<!')y{2}(?!y)/",
                            'y',
                            $newPattern,
                        ) ?? $newPattern;
                    }
                    if ($newPattern !== $pattern) {
                        $base->setPattern($newPattern);
                    }
                }
                return $base;
            }
            $hasDate = self::patternHasAnyOf($pattern, $dateLetters);
            $hasTime = self::patternHasAnyOf($pattern, $timeLetters);
            if ($hasDate && !$hasTime) {
                // "j" picks the locale's preferred hour cycle so de-AT
                // gets HH:mm:ss while en-US gets h:mm:ss a.
                $augmentSkeleton = 'jms';
            } elseif ($hasTime && !$hasDate) {
                $augmentSkeleton = 'yMd';
            } else {
                return $base;
            }
        } else {
            return $base;
        }
        $skeleton = $forceSkeleton !== ''
            ? $forceSkeleton
            : self::extractPatternSkeleton($pattern, $stripLetters);
        if ($augmentSkeleton !== '') {
            $skeleton .= $augmentSkeleton;
        }
        $locale = str_replace('-', '_', self::extractInternalString($dtf, '[[Locale]]', 'en'));
        if ($skeleton !== '' && class_exists('IntlDatePatternGenerator')) {
            try {
                $gen = new \IntlDatePatternGenerator($locale);
                $newPattern = $gen->getBestPattern($skeleton);
                if (is_string($newPattern) && $newPattern !== '') {
                    $base->setPattern($newPattern);
                    return $base;
                }
            } catch (\Throwable) {
            }
        }
        if ($stripLetters !== []) {
            $newPattern = self::stripPatternLetters($pattern, $stripLetters);
            $base->setPattern($newPattern);
        }
        return $base;
    }

    /**
     * Detect whether the user passed explicit format options to the
     * DateTimeFormat constructor. Used by Temporal augmentation to
     * decide if PlainDateTime should add the missing date/time kind.
     * The constructor sets [[ComponentsDefaulted]] = true when it
     * had to fall back to year/month/day defaults; that flag plus
     * the absence of any date/time style is what marks an explicit
     * user choice as "no, none of these".
     */
    private static function dtfHasExplicitOptions(JsObject $dtf): bool
    {
        $defaulted = $dtf->get('[[ComponentsDefaulted]]');
        if ($defaulted instanceof JsBoolean && $defaulted->value === true) {
            return false;
        }
        $slots = [
            '[[DateStyle]]', '[[TimeStyle]]', '[[year]]', '[[month]]',
            '[[day]]', '[[weekday]]', '[[era]]', '[[hour]]', '[[minute]]',
            '[[second]]', '[[fractionalSecondDigits]]', '[[dayPeriod]]',
            '[[timeZoneName]]',
        ];
        foreach ($slots as $slot) {
            if (!$dtf->get($slot) instanceof JsUndefined) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<string> $letters
     */
    private static function patternHasAnyOf(string $pattern, array $letters): bool
    {
        $set = array_flip($letters);
        $len = strlen($pattern);
        $inQuote = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $pattern[$i];
            if ($c === "'") {
                $inQuote = !$inQuote;
                continue;
            }
            if (!$inQuote && isset($set[$c])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Walk the pattern, collect each surviving letter run as a
     * skeleton fragment (e.g. "MMM" + "d" + "y" → "MMMdy"). The
     * resulting skeleton is what IntlDatePatternGenerator expects.
     *
     * @param list<string> $stripLetters
     */
    private static function extractPatternSkeleton(string $pattern, array $stripLetters): string
    {
        $stripSet = array_flip($stripLetters);
        $out = '';
        $len = strlen($pattern);
        $inQuote = false;
        $i = 0;
        while ($i < $len) {
            $c = $pattern[$i];
            if ($c === "'") {
                if ($inQuote && $i + 1 < $len && $pattern[$i + 1] === "'") {
                    $i += 2;
                    continue;
                }
                $inQuote = !$inQuote;
                $i++;
                continue;
            }
            if ($inQuote) {
                $i++;
                continue;
            }
            $isLetter = ($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z');
            if (!$isLetter) {
                $i++;
                continue;
            }
            $j = $i;
            while ($j < $len && $pattern[$j] === $c) {
                $j++;
            }
            if (!isset($stripSet[$c])) {
                $out .= substr($pattern, $i, $j - $i);
            }
            $i = $j;
        }
        return $out;
    }

    /**
     * Remove the given pattern-letter runs from a CLDR pattern,
     * keeping quoted literals intact and collapsing the surrounding
     * separators (commas, "at", connector words) when possible.
     *
     * @param list<string> $letters
     */
    private static function stripPatternLetters(string $pattern, array $letters): string
    {
        $stripSet = array_flip($letters);
        // Walk the pattern and collect tokens (quoted literals,
        // unquoted runs of pattern letters, or unquoted "other"
        // separators). Then drop tokens whose pattern letter is in
        // the strip set, plus any separator tokens left dangling
        // (e.g. "/" between two stripped runs).
        $tokens = [];
        $len = strlen($pattern);
        $inQuote = false;
        $i = 0;
        while ($i < $len) {
            $c = $pattern[$i];
            if ($c === "'") {
                if ($inQuote && $i + 1 < $len && $pattern[$i + 1] === "'") {
                    $tokens[] = ['kind' => 'quote', 'value' => "''"];
                    $i += 2;
                    continue;
                }
                $inQuote = !$inQuote;
                $i++;
                continue;
            }
            if ($inQuote) {
                $j = $i;
                while ($j < $len && $pattern[$j] !== "'") {
                    $j++;
                }
                $tokens[] = ['kind' => 'literal', 'value' => substr($pattern, $i, $j - $i)];
                $i = $j;
                continue;
            }
            // Pattern letter runs are ASCII A-Z / a-z only; UTF-8
            // continuation bytes (0x80+) are non-letter separators.
            $isLetter = ($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z');
            if ($isLetter) {
                $j = $i;
                while ($j < $len && $pattern[$j] === $c) {
                    $j++;
                }
                $tokens[] = ['kind' => 'letter', 'letter' => $c, 'value' => substr($pattern, $i, $j - $i)];
                $i = $j;
                continue;
            }
            $j = $i;
            while ($j < $len) {
                $cc = $pattern[$j];
                if ($cc === "'") {
                    break;
                }
                $isLetterCC = ($cc >= 'a' && $cc <= 'z') || ($cc >= 'A' && $cc <= 'Z');
                if ($isLetterCC) {
                    break;
                }
                $j++;
            }
            $tokens[] = ['kind' => 'separator', 'value' => substr($pattern, $i, $j - $i)];
            $i = $j;
        }
        // Mark letter tokens as kept or stripped.
        foreach ($tokens as $idx => $t) {
            if ($t['kind'] === 'letter' && isset($stripSet[$t['letter']])) {
                $tokens[$idx]['kind'] = 'stripped';
            }
        }
        // Drop separator tokens that no longer sit between two
        // surviving letter tokens.
        $hasKeptBefore = false;
        $output = [];
        $pendingSeparator = null;
        foreach ($tokens as $t) {
            if ($t['kind'] === 'separator') {
                $pendingSeparator = $t['value'];
                continue;
            }
            if ($t['kind'] === 'stripped') {
                continue;
            }
            // Keeper (letter / literal / quote).
            if ($pendingSeparator !== null && $hasKeptBefore) {
                $output[] = $pendingSeparator;
            }
            $pendingSeparator = null;
            // Quoted literals lose their surrounding apostrophes during
            // tokenisation; restore them so the assembled pattern still
            // tells ICU the run is a literal (otherwise letters like 'a'
            // inside the literal would become pattern letters again).
            if ($t['kind'] === 'literal') {
                $output[] = "'" . $t['value'] . "'";
            } else {
                $output[] = $t['value'];
            }
            $hasKeptBefore = true;
        }
        $assembled = implode('', $output);
        // Collapse double whitespace that may have appeared.
        $assembled = preg_replace('/[\s\x{00A0}\x{202F}]{2,}/u', ' ', $assembled) ?? $assembled;
        return trim($assembled);
    }

    /**
     * Decompose a Temporal plain type into [y, m, d, h, min, s, ms].
     * Default missing components to safe values (year 1972 for
     * PlainMonthDay, month/day 1/1 for PlainYearMonth, etc.).
     *
     * @return array{0:int,1:int,2:int,3:int,4:int,5:int,6:int}
     */
    private static function temporalPlainComponents(JsObject $obj): array
    {
        $getInt = static function (string $slot, int $default) use ($obj): int {
            $v = $obj->get($slot);
            return $v instanceof JsNumber ? (int) $v->value : $default;
        };
        $y = $getInt('[[ISOYear]]', 1972);
        $m = $getInt('[[ISOMonth]]', 1);
        $d = $getInt('[[ISODay]]', 1);
        $h = $getInt('[[ISOHour]]', 0);
        $min = $getInt('[[ISOMinute]]', 0);
        $s = $getInt('[[ISOSecond]]', 0);
        $ms = $getInt('[[ISOMillisecond]]', 0);
        return [$y, $m, $d, $h, $min, $s, $ms];
    }

    /**
     * Validate that the formatter's option set is compatible with
     * the Temporal type being formatted. PlainDate can't be passed
     * to a time-only formatter; PlainTime can't be passed to a
     * date-only formatter; etc.
     */
    private static function checkTemporalOptionsCompat(JsObject $dtf, JsObject $obj): void
    {
        // When the formatter's components were defaulted (no user
        // options), it adapts to the Temporal type so any plain
        // type renders without throwing.
        $defaulted = $dtf->get('[[ComponentsDefaulted]]');
        if ($defaulted instanceof JsBoolean && $defaulted->value === true) {
            return;
        }
        $hasTimeStyle = !$dtf->get('[[TimeStyle]]') instanceof JsUndefined;
        $hasDateStyle = !$dtf->get('[[DateStyle]]') instanceof JsUndefined;
        $hasYear = !$dtf->get('[[year]]') instanceof JsUndefined;
        $hasMonth = !$dtf->get('[[month]]') instanceof JsUndefined;
        $hasDay = !$dtf->get('[[day]]') instanceof JsUndefined;
        $hasWeekday = !$dtf->get('[[weekday]]') instanceof JsUndefined;
        $hasEra = !$dtf->get('[[era]]') instanceof JsUndefined;
        $hasHour = !$dtf->get('[[hour]]') instanceof JsUndefined;
        $hasMinute = !$dtf->get('[[minute]]') instanceof JsUndefined;
        $hasSecond = !$dtf->get('[[second]]') instanceof JsUndefined;
        $hasFractionalSec = !$dtf->get('[[fractionalSecondDigits]]') instanceof JsUndefined;
        $hasDayPeriod = !$dtf->get('[[dayPeriod]]') instanceof JsUndefined;
        $hasAnyTime = $hasHour || $hasMinute || $hasSecond
            || $hasFractionalSec || $hasDayPeriod || $hasTimeStyle;
        $hasAnyDate = $hasYear || $hasMonth || $hasDay || $hasWeekday
            || $hasEra || $hasDateStyle;
        // Spec rule: throw when the intersection of the formatter's
        // requested fields and the Temporal type's data model is
        // empty. The type can still render with extra-but-irrelevant
        // formatter fields as long as at least one field overlaps.
        if ($obj->has('[[IsPlainDate]]')) {
            // PlainDate carries year/month/day/weekday/era.
            if (!$hasAnyDate) {
                throw new TypeError('Temporal.PlainDate has no overlap with formatter options');
            }
        }
        if ($obj->has('[[IsPlainTime]]')) {
            // PlainTime carries hour/minute/second/fractionalSec/dayPeriod.
            if (!$hasAnyTime) {
                throw new TypeError('Temporal.PlainTime has no overlap with formatter options');
            }
        }
        if ($obj->has('[[IsPlainYearMonth]]')) {
            // PlainYearMonth carries year/month/era.
            // Reject formatters that ONLY ask for day/weekday or any
            // time field with no year/month/era overlap. dateStyle is
            // an implicit overlap because it includes month+year.
            $hasYearMonth = $hasYear || $hasMonth || $hasEra || $hasDateStyle;
            if (!$hasYearMonth) {
                throw new TypeError('Temporal.PlainYearMonth has no overlap with formatter options');
            }
        }
        if ($obj->has('[[IsPlainMonthDay]]')) {
            // PlainMonthDay carries month/day.
            $hasMonthDay = $hasMonth || $hasDay || $hasDateStyle;
            if (!$hasMonthDay) {
                throw new TypeError('Temporal.PlainMonthDay has no overlap with formatter options');
            }
        }
    }

    /**
     * Convert an epoch-nanoseconds decimal string to milliseconds.
     * Uses BC math so values past 2^53 don't lose precision.
     */
    private static function epochNsToMs(string $ns): float
    {
        if (function_exists('bcdiv')) {
            $ms = bcdiv($ns, '1000000', 0);
            return (float) $ms;
        }
        return (float) $ns / 1000000;
    }

    /**
     * Build a DateTimeImmutable from a millisecond-precision
     * timestamp using the formatter's resolved time zone. Spec
     * TimeClip applies ToIntegerOrInfinity (truncate toward zero),
     * so a value of -0.9 collapses to 0 rather than -1.
     */
    private static function dateTimeFromTimestampMs(int|float $ms): \DateTimeImmutable
    {
        $msInt = $ms >= 0 ? (int) floor($ms) : (int) ceil($ms);
        $sec = (int) ($msInt / 1000);
        $micros = ($msInt - $sec * 1000) * 1000;
        if ($micros < 0) {
            $micros += 1_000_000;
            $sec--;
        }
        $dt = new \DateTimeImmutable('@' . $sec, new \DateTimeZone('UTC'));
        if ($micros !== 0) {
            $dt = $dt->modify('+' . $micros . ' microseconds');
        }
        return $dt;
    }

    /**
     * Construct an IntlDateFormatter that mirrors the DTF's options.
     * Honours dateStyle / timeStyle as preset constants, otherwise
     * builds a custom skeleton from the explicit component slots
     * ([[year]], [[hour]], [[dayPeriod]], ...) via IntlDatePatternGenerator.
     */
    /**
     * Rewrite a CLDR date pattern's hour letters per the requested
     * hourCycle. Strips the trailing day-period token (a/B) when
     * switching from a 12-hour cycle to a 24-hour one so the result
     * doesn't carry an orphan AM/PM marker.
     */
    private static function rewriteHourCycleInPattern(string $pattern, string $hourCycle): string
    {
        // Letter mapping: h(12: 1-12), K(11: 0-11), H(23: 0-23), k(24: 1-24).
        $targetLetter = match ($hourCycle) {
            'h11' => 'K',
            'h12' => 'h',
            'h23' => 'H',
            'h24' => 'k',
            default => null,
        };
        if ($targetLetter === null) {
            return $pattern;
        }
        // Replace all hour letter runs (h/H/K/k) keeping the run's
        // length so "hh" -> "HH", "h" -> "H", etc. Quoted text
        // ('AM'/'PM' literal) is left alone.
        $out = '';
        $len = strlen($pattern);
        $inQuote = false;
        $i = 0;
        while ($i < $len) {
            $c = $pattern[$i];
            if ($c === "'") {
                if ($inQuote && $i + 1 < $len && $pattern[$i + 1] === "'") {
                    $out .= "''";
                    $i += 2;
                    continue;
                }
                $inQuote = !$inQuote;
                $out .= $c;
                $i++;
                continue;
            }
            if (!$inQuote && in_array($c, ['h', 'H', 'K', 'k'], true)) {
                $j = $i;
                while ($j < $len && $pattern[$j] === $c) {
                    $j++;
                }
                $out .= str_repeat($targetLetter, $j - $i);
                $i = $j;
                continue;
            }
            $out .= $c;
            $i++;
        }
        // For 24-hour cycles, drop dayPeriod tokens (a/B) and any
        // literal whitespace surrounding them.
        if ($hourCycle === 'h23' || $hourCycle === 'h24') {
            $out = self::stripDayPeriodTokens($out);
        }
        return $out;
    }

    private static function stripDayPeriodTokens(string $pattern): string
    {
        // Match optional whitespace + run of 'a'/'B' letters + optional whitespace.
        $cleaned = preg_replace(
            '/(\s*\b[aB]+\b\s*)/u',
            ' ',
            $pattern,
        );
        if (!is_string($cleaned)) {
            return $pattern;
        }
        // Collapse double spaces and trim.
        $cleaned = preg_replace('/\s+/u', ' ', $cleaned);
        if (!is_string($cleaned)) {
            return $pattern;
        }
        return trim($cleaned);
    }

    private static function dateTimeFormatterFor(JsObject $dtf): \IntlDateFormatter
    {
        $localeRaw = self::extractInternalString($dtf, '[[Locale]]', 'en');
        $calendar = self::extractInternalString($dtf, '[[Calendar]]', 'gregory');
        $numberingSystem = self::extractInternalString($dtf, '[[NumberingSystem]]', 'latn');
        $locale = str_replace('-', '_', $localeRaw);
        // iso8601 is a Gregorian-equivalent calendar in ICU; keep
        // the GREGORIAN backend so date formatting matches normal
        // Gregorian patterns. Other non-Gregorian calendars route
        // through TRADITIONAL with an ICU "@calendar=…" suffix.
        $needsTraditional = $calendar !== 'gregory'
            && $calendar !== ''
            && $calendar !== 'iso8601';
        // Collect ICU keyword=value pairs so the locale ends up as
        // "base@k1=v1;k2=v2". Encoding [[NumberingSystem]] here lets
        // IntlDateFormatter emit non-Latn digits (arab, deva, hanidec,
        // ...) without a post-format digit-translation pass.
        $icuKeywords = [];
        if ($needsTraditional) {
            // ICU recognizes "ethiopic-amete-alem" not "ethioaa"
            // for the @calendar= suffix; everything else passes
            // through verbatim.
            static $icuCalendarMap = [
                'ethioaa' => 'ethiopic-amete-alem',
            ];
            $icuKeywords['calendar'] = $icuCalendarMap[$calendar] ?? $calendar;
        }
        if ($numberingSystem !== '' && $numberingSystem !== 'latn') {
            $icuKeywords['numbers'] = $numberingSystem;
        }
        if (!empty($icuKeywords) && !str_contains($locale, '@')) {
            $parts = [];
            foreach ($icuKeywords as $k => $v) {
                $parts[] = $k . '=' . $v;
            }
            $locale .= '@' . implode(';', $parts);
        }
        $calendarKind = $needsTraditional
            ? \IntlDateFormatter::TRADITIONAL
            : \IntlDateFormatter::GREGORIAN;
        // Use a proleptic Gregorian calendar (no Julian/Gregorian cutover
        // in 1582) so ECMAScript dates before 1582 format with the right
        // year/era. ICU's default GregorianCalendar treats pre-1582 dates
        // as Julian, which can shift the year by several units for very
        // ancient timestamps.
        $prolepticCalendar = null;
        if (!$needsTraditional && class_exists('IntlGregorianCalendar')) {
            try {
                // Strip BCP47 -u-…/-x-… subtags (after `_u_` or `_x_` in
                // ICU underscore form) so createInstance returns a real
                // IntlGregorianCalendar. With `@calendar=` or `_u_ca_X`
                // present, ICU returns a base IntlCalendar instead, which
                // doesn't expose setGregorianChange.
                $localeForCal = preg_replace('/_(?:u|x)_.*$/', '', $locale);
                if (!is_string($localeForCal)) {
                    $localeForCal = $locale;
                }
                $prolepticCalendar = \IntlGregorianCalendar::createInstance(
                    'UTC',
                    $localeForCal,
                );
                $prolepticCalendar->setGregorianChange(PHP_INT_MIN);
            } catch (\Throwable) {
                $prolepticCalendar = null;
            }
        }
        $tz = self::extractInternalString($dtf, '[[TimeZone]]', 'UTC');
        if (preg_match('/^[+-]\d{2}:\d{2}$/', $tz) === 1) {
            $tz = 'GMT' . $tz;
        }
        $dateStyle = $dtf->get('[[DateStyle]]');
        $timeStyle = $dtf->get('[[TimeStyle]]');
        $dateStyle = $dateStyle instanceof JsString ? $dateStyle->value : null;
        $timeStyle = $timeStyle instanceof JsString ? $timeStyle->value : null;
        $mapStyle = static function (?string $s): int {
            return match ($s) {
                'full' => \IntlDateFormatter::FULL,
                'long' => \IntlDateFormatter::LONG,
                'medium' => \IntlDateFormatter::MEDIUM,
                'short' => \IntlDateFormatter::SHORT,
                default => \IntlDateFormatter::NONE,
            };
        };
        $calendarParam = $prolepticCalendar ?? $calendarKind;
        if ($prolepticCalendar !== null) {
            try {
                $prolepticCalendar->setTimeZone(new \DateTimeZone($tz));
            } catch (\Throwable) {
                // If the timezone is unrecognised, drop back to the kind.
                $calendarParam = $calendarKind;
            }
        }
        if ($dateStyle !== null || $timeStyle !== null) {
            $base = new \IntlDateFormatter(
                $locale,
                $mapStyle($dateStyle),
                $mapStyle($timeStyle),
                $tz,
                $calendarParam,
            );
            // Honour an explicit hour12 / hourCycle override against
            // the locale's CLDR-derived time pattern. Without this the
            // user's hour-cycle choice silently drops when timeStyle
            // is set.
            $hourCycleVal = $dtf->get('[[HourCycle]]');
            $hourCycle = $hourCycleVal instanceof JsString ? $hourCycleVal->value : null;
            $hourCycleSource = $dtf->get('[[HourCycleSource]]');
            $isExplicit = $hourCycleSource instanceof JsString
                && $hourCycleSource->value === 'option';
            if ($isExplicit && $hourCycle !== null && $timeStyle !== null) {
                $pattern = $base->getPattern();
                if (is_string($pattern) && $pattern !== '') {
                    $newPattern = self::rewriteHourCycleInPattern($pattern, $hourCycle);
                    if ($newPattern !== $pattern) {
                        $base->setPattern($newPattern);
                    }
                }
            }
            return $base;
        }
        // Build a CLDR skeleton from the explicit component slots.
        $skeleton = self::dateTimeSkeletonFromOptions($dtf);
        if ($skeleton === '') {
            return new \IntlDateFormatter(
                $locale,
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::MEDIUM,
                $tz,
                $calendarParam,
            );
        }
        $pattern = '';
        if (class_exists('IntlDatePatternGenerator')) {
            try {
                $gen = new \IntlDatePatternGenerator($locale);
                $pattern = $gen->getBestPattern($skeleton);
            } catch (\Throwable) {
                // Fall through to skeleton-as-pattern below.
            }
        }
        if ($pattern === '' || $pattern === false) {
            $pattern = $skeleton;
        }
        // Honour explicit 2-digit width requests for hour/minute/second/
        // day/month against ICU's locale-preferred pattern, which may
        // collapse "hh" → "h" for en-US etc.
        $pattern = self::enforceExplicitDigitWidths($pattern, $dtf);
        // For non-Gregorian calendars that emit cyclic year names
        // (Chinese / Dangi), expand the year letter "y" run to "rU"
        // so the formatted output includes both the related Gregorian
        // year and the cyclic year name (e.g. "2019己亥年").
        if ($calendar === 'chinese' || $calendar === 'dangi') {
            $pattern = self::expandChineseYearPattern($pattern);
        }
        return new \IntlDateFormatter(
            $locale,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::FULL,
            $tz,
            $calendarParam,
            $pattern,
        );
    }

    /**
     * Replace the first "y" letter run in a CLDR pattern with "rU"
     * so chinese/dangi calendar formatters emit relatedYear + yearName.
     */
    private static function expandChineseYearPattern(string $pattern): string
    {
        $out = '';
        $len = strlen($pattern);
        $inQuote = false;
        $i = 0;
        $expanded = false;
        while ($i < $len) {
            $c = $pattern[$i];
            if ($c === "'") {
                $inQuote = !$inQuote;
                $out .= $c;
                $i++;
                continue;
            }
            if (!$inQuote && !$expanded && ($c === 'y' || $c === 'Y')) {
                while ($i < $len && $pattern[$i] === $c) {
                    $i++;
                }
                $out .= 'rU';
                $expanded = true;
                continue;
            }
            $out .= $c;
            $i++;
        }
        return $out;
    }

    /**
     * After ICU's getBestPattern, ensure each component letter run
     * matches the user's requested width when "2-digit" was asked
     * for. ICU is allowed to substitute single-digit forms when the
     * locale prefers them, but the spec mandates the requested width.
     */
    private static function enforceExplicitDigitWidths(string $pattern, JsObject $dtf): string
    {
        $get = static function (string $slot) use ($dtf): ?string {
            $val = $dtf->get($slot);
            return $val instanceof JsString ? $val->value : null;
        };
        $pairs = [
            ['[[hour]]', ['h', 'H', 'K', 'k']],
            ['[[minute]]', ['m']],
            ['[[second]]', ['s']],
            ['[[day]]', ['d']],
            ['[[month]]', ['M']],
        ];
        foreach ($pairs as [$slot, $letters]) {
            if ($get($slot) !== '2-digit') {
                continue;
            }
            $pattern = self::doubleSingleLetterRuns($pattern, $letters);
        }
        return $pattern;
    }

    /**
     * For each pattern letter in $letters, find single-letter runs
     * (outside of quotes) and double them so a 2-digit width is
     * preserved.
     *
     * @param list<string> $letters
     */
    private static function doubleSingleLetterRuns(string $pattern, array $letters): string
    {
        $out = '';
        $len = strlen($pattern);
        $i = 0;
        $inQuote = false;
        while ($i < $len) {
            $c = $pattern[$i];
            if ($c === "'") {
                if ($inQuote && $i + 1 < $len && $pattern[$i + 1] === "'") {
                    $out .= "''";
                    $i += 2;
                    continue;
                }
                $inQuote = !$inQuote;
                $out .= $c;
                $i++;
                continue;
            }
            if (!$inQuote && in_array($c, $letters, true)) {
                $j = $i;
                while ($j < $len && $pattern[$j] === $c) {
                    $j++;
                }
                $runLen = $j - $i;
                if ($runLen === 1) {
                    $out .= $c . $c;
                } else {
                    $out .= str_repeat($c, $runLen);
                }
                $i = $j;
                continue;
            }
            $out .= $c;
            $i++;
        }
        return $out;
    }

    /**
     * Build a CLDR skeleton string from the DTF's explicit component
     * internal slots. Each slot maps onto its CLDR letter at the
     * count appropriate to the requested width:
     * - year: 'numeric' -> y, '2-digit' -> yy
     * - month: 'numeric' -> M, '2-digit' -> MM, 'narrow' -> MMMMM, 'short' -> MMM, 'long' -> MMMM
     * - hour: 'numeric' -> h, '2-digit' -> hh (or H/HH for h23/h24)
     * - and so on.
     */
    private static function dateTimeSkeletonFromOptions(JsObject $dtf): string
    {
        $get = static function (string $slot) use ($dtf): ?string {
            $val = $dtf->get($slot);
            return $val instanceof JsString ? $val->value : null;
        };
        $sk = '';
        if ($v = $get('[[era]]')) {
            $sk .= match ($v) {
                'narrow' => 'GGGGG',
                'long' => 'GGGG',
                default => 'G',
            };
        }
        if ($v = $get('[[year]]')) {
            $sk .= $v === '2-digit' ? 'yy' : 'y';
        }
        if ($v = $get('[[month]]')) {
            $sk .= match ($v) {
                '2-digit' => 'MM',
                'narrow' => 'MMMMM',
                'short' => 'MMM',
                'long' => 'MMMM',
                default => 'M',
            };
        }
        if ($v = $get('[[day]]')) {
            $sk .= $v === '2-digit' ? 'dd' : 'd';
        }
        if ($v = $get('[[weekday]]')) {
            $sk .= match ($v) {
                'narrow' => 'EEEEE',
                'long' => 'EEEE',
                default => 'EEE',
            };
        }
        if ($v = $get('[[hour]]')) {
            $hourCycle = $get('[[HourCycle]]') ?? 'h12';
            $hourLetter = match ($hourCycle) {
                'h11' => 'K',
                'h12' => 'h',
                'h23' => 'H',
                'h24' => 'k',
                default => 'h',
            };
            $sk .= $v === '2-digit' ? $hourLetter . $hourLetter : $hourLetter;
        }
        if ($v = $get('[[minute]]')) {
            $sk .= $v === '2-digit' ? 'mm' : 'm';
        }
        if ($v = $get('[[second]]')) {
            $sk .= $v === '2-digit' ? 'ss' : 's';
        }
        $fsdVal = $dtf->get('[[fractionalSecondDigits]]');
        if ($fsdVal instanceof JsNumber) {
            $n = (int) $fsdVal->value;
            if ($n >= 1 && $n <= 3) {
                $sk .= str_repeat('S', $n);
            }
        }
        if ($v = $get('[[dayPeriod]]')) {
            $sk .= match ($v) {
                'narrow' => 'BBBBB',
                'long' => 'BBBB',
                default => 'B',
            };
        }
        if ($v = $get('[[timeZoneName]]')) {
            $sk .= match ($v) {
                'long' => 'zzzz',
                'shortOffset' => 'O',
                'longOffset' => 'OOOO',
                'shortGeneric' => 'v',
                'longGeneric' => 'vvvv',
                default => 'z',
            };
        }
        return $sk;
    }
}
