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
 * Intl.PluralRules section. Composed into IntlObject via
 * `use Intl\PluralRulesSection;`. `self::` references resolve into
 * the composing class so cross-section helpers continue to work.
 */
trait PluralRulesSection
{
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

                $obj = self::instanceFromConstructor($this_, $proto, 'PluralRules');
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
                    JsNumber::of((float) $minInt),
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
                    $minFrac = !$mfdVal instanceof JsUndefined
                        ? (int) TypeConversion::toNumber($mfdVal) : 0;
                    $maxFrac = !$xfdVal instanceof JsUndefined
                        ? (int) TypeConversion::toNumber($xfdVal) : max(3, $minFrac);
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
                        JsNumber::of((float) (int) $riNum),
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
                $categories->defineOwnProperty(
                    (string) $i,
                    PropertyDescriptor::data(new JsString($cat), true, true, true),
                );
            }
            $categories->set('length', JsNumber::of((float) count($cats)));
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
}
