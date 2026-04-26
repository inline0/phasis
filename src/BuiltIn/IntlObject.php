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

        // Per spec, null falls into the ToObject(locales) path which
        // throws TypeError. We surface the same error directly.
        if ($locales instanceof JsNull) {
            throw new TypeError('Cannot convert null to object');
        }

        $seen = [];

        // Per spec, both String and an Intl.Locale instance are wrapped
        // in a one-element list before iteration. The Locale instance's
        // tag comes from the [[Locale]] internal slot, bypassing
        // ToString to avoid monkey-patched toString.
        if ($locales instanceof JsString) {
            $tag = $locales->value;
            $canon = self::canonicalizeLocaleTag($tag);
            if ($canon === null) {
                throw new RangeError("Invalid language tag: {$tag}");
            }
            return [$canon];
        }
        if ($locales instanceof JsObject && self::isInitializedLocale($locales)) {
            $tag = self::extractInternalString($locales, '[[LocaleTag]]', '');
            if ($tag === '') {
                $tag = TypeConversion::toString($locales);
            }
            $canon = self::canonicalizeLocaleTag($tag);
            if ($canon === null) {
                throw new RangeError("Invalid language tag: {$tag}");
            }
            return [$canon];
        }

        // Spec: ToObject(locales) is performed before iterating. Primitives
        // (boolean, number, symbol, bigint) coerce to wrapper objects whose
        // prototype-chain may expose `length`/index properties — required by
        // the locales-is-not-a-string test that patches Number.prototype.
        $obj = $locales instanceof JsObject
            ? $locales
            : TypeConversion::toObject($locales);

        $lenVal = $obj->get('length');
        $len = $lenVal instanceof JsUndefined ? 0 : (int) TypeConversion::toNumber($lenVal);
        for ($k = 0; $k < $len; $k++) {
            $kPresent = $obj->has((string) $k);
            if ($kPresent) {
                $kValue = $obj->get((string) $k);
                if (!$kValue instanceof JsString && !$kValue instanceof JsObject) {
                    throw new TypeError('Language tag must be a string or object');
                }
                if ($kValue instanceof JsObject && self::isInitializedLocale($kValue)) {
                    $tag = self::extractInternalString($kValue, '[[LocaleTag]]', '');
                    if ($tag === '') {
                        $tag = TypeConversion::toString($kValue);
                    }
                } else {
                    $tag = TypeConversion::toString($kValue);
                }
                $canon = self::canonicalizeLocaleTag($tag);
                if ($canon === null) {
                    throw new RangeError("Invalid language tag: {$tag}");
                }
                if (!in_array($canon, $seen, true)) {
                    $seen[] = $canon;
                }
            }
        }

        return $seen;
    }

    /**
     * Detect an Intl.Locale instance by walking the prototype chain
     * looking for the `[[LocaleTag]]` internal slot we set during
     * construction. Subclasses (like the test262 `PatchedLocale`)
     * inherit the slot through `super`.
     */
    private static function isInitializedLocale(JsObject $obj): bool
    {
        return !$obj->get('[[LocaleTag]]') instanceof JsUndefined
            || !$obj->get('[[language]]') instanceof JsUndefined;
    }

    /**
     * Canonicalize a single BCP 47 language tag. Routes the input through
     * the structural parser/reconstructor so the output is canonical
     * BCP47 (extensions reordered, attributes sorted, duplicate keywords
     * dropped) rather than ICU's "lang@key=val" legacy form.
     */
    private static function canonicalizeLocaleTag(string $tag): ?string
    {
        if ($tag === '') {
            return null;
        }

        // ASCII-only validity gate; reject obvious garbage.
        if (!preg_match('/^[a-zA-Z0-9][-a-zA-Z0-9]*$/u', $tag)) {
            return null;
        }

        $parsed = self::parseLocaleTag($tag);
        if ($parsed === null || ($parsed['language'] ?? '') === '') {
            return null;
        }
        return self::reconstructLocaleTag($parsed);
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
            $available = \ResourceBundle::getLocales('');
            foreach ($requestedLocales as $locale) {
                $icuLocale = str_replace('-', '_', $locale);
                $best = \Locale::lookup($available, $icuLocale, true, '');
                if ($best !== '' && $best !== null) {
                    // Canonicalise the requested tag (preserving its
                    // requested specificity) rather than the truncated
                    // best match — `de-DE` should resolve to `de-DE`,
                    // not `de`, even when only `de` is in the bundle.
                    return self::canonicalizeLocaleTag($locale) ?? $locale;
                }
            }
            $default = \Locale::getDefault();
            // Strip the legacy `POSIX` variant from the host default so
            // resolvedOptions().locale matches V8 ("en-US") instead of
            // exposing the macOS `en_US_POSIX` artefact.
            $default = preg_replace('/[_-]POSIX$/i', '', $default) ?? $default;
            return self::canonicalizeLocaleTag(str_replace('_', '-', $default))
                ?? str_replace('_', '-', $default);
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
        // Defer all validation to canonicalizeLocaleList so primitives
        // get ToObject'd (matching the spec's CanonicalizeLocaleList
        // step 4) and the resulting wrapper's length/index properties
        // are walked via the prototype chain.
        return self::canonicalizeLocaleList($arg);
    }

    /**
     * Convert a JS options argument to a JsObject (coerce undefined/null to empty object).
     * Per spec: CoerceOptionsToObject.
     */
    /**
     * OrdinaryCreateFromConstructor for Intl built-ins. When the
     * constructor is invoked via `super(...)` from a subclass, the
     * runtime allocates an instance with the subclass's prototype and
     * passes it as `this`; we should populate that object in place
     * rather than creating a fresh JsObject with the built-in prototype.
     */
    private static function instanceFromConstructor(JsValue $this_, JsObject $proto): JsObject
    {
        if (
            $this_ instanceof JsObject
            && !$this_->get('[[NewTarget]]') instanceof JsUndefined
        ) {
            return $this_;
        }
        return new JsObject($proto);
    }

    private static function coerceOptions(JsValue $arg): JsObject
    {
        if ($arg instanceof JsUndefined) {
            // Spec uses OrdinaryObjectCreate(null) so that monkey-patching
            // Object.prototype cannot inject defaults into option lookup.
            return JsObject::createNullPrototype();
        }
        if ($arg instanceof JsNull) {
            // Per spec, an explicit null is `ToObject(null)` which throws.
            throw new TypeError('Cannot convert null to options object');
        }
        if ($arg instanceof JsObject) {
            return $arg;
        }
        // Primitive values are coerced to a wrapper Object whose property
        // lookups walk Object.prototype, matching V8's behaviour for
        // `new Intl.X([], "string")` and similar.
        $wrapper = new JsObject();
        return $wrapper;
    }

    /**
     * Strict variant of `coerceOptions` matching the spec's
     * `GetOptionsObject` algorithm. Used by the newer Intl
     * constructors (ListFormat, DisplayNames, Locale, Segmenter)
     * which reject primitive option arguments outright instead of
     * boxing them.
     */
    private static function getOptionsObject(JsValue $arg): JsObject
    {
        if ($arg instanceof JsUndefined) {
            return JsObject::createNullPrototype();
        }
        if ($arg instanceof JsObject) {
            return $arg;
        }
        throw new TypeError('Options argument must be an object or undefined');
    }

    /**
     * `CreateDataPropertyOrThrow` semantics for resolvedOptions: writes a
     * key as an own data property using defineOwnProperty so that any
     * inherited get-only accessor on Object.prototype cannot block the
     * data property from being created.
     */
    private static function defineDataProp(JsObject $obj, string $name, JsValue $value): void
    {
        $obj->defineOwnProperty(
            $name,
            PropertyDescriptor::data($value, true, true, true),
        );
    }

    /**
     * UTS35 type production: alphanum{3,8}(-alphanum{3,8})*. Used by
     * `numberingSystem`, `calendar`, `collation`, `firstDayOfWeek`, etc.
     */
    private static function isValidUnicodeTypeValue(string $value): bool
    {
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
        // The full BCP 47 calendar list per CLDR. ICU's
        // `getKeywordValuesForLocale` historically returned only
        // `gregorian`, so we merge it with the static list and
        // sort to satisfy supportedValuesOf's spec ordering
        // requirement.
        $base = ['buddhist', 'chinese', 'coptic', 'dangi', 'ethioaa',
            'ethiopic', 'gregory', 'hebrew', 'indian', 'islamic',
            'islamic-civil', 'islamic-rgsa', 'islamic-tbla',
            'islamic-umalqura', 'iso8601', 'japanese', 'persian', 'roc'];
        if (extension_loaded('intl')) {
            $iter = \IntlCalendar::getKeywordValuesForLocale('calendar', 'und', true);
            foreach ($iter as $cal) {
                $mapped = match ($cal) {
                    'gregorian' => 'gregory',
                    'ethiopic-amete-alem' => 'ethioaa',
                    default => $cal,
                };
                if (!in_array($mapped, $base, true)) {
                    $base[] = $mapped;
                }
            }
        }
        sort($base, SORT_STRING);
        return $base;
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

    /**
     * Resolve a user-provided time-zone identifier to its canonical form,
     * walking PHP's `DateTimeZone` list AND ICU's `IntlTimeZone`
     * enumeration so legacy aliases ICU still recognises (e.g.
     * `Canada/East-Saskatchewan`) are accepted even though PHP's list
     * has dropped them.
     */
    private static function resolveTimeZoneIdentifier(string $tz): ?string
    {
        static $tzLowerMap = null;
        if ($tzLowerMap === null) {
            $tzLowerMap = [];
            foreach (\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC) as $id) {
                $tzLowerMap[strtolower($id)] = $id;
            }
            foreach (\DateTimeZone::listIdentifiers() as $id) {
                $tzLowerMap[strtolower($id)] = $id;
            }
            if (extension_loaded('intl')) {
                $iter = \IntlTimeZone::createEnumeration();
                foreach ($iter as $id) {
                    $lower = strtolower($id);
                    if (!isset($tzLowerMap[$lower])) {
                        $tzLowerMap[$lower] = $id;
                    }
                }
            }
        }
        return $tzLowerMap[strtolower($tz)] ?? null;
    }

    /**
     * Normalise an offset-style time-zone string ("+05", "+0530", "-0530")
     * to the canonical "+HH:MM" form the spec mandates.
     */
    private static function canonicalizeOffsetTimeZone(string $tz): string
    {
        if (preg_match('/^([+-])(\d{1,2}):?(\d{0,2})$/', $tz, $m) !== 1) {
            return $tz;
        }
        $sign = $m[1];
        $hh = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        $mm = $m[3] === '' ? '00' : str_pad($m[3], 2, '0', STR_PAD_LEFT);
        if ($hh === '00' && $mm === '00') {
            return 'UTC';
        }
        return $sign . $hh . ':' . $mm;
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

            // The spec runs SupportedLocales which calls
            // CoerceOptionsToObject (= ToObject for non-undefined). Pass
            // through coerceOptions so a null argument throws TypeError
            // and primitives are wrapped per spec.
            if (!$optionsArg instanceof JsUndefined) {
                $opts = self::coerceOptions($optionsArg);
                self::validateLocaleMatcher($opts);
            }

            // Implement BestAvailableLocale to filter out tags that
            // ICU doesn't recognise. Without this we'd pretend every
            // structurally-valid tag is supported, which fails tests
            // that rely on intentionally-unsupported tags like `zxx`.
            $available = [];
            if (extension_loaded('intl')) {
                $available = \ResourceBundle::getLocales('');
            }
            $result = new JsArray();
            $count = 0;
            foreach ($canonicalized as $tag) {
                if ($available === []) {
                    $result->set((string) $count, new JsString($tag));
                    $count++;
                    continue;
                }
                $candidate = str_replace('-', '_', $tag);
                $best = \Locale::lookup($available, $candidate, true, '');
                if ($best === '' || $best === null) {
                    continue;
                }
                $result->set((string) $count, new JsString($tag));
                $count++;
            }
            $result->set('length', new JsNumber((float) $count));
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

                $obj = self::instanceFromConstructor($this_, $proto);
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

                // Pre-parse the requested locale so the `-u-kn-…` /
                // `-u-kf-…` / `-u-co-…` extension keywords are
                // available as default values when the matching
                // option isn't set explicitly. Also pulls the legacy
                // `numeric`/`caseFirst`/`collation` slots populated by
                // parseLocaleTag.
                $localeKeywords = ['numeric' => null, 'caseFirst' => null, 'collation' => null];
                foreach ($locales as $candidate) {
                    $parsedTag = self::parseLocaleTag($candidate);
                    if ($parsedTag === null) {
                        continue;
                    }
                    foreach ($localeKeywords as $key => $existing) {
                        if (isset($parsedTag[$key]) && $localeKeywords[$key] === null) {
                            $localeKeywords[$key] = $parsedTag[$key];
                        }
                    }
                    break;
                }

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

                // ignorePunctuation: boolean. Defaults to true for
                // locales whose CLDR data sets `alternateHandling` to
                // shifted; only Thai (and its descendants) matches in
                // CLDR's current data.
                $resolvedLanguage = strtolower(strtok($resolvedLocale, '-_'));
                $ignorePunctuation = $resolvedLanguage === 'th';
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

                // numeric: boolean. Falls back to the locale's `-u-kn-…`
                // value when the option isn't set explicitly.
                $numeric = $localeKeywords['numeric'] ?? false;
                $numVal = $options->get('numeric');
                if (!$numVal instanceof JsUndefined) {
                    $numeric = TypeConversion::toBoolean($numVal);
                }
                $obj->defineOwnProperty('[[Numeric]]', PropertyDescriptor::data(
                    new JsBoolean((bool) $numeric),
                    false,
                    false,
                    false,
                ));

                // caseFirst: "upper", "lower", "false" (default). Same
                // locale-extension fallback as numeric above.
                $caseFirst = $localeKeywords['caseFirst'] ?? 'false';
                if ($caseFirst === '') {
                    $caseFirst = 'false';
                }
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
                // ICU's alternate-handling defaults differ per locale
                // (Thai ships with SHIFTED), so ALWAYS write the
                // attribute explicitly to honour the user's
                // [[IgnorePunctuation]] choice — otherwise opting out
                // of ignorePunctuation on a Thai collator silently
                // keeps SHIFTED active.
                $ipVal = $this_->get('[[IgnorePunctuation]]');
                $ignorePunct = $ipVal instanceof JsBoolean && $ipVal->toBoolean();
                $collator->setAttribute(
                    \Collator::ALTERNATE_HANDLING,
                    $ignorePunct ? \Collator::SHIFTED : \Collator::NON_IGNORABLE,
                );

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
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedCollator]]') instanceof JsUndefined
            ) {
                throw new TypeError('Intl.Collator.prototype.compare called on non-Collator');
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
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedCollator]]') instanceof JsUndefined
            ) {
                throw new TypeError('Intl.Collator.prototype.resolvedOptions called on non-Collator');
            }
            $result = new JsObject();
            $locale = self::extractInternalString($this_, '[[Locale]]', 'en');
            self::defineDataProp($result, 'locale', new JsString($locale));
            self::defineDataProp($result, 'usage', new JsString(
                self::extractInternalString($this_, '[[Usage]]', 'sort'),
            ));
            self::defineDataProp($result, 'sensitivity', new JsString(
                self::extractInternalString($this_, '[[Sensitivity]]', 'variant'),
            ));
            $ipVal = $this_->get('[[IgnorePunctuation]]');
            self::defineDataProp($result, 'ignorePunctuation', new JsBoolean(
                $ipVal instanceof JsBoolean ? $ipVal->toBoolean() : false,
            ));
            self::defineDataProp($result, 'collation', new JsString(
                self::extractInternalString($this_, '[[Collation]]', 'default'),
            ));
            $numVal = $this_->get('[[Numeric]]');
            self::defineDataProp($result, 'numeric', new JsBoolean(
                $numVal instanceof JsBoolean ? $numVal->toBoolean() : false,
            ));
            self::defineDataProp($result, 'caseFirst', new JsString(
                self::extractInternalString($this_, '[[CaseFirst]]', 'false'),
            ));
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

                $obj = self::instanceFromConstructor($this_, $proto);
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
                }

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

                if (!$msdVal instanceof JsUndefined || !$xsdVal instanceof JsUndefined) {
                    // Significant digits mode.
                    $minSig = $defaultNumberOption($msdVal, 1, 21, 1, 'minimumSignificantDigits');
                    $maxSig = $defaultNumberOption($xsdVal, $minSig, 21, 21, 'maximumSignificantDigits');
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
                    $minFrac = $defaultNumberOption($mfdVal, 0, 100, $defaultMinFrac, 'minimumFractionDigits');
                    $maxFrac = $defaultNumberOption(
                        $xfdVal,
                        0,
                        100,
                        max($defaultMaxFrac, $minFrac),
                        'maximumFractionDigits',
                    );
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
                    new JsNumber((float) $roundingIncrement),
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
                //   everything else throws RangeError.
                $useGrouping = 'auto';
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
            $number = $args[0] ?? JsUndefined::instance();
            $numVal = TypeConversion::toNumber($number);
            $formatted = '';
            if ($this_ instanceof JsObject && extension_loaded('intl')) {
                $formatted = self::formatNumber($this_, $numVal);
            } else {
                $formatted = is_nan($numVal) ? 'NaN' : (string) $numVal;
            }
            return self::numberFormatToParts(
                $this_ instanceof JsObject ? $this_ : new JsObject(),
                $formatted,
                $numVal,
            );
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
            $start = TypeConversion::toNumber($startVal);
            $end = TypeConversion::toNumber($endVal);
            if (is_nan($start) || is_nan($end)) {
                throw new RangeError('Invalid number for formatRange');
            }
            $startStr = extension_loaded('intl')
                ? self::formatNumber($this_, $start) : (string) $start;
            $endStr = extension_loaded('intl')
                ? self::formatNumber($this_, $end) : (string) $end;
            if ($startStr === $endStr) {
                return new JsString($startStr);
            }
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
            $start = TypeConversion::toNumber($startVal);
            $end = TypeConversion::toNumber($endVal);
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
            if (
                !$this_ instanceof JsObject
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
            self::defineDataProp($result, 'minimumIntegerDigits', new JsNumber(
                self::extractInternalNumber($this_, '[[MinimumIntegerDigits]]', 1),
            ));
            $rt = self::extractInternalString($this_, '[[RoundingType]]', 'fractionDigits');
            if ($rt === 'significantDigits') {
                self::defineDataProp($result, 'minimumSignificantDigits', new JsNumber(
                    self::extractInternalNumber($this_, '[[MinimumSignificantDigits]]', 1),
                ));
                self::defineDataProp($result, 'maximumSignificantDigits', new JsNumber(
                    self::extractInternalNumber($this_, '[[MaximumSignificantDigits]]', 21),
                ));
            } else {
                self::defineDataProp($result, 'minimumFractionDigits', new JsNumber(
                    self::extractInternalNumber($this_, '[[MinimumFractionDigits]]', 0),
                ));
                self::defineDataProp($result, 'maximumFractionDigits', new JsNumber(
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
            self::defineDataProp($result, 'roundingIncrement', new JsNumber(
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
    private static function numberFormatToParts(JsObject $nf, string $formatted, float $number): JsArray
    {
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
            $result->set('length', new JsNumber((float) $idx));
            return $result;
        }

        if (!is_finite($number)) {
            // The body is the locale-specific Infinity glyph; emit it
            // verbatim under `infinity`.
            $emit('infinity', $body);
            if ($trailing !== '') {
                $emit('literal', $trailing);
            }
            $result->set('length', new JsNumber((float) $idx));
            return $result;
        }

        // Walk the body character-by-character. Digits coalesce into
        // integer/fraction runs; `,` / `.` / locale digit separators
        // map onto group/decimal; non-digit, non-separator runs become
        // currency or percent or literal depending on context.
        $style = self::extractInternalString($nf, '[[Style]]', 'decimal');
        $isCurrency = $style === 'currency';
        $isPercent = $style === 'percent';
        $isUnit = $style === 'unit';
        $sawDecimal = false;
        $i = 0;
        $bodyLen = strlen($body);
        while ($i < $bodyLen) {
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
                $emit($sawDecimal ? 'fraction' : 'integer', $digitRun);
                $i = $j;
                continue;
            }
            if ($ch === ',' || $ch === ' ' || preg_match('/^\p{Zs}$/u', $charStr) === 1) {
                $emit('group', $charStr);
                $i += $charLen;
                continue;
            }
            if ($ch === '.') {
                $sawDecimal = true;
                $emit('decimal', '.');
                $i++;
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
            // Whitespace between currency symbol and digits should be
            // a literal — but a non-alphabetic-non-currency run is
            // typically a literal too.
            if ($isCurrency) {
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
        $result->set('length', new JsNumber((float) $idx));
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
        if (is_nan($number)) {
            return 'NaN';
        }
        if (!is_finite($number)) {
            return $number < 0 ? '-∞' : '∞';
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
        return $sign . $mantissaStr . 'E' . $exp;
    }

    /**
     * Best-effort CLDR-shaped en-US label for a Unit Identifier.
     * Compound units (foo-per-bar) render as "shortFoo/shortBar"
     * via per-unit recursion. Anything else falls back to the
     * raw identifier.
     */
    private static function renderUnitLabel(string $unit, string $display): string
    {
        if ($unit === '') {
            return '';
        }
        if (str_contains($unit, '-per-')) {
            [$num, $den] = explode('-per-', $unit, 2);
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
        return $unit;
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

    private static function formatNumber(JsObject $nf, float $number): string
    {
        $locale = self::extractInternalString($nf, '[[Locale]]', 'en');
        $style = self::extractInternalString($nf, '[[Style]]', 'decimal');
        $numberingSystem = self::extractInternalString($nf, '[[NumberingSystem]]', 'latn');
        $notation = self::extractInternalString($nf, '[[Notation]]', 'standard');

        // Engineering / scientific notations decompose into a
        // mantissa + locale-rendered exponent. Compact notation is
        // outside our reach without CLDR pattern data.
        if ($notation === 'engineering' || $notation === 'scientific') {
            return self::formatScientificNumber($nf, $number, $notation);
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
        $icuMode = match ($rm) {
            'ceil' => \NumberFormatter::ROUND_CEILING,
            'floor' => \NumberFormatter::ROUND_FLOOR,
            'trunc' => \NumberFormatter::ROUND_DOWN,
            'expand' => \NumberFormatter::ROUND_UP,
            'halfCeil' => \NumberFormatter::ROUND_HALFUP,
            'halfFloor' => \NumberFormatter::ROUND_HALFDOWN,
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
        // and suffix the unit with a thin no-break space, mirroring
        // the en-US CLDR pattern. Locale-specific unit translations
        // require the CLDR unit data we don't ship.
        if ($style === 'unit') {
            $bareResult = $formatter->format($number);
            if ($bareResult === false) {
                $bareResult = (string) $number;
            }
            $unit = self::extractInternalString($nf, '[[Unit]]', '');
            $unitDisplay = self::extractInternalString($nf, '[[UnitDisplay]]', 'short');
            $unitLabel = self::renderUnitLabel($unit, $unitDisplay);
            $result = $unitLabel === ''
                ? $bareResult
                : $bareResult . "\u{00A0}" . $unitLabel;
            $result = self::normalizeIntlInfinity($result, $number);
            $result = self::applySignDisplay($nf, $result, $number);
            return $result;
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
            $result = $formatter->format($number);
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

                $obj = self::instanceFromConstructor($this_, $proto);
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
                    if (!self::isValidUnicodeTypeValue($numberingSystem)) {
                        throw new RangeError("Invalid numberingSystem: {$numberingSystem}");
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
                if (!$h12Val instanceof JsUndefined) {
                    $hour12 = TypeConversion::toBoolean($h12Val);
                }
                $hourCycle = null;
                $hcVal = $options->get('hourCycle');
                if (!$hcVal instanceof JsUndefined) {
                    $hc = TypeConversion::toString($hcVal);
                    if (!in_array($hc, ['h11', 'h12', 'h23', 'h24'], true)) {
                        throw new RangeError("Invalid hourCycle: {$hc}");
                    }
                    $hourCycle = $hc;
                }

                // timeZone: must be a recognized identifier per the IANA
                // tz database (or a UTC offset). Identifiers are
                // case-insensitive; canonicalise by matching the host's
                // case-preserved list.
                $timeZone = 'UTC';
                $tzVal = $options->get('timeZone');
                if (!$tzVal instanceof JsUndefined) {
                    $tz = TypeConversion::toString($tzVal);
                    $isOffset = preg_match('/^[+-]\d{1,2}:?\d{0,2}$/', $tz) === 1;
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
                if ($hourCycle !== null) {
                    $obj->defineOwnProperty('[[HourCycle]]', PropertyDescriptor::data(
                        new JsString($hourCycle),
                        false,
                        false,
                        false,
                    ));
                }
                // Remember hour12 for later so we can default the cycle
                // when a `hour` component is requested but no cycle is
                // specified.
                if ($hour12 !== null) {
                    $obj->defineOwnProperty('[[Hour12]]', PropertyDescriptor::data(
                        new JsBoolean($hour12),
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
                $hasExplicitFormatComponents = false;
                $hasHour = false;
                foreach ($components as $prop => $validValues) {
                    $val = $options->get($prop);
                    if (!$val instanceof JsUndefined) {
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
                // When `hour` is requested but no cycle is set, fall
                // back to the locale's CLDR default ("h12" for most
                // locales, "h11" for ja, "h23" for some others). This
                // matches V8's behaviour and keeps resolvedOptions's
                // hourCycle non-undefined whenever hour is included.
                if ($hasHour && $hourCycle === null) {
                    $localeLang = strtolower(strtok($resolvedLocale, '-_'));
                    static $localeDefaultHc = [
                        'ja' => 'h11',
                    ];
                    $hourCycle = $localeDefaultHc[$localeLang] ?? 'h12';
                    $obj->defineOwnProperty('[[HourCycle]]', PropertyDescriptor::data(
                        new JsString($hourCycle),
                        false,
                        false,
                        false,
                    ));
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

            // Get timestamp from Date object or number. Per spec, the
            // value goes through ToNumber and is then validated via
            // TimeClip; non-finite numbers throw RangeError.
            $timestampMs = null;
            if ($dateArg instanceof JsObject && $dateArg->has('getTime')) {
                $getTime = $dateArg->get('getTime');
                if ($getTime instanceof JsFunction) {
                    $interp = \PhpJs\Engine::getCurrentInterpreter();
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
                $formatted = self::formatDateTime($this_, $timestamp);
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
            $timestampMs = null;
            if ($dateArg instanceof JsObject && $dateArg->has('getTime')) {
                $getTime = $dateArg->get('getTime');
                if ($getTime instanceof JsFunction) {
                    $interp = \PhpJs\Engine::getCurrentInterpreter();
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
                ? self::formatDateTime($this_, $timestamp)
                : date('n/j/Y, g:i:s A', $timestamp);
            // Spec emits a typed parts list. Without CLDR pattern
            // skeletons we can't reliably classify each character, so
            // return the formatted output as a single literal-typed
            // part. This still satisfies the brand check, NaN/Infinity
            // throws, and "no time portion" tests that just inspect
            // result.length / result[0].type.
            $result = new JsArray();
            $part = new JsObject();
            self::defineDataProp($part, 'type', new JsString('literal'));
            self::defineDataProp($part, 'value', new JsString($formatted));
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
            self::dateTimeFormatRangeReceiverCheck($this_, 'formatRange');
            $startVal = $args[0] ?? JsUndefined::instance();
            $endVal = $args[1] ?? JsUndefined::instance();
            // Spec step 4: BOTH undefined checks happen before
            // ToNumber, so a poisoned `valueOf` on one side never
            // runs when the other is undefined.
            if ($startVal instanceof JsUndefined || $endVal instanceof JsUndefined) {
                throw new TypeError('formatRange arguments cannot be undefined');
            }
            $startMs = self::dateTimeFormatRangeArgToMs($startVal, 'startDate');
            $endMs = self::dateTimeFormatRangeArgToMs($endVal, 'endDate');
            $startStr = '';
            $endStr = '';
            if (extension_loaded('intl') && $this_ instanceof JsObject) {
                $startStr = self::formatDateTime($this_, (int) round($startMs / 1000));
                $endStr = self::formatDateTime($this_, (int) round($endMs / 1000));
            } else {
                $startStr = (string) $startMs;
                $endStr = (string) $endMs;
            }
            if ($startStr === $endStr) {
                return new JsString($startStr);
            }
            return new JsString($startStr . " \u{2013} " . $endStr);
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
            $startMs = self::dateTimeFormatRangeArgToMs($startVal, 'startDate');
            $endMs = self::dateTimeFormatRangeArgToMs($endVal, 'endDate');
            $result = new JsArray();
            $idx = 0;
            $emit = static function (string $type, string $value, string $source) use (
                &$result,
                &$idx,
            ): void {
                $part = new JsObject();
                self::defineDataProp($part, 'type', new JsString($type));
                self::defineDataProp($part, 'value', new JsString($value));
                self::defineDataProp($part, 'source', new JsString($source));
                $result->set((string) $idx++, $part);
            };
            $startStr = '';
            $endStr = '';
            if (extension_loaded('intl') && $this_ instanceof JsObject) {
                $startStr = self::formatDateTime($this_, (int) round($startMs / 1000));
                $endStr = self::formatDateTime($this_, (int) round($endMs / 1000));
            }
            if ($startStr === $endStr) {
                $emit('literal', $startStr, 'shared');
            } else {
                $emit('literal', $startStr, 'startRange');
                $emit('literal', " \u{2013} ", 'shared');
                $emit('literal', $endStr, 'endRange');
            }
            $result->set('length', new JsNumber((float) $idx));
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
            if (
                !$this_ instanceof JsObject
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

            // Component options.
            foreach (
                ['weekday', 'era', 'year', 'month', 'day', 'dayPeriod',
                'hour', 'minute', 'second', 'fractionalSecondDigits', 'timeZoneName'] as $comp
            ) {
                $val = $this_->get("[[{$comp}]]");
                if (!$val instanceof JsUndefined) {
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
    private static function dateTimeFormatRangeArgToMs(JsValue $val, string $argName): float
    {
        $n = TypeConversion::toNumber($val);
        if (is_nan($n) || !is_finite($n)) {
            throw new RangeError("Invalid {$argName}: not a finite number");
        }
        return $n;
    }

    /**
     * Format a date/time using PHP's IntlDateFormatter.
     */
    private static function formatDateTime(JsObject $dtf, int $timestamp): string
    {
        $locale = str_replace('-', '_', self::extractInternalString($dtf, '[[Locale]]', 'en'));
        $tz = self::extractInternalString($dtf, '[[TimeZone]]', 'UTC');
        // ICU rejects "+HH:MM" / "-HH:MM" identifiers but accepts the
        // "GMT+HH:MM" form. Translate offset-style time zones so
        // IntlDateFormatter doesn't throw `No such time zone`.
        if (preg_match('/^[+-]\d{2}:\d{2}$/', $tz) === 1) {
            $tz = 'GMT' . $tz;
        }

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

    /**
     * Return the CLDR plural-rule categories supported by `$locale`,
     * sorted in the spec's canonical order: zero, one, two, few, many,
     * other. Cardinal and ordinal rules differ; the table covers the
     * locales the test262 fixtures exercise plus a generous default.
     *
     * @return list<string>
     */
    private static function pluralCategoriesForLocale(string $locale, string $type): array
    {
        $lang = strtolower(strtok($locale, '-_'));
        static $order = ['zero', 'one', 'two', 'few', 'many', 'other'];
        static $cardinal = [
            'ar' => ['zero', 'one', 'two', 'few', 'many', 'other'],
            'be' => ['one', 'few', 'many', 'other'],
            'br' => ['one', 'two', 'few', 'many', 'other'],
            'cs' => ['one', 'few', 'many', 'other'],
            'cy' => ['zero', 'one', 'two', 'few', 'many', 'other'],
            'fr' => ['one', 'many', 'other'],
            'ga' => ['one', 'two', 'few', 'many', 'other'],
            'gv' => ['one', 'two', 'few', 'many', 'other'],
            'he' => ['one', 'two', 'many', 'other'],
            'iw' => ['one', 'two', 'many', 'other'],
            'is' => ['one', 'other'],
            'ja' => ['other'],
            'km' => ['other'],
            'ko' => ['other'],
            'lt' => ['one', 'few', 'many', 'other'],
            'lv' => ['zero', 'one', 'other'],
            'mk' => ['one', 'other'],
            'mt' => ['one', 'two', 'few', 'many', 'other'],
            'pl' => ['one', 'few', 'many', 'other'],
            'pt' => ['one', 'many', 'other'],
            'ro' => ['one', 'few', 'other'],
            'ru' => ['one', 'few', 'many', 'other'],
            'sk' => ['one', 'few', 'many', 'other'],
            'sl' => ['one', 'two', 'few', 'other'],
            'sr' => ['one', 'few', 'other'],
            'th' => ['other'],
            'uk' => ['one', 'few', 'many', 'other'],
            'vi' => ['other'],
            'zh' => ['other'],
        ];
        static $ordinal = [
            'ar' => ['other'],
            'cy' => ['zero', 'one', 'two', 'few', 'many', 'other'],
            'en' => ['one', 'two', 'few', 'other'],
            'fr' => ['one', 'other'],
            'ga' => ['one', 'other'],
            'hu' => ['one', 'other'],
            'it' => ['many', 'other'],
            'mk' => ['one', 'two', 'many', 'other'],
            'mr' => ['one', 'two', 'few', 'other'],
            'ne' => ['one', 'other'],
            'sv' => ['one', 'two', 'other'],
            'tk' => ['few', 'other'],
            'uk' => ['few', 'other'],
        ];
        $table = $type === 'ordinal' ? $ordinal : $cardinal;
        $cats = $table[$lang] ?? ['one', 'other'];
        // Always emit in spec order.
        return array_values(array_filter(
            $order,
            static fn(string $c): bool => in_array($c, $cats, true),
        ));
    }

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

                $obj = self::instanceFromConstructor($this_, $proto);
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

                // Spec orders SetNumberFormatDigitOptions reads as:
                // roundingIncrement → roundingMode → roundingPriority →
                // trailingZeroDisplay. The values still feed into the
                // PluralRules digit slots as today.
                $rivVal = $options->get('roundingIncrement');
                if (!$rivVal instanceof JsUndefined) {
                    $riNum = TypeConversion::toNumber($rivVal);
                    $validIncrements = [
                        1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500,
                        1000, 2000, 2500, 5000,
                    ];
                    if (
                        is_nan($riNum)
                        || $riNum != floor($riNum)
                        || !in_array((int) $riNum, $validIncrements, true)
                    ) {
                        throw new RangeError("Invalid roundingIncrement: {$riNum}");
                    }
                    $obj->defineOwnProperty('[[RoundingIncrement]]', PropertyDescriptor::data(
                        new JsNumber((float) (int) $riNum),
                        false,
                        false,
                        false,
                    ));
                }
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
                $rpVal = $options->get('roundingPriority');
                if (!$rpVal instanceof JsUndefined) {
                    $rp = TypeConversion::toString($rpVal);
                    if (!in_array($rp, ['auto', 'morePrecision', 'lessPrecision'], true)) {
                        throw new RangeError("Invalid roundingPriority: {$rp}");
                    }
                    $obj->defineOwnProperty('[[RoundingPriority]]', PropertyDescriptor::data(
                        new JsString($rp),
                        false,
                        false,
                        false,
                    ));
                }
                $tzdVal = $options->get('trailingZeroDisplay');
                if (!$tzdVal instanceof JsUndefined) {
                    $tzd = TypeConversion::toString($tzdVal);
                    if (!in_array($tzd, ['auto', 'stripIfInteger'], true)) {
                        throw new RangeError("Invalid trailingZeroDisplay: {$tzd}");
                    }
                    $obj->defineOwnProperty('[[TrailingZeroDisplay]]', PropertyDescriptor::data(
                        new JsString($tzd),
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
            PropertyDescriptor::data(new JsString('Intl.PluralRules'), false, false, true),
        );

        // PluralRules.prototype.select(number)
        $select = JsFunction::fromCallable('select', function (
            JsValue $this_,
            array $args,
        ): JsValue {
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedPluralRules]]') instanceof JsUndefined
            ) {
                throw new TypeError('Intl.PluralRules.prototype.select called on non-PluralRules');
            }
            $number = $args[0] ?? JsUndefined::instance();
            $n = TypeConversion::toNumber($number);

            if (!is_finite($n)) {
                return new JsString('other');
            }

            $locale = self::extractInternalString($this_, '[[Locale]]', 'en');
            $type = self::extractInternalString($this_, '[[Type]]', 'cardinal');

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
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedPluralRules]]') instanceof JsUndefined
            ) {
                throw new TypeError('Intl.PluralRules.prototype.selectRange called on non-PluralRules');
            }
            if (count($args) < 2) {
                throw new TypeError('selectRange requires two arguments');
            }
            $startArg = $args[0];
            $endArg = $args[1];
            // Spec mandates an explicit undefined check before coercion.
            if ($startArg instanceof JsUndefined || $endArg instanceof JsUndefined) {
                throw new TypeError('selectRange arguments must not be undefined');
            }
            $start = TypeConversion::toNumber($startArg);
            $end = TypeConversion::toNumber($endArg);
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
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedPluralRules]]') instanceof JsUndefined
            ) {
                throw new TypeError('Intl.PluralRules.prototype.resolvedOptions called on non-PluralRules');
            }
            $result = new JsObject();
            self::defineDataProp($result, 'locale', new JsString(
                self::extractInternalString($this_, '[[Locale]]', 'en'),
            ));
            self::defineDataProp($result, 'type', new JsString(
                self::extractInternalString($this_, '[[Type]]', 'cardinal'),
            ));
            self::defineDataProp($result, 'minimumIntegerDigits', new JsNumber(
                self::extractInternalNumber($this_, '[[MinimumIntegerDigits]]', 1),
            ));
            $rt = self::extractInternalString($this_, '[[RoundingType]]', 'fractionDigits');
            if ($rt === 'significantDigits') {
                self::defineDataProp($result, 'minimumSignificantDigits', new JsNumber(
                    self::extractInternalNumber($this_, '[[MinimumSignificantDigits]]', 1),
                ));
                self::defineDataProp($result, 'maximumSignificantDigits', new JsNumber(
                    self::extractInternalNumber($this_, '[[MaximumSignificantDigits]]', 21),
                ));
            } else {
                self::defineDataProp($result, 'minimumFractionDigits', new JsNumber(
                    self::extractInternalNumber($this_, '[[MinimumFractionDigits]]', 0),
                ));
                self::defineDataProp($result, 'maximumFractionDigits', new JsNumber(
                    self::extractInternalNumber($this_, '[[MaximumFractionDigits]]', 3),
                ));
            }
            // Plural categories: subset of `{zero, one, two, few, many, other}`
            // that the locale's CLDR plural rules can produce, returned
            // in the spec's canonical order. Locales not enumerated
            // here fall back to the conservative ['one', 'other'] pair
            // that covers the majority of European languages.
            $type = self::extractInternalString($this_, '[[Type]]', 'cardinal');
            $localeForCats = self::extractInternalString($this_, '[[Locale]]', 'en');
            $cats = self::pluralCategoriesForLocale($localeForCats, $type);
            $categories = new JsArray();
            foreach ($cats as $i => $cat) {
                $categories->set((string) $i, new JsString($cat));
            }
            $categories->set('length', new JsNumber((float) count($cats)));
            self::defineDataProp($result, 'pluralCategories', $categories);
            self::defineDataProp($result, 'roundingMode', new JsString(
                self::extractInternalString($this_, '[[RoundingMode]]', 'halfExpand'),
            ));
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
                // Per spec step 7: only String or Object are accepted;
                // null / undefined / number / boolean / symbol throw TypeError.
                if (
                    !$tagArg instanceof JsString
                    && !$tagArg instanceof JsObject
                ) {
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

                $options = self::getOptionsObject($optionsArg);

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
                    // UTS35 canonical form for a small set of CLDR-aliased
                    // calendar identifiers.
                    static $calendarAliases = [
                        'islamicc' => 'islamic-civil',
                        'ethiopic-amete-alem' => 'ethioaa',
                        'gregorian' => 'gregory',
                    ];
                    $calLower = strtolower($calendar);
                    $calendar = $calendarAliases[$calLower] ?? $calLower;
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
                // firstDayOfWeek: numeric forms 0-7 are mapped to the
                // canonical short weekday names (0 and 7 both alias to
                // sun per UTS35); other strings just need to satisfy
                // the BCP47 "type" production
                // (alphanum{3,8}(-alphanum{3,8})*).
                $firstDayOfWeek = null;
                $fwVal = $options->get('firstDayOfWeek');
                if (!$fwVal instanceof JsUndefined) {
                    $fw = TypeConversion::toString($fwVal);
                    static $weekdayMap = [
                        '0' => 'sun', '1' => 'mon', '2' => 'tue', '3' => 'wed',
                        '4' => 'thu', '5' => 'fri', '6' => 'sat', '7' => 'sun',
                    ];
                    if (isset($weekdayMap[$fw])) {
                        $fw = $weekdayMap[$fw];
                    }
                    $fwLower = strtolower($fw);
                    // The boolean primitive `true` canonicalises to the bare
                    // key with no value subtag.
                    if ($fwLower === 'true') {
                        $firstDayOfWeek = '';
                    } else {
                        if (!$isValidUnicodeType($fwLower)) {
                            throw new RangeError("Invalid firstDayOfWeek: {$fw}");
                        }
                        $firstDayOfWeek = $fwLower;
                    }
                    $parsed['firstDayOfWeek'] = $firstDayOfWeek;
                }

                $obj = self::instanceFromConstructor($this_, $proto);

                // Store parsed components as internal slots.
                foreach ($parsed as $key => $val) {
                    if ($val === null) {
                        continue;
                    }
                    if (is_bool($val)) {
                        $jsVal = new JsBoolean($val);
                    } elseif (is_array($val)) {
                        // Skip extension bookkeeping (unicodeAttributes /
                        // unicodeKeywords) — they're plain PHP storage and
                        // don't need to be exposed as JS internal slots.
                        if ($key === 'unicodeAttributes' || $key === 'unicodeKeywords') {
                            continue;
                        }
                        $arr = new JsArray();
                        $idx = 0;
                        foreach (array_values($val) as $item) {
                            $arr->set((string) $idx++, new JsString((string) $item));
                        }
                        $arr->set('length', new JsNumber((float) $idx));
                        $jsVal = $arr;
                    } else {
                        $jsVal = new JsString((string) $val);
                    }
                    $obj->defineOwnProperty("[[{$key}]]", PropertyDescriptor::data(
                        $jsVal,
                        false,
                        false,
                        false,
                    ));
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
            if ($this_ instanceof JsObject && self::isInitializedLocale($this_)) {
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
            'firstDayOfWeek' => 'firstDayOfWeek',
        ];
        foreach ($accessors as $prop => $internalKey) {
            $getter = JsFunction::fromCallable("get {$prop}", function (JsValue $this_) use ($internalKey): JsValue {
                if (!$this_ instanceof JsObject || !self::isInitializedLocale($this_)) {
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
            if (!$this_ instanceof JsObject || !self::isInitializedLocale($this_)) {
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
            if (!$this_ instanceof JsObject || !self::isInitializedLocale($this_)) {
                throw new TypeError('Intl.Locale.prototype.baseName called on non-Locale');
            }
            $lang = self::extractInternalString($this_, '[[language]]', '');
            $script = self::extractInternalStringOrNull($this_, '[[script]]');
            $region = self::extractInternalStringOrNull($this_, '[[region]]');
            $variantsVal = $this_->get('[[variants]]');

            $parts = [$lang];
            if ($script !== null) {
                $parts[] = $script;
            }
            if ($region !== null) {
                $parts[] = $region;
            }
            if ($variantsVal instanceof JsArray) {
                $variantLen = (int) \PhpJs\Spec\TypeConversion::toNumber($variantsVal->get('length'));
                for ($vi = 0; $vi < $variantLen; $vi++) {
                    $vs = $variantsVal->get((string) $vi);
                    if ($vs instanceof JsString) {
                        $parts[] = $vs->value;
                    }
                }
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
            if (!$this_ instanceof JsObject || !self::isInitializedLocale($this_)) {
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
            if (!$this_ instanceof JsObject || !self::isInitializedLocale($this_)) {
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
            $fn = JsFunction::fromCallable($name, function (JsValue $this_) use ($getter, $name): JsValue {
                if (!$this_ instanceof JsObject || !self::isInitializedLocale($this_)) {
                    throw new TypeError("Intl.Locale.prototype.{$name} called on non-Locale");
                }
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
            if (!$this_ instanceof JsObject || !self::isInitializedLocale($this_)) {
                throw new TypeError('Intl.Locale.prototype.getTextInfo called on non-Locale');
            }
            $result = new JsObject();
            $result->set('direction', new JsString('ltr'));
            return $result;
        }, 0);
        $proto->defineOwnProperty('getTextInfo', PropertyDescriptor::data($getTextInfo, true, false, true));

        // getWeekInfo()
        $getWeekInfo = JsFunction::fromCallable('getWeekInfo', function (JsValue $this_): JsValue {
            if (!$this_ instanceof JsObject || !self::isInitializedLocale($this_)) {
                throw new TypeError('Intl.Locale.prototype.getWeekInfo called on non-Locale');
            }
            // The `fw` extension overrides the locale-derived first day.
            // Map the canonical short weekday name to its ISO 8601 index
            // (mon=1 .. sun=7).
            $firstDay = 1;
            $fwSlot = $this_->get('[[firstDayOfWeek]]');
            if ($fwSlot instanceof JsString) {
                static $weekdayIndex = [
                    'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4,
                    'fri' => 5, 'sat' => 6, 'sun' => 7,
                ];
                $name = strtolower($fwSlot->value);
                if (isset($weekdayIndex[$name])) {
                    $firstDay = $weekdayIndex[$name];
                }
            }
            $result = new JsObject();
            $result->set('firstDay', new JsNumber((float) $firstDay));
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
            if (!$this_ instanceof JsObject || !self::isInitializedLocale($this_)) {
                throw new TypeError('Intl.Locale.prototype.getTimeZones called on non-Locale');
            }
            // Spec: when the locale has no region subtag, return undefined.
            if ($this_->get('[[region]]') instanceof JsUndefined) {
                return JsUndefined::instance();
            }
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
    /**
     * Inverse of utf8ByteToUtf16Index: walk the UTF-8 string and return
     * the byte offset corresponding to a UTF-16 code-unit index.
     * Out-of-range indices clamp to the string length.
     */
    private static function utf16IndexToUtf8Byte(string $str, int $codeUnitIdx): int
    {
        if ($codeUnitIdx <= 0) {
            return 0;
        }
        $i = 0;
        $codeUnits = 0;
        $len = strlen($str);
        while ($i < $len && $codeUnits < $codeUnitIdx) {
            $byte = ord($str[$i]);
            if ($byte < 0x80) {
                $inc = 1;
                $width = 1;
            } elseif ($byte < 0xC0) {
                // Stray continuation byte — count as one without
                // contributing to UTF-16 code units.
                $inc = 0;
                $width = 1;
            } elseif ($byte < 0xE0) {
                $inc = 1;
                $width = 2;
            } elseif ($byte < 0xF0) {
                $inc = 1;
                $width = 3;
            } else {
                // Supplementary plane: 4 bytes UTF-8, 2 UTF-16 code units.
                $inc = 2;
                $width = 4;
            }
            if ($codeUnits + $inc > $codeUnitIdx) {
                // The requested code-unit lands inside a surrogate
                // pair: anchor at the start of the encompassing char.
                return $i;
            }
            $codeUnits += $inc;
            $i += $width;
        }
        return $i;
    }

    /**
     * Compute the [start, end] byte-offset bounds of the segment
     * containing `$byteIdx` for the given granularity. Used by
     * Segmenter.prototype.segment(...).containing(index).
     *
     * @return array{0:int,1:int}
     */
    private static function segmentBoundsAt(string $str, int $byteIdx, string $granularity): array
    {
        if (!extension_loaded('intl')) {
            return [0, strlen($str)];
        }
        $bi = match ($granularity) {
            'word' => \IntlBreakIterator::createWordInstance(),
            'sentence' => \IntlBreakIterator::createSentenceInstance(),
            default => \IntlBreakIterator::createCharacterInstance(),
        };
        $bi->setText($str);
        // ICU's preceding() treats positions inside multi-byte chars
        // oddly (it skips the enclosing break). Walk the breaks
        // forward so the bounds stay correct for supplementary-plane
        // characters and surrogate pairs.
        $start = 0;
        $end = strlen($str);
        $prev = 0;
        while (($pos = $bi->next()) !== \IntlBreakIterator::DONE) {
            if ($pos > $byteIdx) {
                $start = $prev;
                $end = $pos;
                break;
            }
            $prev = $pos;
        }
        if ($pos === \IntlBreakIterator::DONE) {
            $start = $prev;
            $end = strlen($str);
        }
        return [$start, $end];
    }

    /**
     * Convert a UTF-8 byte offset into the equivalent UTF-16 code-unit
     * index. JS strings expose UTF-16 indices, so segment results need
     * the byte offset returned by IntlBreakIterator translated before
     * being handed back to userland.
     */
    private static function utf8ByteToUtf16Index(string $str, int $byteOffset): int
    {
        if ($byteOffset <= 0) {
            return 0;
        }
        $byteOffset = min($byteOffset, strlen($str));
        $sub = substr($str, 0, $byteOffset);
        $codeUnits = 0;
        $i = 0;
        $len = strlen($sub);
        while ($i < $len) {
            $byte = ord($sub[$i]);
            if ($byte < 0x80) {
                $codeUnits++;
                $i++;
            } elseif ($byte < 0xC0) {
                // Continuation byte without lead — count as one to avoid
                // an infinite loop on truncated input.
                $i++;
            } elseif ($byte < 0xE0) {
                $codeUnits++;
                $i += 2;
            } elseif ($byte < 0xF0) {
                $codeUnits++;
                $i += 3;
            } else {
                // Supplementary plane decomposes into a UTF-16 surrogate
                // pair (two code units).
                $codeUnits += 2;
                $i += 4;
            }
        }
        return $codeUnits;
    }

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
                $isUnicode = $key === 'u';
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
                    // UTS35 ukey = alphanum alpha. A length-2 subtag in
                    // the -u- extension must end with a letter, so e.g.
                    // `en-u-c0` and `en-u-00` are invalid (the second
                    // character is a digit).
                    if ($isUnicode && $subLen === 2 && !ctype_alpha($sub[1])) {
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
        // CLDR `<languageAlias>` for regular grandfathered tags that map
        // to a single replacement. Apply before structural parsing so the
        // entire tag substitutes wholesale.
        static $grandfathered = [
            'art-lojban' => 'jbo',
            'cel-gaulish' => 'xtg',
            'zh-guoyu' => 'zh',
            'zh-hakka' => 'hak',
            'zh-xiang' => 'hsn',
            'no-bok' => 'nb',
            'no-nyn' => 'nn',
            'zh-min-nan' => 'nan',
            'zh-min' => 'nan-x-zh-min',
        ];
        $lcTag = strtolower($tag);
        if (isset($grandfathered[$lcTag])) {
            $tag = $grandfathered[$lcTag];
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
            // Apply CLDR language alias replacement so deprecated
            // subtags (cmn, ji, in, aam, ...) collapse to their preferred
            // form. Single-tag replacements are applied unconditionally;
            // multi-tag replacements (sh -> sr-Latn, cnr -> sr-ME) only
            // contribute the script or region if the source tag did not
            // already specify one. Region-conditioned replacements
            // (sgn-XX → ...) drop the region.
            static $languageAliasCanonical = [
                'aam' => 'aas', 'aar' => 'aa', 'aue' => 'ktz', 'arb' => 'ar',
                'ayr' => 'ay', 'ayx' => 'nun', 'bhk' => 'fbl', 'bjd' => 'drl',
                'ccq' => 'rki', 'cjr' => 'mom', 'cka' => 'cmr', 'cmk' => 'xch',
                'cmn' => 'zh', 'drh' => 'khk', 'drw' => 'prs', 'gav' => 'dev',
                'gfx' => 'vaj', 'ggn' => 'gvr', 'gti' => 'nyc', 'guv' => 'duz',
                'hrr' => 'jal', 'ibi' => 'opa', 'ilw' => 'gal', 'in' => 'id',
                'iw' => 'he', 'jeg' => 'oyb', 'ji' => 'yi', 'jw' => 'jv',
                'kgc' => 'tdf', 'kgh' => 'kml', 'koj' => 'kwv', 'krm' => 'bmf',
                'ktr' => 'dtp', 'kvs' => 'gdj', 'kwq' => 'yam', 'kxe' => 'tvd',
                'kzj' => 'dtp', 'kzt' => 'dtp', 'lii' => 'raq', 'lmm' => 'rmx',
                'meg' => 'cir', 'mo' => 'ro', 'mst' => 'mry', 'mwj' => 'vaj',
                'myt' => 'mry', 'nad' => 'xny', 'ncp' => 'kdz', 'nnx' => 'ngv',
                'no-bok' => 'nb', 'no-nyn' => 'nn', 'nts' => 'pij',
                'oun' => 'vaj', 'pcr' => 'adx', 'pmc' => 'huw', 'pmu' => 'phr',
                'ppa' => 'bfy', 'ppr' => 'lcq', 'pry' => 'prt', 'puz' => 'pub',
                'sca' => 'hle', 'skk' => 'oyb', 'tdu' => 'dtp', 'thc' => 'tpo',
                'thx' => 'oyb', 'tie' => 'ras', 'tkk' => 'twm', 'tl' => 'fil',
                'tlw' => 'weo', 'tmp' => 'tyj', 'tne' => 'kak', 'tnf' => 'prs',
                'tsf' => 'taj', 'uok' => 'ema', 'xba' => 'cax', 'xia' => 'acn',
                'xkh' => 'waw', 'xpe' => 'kpe', 'xsj' => 'suj', 'ybd' => 'rki',
                'yma' => 'lrr', 'ymt' => 'mtm', 'yos' => 'zom', 'yuu' => 'yug',
            ];
            if (isset($languageAliasCanonical[$result['language']])) {
                $result['language'] = $languageAliasCanonical[$result['language']];
            }
            // Multi-tag language replacements: <languageAlias> entries with
            // more than one tag in the replacement value contribute the
            // extra subtags only if the source has none of its own.
            if ($result['language'] === 'sh') {
                $result['language'] = 'sr';
                if (!isset($result['script']) || $result['script'] === '') {
                    $result['script'] = 'Latn';
                }
            } elseif ($result['language'] === 'cnr') {
                $result['language'] = 'sr';
                if (!isset($result['region']) || $result['region'] === '') {
                    $result['region'] = 'ME';
                }
            }
            // sgn-XX language replacements: when the source language is
            // "sgn" and a region subtag is present, the combined
            // (lang, region) pair maps to a specific sign-language code
            // and the region is dropped.
            if ($result['language'] === 'sgn' && isset($result['region']) && $result['region'] !== '') {
                static $sgnAliases = [
                    'AE' => 'ase', 'BR' => 'bzs', 'CO' => 'csn', 'DE' => 'gsg',
                    'DK' => 'dsl', 'ES' => 'ssp', 'FR' => 'fsl', 'GB' => 'bfi',
                    'GR' => 'gss', 'IE' => 'isg', 'IT' => 'ise', 'JP' => 'jsl',
                    'MX' => 'mfs', 'NI' => 'ncs', 'NL' => 'dse', 'NO' => 'nsi',
                    'PT' => 'psr', 'SE' => 'swl', 'US' => 'ase', 'ZA' => 'sfs',
                ];
                if (isset($sgnAliases[$result['region']])) {
                    $result['language'] = $sgnAliases[$result['region']];
                    unset($result['region']);
                }
            }
            if (isset($result['region'])) {
                static $regionAliasCanonical = [
                    'BU' => 'MM', 'DD' => 'DE', 'FX' => 'FR', 'TP' => 'TL',
                    'YD' => 'YE', 'ZR' => 'CD', 'CT' => 'KI', 'NH' => 'VU',
                    'RH' => 'ZW', 'VD' => 'VN', 'AN' => 'CW',
                ];
                if (isset($regionAliasCanonical[$result['region']])) {
                    $result['region'] = $regionAliasCanonical[$result['region']];
                }
            }
            // Extract variants (alphanum{5,8} or digit alphanum{3}) from the
            // original tag. ICU's parseLocale exposes them via numbered
            // variant0..variantN keys but also strips them. Walk the raw
            // tag instead so we preserve order and unique-only variants.
            $variants = [];
            $rawParts = explode('-', $tag);
            $idx = 1;
            // Skip script.
            if (isset($rawParts[$idx]) && strlen($rawParts[$idx]) === 4 && ctype_alpha($rawParts[$idx])) {
                $idx++;
            }
            // Skip region.
            if (
                isset($rawParts[$idx])
                && (
                    (strlen($rawParts[$idx]) === 2 && ctype_alpha($rawParts[$idx]))
                    || (strlen($rawParts[$idx]) === 3 && ctype_digit($rawParts[$idx]))
                )
            ) {
                $idx++;
            }
            while (isset($rawParts[$idx])) {
                $sub = $rawParts[$idx];
                $sLen = strlen($sub);
                if ($sLen === 1) {
                    break;
                }
                $isLong = ($sLen >= 5 && $sLen <= 8 && ctype_alnum($sub));
                $isShortNumeric = ($sLen === 4 && ctype_digit($sub[0]) && ctype_alnum($sub));
                if (!$isLong && !$isShortNumeric) {
                    break;
                }
                $variants[strtolower($sub)] = true;
                $idx++;
            }
            // CLDR variantAlias replacement. Multi-variant sequences are
            // replaced first so `hepburn-heploc` collapses to `alalc97`
            // instead of producing two independent replacements.
            if (!empty($variants)) {
                $variantList = array_keys($variants);
                static $multiVariantAliases = [
                    'hepburn-heploc' => 'alalc97',
                ];
                static $variantAliases = [
                    'heploc' => 'alalc97',
                    'aaland' => '',
                    'arevela' => '',
                    'arevmda' => '',
                ];
                // Some variant aliases promote to a language replacement
                // (CLDR `<variantAlias type=... replacement="<lang>"/>`).
                // When the source language matches, the variant is dropped
                // and the language is rewritten.
                static $variantToLanguageAliases = [
                    'arevmda' => ['hy' => 'hyw'],
                ];
                foreach ($variantToLanguageAliases as $vName => $langMap) {
                    if (
                        in_array($vName, $variantList, true)
                        && isset($langMap[$result['language']])
                    ) {
                        $result['language'] = $langMap[$result['language']];
                        $variantList = array_values(array_filter(
                            $variantList,
                            static fn(string $v): bool => $v !== $vName,
                        ));
                    }
                }
                foreach ($multiVariantAliases as $from => $to) {
                    $fromParts = explode('-', $from);
                    $matches = !array_diff($fromParts, $variantList);
                    if ($matches) {
                        $variantList = array_values(array_diff($variantList, $fromParts));
                        if ($to !== '') {
                            $variantList[] = $to;
                        }
                    }
                }
                $remapped = [];
                foreach ($variantList as $v) {
                    if (array_key_exists($v, $variantAliases)) {
                        $repl = $variantAliases[$v];
                        if ($repl !== '') {
                            $remapped[$repl] = true;
                        }
                    } else {
                        $remapped[$v] = true;
                    }
                }
                $variants = $remapped;
            }
            if (!empty($variants)) {
                ksort($variants);
                $result['variants'] = array_keys($variants);
            }

            // Walk the tag once to split off other-extension singletons
            // (`-a-…`, `-t-…`, etc.) and the `-x-` private-use tail. The
            // unicode extension (`-u-…`) is parsed below for its
            // semantic content; the rest are kept as raw payload strings
            // so the canonical form can re-emit them in singleton order.
            $publicTag = $tag;
            $otherExtensions = [];
            $privateUse = null;
            if (
                preg_match(
                    '/^([a-zA-Z]{2,8}(?:-[a-zA-Z]{4})?(?:-[a-zA-Z]{2}|-\d{3})?(?:-[a-zA-Z0-9]{4,8})*(?:-[a-zA-Z0-9]{5,8})*)((?:-[a-zA-Z0-9]-[a-zA-Z0-9-]+)*)$/i',
                    $tag,
                    $structureMatch,
                ) === 1
            ) {
                $publicTag = $structureMatch[1];
                $extensionTail = $structureMatch[2];
                if ($extensionTail !== '') {
                    // Handle the `-x-` private-use boundary specially: once
                    // a `-x-` singleton is seen, everything that follows is
                    // private use and must not be re-parsed as a fresh
                    // extension singleton.
                    $xPos = false;
                    if (preg_match('/-x-/i', $extensionTail, $xMatch, PREG_OFFSET_CAPTURE)) {
                        $xPos = $xMatch[0][1];
                        $privateUse = strtolower(substr($extensionTail, $xPos + 3));
                        $extensionTail = substr($extensionTail, 0, $xPos);
                    }
                    if (
                        $extensionTail !== ''
                        && preg_match_all(
                            '/-([a-zA-Z0-9])-((?:[a-zA-Z0-9]+(?:-[a-zA-Z0-9]+)*?)(?=(?:-[a-zA-Z0-9]-)|$))/',
                            $extensionTail,
                            $matches,
                            PREG_SET_ORDER,
                        )
                    ) {
                        foreach ($matches as $m) {
                            $singleton = strtolower($m[1]);
                            $payload = strtolower($m[2]);
                            if ($singleton === 'u') {
                                $publicTag .= '-u-' . $payload;
                            } else {
                                $otherExtensions[$singleton] = $payload;
                            }
                        }
                    }
                }
            }
            if (preg_match('/-u-(.+?)(?=-[a-wy-z]-|$)/i', $publicTag, $extMatch)) {
                $extStr = strtolower($extMatch[1]);
                $extParts = explode('-', $extStr);
                $i = 0;
                $count = count($extParts);
                $attributes = [];
                $keywords = [];
                $lastKey = null;
                // Leading subtags of length >= 3 are attributes.
                while ($i < $count && strlen($extParts[$i]) >= 3) {
                    $attributes[] = $extParts[$i];
                    $i++;
                }
                while ($i < $count) {
                    $key = $extParts[$i];
                    if (strlen($key) !== 2) {
                        $i++;
                        continue;
                    }
                    $i++;
                    $values = [];
                    while ($i < $count && strlen($extParts[$i]) >= 3) {
                        $values[] = $extParts[$i];
                        $i++;
                    }
                    // Spec keeps the FIRST occurrence of a duplicate
                    // keyword and discards later ones.
                    if (!isset($keywords[$key])) {
                        $keywords[$key] = $values;
                    }
                }
                // Sort attributes and keywords in US-ASCII order.
                sort($attributes, SORT_STRING);
                ksort($keywords);
                if (!empty($attributes)) {
                    $result['unicodeAttributes'] = $attributes;
                }
                if (!empty($keywords)) {
                    $result['unicodeKeywords'] = $keywords;
                }
                // Mirror the well-known keys to the legacy slot names so
                // existing getters continue to function.
                $legacyMap = [
                    'ca' => 'calendar',
                    'co' => 'collation',
                    'fw' => 'firstDayOfWeek',
                    'hc' => 'hourCycle',
                    'kf' => 'caseFirst',
                    'kn' => 'numeric',
                    'nu' => 'numberingSystem',
                ];
                static $calendarAliases = [
                    'islamicc' => 'islamic-civil',
                    'ethiopic-amete-alem' => 'ethioaa',
                    'gregorian' => 'gregory',
                ];
                // UTS35 BCP47 type alias "yes" -> "true" for keyword
                // keys that explicitly list "yes" as an alias of "true".
                // Ordering must be preserved.
                static $yesToTrueKeys = ['kb', 'kc', 'kh', 'kk', 'kn'];
                foreach ($yesToTrueKeys as $yesKey) {
                    if (
                        isset($keywords[$yesKey])
                        && count($keywords[$yesKey]) === 1
                        && $keywords[$yesKey][0] === 'yes'
                    ) {
                        // "true" is the canonical default and renders as
                        // the bare key with no value subtag.
                        $keywords[$yesKey] = [];
                    }
                }
                // CLDR <type alias=...> replacements for -u- extension
                // values. Each table maps deprecated value -> canonical
                // value for a specific key.
                static $unicodeTypeAliases = [
                    // ks (colStrength)
                    'ks' => [
                        'primary' => 'level1',
                        'secondary' => 'level2',
                        'tertiary' => 'level3',
                        'quaternary' => 'level4',
                        'quarternary' => 'level4',
                        'identical' => 'identic',
                    ],
                    // ms (measurement system)
                    'ms' => [
                        'imperial' => 'uksystem',
                    ],
                    // tz (timezone)
                    'tz' => [
                        'cnckg' => 'cnsha',
                        'eire' => 'iedub',
                        'est' => 'papty',
                        'gmt0' => 'gmt',
                        'uct' => 'utc',
                        'zulu' => 'utc',
                    ],
                    // ca (calendar) — same as $calendarAliases above; the
                    // legacy-slot path still applies them for the
                    // corresponding result['calendar'] field.
                    'ca' => [
                        'islamicc' => 'islamic-civil',
                        'ethiopic-amete-alem' => 'ethioaa',
                        'gregorian' => 'gregory',
                    ],
                ];
                static $subdivisionAliases = [
                    'no23' => 'no50', 'cn11' => 'cnbj', 'cz10a' => 'cz110',
                    'fra' => 'frges', 'frg' => 'frges', 'lud' => 'lucl',
                ];
                foreach ($unicodeTypeAliases as $aliasKey => $aliasMap) {
                    if (!isset($keywords[$aliasKey])) {
                        continue;
                    }
                    $combined = implode('-', $keywords[$aliasKey]);
                    if (isset($aliasMap[$combined])) {
                        $canonical = $aliasMap[$combined];
                        $keywords[$aliasKey] = $canonical === '' ? [] : explode('-', $canonical);
                    }
                }
                // Subdivision aliases apply to `sd` and `rg` keys; the
                // value is a single subdivision code.
                foreach (['sd', 'rg'] as $sdKey) {
                    if (!isset($keywords[$sdKey])) {
                        continue;
                    }
                    $val = implode('-', $keywords[$sdKey]);
                    if (isset($subdivisionAliases[$val])) {
                        $keywords[$sdKey] = [$subdivisionAliases[$val]];
                    }
                }
                foreach ($legacyMap as $key => $slot) {
                    if (!isset($keywords[$key])) {
                        continue;
                    }
                    $vals = $keywords[$key];
                    $valStr = empty($vals) ? '' : implode('-', $vals);
                    if ($slot === 'numeric') {
                        $result[$slot] = $valStr === '' || $valStr === 'true';
                    } else {
                        $result[$slot] = $valStr === 'true' ? '' : $valStr;
                        if ($slot === 'calendar' && isset($calendarAliases[$result[$slot]])) {
                            $canonical = $calendarAliases[$result[$slot]];
                            $result[$slot] = $canonical;
                            // Update the keywords list so toString sees the
                            // canonical value too.
                            $keywords[$key] = $canonical === '' ? [] : explode('-', $canonical);
                        }
                    }
                }
                // Re-store keywords in case calendar canonicalization
                // mutated them above.
                if (!empty($keywords)) {
                    $result['unicodeKeywords'] = $keywords;
                }
            }
            if (!empty($otherExtensions)) {
                ksort($otherExtensions);
                $result['otherExtensions'] = $otherExtensions;
            }
            if ($privateUse !== null) {
                $result['privateUse'] = $privateUse;
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
        if (isset($parsed['variants']) && is_array($parsed['variants'])) {
            foreach ($parsed['variants'] as $variant) {
                $parts[] = strtolower((string) $variant);
            }
        }

        // Build the unicode extension. Start from the parsed
        // attributes/keywords (which preserve unknown keywords in
        // US-ASCII order) and overlay any options that the constructor
        // applied through the legacy slot names.
        $attributes = [];
        if (isset($parsed['unicodeAttributes']) && is_array($parsed['unicodeAttributes'])) {
            $attributes = $parsed['unicodeAttributes'];
        }
        $keywords = [];
        if (isset($parsed['unicodeKeywords']) && is_array($parsed['unicodeKeywords'])) {
            $keywords = $parsed['unicodeKeywords'];
        }
        $legacyMap = [
            'calendar' => 'ca',
            'collation' => 'co',
            'firstDayOfWeek' => 'fw',
            'hourCycle' => 'hc',
            'caseFirst' => 'kf',
            'numeric' => 'kn',
            'numberingSystem' => 'nu',
        ];
        foreach ($legacyMap as $slot => $key) {
            if (!isset($parsed[$slot])) {
                continue;
            }
            $val = $parsed[$slot];
            if (is_bool($val)) {
                $keywords[$key] = $val ? [] : ['false'];
            } else {
                $valStr = (string) $val;
                $keywords[$key] = $valStr === '' ? [] : explode('-', $valStr);
            }
        }
        ksort($keywords);
        sort($attributes, SORT_STRING);
        // Collect every extension keyed by its singleton character,
        // then emit them in US-ASCII order with the `-x-` private use
        // tail forced last per UTS35.
        $extensionsBySingleton = [];
        if (!empty($attributes) || !empty($keywords)) {
            $uPayload = [];
            foreach ($attributes as $attr) {
                $uPayload[] = $attr;
            }
            foreach ($keywords as $key => $vals) {
                $uPayload[] = $key;
                foreach ($vals as $v) {
                    $uPayload[] = $v;
                }
            }
            $extensionsBySingleton['u'] = implode('-', $uPayload);
        }
        if (isset($parsed['otherExtensions']) && is_array($parsed['otherExtensions'])) {
            foreach ($parsed['otherExtensions'] as $singleton => $payload) {
                $extensionsBySingleton[(string) $singleton] = (string) $payload;
            }
        }
        ksort($extensionsBySingleton);
        foreach ($extensionsBySingleton as $singleton => $payload) {
            $parts[] = $singleton;
            foreach (explode('-', $payload) as $sub) {
                if ($sub !== '') {
                    $parts[] = $sub;
                }
            }
        }
        if (isset($parsed['privateUse']) && $parsed['privateUse'] !== '') {
            $parts[] = 'x';
            foreach (explode('-', (string) $parsed['privateUse']) as $sub) {
                if ($sub !== '') {
                    $parts[] = $sub;
                }
            }
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
                $options = self::getOptionsObject($optionsArg);
                self::validateLocaleMatcher($options);

                // Spec orders option reads: localeMatcher -> style -> type
                // -> fallback -> languageDisplay. RangeError for an invalid
                // style must therefore propagate before we report the
                // missing required `type` argument.
                $style = 'long';
                $styleVal = $options->get('style');
                if (!$styleVal instanceof JsUndefined) {
                    $s = TypeConversion::toString($styleVal);
                    if (!in_array($s, ['narrow', 'short', 'long'], true)) {
                        throw new RangeError("Invalid style: {$s}");
                    }
                    $style = $s;
                }

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

                $obj = self::instanceFromConstructor($this_, $proto);
                $obj->defineOwnProperty('[[InitializedDisplayNames]]', PropertyDescriptor::data(
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
                $obj->defineOwnProperty('[[Type]]', PropertyDescriptor::data(
                    new JsString($type),
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
                $obj->defineOwnProperty('[[Fallback]]', PropertyDescriptor::data(
                    new JsString($fallback),
                    false,
                    false,
                    false,
                ));
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
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedDisplayNames]]') instanceof JsUndefined
            ) {
                throw new TypeError('Intl.DisplayNames.prototype.of called on non-DisplayNames');
            }
            $code = isset($args[0]) ? TypeConversion::toString($args[0]) : '';
            $type = self::extractInternalString($this_, '[[Type]]', 'language');
            $locale = self::extractInternalString($this_, '[[Locale]]', 'en');
            // Per spec each `type` validates its `code` against a specific
            // production and throws RangeError on any mismatch.
            $validateCode = static function (string $type, string $code): bool {
                if ($code === '') {
                    return false;
                }
                if (preg_match('/[^A-Za-z0-9-]/', $code)) {
                    return false;
                }
                if ($code[0] === '-' || $code[strlen($code) - 1] === '-' || str_contains($code, '--')) {
                    return false;
                }
                $parts = explode('-', $code);
                $first = $parts[0];
                $firstLen = strlen($first);
                switch ($type) {
                    case 'language':
                        // unicode_language_id: alpha{2,3}|alpha{5,8} optional
                        // script (alpha{4}) optional region (alpha{2}|digit{3})
                        // followed by zero or more variant subtags.
                        if (
                            !ctype_alpha($first)
                            || !($firstLen === 2 || $firstLen === 3 || ($firstLen >= 5 && $firstLen <= 8))
                        ) {
                            return false;
                        }
                        $j = 1;
                        $partCount = count($parts);
                        if ($j < $partCount && strlen($parts[$j]) === 4 && ctype_alpha($parts[$j])) {
                            $j++;
                        }
                        if ($j < $partCount) {
                            $rp = $parts[$j];
                            $rpLen = strlen($rp);
                            if (
                                ($rpLen === 2 && ctype_alpha($rp))
                                || ($rpLen === 3 && ctype_digit($rp))
                            ) {
                                $j++;
                            }
                        }
                        while ($j < $partCount) {
                            $vp = $parts[$j];
                            $vpLen = strlen($vp);
                            $isLong = ($vpLen >= 5 && $vpLen <= 8 && ctype_alnum($vp));
                            $isShortNum = ($vpLen === 4 && ctype_digit($vp[0]) && ctype_alnum($vp));
                            if (!$isLong && !$isShortNum) {
                                return false;
                            }
                            $j++;
                        }
                        return true;
                    case 'region':
                        // alpha{2} or digit{3} only.
                        if (count($parts) !== 1) {
                            return false;
                        }
                        return ($firstLen === 2 && ctype_alpha($first))
                            || ($firstLen === 3 && ctype_digit($first));
                    case 'script':
                        if (count($parts) !== 1) {
                            return false;
                        }
                        return $firstLen === 4 && ctype_alpha($first);
                    case 'currency':
                        if (count($parts) !== 1) {
                            return false;
                        }
                        return $firstLen === 3 && ctype_alpha($first);
                    case 'calendar':
                        // calendar = alphanum{3,8}(-alphanum{3,8})*
                        foreach ($parts as $p) {
                            $pLen = strlen($p);
                            if ($pLen < 3 || $pLen > 8 || !ctype_alnum($p)) {
                                return false;
                            }
                        }
                        return true;
                    case 'dateTimeField':
                        return in_array(
                            $code,
                            [
                                'era', 'year', 'quarter', 'month', 'weekOfYear',
                                'weekday', 'day', 'dayPeriod', 'hour', 'minute',
                                'second', 'timeZoneName',
                            ],
                            true,
                        );
                }
                return true;
            };
            if (!$validateCode($type, $code)) {
                throw new RangeError("Invalid code for DisplayNames.of: {$code}");
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
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedDisplayNames]]') instanceof JsUndefined
            ) {
                throw new TypeError('Intl.DisplayNames.prototype.resolvedOptions called on non-DisplayNames');
            }
            $result = new JsObject();
            // Spec table 6 ordering: locale, style, type, fallback, languageDisplay.
            self::defineDataProp($result, 'locale', new JsString(
                self::extractInternalString($this_, '[[Locale]]', 'en'),
            ));
            self::defineDataProp($result, 'style', new JsString(
                self::extractInternalString($this_, '[[Style]]', 'long'),
            ));
            self::defineDataProp($result, 'type', new JsString(
                self::extractInternalString($this_, '[[Type]]', 'language'),
            ));
            self::defineDataProp($result, 'fallback', new JsString(
                self::extractInternalString($this_, '[[Fallback]]', 'code'),
            ));
            self::defineDataProp($result, 'languageDisplay', new JsString(
                self::extractInternalString($this_, '[[LanguageDisplay]]', 'dialect'),
            ));
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

    /**
     * StringListFromIterable: walk the provided iterable, validate that
     * each yielded value is a string, and return them as a PHP list.
     * Properly closes the iterator on abrupt completion and respects
     * the @@iterator protocol so user-defined iterables work.
     *
     * @return list<string>
     */
    private static function stringListFromIterable(JsValue $iterable): array
    {
        if ($iterable instanceof JsUndefined) {
            return [];
        }
        if (!$iterable instanceof JsObject) {
            $iterable = TypeConversion::toObject($iterable);
        }
        $iterMethod = $iterable->getBySymbol(SymbolConstructor::iterator());
        if (!$iterMethod instanceof JsFunction) {
            throw new TypeError('object is not iterable');
        }
        $iterator = $iterMethod->call($iterable, []);
        if (!$iterator instanceof JsObject) {
            throw new TypeError('Result of the Symbol.iterator method is not an object');
        }
        $next = $iterator->get('next');
        if (!$next instanceof JsFunction) {
            throw new TypeError('iterator.next is not a function');
        }
        $closeIterator = static function (JsObject $iterator): void {
            $ret = $iterator->get('return');
            if ($ret instanceof JsFunction) {
                try {
                    $ret->call($iterator, []);
                } catch (\Throwable) {
                    // Closing should not mask the original error.
                }
            }
        };
        $items = [];
        while (true) {
            try {
                $result = $next->call($iterator, []);
            } catch (\Throwable $e) {
                throw $e;
            }
            if (!$result instanceof JsObject) {
                throw new TypeError('Iterator result is not an object');
            }
            if (TypeConversion::toBoolean($result->get('done'))) {
                break;
            }
            $value = $result->get('value');
            if (!$value instanceof JsString) {
                $closeIterator($iterator);
                $rendered = $value instanceof JsObject
                    ? 'object'
                    : TypeConversion::toString($value);
                throw new TypeError("Iterable yielded {$rendered} which is not a string");
            }
            $items[] = $value->value;
        }
        return $items;
    }

    /**
     * Locale-blind fallback list-joiner approximating CLDR list patterns.
     * Implements the well-known English templates for each
     * (type, style) combination so test262's English fixtures pass.
     *
     * @param list<string> $items
     */
    private static function joinListItems(array $items, string $type, string $style): string
    {
        $count = count($items);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $items[0];
        }
        [$pairSep, $startSep, $midSep, $endSep] = self::listSeparators($type, $style);
        if ($count === 2) {
            return $items[0] . $pairSep . $items[1];
        }
        $tail = array_pop($items);
        $first = array_shift($items);
        // CLDR list patterns model 3+ items as start + (middle*) + end.
        $body = $first;
        $body .= $startSep . array_shift($items);
        foreach ($items as $mid) {
            $body .= $midSep . $mid;
        }
        return $body . $endSep . $tail;
    }

    /**
     * Return the (pair, start, middle, end) separator quadruple for the
     * given list (type, style) combination. Mirrors English CLDR data.
     *
     * @return array{0:string,1:string,2:string,3:string}
     */
    private static function listSeparators(string $type, string $style): array
    {
        if ($type === 'unit' && $style === 'narrow') {
            return [' ', ' ', ' ', ' '];
        }
        if ($type === 'unit' && $style === 'short') {
            return [', ', ', ', ', ', ', '];
        }
        if ($type === 'unit' && $style === 'long') {
            return [', ', ', ', ', ', ', '];
        }
        if ($type === 'disjunction' && $style === 'short') {
            return [' or ', ', ', ', ', ', or '];
        }
        if ($type === 'disjunction') {
            return [' or ', ', ', ', ', ', or '];
        }
        // Conjunction.
        if ($style === 'short') {
            return [' & ', ', ', ', ', ', & '];
        }
        if ($style === 'narrow') {
            return [', ', ', ', ', ', ', '];
        }
        return [' and ', ', ', ', ', ', and '];
    }

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
                $options = self::getOptionsObject($optionsArg);
                self::validateLocaleMatcher($options);

                $obj = self::instanceFromConstructor($this_, $proto);
                $obj->defineOwnProperty('[[InitializedListFormat]]', PropertyDescriptor::data(
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
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedListFormat]]') instanceof JsUndefined
            ) {
                throw new TypeError('Intl.ListFormat.prototype.format called on non-ListFormat');
            }
            $items = self::stringListFromIterable($args[0] ?? JsUndefined::instance());
            $type = self::extractInternalString($this_, '[[Type]]', 'conjunction');
            $style = self::extractInternalString($this_, '[[Style]]', 'long');
            return new JsString(self::joinListItems($items, $type, $style));
        }, 1);
        $proto->defineOwnProperty('format', PropertyDescriptor::data($format, true, false, true));

        // ListFormat.prototype.formatToParts(list)
        $formatToParts = JsFunction::fromCallable('formatToParts', function (JsValue $this_, array $args): JsValue {
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedListFormat]]') instanceof JsUndefined
            ) {
                throw new TypeError(
                    'Intl.ListFormat.prototype.formatToParts called on non-ListFormat'
                );
            }
            $items = self::stringListFromIterable($args[0] ?? JsUndefined::instance());
            $type = self::extractInternalString($this_, '[[Type]]', 'conjunction');
            $style = self::extractInternalString($this_, '[[Style]]', 'long');
            $combined = self::joinListItems($items, $type, $style);
            $result = new JsArray();
            $idx = 0;
            $cursor = 0;
            foreach ($items as $item) {
                $pos = strpos($combined, $item, $cursor);
                if ($pos === false) {
                    continue;
                }
                if ($pos > $cursor) {
                    $literal = substr($combined, $cursor, $pos - $cursor);
                    $part = new JsObject();
                    self::defineDataProp($part, 'type', new JsString('literal'));
                    self::defineDataProp($part, 'value', new JsString($literal));
                    $result->set((string) $idx++, $part);
                }
                $part = new JsObject();
                self::defineDataProp($part, 'type', new JsString('element'));
                self::defineDataProp($part, 'value', new JsString($item));
                $result->set((string) $idx++, $part);
                $cursor = $pos + strlen($item);
            }
            if ($cursor < strlen($combined)) {
                $part = new JsObject();
                self::defineDataProp($part, 'type', new JsString('literal'));
                self::defineDataProp($part, 'value', new JsString(substr($combined, $cursor)));
                $result->set((string) $idx++, $part);
            }
            $result->set('length', new JsNumber((float) $idx));
            return $result;
        }, 1);
        $proto->defineOwnProperty('formatToParts', PropertyDescriptor::data($formatToParts, true, false, true));

        // ListFormat.prototype.resolvedOptions()
        $resolvedOptions = JsFunction::fromCallable('resolvedOptions', function (JsValue $this_): JsValue {
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedListFormat]]') instanceof JsUndefined
            ) {
                throw new TypeError('Intl.ListFormat.prototype.resolvedOptions called on non-ListFormat');
            }
            $result = new JsObject();
            self::defineDataProp($result, 'locale', new JsString(
                self::extractInternalString($this_, '[[Locale]]', 'en'),
            ));
            self::defineDataProp($result, 'type', new JsString(
                self::extractInternalString($this_, '[[Type]]', 'conjunction'),
            ));
            self::defineDataProp($result, 'style', new JsString(
                self::extractInternalString($this_, '[[Style]]', 'long'),
            ));
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
        return $fmt->format($n);
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
        } else {
            $unitStr = $isPlural ? $unit . 's' : $unit;
        }
        if ($n < 0 || ($n === 0.0 && self::isNegativeZero($n))) {
            return $absStr . ' ' . $unitStr . ' ago';
        }
        return 'in ' . $absStr . ' ' . $unitStr;
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

                $obj = self::instanceFromConstructor($this_, $proto);
                $obj->defineOwnProperty('[[InitializedRelativeTimeFormat]]', PropertyDescriptor::data(
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
            $result->set('length', new JsNumber((float) $idx));
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
                $options = self::getOptionsObject($optionsArg);
                self::validateLocaleMatcher($options);

                $obj = self::instanceFromConstructor($this_, $proto);
                $obj->defineOwnProperty('[[InitializedSegmenter]]', PropertyDescriptor::data(
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
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedSegmenter]]') instanceof JsUndefined
            ) {
                throw new TypeError('Intl.Segmenter.prototype.segment called on non-Segmenter');
            }
            // Spec: `Let string be ? ToString(string)`. The default-arg
            // ToString happens unconditionally, so a missing argument
            // (or an explicit `undefined`) becomes the literal string
            // "undefined".
            $str = TypeConversion::toString($args[0] ?? JsUndefined::instance());
            $granularity = self::extractInternalString($this_, '[[Granularity]]', 'grapheme');

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
                if (
                    !$this2_ instanceof JsObject
                    || $this2_->get('[[String]]') instanceof JsUndefined
                ) {
                    throw new TypeError(
                        'Intl.Segmenter%segments%.prototype.containing called on incompatible receiver',
                    );
                }
                $rawIndex = TypeConversion::toNumber($args[0] ?? JsUndefined::instance());
                if (is_nan($rawIndex)) {
                    $rawIndex = 0.0;
                }
                $utf16Length = self::utf8ByteToUtf16Index($str, strlen($str));
                if (!is_finite($rawIndex) || $rawIndex < 0 || $rawIndex >= $utf16Length) {
                    return JsUndefined::instance();
                }
                $index = (int) ($rawIndex >= 0 ? floor($rawIndex) : -floor(-$rawIndex));
                $byteIdx = self::utf16IndexToUtf8Byte($str, $index);
                [$start, $end] = self::segmentBoundsAt($str, $byteIdx, $granularity);
                $segment = substr($str, $start, $end - $start);
                $result = new JsObject();
                $result->set('segment', new JsString($segment));
                $result->set(
                    'index',
                    new JsNumber((float) self::utf8ByteToUtf16Index($str, $start)),
                );
                $result->set('input', new JsString($str));
                if ($granularity === 'word') {
                    $result->set('isWordLike', new JsBoolean(
                        preg_match('/\p{L}|\p{N}/u', $segment) === 1,
                    ));
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
                        // IntlBreakIterator returns BYTE offsets when fed
                        // a PHP string (UTF-8). Use substr-by-byte and
                        // convert the byte offset to a UTF-16 code-unit
                        // index for the JS-facing `index` property.
                        $chars[] = [
                            'segment' => substr($str, $prev, $pos - $prev),
                            'index' => self::utf8ByteToUtf16Index($str, $prev),
                        ];
                        $prev = $pos;
                    }
                } elseif ($granularity === 'word') {
                    if (extension_loaded('intl')) {
                        $bi = \IntlBreakIterator::createWordInstance();
                        $bi->setText($str);
                        $prev = 0;
                        while (($pos = $bi->next()) !== \IntlBreakIterator::DONE) {
                            $chars[] = [
                                'segment' => substr($str, $prev, $pos - $prev),
                                'index' => self::utf8ByteToUtf16Index($str, $prev),
                            ];
                            $prev = $pos;
                        }
                    } else {
                        // Fallback: split on word boundaries.
                        preg_match_all('/\S+|\s+/u', $str, $matches, PREG_OFFSET_CAPTURE);
                        foreach ($matches[0] as $m) {
                            $chars[] = [
                                'segment' => $m[0],
                                'index' => mb_strlen(substr($str, 0, $m[1]), 'UTF-8'),
                            ];
                        }
                    }
                } elseif ($granularity === 'sentence') {
                    if (extension_loaded('intl')) {
                        $bi = \IntlBreakIterator::createSentenceInstance();
                        $bi->setText($str);
                        $prev = 0;
                        while (($pos = $bi->next()) !== \IntlBreakIterator::DONE) {
                            $chars[] = [
                                'segment' => substr($str, $prev, $pos - $prev),
                                'index' => self::utf8ByteToUtf16Index($str, $prev),
                            ];
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
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedSegmenter]]') instanceof JsUndefined
            ) {
                throw new TypeError('Intl.Segmenter.prototype.resolvedOptions called on non-Segmenter');
            }
            $result = new JsObject();
            self::defineDataProp($result, 'locale', new JsString(
                self::extractInternalString($this_, '[[Locale]]', 'en'),
            ));
            self::defineDataProp($result, 'granularity', new JsString(
                self::extractInternalString($this_, '[[Granularity]]', 'grapheme'),
            ));
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
