<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

use PhpJs\Exceptions\ReferenceError;
use PhpJs\Exceptions\TypeError;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsProxy;
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

    /**
     * For super references: the actual `this` value used as the receiver
     * when invoking getters/setters or performing [[Set]] on the super base.
     * Null for normal (non-super) references.
     */
    public readonly ?JsObject $thisValue;

    /** For private field references (#name). Null for normal references. */
    private ?string $privateFieldName;

    public function __construct(
        public readonly JsValue|Environment $base,
        public readonly string $name,
        public readonly bool $strict = false,
        ?JsSymbol $symbolKey = null,
        ?JsValue $rawKey = null,
        ?JsObject $thisValue = null,
        ?string $privateFieldName = null,
    ) {
        $this->symbolKey = $symbolKey;
        $this->rawKey = $rawKey;
        $this->thisValue = $thisValue;
        $this->privateFieldName = $privateFieldName;
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
        // Private field access. Per spec, private field operations bypass
        // Proxy traps and operate on the underlying target object.
        if ($this->privateFieldName !== null) {
            $target = $this->base;
            if ($target instanceof JsProxy) {
                $target = $target->getTarget();
            }
            if ($target instanceof JsObject) {
                return $target->getPrivateField($this->privateFieldName);
            }
            throw new TypeError(
                'Cannot read private member ' . $this->privateFieldName . ' from a non-object',
            );
        }

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
            // For super references, use the actual `this` as the receiver so that
            // getters are invoked with the correct `this` (spec §6.2.4.4 step 5b).
            if ($this->symbolKey !== null) {
                if ($this->thisValue !== null) {
                    return $this->base->getBySymbolWithReceiver($this->symbolKey, $this->thisValue);
                }
                return $this->base->getBySymbol($this->symbolKey);
            }
            if ($this->thisValue !== null) {
                return $this->base->internalGet($this->resolvedName(), $this->thisValue);
            }
            return $this->base->get($this->resolvedName());
        }

        return JsUndefined::instance();
    }

    /** Assign a value through this reference. */
    public function setValue(JsValue $value): void
    {
        // Private field assignment. Per spec, private field operations bypass
        // Proxy traps and operate on the underlying target object.
        if ($this->privateFieldName !== null) {
            $target = $this->base;
            if ($target instanceof JsProxy) {
                $target = $target->getTarget();
            }
            if ($target instanceof JsObject) {
                $target->setPrivateFieldValue($this->privateFieldName, $value);
                return;
            }
            throw new TypeError(
                'Cannot write private member ' . $this->privateFieldName . ' to a non-object',
            );
        }

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
            // For super references, use the actual `this` as the receiver so that
            // [[Set]] stores the property on `this`, not on the super base
            // (spec §6.2.4.5 step 6b: base.[[Set]](name, value, GetThisValue(V))).
            if ($this->symbolKey !== null) {
                $receiver = $this->thisValue ?? $this->base;
                $success = $this->base->internalSetBySymbol($this->symbolKey, $value, $receiver);
                if (!$success && $this->strict) {
                    throw new TypeError("Cannot assign to read only property '{$this->resolvedName()}'");
                }
                return;
            }
            if ($this->thisValue !== null) {
                $success = $this->base->internalSet($this->resolvedName(), $value, $this->thisValue);
                if (!$success && $this->strict) {
                    throw new TypeError(
                        "Cannot assign to read only property '{$this->resolvedName()}' of object '#<Object>'"
                    );
                }
                return;
            }
            $this->base->set($this->resolvedName(), $value, $this->strict);
            return;
        }

        if ($this->strict) {
            throw new TypeError("Cannot assign to read only property '{$this->resolvedName()}' of a primitive");
        }
    }
}
