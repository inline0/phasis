<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\String;

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
use Phasis\BuiltIn\SymbolConstructor;
use Phasis\BuiltIn\UnicodeCaseTables;

/**
 * StringPrototype trait part: StringCase. Composed into
 * StringPrototype via `use String\StringCase;`. `self::`/`$this->`
 * resolve into the composing class.
 */
trait StringCase
{
    private static function toLowerCase(): \Closure
    {
        return function (JsValue $this_): JsValue {
            return new JsString(self::caseFoldPreserveSurrogates(self::extractString($this_), false));
        };
    }

    private static function toUpperCase(): \Closure
    {
        return function (JsValue $this_): JsValue {
            return new JsString(self::caseFoldPreserveSurrogates(self::extractString($this_), true));
        };
    }

    /**
     * Apply Unicode 17.0.0 Default Case Conversion (per ECMA-262 toUpperCase
     * and toLowerCase). Host mbstring and ICU can lag Unicode releases,
     * so we bundle the authoritative case tables in
     * UnicodeCaseTables and walk codepoints ourselves whenever the input
     * contains non-ASCII bytes. Pure-ASCII strings keep the existing
     * C-level fast path via strtoupper / strtolower.
     *
     * Lone-surrogate (CESU-8) byte sequences 0xED [0xA0-0xBF] [0x80-0xBF]
     * are passed through unchanged. These are unpaired UTF-16 surrogates
     * the lexer encoded into the UTF-8 string and they have no case
     * mapping.
     *
     * Final_Sigma (Unicode 3.13 conditional, unconditional in locale-
     * default toLowerCase) is implemented inline so that "ΕΣ" lowercases
     * to "ες" rather than "εσ", matching the behaviour ICU produced when
     * we delegated the whole string to mb_strtolower.
     */
    private static function caseFoldPreserveSurrogates(string $str, bool $upper): string
    {
        $len = strlen($str);
        if ($len === 0) {
            return '';
        }

        // ASCII fast path: if every byte is < 0x80, strtolower / strtoupper
        // suffice and match the Unicode mapping for U+0000..U+007F.
        $nonAscii = false;
        for ($i = 0; $i < $len; $i++) {
            if (ord($str[$i]) >= 0x80) {
                $nonAscii = true;
                break;
            }
        }
        if (!$nonAscii) {
            return $upper ? strtoupper($str) : strtolower($str);
        }

        // Decode UTF-8 (with CESU-8 lone-surrogate pass-through) into an
        // array of scalar code points. Lone surrogates are kept as their
        // 0xD800..0xDFFF code-point value so we can re-encode them
        // unchanged.
        $cps = self::decodeUtf8WithSurrogates($str);
        $n = count($cps);

        $result = '';
        $table = $upper ? UnicodeCaseTables::UPPER : UnicodeCaseTables::LOWER;
        for ($i = 0; $i < $n; $i++) {
            $cp = $cps[$i];

            // Pass through lone surrogates verbatim. No case mapping.
            if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                $result .= self::encodeCesu8Surrogate($cp);
                continue;
            }

            // ASCII fast path inside the walk.
            if ($cp < 0x80) {
                if ($upper) {
                    if ($cp >= 0x61 && $cp <= 0x7A) {
                        $cp -= 0x20;
                    }
                } else {
                    if ($cp >= 0x41 && $cp <= 0x5A) {
                        $cp += 0x20;
                    }
                }
                $result .= chr($cp);
                continue;
            }

            // Final_Sigma: U+03A3 (capital sigma) lowercases to U+03C2
            // when preceded by a sequence of one or more cased
            // characters with intervening case-ignorable characters, and
            // NOT followed by such a sequence. See SpecialCasing.txt and
            // Unicode UAX #29.
            if (!$upper && $cp === 0x03A3) {
                if (self::isFinalSigmaContext($cps, $i)) {
                    $result .= "\xCF\x82"; // U+03C2
                    continue;
                }
            }

            if (isset($table[$cp])) {
                foreach ($table[$cp] as $outCp) {
                    $result .= self::encodeUtf8($outCp);
                }
                continue;
            }

            $result .= self::encodeUtf8($cp);
        }

        return $result;
    }

    /**
     * Test whether the sigma at code-point index $i in $cps satisfies
     * the Final_Sigma condition from Unicode SpecialCasing.txt:
     *   Before: at least one cased character with intervening
     *           case-ignorable characters.
     *   After:  no cased character with intervening case-ignorable
     *           characters.
     *
     * @param array<int, int> $cps
     */
    private static function isFinalSigmaContext(array $cps, int $i): bool
    {
        $hasBeforeCased = false;
        for ($j = $i - 1; $j >= 0; $j--) {
            $cp = $cps[$j];
            if (self::isCaseIgnorable($cp)) {
                continue;
            }
            if (self::isCased($cp)) {
                $hasBeforeCased = true;
            }
            break;
        }
        if (!$hasBeforeCased) {
            return false;
        }
        $n = count($cps);
        for ($j = $i + 1; $j < $n; $j++) {
            $cp = $cps[$j];
            if (self::isCaseIgnorable($cp)) {
                continue;
            }
            if (self::isCased($cp)) {
                return false;
            }
            break;
        }
        return true;
    }

    /** Check the Unicode Cased property used by the Final_Sigma context. */
    private static function isCased(int $cp): bool
    {
        if (($cp >= 0x41 && $cp <= 0x5A) || ($cp >= 0x61 && $cp <= 0x7A)) {
            return true;
        }
        if ($cp < 0x80) {
            return false;
        }
        return self::inUnicodeCaseRanges($cp, UnicodeCaseTables::CASED_RANGES);
    }

    /** Check the Unicode Case_Ignorable property used by the Final_Sigma context. */
    private static function isCaseIgnorable(int $cp): bool
    {
        return self::inUnicodeCaseRanges($cp, UnicodeCaseTables::CASE_IGNORABLE_RANGES);
    }

    /** @param list<array{int, int}> $ranges Sorted, disjoint inclusive ranges. */
    private static function inUnicodeCaseRanges(int $cp, array $ranges): bool
    {
        $low = 0;
        $high = count($ranges) - 1;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            [$first, $last] = $ranges[$middle];
            if ($cp < $first) {
                $high = $middle - 1;
            } elseif ($cp > $last) {
                $low = $middle + 1;
            } else {
                return true;
            }
        }
        return false;
    }

    private static function toLocaleLowerCase(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $locale = self::pickToLocaleCaseLocale($args[0] ?? null);
            $lang = $locale !== null ? strtolower(strtok($locale, '-_')) : null;
            // Lithuanian: insert U+0307 immediately after lowercased I/J/Į
            // when the following combining sequence contains a class 230 mark
            // (with possible class 220 marks between). ICU's lt-Lower
            // transliterator returns the marks in canonical order, so the dot
            // ends up *after* class 220 — the spec demands it right after the
            // base letter. Hand-roll the rule.
            if ($lang === 'lt') {
                $ltResult = self::lithuanianLower($str);
                if ($ltResult !== null) {
                    return new JsString($ltResult);
                }
            }
            $result = self::applyLocaleCaseTransliterator($str, $locale, 'Lower');
            if ($result !== null) {
                return new JsString($result);
            }
            return new JsString(mb_strtolower($str, 'UTF-8'));
        };
    }

    private static function toLocaleUpperCase(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $locale = self::pickToLocaleCaseLocale($args[0] ?? null);
            $lang = $locale !== null ? strtolower(strtok($locale, '-_')) : null;
            if ($lang === 'lt') {
                $ltResult = self::lithuanianUpper($str);
                if ($ltResult !== null) {
                    return new JsString($ltResult);
                }
            }
            $result = self::applyLocaleCaseTransliterator($str, $locale, 'Upper');
            if ($result !== null) {
                return new JsString($result);
            }
            return new JsString(mb_strtoupper($str, 'UTF-8'));
        };
    }

    private static function lithuanianLower(string $str): ?string
    {
        if (!class_exists(\IntlChar::class)) {
            return null;
        }
        $cps = mb_str_split($str, 1, 'UTF-8');
        $n = count($cps);
        $result = '';
        for ($i = 0; $i < $n; $i++) {
            $cp = mb_ord($cps[$i], 'UTF-8');
            if ($cp === 0x0049 || $cp === 0x004A || $cp === 0x012E) {
                $hasClass230 = false;
                for ($j = $i + 1; $j < $n; $j++) {
                    $cpJ = mb_ord($cps[$j], 'UTF-8');
                    $ccc = \IntlChar::getCombiningClass($cpJ);
                    if ($ccc === 0) {
                        break;
                    }
                    if ($ccc === 230) {
                        $hasClass230 = true;
                        break;
                    }
                }
                $lower = match ($cp) {
                    0x0049 => 0x0069,
                    0x004A => 0x006A,
                    0x012E => 0x012F,
                };
                $result .= mb_chr($lower, 'UTF-8');
                if ($hasClass230) {
                    $result .= mb_chr(0x0307, 'UTF-8');
                }
                continue;
            }
            // Precomposed I-with-accent-above: decompose to i + U+0307 + accent.
            $precomposed = match ($cp) {
                0x00CC => [0x0069, 0x0307, 0x0300],
                0x00CD => [0x0069, 0x0307, 0x0301],
                0x0128 => [0x0069, 0x0307, 0x0303],
                default => null,
            };
            if ($precomposed !== null) {
                foreach ($precomposed as $rcp) {
                    $result .= mb_chr($rcp, 'UTF-8');
                }
                continue;
            }
            $result .= mb_strtolower($cps[$i], 'UTF-8');
        }
        return $result;
    }

    private static function lithuanianUpper(string $str): ?string
    {
        if (!class_exists(\IntlChar::class)) {
            return null;
        }
        // SpecialCasing.txt: when uppercasing in lt, U+0307 (combining dot
        // above) is removed if preceded by a Soft_Dotted character with no
        // intervening class 0 or class 230 character. Walk backward when we
        // see U+0307 to apply that condition.
        $cps = mb_str_split($str, 1, 'UTF-8');
        $n = count($cps);
        $result = '';
        for ($i = 0; $i < $n; $i++) {
            $cp = mb_ord($cps[$i], 'UTF-8');
            if ($cp === 0x0307) {
                $afterSoftDotted = false;
                for ($j = $i - 1; $j >= 0; $j--) {
                    $cpJ = mb_ord($cps[$j], 'UTF-8');
                    $ccc = \IntlChar::getCombiningClass($cpJ);
                    if ($ccc === 0) {
                        if (\IntlChar::hasBinaryProperty($cpJ, \IntlChar::PROPERTY_SOFT_DOTTED)) {
                            $afterSoftDotted = true;
                        }
                        break;
                    }
                    if ($ccc === 230) {
                        break;
                    }
                }
                if ($afterSoftDotted) {
                    continue;
                }
            }
            $result .= mb_strtoupper($cps[$i], 'UTF-8');
        }
        return $result;
    }

    /**
     * Resolve the locales argument to a single locale identifier
     * (or null when none is provided / valid). Accepts a string or
     * an array; the first entry wins.
     */
    private static function pickToLocaleCaseLocale(mixed $arg): ?string
    {
        if ($arg === null) {
            return null;
        }
        $locales = \Phasis\BuiltIn\IntlObject::canonicalizeLocaleList($arg);
        return $locales[0] ?? null;
    }

    /**
     * Apply ICU's locale-aware case transliteration. PHP exposes
     * Transliterator IDs of the form `<locale>-Upper` /
     * `<locale>-Lower` for the locales whose CLDR SpecialCasing
     * rules require it (tr, az, lt). Returns null when no
     * locale-specific transliterator is needed (caller falls back
     * to the locale-independent mb_strto{lower,upper}).
     */
    private static function applyLocaleCaseTransliterator(
        string $str,
        ?string $locale,
        string $direction,
    ): ?string {
        if ($locale === null || $locale === '' || !class_exists(\Transliterator::class)) {
            return null;
        }
        $lang = strtolower(strtok($locale, '-_'));
        // Only languages where SpecialCasing differs from the default.
        $useLocale = match ($lang) {
            'tr', 'az', 'lt' => $lang,
            default => null,
        };
        if ($useLocale === null) {
            return null;
        }
        $id = $useLocale . '-' . $direction;
        $tx = \Transliterator::create($id);
        if ($tx === null) {
            return null;
        }
        $result = $tx->transliterate($str);
        return is_string($result) ? $result : null;
    }
}
