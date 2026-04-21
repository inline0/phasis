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
 */
class TailCallThunk
{
    public function __construct(
        public readonly JsFunction $function,
        public readonly JsValue $thisValue,
        /** @var list<JsValue> */
        public readonly array $args,
    ) {
    }
}
