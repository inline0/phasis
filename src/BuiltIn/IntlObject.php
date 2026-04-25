<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Exceptions\RangeError;
use PhpJs\Exceptions\TypeError;
use PhpJs\Object\PropertyDescriptor;
use PhpJs\Runtime\Environment;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsArray;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

/**
 * Intl namespace object and all Intl constructors.
 *
 * Uses PHP's intl extension (ICU) when available for locale-sensitive operations.
 */
class IntlObject
{
    public static function install(Environment $env): void
    {
        $intl = new JsObject();

        // Intl[@@toStringTag] = "Intl"
        $intl->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Intl'), false, false, true),
        );

        // Intl.getCanonicalLocales(locales)
        $getCanonicalLocales = JsFunction::fromCallable(
            'getCanonicalLocales',
            self::getCanonicalLocalesFn(),
            1,
        );
        $intl->defineOwnProperty(
            'getCanonicalLocales',
            PropertyDescriptor::data($getCanonicalLocales, true, false, true),
        );

        // Intl.supportedValuesOf(key)
        $supportedValuesOf = JsFunction::fromCallable(
            'supportedValuesOf',
            self::supportedValuesOfFn(),
            1,
        );
        $intl->defineOwnProperty(
            'supportedValuesOf',
            PropertyDescriptor::data($supportedValuesOf, true, false, true),
        );

        // Install constructors on the Intl object.
        self::installCollator($intl);
        self::installNumberFormat($intl);
        self::installDateTimeFormat($intl);
        self::installPluralRules($intl);
        self::installLocale($intl);
        self::installDisplayNames($intl);
        self::installListFormat($intl);
        self::installRelativeTimeFormat($intl);
        self::installSegmenter($intl);

        $env->defineVar('Intl', $intl);
    }

    // ---------------------------------------------------------------
    // Locale resolution helpers
    // ---------------------------------------------------------------

    /**
     * Canonicalize a locale list from a JS argument.
     * Returns a PHP array of canonical locale strings.
     *
     * @return list<string>
     */
    private static function canonicalizeLocaleList(JsValue $locales): array
    {
        if ($locales instanceof JsUndefined) {
            return [];
        }

        $seen = [];

        if ($locales instanceof JsString) {
            $tag = $locales->value;
            $canon = self::canonicalizeLocaleTag($tag);
            if ($canon === null) {
                throw new RangeError("Invalid language tag: {$tag}");
            }
            return [$canon];
        }

        // Treat as array-like.
        if ($locales instanceof JsObject) {
            $lenVal = $locales->get('length');
            $len = $lenVal instanceof JsUndefined ? 0 : (int) TypeConversion::toNumber($lenVal);
            for ($k = 0; $k < $len; $k++) {
                $kPresent = $locales->has((string) $k);
                if ($kPresent) {
                    $kValue = $locales->get((string) $k);
                    if (!$kValue instanceof JsString && !$kValue instanceof JsObject) {
                        throw new TypeError('Language tag must be a string or object');
                    }
                    $tag = TypeConversion::toString($kValue);
                    $canon = self::canonicalizeLocaleTag($tag);
                    if ($canon === null) {
                        throw new RangeError("Invalid language tag: {$tag}");
                    }
                    if (!in_array($canon, $seen, true)) {
                        $seen[] = $canon;
                    }
                }
            }
        }

        return $seen;
    }

    /**
     * Canonicalize a single BCP 47 language tag using ICU.
     */
    private static function canonicalizeLocaleTag(string $tag): ?string
    {
        if ($tag === '') {
            return null;
        }

        // Validate basic structure of BCP 47 tag.
        // Must match: language[-script][-region][-variant]*[-extension]*[-privateuse]
        // Or: x-privateuse
        // Or: grandfathered tags
        if (!preg_match('/^[a-zA-Z0-9][-a-zA-Z0-9]*$/u', $tag)) {
            return null;
        }

        if (extension_loaded('intl')) {
            // Use ICU to canonicalize. \Locale::canonicalize handles
            // grandfathered tags, script/region casing, and alias replacement.
            $canon = \Locale::canonicalize($tag);
            if ($canon === null) {
                return null;
            }
            // ICU uses underscores; BCP 47 uses hyphens.
            $canon = str_replace('_', '-', $canon);

            // ICU may not validate all structural issues. Do a basic check:
            // the result should look like a valid tag.
            if (!preg_match('/^[a-zA-Z]{2,8}/', $canon)) {
                return null;
            }

            // Preserve unicode extension keywords from original tag.
            // ICU's canonicalize sometimes drops or reorders -u- extensions.
            // Re-parse from original if needed.
            return self::formatBcp47Casing($canon);
        }

        // Fallback: basic casing normalization without ICU.
        return self::formatBcp47Casing($tag);
    }

    /**
     * Apply BCP 47 casing rules:
     * - Language subtag: lowercase
     * - Script subtag: titlecase
     * - Region subtag: uppercase
     * - Everything else: lowercase
     */
    private static function formatBcp47Casing(string $tag): string
    {
        $parts = explode('-', $tag);
        $result = [];
        $i = 0;

        // Language subtag (always lowercase).
        $result[] = strtolower($parts[$i]);
        $i++;

        // Script subtag: 4 letters, titlecase.
        if (isset($parts[$i]) && strlen($parts[$i]) === 4 && ctype_alpha($parts[$i])) {
            $result[] = ucfirst(strtolower($parts[$i]));
            $i++;
        }

        // Region subtag: 2 letters uppercase, or 3 digits.
        if (
            isset($parts[$i]) && (
            (strlen($parts[$i]) === 2 && ctype_alpha($parts[$i])) ||
            (strlen($parts[$i]) === 3 && ctype_digit($parts[$i]))
            )
        ) {
            $result[] = strtoupper($parts[$i]);
            $i++;
        }

        // Remaining subtags: lowercase (variants, extensions, private use).
        for (; $i < count($parts); $i++) {
            $result[] = strtolower($parts[$i]);
        }

        return implode('-', $result);
    }

    /**
     * Resolve locale: pick best available from the requested list.
     * Returns the resolved locale string.
     */
    private static function resolveLocale(array $requestedLocales): string
    {
        if (extension_loaded('intl')) {
            $default = \Locale::getDefault();
            foreach ($requestedLocales as $locale) {
                // ICU uses underscores internally.
                $icuLocale = str_replace('-', '_', $locale);
                $lookup = \Locale::lookup([$icuLocale], $default);
                if ($lookup !== '' && $lookup !== null) {
                    return str_replace('_', '-', $icuLocale);
                }
            }
            return str_replace('_', '-', $default);
        }
        // Without ICU, return first requested or 'en'.
        return $requestedLocales[0] ?? 'en';
    }

    /**
     * Convert a JS locales argument (string or array) to a PHP array of locale strings.
     *
     * @return list<string>
     */
    private static function localesFromArg(JsValue $arg): array
    {
        if ($arg instanceof JsUndefined) {
            return [];
        }
        if ($arg instanceof JsString) {
            $canon = self::canonicalizeLocaleTag($arg->value);
            if ($canon === null) {
                throw new RangeError("Invalid language tag: {$arg->value}");
            }
            return [$canon];
        }
        if ($arg instanceof JsObject) {
            return self::canonicalizeLocaleList($arg);
        }
        throw new TypeError('Locales argument must be a string or an object');
    }

    /**
     * Convert a JS options argument to a JsObject (coerce undefined/null to empty object).
     * Per spec: CoerceOptionsToObject.
     */
    private static function coerceOptions(JsValue $arg): JsObject
    {
        if ($arg instanceof JsUndefined || $arg instanceof JsNull) {
            return new JsObject();
        }
        if ($arg instanceof JsObject) {
            return $arg;
        }
        throw new TypeError('Options must be an object');
    }

    /**
     * Validate the localeMatcher option. Per spec, must be "lookup" or "best fit".
     */
    private static function validateLocaleMatcher(JsObject $options): void
    {
        $lmVal = $options->get('localeMatcher');
        if (!$lmVal instanceof JsUndefined) {
            $lm = TypeConversion::toString($lmVal);
            if ($lm !== 'lookup' && $lm !== 'best fit') {
                throw new RangeError("Invalid localeMatcher: {$lm}");
            }
        }
    }

    // ---------------------------------------------------------------
    // Intl.getCanonicalLocales
    // ---------------------------------------------------------------

    private static function getCanonicalLocalesFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $locales = $args[0] ?? JsUndefined::instance();
            $canonicalized = self::canonicalizeLocaleList($locales);
            $result = new JsArray();
            foreach ($canonicalized as $i => $tag) {
                $result->set((string) $i, new JsString($tag));
            }
            $result->set('length', new JsNumber((float) count($canonicalized)));
            return $result;
        };
    }

    // ---------------------------------------------------------------
    // Intl.supportedValuesOf
    // ---------------------------------------------------------------

    private static function supportedValuesOfFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $key = isset($args[0]) ? TypeConversion::toString($args[0]) : '';
            $values = [];

            switch ($key) {
                case 'calendar':
                    $values = self::getSupportedCalendars();
                    break;
                case 'collation':
                    $values = self::getSupportedCollations();
                    break;
                case 'currency':
                    $values = self::getSupportedCurrencies();
                    break;
                case 'numberingSystem':
                    $values = self::getSupportedNumberingSystems();
                    break;
                case 'timeZone':
                    $values = self::getSupportedTimeZones();
                    break;
                case 'unit':
                    $values = self::getSupportedUnits();
                    break;
                default:
                    throw new RangeError("Invalid key: {$key}");
            }

            sort($values, SORT_STRING);
            $result = new JsArray();
            foreach ($values as $i => $v) {
                $result->set((string) $i, new JsString($v));
            }
            $result->set('length', new JsNumber((float) count($values)));
            return $result;
        };
    }

    /** @return list<string> */
    private static function getSupportedCalendars(): array
    {
        if (extension_loaded('intl')) {
            $iter = \IntlCalendar::getKeywordValuesForLocale('calendar', 'und', true);
            $result = [];
            foreach ($iter as $cal) {
                // Map ICU calendar names to BCP 47 / CLDR names.
                $mapped = match ($cal) {
                    'gregorian' => 'gregory',
                    'ethiopic-amete-alem' => 'ethioaa',
                    'islamic-civil' => 'islamic-civil',
                    default => $cal,
                };
                $result[] = $mapped;
            }
            return $result ?: ['buddhist', 'chinese', 'coptic', 'dangi', 'ethioaa',
                'ethiopic', 'gregory', 'hebrew', 'indian', 'islamic', 'islamic-civil',
                'islamic-rgsa', 'islamic-tbla', 'islamic-umalqura', 'iso8601',
                'japanese', 'persian', 'roc'];
        }
        return ['buddhist', 'chinese', 'coptic', 'dangi', 'ethioaa',
            'ethiopic', 'gregory', 'hebrew', 'indian', 'islamic', 'islamic-civil',
            'islamic-rgsa', 'islamic-tbla', 'islamic-umalqura', 'iso8601',
            'japanese', 'persian', 'roc'];
    }

    /** @return list<string> */
    private static function getSupportedCollations(): array
    {
        // Common collation types per CLDR/BCP 47.
        // Per spec, 'standard' and 'search' are excluded from supportedValuesOf.
        return ['big5han', 'compat', 'dict', 'direct', 'ducet', 'emoji', 'eor',
            'gb2312', 'phonebk', 'phonetic', 'pinyin', 'reformed',
            'searchjl', 'stroke', 'trad', 'unihan', 'zhuyin'];
    }

    /** @return list<string> */
    private static function getSupportedCurrencies(): array
    {
        // Return a representative set of ISO 4217 currency codes.
        // The full list has 300+ codes; we include the most commonly used.
        $codes = [];
        if (extension_loaded('intl')) {
            $bundle = \ResourceBundle::create('supplementalData', 'ICUDATA', true);
            // Extract from ICU if possible. Fall back to a static list.
            if ($bundle !== null) {
                $currencyData = $bundle->get('CurrencyMap');
                if ($currencyData !== null) {
                    foreach ($currencyData as $region => $data) {
                        if ($data !== null) {
                            foreach ($data as $entry) {
                                if ($entry !== null) {
                                    $id = $entry->get('id');
                                    if (is_string($id) && strlen($id) === 3 && $id !== 'XXX') {
                                        $codes[$id] = true;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        if (empty($codes)) {
            // Static fallback covering common currencies.
            return ['AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG',
                'AZN', 'BAM', 'BBD', 'BDT', 'BGN', 'BHD', 'BIF', 'BMD', 'BND',
                'BOB', 'BRL', 'BSD', 'BTN', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF',
                'CHF', 'CLP', 'CNY', 'COP', 'CRC', 'CUP', 'CVE', 'CZK', 'DJF',
                'DKK', 'DOP', 'DZD', 'EGP', 'ERN', 'ETB', 'EUR', 'FJD', 'FKP',
                'GBP', 'GEL', 'GHS', 'GIP', 'GMD', 'GNF', 'GTQ', 'GYD', 'HKD',
                'HNL', 'HRK', 'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'IQD', 'IRR',
                'ISK', 'JMD', 'JOD', 'JPY', 'KES', 'KGS', 'KHR', 'KMF', 'KPW',
                'KRW', 'KWD', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL',
                'LYD', 'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRU',
                'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO',
                'NOK', 'NPR', 'NZD', 'OMR', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR',
                'PLN', 'PYG', 'QAR', 'RON', 'RSD', 'RUB', 'RWF', 'SAR', 'SBD',
                'SCR', 'SDG', 'SEK', 'SGD', 'SHP', 'SLE', 'SOS', 'SRD', 'SSP',
                'STN', 'SVC', 'SYP', 'SZL', 'THB', 'TJS', 'TMT', 'TND', 'TOP',
                'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX', 'USD', 'UYU', 'UZS',
                'VES', 'VND', 'VUV', 'WST', 'XAF', 'XCD', 'XOF', 'XPF', 'YER',
                'ZAR', 'ZMW', 'ZWL'];
        }
        $keys = array_keys($codes);
        sort($keys, SORT_STRING);
        return $keys;
    }

    /** @return list<string> */
    private static function getSupportedNumberingSystems(): array
    {
        return ['adlm', 'ahom', 'arab', 'arabext', 'bali', 'beng', 'bhks', 'brah',
            'cakm', 'cham', 'deva', 'diak', 'fullwide', 'gong', 'gonm', 'gujr',
            'guru', 'hanidec', 'hmng', 'hmnp', 'java', 'kali', 'kawi', 'khmr',
            'knda', 'lana', 'lanatham', 'laoo', 'latn', 'lepc', 'limb', 'mathbold',
            'mathdbl', 'mathmono', 'mathsanb', 'mathsans', 'mlym', 'modi', 'mong',
            'mroo', 'mtei', 'mymr', 'mymrshan', 'mymrtlng', 'nagm', 'newa', 'nkoo',
            'olck', 'orya', 'osma', 'rohg', 'saur', 'segment', 'shrd', 'sind',
            'sinh', 'sora', 'sund', 'takr', 'talu', 'tamldec', 'telu', 'thai',
            'tibt', 'tirh', 'tnsa', 'vaii', 'wara', 'wcho'];
    }

    /** @return list<string> */
    private static function getSupportedTimeZones(): array
    {
        if (extension_loaded('intl')) {
            $iter = \IntlTimeZone::createEnumeration();
            $result = [];
            foreach ($iter as $tz) {
                // Filter to IANA time zone names (contain '/').
                if (str_contains($tz, '/') || $tz === 'UTC') {
                    $result[] = $tz;
                }
            }
            if (!empty($result)) {
                return $result;
            }
        }
        // Fallback: use PHP's timezone identifiers.
        return \DateTimeZone::listIdentifiers();
    }

    /** @return list<string> */
    private static function getSupportedUnits(): array
    {
        // ECMA-402 sanctioned simple unit identifiers.
        return ['acre', 'bit', 'byte', 'celsius', 'centimeter', 'day',
            'degree', 'fahrenheit', 'fluid-ounce', 'foot', 'gallon', 'gigabit',
            'gigabyte', 'gram', 'hectare', 'hour', 'inch', 'kilobit', 'kilobyte',
            'kilogram', 'kilometer', 'liter', 'megabit', 'megabyte', 'meter',
            'microsecond', 'mile', 'mile-scandinavian', 'milliliter', 'millimeter',
            'millisecond', 'minute', 'month', 'nanosecond', 'ounce', 'percent',
            'petabyte', 'pound', 'second', 'stone', 'terabit', 'terabyte',
            'week', 'yard', 'year'];
    }

    // ---------------------------------------------------------------
    // supportedLocalesOf helper (shared by all constructors)
    // ---------------------------------------------------------------

    /**
     * Create a supportedLocalesOf static method for a constructor.
     */
    private static function makeSupportedLocalesOf(string $name): JsFunction
    {
        $fn = JsFunction::fromCallable('supportedLocalesOf', function (
            JsValue $this_,
            array $args,
        ): JsValue {
            $locales = $args[0] ?? JsUndefined::instance();
            $optionsArg = $args[1] ?? JsUndefined::instance();
            $canonicalized = self::canonicalizeLocaleList($locales);

            // The spec runs SupportedLocales which validates the
            // localeMatcher option even though all candidate locales
            // ultimately fall back to PHP intl resolution.
            if (!$optionsArg instanceof JsUndefined && !$optionsArg instanceof JsNull) {
                $opts = self::coerceOptions($optionsArg);
                self::validateLocaleMatcher($opts);
            }

            // For our purposes (PHP intl backed), all well-formed locales are supported.
            $result = new JsArray();
            foreach ($canonicalized as $i => $tag) {
                $result->set((string) $i, new JsString($tag));
            }
            $result->set('length', new JsNumber((float) count($canonicalized)));
            return $result;
        }, 1);
        return $fn;
    }

    // ---------------------------------------------------------------
    // Intl.Collator
    // ---------------------------------------------------------------

    private static function installCollator(JsObject $intl): void
    {
        $proto = new JsObject();

        $constructor = JsFunction::fromCallable(
            'Collator',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                $localesArg = $args[0] ?? JsUndefined::instance();
                $optionsArg = $args[1] ?? JsUndefined::instance();

                $locales = self::localesFromArg($localesArg);
                $options = self::coerceOptions($optionsArg);
                self::validateLocaleMatcher($options);

                $obj = new JsObject($proto);
                $obj->defineOwnProperty('[[InitializedCollator]]', PropertyDescriptor::data(
                    new JsBoolean(true),
                    false,
                    false,
                    false,
                ));

                // Resolve locale.
                $resolvedLocale = self::resolveLocale($locales);
                $obj->defineOwnProperty('[[Locale]]', PropertyDescriptor::data(
                    new JsString($resolvedLocale),
                    false,
                    false,
                    false,
                ));

                // Usage: "sort" (default) or "search".
                $usage = 'sort';
                $usageVal = $options->get('usage');
                if (!$usageVal instanceof JsUndefined) {
                    $u = TypeConversion::toString($usageVal);
                    if ($u !== 'sort' && $u !== 'search') {
                        throw new RangeError("Invalid usage: {$u}");
                    }
                    $usage = $u;
                }
                $obj->defineOwnProperty('[[Usage]]', PropertyDescriptor::data(
                    new JsString($usage),
                    false,
                    false,
                    false,
                ));

                // Sensitivity: "base", "accent", "case", "variant" (default).
                $sensitivity = 'variant';
                $sensVal = $options->get('sensitivity');
                if (!$sensVal instanceof JsUndefined) {
                    $s = TypeConversion::toString($sensVal);
                    if (!in_array($s, ['base', 'accent', 'case', 'variant'], true)) {
                        throw new RangeError("Invalid sensitivity: {$s}");
                    }
                    $sensitivity = $s;
                }
                $obj->defineOwnProperty('[[Sensitivity]]', PropertyDescriptor::data(
                    new JsString($sensitivity),
                    false,
                    false,
                    false,
                ));

                // ignorePunctuation: boolean, default false.
                $ignorePunctuation = false;
                $ipVal = $options->get('ignorePunctuation');
                if (!$ipVal instanceof JsUndefined) {
                    $ignorePunctuation = TypeConversion::toBoolean($ipVal);
                }
                $obj->defineOwnProperty('[[IgnorePunctuation]]', PropertyDescriptor::data(
                    new JsBoolean($ignorePunctuation),
                    false,
                    false,
                    false,
                ));

                // numeric: boolean.
                $numeric = false;
                $numVal = $options->get('numeric');
                if (!$numVal instanceof JsUndefined) {
                    $numeric = TypeConversion::toBoolean($numVal);
                }
                $obj->defineOwnProperty('[[Numeric]]', PropertyDescriptor::data(
                    new JsBoolean($numeric),
                    false,
                    false,
                    false,
                ));

                // caseFirst: "upper", "lower", "false" (default).
                $caseFirst = 'false';
                $cfVal = $options->get('caseFirst');
                if (!$cfVal instanceof JsUndefined) {
                    $cf = TypeConversion::toString($cfVal);
                    if (!in_array($cf, ['upper', 'lower', 'false'], true)) {
                        throw new RangeError("Invalid caseFirst: {$cf}");
                    }
                    $caseFirst = $cf;
                }
                $obj->defineOwnProperty('[[CaseFirst]]', PropertyDescriptor::data(
                    new JsString($caseFirst),
                    false,
                    false,
                    false,
                ));

                // collation
                $collation = 'default';
                $collVal = $options->get('collation');
                if (!$collVal instanceof JsUndefined) {
                    $collation = TypeConversion::toString($collVal);
                }
                $obj->defineOwnProperty('[[Collation]]', PropertyDescriptor::data(
                    new JsString($collation),
                    false,
                    false,
                    false,
                ));

                return $obj;
            },
            0,
        );
        $constructor->setConstructable();

        // Collator.prototype
        $constructor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($constructor, true, false, true));

        // Collator.prototype[@@toStringTag] = "Intl.Collator"
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Intl.Collator'), false, false, true),
        );

        // Collator.prototype.compare: getter per spec.
        $compareFn = JsFunction::fromCallable('compare', function (
            JsValue $this_,
            array $args,
        ): JsValue {
            $x = isset($args[0]) ? TypeConversion::toString($args[0]) : '';
            $y = isset($args[1]) ? TypeConversion::toString($args[1]) : '';

            if ($this_ instanceof JsObject && extension_loaded('intl')) {
                $locale = self::extractInternalString($this_, '[[Locale]]', 'en');
                $collator = new \Collator(str_replace('-', '_', $locale));

                $sensitivity = self::extractInternalString($this_, '[[Sensitivity]]', 'variant');
                $strength = match ($sensitivity) {
                    'base' => \Collator::PRIMARY,
                    'accent' => \Collator::SECONDARY,
                    'case' => \Collator::PRIMARY,  // Case-sensitive at primary level.
                    default => \Collator::TERTIARY,
                };
                $collator->setStrength($strength);

                if ($sensitivity === 'case') {
                    $collator->setAttribute(\Collator::CASE_FIRST, \Collator::UPPER_FIRST);
                }

                $numericVal = $this_->get('[[Numeric]]');
                if ($numericVal instanceof JsBoolean && $numericVal->toBoolean()) {
                    $collator->setAttribute(\Collator::NUMERIC_COLLATION, \Collator::ON);
                }

                $result = $collator->compare($x, $y);
                return new JsNumber((float) ($result === false ? 0 : $result));
            }

            // Fallback: PHP strcmp.
            $cmp = strcmp($x, $y);
            return new JsNumber((float) ($cmp < 0 ? -1 : ($cmp > 0 ? 1 : 0)));
        }, 2);

        // Per spec, compare is a bound getter on the prototype.
        $compareGetter = JsFunction::fromCallable('get compare', function (
            JsValue $this_,
            array $args,
        ) use ($compareFn): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Intl.Collator.prototype.compare called on non-object');
            }
            // Return a bound compare function.
            $boundCompare = JsFunction::fromCallable('compare', function (
                JsValue $unused,
                array $innerArgs,
            ) use (
                $this_,
                $compareFn
): JsValue {
                return ($compareFn->getNativeCallable())($this_, $innerArgs);
            }, 2);
            return $boundCompare;
        }, 0);
        $proto->defineOwnProperty('compare', PropertyDescriptor::accessor(
            get: $compareGetter,
            set: null,
            enumerable: false,
            configurable: true,
        ));

        // Collator.prototype.resolvedOptions()
        $resolvedOptions = JsFunction::fromCallable('resolvedOptions', function (
            JsValue $this_,
        ): JsValue {
            $result = new JsObject();
            if ($this_ instanceof JsObject) {
                $locale = self::extractInternalString($this_, '[[Locale]]', 'en');
                $result->set('locale', new JsString($locale));
                $result->set('usage', new JsString(
                    self::extractInternalString($this_, '[[Usage]]', 'sort'),
                ));
                $result->set('sensitivity', new JsString(
                    self::extractInternalString($this_, '[[Sensitivity]]', 'variant'),
                ));
                $ipVal = $this_->get('[[IgnorePunctuation]]');
                $result->set('ignorePunctuation', new JsBoolean(
                    $ipVal instanceof JsBoolean ? $ipVal->toBoolean() : false,
                ));
                $result->set('collation', new JsString(
                    self::extractInternalString($this_, '[[Collation]]', 'default'),
                ));
                $numVal = $this_->get('[[Numeric]]');
                $result->set('numeric', new JsBoolean(
                    $numVal instanceof JsBoolean ? $numVal->toBoolean() : false,
                ));
                $result->set('caseFirst', new JsString(
                    self::extractInternalString($this_, '[[CaseFirst]]', 'false'),
                ));
            }
            return $result;
        }, 0);
        $proto->defineOwnProperty(
            'resolvedOptions',
            PropertyDescriptor::data($resolvedOptions, true, false, true),
        );

        // Collator.supportedLocalesOf
        $constructor->defineOwnProperty(
            'supportedLocalesOf',
            PropertyDescriptor::data(self::makeSupportedLocalesOf('Collator'), true, false, true),
        );

        $intl->defineOwnProperty(
            'Collator',
            PropertyDescriptor::data($constructor, true, false, true),
        );
    }

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

                $obj = new JsObject($proto);
                $obj->defineOwnProperty('[[InitializedNumberFormat]]', PropertyDescriptor::data(
                    new JsBoolean(true),
                    false,
                    false,
                    false,
                ));

                $resolvedLocale = self::resolveLocale($locales);
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

                // unit (required when style is "unit").
                $unit = null;
                $unitVal = $options->get('unit');
                if (!$unitVal instanceof JsUndefined) {
                    $unit = TypeConversion::toString($unitVal);
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

                // useGrouping
                $useGrouping = 'auto';
                $ugVal = $options->get('useGrouping');
                if (!$ugVal instanceof JsUndefined) {
                    if ($ugVal instanceof JsBoolean) {
                        $useGrouping = $ugVal->toBoolean() ? 'always' : 'false';
                    } else {
                        $ug = TypeConversion::toString($ugVal);
                        $useGrouping = $ug;
                    }
                }
                $obj->defineOwnProperty('[[UseGrouping]]', PropertyDescriptor::data(
                    new JsString($useGrouping),
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
                    new JsNumber((float) $minIntDigits),
                    false,
                    false,
                    false,
                ));

                // Fractional digit options.
                $mfdVal = $options->get('minimumFractionDigits');
                $xfdVal = $options->get('maximumFractionDigits');
                $msdVal = $options->get('minimumSignificantDigits');
                $xsdVal = $options->get('maximumSignificantDigits');

                if (!$msdVal instanceof JsUndefined || !$xsdVal instanceof JsUndefined) {
                    // Significant digits mode.
                    $minSig = !$msdVal instanceof JsUndefined ? (int) TypeConversion::toNumber($msdVal) : 1;
                    $maxSig = !$xsdVal instanceof JsUndefined ? (int) TypeConversion::toNumber($xsdVal) : 21;
                    $obj->defineOwnProperty('[[MinimumSignificantDigits]]', PropertyDescriptor::data(
                        new JsNumber((float) $minSig),
                        false,
                        false,
                        false,
                    ));
                    $obj->defineOwnProperty('[[MaximumSignificantDigits]]', PropertyDescriptor::data(
                        new JsNumber((float) $maxSig),
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
                    $defaultMinFrac = $style === 'currency' ? 2 : 0;
                    $defaultMaxFrac = $style === 'currency' ? 2 : ($style === 'percent' ? 0 : 3);
                    $minFrac = !$mfdVal instanceof JsUndefined
                        ? (int) TypeConversion::toNumber($mfdVal) : $defaultMinFrac;
                    $maxFrac = !$xfdVal instanceof JsUndefined
                        ? (int) TypeConversion::toNumber($xfdVal) : max($defaultMaxFrac, $minFrac);
                    $obj->defineOwnProperty('[[MinimumFractionDigits]]', PropertyDescriptor::data(
                        new JsNumber((float) $minFrac),
                        false,
                        false,
                        false,
                    ));
                    $obj->defineOwnProperty('[[MaximumFractionDigits]]', PropertyDescriptor::data(
                        new JsNumber((float) $maxFrac),
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

                // numberingSystem
                $numberingSystem = 'latn';
                $nsVal = $options->get('numberingSystem');
                if (!$nsVal instanceof JsUndefined) {
                    $numberingSystem = TypeConversion::toString($nsVal);
                }
                $obj->defineOwnProperty('[[NumberingSystem]]', PropertyDescriptor::data(
                    new JsString($numberingSystem),
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

                // roundingIncrement
                $roundingIncrement = 1;
                $riVal = $options->get('roundingIncrement');
                if (!$riVal instanceof JsUndefined) {
                    $ri = (int) TypeConversion::toNumber($riVal);
                    $validIncrements = [1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 2500, 5000];
                    if (!in_array($ri, $validIncrements, true)) {
                        throw new RangeError("Invalid roundingIncrement: {$ri}");
                    }
                    $roundingIncrement = $ri;
                }
                $obj->defineOwnProperty('[[RoundingIncrement]]', PropertyDescriptor::data(
                    new JsNumber((float) $roundingIncrement),
                    false,
                    false,
                    false,
                ));

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

                // roundingPriority
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
            $numVal = TypeConversion::toNumber($number);

            if ($this_ instanceof JsObject && extension_loaded('intl')) {
                return new JsString(self::formatNumber($this_, $numVal));
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
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Intl.NumberFormat.prototype.format called on non-object');
            }
            $boundFormat = JsFunction::fromCallable('format', function (
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
            $number = $args[0] ?? JsUndefined::instance();
            $numVal = TypeConversion::toNumber($number);
            // Return a basic parts array.
            $formatted = '0';
            if ($this_ instanceof JsObject && extension_loaded('intl')) {
                $formatted = self::formatNumber($this_, $numVal);
            } else {
                $formatted = is_nan($numVal) ? 'NaN' : (string) $numVal;
            }
            // Basic implementation: return [{type: "integer"/"literal", value: ...}].
            // A full implementation would decompose the formatted string using NumberFormatter attributes.
            $result = new JsArray();
            $part = new JsObject();
            if (is_nan($numVal)) {
                $part->set('type', new JsString('nan'));
            } elseif (!is_finite($numVal)) {
                $part->set('type', new JsString('infinity'));
            } else {
                $part->set('type', new JsString('integer'));
            }
            $part->set('value', new JsString($formatted));
            $result->set('0', $part);
            $result->set('length', new JsNumber(1.0));
            return $result;
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
            if (count($args) < 2) {
                throw new TypeError('formatRange requires two arguments');
            }
            $start = TypeConversion::toNumber($args[0]);
            $end = TypeConversion::toNumber($args[1]);
            if (is_nan($start) || is_nan($end)) {
                throw new RangeError('Invalid number for formatRange');
            }
            $startStr = ($this_ instanceof JsObject && extension_loaded('intl'))
                ? self::formatNumber($this_, $start) : (string) $start;
            $endStr = ($this_ instanceof JsObject && extension_loaded('intl'))
                ? self::formatNumber($this_, $end) : (string) $end;
            // Use an en-dash for ranges.
            return new JsString($startStr . "\u{2013}" . $endStr);
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
            if (count($args) < 2) {
                throw new TypeError('formatRangeToParts requires two arguments');
            }
            $start = TypeConversion::toNumber($args[0]);
            $end = TypeConversion::toNumber($args[1]);
            if (is_nan($start) || is_nan($end)) {
                throw new RangeError('Invalid number for formatRangeToParts');
            }
            $result = new JsArray();
            // Simplified implementation.
            $p1 = new JsObject();
            $p1->set('type', new JsString('integer'));
            $p1->set('value', new JsString((string) $start));
            $p1->set('source', new JsString('startRange'));
            $result->set('0', $p1);
            $result->set('length', new JsNumber(1.0));
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
            $result = new JsObject();
            if ($this_ instanceof JsObject) {
                $result->set('locale', new JsString(
                    self::extractInternalString($this_, '[[Locale]]', 'en'),
                ));
                $result->set('numberingSystem', new JsString(
                    self::extractInternalString($this_, '[[NumberingSystem]]', 'latn'),
                ));
                $style = self::extractInternalString($this_, '[[Style]]', 'decimal');
                $result->set('style', new JsString($style));
                if ($style === 'currency') {
                    $result->set('currency', new JsString(
                        self::extractInternalString($this_, '[[Currency]]', 'USD'),
                    ));
                    $result->set('currencyDisplay', new JsString(
                        self::extractInternalString($this_, '[[CurrencyDisplay]]', 'symbol'),
                    ));
                    $result->set('currencySign', new JsString(
                        self::extractInternalString($this_, '[[CurrencySign]]', 'standard'),
                    ));
                }
                if ($style === 'unit') {
                    $result->set('unit', new JsString(
                        self::extractInternalString($this_, '[[Unit]]', ''),
                    ));
                    $result->set('unitDisplay', new JsString(
                        self::extractInternalString($this_, '[[UnitDisplay]]', 'short'),
                    ));
                }
                $result->set('minimumIntegerDigits', new JsNumber(
                    self::extractInternalNumber($this_, '[[MinimumIntegerDigits]]', 1),
                ));
                $rt = self::extractInternalString($this_, '[[RoundingType]]', 'fractionDigits');
                if ($rt === 'significantDigits') {
                    $result->set('minimumSignificantDigits', new JsNumber(
                        self::extractInternalNumber($this_, '[[MinimumSignificantDigits]]', 1),
                    ));
                    $result->set('maximumSignificantDigits', new JsNumber(
                        self::extractInternalNumber($this_, '[[MaximumSignificantDigits]]', 21),
                    ));
                } else {
                    $result->set('minimumFractionDigits', new JsNumber(
                        self::extractInternalNumber($this_, '[[MinimumFractionDigits]]', 0),
                    ));
                    $result->set('maximumFractionDigits', new JsNumber(
                        self::extractInternalNumber($this_, '[[MaximumFractionDigits]]', 3),
                    ));
                }
                $ug = self::extractInternalString($this_, '[[UseGrouping]]', 'auto');
                // Per spec, useGrouping can be a boolean or string.
                if ($ug === 'false') {
                    $result->set('useGrouping', new JsBoolean(false));
                } else {
                    $result->set('useGrouping', new JsString($ug));
                }
                $result->set('notation', new JsString(
                    self::extractInternalString($this_, '[[Notation]]', 'standard'),
                ));
                $notation = self::extractInternalString($this_, '[[Notation]]', 'standard');
                if ($notation === 'compact') {
                    $result->set('compactDisplay', new JsString(
                        self::extractInternalString($this_, '[[CompactDisplay]]', 'short'),
                    ));
                }
                $result->set('signDisplay', new JsString(
                    self::extractInternalString($this_, '[[SignDisplay]]', 'auto'),
                ));
                $result->set('roundingMode', new JsString(
                    self::extractInternalString($this_, '[[RoundingMode]]', 'halfExpand'),
                ));
                $result->set('roundingIncrement', new JsNumber(
                    self::extractInternalNumber($this_, '[[RoundingIncrement]]', 1),
                ));
                $result->set('trailingZeroDisplay', new JsString(
                    self::extractInternalString($this_, '[[TrailingZeroDisplay]]', 'auto'),
                ));
                $result->set('roundingPriority', new JsString(
                    self::extractInternalString($this_, '[[RoundingPriority]]', 'auto'),
                ));
            }
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
    private static function formatNumber(JsObject $nf, float $number): string
    {
        $locale = self::extractInternalString($nf, '[[Locale]]', 'en');
        $style = self::extractInternalString($nf, '[[Style]]', 'decimal');

        $fmtStyle = match ($style) {
            'currency' => \NumberFormatter::CURRENCY,
            'percent' => \NumberFormatter::PERCENT,
            default => \NumberFormatter::DECIMAL,
        };

        $formatter = new \NumberFormatter(str_replace('-', '_', $locale), $fmtStyle);

        $minInt = (int) self::extractInternalNumber($nf, '[[MinimumIntegerDigits]]', 1);
        $formatter->setAttribute(\NumberFormatter::MIN_INTEGER_DIGITS, $minInt);

        $rt = self::extractInternalString($nf, '[[RoundingType]]', 'fractionDigits');
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

        if ($style === 'currency') {
            $currency = self::extractInternalString($nf, '[[Currency]]', 'USD');
            return $formatter->formatCurrency($number, $currency);
        }

        $result = $formatter->format($number);
        return $result === false ? (string) $number : $result;
    }

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

                $obj = new JsObject($proto);
                $obj->defineOwnProperty('[[InitializedDateTimeFormat]]', PropertyDescriptor::data(
                    new JsBoolean(true),
                    false,
                    false,
                    false,
                ));

                $resolvedLocale = self::resolveLocale($locales);
                $obj->defineOwnProperty('[[Locale]]', PropertyDescriptor::data(
                    new JsString($resolvedLocale),
                    false,
                    false,
                    false,
                ));

                // calendar
                $calendar = 'gregory';
                $calVal = $options->get('calendar');
                if (!$calVal instanceof JsUndefined) {
                    $calendar = TypeConversion::toString($calVal);
                }
                $obj->defineOwnProperty('[[Calendar]]', PropertyDescriptor::data(
                    new JsString($calendar),
                    false,
                    false,
                    false,
                ));

                // numberingSystem
                $numberingSystem = 'latn';
                $nsVal = $options->get('numberingSystem');
                if (!$nsVal instanceof JsUndefined) {
                    $numberingSystem = TypeConversion::toString($nsVal);
                }
                $obj->defineOwnProperty('[[NumberingSystem]]', PropertyDescriptor::data(
                    new JsString($numberingSystem),
                    false,
                    false,
                    false,
                ));

                // timeZone: must be a recognized identifier per the IANA
                // tz database (or a UTC offset). Use the host's timezone
                // list as the canonical authority — anything PHP doesn't
                // recognise is rejected per Intl spec.
                $timeZone = 'UTC';
                $tzVal = $options->get('timeZone');
                if (!$tzVal instanceof JsUndefined) {
                    $tz = TypeConversion::toString($tzVal);
                    $isOffset = preg_match('/^[+-]\d{2}:?\d{2}$/', $tz) === 1;
                    $isValidName = false;
                    if (!$isOffset) {
                        try {
                            new \DateTimeZone($tz);
                            $isValidName = true;
                        } catch (\Throwable) {
                            $isValidName = false;
                        }
                    }
                    if (!$isOffset && !$isValidName) {
                        throw new RangeError("Invalid timeZone: {$tz}");
                    }
                    $timeZone = $tz;
                }
                $obj->defineOwnProperty('[[TimeZone]]', PropertyDescriptor::data(
                    new JsString($timeZone),
                    false,
                    false,
                    false,
                ));

                // hourCycle / hour12
                $hourCycle = null;
                $hcVal = $options->get('hourCycle');
                if (!$hcVal instanceof JsUndefined) {
                    $hc = TypeConversion::toString($hcVal);
                    if (!in_array($hc, ['h11', 'h12', 'h23', 'h24'], true)) {
                        throw new RangeError("Invalid hourCycle: {$hc}");
                    }
                    $hourCycle = $hc;
                }
                $h12Val = $options->get('hour12');
                if (!$h12Val instanceof JsUndefined) {
                    $hourCycle = TypeConversion::toBoolean($h12Val) ? 'h12' : 'h23';
                }
                if ($hourCycle !== null) {
                    $obj->defineOwnProperty('[[HourCycle]]', PropertyDescriptor::data(
                        new JsString($hourCycle),
                        false,
                        false,
                        false,
                    ));
                }

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
                foreach ($components as $prop => $validValues) {
                    $val = $options->get($prop);
                    if (!$val instanceof JsUndefined) {
                        $str = TypeConversion::toString($val);
                        if ($validValues !== null && !in_array($str, $validValues, true)) {
                            throw new RangeError("Invalid {$prop}: {$str}");
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

            // Get timestamp from Date object or number.
            $timestamp = time();
            if ($dateArg instanceof JsObject && $dateArg->has('getTime')) {
                $getTime = $dateArg->get('getTime');
                if ($getTime instanceof JsFunction) {
                    $interp = \PhpJs\Engine::getCurrentInterpreter();
                    if ($interp !== null) {
                        $result = $interp->callFunction($getTime, $dateArg, []);
                        $timestamp = (int) ($result instanceof JsNumber ? $result->value / 1000 : time());
                    }
                }
            } elseif ($dateArg instanceof JsNumber) {
                $timestamp = (int) ($dateArg->value / 1000);
            } elseif (!$dateArg instanceof JsUndefined) {
                $timestamp = (int) (TypeConversion::toNumber($dateArg) / 1000);
            }

            if ($this_ instanceof JsObject && extension_loaded('intl')) {
                $formatted = self::formatDateTime($this_, $timestamp);
                return new JsString($formatted);
            }

            return new JsString(date('n/j/Y, g:i:s A', $timestamp));
        }, 1);
        $formatGetter = JsFunction::fromCallable('get format', function (
            JsValue $this_,
        ) use ($formatFn): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Intl.DateTimeFormat.prototype.format called on non-object');
            }
            $boundFormat = JsFunction::fromCallable('format', function (
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
            $result = new JsArray();
            $part = new JsObject();
            $part->set('type', new JsString('literal'));
            $part->set('value', new JsString(''));
            $result->set('0', $part);
            $result->set('length', new JsNumber(1.0));
            return $result;
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
            if (count($args) < 2) {
                throw new TypeError('formatRange requires two arguments');
            }
            return new JsString('');
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
            if (count($args) < 2) {
                throw new TypeError('formatRangeToParts requires two arguments');
            }
            $result = new JsArray();
            $result->set('length', new JsNumber(0.0));
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
            $result = new JsObject();
            if ($this_ instanceof JsObject) {
                $result->set('locale', new JsString(
                    self::extractInternalString($this_, '[[Locale]]', 'en'),
                ));
                $result->set('calendar', new JsString(
                    self::extractInternalString($this_, '[[Calendar]]', 'gregory'),
                ));
                $result->set('numberingSystem', new JsString(
                    self::extractInternalString($this_, '[[NumberingSystem]]', 'latn'),
                ));
                $result->set('timeZone', new JsString(
                    self::extractInternalString($this_, '[[TimeZone]]', 'UTC'),
                ));
                $hcVal = $this_->get('[[HourCycle]]');
                if (!$hcVal instanceof JsUndefined) {
                    $result->set('hourCycle', new JsString(TypeConversion::toString($hcVal)));
                }

                // Component options.
                foreach (
                    ['weekday', 'era', 'year', 'month', 'day', 'dayPeriod',
                    'hour', 'minute', 'second', 'fractionalSecondDigits', 'timeZoneName'] as $comp
                ) {
                    $val = $this_->get("[[{$comp}]]");
                    if (!$val instanceof JsUndefined) {
                        $result->set($comp, new JsString(TypeConversion::toString($val)));
                    }
                }

                $dsVal = $this_->get('[[DateStyle]]');
                if (!$dsVal instanceof JsUndefined) {
                    $result->set('dateStyle', new JsString(TypeConversion::toString($dsVal)));
                }
                $tsVal = $this_->get('[[TimeStyle]]');
                if (!$tsVal instanceof JsUndefined) {
                    $result->set('timeStyle', new JsString(TypeConversion::toString($tsVal)));
                }
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
     * Format a date/time using PHP's IntlDateFormatter.
     */
    private static function formatDateTime(JsObject $dtf, int $timestamp): string
    {
        $locale = str_replace('-', '_', self::extractInternalString($dtf, '[[Locale]]', 'en'));
        $tz = self::extractInternalString($dtf, '[[TimeZone]]', 'UTC');

        $dateStyle = null;
        $dsVal = $dtf->get('[[DateStyle]]');
        if (!$dsVal instanceof JsUndefined) {
            $dateStyle = TypeConversion::toString($dsVal);
        }
        $timeStyle = null;
        $tsVal = $dtf->get('[[TimeStyle]]');
        if (!$tsVal instanceof JsUndefined) {
            $timeStyle = TypeConversion::toString($tsVal);
        }

        $mapStyle = function (?string $s): int {
            return match ($s) {
                'full' => \IntlDateFormatter::FULL,
                'long' => \IntlDateFormatter::LONG,
                'medium' => \IntlDateFormatter::MEDIUM,
                'short' => \IntlDateFormatter::SHORT,
                default => \IntlDateFormatter::NONE,
            };
        };

        $dateFmt = $mapStyle($dateStyle);
        $timeFmt = $mapStyle($timeStyle);

        // If neither dateStyle nor timeStyle, use a default medium format.
        if ($dateStyle === null && $timeStyle === null) {
            $dateFmt = \IntlDateFormatter::MEDIUM;
            $timeFmt = \IntlDateFormatter::MEDIUM;
        }

        $formatter = new \IntlDateFormatter($locale, $dateFmt, $timeFmt, $tz);
        $result = $formatter->format($timestamp);
        return $result === false ? date('Y-m-d H:i:s', $timestamp) : $result;
    }

    // ---------------------------------------------------------------
    // Intl.PluralRules
    // ---------------------------------------------------------------

    private static function installPluralRules(JsObject $intl): void
    {
        $proto = new JsObject();

        $constructor = JsFunction::fromCallable(
            'PluralRules',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || $this_->get('[[NewTarget]]') instanceof JsUndefined) {
                    throw new TypeError('Constructor Intl.PluralRules requires \'new\'');
                }

                $localesArg = $args[0] ?? JsUndefined::instance();
                $optionsArg = $args[1] ?? JsUndefined::instance();

                $locales = self::localesFromArg($localesArg);
                $options = self::coerceOptions($optionsArg);
                self::validateLocaleMatcher($options);

                $obj = new JsObject($proto);
                $obj->defineOwnProperty('[[InitializedPluralRules]]', PropertyDescriptor::data(
                    new JsBoolean(true),
                    false,
                    false,
                    false,
                ));

                $resolvedLocale = self::resolveLocale($locales);
                $obj->defineOwnProperty('[[Locale]]', PropertyDescriptor::data(
                    new JsString($resolvedLocale),
                    false,
                    false,
                    false,
                ));

                // type: "cardinal" (default) or "ordinal".
                $type = 'cardinal';
                $typeVal = $options->get('type');
                if (!$typeVal instanceof JsUndefined) {
                    $t = TypeConversion::toString($typeVal);
                    if (!in_array($t, ['cardinal', 'ordinal'], true)) {
                        throw new RangeError("Invalid type: {$t}");
                    }
                    $type = $t;
                }
                $obj->defineOwnProperty('[[Type]]', PropertyDescriptor::data(
                    new JsString($type),
                    false,
                    false,
                    false,
                ));

                // Digit options.
                $minInt = 1;
                $midVal = $options->get('minimumIntegerDigits');
                if (!$midVal instanceof JsUndefined) {
                    $minInt = (int) TypeConversion::toNumber($midVal);
                }
                $obj->defineOwnProperty('[[MinimumIntegerDigits]]', PropertyDescriptor::data(
                    new JsNumber((float) $minInt),
                    false,
                    false,
                    false,
                ));

                $mfdVal = $options->get('minimumFractionDigits');
                $xfdVal = $options->get('maximumFractionDigits');
                $msdVal = $options->get('minimumSignificantDigits');
                $xsdVal = $options->get('maximumSignificantDigits');

                if (!$msdVal instanceof JsUndefined || !$xsdVal instanceof JsUndefined) {
                    $minSig = !$msdVal instanceof JsUndefined ? (int) TypeConversion::toNumber($msdVal) : 1;
                    $maxSig = !$xsdVal instanceof JsUndefined ? (int) TypeConversion::toNumber($xsdVal) : 21;
                    $obj->defineOwnProperty('[[MinimumSignificantDigits]]', PropertyDescriptor::data(
                        new JsNumber((float) $minSig),
                        false,
                        false,
                        false,
                    ));
                    $obj->defineOwnProperty('[[MaximumSignificantDigits]]', PropertyDescriptor::data(
                        new JsNumber((float) $maxSig),
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
                    $minFrac = !$mfdVal instanceof JsUndefined
                        ? (int) TypeConversion::toNumber($mfdVal) : 0;
                    $maxFrac = !$xfdVal instanceof JsUndefined
                        ? (int) TypeConversion::toNumber($xfdVal) : max(3, $minFrac);
                    $obj->defineOwnProperty('[[MinimumFractionDigits]]', PropertyDescriptor::data(
                        new JsNumber((float) $minFrac),
                        false,
                        false,
                        false,
                    ));
                    $obj->defineOwnProperty('[[MaximumFractionDigits]]', PropertyDescriptor::data(
                        new JsNumber((float) $maxFrac),
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

                // roundingMode
                $roundingMode = 'halfExpand';
                $rmVal = $options->get('roundingMode');
                if (!$rmVal instanceof JsUndefined) {
                    $roundingMode = TypeConversion::toString($rmVal);
                }
                $obj->defineOwnProperty('[[RoundingMode]]', PropertyDescriptor::data(
                    new JsString($roundingMode),
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
            PropertyDescriptor::data(new JsString('Intl.PluralRules'), false, false, true),
        );

        // PluralRules.prototype.select(number)
        $select = JsFunction::fromCallable('select', function (
            JsValue $this_,
            array $args,
        ): JsValue {
            $number = $args[0] ?? JsUndefined::instance();
            $n = TypeConversion::toNumber($number);

            if (!is_finite($n)) {
                return new JsString('other');
            }

            $locale = 'en';
            $type = 'cardinal';
            if ($this_ instanceof JsObject) {
                $locale = self::extractInternalString($this_, '[[Locale]]', 'en');
                $type = self::extractInternalString($this_, '[[Type]]', 'cardinal');
            }

            if (extension_loaded('intl')) {
                $icuType = $type === 'ordinal'
                    ? \NumberFormatter::ORDINAL
                    : \NumberFormatter::DECIMAL;

                // Use MessageFormatter CLDR plural rules via a trick.
                // Actually, PHP doesn't have a direct PluralRules API.
                // We can approximate using CLDR rules for the locale.
                // Common English rules as fallback.
                return new JsString(self::selectPlural($locale, $n, $type));
            }

            // Basic English plural rules.
            return new JsString(self::selectPlural('en', $n, 'cardinal'));
        }, 1);
        $proto->defineOwnProperty('select', PropertyDescriptor::data($select, true, false, true));

        // PluralRules.prototype.selectRange(start, end)
        $selectRange = JsFunction::fromCallable('selectRange', function (
            JsValue $this_,
            array $args,
        ): JsValue {
            if (count($args) < 2) {
                throw new TypeError('selectRange requires two arguments');
            }
            $start = TypeConversion::toNumber($args[0]);
            $end = TypeConversion::toNumber($args[1]);
            if (is_nan($start) || is_nan($end)) {
                throw new RangeError('Invalid number for selectRange');
            }
            // Per CLDR range rules, the result is typically "other" for most locales.
            return new JsString('other');
        }, 2);
        $proto->defineOwnProperty('selectRange', PropertyDescriptor::data($selectRange, true, false, true));

        // PluralRules.prototype.resolvedOptions()
        $resolvedOptions = JsFunction::fromCallable('resolvedOptions', function (
            JsValue $this_,
        ): JsValue {
            $result = new JsObject();
            if ($this_ instanceof JsObject) {
                $result->set('locale', new JsString(
                    self::extractInternalString($this_, '[[Locale]]', 'en'),
                ));
                $result->set('type', new JsString(
                    self::extractInternalString($this_, '[[Type]]', 'cardinal'),
                ));
                $result->set('minimumIntegerDigits', new JsNumber(
                    self::extractInternalNumber($this_, '[[MinimumIntegerDigits]]', 1),
                ));
                $rt = self::extractInternalString($this_, '[[RoundingType]]', 'fractionDigits');
                if ($rt === 'significantDigits') {
                    $result->set('minimumSignificantDigits', new JsNumber(
                        self::extractInternalNumber($this_, '[[MinimumSignificantDigits]]', 1),
                    ));
                    $result->set('maximumSignificantDigits', new JsNumber(
                        self::extractInternalNumber($this_, '[[MaximumSignificantDigits]]', 21),
                    ));
                } else {
                    $result->set('minimumFractionDigits', new JsNumber(
                        self::extractInternalNumber($this_, '[[MinimumFractionDigits]]', 0),
                    ));
                    $result->set('maximumFractionDigits', new JsNumber(
                        self::extractInternalNumber($this_, '[[MaximumFractionDigits]]', 3),
                    ));
                }
                // Plural categories: per spec, return the list of plural categories for the locale.
                $categories = new JsArray();
                $cats = ['few', 'many', 'one', 'other', 'two', 'zero'];
                foreach ($cats as $i => $cat) {
                    $categories->set((string) $i, new JsString($cat));
                }
                $categories->set('length', new JsNumber((float) count($cats)));
                $result->set('pluralCategories', $categories);
                $result->set('roundingMode', new JsString(
                    self::extractInternalString($this_, '[[RoundingMode]]', 'halfExpand'),
                ));
            }
            return $result;
        }, 0);
        $proto->defineOwnProperty(
            'resolvedOptions',
            PropertyDescriptor::data($resolvedOptions, true, false, true),
        );

        // PluralRules.supportedLocalesOf
        $constructor->defineOwnProperty(
            'supportedLocalesOf',
            PropertyDescriptor::data(self::makeSupportedLocalesOf('PluralRules'), true, false, true),
        );

        $intl->defineOwnProperty(
            'PluralRules',
            PropertyDescriptor::data($constructor, true, false, true),
        );
    }

    /**
     * Select the CLDR plural category for a number in a given locale.
     */
    private static function selectPlural(string $locale, float $n, string $type): string
    {
        $abs = abs($n);
        $intPart = (int) $abs;
        $lang = explode('-', $locale)[0];

        if ($type === 'ordinal') {
            // English ordinal rules.
            if ($lang === 'en') {
                $mod10 = $intPart % 10;
                $mod100 = $intPart % 100;
                if ($mod10 === 1 && $mod100 !== 11) {
                    return 'one';
                }
                if ($mod10 === 2 && $mod100 !== 12) {
                    return 'two';
                }
                if ($mod10 === 3 && $mod100 !== 13) {
                    return 'few';
                }
                return 'other';
            }
            return 'other';
        }

        // Cardinal rules for common languages.
        // See CLDR plural rules: https://www.unicode.org/cldr/charts/latest/supplemental/language_plural_rules.html
        $isInteger = floor($abs) === $abs;

        switch ($lang) {
            case 'en':
            case 'de':
            case 'nl':
            case 'sv':
            case 'da':
            case 'no':
            case 'nb':
            case 'nn':
            case 'it':
            case 'es':
            case 'pt':
                // one: i = 1 and v = 0
                if ($isInteger && $intPart === 1) {
                    return 'one';
                }
                return 'other';

            case 'fr':
                // one: i = 0,1
                if ($isInteger && ($intPart === 0 || $intPart === 1)) {
                    return 'one';
                }
                return 'other';

            case 'ar':
                // Arabic has complex rules.
                if ($n === 0.0) {
                    return 'zero';
                }
                if ($abs === 1.0) {
                    return 'one';
                }
                if ($abs === 2.0) {
                    return 'two';
                }
                $mod100 = $intPart % 100;
                if ($mod100 >= 3 && $mod100 <= 10) {
                    return 'few';
                }
                if ($mod100 >= 11) {
                    return 'many';
                }
                return 'other';

            case 'ja':
            case 'zh':
            case 'ko':
            case 'vi':
            case 'th':
            case 'id':
            case 'ms':
            case 'tr':
                return 'other';

            case 'ru':
            case 'uk':
            case 'pl':
            case 'hr':
            case 'sr':
            case 'bs':
                $mod10 = $intPart % 10;
                $mod100 = $intPart % 100;
                if ($isInteger) {
                    if ($mod10 === 1 && $mod100 !== 11) {
                        return 'one';
                    }
                    if ($mod10 >= 2 && $mod10 <= 4 && !($mod100 >= 12 && $mod100 <= 14)) {
                        return 'few';
                    }
                    if ($mod10 === 0 || $mod10 >= 5 || ($mod100 >= 11 && $mod100 <= 14)) {
                        return 'many';
                    }
                }
                return 'other';

            default:
                if ($isInteger && $intPart === 1) {
                    return 'one';
                }
                return 'other';
        }
    }

    // ---------------------------------------------------------------
    // Intl.Locale
    // ---------------------------------------------------------------

    private static function installLocale(JsObject $intl): void
    {
        $proto = new JsObject();

        $constructor = JsFunction::fromCallable(
            'Locale',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || $this_->get('[[NewTarget]]') instanceof JsUndefined) {
                    throw new TypeError('Constructor Intl.Locale requires \'new\'');
                }

                $tagArg = $args[0] ?? JsUndefined::instance();
                $optionsArg = $args[1] ?? JsUndefined::instance();

                if ($tagArg instanceof JsUndefined) {
                    throw new TypeError('First argument to Intl.Locale must be a string or Locale object');
                }
                // Per spec step 11.a, ToObject(null) throws TypeError when an
                // explicit null options argument is supplied.
                if ($optionsArg instanceof JsNull) {
                    throw new TypeError('Cannot convert null to Locale options object');
                }

                // If tagArg is already a Locale-like object, extract its string.
                $tag = TypeConversion::toString($tagArg);
                if ($tag === '') {
                    throw new RangeError('Invalid language tag: ');
                }

                $options = self::coerceOptions($optionsArg);

                // Apply options overrides. Each subtag must match its
                // BCP47 production exactly; ICU's parser is permissive
                // so we validate up-front.
                $language = null;
                $langVal = $options->get('language');
                if (!$langVal instanceof JsUndefined) {
                    $language = TypeConversion::toString($langVal);
                    $langLen = strlen($language);
                    if (
                        !ctype_alpha($language)
                        || !($langLen === 2 || $langLen === 3 || ($langLen >= 5 && $langLen <= 8))
                    ) {
                        throw new RangeError("Invalid language: {$language}");
                    }
                }
                $script = null;
                $scriptVal = $options->get('script');
                if (!$scriptVal instanceof JsUndefined) {
                    $script = TypeConversion::toString($scriptVal);
                    if (strlen($script) !== 4 || !ctype_alpha($script)) {
                        throw new RangeError("Invalid script: {$script}");
                    }
                }
                $region = null;
                $regionVal = $options->get('region');
                if (!$regionVal instanceof JsUndefined) {
                    $region = TypeConversion::toString($regionVal);
                    $regionLen = strlen($region);
                    $isAlpha2 = ($regionLen === 2 && ctype_alpha($region));
                    $isDigit3 = ($regionLen === 3 && ctype_digit($region));
                    if (!$isAlpha2 && !$isDigit3) {
                        throw new RangeError("Invalid region: {$region}");
                    }
                }

                // Parse the tag.
                $parsed = self::parseLocaleTag($tag);
                if ($parsed === null) {
                    throw new RangeError("Invalid language tag: {$tag}");
                }

                // Override with options.
                if ($language !== null) {
                    $parsed['language'] = strtolower($language);
                }
                if ($script !== null) {
                    $parsed['script'] = ucfirst(strtolower($script));
                }
                if ($region !== null) {
                    $parsed['region'] = strtoupper($region);
                }

                // Unicode extension keywords from options. Each value
                // must satisfy the BCP47 type production:
                // alphanum{3,8}(-alphanum{3,8})*  for calendar /
                // collation / numberingSystem; the others are fixed
                // enumerations.
                $isValidUnicodeType = static function (string $value): bool {
                    if ($value === '') {
                        return false;
                    }
                    foreach (explode('-', $value) as $part) {
                        $partLen = strlen($part);
                        if ($partLen < 3 || $partLen > 8 || !ctype_alnum($part)) {
                            return false;
                        }
                    }
                    return true;
                };
                $calendar = null;
                $calVal = $options->get('calendar');
                if (!$calVal instanceof JsUndefined) {
                    $calendar = TypeConversion::toString($calVal);
                    if (!$isValidUnicodeType($calendar)) {
                        throw new RangeError("Invalid calendar: {$calendar}");
                    }
                    $parsed['calendar'] = $calendar;
                }
                $collation = null;
                $collVal = $options->get('collation');
                if (!$collVal instanceof JsUndefined) {
                    $collation = TypeConversion::toString($collVal);
                    if (!$isValidUnicodeType($collation)) {
                        throw new RangeError("Invalid collation: {$collation}");
                    }
                    $parsed['collation'] = $collation;
                }
                $hourCycle = null;
                $hcVal = $options->get('hourCycle');
                if (!$hcVal instanceof JsUndefined) {
                    $hourCycle = TypeConversion::toString($hcVal);
                    if (!in_array($hourCycle, ['h11', 'h12', 'h23', 'h24'], true)) {
                        throw new RangeError("Invalid hourCycle: {$hourCycle}");
                    }
                    $parsed['hourCycle'] = $hourCycle;
                }
                $caseFirst = null;
                $cfVal = $options->get('caseFirst');
                if (!$cfVal instanceof JsUndefined) {
                    $caseFirst = TypeConversion::toString($cfVal);
                    if (!in_array($caseFirst, ['upper', 'lower', 'false'], true)) {
                        throw new RangeError("Invalid caseFirst: {$caseFirst}");
                    }
                    $parsed['caseFirst'] = $caseFirst;
                }
                $numeric = null;
                $numVal = $options->get('numeric');
                if (!$numVal instanceof JsUndefined) {
                    $numeric = TypeConversion::toBoolean($numVal);
                    $parsed['numeric'] = $numeric;
                }
                $numberingSystem = null;
                $nsVal = $options->get('numberingSystem');
                if (!$nsVal instanceof JsUndefined) {
                    $numberingSystem = TypeConversion::toString($nsVal);
                    if (!$isValidUnicodeType($numberingSystem)) {
                        throw new RangeError("Invalid numberingSystem: {$numberingSystem}");
                    }
                    $parsed['numberingSystem'] = $numberingSystem;
                }

                $obj = new JsObject($proto);

                // Store parsed components as internal slots.
                foreach ($parsed as $key => $val) {
                    if ($val !== null) {
                        $jsVal = is_bool($val) ? new JsBoolean($val) : new JsString((string) $val);
                        $obj->defineOwnProperty("[[{$key}]]", PropertyDescriptor::data(
                            $jsVal,
                            false,
                            false,
                            false,
                        ));
                    }
                }

                // Store the full canonical tag.
                $canonTag = self::reconstructLocaleTag($parsed);
                $obj->defineOwnProperty('[[LocaleTag]]', PropertyDescriptor::data(
                    new JsString($canonTag),
                    false,
                    false,
                    false,
                ));

                return $obj;
            },
            1,
        );
        $constructor->setConstructable();

        $constructor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($constructor, true, false, true));

        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Intl.Locale'), false, false, true),
        );

        // Locale.prototype.toString()
        $toString = JsFunction::fromCallable('toString', function (JsValue $this_): JsValue {
            if ($this_ instanceof JsObject) {
                $tag = $this_->get('[[LocaleTag]]');
                if (!$tag instanceof JsUndefined) {
                    return $tag;
                }
            }
            throw new TypeError('Intl.Locale.prototype.toString called on non-Locale');
        }, 0);
        $proto->defineOwnProperty('toString', PropertyDescriptor::data($toString, true, false, true));

        // Accessor properties: language, script, region, baseName, calendar, etc.
        $accessors = [
            'language' => 'language',
            'script' => 'script',
            'region' => 'region',
            'calendar' => 'calendar',
            'caseFirst' => 'caseFirst',
            'collation' => 'collation',
            'hourCycle' => 'hourCycle',
            'numberingSystem' => 'numberingSystem',
        ];
        foreach ($accessors as $prop => $internalKey) {
            $getter = JsFunction::fromCallable("get {$prop}", function (JsValue $this_) use ($internalKey): JsValue {
                if (!$this_ instanceof JsObject) {
                    throw new TypeError("Intl.Locale.prototype.{$internalKey} called on non-Locale");
                }
                $val = $this_->get("[[{$internalKey}]]");
                return $val instanceof JsUndefined ? JsUndefined::instance() : $val;
            }, 0);
            $proto->defineOwnProperty($prop, PropertyDescriptor::accessor(
                get: $getter,
                set: null,
                enumerable: false,
                configurable: true,
            ));
        }

        // numeric: boolean accessor
        $numericGetter = JsFunction::fromCallable('get numeric', function (JsValue $this_): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Intl.Locale.prototype.numeric called on non-Locale');
            }
            $val = $this_->get('[[numeric]]');
            if ($val instanceof JsBoolean) {
                return $val;
            }
            return new JsBoolean(false);
        }, 0);
        $proto->defineOwnProperty('numeric', PropertyDescriptor::accessor(
            get: $numericGetter,
            set: null,
            enumerable: false,
            configurable: true,
        ));

        // baseName: accessor
        $baseNameGetter = JsFunction::fromCallable('get baseName', function (JsValue $this_): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Intl.Locale.prototype.baseName called on non-Locale');
            }
            $lang = self::extractInternalString($this_, '[[language]]', '');
            $script = self::extractInternalStringOrNull($this_, '[[script]]');
            $region = self::extractInternalStringOrNull($this_, '[[region]]');

            $parts = [$lang];
            if ($script !== null) {
                $parts[] = $script;
            }
            if ($region !== null) {
                $parts[] = $region;
            }
            return new JsString(implode('-', $parts));
        }, 0);
        $proto->defineOwnProperty('baseName', PropertyDescriptor::accessor(
            get: $baseNameGetter,
            set: null,
            enumerable: false,
            configurable: true,
        ));

        // maximize() and minimize()
        $maximize = JsFunction::fromCallable('maximize', function (JsValue $this_) use ($constructor): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Intl.Locale.prototype.maximize called on non-Locale');
            }
            $tag = self::extractInternalString($this_, '[[LocaleTag]]', 'en');
            if (extension_loaded('intl')) {
                $maximized = \Locale::composeLocale(\Locale::parseLocale(str_replace('-', '_', $tag)));
                // Try to add likely subtags.
                $maximized = self::addLikelySubtags($tag);
            } else {
                $maximized = $tag;
            }
            // Construct a new Locale object by calling the constructor directly.
            $newObj = new JsObject($this_->getPrototype());
            $newObj->set('[[NewTarget]]', $constructor);
            $result = ($constructor->getNativeCallable())($newObj, [new JsString($maximized)]);
            return $result;
        }, 0);
        $proto->defineOwnProperty('maximize', PropertyDescriptor::data($maximize, true, false, true));

        $minimize = JsFunction::fromCallable('minimize', function (JsValue $this_) use ($constructor): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Intl.Locale.prototype.minimize called on non-Locale');
            }
            $tag = self::extractInternalString($this_, '[[LocaleTag]]', 'en');
            if (extension_loaded('intl')) {
                $minimized = self::removeLikelySubtags($tag);
            } else {
                $minimized = $tag;
            }
            // Construct a new Locale object by calling the constructor directly.
            $newObj = new JsObject($this_->getPrototype());
            $newObj->set('[[NewTarget]]', $constructor);
            $result = ($constructor->getNativeCallable())($newObj, [new JsString($minimized)]);
            return $result;
        }, 0);
        $proto->defineOwnProperty('minimize', PropertyDescriptor::data($minimize, true, false, true));

        // getCalendars(), getCollations(), getHourCycles(), getNumberingSystems(), getTimeZones()
        $infoMethods = [
            'getCalendars' => function () {
                return self::getSupportedCalendars();
            },
            'getCollations' => function () {
                return self::getSupportedCollations();
            },
            'getHourCycles' => function () {
                return ['h11', 'h12', 'h23', 'h24'];
            },
            'getNumberingSystems' => function () {
                return ['latn'];
            },
        ];
        foreach ($infoMethods as $name => $getter) {
            $fn = JsFunction::fromCallable($name, function (JsValue $this_) use ($getter): JsValue {
                $values = $getter();
                $result = new JsArray();
                foreach ($values as $i => $v) {
                    $result->set((string) $i, new JsString($v));
                }
                $result->set('length', new JsNumber((float) count($values)));
                return $result;
            }, 0);
            $proto->defineOwnProperty($name, PropertyDescriptor::data($fn, true, false, true));
        }

        // getTextInfo()
        $getTextInfo = JsFunction::fromCallable('getTextInfo', function (JsValue $this_): JsValue {
            $result = new JsObject();
            $result->set('direction', new JsString('ltr'));
            return $result;
        }, 0);
        $proto->defineOwnProperty('getTextInfo', PropertyDescriptor::data($getTextInfo, true, false, true));

        // getWeekInfo()
        $getWeekInfo = JsFunction::fromCallable('getWeekInfo', function (JsValue $this_): JsValue {
            $result = new JsObject();
            $result->set('firstDay', new JsNumber(1.0));
            $weekend = new JsArray();
            $weekend->set('0', new JsNumber(6.0));
            $weekend->set('1', new JsNumber(7.0));
            $weekend->set('length', new JsNumber(2.0));
            $result->set('weekend', $weekend);
            $result->set('minimalDays', new JsNumber(1.0));
            return $result;
        }, 0);
        $proto->defineOwnProperty('getWeekInfo', PropertyDescriptor::data($getWeekInfo, true, false, true));

        // getTimeZones()
        $getTimeZones = JsFunction::fromCallable('getTimeZones', function (JsValue $this_): JsValue {
            $result = new JsArray();
            $tzs = self::getSupportedTimeZones();
            $limited = array_slice($tzs, 0, 50);
            foreach ($limited as $i => $tz) {
                $result->set((string) $i, new JsString($tz));
            }
            $result->set('length', new JsNumber((float) count($limited)));
            return $result;
        }, 0);
        $proto->defineOwnProperty('getTimeZones', PropertyDescriptor::data($getTimeZones, true, false, true));

        $intl->defineOwnProperty(
            'Locale',
            PropertyDescriptor::data($constructor, true, false, true),
        );
    }

    /**
     * Parse a BCP 47 language tag into components.
     *
     * @return array<string, mixed>|null
     */
    /**
     * Quick structural rejection of obviously-invalid BCP47 tags before
     * delegating to ICU. Catches the common cases test262 exercises:
     * non-ASCII text, leading singletons, wildcards, duplicate scripts /
     * regions / variants, and duplicate `-u-` / `-x-` extension singletons.
     */
    private static function isStructurallyInvalidLanguageTag(string $tag): bool
    {
        if ($tag === '') {
            return true;
        }
        // ASCII-only letters, digits, hyphens.
        if (preg_match('/[^A-Za-z0-9\-]/', $tag)) {
            return true;
        }
        // No leading or trailing hyphens, no consecutive hyphens, no empty
        // subtags.
        if ($tag[0] === '-' || $tag[strlen($tag) - 1] === '-' || str_contains($tag, '--')) {
            return true;
        }
        $parts = explode('-', $tag);
        $first = $parts[0];
        // First subtag must be unicode_language_subtag = alpha{2,3} | alpha{5,8}.
        $firstLen = strlen($first);
        if (
            !ctype_alpha($first)
            || !($firstLen === 2 || $firstLen === 3 || ($firstLen >= 5 && $firstLen <= 8))
        ) {
            return true;
        }
        $sawScript = false;
        $sawRegion = false;
        $variants = [];
        $extensionsSeen = [];
        $i = 1;
        $count = count($parts);
        while ($i < $count) {
            $p = $parts[$i];
            $len = strlen($p);
            if ($len === 0) {
                return true;
            }
            if ($len === 1) {
                // Singleton extension introducer. Must be unique and
                // followed by at least one extension subtag of length
                // 2-8. Singleton 'x' switches to private use; everything
                // after `x-` is alphanum{1,8} until the end of the tag.
                $key = strtolower($p);
                if (isset($extensionsSeen[$key])) {
                    return true;
                }
                $extensionsSeen[$key] = true;
                $i++;
                $isPrivate = $key === 'x';
                $minSubLen = $isPrivate ? 1 : 2;
                $maxSubLen = 8;
                $sawAny = false;
                while ($i < $count) {
                    $sub = $parts[$i];
                    $subLen = strlen($sub);
                    // Inside non-private extensions a length-1 subtag
                    // starts a new singleton; private use consumes
                    // length-1 subtags as part of its body.
                    if (!$isPrivate && $subLen === 1) {
                        break;
                    }
                    if ($subLen < $minSubLen || $subLen > $maxSubLen) {
                        return true;
                    }
                    if (!ctype_alnum($sub)) {
                        return true;
                    }
                    $sawAny = true;
                    $i++;
                }
                if (!$sawAny) {
                    return true;
                }
                continue;
            }
            // Script: alpha{4} (only one allowed, and only before region/variant).
            if ($len === 4 && ctype_alpha($p) && !$sawScript && !$sawRegion && empty($variants)) {
                $sawScript = true;
                $i++;
                continue;
            }
            // Region: alpha{2} | digit{3}, only one, only before variants.
            if (
                ((($len === 2 && ctype_alpha($p)) || ($len === 3 && ctype_digit($p))))
                && !$sawRegion && empty($variants)
            ) {
                $sawRegion = true;
                $i++;
                continue;
            }
            // Variant: alphanum{5,8} or digit followed by alphanum{3}.
            $isLongVariant = ($len >= 5 && $len <= 8 && ctype_alnum($p));
            $isShortNumericVariant = ($len === 4 && ctype_digit($p[0]) && ctype_alnum($p));
            if ($isLongVariant || $isShortNumericVariant) {
                $vKey = strtolower($p);
                if (isset($variants[$vKey])) {
                    return true;
                }
                $variants[$vKey] = true;
                $i++;
                continue;
            }
            return true;
        }
        return false;
    }

    private static function parseLocaleTag(string $tag): ?array
    {
        if (self::isStructurallyInvalidLanguageTag($tag)) {
            return null;
        }
        if (extension_loaded('intl')) {
            $icuTag = str_replace('-', '_', $tag);
            $parsed = \Locale::parseLocale($icuTag);
            if ($parsed === null || empty($parsed)) {
                // Try to at least extract the language.
                if (preg_match('/^([a-zA-Z]{2,8})/', $tag, $m)) {
                    $parsed = ['language' => strtolower($m[1])];
                } else {
                    return null;
                }
            }

            $result = ['language' => strtolower($parsed['language'] ?? '')];
            // ICU drops `und` instead of treating it as the explicit
            // undetermined code; keep it so `new Intl.Locale("und")`
            // round-trips to "und".
            if ($result['language'] === '' && preg_match('/^([a-zA-Z]{2,8})/', $tag, $m)) {
                $result['language'] = strtolower($m[1]);
            }
            if (isset($parsed['script']) && $parsed['script'] !== '') {
                $result['script'] = ucfirst(strtolower($parsed['script']));
            }
            if (isset($parsed['region']) && $parsed['region'] !== '') {
                $result['region'] = strtoupper($parsed['region']);
            }

            // Extract unicode extension keywords from the original tag.
            if (preg_match('/-u-(.+?)(?:-[a-wyz]-|$)/i', $tag, $extMatch)) {
                $extStr = $extMatch[1];
                $extParts = explode('-', $extStr);
                $i = 0;
                while ($i < count($extParts)) {
                    $key = $extParts[$i];
                    if (strlen($key) === 2) {
                        $val = isset($extParts[$i + 1]) && strlen($extParts[$i + 1]) > 2
                            ? $extParts[$i + 1]
                            : 'true';
                        $mapped = match ($key) {
                            'ca' => 'calendar',
                            'co' => 'collation',
                            'hc' => 'hourCycle',
                            'kf' => 'caseFirst',
                            'kn' => 'numeric',
                            'nu' => 'numberingSystem',
                            default => null,
                        };
                        if ($mapped !== null) {
                            if ($mapped === 'numeric') {
                                $result[$mapped] = ($val === 'true');
                            } else {
                                $result[$mapped] = $val;
                            }
                        }
                        $i += ($val !== 'true' || (isset($extParts[$i + 1]) && strlen($extParts[$i + 1]) > 2)) ? 2 : 1;
                    } else {
                        $i++;
                    }
                }
            }

            return $result;
        }

        // Basic parsing without ICU.
        $parts = explode('-', $tag);
        if (!preg_match('/^[a-zA-Z]{2,8}$/', $parts[0])) {
            return null;
        }

        $result = ['language' => strtolower($parts[0])];
        $i = 1;

        if (isset($parts[$i]) && strlen($parts[$i]) === 4 && ctype_alpha($parts[$i])) {
            $result['script'] = ucfirst(strtolower($parts[$i]));
            $i++;
        }

        if (
            isset($parts[$i]) && (
            (strlen($parts[$i]) === 2 && ctype_alpha($parts[$i])) ||
            (strlen($parts[$i]) === 3 && ctype_digit($parts[$i]))
            )
        ) {
            $result['region'] = strtoupper($parts[$i]);
            $i++;
        }

        return $result;
    }

    /**
     * Reconstruct a BCP 47 tag from parsed components.
     *
     * @param array<string, mixed> $parsed
     */
    private static function reconstructLocaleTag(array $parsed): string
    {
        $parts = [];
        if (isset($parsed['language'])) {
            $parts[] = strtolower((string) $parsed['language']);
        }
        if (isset($parsed['script'])) {
            $parts[] = ucfirst(strtolower((string) $parsed['script']));
        }
        if (isset($parsed['region'])) {
            $parts[] = strtoupper((string) $parsed['region']);
        }

        // Add unicode extensions if present.
        $extensions = [];
        $extMap = [
            'calendar' => 'ca',
            'collation' => 'co',
            'hourCycle' => 'hc',
            'caseFirst' => 'kf',
            'numeric' => 'kn',
            'numberingSystem' => 'nu',
        ];
        foreach ($extMap as $key => $uKey) {
            if (isset($parsed[$key])) {
                $val = $parsed[$key];
                if (is_bool($val)) {
                    // The kn (numeric) extension uses the bare key for
                    // true and `kn-false` for false per UTS35
                    // canonicalization.
                    $extensions[] = $uKey;
                    if (!$val) {
                        $extensions[] = 'false';
                    }
                } else {
                    $extensions[] = $uKey;
                    $extensions[] = (string) $val;
                }
            }
        }
        if (count($extensions) > 0) {
            $parts[] = 'u';
            array_push($parts, ...$extensions);
        }

        return implode('-', $parts);
    }

    /**
     * Add likely subtags to a locale tag.
     */
    private static function addLikelySubtags(string $tag): string
    {
        if (!extension_loaded('intl')) {
            return $tag;
        }
        $icuTag = str_replace('-', '_', $tag);
        // ICU 67+ has Locale::addLikelySubtags but PHP may not expose it.
        // Try using the maximized form from lookup.
        $result = \Locale::lookup([$icuTag], $icuTag, true, $icuTag);
        return str_replace('_', '-', $result ?: $tag);
    }

    /**
     * Remove likely subtags from a locale tag.
     */
    private static function removeLikelySubtags(string $tag): string
    {
        // Basic: keep only language subtag.
        $parts = explode('-', $tag);
        return strtolower($parts[0]);
    }

    // ---------------------------------------------------------------
    // Intl.DisplayNames
    // ---------------------------------------------------------------

    private static function installDisplayNames(JsObject $intl): void
    {
        $proto = new JsObject();

        $constructor = JsFunction::fromCallable(
            'DisplayNames',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || $this_->get('[[NewTarget]]') instanceof JsUndefined) {
                    throw new TypeError('Constructor Intl.DisplayNames requires \'new\'');
                }

                $localesArg = $args[0] ?? JsUndefined::instance();
                $optionsArg = $args[1] ?? JsUndefined::instance();

                $locales = self::localesFromArg($localesArg);
                $options = self::coerceOptions($optionsArg);

                // type is required.
                $typeVal = $options->get('type');
                if ($typeVal instanceof JsUndefined) {
                    throw new TypeError('Required option "type" not provided');
                }
                $type = TypeConversion::toString($typeVal);
                $validTypes = ['language', 'region', 'script', 'currency', 'calendar', 'dateTimeField'];
                if (!in_array($type, $validTypes, true)) {
                    throw new RangeError("Invalid type: {$type}");
                }

                $obj = new JsObject($proto);
                $resolvedLocale = self::resolveLocale($locales);
                $obj->defineOwnProperty('[[Locale]]', PropertyDescriptor::data(
                    new JsString($resolvedLocale),
                    false,
                    false,
                    false,
                ));
                $obj->defineOwnProperty('[[Type]]', PropertyDescriptor::data(
                    new JsString($type),
                    false,
                    false,
                    false,
                ));

                // style
                $style = 'long';
                $styleVal = $options->get('style');
                if (!$styleVal instanceof JsUndefined) {
                    $s = TypeConversion::toString($styleVal);
                    if (!in_array($s, ['narrow', 'short', 'long'], true)) {
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

                // fallback
                $fallback = 'code';
                $fbVal = $options->get('fallback');
                if (!$fbVal instanceof JsUndefined) {
                    $fb = TypeConversion::toString($fbVal);
                    if (!in_array($fb, ['code', 'none'], true)) {
                        throw new RangeError("Invalid fallback: {$fb}");
                    }
                    $fallback = $fb;
                }
                $obj->defineOwnProperty('[[Fallback]]', PropertyDescriptor::data(
                    new JsString($fallback),
                    false,
                    false,
                    false,
                ));

                // languageDisplay
                $languageDisplay = 'dialect';
                $ldVal = $options->get('languageDisplay');
                if (!$ldVal instanceof JsUndefined) {
                    $ld = TypeConversion::toString($ldVal);
                    if (!in_array($ld, ['dialect', 'standard'], true)) {
                        throw new RangeError("Invalid languageDisplay: {$ld}");
                    }
                    $languageDisplay = $ld;
                }
                $obj->defineOwnProperty('[[LanguageDisplay]]', PropertyDescriptor::data(
                    new JsString($languageDisplay),
                    false,
                    false,
                    false,
                ));

                return $obj;
            },
            2,
        );
        $constructor->setConstructable();

        $constructor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($constructor, true, false, true));

        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Intl.DisplayNames'), false, false, true),
        );

        // DisplayNames.prototype.of(code)
        $of = JsFunction::fromCallable('of', function (JsValue $this_, array $args): JsValue {
            $code = isset($args[0]) ? TypeConversion::toString($args[0]) : '';
            if ($code === '') {
                throw new RangeError('Invalid code for DisplayNames.of()');
            }

            $type = 'language';
            $locale = 'en';
            if ($this_ instanceof JsObject) {
                $type = self::extractInternalString($this_, '[[Type]]', 'language');
                $locale = self::extractInternalString($this_, '[[Locale]]', 'en');
            }

            if (extension_loaded('intl')) {
                $icuLocale = str_replace('-', '_', $locale);
                $displayName = match ($type) {
                    'language' => \Locale::getDisplayLanguage(str_replace('-', '_', $code), $icuLocale),
                    'region' => \Locale::getDisplayRegion('und_' . strtoupper($code), $icuLocale),
                    'script' => \Locale::getDisplayScript('und_' . ucfirst(strtolower($code)), $icuLocale),
                    default => $code,
                };
                if ($displayName !== '' && $displayName !== false) {
                    return new JsString($displayName);
                }
            }

            return new JsString($code);
        }, 1);
        $proto->defineOwnProperty('of', PropertyDescriptor::data($of, true, false, true));

        // DisplayNames.prototype.resolvedOptions()
        $resolvedOptions = JsFunction::fromCallable('resolvedOptions', function (JsValue $this_): JsValue {
            $result = new JsObject();
            if ($this_ instanceof JsObject) {
                $result->set('locale', new JsString(
                    self::extractInternalString($this_, '[[Locale]]', 'en'),
                ));
                $result->set('type', new JsString(
                    self::extractInternalString($this_, '[[Type]]', 'language'),
                ));
                $result->set('style', new JsString(
                    self::extractInternalString($this_, '[[Style]]', 'long'),
                ));
                $result->set('fallback', new JsString(
                    self::extractInternalString($this_, '[[Fallback]]', 'code'),
                ));
                $result->set('languageDisplay', new JsString(
                    self::extractInternalString($this_, '[[LanguageDisplay]]', 'dialect'),
                ));
            }
            return $result;
        }, 0);
        $proto->defineOwnProperty(
            'resolvedOptions',
            PropertyDescriptor::data($resolvedOptions, true, false, true),
        );

        $constructor->defineOwnProperty(
            'supportedLocalesOf',
            PropertyDescriptor::data(self::makeSupportedLocalesOf('DisplayNames'), true, false, true),
        );

        $intl->defineOwnProperty(
            'DisplayNames',
            PropertyDescriptor::data($constructor, true, false, true),
        );
    }

    // ---------------------------------------------------------------
    // Intl.ListFormat
    // ---------------------------------------------------------------

    private static function installListFormat(JsObject $intl): void
    {
        $proto = new JsObject();

        $constructor = JsFunction::fromCallable(
            'ListFormat',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || $this_->get('[[NewTarget]]') instanceof JsUndefined) {
                    throw new TypeError('Constructor Intl.ListFormat requires \'new\'');
                }

                $localesArg = $args[0] ?? JsUndefined::instance();
                $optionsArg = $args[1] ?? JsUndefined::instance();

                $locales = self::localesFromArg($localesArg);
                $options = self::coerceOptions($optionsArg);

                $obj = new JsObject($proto);
                $resolvedLocale = self::resolveLocale($locales);
                $obj->defineOwnProperty('[[Locale]]', PropertyDescriptor::data(
                    new JsString($resolvedLocale),
                    false,
                    false,
                    false,
                ));

                // type: "conjunction" (default), "disjunction", "unit".
                $type = 'conjunction';
                $typeVal = $options->get('type');
                if (!$typeVal instanceof JsUndefined) {
                    $t = TypeConversion::toString($typeVal);
                    if (!in_array($t, ['conjunction', 'disjunction', 'unit'], true)) {
                        throw new RangeError("Invalid type: {$t}");
                    }
                    $type = $t;
                }
                $obj->defineOwnProperty('[[Type]]', PropertyDescriptor::data(
                    new JsString($type),
                    false,
                    false,
                    false,
                ));

                // style: "long" (default), "short", "narrow".
                $style = 'long';
                $styleVal = $options->get('style');
                if (!$styleVal instanceof JsUndefined) {
                    $s = TypeConversion::toString($styleVal);
                    if (!in_array($s, ['long', 'short', 'narrow'], true)) {
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

                return $obj;
            },
            0,
        );
        $constructor->setConstructable();

        $constructor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($constructor, true, false, true));

        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Intl.ListFormat'), false, false, true),
        );

        // ListFormat.prototype.format(list)
        $format = JsFunction::fromCallable('format', function (JsValue $this_, array $args): JsValue {
            $list = $args[0] ?? JsUndefined::instance();
            $items = [];
            if ($list instanceof JsArray || $list instanceof JsObject) {
                $lenVal = $list->get('length');
                $len = $lenVal instanceof JsUndefined ? 0 : (int) TypeConversion::toNumber($lenVal);
                for ($i = 0; $i < $len; $i++) {
                    $items[] = TypeConversion::toString($list->get((string) $i));
                }
            }

            $type = 'conjunction';
            if ($this_ instanceof JsObject) {
                $type = self::extractInternalString($this_, '[[Type]]', 'conjunction');
            }

            if (empty($items)) {
                return new JsString('');
            }
            if (count($items) === 1) {
                return new JsString($items[0]);
            }

            $separator = $type === 'disjunction' ? ' or ' : ($type === 'unit' ? ' ' : ' and ');
            $last = array_pop($items);
            return new JsString(implode(', ', $items) . ($count = count($items)) > 0 ? $separator . $last : $last);
        }, 1);
        $proto->defineOwnProperty('format', PropertyDescriptor::data($format, true, false, true));

        // ListFormat.prototype.formatToParts(list)
        $formatToParts = JsFunction::fromCallable('formatToParts', function (JsValue $this_, array $args): JsValue {
            $result = new JsArray();
            $result->set('length', new JsNumber(0.0));
            return $result;
        }, 1);
        $proto->defineOwnProperty('formatToParts', PropertyDescriptor::data($formatToParts, true, false, true));

        // ListFormat.prototype.resolvedOptions()
        $resolvedOptions = JsFunction::fromCallable('resolvedOptions', function (JsValue $this_): JsValue {
            $result = new JsObject();
            if ($this_ instanceof JsObject) {
                $result->set('locale', new JsString(
                    self::extractInternalString($this_, '[[Locale]]', 'en'),
                ));
                $result->set('type', new JsString(
                    self::extractInternalString($this_, '[[Type]]', 'conjunction'),
                ));
                $result->set('style', new JsString(
                    self::extractInternalString($this_, '[[Style]]', 'long'),
                ));
            }
            return $result;
        }, 0);
        $proto->defineOwnProperty(
            'resolvedOptions',
            PropertyDescriptor::data($resolvedOptions, true, false, true),
        );

        $constructor->defineOwnProperty(
            'supportedLocalesOf',
            PropertyDescriptor::data(self::makeSupportedLocalesOf('ListFormat'), true, false, true),
        );

        $intl->defineOwnProperty(
            'ListFormat',
            PropertyDescriptor::data($constructor, true, false, true),
        );
    }

    // ---------------------------------------------------------------
    // Intl.RelativeTimeFormat
    // ---------------------------------------------------------------

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

                $obj = new JsObject($proto);
                $resolvedLocale = self::resolveLocale($locales);
                $obj->defineOwnProperty('[[Locale]]', PropertyDescriptor::data(
                    new JsString($resolvedLocale),
                    false,
                    false,
                    false,
                ));

                // style: "long" (default), "short", "narrow".
                $style = 'long';
                $styleVal = $options->get('style');
                if (!$styleVal instanceof JsUndefined) {
                    $s = TypeConversion::toString($styleVal);
                    if (!in_array($s, ['long', 'short', 'narrow'], true)) {
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

                // numeric: "always" (default), "auto".
                $numeric = 'always';
                $numVal = $options->get('numeric');
                if (!$numVal instanceof JsUndefined) {
                    $n = TypeConversion::toString($numVal);
                    if (!in_array($n, ['always', 'auto'], true)) {
                        throw new RangeError("Invalid numeric: {$n}");
                    }
                    $numeric = $n;
                }
                $obj->defineOwnProperty('[[Numeric]]', PropertyDescriptor::data(
                    new JsString($numeric),
                    false,
                    false,
                    false,
                ));

                // numberingSystem
                $numberingSystem = 'latn';
                $nsVal = $options->get('numberingSystem');
                if (!$nsVal instanceof JsUndefined) {
                    $ns = TypeConversion::toString($nsVal);
                    $numberingSystem = $ns;
                }
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
            $value = $args[0] ?? JsUndefined::instance();
            $unit = isset($args[1]) ? TypeConversion::toString($args[1]) : '';

            $n = TypeConversion::toNumber($value);
            if (!is_finite($n)) {
                throw new RangeError('Invalid time value');
            }

            $validUnits = ['year', 'years', 'quarter', 'quarters', 'month', 'months',
                'week', 'weeks', 'day', 'days', 'hour', 'hours', 'minute', 'minutes',
                'second', 'seconds'];
            if (!in_array($unit, $validUnits, true)) {
                throw new RangeError("Invalid unit: {$unit}");
            }

            // Normalize unit to singular.
            $singular = rtrim($unit, 's');
            if ($singular === $unit && str_ends_with($unit, 's')) {
                $singular = substr($unit, 0, -1);
            }

            $abs = abs($n);
            $unitStr = $abs === 1.0 ? $singular : $singular . 's';

            if ($n < 0) {
                return new JsString("{$abs} {$unitStr} ago");
            }
            if ($n > 0) {
                return new JsString("in {$abs} {$unitStr}");
            }
            return new JsString("in 0 {$unitStr}");
        }, 2);
        $proto->defineOwnProperty('format', PropertyDescriptor::data($format, true, false, true));

        // RelativeTimeFormat.prototype.formatToParts(value, unit)
        $formatToParts = JsFunction::fromCallable('formatToParts', function (JsValue $this_, array $args): JsValue {
            $result = new JsArray();
            $result->set('length', new JsNumber(0.0));
            return $result;
        }, 2);
        $proto->defineOwnProperty('formatToParts', PropertyDescriptor::data($formatToParts, true, false, true));

        // RelativeTimeFormat.prototype.resolvedOptions()
        $resolvedOptions = JsFunction::fromCallable('resolvedOptions', function (JsValue $this_): JsValue {
            $result = new JsObject();
            if ($this_ instanceof JsObject) {
                $result->set('locale', new JsString(
                    self::extractInternalString($this_, '[[Locale]]', 'en'),
                ));
                $result->set('style', new JsString(
                    self::extractInternalString($this_, '[[Style]]', 'long'),
                ));
                $result->set('numeric', new JsString(
                    self::extractInternalString($this_, '[[Numeric]]', 'always'),
                ));
                $result->set('numberingSystem', new JsString(
                    self::extractInternalString($this_, '[[NumberingSystem]]', 'latn'),
                ));
            }
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

    // ---------------------------------------------------------------
    // Intl.Segmenter
    // ---------------------------------------------------------------

    private static function installSegmenter(JsObject $intl): void
    {
        $proto = new JsObject();

        $constructor = JsFunction::fromCallable(
            'Segmenter',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || $this_->get('[[NewTarget]]') instanceof JsUndefined) {
                    throw new TypeError('Constructor Intl.Segmenter requires \'new\'');
                }

                $localesArg = $args[0] ?? JsUndefined::instance();
                $optionsArg = $args[1] ?? JsUndefined::instance();

                $locales = self::localesFromArg($localesArg);
                $options = self::coerceOptions($optionsArg);

                $obj = new JsObject($proto);
                $resolvedLocale = self::resolveLocale($locales);
                $obj->defineOwnProperty('[[Locale]]', PropertyDescriptor::data(
                    new JsString($resolvedLocale),
                    false,
                    false,
                    false,
                ));

                // granularity: "grapheme" (default), "word", "sentence".
                $granularity = 'grapheme';
                $gVal = $options->get('granularity');
                if (!$gVal instanceof JsUndefined) {
                    $g = TypeConversion::toString($gVal);
                    if (!in_array($g, ['grapheme', 'word', 'sentence'], true)) {
                        throw new RangeError("Invalid granularity: {$g}");
                    }
                    $granularity = $g;
                }
                $obj->defineOwnProperty('[[Granularity]]', PropertyDescriptor::data(
                    new JsString($granularity),
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
            PropertyDescriptor::data(new JsString('Intl.Segmenter'), false, false, true),
        );

        // Segmenter.prototype.segment(string)
        $segment = JsFunction::fromCallable('segment', function (JsValue $this_, array $args): JsValue {
            $str = isset($args[0]) ? TypeConversion::toString($args[0]) : '';
            $granularity = 'grapheme';
            if ($this_ instanceof JsObject) {
                $granularity = self::extractInternalString($this_, '[[Granularity]]', 'grapheme');
            }

            // Return a Segments object (iterable).
            $segments = new JsObject();
            // Store the input and granularity for iteration.
            $segments->defineOwnProperty('[[String]]', PropertyDescriptor::data(
                new JsString($str),
                false,
                false,
                false,
            ));
            $segments->defineOwnProperty('[[Granularity]]', PropertyDescriptor::data(
                new JsString($granularity),
                false,
                false,
                false,
            ));

            // containing(index)
            $containing = JsFunction::fromCallable('containing', function (
                JsValue $this2_,
                array $args,
            ) use (
                $str,
                $granularity
): JsValue {
                $index = isset($args[0]) ? (int) TypeConversion::toNumber($args[0]) : 0;
                if ($index < 0 || $index >= mb_strlen($str, 'UTF-8')) {
                    return JsUndefined::instance();
                }
                $char = mb_substr($str, $index, 1, 'UTF-8');
                $result = new JsObject();
                $result->set('segment', new JsString($char));
                $result->set('index', new JsNumber((float) $index));
                $result->set('input', new JsString($str));
                if ($granularity === 'word') {
                    $result->set('isWordLike', new JsBoolean(preg_match('/\w/u', $char) === 1));
                }
                return $result;
            }, 1);
            $segments->defineOwnProperty('containing', PropertyDescriptor::data($containing, true, false, true));

            // [Symbol.iterator]
            $iterFn = JsFunction::fromCallable('[Symbol.iterator]', function (
                JsValue $this2_,
            ) use (
                $str,
                $granularity
): JsValue {
                // Create an iterator that yields segment objects.
                $chars = [];
                if ($granularity === 'grapheme' && extension_loaded('intl')) {
                    $bi = \IntlBreakIterator::createCharacterInstance();
                    $bi->setText($str);
                    $prev = 0;
                    while (($pos = $bi->next()) !== \IntlBreakIterator::DONE) {
                        $chars[] = ['segment' => mb_substr($str, $prev, $pos - $prev, 'UTF-8'), 'index' => $prev];
                        $prev = $pos;
                    }
                } elseif ($granularity === 'word') {
                    // Split on word boundaries.
                    preg_match_all('/\S+|\s+/u', $str, $matches, PREG_OFFSET_CAPTURE);
                    foreach ($matches[0] as $m) {
                        $chars[] = ['segment' => $m[0], 'index' => mb_strlen(substr($str, 0, $m[1]), 'UTF-8')];
                    }
                } elseif ($granularity === 'sentence') {
                    if (extension_loaded('intl')) {
                        $bi = \IntlBreakIterator::createSentenceInstance();
                        $bi->setText($str);
                        $prev = 0;
                        while (($pos = $bi->next()) !== \IntlBreakIterator::DONE) {
                            $chars[] = ['segment' => mb_substr($str, $prev, $pos - $prev, 'UTF-8'), 'index' => $prev];
                            $prev = $pos;
                        }
                    } else {
                        $chars[] = ['segment' => $str, 'index' => 0];
                    }
                } else {
                    // Grapheme fallback without intl.
                    $len = mb_strlen($str, 'UTF-8');
                    for ($i = 0; $i < $len; $i++) {
                        $chars[] = ['segment' => mb_substr($str, $i, 1, 'UTF-8'), 'index' => $i];
                    }
                }

                $idx = 0;
                $total = count($chars);
                $iter = new JsObject();
                $nextCb = function () use (
                    &$idx,
                    $total,
                    &$chars,
                    $str,
                    $granularity,
                ): JsValue {
                    if ($idx >= $total) {
                        $result = new JsObject();
                        $result->set('done', new JsBoolean(true));
                        $result->set('value', JsUndefined::instance());
                        return $result;
                    }
                    $entry = $chars[$idx];
                    $idx++;
                    $segObj = new JsObject();
                    $segObj->set('segment', new JsString($entry['segment']));
                    $segObj->set('index', new JsNumber((float) $entry['index']));
                    $segObj->set('input', new JsString($str));
                    if ($granularity === 'word') {
                        $segObj->set('isWordLike', new JsBoolean(
                            preg_match('/\w/u', $entry['segment']) === 1,
                        ));
                    }
                    $result = new JsObject();
                    $result->set('done', new JsBoolean(false));
                    $result->set('value', $segObj);
                    return $result;
                };
                $nextFn = JsFunction::fromCallable('next', $nextCb, 0);
                $iter->defineOwnProperty('next', PropertyDescriptor::data($nextFn, true, false, true));

                // The iterator itself is iterable.
                $selfIter = JsFunction::fromCallable('[Symbol.iterator]', function (JsValue $this3_): JsValue {
                    return $this3_;
                }, 0);
                $iter->definePropertyBySymbol(
                    SymbolConstructor::iterator(),
                    PropertyDescriptor::data($selfIter, true, false, true),
                );
                return $iter;
            }, 0);
            $segments->definePropertyBySymbol(
                SymbolConstructor::iterator(),
                PropertyDescriptor::data($iterFn, true, false, true),
            );

            return $segments;
        }, 1);
        $proto->defineOwnProperty('segment', PropertyDescriptor::data($segment, true, false, true));

        // Segmenter.prototype.resolvedOptions()
        $resolvedOptions = JsFunction::fromCallable('resolvedOptions', function (JsValue $this_): JsValue {
            $result = new JsObject();
            if ($this_ instanceof JsObject) {
                $result->set('locale', new JsString(
                    self::extractInternalString($this_, '[[Locale]]', 'en'),
                ));
                $result->set('granularity', new JsString(
                    self::extractInternalString($this_, '[[Granularity]]', 'grapheme'),
                ));
            }
            return $result;
        }, 0);
        $proto->defineOwnProperty(
            'resolvedOptions',
            PropertyDescriptor::data($resolvedOptions, true, false, true),
        );

        $constructor->defineOwnProperty(
            'supportedLocalesOf',
            PropertyDescriptor::data(self::makeSupportedLocalesOf('Segmenter'), true, false, true),
        );

        $intl->defineOwnProperty(
            'Segmenter',
            PropertyDescriptor::data($constructor, true, false, true),
        );
    }

    // ---------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------

    private static function extractInternalString(JsObject $obj, string $slot, string $default): string
    {
        $val = $obj->get($slot);
        if ($val instanceof JsString) {
            return $val->value;
        }
        return $default;
    }

    private static function extractInternalNumber(JsObject $obj, string $slot, float $default): float
    {
        $val = $obj->get($slot);
        if ($val instanceof JsNumber) {
            return $val->value;
        }
        return $default;
    }

    private static function extractInternalStringOrNull(JsObject $obj, string $slot): ?string
    {
        $val = $obj->get($slot);
        if ($val instanceof JsString) {
            return $val->value;
        }
        return null;
    }
}
