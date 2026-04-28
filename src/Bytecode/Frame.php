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
 *
 * Fields are mutable so the Interpreter's frame pool can reset and
 * reuse Frame instances across calls. Recursion-heavy workloads
 * (fn-recurse, deep call chains) burn through one allocation per
 * call without it; pooling cuts that to one Frame per max-reached
 * depth.
 */
final class Frame
{
    /** @var list<JsValue> */
    public array $locals;

    /** @var list<JsValue> */
    public array $stack = [];

    public int $sp = 0;

    public Environment $env;
    public JsValue $thisValue;

    public function __construct(
        Environment $env,
        JsValue $thisValue,
        int $slotCount,
        JsValue $undefined,
    ) {
        $this->env = $env;
        $this->thisValue = $thisValue;
        // Pre-fill locals with undefined so unassigned slots don't trip
        // PHP's undefined-index notice on first read.
        $this->locals = array_fill(0, max(1, $slotCount), $undefined);
    }

    /**
     * Reuse this Frame for a new call. Resets state that varies per
     * call (env, thisValue, locals, stack pointer) and grows the
     * locals array if the new slot count is larger than what's
     * currently allocated. Stack is left in place — sp = 0 makes any
     * leftover entries unreachable.
     *
     * Param slots [0..paramCount-1] are NOT cleared here because the
     * caller's param-binding loop overwrites them immediately. Skipping
     * the redundant write is the per-call savings on workloads like
     * fib(n) where slotCount == paramCount and the reset would
     * otherwise dominate the per-call cost.
     */
    public function reset(
        Environment $env,
        JsValue $thisValue,
        int $slotCount,
        int $paramCount,
        JsValue $undefined,
    ): void {
        $this->env = $env;
        $this->thisValue = $thisValue;
        $this->sp = 0;
        $needed = max(1, $slotCount);
        $have = count($this->locals);
        if ($have < $needed) {
            for ($i = $have; $i < $needed; $i++) {
                $this->locals[$i] = $undefined;
            }
        }
        // Reset only the non-param slots: declared-but-uninitialized
        // let / var / function bindings must observe undefined on
        // entry rather than the previous call's stale value. Param
        // slots [0..paramCount-1] are about to be overwritten so a
        // reset here would be wasted work.
        for ($i = $paramCount; $i < $needed; $i++) {
            $this->locals[$i] = $undefined;
        }
    }
}
