<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Intl;

/**
 * Locale preference data from installed ICU and CLDR 48 script metadata.
 *
 * @see https://github.com/unicode-org/cldr/blob/release-48/common/properties/scriptMetadata.txt
 */
final class LocaleInfoData
{
    /** @return list<string> */
    public static function supportedCollations(): array
    {
        static $supported = null;
        if ($supported !== null) {
            return $supported;
        }
        $names = [];
        $locales = class_exists(\ResourceBundle::class)
            ? (\ResourceBundle::getLocales('ICUDATA-coll') ?: ['root']) : ['root'];
        foreach ($locales as $locale) {
            foreach (self::collations($locale) as $collation) {
                $names[$collation] = true;
            }
        }
        $supported = array_keys($names);
        sort($supported, SORT_STRING);
        return $supported;
    }

    /** @var array<string, string> */
    private static array $directions = [];

    /** @return list<string> */
    public static function collations(string $locale): array
    {
        if (!class_exists(\ResourceBundle::class)) {
            return [];
        }
        $base = preg_split('/-[a-z0-9]-/i', $locale)[0] ?? $locale;
        $parts = explode('-', $base);
        $parents = ['root'];
        while ($parts !== []) {
            $parents[] = implode('_', $parts);
            array_pop($parts);
        }
        $names = [];
        foreach (array_unique($parents) as $parent) {
            $bundle = \ResourceBundle::create($parent, 'ICUDATA-coll', false);
            $table = $bundle?->get('collations', false);
            if (!$table instanceof \ResourceBundle) {
                continue;
            }
            foreach ($table as $name => $data) {
                if (!is_string($name) || !($data instanceof \ResourceBundle) || str_starts_with($name, 'private-')) {
                    continue;
                }
                if (in_array($name, ['standard', 'search'], true)) {
                    continue;
                }
                $names[self::canonicalType($name)] = true;
            }
        }
        $result = array_keys($names);
        sort($result, SORT_STRING);
        return $result;
    }

    /**
     * @param list<string> $regions
     * @param list<string> $supported
     * @return list<string>
     */
    public static function calendars(string $language, array $regions, array $supported): array
    {
        $table = self::supplemental('calendarPreferenceData');
        foreach ($regions as $region) {
            $values = $table?->get($language . '_' . $region, false) ?? $table?->get($region, false);
            if (!$values instanceof \ResourceBundle) {
                continue;
            }
            $result = [];
            foreach ($values as $value) {
                if (is_string($value)) {
                    $canonical = self::canonicalType($value);
                    if (in_array($canonical, $supported, true) && !in_array($canonical, $result, true)) {
                        $result[] = $canonical;
                    }
                }
            }
            return $result === [] ? ['gregory'] : $result;
        }
        return ['gregory'];
    }

    /**
     * @param list<string> $regions
     * @return list<string>
     */
    public static function hourCycles(string $language, array $regions): array
    {
        $table = self::supplemental('timeData');
        foreach ($regions as $region) {
            $value = $table?->get($language . '_' . $region, false) ?? $table?->get($region, false);
            if (!$value instanceof \ResourceBundle) {
                continue;
            }
            $allowed = $value->get('allowed');
            $patterns = [$value->get('preferred')];
            if ($allowed instanceof \ResourceBundle) {
                foreach ($allowed as $pattern) {
                    $patterns[] = $pattern;
                }
            }
            $cycles = [];
            foreach ($patterns as $pattern) {
                if (is_string($pattern) && $pattern !== '') {
                    $cycle = match ($pattern[0]) {
                        'h' => 'h12', 'H' => 'h23', 'K' => 'h11', 'k' => 'h24', default => null,
                    };
                    if ($cycle !== null) {
                        $cycles[$cycle] = true;
                    }
                }
            }
            return array_keys($cycles);
        }
        return ['h23'];
    }

    /**
     * @param list<string> $regions
     * @return array{firstDay:int, weekend:list<int>}
     */
    public static function week(array $regions): array
    {
        $table = self::supplemental('weekData');
        foreach ($regions as $region) {
            $data = $table?->get($region, false);
            if (!is_array($data) || count($data) !== 6) {
                continue;
            }
            $weekend = [];
            $start = (int) $data[2] - 1;
            $end = (int) $data[4] - 1;
            if ((int) $data[3] === 86400000) {
                $start = ($start + 1) % 7;
            }
            if ((int) $data[5] === 0) {
                $end = ($end + 6) % 7;
            }
            for ($day = $start;; $day = ($day + 1) % 7) {
                $weekend[] = $day === 0 ? 7 : $day;
                if ($day === $end) {
                    break;
                }
            }
            sort($weekend, SORT_NUMERIC);
            $first = (int) $data[0] - 1;
            return ['firstDay' => $first === 0 ? 7 : $first, 'weekend' => $weekend];
        }
        return ['firstDay' => 1, 'weekend' => [6, 7]];
    }

    /** Default decimal numbering system for the locale, as provided by ICU. */
    public static function numberingSystem(string $locale): string
    {
        if (!class_exists(\ResourceBundle::class)) {
            return 'latn';
        }
        $bundle = \ResourceBundle::create(str_replace('-', '_', $locale), null);
        $numbers = $bundle?->get('NumberElements');
        $default = $numbers instanceof \ResourceBundle ? $numbers->get('default') : null;
        return is_string($default) ? $default : 'latn';
    }

    /** Script direction is undefined when CLDR has no directional classification. */
    public static function direction(string $script): ?string
    {
        if (self::$directions === []) {
            $lines = file(__DIR__ . '/Data/scriptMetadata.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines === false ? [] : $lines as $line) {
                if (str_starts_with($line, '#')) {
                    continue;
                }
                $fields = array_map('trim', explode(';', explode('#', $line)[0]));
                if (isset($fields[6]) && in_array($fields[6], ['YES', 'NO'], true)) {
                    self::$directions[$fields[0]] = $fields[6] === 'YES' ? 'rtl' : 'ltr';
                }
            }
        }
        return self::$directions[$script] ?? null;
    }

    private static function supplemental(string $name): ?\ResourceBundle
    {
        if (!class_exists(\ResourceBundle::class)) {
            return null;
        }
        $bundle = \ResourceBundle::create('supplementalData', null, false);
        $table = $bundle?->get($name);
        return $table instanceof \ResourceBundle ? $table : null;
    }

    private static function canonicalType(string $name): string
    {
        return match ($name) {
            'gregorian' => 'gregory', 'ethiopic-amete-alem' => 'ethioaa',
            'phonebook' => 'phonebk', 'traditional' => 'trad',
            'dictionary' => 'dict', 'gb2312han' => 'gb2312', default => $name,
        };
    }
}
