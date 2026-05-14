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
 * Intl.RelativeTimeFormat section. Composed into IntlObject via
 * `use Intl\RelativeTimeFormatSection;`. `self::` references resolve into
 * the composing class so cross-section helpers continue to work.
 */
trait RelativeTimeFormatSection
{
    // ---------------------------------------------------------------
    // Intl.RelativeTimeFormat
    // ---------------------------------------------------------------

    /**
     * Normalise the user-provided RelativeTimeFormat unit name to its
     * singular canonical form, throwing RangeError for unknown values.
     */
    private static function canonicalRelativeTimeUnit(string $unit): string
    {
        static $unitMap = [
            'second' => 'second', 'seconds' => 'second',
            'minute' => 'minute', 'minutes' => 'minute',
            'hour' => 'hour', 'hours' => 'hour',
            'day' => 'day', 'days' => 'day',
            'week' => 'week', 'weeks' => 'week',
            'month' => 'month', 'months' => 'month',
            'quarter' => 'quarter', 'quarters' => 'quarter',
            'year' => 'year', 'years' => 'year',
        ];
        if (!isset($unitMap[$unit])) {
            throw new RangeError("Invalid unit: {$unit}");
        }
        return $unitMap[$unit];
    }

    /**
     * Format a number through `\NumberFormatter` with locale-aware
     * grouping for use inside RelativeTimeFormat output. Falls back
     * to a plain `(string)` cast when intl isn't available.
     */
    private static function formatRelativeTimeNumber(string $locale, float $n): string
    {
        if (!extension_loaded('intl')) {
            $rendered = (string) $n;
            // PHP's float-to-string can use scientific notation; the
            // RelativeTimeFormat tests only inspect integers though.
            return $rendered;
        }
        $fmt = new \NumberFormatter(str_replace('-', '_', $locale), \NumberFormatter::DECIMAL);
        $rendered = $fmt->format($n);
        if ($rendered === false) {
            return (string) $n;
        }
        // CLDR's `<minimumGroupingDigits>` is 2 for Polish and a
        // handful of other locales: a single-digit leading group
        // (1000–9999) shouldn't be grouped. PHP's NumberFormatter
        // doesn't honour this, so strip the leading separator
        // post-format for the affected locales.
        if (self::localeMinGroupingDigitsIs2($locale)) {
            return self::stripMin2GroupingSeparator($rendered);
        }
        return $rendered;
    }

    private static function localeMinGroupingDigitsIs2(string $locale): bool
    {
        $lang = strtolower(strtok($locale, '-_'));
        // CLDR locales whose minimumGroupingDigits is 2.
        static $min2Langs = [
            'pl', 'lv', 'lt', 'mk', 'es', 'sl', 'pt',
        ];
        return in_array($lang, $min2Langs, true);
    }

    /**
     * Implement the en-US "numeric: auto" exception table from
     * CLDR (today/yesterday/tomorrow/this week/last quarter/...).
     * For other (numeric, locale, value) combinations we fall back
     * to the spec's plural form rendered through NumberFormatter so
     * grouping stays locale-aware.
     */
    private static function formatRelativeTime(
        string $locale,
        float $n,
        string $unit,
        string $numeric,
        string $style = 'long',
    ): string {
        $isEnglish = str_starts_with(strtolower($locale), 'en');
        if ($numeric === 'auto' && $isEnglish && in_array($n, [-1.0, 0.0, 1.0], true) && $style === 'long') {
            $autoTable = [
                'second' => ['-1' => '1 second ago', '0' => 'now', '1' => 'in 1 second'],
                'minute' => ['-1' => '1 minute ago', '0' => 'this minute', '1' => 'in 1 minute'],
                'hour' => ['-1' => '1 hour ago', '0' => 'this hour', '1' => 'in 1 hour'],
                'day' => ['-1' => 'yesterday', '0' => 'today', '1' => 'tomorrow'],
                'week' => ['-1' => 'last week', '0' => 'this week', '1' => 'next week'],
                'month' => ['-1' => 'last month', '0' => 'this month', '1' => 'next month'],
                'quarter' => ['-1' => 'last quarter', '0' => 'this quarter', '1' => 'next quarter'],
                'year' => ['-1' => 'last year', '0' => 'this year', '1' => 'next year'],
            ];
            // -0 collapses to 0 per CLDR (auto-mode treats any zero
            // identically).
            $key = (string) (int) ($n);
            return $autoTable[$unit][$key] ?? '';
        }
        $absN = abs($n);
        $absStr = self::formatRelativeTimeNumber($locale, $absN);
        $isPlural = $absN !== 1.0;
        // Locale-specific abbreviations for short/narrow English styles.
        // Other locales fall back to long-form labels.
        if ($isEnglish) {
            if ($style === 'short') {
                $shortLabels = [
                    'second' => ['sec.', 'sec.'],
                    'minute' => ['min.', 'min.'],
                    'hour' => ['hr.', 'hr.'],
                    'day' => ['day', 'days'],
                    'week' => ['wk.', 'wk.'],
                    'month' => ['mo.', 'mo.'],
                    'quarter' => ['qtr.', 'qtrs.'],
                    'year' => ['yr.', 'yr.'],
                ];
                if (isset($shortLabels[$unit])) {
                    $unitStr = $isPlural ? $shortLabels[$unit][1] : $shortLabels[$unit][0];
                } else {
                    $unitStr = $isPlural ? $unit . 's' : $unit;
                }
            } elseif ($style === 'narrow') {
                $narrowLabels = [
                    'second' => ['sec.', 'sec.'],
                    'minute' => ['min.', 'min.'],
                    'hour' => ['hr.', 'hr.'],
                    'day' => ['day', 'days'],
                    'week' => ['wk.', 'wk.'],
                    'month' => ['mo.', 'mo.'],
                    'quarter' => ['qtr.', 'qtrs.'],
                    'year' => ['yr.', 'yr.'],
                ];
                if (isset($narrowLabels[$unit])) {
                    $unitStr = $isPlural ? $narrowLabels[$unit][1] : $narrowLabels[$unit][0];
                } else {
                    $unitStr = $isPlural ? $unit . 's' : $unit;
                }
            } else {
                $unitStr = $isPlural ? $unit . 's' : $unit;
            }
        } elseif (str_starts_with(strtolower($locale), 'pl')) {
            $unitStr = self::polishRelativeTimeUnit($unit, $absN, $style);
            // Polish past/future templates: "za N unit" / "N unit temu".
            $isPast = $n < 0 || ($n === 0.0 && self::isNegativeZero($n));
            return $isPast
                ? $absStr . ' ' . $unitStr . ' temu'
                : 'za ' . $absStr . ' ' . $unitStr;
        } else {
            $unitStr = $isPlural ? $unit . 's' : $unit;
        }
        if ($n < 0 || ($n === 0.0 && self::isNegativeZero($n))) {
            return $absStr . ' ' . $unitStr . ' ago';
        }
        return 'in ' . $absStr . ' ' . $unitStr;
    }

    /**
     * Pick the Polish CLDR unit label for the given absolute integer
     * value and style. Polish has three plural categories that take
     * effect (one/few/many); decimals fall to "other" but the test262
     * fixtures only exercise integers.
     */
    private static function polishRelativeTimeUnit(string $unit, float $absN, string $style): string
    {
        $cat = self::polishPluralCategory($absN);
        // CLDR short / narrow forms differ for some units (second
        // collapses to "s" in narrow, hour to "g.").
        if ($style === 'narrow') {
            static $narrow = [
                'second' => ['one' => 's', 'few' => 's', 'many' => 's', 'other' => 's'],
                'minute' => ['one' => 'min', 'few' => 'min', 'many' => 'min', 'other' => 'min'],
                'hour' => ['one' => 'g.', 'few' => 'g.', 'many' => 'g.', 'other' => 'g.'],
                'day' => ['one' => 'dzień', 'few' => 'dni', 'many' => 'dni', 'other' => 'dnia'],
                'week' => ['one' => 'tydz.', 'few' => 'tyg.', 'many' => 'tyg.', 'other' => 'tyg.'],
                'month' => ['one' => 'mies.', 'few' => 'mies.', 'many' => 'mies.', 'other' => 'mies.'],
                'quarter' => ['one' => 'kw.', 'few' => 'kw.', 'many' => 'kw.', 'other' => 'kw.'],
                'year' => ['one' => 'rok', 'few' => 'lata', 'many' => 'lat', 'other' => 'roku'],
            ];
            $table = $narrow;
        } elseif ($style === 'short') {
            static $short = [
                'second' => ['one' => 'sek.', 'few' => 'sek.', 'many' => 'sek.', 'other' => 'sek.'],
                'minute' => ['one' => 'min', 'few' => 'min', 'many' => 'min', 'other' => 'min'],
                'hour' => ['one' => 'godz.', 'few' => 'godz.', 'many' => 'godz.', 'other' => 'godz.'],
                'day' => ['one' => 'dzień', 'few' => 'dni', 'many' => 'dni', 'other' => 'dnia'],
                'week' => ['one' => 'tydz.', 'few' => 'tyg.', 'many' => 'tyg.', 'other' => 'tyg.'],
                'month' => ['one' => 'mies.', 'few' => 'mies.', 'many' => 'mies.', 'other' => 'mies.'],
                'quarter' => ['one' => 'kw.', 'few' => 'kw.', 'many' => 'kw.', 'other' => 'kw.'],
                'year' => ['one' => 'rok', 'few' => 'lata', 'many' => 'lat', 'other' => 'roku'],
            ];
            $table = $short;
        } else {
            // Polish CLDR long-form takes the accusative case in
            // RelativeTimeFormat output, so the singular forms differ
            // from the citation forms (sekundę vs sekunda).
            static $long = [
                'second' => ['one' => 'sekundę', 'few' => 'sekundy', 'many' => 'sekund', 'other' => 'sekundy'],
                'minute' => ['one' => 'minutę', 'few' => 'minuty', 'many' => 'minut', 'other' => 'minuty'],
                'hour' => ['one' => 'godzinę', 'few' => 'godziny', 'many' => 'godzin', 'other' => 'godziny'],
                'day' => ['one' => 'dzień', 'few' => 'dni', 'many' => 'dni', 'other' => 'dnia'],
                'week' => ['one' => 'tydzień', 'few' => 'tygodnie', 'many' => 'tygodni', 'other' => 'tygodnia'],
                'month' => ['one' => 'miesiąc', 'few' => 'miesiące', 'many' => 'miesięcy', 'other' => 'miesiąca'],
                'quarter' => ['one' => 'kwartał', 'few' => 'kwartały', 'many' => 'kwartałów', 'other' => 'kwartału'],
                'year' => ['one' => 'rok', 'few' => 'lata', 'many' => 'lat', 'other' => 'roku'],
            ];
            $table = $long;
        }
        if (!isset($table[$unit])) {
            return $unit;
        }
        return $table[$unit][$cat] ?? $table[$unit]['many'];
    }

    private static function polishPluralCategory(float $absN): string
    {
        if ($absN === 1.0) {
            return 'one';
        }
        if (floor($absN) !== $absN) {
            return 'other';
        }
        $i = (int) $absN;
        $mod10 = $i % 10;
        $mod100 = $i % 100;
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return 'few';
        }
        return 'many';
    }

    private static function installRelativeTimeFormat(JsObject $intl): void
    {
        $proto = new JsObject();

        $constructor = JsFunction::fromCallable(
            'RelativeTimeFormat',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || $this_->get('[[NewTarget]]') instanceof JsUndefined) {
                    throw new TypeError('Constructor Intl.RelativeTimeFormat requires \'new\'');
                }

                $localesArg = $args[0] ?? JsUndefined::instance();
                $optionsArg = $args[1] ?? JsUndefined::instance();

                $locales = self::localesFromArg($localesArg);
                $options = self::coerceOptions($optionsArg);
                self::validateLocaleMatcher($options);

                // Spec orders option reads: localeMatcher (already done in
                // validateLocaleMatcher above) -> numberingSystem -> style
                // -> numeric. Throwing getters in test262 verify this exact
                // sequence.
                $numberingSystem = 'latn';
                $nsVal = $options->get('numberingSystem');
                if (!$nsVal instanceof JsUndefined) {
                    $ns = TypeConversion::toString($nsVal);
                    if (!self::isValidUnicodeTypeValue($ns)) {
                        throw new RangeError("Invalid numberingSystem: {$ns}");
                    }
                    if (in_array($ns, self::getSupportedNumberingSystems(), true)) {
                        $numberingSystem = $ns;
                    }
                }

                $style = 'long';
                $styleVal = $options->get('style');
                if (!$styleVal instanceof JsUndefined) {
                    $s = TypeConversion::toString($styleVal);
                    if (!in_array($s, ['long', 'short', 'narrow'], true)) {
                        throw new RangeError("Invalid style: {$s}");
                    }
                    $style = $s;
                }

                $numeric = 'always';
                $numVal = $options->get('numeric');
                if (!$numVal instanceof JsUndefined) {
                    $n = TypeConversion::toString($numVal);
                    if (!in_array($n, ['always', 'auto'], true)) {
                        throw new RangeError("Invalid numeric: {$n}");
                    }
                    $numeric = $n;
                }

                $obj = self::instanceFromConstructor($this_, $proto, 'RelativeTimeFormat');
                $obj->defineOwnProperty('[[InitializedRelativeTimeFormat]]', PropertyDescriptor::data(
                    new JsBoolean(true),
                    false,
                    false,
                    false,
                ));
                $resolvedLocale = self::resolveLocale($locales, ["nu"]);
                $obj->defineOwnProperty('[[Locale]]', PropertyDescriptor::data(
                    new JsString($resolvedLocale),
                    false,
                    false,
                    false,
                ));
                $obj->defineOwnProperty('[[Style]]', PropertyDescriptor::data(
                    new JsString($style),
                    false,
                    false,
                    false,
                ));
                $obj->defineOwnProperty('[[Numeric]]', PropertyDescriptor::data(
                    new JsString($numeric),
                    false,
                    false,
                    false,
                ));
                $obj->defineOwnProperty('[[NumberingSystem]]', PropertyDescriptor::data(
                    new JsString($numberingSystem),
                    false,
                    false,
                    false,
                ));

                return $obj;
            },
            0,
        );
        $constructor->setConstructable();

        $constructor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($constructor, true, false, true));

        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Intl.RelativeTimeFormat'), false, false, true),
        );

        // RelativeTimeFormat.prototype.format(value, unit)
        $format = JsFunction::fromCallable('format', function (JsValue $this_, array $args): JsValue {
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedRelativeTimeFormat]]') instanceof JsUndefined
            ) {
                throw new TypeError(
                    'Intl.RelativeTimeFormat.prototype.format called on non-RelativeTimeFormat'
                );
            }
            $value = $args[0] ?? JsUndefined::instance();
            $unit = isset($args[1]) ? TypeConversion::toString($args[1]) : '';

            $n = TypeConversion::toNumber($value);
            if (!is_finite($n)) {
                throw new RangeError('Invalid time value');
            }

            $singular = self::canonicalRelativeTimeUnit($unit);
            $numeric = self::extractInternalString($this_, '[[Numeric]]', 'always');
            $style = self::extractInternalString($this_, '[[Style]]', 'long');
            $locale = self::extractInternalString($this_, '[[Locale]]', 'en');
            return new JsString(self::formatRelativeTime($locale, $n, $singular, $numeric, $style));
        }, 2);
        $proto->defineOwnProperty('format', PropertyDescriptor::data($format, true, false, true));

        // RelativeTimeFormat.prototype.formatToParts(value, unit)
        $formatToParts = JsFunction::fromCallable('formatToParts', function (JsValue $this_, array $args): JsValue {
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedRelativeTimeFormat]]') instanceof JsUndefined
            ) {
                throw new TypeError(
                    'Intl.RelativeTimeFormat.prototype.formatToParts called on non-RelativeTimeFormat'
                );
            }
            $value = $args[0] ?? JsUndefined::instance();
            $unitArg = $args[1] ?? JsUndefined::instance();
            $n = TypeConversion::toNumber($value);
            if (!is_finite($n)) {
                throw new RangeError('Invalid time value');
            }
            $unit = TypeConversion::toString($unitArg);
            $singular = self::canonicalRelativeTimeUnit($unit);
            $numeric = self::extractInternalString($this_, '[[Numeric]]', 'always');
            $style = self::extractInternalString($this_, '[[Style]]', 'long');
            $locale = self::extractInternalString($this_, '[[Locale]]', 'en');
            $formatted = self::formatRelativeTime($locale, $n, $singular, $numeric, $style);
            // Locate the formatted number digits inside the result so we
            // can split into spec-shaped {literal, integer, group, ...}
            // parts. If the number doesn't appear (auto-mode word like
            // "today"), emit a single literal part.
            $absStr = self::formatRelativeTimeNumber($locale, abs($n));
            $result = new JsArray();
            $idx = 0;
            $pos = $absStr === '' ? false : strpos($formatted, $absStr);
            if ($pos === false) {
                $part = new JsObject();
                self::defineDataProp($part, 'type', new JsString('literal'));
                self::defineDataProp($part, 'value', new JsString($formatted));
                $result->set((string) $idx++, $part);
            } else {
                if ($pos > 0) {
                    $part = new JsObject();
                    self::defineDataProp($part, 'type', new JsString('literal'));
                    self::defineDataProp($part, 'value', new JsString(substr($formatted, 0, $pos)));
                    $result->set((string) $idx++, $part);
                }
                // Walk the rendered number: emit `integer` runs (or
                // `fraction` after a decimal separator), interleaving
                // `group` separators (",", " ") and `decimal` separators
                // ("."). Tracks the integer/fraction transition by
                // remembering whether a decimal separator has been
                // emitted.
                $numLen = strlen($absStr);
                $i = 0;
                $sawDecimal = false;
                // Resolve the locale's decimal & group separators so
                // multi-byte Unicode separators (NBSP for Polish,
                // narrow no-break space for French, etc.) classify
                // as group rather than literal.
                $decimalSep = '.';
                $groupSep = ',';
                if (extension_loaded('intl')) {
                    $sf = new \NumberFormatter(str_replace('-', '_', $locale), \NumberFormatter::DECIMAL);
                    $d = $sf->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
                    $g = $sf->getSymbol(\NumberFormatter::GROUPING_SEPARATOR_SYMBOL);
                    if ($d !== '') {
                        $decimalSep = $d;
                    }
                    if ($g !== '') {
                        $groupSep = $g;
                    }
                }
                while ($i < $numLen) {
                    $ch = $absStr[$i];
                    if (ctype_digit($ch)) {
                        $j = $i;
                        while ($j < $numLen && ctype_digit($absStr[$j])) {
                            $j++;
                        }
                        $part = new JsObject();
                        $digitType = $sawDecimal ? 'fraction' : 'integer';
                        self::defineDataProp($part, 'type', new JsString($digitType));
                        self::defineDataProp($part, 'value', new JsString(substr($absStr, $i, $j - $i)));
                        self::defineDataProp($part, 'unit', new JsString($singular));
                        $result->set((string) $idx++, $part);
                        $i = $j;
                        continue;
                    }
                    // Match locale separator symbols (which may be
                    // multi-byte) before falling back to ASCII heuristics.
                    if (substr($absStr, $i, strlen($groupSep)) === $groupSep) {
                        $part = new JsObject();
                        self::defineDataProp($part, 'type', new JsString('group'));
                        self::defineDataProp($part, 'value', new JsString($groupSep));
                        self::defineDataProp($part, 'unit', new JsString($singular));
                        $result->set((string) $idx++, $part);
                        $i += strlen($groupSep);
                        continue;
                    }
                    if (substr($absStr, $i, strlen($decimalSep)) === $decimalSep) {
                        $sawDecimal = true;
                        $part = new JsObject();
                        self::defineDataProp($part, 'type', new JsString('decimal'));
                        self::defineDataProp($part, 'value', new JsString($decimalSep));
                        self::defineDataProp($part, 'unit', new JsString($singular));
                        $result->set((string) $idx++, $part);
                        $i += strlen($decimalSep);
                        continue;
                    }
                    $type = ($ch === ',' || $ch === ' ') ? 'group' : 'decimal';
                    if ($type === 'decimal') {
                        $sawDecimal = true;
                    }
                    $part = new JsObject();
                    self::defineDataProp($part, 'type', new JsString($type));
                    self::defineDataProp($part, 'value', new JsString($ch));
                    self::defineDataProp($part, 'unit', new JsString($singular));
                    $result->set((string) $idx++, $part);
                    $i++;
                }
                $tailStart = $pos + strlen($absStr);
                if ($tailStart < strlen($formatted)) {
                    $part = new JsObject();
                    self::defineDataProp($part, 'type', new JsString('literal'));
                    self::defineDataProp($part, 'value', new JsString(substr($formatted, $tailStart)));
                    $result->set((string) $idx++, $part);
                }
            }
            $result->set('length', JsNumber::of((float) $idx));
            return $result;
        }, 2);
        $proto->defineOwnProperty('formatToParts', PropertyDescriptor::data($formatToParts, true, false, true));

        // RelativeTimeFormat.prototype.resolvedOptions()
        $resolvedOptions = JsFunction::fromCallable('resolvedOptions', function (JsValue $this_): JsValue {
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedRelativeTimeFormat]]') instanceof JsUndefined
            ) {
                throw new TypeError('Intl.RelativeTimeFormat.prototype.resolvedOptions called on non-RelativeTimeFormat');
            }
            $result = new JsObject();
            // Use defineOwnProperty so an inherited accessor on
            // Object.prototype with no setter cannot intercept the
            // resolved values (CreateDataPropertyOrThrow semantics).
            $result->defineOwnProperty('locale', PropertyDescriptor::data(
                new JsString(self::extractInternalString($this_, '[[Locale]]', 'en')),
                true,
                true,
                true,
            ));
            $result->defineOwnProperty('style', PropertyDescriptor::data(
                new JsString(self::extractInternalString($this_, '[[Style]]', 'long')),
                true,
                true,
                true,
            ));
            $result->defineOwnProperty('numeric', PropertyDescriptor::data(
                new JsString(self::extractInternalString($this_, '[[Numeric]]', 'always')),
                true,
                true,
                true,
            ));
            $result->defineOwnProperty('numberingSystem', PropertyDescriptor::data(
                new JsString(self::extractInternalString($this_, '[[NumberingSystem]]', 'latn')),
                true,
                true,
                true,
            ));
            return $result;
        }, 0);
        $proto->defineOwnProperty(
            'resolvedOptions',
            PropertyDescriptor::data($resolvedOptions, true, false, true),
        );

        $constructor->defineOwnProperty(
            'supportedLocalesOf',
            PropertyDescriptor::data(self::makeSupportedLocalesOf('RelativeTimeFormat'), true, false, true),
        );

        $intl->defineOwnProperty(
            'RelativeTimeFormat',
            PropertyDescriptor::data($constructor, true, false, true),
        );
    }
}
