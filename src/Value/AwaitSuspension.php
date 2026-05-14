<?php

declare(strict_types=1);

namespace Phasis\Value;

/**
 * Internal marker used by Fiber-based async function execution.
 *
 * When `await x` is evaluated inside an async function fiber, the
 * interpreter calls Fiber::suspend with this marker wrapping the
 * awaited value. The async function controller resolves the value
 * through PromiseResolve semantics, then resumes the fiber with the
 * settled result (or throws into the fiber on rejection).
 */
class AwaitSuspension
{
    public function __construct(
        public readonly JsValue $value,
    ) {
    }
}
