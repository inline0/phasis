<?php

declare(strict_types=1);

namespace Phasis\Value;

use Phasis\Spec\AbstractOperations;

/**
 * JavaScript Map object.
 *
 * Stores key-value entries using SameValueZero for key comparison.
 * Uses a slot-based internal list where deleted entries are marked as empty
 * (null key) rather than removed, so that live iteration during forEach
 * and iterators correctly sees additions and skips deletions per spec.
 */
class JsMap extends JsObject
{
    /**
     * Internal [[MapData]] list. Each slot is either [key, value] for a live
     * entry or null for a deleted (empty) slot.
     *
     * @var list<array{0: JsValue, 1: JsValue}|null>
     */
    private array $entries = [];

    /** Number of live (non-null) entries. */
    private int $liveCount = 0;

    public function __construct(?JsObject $prototype = null)
    {
        parent::__construct($prototype);
    }

    public function mapGet(JsValue $key): JsValue
    {
        $index = $this->findIndex($key);
        if ($index === -1) {
            return JsUndefined::instance();
        }
        return $this->entries[$index][1];
    }

    public function mapSet(JsValue $key, JsValue $value): void
    {
        // Normalize -0 to +0 for key storage per spec.
        if ($key instanceof JsNumber && $key->value === 0.0) {
            $key = JsNumber::of(0.0);
        }

        $index = $this->findIndex($key);
        if ($index !== -1) {
            $this->entries[$index][1] = $value;
        } else {
            $this->entries[] = [$key, $value];
            $this->liveCount++;
        }
    }

    public function mapHas(JsValue $key): bool
    {
        return $this->findIndex($key) !== -1;
    }

    public function mapDelete(JsValue $key): bool
    {
        $index = $this->findIndex($key);
        if ($index === -1) {
            return false;
        }
        // Mark the slot as empty rather than splicing, so iteration indices stay valid.
        $this->entries[$index] = null;
        $this->liveCount--;
        return true;
    }

    public function mapClear(): void
    {
        // Mark all slots as empty so in-progress iterators see them as deleted.
        $count = count($this->entries);
        for ($i = 0; $i < $count; $i++) {
            $this->entries[$i] = null;
        }
        $this->liveCount = 0;
    }

    public function mapSize(): int
    {
        return $this->liveCount;
    }

    /**
     * Live iteration per spec: iterate by index, re-check length each step,
     * skip empty (deleted) slots, and see entries appended during iteration.
     */
    public function mapForEach(JsFunction $callback, JsValue $thisArg): void
    {
        $index = 0;
        while ($index < count($this->entries)) {
            $entry = $this->entries[$index];
            $index++;
            if ($entry === null) {
                continue;
            }
            $callback->call($thisArg, [$entry[1], $entry[0], $this]);
        }
    }

    /**
     * Return the total number of slots (including empty) for iterator use.
     */
    public function slotCount(): int
    {
        return count($this->entries);
    }

    /**
     * Get the entry at a given slot index, or null if the slot is empty.
     *
     * @return array{0: JsValue, 1: JsValue}|null
     */
    public function getSlot(int $index): ?array
    {
        return $this->entries[$index] ?? null;
    }

    /** @return list<array{0: JsValue, 1: JsValue}> */
    public function mapEntries(): array
    {
        $result = [];
        foreach ($this->entries as $entry) {
            if ($entry !== null) {
                $result[] = $entry;
            }
        }
        return $result;
    }

    /** @return list<JsValue> */
    public function mapKeys(): array
    {
        $result = [];
        foreach ($this->entries as $entry) {
            if ($entry !== null) {
                $result[] = $entry[0];
            }
        }
        return $result;
    }

    /** @return list<JsValue> */
    public function mapValues(): array
    {
        $result = [];
        foreach ($this->entries as $entry) {
            if ($entry !== null) {
                $result[] = $entry[1];
            }
        }
        return $result;
    }

    public function toJsString(): string
    {
        return '[object Map]';
    }

    public function display(): string
    {
        $parts = [];
        foreach ($this->entries as $entry) {
            if ($entry !== null) {
                $parts[] = $entry[0]->display() . ' => ' . $entry[1]->display();
            }
        }
        return 'Map(' . $this->liveCount . ') { ' . implode(', ', $parts) . ' }';
    }

    /**
     * Find the slot index of a key using SameValueZero comparison.
     * Returns -1 if not found (or only found in empty slots).
     */
    private function findIndex(JsValue $key): int
    {
        foreach ($this->entries as $i => $entry) {
            if ($entry !== null && AbstractOperations::sameValueZero($entry[0], $key)) {
                return $i;
            }
        }
        return -1;
    }
}
