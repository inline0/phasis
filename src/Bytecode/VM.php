<?php

declare(strict_types=1);

namespace Phasis\Bytecode;

use Phasis\Exceptions\InternalError;
use Phasis\Exceptions\TypeError;
use Phasis\Runtime\Interpreter;
use Phasis\Spec\AbstractOperations;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

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
            if ($primSlot !== null && $primSlot->value instanceof \Phasis\Value\JsSymbol) {
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
        if ($resolved instanceof \Phasis\Value\JsSymbol) {
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
        if ($resolved instanceof \Phasis\Value\JsSymbol) {
            if ($obj instanceof JsObject) {
                $ok = $obj->internalSetBySymbol($resolved, $value, $obj);
                if (!$ok && $this->interp->isStrictMode()) {
                    throw new TypeError(
                        "Cannot assign to read only property 'Symbol(" .
                        ($resolved->getDescription() ?? '') . ")' of object",
                    );
                }
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
        \Phasis\Value\JsArray $receiver,
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

            case 'array.pop':
                if ($argc !== 0) {
                    return null;
                }
                // Bail when the length descriptor is non-writable
                // (Object.freeze / preventExtensions / explicit
                // defineProperty). Spec path then surfaces the
                // TypeError that ours would silently skip.
                if (!$receiver->isLengthWritable()) {
                    return null;
                }
                $len = $receiver->getLength();
                if ($len === 0) {
                    return JsUndefined::instance();
                }
                $elements = $receiver->getDenseElements();
                $last = $elements[$len - 1] ?? null;
                if ($last === null) {
                    return null;
                }
                // Spec uses DeletePropertyOrThrow + Set(length). On a
                // dense JsArray with default attributes, both reduce
                // to setLength(N-1) which drops the trailing dense
                // entry.
                $receiver->setLength($len - 1);
                return $last;

            case 'array.indexOf':
                if ($argc < 1) {
                    return null;
                }
                $search = $args[0];
                $len = $receiver->getLength();
                $start = 0;
                if ($argc >= 2) {
                    $startArg = $args[1];
                    if (!$startArg instanceof JsNumber) {
                        return null;
                    }
                    $sv = $startArg->value;
                    // ToIntegerOrInfinity: +Infinity -> return -1 (range
                    // [Infinity, len) is empty); -Infinity / NaN -> 0.
                    if (is_nan($sv) || $sv === -INF) {
                        $start = 0;
                    } elseif ($sv === INF) {
                        return JsNumber::of(-1.0);
                    } else {
                        $startN = (int) $sv;
                        if ($startN < 0) {
                            $startN = max($len + $startN, 0);
                        }
                        $start = $startN;
                    }
                }
                $elements = $receiver->getDenseElements();
                for ($i = $start; $i < $len; $i++) {
                    $el = $elements[$i] ?? null;
                    if ($el === null) {
                        return null;
                    }
                    if (AbstractOperations::strictEquals($el, $search)) {
                        return JsNumber::of((float) $i);
                    }
                }
                return JsNumber::of(-1.0);

            case 'array.slice':
                // 0/1/2 args, all optional numeric. Bail if a custom
                // 'constructor' override is present so spec
                // ArraySpeciesCreate runs.
                if ($argc > 2) {
                    return null;
                }
                if ($receiver->getOwnPropertyDescriptor('constructor') !== null) {
                    return null;
                }
                $len = $receiver->getLength();
                $startN = 0;
                $endN = $len;
                if ($argc >= 1) {
                    $startArg = $args[0];
                    if ($startArg instanceof JsUndefined) {
                        // start defaults to 0; nothing to do.
                    } elseif ($startArg instanceof JsNumber) {
                        // Spec: ToIntegerOrInfinity. +Infinity clamps
                        // to length, -Infinity clamps to 0; NaN -> 0.
                        $sv = $startArg->value;
                        if (is_nan($sv)) {
                            $startN = 0;
                        } elseif ($sv === INF) {
                            $startN = $len;
                        } elseif ($sv === -INF) {
                            $startN = 0;
                        } else {
                            $si = (int) $sv;
                            $startN = $si < 0 ? max($len + $si, 0) : min($si, $len);
                        }
                    } else {
                        return null;
                    }
                }
                if ($argc >= 2) {
                    $endArg = $args[1];
                    if ($endArg instanceof JsUndefined) {
                        $endN = $len;
                    } elseif ($endArg instanceof JsNumber) {
                        $ev = $endArg->value;
                        if (is_nan($ev)) {
                            $endN = 0;
                        } elseif ($ev === INF) {
                            $endN = $len;
                        } elseif ($ev === -INF) {
                            $endN = 0;
                        } else {
                            $ei = (int) $ev;
                            $endN = $ei < 0 ? max($len + $ei, 0) : min($ei, $len);
                        }
                    } else {
                        return null;
                    }
                }
                $elements = $receiver->getDenseElements();
                $result = new \Phasis\Value\JsArray();
                for ($i = $startN; $i < $endN; $i++) {
                    $el = $elements[$i] ?? null;
                    if ($el === null) {
                        // Hole: spec preserves it; the new array shouldn't
                        // claim a value here. Bail to spec path so the
                        // hole-vs-undefined distinction is preserved.
                        return null;
                    }
                    $result->push($el);
                }
                return $result;

            case 'array.includes':
                if ($argc < 1) {
                    return null;
                }
                $search = $args[0];
                $len = $receiver->getLength();
                $start = 0;
                if ($argc >= 2) {
                    $startArg = $args[1];
                    if (!$startArg instanceof JsNumber) {
                        return null;
                    }
                    $sv = $startArg->value;
                    // ToIntegerOrInfinity: +Infinity skips the search
                    // (k >= len); -Infinity / NaN -> 0.
                    if (is_nan($sv) || $sv === -INF) {
                        $start = 0;
                    } elseif ($sv === INF) {
                        return new JsBoolean(false);
                    } else {
                        $startN = (int) $sv;
                        if ($startN < 0) {
                            $startN = max($len + $startN, 0);
                        }
                        $start = $startN;
                    }
                }
                $elements = $receiver->getDenseElements();
                for ($i = $start; $i < $len; $i++) {
                    $el = $elements[$i] ?? null;
                    if ($el === null) {
                        return null;
                    }
                    if (AbstractOperations::sameValueZero($el, $search)) {
                        return new JsBoolean(true);
                    }
                }
                return new JsBoolean(false);

            case 'array.join':
                // Single-arg ASCII string join over a dense JsArray
                // whose elements are all JsStrings. PHP's implode
                // produces the spec result without per-element
                // ToString invocations.
                if ($argc > 1) {
                    return null;
                }
                if ($argc === 1) {
                    $sepArg = $args[0];
                    if (!$sepArg instanceof JsString) {
                        return null;
                    }
                    $sep = $sepArg->value;
                } else {
                    $sep = ',';
                }
                $len = $receiver->getLength();
                $elements = $receiver->getDenseElements();
                $parts = [];
                for ($i = 0; $i < $len; $i++) {
                    $el = $elements[$i] ?? null;
                    if ($el instanceof JsString) {
                        $parts[] = $el->value;
                        continue;
                    }
                    if ($el === null || $el instanceof JsUndefined || $el instanceof JsNull) {
                        $parts[] = '';
                        continue;
                    }
                    // Non-string element would need spec ToString
                    // (which can have side effects). Bail to spec
                    // path so toString / valueOf hooks fire.
                    return null;
                }
                return new JsString(implode($sep, $parts));

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
                    ? $args[1] : JsUndefined::instance();
                $len = $receiver->getLength();
                $elements = $receiver->getDenseElements();
                $result = new \Phasis\Value\JsArray();
                // The callback's phpCompiled / phpCompiledNumeric may
                // be null on first iter and populate on the first call
                // (lazy compile inside callFunction). Read live each
                // iter so subsequent iters pick up the cached entries.
                $maxParams = count($callback->getParams());
                $numericFits = $maxParams <= 2;
                for ($i = 0; $i < $len; $i++) {
                    $val = $elements[$i] ?? null;
                    if ($val === null) {
                        return null;
                    }
                    $numeric = $callback->phpCompiledNumeric;
                    if ($numeric !== null && $numericFits && $val instanceof JsNumber) {
                        try {
                            $rawResult = $numeric(
                                [$val->value, (float) $i],
                                $callback->closure,
                                $this->interp,
                                $callback->phpCompiledNodes,
                            );
                            $result->push(JsNumber::of($rawResult));
                            continue;
                        } catch (\Phasis\Bytecode\Bailout) {
                            // Fall through to the standard / spec path.
                        }
                    }
                    $compiled = $callback->phpCompiled;
                    $idx = JsNumber::of((float) $i);
                    if ($compiled !== null) {
                        try {
                            $mapped = $compiled(
                                [$val, $idx, $receiver],
                                $callback->closure,
                                $this->interp,
                                $callback->phpCompiledNodes,
                            );
                        } catch (\Phasis\Bytecode\Bailout) {
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
                $numericFits = count($callback->getParams()) <= 2;
                $accIsNumber = $acc instanceof JsNumber;
                for ($i = $start; $i < $len; $i++) {
                    $val = $elements[$i] ?? null;
                    if ($val === null) {
                        return null;
                    }
                    $numeric = $callback->phpCompiledNumeric;
                    if ($numeric !== null && $numericFits && $accIsNumber && $val instanceof JsNumber) {
                        try {
                            $rawAcc = $numeric(
                                [$acc->value, $val->value],
                                $callback->closure,
                                $this->interp,
                                $callback->phpCompiledNodes,
                            );
                            $acc = JsNumber::of($rawAcc);
                            continue;
                        } catch (\Phasis\Bytecode\Bailout) {
                            // Fall through to the standard / spec path
                            // for this iteration.
                        }
                    }
                    $compiled = $callback->phpCompiled;
                    $idx = JsNumber::of((float) $i);
                    if ($compiled !== null) {
                        try {
                            $acc = $compiled(
                                [$acc, $val, $idx, $receiver],
                                $callback->closure,
                                $this->interp,
                                $callback->phpCompiledNodes,
                            );
                            $accIsNumber = $acc instanceof JsNumber;
                            continue;
                        } catch (\Phasis\Bytecode\Bailout) {
                        }
                    }
                    $acc = $this->interp->callFunction(
                        $callback,
                        $undef,
                        [$acc, $val, $idx, $receiver],
                    );
                    $accIsNumber = $acc instanceof JsNumber;
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
                        } catch (\Phasis\Bytecode\Bailout) {
                        }
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
                $result = new \Phasis\Value\JsArray();
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
                        } catch (\Phasis\Bytecode\Bailout) {
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

    /**
     * String.prototype.split inline: receiver is a JsString, single
     * non-empty JsString separator. Lowers to PHP's native explode +
     * JsString wrapping per part. Spec features that this path skips
     * (regex separators, Symbol.split, surrogate handling) trigger a
     * null return so the spec body runs.
     *
     * Lives outside the CALL_METHOD switch so the dispatch-loop body
     * stays compact enough for PHP 8.5 tracing JIT (otherwise trace
     * length exceeds jit_max_trace_length and the entire VM execute
     * runs uncompiled).
     *
     * @param list<JsValue> $args
     */
    private function inlineStringSplit(JsString $receiver, array $args, int $argc): ?JsValue
    {
        if ($argc !== 1) {
            return null;
        }
        $sepArg = $args[0];
        if (!$sepArg instanceof JsString) {
            return null;
        }
        if ($sepArg->value === '') {
            return null;
        }
        $parts = explode($sepArg->value, $receiver->value);
        $arr = new \Phasis\Value\JsArray();
        foreach ($parts as $p) {
            $arr->push(new JsString($p));
        }
        return $arr;
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

        // === Custom callstack ====================================
        //
        // When `Op::CALL` / `Op::CALL_METHOD` lands on an inline-
        // eligible BC-compiled callee, the VM SAVES the caller's
        // frame state onto `$savedFrames`, swaps in the callee's
        // state, and keeps dispatching. `Op::RET` pops one saved
        // frame and resumes the caller; exceptions walk
        // `$savedFrames` looking for a handler in any parent frame.
        //
        // This eliminates the recursive PHP `vm->execute` call for
        // the most common pure-JS-calls-pure-JS shape; PHP's stack
        // ceiling no longer bounds JS recursion depth on this path.
        //
        // The save format is a `SavedFrame` class with individually
        // typed public fields, pooled per execute() invocation so a
        // long-running script pays one allocation per max-reached
        // depth instead of one per call. PHP 8.5's tracing JIT
        // specializes the field read/write significantly better
        // than the equivalent mixed-array push/destructure.
        //
        // @var list<SavedFrame>
        $savedFrames = [];
        $savedFramesDepth = 0;

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
                            if ($ic !== null && $ic[1] === \Phasis\Runtime\Environment::$globalBindingsVersion) {
                                $stack[$sp++] = $ic[0];
                            } else {
                                $resolved = $env->get($names[$code[$pc + 1]], $strict);
                                $cf->loadNameIc[$pc] = [$resolved, \Phasis\Runtime\Environment::$globalBindingsVersion];
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
                            } elseif ($l instanceof JsString && $r instanceof JsString) {
                                $stack[$sp++] = $l->value === $r->value ? $true : $false;
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
                            } elseif ($l instanceof JsString && $r instanceof JsString) {
                                $stack[$sp++] = $l->value === $r->value ? $false : $true;
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
                        case Op::BNOT:
                            // ~x per §13.5.6.1: ToNumeric, then ~ToInt32 for
                            // Number / bigInt bitwise-not for BigInt. Boxed
                            // wrappers (new Boolean(true)) are unboxed by
                            // ToNumeric/ToInt32. Match tree-walker semantics.
                            $v = $stack[--$sp];
                            $numeric = TypeConversion::toNumeric($v);
                            if ($numeric instanceof \Phasis\Value\JsBigInt) {
                                $stack[$sp++] = new \Phasis\Value\JsBigInt(
                                    Interpreter::bigIntBitwiseNotPublic($numeric->value)
                                );
                            } else {
                                $stack[$sp++] = JsNumber::of((float) (~TypeConversion::toInt32($numeric)));
                            }
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
                        case Op::TO_STRING:
                            $v = $stack[--$sp];
                            if ($v instanceof JsString) {
                                $stack[$sp++] = $v;
                            } else {
                                $stack[$sp++] = new JsString(TypeConversion::toString($v));
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
                        case Op::JUMP_IF_LOCAL_GE_LOCAL:
                            // Fused `for (i = a; i < j; i++)`. Body runs while
                            // i < j. Jump (exit) when i >= j.
                            $a = $locals[$code[$pc + 1]];
                            $b = $locals[$code[$pc + 2]];
                            if ($a instanceof JsNumber && $b instanceof JsNumber) {
                                if (!($a->value < $b->value)) {
                                    $pc += $code[$pc + 3];
                                } else {
                                    $pc += 4;
                                }
                            } else {
                                $rel = AbstractOperations::abstractRelational($a, $b, true);
                                if ($rel !== true) {
                                    $pc += $code[$pc + 3];
                                } else {
                                    $pc += 4;
                                }
                            }
                            break;
                        case Op::JUMP_IF_LOCAL_GT_LOCAL:
                            // Fused `for (i = a; i <= j; i++)`. Body runs while
                            // i <= j. Jump (exit) when i > j.
                            $a = $locals[$code[$pc + 1]];
                            $b = $locals[$code[$pc + 2]];
                            if ($a instanceof JsNumber && $b instanceof JsNumber) {
                                if ($a->value > $b->value) {
                                    $pc += $code[$pc + 3];
                                } else {
                                    $pc += 4;
                                }
                            } else {
                                // Per spec, `a <= b` is the negation of `b < a`
                                // when neither side is NaN; if either is NaN,
                                // the result is false (don't continue, jump).
                                $rel = AbstractOperations::abstractRelational($b, $a, false);
                                if ($rel === false) {
                                    $pc += 4;
                                } else {
                                    $pc += $code[$pc + 3];
                                }
                            }
                            break;
                        case Op::JUMP_IF_LOCAL_GT_CONST:
                            // Fused `for (i = a; i <= N; i++)`. Same shape as
                            // JUMP_IF_LOCAL_GT_LOCAL with the bound interned
                            // as a constant.
                            $a = $locals[$code[$pc + 1]];
                            $b = $consts[$code[$pc + 2]];
                            if ($a instanceof JsNumber && $b instanceof JsNumber) {
                                if ($a->value > $b->value) {
                                    $pc += $code[$pc + 3];
                                } else {
                                    $pc += 4;
                                }
                            } else {
                                $rel = AbstractOperations::abstractRelational($b, $a, false);
                                if ($rel === false) {
                                    $pc += 4;
                                } else {
                                    $pc += $code[$pc + 3];
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
                                // Callable Proxy: dispatch through its
                                // [[Call]] (apply trap) so a Proxy whose
                                // target is a function (including a
                                // ShadowRealm WrappedFunction) can be
                                // invoked from a non-method call site.
                                // Without this branch, identifier-form
                                // calls like `proxy(arg)` reach the
                                // generic "is not a function" throw even
                                // though the proxy advertises typeof
                                // "function". CALL_METHOD already has
                                // the analogous proxy branch.
                                if (
                                    $callee instanceof \Phasis\Value\JsProxy
                                    && $callee->isCallable()
                                ) {
                                    $stack[$sp++] = $callee->apply($undef, $args);
                                    $pc += 2;
                                    break;
                                }
                                throw new TypeError(
                                    (TypeConversion::toString($callee) ?: 'value') . ' is not a function'
                                );
                            }
                            // Fast path for the global URI built-ins (encodeURI,
                            // decodeURI, encodeURIComponent, decodeURIComponent).
                            // These are single-realm, never callable as
                            // constructors, never generators / async, and have
                            // no tail-call semantics. The Sputnik UTF-8 sweep
                            // tests fire ~1M decodeURI calls each, so skipping
                            // the callFunction realm switch, trampoline, and
                            // callFunctionInner class-ctor guard drops the
                            // per-call PHP cost to a single closure invocation.
                            $kind = $callee->builtinKind;
                            if (
                                $kind !== null
                                && $kind[0] === 'g'
                                && (
                                    $kind === 'global.decodeURI'
                                    || $kind === 'global.decodeURIComponent'
                                    || $kind === 'global.encodeURI'
                                    || $kind === 'global.encodeURIComponent'
                                )
                            ) {
                                $native = $callee->getNativeCallable();
                                if ($native !== null) {
                                    $stack[$sp++] = $native($undef, $args, $this->interp);
                                    $pc += 2;
                                    break;
                                }
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
                                    // Compute the result first, push only after
                                    // the call returns. PHP evaluates `$stack[$sp++]`'s
                                    // post-increment before the rhs, so a Bailout
                                    // mid-call would leave $sp advanced with no
                                    // value written — the fallback path below
                                    // would then push at the wrong slot and
                                    // future stack reads would observe stale data.
                                    $result = ($callee->phpCompiled)(
                                        $args,
                                        $callee->closure,
                                        $this->interp,
                                        $callee->phpCompiledNodes,
                                    );
                                    $stack[$sp++] = $result;
                                    $pc += 2;
                                    break;
                                } catch (\Phasis\Bytecode\Bailout) {
                                    // Fall through to executeVmFunctionDirect /
                                    // callFunction so the call still completes.
                                }
                            }
                            // Custom callstack: any eligible BC-compiled
                            // callee that isn't a bound function.
                            // setupInlineVmCall handles the Annex B
                            // caller/arguments magic + sloppy this-
                            // binding adjustment uniformly across
                            // strict / arrow / sloppy callees.
                            $inlineable = $callee->vmInlineCallCache;
                            if ($inlineable === null) {
                                $inlineable = $eligible && $callee->getBoundTarget() === null;
                                if ($inlineable) {
                                    $callee->vmInlineCallCache = true;
                                }
                            }
                            if ($inlineable) {
                                $newCf = $callee->compiled;
                                $restore = $this->interp->setupInlineVmCall($callee, $undef);
                                $paramSlots = $newCf->paramSlots;
                                $paramCount = count($paramSlots);
                                $newFrame = $this->interp->borrowInlineVmFrame(
                                    $callee->closure,
                                    $restore[0],
                                    $newCf->slotCount,
                                    $paramCount,
                                    $undef,
                                );
                                $argCount = count($args);
                                for ($i = 0; $i < $paramCount; $i++) {
                                    $newFrame->locals[$paramSlots[$i]] =
                                        $i < $argCount ? $args[$i] : $undef;
                                }
                                // Pool a SavedFrame: typed fields the
                                // JIT can specialize; one alloc per
                                // max-reached depth.
                                if ($savedFramesDepth < count($savedFrames)) {
                                    $sf = $savedFrames[$savedFramesDepth];
                                } else {
                                    $sf = new SavedFrame();
                                    $savedFrames[] = $sf;
                                }
                                $sf->cf = $cf;
                                $sf->code = $code;
                                $sf->consts = $consts;
                                $sf->names = $names;
                                $sf->nestedFns = $nestedFns;
                                $sf->stack = $stack;
                                $sf->sp = $sp;
                                $sf->locals = $locals;
                                $sf->env = $env;
                                $sf->thisValue = $thisValue;
                                $sf->strict = $strict;
                                $sf->hasHandlers = $hasHandlers;
                                $sf->returnPc = $pc + 2;
                                $sf->restore = $restore;
                                $sf->callee = $callee;
                                $savedFramesDepth++;
                                // Switch to callee's state.
                                $cf = $newCf;
                                $code = $newCf->code;
                                $consts = $newCf->consts;
                                $names = $newCf->names;
                                $nestedFns = $newCf->nestedFns;
                                $hasHandlers = $newCf->handlers !== [];
                                $stack = $newFrame->stack;
                                $sp = $newFrame->sp;
                                $locals = $newFrame->locals;
                                $env = $newFrame->env;
                                $thisValue = $newFrame->thisValue;
                                $strict = $this->interp->isStrictMode();
                                $pc = 0;
                                break;
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
                            $stack[$sp++] = \Phasis\Value\JsArray::fromArray($items);
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
                                if ($resolved instanceof \Phasis\Value\JsSymbol) {
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
                            // Fast path for `new Date(<number>)`. The SM
                            // dst-offset-caching stress harness builds
                            // millions of throwaway Date objects per
                            // fraction; routing them through the full
                            // [[NewTarget]] + callFunction trampoline
                            // dominated the inner-loop cost. The helper
                            // returns null when the shape does not
                            // match, so subclass / string / multi-arg
                            // construction still flows through the
                            // canonical vmNewExpression path.
                            if (
                                $callee instanceof JsFunction
                                && $callee->builtinKind === 'date.construct'
                            ) {
                                $fastDate = \Phasis\BuiltIn\DateConstructor::vmFastNewDate($callee, $args);
                                if ($fastDate !== null) {
                                    $stack[$sp++] = $fastDate;
                                    $pc += 2;
                                    break;
                                }
                            }
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
                                $method instanceof \Phasis\Value\JsProxy
                                && $method->isCallable()
                            ) {
                                $stack[$sp++] = $method->apply($receiver, $args);
                                $pc += 2;
                                break;
                            }
                            if (!$method instanceof JsFunction) {
                                // Prefer the compiler-rendered call-site
                                // source (e.g. `[].__proto__`) so the
                                // TypeError matches V8 / SpiderMonkey
                                // wording. Fall back to the value's
                                // toString and finally a generic 'value'
                                // when no display was recorded.
                                $display = $cf->callMethodDisplays[$pc] ?? null;
                                if ($display === null) {
                                    $display = TypeConversion::toString($method) ?: 'value';
                                }
                                throw new TypeError($display . ' is not a function');
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
                                && $receiver instanceof \Phasis\Value\JsArray
                                && $argc === 1
                                && $receiver->isLengthWritable()
                                && $receiver->isExtensible()
                            ) {
                                // Skip the inline if Array.prototype has an own
                                // accessor / data property at the index we're
                                // about to write — spec Set walks the proto
                                // chain and may invoke a setter (which can
                                // freeze the receiver, throw, etc.). Common
                                // case: Array.prototype has no own indexed
                                // properties → fast path runs.
                                $proto = $receiver->getPrototype();
                                $indexKey = (string) $receiver->getLength();
                                if (
                                    $proto === null
                                    || $proto->getOwnPropertyDescriptor($indexKey) === null
                                ) {
                                    $receiver->push($args[0]);
                                    $stack[$sp++] = JsNumber::of((float) $receiver->getLength());
                                    $pc += 2;
                                    break;
                                }
                            }
                            $kind = $method->builtinKind;
                            // Date.prototype hot paths used by the SM
                            // dst-offset-caching stress harness. Both
                            // helpers return null when the receiver
                            // shape does not match, in which case the
                            // dispatch falls through to the normal
                            // callFunction path.
                            if ($kind !== null && $kind[0] === 'd' && str_starts_with($kind, 'date.')) {
                                if ($kind === 'date.getTimezoneOffset') {
                                    $fast = \Phasis\BuiltIn\DateConstructor::vmFastDateGetTimezoneOffset($receiver);
                                    if ($fast !== null) {
                                        $stack[$sp++] = $fast;
                                        $pc += 2;
                                        break;
                                    }
                                } elseif ($kind === 'date.setTime') {
                                    $fast = \Phasis\BuiltIn\DateConstructor::vmFastDateSetTime($receiver, $args);
                                    if ($fast !== null) {
                                        $stack[$sp++] = $fast;
                                        $pc += 2;
                                        break;
                                    }
                                }
                            }
                            // String.prototype.split inline lives in
                            // a helper so the dispatch-loop body stays
                            // small enough for PHP 8.5's tracing JIT
                            // to compile (otherwise the trace exceeds
                            // jit_max_trace_length and the entire
                            // VM->execute() runs uncompiled — way
                            // worse than the spec path).
                            if ($kind === 'string.split' && $receiver instanceof JsString) {
                                $inlined = $this->inlineStringSplit($receiver, $args, $argc);
                                if ($inlined !== null) {
                                    $stack[$sp++] = $inlined;
                                    $pc += 2;
                                    break;
                                }
                            }
                            // JSON.parse / JSON.stringify / String.
                            // fromCharCode dispatch goes straight to the
                            // native callable, skipping callFunction's
                            // tail-call trampoline + class-constructor
                            // guard. These are static built-in helpers,
                            // never callable as constructors, so the
                            // bypass is safe. fromCharCode is on this
                            // path because Sputnik's UTF-8 decode sweep
                            // (S15.1.3.1_A2.5_T1) fires ~1M calls per
                            // run inside its hot comparison loop.
                            if (
                                $kind === 'json.parse'
                                || $kind === 'json.stringify'
                                || $kind === 'string.fromCharCode'
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
                                && $receiver instanceof \Phasis\Value\JsArray
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
                            // Stage 4 custom callstack: extend the
                            // inline-call path to method calls too.
                            // Same eligibility predicate Op::CALL
                            // uses; receiver becomes the new this
                            // binding (subject to sloppy-mode
                            // adjustment inside setupInlineVmCall).
                            $methodEligible = $method->vmDirectDispatchCache;
                            if ($methodEligible === null) {
                                $methodEligible = $method->compiled !== null
                                    && $method->compiled->canSkipEnvAlloc
                                    && !$method->isClassConstructor()
                                    && !$method->isDerivedConstructor()
                                    && $method->getHomeObject() === null
                                    && $method->getNativeCallable() === null
                                    && !$method->isAsync()
                                    && !$method->isGenerator();
                                if ($methodEligible) {
                                    $method->vmDirectDispatchCache = true;
                                }
                            }
                            $methodInlineable = $method->vmInlineCallCache;
                            if ($methodInlineable === null) {
                                $methodInlineable = $methodEligible
                                    && $method->getBoundTarget() === null;
                                if ($methodInlineable) {
                                    $method->vmInlineCallCache = true;
                                }
                            }
                            if ($methodInlineable) {
                                $newCf = $method->compiled;
                                $restore = $this->interp->setupInlineVmCall($method, $receiver);
                                $paramSlots = $newCf->paramSlots;
                                $paramCount = count($paramSlots);
                                $newFrame = $this->interp->borrowInlineVmFrame(
                                    $method->closure,
                                    $restore[0],
                                    $newCf->slotCount,
                                    $paramCount,
                                    $undef,
                                );
                                $argCount = count($args);
                                for ($i = 0; $i < $paramCount; $i++) {
                                    $newFrame->locals[$paramSlots[$i]] =
                                        $i < $argCount ? $args[$i] : $undef;
                                }
                                if ($savedFramesDepth < count($savedFrames)) {
                                    $sf = $savedFrames[$savedFramesDepth];
                                } else {
                                    $sf = new SavedFrame();
                                    $savedFrames[] = $sf;
                                }
                                $sf->cf = $cf;
                                $sf->code = $code;
                                $sf->consts = $consts;
                                $sf->names = $names;
                                $sf->nestedFns = $nestedFns;
                                $sf->stack = $stack;
                                $sf->sp = $sp;
                                $sf->locals = $locals;
                                $sf->env = $env;
                                $sf->thisValue = $thisValue;
                                $sf->strict = $strict;
                                $sf->hasHandlers = $hasHandlers;
                                $sf->returnPc = $pc + 2;
                                $sf->restore = $restore;
                                $sf->callee = $method;
                                $savedFramesDepth++;
                                $cf = $newCf;
                                $code = $newCf->code;
                                $consts = $newCf->consts;
                                $names = $newCf->names;
                                $nestedFns = $newCf->nestedFns;
                                $hasHandlers = $newCf->handlers !== [];
                                $stack = $newFrame->stack;
                                $sp = $newFrame->sp;
                                $locals = $newFrame->locals;
                                $env = $newFrame->env;
                                $thisValue = $newFrame->thisValue;
                                $strict = $this->interp->isStrictMode();
                                $pc = 0;
                                break;
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
                            // Hot path: `arr[i] = v` with a JsArray base and a
                            // non-negative-integer JsNumber index. Skips the
                            // toPropertyKey roundtrip (which would allocate a
                            // JsString from the JsNumber) and the JsArray::set
                            // method dispatch by writing the dense slot inline.
                            // Falls through to the spec path on bailout.
                            if (
                                $obj instanceof \Phasis\Value\JsArray
                                && $key instanceof JsNumber
                            ) {
                                $kv = $key->value;
                                $kvi = (int) $kv;
                                if (
                                    $kvi >= 0
                                    && (float) $kvi === $kv
                                    && $obj->tryDenseWrite($kvi, $val)
                                ) {
                                    $stack[$sp++] = $val;
                                    $pc++;
                                    break;
                                }
                            }
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
                            $retResult = $stack[--$sp];
                            if ($savedFramesDepth > 0) {
                                // Inline-call return: pop one saved
                                // frame, restore caller state, push
                                // the return value onto the caller's
                                // operand stack, continue dispatch.
                                $savedFramesDepth--;
                                $sf = $savedFrames[$savedFramesDepth];
                                $this->interp->teardownInlineVmCall($sf->callee, $sf->restore);
                                $this->interp->releaseInlineVmFrame();
                                $cf = $sf->cf;
                                $code = $sf->code;
                                $consts = $sf->consts;
                                $names = $sf->names;
                                $nestedFns = $sf->nestedFns;
                                $stack = $sf->stack;
                                $sp = $sf->sp;
                                $locals = $sf->locals;
                                $env = $sf->env;
                                $thisValue = $sf->thisValue;
                                $strict = $sf->strict;
                                $hasHandlers = $sf->hasHandlers;
                                $pc = $sf->returnPc;
                                $stack[$sp++] = $retResult;
                                break;
                            }
                            return $retResult;

                        default:
                            throw new InternalError('VM: unknown opcode ' . $op);
                    }
                } // end inner while (dispatch loop)
            } catch (\Phasis\Exceptions\RuntimeError | \Phasis\Exceptions\SyntaxError $e) {
                // Handler search — first in the current frame, then
                // unwinding through `$savedFrames` if necessary (the
                // Stage 1 custom callstack puts caller frames there).
                // SyntaxError gets the same treatment as RuntimeError
                // so an eval() / Function() parse failure still
                // surfaces through a JS try/catch in the calling
                // frame.
                $matched = null;
                while (true) {
                    if ($hasHandlers) {
                        foreach ($cf->handlers as $h) {
                            if ($pc >= $h->tryStart && $pc < $h->tryEnd) {
                                $matched = $h;
                                break;
                            }
                        }
                    }
                    if ($matched !== null) {
                        break;
                    }
                    // No handler here. If there's a saved caller
                    // frame, unwind it (tearing down the per-call
                    // interpreter state) and retry the search in
                    // the caller. The saved pc was the post-CALL
                    // return address; back it up by 2 to the CALL
                    // opcode itself so handler ranges that cover
                    // the call site match.
                    if ($savedFramesDepth === 0) {
                        throw $e;
                    }
                    $savedFramesDepth--;
                    $sf = $savedFrames[$savedFramesDepth];
                    $this->interp->teardownInlineVmCall($sf->callee, $sf->restore);
                    $this->interp->releaseInlineVmFrame();
                    $cf = $sf->cf;
                    $code = $sf->code;
                    $consts = $sf->consts;
                    $names = $sf->names;
                    $nestedFns = $sf->nestedFns;
                    $stack = $sf->stack;
                    $sp = $sf->sp;
                    $locals = $sf->locals;
                    $env = $sf->env;
                    $thisValue = $sf->thisValue;
                    $strict = $sf->strict;
                    $hasHandlers = $sf->hasHandlers;
                    $pc = $sf->returnPc - 2;
                }
                // Convert the PHP exception to a JsValue for the catch
                // parameter. JsThrowable wraps a user-thrown JS value;
                // anything else (TypeError, RangeError, etc.) is a
                // host-level failure that the spec models as a JS
                // Error subclass.
                $jsValue = $e instanceof \Phasis\Exceptions\JsThrowable
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
