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
 * Intl.SupportedValuesOf section. Composed into IntlObject via
 * `use Intl\SupportedValuesOfSection;`. `self::` references resolve into
 * the composing class so cross-section helpers continue to work.
 */
trait SupportedValuesOfSection
{
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
            $result->set('length', JsNumber::of((float) count($values)));
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
        // PHP's listIdentifiers() returns the canonical IANA names
        // (excluding deprecated aliases like
        // 'Canada/East-Saskatchewan' that ICU still enumerates),
        // matching the spec's CanonicalizeTimeZoneName output. Per
        // the spec we also include the Etc/GMT* fixed-offset zones
        // (PHP filters those out by default).
        $result = \DateTimeZone::listIdentifiers();
        // Per CLDR, only the offset-bearing Etc/GMT* zones are
        // canonical primary identifiers. Etc/GMT, Etc/UTC,
        // Etc/Universal etc. all alias UTC and aren't included.
        static $etcAllow = [
            'Etc/GMT+1', 'Etc/GMT+2', 'Etc/GMT+3', 'Etc/GMT+4',
            'Etc/GMT+5', 'Etc/GMT+6', 'Etc/GMT+7', 'Etc/GMT+8',
            'Etc/GMT+9', 'Etc/GMT+10', 'Etc/GMT+11', 'Etc/GMT+12',
            'Etc/GMT-1', 'Etc/GMT-2', 'Etc/GMT-3', 'Etc/GMT-4',
            'Etc/GMT-5', 'Etc/GMT-6', 'Etc/GMT-7', 'Etc/GMT-8',
            'Etc/GMT-9', 'Etc/GMT-10', 'Etc/GMT-11', 'Etc/GMT-12',
            'Etc/GMT-13', 'Etc/GMT-14',
        ];
        $etc = [];
        $allBc = \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC);
        foreach ($etcAllow as $id) {
            if (in_array($id, $allBc, true)) {
                $etc[] = $id;
            }
        }
        $result = array_unique(array_merge($result, $etc));
        if (!in_array('UTC', $result, true)) {
            $result[] = 'UTC';
        }
        sort($result);
        return $result;
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
     * Determine a locale's preferred default hour cycle (h11/h12/h23/h24)
     * by inspecting the "j" pattern returned by IntlDatePatternGenerator.
     * The first unquoted hour-letter run identifies the cycle:
     *   H -> h23, k -> h24, h -> h12, K -> h11.
     */
    private static function resolveLocaleHourCycle(string $resolvedLocale): ?string
    {
        if (!class_exists('IntlDatePatternGenerator')) {
            return null;
        }
        $loc = str_replace('-', '_', $resolvedLocale);
        $loc = preg_replace('/_(?:u|x)_.*$/', '', $loc) ?? $loc;
        try {
            $gen = new \IntlDatePatternGenerator($loc);
            $j = $gen->getBestPattern('j');
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($j) || $j === '') {
            return null;
        }
        $inQuote = false;
        $len = strlen($j);
        for ($i = 0; $i < $len; $i++) {
            $c = $j[$i];
            if ($c === "'") {
                $inQuote = !$inQuote;
                continue;
            }
            if ($inQuote) {
                continue;
            }
            switch ($c) {
                case 'H':
                    return 'h23';
                case 'k':
                    return 'h24';
                case 'h':
                    return 'h12';
                case 'K':
                    return 'h11';
            }
        }
        return null;
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
}
