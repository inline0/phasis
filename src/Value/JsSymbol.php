<?php

declare(strict_types=1);

namespace PhpJs\Value;

class JsSymbol implements JsValue
{
    private static int $nextId = 0;
    /** @var array<int, JsSymbol> Map from ID to symbol instance for reverse lookup. */
    private static array $instances = [];
    private readonly int $id;

    /** Symbol.prototype: set during SymbolConstructor::install. */
    private static ?JsObject $symbolPrototype = null;

    public static function setSymbolPrototype(JsObject $proto): void
    {
        self::$symbolPrototype = $proto;
    }

    public static function getSymbolPrototype(): ?JsObject
    {
        // Prefer the live Symbol.prototype from the current realm's
        // global env so getPrototypeOf on a symbol returns the calling
        // realm's prototype, not a stale one from a sibling Engine
        // (e.g. a ShadowRealm) that wrote the static last.
        $interp = \PhpJs\Engine::getCurrentInterpreter();
        if ($interp !== null) {
            $env = $interp->getGlobalEnv();
            $symVal = $env?->get('Symbol', false);
            if ($symVal instanceof JsObject) {
                $protoVal = $symVal->get('prototype');
                if ($protoVal instanceof JsObject) {
                    return $protoVal;
                }
            }
        }
        return self::$symbolPrototype;
    }

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
