<?php

declare(strict_types=1);

namespace PhpJs\Value;

use PhpJs\Spec\AbstractOperations;

/**
 * JavaScript Set object.
 *
 * Stores unique values using SameValueZero for comparison.
 * Uses a slot-based internal list where deleted entries are marked as a
 * sentinel rather than removed, so that live iteration during forEach
 * and iterators correctly sees additions and skips deletions per spec.
 */
class JsSet extends JsObject
{
    /**
     * Internal [[SetData]] list. Each slot is either a JsValue for a live
     * entry or null for a deleted (empty) slot.
     *
     * @var list<JsValue|null>
     */
    private array $values = [];

    /** Number of live (non-null) entries. */
    private int $liveCount = 0;

    public function __construct(?JsObject $prototype = null)
    {
        parent::__construct($prototype);
    }

    public function setAdd(JsValue $value): void
    {
        // Normalize -0 to +0 per spec.
        if ($value instanceof JsNumber && $value->value === 0.0) {
            $value = JsNumber::of(0.0);
        }

        if (!$this->setHas($value)) {
            $this->values[] = $value;
            $this->liveCount++;
        }
    }

    public function setHas(JsValue $value): bool
    {
        return $this->findIndex($value) !== -1;
    }

    public function setDelete(JsValue $value): bool
    {
        $index = $this->findIndex($value);
        if ($index === -1) {
            return false;
        }
        // Mark the slot as empty rather than splicing, so iteration indices stay valid.
        $this->values[$index] = null;
        $this->liveCount--;
        return true;
    }

    public function setClear(): void
    {
        $count = count($this->values);
        for ($i = 0; $i < $count; $i++) {
            $this->values[$i] = null;
        }
        $this->liveCount = 0;
    }

    public function setSize(): int
    {
        return $this->liveCount;
    }

    /**
     * Live iteration per spec: iterate by index, re-check length each step,
     * skip empty (deleted) slots, and see entries appended during iteration.
     */
    public function setForEach(JsFunction $callback, JsValue $thisArg): void
    {
        $index = 0;
        while ($index < count($this->values)) {
            $value = $this->values[$index];
            $index++;
            if ($value === null) {
                continue;
            }
            $callback->call($thisArg, [$value, $value, $this]);
        }
    }

    /**
     * Return the total number of slots (including empty) for iterator use.
     */
    public function slotCount(): int
    {
        return count($this->values);
    }

    /**
     * Get the value at a given slot index, or null if the slot is empty.
     */
    public function getSlot(int $index): ?JsValue
    {
        return $this->values[$index] ?? null;
    }

    /** @return list<JsValue> */
    public function setValues(): array
    {
        $result = [];
        foreach ($this->values as $value) {
            if ($value !== null) {
                $result[] = $value;
            }
        }
        return $result;
    }


    /**
     * Create a shallow copy of this Set. By default the copy shares the
     * receiver's [[Prototype]]; pass $proto to force a specific prototype
     * (used by Set.prototype methods that must always return a plain Set).
     * Only live (non-deleted) entries are copied, producing a compact list.
     */
    public function copy(?JsObject $proto = null): self
    {
        $clone = new self($proto ?? $this->getPrototype());
        foreach ($this->values as $value) {
            if ($value !== null) {
                $clone->values[] = $value;
                $clone->liveCount++;
            }
        }
        return $clone;
    }

    public function toJsString(): string
    {
        return '[object Set]';
    }

    public function display(): string
    {
        $parts = [];
        foreach ($this->values as $value) {
            if ($value !== null) {
                $parts[] = $value->display();
            }
        }
        return 'Set(' . $this->liveCount . ') { ' . implode(', ', $parts) . ' }';
    }

    /**
     * Find the slot index of a value using SameValueZero comparison.
     * Returns -1 if not found (or only found in empty slots).
     */
    private function findIndex(JsValue $value): int
    {
        foreach ($this->values as $i => $stored) {
            if ($stored !== null && AbstractOperations::sameValueZero($stored, $value)) {
                return $i;
            }
        }
        return -1;
    }
}
