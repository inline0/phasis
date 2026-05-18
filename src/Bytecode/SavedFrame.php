<?php

declare(strict_types=1);

namespace Phasis\Bytecode;

use Phasis\Runtime\Environment;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsValue;

/**
 * One saved-caller entry on the VM's inline-call stack.
 *
 * Replaces the original 15-slot mixed-type array. The fields here
 * are individually typed so PHP 8.5's tracing JIT can specialize
 * the field reads and writes; the savings are most visible on the
 * RET path where every field is read back to restore the caller.
 *
 * Frames are pooled on the Interpreter so a long-running script
 * pays one allocation per max-reached call depth instead of one
 * per call. The fields are public + mutable so the VM can re-fill
 * a recycled instance in place.
 *
 * Field meaning matches the previous positional array layout:
 *   - cf / code / consts / names / nestedFns: the caller's
 *     CompiledFunction plus its destructured hot views the VM
 *     reads on every dispatch tick.
 *   - stack / sp / locals: the caller's operand stack, stack
 *     pointer, and local-slot array.
 *   - env / thisValue: the caller's lexical env + this binding.
 *   - strict / hasHandlers: control-flow flags the dispatch loop
 *     reads on every iteration / exception unwind.
 *   - returnPc: the pc the caller resumes at after the inlined
 *     call returns (typically the post-CALL pc — `pc + 2`).
 *   - restore: opaque blob from `Interpreter::setupInlineVmCall`
 *     handed back to `teardownInlineVmCall` on RET / on
 *     exception unwind through this frame.
 *   - callee: the inlined callee JsFunction (kept so teardown can
 *     undo Annex B caller / arguments magic on the same instance).
 */
final class SavedFrame
{
    public CompiledFunction $cf;

    /** @var list<int> */
    public array $code;

    /** @var list<mixed> */
    public array $consts;

    /** @var list<string> */
    public array $names;

    /** @var list<mixed> */
    public array $nestedFns;

    /** @var list<JsValue> */
    public array $stack;

    public int $sp;

    /** @var list<JsValue> */
    public array $locals;

    public Environment $env;

    public JsValue $thisValue;

    public bool $strict;

    public bool $hasHandlers;

    public int $returnPc;

    /** @var array<int, mixed> */
    public array $restore;

    public JsFunction $callee;

    /**
     * True if the inlined call was a `new`-expression (NEW_CALL).
     * On RET the dispatch loop applies the construct substitution
     * rule (return the object the body explicitly returned, else
     * fall back to `$newObject`) before pushing the result onto
     * the caller's operand stack.
     */
    public bool $isConstruct = false;

    /**
     * The fresh JsObject NEW_CALL allocated as `this`. Used both as
     * the substitution fallback (if the constructor body returns
     * a non-object) and as the object the dispatch loop needs to
     * `forceDelete('[[NewTarget]]')` once construction completes
     * or unwinds.
     */
    public ?JsObject $newObject = null;
}
