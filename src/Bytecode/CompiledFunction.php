<?php

declare(strict_types=1);

namespace PhpJs\Bytecode;

use PhpJs\Value\JsValue;

/**
 * Result of compiling a JsFunction body to bytecode. Holds the flat
 * instruction stream, the constants pool, the names table for
 * free-variable lookups, and the layout metadata the VM needs to
 * build a Frame on each call.
 *
 * Compilation is lazy and best-effort: a JsFunction whose body uses
 * features the compiler doesn't yet handle (eval, with, generators,
 * etc.) is marked with a non-null bailoutReason and the tree-walker
 * keeps owning it. Already-compiled bodies stay compiled for the
 * lifetime of the JsFunction.
 */
final class CompiledFunction
{
    /**
     * @param list<int>     $code   Flat instruction stream (opcodes interleaved with operands).
     * @param list<JsValue> $consts Constants pool — Literal values pre-converted to JsValue.
     * @param list<string>  $names  Identifier names referenced by LOAD_NAME / STORE_NAME / LOAD_MEMBER.
     * @param list<string>  $localNames   Slot-index → identifier name (for diagnostics + arguments-object aliasing).
     * @param list<int>     $paramSlots   For each parameter, the local slot it is bound into.
     */
    public function __construct(
        public readonly array $code,
        public readonly array $consts,
        public readonly array $names,
        public readonly array $localNames,
        public readonly array $paramSlots,
        public readonly int $slotCount,
    ) {
    }
}
