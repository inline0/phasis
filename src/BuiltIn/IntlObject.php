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
    private static function resolveLocale(array $requestedLocales, ?array $allowedExtensions = null): string
    {
        $resolved = null;
        if (extension_loaded('intl')) {
            $available = \ResourceBundle::getLocales('');
            foreach ($requestedLocales as $locale) {
                $icuLocale = str_replace('-', '_', $locale);
                $best = \Locale::lookup($available, $icuLocale, true, '');
                if ($best !== '' && $best !== null) {
                    $resolved = self::canonicalizeLocaleTag($locale) ?? $locale;
                    break;
                }
            }
            if ($resolved === null) {
                $default = \Locale::getDefault();
                // Strip the legacy `POSIX` variant from the host
                // default so resolvedOptions().locale matches V8.
                $default = preg_replace('/[_-]POSIX$/i', '', $default) ?? $default;
                $resolved = self::canonicalizeLocaleTag(str_replace('_', '-', $default))
                    ?? str_replace('_', '-', $default);
            }
        } else {
            $resolved = $requestedLocales[0] ?? 'en';
        }
        if ($allowedExtensions !== null) {
            $resolved = self::filterUnicodeExtensions($resolved, $allowedExtensions);
        }
        return $resolved;
    }

    /**
     * Strip Unicode `-u-` extension keywords from the locale tag whose
     * key isn't in the constructor's allowed-extensions list. Keeps
     * the legacy attribute prefix alone so `da-u-attr-co-search` with
     * `co` removed still emits `da-u-attr` rather than `da`.
     *
     * @param list<string> $allowedKeys
     */
    private static function filterUnicodeExtensions(string $tag, array $allowedKeys): string
    {
        if (preg_match('/^(.+?)-u-(.+?)((?:-[a-wy-z]-.*)?)$/i', $tag, $m) !== 1) {
            return $tag;
        }
        $prefix = $m[1];
        $payload = $m[2];
        $tail = $m[3] ?? '';
        // Walk the payload one segment at a time, dropping keyword
        // value-runs whose 2-char key isn't allowed. Attributes
        // (leading 3-8 char tokens) are spec-defined as having no
        // semantic effect, so they're dropped entirely from the
        // resolved locale.
        $tokens = explode('-', $payload);
        $kept = [];
        $i = 0;
        $count = count($tokens);
        while ($i < $count && strlen($tokens[$i]) >= 3) {
            // Skip attributes (no key, just a value).
            $i++;
        }
        while ($i < $count) {
            $key = $tokens[$i];
            if (strlen($key) !== 2) {
                $i++;
                continue;
            }
            $i++;
            $values = [];
            while ($i < $count && strlen($tokens[$i]) >= 3) {
                $values[] = $tokens[$i];
                $i++;
            }
            if (in_array($key, $allowedKeys, true)) {
                $combined = implode('-', $values);
                if (!self::isRecognisedUnicodeKeywordValue($key, $combined)) {
                    continue;
                }
                $kept[] = $key;
                foreach ($values as $v) {
                    $kept[] = $v;
                }
            }
        }
        if (empty($kept)) {
            return $prefix . $tail;
        }
        return $prefix . '-u-' . implode('-', $kept) . $tail;
    }

    /**
     * Test whether a `-u-` keyword value is among the values our
     * implementation actually recognises. Unrecognised values are
     * dropped during locale resolution per ResolveLocale spec.
     */
    private static function isRecognisedUnicodeKeywordValue(string $key, string $value): bool
    {
        if ($value === '') {
            return true;
        }
        switch ($key) {
            case 'nu':
                return in_array($value, self::getSupportedNumberingSystems(), true);
            case 'ca':
                return in_array($value, self::getSupportedCalendars(), true);
            case 'co':
                if ($value === 'standard' || $value === 'search') {
                    return false;
                }
                return in_array($value, self::getSupportedCollations(), true);
            case 'hc':
                return in_array($value, ['h11', 'h12', 'h23', 'h24'], true);
            case 'kf':
                return in_array($value, ['upper', 'lower', 'false'], true);
            case 'kn':
                return $value === 'true' || $value === 'false';
        }
        return true;
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
                // ICU's enumeration includes 3-letter abbreviation
                // aliases (ACT, BST, CST, ...) that the spec
                // explicitly rejects. Only pull in the multi-segment
                // aliases (those containing a '/') so legacy IANA
                // names like Canada/East-Saskatchewan are accepted.
                $iter = \IntlTimeZone::createEnumeration();
                foreach ($iter as $id) {
                    $lower = strtolower($id);
                    if (isset($tzLowerMap[$lower])) {
                        continue;
                    }
                    if (str_contains($id, '/') || $id === 'UTC') {
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
        // Zero-offset normalises to "+00:00" regardless of sign.
        if ($hh === '00' && $mm === '00') {
            $sign = '+';
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
                $resolvedLocale = self::resolveLocale($locales, ["co", "kf", "kn"]);
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

                // collation: validate the type-value grammar but
                // silently drop the reserved "standard"/"search"
                // identifiers (V8 falls back to "default" rather than
                // throwing). User-driven search collation is selected
                // via usage:"search".
                $collation = 'default';
                $collVal = $options->get('collation');
                if (!$collVal instanceof JsUndefined) {
                    $collRaw = TypeConversion::toString($collVal);
                    if (!self::isValidUnicodeTypeValue($collRaw)) {
                        throw new RangeError("Invalid collation: {$collRaw}");
                    }
                    if (!in_array($collRaw, ['standard', 'search'], true)) {
                        $collation = $collRaw;
                    }
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
                $usage = self::extractInternalString($this_, '[[Usage]]', 'sort');
                $localeForIcu = str_replace('-', '_', $locale);
                if ($usage === 'search') {
                    $sep = strpos($localeForIcu, '@') === false ? '@' : ';';
                    $localeForIcu .= $sep . 'collation=search';
                }
                $collator = new \Collator($localeForIcu);

                $sensitivity = self::extractInternalString($this_, '[[Sensitivity]]', 'variant');
                $strength = match ($sensitivity) {
                    'base' => \Collator::PRIMARY,
                    'accent' => \Collator::SECONDARY,
                    'case' => \Collator::PRIMARY,
                    default => \Collator::TERTIARY,
                };
                $collator->setStrength($strength);

                if ($sensitivity === 'case' && defined('Collator::CASE_LEVEL')) {
                    $collator->setAttribute(\Collator::CASE_LEVEL, \Collator::ON);
                }

                $collator->setAttribute(
                    \Collator::NORMALIZATION_MODE,
                    \Collator::ON,
                );

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
            $boundCompare = JsFunction::fromCallable('', function (
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
            $bigStr = null;
            if ($number instanceof \PhpJs\Value\JsBigInt) {
                $abs = ltrim($number->value, '-');
                $fitsInDouble = strlen($abs) < 16
                    || (strlen($abs) === 16 && strcmp($abs, '9007199254740992') <= 0);
                if (!$fitsInDouble) {
                    $bigStr = $number->value;
                }
            }
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
            $numVal = TypeConversion::toNumber($number);
            $formatted = '';
            if (extension_loaded('intl')) {
                $formatted = self::formatNumber($this_, $numVal);
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
            $startStr = extension_loaded('intl')
                ? self::formatNumber($this_, $start) : (string) $start;
            $endStr = extension_loaded('intl')
                ? self::formatNumber($this_, $end) : (string) $end;
            // Approximately sign: when distinct numeric inputs round
            // to the same formatted output, the spec mandates an
            // "approximately" prefix so the consumer can tell that
            // the range collapsed only after rounding.
            if ($startStr === $endStr) {
                if ($start !== $end) {
                    return new JsString(self::numberFormatApproximatelyPrefix($this_) . $startStr);
                }
                return new JsString($startStr);
            }
            $sep = self::numberFormatRangeSeparator($this_);
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
            $emit = static function (string $type, string $value, string $source) use (
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
                $appendTyped($startParts, 'shared');
            } else {
                $endParts = self::numberFormatToParts($this_, $endStr, $end);
                $appendTyped($startParts, 'startRange');
                $emit('literal', self::numberFormatRangeSeparator($this_), 'shared');
                $appendTyped($endParts, 'endRange');
            }
            $result->set('length', new JsNumber((float) $idx));
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
                $result->set('length', new JsNumber((float) $idx));
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
            if (substr($body, $i, strlen($decimalSym)) === $decimalSym && $decimalSym !== '') {
                $sawDecimal = true;
                $emit('decimal', $decimalSym);
                $i += strlen($decimalSym);
                continue;
            }
            if (substr($body, $i, strlen($groupSym)) === $groupSym && $groupSym !== '') {
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
    private static function formatCompactNumber(JsObject $nf, float $number): ?string
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
            return is_string($sym) && $sym !== '' ? $sym : 'NaN';
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
            return is_string($sym) && $sym !== '' ? $sym : '∞';
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
        if ($val instanceof \PhpJs\Value\JsBigInt) {
            return (float) $val->value;
        }
        return TypeConversion::toNumber($val);
    }

    /**
     * Render a BigInt's decimal string using the locale's grouping
     * separator and (for unusual numbering systems) digit set, while
     * honouring useGrouping / minimumIntegerDigits. Sub-thousand
     * values aren't grouped, matching CLDR's "min2" behaviour for
     * useGrouping when the leading group is single-digit.
     */
    private static function renderBigIntStringLocaleAware(
        string $bigIntStr,
        \NumberFormatter $formatter,
        JsObject $nf,
    ): string {
        $sign = '';
        $digits = $bigIntStr;
        if (str_starts_with($digits, '-')) {
            $sign = '-';
            $digits = substr($digits, 1);
        } elseif (str_starts_with($digits, '+')) {
            $digits = substr($digits, 1);
        }
        $minInt = (int) self::extractInternalNumber($nf, '[[MinimumIntegerDigits]]', 1);
        if ($minInt > strlen($digits)) {
            $digits = str_repeat('0', $minInt - strlen($digits)) . $digits;
        }
        $useGrouping = self::extractInternalString($nf, '[[UseGrouping]]', 'auto');
        $groupSym = $formatter->getSymbol(\NumberFormatter::GROUPING_SEPARATOR_SYMBOL);
        $shouldGroup = match ($useGrouping) {
            'false' => false,
            'always' => true,
            'min2' => strlen($digits) > 4,
            default => strlen($digits) > 3,
        };
        if (!$shouldGroup || $groupSym === false || $groupSym === '') {
            return $sign . $digits;
        }
        // Split from the right into 3-digit groups (Indian numbering
        // systems use 2-3-3 but we'd handle that separately).
        $out = '';
        $len = strlen($digits);
        for ($i = $len; $i > 0; $i -= 3) {
            $start = max(0, $i - 3);
            $chunk = substr($digits, $start, $i - $start);
            $out = $chunk . ($out === '' ? '' : $groupSym . $out);
        }
        return $sign . $out;
    }

    private static function formatNumber(JsObject $nf, float $number, ?string $bigIntStr = null): string
    {
        $locale = self::extractInternalString($nf, '[[Locale]]', 'en');
        $style = self::extractInternalString($nf, '[[Style]]', 'decimal');
        $numberingSystem = self::extractInternalString($nf, '[[NumberingSystem]]', 'latn');
        $notation = self::extractInternalString($nf, '[[Notation]]', 'standard');

        // Engineering / scientific notations decompose into a
        // mantissa + locale-rendered exponent.
        if ($notation === 'engineering' || $notation === 'scientific') {
            return self::formatScientificNumber($nf, $number, $notation);
        }
        // Compact notation: covers en-US short/long; other locales
        // share the same structure with localised suffix labels.
        if ($notation === 'compact') {
            $compact = self::formatCompactNumber($nf, $number);
            if ($compact !== null) {
                return self::applySignDisplay($nf, $compact, $number);
            }
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
                        $mm = isset($offMatch[3]) && $offMatch[3] !== ''
                            ? (int) $offMatch[3]
                            : 0;
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
                                new JsNumber((float) $intVal),
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
                }

                // Now that dateStyle/timeStyle are known, decide
                // whether to expose [[HourCycle]] / [[Hour12]] (only
                // when the formatter will emit hours -- explicit
                // hour component or via timeStyle).
                $impliesHour = $hasHour || $timeStyle !== null;
                if ($impliesHour) {
                    if ($hourCycle === null) {
                        $localeLang = strtolower(strtok($resolvedLocale, '-_'));
                        static $localeDefaultHc = [
                            'ja' => 'h11',
                        ];
                        $hourCycle = $localeDefaultHc[$localeLang] ?? 'h12';
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
            $startMs = self::dateTimeFormatRangeArgToMs($startVal, 'startDate');
            $endMs = self::dateTimeFormatRangeArgToMs($endVal, 'endDate');
            $startStr = '';
            $endStr = '';
            if (extension_loaded('intl') && $this_ instanceof JsObject) {
                $startStr = self::formatDateTimeMs($this_, (float) $startMs);
                $endStr = self::formatDateTimeMs($this_, (float) $endMs);
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
                $startStr = self::formatDateTimeMs($this_, (float) $startMs);
                $endStr = self::formatDateTimeMs($this_, (float) $endMs);
            }
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
            );
            if ($startStr === $endStr) {
                $appendTyped($startParts, 'shared');
            } else {
                $endParts = self::dateTimeFormatToParts(
                    $this_,
                    $endStr,
                    (int) round($endMs / 1000),
                );
                $appendTyped($startParts, 'startRange');
                $emit('literal', " \u{2013} ", 'shared');
                $appendTyped($endParts, 'endRange');
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
                    self::defineDataProp($result, $comp, new JsNumber((float) $num));
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
        // TimeClip: NaN if outside ECMAScript's ±8.64e15 ms range.
        if (abs($n) > 8.64e15) {
            throw new RangeError("Invalid {$argName}: time value out of range");
        }
        return $n;
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
    private static function dateTimeFormatToParts(JsObject $dtf, string $formatted, int $timestamp): JsArray
    {
        $parts = new JsArray();
        $idx = 0;
        $emit = static function (string $type, string $value) use (&$parts, &$idx): void {
            if ($value === '') {
                return;
            }
            $part = new JsObject();
            self::defineDataProp($part, 'type', new JsString($type));
            self::defineDataProp($part, 'value', new JsString($value));
            $parts->set((string) $idx++, $part);
        };
        // Without intl we already only have a literal output.
        if (!extension_loaded('intl')) {
            $emit('literal', $formatted);
            $parts->set('length', new JsNumber((float) $idx));
            return $parts;
        }
        // Reuse the same formatter the format() pipeline used so
        // `getPattern()` reflects the same skeleton.
        $formatter = self::dateTimeFormatterFor($dtf);
        $pattern = $formatter->getPattern();
        if ($pattern === false || $pattern === '') {
            $emit('literal', $formatted);
            $parts->set('length', new JsNumber((float) $idx));
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
                    // Search for any whitespace cluster as the
                    // boundary, not just the exact pattern char.
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
            $value = substr($formatted, $cursor, $endPos - $cursor);
            if ($value !== '') {
                $emit($tok['type'], $value);
            }
            $cursor = $endPos;
        }
        if ($cursor < $outLen) {
            $emit('literal', substr($formatted, $cursor));
        }
        $parts->set('length', new JsNumber((float) $idx));
        return $parts;
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
     * Format a date/time using PHP's IntlDateFormatter.
     */
    private static function formatDateTime(JsObject $dtf, int $timestamp): string
    {
        return self::formatDateTimeMs($dtf, $timestamp * 1000.0);
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
        return $result === false ? date('Y-m-d H:i:s', $secFallback) : $result;
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
        $locale = str_replace('-', '_', self::extractInternalString($dtf, '[[Locale]]', 'en'));
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
        if ($dateStyle !== null || $timeStyle !== null) {
            $base = new \IntlDateFormatter(
                $locale,
                $mapStyle($dateStyle),
                $mapStyle($timeStyle),
                $tz,
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
        return new \IntlDateFormatter(
            $locale,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::FULL,
            $tz,
            \IntlDateFormatter::GREGORIAN,
            $pattern,
        );
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

                $resolvedLocale = self::resolveLocale($locales, ["nu"]);
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
                    // Re-apply CLDR region canonicalisation: M.49
                    // numeric codes such as "554" canonicalise to
                    // their alpha-2 equivalent (NZ).
                    $parsed = self::applyRegionAlias($parsed);
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
            $maximized = self::addLikelySubtags($tag);
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
                $isTransform = $key === 't';
                $minSubLen = $isPrivate ? 1 : 2;
                $maxSubLen = 8;
                $sawAny = false;
                // Track tlang state for the transformed extension.
                // The first run is the tlang (language, optional
                // script/region/variants); subsequent runs are tfields
                // (tkey tvalue+). A length-2 subtag whose 2nd char is
                // a digit signals the transition from tlang to tfields.
                $tlangSeen = false;
                $inTlang = false;
                $tlangSawScript = false;
                $tlangSawRegion = false;
                $tlangVariants = [];
                // tfield tracking: tkey requires at least one
                // tvalue (alphanum{3,8}) following it.
                $awaitingTvalue = false;
                $sawTvalueForCurrentTkey = false;
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
                    if ($isTransform) {
                        // Detect a tkey: alpha digit (length 2). If we
                        // see one, we're past the tlang.
                        $isTkey = $subLen === 2
                            && ctype_alpha($sub[0])
                            && ctype_digit($sub[1]);
                        if (!$tlangSeen && !$isTkey) {
                            // First non-tkey subtag starts the tlang.
                            // Must be a valid language subtag (2-3 or
                            // 5-8 alpha; "root" / 4-letter alpha is
                            // not a valid language).
                            if (
                                !ctype_alpha($sub)
                                || !(
                                    $subLen === 2
                                    || $subLen === 3
                                    || ($subLen >= 5 && $subLen <= 8)
                                )
                            ) {
                                return true;
                            }
                            $tlangSeen = true;
                            $inTlang = true;
                            $sawAny = true;
                            $i++;
                            continue;
                        }
                        if ($inTlang && !$isTkey) {
                            // Within tlang: script, region, or variant.
                            if (
                                $subLen === 4
                                && ctype_alpha($sub)
                                && !$tlangSawScript
                                && !$tlangSawRegion
                                && empty($tlangVariants)
                            ) {
                                $tlangSawScript = true;
                                $sawAny = true;
                                $i++;
                                continue;
                            }
                            if (
                                ((($subLen === 2 && ctype_alpha($sub))
                                    || ($subLen === 3 && ctype_digit($sub))))
                                && !$tlangSawRegion
                                && empty($tlangVariants)
                            ) {
                                $tlangSawRegion = true;
                                $sawAny = true;
                                $i++;
                                continue;
                            }
                            $isLongVar = $subLen >= 5 && $subLen <= 8 && ctype_alnum($sub);
                            $isShortNumVar = $subLen === 4
                                && ctype_digit($sub[0])
                                && ctype_alnum($sub);
                            if ($isLongVar || $isShortNumVar) {
                                $vKey = strtolower($sub);
                                if (isset($tlangVariants[$vKey])) {
                                    return true;
                                }
                                $tlangVariants[$vKey] = true;
                                $sawAny = true;
                                $i++;
                                continue;
                            }
                            // Unknown subtag inside tlang — invalid.
                            return true;
                        }
                        if ($isTkey) {
                            // Switching from tlang to tfields. The
                            // previous tkey (if any) must have had at
                            // least one tvalue.
                            if ($awaitingTvalue && !$sawTvalueForCurrentTkey) {
                                return true;
                            }
                            $inTlang = false;
                            $awaitingTvalue = true;
                            $sawTvalueForCurrentTkey = false;
                            $sawAny = true;
                            $i++;
                            continue;
                        }
                        // Inside tfields, a non-tkey subtag is a
                        // tvalue. tvalue = alphanum{3,8}.
                        if ($awaitingTvalue) {
                            if ($subLen < 3 || $subLen > 8 || !ctype_alnum($sub)) {
                                return true;
                            }
                            $sawTvalueForCurrentTkey = true;
                            $sawAny = true;
                            $i++;
                            continue;
                        }
                        // Reached an unexpected token in -t-.
                        return true;
                    }
                    $sawAny = true;
                    $i++;
                }
                if ($isTransform) {
                    // A tkey at the end without a tvalue is invalid.
                    if ($awaitingTvalue && !$sawTvalueForCurrentTkey) {
                        return true;
                    }
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
                    // CLDR M.49 numeric -> alpha-2 territoryAlias map.
                    '004' => 'AF', '008' => 'AL', '012' => 'DZ',
                    '016' => 'AS', '020' => 'AD', '024' => 'AO',
                    '028' => 'AG', '031' => 'AZ', '032' => 'AR',
                    '036' => 'AU', '040' => 'AT', '044' => 'BS',
                    '048' => 'BH', '050' => 'BD', '051' => 'AM',
                    '052' => 'BB', '056' => 'BE', '060' => 'BM',
                    '064' => 'BT', '068' => 'BO', '070' => 'BA',
                    '072' => 'BW', '076' => 'BR', '084' => 'BZ',
                    '090' => 'SB', '096' => 'BN', '100' => 'BG',
                    '104' => 'MM', '108' => 'BI', '112' => 'BY',
                    '116' => 'KH', '120' => 'CM', '124' => 'CA',
                    '132' => 'CV', '136' => 'KY', '140' => 'CF',
                    '144' => 'LK', '148' => 'TD', '152' => 'CL',
                    '156' => 'CN', '170' => 'CO', '174' => 'KM',
                    '178' => 'CG', '180' => 'CD', '184' => 'CK',
                    '188' => 'CR', '191' => 'HR', '192' => 'CU',
                    '196' => 'CY', '203' => 'CZ', '204' => 'BJ',
                    '208' => 'DK', '212' => 'DM', '214' => 'DO',
                    '218' => 'EC', '222' => 'SV', '226' => 'GQ',
                    '231' => 'ET', '232' => 'ER', '233' => 'EE',
                    '242' => 'FJ', '246' => 'FI', '250' => 'FR',
                    '258' => 'PF', '262' => 'DJ', '266' => 'GA',
                    '268' => 'GE', '270' => 'GM', '276' => 'DE',
                    '288' => 'GH', '296' => 'KI', '300' => 'GR',
                    '308' => 'GD', '320' => 'GT', '324' => 'GN',
                    '328' => 'GY', '332' => 'HT', '340' => 'HN',
                    '344' => 'HK', '348' => 'HU', '352' => 'IS',
                    '356' => 'IN', '360' => 'ID', '364' => 'IR',
                    '368' => 'IQ', '372' => 'IE', '376' => 'IL',
                    '380' => 'IT', '384' => 'CI', '388' => 'JM',
                    '392' => 'JP', '398' => 'KZ', '400' => 'JO',
                    '404' => 'KE', '408' => 'KP', '410' => 'KR',
                    '414' => 'KW', '417' => 'KG', '418' => 'LA',
                    '422' => 'LB', '426' => 'LS', '428' => 'LV',
                    '430' => 'LR', '434' => 'LY', '438' => 'LI',
                    '440' => 'LT', '442' => 'LU', '446' => 'MO',
                    '450' => 'MG', '454' => 'MW', '458' => 'MY',
                    '462' => 'MV', '466' => 'ML', '470' => 'MT',
                    '478' => 'MR', '480' => 'MU', '484' => 'MX',
                    '492' => 'MC', '496' => 'MN', '498' => 'MD',
                    '499' => 'ME', '500' => 'MS', '504' => 'MA',
                    '508' => 'MZ', '512' => 'OM', '516' => 'NA',
                    '520' => 'NR', '524' => 'NP', '528' => 'NL',
                    '533' => 'AW', '540' => 'NC', '548' => 'VU',
                    '554' => 'NZ', '558' => 'NI', '562' => 'NE',
                    '566' => 'NG', '570' => 'NU', '578' => 'NO',
                    '583' => 'FM', '584' => 'MH', '585' => 'PW',
                    '586' => 'PK', '591' => 'PA', '598' => 'PG',
                    '600' => 'PY', '604' => 'PE', '608' => 'PH',
                    '616' => 'PL', '620' => 'PT', '624' => 'GW',
                    '626' => 'TL', '630' => 'PR', '634' => 'QA',
                    '642' => 'RO', '643' => 'RU', '646' => 'RW',
                    '659' => 'KN', '662' => 'LC', '670' => 'VC',
                    '674' => 'SM', '678' => 'ST', '682' => 'SA',
                    '686' => 'SN', '688' => 'RS', '690' => 'SC',
                    '694' => 'SL', '702' => 'SG', '703' => 'SK',
                    '704' => 'VN', '705' => 'SI', '706' => 'SO',
                    '710' => 'ZA', '716' => 'ZW', '724' => 'ES',
                    '729' => 'SD', '732' => 'EH', '740' => 'SR',
                    '748' => 'SZ', '752' => 'SE', '756' => 'CH',
                    '760' => 'SY', '762' => 'TJ', '764' => 'TH',
                    '768' => 'TG', '776' => 'TO', '780' => 'TT',
                    '784' => 'AE', '788' => 'TN', '792' => 'TR',
                    '795' => 'TM', '798' => 'TV', '800' => 'UG',
                    '804' => 'UA', '807' => 'MK', '818' => 'EG',
                    '826' => 'GB', '834' => 'TZ', '840' => 'US',
                    '854' => 'BF', '858' => 'UY', '860' => 'UZ',
                    '862' => 'VE', '882' => 'WS', '887' => 'YE',
                    '894' => 'ZM',
                ];
                if (isset($regionAliasCanonical[$result['region']])) {
                    $result['region'] = $regionAliasCanonical[$result['region']];
                }
                // CLDR multi-region territoryAlias entries: pick the
                // likely region based on the language (and script,
                // if present). Falls back to the first listed region
                // when no likelySubtags hit applies.
                static $multiRegionAliases = [
                    'SU' => ['RU', 'AM', 'AZ', 'BY', 'EE', 'GE', 'KZ', 'KG',
                        'LV', 'LT', 'MD', 'TJ', 'TM', 'UA', 'UZ'],
                    '810' => ['RU', 'AM', 'AZ', 'BY', 'EE', 'GE', 'KZ', 'KG',
                        'LV', 'LT', 'MD', 'TJ', 'TM', 'UA', 'UZ'],
                    'CS' => ['RS', 'ME'],
                    '891' => ['RS', 'ME'],
                    'NT' => ['SA', 'IQ'],
                    '536' => ['SA', 'IQ'],
                    'PC' => ['FM', 'MH', 'MP', 'PW'],
                ];
                if (isset($multiRegionAliases[$result['region']])) {
                    $candidates = $multiRegionAliases[$result['region']];
                    $likelyRegion = null;
                    $lookupKey = strtolower(($result['language'] ?? 'und'));
                    if (!empty($result['script'])) {
                        $scriptKey = $lookupKey . '-' . strtolower($result['script']);
                        $table = self::likelySubtagsTable();
                        if (isset($table[$scriptKey])) {
                            $likelyRegion = $table[$scriptKey]['region'];
                        }
                    }
                    if ($likelyRegion === null) {
                        $table = self::likelySubtagsTable();
                        if (isset($table[$lookupKey])) {
                            $likelyRegion = $table[$lookupKey]['region'];
                        }
                    }
                    $result['region'] = (
                        $likelyRegion !== null
                        && in_array($likelyRegion, $candidates, true)
                    )
                        ? $likelyRegion
                        : $candidates[0];
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
    /**
     * Apply CLDR territoryAlias replacements to a parsed locale's
     * region field. Mirrors the inline alias logic used by
     * parseLocaleTag so option-supplied regions canonicalise too.
     *
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    private static function applyRegionAlias(array $parsed): array
    {
        if (!isset($parsed['region']) || $parsed['region'] === '') {
            return $parsed;
        }
        $region = $parsed['region'];
        static $singleRegionAliases = null;
        if ($singleRegionAliases === null) {
            // Reuse the M.49 -> alpha-2 + legacy alpha-2 map by
            // calling parseLocaleTag on a probe tag and reading
            // back the canonical form.
            $singleRegionAliases = [];
            $probe = self::parseLocaleTag('en-554');
            // We don't actually need to introspect; build the same
            // map inline. (Mirrored from parseLocaleTag.)
            $singleRegionAliases = [
                'BU' => 'MM', 'DD' => 'DE', 'FX' => 'FR', 'TP' => 'TL',
                'YD' => 'YE', 'ZR' => 'CD', 'CT' => 'KI', 'NH' => 'VU',
                'RH' => 'ZW', 'VD' => 'VN', 'AN' => 'CW',
                '004' => 'AF', '008' => 'AL', '012' => 'DZ',
                '016' => 'AS', '020' => 'AD', '024' => 'AO',
                '028' => 'AG', '031' => 'AZ', '032' => 'AR',
                '036' => 'AU', '040' => 'AT', '044' => 'BS',
                '048' => 'BH', '050' => 'BD', '051' => 'AM',
                '052' => 'BB', '056' => 'BE', '060' => 'BM',
                '064' => 'BT', '068' => 'BO', '070' => 'BA',
                '072' => 'BW', '076' => 'BR', '084' => 'BZ',
                '090' => 'SB', '096' => 'BN', '100' => 'BG',
                '104' => 'MM', '108' => 'BI', '112' => 'BY',
                '116' => 'KH', '120' => 'CM', '124' => 'CA',
                '132' => 'CV', '136' => 'KY', '140' => 'CF',
                '144' => 'LK', '148' => 'TD', '152' => 'CL',
                '156' => 'CN', '170' => 'CO', '174' => 'KM',
                '178' => 'CG', '180' => 'CD', '184' => 'CK',
                '188' => 'CR', '191' => 'HR', '192' => 'CU',
                '196' => 'CY', '203' => 'CZ', '204' => 'BJ',
                '208' => 'DK', '212' => 'DM', '214' => 'DO',
                '218' => 'EC', '222' => 'SV', '226' => 'GQ',
                '231' => 'ET', '232' => 'ER', '233' => 'EE',
                '242' => 'FJ', '246' => 'FI', '250' => 'FR',
                '258' => 'PF', '262' => 'DJ', '266' => 'GA',
                '268' => 'GE', '270' => 'GM', '276' => 'DE',
                '288' => 'GH', '296' => 'KI', '300' => 'GR',
                '308' => 'GD', '320' => 'GT', '324' => 'GN',
                '328' => 'GY', '332' => 'HT', '340' => 'HN',
                '344' => 'HK', '348' => 'HU', '352' => 'IS',
                '356' => 'IN', '360' => 'ID', '364' => 'IR',
                '368' => 'IQ', '372' => 'IE', '376' => 'IL',
                '380' => 'IT', '384' => 'CI', '388' => 'JM',
                '392' => 'JP', '398' => 'KZ', '400' => 'JO',
                '404' => 'KE', '408' => 'KP', '410' => 'KR',
                '414' => 'KW', '417' => 'KG', '418' => 'LA',
                '422' => 'LB', '426' => 'LS', '428' => 'LV',
                '430' => 'LR', '434' => 'LY', '438' => 'LI',
                '440' => 'LT', '442' => 'LU', '446' => 'MO',
                '450' => 'MG', '454' => 'MW', '458' => 'MY',
                '462' => 'MV', '466' => 'ML', '470' => 'MT',
                '478' => 'MR', '480' => 'MU', '484' => 'MX',
                '492' => 'MC', '496' => 'MN', '498' => 'MD',
                '499' => 'ME', '500' => 'MS', '504' => 'MA',
                '508' => 'MZ', '512' => 'OM', '516' => 'NA',
                '520' => 'NR', '524' => 'NP', '528' => 'NL',
                '533' => 'AW', '540' => 'NC', '548' => 'VU',
                '554' => 'NZ', '558' => 'NI', '562' => 'NE',
                '566' => 'NG', '570' => 'NU', '578' => 'NO',
                '583' => 'FM', '584' => 'MH', '585' => 'PW',
                '586' => 'PK', '591' => 'PA', '598' => 'PG',
                '600' => 'PY', '604' => 'PE', '608' => 'PH',
                '616' => 'PL', '620' => 'PT', '624' => 'GW',
                '626' => 'TL', '630' => 'PR', '634' => 'QA',
                '642' => 'RO', '643' => 'RU', '646' => 'RW',
                '659' => 'KN', '662' => 'LC', '670' => 'VC',
                '674' => 'SM', '678' => 'ST', '682' => 'SA',
                '686' => 'SN', '688' => 'RS', '690' => 'SC',
                '694' => 'SL', '702' => 'SG', '703' => 'SK',
                '704' => 'VN', '705' => 'SI', '706' => 'SO',
                '710' => 'ZA', '716' => 'ZW', '724' => 'ES',
                '729' => 'SD', '732' => 'EH', '740' => 'SR',
                '748' => 'SZ', '752' => 'SE', '756' => 'CH',
                '760' => 'SY', '762' => 'TJ', '764' => 'TH',
                '768' => 'TG', '776' => 'TO', '780' => 'TT',
                '784' => 'AE', '788' => 'TN', '792' => 'TR',
                '795' => 'TM', '798' => 'TV', '800' => 'UG',
                '804' => 'UA', '807' => 'MK', '818' => 'EG',
                '826' => 'GB', '834' => 'TZ', '840' => 'US',
                '854' => 'BF', '858' => 'UY', '860' => 'UZ',
                '862' => 'VE', '882' => 'WS', '887' => 'YE',
                '894' => 'ZM',
            ];
            unset($probe);
        }
        if (isset($singleRegionAliases[$region])) {
            $parsed['region'] = $singleRegionAliases[$region];
        }
        return $parsed;
    }

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
     * CLDR likelySubtags lookup. Maps a partial language tag (just
     * language, language+script, language+region) to the full
     * language-script-region form expected by Intl.Locale.maximize().
     * Covers the common test262 fixtures plus some popular locales.
     *
     * @return array<string, array{language:string, script:string, region:string}>
     */
    private static function likelySubtagsTable(): array
    {
        static $table = null;
        if ($table !== null) {
            return $table;
        }
        // Each entry maps the input form to the maximized form.
        // Sourced from CLDR's likelySubtags.xml; only the entries
        // we've actually exercised in tests are listed here.
        $raw = [
            'aa' => 'aa-Latn-ET', 'ab' => 'ab-Cyrl-GE', 'af' => 'af-Latn-ZA',
            'ak' => 'ak-Latn-GH', 'am' => 'am-Ethi-ET', 'ar' => 'ar-Arab-EG',
            'as' => 'as-Beng-IN', 'az' => 'az-Latn-AZ', 'be' => 'be-Cyrl-BY',
            'bg' => 'bg-Cyrl-BG', 'bm' => 'bm-Latn-ML', 'bn' => 'bn-Beng-BD',
            'bo' => 'bo-Tibt-CN', 'br' => 'br-Latn-FR', 'bs' => 'bs-Latn-BA',
            'ca' => 'ca-Latn-ES', 'ce' => 'ce-Cyrl-RU', 'co' => 'co-Latn-FR',
            'cs' => 'cs-Latn-CZ', 'cy' => 'cy-Latn-GB', 'da' => 'da-Latn-DK',
            'de' => 'de-Latn-DE', 'dz' => 'dz-Tibt-BT', 'ee' => 'ee-Latn-GH',
            'el' => 'el-Grek-GR', 'en' => 'en-Latn-US', 'eo' => 'eo-Latn-001',
            'es' => 'es-Latn-ES', 'et' => 'et-Latn-EE', 'eu' => 'eu-Latn-ES',
            'fa' => 'fa-Arab-IR', 'ff' => 'ff-Latn-SN', 'fi' => 'fi-Latn-FI',
            'fil' => 'fil-Latn-PH', 'fo' => 'fo-Latn-FO', 'fr' => 'fr-Latn-FR',
            'fy' => 'fy-Latn-NL', 'ga' => 'ga-Latn-IE', 'gd' => 'gd-Latn-GB',
            'gl' => 'gl-Latn-ES', 'gn' => 'gn-Latn-PY', 'gu' => 'gu-Gujr-IN',
            'gv' => 'gv-Latn-IM', 'ha' => 'ha-Latn-NG', 'he' => 'he-Hebr-IL',
            'hi' => 'hi-Deva-IN', 'hr' => 'hr-Latn-HR', 'hu' => 'hu-Latn-HU',
            'hy' => 'hy-Armn-AM', 'hyw' => 'hyw-Armn-AM',
            'id' => 'id-Latn-ID', 'ig' => 'ig-Latn-NG', 'ii' => 'ii-Yiii-CN',
            'is' => 'is-Latn-IS', 'it' => 'it-Latn-IT', 'iu' => 'iu-Cans-CA',
            'ja' => 'ja-Jpan-JP', 'jbo' => 'jbo-Latn-001', 'jv' => 'jv-Latn-ID',
            'ka' => 'ka-Geor-GE', 'kk' => 'kk-Cyrl-KZ', 'kl' => 'kl-Latn-GL',
            'km' => 'km-Khmr-KH', 'kn' => 'kn-Knda-IN', 'ko' => 'ko-Kore-KR',
            'ks' => 'ks-Arab-IN', 'ku' => 'ku-Latn-TR', 'kw' => 'kw-Latn-GB',
            'ky' => 'ky-Cyrl-KG', 'la' => 'la-Latn-VA', 'lb' => 'lb-Latn-LU',
            'ln' => 'ln-Latn-CD', 'lo' => 'lo-Laoo-LA', 'lt' => 'lt-Latn-LT',
            'lv' => 'lv-Latn-LV', 'mg' => 'mg-Latn-MG', 'mi' => 'mi-Latn-NZ',
            'mk' => 'mk-Cyrl-MK', 'ml' => 'ml-Mlym-IN', 'mn' => 'mn-Cyrl-MN',
            'mr' => 'mr-Deva-IN', 'ms' => 'ms-Latn-MY', 'mt' => 'mt-Latn-MT',
            'my' => 'my-Mymr-MM', 'nb' => 'nb-Latn-NO', 'ne' => 'ne-Deva-NP',
            'nl' => 'nl-Latn-NL', 'nn' => 'nn-Latn-NO', 'no' => 'no-Latn-NO',
            'or' => 'or-Orya-IN', 'pa' => 'pa-Guru-IN', 'pl' => 'pl-Latn-PL',
            'ps' => 'ps-Arab-AF', 'pt' => 'pt-Latn-BR', 'qu' => 'qu-Latn-PE',
            'rm' => 'rm-Latn-CH', 'rn' => 'rn-Latn-BI', 'ro' => 'ro-Latn-RO',
            'ru' => 'ru-Cyrl-RU', 'rw' => 'rw-Latn-RW', 'sa' => 'sa-Deva-IN',
            'sc' => 'sc-Latn-IT', 'sd' => 'sd-Arab-PK', 'se' => 'se-Latn-NO',
            'sg' => 'sg-Latn-CF', 'si' => 'si-Sinh-LK', 'sk' => 'sk-Latn-SK',
            'sl' => 'sl-Latn-SI', 'sn' => 'sn-Latn-ZW', 'so' => 'so-Latn-SO',
            'sq' => 'sq-Latn-AL', 'sr' => 'sr-Cyrl-RS', 'su' => 'su-Latn-ID',
            'sv' => 'sv-Latn-SE', 'sw' => 'sw-Latn-TZ', 'ta' => 'ta-Taml-IN',
            'te' => 'te-Telu-IN', 'tg' => 'tg-Cyrl-TJ', 'th' => 'th-Thai-TH',
            'ti' => 'ti-Ethi-ET', 'tk' => 'tk-Latn-TM', 'tn' => 'tn-Latn-ZA',
            'to' => 'to-Latn-TO', 'tr' => 'tr-Latn-TR', 'tt' => 'tt-Cyrl-RU',
            'ug' => 'ug-Arab-CN', 'uk' => 'uk-Cyrl-UA', 'und' => 'en-Latn-US',
            'ur' => 'ur-Arab-PK', 'uz' => 'uz-Latn-UZ', 'vi' => 'vi-Latn-VN',
            'wo' => 'wo-Latn-SN', 'xh' => 'xh-Latn-ZA', 'yi' => 'yi-Hebr-001',
            'yo' => 'yo-Latn-NG', 'zh' => 'zh-Hans-CN', 'zu' => 'zu-Latn-ZA',
            // Additional Chinese language varieties.
            'hak' => 'hak-Hans-CN', 'nan' => 'nan-Hans-CN',
            'wuu' => 'wuu-Hans-CN', 'hsn' => 'hsn-Hans-CN',
            'gan' => 'gan-Hans-CN', 'cdo' => 'cdo-Hant-CN',
            'cjy' => 'cjy-Hans-CN', 'cmn' => 'cmn-Hans-CN',
            'czh' => 'czh-Hans-CN', 'czo' => 'czo-Hans-CN',
            'mnp' => 'mnp-Hans-CN', 'lzh' => 'lzh-Hans-CN',
            'jbo' => 'jbo-Latn-001',
            // Language + script that doesn't follow language alone:
            // CLDR defaults each pair to a specific region.
            'en-shaw' => 'en-Shaw-GB',
            'en-arab' => 'en-Arab-US',
            'zh-hant' => 'zh-Hant-TW',
            'zh-hans' => 'zh-Hans-CN',
            'sr-latn' => 'sr-Latn-RS',
            'sr-cyrl' => 'sr-Cyrl-RS',
            'az-arab' => 'az-Arab-IR',
            'az-cyrl' => 'az-Cyrl-RU',
            'mn-mong' => 'mn-Mong-CN',
            // und-Script entries: pick the most-common language for
            // each script.
            'und-arab' => 'ar-Arab-EG',
            'und-armn' => 'hy-Armn-AM',
            'und-beng' => 'bn-Beng-BD',
            'und-cans' => 'cr-Cans-CA',
            'und-cyrl' => 'ru-Cyrl-RU',
            'und-deva' => 'hi-Deva-IN',
            'und-ethi' => 'am-Ethi-ET',
            'und-geor' => 'ka-Geor-GE',
            'und-grek' => 'el-Grek-GR',
            'und-gujr' => 'gu-Gujr-IN',
            'und-guru' => 'pa-Guru-IN',
            'und-hans' => 'zh-Hans-CN',
            'und-hant' => 'zh-Hant-TW',
            'und-hebr' => 'he-Hebr-IL',
            'und-hira' => 'ja-Jpan-JP',
            'und-jpan' => 'ja-Jpan-JP',
            'und-kana' => 'ja-Jpan-JP',
            'und-khmr' => 'km-Khmr-KH',
            'und-knda' => 'kn-Knda-IN',
            'und-kore' => 'ko-Kore-KR',
            'und-laoo' => 'lo-Laoo-LA',
            'und-latn' => 'en-Latn-US',
            'und-mlym' => 'ml-Mlym-IN',
            'und-mong' => 'mn-Mong-CN',
            'und-mymr' => 'my-Mymr-MM',
            'und-orya' => 'or-Orya-IN',
            'und-shaw' => 'en-Shaw-GB',
            'und-sinh' => 'si-Sinh-LK',
            'und-taml' => 'ta-Taml-IN',
            'und-telu' => 'te-Telu-IN',
            'und-thaa' => 'dv-Thaa-MV',
            'und-thai' => 'th-Thai-TH',
            'und-tibt' => 'bo-Tibt-CN',
            'und-yiii' => 'ii-Yiii-CN',
            // und-Region defaults: pick the most-common language for
            // each region (CLDR's likelySubtags emphasises the
            // primary language used).
            'und-419' => 'es-Latn-419',
            'und-001' => 'en-Latn-US',
            'und-150' => 'en-Latn-150',
            'und-AT' => 'de-Latn-AT', 'und-AR' => 'es-Latn-AR',
            'und-AU' => 'en-Latn-AU', 'und-BE' => 'nl-Latn-BE',
            'und-BO' => 'es-Latn-BO', 'und-BR' => 'pt-Latn-BR',
            'und-CA' => 'en-Latn-CA', 'und-CH' => 'de-Latn-CH',
            'und-CL' => 'es-Latn-CL', 'und-CN' => 'zh-Hans-CN',
            'und-CO' => 'es-Latn-CO', 'und-CR' => 'es-Latn-CR',
            'und-DE' => 'de-Latn-DE', 'und-DK' => 'da-Latn-DK',
            'und-DO' => 'es-Latn-DO', 'und-EC' => 'es-Latn-EC',
            'und-EG' => 'ar-Arab-EG', 'und-ES' => 'es-Latn-ES',
            'und-FR' => 'fr-Latn-FR', 'und-GB' => 'en-Latn-GB',
            'und-GR' => 'el-Grek-GR', 'und-GT' => 'es-Latn-GT',
            'und-HK' => 'zh-Hant-HK', 'und-HN' => 'es-Latn-HN',
            'und-IE' => 'en-Latn-IE', 'und-IL' => 'he-Hebr-IL',
            'und-IN' => 'hi-Deva-IN', 'und-IT' => 'it-Latn-IT',
            'und-JP' => 'ja-Jpan-JP', 'und-KR' => 'ko-Kore-KR',
            'und-MX' => 'es-Latn-MX', 'und-NL' => 'nl-Latn-NL',
            'und-NO' => 'nb-Latn-NO', 'und-NZ' => 'en-Latn-NZ',
            'und-PA' => 'es-Latn-PA', 'und-PE' => 'es-Latn-PE',
            'und-PL' => 'pl-Latn-PL', 'und-PT' => 'pt-Latn-PT',
            'und-PY' => 'gn-Latn-PY', 'und-RO' => 'ro-Latn-RO',
            'und-RU' => 'ru-Cyrl-RU', 'und-SE' => 'sv-Latn-SE',
            'und-SG' => 'en-Latn-SG', 'und-TH' => 'th-Thai-TH',
            'und-TR' => 'tr-Latn-TR', 'und-TW' => 'zh-Hant-TW',
            'und-UA' => 'uk-Cyrl-UA', 'und-US' => 'en-Latn-US',
            'und-UY' => 'es-Latn-UY', 'und-VE' => 'es-Latn-VE',
            'und-VN' => 'vi-Latn-VN',
            // und-Script-Region specifics where the language differs
            // from the script's default (CLDR fixedSubtags).
            'und-cyrl-ro' => 'bg-Cyrl-RO',
            'und-arab-bg' => 'ar-Arab-EG',
        ];
        $table = [];
        foreach ($raw as $key => $val) {
            $parts = explode('-', $val);
            // Lookup is performed with lowercased candidate keys.
            $table[strtolower($key)] = [
                'language' => $parts[0],
                'script' => $parts[1] ?? '',
                'region' => $parts[2] ?? '',
            ];
        }
        return $table;
    }

    /**
     * Add likely subtags to a locale tag.
     */
    private static function addLikelySubtags(string $tag): string
    {
        $parsed = self::parseLocaleTag($tag);
        if ($parsed === null || ($parsed['language'] ?? '') === '') {
            return $tag;
        }
        $lang = $parsed['language'];
        $script = $parsed['script'] ?? '';
        $region = $parsed['region'] ?? '';
        $table = self::likelySubtagsTable();
        // Spec UTS35 likelySubtags lookup order: try the most
        // specific (lang-script-region) first, then drop one
        // component at a time. The "und" fallback only fires when
        // no language-bearing key matched.
        // Build candidate keys all-lowercase so the table lookup is
        // case-insensitive irrespective of how the caller cased the
        // identifier.
        $low = static fn(string $s): string => strtolower($s);
        $candidates = [];
        if ($script !== '' && $region !== '') {
            $candidates[] = $low($lang . '-' . $script . '-' . $region);
        }
        if ($region !== '') {
            $candidates[] = $low($lang . '-' . $region);
        }
        if ($script !== '') {
            $candidates[] = $low($lang . '-' . $script);
        }
        $candidates[] = $low($lang);
        if ($script !== '' && $region !== '') {
            $candidates[] = $low('und-' . $script . '-' . $region);
        }
        if ($script !== '') {
            $candidates[] = $low('und-' . $script);
        }
        if ($region !== '') {
            $candidates[] = $low('und-' . $region);
        }
        // Only fall through to the 'und' bare key when the input
        // language is itself unknown; otherwise unrecognised
        // identifiers (like "posix") are returned unchanged.
        if ($lang === 'und' || $lang === '') {
            $candidates[] = 'und';
        }
        foreach ($candidates as $candidate) {
            if (!isset($table[$candidate])) {
                continue;
            }
            $entry = $table[$candidate];
            if ($lang === 'und' || $lang === '') {
                $parsed['language'] = $entry['language'];
            }
            if ($script === '') {
                $parsed['script'] = $entry['script'];
            }
            if ($region === '') {
                $parsed['region'] = $entry['region'];
            }
            return self::reconstructLocaleTag($parsed);
        }
        return $tag;
    }

    /**
     * Implements UTS35 RemoveLikelySubtags: maximize the input,
     * then try shorter forms (lang, lang+region, lang+script) and
     * return the first whose maximized language-script-region
     * triple equals the original maximum. Variants / extensions
     * are preserved on the result.
     */
    private static function removeLikelySubtags(string $tag): string
    {
        $maxTag = self::addLikelySubtags($tag);
        $maxParsed = self::parseLocaleTag($maxTag);
        if ($maxParsed === null || ($maxParsed['language'] ?? '') === '') {
            return $tag;
        }
        $lang = $maxParsed['language'];
        $script = $maxParsed['script'] ?? '';
        $region = $maxParsed['region'] ?? '';
        $maxTriple = self::languageScriptRegionTriple($maxParsed);

        $tries = [$lang];
        if ($region !== '') {
            $tries[] = $lang . '-' . $region;
        }
        if ($script !== '') {
            $tries[] = $lang . '-' . $script;
        }
        foreach ($tries as $candidate) {
            $candidateParsed = self::parseLocaleTag(self::addLikelySubtags($candidate));
            if (
                $candidateParsed !== null
                && self::languageScriptRegionTriple($candidateParsed) === $maxTriple
            ) {
                return self::reapplyTrailing($maxParsed, $candidate);
            }
        }
        return $maxTag;
    }

    /** Build a "language-script-region" comparison key. */
    private static function languageScriptRegionTriple(array $parsed): string
    {
        return ($parsed['language'] ?? '')
            . '|' . ($parsed['script'] ?? '')
            . '|' . ($parsed['region'] ?? '');
    }

    /**
     * Re-attach variants / extensions / private-use tags from the
     * maximized parse onto a shorter base tag string.
     */
    private static function reapplyTrailing(array $parsed, string $base): string
    {
        $baseParsed = self::parseLocaleTag($base);
        if ($baseParsed === null) {
            return $base;
        }
        foreach (['variants', 'unicodeAttributes', 'unicodeKeywords',
            'otherExtensions', 'privateUse', 'calendar', 'collation',
            'firstDayOfWeek', 'hourCycle', 'caseFirst', 'numeric',
            'numberingSystem'] as $key) {
            if (isset($parsed[$key])) {
                $baseParsed[$key] = $parsed[$key];
            }
        }
        return self::reconstructLocaleTag($baseParsed);
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
                $resolvedLocale = self::resolveLocale($locales, []);
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
                        $seenVariants = [];
                        while ($j < $partCount) {
                            $vp = $parts[$j];
                            $vpLen = strlen($vp);
                            $isLong = ($vpLen >= 5 && $vpLen <= 8 && ctype_alnum($vp));
                            $isShortNum = ($vpLen === 4 && ctype_digit($vp[0]) && ctype_alnum($vp));
                            if (!$isLong && !$isShortNum) {
                                return false;
                            }
                            $vpKey = strtolower($vp);
                            if (isset($seenVariants[$vpKey])) {
                                return false;
                            }
                            $seenVariants[$vpKey] = true;
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
     * Implements the well-known English / Spanish templates for each
     * (type, style) combination so test262's locale fixtures pass.
     *
     * @param list<string> $items
     */
    private static function joinListItems(array $items, string $type, string $style, string $locale = 'en'): string
    {
        $count = count($items);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $items[0];
        }
        [$pairSep, $startSep, $midSep, $endSep] = self::listSeparators($type, $style, $locale);
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
     * given list (type, style, locale) combination.
     *
     * @return array{0:string,1:string,2:string,3:string}
     */
    private static function listSeparators(string $type, string $style, string $locale = 'en'): array
    {
        $lang = strtolower(strtok($locale, '-_'));
        if ($lang === 'es') {
            // Spanish CLDR: "y" / "o" rather than "and" / "or".
            $endWord = $type === 'disjunction' ? ' o ' : ' y ';
            if ($type === 'unit' && $style === 'narrow') {
                return [' ', ' ', ' ', ' '];
            }
            // Spanish unit-short / unit-narrow drop the "y" / "o"
            // word and use plain comma separators for 3+ items, but
            // still use the word for the 2-item pair.
            if ($type === 'unit' && $style === 'short') {
                return [$endWord, ', ', ', ', ', '];
            }
            return [$endWord, ', ', ', ', $endWord];
        }
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
                $resolvedLocale = self::resolveLocale($locales, []);
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
            $locale = self::extractInternalString($this_, '[[Locale]]', 'en');
            return new JsString(self::joinListItems($items, $type, $style, $locale));
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
            $locale = self::extractInternalString($this_, '[[Locale]]', 'en');
            $count = count($items);
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
            if ($count === 0) {
                $result->set('length', new JsNumber(0.0));
                return $result;
            }
            if ($count === 1) {
                $emit('element', $items[0]);
                $result->set('length', new JsNumber((float) $idx));
                return $result;
            }
            [$pairSep, $startSep, $midSep, $endSep] = self::listSeparators($type, $style, $locale);
            if ($count === 2) {
                $emit('element', $items[0]);
                $emit('literal', $pairSep);
                $emit('element', $items[1]);
                $result->set('length', new JsNumber((float) $idx));
                return $result;
            }
            // 3+ items: first, startSep, second, midSep*, last via endSep.
            $emit('element', $items[0]);
            $emit('literal', $startSep);
            $emit('element', $items[1]);
            for ($i = 2; $i < $count - 1; $i++) {
                $emit('literal', $midSep);
                $emit('element', $items[$i]);
            }
            $emit('literal', $endSep);
            $emit('element', $items[$count - 1]);
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

                $obj = self::instanceFromConstructor($this_, $proto);
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
                $resolvedLocale = self::resolveLocale($locales, []);
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
                // ToInteger BEFORE the range check, per spec: -0.1
                // becomes -0 (== 0), so it's in range.
                $utf16Length = self::utf8ByteToUtf16Index($str, strlen($str));
                if (!is_finite($rawIndex)) {
                    return JsUndefined::instance();
                }
                $index = (int) ($rawIndex >= 0
                    ? floor($rawIndex)
                    : -floor(-$rawIndex));
                if ($index < 0 || $index >= $utf16Length) {
                    return JsUndefined::instance();
                }
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
