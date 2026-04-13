<?php

declare(strict_types=1);

namespace PhpJs\Value;

class JsString implements JsValue
{
    public function __construct(
        public readonly string $value,
    ) {
    }

    public function typeof(): string
    {
        return 'string';
    }

    public function toBoolean(): bool
    {
        return $this->value !== '';
    }

    public function toNumber(): float
    {
        return \PhpJs\Spec\TypeConversion::stringToNumber($this->value);
    }

    public function toInt32(): int
    {
        return (new JsNumber($this->toNumber()))->toInt32();
    }

    public function toUint32(): int
    {
        return (new JsNumber($this->toNumber()))->toUint32();
    }

    public function toJsString(): string
    {
        return $this->value;
    }

    public function display(): string
    {
        return $this->value;
    }

    /** JavaScript String.length (UTF-16 code unit count). */
    public function length(): int
    {
        return mb_strlen($this->value, 'UTF-16LE') ?: strlen($this->value);
    }
}
