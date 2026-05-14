<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * Intl namespace object and all Intl constructors.
 *
 * Uses PHP's intl extension (ICU) when available for locale-sensitive operations.
 */
class IntlObject
{
    use Intl\SupportedValuesOfSection;
    use Intl\CollatorSection;
    use Intl\NumberFormatSection;
    use Intl\DateTimeFormatSection;
    use Intl\PluralRulesSection;
    use Intl\LocaleSection;
    use Intl\DisplayNamesSection;
    use Intl\ListFormatSection;
    use Intl\RelativeTimeFormatSection;
    use Intl\SegmenterSection;
    use Intl\DurationFormatSection;

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
        self::installDurationFormat($intl);

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
     * Resolve locale: pick best available from the requested list.
     * Returns the resolved locale string.
     *
     * @param array<mixed> $allowedExtensions
     * @param array<mixed> $requestedLocales
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
        // Parse, filter the -u- keywords against the allow-list, drop
        // unicodeAttributes (per spec they have no effect), then
        // reconstruct so other extensions (-t-, -x-, etc.) and the
        // private-use tail survive untouched.
        $parsed = self::parseLocaleTag($tag);
        if ($parsed === null) {
            return $tag;
        }
        $kw = $parsed['unicodeKeywords'] ?? [];
        $filtered = [];
        foreach ($kw as $key => $vals) {
            if (!in_array($key, $allowedKeys, true)) {
                continue;
            }
            $combined = is_array($vals) ? implode('-', $vals) : (string) $vals;
            if (!self::isRecognisedUnicodeKeywordValue($key, $combined)) {
                continue;
            }
            $filtered[$key] = $vals;
        }
        if (empty($filtered)) {
            unset($parsed['unicodeKeywords']);
        } else {
            $parsed['unicodeKeywords'] = $filtered;
        }
        unset($parsed['unicodeAttributes']);
        // Drop legacy slots whose keyword was filtered out so the
        // legacy-slot layer in reconstructLocaleTag doesn't re-add
        // them.
        $legacyMap = [
            'ca' => 'calendar',
            'co' => 'collation',
            'fw' => 'firstDayOfWeek',
            'hc' => 'hourCycle',
            'kf' => 'caseFirst',
            'kn' => 'numeric',
            'nu' => 'numberingSystem',
        ];
        foreach ($legacyMap as $key => $slot) {
            if (!isset($filtered[$key])) {
                unset($parsed[$slot]);
            }
        }
        return self::reconstructLocaleTag($parsed);
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
     *
     * The `$intrinsicName` argument enables spec-compliant
     * GetPrototypeFromConstructor cross-realm fallback: when
     * `NewTarget.prototype` is not an Object, the prototype is taken
     * from `GetFunctionRealm(NewTarget).Intl.<intrinsicName>.prototype`
     * instead of the current realm's installed prototype.
     */
    private static function instanceFromConstructor(
        JsValue $this_,
        JsObject $proto,
        ?string $intrinsicName = null,
    ): JsObject {
        if (
            $this_ instanceof JsObject
            && !$this_->get('[[NewTarget]]') instanceof JsUndefined
        ) {
            // Per spec OrdinaryCreateFromConstructor: GetPrototypeFromConstructor
            // is called on NewTarget, which Gets the "prototype" property. A
            // poisoned getter on NewTarget.prototype must surface here, before
            // any option validation in the constructor body.
            $newTarget = $this_->get('[[NewTarget]]');
            if ($newTarget instanceof JsObject) {
                $resolved = self::resolveProtoFromCtor($newTarget, $intrinsicName);
                if ($resolved !== null) {
                    $this_->setPrototype($resolved);
                }
            }
            return $this_;
        }
        return new JsObject($proto);
    }

    /**
     * Spec GetPrototypeFromConstructor for Intl constructors: returns
     * `NewTarget.prototype` when it's an Object, otherwise falls back to
     * `GetFunctionRealm(NewTarget).Intl.<intrinsicName>.prototype`.
     * Returns null when no lookup yields an object, in which case the
     * caller leaves the current prototype in place.
     */
    private static function resolveProtoFromCtor(JsValue $newTarget, ?string $intrinsicName): ?JsObject
    {
        $ntProto = $newTarget instanceof JsObject ? $newTarget->get('prototype') : JsUndefined::instance();
        if ($ntProto instanceof JsObject) {
            return $ntProto;
        }
        if ($intrinsicName === null) {
            return null;
        }
        $realm = \Phasis\Spec\AbstractOperations::getFunctionRealm($newTarget);
        if ($realm === null) {
            return null;
        }
        $globalEnv = $realm->getGlobalEnv();
        if (!$globalEnv->has('Intl')) {
            return null;
        }
        $intl = $globalEnv->get('Intl');
        if (!$intl instanceof JsObject) {
            return null;
        }
        $ctor = $intl->get($intrinsicName);
        if (!$ctor instanceof JsObject) {
            return null;
        }
        $proto = $ctor->get('prototype');
        return $proto instanceof JsObject ? $proto : null;
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
            $result->set('length', JsNumber::of((float) count($canonicalized)));
            return $result;
        };
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
            // Use [[DefineOwnProperty]] (data descriptors) so a
            // tainted Array.prototype setter on index 0 doesn't fire
            // — the spec creates each entry via CreateDataProperty,
            // not [[Set]].
            $result = new JsArray();
            $count = 0;
            foreach ($canonicalized as $tag) {
                if ($available === []) {
                    $result->defineOwnProperty(
                        (string) $count,
                        PropertyDescriptor::data(new JsString($tag), true, true, true),
                    );
                    $count++;
                    continue;
                }
                $candidate = str_replace('-', '_', $tag);
                $best = \Locale::lookup($available, $candidate, true, '');
                if ($best === '' || $best === null) {
                    continue;
                }
                $result->defineOwnProperty(
                    (string) $count,
                    PropertyDescriptor::data(new JsString($tag), true, true, true),
                );
                $count++;
            }
            $result->set('length', JsNumber::of((float) $count));
            return $result;
        }, 1);
        return $fn;
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
