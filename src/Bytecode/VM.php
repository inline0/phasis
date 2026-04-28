<?php

declare(strict_types=1);

namespace PhpJs\Bytecode;

use PhpJs\Exceptions\InternalError;
use PhpJs\Exceptions\TypeError;
use PhpJs\Runtime\Interpreter;
use PhpJs\Spec\AbstractOperations;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

/**
 * Bytecode dispatcher. One PHP function call per JsFunction
 * invocation, regardless of how many AST nodes the function body
 * contains. Hot ops (numeric arithmetic / comparison / locals)
 * stay inside the switch with no method dispatch; spec fallback
 * delegates to existing Interpreter helpers.
 */
final class VM
{
    public function __construct(private readonly Interpreter $interp)
    {
    }

    /**
     * Property-read helper shared by LOAD_MEMBER (with a literal name)
     * and CALL_METHOD (which fetches the callee). For plain
     * (non-Symbol-wrapper) JsObjects we use the inlined own-data
     * fast path that JsObject::get already provides. Symbol wrappers
     * and primitive bases route through the Interpreter's
     * full-member-access helper.
     */
    private function lookupMember(JsValue $obj, string $name): JsValue
    {
        if ($obj instanceof JsNull || $obj instanceof JsUndefined) {
            $type = $obj instanceof JsNull ? 'null' : 'undefined';
            throw new TypeError("Cannot read properties of {$type} (reading '{$name}')");
        }
        if ($obj instanceof JsObject) {
            $primSlot = $obj->getOwnPropertyDescriptor('[[PrimitiveValue]]');
            if ($primSlot !== null && $primSlot->value instanceof \PhpJs\Value\JsSymbol) {
                return $this->interp->vmLookupPrimitiveMember($obj, $name);
            }
            return $obj->get($name);
        }
        return $this->interp->vmLookupPrimitiveMember($obj, $name);
    }

    private function lookupComputed(JsValue $obj, JsValue $key): JsValue
    {
        if ($obj instanceof JsNull || $obj instanceof JsUndefined) {
            $type = $obj instanceof JsNull ? 'null' : 'undefined';
            throw new TypeError("Cannot read properties of {$type}");
        }
        $resolved = TypeConversion::toPropertyKey($key);
        if ($resolved instanceof \PhpJs\Value\JsSymbol) {
            if ($obj instanceof JsObject) {
                return $obj->getBySymbol($resolved);
            }
            return $this->slowComputedRead($obj, $key);
        }
        $name = $resolved instanceof JsString ? $resolved->value : TypeConversion::toString($resolved);
        if ($obj instanceof JsObject) {
            return $obj->get($name);
        }
        return $this->slowMemberRead($obj, $name);
    }

    private function writeMember(JsValue $obj, string $name, JsValue $value): void
    {
        if ($obj instanceof JsNull || $obj instanceof JsUndefined) {
            $type = $obj instanceof JsNull ? 'null' : 'undefined';
            throw new TypeError("Cannot set properties of {$type} (setting '{$name}')");
        }
        if ($obj instanceof JsObject) {
            $obj->set($name, $value, $this->interp->isStrictMode());
            return;
        }
        // Primitive base: spec PutValue calls [[Set]] on the boxed
        // wrapper with the original primitive as the receiver. The
        // wrapper's [[Set]] walks the prototype chain looking for a
        // setter; with no setter, OrdinarySet returns false because
        // the receiver is not an Object that can host a new property.
        // Strict mode then throws TypeError. Defer to the
        // Interpreter helper that owns this branch in the tree-walker.
        $this->interp->vmPrimitiveSet($obj, $name, $value);
    }

    private function writeComputed(JsValue $obj, JsValue $key, JsValue $value): void
    {
        if ($obj instanceof JsNull || $obj instanceof JsUndefined) {
            $type = $obj instanceof JsNull ? 'null' : 'undefined';
            throw new TypeError("Cannot set properties of {$type}");
        }
        $resolved = TypeConversion::toPropertyKey($key);
        if ($resolved instanceof \PhpJs\Value\JsSymbol) {
            if ($obj instanceof JsObject) {
                $obj->internalSetBySymbol($resolved, $value, $obj);
                return;
            }
            // Symbol-keyed write on a primitive needs the same
            // walk-the-chain dance as the string-keyed primitive
            // case; defer to the tree-walker helper.
            $this->interp->vmPrimitiveSetSymbol($obj, $resolved, $value);
            return;
        }
        $name = $resolved instanceof JsString ? $resolved->value : TypeConversion::toString($resolved);
        $this->writeMember($obj, $name, $value);
    }

    /**
     * Fall back to the tree-walker's full member-read path for
     * primitive bases (string indexing, prototype chain, primitive
     * auto-box) by routing through Interpreter::lookupMemberFallback.
     * Avoids re-implementing the long branch ladder in the VM.
     */
    private function slowMemberRead(JsValue $obj, string $name): JsValue
    {
        return $this->interp->vmLookupPrimitiveMember($obj, $name);
    }

    private function slowComputedRead(JsValue $obj, JsValue $key): JsValue
    {
        return $this->interp->vmLookupPrimitiveComputed($obj, $key);
    }

    /**
     * Inline implementation of hot Array.prototype methods on a dense
     * JsArray receiver. Returns the JS result on success, or null to
     * indicate the spec path should run instead (callback shape that
     * doesn't fit the inline template, sparse element, etc.). The
     * caller asserts the receiver is a dense JsArray; this helper
     * still rechecks sub-conditions per method.
     *
     * The inline loops dispatch the JsFunction callback directly via
     * its phpCompiled closure when one is cached, skipping callFunction
     * / executeFunction entirely. When the callback returns a value
     * incompatible with the inline assumptions (non-numeric for
     * accumulator, etc.) it bails out so the spec path takes over.
     *
     * @param list<JsValue> $args
     */
    private function inlineDenseArrayMethod(
        string $kind,
        \PhpJs\Value\JsArray $receiver,
        array $args,
        int $argc,
    ): ?JsValue {
        switch ($kind) {
            case 'array.fill':
                if ($argc !== 1) {
                    return null;
                }
                $value = $args[0];
                $len = $receiver->getLength();
                for ($i = 0; $i < $len; $i++) {
                    $receiver->setDenseElement($i, $value);
                }
                return $receiver;

            case 'array.map':
                if ($argc < 1 || !($args[0] instanceof JsFunction)) {
                    return null;
                }
                // Spec ArraySpeciesCreate inspects the receiver's own
                // 'constructor' property (then Symbol.species on it) to
                // decide what type of array to allocate. Bail if the
                // receiver has its own constructor override so the
                // spec path picks up the species hook.
                if ($receiver->getOwnPropertyDescriptor('constructor') !== null) {
                    return null;
                }
                $callback = $args[0];
                if (
                    $callback->isClassConstructor()
                    || $callback->isDerivedConstructor()
                    || $callback->getHomeObject() !== null
                ) {
                    return null;
                }
                $thisArg = (isset($args[1]) && !$args[1] instanceof JsUndefined)
                    ? $args[1] : $undef = JsUndefined::instance();
                $len = $receiver->getLength();
                $elements = $receiver->getDenseElements();
                $result = new \PhpJs\Value\JsArray();
                $compiled = $callback->phpCompiled;
                for ($i = 0; $i < $len; $i++) {
                    $val = $elements[$i] ?? null;
                    if ($val === null) {
                        return null;
                    }
                    $idx = JsNumber::of((float) $i);
                    if ($compiled !== null) {
                        try {
                            $mapped = $compiled(
                                [$val, $idx, $receiver],
                                $callback->getClosure(),
                                $this->interp,
                                $callback->phpCompiledNodes,
                            );
                        } catch (\PhpJs\Bytecode\Bailout) {
                            $mapped = $this->interp->callFunction($callback, $thisArg, [$val, $idx, $receiver]);
                        }
                    } else {
                        $mapped = $this->interp->callFunction($callback, $thisArg, [$val, $idx, $receiver]);
                    }
                    $result->push($mapped);
                }
                return $result;

            case 'array.reduce':
                if ($argc < 1 || !($args[0] instanceof JsFunction)) {
                    return null;
                }
                $callback = $args[0];
                if (
                    $callback->isClassConstructor()
                    || $callback->isDerivedConstructor()
                    || $callback->getHomeObject() !== null
                ) {
                    return null;
                }
                $len = $receiver->getLength();
                $elements = $receiver->getDenseElements();
                $hasInitial = $argc >= 2;
                if (!$hasInitial && $len === 0) {
                    return null;
                }
                $start = 0;
                if ($hasInitial) {
                    $acc = $args[1];
                } else {
                    $acc = $elements[0] ?? null;
                    if ($acc === null) {
                        return null;
                    }
                    $start = 1;
                }
                $undef = JsUndefined::instance();
                $compiled = $callback->phpCompiled;
                for ($i = $start; $i < $len; $i++) {
                    $val = $elements[$i] ?? null;
                    if ($val === null) {
                        return null;
                    }
                    $idx = JsNumber::of((float) $i);
                    if ($compiled !== null) {
                        try {
                            $acc = $compiled(
                                [$acc, $val, $idx, $receiver],
                                $callback->getClosure(),
                                $this->interp,
                                $callback->phpCompiledNodes,
                            );
                            continue;
                        } catch (\PhpJs\Bytecode\Bailout) {}
                    }
                    $acc = $this->interp->callFunction(
                        $callback,
                        $undef,
                        [$acc, $val, $idx, $receiver],
                    );
                }
                return $acc;

            case 'array.forEach':
                if ($argc < 1 || !($args[0] instanceof JsFunction)) {
                    return null;
                }
                $callback = $args[0];
                if (
                    $callback->isClassConstructor()
                    || $callback->isDerivedConstructor()
                    || $callback->getHomeObject() !== null
                ) {
                    return null;
                }
                $thisArg = (isset($args[1]) && !$args[1] instanceof JsUndefined)
                    ? $args[1] : JsUndefined::instance();
                $len = $receiver->getLength();
                $elements = $receiver->getDenseElements();
                $compiled = $callback->phpCompiled;
                for ($i = 0; $i < $len; $i++) {
                    $val = $elements[$i] ?? null;
                    if ($val === null) {
                        return null;
                    }
                    $idx = JsNumber::of((float) $i);
                    if ($compiled !== null) {
                        try {
                            $compiled(
                                [$val, $idx, $receiver],
                                $callback->getClosure(),
                                $this->interp,
                                $callback->phpCompiledNodes,
                            );
                            continue;
                        } catch (\PhpJs\Bytecode\Bailout) {}
                    }
                    $this->interp->callFunction($callback, $thisArg, [$val, $idx, $receiver]);
                }
                return JsUndefined::instance();

            case 'array.filter':
                if ($argc < 1 || !($args[0] instanceof JsFunction)) {
                    return null;
                }
                // Same species-detection rule as map: own 'constructor'
                // override means the spec ArraySpeciesCreate path needs
                // to run so the species hook can fire.
                if ($receiver->getOwnPropertyDescriptor('constructor') !== null) {
                    return null;
                }
                $callback = $args[0];
                if (
                    $callback->isClassConstructor()
                    || $callback->isDerivedConstructor()
                    || $callback->getHomeObject() !== null
                ) {
                    return null;
                }
                $thisArg = (isset($args[1]) && !$args[1] instanceof JsUndefined)
                    ? $args[1] : JsUndefined::instance();
                $len = $receiver->getLength();
                $elements = $receiver->getDenseElements();
                $result = new \PhpJs\Value\JsArray();
                $compiled = $callback->phpCompiled;
                for ($i = 0; $i < $len; $i++) {
                    $val = $elements[$i] ?? null;
                    if ($val === null) {
                        return null;
                    }
                    $idx = JsNumber::of((float) $i);
                    if ($compiled !== null) {
                        try {
                            $keep = $compiled(
                                [$val, $idx, $receiver],
                                $callback->getClosure(),
                                $this->interp,
                                $callback->phpCompiledNodes,
                            );
                        } catch (\PhpJs\Bytecode\Bailout) {
                            $keep = $this->interp->callFunction($callback, $thisArg, [$val, $idx, $receiver]);
                        }
                    } else {
                        $keep = $this->interp->callFunction($callback, $thisArg, [$val, $idx, $receiver]);
                    }
                    if (TypeConversion::toBoolean($keep)) {
                        $result->push($val);
                    }
                }
                return $result;
        }
        return null;
    }

    public function execute(CompiledFunction $cf, Frame $frame): JsValue
    {
        $code = $cf->code;
        $consts = $cf->consts;
        $names = $cf->names;
        $nestedFns = $cf->nestedFns;
        $stack = $frame->stack;
        $sp = $frame->sp;
        $locals = $frame->locals;
        $env = $frame->env;
        $thisValue = $frame->thisValue;
        $strict = $this->interp->isStrictMode();
        $undef = JsUndefined::instance();
        $null = JsNull::instance();
        $true = JsBoolean::of(true);
        $false = JsBoolean::of(false);

        $pc = 0;
        $hasHandlers = $cf->handlers !== [];

        // Outer loop wraps the dispatch in try/catch only when this
        // function actually has handlers. The bench-relevant hot path
        // (fib, closure, etc.) has no handlers and runs the cheaper
        // dispatch loop without the try frame.
        while (true) {
            try {
                while (true) {
                    $op = $code[$pc];
                    switch ($op) {
                        case Op::POP:
                            $sp--;
                            $pc++;
                            break;
                        case Op::DUP:
                            $stack[$sp] = $stack[$sp - 1];
                            $sp++;
                            $pc++;
                            break;

                        case Op::LOAD_CONST:
                            $stack[$sp++] = $consts[$code[$pc + 1]];
                            $pc += 2;
                            break;
                        case Op::LOAD_UNDEF:
                            $stack[$sp++] = $undef;
                            $pc++;
                            break;
                        case Op::LOAD_NULL:
                            $stack[$sp++] = $null;
                            $pc++;
                            break;
                        case Op::LOAD_TRUE:
                            $stack[$sp++] = $true;
                            $pc++;
                            break;
                        case Op::LOAD_FALSE:
                            $stack[$sp++] = $false;
                            $pc++;
                            break;
                        case Op::LOAD_THIS:
                            // Resolve this via the env chain so derived-
                            // constructor TDZ throws (and arrow lexical-this
                            // walks) fire at the right point — matching the
                            // tree-walker's evalThisExpression.
                            $stack[$sp++] = $env->get('this');
                            $pc++;
                            break;

                        case Op::LOAD_LOCAL:
                            $stack[$sp++] = $locals[$code[$pc + 1]];
                            $pc += 2;
                            break;
                        case Op::STORE_LOCAL:
                            $locals[$code[$pc + 1]] = $stack[--$sp];
                            $pc += 2;
                            break;
                        case Op::INC_LOCAL:
                            $slotIdx = $code[$pc + 1];
                            $cur = $locals[$slotIdx];
                            $locals[$slotIdx] = ($cur instanceof JsNumber)
                                ? JsNumber::of($cur->value + 1.0)
                                : JsNumber::of(TypeConversion::toNumber($cur) + 1.0);
                            $pc += 2;
                            break;
                        case Op::DEC_LOCAL:
                            $slotIdx = $code[$pc + 1];
                            $cur = $locals[$slotIdx];
                            $locals[$slotIdx] = ($cur instanceof JsNumber)
                                ? JsNumber::of($cur->value - 1.0)
                                : JsNumber::of(TypeConversion::toNumber($cur) - 1.0);
                            $pc += 2;
                            break;

                        case Op::LOAD_NAME:
                            // Inline cache: if we resolved this PC before AND
                            // no env binding has mutated since (global version
                            // counter unchanged), reuse the captured value.
                            // The env walk for global function references like
                            // `fib` inside fib's body otherwise burns ~10 hash
                            // ops per call, repeated on every recursive entry.
                            $ic = $cf->loadNameIc[$pc] ?? null;
                            if ($ic !== null && $ic[1] === \PhpJs\Runtime\Environment::$globalBindingsVersion) {
                                $stack[$sp++] = $ic[0];
                            } else {
                                $resolved = $env->get($names[$code[$pc + 1]], $strict);
                                $cf->loadNameIc[$pc] = [$resolved, \PhpJs\Runtime\Environment::$globalBindingsVersion];
                                $stack[$sp++] = $resolved;
                            }
                            $pc += 2;
                            break;
                        case Op::STORE_NAME:
                            $env->set($names[$code[$pc + 1]], $stack[--$sp], $strict);
                            $pc += 2;
                            break;

                // ---- Arithmetic ------------------------------------------
                        case Op::ADD:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            if ($l instanceof JsNumber && $r instanceof JsNumber) {
                                $stack[$sp++] = JsNumber::of($l->value + $r->value);
                            } elseif ($l instanceof JsString && $r instanceof JsString) {
                                // Both strings: spec ApplyStringOrNumericBinaryOperator
                                // calls ToPrimitive on both (no-op for strings) then,
                                // since at least one primitive is string, returns a
                                // string concatenation. Skip the addOperator dispatch
                                // but route through concatNormalize so a trailing
                                // CESU-8 high surrogate in $l merges with a leading
                                // low surrogate in $r into a proper supplementary
                                // codepoint.
                                $stack[$sp++] = new JsString(JsString::concatNormalize($l->value, $r->value));
                            } else {
                                $stack[$sp++] = $this->interp->addOperator($l, $r);
                            }
                            $pc++;
                            break;
                        case Op::SUB:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            $stack[$sp++] = ($l instanceof JsNumber && $r instanceof JsNumber)
                                ? JsNumber::of($l->value - $r->value)
                                : $this->interp->numericBinaryOp($l, $r, '-');
                            $pc++;
                            break;
                        case Op::MUL:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            $stack[$sp++] = ($l instanceof JsNumber && $r instanceof JsNumber)
                                ? JsNumber::of($l->value * $r->value)
                                : $this->interp->numericBinaryOp($l, $r, '*');
                            $pc++;
                            break;
                        case Op::DIV:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            $stack[$sp++] = $this->interp->numericBinaryOp($l, $r, '/');
                            $pc++;
                            break;
                        case Op::MOD:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            $stack[$sp++] = $this->interp->numericBinaryOp($l, $r, '%');
                            $pc++;
                            break;
                        case Op::POW:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            $stack[$sp++] = $this->interp->exponentiate($l, $r);
                            $pc++;
                            break;

                // ---- Comparison ------------------------------------------
                        case Op::LT:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            if ($l instanceof JsNumber && $r instanceof JsNumber) {
                                $lv = $l->value;
                                $rv = $r->value;
                                $stack[$sp++] = (!is_nan($lv) && !is_nan($rv) && $lv < $rv) ? $true : $false;
                            } else {
                                $stack[$sp++] = $this->interp->relational($l, $r, '<');
                            }
                            $pc++;
                            break;
                        case Op::GT:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            if ($l instanceof JsNumber && $r instanceof JsNumber) {
                                $lv = $l->value;
                                $rv = $r->value;
                                $stack[$sp++] = (!is_nan($lv) && !is_nan($rv) && $lv > $rv) ? $true : $false;
                            } else {
                                $stack[$sp++] = $this->interp->relational($r, $l, '>');
                            }
                            $pc++;
                            break;
                        case Op::LE:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            if ($l instanceof JsNumber && $r instanceof JsNumber) {
                                $lv = $l->value;
                                $rv = $r->value;
                                $stack[$sp++] = (!is_nan($lv) && !is_nan($rv) && $lv <= $rv) ? $true : $false;
                            } else {
                                $stack[$sp++] = $this->interp->relational($r, $l, '<=');
                            }
                            $pc++;
                            break;
                        case Op::GE:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            if ($l instanceof JsNumber && $r instanceof JsNumber) {
                                $lv = $l->value;
                                $rv = $r->value;
                                $stack[$sp++] = (!is_nan($lv) && !is_nan($rv) && $lv >= $rv) ? $true : $false;
                            } else {
                                $stack[$sp++] = $this->interp->relational($l, $r, '>=');
                            }
                            $pc++;
                            break;
                        case Op::EQ:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            $stack[$sp++] = AbstractOperations::abstractEquals($l, $r) ? $true : $false;
                            $pc++;
                            break;
                        case Op::NEQ:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            $stack[$sp++] = AbstractOperations::abstractEquals($l, $r) ? $false : $true;
                            $pc++;
                            break;
                        case Op::SEQ:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            if ($l instanceof JsNumber && $r instanceof JsNumber) {
                                $lv = $l->value;
                                $rv = $r->value;
                                $stack[$sp++] = ($lv === $rv && !is_nan($lv)) ? $true : $false;
                            } else {
                                $stack[$sp++] = AbstractOperations::strictEquals($l, $r) ? $true : $false;
                            }
                            $pc++;
                            break;
                        case Op::SNEQ:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            if ($l instanceof JsNumber && $r instanceof JsNumber) {
                                $lv = $l->value;
                                $rv = $r->value;
                                $stack[$sp++] = ($lv !== $rv || is_nan($lv)) ? $true : $false;
                            } else {
                                $stack[$sp++] = AbstractOperations::strictEquals($l, $r) ? $false : $true;
                            }
                            $pc++;
                            break;

                // ---- Bitwise --------------------------------------------
                        case Op::BAND:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            $stack[$sp++] = $this->interp->bitwiseBinaryOp($l, $r, '&');
                            $pc++;
                            break;
                        case Op::BOR:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            $stack[$sp++] = $this->interp->bitwiseBinaryOp($l, $r, '|');
                            $pc++;
                            break;
                        case Op::BXOR:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            $stack[$sp++] = $this->interp->bitwiseBinaryOp($l, $r, '^');
                            $pc++;
                            break;
                        case Op::SHL:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            $stack[$sp++] = $this->interp->bitwiseShift($l, $r, '<<');
                            $pc++;
                            break;
                        case Op::SHR:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            $stack[$sp++] = $this->interp->bitwiseShift($l, $r, '>>');
                            $pc++;
                            break;
                        case Op::USHR:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            $stack[$sp++] = $this->interp->bitwiseShift($l, $r, '>>>');
                            $pc++;
                            break;

                // ---- Unary ----------------------------------------------
                        case Op::NOT:
                            $v = $stack[--$sp];
                            $stack[$sp++] = TypeConversion::toBoolean($v) ? $false : $true;
                            $pc++;
                            break;
                        case Op::NEG:
                            $v = $stack[--$sp];
                            if ($v instanceof JsNumber) {
                                $stack[$sp++] = JsNumber::of(-$v->value);
                            } else {
                                $n = TypeConversion::toNumeric($v);
                                if ($n instanceof JsNumber) {
                                    $stack[$sp++] = JsNumber::of(-$n->value);
                                } else {
                                    // BigInt or other: defer to the spec helper
                                    // for full correctness.
                                    $stack[$sp++] = $this->interp->numericBinaryOp(
                                        JsNumber::of(0.0),
                                        $v,
                                        '-',
                                    );
                                }
                            }
                            $pc++;
                            break;
                        case Op::POS:
                            $v = $stack[--$sp];
                            if ($v instanceof JsNumber) {
                                $stack[$sp++] = $v;
                            } else {
                                $stack[$sp++] = JsNumber::of(TypeConversion::toNumber($v));
                            }
                            $pc++;
                            break;

                // ---- Control flow ---------------------------------------
                        case Op::JUMP:
                            $pc += $code[$pc + 1];
                            break;
                        case Op::JUMP_IF_TRUE:
                            $v = $stack[--$sp];
                            // Hot path: comparison ops produce JsBoolean
                            // results, which feed straight into conditional
                            // jumps. Read the value field directly to skip
                            // toBoolean's dispatch chain.
                            $truthy = $v instanceof JsBoolean
                                ? $v->value
                                : TypeConversion::toBoolean($v);
                            if ($truthy) {
                                $pc += $code[$pc + 1];
                            } else {
                                $pc += 2;
                            }
                            break;
                        case Op::JUMP_IF_FALSE:
                            $v = $stack[--$sp];
                            $truthy = $v instanceof JsBoolean
                                ? $v->value
                                : TypeConversion::toBoolean($v);
                            if (!$truthy) {
                                $pc += $code[$pc + 1];
                            } else {
                                $pc += 2;
                            }
                            break;
                        case Op::JUMP_IF_LOCAL_GE_CONST:
                            // Fused for-loop test: pc += offset (jump to loop
                            // exit) when locals[L] >= consts[K], else fall
                            // through to body. Hot path is JsNumber/JsNumber;
                            // any other operand type runs the spec-correct
                            // AbstractRelational comparison.
                            $localVal = $locals[$code[$pc + 1]];
                            $constVal = $consts[$code[$pc + 2]];
                            if ($localVal instanceof JsNumber && $constVal instanceof JsNumber) {
                                if (!($localVal->value < $constVal->value)) {
                                    $pc += $code[$pc + 3];
                                } else {
                                    $pc += 4;
                                }
                            } else {
                                // Spec-correct fallback: local < const via the
                                // shared abstractRelational helper. NaN (null
                                // result) or false collapses to "exit loop";
                                // true means continue.
                                $rel = AbstractOperations::abstractRelational($localVal, $constVal, true);
                                if ($rel !== true) {
                                    $pc += $code[$pc + 3];
                                } else {
                                    $pc += 4;
                                }
                            }
                            break;
                        case Op::JUMP_IF_TRUTHY_KEEP:
                            $v = $stack[$sp - 1];
                            if (TypeConversion::toBoolean($v)) {
                                $pc += $code[$pc + 1];
                            } else {
                                $sp--;
                                $pc += 2;
                            }
                            break;
                        case Op::JUMP_IF_FALSY_KEEP:
                            $v = $stack[$sp - 1];
                            if (!TypeConversion::toBoolean($v)) {
                                $pc += $code[$pc + 1];
                            } else {
                                $sp--;
                                $pc += 2;
                            }
                            break;
                        case Op::JUMP_IF_NULLISH:
                            // If nullish: pop and jump (so the right-side
                            // path can evaluate its replacement onto a clean
                            // stack). If not nullish: keep on stack, fall
                            // through (the parent JUMP @end then skips the
                            // right side, leaving left as the `??` result).
                            $v = $stack[$sp - 1];
                            if ($v instanceof JsNull || $v instanceof JsUndefined) {
                                $sp--;
                                $pc += $code[$pc + 1];
                            } else {
                                $pc += 2;
                            }
                            break;

                // ---- Calls / return -------------------------------------
                        case Op::CALL:
                            $argc = $code[$pc + 1];
                            $base = $sp - $argc;
                            // Inline small-argc arg packing — array_slice
                            // allocates a new array even for argc=1 (the most
                            // common case for closures). Manual packing for
                            // argc <= 2 saves an allocation per call site.
                            if ($argc === 0) {
                                $args = [];
                            } elseif ($argc === 1) {
                                $args = [$stack[$base]];
                            } elseif ($argc === 2) {
                                $args = [$stack[$base], $stack[$base + 1]];
                            } else {
                                $args = array_slice($stack, $base, $argc);
                            }
                            $callee = $stack[$base - 1];
                            $sp = $base - 1;
                            if (!$callee instanceof JsFunction) {
                                throw new TypeError(
                                    (TypeConversion::toString($callee) ?: 'value') . ' is not a function'
                                );
                            }
                            // Fast path: VM-compiled callee with the canSkipEnvAlloc
                            // shape. Skip callFunction's tail-call trampoline and
                            // callFunctionInner's kind dispatcher — both of which
                            // are pure overhead for the case we hit ~99% of the
                            // time. Eligibility is memoised on JsFunction so the
                            // hot CALL path collapses to a single field fetch.
                            $eligible = $callee->vmDirectDispatchCache;
                            if ($eligible === null) {
                                $eligible = $callee->compiled !== null
                                    && $callee->compiled->canSkipEnvAlloc
                                    && !$callee->isClassConstructor()
                                    && !$callee->isDerivedConstructor()
                                    && $callee->getHomeObject() === null
                                    && $callee->getNativeCallable() === null
                                    && !$callee->isAsync()
                                    && !$callee->isGenerator();
                                // Only memoise positive results; a negative on
                                // first call could later become positive once
                                // the lazy compiler populates $compiled.
                                if ($eligible) {
                                    $callee->vmDirectDispatchCache = true;
                                }
                            }
                            // Top-tier fast path: callee has a JsToPhp-compiled
                            // PHP closure. Invoke it directly, skipping
                            // executeVmFunctionDirect's prologue (callStack /
                            // callerStack push, frame setup, teardown). The
                            // closure body's compile-time numeric proof made
                            // all that bookkeeping unnecessary; runtime
                            // bailouts (non-numeric arg) throw a Bailout that
                            // we catch and retry through the normal direct /
                            // slow path.
                            if (
                                $callee->phpCompiled !== null
                                && !$callee->isClassConstructor()
                                && !$callee->isDerivedConstructor()
                                && $callee->getHomeObject() === null
                            ) {
                                try {
                                    $stack[$sp++] = ($callee->phpCompiled)(
                                        $args,
                                        $callee->getClosure(),
                                        $this->interp,
                                        $callee->phpCompiledNodes,
                                    );
                                    $pc += 2;
                                    break;
                                } catch (\PhpJs\Bytecode\Bailout) {
                                    // Fall through to executeVmFunctionDirect /
                                    // callFunction so the call still completes.
                                }
                            }
                            if ($eligible) {
                                $stack[$sp++] = $this->interp->executeVmFunctionDirect(
                                    $callee,
                                    $undef,
                                    $args,
                                );
                            } else {
                                $stack[$sp++] = $this->interp->callFunction(
                                    $callee,
                                    $undef,
                                    $args,
                                );
                            }
                            $pc += 2;
                            break;

                        case Op::NEW_OBJECT:
                            $stack[$sp++] = $this->interp->vmNewObject();
                            $pc++;
                            break;
                        case Op::NEW_ARRAY:
                            $count = $code[$pc + 1];
                            $base = $sp - $count;
                            $items = $count === 0 ? [] : array_slice($stack, $base, $count);
                            $sp = $base;
                            $stack[$sp++] = \PhpJs\Value\JsArray::fromArray($items);
                            $pc += 2;
                            break;
                        case Op::SET_PROP:
                            // Stack: [obj, val] -> [obj]; effect: obj.name = val.
                            $val = $stack[--$sp];
                            $obj = $stack[$sp - 1]; // peek
                            $name = $names[$code[$pc + 1]];
                            if ($obj instanceof JsObject) {
                                $obj->defineOwnDataPropertyFast($name, $val);
                            }
                            $pc += 2;
                            break;
                        case Op::SET_COMPUTED:
                            // Stack: [obj, key, val] -> [obj].
                            $val = $stack[--$sp];
                            $key = $stack[--$sp];
                            $obj = $stack[$sp - 1];
                            if ($obj instanceof JsObject) {
                                $resolved = TypeConversion::toPropertyKey($key);
                                if ($resolved instanceof \PhpJs\Value\JsSymbol) {
                                    $obj->setBySymbol($resolved, $val);
                                } else {
                                    $name = $resolved instanceof JsString
                                        ? $resolved->value
                                        : TypeConversion::toString($resolved);
                                    $obj->defineOwnDataPropertyFast($name, $val);
                                }
                            }
                            $pc++;
                            break;
                        case Op::NEW_CALL:
                            $argc = $code[$pc + 1];
                            $base = $sp - $argc;
                            $args = $argc === 0 ? [] : array_slice($stack, $base, $argc);
                            $callee = $stack[$base - 1];
                            $sp = $base - 1;
                            $stack[$sp++] = $this->interp->vmNewExpression($callee, $args, $env);
                            $pc += 2;
                            break;

                        case Op::CALL_METHOD:
                            // Stack: [..., obj, method, arg0, ..., argN-1]
                            // The method was already loaded (LOAD_MEMBER) so
                            // any TypeError for a null/undefined receiver
                            // already surfaced before any argument was eval'd.
                            $argc = $code[$pc + 1];
                            $base = $sp - $argc;
                            $args = $argc === 0 ? [] : array_slice($stack, $base, $argc);
                            $method = $stack[$base - 1];
                            $receiver = $stack[$base - 2];
                            $sp = $base - 2;
                            if (
                                $method instanceof \PhpJs\Value\JsProxy
                                && $method->isCallable()
                            ) {
                                $stack[$sp++] = $method->apply($receiver, $args);
                                $pc += 2;
                                break;
                            }
                            if (!$method instanceof JsFunction) {
                                throw new TypeError(
                                    (TypeConversion::toString($method) ?: 'value') . ' is not a function'
                                );
                            }
                            // Inline path for tagged hot built-ins: when the
                            // receiver shape matches what the built-in's spec
                            // path simplifies to (plain JsArray for array
                            // methods), do the operation directly and skip the
                            // entire callFunction → native dispatch chain. The
                            // marker is set at engine init when the built-in
                            // is installed; absence falls through to the spec
                            // path so semantics never diverge.
                            if (
                                $method->builtinKind === 'array.push'
                                && $receiver instanceof \PhpJs\Value\JsArray
                                && $argc === 1
                            ) {
                                $receiver->push($args[0]);
                                $stack[$sp++] = JsNumber::of((float) $receiver->getLength());
                                $pc += 2;
                                break;
                            }
                            $kind = $method->builtinKind;
                            // JSON.parse / JSON.stringify dispatch goes
                            // straight to the native callable, skipping
                            // callFunction's tail-call trampoline +
                            // class-constructor guard. These are static
                            // built-in helpers, never callable as
                            // constructors, so the bypass is safe.
                            if (
                                $kind === 'json.parse'
                                || $kind === 'json.stringify'
                            ) {
                                $native = $method->getNativeCallable();
                                if ($native !== null) {
                                    $stack[$sp++] = $native(
                                        $receiver,
                                        $args,
                                        $this->interp,
                                    );
                                    $pc += 2;
                                    break;
                                }
                            }
                            if (
                                $kind !== null
                                && $receiver instanceof \PhpJs\Value\JsArray
                                && $receiver->isDenseMode()
                            ) {
                                $inlined = $this->inlineDenseArrayMethod(
                                    $kind,
                                    $receiver,
                                    $args,
                                    $argc,
                                );
                                if ($inlined !== null) {
                                    $stack[$sp++] = $inlined;
                                    $pc += 2;
                                    break;
                                }
                            }
                            $stack[$sp++] = $this->interp->callFunction(
                                $method,
                                $receiver,
                                $args,
                            );
                            $pc += 2;
                            break;

                        case Op::LOAD_MEMBER:
                            $obj = $stack[--$sp];
                            $name = $names[$code[$pc + 1]];
                            // Fast path: own default-attr data slot on a plain
                            // JsObject. Direct array hit on the receiver's
                            // dataSlots skips lookupMember -> JsObject::get ->
                            // dataSlots, three layers of method dispatch.
                            if (
                                $obj instanceof JsObject
                                && isset($obj->properties->dataSlots[$name])
                            ) {
                                $stack[$sp++] = $obj->properties->dataSlots[$name];
                            } else {
                                $stack[$sp++] = $this->lookupMember($obj, $name);
                            }
                            $pc += 2;
                            break;
                        case Op::LOAD_COMPUTED:
                            $key = $stack[--$sp];
                            $obj = $stack[--$sp];
                            $stack[$sp++] = $this->lookupComputed($obj, $key);
                            $pc++;
                            break;
                        case Op::STORE_MEMBER:
                            $val = $stack[--$sp];
                            $obj = $stack[--$sp];
                            $name = $names[$code[$pc + 1]];
                            // Fast path: assigning to an existing own default-
                            // attr data slot. Per OrdinarySetWithOwnDescriptor
                            // an own writable data property wins over any
                            // prototype-chain accessor or readonly slot, so a
                            // direct in-place write is spec-correct here. The
                            // slow path handles non-extensible / accessor
                            // setter / readonly proto / strict-mode error cases.
                            if (
                                $obj instanceof JsObject
                                && isset($obj->properties->dataSlots[$name])
                            ) {
                                $obj->properties->dataSlots[$name] = $val;
                            } else {
                                $this->writeMember($obj, $name, $val);
                            }
                            $stack[$sp++] = $val;
                            $pc += 2;
                            break;
                        case Op::STORE_COMPUTED:
                            $val = $stack[--$sp];
                            $key = $stack[--$sp];
                            $obj = $stack[--$sp];
                            $this->writeComputed($obj, $key, $val);
                            $stack[$sp++] = $val;
                            $pc++;
                            break;

                        case Op::MAKE_FUNCTION:
                            $stack[$sp++] = $this->interp->vmMakeFunction(
                                $nestedFns[$code[$pc + 1]],
                                $env,
                            );
                            $pc += 2;
                            break;

                        case Op::MAKE_CLASS:
                            $stack[$sp++] = $this->interp->vmMakeClass(
                                $cf->classNodes[$code[$pc + 1]],
                                $env,
                            );
                            $pc += 2;
                            break;

                        case Op::THROW:
                            $val = $stack[--$sp];
                            $this->interp->throwJsValue($val);
                            // throwJsValue always throws; the no-fallthrough is
                            // intentional and PSR-2 wants this comment to make
                            // the missing break explicit.
                            // no break

                        case Op::RET:
                            return $stack[--$sp];

                        default:
                            throw new InternalError('VM: unknown opcode ' . $op);
                    }
                } // end inner while (dispatch loop)
            } catch (\PhpJs\Exceptions\RuntimeError $e) {
                // Look for a handler whose protected range covers the
                // current PC (the PC of the instruction that threw or
                // raised the exception synchronously).
                if (!$hasHandlers) {
                    throw $e;
                }
                $matched = null;
                foreach ($cf->handlers as $h) {
                    if ($pc >= $h->tryStart && $pc < $h->tryEnd) {
                        $matched = $h;
                        break;
                    }
                }
                if ($matched === null) {
                    throw $e;
                }
                // Convert the PHP exception to a JsValue for the catch
                // parameter. JsThrowable wraps a user-thrown JS value;
                // anything else (TypeError, RangeError, etc.) is a
                // host-level failure that the spec models as a JS
                // Error subclass.
                $jsValue = $e instanceof \PhpJs\Exceptions\JsThrowable
                    ? $e->jsValue
                    : $this->interp->phpExceptionToJsValue($e);
                if ($matched->exceptionSlot >= 0) {
                    $locals[$matched->exceptionSlot] = $jsValue;
                }
                $sp = $matched->stackBase;
                $pc = $matched->catchPc;
                // Fall through to outer while: re-enter the try and
                // resume dispatch at the catch entry.
            }
        } // end outer while (try-catch loop)
    }
}
