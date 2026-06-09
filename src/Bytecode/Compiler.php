<?php

declare(strict_types=1);

namespace Phasis\Bytecode;

use Phasis\Ast\Expression\ArrayExpression;
use Phasis\Ast\Expression\AssignmentExpression;
use Phasis\Ast\Expression\BinaryExpression;
use Phasis\Ast\Expression\CallExpression;
use Phasis\Ast\Expression\ConditionalExpression;
use Phasis\Ast\Expression\Identifier;
use Phasis\Ast\Expression\Literal;
use Phasis\Ast\Expression\MemberExpression;
use Phasis\Ast\Expression\NewExpression;
use Phasis\Ast\Expression\ObjectExpression;
use Phasis\Ast\Expression\PrivateIdentifier;
use Phasis\Ast\Expression\Property;
use Phasis\Ast\Expression\SpreadElement;
use Phasis\Ast\Expression\TemplateElement;
use Phasis\Ast\Expression\TemplateLiteral;
use Phasis\Ast\Expression\ThisExpression;
use Phasis\Ast\Node;
use Phasis\Ast\Declaration\FunctionDeclaration;
use Phasis\Ast\Declaration\VariableDeclaration;
use Phasis\Ast\Expression\LogicalExpression;
use Phasis\Ast\Expression\SequenceExpression;
use Phasis\Ast\Expression\UnaryExpression;
use Phasis\Ast\Expression\UpdateExpression;
use Phasis\Ast\Pattern\ArrayPattern;
use Phasis\Ast\Pattern\AssignmentPattern;
use Phasis\Ast\Pattern\ObjectPattern;
use Phasis\Ast\Pattern\RestElement;
use Phasis\Ast\Statement\BlockStatement;
use Phasis\Ast\Statement\BreakStatement;
use Phasis\Ast\Statement\ContinueStatement;
use Phasis\Ast\Statement\DoWhileStatement;
use Phasis\Ast\Statement\EmptyStatement;
use Phasis\Ast\Statement\ExpressionStatement;
use Phasis\Ast\Statement\ForStatement;
use Phasis\Ast\Statement\IfStatement;
use Phasis\Ast\Statement\ReturnStatement;
use Phasis\Ast\Statement\ThrowStatement;
use Phasis\Ast\Statement\WhileStatement;
use Phasis\Value\JsBigInt;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * AST → bytecode lowering. Phase 2 supports the minimal subset
 * needed to run fib(): arithmetic, comparison, conditional
 * expression, return, simple identifier callee calls, parameter
 * locals, free-variable name lookups. Anything else throws
 * CompilerBailout and JsFunction falls back to the tree-walker.
 */
final class Compiler
{
    use Parts\CompilerBailoutAnalysis;
    use Parts\CompilerScope;
    use Parts\CompilerEmit;
    use Parts\CompilerStatement;
    use Parts\CompilerExpression;

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

    /** @var list<\Phasis\Ast\Node> */
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
     * @var list<\Phasis\Ast\Node>
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

    /**
     * Toggled true while compiling a snapshot-safe generator body.
     * When set, `scanBailout` permits non-delegate yield expressions
     * (delegate=false) instead of bailing on them, and
     * `compileYieldExpression` emits `Op::YIELD`. `generatorBody
     * IsSnapshotSafe()` is the gate; only bodies that pass it get
     * this flag, so yield* / try-with-yield / await never reach
     * the YIELD opcode.
     */
    private bool $inGenerator = false;

    /**
     * True while compiling an ordinary (non-arrow, non-generator)
     * function body: ThisExpression lowers to LOAD_THIS_FRAME, which
     * reads the receiver off the Frame instead of walking the env
     * chain. Arrows need the lexical walk, generator bodies resume
     * from snapshots whose env is authoritative, and program bodies
     * resolve the global this binding — all three keep LOAD_THIS.
     */
    private bool $thisFromFrame = false;

    public function compile(JsFunction $fn): CompiledFunction
    {
        if ($fn->isAsync() || $fn->isNative() || $fn->isClassConstructor()) {
            throw new CompilerBailout('non-ordinary function kind');
        }
        $this->thisFromFrame = !$fn->isArrow() && !$fn->isGenerator();
        // Generators get a stricter bailout: only "simple" bodies
        // (no yield* delegation, no try/catch/finally containing
        // yield, not an async generator) can be lowered to the
        // snapshot path. Everything else stays on the Fiber-based
        // tree-walker. The scan is conservative — false negatives
        // are fine, false positives would break gen.return() /
        // gen.throw() reentry semantics.
        if ($fn->isGenerator()) {
            if (!$this->generatorBodyIsSnapshotSafe($fn->getBody())) {
                throw new CompilerBailout('generator: yield* / try-with-yield / async');
            }
            $this->inGenerator = true;
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
            // needsThis now means "the env must carry a `this` binding":
            // true when the body's own bytecode walks the env for this
            // (LOAD_THIS — arrows/generators) or when a nested arrow /
            // class outer-position lexically references this or super
            // and will resolve it through the env chain at run time.
            $needsEnvThis = in_array(Op::LOAD_THIS, $this->code, true)
                || $this->nestedFnsLexicallyReferenceThis();
            $cf = new CompiledFunction(
                code: $this->code,
                consts: $this->consts,
                names: $this->names,
                localNames: $this->localNames,
                paramSlots: $this->paramSlots,
                slotCount: max(1, count($this->localNames)),
                nestedFns: $this->nestedFns,
                needsThis: $needsEnvThis,
                needsArgsBinding: false,
                canSkipEnvAlloc: !$needsEnvThis,
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

        // See the expression-body site above for the needsThis /
        // canSkipEnvAlloc semantics under frame-based this.
        $needsEnvThis = in_array(Op::LOAD_THIS, $this->code, true)
            || $this->nestedFnsLexicallyReferenceThis();
        $cf = new CompiledFunction(
            code: $this->code,
            consts: $this->consts,
            names: $this->names,
            localNames: $this->localNames,
            paramSlots: $this->paramSlots,
            slotCount: max(1, count($this->localNames)),
            nestedFns: $this->nestedFns,
            needsThis: $needsEnvThis,
            needsArgsBinding: false,
            canSkipEnvAlloc: !$needsEnvThis,
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
     * @param \Phasis\Ast\Program $program
     */
    public function compileProgram(\Phasis\Ast\Program $program): CompiledFunction
    {
        // Program bodies resolve `this` via the env chain (global this
        // binding); never read the frame.
        $this->thisFromFrame = false;
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
                $stmt instanceof \Phasis\Ast\Declaration\ImportDeclaration
                || $stmt instanceof \Phasis\Ast\Declaration\ExportDeclaration
                || $stmt instanceof \Phasis\Ast\Declaration\ClassDeclaration
            ) {
                throw new CompilerBailout('top-level module/class decl');
            }
            if ($stmt instanceof \Phasis\Ast\Statement\TryStatement) {
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
}
