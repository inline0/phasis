<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

use PhpJs\Value\JsFunction;
use PhpJs\Value\JsValue;

/**
 * Represents a pending tail call that should be trampolined
 * instead of adding another stack frame.
 *
 * Per ES2015 spec section 14.13 (Tail Position Calls),
 * proper tail calls apply in strict mode when a return statement
 * directly calls a function.
 *
 * Implements JsValue so it can be stored in a Completion record
 * and propagated through the return chain.
 */
class TailCallThunk implements JsValue
{
    public function __construct(
        public readonly JsFunction $function,
        public readonly JsValue $thisValue,
        /** @var list<JsValue> */
        public readonly array $args,
    ) {
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
        return NAN;
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
        return '[TailCallThunk]';
    }

    public function display(): string
    {
        return '[TailCallThunk]';
    }
}
