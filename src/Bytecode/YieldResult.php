<?php

declare(strict_types=1);

namespace Phasis\Bytecode;

use Phasis\Value\JsValue;

/**
 * Returned from `VM::execute` (or its resume entry point) when the
 * bytecode hit `Op::YIELD`. The caller (JsGenerator's snapshot
 * driver) packages this into the iterator-result object
 * `{value, done: false}` and stores the snapshot for the next
 * resume.
 */
final class YieldResult
{
    public function __construct(
        public readonly JsValue $value,
        public readonly GeneratorSnapshot $snapshot,
    ) {
    }
}
