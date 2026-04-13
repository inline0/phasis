<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

use PhpJs\Exceptions\ReferenceError;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsSymbol;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

class Reference
{
    private ?JsSymbol $symbolKey;

    public function __construct(
        public readonly JsValue|Environment $base,
        public readonly string $name,
        public readonly bool $strict = false,
        ?JsSymbol $symbolKey = null,
    ) {
        $this->symbolKey = $symbolKey;
    }

    /** Resolve the reference to its current value. */
    public function getValue(): JsValue
    {
        if ($this->base instanceof Environment) {
            return $this->base->get($this->name);
        }

        if ($this->base instanceof JsObject) {
            if ($this->symbolKey !== null) {
                return $this->base->getBySymbol($this->symbolKey);
            }
            return $this->base->get($this->name);
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

        if ($this->base instanceof JsObject) {
            if ($this->symbolKey !== null) {
                $this->base->setBySymbol($this->symbolKey, $value);
                return;
            }
            $this->base->set($this->name, $value);
            return;
        }

        if ($this->strict) {
            throw new ReferenceError("Cannot assign to property '{$this->name}' of a non-object");
        }
    }
}
