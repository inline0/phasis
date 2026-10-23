<?php

declare(strict_types=1);

namespace Phasis\Value;

use Phasis\BuiltIn\SymbolConstructor;

/**
 * JavaScript WeakMap object.
 *
 * Native weak keys preserve object identity without making a long-lived
 * JavaScript collection keep every past key alive. Values remain strongly
 * referenced while their keys are live.
 */
class JsWeakMap extends JsObject
{
    /** @var \WeakMap<JsObject|JsSymbol, JsValue> */
    private \WeakMap $values;

    public function __construct(?JsObject $prototype = null)
    {
        parent::__construct($prototype);
        $this->values = new \WeakMap();
    }

    public function weakMapGet(JsValue $key): JsValue
    {
        if (!$key instanceof JsObject && !$key instanceof JsSymbol) {
            return JsUndefined::instance();
        }
        return $this->values[$key] ?? JsUndefined::instance();
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
        $this->values[$key] = $value;
    }

    public function weakMapHas(JsValue $key): bool
    {
        if (!$key instanceof JsObject && !$key instanceof JsSymbol) {
            return false;
        }
        return isset($this->values[$key]);
    }

    public function weakMapDelete(JsValue $key): bool
    {
        if (!$key instanceof JsObject && !$key instanceof JsSymbol) {
            return false;
        }
        if (!isset($this->values[$key])) {
            return false;
        }
        unset($this->values[$key]);
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
