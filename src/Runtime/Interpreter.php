<?php

declare(strict_types=1);

namespace Phasis\Runtime;

use Phasis\Ast\Declaration\ClassDeclaration;
use Phasis\Ast\Declaration\ExportDeclaration;
use Phasis\Ast\Declaration\FunctionDeclaration;
use Phasis\Ast\Declaration\ImportDeclaration;
use Phasis\Ast\Declaration\VariableDeclaration;
use Phasis\Ast\Declaration\VariableDeclarator;
use Phasis\Ast\Expression\ArrayExpression;
use Phasis\Ast\Expression\ArrowFunction;
use Phasis\Ast\Expression\AssignmentExpression;
use Phasis\Ast\Expression\AwaitExpression;
use Phasis\Ast\Expression\BinaryExpression;
use Phasis\Ast\Expression\CallExpression;
use Phasis\Ast\Expression\ClassExpression;
use Phasis\Ast\Expression\ClassMethod;
use Phasis\Ast\Expression\ClassProperty;
use Phasis\Ast\Expression\PrivateIdentifier;
use Phasis\Ast\Expression\StaticBlock;
use Phasis\Ast\Expression\ConditionalExpression;
use Phasis\Ast\Expression\FunctionExpression;
use Phasis\Ast\Expression\ImportExpression;
use Phasis\Ast\Expression\MetaProperty;
use Phasis\Ast\Expression\Identifier;
use Phasis\Ast\Expression\Literal;
use Phasis\Ast\Expression\LogicalExpression;
use Phasis\Ast\Expression\MemberExpression;
use Phasis\Ast\Expression\NewExpression;
use Phasis\Ast\Expression\ObjectExpression;
use Phasis\Ast\Expression\Property;
use Phasis\Ast\Expression\SequenceExpression;
use Phasis\Ast\Expression\SpreadElement;
use Phasis\Ast\Expression\TaggedTemplate;
use Phasis\Ast\Expression\TemplateLiteral;
use Phasis\Ast\Expression\ThisExpression;
use Phasis\Ast\Expression\UnaryExpression;
use Phasis\Ast\Expression\UpdateExpression;
use Phasis\Ast\Expression\YieldExpression;
use Phasis\Ast\Node;
use Phasis\Ast\Pattern\ArrayPattern;
use Phasis\Ast\Pattern\AssignmentPattern;
use Phasis\Ast\Pattern\AssignmentProperty;
use Phasis\Ast\Pattern\ObjectPattern;
use Phasis\Ast\Pattern\RestElement;
use Phasis\Ast\Program;
use Phasis\Ast\Statement\BlockStatement;
use Phasis\Ast\Statement\BreakStatement;
use Phasis\Ast\Statement\ContinueStatement;
use Phasis\Ast\Statement\DebuggerStatement;
use Phasis\Ast\Statement\DoWhileStatement;
use Phasis\Ast\Statement\EmptyStatement;
use Phasis\Ast\Statement\ExpressionStatement;
use Phasis\Ast\Statement\ForInStatement;
use Phasis\Ast\Statement\ForOfStatement;
use Phasis\Ast\Statement\ForStatement;
use Phasis\Ast\Statement\IfStatement;
use Phasis\Ast\Statement\LabeledStatement;
use Phasis\Ast\Statement\ReturnStatement;
use Phasis\Ast\Statement\SwitchCase;
use Phasis\Ast\Statement\SwitchStatement;
use Phasis\Ast\Statement\ThrowStatement;
use Phasis\Ast\Statement\TryStatement;
use Phasis\Ast\Statement\WhileStatement;
use Phasis\Ast\Statement\WithStatement;
use Phasis\Exceptions\InternalError;
use Phasis\Exceptions\ReferenceError;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Spec\AbstractOperations;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
use Phasis\Value\JsBigInt;
use Phasis\Value\JsBoolean;
use Phasis\Value\GeneratorReturnSignal;
use Phasis\Value\GeneratorThrowSignal;
use Phasis\Value\JsAsyncGenerator;
use Phasis\Value\JsFunction;
use Phasis\Value\JsGenerator;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsArgumentsObject;
use Phasis\Value\JsObject;
use Phasis\Value\JsOptionalUndefined;
use Phasis\Value\JsProxy;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

class Interpreter
{
    use Parts\ExpressionEvaluation;
    use Parts\DisposalSupport;
    use Parts\StatementExecution;
    use Parts\Hoisting;
    use Parts\InterpreterHelpers;

    private CallStack $callStack;
    private int $maxLoopIterations;
    private bool $strictMode = false;

    /** @var array<string, bool> Current function parameter names for Annex B hoisting. */
    private array $currentParamNames = [];

    /**
     * Cached %ObjectPrototype% lookup from the global env. Accessed by
     * every object-literal evaluation; the binding never changes during
     * an Engine instance's lifetime, so we lazy-resolve once and reuse.
     */
    private ?JsObject $cachedObjectPrototype = null;

    /** Cached %StringPrototype% lookup; used by string member/method access. */
    private ?JsObject $cachedStringPrototype = null;

    /**
     * Lazy-built bytecode VM dispatcher. Reused across all VM-executed
     * function calls; instantiated on first need.
     */
    private ?\Phasis\Bytecode\VM $vm = null;

    /**
     * Stack of pooled Frame instances reused across VM dispatches.
     * Recursion-heavy workloads (fib, deep call chains) used to allocate
     * a fresh Frame on every call; the pool grows once per max depth,
     * then reuses the same instances for subsequent calls. Index =
     * current depth; entries above $framePoolDepth are quiescent.
     *
     * @var list<\Phasis\Bytecode\Frame>
     */
    private array $framePool = [];
    private int $framePoolDepth = 0;

    /** Accessor used by the VM's inline-call path (Stage 1 custom callstack). */
    public function getFramePoolDepth(): int
    {
        return $this->framePoolDepth;
    }

    /**
     * Allocate (or reuse) a Frame from the pool for an inline VM call.
     * Mirrors the pool dance in `tryRunOnVm`: index = current depth,
     * reset if reusing, allocate + stash if growing. Bumps the depth
     * counter; caller must call `releaseInlineVmFrame()` on return.
     */
    public function borrowInlineVmFrame(
        \Phasis\Runtime\Environment $fnEnv,
        \Phasis\Value\JsValue $thisValue,
        int $slotCount,
        int $paramCount,
        \Phasis\Value\JsValue $undef,
    ): \Phasis\Bytecode\Frame {
        $depth = $this->framePoolDepth;
        if ($depth < count($this->framePool)) {
            $frame = $this->framePool[$depth];
            $frame->reset($fnEnv, $thisValue, $slotCount, $paramCount, $undef);
        } else {
            $frame = new \Phasis\Bytecode\Frame(
                env: $fnEnv,
                thisValue: $thisValue,
                slotCount: $slotCount,
                undefined: $undef,
            );
            $this->framePool[] = $frame;
        }
        $this->framePoolDepth = $depth + 1;
        return $frame;
    }

    /** Release the most recently borrowed inline-call frame. */
    public function releaseInlineVmFrame(): void
    {
        if ($this->framePoolDepth > 0) {
            $this->framePoolDepth--;
        }
    }

    /**
     * Per-call interpreter-state setup for the VM's inline-call path
     * (custom callstack). Mirrors `executeVmFunctionDirect`'s setup
     * for any callee shape the VM elects to inline: handles the
     * activation recording for lazy Annex B caller/arguments reads,
     * the strictMode swap, the module-path swap, and the sloppy
     * this-binding adjustment.
     *
     * Caller (the VM) checks the structural eligibility (BC-compiled,
     * canSkipEnvAlloc, not class ctor / derived ctor / homeObject /
     * native / async / generator / bound). This helper handles every
     * remaining shape uniformly.
     *
     * Returns the bag the VM must keep alongside the saved-frame
     * entry and hand back to `teardownInlineVmCall` on RET / on
     * exception unwind through the frame. The bag's `0` slot is the
     * (possibly adjusted) `thisValue` the VM should use as the
     * callee's `this` binding.
     *
     * @param list<\Phasis\Value\JsValue> $args
     * @return array{
     *     0: \Phasis\Value\JsValue,
     *     1: bool,
     *     2: bool,
     *     3: ?string,
     *     4: bool,
     * }
     */
    public function setupInlineVmCall(
        \Phasis\Value\JsFunction $fn,
        \Phasis\Value\JsValue $thisValue,
        array $args,
    ): array {
        $this->callStack->push($fn->name, 0);
        $this->callerStack[] = $fn;

        if ($fn->setCallerPropCache === null) {
            $body = $fn->getBody();
            $hasBodyStrict = $body instanceof \Phasis\Ast\Statement\BlockStatement
                && $this->hasUseStrictDirective($body->body);
            $fn->setCallerPropCache = !$fn->isStrict()
                && !$hasBodyStrict
                && !$fn->isArrow()
                && !$fn->isNative()
                && !$fn->isAsync()
                && !$fn->isGenerator()
                && !$fn->isClassConstructor()
                && $fn->isConstructable();
        }
        // Lazy Annex B: instead of writing fn.caller / fn.arguments
        // descriptors on every call (descriptor reads + writes +
        // restore on exit), record the activation cheaply. A read of
        // fn.caller / fn.arguments materializes the value on demand
        // from these records (see lazyAnnexBValue), matching V8's
        // stack-walking semantics.
        $pushedArgRecord = $fn->setCallerPropCache;
        if ($pushedArgRecord) {
            $this->annexBArgFns[] = $fn;
            $this->annexBArgArgs[] = $args;
            $this->annexBArgEnvs[] = null;
        }

        $previousStrictMode = $this->strictMode;
        $previousModulePath = $this->currentModulePath;
        $switchModulePath = $fn->definingModulePath !== null
            && $fn->definingModulePath !== $this->currentModulePath;
        if ($switchModulePath) {
            $this->currentModulePath = $fn->definingModulePath;
        }
        if ($fn->effectiveStrictCache === null) {
            $body = $fn->getBody();
            $fn->effectiveStrictCache = $fn->isStrict()
                || ($body instanceof \Phasis\Ast\Statement\BlockStatement
                    && $this->hasUseStrictDirective($body->body));
        }
        $this->strictMode = $fn->effectiveStrictCache;

        // Sloppy-this coercion. Two common shapes dominate: `this`
        // already a JsObject (method calls), and `this` undefined
        // (bare function calls). Check them first so they each take
        // one instanceof; the rare boxed-primitive coercion path
        // takes the remaining branches.
        if (!$this->strictMode && !$fn->isArrow) {
            if ($thisValue instanceof \Phasis\Value\JsUndefined) {
                // Callee-realm global per OrdinaryCallBindThis.
                $thisValue = $this->getFunctionGlobalObject($fn);
            } elseif (!$thisValue instanceof \Phasis\Value\JsObject) {
                if ($thisValue instanceof \Phasis\Value\JsNull) {
                    $thisValue = $this->getFunctionGlobalObject($fn);
                } elseif (
                    $thisValue instanceof \Phasis\Value\JsNumber
                    || $thisValue instanceof \Phasis\Value\JsString
                    || $thisValue instanceof \Phasis\Value\JsBoolean
                    || $thisValue instanceof \Phasis\Value\JsSymbol
                    || $thisValue instanceof \Phasis\Value\JsBigInt
                ) {
                    $thisValue = \Phasis\Spec\TypeConversion::toObject($thisValue);
                }
            }
        }

        return [
            $thisValue,
            $previousStrictMode,
            $switchModulePath,
            $previousModulePath,
            $pushedArgRecord,
        ];
    }

    /**
     * Reverse of `setupInlineVmCall`. Mirrors `teardownExecuteFunction`.
     *
     * @param array{
     *     0: \Phasis\Value\JsValue,
     *     1: bool,
     *     2: bool,
     *     3: ?string,
     *     4: bool,
     * } $restore
     */
    public function teardownInlineVmCall(\Phasis\Value\JsFunction $fn, array $restore): void
    {
        // Hot path: direct-index reads instead of list destructure.
        // Module-path restore is checked only when its gate flag
        // (slot 2) is true.
        if ($restore[2]) {
            $this->currentModulePath = $restore[3];
        }
        array_pop($this->callerStack);
        if ($restore[4]) {
            array_pop($this->annexBArgFns);
            array_pop($this->annexBArgArgs);
            array_pop($this->annexBArgEnvs);
        }
        $this->strictMode = $restore[1];
        $this->callStack->pop();
    }

    /**
     * Activation records for lazy Annex B fn.arguments reads: one
     * entry per active call of a sloppy ordinary function (the
     * setCallerPropCache-eligible shape), aligned across the three
     * arrays. A read of fn.arguments mid-call materializes a fresh
     * arguments object from the topmost record for that function;
     * when no record exists the descriptor's stored null stands.
     * Pushed by executeFunction / executeVmFunctionDirect /
     * setupInlineVmCall, popped by their teardowns.
     *
     * @var list<\Phasis\Value\JsFunction> */
    public array $annexBArgFns = [];
    /** @var list<list<\Phasis\Value\JsValue>> */
    public array $annexBArgArgs = [];
    /** @var list<?Environment> */
    public array $annexBArgEnvs = [];

    /**
     * Compute the live Annex B `caller` / `arguments` value for a
     * sloppy ordinary function that is currently executing. Returns
     * null when the function has no active invocation (the stored
     * descriptor value — null — stands) or is not eligible. Mirrors
     * V8: the value is materialized at read time by walking the
     * active-call records, not eagerly written per call.
     */
    public function lazyAnnexBValue(\Phasis\Value\JsFunction $fn, string $name): ?\Phasis\Value\JsValue
    {
        if ($fn->setCallerPropCache !== true) {
            return null;
        }
        if ($name === 'caller') {
            for ($i = count($this->callerStack) - 1; $i >= 0; $i--) {
                if ($this->callerStack[$i] === $fn) {
                    $callerFn = $i > 0 ? $this->callerStack[$i - 1] : null;
                    if (!$callerFn instanceof \Phasis\Value\JsFunction) {
                        return \Phasis\Value\JsNull::instance();
                    }
                    $callerStrict = $callerFn->effectiveStrictCache ?? $callerFn->isStrict();
                    return $callerStrict ? \Phasis\Value\JsNull::instance() : $callerFn;
                }
            }
            return null;
        }
        // arguments: materialize a fresh snapshot from the topmost
        // activation record. Mapped-parameter liveness is approximated
        // by reading the current env binding when the activation ran
        // through the tree-walker (env recorded); VM-run bodies keep
        // call-time values, matching the engine's pre-lazy behavior
        // for compiled bodies.
        for ($i = count($this->annexBArgFns) - 1; $i >= 0; $i--) {
            if ($this->annexBArgFns[$i] === $fn) {
                $args = $this->annexBArgArgs[$i];
                $env = $this->annexBArgEnvs[$i];
                if ($env !== null) {
                    $params = $fn->getParams();
                    $n = min(count($params), count($args));
                    for ($p = 0; $p < $n; $p++) {
                        $param = $params[$p];
                        if ($param instanceof \Phasis\Ast\Expression\Identifier && $env->has($param->name)) {
                            $args[$p] = $env->get($param->name);
                        }
                    }
                }
                return $this->makeArgumentsObject($args, $fn, false);
            }
        }
        return null;
    }

    /**
     * Whether the program currently being executed is provably free
     * of direct `eval` calls. When true (the common case), the
     * Identifier resolution cache `Identifier::$resolvedDepth` can be
     * trusted. A direct eval flips this off because eval-injected
     * `var` bindings can change which env owns a name, invalidating
     * depths cached previously. Default true; the first eval call
     * (or a parse-time scan of the program for `eval` references)
     * sets it false and prevents further cache reads.
     */
    private bool $programIsEvalFree = true;

    /** When true, hoistDeclarations skips Annex B block-function hoisting. */
    private bool $skipAnnexBHoisting = false;

    /** When true, return statements with call expressions can produce TailCallThunks. */
    private bool $inTailPosition = false;

    /**
     * Track which FunctionDeclaration AST nodes are eligible for Annex B
     * propagation. Only function declarations identified during hoisting
     * should propagate their value to the function scope during execution.
     * Keyed by spl_object_id of the FunctionDeclaration AST node.
     * @var array<int, bool>
     */
    private array $annexBEligible = [];

    /**
     * Map of with-environment identity to their binding objects.
     * Used for spec-correct binding resolution (ResolveBinding before Initializer).
     * Keys are spl_object_id of the Environment, values are the JsObject.
     * @var array<int, JsObject>
     */
    private array $withEnvObjects = [];

    /**
     * Set of spl_object_id values for JsObjects currently used as with-binding objects.
     * Used by SetMutableBinding to perform the HasProperty check per spec 9.1.1.2.5.
     * @var array<int, true>
     */
    private array $activeWithObjectIds = [];

    /** Whether this interpreter is executing indirect eval code. */
    private bool $isEvalContext = false;

    /**
     * When true, indirect eval creates a fresh DeclarativeEnvironment for
     * its var bindings even in non-strict mode. Used by ShadowRealm.evaluate
     * which (per PerformShadowRealmEval) always isolates declarations,
     * regardless of the source's directive prologue.
     */
    private bool $isolateEvalScope = false;

    /** @var list<\Phasis\Value\JsFunction|null> Stack of currently executing functions for Annex B caller. */
    private array $callerStack = [];

    /** Monotonically increasing counter for unique private name brands. */
    private static int $nextPrivateBrandId = 0;

    /** Monotonically increasing counter for unique auto-accessor storage slots. */
    private static int $nextAutoAccessorId = 0;

    /** Module loader for dynamic import() and static import/export. */
    private ?\Phasis\Module\ModuleLoader $moduleLoader = null;

    /** Current module path for resolving relative import specifiers. */
    private ?string $currentModulePath = null;

    /**
     * Cache of import.meta objects, keyed by module path. Per spec, every
     * evaluation of `import.meta` within a single module returns the same
     * object — host hooks may attach properties to it that should persist
     * across reads.
     *
     * @var array<string, JsObject>
     */
    private array $importMetaCache = [];

    public function getModuleLoader(): \Phasis\Module\ModuleLoader
    {
        if ($this->moduleLoader === null) {
            $this->moduleLoader = new \Phasis\Module\ModuleLoader($this, $this->globalEnv);
        }
        return $this->moduleLoader;
    }

    public function setModuleLoader(\Phasis\Module\ModuleLoader $loader): void
    {
        $this->moduleLoader = $loader;
    }

    public function setCurrentModulePath(?string $path): void
    {
        $this->currentModulePath = $path;
    }

    public function getCurrentModulePath(): ?string
    {
        return $this->currentModulePath;
    }

    /**
     * The Engine (realm) this interpreter belongs to. Set by Engine::__construct
     * via setRealm(). Used by GetFunctionRealm so native built-ins constructed
     * during installBuiltins can be tagged with the realm that's building them.
     */
    private ?\Phasis\Engine $engineRealm = null;

    public function setRealm(\Phasis\Engine $engine): void
    {
        $this->engineRealm = $engine;
    }

    public function getRealm(): ?\Phasis\Engine
    {
        return $this->engineRealm;
    }

    public function __construct(
        private Environment $globalEnv,
        ?CallStack $callStack = null,
        int $maxLoopIterations = 100000,
    ) {
        $this->callStack = $callStack ?? new CallStack();
        $this->maxLoopIterations = $maxLoopIterations;

        // Register interpreter callback so JsFunction::call() works for non-native functions
        JsFunction::setInterpreterCallback(function (JsFunction $fn, JsValue $thisValue, array $args): JsValue {
            return $this->callFunction($fn, $thisValue, $args);
        });

        // Store interpreter reference for private accessor invocation in JsObject.
        JsFunction::setInterpreterInstance($this);
    }

    public function setMaxLoopIterations(int $limit): void
    {
        $this->maxLoopIterations = $limit;
    }

    /** Mark this interpreter as running eval code (indirect eval). */
    public function setEvalContext(bool $isEval): void
    {
        $this->isEvalContext = $isEval;
    }

    public function isEvalContext(): bool
    {
        return $this->isEvalContext;
    }

    public function isStrictMode(): bool
    {
        return $this->strictMode;
    }

    public function setStrictMode(bool $strict): void
    {
        $this->strictMode = $strict;
    }

    /** Toggle ShadowRealm-style isolation: each indirect eval gets its
     * own DeclarativeEnvironment for var bindings, regardless of strict.
     */
    public function setIsolateEvalScope(bool $value): void
    {
        $this->isolateEvalScope = $value;
    }

    public function isIsolateEvalScope(): bool
    {
        return $this->isolateEvalScope;
    }

    /** Check whether a list of statements begins with a "use strict" directive. */
    /**
     * @param array<mixed> $statements
     */
    private function hasUseStrictDirective(array $statements): bool
    {
        foreach ($statements as $stmt) {
            if (!$stmt instanceof ExpressionStatement) {
                break;
            }
            $expr = $stmt->expression;
            if ($expr instanceof Literal && is_string($expr->value) && $expr->value === 'use strict') {
                // Per spec, the directive must be the exact token "use strict" or 'use strict'
                // with no escape sequences or line continuations. The verbatim flag is set by
                // the lexer when the string contained no backslash escapes.
                if ($expr->verbatim) {
                    return true;
                }
            }
            // Only string literal expression statements can be directives.
            if (!$expr instanceof Literal || !is_string($expr->value)) {
                break;
            }
        }
        return false;
    }

    public function execute(Program $program): JsValue
    {
        // Detect strict mode from directive prologue.
        if ($this->hasUseStrictDirective($program->body)) {
            $this->strictMode = true;
        }

        // Pre-scan: if the program contains any reference to the
        // identifier `eval` (used as a callee, captured into a
        // variable, etc.), treat the whole program as eval-tainted.
        // The Identifier scope-depth cache is only consulted when
        // this flag stays true; an eval-tainted program could have
        // var bindings injected mid-execution that move where a
        // name resolves to. A `with` statement under `programIsEvalFree`
        // is fine — that path is gated separately by
        // hasAnyWithObjectInChain at lookup time.
        if (
            $this->programIsEvalFree
            && $this->programReferencesEval($program->body)
        ) {
            $this->programIsEvalFree = false;
        }

        // Validate strict mode restrictions (reserved words, with, etc.)
        // so that indirect eval with 'use strict' code gets checked.
        if ($this->strictMode) {
            $this->validateStrictModeRestrictions($program->body);
        }
        $this->validateSelfStrictFunctions($program->body);

        // Per spec PerformEval: indirect eval creates a new declarative
        // environment for its lexical declarations (step 10.a), while var
        // declarations go to the global VariableEnvironment. Lexical
        // declarations in indirect eval do not conflict with existing
        // global lexical bindings.
        if ($this->isEvalContext) {
            // Per PerformEval step 10: if the eval source is strict, create a
            // new declarative VariableEnvironment so var/function declarations
            // do not leak into the global scope. Otherwise var declarations
            // go into the caller's VariableEnvironment (globalEnv here).
            // ShadowRealm.evaluate (isolateEvalScope) takes the strict path
            // for env isolation regardless of strictness, since per
            // PerformShadowRealmEval each evaluate gets a fresh varEnv/lexEnv.
            if ($this->strictMode || $this->isolateEvalScope) {
                $varEnv = $this->globalEnv->createChild();
                $lexEnv = $varEnv->createChild();
                // Pre-declare all var names in varEnv as own bindings so
                // hoistDeclarations does not skip them when an outer scope
                // (e.g. global) already has a same-named binding.
                $strictVarNames = $this->collectEvalVarNames($program->body);
                foreach ($strictVarNames as $vn) {
                    if (!$varEnv->hasOwnBinding($vn)) {
                        $varEnv->defineVar($vn, JsUndefined::instance());
                    }
                }
                $this->hoistDeclarations($program->body, $varEnv);
                $this->hoistEvalLexicalDeclarations($program->body, $lexEnv);
                return $this->executeStatements($program->body, $lexEnv);
            }
            $lexEnv = $this->globalEnv->createChild();
            // Per EvalDeclarationInstantiation step 15/18: non-strict indirect
            // eval at global scope creates function/var bindings with D=true,
            // so they are configurable. Use the eval-specific hoist path that
            // matches direct eval at global scope.
            if ($this->globalEnv->getLinkedObject() !== null) {
                $this->hoistEvalGlobalDeclarations($program->body, $this->globalEnv);
            } else {
                $this->hoistDeclarations($program->body, $this->globalEnv);
            }
            $this->hoistEvalLexicalDeclarations($program->body, $lexEnv);
            return $this->executeStatements($program->body, $lexEnv);
        }

        $this->validateGlobalLexDecls($program->body);
        // Early-error check: Script top-level must not contain bare return,
        // break, or continue (§16.1.1), or super/new.target/private
        // identifiers outside a class body. Reuses the eval-body/program
        // validators and the dynamic-function private-name rejecter.
        $this->validateEvalBody($program->body);
        if ($this->astContainsNewTargetTransparent($program->body)) {
            throw new \Phasis\Exceptions\SyntaxError(
                'new.target expression is not allowed here'
            );
        }
        if ($this->astContainsSuperTransparent($program->body)) {
            throw new \Phasis\Exceptions\SyntaxError(
                "'super' keyword unexpected here"
            );
        }
        \Phasis\BuiltIn\GlobalObject::rejectPrivateIdentifiersInProgramPublic($program);
        $this->hoistDeclarations($program->body, $this->globalEnv);
        $this->hoistEvalLexicalDeclarations($program->body, $this->globalEnv);
        // Top-level bytecode fast path: if the program body lowers to
        // VM bytecode (predominantly `var` + control flow + calls into
        // hoisted functions), execute via the bytecode dispatcher. The
        // tree-walker per-iteration overhead is what otherwise makes
        // stress tests like decodeURI's 4-byte UTF-8 sweep time out at
        // top level. Falls back to the tree-walker on any bailout.
        $vmResult = $this->tryRunProgramOnVm($program);
        if ($vmResult !== null) {
            return $vmResult;
        }
        return $this->executeStatements($program->body, $this->globalEnv);
    }

    /**
     * Best-effort lowering of the top-level Program to bytecode. Returns
     * null when the Compiler refuses (top-level let/const, module
     * syntax, classes, eval-tainted identifiers, etc.) so the caller
     * falls back to the tree-walker. On success the VM runs the
     * compiled bytecode with Frame::env = globalEnv directly; nested
     * closures capture globalEnv as expected.
     */
    private function tryRunProgramOnVm(\Phasis\Ast\Program $program): ?JsValue
    {
        // The Interpreter::$programIsEvalFree flag is intentionally
        // not consulted here: it is set false for the LIFETIME of an
        // Engine after the first program that references `eval`, so a
        // single tainted program (e.g. the test262 `$262.agent` shim
        // that does `(0, eval)(...)`) would force every subsequent
        // engine->eval() call back onto the tree-walker. Per-program
        // safety is enforced by Compiler::scanBailout, which rejects
        // any individual Program whose body still references `eval`
        // or `arguments` at top level. A program without those
        // identifiers is safe to lower even if the engine ran other
        // eval-tainted programs earlier.
        //
        // Modules carry import/export declarations the bytecode path
        // refuses; route them straight to the tree-walker.
        foreach ($program->body as $stmt) {
            if (
                $stmt instanceof \Phasis\Ast\Declaration\ImportDeclaration
                || $stmt instanceof \Phasis\Ast\Declaration\ExportDeclaration
            ) {
                return null;
            }
        }
        try {
            $compiler = new \Phasis\Bytecode\Compiler();
            $cf = $compiler->compileProgram($program);
        } catch (\Phasis\Bytecode\CompilerBailout) {
            return null;
        } catch (\Throwable) {
            // Defensive: a compile bug must never break the
            // tree-walker fallback. Skip compile and let the body run
            // normally through the interpreter.
            return null;
        }

        if ($this->vm === null) {
            $this->vm = new \Phasis\Bytecode\VM($this);
        }
        $undef = JsUndefined::instance();
        // Top-level `this` resolves through env->get('this') which
        // Engine seeds with the global object on the globalEnv.
        $thisValue = $this->globalEnv->has('this')
            ? $this->globalEnv->get('this')
            : $undef;
        $frame = new \Phasis\Bytecode\Frame(
            env: $this->globalEnv,
            thisValue: $thisValue,
            slotCount: $cf->slotCount,
            undefined: $undef,
        );
        return $this->vm->execute($cf, $frame);
    }

    /**
     * Per ModuleDeclarationInstantiation: the LexicallyDeclaredNames of
     * the ModuleBody must be unique, and the ExportedNames (union across
     * all export declarations, including `export default` and `export *`
     * with alias) must also be unique. Duplicate declarations at module
     * top level are parse-time SyntaxErrors.
     *
     * @param Node[] $body
     */
    private function validateModuleTopLevelDuplicateBindings(array $body): void
    {
        $names = [];
        $varNames = [];
        $exportNames = [];
        $addExport = function (string $name) use (&$exportNames): void {
            if (isset($exportNames[$name])) {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Duplicate export of '{$name}'",
                );
            }
            $exportNames[$name] = true;
        };
        foreach ($body as $stmt) {
            // Track export names for each export declaration.
            if ($stmt instanceof ExportDeclaration) {
                if ($stmt->isDefault) {
                    $addExport('default');
                }
                foreach ($stmt->specifiers as $spec) {
                    $addExport($spec->exported ?? $spec->local);
                }
                if ($stmt->isAll && $stmt->allAs !== null) {
                    $addExport($stmt->allAs);
                }
                if ($stmt->declaration !== null) {
                    $inner = $stmt->declaration;
                    if (
                        $inner instanceof FunctionDeclaration
                        || $inner instanceof ClassDeclaration
                    ) {
                        if ($inner->id !== null) {
                            $addExport($inner->id->name);
                        }
                    } elseif ($inner instanceof VariableDeclaration) {
                        foreach ($inner->declarations as $d) {
                            foreach ($this->patternBoundNames($d->id) as $n) {
                                $addExport($n);
                            }
                        }
                    }
                }
            }

            // Track locally-bound lexical declarations.
            $inner = $stmt instanceof ExportDeclaration ? $stmt->declaration : $stmt;
            if ($inner === null) {
                continue;
            }
            $collected = [];
            if (
                $inner instanceof VariableDeclaration && (
                $inner->kind === 'let' || $inner->kind === 'const'
                || $inner->kind === 'using' || $inner->kind === 'await using'
                )
            ) {
                foreach ($inner->declarations as $d) {
                    foreach ($this->patternBoundNames($d->id) as $n) {
                        $collected[] = $n;
                    }
                }
            } elseif (
                ($inner instanceof FunctionDeclaration || $inner instanceof ClassDeclaration)
                && $inner->id !== null
            ) {
                $collected[] = $inner->id->name;
            } elseif (
                $inner instanceof VariableDeclaration && $inner->kind === 'var'
            ) {
                foreach ($inner->declarations as $d) {
                    foreach ($this->patternBoundNames($d->id) as $n) {
                        $varNames[$n] = true;
                    }
                }
            }
            foreach ($collected as $n) {
                if (isset($names[$n])) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Identifier '{$n}' has already been declared",
                    );
                }
                $names[$n] = true;
            }
        }
        // Per §16.1.7: every ExportedBinding from `export { name }` (no
        // `from`) must be declared locally — either lex or var.
        $exportedBindings = [];
        $importBindings = [];
        foreach ($body as $stmt) {
            if ($stmt instanceof ExportDeclaration && $stmt->source === null) {
                foreach ($stmt->specifiers as $spec) {
                    $exportedBindings[$spec->local] = true;
                }
            }
            if ($stmt instanceof ImportDeclaration) {
                foreach ($stmt->specifiers as $spec) {
                    $importBindings[$spec->local] = true;
                }
            }
        }
        // Now flag any exported binding not declared locally.
        $allLocal = $names + $varNames + $importBindings;
        foreach (array_keys($exportedBindings) as $bound) {
            if (!isset($allLocal[$bound])) {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Export of unknown binding '{$bound}'",
                );
            }
        }
        // Modules apply the lex-vs-var overlap rule with no Annex B
        // function-to-var hoisting. Also collect nested var-declared names
        // (from nested blocks) at module-top-level.
        foreach ($body as $stmt) {
            $this->collectModuleVarDeclaredNames($stmt, $varNames);
        }
        foreach (array_keys($names) as $n) {
            if (isset($varNames[$n])) {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Identifier '{$n}' has already been declared",
                );
            }
        }
    }

    /**
     * Walk into nested block-like statements but not into functions/classes,
     * collecting `var` declarations at module top-level.
     *
     * @param array<string,bool> $out
     */
    private function collectModuleVarDeclaredNames(Node $node, array &$out): void
    {
        if ($node instanceof VariableDeclaration && $node->kind === 'var') {
            foreach ($node->declarations as $d) {
                foreach ($this->patternBoundNames($d->id) as $n) {
                    $out[$n] = true;
                }
            }
            return;
        }
        if ($node instanceof BlockStatement) {
            foreach ($node->body as $s) {
                $this->collectModuleVarDeclaredNames($s, $out);
            }
            return;
        }
        if ($node instanceof \Phasis\Ast\Statement\IfStatement) {
            $this->collectModuleVarDeclaredNames($node->consequent, $out);
            if ($node->alternate !== null) {
                $this->collectModuleVarDeclaredNames($node->alternate, $out);
            }
            return;
        }
        if ($node instanceof \Phasis\Ast\Statement\ForStatement) {
            if ($node->init instanceof Node) {
                $this->collectModuleVarDeclaredNames($node->init, $out);
            }
            $this->collectModuleVarDeclaredNames($node->body, $out);
            return;
        }
        if (
            $node instanceof \Phasis\Ast\Statement\ForInStatement
            || $node instanceof \Phasis\Ast\Statement\ForOfStatement
        ) {
            if ($node->left instanceof VariableDeclaration) {
                $this->collectModuleVarDeclaredNames($node->left, $out);
            }
            $this->collectModuleVarDeclaredNames($node->body, $out);
            return;
        }
        if (
            $node instanceof \Phasis\Ast\Statement\WhileStatement
            || $node instanceof \Phasis\Ast\Statement\DoWhileStatement
        ) {
            $this->collectModuleVarDeclaredNames($node->body, $out);
            return;
        }
        if ($node instanceof \Phasis\Ast\Statement\SwitchStatement) {
            foreach ($node->cases as $case) {
                foreach ($case->consequent as $s) {
                    $this->collectModuleVarDeclaredNames($s, $out);
                }
            }
            return;
        }
        if ($node instanceof \Phasis\Ast\Statement\TryStatement) {
            $this->collectModuleVarDeclaredNames($node->block, $out);
            if ($node->handler !== null) {
                $this->collectModuleVarDeclaredNames($node->handler->body, $out);
            }
            if ($node->finalizer !== null) {
                $this->collectModuleVarDeclaredNames($node->finalizer, $out);
            }
            return;
        }
        if ($node instanceof \Phasis\Ast\Statement\LabeledStatement) {
            $this->collectModuleVarDeclaredNames($node->body, $out);
            return;
        }
    }

    /**
     * Per GlobalDeclarationInstantiation step 5c/5d: lexical declarations
     * must not collide with restricted (non-configurable) global properties.
     *
     * @param Node[] $body
     */
    private function validateGlobalLexDecls(array $body): void
    {
        $globalObj = $this->globalEnv->getLinkedObject();
        if ($globalObj === null) {
            return;
        }

        // Collect lexical names (let/const/class declarations).
        $lexNames = [];
        $seenLex = [];
        foreach ($body as $stmt) {
            $newNames = [];
            if (
                $stmt instanceof VariableDeclaration && (
                $stmt->kind === 'let' || $stmt->kind === 'const'
                || $stmt->kind === 'using' || $stmt->kind === 'await using'
                )
            ) {
                foreach ($stmt->declarations as $decl) {
                    foreach ($this->patternBoundNames($decl->id) as $n) {
                        $newNames[] = $n;
                    }
                }
            } elseif ($stmt instanceof ClassDeclaration && $stmt->id !== null) {
                $newNames[] = $stmt->id->name;
            }
            foreach ($newNames as $n) {
                // Per §16.1.1: LexicallyDeclaredNames of script body
                // cannot have duplicates.
                if (isset($seenLex[$n])) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Identifier '{$n}' has already been declared",
                    );
                }
                $seenLex[$n] = true;
                $lexNames[] = $n;
            }
        }

        // Per spec step 5: for each lexical name, check:
        //   a. HasVarDeclaration or HasLexicalDeclaration => SyntaxError
        //   c/d. HasRestrictedGlobalProperty => SyntaxError
        foreach ($lexNames as $name) {
            // Check for existing lexical binding in the global environment.
            if ($this->globalEnv->hasLexicalBinding($name)) {
                $this->throwJsValue(
                    $this->phpExceptionToJsValue(
                        new \Phasis\Exceptions\SyntaxError(
                            "Identifier '{$name}' has already been declared",
                        ),
                    ),
                );
            }
            // Check for restricted global property (non-configurable).
            if ($globalObj->hasOwnProperty($name)) {
                $desc = $globalObj->getOwnPropertyDescriptor($name);
                if ($desc !== null && $desc->configurable === false) {
                    $this->throwJsValue(
                        $this->phpExceptionToJsValue(
                            new \Phasis\Exceptions\SyntaxError(
                                "Identifier '{$name}' has already been declared",
                            ),
                        ),
                    );
                }
            }
        }

        // Collect var names.
        $varNames = [];
        foreach ($body as $stmt) {
            if ($stmt instanceof VariableDeclaration && $stmt->kind === 'var') {
                foreach ($stmt->declarations as $decl) {
                    foreach ($this->patternBoundNames($decl->id) as $n) {
                        $varNames[] = $n;
                    }
                }
            } elseif ($stmt instanceof FunctionDeclaration) {
                $varNames[] = $stmt->id->name;
            }
        }

        // Per spec step 6: for each var name, check HasLexicalDeclaration.
        foreach ($varNames as $name) {
            if ($this->globalEnv->hasLexicalBinding($name)) {
                $this->throwJsValue(
                    $this->phpExceptionToJsValue(
                        new \Phasis\Exceptions\SyntaxError(
                            "Identifier '{$name}' has already been declared",
                        ),
                    ),
                );
            }
        }

        // Per spec step 10: CanDeclareGlobalFunction for each function declaration.
        // Per spec step 12: CanDeclareGlobalVar for each var name.
        $isExtensible = $globalObj->isExtensible();

        // Collect function declaration names (in reverse order, last wins).
        $declaredFuncNames = [];
        for ($i = count($body) - 1; $i >= 0; $i--) {
            $stmt = $body[$i];
            if ($stmt instanceof FunctionDeclaration) {
                $fn = $stmt->id->name;
                if (!isset($declaredFuncNames[$fn])) {
                    $declaredFuncNames[$fn] = true;
                    // CanDeclareGlobalFunction check.
                    $existingProp = $globalObj->getOwnPropertyDescriptor($fn);
                    if ($existingProp === null) {
                        // No existing property: check extensibility.
                        if (!$isExtensible) {
                            $this->throwJsValue(
                                $this->phpExceptionToJsValue(
                                    new TypeError("Cannot define property {$fn}, object is not extensible"),
                                ),
                            );
                        }
                    } elseif (!$existingProp->configurable) {
                        // Non-configurable: must be data, writable, enumerable.
                        $isOk = $existingProp->isDataDescriptor()
                            && $existingProp->writable === true
                            && $existingProp->enumerable === true;
                        if (!$isOk) {
                            $this->throwJsValue(
                                $this->phpExceptionToJsValue(
                                    new TypeError(
                                        "Cannot redefine property: {$fn}",
                                    ),
                                ),
                            );
                        }
                    }
                }
            }
        }

        // CanDeclareGlobalVar for each var name not already a declared function.
        foreach ($body as $stmt) {
            if ($stmt instanceof VariableDeclaration && $stmt->kind === 'var') {
                foreach ($stmt->declarations as $decl) {
                    foreach ($this->patternBoundNames($decl->id) as $vn) {
                        if (!isset($declaredFuncNames[$vn])) {
                            if (!$globalObj->hasOwnProperty($vn) && !$isExtensible) {
                                $this->throwJsValue(
                                    $this->phpExceptionToJsValue(
                                        new TypeError(
                                            "Cannot define property {$vn}, object is not extensible",
                                        ),
                                    ),
                                );
                            }
                        }
                    }
                }
            }
        }
    }

    /** @param Node[] $statements */
    private function executeStatements(array $statements, Environment $env): JsValue
    {
        $result = JsUndefined::instance();
        foreach ($statements as $stmt) {
            // Same ExpressionStatement fast path as executeBody.
            if ($stmt instanceof ExpressionStatement) {
                $result = $this->evaluate($stmt->expression, $env);
                continue;
            }
            $completion = $this->executeStatement($stmt, $env);
            if ($completion->isAbrupt()) {
                return $this->handleAbrupt($completion);
            }
            // Empty completions (empty statement, debugger) don't override the result
            if (!$completion->empty) {
                $result = $completion->value;
            }
        }
        return $result;
    }

    /** @param Node[] $statements */
    public function executeBody(array $statements, Environment $env): Completion
    {
        $result = JsUndefined::instance();
        $anyNonEmpty = false;
        foreach ($statements as $stmt) {
            // Inline the ExpressionStatement path (the dominant statement
            // type in real bodies). Skips the per-statement Completion
            // allocation that execExpressionStatement otherwise emits and
            // the executeStatement match-true dispatch.
            if ($stmt instanceof ExpressionStatement) {
                $result = $this->evaluate($stmt->expression, $env);
                $anyNonEmpty = true;
                continue;
            }
            $completion = $this->executeStatement($stmt, $env);
            if ($completion->isAbrupt()) {
                if ($completion->empty && !$result instanceof JsUndefined) {
                    return new Completion($completion->type, $result, $completion->target);
                }
                return $completion;
            }
            if (!$completion->empty) {
                $result = $completion->value;
                $anyNonEmpty = true;
            }
        }
        if (!$anyNonEmpty) {
            return Completion::normalEmpty();
        }
        return new Completion(CompletionType::Normal, $result, empty: false);
    }

    private function executeStatement(Node $node, Environment $env): Completion
    {
        // ExpressionStatement is the overwhelmingly common statement type
        // in real code (every function body, every loop iteration body).
        // Handle it via a typed pre-check before the match dispatch.
        if ($node instanceof ExpressionStatement) {
            return $this->execExpressionStatement($node, $env);
        }
        return match (true) {
            $node instanceof BlockStatement => $this->execBlockStatement($node, $env),
            $node instanceof IfStatement => $this->execIfStatement($node, $env),
            $node instanceof VariableDeclaration => $this->execVariableDeclaration($node, $env),
            $node instanceof ReturnStatement => $this->execReturnStatement($node, $env),
            $node instanceof ForStatement => $this->execForStatement($node, $env),
            $node instanceof FunctionDeclaration => $this->execFunctionDeclaration($node, $env),
            $node instanceof ClassDeclaration => $this->execClassDeclaration($node, $env),
            $node instanceof ForInStatement => $this->execForInStatement($node, $env),
            $node instanceof ForOfStatement => $this->execForOfStatement($node, $env),
            $node instanceof WhileStatement => $this->execWhileStatement($node, $env),
            $node instanceof DoWhileStatement => $this->execDoWhileStatement($node, $env),
            $node instanceof SwitchStatement => $this->execSwitchStatement($node, $env),
            $node instanceof ThrowStatement => $this->execThrowStatement($node, $env),
            $node instanceof TryStatement => $this->execTryStatement($node, $env),
            $node instanceof BreakStatement => Completion::break($node->label),
            $node instanceof ContinueStatement => Completion::continue($node->label),
            $node instanceof LabeledStatement => $this->execLabeledStatement($node, $env),
            $node instanceof WithStatement => $this->execWithStatement($node, $env),
            $node instanceof EmptyStatement => Completion::normalEmpty(),
            $node instanceof DebuggerStatement => Completion::normal(JsUndefined::instance()),
            $node instanceof ImportDeclaration => Completion::normal(JsUndefined::instance()),
            $node instanceof ExportDeclaration => $this->execExportDeclaration($node, $env),
            default => throw new InternalError('Unknown statement type: ' . $node->type()),
        };
    }
}
