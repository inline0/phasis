<?php

declare(strict_types=1);

namespace PhpJs\Value;

class JsBoolean implements JsValue
{
    private static ?JsObject $booleanPrototype = null;

    public static function setBooleanPrototype(JsObject $proto): void
    {
        self::$booleanPrototype = $proto;
    }

    public static function resetBooleanPrototype(): void
    {
        self::$booleanPrototype = null;
    }

    public static function getBooleanPrototype(): ?JsObject
    {
        return self::$booleanPrototype;
    }

    public function __construct(
        public readonly bool $value,
    ) {
    }

    public function typeof(): string
    {
        return 'boolean';
    }

    public function toBoolean(): bool
    {
        return $this->value;
    }

    public function toNumber(): float
    {
        return $this->value ? 1.0 : 0.0;
    }

    public function toInt32(): int
    {
        return $this->value ? 1 : 0;
    }

    public function toUint32(): int
    {
        return $this->value ? 1 : 0;
    }

    public function toJsString(): string
    {
        return $this->value ? 'true' : 'false';
    }

    public function display(): string
    {
        return $this->value ? 'true' : 'false';
    }
}
