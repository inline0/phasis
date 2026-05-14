<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

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

/**
 * Date constructor and prototype methods.
 *
 * Stores the internal time value as milliseconds since the Unix epoch (1970-01-01T00:00:00Z).
 * NaN means the Date is invalid. All arithmetic and accessors use this internal float.
 */
class DateConstructor
{
    use Date\DateParsing;
    use Date\DateConstruction;
    use Date\DateFormatting;
    use Date\DateTimezone;
    use Date\DateAccessors;

    public static function install(Environment $env): void
    {
        $proto = self::createPrototype();

        // Cache %Date.prototype% so the bytecode VM's date.construct fast
        // path (see vmFastNewDate) skips a prototype property lookup on
        // every `new Date(...)`.
        self::$datePrototype = $proto;

        $constructor = JsFunction::fromCallable('Date', function (JsValue $this_, array $args) use ($proto): JsValue {
            // Called as a function (without new): always return the current date string.
            if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                return new JsString(self::toDateString(self::nowMs()));
            }

            // Called with new: construct a Date object.
            // Per §9.1.13 OrdinaryCreateFromConstructor, the prototype must
            // come from newTarget.prototype (fall back to %Date.prototype%
            // if newTarget.prototype is not an object).
            $newTarget = $this_->get('[[NewTarget]]');
            if ($newTarget instanceof JsObject) {
                $ntProto = $newTarget->get('prototype');
                $useProto = $ntProto instanceof JsObject ? $ntProto : $proto;
                $this_->setPrototype($useProto);
            }
            $timeValue = self::constructTimeValue($args);
            // Internal slots bypass the property map: direct hashmap
            // writes that the matching reads in isDateObject() /
            // dateValueOf() pick up in O(1).
            $this_->setInternalProperty('[[DateValue]]', $timeValue);
            $this_->setInternalProperty('[[IsDate]]', true);
            return $this_;
        }, 7);
        $constructor->setConstructable();
        // Tag for the bytecode VM's NEW_CALL fast path. Only the original
        // Date constructor short-circuits; subclasses still flow through
        // vmNewExpression so [[NewTarget]] / prototype semantics stay
        // intact.
        $constructor->builtinKind = 'date.construct';
        self::$dateConstructor = $constructor;

        // Static methods.
        $constructor->defineOwnProperty('now', PropertyDescriptor::data(
            JsFunction::fromCallable('now', function (JsValue $this_, array $args): JsValue {
                return JsNumber::of(self::nowMs());
            }, 0),
            true,
            false,
            true,
        ));

        $constructor->defineOwnProperty('parse', PropertyDescriptor::data(
            JsFunction::fromCallable('parse', function (JsValue $this_, array $args): JsValue {
                $str = isset($args[0]) ? TypeConversion::toString($args[0]) : '';
                return JsNumber::of(self::parseDate($str));
            }, 1),
            true,
            false,
            true,
        ));

        $constructor->defineOwnProperty('UTC', PropertyDescriptor::data(
            JsFunction::fromCallable('UTC', function (JsValue $this_, array $args): JsValue {
                return JsNumber::of(self::makeUtcMs($args));
            }, 7),
            true,
            false,
            true,
        ));

        $constructor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($constructor, true, false, true));

        $env->defineVar('Date', $constructor);
    }






















    /**
     * Cached %Date.prototype% snapshot used by the bytecode VM's
     * date.construct fast path (see vmFastNewDate). Populated once at
     * install time so `new Date(...)` does not pay a prototype property
     * lookup per call.
     */
    private static ?JsObject $datePrototype = null;

    /**
     * Cached Date constructor reference. The fast path verifies the
     * callee identity matches before short-circuiting; a subclass like
     * `class Foo extends Date {}` must still flow through
     * vmNewExpression so it picks up its own prototype.
     */
    private static ?JsFunction $dateConstructor = null;




    /**
     * Currently cached IANA name (matches date_default_timezone_get()).
     */
    private static ?string $tzCacheName = null;

    /** Reusable DateTimeZone for the cached IANA name. */
    private static ?\DateTimeZone $tzCacheObject = null;

    /**
     * Sorted list of DST transition timestamps (Unix seconds) for the
     * cached time zone. Transition i is in effect during
     * [tsBoundaries[i], tsBoundaries[i+1]).
     *
     * @var list<int>
     */
    private static array $tzCacheTsBoundaries = [];

    /**
     * Parallel list of offsets (in seconds) that apply at and after each
     * boundary in $tzCacheTsBoundaries.
     *
     * @var list<int>
     */
    private static array $tzCacheOffsets = [];

    /** Lower bound (Unix seconds) of the cached transition window. */
    private static int $tzCacheRangeFrom = 0;

    /** Upper bound (Unix seconds) of the cached transition window. */
    private static int $tzCacheRangeTo = 0;

    /**
     * Two most recently observed (rangeStart, rangeEnd, offset) hits, with
     * "young" probed first. This mirrors SpiderMonkey's DST cache: a fresh
     * timestamp that falls inside a known constant-offset interval bypasses
     * the binary search entirely. Decisive for the dst-offset-caching
     * harness, whose four-deep loop hammers a handful of distinct
     * timestamps over and over.
     *
     * Layout: [from0, to0, offset0, from1, to1, offset1]. Set to PHP_INT_MAX
     * for "from" and PHP_INT_MIN for "to" so empty slots never match.
     */
    private static int $tzHotFrom0 = PHP_INT_MAX;
    private static int $tzHotTo0 = PHP_INT_MIN;
    private static int $tzHotOff0 = 0;
    private static int $tzHotFrom1 = PHP_INT_MAX;
    private static int $tzHotTo1 = PHP_INT_MIN;
    private static int $tzHotOff1 = 0;

    /**
     * Direct ts-second to offset-second cache, populated lazily on every
     * resolved lookup. The SM dst-offset-caching stress harness only
     * queries ~80 distinct timestamps in its inner loop (38
     * TEST_TIMESTAMPS plus their "opposite" mirrors plus 0 and
     * MAX_UNIX_TIMET), so once warm every call is a single hashmap probe
     * rather than a segment-bound check plus a binary search. Cleared
     * lock-step with the transition cache whenever the active zone
     * changes.
     *
     * Capped at TZ_DIRECT_CACHE_LIMIT entries: scripts that query a
     * wide cloud of distinct timestamps (millisecond-resolution sweeps)
     * must not balloon the cache. When the cap is hit we stop filling
     * it and keep relying on the segment cache (still O(log N)).
     *
     * @var array<int, int>
     */
    private static array $tzDirectCache = [];

    /**
     * Soft cap on $tzDirectCache. 4096 comfortably covers the SM stress
     * harness (~80 timestamps) and any plausible interactive workload
     * while keeping memory bounded for pathological inputs.
     */
    private const TZ_DIRECT_CACHE_LIMIT = 4096;

    /**
     * Default expansion of the transition window on a cache miss, in seconds.
     * 30 years on each side keeps the cached array small (~60 transitions for
     * any DST-using zone) while spanning the vast majority of the inputs the
     * SpiderMonkey DST stress harness probes.
     */
    private const TZ_CACHE_EXPANSION = 30 * 365 * 86400;
}
