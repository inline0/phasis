<?php

declare(strict_types=1);

namespace Phasis\Value;

use Phasis\BuiltIn\SymbolConstructor;

/**
 * JavaScript WeakMap object.
 *
 * Backed by the same storage as JsMap, but is a distinct type so that
 * Map.prototype methods correctly reject it via instanceof checks.
 * PHP does not have weak references for arbitrary values, so entries
 * are stored strongly (no garbage collection of unreferenced keys).
 *
 * Keys must be JsObject or non-registered JsSymbol per spec; both are
 * PHP objects so spl_object_id provides O(1) lookup keyed by identity,
 * which matches the spec's reference equality requirement for WeakMap.
 */
class JsWeakMap extends JsObject
{
    /** @var array<int, JsValue> Map of spl_object_id(key) => value. */
    private array $values = [];

    /** @var array<int, JsValue> Map of spl_object_id(key) => key (keeps key alive). */
    private array $keys = [];

    public function __construct(?JsObject $prototype = null)
    {
        parent::__construct($prototype);
    }

    public function weakMapGet(JsValue $key): JsValue
    {
        if (!$key instanceof JsObject && !$key instanceof JsSymbol) {
            return JsUndefined::instance();
        }
        $id = spl_object_id($key);
        return $this->values[$id] ?? JsUndefined::instance();
    }

    public function weakMapSet(JsValue $key, JsValue $value): void
    {
        // Per spec: CanBeHeldWeakly: objects, or non-registered symbols.
        if (
            !$key instanceof JsObject
            && !($key instanceof JsSymbol && !SymbolConstructor::isRegisteredSymbol($key))
        ) {
            throw new \Phasis\Exceptions\TypeError('Invalid value used as weak map key');
        }
        $id = spl_object_id($key);
        $this->keys[$id] = $key;
        $this->values[$id] = $value;
    }

    public function weakMapHas(JsValue $key): bool
    {
        if (!$key instanceof JsObject && !$key instanceof JsSymbol) {
            return false;
        }
        return isset($this->values[spl_object_id($key)]) || array_key_exists(spl_object_id($key), $this->values);
    }

    public function weakMapDelete(JsValue $key): bool
    {
        if (!$key instanceof JsObject && !$key instanceof JsSymbol) {
            return false;
        }
        $id = spl_object_id($key);
        if (!array_key_exists($id, $this->values)) {
            return false;
        }
        unset($this->values[$id], $this->keys[$id]);
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
}
