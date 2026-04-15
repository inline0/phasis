<?php

declare(strict_types=1);

namespace PhpJs\Object;

use PhpJs\Value\JsFunction;
use PhpJs\Value\JsValue;

class PropertyDescriptor
{
    /**
     * When true, this descriptor was explicitly created as an accessor
     * (via defineProperty with get/set keys), even if both get and set are null.
     */
    private bool $isAccessor = false;

    public function __construct(
        public ?JsValue $value = null,
        public ?bool $writable = null,
        public ?bool $enumerable = null,
        public ?bool $configurable = null,
        public ?JsFunction $get = null,
        public ?JsFunction $set = null,
    ) {
    }

    /** Whether this is a data descriptor (has value or writable). */
    public function isDataDescriptor(): bool
    {
        if ($this->isAccessor) {
            return false;
        }
        return $this->value !== null || $this->writable !== null;
    }

    /** Whether this is an accessor descriptor (has get or set). */
    public function isAccessorDescriptor(): bool
    {
        return $this->isAccessor || $this->get !== null || $this->set !== null;
    }

    public static function data(
        JsValue $value,
        bool $writable = true,
        bool $enumerable = true,
        bool $configurable = true,
    ): self {
        return new self(
            value: $value,
            writable: $writable,
            enumerable: $enumerable,
            configurable: $configurable,
        );
    }

    public static function accessor(
        ?JsFunction $get = null,
        ?JsFunction $set = null,
        bool $enumerable = true,
        bool $configurable = true,
    ): self {
        $desc = new self(
            enumerable: $enumerable,
            configurable: $configurable,
            get: $get,
            set: $set,
        );
        $desc->isAccessor = true;
        return $desc;
    }
}
