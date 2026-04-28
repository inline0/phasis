<?php

declare(strict_types=1);

namespace PhpJs\Object;

use PhpJs\Value\JsValue;

/**
 * Property map with separate fast / slow stores.
 *
 * Default-attr data slots live in $dataSlots as raw JsValue; non-default
 * and accessor slots live in $descriptors as PropertyDescriptor. The two
 * are mutually exclusive: a key is in at most one store at a time. Hot
 * paths in JsObject access $dataSlots directly via the public field for
 * a single isset / array hit with no instanceof or method dispatch.
 *
 * Insertion order across both stores is tracked in $order so ownKeys /
 * for-in / Object.keys preserve spec ordering through promote / demote.
 */
class PropertyMap
{
    /**
     * Default-attr data slots. Public so JsObject can read and update
     * inline without method dispatch.
     *
     * @var array<string, JsValue>
     */
    public array $dataSlots = [];

    /**
     * Slow-path descriptors. Public so JsObject's slow paths can read
     * inline too.
     *
     * @var array<string, PropertyDescriptor>
     */
    public array $descriptors = [];

    /**
     * Insertion-ordered set of keys spanning both stores. The value is
     * unused; array_keys($order) gives the merged ordering.
     *
     * @var array<string, bool>
     */
    private array $order = [];

    public function get(string $key): ?PropertyDescriptor
    {
        if (isset($this->dataSlots[$key])) {
            return PropertyDescriptor::data($this->dataSlots[$key]);
        }
        return $this->descriptors[$key] ?? null;
    }

    public function getValue(string $key): ?JsValue
    {
        return $this->dataSlots[$key] ?? null;
    }

    public function getDescriptor(string $key): ?PropertyDescriptor
    {
        return $this->descriptors[$key] ?? null;
    }

    public function set(string $key, PropertyDescriptor $desc): void
    {
        if (
            $desc->get === null
            && $desc->set === null
            && $desc->value !== null
            && $desc->writable === true
            && $desc->enumerable === true
            && $desc->configurable === true
            && !$desc->isAccessorDescriptor()
        ) {
            unset($this->descriptors[$key]);
            $this->dataSlots[$key] = $desc->value;
            $this->order[$key] = true;
            return;
        }
        unset($this->dataSlots[$key]);
        $this->descriptors[$key] = $desc;
        $this->order[$key] = true;
    }

    public function setDataSlot(string $key, JsValue $value): void
    {
        $this->dataSlots[$key] = $value;
        $this->order[$key] = true;
    }

    public function has(string $key): bool
    {
        return isset($this->order[$key]);
    }

    public function delete(string $key): bool
    {
        unset($this->dataSlots[$key]);
        unset($this->descriptors[$key]);
        unset($this->order[$key]);
        return true;
    }

    /** @return list<string> Keys in insertion order. */
    public function keys(): array
    {
        return array_map('strval', array_keys($this->order));
    }

    /** @return list<string> Only enumerable keys, in insertion order. */
    public function enumerableKeys(): array
    {
        $result = [];
        foreach ($this->order as $key => $_) {
            $key = (string) $key;
            if (isset($this->dataSlots[$key])) {
                $result[] = $key;
                continue;
            }
            $desc = $this->descriptors[$key] ?? null;
            if ($desc !== null && $desc->enumerable === true) {
                $result[] = $key;
            }
        }
        return $result;
    }

    public function count(): int
    {
        return count($this->order);
    }
}
