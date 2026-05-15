<?php

declare(strict_types=1);

namespace Phasis\Object;

use Phasis\Value\JsValue;

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
    public array $order = [];

    /**
     * Mutation generation counter. Bumped on every set / delete that
     * touches structure OR data-slot values. The VM's LOAD_MEMBER
     * inline cache stores this counter alongside its cached value;
     * on read the cache trusts itself iff the version still matches.
     *
     * Note: overwrites of an existing data slot also bump the
     * version. This is a write-time cost (one int increment) that
     * buys read-time freedom from re-verifying the slot value on
     * every IC hit. Class prototypes — the common IC target — are
     * populated once at class creation and never overwritten.
     */
    public int $version = 0;

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
        $this->version++;
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
            self::touchIntegerIndex($key);
            return;
        }
        unset($this->dataSlots[$key]);
        $this->descriptors[$key] = $desc;
        $this->order[$key] = true;
        self::touchIntegerIndex($key);
    }

    public function setDataSlot(string $key, JsValue $value): void
    {
        $this->version++;
        $isNew = !isset($this->order[$key]);
        $this->dataSlots[$key] = $value;
        $this->order[$key] = true;
        if ($isNew) {
            self::touchIntegerIndex($key);
        }
    }

    public function has(string $key): bool
    {
        return isset($this->order[$key]);
    }

    public function delete(string $key): bool
    {
        $existed = isset($this->order[$key]);
        unset($this->dataSlots[$key]);
        unset($this->descriptors[$key]);
        unset($this->order[$key]);
        if ($existed) {
            $this->version++;
            self::touchIntegerIndex($key);
        }
        return true;
    }

    /**
     * Bump the static version counter that JsArray's dense-write
     * fast path uses to invalidate its "prototype chain has no
     * integer-indexed property" cache. Only triggers for numeric
     * key strings shaped like array indices (max 4_294_967_294
     * per spec). Non-index keys (named props, symbols-as-strings)
     * are skipped — they don't affect dense-write semantics.
     */
    private static function touchIntegerIndex(string $key): void
    {
        // Inline the array-index check: digits only, no leading zero
        // (except the single character "0"), value < 2^32 - 1.
        $len = strlen($key);
        if ($len === 0) {
            return;
        }
        if ($len > 1 && $key[0] === '0') {
            return;
        }
        if ($len > 10) {
            return;
        }
        for ($i = 0; $i < $len; $i++) {
            $c = $key[$i];
            if ($c < '0' || $c > '9') {
                return;
            }
        }
        if ($len === 10 && strcmp($key, '4294967294') > 0) {
            return;
        }
        \Phasis\Value\JsArray::$protoIntegerPropsVersion++;
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
