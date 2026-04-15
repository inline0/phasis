<?php

declare(strict_types=1);

namespace PhpJs\Value;

use PhpJs\Spec\AbstractOperations;

/**
 * JavaScript WeakMap object.
 *
 * Backed by the same storage as JsMap, but is a distinct type so that
 * Map.prototype methods correctly reject it via instanceof checks.
 * PHP does not have weak references for arbitrary values, so entries
 * are stored strongly (no garbage collection of unreferenced keys).
 */
class JsWeakMap extends JsObject
{
    /** @var list<array{0: JsValue, 1: JsValue}> */
    private array $entries = [];

    public function __construct(?JsObject $prototype = null)
    {
        parent::__construct($prototype);
    }

    public function weakMapGet(JsValue $key): JsValue
    {
        $index = $this->findIndex($key);
        if ($index === -1) {
            return JsUndefined::instance();
        }
        return $this->entries[$index][1];
    }

    public function weakMapSet(JsValue $key, JsValue $value): void
    {
        if (!$key instanceof JsObject) {
            throw new \PhpJs\Exceptions\TypeError('Invalid value used as weak map key');
        }
        $index = $this->findIndex($key);
        if ($index !== -1) {
            $this->entries[$index][1] = $value;
        } else {
            $this->entries[] = [$key, $value];
        }
    }

    public function weakMapHas(JsValue $key): bool
    {
        return $this->findIndex($key) !== -1;
    }

    public function weakMapDelete(JsValue $key): bool
    {
        $index = $this->findIndex($key);
        if ($index === -1) {
            return false;
        }
        array_splice($this->entries, $index, 1);
        return true;
    }

    public function toJsString(): string
    {
        return '[object WeakMap]';
    }

    public function display(): string
    {
        return 'WeakMap { <items unknown> }';
    }

    /**
     * Find the index of a key using reference equality for objects.
     */
    private function findIndex(JsValue $key): int
    {
        foreach ($this->entries as $i => $entry) {
            if (AbstractOperations::sameValueZero($entry[0], $key)) {
                return $i;
            }
        }
        return -1;
    }
}
