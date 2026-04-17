<?php

declare(strict_types=1);

namespace PhpJs\Value;

/**
 * Internal marker used during yield* delegation.
 *
 * When a yield* delegation suspends the Fiber, the inner iterator's result
 * object should be returned directly to the caller without wrapping in a
 * new {value, done} object. This class signals to JsGenerator::next() that
 * the suspended value is already a complete iterator result.
 */
class YieldDelegateResult
{
    public function __construct(
        public readonly JsObject $result,
    ) {
    }
}
