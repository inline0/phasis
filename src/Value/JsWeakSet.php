<?php

declare(strict_types=1);

namespace PhpJs\Value;

use PhpJs\BuiltIn\SymbolConstructor;
use PhpJs\Spec\AbstractOperations;

/**
 * JavaScript WeakSet object.
 *
 * Backed by the same storage as JsSet, but is a distinct type so that
 * Set.prototype methods correctly reject it via instanceof checks.
 * PHP does not have weak references for arbitrary values, so entries
 * are stored strongly (no garbage collection of unreferenced keys).
 */
class JsWeakSet extends JsObject
{
    /** @var list<JsValue> */
    private array $values = [];

    public function __construct(?JsObject $prototype = null)
    {
        parent::__construct($prototype);
    }

    public function weakSetAdd(JsValue $value): void
    {
        // Per spec: objects and non-registered symbols are valid WeakSet values.
        $validValue = $value instanceof JsObject
            || ($value instanceof JsSymbol && !SymbolConstructor::isRegisteredSymbol($value));
        if (!$validValue) {
            throw new \PhpJs\Exceptions\TypeError('Invalid value used in weak set');
        }
        if (!$this->weakSetHas($value)) {
            $this->values[] = $value;
        }
    }

    public function weakSetHas(JsValue $value): bool
    {
        return $this->findIndex($value) !== -1;
    }

    public function weakSetDelete(JsValue $value): bool
    {
        $index = $this->findIndex($value);
        if ($index === -1) {
            return false;
        }
        array_splice($this->values, $index, 1);
        return true;
    }

    public function toJsString(): string
    {
        return '[object WeakSet]';
    }

    public function display(): string
    {
        return 'WeakSet { <items unknown> }';
    }

    /**
     * Find the index of a value using reference equality for objects.
     */
    private function findIndex(JsValue $value): int
    {
        foreach ($this->values as $i => $stored) {
            if (AbstractOperations::sameValueZero($stored, $value)) {
                return $i;
            }
        }
        return -1;
    }
}
