<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Intl;

/**
 * Decimal digit mappings from CLDR 48, including systems absent in older ICU.
 *
 * @see https://github.com/unicode-org/cldr/blob/release-48/common/supplemental/numberingSystems.xml
 */
final class NumberingSystemData
{
    /** @var array<string, list<string>>|null */
    private static ?array $systems = null;

    /** @var array<string, int>|null */
    private static ?array $digitValues = null;

    /** @var array<string, array<string, string>> */
    private static array $substitutions = [];

    /** @return list<string> */
    public static function identifiers(): array
    {
        return array_keys(self::systems());
    }

    /** Older ICU releases can format the locale before bundled digit substitution. */
    public static function createFormatter(string $locale, int $style): \NumberFormatter
    {
        try {
            return new \NumberFormatter($locale, $style);
        } catch (\IntlException) {
            $baseLocale = explode('@', $locale, 2)[0];
            return new \NumberFormatter($baseLocale . '@numbers=latn', $style);
        }
    }

    /** Substitute decimal digits while preserving locale punctuation and labels. */
    public static function substitute(string $formatted, string $system): string
    {
        if ($system === 'latn' && preg_match('/[^\x00-\x7f]/', $formatted) !== 1) {
            return $formatted;
        }
        if (isset(self::$substitutions[$system])) {
            return strtr($formatted, self::$substitutions[$system]);
        }
        $systems = self::systems();
        $digits = $systems[$system] ?? null;
        if ($digits === null) {
            return $formatted;
        }
        if (self::$digitValues === null) {
            self::$digitValues = [];
            foreach ($systems as $sourceDigits) {
                foreach ($sourceDigits as $value => $digit) {
                    // Decimal digits have positional meaning in a formatted
                    // number. Ordinary letters in labels retain their meaning.
                    if (preg_match('/^\p{Nd}$/u', $digit) === 1) {
                        self::$digitValues[$digit] = $value;
                    }
                }
            }
        }
        $replacements = [];
        foreach (self::$digitValues as $digit => $value) {
            $replacements[$digit] = $digits[$value];
        }
        self::$substitutions[$system] = $replacements;
        return strtr($formatted, $replacements);
    }

    /** @return array<string, list<string>> */
    private static function systems(): array
    {
        if (self::$systems !== null) {
            return self::$systems;
        }
        self::$systems = [];
        $xml = file_get_contents(__DIR__ . '/Data/numberingSystems.xml');
        if ($xml === false) {
            throw new \RuntimeException('Missing CLDR numbering system data');
        }
        preg_match_all('/<numberingSystem\b([^>]*)\/>/u', $xml, $entries, PREG_SET_ORDER);
        foreach ($entries as $entry) {
            if (
                preg_match('/\btype="numeric"/', $entry[1]) !== 1
                || preg_match('/\bid="([^"]+)"/', $entry[1], $id) !== 1
                || preg_match('/\bdigits="([^"]+)"/', $entry[1], $mapping) !== 1
            ) {
                continue;
            }
            $text = html_entity_decode($mapping[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            $digits = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
            if ($digits !== false && count($digits) === 10) {
                self::$systems[$id[1]] = $digits;
            }
        }
        ksort(self::$systems, SORT_STRING);
        return self::$systems;
    }
}
