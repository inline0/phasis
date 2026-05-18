<?php

declare(strict_types=1);

namespace Phasis\Bytecode;

use Phasis\Ast\Declaration\FunctionDeclaration;
use Phasis\Ast\Declaration\VariableDeclaration;
use Phasis\Ast\Expression\AssignmentExpression;
use Phasis\Ast\Expression\BinaryExpression;
use Phasis\Ast\Expression\CallExpression;
use Phasis\Ast\Expression\ConditionalExpression;
use Phasis\Ast\Expression\Identifier;
use Phasis\Ast\Expression\Literal;
use Phasis\Ast\Expression\LogicalExpression;
use Phasis\Ast\Expression\UnaryExpression;
use Phasis\Ast\Expression\UpdateExpression;
use Phasis\Ast\Node;
use Phasis\Ast\Statement\BlockStatement;
use Phasis\Ast\Statement\BreakStatement;
use Phasis\Ast\Statement\ContinueStatement;
use Phasis\Ast\Statement\DoWhileStatement;
use Phasis\Ast\Statement\EmptyStatement;
use Phasis\Ast\Statement\ExpressionStatement;
use Phasis\Ast\Statement\ForStatement;
use Phasis\Ast\Statement\IfStatement;
use Phasis\Ast\Statement\ReturnStatement;
use Phasis\Ast\Statement\WhileStatement;
use Phasis\Value\JsFunction;

/**
 * JS-to-PHP source compiler. Emits a PHP closure that runs the JS
 * function body directly, bypassing the VM dispatch loop entirely.
 *
 * Phase 2 scope: tight numeric subset where every value flow can be
 * statically proven to use raw PHP doubles for numbers and bools for
 * booleans. The compiler now supports:
 *   - Free-variable reads (resolved via $env->get + unbox-on-entry).
 *   - Function calls with Identifier callee, when args / return are
 *     numeric. Recursive call into another phpCompiled closure goes
 *     direct, skipping executeFunction's setup; otherwise we route
 *     through Interpreter::callFunction so the spec-y bookkeeping
 *     still runs.
 *   - All Phase 1 features: arithmetic, comparisons, locals, simple
 *     control flow.
 *
 * Bails (returns null) on:
 *   - Member access (would need shape-aware property reads).
 *   - String / object / array operations.
 *   - Closures that write to free variables.
 *   - Any node not enumerated in emitStatement / emitExpression.
 *
 * Calls inside expressions are lifted to temporary statements by a
 * pendingStatements buffer so each call result lands in its own
 * named PHP variable; the enclosing expression then references that
 * variable. Avoids closure-per-expression patterns that would blow
 * up PHP JIT traces.
 */
final class JsToPhp
{
    use Parts\JsToPhpEligibility;
    use Parts\JsToPhpScope;
    use Parts\JsToPhpEmitStatement;
    use Parts\JsToPhpEmitExpression;

    /** @var array<string, true> */
    private array $declaredLocals = [];

    /**
     * Locals declared without an initializer (e.g. `var x;`,
     * `let x;`). The prologue sets their slot to a numeric default
     * (0.0) which makes a subsequent unassigned read return 0
     * instead of the spec-correct undefined. After collectLocals,
     * we cross-check against $assignedNames; any name still in
     * $unassignedDeclares is provably read-as-undefined and forces
     * a compile bailout so the tree-walker handles it.
     *
     * @var array<string, true>
     */
    private array $unassignedDeclares = [];

    /**
     * Locals declared with `const`. Any AssignmentExpression /
     * UpdateExpression target on these names should throw TypeError
     * per spec, but JsToPhp treats them as plain mutable PHP
     * variables. Tracking const names lets the collect pass detect
     * mutation attempts and bail compile, so the tree-walker
     * (which honours const-ness) raises the spec-correct error.
     *
     * @var array<string, true>
     */
    private array $constLocals = [];

    /**
     * Locals seen as the LHS of an AssignmentExpression at any point
     * in the body. Combined with $unassignedDeclares to detect the
     * "var x; ...; return x" shape that JsToPhp would otherwise
     * silently wrap as JsNumber(0).
     *
     * @var array<string, true>
     */
    private array $assignedNames = [];

    /**
     * Per-local type kind. Values:
     *   - 'numeric'  (default): PHP raw double, stored in $_l_NAME.
     *   - 'object'           : JsObject reference, stored in $_lo_NAME.
     *   - 'function'         : JsFunction reference, stored in $_lf_NAME.
     *   - 'array'            : JsArray reference, stored in $_la_NAME.
     *   - 'string'           : raw PHP string (ASCII-only profile),
     *                          stored in $_ls_NAME. UTF-16 length
     *                          equals byte length so `.length` lowers
     *                          to strlen() without conversion.
     *
     * Determined by a pre-walk that looks at every declarator init and
     * assignment. A local that is ever assigned an ObjectExpression
     * (and never assigned a numeric expression) becomes 'object';
     * a local declared as a FunctionDeclaration or used as a callee
     * becomes 'function'; a local initialised with an ArrayExpression
     * (or assigned one) becomes 'array'; a local initialised with an
     * ASCII string literal (or +="ascii" in any branch) becomes
     * 'string'; everything else stays 'numeric'. Mixed types on
     * different paths is a compile-time bailout so the emitted PHP
     * slot variable is unambiguous.
     *
     * @var array<string, string>
     */
    private array $localTypes = [];

    /** @var array<string, true> Free variables referenced by the body. */
    private array $freeVars = [];

    /**
     * Per-safe-name set of free vars whose `static $_fvenv_…, $_fvver_…,
     * $_fv_… = ...;` declaration has already been hoisted into the
     * closure prologue. Survives captureBranch (which intentionally
     * resets `freeVars` so each conditional arm re-emits the lazy
     * init check), so the same `static` declaration isn't emitted
     * twice and tripped by PHP's "Duplicate declaration of static
     * variable" compile-time error.
     *
     * @var array<string, true>
     */
    private array $freeVarStatics = [];

    /**
     * `static $_fvenv_$safe = null, $_fvver_$safe = -1, $_fv_$safe = 0.0;`
     * lines hoisted to the top of the closure. Each safe-name appears
     * at most once, regardless of how many branches resolve the same
     * free var.
     *
     * @var list<string>
     */
    private array $freeVarStaticDecls = [];

    /**
     * AST nodes the eval'd closure references at run time, indexed
     * positionally. Currently only nested FunctionDeclaration nodes
     * that the closure materialises into JsFunctions via vmMakeFunction.
     * This list ends up on JsFunction::phpCompiledNodes so each call
     * to the closure receives the same node references.
     *
     * @var list<Node>
     */
    private array $nestedFnNodes = [];

    /**
     * Map from FunctionDeclaration name to its index into
     * $nestedFnNodes. Used in emitStatement when we re-encounter a
     * FunctionDeclaration during the second walk so we don't register
     * the node twice.
     *
     * @var array<string, int>
     */
    private array $nestedFnIndex = [];

    /** Buffer accumulating emitted PHP source. */
    private string $out = '';

    /**
     * Statements queued to be emitted before the next call to
     * emitLine() that flushes them. Used by emitExpression to lift
     * call expressions out of compound expressions; each call site
     * stores its result in a temp PHP variable that the surrounding
     * expression reads via that name.
     *
     * @var list<string>
     */
    private array $pendingStatements = [];

    private int $tempCounter = 0;

    private int $indentLevel = 1;

    /**
     * Numeric calling convention flag. When true, the emitted body
     * accepts raw PHP float args via $rawArgs[i] (skipping the
     * JsNumber unbox + instanceof check) and returns a raw PHP float
     * (skipping the JsNumber::of wrap). Used to compile a parallel
     * "numeric entry" that another JsToPhp closure can invoke without
     * boxing its raw doubles into JsNumber for the call boundary.
     */
    private bool $numericMode = false;

    public static function compile(JsFunction $fn): ?\Closure
    {
        // Class / derived constructors and method-bound functions
        // (homeObject != null) carry spec semantics — `this`, `super`,
        // [[NewTarget]], MakeSuperPropertyKey — that the JsToPhp emit
        // can't model. The execution-side dispatch already gates on
        // these flags, but tryRunOnVm invokes phpCompiled BEFORE that
        // gate, so we have to refuse to compile here. `super()` in a
        // derived ctor was leaking through as `$env->get("super")`
        // which then ReferenceError-ed at runtime.
        if (
            $fn->isClassConstructor()
            || $fn->isDerivedConstructor()
            || $fn->getHomeObject() !== null
        ) {
            return null;
        }
        // Strict-mode functions can require tail-call optimisation
        // (`return f(n-1)` recursion at $MAX_ITERATIONS depth).
        // The tree-walker honours the spec via TailCallThunk +
        // callFunction trampoline; the JsToPhp closure invokes
        // its callees directly via PHP function calls which grow
        // the PHP stack. Bail strict-mode functions whose body
        // contains a return-with-call shape so the spec path runs.
        if ($fn->isStrict() && self::bodyHasReturnCall($fn->getBody())) {
            return null;
        }
        $body = $fn->getBody();
        $isExpressionBody = !$body instanceof BlockStatement;
        if ($isExpressionBody && !$body instanceof Node) {
            return null;
        }
        // Refuse to compile bodies that reference 'arguments' /
        // 'this' / 'new.target' as identifiers. The JsToPhp closure
        // runs in the function's lexical $closure environment, NOT
        // the per-invocation environment that defines those bindings,
        // so emitting `$env->get('arguments')` would either throw
        // ReferenceError or read a stale binding from an outer scope.
        if (self::bodyReferencesPerCallBindings($body)) {
            return null;
        }
        // Refuse to compile bodies that contain nested block-scoped
        // let / const declarations. JsToPhp models locals as
        // function-scoped PHP variables; it has no per-block
        // lexical scope and therefore can't enforce the spec
        // Temporal Dead Zone (`{ x; const x = 1; }` should throw
        // ReferenceError when reading x before its declaration).
        // The tree-walker / bytecode VM both honour TDZ, so falling
        // back to those paths fixes the test262 const-/let-tdz tests.
        if (self::bodyHasNestedLexical($body)) {
            return null;
        }
        $standard = self::compileWith($fn, false);
        if ($standard === null) {
            return null;
        }
        // Try to also compile a numeric-mode variant for the hot
        // JsToPhp-to-JsToPhp dispatch path. Eligibility is the same
        // body shape that produces a numeric-only return; failures
        // here just leave phpCompiledNumeric null and the caller
        // falls back to the standard entry.
        if (self::numericModeEligible($fn)) {
            $numeric = self::compileWith($fn, true);
            if ($numeric !== null) {
                $fn->phpCompiledNumeric = $numeric;
            }
        }
        return $standard;
    }

    /**
     * Compile pass with the given mode. Returns the eval'd closure
     * or null on bailout. Sets $fn->phpCompiledNodes when the
     * standard pass succeeds.
     */
    private static function compileWith(JsFunction $fn, bool $numeric): ?\Closure
    {
        $body = $fn->getBody();
        $isExpressionBody = !$body instanceof BlockStatement;
        if ($isExpressionBody && !$body instanceof Node) {
            return null;
        }
        $compiler = new self();
        $compiler->numericMode = $numeric;
        try {
            $params = $fn->getParams();
            foreach ($params as $p) {
                if (!$p instanceof Identifier) {
                    return null;
                }
                $compiler->declaredLocals[$p->name] = true;
            }
            if ($isExpressionBody) {
                $type = $compiler->inferExpressionType($body);
                if ($numeric && $type !== 'numeric') {
                    return null;
                }
                $compiler->emitPrologue($params);
                $value = $compiler->emitExpression($body);
                $compiler->flushPending();
                if ($numeric) {
                    $compiler->emitLine('return (float)(' . $value . ');');
                } elseif ($type === 'boolean') {
                    $compiler->emitLine(
                        'return \\Phasis\\Value\\JsBoolean::of((bool)(' . $value . '));'
                    );
                } elseif ($type === 'numeric') {
                    $compiler->emitLine(
                        'return \\Phasis\\Value\\JsNumber::of((float)(' . $value . '));'
                    );
                } else {
                    return null;
                }
            } else {
                $compiler->collectLocals($body->body);
                $compiler->checkNestedCaptures($body->body);
                // Bail if any declared local has no init AND no
                // AssignmentExpression target anywhere in the body.
                // Reading it would observe the prologue default
                // (0.0 for numeric, null for object) instead of the
                // spec-mandated undefined.
                foreach ($compiler->unassignedDeclares as $name => $_) {
                    if (!isset($compiler->assignedNames[$name])) {
                        return null;
                    }
                }
                // Even when the local IS assigned somewhere, the
                // prologue's default-zero is wrong for any read that
                // happens BEFORE the assignment (e.g.
                // `function f(a) { var x; if (a===1) return x; ... x = 0; }`
                // — when a is 1, x must be undefined, not 0). If the
                // body has any IfStatement / ConditionalExpression
                // along with a no-init declaration, control flow may
                // observe the unassigned slot. Bail conservatively
                // so the tree-walker (which initialises x to undefined)
                // produces the spec-correct result.
                if (
                    $compiler->unassignedDeclares !== []
                    && self::bodyHasConditional($body)
                ) {
                    return null;
                }
                // Bail if any const-declared local is mutated.
                // JsToPhp emits all locals as plain mutable PHP
                // variables; a `for (const i = 0; ...; i++) {}`
                // would silently mutate i instead of throwing the
                // spec-required TypeError.
                foreach ($compiler->constLocals as $name => $_) {
                    if (isset($compiler->assignedNames[$name])) {
                        return null;
                    }
                }
                $compiler->emitPrologue($params);
                foreach ($body->body as $stmt) {
                    $compiler->emitStatement($stmt);
                }
                if ($numeric) {
                    // Numeric-mode bodies must end with an explicit
                    // ReturnStatement (handled by emitStatement). A
                    // function that falls through has no numeric value
                    // to surface — bail at runtime so the standard
                    // entry's undefined return takes over. NAN serves
                    // as a "fell off body" sentinel here, but better
                    // is to emit a Bailout so the caller falls back.
                    $compiler->emitLine(
                        'throw new \\Phasis\\Bytecode\\Bailout("numeric body fall-off");'
                    );
                } else {
                    $compiler->emitLine('return \\Phasis\\Value\\JsUndefined::instance();');
                }
            }
        } catch (Bailout) {
            return null;
        }
        // $thisValue is the per-invocation `this` binding. The
        // closure captures the function's lexical $closure env;
        // `this` is NOT in that env (it's per-call), so it has
        // to ride in as an explicit parameter. Regular function
        // calls pass JsUndefined; method calls pass the receiver.
        $signature = $numeric
            ? 'function (array $rawArgs, $thisValue, $env, $interp, $nestedFns)'
            : 'function ($args, $thisValue, $env, $interp, $nestedFns)';
        // Guard against unbounded recursion. The interpreter's
        // CallStack enforces its own (much larger) limit, but a
        // JsToPhp closure dispatching through phpCompiled bypasses
        // callFunctionInner's push/pop entirely AND grows PHP's
        // actual interpreter stack on every recursive call. Cap
        // the recursion depth so a runaway self-recursive body
        // doesn't blow past PHP's process stack (~10 k frames on
        // a default build) and crash the worker. 8192 puts us
        // comfortably above V8/SpiderMonkey practical stack-
        // overflow thresholds while leaving headroom below
        // typical PHP defaults. Use a static counter local to the
        // closure so we don't depend on private CallStack APIs.
        $freeVarStatics = '';
        foreach ($compiler->freeVarStaticDecls as $decl) {
            $freeVarStatics .= $decl . "\n";
        }
        $bodyPrologue = $freeVarStatics
            . "static \$_jstophp_depth = 0;\n"
            . "if (\$_jstophp_depth >= 8192) { throw new \\Phasis\\Exceptions\\InternalError('Maximum call stack size exceeded'); }\n"
            . "\$_jstophp_depth++;\n"
            . "try {\n";
        $bodyEpilogue = "} finally { \$_jstophp_depth--; }\n";
        $php = "return " . $signature . " {\n"
            . $bodyPrologue . $compiler->out . $bodyEpilogue
            . "};";
        try {
            /** @var \Closure $closure */
            $closure = eval($php);
        } catch (\Throwable) {
            return null;
        }
        if (!$numeric) {
            $fn->phpCompiledNodes = $compiler->nestedFnNodes;
        }
        return $closure;
    }
}
