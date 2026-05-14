<?php

declare(strict_types=1);

namespace Phasis\Value;

class JsBoolean implements JsValue
{
    private static ?JsObject $booleanPrototype = null;
    private static ?self $trueInstance = null;
    private static ?self $falseInstance = null;

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

    /**
     * Get the singleton JsBoolean for a given native bool. JsBoolean is
     * fully readonly, so reusing one instance across all true (or false)
     * results is safe and saves an allocation per comparison or
     * truthiness coercion.
     */
    public static function of(bool $value): self
    {
        if ($value) {
            return self::$trueInstance ??= new self(true);
        }
        return self::$falseInstance ??= new self(false);
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
