<?php

declare(strict_types=1);

namespace PhpJs\Bytecode;

use PhpJs\Ast\Expression\ArrayExpression;
use PhpJs\Ast\Expression\AssignmentExpression;
use PhpJs\Ast\Expression\BinaryExpression;
use PhpJs\Ast\Expression\CallExpression;
use PhpJs\Ast\Expression\ConditionalExpression;
use PhpJs\Ast\Expression\Identifier;
use PhpJs\Ast\Expression\Literal;
use PhpJs\Ast\Expression\MemberExpression;
use PhpJs\Ast\Expression\NewExpression;
use PhpJs\Ast\Expression\ObjectExpression;
use PhpJs\Ast\Expression\PrivateIdentifier;
use PhpJs\Ast\Expression\Property;
use PhpJs\Ast\Expression\SpreadElement;
use PhpJs\Ast\Expression\TemplateElement;
use PhpJs\Ast\Expression\TemplateLiteral;
use PhpJs\Ast\Expression\ThisExpression;
use PhpJs\Ast\Node;
use PhpJs\Ast\Declaration\FunctionDeclaration;
use PhpJs\Ast\Declaration\VariableDeclaration;
use PhpJs\Ast\Expression\LogicalExpression;
use PhpJs\Ast\Expression\SequenceExpression;
use PhpJs\Ast\Expression\UnaryExpression;
use PhpJs\Ast\Expression\UpdateExpression;
use PhpJs\Ast\Pattern\ArrayPattern;
use PhpJs\Ast\Pattern\AssignmentPattern;
use PhpJs\Ast\Pattern\ObjectPattern;
use PhpJs\Ast\Pattern\RestElement;
use PhpJs\Ast\Statement\BlockStatement;
use PhpJs\Ast\Statement\BreakStatement;
use PhpJs\Ast\Statement\ContinueStatement;
use PhpJs\Ast\Statement\DoWhileStatement;
use PhpJs\Ast\Statement\EmptyStatement;
use PhpJs\Ast\Statement\ExpressionStatement;
use PhpJs\Ast\Statement\ForStatement;
use PhpJs\Ast\Statement\IfStatement;
use PhpJs\Ast\Statement\ReturnStatement;
use PhpJs\Ast\Statement\ThrowStatement;
use PhpJs\Ast\Statement\WhileStatement;
use PhpJs\Value\JsBigInt;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

/**
 * AST → bytecode lowering. Phase 2 supports the minimal subset
 * needed to run fib(): arithmetic, comparison, conditional
 * expression, return, simple identifier callee calls, parameter
 * locals, free-variable name lookups. Anything else throws
 * CompilerBailout and JsFunction falls back to the tree-walker.
 */
final class Compiler
{
    /** @var list<int> */
    private array $code = [];

    /** @var list<JsValue> */
    private array $consts = [];

    /** @var list<string> */
    private array $names = [];

    /** @var array<string,int> Reverse map for de-duping constants by string key. */
    private array $constIndex = [];

    /** @var array<string,int> Reverse map for de-duping names. */
    private array $nameIndex = [];

    /** @var list<string> Slot index → identifier name, for diagnostics. */
    private array $localNames = [];

    /** @var array<string,int> Identifier name → slot index. */
    private array $localSlots = [];

    /** @var list<int> */
    private array $paramSlots = [];

    /** @var list<\PhpJs\Ast\Node> */
    private array $nestedFns = [];

    /**
     * Slot indices that were declared `const`. Re-assignment to any
     * of these (by ordinary `=`, compound `+=`, or `++`/`--`) is a
     * TypeError per spec; the compiler bails so the tree-walker can
     * surface the spec-correct error.
     *
     * @var array<int,bool>
     */
    private array $constSlots = [];

    /**
     * Names declared via `var` (function-scoped) anywhere in the
     * function body. Captured during collectStatementLocals. Used by
     * the TDZ check to suppress false positives when a `let X` inside
     * a block shares a name with an outer `var X`: the slot-based
     * compiler folds them to one slot, and source-order references
     * to the var resolve to the same slot, so there's no actual TDZ.
     *
     * @var array<string,bool>
     */
    private array $varDeclaredNames = [];

    /**
     * Set of top-level names that already have a FunctionDeclaration at
     * program scope. compileProgram populates this BEFORE allocating
     * frame slots for top-level vars so a `var x; function x() {}` pair
     * leaves `x` on globalEnv (where the FD hoists it) rather than
     * shadowing it with an undefined frame slot. Empty in all non-program
     * compilation paths.
     *
     * @var array<string,bool>
     */
    private array $fnDeclShadowedVarNames = [];

    /**
     * Stack of (continueTargetPc, breakPatchOperandIndices). Each
     * loop / switch entry pushes; break/continue inside the body
     * read the top entry to emit jumps. Patched at scope close.
     *
     * @var list<array{continue: int, breaks: list<int>, continues: list<int>}>
     */
    private array $loopStack = [];

    /**
     * Exception-handler entries collected while compiling. Emitted
     * onto CompiledFunction at the end so the VM can find the
     * innermost handler covering a given PC on throw.
     *
     * @var list<HandlerEntry>
     */
    private array $handlers = [];

    /**
     * Class AST nodes referenced by MAKE_CLASS opcodes. Each entry is a
     * ClassDeclaration or ClassExpression node that the VM will hand to
     * Interpreter::vmMakeClass at run time. The enclosing function
     * compiles to bytecode without needing the compiler to model class
     * semantics directly.
     *
     * @var list<\PhpJs\Ast\Node>
     */
    private array $classNodes = [];

    /**
     * Source-like display string for each CALL_METHOD opcode emitted,
     * keyed by the PC of the opcode. The VM reads this on the
     * "is not a function" error path so the message matches V8 /
     * SpiderMonkey ("[].__proto__ is not a function") rather than
     * collapsing to "value is not a function" when the callee's
     * toString is empty (arrays, plain objects, etc.).
     *
     * @var array<int, string>
     */
    private array $callMethodDisplays = [];

    /**
     * Per-function tracking of operand-stack depth at the start of
     * each compiled statement. The compiler doesn't otherwise track
     * stack depth, so we approximate by snapshotting the depth
     * before compiling a try block and storing that on the
     * HandlerEntry.stackBase.
     *
     * Phase 1: assume try blocks are entered with empty operand
     * stack (the spec is fine with non-zero, but our
     * StatementListEvaluation always has the stack drained between
     * statements). The VM still resets to stackBase so future
     * support for non-empty entries is a one-line change.
     */
    private int $tryEntryStackDepth = 0;

    /**
     * Phase 2 finally guard: stack of loopStack sizes captured at
     * each try-with-finally entry. Used to detect whether a
     * break / continue inside the protected block targets a loop
     * outside the try (bail) versus a nested loop entirely within
     * the try (fine — finally still runs because the inner break
     * doesn't escape the try). A non-empty stack also forces return
     * inside the protected block to bail, since we'd have to inline
     * the finally body before RET to preserve spec semantics.
     *
     * @var list<int>
     */
    private array $finallyLoopBoundaries = [];

    /**
     * THROW opcode operand: a single int that the VM uses to look
     * up the right exception via thrown JsValue. Throw lowering
     * leaves the value on stack and emits THROW; the VM raises a
     * JsThrowable with that value.
     */

    /**
     * Slot indices that represent top-level `var` bindings. When set,
     * every STORE_LOCAL into one of these slots is followed by an
     * eager LOAD_LOCAL + STORE_NAME pair so the global object's
     * property stays in lock-step with the frame slot. The end-of-
     * program mirror loop alone is not enough: a Function-constructor
     * body or any nested call that reads `globalThis.<name>` mid-
     * program would observe the hoisted-undefined placeholder
     * otherwise (the test262 S15.3_A3_T2 / T6 regression).
     *
     * @var array<int,string>
     */
    private array $programVarSlots = [];

    /**
     * Whether the program currently being compiled has a `"use strict"`
     * directive in its prologue (or was wrapped with one by the host).
     * Used by compileAssignment to bail when a non-local identifier is
     * the LHS: per spec, the LHS reference resolves BEFORE the RHS, so
     * a strict-mode unresolvable LHS must throw ReferenceError even
     * when the RHS happens to add the property to the global object as
     * a side effect (test262 language/identifier-resolution/
     * assign-to-global-undefined.js).
     */
    private bool $programIsStrict = false;

    public function compile(JsFunction $fn): CompiledFunction
    {
        if ($fn->isGenerator() || $fn->isAsync() || $fn->isNative() || $fn->isClassConstructor()) {
            throw new CompilerBailout('non-ordinary function kind');
        }
        // Strict-mode bodies that have a return-with-call shape need
        // tail-call optimisation per spec. The bytecode VM emits
        // CALL+RET which recurses normally and grows the PHP stack
        // (test262 tco-* tests at $MAX_ITERATIONS=100000 hit
        // maxCallDepth). Bail compile so the tree-walker (which
        // returns TailCallThunk + callFunction trampolines) handles
        // these. Mirrors the same bail in JsToPhp::compile.
        if ($fn->isStrict() && self::bytecodeBailsForTailCall($fn->getBody())) {
            throw new CompilerBailout('strict-mode tail-call body');
        }
        $body = $fn->getBody();
        $isExpressionBody = !$body instanceof BlockStatement;
        if ($isExpressionBody && !$body instanceof Node) {
            throw new CompilerBailout('non-AST body');
        }

        // Reject bodies the tree-walker treats as "dynamic": eval
        // references, with statements, generators, etc. The caller
        // (Interpreter) already filters generators/async/etc. via
        // JsFunction flags, but eval/with require AST inspection.
        if ($isExpressionBody) {
            $this->scanBailout($body);
        } else {
            $this->ensureNoBailoutFeatures($body);
        }

        foreach ($fn->getParams() as $param) {
            if (!$param instanceof Identifier) {
                throw new CompilerBailout('non-simple parameter');
            }
            $slot = $this->declareLocal($param->name);
            $this->paramSlots[] = $slot;
        }

        if ($isExpressionBody) {
            // Arrow expression body: nothing to pre-walk (no var
            // decls allowed), no TDZ, no statements. Compile the
            // single expression directly.
            $this->compileExpression($body);
            $this->emit(Op::RET);
            $needsThis = in_array(Op::LOAD_THIS, $this->code, true);
            $cf = new CompiledFunction(
                code: $this->code,
                consts: $this->consts,
                names: $this->names,
                localNames: $this->localNames,
                paramSlots: $this->paramSlots,
                slotCount: max(1, count($this->localNames)),
                nestedFns: $this->nestedFns,
                needsThis: $needsThis,
                needsArgsBinding: false,
                canSkipEnvAlloc: !$needsThis,
            );
            $cf->callMethodDisplays = $this->callMethodDisplays;
            return $cf;
        }

        // Pre-walk: collect every var/let/const/function name in
        // the body so each is assigned a unique frame slot up front.
        $this->collectFunctionLocals($body->body);

        // TDZ guard: bail if any let/const name appears in source
        // BEFORE its declaration.
        $this->ensureNoTdzViolations($body->body);

        // FunctionDeclaration hoisting: per spec, function decls bind
        // at the start of the enclosing function. Emit MAKE_FUNCTION
        // + STORE_LOCAL ahead of the regular statement loop so any
        // forward reference resolves correctly.
        $this->hoistFunctionDeclarations($body->body);

        foreach ($body->body as $stmt) {
            // Skip top-level FunctionDeclaration here — already
            // emitted above. Their identity must not be re-bound.
            if ($stmt instanceof FunctionDeclaration) {
                continue;
            }
            $this->compileStatement($stmt);
        }

        $this->emit(Op::LOAD_UNDEF);
        $this->emit(Op::RET);

        $needsThis = in_array(Op::LOAD_THIS, $this->code, true);
        $cf = new CompiledFunction(
            code: $this->code,
            consts: $this->consts,
            names: $this->names,
            localNames: $this->localNames,
            paramSlots: $this->paramSlots,
            slotCount: max(1, count($this->localNames)),
            nestedFns: $this->nestedFns,
            needsThis: $needsThis,
            needsArgsBinding: false,
            canSkipEnvAlloc: !$needsThis,
        );
        $cf->handlers = $this->handlers;
        $cf->classNodes = $this->classNodes;
        $cf->callMethodDisplays = $this->callMethodDisplays;
        return $cf;
    }

    /**
     * Top-level Program compile entry. Mirrors compile() but operates on a
     * Program node instead of a JsFunction. Returns a CompiledFunction whose
     * Frame is meant to run with env = globalEnv. Free identifier lookups
     * (LOAD_NAME / STORE_NAME) route through that env chain — top-level
     * function declarations are NOT emitted by the bytecode because
     * Interpreter::execute pre-hoists them into globalEnv before invoking
     * the VM.
     *
     * Throws CompilerBailout when the program shape is unsupported (any
     * top-level let/const, classes, modules, with, eval-tainted references,
     * destructuring, etc.). The caller is expected to fall back to the
     * tree-walker on bailout.
     *
     * @param \PhpJs\Ast\Program $program
     */
    public function compileProgram(\PhpJs\Ast\Program $program): CompiledFunction
    {
        // Detect "use strict" prologue so compileAssignment can bail
        // for non-local identifier writes. The spec requires LHS
        // reference resolution to occur BEFORE the RHS is evaluated,
        // and the bytecode VM evaluates RHS first then performs
        // STORE_NAME — a difference that only matters in strict mode
        // when the RHS itself creates the global property.
        $this->programIsStrict = self::programHasUseStrictDirective($program->body);

        // Only accept a feature subset that's safe to lower to the VM at
        // top level. Any reference to `eval`/`arguments`, `with`,
        // yield/await, etc. is caught here and propagates as a bailout to
        // the caller.
        $this->scanBailout($program->body);

        // Reject top-level features the slot-based compiler can't model
        // correctly at script level. `let`/`const` would need a separate
        // Script Lexical Environment so closures could observe them; the
        // VM frame doesn't reify one. Modules / imports / exports / classes
        // at top level have their own env semantics that the bytecode VM
        // doesn't honour. Top-level TryStatement is rejected because the
        // catch body's last ExpressionStatement is the completion value
        // of the whole try-catch — but the VM's POP-after-ExpressionStatement
        // path inside compileTryCatch drops that value, so the program
        // result would silently diverge from the tree-walker.
        foreach ($program->body as $stmt) {
            if ($stmt instanceof VariableDeclaration) {
                if ($stmt->kind !== 'var') {
                    throw new CompilerBailout('top-level let/const/using');
                }
            }
            if (
                $stmt instanceof \PhpJs\Ast\Declaration\ImportDeclaration
                || $stmt instanceof \PhpJs\Ast\Declaration\ExportDeclaration
                || $stmt instanceof \PhpJs\Ast\Declaration\ClassDeclaration
            ) {
                throw new CompilerBailout('top-level module/class decl');
            }
            if ($stmt instanceof \PhpJs\Ast\Statement\TryStatement) {
                throw new CompilerBailout('top-level try statement');
            }
        }

        // First pass: collect every top-level function-declaration name.
        // These bind on globalEnv (via hoistDeclarations) and must NOT
        // also get a frame slot — otherwise a `var __func; function
        // __func() {}; __func()` shape would resolve `__func` through
        // the frame slot (still undefined because var initializes to
        // undefined; the function-decl hoist only writes to globalEnv),
        // diverging from the tree-walker where the function-decl wins.
        // §B.3.3 / §15.2.1.21 require the FD-name to shadow the var.
        $topLevelFnDeclNames = [];
        foreach ($program->body as $stmt) {
            if ($stmt instanceof FunctionDeclaration && $stmt->id instanceof Identifier) {
                $topLevelFnDeclNames[$stmt->id->name] = true;
            }
        }
        $this->fnDeclShadowedVarNames = $topLevelFnDeclNames;

        // Collect every top-level var name so each is assigned a frame slot.
        // Function declarations are deliberately skipped: hoistDeclarations
        // (in Interpreter::execute) already installs them onto globalEnv,
        // and accessing them through LOAD_NAME lets nested closures resolve
        // them through the env chain rather than via frame slots that the
        // closures cannot see.
        foreach ($program->body as $stmt) {
            $this->collectProgramVarLocals($stmt);
        }

        // Closure-capture safety: top-level FunctionDeclarations are hoisted
        // onto globalEnv at execute time, so their closure env is globalEnv
        // — they cannot see the bytecode's frame slots. If any such function
        // body references a top-level var name (which we just mapped to a
        // frame slot), the call would resolve through globalEnv and find an
        // undefined hoisted placeholder, silently diverging from the
        // tree-walker. The same capturesOuterLocal scan already runs on
        // nested function/arrow EXPRESSIONS via compileNestedFunction; here
        // we cover the DECLARATION case the skip-in-emit loop would miss.
        if ($this->localSlots !== []) {
            foreach ($program->body as $stmt) {
                if (
                    $stmt instanceof FunctionDeclaration
                    && $this->capturesOuterLocal($stmt)
                ) {
                    throw new CompilerBailout('top-level fn-decl captures top-level var');
                }
            }

            // Writes through globalThis / this at program scope can
            // clobber the globalEnv binding of a top-level var. The
            // bytecode keeps top-level vars in frame slots, so e.g.
            // `this['x'] = 1; ... ; var x;` would set the global binding
            // to 1 but reads of `x` still see the frame-slot undefined.
            // We can't model that statically without IR alias tracking,
            // so bail conservatively when the program contains any
            // assignment whose target is rooted at `this` or
            // `globalThis` (member or computed-member). Pure reads
            // (`this.x`, `globalThis.foo`) are fine and stay on the VM
            // path.
            if ($this->programWritesThroughGlobalAlias($program->body)) {
                throw new CompilerBailout('top-level write through this/globalThis');
            }
        }

        // Reserve a slot to hold the program's completion value. Each
        // ExpressionStatement compiles to STORE_LOCAL[resultSlot] instead
        // of POP so the trailing LOAD_LOCAL+RET surfaces the last
        // expression statement's value, matching executeStatements.
        $resultSlot = count($this->localNames);
        $this->localNames[] = '[[programResult]]';

        // Snapshot top-level var slots BEFORE statement compilation so the
        // end-of-program mirror loop only touches names that were truly
        // top-level vars (not catch params, not the result slot, not
        // inner-let slots discovered by collectStatementLocals during
        // statement compile). Each (name, slot) entry will trigger one
        // LOAD_LOCAL + STORE_NAME at program exit so subsequent
        // engine->eval() calls see the latest values via globalEnv —
        // matching the tree-walker's behaviour where every top-level
        // var is a property of the global object.
        $topLevelVarSlots = $this->localSlots;

        // Enable eager STORE_NAME mirroring for every write to a
        // top-level var slot during statement compile, so functions
        // created during the program (Function-constructor bodies,
        // setTimeout-style callbacks, nested closures invoked
        // mid-script) see live values on globalThis rather than the
        // hoisted-undefined placeholder. The end-of-program mirror
        // loop alone is not sufficient.
        foreach ($topLevelVarSlots as $name => $slot) {
            $this->programVarSlots[$slot] = $name;
        }

        foreach ($program->body as $stmt) {
            // Top-level FunctionDeclarations are pre-hoisted onto
            // globalEnv by Interpreter::execute; the bytecode does NOT
            // re-bind them. A duplicate MAKE_FUNCTION here would create a
            // second JsFunction with a different identity and overwrite
            // the env binding — but its closure env still points at
            // globalEnv, so a `Test262Error.thrower = function () { throw
            // new Test262Error(...) }`-style nested capture would resolve
            // against the new instance (or worse, against a stale one).
            // Skip to keep the single hoisted instance authoritative.
            if ($stmt instanceof FunctionDeclaration) {
                continue;
            }
            if ($stmt instanceof ExpressionStatement) {
                $this->compileExpression($stmt->expression);
                $this->emit(Op::STORE_LOCAL, $resultSlot);
                continue;
            }
            $this->compileStatement($stmt);
        }

        // Mirror each top-level var slot's final value back onto
        // globalEnv. Without this, a `var $262 = {}` at top level would
        // populate the frame slot only; the binding is gone the moment
        // VM execution returns, and the next engine->eval() sees the
        // hoisted undefined placeholder. The capturesOuterLocal scan
        // already excluded the case where a still-pending closure
        // reads these names mid-execution, so a final write is enough
        // to keep subsequent eval()s consistent with the tree-walker.
        foreach ($topLevelVarSlots as $name => $slot) {
            $this->emit(Op::LOAD_LOCAL, $slot);
            $this->emit(Op::STORE_NAME, $this->internName($name));
        }

        $this->emit(Op::LOAD_LOCAL, $resultSlot);
        $this->emit(Op::RET);

        $needsThis = in_array(Op::LOAD_THIS, $this->code, true);
        $cf = new CompiledFunction(
            code: $this->code,
            consts: $this->consts,
            names: $this->names,
            localNames: $this->localNames,
            paramSlots: $this->paramSlots,
            slotCount: max(1, count($this->localNames)),
            nestedFns: $this->nestedFns,
            needsThis: $needsThis,
            // Top-level frame is the globalEnv directly; no per-call
            // child env is allocated by the program runner, so the
            // canSkipEnvAlloc bit on JsFunction call paths doesn't
            // apply here.
            needsArgsBinding: false,
            canSkipEnvAlloc: false,
        );
        $cf->handlers = $this->handlers;
        $cf->classNodes = $this->classNodes;
        $cf->callMethodDisplays = $this->callMethodDisplays;
        return $cf;
    }

    /**
     * Walk a top-level statement and reserve frame slots for every var
     * binding it introduces. Differs from collectStatementLocals in that
     * FunctionDeclaration is intentionally NOT assigned a frame slot:
     * function-decl bindings live on globalEnv (installed by
     * Interpreter::hoistDeclarations before the VM runs), so closures
     * inside the function body can resolve them via the env chain.
     */
    private function collectProgramVarLocals(Node $stmt): void
    {
        if ($stmt instanceof VariableDeclaration) {
            if ($stmt->kind !== 'var') {
                // compileProgram already validated; defensive only.
                throw new CompilerBailout('non-var top-level decl');
            }
            foreach ($stmt->declarations as $decl) {
                if (!($decl->id instanceof Identifier)) {
                    throw new CompilerBailout('top-level destructuring var');
                }
                $name = $decl->id->name;
                $this->varDeclaredNames[$name] = true;
                // §B.3.2: A FunctionDeclaration with the same name as a
                // var shadows the var binding (the FD writes globalEnv,
                // the var binding is a no-op except for any initializer).
                // If we assign a frame slot here, LOAD_NAME(name) inside
                // bytecode would read the slot (undefined) and miss the
                // hoisted function on globalEnv. Leave it to globalEnv
                // entirely; the VariableDeclarator's optional initializer
                // is handled at statement-compile time via a normal
                // STORE_NAME path.
                if (isset($this->fnDeclShadowedVarNames[$name])) {
                    continue;
                }
                $this->declareLocal($name);
            }
            return;
        }
        if ($stmt instanceof FunctionDeclaration) {
            // Skip: pre-hoisted onto globalEnv by Interpreter::execute.
            return;
        }
        if ($stmt instanceof BlockStatement) {
            foreach ($stmt->body as $inner) {
                $this->collectProgramVarLocals($inner);
            }
            return;
        }
        if ($stmt instanceof IfStatement) {
            $this->collectProgramVarLocals($stmt->consequent);
            if ($stmt->alternate !== null) {
                $this->collectProgramVarLocals($stmt->alternate);
            }
            return;
        }
        if ($stmt instanceof ForStatement) {
            if ($stmt->init instanceof VariableDeclaration) {
                if ($stmt->init->kind !== 'var') {
                    throw new CompilerBailout('top-level let/const for-init');
                }
                foreach ($stmt->init->declarations as $decl) {
                    if (!($decl->id instanceof Identifier)) {
                        throw new CompilerBailout('top-level destructuring for-init');
                    }
                    $name = $decl->id->name;
                    $this->varDeclaredNames[$name] = true;
                    if (isset($this->fnDeclShadowedVarNames[$name])) {
                        continue;
                    }
                    $this->declareLocal($name);
                }
            }
            $this->collectProgramVarLocals($stmt->body);
            return;
        }
        if ($stmt instanceof WhileStatement || $stmt instanceof DoWhileStatement) {
            $this->collectProgramVarLocals($stmt->body);
            return;
        }
        if ($stmt instanceof \PhpJs\Ast\Statement\LabeledStatement) {
            $this->collectProgramVarLocals($stmt->body);
            return;
        }
        if ($stmt instanceof \PhpJs\Ast\Statement\TryStatement) {
            foreach ($stmt->block->body as $inner) {
                $this->collectProgramVarLocals($inner);
            }
            if ($stmt->handler !== null) {
                if ($stmt->handler->param instanceof Identifier) {
                    $catchName = $stmt->handler->param->name;
                    if (isset($this->localSlots[$catchName])) {
                        throw new CompilerBailout('catch param shadows top-level var');
                    }
                    $this->declareLocal($catchName);
                }
                foreach ($stmt->handler->body->body as $inner) {
                    $this->collectProgramVarLocals($inner);
                }
            }
            if ($stmt->finalizer !== null) {
                foreach ($stmt->finalizer->body as $inner) {
                    $this->collectProgramVarLocals($inner);
                }
            }
            return;
        }
        // ExpressionStatement / ReturnStatement / ThrowStatement / etc.
        // introduce no new bindings; nothing to declare here.
    }

    /**
     * Walk the body looking for features the compiler refuses to
     * handle. Mirrors the tree-walker's lazy-arguments / no-eval
     * guarantees so the VM's optimised resolution paths stay safe.
     *
     * @param BlockStatement $body
     */
    private function ensureNoBailoutFeatures(BlockStatement $body): void
    {
        $this->scanBailout($body->body);
    }

    /**
     * Recursive scan for AssignmentExpression nodes whose target is a
     * MemberExpression rooted at `this`, `globalThis`, `window`,
     * `self`, or `global`. These writes go through the global object
     * which is the SAME binding store that holds the engine's top-level
     * var bindings; if we keep those vars in frame slots, subsequent
     * reads of the var name would see the frame slot rather than the
     * just-written global. Returns true on the first match. Nested
     * functions are skipped because their writes go to the FUNCTION's
     * local env first.
     *
     * @param Node[] $body
     */
    private function programWritesThroughGlobalAlias(array $body): bool
    {
        foreach ($body as $stmt) {
            if ($this->nodeWritesThroughGlobalAlias($stmt)) {
                return true;
            }
        }
        return false;
    }

    private function nodeWritesThroughGlobalAlias(?Node $node): bool
    {
        if ($node === null) {
            return false;
        }
        if (
            $node instanceof \PhpJs\Ast\Expression\FunctionExpression
            || $node instanceof \PhpJs\Ast\Expression\ArrowFunction
            || $node instanceof FunctionDeclaration
            || $node instanceof \PhpJs\Ast\Expression\ClassExpression
            || $node instanceof \PhpJs\Ast\Declaration\ClassDeclaration
        ) {
            // Writes inside a nested function bind that function's
            // local env, not the program scope. Stop here.
            return false;
        }
        if ($node instanceof \PhpJs\Ast\Expression\AssignmentExpression) {
            if (self::isGlobalAliasMember($node->left)) {
                return true;
            }
        }
        $ref = new \ReflectionObject($node);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                if ($this->nodeWritesThroughGlobalAlias($value)) {
                    return true;
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node && $this->nodeWritesThroughGlobalAlias($item)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * True when $node is a MemberExpression whose base resolves to the
     * global object (this in sloppy program scope, or one of the named
     * aliases). The classic test262 pattern is
     * `this['__declared_var'] = "v"` followed by a `var __declared_var`
     * that the bytecode would lower to a frame slot.
     */
    private static function isGlobalAliasMember(Node $node): bool
    {
        if (!($node instanceof \PhpJs\Ast\Expression\MemberExpression)) {
            return false;
        }
        $obj = $node->object;
        if ($obj instanceof \PhpJs\Ast\Expression\ThisExpression) {
            return true;
        }
        if ($obj instanceof Identifier && in_array($obj->name, ['globalThis', 'window', 'self', 'global'], true)) {
            return true;
        }
        return false;
    }

    /**
     * @param Node[]|Node|null $statements
     */
    private function scanBailout(mixed $statements): void
    {
        if ($statements === null) {
            return;
        }
        if (is_array($statements)) {
            foreach ($statements as $s) {
                $this->scanBailout($s);
            }
            return;
        }
        $node = $statements;
        if ($node instanceof Identifier && ($node->name === 'eval' || $node->name === 'arguments')) {
            // `arguments` in body forces argsObj construction; let
            // the tree-walker keep that path. `eval` is dynamic.
            throw new CompilerBailout('uses ' . $node->name);
        }
        if ($node instanceof Identifier && $node->name === '[[NewTarget]]') {
            // `new.target` reads need an env binding the VM-compiled
            // prologue skips. Bail to keep the prologue-skip optimization
            // safe for the common case.
            throw new CompilerBailout('uses new.target');
        }
        if ($node instanceof \PhpJs\Ast\Statement\WithStatement) {
            throw new CompilerBailout('with statement');
        }
        if ($node instanceof \PhpJs\Ast\Statement\TryStatement) {
            // try / catch (Phase 1) or try / finally / try-catch-finally
            // (Phase 2). Phase 2's finally support requires that no
            // abrupt completion (return / break / continue) escapes the
            // protected blocks; that's enforced lazily inside
            // compileStatement via finallyLoopBoundaries, so here we
            // only require the structure to be one of the supported
            // shapes.
            if ($node->handler === null && $node->finalizer === null) {
                throw new CompilerBailout('try without catch or finally');
            }
            // Optional catch binding (`catch { ... }`) is fine; a
            // pattern-bound catch parameter (`catch ({a, b}) { ... }`)
            // would need pattern destructuring on the thrown value
            // before the body runs. Phase 1 only covers identifier
            // params.
            if (
                $node->handler !== null
                && $node->handler->param !== null
                && !($node->handler->param instanceof \PhpJs\Ast\Expression\Identifier)
            ) {
                throw new CompilerBailout('destructuring catch param');
            }
            // Recurse into the protected blocks so any inner bailout
            // feature still aborts compilation up front.
            $this->scanBailout($node->block->body);
            if ($node->handler !== null) {
                $this->scanBailout($node->handler->body->body);
            }
            if ($node->finalizer !== null) {
                $this->scanBailout($node->finalizer->body);
            }
            return;
        }
        if (
            $node instanceof \PhpJs\Ast\Expression\YieldExpression
            || $node instanceof \PhpJs\Ast\Expression\AwaitExpression
        ) {
            throw new CompilerBailout('yield/await');
        }
        // Arrow functions don't have their own arguments / this /
        // new.target — they capture the enclosing function's bindings
        // through the lexical environment. The compiled prologue
        // skips installing those per-call slots when the function's
        // own body doesn't reference them, so an arrow nested in this
        // body that DOES reference arguments would resolve through a
        // stale or missing binding at runtime. Scan the arrow body
        // for those references and bail compilation if found.
        if ($node instanceof \PhpJs\Ast\Expression\ArrowFunction) {
            // Arrows still need their body scanned for the outer
            // function's arguments / this / new.target usage (see
            // comment above) AND for an indirect/direct eval call
            // that would observe top-level vars the program-exit
            // mirror loop hasn't written yet.
            if (self::nodeReferencesEval($node)) {
                throw new CompilerBailout('nested arrow references eval');
            }
            $this->scanBailout($node->body);
            return;
        }
        // Other nested bodies (regular functions, classes) get their
        // own per-call arguments / this binding scopes, so the outer
        // function's compile is unaffected by their identifier usage.
        // BUT: an `eval` reference inside a nested body is still a
        // compile-blocker for the top-level program, because direct
        // or indirect eval at runtime needs to observe top-level var
        // bindings via globalEnv. The compiler mirrors those bindings
        // only at program exit (after RET), so a function body that
        // calls eval (directly or as `(1, eval)(...)`) at runtime
        // would see stale globals. Recurse just enough to detect any
        // eval reference and bail compilation if found.
        if (
            $node instanceof \PhpJs\Ast\Expression\FunctionExpression
            || $node instanceof \PhpJs\Ast\Declaration\FunctionDeclaration
        ) {
            if (self::nodeReferencesEval($node)) {
                throw new CompilerBailout('nested function references eval');
            }
            return;
        }
        if (
            $node instanceof \PhpJs\Ast\Expression\ClassExpression
            || $node instanceof \PhpJs\Ast\Declaration\ClassDeclaration
        ) {
            return;
        }
        $ref = new \ReflectionObject($node);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node || is_array($value)) {
                $this->scanBailout($value);
            }
        }
    }

    /**
     * Walk $node and any nested functions/classes looking for an
     * Identifier whose name is `eval`. Used to detect indirect or
     * direct eval references inside nested function bodies so the
     * top-level program compile can bail before mirroring vars too
     * late for the runtime eval.
     */
    private static function nodeReferencesEval(Node $node): bool
    {
        if ($node instanceof Identifier && $node->name === 'eval') {
            return true;
        }
        $ref = new \ReflectionObject($node);
        foreach ($ref->getProperties() as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                if (self::nodeReferencesEval($value)) {
                    return true;
                }
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node && self::nodeReferencesEval($item)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * @param Node[] $statements
     */
    private function collectFunctionLocals(array $statements): void
    {
        foreach ($statements as $stmt) {
            $this->collectStatementLocals($stmt);
        }
    }

    private function collectStatementLocals(Node $stmt): void
    {
        if ($stmt instanceof VariableDeclaration) {
            $isLexical = $stmt->kind === 'let' || $stmt->kind === 'const';
            foreach ($stmt->declarations as $decl) {
                if ($decl->id instanceof Identifier) {
                    // let/const shadowing an existing slot (param or
                    // earlier var) would need separate slot allocation
                    // per scope, which the flat-slot compiler doesn't
                    // model. Bail.
                    if ($isLexical && isset($this->localSlots[$decl->id->name])) {
                        throw new CompilerBailout('let/const shadowing existing local');
                    }
                    if (!$isLexical) {
                        $this->varDeclaredNames[$decl->id->name] = true;
                    }
                    $this->declareLocal($decl->id->name);
                    continue;
                }
                if ($decl->id instanceof ObjectPattern) {
                    foreach ($decl->id->properties as $prop) {
                        if (
                            $prop instanceof \PhpJs\Ast\Pattern\AssignmentProperty
                            && !$prop->computed
                            && !($prop->value instanceof \PhpJs\Ast\Pattern\AssignmentPattern)
                            && $prop->value instanceof Identifier
                        ) {
                            if ($isLexical && isset($this->localSlots[$prop->value->name])) {
                                throw new CompilerBailout('let/const pattern shadowing');
                            }
                            $this->declareLocal($prop->value->name);
                            continue;
                        }
                        throw new CompilerBailout('complex object pattern');
                    }
                    continue;
                }
                throw new CompilerBailout('destructuring declaration');
            }
            return;
        }
        if ($stmt instanceof FunctionDeclaration) {
            // Function declarations are hoisted: assign a slot for
            // the binding name. The VM materialises the JsFunction
            // value when the declaration statement runs.
            if ($stmt->id !== null) {
                $this->declareLocal($stmt->id->name);
            }
            return;
        }
        if ($stmt instanceof BlockStatement) {
            $this->collectFunctionLocals($stmt->body);
            return;
        }
        if ($stmt instanceof IfStatement) {
            $this->collectStatementLocals($stmt->consequent);
            if ($stmt->alternate !== null) {
                $this->collectStatementLocals($stmt->alternate);
            }
            return;
        }
        if ($stmt instanceof ForStatement) {
            if ($stmt->init instanceof VariableDeclaration) {
                $isLexical = $stmt->init->kind === 'let' || $stmt->init->kind === 'const';
                foreach ($stmt->init->declarations as $decl) {
                    if (!($decl->id instanceof Identifier)) {
                        throw new CompilerBailout('destructuring for-init');
                    }
                    if (!$isLexical) {
                        $this->varDeclaredNames[$decl->id->name] = true;
                    }
                    $this->declareLocal($decl->id->name);
                }
            }
            $this->collectStatementLocals($stmt->body);
            return;
        }
        if ($stmt instanceof WhileStatement || $stmt instanceof DoWhileStatement) {
            $this->collectStatementLocals($stmt->body);
            return;
        }
        if ($stmt instanceof \PhpJs\Ast\Statement\LabeledStatement) {
            $this->collectStatementLocals($stmt->body);
            return;
        }
        if ($stmt instanceof \PhpJs\Ast\Declaration\ClassDeclaration) {
            // Class declarations create a let-style binding under the
            // class name. Allocate a slot just like a function decl;
            // the actual class object is built by MAKE_CLASS at run
            // time and stored via STORE_LOCAL.
            if ($stmt->id !== null && !isset($this->localSlots[$stmt->id->name])) {
                $this->declareLocal($stmt->id->name);
            }
            return;
        }
        if ($stmt instanceof \PhpJs\Ast\Statement\TryStatement) {
            $this->collectFunctionLocals($stmt->block->body);
            if ($stmt->handler !== null) {
                if ($stmt->handler->param instanceof \PhpJs\Ast\Expression\Identifier) {
                    $catchName = $stmt->handler->param->name;
                    if (isset($this->localSlots[$catchName])) {
                        // catch (a) where `a` is already a local would
                        // alias the outer binding's slot. Per spec the
                        // catch param introduces a fresh block-scoped
                        // binding that shadows outer names; the slot-based
                        // compiler can't model that — bail to the tree
                        // walker.
                        throw new CompilerBailout('catch param shadows outer local');
                    }
                    $this->declareLocal($catchName);
                }
                $this->collectFunctionLocals($stmt->handler->body->body);
            }
            if ($stmt->finalizer !== null) {
                $this->collectFunctionLocals($stmt->finalizer->body);
            }
            return;
        }
        // ExpressionStatement / ReturnStatement / ThrowStatement / etc:
        // no new bindings.
    }

    /**
     * Bail if any let/const-declared name has a reference in the
     * function body that lexically precedes its declaration, since
     * the slot-based compiler can't enforce TDZ for those reads.
     *
     * @param Node[] $body
     */
    private function ensureNoTdzViolations(array $body): void
    {
        // Collect (name → declaration index) for every let/const at
        // any depth; index reflects source order across the flat
        // function-body sequence.
        $declOrder = [];
        $idx = 0;
        $this->collectLetConstOrder($body, $declOrder, $idx);
        if ($declOrder === []) {
            return;
        }
        // Walk the body again and gate every Identifier reference
        // against the declaration index. A reference whose visit
        // index is < the declaration index is a TDZ candidate.
        $idx = 0;
        $this->checkTdzReferences($body, $declOrder, $idx);
    }

    /**
     * @param Node[] $body
     * @param array<string,int> $out
     */
    private function collectLetConstOrder(array $body, array &$out, int &$idx): void
    {
        foreach ($body as $stmt) {
            $this->collectLetConstOrderNode($stmt, $out, $idx);
        }
    }

    /**
     * @param array<string,int> $out
     */
    private function collectLetConstOrderNode(Node $node, array &$out, int &$idx): void
    {
        $idx++;
        if ($node instanceof VariableDeclaration && ($node->kind === 'let' || $node->kind === 'const')) {
            // Per spec, the binding is in TDZ until the init
            // expression has fully executed. Visit the init first so
            // its identifier indices are < the declaration index.
            foreach ($node->declarations as $decl) {
                if ($decl->init !== null) {
                    $this->collectLetConstOrderNode($decl->init, $out, $idx);
                }
                if (
                    $decl->id instanceof Identifier
                    && !isset($out[$decl->id->name])
                    && !isset($this->varDeclaredNames[$decl->id->name])
                ) {
                    $idx++;
                    $out[$decl->id->name] = $idx;
                }
            }
            return;
        }
        // Stop at function/class boundaries — separate scope.
        if (
            $node instanceof \PhpJs\Ast\Expression\FunctionExpression
            || $node instanceof \PhpJs\Ast\Expression\ArrowFunction
            || $node instanceof FunctionDeclaration
            || $node instanceof \PhpJs\Ast\Expression\ClassExpression
            || $node instanceof \PhpJs\Ast\Declaration\ClassDeclaration
        ) {
            return;
        }
        // Mirror checkTdzReferencesNode: skip non-reference identifier
        // positions so the index stays in sync between the two walks.
        if ($node instanceof MemberExpression && !$node->computed) {
            $this->collectLetConstOrderNode($node->object, $out, $idx);
            return;
        }
        if ($node instanceof Property && !$node->computed) {
            $this->collectLetConstOrderNode($node->value, $out, $idx);
            return;
        }
        $ref = new \ReflectionObject($node);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                $this->collectLetConstOrderNode($value, $out, $idx);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        $this->collectLetConstOrderNode($item, $out, $idx);
                    }
                }
            }
        }
    }

    /**
     * @param Node[] $body
     * @param array<string,int> $declOrder
     */
    private function checkTdzReferences(array $body, array $declOrder, int &$idx): void
    {
        foreach ($body as $stmt) {
            $this->checkTdzReferencesNode($stmt, $declOrder, $idx);
        }
    }

    /**
     * @param array<string,int> $declOrder
     */
    private function checkTdzReferencesNode(Node $node, array $declOrder, int &$idx): void
    {
        $idx++;
        $myIdx = $idx;
        if ($node instanceof Identifier && isset($declOrder[$node->name])) {
            if ($myIdx < $declOrder[$node->name]) {
                throw new CompilerBailout('let/const used before declaration: ' . $node->name);
            }
        }
        if ($node instanceof VariableDeclaration && ($node->kind === 'let' || $node->kind === 'const')) {
            // Mirror collectLetConstOrder's traversal: visit the init
            // first so identifier indices inside it are < the
            // declaration index. Skip the binding-id Identifier of
            // each declarator (that's the new name, not a reference).
            foreach ($node->declarations as $decl) {
                if ($decl->init !== null) {
                    $this->checkTdzReferencesNode($decl->init, $declOrder, $idx);
                }
                if ($decl->id instanceof Identifier) {
                    $idx++;
                } elseif ($decl->id instanceof Node) {
                    // Destructuring decl id — bailout already triggers.
                    $this->checkTdzReferencesNode($decl->id, $declOrder, $idx);
                }
            }
            return;
        }
        if (
            $node instanceof \PhpJs\Ast\Expression\FunctionExpression
            || $node instanceof \PhpJs\Ast\Expression\ArrowFunction
            || $node instanceof FunctionDeclaration
            || $node instanceof \PhpJs\Ast\Expression\ClassExpression
            || $node instanceof \PhpJs\Ast\Declaration\ClassDeclaration
        ) {
            // Closures: any reference to outer let/const we haven't
            // yet declared would race with slot init at call time.
            $this->scanIdentifiersForTdz($node, $declOrder, $myIdx);
            return;
        }
        // MemberExpression `obj.prop`: the `property` is a name, not
        // an identifier reference. Visiting it walks the variable
        // declaration index past `prop` and (worse) raises spurious
        // "let used before decl" errors when a property happens to
        // share a name with a later let/const binding (e.g. `arr.length`
        // alongside a hot-loop `let length`).
        if ($node instanceof MemberExpression && !$node->computed) {
            $this->checkTdzReferencesNode($node->object, $declOrder, $idx);
            return;
        }
        // ObjectExpression property keys: same story. `{length: 0}`
        // shouldn't count as a reference to a hoisted `length`. Only
        // the value side carries a reference.
        if ($node instanceof Property && !$node->computed) {
            $this->checkTdzReferencesNode($node->value, $declOrder, $idx);
            return;
        }
        $ref = new \ReflectionObject($node);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                $this->checkTdzReferencesNode($value, $declOrder, $idx);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        $this->checkTdzReferencesNode($item, $declOrder, $idx);
                    }
                }
            }
        }
    }

    /**
     * Scan an inner closure for identifier references to outer let/
     * const bindings; if any are still pre-declaration, bail.
     *
     * @param array<string,int> $declOrder
     */
    private function scanIdentifiersForTdz(Node $node, array $declOrder, int $closurePos): void
    {
        if ($node instanceof Identifier) {
            if (isset($declOrder[$node->name]) && $closurePos < $declOrder[$node->name]) {
                throw new CompilerBailout('inner closure refs let/const pre-decl: ' . $node->name);
            }
            return;
        }
        if ($node instanceof MemberExpression && !$node->computed) {
            $this->scanIdentifiersForTdz($node->object, $declOrder, $closurePos);
            return;
        }
        if ($node instanceof Property && !$node->computed) {
            $this->scanIdentifiersForTdz($node->value, $declOrder, $closurePos);
            return;
        }
        $ref = new \ReflectionObject($node);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                $this->scanIdentifiersForTdz($value, $declOrder, $closurePos);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        $this->scanIdentifiersForTdz($item, $declOrder, $closurePos);
                    }
                }
            }
        }
    }

    private function declareLocal(string $name): int
    {
        if (isset($this->localSlots[$name])) {
            return $this->localSlots[$name];
        }
        $slot = count($this->localNames);
        $this->localNames[] = $name;
        $this->localSlots[$name] = $slot;
        return $slot;
    }

    private function emit(int $op, int ...$operands): void
    {
        $this->code[] = $op;
        foreach ($operands as $o) {
            $this->code[] = $o;
        }
    }

    /**
     * Emit a STORE_LOCAL to $slot. When $slot is a top-level program
     * var slot, also mirror the value onto globalEnv via STORE_NAME so
     * mid-program reads of globalThis.<name> (e.g. from Function-
     * constructor bodies) see the live value. Spec-required: per ES,
     * top-level `var` creates a property on the global object whose
     * value is updated synchronously by the var initializer / any
     * subsequent assignment.
     */
    private function emitStoreLocal(int $slot): void
    {
        $this->emit(Op::STORE_LOCAL, $slot);
        if (isset($this->programVarSlots[$slot])) {
            $this->emit(Op::LOAD_LOCAL, $slot);
            $this->emit(Op::STORE_NAME, $this->internName($this->programVarSlots[$slot]));
        }
    }

    /**
     * Best-effort source rendering for a method-call callee
     * (`obj.prop`). Mirrors Interpreter::renderCalleeNode so the
     * VM-compiled and tree-walker paths produce the same TypeError
     * text. Returns null when the receiver shape doesn't have an
     * obvious literal form, in which case the VM falls back to its
     * legacy stringification.
     */
    private function renderMethodCallDisplay(MemberExpression $callee): ?string
    {
        if ($callee->computed) {
            return null;
        }
        if (!($callee->property instanceof Identifier)) {
            return null;
        }
        $obj = $this->renderCalleeObject($callee->object);
        if ($obj === null) {
            return null;
        }
        return $obj . '.' . $callee->property->name;
    }

    private function renderCalleeObject(Node $node): ?string
    {
        if ($node instanceof Identifier) {
            return $node->name;
        }
        if ($node instanceof ThisExpression) {
            return 'this';
        }
        if ($node instanceof MemberExpression && !$node->computed && $node->property instanceof Identifier) {
            $parent = $this->renderCalleeObject($node->object);
            if ($parent === null) {
                return null;
            }
            return $parent . '.' . $node->property->name;
        }
        if ($node instanceof ArrayExpression && $node->elements === []) {
            return '[]';
        }
        if ($node instanceof ObjectExpression && $node->properties === []) {
            return '({})';
        }
        return null;
    }

    /**
     * Reserve a placeholder operand for a forward jump and return its
     * index so the caller can patch it once the target offset is known.
     */
    private function emitJump(int $op): int
    {
        $this->code[] = $op;
        $this->code[] = 0; // placeholder
        return count($this->code) - 1;
    }

    private function patchJumpToHere(int $operandIndex): void
    {
        // Operand semantics: pc += operand. The dispatcher reads the
        // operand at $pc + 1 then advances $pc += operand. So the
        // operand is "from the opcode pc to the target pc". Compute
        // target - opcode_pc.
        $opcodePc = $operandIndex - 1;
        $targetPc = count($this->code);
        $this->code[$operandIndex] = $targetPc - $opcodePc;
    }

    private function compileStatement(Node $node): void
    {
        if ($node instanceof ReturnStatement) {
            if ($this->finallyLoopBoundaries !== []) {
                throw new CompilerBailout('return inside try-with-finally');
            }
            if ($node->argument === null) {
                $this->emit(Op::LOAD_UNDEF);
            } else {
                $this->compileExpression($node->argument);
            }
            $this->emit(Op::RET);
            return;
        }
        if ($node instanceof ExpressionStatement) {
            if ($this->tryEmitDiscardedUpdate($node->expression)) {
                return;
            }
            $this->compileExpression($node->expression);
            $this->emit(Op::POP);
            return;
        }
        if ($node instanceof BlockStatement) {
            foreach ($node->body as $inner) {
                $this->compileStatement($inner);
            }
            return;
        }
        if ($node instanceof EmptyStatement) {
            return;
        }
        if ($node instanceof IfStatement) {
            $this->compileIf($node);
            return;
        }
        if ($node instanceof ForStatement) {
            $this->compileFor($node);
            return;
        }
        if ($node instanceof WhileStatement) {
            $this->compileWhile($node);
            return;
        }
        if ($node instanceof DoWhileStatement) {
            $this->compileDoWhile($node);
            return;
        }
        if ($node instanceof BreakStatement) {
            if ($node->label !== null) {
                throw new CompilerBailout('labeled break');
            }
            if ($this->loopStack === []) {
                throw new CompilerBailout('break outside loop');
            }
            $loopDepth = count($this->loopStack);
            foreach ($this->finallyLoopBoundaries as $boundary) {
                if ($boundary >= $loopDepth) {
                    throw new CompilerBailout('break escaping try-with-finally');
                }
            }
            $idx = $this->emitJump(Op::JUMP);
            $this->loopStack[$loopDepth - 1]['breaks'][] = $idx;
            return;
        }
        if ($node instanceof ContinueStatement) {
            if ($node->label !== null) {
                throw new CompilerBailout('labeled continue');
            }
            if ($this->loopStack === []) {
                throw new CompilerBailout('continue outside loop');
            }
            $loopDepth = count($this->loopStack);
            foreach ($this->finallyLoopBoundaries as $boundary) {
                if ($boundary >= $loopDepth) {
                    throw new CompilerBailout('continue escaping try-with-finally');
                }
            }
            $idx = $this->emitJump(Op::JUMP);
            $this->loopStack[$loopDepth - 1]['continues'][] = $idx;
            return;
        }
        if ($node instanceof VariableDeclaration) {
            $this->compileVarDecl($node);
            return;
        }
        if ($node instanceof ThrowStatement) {
            $this->compileExpression($node->argument);
            $this->emit(Op::THROW);
            return;
        }
        if ($node instanceof \PhpJs\Ast\Statement\TryStatement) {
            $this->compileTryCatch($node);
            return;
        }
        if ($node instanceof \PhpJs\Ast\Declaration\ClassDeclaration) {
            // Classes that reference outer locals (e.g. `class C extends
            // OuterParam { ... }`) need env-based resolution for those
            // names. The VM-compiled body keeps locals in frame slots,
            // so the class body would fail to find them. Bail to the
            // tree-walker for now.
            if ($this->capturesOuterLocal($node)) {
                throw new CompilerBailout('class captures outer local');
            }
            $idx = count($this->classNodes);
            $this->classNodes[] = $node;
            $this->emit(Op::MAKE_CLASS, $idx);
            if ($node->id !== null) {
                $this->emit(Op::STORE_LOCAL, $this->localSlots[$node->id->name]);
            } else {
                $this->emit(Op::POP);
            }
            return;
        }
        throw new CompilerBailout('unsupported statement: ' . $node->type());
    }

    /**
     * Lower a TryStatement to a bytecode block plus handler-table
     * entries. Three shapes are supported:
     *
     *  - try / catch (Phase 1): one handler protecting the try body;
     *    on throw, the VM stores the value in the catch param slot
     *    and jumps to the catch body. Normal exit JUMPs past catch.
     *
     *  - try / finally (Phase 2): the finally body is inlined twice —
     *    once on the normal exit path, once on the exception-rethrow
     *    path that runs after the handler captures the thrown value.
     *
     *  - try / catch / finally (Phase 2): both handlers are emitted.
     *    Handler1 covers the try body and routes the exception
     *    through the catch body, then to the normal-finally inline
     *    (the catch already swallowed the original exception).
     *    Handler2 covers the catch body and routes any catch-body
     *    exception to the rethrow-finally inline so the catch's
     *    exception still surfaces after finally runs.
     *
     * Phase 2 enforces (via finallyLoopBoundaries / finallyDepth) that
     * no return / break / continue escapes the protected blocks; those
     * cases bail to the tree-walker because the inlined-finally
     * approach would otherwise need to inline at every exit point.
     */
    private function compileTryCatch(\PhpJs\Ast\Statement\TryStatement $node): void
    {
        $hasFinally = $node->finalizer !== null;
        $hasCatch = $node->handler !== null;
        $stackBase = $this->tryEntryStackDepth;
        $finallyBoundary = count($this->loopStack);

        // Phase 1 fast path: try / catch with no finalizer. The
        // implementation is the original Phase 1 layout.
        if (!$hasFinally) {
            $tryStart = count($this->code);
            foreach ($node->block->body as $stmt) {
                $this->compileStatement($stmt);
            }
            $tryEnd = count($this->code);
            $jmpPastCatch = $this->emitJump(Op::JUMP);
            $catchPc = count($this->code);
            $exceptionSlot = -1;
            if ($node->handler->param instanceof \PhpJs\Ast\Expression\Identifier) {
                $name = $node->handler->param->name;
                $exceptionSlot = $this->localSlots[$name]
                    ?? $this->declareLocal($name);
            }
            foreach ($node->handler->body->body as $stmt) {
                $this->compileStatement($stmt);
            }
            $this->patchJumpToHere($jmpPastCatch);
            $this->handlers[] = new HandlerEntry(
                tryStart: $tryStart,
                tryEnd: $tryEnd,
                catchPc: $catchPc,
                exceptionSlot: $exceptionSlot,
                stackBase: $stackBase,
            );
            return;
        }

        // Phase 2: finalizer present (with or without catch).
        // Layout (annotated PCs):
        //   [tryStart..tryEnd)          : try body
        //   tryEnd                      : JUMP -> finallyNormalPc
        //   tryHandlerCatchPc           : (catch present) catch param
        //                                 mirror + catch body, then
        //                                 JUMP -> finallyNormalPc
        //                                 (no catch) JUMP -> finallyRethrowPc
        //   catchHandlerCatchPc         : JUMP -> finallyRethrowPc
        //   finallyNormalPc             : finally body, JUMP -> afterTryPc
        //   finallyRethrowPc            : finally body, LOAD rethrowSlot, THROW
        //   afterTryPc                  : continuation
        $rethrowSlot = count($this->localNames);
        $this->localNames[] = '[[finallyRethrow]]';

        $tryStart = count($this->code);
        $this->finallyLoopBoundaries[] = $finallyBoundary;
        foreach ($node->block->body as $stmt) {
            $this->compileStatement($stmt);
        }
        array_pop($this->finallyLoopBoundaries);
        $tryEnd = count($this->code);
        $jmpFromTry = $this->emitJump(Op::JUMP);

        $tryHandlerCatchPc = count($this->code);
        $tryHandlerExceptionSlot = -1;
        $catchStart = -1;
        $catchEnd = -1;
        $jmpFromCatch = -1;
        $jmpFromTryHandlerNoCatch = -1;

        if ($hasCatch) {
            // Handler1 stores the thrown value in the catch param
            // slot. Mirror it into rethrowSlot so a catch-body throw
            // can later observe its OWN exception, not the original.
            if ($node->handler->param instanceof \PhpJs\Ast\Expression\Identifier) {
                $name = $node->handler->param->name;
                $tryHandlerExceptionSlot = $this->localSlots[$name]
                    ?? $this->declareLocal($name);
                $this->emit(Op::LOAD_LOCAL, $tryHandlerExceptionSlot);
                $this->emit(Op::STORE_LOCAL, $rethrowSlot);
            } else {
                // No catch param: still need to seed rethrowSlot from
                // the param slot so handler2 can re-throw on
                // catch-body exceptions. Allocate a temp.
                $tryHandlerExceptionSlot = count($this->localNames);
                $this->localNames[] = '[[catchTmp]]';
                $this->emit(Op::LOAD_LOCAL, $tryHandlerExceptionSlot);
                $this->emit(Op::STORE_LOCAL, $rethrowSlot);
            }
            $catchStart = count($this->code);
            $this->finallyLoopBoundaries[] = $finallyBoundary;
            foreach ($node->handler->body->body as $stmt) {
                $this->compileStatement($stmt);
            }
            array_pop($this->finallyLoopBoundaries);
            $catchEnd = count($this->code);
            $jmpFromCatch = $this->emitJump(Op::JUMP);
        } else {
            // No catch: handler1 stores the thrown value directly in
            // rethrowSlot, then jumps to the rethrow-finally inline.
            $tryHandlerExceptionSlot = $rethrowSlot;
            $jmpFromTryHandlerNoCatch = $this->emitJump(Op::JUMP);
        }

        // Handler2 (only meaningful if there's a catch): catches
        // exceptions thrown inside the catch body and routes them
        // through finally before re-raising.
        $catchHandlerCatchPc = $hasCatch ? count($this->code) : -1;
        $jmpFromHandler2 = -1;
        if ($hasCatch) {
            $jmpFromHandler2 = $this->emitJump(Op::JUMP);
        }

        // Finally inlines.
        $finallyNormalPc = count($this->code);
        $this->finallyLoopBoundaries[] = $finallyBoundary;
        foreach ($node->finalizer->body as $stmt) {
            $this->compileStatement($stmt);
        }
        array_pop($this->finallyLoopBoundaries);
        $jmpAfterTry = $this->emitJump(Op::JUMP);

        $finallyRethrowPc = count($this->code);
        $this->finallyLoopBoundaries[] = $finallyBoundary;
        foreach ($node->finalizer->body as $stmt) {
            $this->compileStatement($stmt);
        }
        array_pop($this->finallyLoopBoundaries);
        $this->emit(Op::LOAD_LOCAL, $rethrowSlot);
        $this->emit(Op::THROW);

        // afterTry. patchJumpToHere targets the current PC; the
        // jmpAfterTry was emitted right before this comment so it
        // already lines up.
        $this->patchJumpToHere($jmpAfterTry);
        $this->code[$jmpFromTry] = $finallyNormalPc - ($jmpFromTry - 1);
        if ($jmpFromCatch !== -1) {
            $this->code[$jmpFromCatch] = $finallyNormalPc - ($jmpFromCatch - 1);
        }
        if ($jmpFromTryHandlerNoCatch !== -1) {
            $this->code[$jmpFromTryHandlerNoCatch] =
                $finallyRethrowPc - ($jmpFromTryHandlerNoCatch - 1);
        }
        if ($jmpFromHandler2 !== -1) {
            $this->code[$jmpFromHandler2] =
                $finallyRethrowPc - ($jmpFromHandler2 - 1);
        }

        $this->handlers[] = new HandlerEntry(
            tryStart: $tryStart,
            tryEnd: $tryEnd,
            catchPc: $tryHandlerCatchPc,
            exceptionSlot: $tryHandlerExceptionSlot,
            stackBase: $stackBase,
        );
        if ($hasCatch) {
            $this->handlers[] = new HandlerEntry(
                tryStart: $catchStart,
                tryEnd: $catchEnd,
                catchPc: $catchHandlerCatchPc,
                exceptionSlot: $rethrowSlot,
                stackBase: $stackBase,
            );
        }
    }

    private function compileIf(IfStatement $node): void
    {
        $this->compileExpression($node->test);
        $jmpFalse = $this->emitJump(Op::JUMP_IF_FALSE);
        $this->compileStatement($node->consequent);
        if ($node->alternate !== null) {
            $jmpEnd = $this->emitJump(Op::JUMP);
            $this->patchJumpToHere($jmpFalse);
            $this->compileStatement($node->alternate);
            $this->patchJumpToHere($jmpEnd);
        } else {
            $this->patchJumpToHere($jmpFalse);
        }
    }

    /**
     * Detect a canonical for-loop test of the form `local op rhs`
     * where op is `<` or `<=` and rhs is either a numeric literal or
     * another local. Emits one of the fused 3-operand jump opcodes
     * so the dispatcher hits a single arm per iteration instead of
     * LOAD_LOCAL + (LOAD_LOCAL|LOAD_CONST) + LT|LE + JUMP_IF_FALSE.
     * Returns the opcode pc on success so the caller can patch the
     * offset cell at +3 once the loop body is compiled. Returns -1
     * for any unsupported test shape.
     */
    private function tryEmitFusedLoopTest(\PhpJs\Ast\Node $test): int
    {
        if (!$test instanceof BinaryExpression) {
            return -1;
        }
        $op = $test->operator;
        if ($op !== '<' && $op !== '<=') {
            return -1;
        }
        $left = $test->left;
        $right = $test->right;
        if (!$left instanceof Identifier || !isset($this->localSlots[$left->name])) {
            return -1;
        }
        $localSlot = $this->localSlots[$left->name];

        if ($right instanceof Literal && (is_int($right->value) || is_float($right->value))) {
            // Match compileLiteral's full-precision dedupe key so the
            // fused for-loop test never collides with the canonical
            // literal slot (e.g. `for (i = 0; i < 1; ...)` vs a stray
            // 1.0000000000000001 elsewhere in the same Program).
            $constIdx = $this->internConst(
                JsNumber::of((float) $right->value),
                'n:' . serialize((float) $right->value),
            );
            $opcodePc = count($this->code);
            $this->code[] = $op === '<' ? Op::JUMP_IF_LOCAL_GE_CONST : Op::JUMP_IF_LOCAL_GT_CONST;
            $this->code[] = $localSlot;
            $this->code[] = $constIdx;
            $this->code[] = 0; // placeholder offset
            return $opcodePc;
        }
        if ($right instanceof Identifier && isset($this->localSlots[$right->name])) {
            $rightSlot = $this->localSlots[$right->name];
            $opcodePc = count($this->code);
            $this->code[] = $op === '<' ? Op::JUMP_IF_LOCAL_GE_LOCAL : Op::JUMP_IF_LOCAL_GT_LOCAL;
            $this->code[] = $localSlot;
            $this->code[] = $rightSlot;
            $this->code[] = 0; // placeholder offset
            return $opcodePc;
        }
        return -1;
    }

    private function compileFor(ForStatement $node): void
    {
        // Init.
        if ($node->init !== null) {
            if ($node->init instanceof VariableDeclaration) {
                $this->compileVarDecl($node->init);
            } else {
                $this->compileExpression($node->init);
                $this->emit(Op::POP);
            }
        }
        $loopStart = count($this->code);
        // Test. Try the fused JUMP_IF_LOCAL_GE_CONST shortcut first
        // for the canonical `for (let i = 0; i < N; i++)` pattern.
        $jmpExit = -1;
        $fusedOpcodePc = -1;
        if ($node->test !== null) {
            $fusedOpcodePc = $this->tryEmitFusedLoopTest($node->test);
            if ($fusedOpcodePc === -1) {
                $this->compileExpression($node->test);
                $jmpExit = $this->emitJump(Op::JUMP_IF_FALSE);
            }
        }
        // Body.
        $this->loopStack[] = [
            'continue' => -1, // patched after body via patchContinues
            'breaks' => [],
            'continues' => [],
        ];
        $this->compileStatement($node->body);
        $continueTarget = count($this->code);
        // Update.
        if ($node->update !== null) {
            if (!$this->tryEmitDiscardedUpdate($node->update)) {
                $this->compileExpression($node->update);
                $this->emit(Op::POP);
            }
        }
        $this->emit(Op::JUMP, $loopStart - count($this->code) + 1);
        // Patch the JUMP we just emitted: operand is the second int
        // we wrote, so its operandIndex = count - 1 - 0 = count - 2.
        // Actually emit(Op::JUMP, X) wrote opcode then operand. The
        // operand cell index is len - 1 with the value already X.
        // Recompute relative jump: target - opcodePc.
        $jumpOpcodePc = count($this->code) - 2;
        $this->code[$jumpOpcodePc + 1] = $loopStart - $jumpOpcodePc;

        $loopExit = count($this->code);
        if ($jmpExit !== -1) {
            $this->patchJumpToHere($jmpExit);
        }
        if ($fusedOpcodePc !== -1) {
            // JUMP_IF_LOCAL_GE_CONST layout: opcode at fusedOpcodePc,
            // operands at +1, +2, +3. The dispatcher does pc += offset
            // where pc starts at the opcode position.
            $this->code[$fusedOpcodePc + 3] = $loopExit - $fusedOpcodePc;
        }
        $frame = array_pop($this->loopStack);
        foreach ($frame['continues'] as $idx) {
            $opcodePc = $idx - 1;
            $this->code[$idx] = $continueTarget - $opcodePc;
        }
        foreach ($frame['breaks'] as $idx) {
            $opcodePc = $idx - 1;
            $this->code[$idx] = $loopExit - $opcodePc;
        }
    }

    private function compileWhile(WhileStatement $node): void
    {
        $loopStart = count($this->code);
        $this->compileExpression($node->test);
        $jmpExit = $this->emitJump(Op::JUMP_IF_FALSE);
        $this->loopStack[] = ['continue' => -1, 'breaks' => [], 'continues' => []];
        $this->compileStatement($node->body);
        $continueTarget = count($this->code);
        $this->emit(Op::JUMP, 0);
        $jumpOpcodePc = count($this->code) - 2;
        $this->code[$jumpOpcodePc + 1] = $loopStart - $jumpOpcodePc;
        $loopExit = count($this->code);
        $this->patchJumpToHere($jmpExit);
        $frame = array_pop($this->loopStack);
        foreach ($frame['continues'] as $idx) {
            $opcodePc = $idx - 1;
            $this->code[$idx] = $continueTarget - $opcodePc;
        }
        foreach ($frame['breaks'] as $idx) {
            $opcodePc = $idx - 1;
            $this->code[$idx] = $loopExit - $opcodePc;
        }
    }

    private function compileDoWhile(DoWhileStatement $node): void
    {
        $loopStart = count($this->code);
        $this->loopStack[] = ['continue' => -1, 'breaks' => [], 'continues' => []];
        $this->compileStatement($node->body);
        $continueTarget = count($this->code);
        $this->compileExpression($node->test);
        // JUMP_IF_TRUE back to start.
        $this->emit(Op::JUMP_IF_TRUE, 0);
        $jumpOpcodePc = count($this->code) - 2;
        $this->code[$jumpOpcodePc + 1] = $loopStart - $jumpOpcodePc;
        $loopExit = count($this->code);
        $frame = array_pop($this->loopStack);
        foreach ($frame['continues'] as $idx) {
            $opcodePc = $idx - 1;
            $this->code[$idx] = $continueTarget - $opcodePc;
        }
        foreach ($frame['breaks'] as $idx) {
            $opcodePc = $idx - 1;
            $this->code[$idx] = $loopExit - $opcodePc;
        }
    }

    private function compileVarDecl(VariableDeclaration $node): void
    {
        if ($node->kind === 'using' || $node->kind === 'await using') {
            // `using` declarations require resource-tracking on the
            // current env's disposal stack; the slot-based compiler
            // doesn't model that yet.
            throw new CompilerBailout('using declaration');
        }
        foreach ($node->declarations as $decl) {
            if ($decl->id instanceof ObjectPattern) {
                if ($decl->init === null) {
                    throw new CompilerBailout('destructure without init');
                }
                $count = count($decl->id->properties);
                if ($count === 0) {
                    // Empty pattern still requires the spec ToObject
                    // check on init (TypeError on null/undefined).
                    // Tree-walker handles the edge case; bail.
                    throw new CompilerBailout('empty object pattern');
                }
                $this->compileExpression($decl->init);
                $i = 0;
                foreach ($decl->id->properties as $prop) {
                    $i++;
                    if (!($prop instanceof \PhpJs\Ast\Pattern\AssignmentProperty)) {
                        throw new CompilerBailout('rest in pattern');
                    }
                    if ($prop->computed) {
                        throw new CompilerBailout('computed key in pattern');
                    }
                    if (!($prop->value instanceof Identifier)) {
                        throw new CompilerBailout('non-identifier pattern target');
                    }
                    $keyName = $prop->key instanceof Identifier
                        ? $prop->key->name
                        : ($prop->key instanceof Literal && is_string($prop->key->value)
                            ? $prop->key->value
                            : throw new CompilerBailout('weird pattern key'));
                    if ($i < $count) {
                        $this->emit(Op::DUP);
                    }
                    $this->emit(Op::LOAD_MEMBER, $this->internName($keyName));
                    $slot = $this->localSlots[$prop->value->name] ?? $this->declareLocal($prop->value->name);
                    $this->emitStoreLocal($slot);
                    if ($node->kind === 'const') {
                        $this->constSlots[$slot] = true;
                    }
                }
                continue;
            }
            if (!($decl->id instanceof Identifier)) {
                throw new CompilerBailout('destructuring var');
            }
            $name = $decl->id->name;
            // §B.3.2 var-vs-function-decl name conflict at program scope:
            // collectProgramVarLocals deliberately left this name OFF the
            // localSlots table because the FunctionDeclaration hoists it
            // onto globalEnv. Emit the var binding via the env path so
            // a later read sees the hoisted function rather than an
            // undefined frame-slot. Pure declarations (no init) become
            // a no-op because the FD hoist already created the binding;
            // declarations with an initializer (e.g. `var f = 1; function
            // f() {}` — initializer order matters per source-order
            // execution) emit a STORE_NAME so the var-init clobbers the
            // FD-installed value, matching the tree-walker's behaviour
            // where statement-list evaluation reaches the var initializer
            // after both have been hoisted.
            if ($node->kind === 'var' && isset($this->fnDeclShadowedVarNames[$name])) {
                if ($decl->init !== null) {
                    if (self::initNeedsNamedEvaluation($decl->init)) {
                        throw new CompilerBailout('var init needs named evaluation');
                    }
                    $this->compileExpression($decl->init);
                    $this->emit(Op::STORE_NAME, $this->internName($name));
                }
                continue;
            }
            $slot = $this->localSlots[$name] ?? $this->declareLocal($name);
            if ($decl->init !== null) {
                // NamedEvaluation: per spec 14.3.2.1, when the initializer
                // is an anonymous function/class definition, the declared
                // binding name becomes the function's .name. The bytecode
                // emit path stores the value verbatim with no rename hook,
                // so bail to the tree-walker (which calls JsFunction::setName).
                if (self::initNeedsNamedEvaluation($decl->init)) {
                    throw new CompilerBailout('var init needs named evaluation');
                }
                $this->compileExpression($decl->init);
                $this->emitStoreLocal($slot);
            } else {
                if ($node->kind === 'const') {
                    throw new CompilerBailout('const without initializer');
                }
            }
            if ($node->kind === 'const') {
                $this->constSlots[$slot] = true;
            }
        }
    }

    /**
     * Return true when the spec's IsAnonymousFunctionDefinition is
     * true for $init, i.e. a NamedEvaluation should fire on the
     * binding name. Anonymous function expressions, arrow functions
     * and anonymous class expressions all qualify.
     */
    private static function initNeedsNamedEvaluation(Node $init): bool
    {
        if (
            $init instanceof \PhpJs\Ast\Expression\FunctionExpression
            && $init->name === null
        ) {
            return true;
        }
        if ($init instanceof \PhpJs\Ast\Expression\ArrowFunction) {
            return true;
        }
        if (
            $init instanceof \PhpJs\Ast\Expression\ClassExpression
            && $init->id === null
        ) {
            return true;
        }
        return false;
    }

    private function compileExpression(Node $node): void
    {
        if ($node instanceof Literal) {
            $this->compileLiteral($node);
            return;
        }
        if ($node instanceof Identifier) {
            $this->compileIdentifier($node);
            return;
        }
        if ($node instanceof BinaryExpression) {
            $this->compileBinary($node);
            return;
        }
        if ($node instanceof ConditionalExpression) {
            $this->compileConditional($node);
            return;
        }
        if ($node instanceof CallExpression) {
            $this->compileCall($node);
            return;
        }
        if ($node instanceof MemberExpression) {
            $this->compileMember($node);
            return;
        }
        if ($node instanceof AssignmentExpression) {
            $this->compileAssignment($node);
            return;
        }
        if ($node instanceof ThisExpression) {
            $this->emit(Op::LOAD_THIS);
            return;
        }
        if ($node instanceof UpdateExpression) {
            $this->compileUpdate($node);
            return;
        }
        if ($node instanceof UnaryExpression) {
            $this->compileUnary($node);
            return;
        }
        if ($node instanceof LogicalExpression) {
            $this->compileLogical($node);
            return;
        }
        if ($node instanceof SequenceExpression) {
            $count = count($node->expressions);
            for ($i = 0; $i < $count; $i++) {
                $this->compileExpression($node->expressions[$i]);
                if ($i !== $count - 1) {
                    $this->emit(Op::POP);
                }
            }
            return;
        }
        if ($node instanceof ObjectExpression) {
            $this->compileObjectLiteral($node);
            return;
        }
        if ($node instanceof ArrayExpression) {
            $this->compileArrayLiteral($node);
            return;
        }
        if ($node instanceof TemplateLiteral) {
            $this->compileTemplate($node);
            return;
        }
        if ($node instanceof NewExpression) {
            $this->compileNew($node);
            return;
        }
        if (
            $node instanceof \PhpJs\Ast\Expression\ArrowFunction
            || $node instanceof \PhpJs\Ast\Expression\FunctionExpression
        ) {
            $this->compileNestedFunction($node);
            return;
        }
        if ($node instanceof \PhpJs\Ast\Expression\ClassExpression) {
            if ($this->capturesOuterLocal($node)) {
                throw new CompilerBailout('class expression captures outer local');
            }
            $idx = count($this->classNodes);
            $this->classNodes[] = $node;
            $this->emit(Op::MAKE_CLASS, $idx);
            return;
        }
        throw new CompilerBailout('unsupported expression: ' . $node->type());
    }

    /**
     * Emit MAKE_FUNCTION + STORE_LOCAL for every top-level
     * FunctionDeclaration in the body — hoisted to the start so
     * forward references resolve. Each declaration's slot was
     * already reserved by collectFunctionLocals.
     *
     * Bails if the declaration captures an outer local (its inner
     * body would fail the env-walk) or is a generator / async
     * function (those still need the tree-walker's full prologue).
     *
     * @param Node[] $statements
     */
    private function hoistFunctionDeclarations(array $statements): void
    {
        foreach ($statements as $stmt) {
            if (!($stmt instanceof FunctionDeclaration) || $stmt->id === null) {
                continue;
            }
            if ($stmt->generator || $stmt->async) {
                throw new CompilerBailout('hoisted generator/async fn');
            }
            if ($this->capturesOuterLocal($stmt)) {
                throw new CompilerBailout('hoisted fn captures outer local');
            }
            $idx = count($this->nestedFns);
            $this->nestedFns[] = $stmt;
            $this->emit(Op::MAKE_FUNCTION, $idx);
            $slot = $this->localSlots[$stmt->id->name] ?? $this->declareLocal($stmt->id->name);
            $this->emit(Op::STORE_LOCAL, $slot);
        }
    }

    /**
     * Lower a nested function or arrow expression into a
     * MAKE_FUNCTION opcode that the VM materialises into a
     * JsFunction at runtime, capturing the current Frame's env as
     * the closure.
     *
     * Capture safety: the outer function's locals live in frame
     * slots, NOT in the env. A nested function that references any
     * outer local would walk the env chain at call time and not
     * find it. Bail when such a capture exists so the tree-walker
     * keeps owning the outer function (which uses env-based
     * bindings the inner closure can read).
     */
    private function compileNestedFunction(Node $node): void
    {
        if ($node instanceof \PhpJs\Ast\Expression\FunctionExpression) {
            if ($node->generator || $node->async) {
                throw new CompilerBailout('nested generator/async fn');
            }
        }
        if ($node instanceof \PhpJs\Ast\Expression\ArrowFunction) {
            if ($node->async) {
                throw new CompilerBailout('nested async arrow');
            }
        }
        if ($this->capturesOuterLocal($node)) {
            throw new CompilerBailout('nested fn captures outer local');
        }
        $idx = count($this->nestedFns);
        $this->nestedFns[] = $node;
        $this->emit(Op::MAKE_FUNCTION, $idx);
    }

    /**
     * Walk a nested function's params + body for any Identifier
     * reference to a name in the OUTER function's local slots that
     * the nested function does not itself shadow. Returns true if
     * any such capture exists.
     */
    private function capturesOuterLocal(Node $node): bool
    {
        // Collect names declared INSIDE the nested fn — those shadow
        // outer locals and don't constitute a capture.
        $shadow = [];
        if ($node instanceof \PhpJs\Ast\Expression\FunctionExpression) {
            $params = $node->params;
            $body = $node->body;
            if ($node->name !== null) {
                // Named function expression's own name shadows
                // outer bindings inside the body.
                $shadow[$node->name] = true;
            }
        } elseif ($node instanceof FunctionDeclaration) {
            // Reached only from compileProgram's top-level safety scan:
            // function declarations are hoisted to globalEnv but their
            // bodies may still reference frame-slot top-level vars.
            $params = $node->params;
            $body = $node->body;
            if ($node->id !== null) {
                $shadow[$node->id->name] = true;
            }
        } elseif ($node instanceof \PhpJs\Ast\Expression\ArrowFunction) {
            $params = $node->params;
            $body = $node->body;
        } else {
            return true; // unknown shape — bail conservatively
        }
        foreach ($params as $param) {
            $this->collectPatternBoundNames($param, $shadow);
        }
        if ($body instanceof BlockStatement) {
            $this->collectInnerDeclaredNames($body->body, $shadow);
        }
        // Param defaults are evaluated before the body and resolve in
        // the enclosing scope minus the param names introduced strictly
        // before this slot. For capture safety it's enough to use the
        // already-populated shadow set: an outer-local Identifier in
        // any default expression (e.g. `function f(x = outerVar)`) is
        // a real capture and must trigger the bailout. Without this
        // scan, `function* f([x] = iter)` with top-level `var iter` at
        // program scope compiles, then the call resolves `iter` via
        // globalEnv and finds the hoisted undefined placeholder.
        foreach ($params as $param) {
            if ($this->scanForOuterCapture($param, $shadow)) {
                return true;
            }
        }
        return $this->scanForOuterCapture($body, $shadow);
    }

    /**
     * @param array<string,bool> $out
     */
    private function collectPatternBoundNames(Node $pattern, array &$out): void
    {
        if ($pattern instanceof Identifier) {
            $out[$pattern->name] = true;
            return;
        }
        if ($pattern instanceof AssignmentPattern) {
            $this->collectPatternBoundNames($pattern->left, $out);
            return;
        }
        if ($pattern instanceof RestElement) {
            $this->collectPatternBoundNames($pattern->argument, $out);
            return;
        }
        if ($pattern instanceof ArrayPattern) {
            foreach ($pattern->elements as $elem) {
                if ($elem instanceof Node) {
                    $this->collectPatternBoundNames($elem, $out);
                }
            }
            return;
        }
        if ($pattern instanceof ObjectPattern) {
            foreach ($pattern->properties as $prop) {
                if ($prop instanceof \PhpJs\Ast\Pattern\AssignmentProperty) {
                    $this->collectPatternBoundNames($prop->value, $out);
                } elseif ($prop instanceof RestElement) {
                    $this->collectPatternBoundNames($prop->argument, $out);
                }
            }
            return;
        }
    }

    /**
     * @param Node[] $statements
     * @param array<string,bool> $out
     */
    private function collectInnerDeclaredNames(array $statements, array &$out): void
    {
        foreach ($statements as $stmt) {
            $this->collectInnerDeclaredNamesNode($stmt, $out);
        }
    }

    /**
     * @param array<string,bool> $out
     */
    private function collectInnerDeclaredNamesNode(Node $node, array &$out): void
    {
        if ($node instanceof VariableDeclaration) {
            foreach ($node->declarations as $decl) {
                $this->collectPatternBoundNames($decl->id, $out);
            }
            return;
        }
        if ($node instanceof FunctionDeclaration && $node->id !== null) {
            $out[$node->id->name] = true;
            return;
        }
        if ($node instanceof BlockStatement) {
            foreach ($node->body as $s) {
                $this->collectInnerDeclaredNamesNode($s, $out);
            }
            return;
        }
        if ($node instanceof IfStatement) {
            $this->collectInnerDeclaredNamesNode($node->consequent, $out);
            if ($node->alternate !== null) {
                $this->collectInnerDeclaredNamesNode($node->alternate, $out);
            }
            return;
        }
        if ($node instanceof ForStatement) {
            if ($node->init instanceof VariableDeclaration) {
                foreach ($node->init->declarations as $d) {
                    $this->collectPatternBoundNames($d->id, $out);
                }
            }
            $this->collectInnerDeclaredNamesNode($node->body, $out);
            return;
        }
        if ($node instanceof WhileStatement || $node instanceof DoWhileStatement) {
            $this->collectInnerDeclaredNamesNode($node->body, $out);
            return;
        }
    }

    /**
     * @param array<string,bool> $shadow
     */
    private function scanForOuterCapture(?Node $node, array $shadow): bool
    {
        if ($node === null) {
            return false;
        }
        if ($node instanceof Identifier) {
            $name = $node->name;
            if (
                isset($this->localSlots[$name])
                && !isset($shadow[$name])
            ) {
                return true;
            }
            return false;
        }
        // Don't recurse into deeper nested functions — they'll bail
        // independently when their compile attempt happens.
        if (
            $node instanceof \PhpJs\Ast\Expression\FunctionExpression
            || $node instanceof \PhpJs\Ast\Expression\ArrowFunction
            || $node instanceof FunctionDeclaration
            || $node instanceof \PhpJs\Ast\Expression\ClassExpression
            || $node instanceof \PhpJs\Ast\Declaration\ClassDeclaration
        ) {
            // Even so, the inner-inner closure may reference names
            // from THIS scope — its compile will catch it. But for
            // the OUTER's safety, also check directly: if the
            // inner-inner refs match this scope's locals, that's a
            // capture too.
            return $this->capturesOuterLocal($node);
        }
        $ref = new \ReflectionObject($node);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                if ($this->scanForOuterCapture($value, $shadow)) {
                    return true;
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node && $this->scanForOuterCapture($item, $shadow)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private function compileObjectLiteral(ObjectExpression $node): void
    {
        $this->emit(Op::NEW_OBJECT);
        // Stack: [obj]
        foreach ($node->properties as $prop) {
            if ($prop instanceof SpreadElement) {
                throw new CompilerBailout('spread in object literal');
            }
            if (!$prop instanceof Property) {
                throw new CompilerBailout('object literal: ' . get_class($prop));
            }
            if ($prop->kind !== 'init') {
                throw new CompilerBailout('getter/setter literal');
            }
            if ($prop->method) {
                throw new CompilerBailout('method shorthand');
            }
            if ($prop->computed) {
                // [expr]: val — push key, push val, SET_COMPUTED.
                $this->compileExpression($prop->key);
                $this->compileExpression($prop->value);
                $this->emit(Op::SET_COMPUTED);
                continue;
            }
            // Static name (Identifier or string Literal).
            if ($prop->key instanceof Identifier) {
                $name = $prop->key->name;
            } elseif ($prop->key instanceof Literal) {
                if (!is_string($prop->key->value) && !is_int($prop->key->value) && !is_float($prop->key->value)) {
                    throw new CompilerBailout('non-string-or-numeric literal key');
                }
                $name = (string) $prop->key->value;
            } else {
                throw new CompilerBailout('non-identifier non-literal key');
            }
            // `__proto__: value` is the special prototype-set sugar
            // when shorthand=false (method=false has already bailed
            // above); the tree-walker handles it via setPrototype rather
            // than defining a property. Bail to keep spec semantics.
            if ($name === '__proto__' && !$prop->shorthand) {
                throw new CompilerBailout('__proto__ literal');
            }
            $this->compileExpression($prop->value);
            $this->emit(Op::SET_PROP, $this->internName($name));
        }
        // Stack: [obj] (unchanged net by SET_PROP / SET_COMPUTED).
    }

    private function compileArrayLiteral(ArrayExpression $node): void
    {
        // Use NEW_ARRAY <count> for dense literals with no holes /
        // spread. Sparse / spread arrays bail.
        $count = count($node->elements);
        $hasHoles = false;
        $hasSpread = false;
        foreach ($node->elements as $el) {
            if ($el === null) {
                $hasHoles = true;
            } elseif ($el instanceof SpreadElement) {
                $hasSpread = true;
            }
        }
        if ($hasHoles || $hasSpread) {
            throw new CompilerBailout('array literal with hole/spread');
        }
        foreach ($node->elements as $el) {
            $this->compileExpression($el);
        }
        $this->emit(Op::NEW_ARRAY, $count);
    }

    private function compileTemplate(TemplateLiteral $node): void
    {
        // Untagged template: alternate quasis and expressions, build a
        // string by concatenation. ToString happens via ADD's string
        // path when one operand is a string.
        $quasis = $node->quasis;
        $expressions = $node->expressions;
        $count = count($quasis);
        if ($count === 0) {
            $this->emit(Op::LOAD_CONST, $this->internConst(new JsString(''), 's:'));
            return;
        }
        for ($i = 0; $i < $count; $i++) {
            $part = $quasis[$i];
            $cookedRaw = $part->cookedValue;
            if ($cookedRaw === null) {
                throw new CompilerBailout('template element with invalid escape');
            }
            $idx = $this->internConst(new JsString($cookedRaw), 's:' . $cookedRaw);
            $this->emit(Op::LOAD_CONST, $idx);
            if ($i > 0) {
                // After the first quasi, concatenate with whatever
                // is already on the stack from the previous expr.
                $this->emit(Op::ADD);
            }
            if ($i < count($expressions)) {
                $this->compileExpression($expressions[$i]);
                // Per spec 13.2.8.6 SubstitutionTemplate evaluation:
                // each ${expr} is coerced via ToString directly (which
                // uses ToPrimitive("string") for objects), NOT via
                // `+`'s default hint. The distinction is observable
                // when @@toPrimitive is monkey-patched (e.g. Symbol
                // wrapper with @@toPrimitive=null should reach
                // Symbol.prototype.toString and yield "Symbol()"
                // rather than the default-hint path that surfaces a
                // raw JsSymbol primitive and throws on ToString).
                $this->emit(Op::TO_STRING);
                $this->emit(Op::ADD);
            }
        }
    }

    private function compileNew(NewExpression $node): void
    {
        foreach ($node->arguments as $arg) {
            if ($arg instanceof SpreadElement) {
                throw new CompilerBailout('spread in new');
            }
        }
        $this->compileExpression($node->callee);
        foreach ($node->arguments as $arg) {
            $this->compileExpression($arg);
        }
        $this->emit(Op::NEW_CALL, count($node->arguments));
    }

    /**
     * Compile `i++` / `i--` / `++i` / `--i` when the result is unused
     * (ExpressionStatement at top level, ForStatement::update slot) into
     * a single INC_LOCAL / DEC_LOCAL op. Returns true on success so the
     * caller can skip the standard compile-then-pop path.
     */
    private function tryEmitDiscardedUpdate(\PhpJs\Ast\Node $expr): bool
    {
        if (!$expr instanceof UpdateExpression) {
            return false;
        }
        $arg = $expr->argument;
        if (!$arg instanceof Identifier) {
            return false;
        }
        $name = $arg->name;
        if ($name === 'eval' || $name === 'arguments' || $name === '[[NewTarget]]') {
            return false;
        }
        if (!isset($this->localSlots[$name])) {
            return false;
        }
        $slot = $this->localSlots[$name];
        if (isset($this->constSlots[$slot])) {
            return false;
        }
        $this->emit($expr->operator === '++' ? Op::INC_LOCAL : Op::DEC_LOCAL, $slot);
        return true;
    }

    private function compileUpdate(UpdateExpression $node): void
    {
        $arg = $node->argument;
        $isInc = $node->operator === '++';
        if ($arg instanceof Identifier) {
            $name = $arg->name;
            if ($name === 'eval' || $name === 'arguments') {
                throw new CompilerBailout('update eval/arguments');
            }
            if (isset($this->localSlots[$name])) {
                $slot = $this->localSlots[$name];
                if (isset($this->constSlots[$slot])) {
                    throw new CompilerBailout('update of const');
                }
                $this->emit(Op::LOAD_LOCAL, $slot);
                // Per §13.4 (UpdateExpression) and §6.1.6.1.10 NumericAdd:
                // both ++x and x++ start with ToNumber(oldValue). Op::ADD
                // on a JsString left operand is a string-concat, so emit
                // Op::POS first to coerce the loaded value to a numeric.
                // For postfix, we DUP after coercion so the expression
                // result is the ToNumber(oldValue) (e.g. y = x-- with x=true
                // returns 1, not JsBoolean). For prefix, the post-ADD/SUB
                // value is the result. Op::POS on JsNumber is a no-op so
                // the steady-state numeric path is unchanged. BigInt is
                // covered by the wider compileUpdate bailout above.
                $this->emit(Op::POS);
                if (!$node->prefix) {
                    $this->emit(Op::DUP);
                }
                $oneIdx = $this->internConst(JsNumber::of(1.0), 'n:1');
                $this->emit(Op::LOAD_CONST, $oneIdx);
                $this->emit($isInc ? Op::ADD : Op::SUB);
                if ($node->prefix) {
                    $this->emit(Op::DUP);
                }
                $this->emitStoreLocal($slot);
                return;
            }
            // Free variable: bail to tree-walker (Reference path is
            // spec-correct for Update on names that may resolve via
            // a with-environment etc.).
            throw new CompilerBailout('update free var');
        }
        // Member targets (`o.x++`, `o[k]++`) come in a later phase.
        throw new CompilerBailout('update non-identifier');
    }

    private function compileUnary(UnaryExpression $node): void
    {
        if (!$node->prefix) {
            throw new CompilerBailout('postfix unary');
        }
        $op = $node->operator;
        // `delete`, `typeof` on identifiers, `void` are easy enough,
        // but the spec edge cases (delete on with-bindings, typeof
        // on undeclared) are subtle. Bail to tree-walker for safety.
        if ($op === 'delete' || $op === 'typeof') {
            throw new CompilerBailout('delete/typeof');
        }
        if ($op === 'void') {
            $this->compileExpression($node->argument);
            $this->emit(Op::POP);
            $this->emit(Op::LOAD_UNDEF);
            return;
        }
        $this->compileExpression($node->argument);
        switch ($op) {
            case '-':
                $this->emit(Op::NEG);
                return;
            case '+':
                $this->emit(Op::POS);
                return;
            case '!':
                $this->emit(Op::NOT);
                return;
            case '~':
                $this->emit(Op::BNOT);
                return;
            default:
                throw new CompilerBailout('unary: ' . $op);
        }
    }

    private function compileLogical(LogicalExpression $node): void
    {
        // && and ||: short-circuit by peeking.
        if ($node->operator === '&&' || $node->operator === '||') {
            $this->compileExpression($node->left);
            $jmp = $node->operator === '&&'
                ? $this->emitJump(Op::JUMP_IF_FALSY_KEEP)
                : $this->emitJump(Op::JUMP_IF_TRUTHY_KEEP);
            $this->compileExpression($node->right);
            $this->patchJumpToHere($jmp);
            return;
        }
        if ($node->operator === '??') {
            // VM's JUMP_IF_NULLISH pops on jump and keeps on fall-
            // through, so the @nullish path starts with a clean stack
            // and can compile right directly.
            $this->compileExpression($node->left);
            $jmp = $this->emitJump(Op::JUMP_IF_NULLISH);
            // not nullish: left already on stack; skip right.
            $jmpEnd = $this->emitJump(Op::JUMP);
            $this->patchJumpToHere($jmp);
            $this->compileExpression($node->right);
            $this->patchJumpToHere($jmpEnd);
            return;
        }
        throw new CompilerBailout('logical: ' . $node->operator);
    }

    private function compileLiteral(Literal $node): void
    {
        $value = $node->value;
        if ($value === null) {
            $this->emit(Op::LOAD_NULL);
            return;
        }
        if (is_bool($value)) {
            $this->emit($value ? Op::LOAD_TRUE : Op::LOAD_FALSE);
            return;
        }
        if (is_int($value) || is_float($value)) {
            // Use a full-precision round-trippable string as the dedupe
            // key. Plain string concat ((string)$value) goes through
            // PHP's `precision` ini (default 14 digits), so a numerically
            // distinct float like 1.000000000000001 and a plain `1` would
            // both stringify to "1" and share a constant slot — making
            // `a[1] = -1.000000000000001` read back as -1 because the
            // dedupe handed the second literal the JsNumber stored for
            // the first. serialize() emits the IEEE 754 round-trip form.
            $idx = $this->internConst(
                JsNumber::of((float) $value),
                'n:' . serialize((float) $value),
            );
            $this->emit(Op::LOAD_CONST, $idx);
            return;
        }
        if (is_string($value)) {
            // RegExp literal: bail — fresh regex per evaluation, plus
            // BigInt literal sharing the string slot.
            if (str_starts_with($node->raw, '__REGEXP__')) {
                throw new CompilerBailout('regexp literal');
            }
            if (str_starts_with($node->raw, '__BIGINT__')) {
                throw new CompilerBailout('bigint literal');
            }
            $idx = $this->internConst(new JsString($value), 's:' . $value);
            $this->emit(Op::LOAD_CONST, $idx);
            return;
        }
        throw new CompilerBailout('unknown literal kind');
    }

    private function compileIdentifier(Identifier $node): void
    {
        $name = $node->name;
        if ($name === 'undefined') {
            $this->emit(Op::LOAD_UNDEF);
            return;
        }
        if (isset($this->localSlots[$name])) {
            $this->emit(Op::LOAD_LOCAL, $this->localSlots[$name]);
            return;
        }
        // Free variable — let the VM walk the env via the existing
        // (already cached) Environment::get path.
        $this->emit(Op::LOAD_NAME, $this->internName($name));
    }

    private function compileBinary(BinaryExpression $node): void
    {
        // Bail on operators that need special left-side handling (in,
        // instanceof, with-private-field) for now. They're not on the
        // hot path of fib / loop microbenchmarks.
        $op = $node->operator;
        $opcode = match ($op) {
            '+'   => Op::ADD,
            '-'   => Op::SUB,
            '*'   => Op::MUL,
            '/'   => Op::DIV,
            '%'   => Op::MOD,
            '**'  => Op::POW,
            '<'   => Op::LT,
            '>'   => Op::GT,
            '<='  => Op::LE,
            '>='  => Op::GE,
            '=='  => Op::EQ,
            '!='  => Op::NEQ,
            '===' => Op::SEQ,
            '!==' => Op::SNEQ,
            '&'   => Op::BAND,
            '|'   => Op::BOR,
            '^'   => Op::BXOR,
            '<<'  => Op::SHL,
            '>>'  => Op::SHR,
            '>>>' => Op::USHR,
            default => throw new CompilerBailout('binary operator: ' . $op),
        };
        $this->compileExpression($node->left);
        $this->compileExpression($node->right);
        $this->emit($opcode);
    }

    private function compileConditional(ConditionalExpression $node): void
    {
        $this->compileExpression($node->test);
        $jmpElse = $this->emitJump(Op::JUMP_IF_FALSE);
        $this->compileExpression($node->consequent);
        $jmpEnd = $this->emitJump(Op::JUMP);
        $this->patchJumpToHere($jmpElse);
        $this->compileExpression($node->alternate);
        $this->patchJumpToHere($jmpEnd);
    }

    private function compileCall(CallExpression $node): void
    {
        // No optional calls / direct eval / spread args yet.
        if ($node->optional) {
            throw new CompilerBailout('optional call');
        }
        if ($node->callee instanceof Identifier && $node->callee->name === 'eval') {
            throw new CompilerBailout('direct eval');
        }
        if ($node->callee instanceof Identifier && $node->callee->name === 'super') {
            throw new CompilerBailout('super call');
        }
        foreach ($node->arguments as $arg) {
            if ($arg instanceof SpreadElement) {
                throw new CompilerBailout('spread call argument');
            }
        }

        // Method call: `obj.m(...)` and `obj[k](...)`. Emit
        // CALL_METHOD with the receiver on the stack so the dispatcher
        // can use it as `this`.
        if (
            $node->callee instanceof MemberExpression
            && !$node->callee->optional
            && !($node->callee->property instanceof PrivateIdentifier)
            && !($node->callee->object instanceof Identifier && $node->callee->object->name === 'super')
        ) {
            if ($node->callee->computed) {
                throw new CompilerBailout('computed method call');
            }
            if (!($node->callee->property instanceof Identifier)) {
                throw new CompilerBailout('non-identifier method name');
            }
            // Spec evaluation order: look up the method (and surface
            // any TypeError on null/undefined receivers) BEFORE
            // evaluating any arguments. Stack layout:
            //   [..., obj, obj]                via DUP
            //   [..., obj, method]             via LOAD_MEMBER
            //   [..., obj, method, arg0..argN] after arg compile
            // CALL_METHOD argc pops method + args, peeks obj as
            // `this`, leaves result.
            $this->compileExpression($node->callee->object);
            $this->emit(Op::DUP);
            $this->emit(Op::LOAD_MEMBER, $this->internName($node->callee->property->name));
            foreach ($node->arguments as $arg) {
                $this->compileExpression($arg);
            }
            // Render the call-site source for the "is not a function"
            // error before emit so the PC we record actually points at
            // the CALL_METHOD opcode.
            $display = $this->renderMethodCallDisplay($node->callee);
            $callMethodPc = count($this->code);
            $this->emit(Op::CALL_METHOD, count($node->arguments));
            if ($display !== null) {
                $this->callMethodDisplays[$callMethodPc] = $display;
            }
            return;
        }

        // Direct function call: callee is anything except a member
        // expression (i.e. `f(...)` not `obj.m(...)`).
        $this->compileExpression($node->callee);
        foreach ($node->arguments as $arg) {
            $this->compileExpression($arg);
        }
        $this->emit(Op::CALL, count($node->arguments));
    }

    private function compileMember(MemberExpression $node): void
    {
        if ($node->optional) {
            throw new CompilerBailout('optional member');
        }
        if ($node->object instanceof Identifier && $node->object->name === 'super') {
            throw new CompilerBailout('super member access');
        }
        if ($node->property instanceof PrivateIdentifier) {
            throw new CompilerBailout('private field access');
        }
        if ($node->computed) {
            $this->compileExpression($node->object);
            $this->compileExpression($node->property);
            $this->emit(Op::LOAD_COMPUTED);
            return;
        }
        if (!($node->property instanceof Identifier)) {
            throw new CompilerBailout('non-identifier member property');
        }
        $this->compileExpression($node->object);
        $this->emit(Op::LOAD_MEMBER, $this->internName($node->property->name));
    }

    private function compileAssignment(AssignmentExpression $node): void
    {
        if ($node->leftParenthesized) {
            throw new CompilerBailout('parenthesized lhs');
        }
        $left = $node->left;
        $op = $node->operator;

        // Compound op (`+=`, `-=`, ...) gets lowered to:
        //   load lhs; compile rhs; <op>; <store-keep>
        // Logical compound (`&&=` / `||=` / `??=`) goes to the
        // tree-walker because of subtle short-circuit semantics
        // around with-environments.
        $binaryOpcode = match ($op) {
            '='   => null,
            '+='  => Op::ADD,
            '-='  => Op::SUB,
            '*='  => Op::MUL,
            '/='  => Op::DIV,
            '%='  => Op::MOD,
            '**=' => Op::POW,
            '&='  => Op::BAND,
            '|='  => Op::BOR,
            '^='  => Op::BXOR,
            '<<=' => Op::SHL,
            '>>=' => Op::SHR,
            '>>>='=> Op::USHR,
            default => throw new CompilerBailout('logical compound assignment'),
        };

        if ($left instanceof Identifier) {
            $name = $left->name;
            if ($name === 'eval' || $name === 'arguments') {
                throw new CompilerBailout('assign to eval/arguments');
            }
            $isLocal = isset($this->localSlots[$name]);

            if ($isLocal && isset($this->constSlots[$this->localSlots[$name]])) {
                throw new CompilerBailout('assignment to const');
            }

            // Strict-mode write to a non-local identifier: bail so the
            // tree-walker can pre-resolve the LHS reference per spec
            // (12.15.4 step 1: lref is evaluated BEFORE the RHS).
            // The VM's STORE_NAME runs after RHS, so a side-effecting
            // RHS that creates the global property would mask the
            // unresolvable LHS — strict mode must surface ReferenceError
            // (test262 language/identifier-resolution/
            // assign-to-global-undefined.js).
            if (!$isLocal && $this->programIsStrict) {
                throw new CompilerBailout('strict-mode non-local identifier assignment');
            }
            if ($op === '=') {
                $this->compileExpression($node->right);
                $this->emit(Op::DUP);
                if ($isLocal) {
                    $this->emitStoreLocal($this->localSlots[$name]);
                } else {
                    $this->emit(Op::STORE_NAME, $this->internName($name));
                }
                return;
            }
            // Compound on identifier:
            //   load lhs; compile rhs; <op>; DUP; STORE_*
            if ($isLocal) {
                $this->emit(Op::LOAD_LOCAL, $this->localSlots[$name]);
            } else {
                $this->emit(Op::LOAD_NAME, $this->internName($name));
            }
            $this->compileExpression($node->right);
            $this->emit($binaryOpcode);
            $this->emit(Op::DUP);
            if ($isLocal) {
                $this->emitStoreLocal($this->localSlots[$name]);
            } else {
                $this->emit(Op::STORE_NAME, $this->internName($name));
            }
            return;
        }

        if ($left instanceof MemberExpression) {
            if ($left->optional) {
                throw new CompilerBailout('optional assign');
            }
            if ($left->object instanceof Identifier && $left->object->name === 'super') {
                throw new CompilerBailout('super assign');
            }
            if ($left->property instanceof PrivateIdentifier) {
                throw new CompilerBailout('private assign');
            }
            if ($left->computed) {
                // Computed `obj[key] = rhs` (and compound `obj[key] op= rhs`).
                // Hot in code like `codePoints[length++] = codePoint`. Without
                // this path the entire enclosing function falls back to the
                // tree-walker, which adds a 5-10x dispatch overhead per
                // iteration. Compound forms only emit when no in-bytecode op
                // for the operator exists; logical compound (`&&=` etc.)
                // already bailed before this branch.
                if ($op === '=') {
                    // Stack: obj, key, val. STORE_COMPUTED writes and
                    // re-pushes val so the assignment expression yields rhs.
                    $this->compileExpression($left->object);
                    $this->compileExpression($left->property);
                    $this->compileExpression($node->right);
                    $this->emit(Op::STORE_COMPUTED);
                    return;
                }
                // Compound `obj[key] op= rhs`. Each side must be evaluated
                // once. The spec order is:
                //   1. Evaluate obj.
                //   2. Evaluate key (and ToPropertyKey).
                //   3. GetValue using obj+key.
                //   4. Evaluate rhs.
                //   5. ApplyOp.
                //   6. PutValue using obj+key.
                // We don't have a stack-shuffle op, so route through the
                // tree-walker for compound on a computed target. The plain
                // `=` form is the actually hot case.
                throw new CompilerBailout('compound computed assign');
            }
            if (!($left->property instanceof Identifier)) {
                throw new CompilerBailout('non-identifier assign target');
            }
            $nameIdx = $this->internName($left->property->name);

            if ($op === '=') {
                $this->compileExpression($left->object);
                $this->compileExpression($node->right);
                $this->emit(Op::STORE_MEMBER, $nameIdx);
                return;
            }
            // Compound on `obj.x`:
            //   compile obj; DUP; LOAD_MEMBER name; compile rhs;
            //   <op>; STORE_MEMBER name
            // Stack at STORE_MEMBER: [..., obj, newVal] → [..., newVal]
            $this->compileExpression($left->object);
            $this->emit(Op::DUP);
            $this->emit(Op::LOAD_MEMBER, $nameIdx);
            $this->compileExpression($node->right);
            $this->emit($binaryOpcode);
            $this->emit(Op::STORE_MEMBER, $nameIdx);
            return;
        }

        throw new CompilerBailout('unsupported assignment target');
    }

    private function internConst(JsValue $value, string $key): int
    {
        if (isset($this->constIndex[$key])) {
            return $this->constIndex[$key];
        }
        $idx = count($this->consts);
        $this->consts[] = $value;
        $this->constIndex[$key] = $idx;
        return $idx;
    }

    private function internName(string $name): int
    {
        if (isset($this->nameIndex[$name])) {
            return $this->nameIndex[$name];
        }
        $idx = count($this->names);
        $this->names[] = $name;
        $this->nameIndex[$name] = $idx;
        return $idx;
    }

    /**
     * Walk a function body looking for a ReturnStatement whose
     * argument is (or wraps) a CallExpression — the tail-call shape
     * spec requires to TCO. The bytecode VM doesn't trampoline,
     * so strict-mode functions matching this shape have to fall
     * through to the tree-walker. Doesn't descend into nested
     * functions (those have their own tail-position scope).
     */
    /**
     * Scan the directive prologue of a Program body for a "use strict"
     * directive. Matches Interpreter::hasUseStrictDirective — only
     * leading ExpressionStatement literals count, and only the exact
     * verbatim string `"use strict"`.
     *
     * @param array<int, \PhpJs\Ast\Node> $statements
     */
    private static function programHasUseStrictDirective(array $statements): bool
    {
        foreach ($statements as $stmt) {
            if (!$stmt instanceof \PhpJs\Ast\Statement\ExpressionStatement) {
                return false;
            }
            $expr = $stmt->expression;
            if (!$expr instanceof \PhpJs\Ast\Expression\Literal) {
                return false;
            }
            if (!is_string($expr->value)) {
                return false;
            }
            // Only verbatim "use strict" (no escape sequences /
            // line continuations) counts as a directive per spec.
            if ($expr->value === 'use strict' && $expr->verbatim) {
                return true;
            }
        }
        return false;
    }

    private static function bytecodeBailsForTailCall(\PhpJs\Ast\Node $body): bool
    {
        if ($body instanceof ReturnStatement) {
            return $body->argument !== null
                && self::tailCallExpr($body->argument);
        }
        if (
            $body instanceof \PhpJs\Ast\Declaration\FunctionDeclaration
            || $body instanceof \PhpJs\Ast\Expression\FunctionExpression
            || $body instanceof \PhpJs\Ast\Expression\ArrowFunction
        ) {
            return false;
        }
        foreach ((array) $body as $value) {
            if ($value instanceof \PhpJs\Ast\Node) {
                if (self::bytecodeBailsForTailCall($value)) {
                    return true;
                }
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof \PhpJs\Ast\Node && self::bytecodeBailsForTailCall($item)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private static function tailCallExpr(\PhpJs\Ast\Node $node): bool
    {
        if ($node instanceof CallExpression) {
            return true;
        }
        if ($node instanceof ConditionalExpression) {
            return self::tailCallExpr($node->consequent)
                || self::tailCallExpr($node->alternate);
        }
        if ($node instanceof \PhpJs\Ast\Expression\LogicalExpression) {
            return self::tailCallExpr($node->left)
                || self::tailCallExpr($node->right);
        }
        if ($node instanceof \PhpJs\Ast\Expression\SequenceExpression) {
            $exprs = $node->expressions;
            return $exprs !== [] && self::tailCallExpr($exprs[count($exprs) - 1]);
        }
        return false;
    }
}
