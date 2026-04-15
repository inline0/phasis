<?php

declare(strict_types=1);

namespace PhpJs\Value;

class JsSymbol implements JsValue
{
    private static int $nextId = 0;
    /** @var array<int, JsSymbol> Map from ID to symbol instance for reverse lookup. */
    private static array $instances = [];
    private readonly int $id;

    public function __construct(
        public readonly ?string $description = null,
    ) {
        $this->id = self::$nextId++;
        self::$instances[$this->id] = $this;
    }

    /** Look up a symbol by its internal ID. Returns null if the ID is unknown. */
    public static function fromId(int $id): ?self
    {
        return self::$instances[$id] ?? null;
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
