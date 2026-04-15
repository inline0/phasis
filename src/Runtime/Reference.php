<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

use PhpJs\Exceptions\ReferenceError;
use PhpJs\Exceptions\TypeError;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsSymbol;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

class Reference
{
    private ?JsSymbol $symbolKey;

    /**
     * When non-null, the property key is not yet stringified.
     * ToPropertyKey is deferred until getValue()/setValue() so
     * that the RHS of an assignment is evaluated first (per spec
     * evaluation order).
     */
    private ?JsValue $rawKey;

    /** Cached result of resolvedName() so ToString is called at most once. */
    private ?string $resolvedNameCache = null;

    public function __construct(
        public readonly JsValue|Environment $base,
        public readonly string $name,
        public readonly bool $strict = false,
        ?JsSymbol $symbolKey = null,
        ?JsValue $rawKey = null,
    ) {
        $this->symbolKey = $symbolKey;
        $this->rawKey = $rawKey;
    }

    /**
     * Resolve the deferred property key to a string name.
     * Performs ToPropertyKey on the stored raw key. The result is
     * cached so that ToString is called at most once per reference.
     */
    private function resolvedName(): string
    {
        if ($this->resolvedNameCache !== null) {
            return $this->resolvedNameCache;
        }
        if ($this->rawKey !== null) {
            $this->resolvedNameCache = TypeConversion::toString($this->rawKey);
            return $this->resolvedNameCache;
        }
        return $this->name;
    }

    /** Resolve the reference to its current value. */
    public function getValue(): JsValue
    {
        if ($this->base instanceof Environment) {
            return $this->base->get($this->name);
        }

        // Per spec 6.2.4.4 GetValue: if base is null/undefined, throw TypeError.
        if ($this->base instanceof JsNull || $this->base instanceof JsUndefined) {
            $typeName = $this->base instanceof JsNull ? 'null' : 'undefined';
            throw new TypeError(
                "Cannot read properties of {$typeName} (reading '{$this->resolvedName()}')"
            );
        }

        if ($this->base instanceof JsObject) {
            if ($this->symbolKey !== null) {
                return $this->base->getBySymbol($this->symbolKey);
            }
            return $this->base->get($this->resolvedName());
        }

        return JsUndefined::instance();
    }

    /** Assign a value through this reference. */
    public function setValue(JsValue $value): void
    {
        if ($this->base instanceof Environment) {
            $this->base->set($this->name, $value, $this->strict);
            return;
        }

        // Per spec 6.2.4.5 PutValue: if base is null/undefined, ToObject throws TypeError.
        if ($this->base instanceof JsNull || $this->base instanceof JsUndefined) {
            $typeName = $this->base instanceof JsNull ? 'null' : 'undefined';
            throw new TypeError(
                "Cannot set properties of {$typeName} (setting '{$this->resolvedName()}')"
            );
        }

        if ($this->base instanceof JsObject) {
            if ($this->symbolKey !== null) {
                $this->base->setBySymbol($this->symbolKey, $value);
                return;
            }
            $this->base->set($this->resolvedName(), $value, $this->strict);
            return;
        }

        if ($this->strict) {
            throw new ReferenceError("Cannot assign to property '{$this->resolvedName()}' of a non-object");
        }
    }
}
