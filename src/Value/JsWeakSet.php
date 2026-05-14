<?php

declare(strict_types=1);

namespace Phasis\Value;

use Phasis\BuiltIn\SymbolConstructor;

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
    /** @var \WeakMap<JsObject, true> */
    private \WeakMap $objectMembers;
    /** @var array<int, true> Indexed by JsSymbol object id. */
    private array $symbolMembers = [];

    public function __construct(?JsObject $prototype = null)
    {
        parent::__construct($prototype);
        $this->objectMembers = new \WeakMap();
    }

    public function weakSetAdd(JsValue $value): void
    {
        if ($value instanceof JsObject) {
            $this->objectMembers[$value] = true;
            return;
        }
        if ($value instanceof JsSymbol && !SymbolConstructor::isRegisteredSymbol($value)) {
            $this->symbolMembers[spl_object_id($value)] = true;
            return;
        }
        throw new \Phasis\Exceptions\TypeError('Invalid value used in weak set');
    }

    public function weakSetHas(JsValue $value): bool
    {
        if ($value instanceof JsObject) {
            return isset($this->objectMembers[$value]);
        }
        if ($value instanceof JsSymbol) {
            return isset($this->symbolMembers[spl_object_id($value)]);
        }
        return false;
    }

    public function weakSetDelete(JsValue $value): bool
    {
        if ($value instanceof JsObject) {
            if (!isset($this->objectMembers[$value])) {
                return false;
            }
            unset($this->objectMembers[$value]);
            return true;
        }
        if ($value instanceof JsSymbol) {
            $id = spl_object_id($value);
            if (!isset($this->symbolMembers[$id])) {
                return false;
            }
            unset($this->symbolMembers[$id]);
            return true;
        }
        return false;
    }

    public function toJsString(): string
    {
        return '[object WeakSet]';
    }

    public function display(): string
    {
        return 'WeakSet { <items unknown> }';
    }
}
