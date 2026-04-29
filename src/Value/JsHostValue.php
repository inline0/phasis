<?php

declare(strict_types=1);

namespace PhpJs\Value;

/**
 * Opaque host value: wraps an arbitrary PHP object so it can ride
 * through a JsObject's property map (as an internal slot) without
 * the engine ever exposing it to user code. Used for stashing the
 * regex AST on a RegExp object so exec() can fetch it for the
 * in-engine matcher path.
 *
 * No JS-level operations are valid on a JsHostValue. Reads through
 * normal property access never return one because we always store
 * these on internal-slot keys ([[...]]) which are not enumerable.
 */
class JsHostValue implements JsValue
{
    public function __construct(public mixed $value)
    {
    }

    public function typeof(): string
    {
        return 'object';
    }

    public function toBoolean(): bool
    {
        return true;
    }

    public function toNumber(): float
    {
        return 0.0;
    }

    public function toInt32(): int
    {
        return 0;
    }

    public function toUint32(): int
    {
        return 0;
    }

    public function toJsString(): string
    {
        return '[host value]';
    }

    public function display(): string
    {
        return '[host value]';
    }
}
