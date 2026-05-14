<?php

declare(strict_types=1);

namespace Phasis\Value;

/**
 * Wrapper used inside the Fiber to mark a value as the final return
 * value of a generator function (as opposed to a yielded value).
 *
 * This is not an exception. It is just a marker object passed to
 * Fiber::suspend() or returned from the Fiber callable so that
 * JsGenerator::next() can distinguish a return from a yield.
 */
class GeneratorReturn
{
    public function __construct(
        public readonly JsValue $value,
    ) {
    }
}
