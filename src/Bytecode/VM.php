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
                                JsUndefined::instance(),
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
                                JsUndefined::instance(),
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
                                JsUndefined::instance(),
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
                                JsUndefined::instance(),
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
                                JsUndefined::instance(),
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
                                JsUndefined::instance(),
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

    /**
     * Execute a CompiledFunction. For non-generator functions the
     * return type is JsValue; for generator functions whose body the
     * compiler lowered to bytecode (snapshot mode, see
     * `Op::YIELD`), the return type is `YieldResult` when the body
     * suspends or `JsValue` when it runs to completion.
     *
     * The `$resumeFrom` / `$resumeValue` / `$resumeThrow` parameters
     * are how `JsGenerator` resumes a suspended generator: the
     * snapshot's frame state replaces the freshly-allocated frame,
     * `$resumeValue` is pushed onto the operand stack (it becomes
     * the result of the yield expression that suspended us), and
     * `$resumeThrow=true` injects the value as a thrown exception
     * at the saved pc (gen.throw() semantics).
     */
    public function execute(
        CompiledFunction $cf,
        Frame $frame,
        ?GeneratorSnapshot $resumeFrom = null,
        ?JsValue $resumeValue = null,
        bool $resumeThrow = false,
    ): JsValue|YieldResult {
        $code = $cf->code;
        $consts = $cf->consts;
        $names = $cf->names;
        $nestedFns = $cf->nestedFns;
        if ($resumeFrom !== null) {
            $stack = $resumeFrom->stack;
            $sp = $resumeFrom->sp;
            $locals = $resumeFrom->locals;
            $env = $resumeFrom->env;
            $thisValue = $resumeFrom->thisValue;
            $strict = $resumeFrom->strict;
        } else {
            $stack = $frame->stack;
            $sp = $frame->sp;
            $locals = $frame->locals;
            $env = $frame->env;
            $thisValue = $frame->thisValue;
            $strict = $this->interp->isStrictMode();
        }
        $undef = JsUndefined::instance();
        $null = JsNull::instance();
        $true = JsBoolean::of(true);
        $false = JsBoolean::of(false);

        if ($resumeFrom !== null) {
            $pc = $resumeFrom->pc;
        } else {
            $pc = 0;
        }
        $hasHandlers = $cf->handlers !== [];
        // Resume-as-throw: gen.throw() entry. Push the value onto
        // the stack so a synthetic THROW path can pick it up — but
        // simplest is to just raise it now and let the dispatch
        // loop's existing exception handling unwind through any
        // try/catch in the generator body (or escape).
        if ($resumeFrom !== null && $resumeThrow && $resumeValue !== null) {
            // The same catch block below handles this throw —
            // either matches a handler in cf or unwinds out of
            // execute() to the JsGenerator driver.
            $throwAtResume = $resumeValue;
        } else {
            $throwAtResume = null;
            // Non-throw resume: push the resume value (the result
            // of the yield expression that suspended us).
            if ($resumeFrom !== null && $resumeValue !== null) {
                $stack[$sp++] = $resumeValue;
            }
        }

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
                // gen.throw() entry: synthesise a throw at the
                // saved resume pc so the dispatch loop's existing
                // unwind machinery finds the matching handler
                // (or escapes out of execute() to JsGenerator).
                if ($throwAtResume !== null) {
                    $pendingThrow = $throwAtResume;
                    $throwAtResume = null;
                    throw new \Phasis\Exceptions\JsThrowable($pendingThrow);
                }
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
                        case Op::DEFINE_NAME:
                            $env->defineVar($names[$code[$pc + 1]], $stack[--$sp]);
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
                            // fdiv preserves JS division-by-zero semantics
                            // (Infinity / -Infinity / NaN) where PHP's `/`
                            // would throw DivisionByZeroError.
                            $stack[$sp++] = ($l instanceof JsNumber && $r instanceof JsNumber)
                                ? JsNumber::of(fdiv($l->value, $r->value))
                                : $this->interp->numericBinaryOp($l, $r, '/');
                            $pc++;
                            break;
                        case Op::MOD:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            // fmod gives IEEE-754 remainder; PHP `%` only
                            // accepts ints and truncates.
                            $stack[$sp++] = ($l instanceof JsNumber && $r instanceof JsNumber)
                                ? JsNumber::of(fmod($l->value, $r->value))
                                : $this->interp->numericBinaryOp($l, $r, '%');
                            $pc++;
                            break;
                        case Op::POW:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            $stack[$sp++] = ($l instanceof JsNumber && $r instanceof JsNumber)
                                ? JsNumber::of($l->value ** $r->value)
                                : $this->interp->exponentiate($l, $r);
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
                            // Loose-equals inline fast paths: same-type
                            // shortcuts that match abstractEquals's
                            // identity branches without the method
                            // dispatch + branch ladder.
                            if ($l instanceof JsNumber && $r instanceof JsNumber) {
                                $lv = $l->value;
                                $stack[$sp++] = ($lv === $r->value && !is_nan($lv)) ? $true : $false;
                            } elseif ($l instanceof JsString && $r instanceof JsString) {
                                $stack[$sp++] = $l->value === $r->value ? $true : $false;
                            } else {
                                $stack[$sp++] = AbstractOperations::abstractEquals($l, $r) ? $true : $false;
                            }
                            $pc++;
                            break;
                        case Op::NEQ:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            if ($l instanceof JsNumber && $r instanceof JsNumber) {
                                $lv = $l->value;
                                $stack[$sp++] = ($lv !== $r->value || is_nan($lv)) ? $true : $false;
                            } elseif ($l instanceof JsString && $r instanceof JsString) {
                                $stack[$sp++] = $l->value === $r->value ? $false : $true;
                            } else {
                                $stack[$sp++] = AbstractOperations::abstractEquals($l, $r) ? $false : $true;
                            }
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
                // Fast path: both operands are JsNumber with int-valued
                // doubles in int32 range. ToInt32 is the identity here, so
                // PHP's native bitwise op produces the same result as the
                // spec helper without paying its method-dispatch +
                // ToNumeric + JsBigInt branch ladder. Codec/encoder/hash
                // libraries (xxhashjs, noble-hashes, crypto-js) live in
                // this corner.
                        case Op::BAND:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            if ($l instanceof JsNumber && $r instanceof JsNumber) {
                                $lv = $l->value; $rv = $r->value;
                                // Guard NaN/Inf BEFORE the (int) cast —
                                // PHP 8.5 deprecates casting non-finite
                                // doubles to int.
                                if (
                                    is_finite($lv) && is_finite($rv)
                                    && $lv >= -2147483648.0 && $lv <= 2147483647.0
                                    && $rv >= -2147483648.0 && $rv <= 2147483647.0
                                ) {
                                    $li = (int) $lv; $ri = (int) $rv;
                                    if ((float) $li === $lv && (float) $ri === $rv) {
                                        $stack[$sp++] = JsNumber::of((float) ($li & $ri));
                                        $pc++;
                                        break;
                                    }
                                }
                            }
                            $stack[$sp++] = $this->interp->bitwiseBinaryOp($l, $r, '&');
                            $pc++;
                            break;
                        case Op::BOR:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            if ($l instanceof JsNumber && $r instanceof JsNumber) {
                                $lv = $l->value; $rv = $r->value;
                                if (
                                    is_finite($lv) && is_finite($rv)
                                    && $lv >= -2147483648.0 && $lv <= 2147483647.0
                                    && $rv >= -2147483648.0 && $rv <= 2147483647.0
                                ) {
                                    $li = (int) $lv; $ri = (int) $rv;
                                    if ((float) $li === $lv && (float) $ri === $rv) {
                                        $stack[$sp++] = JsNumber::of((float) ($li | $ri));
                                        $pc++;
                                        break;
                                    }
                                }
                            }
                            $stack[$sp++] = $this->interp->bitwiseBinaryOp($l, $r, '|');
                            $pc++;
                            break;
                        case Op::BXOR:
                            $r = $stack[--$sp];
                            $l = $stack[--$sp];
                            if ($l instanceof JsNumber && $r instanceof JsNumber) {
                                $lv = $l->value; $rv = $r->value;
                                if (
                                    is_finite($lv) && is_finite($rv)
                                    && $lv >= -2147483648.0 && $lv <= 2147483647.0
                                    && $rv >= -2147483648.0 && $rv <= 2147483647.0
                                ) {
                                    $li = (int) $lv; $ri = (int) $rv;
                                    if ((float) $li === $lv && (float) $ri === $rv) {
                                        $stack[$sp++] = JsNumber::of((float) ($li ^ $ri));
                                        $pc++;
                                        break;
                                    }
                                }
                            }
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
                            // Cached eligibility for the phpCompiled fast
                            // dispatch: a single boolean read replaces
                            // four field/method calls per dispatch.
                            // Negative results aren't cached (phpCompiled
                            // populates lazily; an early-call false would
                            // permanently lock the function out of the
                            // JIT). Reset by the JIT compiler when it
                            // first installs phpCompiled.
                            $phpEligible = $callee->phpCompiledDispatchCache;
                            if ($phpEligible === null && $callee->phpCompiled !== null) {
                                $phpEligible = !$callee->isClassConstructor()
                                    && !$callee->isDerivedConstructor()
                                    && $callee->getHomeObject() === null;
                                if ($phpEligible) {
                                    $callee->phpCompiledDispatchCache = true;
                                }
                            }
                            if ($phpEligible === true && $callee->phpCompiled !== null) {
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
                                        $undef,
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
                            // Inline arg packing for small counts. Empty
                            // and single-element array literals are very
                            // common in real code (every `[]`, every
                            // `[node]` push, every iterator step); two
                            // are the typical `[key, value]` pair.
                            if ($count === 0) {
                                $items = [];
                            } elseif ($count === 1) {
                                $items = [$stack[$base]];
                            } elseif ($count === 2) {
                                $items = [$stack[$base], $stack[$base + 1]];
                            } else {
                                $items = array_slice($stack, $base, $count);
                            }
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
                            // Inline small-argc packing (mirrors CALL).
                            // Constructor calls with 0–2 args are the
                            // common shape (every `new Point(x, y)`,
                            // every `new Map()`).
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
                            // Inline construct: skip the vmNewExpression
                            // trampoline for plain class / function
                            // constructors that have no field or
                            // private-method initializers. Mirrors the
                            // CALL inline path with two extras: a
                            // freshly-allocated `this` and a
                            // [[NewTarget]] internal property the RET
                            // path tears down once the body returns.
                            // Derived constructors and initializer-
                            // bearing classes still flow through
                            // vmNewExpression so the AST super() path
                            // and class-field timing rules stay
                            // exactly as the tree-walker has them.
                            if ($callee instanceof JsFunction) {
                                $newInlineable = $callee->vmInlineConstructCache;
                                if ($newInlineable === null) {
                                    $newInlineable = $callee->compiled !== null
                                        && $callee->compiled->canSkipEnvAlloc
                                        && $callee->isConstructable()
                                        && !$callee->isDerivedConstructor()
                                        && $callee->getBoundTarget() === null
                                        && $callee->getNativeCallable() === null
                                        && !$callee->isAsync()
                                        && !$callee->isGenerator()
                                        && $callee->getInstanceFieldInitializers() === []
                                        && $callee->getPrivateMethodEntries() === [];
                                    if ($newInlineable) {
                                        $callee->vmInlineConstructCache = true;
                                    }
                                }
                                if ($newInlineable) {
                                    // Inline the dataSlots-fast-path of
                                    // $callee->get('prototype'): regular
                                    // function instances always carry
                                    // `prototype` as an own data slot,
                                    // so the hit lands in one array
                                    // lookup with no method dispatch.
                                    // Fall back to the spec getter only
                                    // if the slot was deleted (rare).
                                    $proto = $callee->properties->dataSlots['prototype']
                                        ?? $callee->get('prototype');
                                    $newObj = new JsObject($proto instanceof JsObject ? $proto : null);
                                    // Skip the [[NewTarget]] install
                                    // for function bodies that don't
                                    // read new.target / super(). The
                                    // matching forceDelete on the RET
                                    // path below also skips. Typical
                                    // `function Ctor() { this.x = … }`
                                    // bodies never observe new.target;
                                    // ctor-heavy is the canonical case.
                                    if ($callee->bodyUsesNewTargetCache === null) {
                                        $callee->bodyUsesNewTargetCache =
                                            $this->interp->bodyContainsNewTarget($callee->getBody());
                                    }
                                    $installNewTarget = $callee->bodyUsesNewTargetCache;
                                    if ($installNewTarget) {
                                        $newObj->defineOwnProperty(
                                            '[[NewTarget]]',
                                            \Phasis\Object\PropertyDescriptor::data($callee, false, false, false),
                                        );
                                    }
                                    $newCf = $callee->compiled;
                                    $restore = $this->interp->setupInlineVmCall($callee, $newObj);
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
                                    $sf->isConstruct = true;
                                    $sf->newObject = $newObj;
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
                            // Inline arg packing for argc ≤ 2. array_slice
                            // is the long-tail allocator on method-call-
                            // heavy code (arr.push(x), obj.method(a,b),
                            // Math.max(a,b)). Two array literals are
                            // cheaper than array_slice's copy + length
                            // counting; argc=0 short-circuits to the
                            // shared $undefArgs reference.
                            if ($argc === 0) {
                                $args = [];
                            } elseif ($argc === 1) {
                                $args = [$stack[$base]];
                            } elseif ($argc === 2) {
                                $args = [$stack[$base], $stack[$base + 1]];
                            } else {
                                $args = array_slice($stack, $base, $argc);
                            }
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
                            ) {
                                // Lean on JsArray::tryDenseWrite, which
                                // already caches the proto-chain checks
                                // (writable length + extensible +
                                // no own integer-index property on
                                // prototypes) per instance and skips the
                                // per-iteration `getOwnPropertyDescriptor`
                                // hash lookup on Array.prototype. False
                                // return falls back to the spec path.
                                $newIndex = $receiver->getLength();
                                if ($receiver->tryDenseWrite($newIndex, $args[0])) {
                                    $stack[$sp++] = JsNumber::of((float) ($newIndex + 1));
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
                                $pc += 2;
                                break;
                            }
                            // Prototype inline cache: when the receiver has no
                            // own data slot for $name AND no own descriptor,
                            // try the per-PC cache. The cache stores the
                            // receiver's prototype reference plus the
                            // resolved value and the proto's PropertyMap
                            // version at capture. A hit returns the cached
                            // value without walking the prototype chain.
                            // JsProxy is excluded from the IC entirely:
                            // its `getPrototype()` forwards to the proxy's
                            // `getPrototypeOf` trap, which is an observable
                            // side effect every IC lookup would re-trigger.
                            // The IC also can't trust the proxy's
                            // prototype-as-identity in any case — the
                            // trap can return a different object on each
                            // call. Slow path handles proxies correctly.
                            if (
                                $obj instanceof JsObject
                                && !$obj instanceof \Phasis\Value\JsProxy
                                && !isset($obj->properties->descriptors[$name])
                            ) {
                                // Polymorphic inline cache: stores up to
                                // 4 [proto, resolved, version] triples
                                // per PC. Real-world code routinely
                                // polymorphs at the same property-read
                                // site (`node.type` where node can be
                                // any of N AST node kinds; `arr[i].x`
                                // where arr is sometimes objects A,
                                // sometimes B). The monomorphic version
                                // missed on every alternation and
                                // re-walked the prototype chain via
                                // lookupMember; the polymorphic version
                                // catches up to 4 shapes per site, then
                                // marks the PC megamorphic (a sentinel)
                                // so future calls skip the IC walk and
                                // go straight to lookupMember.
                                $ic = $cf->loadMemberIc[$pc] ?? null;
                                if ($ic !== null) {
                                    $objProto = $obj->prototype;
                                    // Megamorphic sentinel: skip the
                                    // IC walk entirely.
                                    if ($ic === true) {
                                        $stack[$sp++] = $this->lookupMember($obj, $name);
                                        $pc += 2;
                                        break;
                                    }
                                    // Unroll the monomorphic 1-entry
                                    // case so the foreach setup cost
                                    // doesn't penalise the most common
                                    // shape (class instances where every
                                    // receiver shares a prototype).
                                    $e0 = $ic[0];
                                    if (
                                        $e0[0] === $objProto
                                        && $objProto->properties->version === $e0[2]
                                    ) {
                                        $stack[$sp++] = $e0[1];
                                        $pc += 2;
                                        break;
                                    }
                                    // Polymorphic walk: 2..4 entries.
                                    $icLen = count($ic);
                                    $hit = false;
                                    for ($i = 1; $i < $icLen; $i++) {
                                        $entry = $ic[$i];
                                        if (
                                            $entry[0] === $objProto
                                            && $objProto->properties->version === $entry[2]
                                        ) {
                                            $stack[$sp++] = $entry[1];
                                            $pc += 2;
                                            $hit = true;
                                            break;
                                        }
                                    }
                                    if ($hit) {
                                        break;
                                    }
                                }
                                $resolved = $this->lookupMember($obj, $name);
                                // Only cache when the resolved value lives
                                // on a prototype as a data property — be it
                                // a fast dataSlot or a writable data
                                // descriptor (class methods land here:
                                // they're stored as non-enumerable
                                // descriptors with $get === null).
                                // Getter accessors must re-run on every
                                // read, so they're not cacheable.
                                $proto = $obj->prototype;
                                if ($proto !== null) {
                                    $cacheable = false;
                                    if (
                                        isset($proto->properties->dataSlots[$name])
                                        && $proto->properties->dataSlots[$name] === $resolved
                                    ) {
                                        $cacheable = true;
                                    } else {
                                        $protoDesc = $proto->properties->descriptors[$name] ?? null;
                                        if (
                                            $protoDesc !== null
                                            && $protoDesc->get === null
                                            && $protoDesc->set === null
                                            && $protoDesc->value === $resolved
                                        ) {
                                            $cacheable = true;
                                        }
                                    }
                                    if ($cacheable) {
                                        $newEntry = [
                                            $proto,
                                            $resolved,
                                            $proto->properties->version,
                                        ];
                                        $existing = $cf->loadMemberIc[$pc] ?? null;
                                        if ($existing === null) {
                                            $cf->loadMemberIc[$pc] = [$newEntry];
                                        } elseif (is_array($existing) && count($existing) < 4) {
                                            // Append; no need to dedupe
                                            // since a miss above means
                                            // this proto wasn't in the
                                            // list under the current
                                            // version.
                                            $existing[] = $newEntry;
                                            $cf->loadMemberIc[$pc] = $existing;
                                        } else {
                                            // 4 shapes already seen; site
                                            // is megamorphic. Sentinel
                                            // stops further IC churn so
                                            // future hits go straight to
                                            // lookupMember without the
                                            // walk overhead.
                                            $cf->loadMemberIc[$pc] = true;
                                        }
                                    }
                                }
                                $stack[$sp++] = $resolved;
                                $pc += 2;
                                break;
                            }
                            $stack[$sp++] = $this->lookupMember($obj, $name);
                            $pc += 2;
                            break;
                        case Op::LOAD_COMPUTED:
                            $key = $stack[--$sp];
                            $obj = $stack[--$sp];
                            // Hot path: `arr[i]` with a JsArray base and a
                            // non-negative-integer JsNumber index. Mirrors
                            // STORE_COMPUTED's fast write at line 2168 so
                            // bracket reads and writes have the same cost.
                            // Dense-array indexing is the inner loop of
                            // every parser / iterator / numerical kernel,
                            // and routing through the full toPropertyKey →
                            // lookupComputed dispatch ladder allocated a
                            // JsString and walked the descriptor table for
                            // every read. Bails to the spec path on holes
                            // (denseElements[$kvi] === null), non-integer
                            // keys, or non-dense arrays.
                            if (
                                $obj instanceof \Phasis\Value\JsArray
                                && $key instanceof JsNumber
                                && $obj->isDenseMode()
                            ) {
                                $kv = $key->value;
                                $kvi = (int) $kv;
                                if (
                                    $kvi >= 0
                                    && (float) $kvi === $kv
                                    && $kvi < $obj->getLength()
                                ) {
                                    $val = $obj->getDenseElements()[$kvi] ?? null;
                                    if ($val !== null) {
                                        $stack[$sp++] = $val;
                                        $pc++;
                                        break;
                                    }
                                }
                            }
                            $stack[$sp++] = $this->lookupComputed($obj, $key);
                            $pc++;
                            break;
                        case Op::STORE_MEMBER:
                            $val = $stack[--$sp];
                            $obj = $stack[--$sp];
                            $name = $names[$code[$pc + 1]];
                            // Fast path 1: assigning to an existing own default-
                            // attr data slot. Per OrdinarySetWithOwnDescriptor
                            // an own writable data property wins over any
                            // prototype-chain accessor or readonly slot, so a
                            // direct in-place write is spec-correct here.
                            //
                            // Exotic-slot bailout: JsArray's `length` is NOT
                            // stored in $properties->dataSlots — it lives in
                            // a dedicated field with custom setter semantics
                            // (ArraySetLength, which trims dense elements and
                            // can throw RangeError). Skipping the slow path
                            // and stashing the new length into dataSlots
                            // creates a phantom `length` property that
                            // shadows the real exotic slot on subsequent
                            // reads and corrupts the array. Same shape would
                            // apply to TypedArray length and any future
                            // exotic-length carrier — the safe gate is "no
                            // exotic length descriptor at all," which on
                            // plain JsObject is always true.
                            if (
                                $obj instanceof JsObject
                                && !$obj instanceof \Phasis\Value\JsArray
                                && !$obj instanceof \Phasis\Value\JsTypedArray
                                && isset($obj->properties->dataSlots[$name])
                            ) {
                                $obj->properties->dataSlots[$name] = $val;
                                $obj->properties->mutationVersion++;
                                if ($obj->properties->isUsedAsProto) {
                                    $obj->properties->version++;
                                }
                                $stack[$sp++] = $val;
                                $pc += 2;
                                break;
                            }
                            // Fast path 2: inline cache for "new own property"
                            // writes. The IC stores a snapshot of the prototype
                            // chain (proto refs + their PropertyMap versions)
                            // taken when the chain was known to have no setter
                            // or readonly descriptor for $name at any depth.
                            // On hit, the receiver gets a direct setDataSlot,
                            // which is what the slow path would land on anyway
                            // but without the method-dispatch ladder.
                            // Excludes Proxy, non-extensible objects, and
                            // receivers that already carry an own descriptor
                            // for $name (which would force the slow path).
                            //
                            // Also excludes JsArray / JsTypedArray: their
                            // `length` lives in an exotic slot rather than
                            // dataSlots, and setDataSlot would create a
                            // phantom property that shadows the real length
                            // on subsequent reads — defeating push, splice,
                            // and the truncation done by `arr.length = N`.
                            // Surfaced by prettier's path-stack code:
                            // `s.length -= 2` after a push was silently
                            // dropping the truncation on the second hit and
                            // recursing forever as the visitor re-entered
                            // the same node.
                            if (
                                $obj instanceof JsObject
                                && !$obj instanceof \Phasis\Value\JsProxy
                                && !$obj instanceof \Phasis\Value\JsArray
                                && !$obj instanceof \Phasis\Value\JsTypedArray
                                && $obj->isExtensible()
                                && !isset($obj->properties->descriptors[$name])
                            ) {
                                $ic = $cf->storeMemberIc[$pc] ?? null;
                                if ($ic !== null) {
                                    $chainOk = true;
                                    $cursor = $obj->prototype;
                                    foreach ($ic as $entry) {
                                        if (
                                            $cursor !== $entry[0]
                                            || $cursor->properties->version !== $entry[1]
                                        ) {
                                            $chainOk = false;
                                            break;
                                        }
                                        $cursor = $cursor->prototype;
                                    }
                                    if ($chainOk && $cursor === null) {
                                        $obj->properties->setDataSlot($name, $val);
                                        $stack[$sp++] = $val;
                                        $pc += 2;
                                        break;
                                    }
                                }
                                // IC miss. Walk the chain to see whether this
                                // site is even cacheable. A miss is cheap; a
                                // permanent uncacheable mark is cheaper still.
                                if (!isset($cf->storeMemberIcUncacheable[$pc])) {
                                    $chain = [];
                                    $cursor = $obj->prototype;
                                    $cacheable = true;
                                    while ($cursor !== null) {
                                        if ($cursor instanceof \Phasis\Value\JsProxy) {
                                            $cacheable = false;
                                            break;
                                        }
                                        // Reject if any ancestor has a setter
                                        // or readonly data descriptor for $name
                                        // — both would intercept the write per
                                        // spec OrdinarySetWithOwnDescriptor.
                                        $ancDesc = $cursor->properties->descriptors[$name] ?? null;
                                        if ($ancDesc !== null) {
                                            if (
                                                $ancDesc->set !== null
                                                || $ancDesc->get !== null
                                                || $ancDesc->writable === false
                                            ) {
                                                $cacheable = false;
                                                break;
                                            }
                                        }
                                        $chain[] = [$cursor, $cursor->properties->version];
                                        $cursor = $cursor->prototype;
                                    }
                                    if ($cacheable) {
                                        $cf->storeMemberIc[$pc] = $chain;
                                        // Fall through to the slow path for
                                        // the current write — populating the
                                        // cache and writing in one step would
                                        // duplicate the spec checks the slow
                                        // path already enforces.
                                    } else {
                                        $cf->storeMemberIcUncacheable[$pc] = true;
                                    }
                                }
                            }
                            $this->writeMember($obj, $name, $val);
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
                                // Construct substitution: when the
                                // inlined call was `new`, the value
                                // pushed onto the caller stack is the
                                // body's return value if it's an
                                // object, else the freshly-allocated
                                // `this`. Mirrors vmNewExpression's
                                // post-call branch (non-derived path —
                                // derived ctors are not inlined).
                                if ($sf->isConstruct) {
                                    $newObj = $sf->newObject;
                                    // Matching skip for the body-uses-
                                    // new.target optimisation above. When
                                    // the install was elided, neither
                                    // newObj nor the returned object
                                    // carries the slot, so forceDelete is
                                    // pure overhead.
                                    $needsDelete = $sf->callee instanceof JsFunction
                                        && $sf->callee->bodyUsesNewTargetCache === true;
                                    if ($retResult instanceof JsObject) {
                                        if ($needsDelete) {
                                            $retResult->forceDelete('[[NewTarget]]');
                                        }
                                        $finalResult = $retResult;
                                    } else {
                                        if ($needsDelete) {
                                            $newObj->forceDelete('[[NewTarget]]');
                                        }
                                        $finalResult = $newObj;
                                    }
                                    $sf->newObject = null;
                                    $sf->isConstruct = false;
                                } else {
                                    $finalResult = $retResult;
                                }
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
                                $stack[$sp++] = $finalResult;
                                break;
                            }
                            return $retResult;

                        case Op::YIELD:
                            // Snapshot generator suspension. `yield`
                            // can only appear syntactically at the
                            // generator's own top frame — any nested
                            // call must RET first — so $savedFrames
                            // is empty (relative to this execute()
                            // invocation) and doesn't need saving.
                            // Resume pc is pc+1: the byte after
                            // YIELD, where the dispatch will push
                            // the resume value and continue.
                            //
                            // Reuse incoming $resumeFrom in place
                            // instead of allocating a fresh
                            // GeneratorSnapshot: its fields are no
                            // longer needed since execute() copied
                            // them into PHP locals at entry, and
                            // the JsGenerator driver replaces its
                            // own snapshot reference with whatever
                            // YieldResult carries. One allocation
                            // saved per yield in steady state.
                            $yieldedValue = $stack[--$sp];
                            if ($resumeFrom !== null) {
                                $resumeFrom->cf = $cf;
                                $resumeFrom->pc = $pc + 1;
                                $resumeFrom->stack = $stack;
                                $resumeFrom->sp = $sp;
                                $resumeFrom->locals = $locals;
                                $resumeFrom->env = $env;
                                $resumeFrom->thisValue = $thisValue;
                                $resumeFrom->strict = $strict;
                                $snap = $resumeFrom;
                            } else {
                                $snap = new GeneratorSnapshot(
                                    $cf,
                                    $pc + 1,
                                    $stack,
                                    $sp,
                                    $locals,
                                    $env,
                                    $thisValue,
                                    $strict,
                                );
                            }
                            return new YieldResult($yieldedValue, $snap);

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
                    // Construct frame unwind: the freshly-allocated
                    // `this` is being discarded, but we still scrub
                    // [[NewTarget]] off it so a finalizer or weak ref
                    // never observes the engine-internal property.
                    if ($sf->isConstruct && $sf->newObject !== null) {
                        $sf->newObject->forceDelete('[[NewTarget]]');
                        $sf->newObject = null;
                        $sf->isConstruct = false;
                    }
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
