<?php

declare(strict_types=1);

namespace Phasis\Bytecode;

/**
 * One row of the per-function exception-handler table.
 *
 * The compiler emits a HandlerEntry for every `try { ... } catch (e) { ... }`
 * pair. When a JS throw fires inside the protected PC range, the VM looks up
 * the most recently entered (innermost) handler whose [tryStart, tryEnd)
 * window covers the current PC, stores the thrown value into the catch
 * parameter's local slot, resets the operand stack to the entry depth,
 * and jumps to catchPc.
 *
 * Finalizer handlers save the thrown value in an internal local slot.
 * The compiler routes normal, throw and return completions through the
 * finalizer outside these protected ranges before resuming the completion.
 */
final class HandlerEntry
{
    public function __construct(
        /** PC of the first instruction inside the try block. */
        public readonly int $tryStart,
        /** PC just past the last instruction inside the try block. */
        public readonly int $tryEnd,
        /** PC where the catch block begins. */
        public readonly int $catchPc,
        /** Local slot that the thrown JsValue is written to before catchPc runs. */
        public readonly int $exceptionSlot,
        /** Operand-stack depth at try entry; restored before jumping to catchPc. */
        public readonly int $stackBase,
    ) {
    }
}
