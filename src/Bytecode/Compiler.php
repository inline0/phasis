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
    use Parts\CompilerForOf;
    use Parts\CompilerBlockBindings;
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

    private bool $parametersFromEnvironment = false;

    private bool $tailCallsEnabled = false;

    private bool $hasTailCalls = false;

    private bool $argumentsAllowed = false;

    private bool $needsArguments = false;

    /** @var array<string, string> Captured function-scope bindings and declaration kinds. */
    private array $capturedLocals = [];

    /** @var array<string, true> Uncaptured lexical bindings with proven disjoint block lifetimes. */
    private array $reusableBlockLocals = [];

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
     * Program var bindings live in the global environment so dynamic code
     * and property writes share the same storage as compiled identifier reads.
     * Empty when compiling an ordinary function.
     *
     * @var array<string,bool>
     */
    private array $environmentVarNames = [];

    /**
     * Stack of (continueTargetPc, breakPatchOperandIndices). Each
     * loop / switch entry pushes; break/continue inside the body
     * read the top entry to emit jumps. Patched at scope close.
     *
     * @var list<array{continue: int, breaks: list<int>, continues: list<int>}>
     */
    private array $loopStack = [];

    private int $loopCount = 0;

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

    /** @var list<Node> Program expressions that need complete interpreter reference semantics. */
    private array $interpretedExpressions = [];

    /**
     * @var list<array{kind: string, elements: list<array{slot: int, capturedName: ?string, rest: bool}|array{target: Node, rest: bool}|null>}>
     */
    private array $arrayBindings = [];

    /** Interpreted assignment references need the activation's lexical this binding. */
    private bool $arrayAssignmentReferences = false;

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
     * Loop depths at each active finalizer keep unsupported escaping loop
     * completions on the interpreter path.
     *
     * @var list<int>
     */
    private array $finallyLoopBoundaries = [];

    /**
     * Return jumps target the innermost pending finalizer. A finalizer's
     * body is compiled after removing its own context, so a return there
     * overrides the saved completion and proceeds to any outer finalizer.
     *
     * @var list<array{valueSlot: int, completionSlot: int, jumps: list<int>}>
     */
    private array $finallyContexts = [];

    /**
     * THROW opcode operand: a single int that the VM uses to look
     * up the right exception via thrown JsValue. Throw lowering
     * leaves the value on stack and emits THROW; the VM raises a
     * JsThrowable with that value.
     */


    /**
     * Whether the program currently being compiled has a `"use strict"`
     * directive in its prologue (or was wrapped with one by the host).
     * Used by compileAssignment to retain interpreter reference semantics
     * when a non-local identifier is the LHS: the reference resolves before the RHS, so
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

    /** Operands can equal LOAD_THIS, so track actual instructions during emission. */
    private bool $hasLoadThis = false;

    public function compile(JsFunction $fn): CompiledFunction
    {
        $template = CompilationTemplates::get($fn);
        if ($template !== null) {
            return $template;
        }
        $reason = CompilationFailures::get($fn, 'bytecode');
        if ($reason !== null) {
            throw new CompilerBailout($reason);
        }
        try {
            $compiled = $this->compileFunction($fn);
            CompilationTemplates::remember($fn, $compiled);
            return $compiled;
        } catch (CompilerBailout $error) {
            CompilationFailures::remember($fn, 'bytecode', $error->getMessage());
            throw $error;
        }
    }

    private function compileFunction(JsFunction $fn): CompiledFunction
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
        $body = $fn->getBody();
        $functionStrict = $fn->isStrict()
            || ($body instanceof BlockStatement && self::programHasUseStrictDirective($body->body));
        $this->tailCallsEnabled = $functionStrict && !$fn->isGenerator();
        // Unmapped arguments and zero-parameter ordinary functions can use
        // the interpreter's existing arguments object without slot aliases.
        // Sloppy mapped parameter lists retain the interpreter path.
        $this->argumentsAllowed = !$fn->isGenerator() && (
            $fn->isArrow() || $functionStrict || $fn->getParams() === []
        );
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
            if ($param instanceof AssignmentPattern && $param->left instanceof Identifier && !$fn->isGenerator()) {
                if ($this->parameterInitializerCanCapture($param->right)) {
                    throw new CompilerBailout('parameter initializer captures bindings');
                }
                $this->parametersFromEnvironment = true;
                $param = $param->left;
            }
            if (!$param instanceof Identifier) {
                throw new CompilerBailout('non-simple parameter');
            }
            $slot = $this->declareLocal($param->name);
            $this->paramSlots[] = $slot;
        }

        if ($isExpressionBody) {
            $this->collectCapturedLocals($body);
            // Arrow expression body: nothing to pre-walk (no var
            // decls allowed), no TDZ, no statements. Compile the
            // single expression directly.
            $this->compileReturnExpression($body);
            $this->emit(Op::RET);
            // needsThis now means "the env must carry a `this` binding":
            // true when the body's own bytecode walks the env for this
            // (LOAD_THIS — arrows/generators) or when a nested arrow /
            // class outer-position lexically references this or super
            // and will resolve it through the env chain at run time.
            $needsEnvThis = $this->arrayAssignmentReferences || $this->hasLoadThis
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
                needsArgsBinding: $this->capturedLocals !== [] || $this->needsArguments,
                canSkipEnvAlloc: !$needsEnvThis && !$this->parametersFromEnvironment
                    && $this->capturedLocals === [] && !$this->needsArguments,
                parametersFromEnvironment: $this->parametersFromEnvironment,
                hasTailCalls: $this->hasTailCalls,
            );
            $cf->arrayBindings = $this->arrayBindings;
            $cf->callMethodDisplays = $this->callMethodDisplays;
            return $cf;
        }

        // Pre-walk: collect every var/let/const/function name in
        // the body so each is assigned a unique frame slot up front.
        $this->forOfBody = $body;
        $this->collectReusableBlockBindings($body);
        $this->collectFunctionLocals($body->body);
        if ($this->needsArguments && isset($this->localSlots['arguments'])) {
            throw new CompilerBailout('local arguments binding');
        }
        $this->collectCapturedLocals($body);

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
        $needsEnvThis = $this->arrayAssignmentReferences || $this->hasLoadThis
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
            needsArgsBinding: $this->capturedLocals !== [] || $this->needsArguments,
            canSkipEnvAlloc: !$needsEnvThis && !$this->parametersFromEnvironment
                && $this->capturedLocals === [] && $this->nestedFns === []
                && !$this->needsArguments,
            parametersFromEnvironment: $this->parametersFromEnvironment,
            hasTailCalls: $this->hasTailCalls,
            capturedEnvironmentBindings: $this->capturedEnvironmentBindings($fn),
        );
        $cf->handlers = $this->handlers;
        $cf->classNodes = $this->classNodes;
        $cf->arrayBindings = $this->arrayBindings;
        $cf->callMethodDisplays = $this->callMethodDisplays;
        return $cf;
    }

    /** @return array<string, string>|null */
    private function capturedEnvironmentBindings(JsFunction $fn): ?array
    {
        if (
            $fn->isArrow() || $fn->isGenerator() || $this->parametersFromEnvironment
            || $this->needsArguments || $this->classNodes !== [] || $this->arrayAssignmentReferences
        ) {
            return null;
        }
        $bindings = [];
        foreach ($this->capturedLocals as $name => $kind) {
            if ($kind === 'var' || $kind === 'let' || $kind === 'const') {
                $bindings[$name] = $kind;
            } elseif ($kind !== 'parameter' && $kind !== 'function') {
                return null;
            }
        }
        return $bindings;
    }

    private function parameterInitializerCanCapture(Node $node): bool
    {
        if (
            $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Expression\ArrowFunction
            || $node instanceof \Phasis\Ast\Expression\ClassExpression
            || ($node instanceof Identifier && $node->name === 'eval')
        ) {
            return true;
        }
        foreach (get_object_vars($node) as $value) {
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node && $this->parameterInitializerCanCapture($child)) {
                    return true;
                }
            }
        }
        return false;
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

        // Global var declarations are already hoisted by the interpreter.
        // Keep their reads and writes on the environment: dynamic functions
        // and callbacks can mutate the same global object during execution.
        foreach ($program->body as $stmt) {
            $this->collectProgramVarLocals($stmt);
        }

        // Reserve a slot to hold the program's completion value. Each
        // ExpressionStatement compiles to STORE_LOCAL[resultSlot] instead
        // of POP so the trailing LOAD_LOCAL+RET surfaces the last
        // expression statement's value, matching executeStatements.
        $resultSlot = count($this->localNames);
        $this->localNames[] = '[[programResult]]';

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

        $this->emit(Op::LOAD_LOCAL, $resultSlot);
        $this->emit(Op::RET);

        $needsThis = $this->hasLoadThis;
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
        $cf->interpretedExpressions = $this->interpretedExpressions;
        $cf->arrayBindings = $this->arrayBindings;
        $cf->callMethodDisplays = $this->callMethodDisplays;
        return $cf;
    }
}
