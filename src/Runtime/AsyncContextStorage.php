<?php

declare(strict_types=1);

namespace Phasis\Runtime;

use Phasis\Value\JsObject;
use Phasis\Value\JsValue;

/**
 * Per-process active map of `AsyncContext.Variable` instance →
 * current value. The map is the single mutable cell that
 * `JsPromise::scheduleCallback` and the EventLoop snapshot/restore
 * around each scheduled callback, so context flows across async
 * boundaries.
 *
 * Single-process is fine because Phasis runs one JS realm per PHP
 * request and the static `$active` cell is reset on Engine::reset.
 * Inside ShadowRealm sub-engines, each engine's microtask path runs
 * through the same `JsPromise` static — they share the cell, which
 * matches the spec's "AsyncContext is realm-aware but the storage
 * is per-agent" framing for now.
 *
 * Keys are JsObject instances (the Variable handles). PHP's
 * SplObjectStorage gives us O(1) keyed-by-identity storage without
 * implementing __hashCode on JsObject.
 */
final class AsyncContextStorage
{
    private static ?self $active = null;

    /** @var \SplObjectStorage<JsObject, JsValue> */
    private \SplObjectStorage $values;

    private function __construct()
    {
        $this->values = new \SplObjectStorage();
    }

    public static function active(): self
    {
        if (self::$active === null) {
            self::$active = new self();
        }
        return self::$active;
    }

    public static function reset(): void
    {
        self::$active = null;
    }

    /** Set a variable's current value. */
    public function set(JsObject $variable, JsValue $value): void
    {
        $this->values[$variable] = $value;
    }

    /**
     * Read the current value for $variable. Returns null when no
     * value has been set on this storage (caller falls back to the
     * variable's defaultValue).
     */
    public function getRaw(JsObject $variable): ?JsValue
    {
        return $this->values[$variable] ?? null;
    }

    /**
     * Snapshot the full map for capture/restore around scheduled
     * callbacks. The returned array is keyed by spl_object_id so
     * the SplObjectStorage iteration order is preserved.
     *
     * @return array{0: array<int, JsObject>, 1: array<int, JsValue>}
     */
    public function snapshot(): array
    {
        $keys = [];
        $vals = [];
        foreach ($this->values as $obj) {
            $id = spl_object_id($obj);
            $keys[$id] = $obj;
            $vals[$id] = $this->values[$obj];
        }
        return [$keys, $vals];
    }

    /**
     * Restore a previously-captured snapshot. Replaces the current
     * map completely.
     *
     * @param array{0: array<int, JsObject>, 1: array<int, JsValue>} $snapshot
     */
    public function restore(array $snapshot): void
    {
        $this->values = new \SplObjectStorage();
        [$keys, $vals] = $snapshot;
        foreach ($keys as $id => $obj) {
            $this->values[$obj] = $vals[$id];
        }
    }

    /**
     * Restore a single slot to a prior value (or remove it when
     * `null`). Used by `Variable.run(value, fn)` to keep other
     * variables untouched while undoing the one it set.
     */
    public function restoreSlot(JsObject $variable, ?JsValue $previous): void
    {
        if ($previous === null) {
            unset($this->values[$variable]);
        } else {
            $this->values[$variable] = $previous;
        }
    }
}
