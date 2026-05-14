<?php

declare(strict_types=1);

namespace Phasis\Bytecode;

use Phasis\Value\JsValue;

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
     * Inline cache for LOAD_NAME, indexed by PC. Stores the resolved
     * JsValue at first dispatch; subsequent LOAD_NAMEs at the same PC
     * skip the Environment walk entirely.
     *
     * Soundness: invalidated by Environment::set, which bumps a global
     * counter. Each cache entry carries the counter value at capture
     * time; on read, mismatch forces a re-resolve.
     *
     * @var array<int, array{0: JsValue, 1: int}>
     */
    public array $loadNameIc = [];

    /**
     * Exception-handler table for this function's bytecode. Each entry
     * defines a [tryStart, tryEnd) PC range, a catchPc to jump to on
     * throw, an exception slot for the catch parameter, and the
     * operand-stack depth at try entry. The VM consults this on every
     * caught exception to find the innermost matching handler.
     *
     * @var list<HandlerEntry>
     */
    public array $handlers = [];

    /**
     * AST nodes for nested class declarations / expressions. Op::MAKE_CLASS
     * carries an index into this list and the VM hands the node to
     * Interpreter::vmMakeClass, which delegates to the tree-walker's
     * class evaluator. The class body (methods, super, [[HomeObject]],
     * field initializers) still runs in the tree-walker; the enclosing
     * function is what actually gets VM-compiled now.
     *
     * @var list<\Phasis\Ast\Node>
     */
    public array $classNodes = [];

    /**
     * Source-style display strings for method-call sites, keyed by the
     * PC of the CALL_METHOD opcode. When the dispatched method turns
     * out not to be callable, the VM uses the stored display
     * (e.g. `[].__proto__` or `obj.foo`) in the TypeError message
     * instead of falling back to the value's stringification, which
     * is empty for plain objects/arrays. Only populated for call
     * sites whose callee shape the compiler can render literally;
     * other sites keep the legacy fallback.
     *
     * @var array<int, string>
     */
    public array $callMethodDisplays = [];


    /**
     * @param list<int>     $code        Flat instruction stream.
     * @param list<JsValue> $consts      Pre-converted JsValue constants.
     * @param list<string>  $names       Identifier name table.
     * @param list<string>  $localNames  Slot index → name (diagnostics).
     * @param list<int>     $paramSlots  Parameter index → local slot.
     * @param list<\Phasis\Ast\Node> $nestedFns Templates for nested fns.
     * @param bool $needsThis       True when LOAD_THIS appears anywhere
     *        in the bytecode. Lets executeFunction skip the
     *        defineVar('this', ...) that the tree-walker performs.
     * @param bool $needsArgsBinding True when LOAD_NAME / STORE_NAME
     *        targets a parameter name (the body referenced the param
     *        via its identifier in a way the slot-based path doesn't
     *        cover). When false, executeFunction can skip
     *        bindParameters because LOAD_LOCAL / paramSlot handles
     *        every reference.
     * @param bool $canSkipEnvAlloc True when the bytecode never needs
     *        a fresh scope distinct from the closure: no `this` binding
     *        read via env, no nested-function capture difference, no
     *        body declarations that go through env (the compiler maps
     *        every var/let/const to a frame slot). When true,
     *        executeFunction can hand the closure environment to the
     *        Frame directly and skip the per-call createChild
     *        allocation.
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
        public readonly bool $canSkipEnvAlloc = false,
    ) {
    }
}
