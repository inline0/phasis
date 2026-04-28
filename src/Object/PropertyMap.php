<?php

declare(strict_types=1);

namespace PhpJs\Object;

use PhpJs\Value\JsValue;

/**
 * Property map with in-object data slots.
 *
 * Slots whose descriptor matches the default-attr data shape (writable,
 * enumerable, configurable; no accessor) live in $slots as a raw JsValue
 * with no PropertyDescriptor wrapper. Hot-path reads and writes on
 * JsObject go straight through the public $slots array — no method
 * dispatch, no descriptor materialization, no allocation.
 *
 * Non-default and accessor descriptors share the same $slots array as
 * full PropertyDescriptor instances. Insertion order across both shapes
 * is preserved because there is only one underlying array.
 *
 * @phpstan-type SlotValue JsValue|PropertyDescriptor
 */
class PropertyMap
{
    /**
     * Combined property storage. Each slot is either a raw JsValue
     * (default-attr data slot, fast path) or a PropertyDescriptor
     * (non-default attrs or accessor). PropertyDescriptor does not
     * implement JsValue, so an `instanceof JsValue` check
     * distinguishes the two without ambiguity.
     *
     * @var array<string, JsValue|PropertyDescriptor>
     */
    public array $slots = [];

    public function get(string $key): ?PropertyDescriptor
    {
        $val = $this->slots[$key] ?? null;
        if ($val === null) {
            return null;
        }
        if ($val instanceof PropertyDescriptor) {
            return $val;
        }
        return PropertyDescriptor::data($val);
    }

    /**
     * Returns the raw slot value when it is a default-attr data slot,
     * otherwise null. Lets hot paths skip the temp PropertyDescriptor
     * allocation that get() would do.
     */
    public function getValue(string $key): ?JsValue
    {
        $val = $this->slots[$key] ?? null;
        if ($val instanceof JsValue) {
            return $val;
        }
        return null;
    }

    /**
     * Returns the slow descriptor when one exists, otherwise null.
     * Distinct from get() in that it does not materialize a temp
     * descriptor for fast slots.
     */
    public function getDescriptor(string $key): ?PropertyDescriptor
    {
        $val = $this->slots[$key] ?? null;
        return ($val instanceof PropertyDescriptor) ? $val : null;
    }

    public function set(string $key, PropertyDescriptor $desc): void
    {
        // Promote to fast slot when the descriptor matches the default
        // data shape. This is the common case for object-literal
        // properties and CreateDataProperty.
        if (
            $desc->get === null
            && $desc->set === null
            && $desc->value !== null
            && $desc->writable === true
            && $desc->enumerable === true
            && $desc->configurable === true
            && !$desc->isAccessorDescriptor()
        ) {
            $this->slots[$key] = $desc->value;
            return;
        }
        $this->slots[$key] = $desc;
    }

    /**
     * Direct fast-slot write. Caller is responsible for ensuring no
     * existing slow descriptor would be silently replaced.
     */
    public function setDataSlot(string $key, JsValue $value): void
    {
        $this->slots[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($this->slots[$key]);
    }

    public function delete(string $key): bool
    {
        unset($this->slots[$key]);
        return true;
    }

    /** @return list<string> Keys in insertion order. */
    public function keys(): array
    {
        return array_map('strval', array_keys($this->slots));
    }

    /** @return list<string> Only enumerable keys, in insertion order. */
    public function enumerableKeys(): array
    {
        $result = [];
        foreach ($this->slots as $key => $val) {
            if ($val instanceof PropertyDescriptor) {
                if ($val->enumerable === true) {
                    $result[] = (string) $key;
                }
            } else {
                // Fast slots are enumerable by construction.
                $result[] = (string) $key;
            }
        }
        return $result;
    }

    public function count(): int
    {
        return count($this->slots);
    }
}
