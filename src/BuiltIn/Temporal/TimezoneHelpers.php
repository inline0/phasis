<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Temporal;

use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsBigInt;
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

/**
 * Temporal helper section (TimezoneHelpers). Composed into TemporalObject
 * via `use Temporal\TimezoneHelpers;`. `self::` references resolve into
 * the composing class.
 */
trait TimezoneHelpers
{
    // -----------------------------------------------------------------------
    // Helpers: timezone
    // -----------------------------------------------------------------------

    /**
     * Resolve a timezone string to a PHP DateTimeZone.
     * Supports IANA names, UTC offsets, and ISO datetime strings with timezone annotations.
     */
    private static function resolveTimeZone(string $tz): \DateTimeZone
    {
        // IANA's "backward" file lists deprecated zone names as Link
        // entries pointing at a canonical zone. PHP's tzdata stores
        // both names with separate (often divergent pre-1970) offset
        // histories: e.g. Africa/Asmera carries the LMT for Addis
        // Ababa (+02:27:16) while the canonical Africa/Asmara uses
        // its own LMT (+02:35:20). Per the Temporal spec, the link
        // and target must produce identical offsets at every instant.
        // Resolve the canonical name and use its zone object for all
        // offset queries; the original [[TimeZone]] slot continues
        // to expose the input identifier.
        $canon = self::ianaLinkCanonical($tz);
        if ($canon !== null) {
            try {
                return new \DateTimeZone($canon);
            } catch (\Throwable) {
                // Fall through to direct lookup.
            }
        }
        // Try direct timezone ID first (IANA names, UTC, etc.).
        try {
            return new \DateTimeZone($tz);
        } catch (\Throwable) {
            // Fall through to parse as ISO string.
        }

        // Try to extract timezone from an ISO datetime string.
        $parsed = self::parseTimeZoneFromISOString($tz);
        if ($parsed !== null) {
            try {
                return new \DateTimeZone($parsed);
            } catch (\Throwable) {
                throw new RangeError("Invalid time zone: {$tz}");
            }
        }

        throw new RangeError("Invalid time zone: {$tz}");
    }

    /**
     * Return zone transitions within the [$start, $end] window. When
     * the zone is in the vendored tzdata snapshot bundle, consult the
     * bundle for host-independent transition values (test262 probes
     * specific historical and DST rows that lag on Ubuntu CI tzdata
     * but match macOS and the test reference). Falls through to PHP's
     * DateTimeZone::getTransitions for any zone not in the bundle.
     *
     * Output mirrors PHP's getTransitions shape: a synthetic
     * state-at-start row first (ts = $start, with the active offset
     * and isdst from the most recent earlier transition), followed by
     * the actual transitions in (start, end]. Each row carries at
     * minimum 'ts' (int), 'offset' (int), 'isdst' (bool).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function getTzTransitions(string $tz, \DateTimeZone $tzObj, int $start, int $end): array
    {
        static $bundle = null;
        if ($bundle === null) {
            $path = dirname(__DIR__, 2) . '/config/tzdata-snapshot.php';
            $bundle = is_file($path) ? require $path : [];
            if (!is_array($bundle)) {
                $bundle = [];
            }
        }
        // Resolve through the IANA link table so legacy names hit the
        // same bundle entry as their canonical target.
        $canon = self::ianaLinkCanonical($tz);
        $lookup = $canon ?? $tz;
        if (!isset($bundle[$lookup])) {
            return $tzObj->getTransitions($start, $end);
        }
        $rows = $bundle[$lookup];
        // Find the state-at-start row: the most recent transition at
        // or before $start. Bundle rows are sorted ascending by ts.
        $stateOffset = $rows[0]['offset'];
        $stateIsDst = $rows[0]['isdst'];
        $out = [];
        foreach ($rows as $r) {
            if ($r['ts'] <= $start) {
                $stateOffset = $r['offset'];
                $stateIsDst = $r['isdst'];
                continue;
            }
            if ($r['ts'] > $end) {
                break;
            }
            $out[] = ['ts' => $r['ts'], 'offset' => $r['offset'], 'isdst' => $r['isdst']];
        }
        array_unshift($out, ['ts' => $start, 'offset' => $stateOffset, 'isdst' => $stateIsDst]);
        return $out;
    }

    /**
     * Resolve a deprecated IANA Link name (from tzdata "backward")
     * to its canonical Zone target. Returns null when the input is
     * already canonical or unknown. Comparisons are case-insensitive
     * to match IANA's own case-folding rules. Tracks tzdata 2024b.
     */
    private static function ianaLinkCanonical(string $name): ?string
    {
        static $links = null;
        if ($links === null) {
            $links = [
                'africa/asmera' => 'Africa/Asmara',
                'africa/timbuktu' => 'Africa/Bamako',
                'america/argentina/comodrivadavia' => 'America/Argentina/Catamarca',
                'america/atka' => 'America/Adak',
                'america/buenos_aires' => 'America/Argentina/Buenos_Aires',
                'america/catamarca' => 'America/Argentina/Catamarca',
                'america/coral_harbour' => 'America/Atikokan',
                'america/cordoba' => 'America/Argentina/Cordoba',
                'america/ensenada' => 'America/Tijuana',
                'america/fort_wayne' => 'America/Indiana/Indianapolis',
                'america/godthab' => 'America/Nuuk',
                'america/indianapolis' => 'America/Indiana/Indianapolis',
                'america/jujuy' => 'America/Argentina/Jujuy',
                'america/knox_in' => 'America/Indiana/Knox',
                'america/louisville' => 'America/Kentucky/Louisville',
                'america/mendoza' => 'America/Argentina/Mendoza',
                'america/montreal' => 'America/Toronto',
                'america/nipigon' => 'America/Toronto',
                'america/pangnirtung' => 'America/Iqaluit',
                'america/porto_acre' => 'America/Rio_Branco',
                'america/rainy_river' => 'America/Winnipeg',
                'america/rosario' => 'America/Argentina/Cordoba',
                'america/santa_isabel' => 'America/Tijuana',
                'america/shiprock' => 'America/Denver',
                'america/thunder_bay' => 'America/Toronto',
                'america/virgin' => 'America/St_Thomas',
                'america/yellowknife' => 'America/Edmonton',
                'antarctica/south_pole' => 'Antarctica/McMurdo',
                'asia/ashkhabad' => 'Asia/Ashgabat',
                'asia/calcutta' => 'Asia/Kolkata',
                'asia/choibalsan' => 'Asia/Ulaanbaatar',
                'asia/chongqing' => 'Asia/Shanghai',
                'asia/chungking' => 'Asia/Shanghai',
                'asia/dacca' => 'Asia/Dhaka',
                'asia/harbin' => 'Asia/Shanghai',
                'asia/istanbul' => 'Europe/Istanbul',
                'asia/kashgar' => 'Asia/Urumqi',
                'asia/katmandu' => 'Asia/Kathmandu',
                'asia/macao' => 'Asia/Macau',
                'asia/rangoon' => 'Asia/Yangon',
                'asia/saigon' => 'Asia/Ho_Chi_Minh',
                'asia/tel_aviv' => 'Asia/Jerusalem',
                'asia/thimbu' => 'Asia/Thimphu',
                'asia/ujung_pandang' => 'Asia/Makassar',
                'asia/ulan_bator' => 'Asia/Ulaanbaatar',
                'atlantic/faeroe' => 'Atlantic/Faroe',
                'atlantic/jan_mayen' => 'Arctic/Longyearbyen',
                'australia/act' => 'Australia/Sydney',
                'australia/canberra' => 'Australia/Sydney',
                'australia/currie' => 'Australia/Hobart',
                'australia/lhi' => 'Australia/Lord_Howe',
                'australia/nsw' => 'Australia/Sydney',
                'australia/north' => 'Australia/Darwin',
                'australia/queensland' => 'Australia/Brisbane',
                'australia/south' => 'Australia/Adelaide',
                'australia/tasmania' => 'Australia/Hobart',
                'australia/victoria' => 'Australia/Melbourne',
                'australia/west' => 'Australia/Perth',
                'australia/yancowinna' => 'Australia/Broken_Hill',
                'brazil/acre' => 'America/Rio_Branco',
                'brazil/denoronha' => 'America/Noronha',
                'brazil/east' => 'America/Sao_Paulo',
                'brazil/west' => 'America/Manaus',
                'cet' => 'Europe/Brussels',
                'cst6cdt' => 'America/Chicago',
                'canada/atlantic' => 'America/Halifax',
                'canada/central' => 'America/Winnipeg',
                'canada/eastern' => 'America/Toronto',
                'canada/mountain' => 'America/Edmonton',
                'canada/newfoundland' => 'America/St_Johns',
                'canada/pacific' => 'America/Vancouver',
                'canada/saskatchewan' => 'America/Regina',
                'canada/yukon' => 'America/Whitehorse',
                'chile/continental' => 'America/Santiago',
                'chile/easterisland' => 'Pacific/Easter',
                'cuba' => 'America/Havana',
                'eet' => 'Europe/Athens',
                'est' => 'America/Panama',
                'est5edt' => 'America/New_York',
                'egypt' => 'Africa/Cairo',
                'eire' => 'Europe/Dublin',
                'europe/belfast' => 'Europe/London',
                'europe/kiev' => 'Europe/Kyiv',
                'europe/nicosia' => 'Asia/Nicosia',
                'europe/tiraspol' => 'Europe/Chisinau',
                'europe/uzhgorod' => 'Europe/Kyiv',
                'europe/zaporozhye' => 'Europe/Kyiv',
                'gb' => 'Europe/London',
                'gb-eire' => 'Europe/London',
                'hst' => 'Pacific/Honolulu',
                'hongkong' => 'Asia/Hong_Kong',
                'iceland' => 'Atlantic/Reykjavik',
                'iran' => 'Asia/Tehran',
                'israel' => 'Asia/Jerusalem',
                'jamaica' => 'America/Jamaica',
                'japan' => 'Asia/Tokyo',
                'kwajalein' => 'Pacific/Kwajalein',
                'libya' => 'Africa/Tripoli',
                'met' => 'Europe/Brussels',
                'mst' => 'America/Phoenix',
                'mst7mdt' => 'America/Denver',
                'mexico/bajanorte' => 'America/Tijuana',
                'mexico/bajasur' => 'America/Mazatlan',
                'mexico/general' => 'America/Mexico_City',
                'nz' => 'Pacific/Auckland',
                'nz-chat' => 'Pacific/Chatham',
                'navajo' => 'America/Denver',
                'prc' => 'Asia/Shanghai',
                'pst8pdt' => 'America/Los_Angeles',
                'pacific/enderbury' => 'Pacific/Kanton',
                'pacific/johnston' => 'Pacific/Honolulu',
                'pacific/ponape' => 'Pacific/Pohnpei',
                'pacific/samoa' => 'Pacific/Pago_Pago',
                'pacific/truk' => 'Pacific/Chuuk',
                'pacific/yap' => 'Pacific/Chuuk',
                'poland' => 'Europe/Warsaw',
                'portugal' => 'Europe/Lisbon',
                'roc' => 'Asia/Taipei',
                'rok' => 'Asia/Seoul',
                'singapore' => 'Asia/Singapore',
                'turkey' => 'Europe/Istanbul',
                'us/alaska' => 'America/Anchorage',
                'us/aleutian' => 'America/Adak',
                'us/arizona' => 'America/Phoenix',
                'us/central' => 'America/Chicago',
                'us/east-indiana' => 'America/Indiana/Indianapolis',
                'us/eastern' => 'America/New_York',
                'us/hawaii' => 'Pacific/Honolulu',
                'us/indiana-starke' => 'America/Indiana/Knox',
                'us/michigan' => 'America/Detroit',
                'us/mountain' => 'America/Denver',
                'us/pacific' => 'America/Los_Angeles',
                'us/samoa' => 'Pacific/Pago_Pago',
                'w-su' => 'Europe/Moscow',
                'wet' => 'Europe/Lisbon',
            ];
        }
        return $links[strtolower($name)] ?? null;
    }

    /**
     * Return true when the given name is the canonical target of one
     * or more deprecated Link entries. Used to bypass ICU's
     * canonicalization when ICU lags IANA's preferred direction.
     */
    private static function isIanaLinkTarget(string $name): bool
    {
        static $targets = null;
        if ($targets === null) {
            $targets = [
                'Africa/Asmara' => true, 'Africa/Bamako' => true,
                'Africa/Cairo' => true, 'Africa/Tripoli' => true,
                'America/Argentina/Buenos_Aires' => true, 'America/Argentina/Catamarca' => true,
                'America/Argentina/Cordoba' => true, 'America/Argentina/Jujuy' => true,
                'America/Argentina/Mendoza' => true,
                'America/Adak' => true, 'America/Atikokan' => true, 'America/Tijuana' => true,
                'America/Indiana/Indianapolis' => true, 'America/Indiana/Knox' => true,
                'America/Kentucky/Louisville' => true, 'America/Toronto' => true,
                'America/Iqaluit' => true, 'America/Rio_Branco' => true,
                'America/Winnipeg' => true, 'America/Edmonton' => true, 'America/Denver' => true,
                'America/Halifax' => true, 'America/St_Johns' => true, 'America/Vancouver' => true,
                'America/Regina' => true, 'America/Whitehorse' => true, 'America/Santiago' => true,
                'America/Havana' => true, 'America/Panama' => true, 'America/New_York' => true,
                'America/Nuuk' => true, 'America/St_Thomas' => true, 'America/Sao_Paulo' => true,
                'America/Manaus' => true, 'America/Noronha' => true, 'America/Mazatlan' => true,
                'America/Mexico_City' => true, 'America/Phoenix' => true, 'America/Chicago' => true,
                'America/Detroit' => true, 'America/Los_Angeles' => true, 'America/Anchorage' => true,
                'America/Jamaica' => true,
                'Antarctica/McMurdo' => true,
                'Asia/Ashgabat' => true, 'Asia/Kolkata' => true, 'Asia/Ulaanbaatar' => true,
                'Asia/Shanghai' => true, 'Asia/Dhaka' => true, 'Asia/Urumqi' => true,
                'Asia/Kathmandu' => true, 'Asia/Macau' => true, 'Asia/Yangon' => true,
                'Asia/Ho_Chi_Minh' => true, 'Asia/Jerusalem' => true, 'Asia/Thimphu' => true,
                'Asia/Makassar' => true, 'Asia/Hong_Kong' => true, 'Asia/Tehran' => true,
                'Asia/Tokyo' => true, 'Asia/Taipei' => true, 'Asia/Seoul' => true,
                'Asia/Singapore' => true, 'Asia/Nicosia' => true,
                'Atlantic/Faroe' => true, 'Atlantic/Reykjavik' => true,
                'Arctic/Longyearbyen' => true,
                'Australia/Sydney' => true, 'Australia/Hobart' => true, 'Australia/Lord_Howe' => true,
                'Australia/Darwin' => true, 'Australia/Brisbane' => true, 'Australia/Adelaide' => true,
                'Australia/Melbourne' => true, 'Australia/Perth' => true, 'Australia/Broken_Hill' => true,
                'Europe/Athens' => true, 'Europe/Brussels' => true, 'Europe/Dublin' => true,
                'Europe/London' => true, 'Europe/Kyiv' => true, 'Europe/Chisinau' => true,
                'Europe/Lisbon' => true, 'Europe/Moscow' => true, 'Europe/Warsaw' => true,
                'Europe/Istanbul' => true,
                'Pacific/Auckland' => true, 'Pacific/Chatham' => true, 'Pacific/Easter' => true,
                'Pacific/Honolulu' => true, 'Pacific/Kanton' => true, 'Pacific/Kwajalein' => true,
                'Pacific/Pago_Pago' => true, 'Pacific/Pohnpei' => true, 'Pacific/Chuuk' => true,
            ];
        }
        return isset($targets[$name]);
    }

    /**
     * Extract a timezone identifier from an ISO datetime string.
     * Returns an IANA name (from bracket annotation) or a UTC offset, or null if not a valid tz string.
     */
    private static function parseTimeZoneFromISOString(string $str): ?string
    {
        // Reject minus zero year.
        if (preg_match('/^-0{4,6}-/', $str)) {
            throw new RangeError("reject minus zero as extended year: {$str}");
        }

        // Must look like a datetime string.
        // Pattern: date T time [offset] [annotation]
        // Allow seconds of 60 for leap second in the time portion.
        $dateTime = '/^(?:[+-]?\d{4,6})(?:-\d{2}-\d{2}|\d{4})[T ]\d{2}:?\d{2}/';
        if (!preg_match($dateTime, $str)) {
            return null;
        }

        // Reject bare datetime strings with no offset and no annotation.
        // First check for annotation brackets.
        $hasAnnotation = str_contains($str, '[');
        // Check for offset: Z or +/- after the time portion.
        // Need to be careful not to match the sign in the year part.
        $afterTime = preg_replace('/^[^T ]+[T ]/', '', $str);
        $hasOffset = (bool) preg_match('/[Zz]|[+-]\d{2}/', $afterTime);
        if (!$hasOffset && !$hasAnnotation) {
            return null;
        }

        // If there is a bracket annotation, extract the IANA name or offset-based tz.
        if (preg_match('/\[([^\]]+)\]/', $str, $m)) {
            $annotation = $m[1];
            // Skip calendar annotations.
            if (!str_starts_with($annotation, 'u-ca=') && !str_starts_with($annotation, '!u-ca=')) {
                // Validate: if this is a numeric offset with seconds (e.g., +23:59:60), reject it.
                if (preg_match('/^[+-]\d{2}:\d{2}:\d{2}/', $annotation)) {
                    throw new RangeError("leap second in time zone name not valid: {$str}");
                }
                return $annotation;
            }
        }

        // Extract the offset from the string (after time portion).
        // Reject sub-minute offsets (seconds portion in offset).
        if (preg_match('/([Zz]|[+-]\d{2}:?\d{2}(?::?\d{2}(?:[.,]\d+)?)?)(?:\[|$)/', $afterTime, $om)) {
            $offset = $om[1];
            if (strtoupper($offset) === 'Z') {
                return 'UTC';
            }
            // Check for sub-minute offset: if offset has seconds part, reject it.
            $cleanOffset = preg_replace('/[^0-9+-]/', '', $offset);
            // After removing non-digits (except sign), format is: +HHMM or +HHMMSS...
            $digits = ltrim($cleanOffset, '+-');
            if (strlen($digits) > 4) {
                // Has seconds component: always invalid as timezone, even if zero.
                throw new RangeError("ISO string with a sub-minute offset is not a valid time zone: {$str}");
            }
            // Also check for fractional seconds in the offset.
            if (preg_match('/[.,]\d+/', $offset)) {
                throw new RangeError("ISO string with a sub-minute offset is not a valid time zone: {$str}");
            }
            // Normalize to +HH:MM format.
            $sign = $offset[0];
            $h = substr($digits, 0, 2);
            $min = strlen($digits) >= 4 ? substr($digits, 2, 2) : '00';
            return "{$sign}{$h}:{$min}";
        }

        return null;
    }

    /**
     * @return array{year: int, month: int, day: int, hour: int, minute: int, second: int, millisecond: int, microsecond: int, nanosecond: int}
     */
    private static function epochNsToISOParts(string $ns, string $tz): array
    {
        // Convert epoch nanoseconds to date/time parts in the given timezone.
        $negative = isset($ns[0]) && $ns[0] === '-';
        $abs = $negative ? substr($ns, 1) : $ns;

        $secStr = bcdiv($abs, '1000000000', 0);
        $subNs = bcsub($abs, bcmul($secStr, '1000000000', 0), 0);

        if ($negative && $subNs !== '0') {
            $secStr = bcadd($secStr, '1', 0);
            $subNs = bcsub('1000000000', $subNs, 0);
        }

        $epochSec = $negative ? '-' . $secStr : $secStr;

        try {
            $dt = new \DateTimeImmutable('@' . $epochSec);
            $local = $dt->setTimezone(self::resolveTimeZone($tz));
        } catch (\Throwable) {
            return ['year' => 1970, 'month' => 1, 'day' => 1, 'hour' => 0, 'minute' => 0, 'second' => 0,
                'millisecond' => 0, 'microsecond' => 0, 'nanosecond' => 0];
        }

        $subNsPadded = str_pad($subNs, 9, '0', STR_PAD_LEFT);

        return [
            'year' => (int) $local->format('Y'),
            'month' => (int) $local->format('n'),
            'day' => (int) $local->format('j'),
            'hour' => (int) $local->format('G'),
            'minute' => (int) $local->format('i'),
            'second' => (int) $local->format('s'),
            'millisecond' => (int) substr($subNsPadded, 0, 3),
            'microsecond' => (int) substr($subNsPadded, 3, 3),
            'nanosecond' => (int) substr($subNsPadded, 6, 3),
        ];
    }

    /** Compute difference between two PlainDateTimes as a Duration. */
    private static function plainDateTimeDifference(
        JsValue $dt1,
        JsValue $dt2,
        JsValue $options,
        int $sign = 1,
        ?JsValue $anchor = null,
    ): JsObject {
        $cal1 = self::getSlotString($dt1, '[[Calendar]]');
        $cal2 = self::getSlotString($dt2, '[[Calendar]]');
        if ($cal1 !== $cal2) {
            throw new RangeError(
                "calendar IDs do not match: {$cal1} vs {$cal2}",
            );
        }
        $ns1 = self::isoDateTimeToEpochNs(
            self::getSlotInt($dt1, '[[ISOYear]]'),
            self::getSlotInt($dt1, '[[ISOMonth]]'),
            self::getSlotInt($dt1, '[[ISODay]]'),
            self::getSlotInt($dt1, '[[ISOHour]]'),
            self::getSlotInt($dt1, '[[ISOMinute]]'),
            self::getSlotInt($dt1, '[[ISOSecond]]'),
            self::getSlotInt($dt1, '[[ISOMillisecond]]'),
            self::getSlotInt($dt1, '[[ISOMicrosecond]]'),
            self::getSlotInt($dt1, '[[ISONanosecond]]'),
            'UTC',
        );
        $ns2 = self::isoDateTimeToEpochNs(
            self::getSlotInt($dt2, '[[ISOYear]]'),
            self::getSlotInt($dt2, '[[ISOMonth]]'),
            self::getSlotInt($dt2, '[[ISODay]]'),
            self::getSlotInt($dt2, '[[ISOHour]]'),
            self::getSlotInt($dt2, '[[ISOMinute]]'),
            self::getSlotInt($dt2, '[[ISOSecond]]'),
            self::getSlotInt($dt2, '[[ISOMillisecond]]'),
            self::getSlotInt($dt2, '[[ISOMicrosecond]]'),
            self::getSlotInt($dt2, '[[ISONanosecond]]'),
            'UTC',
        );
        $diffNs = bcsub($ns2, $ns1, 0);
        $largestUnit = 'day';
        $largestUnitExplicit = false;
        $smallestUnit = 'nanosecond';
        if ($options instanceof JsObject) {
            $lu = $options->get('largestUnit');
            if (!($lu instanceof JsUndefined)) {
                $largestUnitExplicit = true;
                $largestUnit = TypeConversion::toString($lu);
                if ($largestUnit === 'auto') {
                    $largestUnit = 'day';
                    $largestUnitExplicit = false;
                } else {
                    $largestUnit = self::canonicalTemporalUnit($largestUnit);
                }
            }
            // Alphabetical: roundingIncrement, roundingMode, smallestUnit.
            $ri = $options->get('roundingIncrement');
            if (!($ri instanceof JsUndefined)) {
                $riNum = TypeConversion::toNumber($ri);
                if (!is_finite($riNum)) {
                    throw new RangeError("Invalid roundingIncrement");
                }
                $riNum = (int) $riNum;
                if ($riNum < 1 || $riNum > 1_000_000_000) {
                    throw new RangeError("Invalid roundingIncrement");
                }
            }
            $rm = $options->get('roundingMode');
            if (!($rm instanceof JsUndefined)) {
                $rmStr = TypeConversion::toString($rm);
                $validRM = ['ceil', 'floor', 'expand', 'trunc', 'halfCeil', 'halfFloor', 'halfExpand', 'halfTrunc', 'halfEven'];
                if (!in_array($rmStr, $validRM, true)) {
                    throw new RangeError("Invalid roundingMode: {$rmStr}");
                }
            }
            $su = $options->get('smallestUnit');
            if (!($su instanceof JsUndefined)) {
                $smallestUnit = TypeConversion::toString($su);
                $smallestUnit = self::canonicalTemporalUnit($smallestUnit);
            }
            // Validate roundingIncrement divides evenly.
            if (isset($riNum) && $riNum > 1) {
                self::validateRoundingIncrement($smallestUnit, $riNum);
            }
            // Default largestUnit to smallestUnit if needed.
            $allUnits = ['year', 'month', 'week', 'day', 'hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];
            $liIdx = array_search($largestUnit, $allUnits);
            $siIdx = array_search($smallestUnit, $allUnits);
            if (!$largestUnitExplicit && $siIdx !== false && $liIdx !== false && $siIdx < $liIdx) {
                $largestUnit = $smallestUnit;
                $liIdx = $siIdx;
            }
            if ($liIdx !== false && $siIdx !== false && $liIdx > $siIdx) {
                throw new RangeError('largestUnit must be >= smallestUnit');
            }
        }
        // For calendar units (year, month, week), compute using date components.
        $calendarUnits = ['year', 'month', 'week'];
        if (in_array($largestUnit, $calendarUnits, true)) {
            $dur = self::calendarDateTimeDifference($dt1, $dt2, $largestUnit, 1, $anchor);
            // Apply rounding if smallestUnit is a calendar unit.
            $suFinal = $smallestUnit;
            $rmFinal = $rmStr ?? 'trunc';
            $riFinal = isset($riNum) ? (int) $riNum : 1;
            if (in_array($suFinal, ['year', 'month', 'week', 'day'], true)) {
                return self::roundCalendarDuration($dur, $suFinal, $rmFinal, $riFinal, $largestUnit, $dt1);
            }
            if ($suFinal !== 'nanosecond' || $riFinal !== 1) {
                return self::roundCalendarDuration($dur, $suFinal, $rmFinal, $riFinal, $largestUnit, $dt1);
            }
            return $dur;
        }
        // Apply rounding.
        $roundIncrement = isset($riNum) ? (int) $riNum : 1;
        $roundMode = $rmStr ?? 'trunc';
        if ($smallestUnit !== 'nanosecond' || $roundIncrement !== 1) {
            $unitNsMap = [
                'day' => '86400000000000',
                'hour' => '3600000000000',
                'minute' => '60000000000',
                'second' => '1000000000',
                'millisecond' => '1000000',
                'microsecond' => '1000',
                'nanosecond' => '1',
            ];
            $unitNs = $unitNsMap[$smallestUnit] ?? '1';
            $incrementNs = bcmul((string) $roundIncrement, $unitNs, 0);
            $diffNs = self::roundNs($diffNs, $incrementNs, $roundMode);
        }
        return self::nsToDateTimeDuration($diffNs, $largestUnit);
    }

    /** Round a calendar-unit Duration to the specified smallestUnit. */
    private static function roundCalendarDuration(
        JsObject $dur,
        string $smallestUnit,
        string $roundingMode,
        int $increment,
        string $largestUnit,
        JsValue $ref,
    ): JsObject {
        $years = self::getDurationField($dur, 'years');
        $months = self::getDurationField($dur, 'months');
        $weeks = self::getDurationField($dur, 'weeks');
        $days = self::getDurationField($dur, 'days');
        $hours = self::getDurationField($dur, 'hours');
        $minutes = self::getDurationField($dur, 'minutes');
        $seconds = self::getDurationField($dur, 'seconds');
        $ms = self::getDurationField($dur, 'milliseconds');
        $us = self::getDurationField($dur, 'microseconds');
        $ns = self::getDurationField($dur, 'nanoseconds');
        $sign = self::durationSign($dur);
        if ($sign === 0) {
            return $dur;
        }
        $absYears = abs($years);
        $absMonths = abs($months);
        $absWeeks = abs($weeks);
        $absDays = abs($days);
        // Rounding modes are defined relative to the number line (+inf/-inf).
        // When working on absolute values of negative durations, swap directional modes.
        $absRoundingMode = $roundingMode;
        if ($sign < 0) {
            $absRoundingMode = match ($roundingMode) {
                'ceil' => 'floor',
                'floor' => 'ceil',
                'halfCeil' => 'halfFloor',
                'halfFloor' => 'halfCeil',
                default => $roundingMode,
            };
        }
        // Resolve year/month/day for the ref calendar context. For a ZDT, use
        // its wall-time components.
        $refZdt = ($ref instanceof JsObject && $ref->has('[[IsZonedDateTime]]')) ? $ref : null;
        // DST-aware path is only needed when the ZDT's time zone can actually
        // produce non-24h days. UTC and fixed offset zones are always 24h.
        $refDstZdt = null;
        if ($refZdt !== null) {
            $refTz = self::getSlotString($refZdt, '[[TimeZone]]');
            if (!self::isFixedOffset($refTz)) {
                $refDstZdt = $refZdt;
            }
        }
        if ($refZdt !== null) {
            $refParts = self::zonedDateTimeParts($refZdt);
            $refY = $refParts['year'];
            $refM = $refParts['month'];
            $refD = $refParts['day'];
        } else {
            $refY = $ref instanceof JsObject && $ref->has('[[ISOYear]]') ? self::getSlotInt($ref, '[[ISOYear]]') : 2000;
            $refM = $ref instanceof JsObject && $ref->has('[[ISOMonth]]') ? self::getSlotInt($ref, '[[ISOMonth]]') : 1;
            $refD = $ref instanceof JsObject && $ref->has('[[ISODay]]') ? self::getSlotInt($ref, '[[ISODay]]') : 1;
        }
        if ($smallestUnit === 'year') {
            // Compute fractional year per spec: remaining days / year-boundary days.
            // yearStart = ref + absYears years (in the forward direction).
            $ysY = $refY + $absYears * $sign;
            $ysM = $refM;
            $ysD = min($refD, self::isoDaysInMonth($ysY, $ysM));
            // yearEnd = yearStart + 1 year.
            $yeY = $ysY + $sign;
            $yeM = $ysM;
            $yeD = min($refD, self::isoDaysInMonth($yeY, $yeM));
            $yearLengthDays = abs(self::isoToJulianDay($yeY, $yeM, $yeD) - self::isoToJulianDay($ysY, $ysM, $ysD));
            // Compute remaining days from yearStart to (yearStart + months + days + time).
            $midDur = self::createDurationObject(0, $absMonths, $absWeeks, $absDays, 0, 0, 0, 0, 0, 0);
            $midEnd = self::plainDateAdd(self::createPlainDateObject($ysY, $ysM, $ysD, 'iso8601'), $midDur, $sign);
            $remainDays = abs(self::isoToJulianDay(
                self::getSlotInt($midEnd, '[[ISOYear]]'),
                self::getSlotInt($midEnd, '[[ISOMonth]]'),
                self::getSlotInt($midEnd, '[[ISODay]]'),
            ) - self::isoToJulianDay($ysY, $ysM, $ysD));
            $timeFrac = (abs($hours) * 3600 + abs($minutes) * 60 + abs($seconds)) / 86400.0;
            $frac = $yearLengthDays > 0 ? ($remainDays + $timeFrac) / (float) $yearLengthDays : 0;
            $totalYears = $absYears + $frac;
            $rounded = self::roundToIncrement((int) round($totalYears * 1000000), $increment * 1000000, $absRoundingMode);
            $roundedYears = intdiv($rounded, 1000000);
            // Validate the ceil endpoint (rounded + increment) is representable.
            $ceilY = $refY + ($roundedYears + $increment) * $sign;
            if ($ceilY < self::ISO_YEAR_MIN || $ceilY > self::ISO_YEAR_MAX) {
                throw new RangeError('Rounded date outside valid ISO date range');
            }
            return self::createDurationObject($sign * $roundedYears, 0, 0, 0, 0, 0, 0, 0, 0, 0);
        }
        if ($smallestUnit === 'month') {
            // The fractional month comes from the "remainder" days. For positive durations,
            // $ref is the start, and the span goes forward $absMonths months then $absDays days.
            // The relevant daysInMonth is at ref + absMonths.
            // For negative durations, $ref is the end (later), and the absDays span ends at ref.
            // The relevant month is the one just before ref (ref.month - 1).
            if ($sign < 0) {
                $midTotalM = $refY * 12 + ($refM - 2); // zero-based months: refM-1 then subtract 1
                $midY2 = intdiv($midTotalM, 12);
                $midM2 = ($midTotalM % 12) + 1;
                if ($midM2 < 1) {
                    $midM2 += 12;
                    $midY2--;
                }
            } else {
                $midTotalM = ($refY * 12 + ($refM - 1)) + ($absYears * 12 + $absMonths);
                $midY2 = intdiv($midTotalM, 12);
                $midM2 = ($midTotalM % 12) + 1;
            }
            if ($refZdt !== null) {
                // ZDT-aware: month length and progress in epoch ns so that
                // DST transitions inside the span are accounted for.
                $absTotalMonthCount = $absYears * 12 + $absMonths;
                $midDur = self::createDurationObject(
                    0,
                    $sign * $absTotalMonthCount,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                );
                $nextDur = self::createDurationObject(
                    0,
                    $sign * ($absTotalMonthCount + 1),
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                );
                $midNsRef = self::addDurationToZdt($refZdt, $midDur, 1, 'constrain');
                $nextNsRef = self::addDurationToZdt($refZdt, $nextDur, 1, 'constrain');
                $monthLenNs = bcsub($nextNsRef, $midNsRef, 0);
                $absMonthLen = bccomp($monthLenNs, '0', 0) < 0 ? substr($monthLenNs, 1) : $monthLenNs;
                $fullDur = self::createDurationObject(
                    $sign * $absYears,
                    $sign * $absMonths,
                    $sign * $absWeeks,
                    $sign * $absDays,
                    (float) $hours,
                    (float) $minutes,
                    (float) $seconds,
                    (float) $ms,
                    (float) $us,
                    (float) $ns,
                );
                $endNsRef = self::addDurationToZdt($refZdt, $fullDur, 1, 'constrain');
                $progressNs = bcsub($endNsRef, $midNsRef, 0);
                $absProgress = bccomp($progressNs, '0', 0) < 0 ? substr($progressNs, 1) : $progressNs;
                if (bccomp($absMonthLen, '0', 0) === 0) {
                    $fracTimes1e9 = '0';
                } else {
                    $fracTimes1e9 = bcdiv(bcmul($absProgress, '1000000000', 0), $absMonthLen, 0);
                }
                $totalMonthsScaled = bcadd(
                    bcmul((string) $absTotalMonthCount, '1000000000', 0),
                    $fracTimes1e9,
                    0,
                );
                $rounded = self::roundToIncrement(
                    (int) $totalMonthsScaled,
                    $increment * 1000000000,
                    $absRoundingMode,
                );
                $rm = intdiv($rounded, 1000000000);
            } else {
                $daysInMonth = self::isoDaysInMonth($midY2, $midM2);
                $frac = $daysInMonth > 0 ? ($absDays + abs($hours) / 24.0) / $daysInMonth : 0;
                $totalMonths = $absYears * 12 + $absMonths + $frac;
                $rounded = self::roundToIncrement((int) round($totalMonths * 1000000), $increment * 1000000, $absRoundingMode);
                $rm = intdiv($rounded, 1000000);
            }
            // Validate both the rounded endpoint and ceil endpoint are representable.
            $ceilM = $rm + $increment;
            $ceilTotalM = $refY * 12 + ($refM - 1) + $ceilM * $sign;
            $ceilY = intdiv($ceilTotalM, 12);
            if ($ceilY < self::ISO_YEAR_MIN || $ceilY > self::ISO_YEAR_MAX) {
                throw new RangeError('Rounded date outside valid ISO date range');
            }
            if ($largestUnit === 'year') {
                return self::createDurationObject($sign * intdiv($rm, 12), $sign * ($rm % 12), 0, 0, 0, 0, 0, 0, 0, 0);
            }
            return self::createDurationObject(0, $sign * $rm, 0, 0, 0, 0, 0, 0, 0, 0);
        }
        if ($smallestUnit === 'week') {
            // Include sub-day time as fractional days for rounding purposes.
            $timeNs = abs($hours) * 3600000000000
                + abs($minutes) * 60000000000
                + abs($seconds) * 1000000000
                + abs($ms) * 1000000
                + abs($us) * 1000
                + abs($ns);
            $dayNs = 86400000000000;
            $weekNs = $dayNs * 7 * $increment;
            $totalNs = ($absWeeks * 7 + $absDays) * $dayNs + $timeNs;
            $roundedNs = self::roundNs((string) ($sign < 0 ? -$totalNs : $totalNs), (string) $weekNs, $roundingMode);
            $roundedWeeks = (int) (abs((int) $roundedNs) / $dayNs / 7);
            return self::createDurationObject($sign * $absYears, $sign * $absMonths, $sign * $roundedWeeks, 0, 0, 0, 0, 0, 0, 0);
        }
        if ($smallestUnit === 'day') {
            // Include sub-day time as fractional days for rounding purposes.
            // For ZDT relativeTo, the actual day length on the relevant date
            // may not be 24h (DST transitions); use the ZDT-aware length so
            // that 11.5h on a 23h day correctly equals exactly 0.5 day.
            if ($refZdt !== null) {
                $absTotalDays = $absDays + $absWeeks * 7;
                $baseDur = self::createDurationObject(0, 0, 0, $sign * $absTotalDays, 0, 0, 0, 0, 0, 0);
                $nextDur = self::createDurationObject(0, 0, 0, $sign * ($absTotalDays + 1), 0, 0, 0, 0, 0, 0);
                $startNsRef = self::getSlotString($refZdt, '[[EpochNanoseconds]]');
                $baseNsRef = self::addDurationToZdt($refZdt, $baseDur, 1, 'constrain');
                $nextNsRef = self::addDurationToZdt($refZdt, $nextDur, 1, 'constrain');
                $dayLenNs = bcsub($nextNsRef, $baseNsRef, 0);
                $absDayLen = bccomp($dayLenNs, '0', 0) < 0 ? substr($dayLenNs, 1) : $dayLenNs;
                $fullDur = self::createDurationObject(
                    $sign * $absYears,
                    $sign * $absMonths,
                    $sign * $absWeeks,
                    $sign * $absDays,
                    (float) $hours,
                    (float) $minutes,
                    (float) $seconds,
                    (float) $ms,
                    (float) $us,
                    (float) $ns,
                );
                $endNsRef = self::addDurationToZdt($refZdt, $fullDur, 1, 'constrain');
                $progressNs = bcsub($endNsRef, $baseNsRef, 0);
                $absProgress = bccomp($progressNs, '0', 0) < 0 ? substr($progressNs, 1) : $progressNs;
                if (bccomp($absDayLen, '0', 0) === 0) {
                    $fracTimes1e9 = '0';
                } else {
                    $fracTimes1e9 = bcdiv(bcmul($absProgress, '1000000000', 0), $absDayLen, 0);
                }
                $totalDaysScaled = bcadd(
                    bcmul((string) $absTotalDays, '1000000000', 0),
                    $fracTimes1e9,
                    0,
                );
                $roundedScaled = self::roundToIncrement(
                    (int) $totalDaysScaled,
                    $increment * 1000000000,
                    $absRoundingMode,
                );
                $rounded = intdiv($roundedScaled, 1000000000);
            } else {
                $timeNs = abs($hours) * 3600000000000
                    + abs($minutes) * 60000000000
                    + abs($seconds) * 1000000000
                    + abs($ms) * 1000000
                    + abs($us) * 1000
                    + abs($ns);
                $dayNs = 86400000000000;
                $totalNs = ($absWeeks * 7 + $absDays) * $dayNs + $timeNs;
                $incrNs = $dayNs * $increment;
                $roundedNs = self::roundNs((string) ($sign < 0 ? -$totalNs : $totalNs), (string) $incrNs, $roundingMode);
                $rounded = (int) (abs((int) $roundedNs) / $dayNs);
            }
            // Validate ceiling (rounded + increment) doesn't exceed 100M days.
            $ceilDays = $rounded + $increment;
            if ($ceilDays > 100000000) {
                throw new RangeError('Rounded date outside valid ISO date range');
            }
            if ($largestUnit === 'week') {
                return self::createDurationObject(
                    $sign * $absYears,
                    $sign * $absMonths,
                    $sign * intdiv($rounded, 7),
                    $sign * ($rounded % 7),
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                );
            }
            return self::createDurationObject($sign * $absYears, $sign * $absMonths, 0, $sign * $rounded, 0, 0, 0, 0, 0, 0);
        }
        // Time-unit smallestUnit: compute total time ns (including days if largestUnit is a time unit).
        $dayNs = '86400000000000';
        $timeNsBc = bcadd(
            bcadd(
                bcadd(
                    bcmul((string) abs($hours), '3600000000000', 0),
                    bcmul((string) abs($minutes), '60000000000', 0),
                    0,
                ),
                bcmul((string) abs($seconds), '1000000000', 0),
                0,
            ),
            bcadd(
                bcadd(bcmul((string) abs($ms), '1000000', 0), bcmul((string) abs($us), '1000', 0), 0),
                (string) abs($ns),
                0,
            ),
            0,
        );
        // If largestUnit is a time unit, fold days into time nanoseconds.
        // Under DST, a wall-clock day is 23/24/25 hours, not always 24 — fold
        // via actual epoch ns from ref so the conversion matches reality.
        $timeUnits = ['hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];
        if (in_array($largestUnit, $timeUnits, true)) {
            if (
                $refDstZdt !== null
                && ($absDays !== 0 || $absWeeks !== 0 || $absMonths !== 0 || $absYears !== 0)
            ) {
                $startNsRef = self::getSlotString($refDstZdt, '[[EpochNanoseconds]]');
                $dateOnlyDur = self::createDurationObject(
                    $sign * $absYears,
                    $sign * $absMonths,
                    $sign * $absWeeks,
                    $sign * $absDays,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                );
                $endDateNsRef = self::addDurationToZdt($refDstZdt, $dateOnlyDur, 1, 'constrain');
                $deltaNsRef = bcsub($endDateNsRef, $startNsRef, 0);
                if (bccomp($deltaNsRef, '0', 0) < 0) {
                    $deltaNsRef = substr($deltaNsRef, 1);
                }
                $timeNsBc = bcadd($timeNsBc, $deltaNsRef, 0);
            } else {
                $timeNsBc = bcadd($timeNsBc, bcmul((string) $absDays, $dayNs, 0), 0);
            }
            $absDays = 0;
            $absWeeks = 0;
            $absMonths = 0;
            $absYears = 0;
        }
        $unitNs = self::temporalUnitToNs($smallestUnit);
        $incNs = bcmul((string) $increment, $unitNs, 0);
        if ($incNs !== '0') {
            $signed = $sign < 0 ? '-' . $timeNsBc : $timeNsBc;
            $timeNsBc = self::roundNs($signed, $incNs, $roundingMode);
            if (bccomp($timeNsBc, '0', 0) < 0) {
                $timeNsBc = substr($timeNsBc, 1);
            }
        }
        // AdjustRoundedDurationDays per spec: under DST, when the rounded
        // time portion exceeds the actual wall day length, increment days
        // and subtract the day length (then re-round the remainder).
        if (
            $refDstZdt !== null
            && in_array($largestUnit, ['year', 'month', 'week', 'day'], true)
            && bccomp($timeNsBc, '0', 0) > 0
        ) {
            while (true) {
                $iterStartDur = self::createDurationObject(0, 0, 0, $sign * $absDays, 0, 0, 0, 0, 0, 0);
                $iterEndDur = self::createDurationObject(0, 0, 0, $sign * ($absDays + 1), 0, 0, 0, 0, 0, 0);
                $iterStartNs = self::addDurationToZdt($refDstZdt, $iterStartDur, 1, 'constrain');
                $iterEndNs = self::addDurationToZdt($refDstZdt, $iterEndDur, 1, 'constrain');
                $dayLenNs = bcsub($iterEndNs, $iterStartNs, 0);
                if (bccomp($dayLenNs, '0', 0) < 0) {
                    $dayLenNs = substr($dayLenNs, 1);
                }
                // For largestUnit=day, time exactly equal to the day
                // length still folds into a whole day (e.g. 25h on a 25h
                // wall day = exactly 1 day). For larger units (year /
                // month / week), keep "= dayLen" as time so a no-op
                // round of e.g. 1Y 24h doesn't bubble into 1Y 1D.
                // Special case: a zero-length wall day (e.g. Apia's
                // skipped 2011-12-30) should still consume as one day
                // when walking forward and there's any time left to
                // attribute, since the calendar advances even though no
                // UTC ns elapsed.
                if (bccomp($dayLenNs, '0', 0) === 0) {
                    if (bccomp($timeNsBc, '0', 0) > 0) {
                        $absDays++;
                        continue;
                    }
                    break;
                }
                $cmp = bccomp($timeNsBc, $dayLenNs, 0);
                if ($largestUnit === 'day') {
                    if ($cmp < 0) {
                        break;
                    }
                } else {
                    if ($cmp <= 0) {
                        break;
                    }
                }
                $timeNsBc = bcsub($timeNsBc, $dayLenNs, 0);
                $absDays++;
                if ($incNs !== '0') {
                    $signed = $sign < 0 ? '-' . $timeNsBc : $timeNsBc;
                    $timeNsBc = self::roundNs($signed, $incNs, $roundingMode);
                    if (bccomp($timeNsBc, '0', 0) < 0) {
                        $timeNsBc = substr($timeNsBc, 1);
                    }
                }
            }
        }
        // Balance time into days if largestUnit allows it. With ZDT relativeTo
        // we must NOT pre-collapse time to days at fixed 24h; under DST a
        // wall-clock day can be 23/24/25 hours, and the spec preserves whole
        // days in calendar arithmetic only.
        $extraDays = 0;
        if (in_array($largestUnit, ['year', 'month', 'week', 'day'], true) && $refDstZdt === null) {
            $extraDays = (int) bcdiv($timeNsBc, $dayNs, 0);
            $timeNsBc = bcsub($timeNsBc, bcmul((string) $extraDays, $dayNs, 0), 0);
        }
        // For ZDT context, treat the time portion at hour granularity so that
        // 24h doesn't silently turn into 1 day under DST when the wall day is
        // 23 or 25 hours.
        $timePartLU = $refDstZdt !== null && in_array($largestUnit, ['year', 'month', 'week', 'day'], true)
            ? 'hour'
            : $largestUnit;
        $timePart = self::nsToTimeDuration($timeNsBc, $timePartLU);
        $finalDays = $absDays + $extraDays;
        $finalWeeks = $absWeeks;
        $finalMonths = $absMonths;
        $finalYears = $absYears;
        if ($largestUnit === 'week') {
            $finalWeeks += intdiv($finalDays, 7);
            $finalDays = $finalDays % 7;
        }
        if (in_array($largestUnit, ['year', 'month'], true) && $finalDays > 0 && $ref instanceof JsObject) {
            $rY = $refY;
            $rM = $refM;
            $ctm = $rY * 12 + ($rM - 1) + ($finalYears * 12) + $finalMonths;
            $cY = intdiv($ctm, 12);
            $cM = ($ctm % 12) + 1;
            $dim = self::isoDaysInMonth($cY, $cM);
            while ($finalDays >= $dim) {
                $finalDays -= $dim;
                $finalMonths++;
                if ($largestUnit === 'year' && $finalMonths >= 12) {
                    $finalYears += intdiv($finalMonths, 12);
                    $finalMonths = $finalMonths % 12;
                }
                $ctm = $rY * 12 + ($rM - 1) + ($finalYears * 12) + $finalMonths;
                $cY = intdiv($ctm, 12);
                $cM = ($ctm % 12) + 1;
                $dim = self::isoDaysInMonth($cY, $cM);
            }
        }
        return self::createDurationObject(
            $sign * $finalYears,
            $sign * $finalMonths,
            $sign * $finalWeeks,
            $sign * $finalDays,
            $sign * self::getDurationField($timePart, 'hours'),
            $sign * self::getDurationField($timePart, 'minutes'),
            $sign * self::getDurationField($timePart, 'seconds'),
            $sign * self::getDurationField($timePart, 'milliseconds'),
            $sign * self::getDurationField($timePart, 'microseconds'),
            $sign * self::getDurationField($timePart, 'nanoseconds'),
        );
    }

    /** Calendar-aware difference for year/month/week largestUnit. */
    private static function calendarDateTimeDifference(
        JsValue $dt1,
        JsValue $dt2,
        string $largestUnit,
        int $signParam = 1,
        ?JsValue $anchorDt = null,
    ): JsObject {
        $y1 = self::getSlotInt($dt1, '[[ISOYear]]');
        $m1 = self::getSlotInt($dt1, '[[ISOMonth]]');
        $d1 = self::getSlotInt($dt1, '[[ISODay]]');
        $y2 = self::getSlotInt($dt2, '[[ISOYear]]');
        $m2 = self::getSlotInt($dt2, '[[ISOMonth]]');
        $d2 = self::getSlotInt($dt2, '[[ISODay]]');
        // Determine sign.
        $cmp = ($y2 <=> $y1) ?: ($m2 <=> $m1) ?: ($d2 <=> $d1);
        if ($cmp === 0) {
            // Same date, compute time diff.
            $ns1 = self::isoDateTimeToEpochNs(
                $y1,
                $m1,
                $d1,
                self::getSlotInt($dt1, '[[ISOHour]]'),
                self::getSlotInt($dt1, '[[ISOMinute]]'),
                self::getSlotInt($dt1, '[[ISOSecond]]'),
                self::getSlotInt($dt1, '[[ISOMillisecond]]'),
                self::getSlotInt($dt1, '[[ISOMicrosecond]]'),
                self::getSlotInt($dt1, '[[ISONanosecond]]'),
                'UTC'
            );
            $ns2 = self::isoDateTimeToEpochNs(
                $y2,
                $m2,
                $d2,
                self::getSlotInt($dt2, '[[ISOHour]]'),
                self::getSlotInt($dt2, '[[ISOMinute]]'),
                self::getSlotInt($dt2, '[[ISOSecond]]'),
                self::getSlotInt($dt2, '[[ISOMillisecond]]'),
                self::getSlotInt($dt2, '[[ISOMicrosecond]]'),
                self::getSlotInt($dt2, '[[ISONanosecond]]'),
                'UTC'
            );
            $diffNs = bcsub($ns2, $ns1, 0);
            return self::nsToTimeDuration($diffNs, 'hour');
        }
        $sign = $cmp > 0 ? 1 : -1;
        // Anchor on the provided anchor date, or dt1 by default.
        $anchorDay = $anchorDt !== null
            ? self::getSlotInt($anchorDt, '[[ISODay]]')
            : $d1;
        if ($sign < 0) {
            [$sY, $sM, $sD, $eY, $eM, $eD] = [$y2, $m2, $d2, $y1, $m1, $d1];
            $smlDt = $dt2;
            $lrgDt = $dt1;
        } else {
            [$sY, $sM, $sD, $eY, $eM, $eD] = [$y1, $m1, $d1, $y2, $m2, $d2];
            $smlDt = $dt1;
            $lrgDt = $dt2;
        }
        $years = 0;
        $months = 0;
        $weeks = 0;
        $days = 0;
        if ($largestUnit === 'year' || $largestUnit === 'month') {
            if ($sign > 0) {
                // Forward: date1 < date2. Add months from date1 toward date2.
                $totalMonths = ($eY * 12 + $eM) - ($sY * 12 + $sM);
                if ($eD < $anchorDay) {
                    $totalMonths--;
                }
                $mt = $sY * 12 + ($sM - 1) + $totalMonths;
                $midMY = intdiv($mt, 12);
                $midMM = ($mt % 12) + 1;
                $midD = min($anchorDay, self::isoDaysInMonth($midMY, $midMM));
                $days = self::isoToJulianDay($eY, $eM, $eD) - self::isoToJulianDay($midMY, $midMM, $midD);
                if ($days < 0) {
                    $totalMonths--;
                    $mt = $sY * 12 + ($sM - 1) + $totalMonths;
                    $midMY = intdiv($mt, 12);
                    $midMM = ($mt % 12) + 1;
                    $midD = min($anchorDay, self::isoDaysInMonth($midMY, $midMM));
                    $days = self::isoToJulianDay($eY, $eM, $eD) - self::isoToJulianDay($midMY, $midMM, $midD);
                }
            } else {
                // Backward: date1(eY,eM,eD) > date2(sY,sM,sD). Subtract months from date1.
                $totalMonths = ($eY * 12 + $eM) - ($sY * 12 + $sM);
                if ($sD > $anchorDay) {
                    $totalMonths--;
                }
                // Midpoint = date1 - totalMonths months.
                $mt = $eY * 12 + ($eM - 1) - $totalMonths;
                $midMY = intdiv($mt, 12);
                $midMM = ($mt % 12) + 1;
                if ($midMM < 1) {
                    $midMM += 12;
                    $midMY--;
                }
                $midD = min($anchorDay, self::isoDaysInMonth($midMY, $midMM));
                // Days from midpoint backward to date2.
                $days = self::isoToJulianDay($midMY, $midMM, $midD) - self::isoToJulianDay($sY, $sM, $sD);
                if ($days < 0) {
                    $totalMonths--;
                    $mt = $eY * 12 + ($eM - 1) - $totalMonths;
                    $midMY = intdiv($mt, 12);
                    $midMM = ($mt % 12) + 1;
                    if ($midMM < 1) {
                        $midMM += 12;
                        $midMY--;
                    }
                    $midD = min($anchorDay, self::isoDaysInMonth($midMY, $midMM));
                    $days = self::isoToJulianDay($midMY, $midMM, $midD) - self::isoToJulianDay($sY, $sM, $sD);
                }
            }
            if ($largestUnit === 'year') {
                $years = intdiv($totalMonths, 12);
                $months = $totalMonths - $years * 12;
            } else {
                $months = $totalMonths;
            }
        } elseif ($largestUnit === 'week') {
            $jd1 = self::isoToJulianDay($sY, $sM, $sD);
            $jd2 = self::isoToJulianDay($eY, $eM, $eD);
            $totalDays = $jd2 - $jd1;
            $weeks = intdiv($totalDays, 7);
            $days = $totalDays % 7;
        }
        // Compute time difference: always (larger_time - smaller_time).
        $timeNs1 = (self::getSlotInt($smlDt, '[[ISOHour]]') * 3600
            + self::getSlotInt($smlDt, '[[ISOMinute]]') * 60
            + self::getSlotInt($smlDt, '[[ISOSecond]]')) * 1000000000
            + self::getSlotInt($smlDt, '[[ISOMillisecond]]') * 1000000
            + self::getSlotInt($smlDt, '[[ISOMicrosecond]]') * 1000
            + self::getSlotInt($smlDt, '[[ISONanosecond]]');
        $timeNs2 = (self::getSlotInt($lrgDt, '[[ISOHour]]') * 3600
            + self::getSlotInt($lrgDt, '[[ISOMinute]]') * 60
            + self::getSlotInt($lrgDt, '[[ISOSecond]]')) * 1000000000
            + self::getSlotInt($lrgDt, '[[ISOMillisecond]]') * 1000000
            + self::getSlotInt($lrgDt, '[[ISOMicrosecond]]') * 1000
            + self::getSlotInt($lrgDt, '[[ISONanosecond]]');
        $timeDiffNs = (string) ($timeNs2 - $timeNs1);
        if ($timeNs2 < $timeNs1) {
            if ($days > 0) {
                $days--;
                $timeDiffNs = (string) ($timeNs2 - $timeNs1 + 86400000000000);
            } elseif ($months > 0) {
                $months--;
                if ($sign > 0) {
                    // Forward: recompute midpoint from sml + months.
                    $mt2 = $sY * 12 + ($sM - 1) + ($years * 12) + $months;
                    $midMY2 = intdiv($mt2, 12);
                    $midMM2 = ($mt2 % 12) + 1;
                    $midD2 = min($anchorDay, self::isoDaysInMonth($midMY2, $midMM2));
                    $days = self::isoToJulianDay($eY, $eM, $eD) - self::isoToJulianDay($midMY2, $midMM2, $midD2);
                } else {
                    // Backward: recompute midpoint from date1 - months.
                    $mt2 = $eY * 12 + ($eM - 1) - ($years * 12) - $months;
                    $midMY2 = intdiv($mt2, 12);
                    $midMM2 = ($mt2 % 12) + 1;
                    if ($midMM2 < 1) {
                        $midMM2 += 12;
                        $midMY2--;
                    }
                    $midD2 = min($anchorDay, self::isoDaysInMonth($midMY2, $midMM2));
                    $days = self::isoToJulianDay($midMY2, $midMM2, $midD2) - self::isoToJulianDay($sY, $sM, $sD);
                }
                if ($days > 0) {
                    $days--;
                }
                $timeDiffNs = (string) ($timeNs2 - $timeNs1 + 86400000000000);
            } elseif ($years > 0) {
                $years--;
                if ($sign > 0) {
                    $tempY = $sY + $years;
                    $totalM = ($eY * 12 + $eM) - ($tempY * 12 + $sM);
                    if ($eD < $anchorDay) {
                        $totalM--;
                    }
                    $months = $totalM;
                    $mt2 = $tempY * 12 + ($sM - 1) + $months;
                    $midMY2 = intdiv($mt2, 12);
                    $midMM2 = ($mt2 % 12) + 1;
                    $midD2 = min($anchorDay, self::isoDaysInMonth($midMY2, $midMM2));
                    $days = self::isoToJulianDay($eY, $eM, $eD) - self::isoToJulianDay($midMY2, $midMM2, $midD2);
                } else {
                    // Backward: years borrow.
                    $remMonths = ($eY * 12 + $eM) - ($sY * 12 + $sM) - ($years * 12);
                    if ($sD > $anchorDay) {
                        $remMonths--;
                    }
                    $months = $remMonths;
                    $mt2 = $eY * 12 + ($eM - 1) - ($years * 12) - $months;
                    $midMY2 = intdiv($mt2, 12);
                    $midMM2 = ($mt2 % 12) + 1;
                    if ($midMM2 < 1) {
                        $midMM2 += 12;
                        $midMY2--;
                    }
                    $midD2 = min($anchorDay, self::isoDaysInMonth($midMY2, $midMM2));
                    $days = self::isoToJulianDay($midMY2, $midMM2, $midD2) - self::isoToJulianDay($sY, $sM, $sD);
                }
                if ($days > 0) {
                    $days--;
                }
                $timeDiffNs = (string) ($timeNs2 - $timeNs1 + 86400000000000);
            }
        }
        $timeDur = self::nsToTimeDuration($timeDiffNs, 'hour');
        $hours = self::getDurationField($timeDur, 'hours');
        $minutes = self::getDurationField($timeDur, 'minutes');
        $seconds = self::getDurationField($timeDur, 'seconds');
        $ms = self::getDurationField($timeDur, 'milliseconds');
        $us = self::getDurationField($timeDur, 'microseconds');
        $ns = self::getDurationField($timeDur, 'nanoseconds');
        return self::createDurationObject(
            $sign * $years,
            $sign * $months,
            $sign * $weeks,
            $sign * $days,
            $sign * $hours,
            $sign * $minutes,
            $sign * $seconds,
            $sign * $ms,
            $sign * $us,
            $sign * $ns,
        );
    }

    /** Convert nanosecond diff to Duration with date+time units. */
    private static function nsToDateTimeDuration(string $ns, string $largestUnit): JsObject
    {
        $sign = bccomp($ns, '0', 0) < 0 ? -1 : 1;
        $abs = $sign < 0 ? bcsub('0', $ns, 0) : $ns;
        $days = 0;
        $hours = 0;
        $minutes = 0;
        $seconds = 0;
        $milliseconds = 0;
        $microseconds = 0;
        $nanoseconds = 0;
        $nsPerDay = '86400000000000';
        $nsPerHour = '3600000000000';
        $nsPerMin = '60000000000';
        $nsPerSec = '1000000000';
        $nsPerMs = '1000000';
        $nsPerUs = '1000';
        $allUnits = ['day', 'hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];
        $luIdx = array_search($largestUnit, $allUnits, true);
        if ($luIdx === false) {
            $luIdx = 0;
        }
        if ($luIdx <= 0) {
            $days = (int) bcdiv($abs, $nsPerDay, 0);
            $abs = bcmod($abs, $nsPerDay);
        }
        if ($luIdx <= 1) {
            $hours = (int) bcdiv($abs, $nsPerHour, 0);
            $abs = bcmod($abs, $nsPerHour);
        }
        if ($luIdx <= 2) {
            $minutes = (int) bcdiv($abs, $nsPerMin, 0);
            $abs = bcmod($abs, $nsPerMin);
        }
        if ($luIdx <= 3) {
            $seconds = (int) bcdiv($abs, $nsPerSec, 0);
            $abs = bcmod($abs, $nsPerSec);
        }
        if ($luIdx <= 4) {
            $milliseconds = (int) bcdiv($abs, $nsPerMs, 0);
            $abs = bcmod($abs, $nsPerMs);
        }
        if ($luIdx <= 5) {
            $microseconds = (int) bcdiv($abs, $nsPerUs, 0);
            $abs = bcmod($abs, $nsPerUs);
        }
        $nanoseconds = (int) $abs;
        return self::createDurationObject(
            0,
            0,
            0,
            $sign * $days,
            $sign * $hours,
            $sign * $minutes,
            $sign * $seconds,
            $sign * $milliseconds,
            $sign * $microseconds,
            $sign * $nanoseconds,
        );
    }

    private static function isoDateTimeToEpochNs(
        int $y,
        int $m,
        int $d,
        int $h,
        int $min,
        int $s,
        int $ms,
        int $us,
        int $ns,
        string $tz,
    ): string {
        try {
            $dt = new \DateTimeImmutable('2000-01-01 00:00:00', self::resolveTimeZone($tz));
            $dt = $dt->setDate($y, $m, $d);
            $dt = $dt->setTime($h, $min, $s);
            $epochSec = $dt->format('U');
        } catch (\Throwable) {
            return '0';
        }
        $epochNs = bcmul($epochSec, '1000000000', 0);
        $subNs = (string) ($ms * 1000000 + $us * 1000 + $ns);
        return bcadd($epochNs, $subNs, 0);
    }

    /**
     * Convert wall-clock components to an epoch ns under the given
     * timezone, applying the disambiguation policy at DST gaps and
     * folds:
     *   - "earlier"   pick the earlier UTC moment.
     *   - "later"     pick the later UTC moment.
     *   - "compatible" forward-shift in gaps, earlier in folds.
     *   - "reject"    throw RangeError when ambiguous.
     */
    private static function isoDateTimeToEpochNsDisambiguated(
        int $y,
        int $m,
        int $d,
        int $h,
        int $min,
        int $s,
        int $ms,
        int $us,
        int $ns,
        string $tz,
        string $disam,
    ): string {
        $nsPart = (int) ($ms * 1000000 + $us * 1000 + $ns);
        // Wall-clock seconds, as if the timezone were UTC.
        $wallUtcSec = bcadd(
            bcmul((string) self::isoDateToDays($y, $m, $d), '86400', 0),
            (string) ($h * 3600 + $min * 60 + $s),
            0,
        );
        // Probe the timezone's offset 12h before and 12h after the
        // wall-clock instant to detect DST transitions in the
        // window. If both probes return the same offset there's no
        // ambiguity.
        try {
            $tzObj = self::resolveTimeZone($tz);
            $beforeSec = bcsub($wallUtcSec, '43200', 0);
            $afterSec = bcadd($wallUtcSec, '43200', 0);
            $offBefore = (int) (new \DateTimeImmutable('@' . $beforeSec))
                ->setTimezone($tzObj)->format('Z');
            $offAfter = (int) (new \DateTimeImmutable('@' . $afterSec))
                ->setTimezone($tzObj)->format('Z');
        } catch (\Throwable) {
            return self::isoDateTimeToEpochNs($y, $m, $d, $h, $min, $s, $ms, $us, $ns, $tz);
        }
        if ($offBefore === $offAfter) {
            $epochSec = bcsub($wallUtcSec, (string) $offBefore, 0);
            return bcadd(bcmul($epochSec, '1000000000', 0), (string) $nsPart, 0);
        }
        // Compute the two candidate epochs (as if each offset were
        // chosen) and verify which actually carry that offset at
        // their resolved instant. A "valid" candidate's actual
        // offset matches the offset used to compute it.
        $epochWithBefore = bcsub($wallUtcSec, (string) $offBefore, 0);
        $epochWithAfter = bcsub($wallUtcSec, (string) $offAfter, 0);
        try {
            $actualAtBefore = (int) (new \DateTimeImmutable('@' . $epochWithBefore))
                ->setTimezone($tzObj)->format('Z');
            $actualAtAfter = (int) (new \DateTimeImmutable('@' . $epochWithAfter))
                ->setTimezone($tzObj)->format('Z');
        } catch (\Throwable) {
            return self::isoDateTimeToEpochNs($y, $m, $d, $h, $min, $s, $ms, $us, $ns, $tz);
        }
        $beforeValid = $actualAtBefore === $offBefore;
        $afterValid = $actualAtAfter === $offAfter;
        if ($beforeValid && $afterValid) {
            // Fold: both interpretations are valid. Pick by UTC order.
            $earlierEpoch = bccomp($epochWithBefore, $epochWithAfter, 0) < 0
                ? $epochWithBefore : $epochWithAfter;
            $laterEpoch = bccomp($epochWithBefore, $epochWithAfter, 0) < 0
                ? $epochWithAfter : $epochWithBefore;
            if ($disam === 'reject') {
                throw new RangeError(
                    'wall-clock time is ambiguous in a DST fold and disambiguation is "reject"',
                );
            }
            $epochSec = ($disam === 'later') ? $laterEpoch : $earlierEpoch;
            return bcadd(bcmul($epochSec, '1000000000', 0), (string) $nsPart, 0);
        }
        if (!$beforeValid && !$afterValid) {
            // Gap: neither interpretation is valid. The wall-clock
            // time was skipped.
            if ($disam === 'reject') {
                throw new RangeError(
                    'wall-clock time falls in a DST gap and disambiguation is "reject"',
                );
            }
            $earlierEpoch = bccomp($epochWithBefore, $epochWithAfter, 0) < 0
                ? $epochWithBefore : $epochWithAfter;
            $laterEpoch = bccomp($epochWithBefore, $epochWithAfter, 0) < 0
                ? $epochWithAfter : $epochWithBefore;
            // For gap, "earlier" picks the moment with the wall-
            // clock interpretation that points BEFORE the gap;
            // "later"/"compatible" point AFTER. The "before" offset
            // applied to the wall lands AT or BEFORE the
            // transition; the "after" offset lands AT or AFTER.
            // "earlier" disambiguation -> use the BEFORE offset's
            // candidate, which lies AFTER the actual transition (so
            // ironically the LATER UTC). The spec is:
            //   earlier: pick offset that gives the earlier UTC
            //   later:   pick offset that gives the later UTC
            //   compatible (gap): later
            $epochSec = ($disam === 'earlier') ? $earlierEpoch : $laterEpoch;
            return bcadd(bcmul($epochSec, '1000000000', 0), (string) $nsPart, 0);
        }
        // Exactly one valid interpretation.
        $epochSec = $beforeValid ? $epochWithBefore : $epochWithAfter;
        return bcadd(bcmul($epochSec, '1000000000', 0), (string) $nsPart, 0);
    }

    /**
     * Days from the proleptic Gregorian epoch (1970-01-01) to the
     * given Y/M/D. Negative for dates before 1970. Used by the
     * disambiguation helper to compute wall-clock UTC seconds
     * without triggering DateTimeImmutable's timezone interpretation.
     */
    private static function isoDateToDays(int $y, int $m, int $d): int
    {
        // Howard Hinnant's days_from_civil algorithm.
        $y -= ($m <= 2) ? 1 : 0;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($m + ($m > 2 ? -3 : 9)) + 2, 5) + $d - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;
        return $era * 146097 + $doe - 719468;
    }

    /** Return the UTC offset in nanoseconds for the given timezone at a given epoch-ns instant. */
    private static function getUtcOffsetNsForTimestamp(string $tz, string $epochNs): int
    {
        $negative = isset($epochNs[0]) && $epochNs[0] === '-';
        $abs = $negative ? substr($epochNs, 1) : $epochNs;
        $secStr = bcdiv($abs, '1000000000', 0);
        $epochSec = $negative ? '-' . $secStr : $secStr;
        try {
            $dt = new \DateTimeImmutable('@' . $epochSec);
            $local = $dt->setTimezone(self::resolveTimeZone($tz));
            return (int) $local->format('Z') * 1_000_000_000;
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Parse an ISO offset string (e.g. "Z", "+05:30", "-01:00", "+01:35:00.000000000") to nanoseconds. */
    private static function parseOffsetToNs(string $offset): int
    {
        if (strtoupper($offset) === 'Z') {
            return 0;
        }
        // Extended format: +HH:MM[:SS[.fractional]]
        if (preg_match('/^([+-])(\d{2})(?::(\d{2})(?::(\d{2})(?:[.,](\d{1,9}))?)?)?$/', $offset, $m)) {
            $sign = $m[1] === '+' ? 1 : -1;
            $h = (int) $m[2];
            $min = isset($m[3]) ? (int) $m[3] : 0;
            $sec = isset($m[4]) ? (int) $m[4] : 0;
            return $sign * ($h * 3600 + $min * 60 + $sec) * 1_000_000_000;
        }
        // Basic format without colons: +HHMM or +HHMMss
        if (preg_match('/^([+-])(\d{2})(\d{2})(?:(\d{2})(?:[.,](\d{1,9}))?)?$/', $offset, $m)) {
            $sign = $m[1] === '+' ? 1 : -1;
            $h = (int) $m[2];
            $min = (int) $m[3];
            $sec = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;
            return $sign * ($h * 3600 + $min * 60 + $sec) * 1_000_000_000;
        }
        return 0;
    }

    /** Return true if the given string looks like a fixed UTC offset (not an IANA name). */
    private static function isFixedOffset(string $tz): bool
    {
        if (strtoupper($tz) === 'Z' || strtoupper($tz) === 'UTC' || strtoupper($tz) === 'GMT') {
            return true;
        }
        // Accept both colon-separated and compact ISO offset forms:
        //   ±HH, ±HHMM, ±HH:MM, ±HHMMSS, ±HH:MM:SS, plus optional
        //   fractional seconds. ±HHMM occurs in inputs like
        //   "2021-08-19T1730-0700" where the time-zone annotation
        //   strips the date prefix and leaves a compact offset.
        return (bool) preg_match(
            '/^[+-]\d{2}(?::?\d{2}(?::?\d{2}(?:[.,]\d+)?)?)?$/',
            $tz,
        );
    }

    /** Validate ZonedDateTime options in alphabetical order: disambiguation, offset, overflow. */
    private static function validateZonedDateTimeOptions(JsValue $options): void
    {
        if (!$options instanceof JsObject) {
            return;
        }
        $dv = $options->get('disambiguation');
        if (!($dv instanceof JsUndefined)) {
            $dis = TypeConversion::toString($dv);
            if (!in_array($dis, ['compatible', 'earlier', 'later', 'reject'], true)) {
                throw new RangeError("Invalid disambiguation: {$dis}");
            }
        }
        $offOpt = $options->get('offset');
        if (!($offOpt instanceof JsUndefined)) {
            $offStr = TypeConversion::toString($offOpt);
            if (!in_array($offStr, ['prefer', 'use', 'ignore', 'reject'], true)) {
                throw new RangeError("Invalid offset option: {$offStr}");
            }
        }
        self::getOverflow($options);
    }

    /** Validate a UTC offset string per the Temporal spec. Must be +/-HH:MM with optional seconds up to 9 fractional digits. */
    private static function isValidOffsetString(string $str): bool
    {
        // Must start with + or -.
        if ($str === '' || ($str[0] !== '+' && $str[0] !== '-')) {
            return false;
        }
        // Extended format: +HH:MM or +HH:MM:SS or +HH:MM:SS.fffffffff (up to 9 fractional digits).
        if (preg_match('/^[+-]\d{2}:\d{2}(?::\d{2}(?:[.,]\d{1,9})?)?$/', $str)) {
            return true;
        }
        return false;
    }

    private static function normalizeOffset(int $offsetNs): string
    {
        $sign = $offsetNs >= 0 ? '+' : '-';
        $absS = abs(intdiv($offsetNs, 1_000_000_000));
        $h = intdiv($absS, 3600);
        $m = intdiv($absS % 3600, 60);
        $s = $absS % 60;
        $result = $sign . sprintf('%02d', $h) . ':' . sprintf('%02d', $m);
        if ($s !== 0) {
            $result .= ':' . sprintf('%02d', $s);
        }
        return $result;
    }

    private static function timeZoneOffsetString(string $ns, string $tz, bool $roundToMinute = true): string
    {
        // Floor-divide ns to seconds so that sub-second amounts of negative ns
        // round toward -∞ (matching the actual epoch second).
        $secStr = bcdiv($ns, '1000000000', 0);
        $remainder = bcmod($ns, '1000000000');
        if (bccomp($ns, '0', 0) < 0 && $remainder !== '0') {
            $secStr = bcsub($secStr, '1', 0);
        }
        $epochSec = $secStr;

        try {
            $dt = new \DateTimeImmutable('@' . $epochSec);
            $local = $dt->setTimezone(self::resolveTimeZone($tz));
            $offset = (int) $local->format('Z'); // Offset in seconds.
        } catch (\Throwable) {
            return '+00:00';
        }

        $sign = $offset >= 0 ? '+' : '-';
        $absOffset = abs($offset);
        if ($roundToMinute) {
            // Spec FormatDateTimeUTCOffsetRounded: ISO 8601 string
            // serialization rounds sub-minute offsets to the nearest
            // minute (ties away from zero).
            $minutes = (int) round($absOffset / 60, 0, PHP_ROUND_HALF_UP);
            $h = intdiv($minutes, 60);
            $m = $minutes % 60;
            return $sign . self::pad2($h) . ':' . self::pad2($m);
        }
        // ZDT.offset getter preserves the exact sub-minute offset.
        $h = intdiv($absOffset, 3600);
        $m = intdiv($absOffset % 3600, 60);
        $s = $absOffset % 60;
        $result = $sign . self::pad2($h) . ':' . self::pad2($m);
        if ($s !== 0) {
            $result .= ':' . self::pad2($s);
        }
        return $result;
    }
}
