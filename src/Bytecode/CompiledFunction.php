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
     * Inline cache for LOAD_MEMBER, indexed by PC. Stores the
     * receiver's prototype reference at first dispatch alongside the
     * resolved member value and the prototype PropertyMap's version
     * at capture time.
     *
     * Hit condition: the current receiver has no own slot for $name
     * (already verified by the LOAD_MEMBER fast-path miss that led
     * here), the cached proto reference is identical to the
     * receiver's current `getPrototype()`, and the proto's
     * PropertyMap::$version still matches the captured version.
     *
     * Soundness: PropertyMap bumps `version` on every set / delete /
     * data-slot mutation, so any change to the cached value (or its
     * surrounding layout) forces an IC miss on the next read.
     *
     * Optimises the prototype-method-on-instance pattern (class
     * methods, `Array.prototype.push`-style hot calls) where the
     * existing `$obj->properties->dataSlots` fast path can't fire.
     *
     * Polymorphic shape: each PC maps to either a list of up to 4
     * [proto, resolved, version] triples (one per observed shape), or
     * the boolean `true` sentinel marking a megamorphic site (more
     * than 4 distinct shapes seen — the IC walk is skipped, every read
     * routes through lookupMember).
     *
     * @var array<int, list<array{0: \Phasis\Value\JsObject, 1: JsValue, 2: int}>|true>
     */
    public array $loadMemberIc = [];

    /**
     * Inline cache for STORE_MEMBER, indexed by PC. Optimises the
     * "create a new own data property on the receiver" path that the
     * existing dataSlot-fast-path can't fire on (the slot doesn't
     * exist yet, so writeMember -> JsObject::set -> ordinarySetWith…
     * walks the prototype chain looking for interfering setters or
     * readonly inherited descriptors before falling back to
     * defineOwnProperty).
     *
     * Cache shape: a list of [proto, capturedVersion] pairs covering
     * the entire prototype chain at fill time. On hit, the chain is
     * re-walked and every (identity, version) must still match —
     * which guarantees no setter/readonly for $name has appeared
     * anywhere in the chain since the cache was filled. When the
     * chain is clean, the receiver gets a direct setDataSlot, the
     * same as what the slow path would land on.
     *
     * Cache filled only when (a) the chain has no descriptor for
     * $name at any depth and (b) every chain member is a plain
     * JsObject (no Proxy). Anything else falls back to the slow
     * path forever for that PC.
     *
     * @var array<int, list<array{0: \Phasis\Value\JsObject, 1: int}>>
     */
    public array $storeMemberIc = [];

    /**
     * PCs where STORE_MEMBER tried to fill its IC and discovered the
     * site is not cacheable (Proxy in chain, setter/readonly inherited
     * descriptor, etc). Marked once so subsequent dispatches skip the
     * walk entirely and go straight to the slow path.
     *
     * @var array<int, true>
     */
    public array $storeMemberIcUncacheable = [];

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
     * @param bool $needsThis       True when the env must carry a `this`
     *        binding for this body: its own bytecode walks the env for
     *        this (LOAD_THIS — arrows / generators), or a nested arrow
     *        or class outer position lexically references this/super
     *        and resolves it through the env chain at run time.
     *        Frame-this bodies (LOAD_THIS_FRAME) with no such nested
     *        references report false, letting executeFunction skip the
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
