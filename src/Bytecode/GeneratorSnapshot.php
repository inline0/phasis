<?php

declare(strict_types=1);

namespace Phasis\Bytecode;

use Phasis\Runtime\Environment;
use Phasis\Value\JsValue;

/**
 * Frozen frame state for a suspended generator that runs on the
 * bytecode VM's frame-snapshot path (no PHP Fiber).
 *
 * Captured by `Op::YIELD` at suspend time and consumed by
 * `Interpreter::resumeVmGenerator()` on the next `gen.next()` /
 * `gen.return()` / `gen.throw()` call. Fields are public + mutable
 * so the VM can restore them inline.
 *
 * Generators reach this path only when the compiler determines the
 * body is "simple": no `yield*`, no try/catch/finally that contains
 * a yield, not an async generator. Anything else stays on the
 * Fiber-based path inside JsGenerator.
 *
 * Note: there is no `savedFrames` field. `yield` can only appear
 * syntactically inside the generator's own body, never inside a
 * nested non-generator function call. So at any suspension point
 * the inline-call savedFrames stack (relative to this generator's
 * VM::execute invocation) is empty.
 */
final class GeneratorSnapshot
{
    public CompiledFunction $cf;

    /** Resume here on the next gen.next() / .return() / .throw(). */
    public int $pc;

    /** @var list<JsValue> */
    public array $stack;

    public int $sp;

    /** @var list<JsValue> */
    public array $locals;

    public Environment $env;

    public JsValue $thisValue;

    public bool $strict;

    /**
     * True once the generator has returned (RET opcode at the outer
     * frame) or been forcibly closed via gen.return() / gen.throw().
     */
    public bool $done = false;

    /**
     * @param list<JsValue> $stack
     * @param list<JsValue> $locals
     */
    public function __construct(
        CompiledFunction $cf,
        int $pc,
        array $stack,
        int $sp,
        array $locals,
        Environment $env,
        JsValue $thisValue,
        bool $strict,
    ) {
        $this->cf = $cf;
        $this->pc = $pc;
        $this->stack = $stack;
        $this->sp = $sp;
        $this->locals = $locals;
        $this->env = $env;
        $this->thisValue = $thisValue;
        $this->strict = $strict;
    }
}
