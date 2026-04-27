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
     * @param list<int>     $code        Flat instruction stream.
     * @param list<JsValue> $consts      Pre-converted JsValue constants.
     * @param list<string>  $names       Identifier name table.
     * @param list<string>  $localNames  Slot index → name (diagnostics).
     * @param list<int>     $paramSlots  Parameter index → local slot.
     * @param list<\PhpJs\Ast\Node> $nestedFns Templates for nested fns.
     * @param bool $needsThis       True when LOAD_THIS appears anywhere
     *        in the bytecode. Lets executeFunction skip the
     *        defineVar('this', ...) that the tree-walker performs.
     * @param bool $needsArgsBinding True when LOAD_NAME / STORE_NAME
     *        targets a parameter name (the body referenced the param
     *        via its identifier in a way the slot-based path doesn't
     *        cover). When false, executeFunction can skip
     *        bindParameters because LOAD_LOCAL / paramSlot handles
     *        every reference.
     */
    public function __construct(
        public readonly array $code,
        public readonly array $consts,
        public readonly array $names,
        public readonly array $localNames,
        public readonly array $paramSlots,
        public readonly int $slotCount,
        public readonly array $nestedFns = [],
        public readonly bool $needsThis = true,
        public readonly bool $needsArgsBinding = true,
    ) {
    }
}
