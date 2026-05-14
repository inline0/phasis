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
 * Phase 1 covers `try / catch` only. `try / finally` and `try / catch /
 * finally` keep using the tree-walker (compiler bails) because the
 * completion-record dance for re-triggering pending return/break/continue
 * after finally still needs design work.
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
