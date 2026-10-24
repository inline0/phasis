<?php

declare(strict_types=1);

namespace Phasis\Bytecode;

/**
 * Opcode constants for the bytecode VM. Plain int constants (not an
 * enum) so `switch` / `match` on them in the hot dispatch loop hit
 * OPcache's jump-table optimisation.
 *
 * Encoding: each instruction is one int opcode at $code[$pc] followed
 * by 0..N operand ints at $code[$pc + 1 .. pc + N]. Operand counts are
 * fixed per opcode; the dispatcher advances pc by 1 + operand_count.
 *
 * Operand types:
 *   - K: index into CompiledFunction::$consts (a JsValue)
 *   - N: index into CompiledFunction::$names  (a string)
 *   - L: local slot index
 *   - D: scope-depth (for free-var lookups via env chain walk)
 *   - I: signed immediate int (jump offset, argc, etc.)
 *   - H: handler-table index (for try/catch)
 */
final class Op
{
    // ---- Stack manipulation ---------------------------------------------
    public const POP = 1;          // discards top of stack
    public const DUP = 2;          // duplicates top of stack

    // ---- Constants & literals -------------------------------------------
    public const LOAD_CONST = 10;  // K  — push consts[K]
    public const LOAD_UNDEF = 11;  // push JsUndefined
    public const LOAD_NULL = 12;   // push JsNull
    public const LOAD_TRUE = 13;   // push JsBoolean(true)
    public const LOAD_FALSE = 14;  // push JsBoolean(false)
    public const LOAD_THIS = 15;   // push this via env chain walk (arrow lexical-this, derived-ctor TDZ, generators)
    public const NEW_REGEXP = 16;  // N N — create a fresh RegExp from pattern and flags strings.
    public const LOAD_THIS_FRAME = 111; // push frame->thisValue directly (ordinary fn bodies; receiver fixed per call)

    // ---- Locals ---------------------------------------------------------
    public const LOAD_LOCAL = 20;  // L  — push frame->locals[L]
    public const STORE_LOCAL = 21; // L  — frame->locals[L] = pop()
    // INC/DEC discard-result variants. Update a numeric local in place
    // without stack traffic; used by for-loop updates and bare i++/i--
    // expression statements where the result is unused.
    public const INC_LOCAL = 22;   // L  — locals[L] = ToNumber(locals[L]) + 1
    public const DEC_LOCAL = 23;   // L  — locals[L] = ToNumber(locals[L]) - 1

    // ---- Free variables (parent scope binding by name) ------------------
    // The compiler emits LOAD_NAME when it cannot prove the binding is
    // local to the current frame. The VM walks the env chain via the
    // existing Environment::get path (which is already fast thanks to
    // the scope-depth cache on the Identifier AST node we hand off).
    public const LOAD_NAME = 30;   // N  — push env->get(names[N])
    public const STORE_NAME = 31;  // N  — env->set(names[N], pop())
    public const DEFINE_NAME = 32; // N  — env->defineVar(names[N], pop());
                                   // unlike STORE_NAME this creates the binding
                                   // on the current env regardless of whether
                                   // one already exists higher up the chain.
                                   // Used by FunctionDeclaration hoisting to
                                   // re-bind the env entry that hoistDeclarations
                                   // pre-created in the tree-walker, with the
                                   // VM-instantiated JsFunction value so both
                                   // the frame slot and env binding stay in
                                   // sync (closure-capture safety).
    public const INITIALIZE_NAME = 33; // N: initialize a captured lexical binding, leaving TDZ.
    public const ARRAY_APPEND = 34; // Append a data element to the array below it on the stack.
    public const ARRAY_SPREAD = 35; // Consume an iterable into the array below it on the stack.
    public const ARRAY_HOLE = 36; // Extend the array's length without defining an element.
    public const BIND_ARRAY = 37; // I: pop iterable, initialize the indexed flat binding pattern in order.
    public const IN = 38; // Pop object and key, perform HasProperty after ToPropertyKey.
    public const INSTANCEOF = 39; // Pop constructor and value, use InstanceofOperator.

    // ---- Arithmetic & comparison ----------------------------------------
    public const ADD = 40;
    public const SUB = 41;
    public const MUL = 42;
    public const DIV = 43;
    public const MOD = 44;
    public const POW = 45;
    public const NEG = 46;          // unary minus
    public const POS = 47;          // unary plus / ToNumber
    public const LT = 50;
    public const GT = 51;
    public const LE = 52;
    public const GE = 53;
    public const EQ = 54;           // ==  loose equality
    public const NEQ = 55;          // !=  loose inequality
    public const SEQ = 56;          // === strict
    public const SNEQ = 57;         // !== strict
    public const NOT = 58;          // logical !
    public const TYPEOF = 59;
    // ToString coercion. Pops, runs TypeConversion::toString (which
    // invokes ToPrimitive("string") for objects), and pushes a
    // JsString. Used by template literal lowering so untagged
    // ${expr} segments match the spec's ToString semantics rather
    // than the `+` operator's "default" hint (which would let a
    // wrapped Symbol fall through to Symbol → string conversion and
    // throw, breaking the @@toPrimitive=null fallback path).
    public const TO_STRING = 67;

    // ---- Bitwise -------------------------------------------------------
    public const BAND = 60;
    public const BOR = 61;
    public const BXOR = 62;
    public const SHL = 63;
    public const SHR = 64;
    public const USHR = 65;
    public const BNOT = 66;

    // ---- Control flow --------------------------------------------------
    public const JUMP = 70;          // I — pc += operand
    public const JUMP_IF_TRUE = 71;  // I — pop; if truthy jump
    public const JUMP_IF_FALSE = 72; // I — pop; if falsy jump
    public const JUMP_IF_NULLISH = 73; // I — peek; if null/undef jump (preserves stack for ??)
    public const JUMP_IF_TRUTHY_KEEP = 74; // I — peek; jump if truthy (for ||, &&)
    public const JUMP_IF_FALSY_KEEP = 75;  // I — peek; jump if falsy
    // Fused for-loop test: jump (operand offset) if locals[L] is not
    // numerically less than consts[K]. Operands: L K offset. Falls
    // through (continues the loop body) when locals[L] < consts[K].
    // Used for the `for (let i = 0; i < N; i++)` pattern where N is
    // a numeric literal — saves four dispatches per iteration vs the
    // generic LOAD_LOCAL / LOAD_CONST / LT / JUMP_IF_FALSE chain.
    // The hot path requires both operands to be JsNumber; anything
    // else falls back to the generic relational + branch.
    public const JUMP_IF_LOCAL_GE_CONST = 76;
    // Same family, additional shapes used by hot for-loops where the
    // bound is a JS-source local (e.g. `for (i = start; i <= end; i++)`).
    // Operands: L1 L2 offset. JsNumber/JsNumber fast path; any other
    // operand type falls through to the spec relational helper.
    public const JUMP_IF_LOCAL_GE_LOCAL = 77; // for `i < j` test
    public const JUMP_IF_LOCAL_GT_LOCAL = 78; // for `i <= j` test
    public const JUMP_IF_LOCAL_GT_CONST = 79; // for `i <= N` test

    // ---- Calls & returns -----------------------------------------------
    // Each call opcode accepts argc=-1 for a compiler-owned dense argument
    // array on top of the stack, allowing spread without changing call order.
    public const CALL = 80;          // I — argc; callee at stack[-argc-1]
    public const CALL_METHOD = 81;   // I — argc; stack: [obj, method, args...]
                                       // Pops method + args, peeks obj as receiver,
                                       // result replaces obj. Spec evaluation order
                                       // (lookup before arg eval) is preserved by
                                       // compiling LOAD_MEMBER ahead of the args.
    public const NEW_CALL = 82;      // I — argc; constructor at stack[-argc-1]
    public const RET = 83;           // pop value, return from VM
    public const YIELD = 84;         // generator suspension; pop yielded value,
                                       // capture frame state into a GeneratorSnapshot,
                                       // return YieldResult to VM caller. Resume
                                       // re-enters at pc+1 with the resumed value
                                       // pushed onto the operand stack.
    public const TAIL_CALL = 85;     // I — CALL layout, produce a trampoline thunk for RET
    public const TAIL_CALL_METHOD = 86; // I — CALL_METHOD layout, preserve the receiver in the thunk

    // ---- Object / array literals & member access ----------------------
    public const NEW_OBJECT = 90;    // push new {} (with %ObjectPrototype%)
    public const NEW_ARRAY = 91;     // I — length; pop length values, build JsArray
    public const SET_PROP = 92;      // N — obj at stack[-2], val at stack[-1]; obj.name = val (consumes val, leaves obj)
    public const SET_COMPUTED = 93;  // obj, key, val on stack; obj[key] = val (leaves obj)
    public const LOAD_MEMBER = 94;   // N — pop obj; push obj.name
    public const LOAD_COMPUTED = 95; // pop key, pop obj; push obj[key]
    public const STORE_MEMBER = 96;  // N — pop val, pop obj; obj.name = val (writes, no push)
    public const STORE_COMPUTED = 97;// pop val, key, obj; obj[key] = val
    public const SET_LITERAL_PROTOTYPE = 98; // pop value; initialize the remaining object's prototype if object/null.
    public const TO_PROPERTY_KEY = 99; // replace the top value with its string or Symbol property key.

    // ---- Function expressions & arrows ---------------------------------
    public const MAKE_FUNCTION = 100; // I — index into CompiledFunction::$nestedFns;
                                       //     pushes a JsFunction whose closure is
                                       //     the current env.

    // ---- Classes -------------------------------------------------------
    // Pushes a JsFunction (the class constructor) built by the
    // tree-walker's class evaluator. The class body — methods, super
    // bindings, [[HomeObject]] wiring, instance fields — still runs in
    // the tree-walker; this op just lets the *enclosing* function be
    // VM-compiled instead of bailing on the whole thing.
    public const MAKE_CLASS = 101;     // I — index into CompiledFunction::$classNodes;

    // ---- Throw -----------------------------------------------------------
    public const THROW = 110;          // pop value, raise as JsThrowable.
    public const TYPEOF_NAME = 112; // N: typeof a binding, allowing unresolvable names but preserving TDZ.
    public const DELETE_NAME = 113; // N: delete an environment binding without reading its value.
    public const DELETE_PROPERTY = 114; // pop key and base, delete the property, push the Boolean result.
    public const UPDATE_NAME = 115; // N I I: resolve a binding once, update by +/-1, push prefix/postfix result.
    public const EVAL_EXPRESSION = 116; // I: evaluate an environment-only expression through the interpreter.
    public const UPDATE_MEMBER = 117; // N I I: update a literal property, preserving its resolved base.
    public const UPDATE_COMPUTED = 118; // I I: update a computed property, coercing its key once.

    public const LOOP_INIT = 120;
    public const LOOP_TICK = 121;
    public const CHECK_BUDGET = 126; // Host deadline checkpoint, independent of the body iteration count.
    public const STRING_LOOP_REGION = 125; // I: one-shot pure first segment; ordinary VM backedges bypass it.
    public const ITERATOR_INIT = 122; // L: acquire an iterator and cached next in adjacent private slots.
    public const ITERATOR_NEXT = 123; // L I J: check loop limits, push the next value or branch when done.
    public const ITERATOR_CLOSE = 124; // L L L: close with the pending completion and value.

    // ---- Sentinel / bailout placeholder -------------------------------
    public const NOP = 0;

    /** Number of operand ints following the opcode. */
    public static function operandCount(int $op): int
    {
        switch ($op) {
            // 0-operand opcodes
            case self::CHECK_BUDGET:
            case self::POP:
            case self::DUP:
            case self::LOAD_UNDEF:
            case self::LOAD_NULL:
            case self::LOAD_TRUE:
            case self::LOAD_FALSE:
            case self::LOAD_THIS:
            case self::LOAD_THIS_FRAME:
            case self::ADD:
            case self::SUB:
            case self::MUL:
            case self::DIV:
            case self::MOD:
            case self::POW:
            case self::NEG:
            case self::POS:
            case self::LT:
            case self::GT:
            case self::LE:
            case self::GE:
            case self::EQ:
            case self::NEQ:
            case self::SEQ:
            case self::SNEQ:
            case self::IN:
            case self::INSTANCEOF:
            case self::NOT:
            case self::TYPEOF:
            case self::BAND:
            case self::BOR:
            case self::BXOR:
            case self::SHL:
            case self::SHR:
            case self::USHR:
            case self::BNOT:
            case self::RET:
            case self::YIELD:
            case self::NEW_OBJECT:
            case self::ARRAY_APPEND:
            case self::ARRAY_SPREAD:
            case self::ARRAY_HOLE:
            case self::SET_LITERAL_PROTOTYPE:
            case self::TO_PROPERTY_KEY:
            case self::SET_COMPUTED:
            case self::LOAD_COMPUTED:
            case self::STORE_COMPUTED:
            case self::THROW:
            case self::DELETE_PROPERTY:
            case self::NOP:
                return 0;
            // 1-operand opcodes
            case self::LOAD_CONST:
            case self::LOAD_LOCAL:
            case self::STORE_LOCAL:
            case self::INC_LOCAL:
            case self::DEC_LOCAL:
            case self::LOAD_NAME:
            case self::STORE_NAME:
            case self::DEFINE_NAME:
            case self::INITIALIZE_NAME:
            case self::BIND_ARRAY:
            case self::TYPEOF_NAME:
            case self::DELETE_NAME:
            case self::EVAL_EXPRESSION:
            case self::JUMP:
            case self::JUMP_IF_TRUE:
            case self::JUMP_IF_FALSE:
            case self::JUMP_IF_NULLISH:
            case self::JUMP_IF_TRUTHY_KEEP:
            case self::JUMP_IF_FALSY_KEEP:
            case self::CALL:
            case self::TAIL_CALL:
            case self::TAIL_CALL_METHOD:
            case self::NEW_CALL:
            case self::NEW_ARRAY:
            case self::SET_PROP:
            case self::LOAD_MEMBER:
            case self::STORE_MEMBER:
            case self::CALL_METHOD:
            case self::MAKE_FUNCTION:
            case self::MAKE_CLASS:
            case self::LOOP_INIT:
            case self::LOOP_TICK:
            case self::STRING_LOOP_REGION:
            case self::ITERATOR_INIT:
                return 1;
            // 2-operand opcodes
            case self::NEW_REGEXP:
            case self::UPDATE_COMPUTED:
                return 2;
            // 3-operand opcodes
            case self::UPDATE_NAME:
            case self::ITERATOR_NEXT:
            case self::ITERATOR_CLOSE:
            case self::UPDATE_MEMBER:
            case self::JUMP_IF_LOCAL_GE_CONST:
            case self::JUMP_IF_LOCAL_GE_LOCAL:
            case self::JUMP_IF_LOCAL_GT_LOCAL:
            case self::JUMP_IF_LOCAL_GT_CONST:
                return 3;
            default:
                throw new \LogicException('Unknown opcode: ' . $op);
        }
    }
}
