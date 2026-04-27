<?php

declare(strict_types=1);

namespace PhpJs\Bytecode;

use PhpJs\Runtime\Environment;
use PhpJs\Value\JsValue;

/**
 * Per-call execution state for the bytecode VM. The locals array is
 * pre-sized from CompiledFunction::$slotCount so the VM dispatcher
 * never grows the array. Free-variable reads and writes still go
 * through the Environment chain via $env (using the existing
 * scope-depth cache); compilation is "registers + closure-by-name".
 */
final class Frame
{
    /** @var list<JsValue> */
    public array $locals;

    /** @var list<JsValue> */
    public array $stack = [];

    public int $sp = 0;

    public function __construct(
        public readonly Environment $env,
        public readonly JsValue $thisValue,
        int $slotCount,
        JsValue $undefined,
    ) {
        // Pre-fill locals with undefined so unassigned slots don't trip
        // PHP's undefined-index notice on first read.
        $this->locals = array_fill(0, max(1, $slotCount), $undefined);
    }
}
