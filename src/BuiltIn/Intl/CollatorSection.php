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
 * Intl.Collator section. Composed into IntlObject via
 * `use Intl\CollatorSection;`. `self::` references resolve into
 * the composing class so cross-section helpers continue to work.
 */
trait CollatorSection
{
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

                $obj = self::instanceFromConstructor($this_, $proto, 'Collator');
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
                return JsNumber::of((float) ($result === false ? 0 : $result));
            }

            // Fallback: PHP strcmp.
            $cmp = strcmp($x, $y);
            return JsNumber::of((float) ($cmp < 0 ? -1 : ($cmp > 0 ? 1 : 0)));
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
}
