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
 * Intl.DisplayNames section. Composed into IntlObject via
 * `use Intl\DisplayNamesSection;`. `self::` references resolve into
 * the composing class so cross-section helpers continue to work.
 */
trait DisplayNamesSection
{
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

                // Per spec: OrdinaryCreateFromConstructor (which calls
                // Get(NewTarget, "prototype")) runs before reading options,
                // so a poisoned prototype getter must throw first.
                $obj = self::instanceFromConstructor($this_, $proto, 'DisplayNames');

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

            $fallback = self::extractInternalString($this_, '[[Fallback]]', 'code');
            if (extension_loaded('intl')) {
                $icuLocale = str_replace('-', '_', $locale);
                $displayName = match ($type) {
                    'language' => \Locale::getDisplayLanguage(str_replace('-', '_', $code), $icuLocale),
                    'region' => \Locale::getDisplayRegion('und_' . strtoupper($code), $icuLocale),
                    'script' => \Locale::getDisplayScript('und_' . ucfirst(strtolower($code)), $icuLocale),
                    'currency' => self::displayNameForCurrency($code, $icuLocale),
                    default => $code,
                };
                if ($displayName !== '' && $displayName !== false && $displayName !== null) {
                    return new JsString($displayName);
                }
            }

            // Per spec: fallback "none" returns undefined for an
            // unrecognised code; "code" returns the code itself.
            if ($fallback === 'none') {
                return JsUndefined::instance();
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
}
