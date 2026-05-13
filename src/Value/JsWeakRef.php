<?php

declare(strict_types=1);

namespace PhpJs\Value;

/**
 * JavaScript WeakRef object.
 *
 * Per spec 26.1: WeakRef wraps a target object. deref() returns the target
 * if it has not been garbage collected.
 *
 * In PHP we use WeakReference for JsObject targets. For non-registered
 * JsSymbol targets we store a strong reference since PHP WeakReference
 * does not support non-objects.
 */
class JsWeakRef extends JsObject
{
    /** @var \WeakReference<JsObject>|null */
    private ?\WeakReference $weakRef = null;
    private ?JsSymbol $symbolRef = null;

    public function __construct(?JsObject $prototype = null)
    {
        parent::__construct($prototype);
    }

    /**
     * Store the target. Objects use PHP WeakReference; symbols are stored strongly.
     */
    public function setTarget(JsValue $target): void
    {
        if ($target instanceof JsObject) {
            $this->weakRef = \WeakReference::create($target);
            $this->symbolRef = null;
        } elseif ($target instanceof JsSymbol) {
            $this->symbolRef = $target;
            $this->weakRef = null;
        }
    }

    /**
     * Per spec 26.1.3.2: WeakRef.prototype.deref().
     * Returns the target object/symbol or undefined if it has been GC'd.
     */
    public function deref(): JsValue
    {
        if ($this->weakRef !== null) {
            $target = $this->weakRef->get();
            return $target instanceof JsObject ? $target : JsUndefined::instance();
        }
        if ($this->symbolRef !== null) {
            return $this->symbolRef;
        }
        return JsUndefined::instance();
    }

    public function toJsString(): string
    {
        return '[object WeakRef]';
    }

    public function display(): string
    {
        return 'WeakRef {}';
    }
}
