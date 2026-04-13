<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

use PhpJs\Exceptions\ReferenceError;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

class Reference
{
    public function __construct(
        public readonly JsValue|Environment $base,
        public readonly string $name,
        public readonly bool $strict = false,
    ) {
    }

    /** Resolve the reference to its current value. */
    public function getValue(): JsValue
    {
        if ($this->base instanceof Environment) {
            return $this->base->get($this->name);
        }

        if ($this->base instanceof JsObject) {
            return $this->base->get($this->name);
        }

        return JsUndefined::instance();
    }

    /** Assign a value through this reference. */
    public function setValue(JsValue $value): void
    {
        if ($this->base instanceof Environment) {
            $this->base->set($this->name, $value);
            return;
        }

        if ($this->base instanceof JsObject) {
            $this->base->set($this->name, $value);
            return;
        }

        if ($this->strict) {
            throw new ReferenceError("Cannot assign to property '{$this->name}' of a non-object");
        }
    }
}
