<?php

declare(strict_types=1);

namespace PhpJs\Value;

class JsSymbol implements JsValue
{
    private static int $nextId = 0;
    private readonly int $id;

    public function __construct(
        public readonly ?string $description = null,
    ) {
        $this->id = self::$nextId++;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function toString(): string
    {
        return 'Symbol(' . ($this->description ?? '') . ')';
    }

    public function typeof(): string
    {
        return 'symbol';
    }

    public function toBoolean(): bool
    {
        return true;
    }

    public function toNumber(): float
    {
        throw new \PhpJs\Exceptions\TypeError('Cannot convert a Symbol value to a number');
    }

    public function toInt32(): int
    {
        throw new \PhpJs\Exceptions\TypeError('Cannot convert a Symbol value to a number');
    }

    public function toUint32(): int
    {
        throw new \PhpJs\Exceptions\TypeError('Cannot convert a Symbol value to a number');
    }

    public function toJsString(): string
    {
        throw new \PhpJs\Exceptions\TypeError('Cannot convert a Symbol value to a string');
    }

    public function display(): string
    {
        if ($this->description !== null) {
            return "Symbol({$this->description})";
        }

        return 'Symbol()';
    }
}
