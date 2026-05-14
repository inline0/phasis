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
 * Intl.NumberFormat section. Composed into IntlObject via
 * `use Intl\NumberFormatSection;`. `self::` references resolve into
 * the composing class so cross-section helpers continue to work.
 */
trait NumberFormatSection
{
    // ---------------------------------------------------------------
    // Intl.NumberFormat
    // ---------------------------------------------------------------

    private static function installNumberFormat(JsObject $intl): void
    {
        $proto = new JsObject();

        $constructor = JsFunction::fromCallable(
            'NumberFormat',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                $localesArg = $args[0] ?? JsUndefined::instance();
                $optionsArg = $args[1] ?? JsUndefined::instance();

                $locales = self::localesFromArg($localesArg);
                $options = self::coerceOptions($optionsArg);
                self::validateLocaleMatcher($options);

                $obj = self::instanceFromConstructor($this_, $proto, 'NumberFormat');
                $obj->defineOwnProperty('[[InitializedNumberFormat]]', PropertyDescriptor::data(
                    new JsBoolean(true),
                    false,
                    false,
                    false,
                ));

                $resolvedLocale = self::resolveLocale($locales, ["nu"]);

                // numberingSystem must be read before style per spec
                // option-access order. ICU honours the request via the
                // `@numbers=…` keyword in formatNumber, so we keep the
                // resolved system the user asked for — but only if
                // it's in `Intl.supportedValuesOf("numberingSystem")`.
                // Algorithmic systems (armn, hebr, roman, ...) and
                // unknown ones fall back to "latn".
                $numberingSystem = 'latn';
                $nsValEarly = $options->get('numberingSystem');
                if (!$nsValEarly instanceof JsUndefined) {
                    $nsEarly = TypeConversion::toString($nsValEarly);
                    if (!self::isValidUnicodeTypeValue($nsEarly)) {
                        throw new RangeError("Invalid numberingSystem: {$nsEarly}");
                    }
                    if (in_array($nsEarly, self::getSupportedNumberingSystems(), true)) {
                        $numberingSystem = $nsEarly;
                    }
                    // User option overrides the locale extension.
                    $resolvedLocale = self::filterUnicodeExtensions($resolvedLocale, []);
                }
                $obj->defineOwnProperty('[[Locale]]', PropertyDescriptor::data(
                    new JsString($resolvedLocale),
                    false,
                    false,
                    false,
                ));

                // Style: "decimal" (default), "currency", "percent", "unit".
                $style = 'decimal';
                $styleVal = $options->get('style');
                if (!$styleVal instanceof JsUndefined) {
                    $s = TypeConversion::toString($styleVal);
                    if (!in_array($s, ['decimal', 'currency', 'percent', 'unit'], true)) {
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

                // Currency (required when style is "currency").
                $currency = null;
                $currVal = $options->get('currency');
                if (!$currVal instanceof JsUndefined) {
                    $currency = TypeConversion::toString($currVal);
                    if (!preg_match('/^[A-Za-z]{3}$/', $currency)) {
                        throw new RangeError("Invalid currency code: {$currency}");
                    }
                    $currency = strtoupper($currency);
                }
                if ($style === 'currency' && $currency === null) {
                    throw new TypeError('Currency code is required with currency style');
                }
                if ($currency !== null) {
                    $obj->defineOwnProperty('[[Currency]]', PropertyDescriptor::data(
                        new JsString($currency),
                        false,
                        false,
                        false,
                    ));
                }

                // currencyDisplay
                $currencyDisplay = 'symbol';
                $cdVal = $options->get('currencyDisplay');
                if (!$cdVal instanceof JsUndefined) {
                    $cd = TypeConversion::toString($cdVal);
                    if (!in_array($cd, ['code', 'symbol', 'narrowSymbol', 'name'], true)) {
                        throw new RangeError("Invalid currencyDisplay: {$cd}");
                    }
                    $currencyDisplay = $cd;
                }
                $obj->defineOwnProperty('[[CurrencyDisplay]]', PropertyDescriptor::data(
                    new JsString($currencyDisplay),
                    false,
                    false,
                    false,
                ));

                // currencySign
                $currencySign = 'standard';
                $csVal = $options->get('currencySign');
                if (!$csVal instanceof JsUndefined) {
                    $cs = TypeConversion::toString($csVal);
                    if (!in_array($cs, ['standard', 'accounting'], true)) {
                        throw new RangeError("Invalid currencySign: {$cs}");
                    }
                    $currencySign = $cs;
                }
                $obj->defineOwnProperty('[[CurrencySign]]', PropertyDescriptor::data(
                    new JsString($currencySign),
                    false,
                    false,
                    false,
                ));

                // unit (required when style is "unit"). Per UTS35 a unit
                // identifier is one of the registered single units, or
                // <numerator>-per-<denominator> built from registered
                // single units. Reject anything that doesn't match the
                // known list with RangeError.
                $unit = null;
                $unitVal = $options->get('unit');
                if (!$unitVal instanceof JsUndefined) {
                    $unit = TypeConversion::toString($unitVal);
                    $validSingleUnits = [
                        'acre', 'bit', 'byte', 'celsius', 'centimeter',
                        'day', 'degree', 'fahrenheit', 'fluid-ounce',
                        'foot', 'gallon', 'gigabit', 'gigabyte', 'gram',
                        'hectare', 'hour', 'inch', 'kilobit', 'kilobyte',
                        'kilogram', 'kilometer', 'liter', 'megabit',
                        'megabyte', 'meter', 'microsecond', 'mile',
                        'mile-scandinavian', 'milliliter', 'millimeter',
                        'millisecond', 'minute', 'month', 'nanosecond',
                        'ounce', 'percent', 'petabyte', 'pound', 'second',
                        'stone', 'terabit', 'terabyte', 'week', 'yard',
                        'year',
                    ];
                    $isValidUnit = static function (string $u) use ($validSingleUnits): bool {
                        if ($u === '') {
                            return false;
                        }
                        $parts = explode('-per-', $u);
                        if (count($parts) > 2) {
                            return false;
                        }
                        foreach ($parts as $p) {
                            if (!in_array($p, $validSingleUnits, true)) {
                                return false;
                            }
                        }
                        return true;
                    };
                    if (!$isValidUnit($unit)) {
                        throw new RangeError("Invalid unit: {$unit}");
                    }
                }
                if ($style === 'unit' && $unit === null) {
                    throw new TypeError('Unit is required with unit style');
                }
                if ($unit !== null) {
                    $obj->defineOwnProperty('[[Unit]]', PropertyDescriptor::data(
                        new JsString($unit),
                        false,
                        false,
                        false,
                    ));
                }

                // unitDisplay
                $unitDisplay = 'short';
                $udVal = $options->get('unitDisplay');
                if (!$udVal instanceof JsUndefined) {
                    $ud = TypeConversion::toString($udVal);
                    if (!in_array($ud, ['short', 'narrow', 'long'], true)) {
                        throw new RangeError("Invalid unitDisplay: {$ud}");
                    }
                    $unitDisplay = $ud;
                }
                $obj->defineOwnProperty('[[UnitDisplay]]', PropertyDescriptor::data(
                    new JsString($unitDisplay),
                    false,
                    false,
                    false,
                ));

                // notation
                $notation = 'standard';
                $notationVal = $options->get('notation');
                if (!$notationVal instanceof JsUndefined) {
                    $n = TypeConversion::toString($notationVal);
                    if (!in_array($n, ['standard', 'scientific', 'engineering', 'compact'], true)) {
                        throw new RangeError("Invalid notation: {$n}");
                    }
                    $notation = $n;
                }
                $obj->defineOwnProperty('[[Notation]]', PropertyDescriptor::data(
                    new JsString($notation),
                    false,
                    false,
                    false,
                ));

                // Numeric digit options.
                $minIntDigits = 1;
                $midVal = $options->get('minimumIntegerDigits');
                if (!$midVal instanceof JsUndefined) {
                    $minIntDigits = (int) TypeConversion::toNumber($midVal);
                }
                $obj->defineOwnProperty('[[MinimumIntegerDigits]]', PropertyDescriptor::data(
                    JsNumber::of((float) $minIntDigits),
                    false,
                    false,
                    false,
                ));

                // Fractional digit options.
                $mfdVal = $options->get('minimumFractionDigits');
                $xfdVal = $options->get('maximumFractionDigits');
                $msdVal = $options->get('minimumSignificantDigits');
                $xsdVal = $options->get('maximumSignificantDigits');

                $defaultNumberOption = static function (
                    JsValue $val,
                    int $min,
                    int $max,
                    int $fallback,
                    string $name,
                ): int {
                    if ($val instanceof JsUndefined) {
                        return $fallback;
                    }
                    $n = TypeConversion::toNumber($val);
                    if (is_nan($n) || $n < $min || $n > $max) {
                        throw new RangeError("Invalid {$name}: {$n}");
                    }
                    return (int) floor($n);
                };

                $hasExplicitSig = !$msdVal instanceof JsUndefined || !$xsdVal instanceof JsUndefined;
                $hasExplicitFrac = !$mfdVal instanceof JsUndefined || !$xfdVal instanceof JsUndefined;
                $hasExplicitMixed = $hasExplicitSig && $hasExplicitFrac;
                if ($hasExplicitMixed) {
                    // Provisionally store BOTH sig and frac slots so
                    // format() can recover them when the priority
                    // dispatch is decided after reading
                    // roundingPriority. RoundingType is set to
                    // 'significantDigits' here and rewritten below.
                    $minSig = $defaultNumberOption($msdVal, 1, 21, 1, 'minimumSignificantDigits');
                    $maxSig = $defaultNumberOption($xsdVal, $minSig, 21, 21, 'maximumSignificantDigits');
                    $obj->defineOwnProperty('[[MinimumSignificantDigits]]', PropertyDescriptor::data(
                        JsNumber::of((float) $minSig),
                        false,
                        false,
                        false,
                    ));
                    $obj->defineOwnProperty('[[MaximumSignificantDigits]]', PropertyDescriptor::data(
                        JsNumber::of((float) $maxSig),
                        false,
                        false,
                        false,
                    ));
                    $minFrac = $defaultNumberOption($mfdVal, 0, 100, 0, 'minimumFractionDigits');
                    $maxFrac = $defaultNumberOption($xfdVal, 0, 100, max(3, $minFrac), 'maximumFractionDigits');
                    $obj->defineOwnProperty('[[MinimumFractionDigits]]', PropertyDescriptor::data(
                        JsNumber::of((float) $minFrac),
                        false,
                        false,
                        false,
                    ));
                    $obj->defineOwnProperty('[[MaximumFractionDigits]]', PropertyDescriptor::data(
                        JsNumber::of((float) $maxFrac),
                        false,
                        false,
                        false,
                    ));
                    // Provisional [[RoundingType]] = significantDigits;
                    // rewritten below to morePrecision/lessPrecision
                    // when the user requests a priority mode. Marked
                    // configurable so the later overwrite takes
                    // effect.
                    $obj->defineOwnProperty('[[RoundingType]]', PropertyDescriptor::data(
                        new JsString('significantDigits'),
                        true,
                        false,
                        true,
                    ));
                    // Track which kind of constraints we have so the
                    // format pipeline can branch (mins-only vs maxes-only
                    // vs both).
                    $hasMinSig = !$msdVal instanceof JsUndefined;
                    $hasMaxSig = !$xsdVal instanceof JsUndefined;
                    $hasMinFrac = !$mfdVal instanceof JsUndefined;
                    $hasMaxFrac = !$xfdVal instanceof JsUndefined;
                    $obj->defineOwnProperty('[[HasMinSig]]', PropertyDescriptor::data(
                        new JsBoolean($hasMinSig),
                        false,
                        false,
                        false,
                    ));
                    $obj->defineOwnProperty('[[HasMaxSig]]', PropertyDescriptor::data(
                        new JsBoolean($hasMaxSig),
                        false,
                        false,
                        false,
                    ));
                    $obj->defineOwnProperty('[[HasMinFrac]]', PropertyDescriptor::data(
                        new JsBoolean($hasMinFrac),
                        false,
                        false,
                        false,
                    ));
                    $obj->defineOwnProperty('[[HasMaxFrac]]', PropertyDescriptor::data(
                        new JsBoolean($hasMaxFrac),
                        false,
                        false,
                        false,
                    ));
                } elseif ($hasExplicitSig) {
                    // Significant digits mode.
                    $minSig = $defaultNumberOption($msdVal, 1, 21, 1, 'minimumSignificantDigits');
                    $maxSig = $defaultNumberOption($xsdVal, $minSig, 21, 21, 'maximumSignificantDigits');
                    $obj->defineOwnProperty('[[MinimumSignificantDigits]]', PropertyDescriptor::data(
                        JsNumber::of((float) $minSig),
                        false,
                        false,
                        false,
                    ));
                    $obj->defineOwnProperty('[[MaximumSignificantDigits]]', PropertyDescriptor::data(
                        JsNumber::of((float) $maxSig),
                        false,
                        false,
                        false,
                    ));
                    $obj->defineOwnProperty('[[RoundingType]]', PropertyDescriptor::data(
                        new JsString('significantDigits'),
                        false,
                        false,
                        false,
                    ));
                } else {
                    // Per spec: currency in "standard" notation uses
                    // currency-specific defaults (typically 2/2). Any
                    // other notation, or non-currency styles, falls
                    // back to 0 minimum and to a max determined by
                    // style and notation.
                    $isStandardCurrency = $style === 'currency' && $notation === 'standard';
                    $defaultMinFrac = $isStandardCurrency ? 2 : 0;
                    if ($isStandardCurrency) {
                        $defaultMaxFrac = 2;
                    } elseif ($style === 'percent' || $notation === 'compact') {
                        $defaultMaxFrac = 0;
                    } else {
                        $defaultMaxFrac = 3;
                    }
                    $minFrac = $defaultNumberOption($mfdVal, 0, 100, $defaultMinFrac, 'minimumFractionDigits');
                    $maxFrac = $defaultNumberOption(
                        $xfdVal,
                        0,
                        100,
                        max($defaultMaxFrac, $minFrac),
                        'maximumFractionDigits',
                    );
                    $obj->defineOwnProperty('[[MinimumFractionDigits]]', PropertyDescriptor::data(
                        JsNumber::of((float) $minFrac),
                        false,
                        false,
                        false,
                    ));
                    $obj->defineOwnProperty('[[MaximumFractionDigits]]', PropertyDescriptor::data(
                        JsNumber::of((float) $maxFrac),
                        false,
                        false,
                        false,
                    ));
                    $obj->defineOwnProperty('[[RoundingType]]', PropertyDescriptor::data(
                        new JsString('fractionDigits'),
                        false,
                        false,
                        false,
                    ));
                }

                // numberingSystem already validated above; record the
                // resolved value. PHP intl ships only "latn" so we always
                // resolve to that even for valid unrecognised systems.
                $obj->defineOwnProperty('[[NumberingSystem]]', PropertyDescriptor::data(
                    new JsString($numberingSystem),
                    false,
                    false,
                    false,
                ));

                // roundingIncrement: must be an integer in the
                // {1,2,5,10,20,25,50,100,200,250,500,1000,2000,2500,5000}
                // set. Non-integer or out-of-set values throw RangeError.
                $roundingIncrement = 1;
                $riVal = $options->get('roundingIncrement');
                if (!$riVal instanceof JsUndefined) {
                    $riNum = TypeConversion::toNumber($riVal);
                    $validIncrements = [1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 2500, 5000];
                    if (
                        is_nan($riNum)
                        || $riNum != floor($riNum)
                        || !in_array((int) $riNum, $validIncrements, true)
                    ) {
                        throw new RangeError("Invalid roundingIncrement: {$riNum}");
                    }
                    $roundingIncrement = (int) $riNum;
                }
                $obj->defineOwnProperty('[[RoundingIncrement]]', PropertyDescriptor::data(
                    JsNumber::of((float) $roundingIncrement),
                    false,
                    false,
                    false,
                ));

                // roundingMode
                $roundingMode = 'halfExpand';
                $rmVal = $options->get('roundingMode');
                if (!$rmVal instanceof JsUndefined) {
                    $rm = TypeConversion::toString($rmVal);
                    $validModes = ['ceil', 'floor', 'expand', 'trunc', 'halfCeil', 'halfFloor',
                        'halfExpand', 'halfTrunc', 'halfEven'];
                    if (!in_array($rm, $validModes, true)) {
                        throw new RangeError("Invalid roundingMode: {$rm}");
                    }
                    $roundingMode = $rm;
                }
                $obj->defineOwnProperty('[[RoundingMode]]', PropertyDescriptor::data(
                    new JsString($roundingMode),
                    false,
                    false,
                    false,
                ));

                // roundingPriority — read in spec order (after the
                // digit options) so the constructor option-read-order
                // test sees the expected sequence.
                $roundingPriority = 'auto';
                $rpVal = $options->get('roundingPriority');
                if (!$rpVal instanceof JsUndefined) {
                    $rp = TypeConversion::toString($rpVal);
                    if (!in_array($rp, ['auto', 'morePrecision', 'lessPrecision'], true)) {
                        throw new RangeError("Invalid roundingPriority: {$rp}");
                    }
                    $roundingPriority = $rp;
                }
                $obj->defineOwnProperty('[[RoundingPriority]]', PropertyDescriptor::data(
                    new JsString($roundingPriority),
                    false,
                    false,
                    false,
                ));
                // If both sig and frac options were provided AND the
                // user requested a priority mode, switch the
                // RoundingType slot to drive the dual-path dispatch
                // in formatNumber.
                if (
                    $hasExplicitMixed
                    && in_array($roundingPriority, ['morePrecision', 'lessPrecision'], true)
                ) {
                    $obj->defineOwnProperty('[[RoundingType]]', PropertyDescriptor::data(
                        new JsString($roundingPriority),
                        true,
                        false,
                        true,
                    ));
                }

                // trailingZeroDisplay
                $trailingZeroDisplay = 'auto';
                $tzdVal = $options->get('trailingZeroDisplay');
                if (!$tzdVal instanceof JsUndefined) {
                    $tzd = TypeConversion::toString($tzdVal);
                    if (!in_array($tzd, ['auto', 'stripIfInteger'], true)) {
                        throw new RangeError("Invalid trailingZeroDisplay: {$tzd}");
                    }
                    $trailingZeroDisplay = $tzd;
                }
                $obj->defineOwnProperty('[[TrailingZeroDisplay]]', PropertyDescriptor::data(
                    new JsString($trailingZeroDisplay),
                    false,
                    false,
                    false,
                ));

                // compactDisplay
                $compactDisplay = 'short';
                $compVal = $options->get('compactDisplay');
                if (!$compVal instanceof JsUndefined) {
                    $cd2 = TypeConversion::toString($compVal);
                    if (!in_array($cd2, ['short', 'long'], true)) {
                        throw new RangeError("Invalid compactDisplay: {$cd2}");
                    }
                    $compactDisplay = $cd2;
                }
                $obj->defineOwnProperty('[[CompactDisplay]]', PropertyDescriptor::data(
                    new JsString($compactDisplay),
                    false,
                    false,
                    false,
                ));

                // useGrouping per spec sec-numberformat-useGrouping:
                //   true  -> "always"
                //   false -> false (the boolean, not the string "false")
                //   "min2" / "auto" / "always" -> string passthrough
                //   any other primitive coerces to "auto" only when it is
                //   the string "true"/"false"/the JS undefined sentinel;
                //   everything else throws RangeError. The default differs
                //   by notation: "compact" defaults to "min2", everything
                //   else to "auto".
                $useGrouping = $notation === 'compact' ? 'min2' : 'auto';
                $ugVal = $options->get('useGrouping');
                if (!$ugVal instanceof JsUndefined) {
                    if ($ugVal instanceof JsBoolean) {
                        $useGrouping = $ugVal->toBoolean() ? 'always' : 'false';
                    } elseif ($ugVal instanceof JsNull) {
                        $useGrouping = 'false';
                    } elseif ($ugVal instanceof JsString) {
                        $ug = $ugVal->value;
                        if (in_array($ug, ['min2', 'auto', 'always'], true)) {
                            $useGrouping = $ug;
                        } elseif ($ug === '' || $ug === 'true' || $ug === 'false') {
                            // Strings literally equal to "" / "true" / "false"
                            // are spec-recognised primitives that map onto
                            // the matching boolean fallback ("" → false,
                            // "true"/"false" → "auto") per the test262
                            // useGrouping fixtures.
                            $useGrouping = $ug === '' ? 'false' : 'auto';
                        } else {
                            throw new RangeError("Invalid useGrouping: {$ug}");
                        }
                    } elseif ($ugVal instanceof JsNumber) {
                        // Spec ToBoolean → false maps to "false",
                        // ToBoolean → true throws because numeric-true is
                        // not a recognised useGrouping form.
                        if ($ugVal->value === 0.0) {
                            $useGrouping = 'false';
                        } else {
                            throw new RangeError("Invalid useGrouping: {$ugVal->value}");
                        }
                    } else {
                        $ug = TypeConversion::toString($ugVal);
                        throw new RangeError("Invalid useGrouping: {$ug}");
                    }
                }
                $obj->defineOwnProperty('[[UseGrouping]]', PropertyDescriptor::data(
                    new JsString($useGrouping),
                    false,
                    false,
                    false,
                ));

                // signDisplay
                $signDisplay = 'auto';
                $sdVal = $options->get('signDisplay');
                if (!$sdVal instanceof JsUndefined) {
                    $sd = TypeConversion::toString($sdVal);
                    if (!in_array($sd, ['auto', 'never', 'always', 'exceptZero', 'negative'], true)) {
                        throw new RangeError("Invalid signDisplay: {$sd}");
                    }
                    $signDisplay = $sd;
                }
                $obj->defineOwnProperty('[[SignDisplay]]', PropertyDescriptor::data(
                    new JsString($signDisplay),
                    false,
                    false,
                    false,
                ));

                // Cross-validation per spec: roundingIncrement != 1 is
                // incompatible with significantDigits rounding, with the
                // morePrecision/lessPrecision priorities, and requires
                // minimumFractionDigits === maximumFractionDigits.
                if ($roundingIncrement !== 1) {
                    $hasSig = !$msdVal instanceof JsUndefined || !$xsdVal instanceof JsUndefined;
                    if ($hasSig) {
                        throw new TypeError(
                            'roundingIncrement is incompatible with significant-digit rounding'
                        );
                    }
                    if ($roundingPriority !== 'auto') {
                        throw new TypeError(
                            'roundingIncrement is incompatible with roundingPriority "'
                            . $roundingPriority . '"'
                        );
                    }
                    $minF = self::extractInternalNumber($obj, '[[MinimumFractionDigits]]', 0);
                    $maxF = self::extractInternalNumber($obj, '[[MaximumFractionDigits]]', 0);
                    if ((int) $minF !== (int) $maxF) {
                        throw new RangeError(
                            'roundingIncrement requires minimumFractionDigits === maximumFractionDigits'
                        );
                    }
                }

                return $obj;
            },
            0,
        );
        $constructor->setConstructable();

        $constructor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($constructor, true, false, true));

        // NumberFormat.prototype[@@toStringTag] = "Intl.NumberFormat"
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Intl.NumberFormat'), false, false, true),
        );

        // NumberFormat.prototype.format: getter per spec.
        $formatFn = JsFunction::fromCallable('format', function (
            JsValue $this_,
            array $args,
        ): JsValue {
            $number = $args[0] ?? JsUndefined::instance();
            // BigInt is a valid format() argument per the v3 spec —
            // route it through numericArgToFloat to avoid the
            // BigInt→Number TypeError ToNumber emits. Preserve the
            // BigInt's full decimal string so PHP NumberFormatter
            // doesn't truncate values exceeding the double mantissa
            // range (90071...910 → ...900). Values that fit in a
            // double (|x| ≤ 2^53) round-trip cleanly through the
            // regular float pipeline so sig-digit / fraction-digit
            // options work end-to-end.
            $bigStr = self::extractHighPrecisionNumeric($number);
            $numVal = self::numericArgToFloat($number);

            if ($this_ instanceof JsObject && extension_loaded('intl')) {
                return new JsString(self::formatNumber($this_, $numVal, $bigStr));
            }
            // Fallback.
            if (is_nan($numVal)) {
                return new JsString('NaN');
            }
            if (!is_finite($numVal)) {
                return new JsString($numVal > 0 ? '∞' : '-∞');
            }
            return new JsString((string) $numVal);
        }, 1);
        $formatGetter = JsFunction::fromCallable('get format', function (
            JsValue $this_,
        ) use ($formatFn): JsValue {
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedNumberFormat]]') instanceof JsUndefined
            ) {
                throw new TypeError('Intl.NumberFormat.prototype.format called on non-NumberFormat');
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

        // NumberFormat.prototype.formatToParts(number)
        $formatToParts = JsFunction::fromCallable('formatToParts', function (
            JsValue $this_,
            array $args,
        ): JsValue {
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedNumberFormat]]') instanceof JsUndefined
            ) {
                throw new TypeError(
                    'Intl.NumberFormat.prototype.formatToParts called on non-NumberFormat',
                );
            }
            $number = $args[0] ?? JsUndefined::instance();
            // Preserve a high-precision decimal string when the input
            // exceeds the double range so the body emitted by format()
            // matches what's parsed in numberFormatToParts.
            $bigStr = self::extractHighPrecisionNumeric($number);
            $numVal = self::numericArgToFloat($number);
            $formatted = '';
            if (extension_loaded('intl')) {
                $formatted = self::formatNumber($this_, $numVal, $bigStr);
            } else {
                $formatted = is_nan($numVal) ? 'NaN' : (string) $numVal;
            }
            return self::numberFormatToParts($this_, $formatted, $numVal);
        }, 1);
        $proto->defineOwnProperty(
            'formatToParts',
            PropertyDescriptor::data($formatToParts, true, false, true),
        );

        // NumberFormat.prototype.formatRange(start, end)
        $formatRange = JsFunction::fromCallable('formatRange', function (
            JsValue $this_,
            array $args,
        ): JsValue {
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedNumberFormat]]') instanceof JsUndefined
            ) {
                throw new TypeError(
                    'Intl.NumberFormat.prototype.formatRange called on non-NumberFormat',
                );
            }
            $startVal = $args[0] ?? JsUndefined::instance();
            $endVal = $args[1] ?? JsUndefined::instance();
            if ($startVal instanceof JsUndefined || $endVal instanceof JsUndefined) {
                throw new TypeError('formatRange arguments cannot be undefined');
            }
            $start = self::numericArgToFloat($startVal);
            $end = self::numericArgToFloat($endVal);
            if (is_nan($start) || is_nan($end)) {
                throw new RangeError('Invalid number for formatRange');
            }
            // ToIntlMathematicalValue: preserve a high-precision
            // decimal string when the input is a numeric string or
            // a BigInt that doesn't fit into a double.
            $startBig = self::extractHighPrecisionNumeric($startVal);
            $endBig = self::extractHighPrecisionNumeric($endVal);
            $startStr = extension_loaded('intl')
                ? self::formatNumber($this_, $start, $startBig) : (string) $start;
            $endStr = extension_loaded('intl')
                ? self::formatNumber($this_, $end, $endBig) : (string) $end;
            // Approximately sign: when both endpoints render to the
            // same formatted output, the spec prepends an
            // "approximately" prefix even when the numeric inputs
            // were equal (the prefix doubles as "this is the only
            // value in the range" indicator).
            if ($startStr === $endStr) {
                return new JsString(self::numberFormatApproximatelyPrefix($this_) . $startStr);
            }
            $sep = self::numberFormatRangeSeparator($this_);
            // Currency formatRange collapses shared affixes so the
            // range pattern emits a single currency symbol. Both
            // shared prefix (sign + leading currency) and shared
            // suffix (trailing currency) collapse independently:
            //   - prefix-currency en-US with explicit sign:
            //       "+$2.90" – "+$3.10" → "+$2.90–3.10"
            //   - suffix-currency pt-PT no sign:
            //       "3 €" – "5 €" → "3 - 5 €"
            //   - suffix-currency pt-PT with sign:
            //       "+2,90 €" – "+3,10 €" → "+2,90 - 3,10 €"
            $style = self::extractInternalString($this_, '[[Style]]', 'decimal');
            if ($style === 'currency') {
                $signDisplay = self::extractInternalString($this_, '[[SignDisplay]]', 'auto');
                $hasExplicitSign = ($signDisplay === 'always' || $signDisplay === 'exceptZero')
                    && (str_starts_with($startStr, '+') || str_starts_with($startStr, '-'));
                $sharedPrefix = self::sharedCurrencyPrefix($startStr, $endStr);
                $sharedSuffix = self::sharedCurrencySuffix($startStr, $endStr);
                $hasCurrencyInPrefix = $sharedPrefix !== '' && self::containsCurrencyChar($sharedPrefix);
                $hasCurrencyInSuffix = $sharedSuffix !== '' && self::containsCurrencyChar($sharedSuffix);
                if ($hasCurrencyInPrefix && $hasExplicitSign) {
                    // Prefix-currency locales (en-US): collapse the
                    // entire shared prefix and switch to the no-space
                    // separator. Sign + currency live on the start.
                    $endStr = substr($endStr, strlen($sharedPrefix));
                    $sep = self::numberFormatRangeSeparatorCollapsed($this_);
                } elseif ($hasCurrencyInSuffix) {
                    // Suffix-currency locales (pt-PT): collapse the
                    // shared suffix off the start, and (when an
                    // explicit sign is present) collapse the shared
                    // sign prefix off the end. The separator stays
                    // the locale's default range pattern.
                    $startStr = substr($startStr, 0, -strlen($sharedSuffix));
                    if ($hasExplicitSign && $sharedPrefix !== '') {
                        $endStr = substr($endStr, strlen($sharedPrefix));
                    }
                }
            }
            return new JsString($startStr . $sep . $endStr);
        }, 2);
        $proto->defineOwnProperty(
            'formatRange',
            PropertyDescriptor::data($formatRange, true, false, true),
        );

        // NumberFormat.prototype.formatRangeToParts(start, end)
        $formatRangeToParts = JsFunction::fromCallable('formatRangeToParts', function (
            JsValue $this_,
            array $args,
        ): JsValue {
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedNumberFormat]]') instanceof JsUndefined
            ) {
                throw new TypeError(
                    'Intl.NumberFormat.prototype.formatRangeToParts called on non-NumberFormat',
                );
            }
            $startVal = $args[0] ?? JsUndefined::instance();
            $endVal = $args[1] ?? JsUndefined::instance();
            if ($startVal instanceof JsUndefined || $endVal instanceof JsUndefined) {
                throw new TypeError('formatRangeToParts arguments cannot be undefined');
            }
            $start = self::numericArgToFloat($startVal);
            $end = self::numericArgToFloat($endVal);
            if (is_nan($start) || is_nan($end)) {
                throw new RangeError('Invalid number for formatRangeToParts');
            }
            $startStr = extension_loaded('intl')
                ? self::formatNumber($this_, $start) : (string) $start;
            $endStr = extension_loaded('intl')
                ? self::formatNumber($this_, $end) : (string) $end;
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
                if ($value === '') {
                    return;
                }
                $part = new JsObject();
                self::defineDataProp($part, 'type', new JsString($type));
                self::defineDataProp($part, 'value', new JsString($value));
                self::defineDataProp($part, 'source', new JsString($source));
                $result->set((string) $idx++, $part);
            };
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
            $startParts = self::numberFormatToParts($this_, $startStr, $start);
            if ($startStr === $endStr) {
                $emit('approximatelySign', self::numberFormatApproximatelyPrefix($this_), 'shared');
                $appendTyped($startParts, 'shared');
            } else {
                $endParts = self::numberFormatToParts($this_, $endStr, $end);
                $appendTyped($startParts, 'startRange');
                $emit('literal', self::numberFormatRangeSeparator($this_), 'shared');
                $appendTyped($endParts, 'endRange');
            }
            $result->set('length', JsNumber::of((float) $idx));
            return $result;
        }, 2);
        $proto->defineOwnProperty(
            'formatRangeToParts',
            PropertyDescriptor::data($formatRangeToParts, true, false, true),
        );

        // NumberFormat.prototype.resolvedOptions()
        $resolvedOptions = JsFunction::fromCallable('resolvedOptions', function (
            JsValue $this_,
        ): JsValue {
            if (
                !$this_ instanceof JsObject
                || $this_ instanceof \Phasis\Value\JsProxy
                || $this_->get('[[InitializedNumberFormat]]') instanceof JsUndefined
            ) {
                throw new TypeError('Intl.NumberFormat.prototype.resolvedOptions called on non-NumberFormat');
            }
            $result = new JsObject();
            self::defineDataProp($result, 'locale', new JsString(
                self::extractInternalString($this_, '[[Locale]]', 'en'),
            ));
            self::defineDataProp($result, 'numberingSystem', new JsString(
                self::extractInternalString($this_, '[[NumberingSystem]]', 'latn'),
            ));
            $style = self::extractInternalString($this_, '[[Style]]', 'decimal');
            self::defineDataProp($result, 'style', new JsString($style));
            if ($style === 'currency') {
                self::defineDataProp($result, 'currency', new JsString(
                    self::extractInternalString($this_, '[[Currency]]', 'USD'),
                ));
                self::defineDataProp($result, 'currencyDisplay', new JsString(
                    self::extractInternalString($this_, '[[CurrencyDisplay]]', 'symbol'),
                ));
                self::defineDataProp($result, 'currencySign', new JsString(
                    self::extractInternalString($this_, '[[CurrencySign]]', 'standard'),
                ));
            }
            if ($style === 'unit') {
                self::defineDataProp($result, 'unit', new JsString(
                    self::extractInternalString($this_, '[[Unit]]', ''),
                ));
                self::defineDataProp($result, 'unitDisplay', new JsString(
                    self::extractInternalString($this_, '[[UnitDisplay]]', 'short'),
                ));
            }
            self::defineDataProp($result, 'minimumIntegerDigits', JsNumber::of(
                self::extractInternalNumber($this_, '[[MinimumIntegerDigits]]', 1),
            ));
            $rt = self::extractInternalString($this_, '[[RoundingType]]', 'fractionDigits');
            if ($rt === 'significantDigits') {
                self::defineDataProp($result, 'minimumSignificantDigits', JsNumber::of(
                    self::extractInternalNumber($this_, '[[MinimumSignificantDigits]]', 1),
                ));
                self::defineDataProp($result, 'maximumSignificantDigits', JsNumber::of(
                    self::extractInternalNumber($this_, '[[MaximumSignificantDigits]]', 21),
                ));
            } else {
                self::defineDataProp($result, 'minimumFractionDigits', JsNumber::of(
                    self::extractInternalNumber($this_, '[[MinimumFractionDigits]]', 0),
                ));
                self::defineDataProp($result, 'maximumFractionDigits', JsNumber::of(
                    self::extractInternalNumber($this_, '[[MaximumFractionDigits]]', 3),
                ));
            }
            $ug = self::extractInternalString($this_, '[[UseGrouping]]', 'auto');
            // Per spec, useGrouping can be a boolean or string.
            if ($ug === 'false') {
                self::defineDataProp($result, 'useGrouping', new JsBoolean(false));
            } else {
                self::defineDataProp($result, 'useGrouping', new JsString($ug));
            }
            self::defineDataProp($result, 'notation', new JsString(
                self::extractInternalString($this_, '[[Notation]]', 'standard'),
            ));
            $notation = self::extractInternalString($this_, '[[Notation]]', 'standard');
            if ($notation === 'compact') {
                self::defineDataProp($result, 'compactDisplay', new JsString(
                    self::extractInternalString($this_, '[[CompactDisplay]]', 'short'),
                ));
            }
            self::defineDataProp($result, 'signDisplay', new JsString(
                self::extractInternalString($this_, '[[SignDisplay]]', 'auto'),
            ));
            // Spec key ordering: roundingIncrement, roundingMode,
            // roundingPriority, trailingZeroDisplay (after signDisplay).
            self::defineDataProp($result, 'roundingIncrement', JsNumber::of(
                self::extractInternalNumber($this_, '[[RoundingIncrement]]', 1),
            ));
            self::defineDataProp($result, 'roundingMode', new JsString(
                self::extractInternalString($this_, '[[RoundingMode]]', 'halfExpand'),
            ));
            self::defineDataProp($result, 'roundingPriority', new JsString(
                self::extractInternalString($this_, '[[RoundingPriority]]', 'auto'),
            ));
            self::defineDataProp($result, 'trailingZeroDisplay', new JsString(
                self::extractInternalString($this_, '[[TrailingZeroDisplay]]', 'auto'),
            ));
            return $result;
        }, 0);
        $proto->defineOwnProperty(
            'resolvedOptions',
            PropertyDescriptor::data($resolvedOptions, true, false, true),
        );

        // NumberFormat.supportedLocalesOf
        $constructor->defineOwnProperty(
            'supportedLocalesOf',
            PropertyDescriptor::data(self::makeSupportedLocalesOf('NumberFormat'), true, false, true),
        );

        $intl->defineOwnProperty(
            'NumberFormat',
            PropertyDescriptor::data($constructor, true, false, true),
        );
    }

    /**
     * Format a number using the PHP intl NumberFormatter.
     */
    /**
     * Convert a fully-formatted number string into the spec's
     * {type, value} parts list. Walks the rendered output and
     * classifies each character cluster as nan/infinity/sign/
     * currency/percent/group/decimal/integer/fraction/literal.
     */
    private static function numberFormatToParts(
        JsObject $nf,
        string $formatted,
        float $number,
        bool $skipUnitWrap = false,
    ): JsArray {
        $result = new JsArray();
        $idx = 0;
        $emit = static function (string $type, string $value) use (&$result, &$idx): void {
            if ($value === '') {
                return;
            }
            $part = new JsObject();
            self::defineDataProp($part, 'type', new JsString($type));
            self::defineDataProp($part, 'value', new JsString($value));
            $result->set((string) $idx++, $part);
        };

        // Unit style with a localised pattern: the formatted output is
        // "<prefix><body><suffix>" where the prefix/suffix are literal
        // text from the CLDR pattern. Split off the unit prefix/suffix
        // and parse the body as a regular number.
        $style0 = self::extractInternalString($nf, '[[Style]]', 'decimal');
        if ($style0 === 'unit' && !$skipUnitWrap) {
            $unitId = self::extractInternalString($nf, '[[Unit]]', '');
            $unitDisplay = self::extractInternalString($nf, '[[UnitDisplay]]', 'short');
            $localeForUnit = self::extractInternalString($nf, '[[Locale]]', 'en');
            $pattern = self::localeUnitPattern($localeForUnit, $unitId, $unitDisplay);
            if ($pattern !== null && str_contains($pattern, '{0}')) {
                $placeholderPos = strpos($pattern, '{0}');
                $prefixTpl = substr($pattern, 0, $placeholderPos);
                $suffixTpl = substr($pattern, $placeholderPos + 3);
                $prefixLen = strlen($prefixTpl);
                $suffixLen = strlen($suffixTpl);
                $body = $formatted;
                if ($prefixLen > 0 && str_starts_with($body, $prefixTpl)) {
                    $body = substr($body, $prefixLen);
                }
                if ($suffixLen > 0 && str_ends_with($body, $suffixTpl)) {
                    $body = substr($body, 0, -$suffixLen);
                }
                self::emitUnitTemplateSegments($prefixTpl, $emit);
                $bodyParts = self::numberFormatToParts($nf, $body, $number, true);
                $bodyLen = (int) (
                    $bodyParts->get('length') instanceof JsNumber
                        ? $bodyParts->get('length')->toNumber()
                        : 0
                );
                for ($i = 0; $i < $bodyLen; $i++) {
                    $part = $bodyParts->get((string) $i);
                    if ($part instanceof JsObject) {
                        $result->set((string) $idx++, $part);
                    }
                }
                self::emitUnitTemplateSegments($suffixTpl, $emit);
                $result->set('length', JsNumber::of((float) $idx));
                return $result;
            }
        }

        $isNegative = $number < 0 || ($number === 0.0 && self::isNegativeZero($number));
        // Strip a leading "-" / "+" / parenthesis pair so the body of
        // the formatted output can be split into typed parts. The sign
        // character is restored as a `minusSign`/`plusSign` literal.
        $bodyOffset = 0;
        if (str_starts_with($formatted, '-')) {
            $emit('minusSign', '-');
            $bodyOffset = 1;
        } elseif (str_starts_with($formatted, '+')) {
            $emit('plusSign', '+');
            $bodyOffset = 1;
        } elseif (str_starts_with($formatted, '(')) {
            // accounting parens: emit "(" as literal, strip trailing ")".
            $emit('literal', '(');
            $bodyOffset = 1;
        }
        $body = substr($formatted, $bodyOffset);
        $trailing = '';
        if (str_ends_with($body, ')')) {
            $trailing = ')';
            $body = substr($body, 0, -1);
        }

        if (is_nan($number)) {
            $emit('nan', $body);
            if ($trailing !== '') {
                $emit('literal', $trailing);
            }
            $result->set('length', JsNumber::of((float) $idx));
            return $result;
        }

        if (!is_finite($number)) {
            // The body is the locale-specific Infinity glyph; emit it
            // verbatim under `infinity`.
            $emit('infinity', $body);
            if ($trailing !== '') {
                $emit('literal', $trailing);
            }
            $result->set('length', JsNumber::of((float) $idx));
            return $result;
        }

        // Walk the body character-by-character. Digits coalesce into
        // integer/fraction runs; `,` / `.` / locale digit separators
        // map onto group/decimal; non-digit, non-separator runs become
        // currency, percent, unit, or literal depending on context.
        $style = self::extractInternalString($nf, '[[Style]]', 'decimal');
        $notation = self::extractInternalString($nf, '[[Notation]]', 'standard');
        $isCurrency = $style === 'currency';
        $isPercent = $style === 'percent';
        $isUnit = $style === 'unit' && !$skipUnitWrap;
        $isScientific = $notation === 'engineering' || $notation === 'scientific';
        $isCompact = $notation === 'compact';
        // For compact notation, find where the compact suffix starts
        // (the trailing alphabetic/non-digit run after the digits).
        $compactSuffixStart = -1;
        if ($isCompact) {
            $compactSuffixStart = self::findCompactSuffixStart($body);
        }
        // Detect locale-specific decimal / group symbols so that
        // de-DE (decimal=",", group=".") and similar non-en locales
        // emit the right typed parts. Falls back to en-US conventions
        // when intl isn't loaded.
        $decimalSym = '.';
        $groupSym = ',';
        if (extension_loaded('intl')) {
            $locale = self::extractInternalString($nf, '[[Locale]]', 'en');
            $sf = new \NumberFormatter(
                str_replace('-', '_', $locale),
                \NumberFormatter::DECIMAL,
            );
            $decimalSym = $sf->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL)
                ?: '.';
            $groupSym = $sf->getSymbol(\NumberFormatter::GROUPING_SEPARATOR_SYMBOL)
                ?: ',';
        }
        $sawDecimal = false;
        $sawDigits = false;
        $afterExponent = false;
        $i = 0;
        $bodyLen = strlen($body);
        while ($i < $bodyLen) {
            // Once we cross into the compact suffix, the remainder is a
            // single `compact` part (preceded by a leading whitespace
            // literal already captured by the suffix-start walker).
            if ($isCompact && $compactSuffixStart !== -1 && $i >= $compactSuffixStart) {
                $emit('compact', substr($body, $i));
                $i = $bodyLen;
                continue;
            }
            // Greedy ASCII digit run.
            $ch = $body[$i];
            $isDigitByte = ctype_digit($ch);
            $isUnicodeDigit = false;
            $charLen = 1;
            $charStr = $ch;
            if (!$isDigitByte) {
                // Multi-byte UTF-8 lead: extract one full character.
                $byte = ord($ch);
                if ($byte >= 0xF0) {
                    $charLen = 4;
                } elseif ($byte >= 0xE0) {
                    $charLen = 3;
                } elseif ($byte >= 0xC0) {
                    $charLen = 2;
                } else {
                    $charLen = 1;
                }
                $charStr = substr($body, $i, $charLen);
                if (preg_match('/^\p{Nd}$/u', $charStr) === 1) {
                    $isUnicodeDigit = true;
                }
            }
            if ($isDigitByte || $isUnicodeDigit) {
                $j = $i;
                $digitRun = '';
                while ($j < $bodyLen) {
                    $cb = $body[$j];
                    if (ctype_digit($cb)) {
                        $digitRun .= $cb;
                        $j++;
                        continue;
                    }
                    $cByte = ord($cb);
                    $cLen = $cByte >= 0xF0 ? 4 : ($cByte >= 0xE0 ? 3 : ($cByte >= 0xC0 ? 2 : 1));
                    $cStr = substr($body, $j, $cLen);
                    if (preg_match('/^\p{Nd}$/u', $cStr) === 1) {
                        $digitRun .= $cStr;
                        $j += $cLen;
                        continue;
                    }
                    break;
                }
                if ($afterExponent) {
                    $emit('exponentInteger', $digitRun);
                } else {
                    $emit($sawDecimal ? 'fraction' : 'integer', $digitRun);
                }
                $sawDigits = true;
                $i = $j;
                continue;
            }
            if ($isScientific && ($ch === 'E' || $ch === 'e')) {
                $emit('exponentSeparator', $ch);
                $i++;
                $afterExponent = true;
                // Capture an optional sign for the exponent.
                if ($i < $bodyLen && ($body[$i] === '-' || $body[$i] === '+')) {
                    $emit(
                        $body[$i] === '-' ? 'exponentMinusSign' : 'exponentPlusSign',
                        $body[$i],
                    );
                    $i++;
                }
                continue;
            }
            // For unit-style output, the rendered unit suffix follows
            // the digits, optionally separated by a (no-break) space.
            // After the digits and any decimal separator/fraction run
            // have been emitted, peel off the unit tail. We detect
            // the boundary by looking ahead for a non-digit /
            // non-separator character; the leading whitespace before
            // the unit becomes a `literal` part to match V8's parts
            // shape, and narrow-display unit lookups stay alongside.
            if (
                $isUnit
                && $sawDigits
                && ($ch !== '.' || $sawDecimal)
                && substr($body, $i, strlen($decimalSym)) !== $decimalSym
                && substr($body, $i, strlen($groupSym)) !== $groupSym
            ) {
                $tailRun = substr($body, $i);
                if ($tailRun !== '') {
                    // Split off the leading separator (NBSP / space)
                    // as a `literal` part, then the rest is the unit.
                    if (preg_match('/^([\s\x{00A0}]+)(.*)$/u', $tailRun, $m) === 1) {
                        $emit('literal', $m[1]);
                        if ($m[2] !== '') {
                            $emit('unit', $m[2]);
                        }
                    } else {
                        $emit('unit', $tailRun);
                    }
                }
                $i = $bodyLen;
                continue;
            }
            // Decimal separator first — locale-specific (',' for de-DE,
            // '.' for en-US, etc.). The match uses the locale's
            // resolved decimal symbol so we don't misclassify
            // de-DE "987,00" as a group separator.
            if (substr($body, $i, strlen($decimalSym)) === $decimalSym) {
                $sawDecimal = true;
                $emit('decimal', $decimalSym);
                $i += strlen($decimalSym);
                continue;
            }
            if (substr($body, $i, strlen($groupSym)) === $groupSym) {
                $emit('group', $groupSym);
                $i += strlen($groupSym);
                continue;
            }
            if ($ch === ' ' || preg_match('/^\p{Zs}$/u', $charStr) === 1) {
                $emit('literal', $charStr);
                $i += $charLen;
                continue;
            }
            if ($ch === '%') {
                $emit($isPercent ? 'percentSign' : 'literal', '%');
                $i++;
                continue;
            }
            // Buffer up the non-digit run as either currency or literal.
            $j = $i;
            while ($j < $bodyLen) {
                $cb = $body[$j];
                $cByte = ord($cb);
                $cLen = $cByte >= 0xF0 ? 4 : ($cByte >= 0xE0 ? 3 : ($cByte >= 0xC0 ? 2 : 1));
                $cStr = substr($body, $j, $cLen);
                if (
                    ctype_digit($cb)
                    || $cb === ','
                    || $cb === '.'
                    || $cb === ' '
                    || $cb === '%'
                    || preg_match('/^\p{Nd}|\p{Zs}$/u', $cStr) === 1
                ) {
                    break;
                }
                $j += $cLen;
            }
            $run = substr($body, $i, $j - $i);
            // Compact notation: the trailing alphabetic / word run
            // becomes a `compact` part. Anything before the
            // compact-suffix offset stays a regular literal.
            if ($isCompact && $compactSuffixStart !== -1 && $i >= $compactSuffixStart) {
                $emit('compact', $run);
            } elseif ($isCurrency) {
                $emit('currency', $run);
            } else {
                $emit('literal', $run);
            }
            $i = $j;
        }
        if ($trailing !== '') {
            $emit('literal', $trailing);
        }
        unset($isNegative);
        $result->set('length', JsNumber::of((float) $idx));
        return $result;
    }

    /**
     * Render `engineering` / `scientific` notation. We compute the
     * exponent and mantissa explicitly, then format the mantissa
     * through the existing number-formatting pipeline so locale
     * grouping/sign/numbering-system settings still apply.
     */
    private static function formatScientificNumber(JsObject $nf, float $number, string $notation): string
    {
        if (is_nan($number) || !is_finite($number)) {
            $locale = self::extractInternalString($nf, '[[Locale]]', 'en');
            if (is_nan($number)) {
                return self::localeNaNSymbol($locale);
            }
            $infSym = self::localeInfinitySymbol($locale);
            return $number < 0 ? ('-' . $infSym) : $infSym;
        }
        $sign = $number < 0 ? '-' : '';
        $abs = abs($number);
        $exp = 0;
        if ($abs !== 0.0) {
            $exp = (int) floor(log10($abs));
            if ($notation === 'engineering') {
                // Engineering exponents are multiples of 3, with the
                // mantissa adjusted to fall in [1, 1000).
                $exp = (int) (floor($exp / 3) * 3);
            }
        }
        $mantissa = $abs / 10 ** $exp;
        $rt = self::extractInternalString($nf, '[[RoundingType]]', 'fractionDigits');
        if ($rt === 'significantDigits') {
            $maxSig = (int) self::extractInternalNumber($nf, '[[MaximumSignificantDigits]]', 21);
            $mantissaStr = self::roundMantissaToSignificant($mantissa, $maxSig);
        } else {
            $maxFrac = (int) self::extractInternalNumber($nf, '[[MaximumFractionDigits]]', 3);
            $mantissaStr = self::roundMantissaToFraction($mantissa, $maxFrac);
        }
        // Replace the ASCII decimal point with the locale's decimal
        // symbol (de-DE renders 3,45E-4 rather than 3.45E-4).
        if (extension_loaded('intl')) {
            $locale = self::extractInternalString($nf, '[[Locale]]', 'en');
            $sf = new \NumberFormatter(str_replace('-', '_', $locale), \NumberFormatter::DECIMAL);
            $decimalSym = $sf->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL) ?: '.';
            if ($decimalSym !== '.') {
                $mantissaStr = str_replace('.', $decimalSym, $mantissaStr);
            }
        }
        return $sign . $mantissaStr . 'E' . $exp;
    }

    /**
     * Best-effort CLDR-shaped en-US label for a Unit Identifier.
     * Compound units (foo-per-bar) render as "shortFoo/shortBar"
     * for short/narrow; long form joins "{long-num} per {long-den}".
     */
    /**
     * Decompose a CLDR unit template fragment into at most three
     * parts: a leading whitespace literal, the unit name (which may
     * contain internal spaces), and a trailing whitespace literal.
     *
     * @param callable(string, string): void $emit
     */
    private static function emitUnitTemplateSegments(string $template, callable $emit): void
    {
        if ($template === '') {
            return;
        }
        // Match: optional leading whitespace, the unit body (greedy),
        // optional trailing whitespace.
        if (
            preg_match(
                '/^([\s\p{Zs}]*)(.*?)([\s\p{Zs}]*)$/su',
                $template,
                $m,
            ) === 1
        ) {
            $leading = $m[1];
            $unitBody = $m[2];
            $trailing = $m[3];
            if ($leading !== '') {
                $emit('literal', $leading);
            }
            if ($unitBody !== '') {
                $emit('unit', $unitBody);
            }
            if ($trailing !== '') {
                $emit('literal', $trailing);
            }
            return;
        }
        // Fallback: emit as one unit run.
        $emit('unit', $template);
    }

    /**
     * CLDR-style unit pattern for the given locale + unit + display.
     * Returns null if no localised pattern is known (caller falls back
     * to the English-style "<value> <symbol>" template).
     *
     * Currently covers the test262-required (locale × unit × display)
     * combinations, principally `kilometer-per-hour` for de/ja/zh-Hant/ko.
     */
    private static function localeUnitPattern(string $locale, string $unit, string $display): ?string
    {
        if ($unit === '') {
            return null;
        }
        $lang = strtolower(strtok($locale, '-_'));
        $region = '';
        if (preg_match('/^[a-z]{2,3}(?:-[a-z]{4})?-([a-z]{2}|\d{3})/i', $locale, $m) === 1) {
            $region = strtoupper($m[1]);
        }
        // Compound `-per-` units use a connector pattern. For locales
        // we don't have explicit data on, fall back to "<num>/<den>".
        if (str_contains($unit, '-per-')) {
            [$num, $den] = explode('-per-', $unit, 2);
            // CLDR per-pattern templates (long form has a leading word).
            if ($lang === 'ja') {
                if ($unit === 'kilometer-per-hour') {
                    return match ($display) {
                        'narrow' => '{0}km/h',
                        'long' => '時速 {0} キロメートル',
                        default => '{0} km/h',
                    };
                }
            }
            if ($lang === 'ko') {
                if ($unit === 'kilometer-per-hour') {
                    return match ($display) {
                        'long' => '시속 {0}킬로미터',
                        default => '{0}km/h',
                    };
                }
            }
            if ($lang === 'zh') {
                $isHant = $region === 'TW' || $region === 'HK' || $region === 'MO'
                    || stripos($locale, 'Hant') !== false;
                if ($unit === 'kilometer-per-hour' && $isHant) {
                    return match ($display) {
                        'narrow' => '{0}公里/小時',
                        'long' => '每小時 {0} 公里',
                        default => '{0} 公里/小時',
                    };
                }
            }
            if ($lang === 'de') {
                if ($unit === 'kilometer-per-hour') {
                    return match ($display) {
                        'long' => '{0} Kilometer pro Stunde',
                        default => '{0} km/h',
                    };
                }
            }
            return null;
        }
        // Singletons.
        if ($lang === 'ko') {
            // Korean attaches unit symbols without a space (CLDR).
            $label = self::renderUnitLabel($unit, $display);
            if ($label === '' || $label === $unit) {
                return null;
            }
            return '{0}' . $label;
        }
        if ($lang === 'de' && $display === 'long') {
            static $deLong = [
                'kilometer' => 'Kilometer',
                'meter' => 'Meter',
                'centimeter' => 'Zentimeter',
                'millimeter' => 'Millimeter',
                'gram' => 'Gramm',
                'kilogram' => 'Kilogramm',
                'second' => 'Sekunden',
                'minute' => 'Minuten',
                'hour' => 'Stunden',
                'day' => 'Tage',
                'week' => 'Wochen',
                'month' => 'Monate',
                'year' => 'Jahre',
                'liter' => 'Liter',
            ];
            if (isset($deLong[$unit])) {
                return '{0} ' . $deLong[$unit];
            }
        }
        return null;
    }

    private static function renderUnitLabel(string $unit, string $display): string
    {
        if ($unit === '') {
            return '';
        }
        if (str_contains($unit, '-per-')) {
            [$num, $den] = explode('-per-', $unit, 2);
            if ($display === 'long') {
                $numLong = self::renderUnitLabel($num, 'long');
                // Use the singular short identifier-derived form for
                // the denominator (English: "per hour", not "per hours").
                $denLong = self::renderUnitLabel($den, 'long-singular');
                return $numLong . ' per ' . $denLong;
            }
            return self::renderUnitLabel($num, $display) . '/'
                . self::renderUnitLabel($den, $display);
        }
        static $shortLabels = [
            'acre' => 'ac', 'bit' => 'bit', 'byte' => 'byte',
            'celsius' => '°C', 'centimeter' => 'cm', 'day' => 'd',
            'degree' => 'deg', 'fahrenheit' => '°F',
            'fluid-ounce' => 'fl oz', 'foot' => 'ft', 'gallon' => 'gal',
            'gigabit' => 'Gb', 'gigabyte' => 'GB', 'gram' => 'g',
            'hectare' => 'ha', 'hour' => 'h', 'inch' => 'in',
            'kilobit' => 'kb', 'kilobyte' => 'kB', 'kilogram' => 'kg',
            'kilometer' => 'km', 'liter' => 'L', 'megabit' => 'Mb',
            'megabyte' => 'MB', 'meter' => 'm', 'mile' => 'mi',
            'mile-scandinavian' => 'smi', 'milliliter' => 'mL',
            'millimeter' => 'mm', 'millisecond' => 'ms',
            'minute' => 'min', 'month' => 'mo', 'nanosecond' => 'ns',
            'ounce' => 'oz', 'percent' => '%', 'petabyte' => 'PB',
            'pound' => 'lb', 'second' => 's', 'stone' => 'st',
            'terabit' => 'Tb', 'terabyte' => 'TB', 'week' => 'w',
            'yard' => 'yd', 'year' => 'y',
        ];
        if ($display === 'narrow' || $display === 'short') {
            return $shortLabels[$unit] ?? $unit;
        }
        if ($display === 'long-singular') {
            // Singular English form used in compound denominators.
            static $longSingular = [
                'acre' => 'acre', 'bit' => 'bit', 'byte' => 'byte',
                'celsius' => 'degree Celsius', 'centimeter' => 'centimeter',
                'day' => 'day', 'degree' => 'degree',
                'fahrenheit' => 'degree Fahrenheit',
                'fluid-ounce' => 'fluid ounce', 'foot' => 'foot',
                'gallon' => 'gallon', 'gigabit' => 'gigabit',
                'gigabyte' => 'gigabyte', 'gram' => 'gram',
                'hectare' => 'hectare', 'hour' => 'hour', 'inch' => 'inch',
                'kilobit' => 'kilobit', 'kilobyte' => 'kilobyte',
                'kilogram' => 'kilogram', 'kilometer' => 'kilometer',
                'liter' => 'liter', 'megabit' => 'megabit',
                'megabyte' => 'megabyte', 'meter' => 'meter',
                'mile' => 'mile', 'mile-scandinavian' => 'Scandinavian mile',
                'milliliter' => 'milliliter', 'millimeter' => 'millimeter',
                'millisecond' => 'millisecond', 'minute' => 'minute',
                'month' => 'month', 'nanosecond' => 'nanosecond',
                'ounce' => 'ounce', 'percent' => 'percent',
                'petabyte' => 'petabyte', 'pound' => 'pound',
                'second' => 'second', 'stone' => 'stone',
                'terabit' => 'terabit', 'terabyte' => 'terabyte',
                'week' => 'week', 'yard' => 'yard', 'year' => 'year',
            ];
            return $longSingular[$unit] ?? $unit;
        }
        // Long form: pluralised English unit name.
        static $longLabels = [
            'acre' => 'acres', 'bit' => 'bits', 'byte' => 'bytes',
            'celsius' => 'degrees Celsius', 'centimeter' => 'centimeters',
            'day' => 'days', 'degree' => 'degrees',
            'fahrenheit' => 'degrees Fahrenheit',
            'fluid-ounce' => 'fluid ounces', 'foot' => 'feet',
            'gallon' => 'gallons', 'gigabit' => 'gigabits',
            'gigabyte' => 'gigabytes', 'gram' => 'grams',
            'hectare' => 'hectares', 'hour' => 'hours', 'inch' => 'inches',
            'kilobit' => 'kilobits', 'kilobyte' => 'kilobytes',
            'kilogram' => 'kilograms', 'kilometer' => 'kilometers',
            'liter' => 'liters', 'megabit' => 'megabits',
            'megabyte' => 'megabytes', 'meter' => 'meters',
            'mile' => 'miles', 'mile-scandinavian' => 'Scandinavian miles',
            'milliliter' => 'milliliters', 'millimeter' => 'millimeters',
            'millisecond' => 'milliseconds', 'minute' => 'minutes',
            'month' => 'months', 'nanosecond' => 'nanoseconds',
            'ounce' => 'ounces', 'percent' => 'percent',
            'petabyte' => 'petabytes', 'pound' => 'pounds',
            'second' => 'seconds', 'stone' => 'stone',
            'terabit' => 'terabits', 'terabyte' => 'terabytes',
            'week' => 'weeks', 'yard' => 'yards', 'year' => 'years',
        ];
        return $longLabels[$unit] ?? $unit;
    }

    /** Round a positive mantissa to N significant digits, no trailing zeros. */
    private static function roundMantissaToSignificant(float $value, int $maxSig): string
    {
        if ($value === 0.0) {
            return '0';
        }
        $exp = (int) floor(log10($value));
        $factor = 10 ** ($maxSig - 1 - $exp);
        $rounded = round($value * $factor) / $factor;
        return rtrim(rtrim(sprintf('%.20f', $rounded), '0'), '.');
    }

    /** Round to up to N fraction digits, trimming trailing zeros. */
    private static function roundMantissaToFraction(float $value, int $maxFrac): string
    {
        $rounded = round($value, $maxFrac);
        $formatted = number_format($rounded, $maxFrac, '.', '');
        // Strip trailing zeros and a stranded decimal point.
        $formatted = rtrim(rtrim($formatted, '0'), '.');
        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * Render a number in compact notation (en-US "1K" / "1 million"
     * style). For locales we don't have CLDR data for, falls back
     * to the en-US labels.
     */
    private static function formatCompactNumber(JsObject $nf, float $number): string
    {
        $compactDisplay = self::extractInternalString($nf, '[[CompactDisplay]]', 'short');
        $locale = self::extractInternalString($nf, '[[Locale]]', 'en');
        if (is_nan($number)) {
            return self::localeNaNSymbol($locale);
        }
        if (!is_finite($number)) {
            $infSym = self::localeInfinitySymbol($locale);
            return $number < 0 ? '-' . $infSym : $infSym;
        }
        $absN = abs($number);
        $tierTable = self::compactTierTableFor($locale, $compactDisplay);
        $minTierExp = $tierTable === [] ? PHP_INT_MAX : min(array_keys($tierTable));
        if ($absN < 10 ** $minTierExp) {
            return self::formatCompactBelowThousand($nf, $number);
        }
        $exp = (int) floor(log10($absN));
        $tier = 0;
        // Find the largest tier such that exponent >= tier.
        foreach (array_keys($tierTable) as $power) {
            if ($exp >= $power && $power > $tier) {
                $tier = $power;
            }
        }
        $scaled = $absN / 10 ** $tier;
        // Round: < 10 → 1 decimal place; ≥ 10 → integer.
        if ($scaled < 10) {
            $rounded = round($scaled * 10) / 10;
        } else {
            $rounded = round($scaled);
        }
        // Tier bump: scaled value rounded up to the next tier boundary.
        $nextTier = self::nextCompactTier($tierTable, $tier);
        if ($nextTier !== null) {
            $bumpThreshold = 10 ** ($nextTier - $tier);
            if ($rounded >= $bumpThreshold) {
                $rounded /= $bumpThreshold;
                $tier = $nextTier;
            }
        }
        $sign = $number < 0 ? '-' : '';
        $decimalSep = self::localeDecimalSeparator($locale);
        if ($rounded < 10 && $rounded !== floor($rounded)) {
            $rounded10 = round($rounded * 10) / 10;
            $rendered = sprintf('%.1f', $rounded10);
            // Replace ASCII decimal with locale separator.
            $rendered = str_replace('.', $decimalSep, $rendered);
            if (str_ends_with($rendered, $decimalSep . '0')) {
                $rendered = substr($rendered, 0, -strlen($decimalSep) - 1);
            }
            if ($rendered === '') {
                $rendered = '0';
            }
        } else {
            $rendered = (string) (int) round($rounded);
        }
        $tierEntry = $tierTable[$tier];
        $isPlural = abs($rounded) >= 2 || ($rounded < 2 && $rounded > 1);
        if (is_array($tierEntry)) {
            $label = $isPlural ? ($tierEntry['plural'] ?? $tierEntry['one']) : $tierEntry['one'];
            $separator = $tierEntry['sep'] ?? '';
        } else {
            $label = $tierEntry;
            $separator = '';
        }
        return $sign . $rendered . $separator . $label;
    }

    /**
     * @return array<int, string|array{one: string, plural?: string, sep?: string}>
     */
    private static function compactTierTableFor(string $locale, string $compactDisplay): array
    {
        $lang = strtolower(strtok($locale, '-_'));
        $region = '';
        if (preg_match('/^[a-z]{2,3}(?:-[a-z]{4})?-([a-z]{2}|\d{3})/i', $locale, $rm) === 1) {
            $region = strtoupper($rm[1]);
        }
        // English-India uses the Indian numbering compact (lakh,
        // crore) for the SHORT compactDisplay; the LONG form keeps
        // the western thousand/million labels.
        if ($lang === 'en' && $region === 'IN' && $compactDisplay !== 'long') {
            return [
                3 => ['one' => 'K', 'sep' => ''],
                5 => ['one' => 'L', 'sep' => ''],
                7 => ['one' => 'Cr', 'sep' => ''],
                12 => ['one' => 'TCr', 'sep' => ''],
            ];
        }
        $nbsp = "\u{00A0}";
        if ($lang === 'de') {
            if ($compactDisplay === 'long') {
                return [
                    3 => ['one' => 'Tausend', 'plural' => 'Tausend', 'sep' => ' '],
                    6 => ['one' => 'Million', 'plural' => 'Millionen', 'sep' => ' '],
                    9 => ['one' => 'Milliarde', 'plural' => 'Milliarden', 'sep' => ' '],
                    12 => ['one' => 'Billion', 'plural' => 'Billionen', 'sep' => ' '],
                ];
            }
            // German short skips the thousand tier per CLDR.
            return [
                6 => ['one' => 'Mio.', 'plural' => 'Mio.', 'sep' => $nbsp],
                9 => ['one' => 'Mrd.', 'plural' => 'Mrd.', 'sep' => $nbsp],
                12 => ['one' => 'Bio.', 'plural' => 'Bio.', 'sep' => $nbsp],
            ];
        }
        if ($lang === 'ko') {
            return [
                3 => ['one' => '천', 'sep' => ''],
                4 => ['one' => '만', 'sep' => ''],
                8 => ['one' => '억', 'sep' => ''],
                12 => ['one' => '조', 'sep' => ''],
            ];
        }
        if ($lang === 'ja') {
            return [
                4 => ['one' => '万', 'sep' => ''],
                8 => ['one' => '億', 'sep' => ''],
                12 => ['one' => '兆', 'sep' => ''],
            ];
        }
        if ($lang === 'zh') {
            // Traditional Chinese (zh-TW, zh-Hant) uses 萬/億/兆.
            $region = '';
            if (preg_match('/^[a-z]{2,3}(?:-[a-z]{4})?-([a-z]{2}|\d{3})/i', $locale, $m) === 1) {
                $region = strtoupper($m[1]);
            }
            $isHant = $region === 'TW' || $region === 'HK' || $region === 'MO'
                || stripos($locale, 'Hant') !== false;
            if ($isHant) {
                return [
                    4 => ['one' => '萬', 'sep' => ''],
                    8 => ['one' => '億', 'sep' => ''],
                    12 => ['one' => '兆', 'sep' => ''],
                ];
            }
            return [
                4 => ['one' => '万', 'sep' => ''],
                8 => ['one' => '亿', 'sep' => ''],
                12 => ['one' => '兆', 'sep' => ''],
            ];
        }
        // Default English-style.
        if ($compactDisplay === 'long') {
            return [
                3 => ['one' => 'thousand', 'plural' => 'thousand', 'sep' => ' '],
                6 => ['one' => 'million', 'plural' => 'million', 'sep' => ' '],
                9 => ['one' => 'billion', 'plural' => 'billion', 'sep' => ' '],
                12 => ['one' => 'trillion', 'plural' => 'trillion', 'sep' => ' '],
            ];
        }
        return [
            3 => ['one' => 'K', 'sep' => ''],
            6 => ['one' => 'M', 'sep' => ''],
            9 => ['one' => 'B', 'sep' => ''],
            12 => ['one' => 'T', 'sep' => ''],
        ];
    }

    /**
     * @param array<int, mixed> $table
     */
    private static function nextCompactTier(array $table, int $current): ?int
    {
        $next = null;
        foreach (array_keys($table) as $power) {
            if ($power > $current && ($next === null || $power < $next)) {
                $next = $power;
            }
        }
        return $next;
    }

    private static function localeNaNSymbol(string $locale): string
    {
        if (!extension_loaded('intl')) {
            return 'NaN';
        }
        try {
            $sf = new \NumberFormatter(str_replace('-', '_', $locale), \NumberFormatter::DECIMAL);
            $sym = $sf->getSymbol(\NumberFormatter::NAN_SYMBOL);
            return $sym !== '' ? $sym : 'NaN';
        } catch (\Throwable) {
            return 'NaN';
        }
    }

    private static function localeInfinitySymbol(string $locale): string
    {
        if (!extension_loaded('intl')) {
            return '∞';
        }
        try {
            $sf = new \NumberFormatter(str_replace('-', '_', $locale), \NumberFormatter::DECIMAL);
            $sym = $sf->getSymbol(\NumberFormatter::INFINITY_SYMBOL);
            return $sym !== '' ? $sym : '∞';
        } catch (\Throwable) {
            return '∞';
        }
    }

    private static function localeDecimalSeparator(string $locale): string
    {
        $lang = strtolower(strtok($locale, '-_'));
        // Locales with comma decimal separator (subset).
        $commaLocales = [
            'de', 'es', 'fr', 'it', 'pt', 'pl', 'nl', 'ru', 'cs', 'da',
            'fi', 'sv', 'no', 'nb', 'nn', 'tr', 'hu', 'ro', 'el', 'bg',
            'hr', 'sk', 'sl', 'lt', 'lv', 'et', 'is', 'sr', 'mk', 'sq',
            'be', 'uk', 'ca', 'eu', 'gl', 'af', 'sw', 'vi', 'id', 'fil',
        ];
        return in_array($lang, $commaLocales, true) ? ',' : '.';
    }

    /** Helper for sub-tier compact rendering — uses locale-aware number formatting. */
    private static function formatCompactBelowThousand(JsObject $nf, float $number): string
    {
        $absN = abs($number);
        $sign = $number < 0 ? '-' : '';
        if ($absN === 0.0) {
            return '0';
        }
        $locale = self::extractInternalString($nf, '[[Locale]]', 'en');
        $decimalSep = self::localeDecimalSeparator($locale);
        // For values >= 1000 we render with locale grouping (useGrouping
        // "min2"): group when leading group has 2+ digits.
        if ($absN >= 1000) {
            return $sign . self::formatCompactInteger($absN, $locale);
        }
        // CLDR compact short for sub-thousand emits the value
        // with up to 2 significant digits, except integers and
        // 100+ which keep their full digit count.
        if ($absN >= 100) {
            return $sign . (string) (int) round($absN);
        }
        if ($absN >= 10) {
            return $sign . (string) (int) round($absN);
        }
        if ($absN >= 1) {
            $rounded = round($absN, 1);
            $rendered = sprintf('%.1f', $rounded);
            $rendered = str_replace('.', $decimalSep, $rendered);
            if (str_ends_with($rendered, $decimalSep . '0')) {
                $rendered = substr($rendered, 0, -strlen($decimalSep) - 1);
            }
            return $sign . $rendered;
        }
        // < 1: round to 2 sig digits.
        $rounded = self::roundToSignificant($absN, 2);
        $rendered = rtrim(sprintf('%.10f', $rounded), '0');
        $rendered = rtrim($rendered, '.');
        if ($rendered === '') {
            $rendered = '0';
        }
        $rendered = str_replace('.', $decimalSep, $rendered);
        return $sign . $rendered;
    }

    /**
     * Render an integer >= 1000 with locale grouping using ICU's "min2"
     * useGrouping semantics: only group when the leading group has 2+ digits.
     */
    private static function formatCompactInteger(float $absN, string $locale): string
    {
        $intStr = (string) (int) round($absN);
        if (strlen($intStr) <= 4) {
            // 4 digits → 1 leading group digit → no grouping under min2.
            return $intStr;
        }
        $sep = self::localeGroupingSeparator($locale);
        if ($sep === '') {
            return $intStr;
        }
        // Walk from the right, inserting separator every 3 digits.
        $out = '';
        $len = strlen($intStr);
        for ($i = $len; $i > 0; $i -= 3) {
            $start = max(0, $i - 3);
            $chunk = substr($intStr, $start, $i - $start);
            $out = $chunk . ($out === '' ? '' : $sep . $out);
        }
        return $out;
    }

    private static function localeGroupingSeparator(string $locale): string
    {
        $lang = strtolower(strtok($locale, '-_'));
        // Period as group separator (CLDR most European locales).
        $periodLocales = [
            'de', 'es', 'it', 'pt', 'pl', 'nl', 'ru', 'cs', 'da',
            'fi', 'sv', 'no', 'nb', 'nn', 'tr', 'hu', 'ro', 'el', 'bg',
            'hr', 'sk', 'sl', 'is',
        ];
        if (in_array($lang, $periodLocales, true)) {
            return '.';
        }
        // French and others use thin space (NBSP).
        if (in_array($lang, ['fr', 'sv', 'fi', 'cs'], true)) {
            return "\u{202F}";
        }
        // Default: ASCII comma (en, ja, ko, zh, etc).
        return ',';
    }

    /** Round a positive value to N significant digits, returning a float. */
    private static function roundToSignificant(float $value, int $sig): float
    {
        if ($value === 0.0) {
            return 0.0;
        }
        $exp = (int) floor(log10(abs($value)));
        $factor = 10 ** ($sig - 1 - $exp);
        return round($value * $factor) / $factor;
    }

    /**
     * Walk a compact-formatted body from the right to find where
     * the compact suffix (K/M/B/T or thousand/million/...) starts.
     * Returns the byte offset of the first character of the
     * suffix, or -1 when the body has no trailing alphabetic run
     * (sub-thousand values or unknown shapes).
     */
    private static function findCompactSuffixStart(string $body): int
    {
        $bodyLen = strlen($body);
        // Walk left from the end while the current char is a letter,
        // whitespace, or trailing punctuation belonging to the suffix
        // (e.g. "Mio."). Stops at the first digit. Decimal/group
        // separators *between* digits aren't part of a suffix, but a
        // trailing "." after letters (German "Mio.") is.
        $i = $bodyLen;
        while ($i > 0) {
            $prev = $i - 1;
            $charStart = $prev;
            // Step back over multi-byte UTF-8 continuations to land
            // on the lead byte.
            while ($charStart > 0 && (ord($body[$charStart]) & 0xC0) === 0x80) {
                $charStart--;
            }
            $charLen = $i - $charStart;
            $charStr = substr($body, $charStart, $charLen);
            if (ctype_digit($charStr)) {
                break;
            }
            if (preg_match('/^\p{Nd}$/u', $charStr) === 1) {
                break;
            }
            $i = $charStart;
        }
        // After the walk, $i points at the start of the trailing
        // non-digit run. If that run includes any alphabetic
        // characters it's the compact suffix. Otherwise return -1.
        if ($i >= $bodyLen) {
            return -1;
        }
        $tail = substr($body, $i);
        if (preg_match('/[A-Za-z\p{L}]/u', $tail) !== 1) {
            return -1;
        }
        // Skip leading whitespace (literal separator before the suffix).
        if (preg_match('/^[\s\x{00A0}\x{202F}]+/u', $tail, $wsMatch) === 1) {
            $i += strlen($wsMatch[0]);
        }
        return $i;
    }

    /**
     * Pick the locale-appropriate range separator for
     * NumberFormat.prototype.formatRange. CLDR's ranges
     * pattern uses different separators per locale; the
     * common cases:
     *   - en-* (most): "{0}–{1}" without spaces, but currency
     *     style adds surrounding spaces ("$3 – $5").
     *   - pt-PT: "{0} - {1}" with regular hyphen.
     *   - other locales: en-dash separator.
     */
    /**
     * CLDR's "approximately" sign for the locale, used when a
     * numeric range collapses to a single rendered value via
     * rounding. Most locales use "~"; some use a localised glyph
     * (e.g. "약 " in ko CLDR data).
     */
    private static function numberFormatApproximatelyPrefix(JsObject $nf): string
    {
        return '~';
    }

    /**
     * Pull a precision-preserving decimal string from a numeric
     * argument when one is available (BigInt, or a string already
     * containing a decimal representation that exceeds double
     * precision). Returns null when the regular float pipeline is
     * sufficient.
     */
    private static function extractHighPrecisionNumeric(JsValue $val): ?string
    {
        if ($val instanceof \Phasis\Value\JsBigInt) {
            $abs = ltrim($val->value, '-');
            $fitsInDouble = strlen($abs) < 16
                || (strlen($abs) === 16 && strcmp($abs, '9007199254740992') <= 0);
            return $fitsInDouble ? null : $val->value;
        }
        if ($val instanceof JsString) {
            $s = trim($val->value);
            if (preg_match('/^[+-]?\d+(?:\.\d+)?$/', $s) !== 1) {
                return null;
            }
            $absDigits = ltrim(str_replace(['+', '-', '.'], '', $s), '0');
            if (strlen($absDigits) >= 16) {
                return $s;
            }
        }
        return null;
    }

    /**
     * The longest shared currency prefix between two formatted
     * range endpoints. We walk left-to-right while the characters
     * agree and stop once we hit the first numeric digit.
     */
    private static function sharedCurrencyPrefix(string $a, string $b): string
    {
        $shared = '';
        $len = min(strlen($a), strlen($b));
        for ($i = 0; $i < $len; $i++) {
            $ch = $a[$i];
            if ($ch !== $b[$i]) {
                break;
            }
            // Stop once we reach the first digit so the second value
            // keeps its mantissa.
            if (ctype_digit($ch)) {
                break;
            }
            $shared .= $ch;
        }
        return $shared;
    }

    /**
     * Collapsed range separator (no surrounding spaces) used when
     * the end value's prefix has already been stripped.
     */
    private static function numberFormatRangeSeparatorCollapsed(JsObject $nf): string
    {
        return "\u{2013}";
    }

    /**
     * Detect whether a shared affix string contains any character
     * that could plausibly be a currency symbol (anything outside
     * the ASCII whitespace / sign / paren / digit set).
     */
    private static function containsCurrencyChar(string $affix): bool
    {
        if ($affix === '') {
            return false;
        }
        // Strip ASCII sign / whitespace / digits / parens; if anything
        // remains it's likely a currency glyph (Latin letter sequence
        // for ISO codes, "$"/"€"/"¥" etc.).
        $stripped = preg_replace('/[+\-\s\d()\\.,]/u', '', $affix);
        return is_string($stripped) && $stripped !== '';
    }

    /**
     * Longest shared NON-DIGIT trailing run of two formatted
     * values. Walks right-to-left from each end and stops at the
     * first numeric digit. Used for collapsing a shared currency
     * suffix in suffix-currency locales like pt-PT.
     */
    private static function sharedCurrencySuffix(string $a, string $b): string
    {
        $aLen = strlen($a);
        $bLen = strlen($b);
        $shared = '';
        $i = 0;
        while ($i < $aLen && $i < $bLen) {
            $cha = $a[$aLen - 1 - $i];
            $chb = $b[$bLen - 1 - $i];
            if ($cha !== $chb) {
                break;
            }
            if (ctype_digit($cha)) {
                break;
            }
            $shared = $cha . $shared;
            $i++;
        }
        // Strip a leading whitespace from the suffix so it stays
        // attached to the *end* value rather than the separator.
        return ltrim($shared);
    }

    private static function numberFormatRangeSeparator(JsObject $nf): string
    {
        $locale = self::extractInternalString($nf, '[[Locale]]', 'en');
        $lang = strtolower(strtok($locale, '-_'));
        $region = '';
        if (preg_match('/^[a-z]{2,3}(?:-[a-z]{4})?-([a-z]{2}|\d{3})/i', $locale, $m) === 1) {
            $region = strtoupper($m[1]);
        }
        $style = self::extractInternalString($nf, '[[Style]]', 'decimal');
        if ($lang === 'pt' && $region === 'PT') {
            return ' - ';
        }
        if ($style === 'currency') {
            return ' – ';
        }
        return '–';
    }

    /**
     * Coerce a numeric argument to a PHP float, accepting BigInt
     * (cast via its string value) so that
     * `Intl.NumberFormat.prototype.formatRange(23n, 12n)` doesn't
     * trip the BigInt-to-Number TypeError. Other values fall through
     * the standard ToNumber pipeline.
     */
    private static function numericArgToFloat(JsValue $val): float
    {
        if ($val instanceof \Phasis\Value\JsBigInt) {
            return (float) $val->value;
        }
        return TypeConversion::toNumber($val);
    }

    /**
     * Round a decimal string (split into integer + fraction parts)
     * to the requested significant-digit window, preserving its
     * order of magnitude. Trailing digits past max-sig become zero
     * (rounded half-up). Returns [int, frac].
     *
     * @return array{0: string, 1: string}
     */
    private static function roundDecimalStringToSigDigits(
        string $intPart,
        string $fracPart,
        int $minSig,
        int $maxSig,
    ): array {
        $combined = $intPart . $fracPart;
        // Strip leading zeros to find the first significant digit's
        // position, but remember the original integer length so we
        // can reconstruct magnitude.
        $sigStart = 0;
        $combinedLen = strlen($combined);
        while ($sigStart < $combinedLen && $combined[$sigStart] === '0') {
            $sigStart++;
        }
        if ($sigStart >= $combinedLen) {
            // The value is zero. Pad to minSig zeros.
            $intResult = $intPart === '' ? '0' : $intPart;
            $fracResult = str_pad($fracPart, max($minSig - 1, 0), '0');
            return [$intResult, $fracResult];
        }
        $sigDigits = substr($combined, $sigStart);
        if (strlen($sigDigits) > $maxSig) {
            $kept = substr($sigDigits, 0, $maxSig);
            $rounder = $sigDigits[$maxSig];
            if ($rounder >= '5') {
                // Round up the kept portion; propagate carry through
                // the digits string.
                $kept = self::incrementDigits($kept);
                if (strlen($kept) > $maxSig) {
                    // Carry overflowed; the value scaled up by one
                    // order of magnitude. Strip the trailing zero
                    // since the leading digits already represent it.
                    $sigStart--;
                    if ($sigStart < 0) {
                        // Adding a new leading digit slot.
                        $intPart = '1' . str_repeat('0', strlen($intPart));
                    }
                    $kept = substr($kept, 0, $maxSig);
                }
            }
            // Pad kept to original length with zeros.
            $sigDigits = $kept . str_repeat('0', strlen($sigDigits) - strlen($kept));
        }
        // Pad to minSig if shorter.
        if (strlen($sigDigits) < $minSig) {
            $sigDigits = str_pad($sigDigits, $minSig, '0');
        }
        // Reconstruct: leading zeros + sig digits.
        $reconstructed = str_repeat('0', $sigStart) . $sigDigits;
        $origIntLen = strlen($intPart);
        if (strlen($reconstructed) > $origIntLen) {
            return [
                substr($reconstructed, 0, $origIntLen),
                substr($reconstructed, $origIntLen),
            ];
        }
        return [
            str_pad($reconstructed, $origIntLen, '0', STR_PAD_LEFT),
            '',
        ];
    }

    /** Add 1 to a decimal-digit string, returning the new (possibly longer) string. */
    private static function incrementDigits(string $digits): string
    {
        $len = strlen($digits);
        $out = $digits;
        for ($i = $len - 1; $i >= 0; $i--) {
            $d = ord($out[$i]) - ord('0');
            if ($d < 9) {
                $out[$i] = (string) ($d + 1);
                return $out;
            }
            $out[$i] = '0';
        }
        return '1' . $out;
    }

    /**
     * Render a high-precision numeric string (BigInt or decimal
     * literal) using the locale's grouping / decimal symbols while
     * honouring useGrouping / minimumIntegerDigits. Decimal inputs
     * keep their full fractional precision (only the integer part
     * is grouped; the fractional part is unchanged).
     */
    private static function renderBigIntStringLocaleAware(
        string $bigIntStr,
        \NumberFormatter $formatter,
        JsObject $nf,
    ): string {
        $sign = '';
        $rest = $bigIntStr;
        if (str_starts_with($rest, '-')) {
            // Probe the formatter to learn the locale's actual sign
            // prefix (RTL locales like Arabic emit U+200E + "-").
            $probe = $formatter->format(-1);
            $rest = substr($rest, 1);
            if (is_string($probe) && preg_match('/^([^0-9]+)/u', $probe, $m) === 1) {
                $sign = $m[1];
            } else {
                $sign = '-';
            }
        } elseif (str_starts_with($rest, '+')) {
            $rest = substr($rest, 1);
        }
        $dotPos = strpos($rest, '.');
        if ($dotPos === false) {
            $intPart = $rest;
            $fracPart = '';
        } else {
            $intPart = substr($rest, 0, $dotPos);
            $fracPart = substr($rest, $dotPos + 1);
        }
        // Apply max-sig-digit rounding before grouping so the decimal
        // representation matches what NumberFormatter would emit for
        // an in-range value.
        $rt = self::extractInternalString($nf, '[[RoundingType]]', 'fractionDigits');
        if ($rt === 'significantDigits') {
            $maxSig = (int) self::extractInternalNumber($nf, '[[MaximumSignificantDigits]]', 21);
            $minSig = (int) self::extractInternalNumber($nf, '[[MinimumSignificantDigits]]', 1);
            [$intPart, $fracPart] = self::roundDecimalStringToSigDigits(
                $intPart,
                $fracPart,
                $minSig,
                $maxSig,
            );
        }
        $minInt = (int) self::extractInternalNumber($nf, '[[MinimumIntegerDigits]]', 1);
        if ($minInt > strlen($intPart)) {
            $intPart = str_repeat('0', $minInt - strlen($intPart)) . $intPart;
        }
        $useGrouping = self::extractInternalString($nf, '[[UseGrouping]]', 'auto');
        $groupSym = $formatter->getSymbol(\NumberFormatter::GROUPING_SEPARATOR_SYMBOL);
        $shouldGroup = match ($useGrouping) {
            'false' => false,
            'always' => true,
            'min2' => strlen($intPart) > 4,
            default => strlen($intPart) > 3,
        };
        $intRendered = $intPart;
        if ($shouldGroup && $groupSym !== '') {
            // Split from the right into 3-digit groups.
            $intRendered = '';
            $len = strlen($intPart);
            for ($i = $len; $i > 0; $i -= 3) {
                $start = max(0, $i - 3);
                $chunk = substr($intPart, $start, $i - $start);
                $intRendered = $chunk . ($intRendered === '' ? '' : $groupSym . $intRendered);
            }
        }
        // Honour minimumFractionDigits / maximumFractionDigits when
        // rendering the decimal portion. The integer portion is
        // already rendered above, so the fraction part is independent.
        $rt = self::extractInternalString($nf, '[[RoundingType]]', 'fractionDigits');
        if ($rt === 'fractionDigits') {
            $minFrac = (int) self::extractInternalNumber($nf, '[[MinimumFractionDigits]]', 0);
            $maxFrac = (int) self::extractInternalNumber($nf, '[[MaximumFractionDigits]]', 3);
            if ($maxFrac < strlen($fracPart)) {
                $fracPart = substr($fracPart, 0, $maxFrac);
            }
            // Trim trailing zeros down to the minimum required count.
            while (strlen($fracPart) > $minFrac && substr($fracPart, -1) === '0') {
                $fracPart = substr($fracPart, 0, -1);
            }
            if (strlen($fracPart) < $minFrac) {
                $fracPart = str_pad($fracPart, $minFrac, '0');
            }
        }
        if ($fracPart === '') {
            return $sign . $intRendered;
        }
        $decimalSym = $formatter->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
        if ($decimalSym === '') {
            $decimalSym = '.';
        }
        return $sign . $intRendered . $decimalSym . $fracPart;
    }

    /**
     * Resolve roundingPriority by formatting the value via each
     * rule (sig-only, frac-only) and picking per the spec.
     *
     * The spec algorithm (FormatApproximately) compares the rounding
     * magnitude of each candidate and picks based on whether a min
     * or max sig/frac is in play. We approximate it here:
     *   - When BOTH a min and max for sig/frac are explicit (the
     *     "fully-bounded" case), pick by fractional-digit count
     *     (morePrecision = more, lessPrecision = fewer).
     *   - When ONLY mins are provided (no max sig nor max frac),
     *     morePrecision deterministically picks the sig path (and
     *     vice versa for lessPrecision); this matches V8.
     *   - For asymmetric configurations (e.g. minSig + maxFrac),
     *     fall back to the same fractional-digit count comparison.
     */
    private static function formatNumberWithPriority(
        JsObject $nf,
        float $number,
        ?string $bigIntStr,
        string $priority,
    ): string {
        $hasMaxSig = $nf->get('[[HasMaxSig]]') instanceof JsBoolean
            && $nf->get('[[HasMaxSig]]')->toBoolean();
        $hasMaxFrac = $nf->get('[[HasMaxFrac]]') instanceof JsBoolean
            && $nf->get('[[HasMaxFrac]]')->toBoolean();
        $sigClone = self::cloneNumberFormatWithRoundingType($nf, 'significantDigits');
        $fracClone = self::cloneNumberFormatWithRoundingType($nf, 'fractionDigits');
        $sigStr = self::formatNumber($sigClone, $number, $bigIntStr);
        $fracStr = self::formatNumber($fracClone, $number, $bigIntStr);
        // Mins-only: deterministic sig vs frac dispatch.
        if (!$hasMaxSig && !$hasMaxFrac) {
            return $priority === 'morePrecision' ? $sigStr : $fracStr;
        }
        // Spec compares each path's rounding magnitude (the place value of
        // the last digit kept after rounding, not the trimmed display):
        //   sigMag = e - (maxSd - 1), where e = floor(log10(|x|))
        //   fracMag = -maxFd
        // morePrecision keeps the smaller (more negative) magnitude.
        $maxSdSlot = $nf->get('[[MaximumSignificantDigits]]');
        $maxSd = $maxSdSlot instanceof JsNumber ? (int) $maxSdSlot->value : 21;
        $maxFdSlot = $nf->get('[[MaximumFractionDigits]]');
        $maxFd = $maxFdSlot instanceof JsNumber ? (int) $maxFdSlot->value : 0;
        $absVal = abs($number);
        if ($absVal === 0.0 || !is_finite($absVal)) {
            $sigMag = 0;
        } else {
            $e = (int) floor(log10($absVal));
            $sigMag = $e - ($maxSd - 1);
        }
        $fracMag = -$maxFd;
        if ($priority === 'morePrecision') {
            return $sigMag <= $fracMag ? $sigStr : $fracStr;
        }
        return $sigMag <= $fracMag ? $fracStr : $sigStr;
    }

    /**
     * Build a shallow copy of the NumberFormat object whose
     * [[RoundingType]] slot is overridden so a single roundingType
     * codepath kicks in. The clone shares all other slots by
     * reference (no deep copy needed for read-only formatting).
     */
    private static function cloneNumberFormatWithRoundingType(JsObject $nf, string $rt): JsObject
    {
        $clone = new JsObject($nf->getPrototype());
        // Mark as initialised so brand checks pass downstream.
        $clone->defineOwnProperty('[[InitializedNumberFormat]]', PropertyDescriptor::data(
            new JsBoolean(true),
            false,
            false,
            false,
        ));
        // Copy every internal-slot ([[X]]) from the source. Use the
        // explicit slot list since getOwnPropertyNames hides internal
        // slots (per spec, internal slots are not own property keys).
        $slots = [
            '[[Locale]]', '[[NumberingSystem]]', '[[Style]]',
            '[[Currency]]', '[[CurrencyDisplay]]', '[[CurrencySign]]',
            '[[Unit]]', '[[UnitDisplay]]',
            '[[MinimumIntegerDigits]]',
            '[[MinimumFractionDigits]]', '[[MaximumFractionDigits]]',
            '[[MinimumSignificantDigits]]', '[[MaximumSignificantDigits]]',
            '[[UseGrouping]]', '[[Notation]]', '[[CompactDisplay]]',
            '[[SignDisplay]]', '[[RoundingMode]]',
            '[[RoundingIncrement]]', '[[RoundingPriority]]',
            '[[TrailingZeroDisplay]]',
        ];
        foreach ($slots as $slot) {
            $val = $nf->get($slot);
            if (!$val instanceof JsUndefined) {
                $clone->defineOwnProperty($slot, PropertyDescriptor::data(
                    $val,
                    false,
                    false,
                    false,
                ));
            }
        }
        // Override RoundingType.
        $clone->defineOwnProperty('[[RoundingType]]', PropertyDescriptor::data(
            new JsString($rt),
            false,
            false,
            false,
        ));
        return $clone;
    }


    private static function formatNumber(JsObject $nf, float $number, ?string $bigIntStr = null): string
    {
        $locale = self::extractInternalString($nf, '[[Locale]]', 'en');
        $style = self::extractInternalString($nf, '[[Style]]', 'decimal');
        $numberingSystem = self::extractInternalString($nf, '[[NumberingSystem]]', 'latn');
        $notation = self::extractInternalString($nf, '[[Notation]]', 'standard');
        $rtTop = self::extractInternalString($nf, '[[RoundingType]]', 'fractionDigits');

        // Mixed sig+frac priority modes: format both ways and pick.
        if ($rtTop === 'morePrecision' || $rtTop === 'lessPrecision') {
            return self::formatNumberWithPriority($nf, $number, $bigIntStr, $rtTop);
        }

        // Engineering / scientific notations decompose into a
        // mantissa + locale-rendered exponent.
        if ($notation === 'engineering' || $notation === 'scientific') {
            return self::formatScientificNumber($nf, $number, $notation);
        }
        // Compact notation: covers en-US short/long; other locales
        // share the same structure with localised suffix labels.
        if ($notation === 'compact') {
            $compact = self::formatCompactNumber($nf, $number);
            return self::applySignDisplay($nf, $compact, $number);
        }

        $fmtStyle = match ($style) {
            'currency' => \NumberFormatter::CURRENCY,
            'percent' => \NumberFormatter::PERCENT,
            default => \NumberFormatter::DECIMAL,
        };

        // Encode the numbering system as a `-u-nu-…` extension on the
        // ICU locale so non-Latn digits are emitted (Arabic, Thai,
        // Adlam, ...). Without this every NumberFormat would render
        // 0-9 in Latin digits regardless of [[NumberingSystem]].
        $icuLocale = str_replace('-', '_', $locale);
        if ($numberingSystem !== 'latn' && $numberingSystem !== '') {
            $icuLocale = $icuLocale . '@numbers=' . $numberingSystem;
        }
        $formatter = new \NumberFormatter($icuLocale, $fmtStyle);

        $minInt = (int) self::extractInternalNumber($nf, '[[MinimumIntegerDigits]]', 1);
        $formatter->setAttribute(\NumberFormatter::MIN_INTEGER_DIGITS, $minInt);

        $rt = self::extractInternalString($nf, '[[RoundingType]]', 'fractionDigits');
        $maxFrac = null;
        if ($rt === 'significantDigits') {
            $minSig = (int) self::extractInternalNumber($nf, '[[MinimumSignificantDigits]]', 1);
            $maxSig = (int) self::extractInternalNumber($nf, '[[MaximumSignificantDigits]]', 21);
            $formatter->setAttribute(\NumberFormatter::MIN_SIGNIFICANT_DIGITS, $minSig);
            $formatter->setAttribute(\NumberFormatter::MAX_SIGNIFICANT_DIGITS, $maxSig);
        } else {
            $minFrac = (int) self::extractInternalNumber($nf, '[[MinimumFractionDigits]]', 0);
            $maxFrac = (int) self::extractInternalNumber($nf, '[[MaximumFractionDigits]]', 3);
            $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $minFrac);
            $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $maxFrac);
        }

        $ug = self::extractInternalString($nf, '[[UseGrouping]]', 'auto');
        if ($ug === 'false') {
            $formatter->setAttribute(\NumberFormatter::GROUPING_USED, 0);
        }
        // useGrouping "min2" is post-processed below since ICU's
        // minimumGroupingDigits attribute isn't reliably exposed via
        // PHP across versions.

        // Map the spec's roundingMode to ICU's ROUNDING_MODE.
        // ICU's default is HALFEVEN, but the spec default is halfExpand,
        // so we always explicitly set this.
        $rm = self::extractInternalString($nf, '[[RoundingMode]]', 'halfExpand');
        // halfCeil / halfFloor depend on sign: at the half-way point,
        // halfCeil rounds toward +∞ (so positives go up / away from
        // zero, negatives go up / toward zero), halfFloor rounds
        // toward -∞.
        $isNegative = $number < 0 || ($number === 0.0 && self::isNegativeZero($number));
        $icuMode = match ($rm) {
            'ceil' => \NumberFormatter::ROUND_CEILING,
            'floor' => \NumberFormatter::ROUND_FLOOR,
            'trunc' => \NumberFormatter::ROUND_DOWN,
            'expand' => \NumberFormatter::ROUND_UP,
            'halfCeil' => $isNegative
                ? \NumberFormatter::ROUND_HALFDOWN
                : \NumberFormatter::ROUND_HALFUP,
            'halfFloor' => $isNegative
                ? \NumberFormatter::ROUND_HALFUP
                : \NumberFormatter::ROUND_HALFDOWN,
            'halfTrunc' => \NumberFormatter::ROUND_HALFDOWN,
            'halfEven' => \NumberFormatter::ROUND_HALFEVEN,
            default => \NumberFormatter::ROUND_HALFUP,
        };
        $formatter->setAttribute(\NumberFormatter::ROUNDING_MODE, $icuMode);

        // roundingIncrement uses ICU's "rounding increment" attribute.
        $roundingIncrement = (int) self::extractInternalNumber($nf, '[[RoundingIncrement]]', 1);
        if ($roundingIncrement > 1 && $maxFrac !== null) {
            $factor = 10 ** $maxFrac;
            $formatter->setAttribute(
                \NumberFormatter::ROUNDING_INCREMENT,
                $roundingIncrement / $factor,
            );
        }

        // Unit style: ICU doesn't expose a units-formatter via PHP, so
        // we format the number with the same options as decimal style
        // and suffix the unit. CLDR's English templates use:
        //   - narrow: <number><unit-symbol>      (no separator)
        //   - short: <number><space><unit-symbol>
        //   - long:  <number><space><unit-name>
        if ($style === 'unit') {
            $bareResult = $formatter->format($number);
            if ($bareResult === false) {
                $bareResult = (string) $number;
            }
            $unit = self::extractInternalString($nf, '[[Unit]]', '');
            $unitDisplay = self::extractInternalString($nf, '[[UnitDisplay]]', 'short');
            $localeForUnit = self::extractInternalString($nf, '[[Locale]]', 'en');
            $bareResult = self::normalizeIntlInfinity($bareResult, $number);
            $bareResult = self::applySignDisplay($nf, $bareResult, $number);
            $pattern = self::localeUnitPattern($localeForUnit, $unit, $unitDisplay);
            if ($pattern !== null) {
                return str_replace('{0}', $bareResult, $pattern);
            }
            // CLDR pattern lookup with plural-awareness. ResourceBundle
            // returns {"one": "{0} day", "other": "{0} days"} for the
            // requested locale, falling back through CLDR's locale
            // hierarchy automatically.
            $cldrPatterns = self::cldrUnitPattern($localeForUnit, $unit, $unitDisplay);
            if ($cldrPatterns !== []) {
                $plural = self::selectPlural($localeForUnit, abs((float) $number), 'cardinal');
                $pat = $cldrPatterns[$plural] ?? $cldrPatterns['other'] ?? null;
                if ($pat !== null) {
                    return str_replace('{0}', $bareResult, $pat);
                }
            }
            // Fallback: render the unit label with English-derived
            // separators ({0}<sep><unit>).
            $unitLabel = self::renderUnitLabel($unit, $unitDisplay);
            $isPercentNoSpace = $unit === 'percent' && $unitDisplay !== 'long';
            $separator = ($unitDisplay === 'narrow' || $isPercentNoSpace) ? '' : ' ';
            return $unitLabel === ''
                ? $bareResult
                : $bareResult . $separator . $unitLabel;
        }

        if ($style === 'currency') {
            $currency = self::extractInternalString($nf, '[[Currency]]', 'USD');
            // ICU's NumberFormatter::CURRENCY_ACCOUNTING preset
            // surrounds negative amounts with parentheses per CLDR.
            $currencySign = self::extractInternalString($nf, '[[CurrencySign]]', 'standard');
            if ($currencySign === 'accounting') {
                $accountingFmt = new \NumberFormatter(
                    $icuLocale,
                    \NumberFormatter::CURRENCY_ACCOUNTING,
                );
                foreach (['ROUNDING_MODE', 'GROUPING_USED', 'MIN_INTEGER_DIGITS'] as $attr) {
                    if (defined("\\NumberFormatter::{$attr}")) {
                        $accountingFmt->setAttribute(
                            constant("\\NumberFormatter::{$attr}"),
                            $formatter->getAttribute(constant("\\NumberFormatter::{$attr}")),
                        );
                    }
                }
                if ($maxFrac !== null) {
                    $accountingFmt->setAttribute(
                        \NumberFormatter::MIN_FRACTION_DIGITS,
                        (int) self::extractInternalNumber($nf, '[[MinimumFractionDigits]]', 0),
                    );
                    $accountingFmt->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $maxFrac);
                }
                $result = $accountingFmt->formatCurrency($number, $currency);
            } else {
                $result = $formatter->formatCurrency($number, $currency);
            }
        } else {
            // BigInt: render the full decimal string with locale
            // grouping / decimal symbols so values past the double
            // mantissa range (90071...910 → ...900) keep their
            // precision.
            if ($bigIntStr !== null) {
                $result = self::renderBigIntStringLocaleAware(
                    $bigIntStr,
                    $formatter,
                    $nf,
                );
            } else {
                $result = $formatter->format($number);
            }
            if ($result === false) {
                $result = (string) $number;
            }
        }

        // PHP/ICU uses the locale-specific Infinity glyph (e.g. "INF" for
        // ja-JP). The spec mandates the "∞" U+221E symbol. Substitute
        // the textual marker before applying signDisplay.
        $result = self::normalizeIntlInfinity($result, $number);
        $result = self::applySignDisplay($nf, $result, $number);
        // useGrouping "min2": strip the leading group separator if
        // the leading group is a single digit (e.g. en-US "1,000"
        // -> "1000", de-DE "1.000" -> "1000").
        if ($ug === 'min2' && is_finite($number)) {
            $result = self::stripMin2GroupingSeparator($result);
        }

        return $result;
    }

    /**
     * For useGrouping "min2", remove the FIRST group separator if the
     * leading group has exactly one digit. Pure ASCII / Unicode-digit
     * walk that doesn't disturb a sign prefix or suffix.
     */
    private static function stripMin2GroupingSeparator(string $formatted): string
    {
        if ($formatted === '') {
            return $formatted;
        }
        // Match: optional sign / paren, one digit, separator, three digits
        // followed by either end-of-string or a non-digit.
        $pattern = '/^([^\p{Nd}]*)(\p{Nd})([^\p{Nd}\s])(\p{Nd}{3})(?=$|[^\p{Nd}])/u';
        $altPattern = '/^([^\p{Nd}]*)(\p{Nd})(\s)(\p{Nd}{3})(?=$|[^\p{Nd}])/u';
        // The separator could be a thin space, a comma, a period, a
        // U+202F narrow no-break space, etc. Try both patterns.
        if (preg_match($pattern, $formatted, $m) === 1) {
            return $m[1] . $m[2] . $m[4] . substr($formatted, strlen($m[0]));
        }
        if (preg_match($altPattern, $formatted, $m) === 1) {
            return $m[1] . $m[2] . $m[4] . substr($formatted, strlen($m[0]));
        }
        return $formatted;
    }

    /**
     * Replace the locale-specific Infinity glyph used by some ICU bundles
     * (e.g. "INF" for ja-JP) with the U+221E "∞" symbol the spec mandates.
     */
    private static function normalizeIntlInfinity(string $formatted, float $number): string
    {
        if (!is_finite($number) && !is_nan($number)) {
            // Replace standalone "INF" — surrounded by non-letter
            // characters or at string boundaries — with "∞". Avoid
            // touching e.g. "INFANTRY" embedded in a hypothetical
            // currency name.
            return preg_replace('/\bINF\b/', '∞', $formatted) ?? $formatted;
        }
        return $formatted;
    }

    /**
     * Apply the spec's signDisplay option as a post-processing step.
     * ICU's NumberFormat doesn't expose all of "always", "never",
     * "exceptZero", "negative", so we walk the formatted output to add
     * or strip the leading "+"/"-" prefix.
     */
    private static function applySignDisplay(JsObject $nf, string $formatted, float $number): string
    {
        $signDisplay = self::extractInternalString($nf, '[[SignDisplay]]', 'auto');
        if ($signDisplay === 'auto') {
            return $formatted;
        }
        $isNegative = ($number < 0) || ($number === 0.0 && self::isNegativeZero($number));
        $isNaN = is_nan($number);
        // "Rounded to zero" comparison uses the formatted string so that
        // small magnitudes like -0.0001 with maxFractionDigits=3 are
        // recognised as the rounded zero they actually display as.
        $digitsOnly = preg_replace('/[^0-9\x{0660}-\x{0669}\x{06F0}-\x{06F9}\x{0E50}-\x{0E59}]/u', '', $formatted);
        $roundedToZero = $digitsOnly !== null
            && $digitsOnly !== ''
            && preg_match('/^[0\x{0660}\x{06F0}\x{0E50}]+$/u', $digitsOnly) === 1;
        $effectiveZero = $roundedToZero || $isNaN;
        $hasMinus = str_contains($formatted, '-');
        $hasParens = str_starts_with($formatted, '(') && str_ends_with($formatted, ')');
        // For currency accounting form, the negative indicator is the
        // parenthesis pair, not a "-" prefix. Treat that the same as a
        // minus when applying sign-display rules.
        $negativeShown = $hasMinus || $hasParens;
        $stripNegative = static function (string $s) use ($hasParens): string {
            if ($hasParens) {
                return substr($s, 1, -1);
            }
            return str_replace('-', '', $s);
        };

        switch ($signDisplay) {
            case 'never':
                return $stripNegative($formatted);
            case 'always':
                if ($isNegative || $negativeShown) {
                    return $formatted;
                }
                return '+' . $formatted;
            case 'exceptZero':
                if ($effectiveZero) {
                    return $stripNegative($formatted);
                }
                if ($isNegative || $negativeShown) {
                    return $formatted;
                }
                return '+' . $formatted;
            case 'negative':
                if ($isNegative && !$effectiveZero) {
                    return $formatted;
                }
                return $stripNegative($formatted);
        }
        return $formatted;
    }

    private static function isNegativeZero(float $n): bool
    {
        if ($n !== 0.0) {
            return false;
        }
        // Inspect the IEEE 754 sign bit directly to avoid the
        // DivisionByZeroError raised by `1 / 0.0` under PHP 8.
        $packed = pack('d', $n);
        return ord($packed[7]) >= 0x80;
    }
}
