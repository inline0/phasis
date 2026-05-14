<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Date;

use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
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
 * Date trait part: DateTimezone. Composed into DateConstructor via
 * `use Date\DateTimezone;`. `self::`/`$this->` resolve into the
 * composing class so static-property + cross-trait calls work.
 */
trait DateTimezone
{
    /**
     * Get a DateTimeImmutable in local timezone from the internal timestamp.
     */
    private static function localDateTime(float $tv): \DateTimeImmutable
    {
        $ts = (int) floor($tv / 1000);
        $dt = new \DateTimeImmutable('@' . $ts);
        return $dt->setTimezone(self::cachedLocalTimeZone());
    }

    /**
     * Return a reusable DateTimeZone for the current default time zone.
     *
     * Constructing DateTimeZone is cheap individually but the SpiderMonkey
     * DST stress harness invokes getTimezoneOffset ~2.6M times per fraction,
     * so memoizing this saves measurable time. The cache is invalidated when
     * date_default_timezone_get() changes (e.g., via setTimezone host hook
     * inside the SM shell harness).
     */
    private static function cachedLocalTimeZone(): \DateTimeZone
    {
        $name = date_default_timezone_get();
        if (self::$tzCacheObject === null || self::$tzCacheName !== $name) {
            self::$tzCacheName = $name;
            self::$tzCacheObject = new \DateTimeZone($name);
            self::$tzCacheTsBoundaries = [];
            self::$tzCacheOffsets = [];
            self::$tzCacheRangeFrom = 0;
            self::$tzCacheRangeTo = 0;
            self::$tzHotFrom0 = PHP_INT_MAX;
            self::$tzHotTo0 = PHP_INT_MIN;
            self::$tzHotFrom1 = PHP_INT_MAX;
            self::$tzHotTo1 = PHP_INT_MIN;
            self::$tzDirectCache = [];
        }
        return self::$tzCacheObject;
    }

    /**
     * Return the local-time offset in seconds (UTC + offset = local time)
     * for the given time value in ms.
     *
     * Performs a binary search over a cached, lazily-expanded transition
     * table for the active time zone. SpiderMonkey ships a per-realm DST
     * offset cache for exactly this purpose; without it the SM dst-offset
     * caching stress tests time out under a tree-walking interpreter.
     */
    private static function localOffsetSeconds(float $tv): int
    {
        $tsSec = (int) floor($tv / 1000);

        // Tier 1: direct ts-second hashmap. The SM dst-offset-caching
        // stress harness queries the same ~80 timestamps in a 4-deep
        // loop ~2.7M times per fraction; once warm a single isset +
        // array fetch answers every call. Validity tracks the segment
        // cache: cleared in cachedLocalTimeZone() whenever the
        // underlying zone changes.
        if (isset(self::$tzDirectCache[$tsSec])) {
            return self::$tzDirectCache[$tsSec];
        }

        // Tier 2: SpiderMonkey-style hot-window probes. A fresh
        // timestamp that falls inside a recently observed constant-
        // offset interval bypasses the binary search entirely. Useful
        // when the direct map is dropped (e.g. on overflow) or before
        // it warms.
        if ($tsSec >= self::$tzHotFrom0 && $tsSec <= self::$tzHotTo0) {
            if (count(self::$tzDirectCache) < self::TZ_DIRECT_CACHE_LIMIT) {
                self::$tzDirectCache[$tsSec] = self::$tzHotOff0;
            }
            return self::$tzHotOff0;
        }
        if ($tsSec >= self::$tzHotFrom1 && $tsSec <= self::$tzHotTo1) {
            // Promote the matched slot to "young" so subsequent hits stay
            // in the fastest path.
            $f = self::$tzHotFrom1;
            $t = self::$tzHotTo1;
            $o = self::$tzHotOff1;
            self::$tzHotFrom1 = self::$tzHotFrom0;
            self::$tzHotTo1 = self::$tzHotTo0;
            self::$tzHotOff1 = self::$tzHotOff0;
            self::$tzHotFrom0 = $f;
            self::$tzHotTo0 = $t;
            self::$tzHotOff0 = $o;
            if (count(self::$tzDirectCache) < self::TZ_DIRECT_CACHE_LIMIT) {
                self::$tzDirectCache[$tsSec] = $o;
            }
            return $o;
        }

        $tz = self::cachedLocalTimeZone();
        if ($tsSec < self::$tzCacheRangeFrom || $tsSec > self::$tzCacheRangeTo) {
            self::expandTzCache($tz, $tsSec);
        }

        $boundaries = self::$tzCacheTsBoundaries;
        $offsets = self::$tzCacheOffsets;
        $hi = count($boundaries) - 1;
        if ($hi < 0) {
            // Empty cache (unsupported tz?). Fall back to PHP native lookup.
            return (int) (new \DateTimeImmutable('@' . $tsSec))->setTimezone($tz)->format('Z');
        }
        if ($tsSec < $boundaries[0]) {
            $offset = $offsets[0];
            $segFrom = self::$tzCacheRangeFrom;
            $segTo = $boundaries[0] - 1;
        } elseif ($tsSec >= $boundaries[$hi]) {
            $offset = $offsets[$hi];
            $segFrom = $boundaries[$hi];
            $segTo = self::$tzCacheRangeTo;
        } else {
            $lo = 0;
            while ($lo < $hi) {
                $mid = ($lo + $hi + 1) >> 1;
                if ($boundaries[$mid] <= $tsSec) {
                    $lo = $mid;
                } else {
                    $hi = $mid - 1;
                }
            }
            $offset = $offsets[$lo];
            $segFrom = $boundaries[$lo];
            $segTo = ($lo + 1 < count($boundaries)) ? $boundaries[$lo + 1] - 1 : self::$tzCacheRangeTo;
        }

        // Record the constant-offset segment so the next adjacent lookup
        // skips both the time-zone resolve and the binary search.
        self::$tzHotFrom1 = self::$tzHotFrom0;
        self::$tzHotTo1 = self::$tzHotTo0;
        self::$tzHotOff1 = self::$tzHotOff0;
        self::$tzHotFrom0 = $segFrom;
        self::$tzHotTo0 = $segTo;
        self::$tzHotOff0 = $offset;

        // Memoize the exact ts in the direct cache so subsequent
        // identical probes (the dominant pattern in tight loops) skip
        // every tier above. Capped to keep memory bounded if the caller
        // sweeps a wide range of distinct timestamps.
        if (count(self::$tzDirectCache) < self::TZ_DIRECT_CACHE_LIMIT) {
            self::$tzDirectCache[$tsSec] = $offset;
        }

        return $offset;
    }

    /**
     * Expand the cached transition window to include $tsSec.
     *
     * Uses DateTimeZone::getTransitions over a wide window (±30y by default)
     * and stores the boundary timestamps and resulting offsets in parallel
     * arrays for cheap binary search.
     */
    private static function expandTzCache(\DateTimeZone $tz, int $tsSec): void
    {
        $from = $tsSec - self::TZ_CACHE_EXPANSION;
        $to = $tsSec + self::TZ_CACHE_EXPANSION;

        // Union with the existing window if any, so callers walking
        // outwards don't repeatedly thrash the cache.
        if (self::$tzCacheRangeTo > self::$tzCacheRangeFrom) {
            if (self::$tzCacheRangeFrom < $from) {
                $from = self::$tzCacheRangeFrom;
            }
            if (self::$tzCacheRangeTo > $to) {
                $to = self::$tzCacheRangeTo;
            }
        }

        // DateTimeZone::getTransitions returns an array starting with a
        // synthetic entry at the lower bound describing the offset already
        // in effect at $from, followed by every offset change up to $to.
        // PHP stubs annotate the return as list<array>, but at runtime
        // fixed-offset zones (like "+05:30") yield false; the @ suppresses
        // the warning and the empty/false branch falls back to a single
        // synthetic entry.
        /** @var list<array{ts: int, offset: int}>|false $transitions */
        $transitions = @$tz->getTransitions($from, $to);
        $boundaries = [];
        $offsets = [];
        if ($transitions !== false && count($transitions) > 0) {
            foreach ($transitions as $t) {
                $boundaries[] = (int) $t['ts'];
                $offsets[] = (int) $t['offset'];
            }
        } else {
            // Fixed-offset zone: compute the constant offset once.
            $offsetSec = (int) (new \DateTimeImmutable('@' . $tsSec))->setTimezone($tz)->format('Z');
            $boundaries[] = $from;
            $offsets[] = $offsetSec;
        }

        self::$tzCacheTsBoundaries = $boundaries;
        self::$tzCacheOffsets = $offsets;
        self::$tzCacheRangeFrom = $from;
        self::$tzCacheRangeTo = $to;
        // Boundary positions may have shifted; clear the hot slots so the
        // next probe re-records a segment that is now consistent with the
        // refreshed transition table. The direct cache stays valid for the
        // same tz (offsets at a specific ts are stable across window
        // expansion), so we deliberately keep it across an expand.
        self::$tzHotFrom0 = PHP_INT_MAX;
        self::$tzHotTo0 = PHP_INT_MIN;
        self::$tzHotFrom1 = PHP_INT_MAX;
        self::$tzHotTo1 = PHP_INT_MIN;
    }

    /**
     * Get a DateTimeImmutable in UTC from the internal timestamp.
     */
    private static function utcDateTime(float $tv): \DateTimeImmutable
    {
        $ts = (int) floor($tv / 1000);
        $dt = new \DateTimeImmutable('@' . $ts);
        return $dt->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Compose a Unix timestamp from date/time components in local time.
     *
     * Uses DateTimeImmutable instead of mktime to correctly handle years < 100
     * (mktime adds 1900/2000 to years 0-99 which breaks setFullYear).
     *
     * @param int $y Year
     * @param int $m Month (0-based JS month)
     * @param int $d Day
     * @param int $h Hours
     * @param int $min Minutes
     * @param int $sec Seconds
     * @return int|null Unix timestamp, or null on failure
     */
    private static function composeLocalTimestamp(int $y, int $m, int $d, int $h, int $min, int $sec): ?int
    {
        return self::composeTimestamp($y, $m, $d, $h, $min, $sec, new \DateTimeZone(date_default_timezone_get()));
    }

    /**
     * Compose a Unix timestamp from date/time components in UTC.
     *
     * Uses DateTimeImmutable instead of gmmktime to correctly handle years < 100.
     *
     * @param int $y Year
     * @param int $m Month (0-based JS month)
     * @param int $d Day
     * @param int $h Hours
     * @param int $min Minutes
     * @param int $sec Seconds
     * @return int|null Unix timestamp, or null on failure
     */
    private static function composeUtcTimestamp(int $y, int $m, int $d, int $h, int $min, int $sec): ?int
    {
        return self::composeTimestamp($y, $m, $d, $h, $min, $sec, new \DateTimeZone('UTC'));
    }

    /**
     * Compose a Unix timestamp from date/time components in the given timezone.
     *
     * Handles month overflow/underflow, day overflow, and arbitrary years
     * (including years 0-99 which mktime/gmmktime misinterpret).
     *
     * @param int $y Year
     * @param int $m Month (0-based JS month)
     * @param int $d Day
     * @param int $h Hours
     * @param int $min Minutes
     * @param int $sec Seconds
     * @return int|null Unix timestamp, or null on failure
     */
    private static function composeTimestamp(
        int $y,
        int $m,
        int $d,
        int $h,
        int $min,
        int $sec,
        \DateTimeZone $tz,
    ): ?int {
        // Handle month overflow/underflow: JS months are 0-based.
        $adjustedYear = $y + intdiv($m, 12);
        $adjustedMonth = $m % 12;
        if ($adjustedMonth < 0) {
            $adjustedMonth += 12;
            $adjustedYear--;
        }
        $phpMonth = $adjustedMonth + 1; // Convert to 1-based

        try {
            // Use a reference date then setDate/setTime to avoid mktime's year bugs.
            $dt = new \DateTimeImmutable('2000-01-01 00:00:00', $tz);
            $dt = $dt->setDate($adjustedYear, $phpMonth, $d);
            $dt = $dt->setTime($h, $min, $sec);
            return (int) $dt->format('U');
        } catch (\Throwable) {
            return null;
        }
    }
}
