<?php

declare(strict_types=1);

namespace PhpJs\Bytecode;

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
    public const LOAD_THIS = 15;   // push frame->thisValue

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
    public const CALL = 80;          // I — argc; callee at stack[-argc-1]
    public const CALL_METHOD = 81;   // I — argc; stack: [obj, method, args...]
                                       // Pops method + args, peeks obj as receiver,
                                       // result replaces obj. Spec evaluation order
                                       // (lookup before arg eval) is preserved by
                                       // compiling LOAD_MEMBER ahead of the args.
    public const NEW_CALL = 82;      // I — argc; constructor at stack[-argc-1]
    public const RET = 83;           // pop value, return from VM

    // ---- Object / array literals & member access ----------------------
    public const NEW_OBJECT = 90;    // push new {} (with %ObjectPrototype%)
    public const NEW_ARRAY = 91;     // I — length; pop length values, build JsArray
    public const SET_PROP = 92;      // N — obj at stack[-2], val at stack[-1]; obj.name = val (consumes val, leaves obj)
    public const SET_COMPUTED = 93;  // obj, key, val on stack; obj[key] = val (leaves obj)
    public const LOAD_MEMBER = 94;   // N — pop obj; push obj.name
    public const LOAD_COMPUTED = 95; // pop key, pop obj; push obj[key]
    public const STORE_MEMBER = 96;  // N — pop val, pop obj; obj.name = val (writes, no push)
    public const STORE_COMPUTED = 97;// pop val, key, obj; obj[key] = val

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

    // ---- Sentinel / bailout placeholder -------------------------------
    public const NOP = 0;

    /** Number of operand ints following the opcode. */
    public static function operandCount(int $op): int
    {
        switch ($op) {
            // 0-operand opcodes
            case self::POP:
            case self::DUP:
            case self::LOAD_UNDEF:
            case self::LOAD_NULL:
            case self::LOAD_TRUE:
            case self::LOAD_FALSE:
            case self::LOAD_THIS:
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
            case self::NEW_OBJECT:
            case self::SET_COMPUTED:
            case self::LOAD_COMPUTED:
            case self::STORE_COMPUTED:
            case self::THROW:
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
            case self::JUMP:
            case self::JUMP_IF_TRUE:
            case self::JUMP_IF_FALSE:
            case self::JUMP_IF_NULLISH:
            case self::JUMP_IF_TRUTHY_KEEP:
            case self::JUMP_IF_FALSY_KEEP:
            case self::CALL:
            case self::NEW_CALL:
            case self::NEW_ARRAY:
            case self::SET_PROP:
            case self::LOAD_MEMBER:
            case self::STORE_MEMBER:
            case self::CALL_METHOD:
            case self::MAKE_FUNCTION:
            case self::MAKE_CLASS:
                return 1;
            // 3-operand opcodes
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
