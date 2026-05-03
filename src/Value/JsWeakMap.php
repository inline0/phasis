<?php

declare(strict_types=1);

namespace PhpJs\Value;

use PhpJs\BuiltIn\SymbolConstructor;
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
    // PHP has a native WeakMap that hashes object identity, so object
    // keys land in O(1). The previous list-of-pairs scan was O(N) per
    // op which made tests like regress-1507322-deep-weakmap that build
    // a 100k-entry chain quadratic in time and tip the per-test wall
    // budget.
    private \WeakMap $objectKeys;
    /** @var array<int, JsValue> Indexed by JsSymbol::id() for symbol keys. */
    private array $symbolKeys = [];

    public function __construct(?JsObject $prototype = null)
    {
        parent::__construct($prototype);
        $this->objectKeys = new \WeakMap();
    }

    public function weakMapGet(JsValue $key): JsValue
    {
        if ($key instanceof JsObject) {
            return $this->objectKeys[$key] ?? JsUndefined::instance();
        }
        if ($key instanceof JsSymbol) {
            return $this->symbolKeys[spl_object_id($key)] ?? JsUndefined::instance();
        }
        return JsUndefined::instance();
    }

    public function weakMapSet(JsValue $key, JsValue $value): void
    {
        // Per spec: CanBeHeldWeakly: objects, or non-registered symbols.
        if ($key instanceof JsObject) {
            $this->objectKeys[$key] = $value;
            return;
        }
        if ($key instanceof JsSymbol && !SymbolConstructor::isRegisteredSymbol($key)) {
            $this->symbolKeys[spl_object_id($key)] = $value;
            return;
        }
        throw new \PhpJs\Exceptions\TypeError('Invalid value used as weak map key');
    }

    public function weakMapHas(JsValue $key): bool
    {
        if ($key instanceof JsObject) {
            return isset($this->objectKeys[$key]);
        }
        if ($key instanceof JsSymbol) {
            return isset($this->symbolKeys[spl_object_id($key)]);
        }
        return false;
    }

    public function weakMapDelete(JsValue $key): bool
    {
        if ($key instanceof JsObject) {
            if (!isset($this->objectKeys[$key])) {
                return false;
            }
            unset($this->objectKeys[$key]);
            return true;
        }
        if ($key instanceof JsSymbol) {
            $id = spl_object_id($key);
            if (!isset($this->symbolKeys[$id])) {
                return false;
            }
            unset($this->symbolKeys[$id]);
            return true;
        }
        return false;
    }

    public function toJsString(): string
    {
        return '[object WeakMap]';
    }

    public function display(): string
    {
        return 'WeakMap { <items unknown> }';
    }
}
