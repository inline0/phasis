<?php

declare(strict_types=1);

namespace PhpJs\Bytecode;

use PhpJs\Ast\Declaration\FunctionDeclaration;
use PhpJs\Ast\Declaration\VariableDeclaration;
use PhpJs\Ast\Expression\AssignmentExpression;
use PhpJs\Ast\Expression\BinaryExpression;
use PhpJs\Ast\Expression\CallExpression;
use PhpJs\Ast\Expression\ConditionalExpression;
use PhpJs\Ast\Expression\Identifier;
use PhpJs\Ast\Expression\Literal;
use PhpJs\Ast\Expression\LogicalExpression;
use PhpJs\Ast\Expression\UnaryExpression;
use PhpJs\Ast\Expression\UpdateExpression;
use PhpJs\Ast\Node;
use PhpJs\Ast\Statement\BlockStatement;
use PhpJs\Ast\Statement\BreakStatement;
use PhpJs\Ast\Statement\ContinueStatement;
use PhpJs\Ast\Statement\DoWhileStatement;
use PhpJs\Ast\Statement\EmptyStatement;
use PhpJs\Ast\Statement\ExpressionStatement;
use PhpJs\Ast\Statement\ForStatement;
use PhpJs\Ast\Statement\IfStatement;
use PhpJs\Ast\Statement\ReturnStatement;
use PhpJs\Ast\Statement\WhileStatement;
use PhpJs\Value\JsFunction;

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
    /** @var array<string, true> */
    private array $declaredLocals = [];

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
                        'return \\PhpJs\\Value\\JsBoolean::of((bool)(' . $value . '));'
                    );
                } elseif ($type === 'numeric') {
                    $compiler->emitLine(
                        'return \\PhpJs\\Value\\JsNumber::of((float)(' . $value . '));'
                    );
                } else {
                    return null;
                }
            } else {
                $compiler->collectLocals($body->body);
                $compiler->checkNestedCaptures($body->body);
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
                        'throw new \\PhpJs\\Bytecode\\Bailout("numeric body fall-off");'
                    );
                } else {
                    $compiler->emitLine('return \\PhpJs\\Value\\JsUndefined::instance();');
                }
            }
        } catch (Bailout) {
            return null;
        }
        $signature = $numeric
            ? 'function (array $rawArgs, $env, $interp, $nestedFns)'
            : 'function ($args, $env, $interp, $nestedFns)';
        $php = "return " . $signature . " {\n" . $compiler->out . "};";
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

    /**
     * Infer the static "type kind" of an expression's value:
     *   - 'numeric'  : produces a PHP raw double (or int that PHP
     *                  silently widens to float).
     *   - 'boolean'  : produces a PHP bool.
     *   - 'unknown'  : can't classify statically; caller should bail
     *                  rather than pick the wrong wrap.
     *
     * Used by ReturnStatement / arrow-expression-body emit to decide
     * whether to wrap the raw PHP value in JsNumber, JsBoolean, or
     * refuse to compile the function at all. Without this,
     * a function like `function f() { return a === b; }` would emit
     * `return JsNumber::of((float)(true))` which collapses true to
     * JsNumber(1) — silently breaking a huge swath of test262 helper
     * code that returns booleans.
     */
    private function inferExpressionType(Node $node): string
    {
        if ($node instanceof Literal) {
            if (is_int($node->value) || is_float($node->value)) {
                return 'numeric';
            }
            if (is_bool($node->value)) {
                return 'boolean';
            }
            return 'unknown';
        }
        if ($node instanceof BinaryExpression) {
            switch ($node->operator) {
                case '+': case '-': case '*': case '/': case '%':
                case '**': case '&': case '|': case '^':
                case '<<': case '>>': case '>>>':
                    return 'numeric';
                case '<': case '>': case '<=': case '>=':
                case '==': case '!=': case '===': case '!==':
                    return 'boolean';
                default:
                    return 'unknown';
            }
        }
        if ($node instanceof UnaryExpression) {
            switch ($node->operator) {
                case '-': case '+': case '~':
                    return 'numeric';
                case '!':
                    return 'boolean';
                default:
                    return 'unknown';
            }
        }
        if ($node instanceof UpdateExpression) {
            // ++/-- on a numeric local: result is the raw double
            // (assignment result of slot += 1).
            return 'numeric';
        }
        if ($node instanceof LogicalExpression) {
            $l = $this->inferExpressionType($node->left);
            $r = $this->inferExpressionType($node->right);
            if ($l === $r && $l !== 'unknown') {
                return $l;
            }
            return 'unknown';
        }
        if ($node instanceof ConditionalExpression) {
            $c = $this->inferExpressionType($node->consequent);
            $a = $this->inferExpressionType($node->alternate);
            if ($c === $a && $c !== 'unknown') {
                return $c;
            }
            return 'unknown';
        }
        if ($node instanceof Identifier) {
            if (isset($this->declaredLocals[$node->name])) {
                $kind = $this->localTypes[$node->name] ?? 'numeric';
                return $kind === 'numeric' ? 'numeric' : 'unknown';
            }
            // Free var: emitExpression unboxes to numeric (with a
            // runtime JsNumber assertion).
            return 'numeric';
        }
        if ($node instanceof CallExpression) {
            // emitCallExpression returns the call result already
            // unboxed as a raw double (with runtime JsNumber check).
            // We only reach this when the call is in numeric pipeline,
            // so the result type is numeric by contract.
            return 'numeric';
        }
        if ($node instanceof AssignmentExpression) {
            // Result of an assignment is the assigned value; most
            // assignments in our profile deal with numeric slots.
            return 'numeric';
        }
        if ($node instanceof \PhpJs\Ast\Expression\MemberExpression) {
            // Member reads are unboxed numeric via the dataSlots path
            // (with runtime JsNumber assertion).
            return 'numeric';
        }
        return 'unknown';
    }

    /**
     * Walk a function body looking for identifier references to
     * `arguments` / `this` / `new.target`. These resolve through the
     * per-invocation environment that executeFunction sets up; the
     * JsToPhp closure runs in the function's lexical $closure env
     * which has no such bindings. Touching them would either throw
     * ReferenceError ("arguments is not defined") or — worse —
     * silently read an outer-scope binding with the same name.
     */
    private static function bodyReferencesPerCallBindings(Node $node): bool
    {
        if ($node instanceof Identifier) {
            return $node->name === 'arguments';
        }
        if ($node instanceof \PhpJs\Ast\Expression\ThisExpression) {
            return true;
        }
        if ($node instanceof \PhpJs\Ast\Expression\MetaProperty) {
            return true;
        }
        // Don't descend into nested function bodies — those have their
        // own arguments / this binding scopes. The outer body's
        // reference is what matters.
        if (
            $node instanceof FunctionDeclaration
            || $node instanceof \PhpJs\Ast\Expression\FunctionExpression
        ) {
            return false;
        }
        // ArrowFunction bodies CAN see the outer arguments / this,
        // but we already bail on outer-local capture in
        // checkNestedCaptures; arguments isn't in declaredLocals so
        // checkNestedCaptures wouldn't catch it. Walk into arrow
        // bodies too.
        foreach ((array) $node as $value) {
            if ($value instanceof Node) {
                if (self::bodyReferencesPerCallBindings($value)) {
                    return true;
                }
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node && self::bodyReferencesPerCallBindings($item)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Conservative check that the body's top-level shape is one
     * where every reachable return path produces a numeric value.
     * A fall-through return path would surface as JsUndefined in
     * the standard entry, but our numeric entry's contract is
     * "always return float" — so we bail compilation if the body
     * could fall through.
     *
     * Eligible:
     *   - Arrow expression body (whole body is one expression).
     *   - Block body whose last statement is a ReturnStatement with
     *     a non-null argument, AND no statements before it could
     *     fall through to the implicit undefined exit (we accept
     *     control-flow that's all-numeric, which the per-statement
     *     emit already enforces).
     */
    private static function numericModeEligible(JsFunction $fn): bool
    {
        $body = $fn->getBody();
        if (!$body instanceof BlockStatement) {
            // Arrow expression body: always returns one value.
            return true;
        }
        if ($body->body === []) {
            return false;
        }
        $last = $body->body[count($body->body) - 1];
        return $last instanceof ReturnStatement && $last->argument !== null;
    }

    /**
     * @param Node[] $statements
     */
    private function collectLocals(array $statements): void
    {
        foreach ($statements as $stmt) {
            $this->collectLocalsIn($stmt);
        }
    }

    private function collectLocalsIn(Node $node): void
    {
        if ($node instanceof FunctionDeclaration) {
            // Hoisted: name binds in enclosing scope, holds a fresh
            // JsFunction created at runtime via vmMakeFunction.
            if ($node->id === null) {
                throw new Bailout('anonymous function declaration');
            }
            $name = $node->id->name;
            $this->declaredLocals[$name] = true;
            $this->markLocalAsFunction($name);
            return;
        }
        if ($node instanceof VariableDeclaration) {
            foreach ($node->declarations as $decl) {
                if ($decl->id instanceof \PhpJs\Ast\Pattern\ObjectPattern) {
                    // Object destructuring: collect each shorthand /
                    // identifier-bound property as a numeric local.
                    foreach ($decl->id->properties as $prop) {
                        if (!$prop instanceof \PhpJs\Ast\Pattern\AssignmentProperty) {
                            throw new Bailout('non-AssignmentProperty in pattern');
                        }
                        if ($prop->computed) {
                            throw new Bailout('computed pattern key');
                        }
                        if (!$prop->value instanceof Identifier) {
                            throw new Bailout('non-identifier pattern target');
                        }
                        $this->declaredLocals[$prop->value->name] = true;
                    }
                    continue;
                }
                if (!$decl->id instanceof Identifier) {
                    throw new Bailout('non-identifier var');
                }
                $name = $decl->id->name;
                $this->declaredLocals[$name] = true;
                if ($decl->init instanceof \PhpJs\Ast\Expression\ObjectExpression) {
                    $this->markLocalAsObject($name);
                }
                if ($decl->init instanceof \PhpJs\Ast\Expression\ArrayExpression) {
                    $this->markLocalAsArray($name);
                }
                if ($decl->init !== null && self::isAsciiStringLiteral($decl->init)) {
                    $this->markLocalAsString($name);
                }
            }
            // Recurse into initializers so callee-usage inside an init
            // can flag the LHS as function-typed (e.g. `const add5 =
            // adder(5); add5(s)` — `add5` later appears as callee).
            foreach ($node->declarations as $decl) {
                if ($decl->init !== null) {
                    $this->collectCalleeUsage($decl->init);
                }
            }
            return;
        }
        if ($node instanceof ExpressionStatement) {
            $this->collectAssignmentTypes($node->expression);
            $this->collectCalleeUsage($node->expression);
            return;
        }
        if ($node instanceof BlockStatement) {
            $this->collectLocals($node->body);
            return;
        }
        if ($node instanceof IfStatement) {
            $this->collectCalleeUsage($node->test);
            $this->collectLocalsIn($node->consequent);
            if ($node->alternate !== null) {
                $this->collectLocalsIn($node->alternate);
            }
            return;
        }
        if ($node instanceof ForStatement) {
            if ($node->init instanceof VariableDeclaration) {
                $this->collectLocalsIn($node->init);
            } elseif ($node->init !== null) {
                $this->collectCalleeUsage($node->init);
            }
            if ($node->test !== null) {
                $this->collectCalleeUsage($node->test);
            }
            if ($node->update !== null) {
                $this->collectCalleeUsage($node->update);
            }
            $this->collectLocalsIn($node->body);
            return;
        }
        if ($node instanceof WhileStatement || $node instanceof DoWhileStatement) {
            $this->collectCalleeUsage($node->test);
            $this->collectLocalsIn($node->body);
            return;
        }
        if ($node instanceof ReturnStatement && $node->argument !== null) {
            $this->collectCalleeUsage($node->argument);
            return;
        }
    }

    /**
     * Walk every nested function body in the source and bail if any
     * of them reference an outer local by name. The compiled closure
     * stores outer locals in PHP variables (not $env), so a nested
     * function reading e.g. `x` would resolve to a stale TDZ binding
     * in $env instead of the PHP-local value. This check catches
     * shapes like:
     *
     *     let x = 10;
     *     function inner() { return x; }   // reads outer's x → bail
     *
     * but allows shapes like the closure benchmark where the nested
     * fn captures only its own params:
     *
     *     function adder(n) { return x => x + n; }   // captures n only
     *
     * @param Node[] $statements
     */
    private function checkNestedCaptures(array $statements): void
    {
        foreach ($statements as $stmt) {
            $this->checkNestedCapturesIn($stmt);
        }
    }

    private function checkNestedCapturesIn(Node $node): void
    {
        if (
            $node instanceof FunctionDeclaration
            || $node instanceof \PhpJs\Ast\Expression\FunctionExpression
            || $node instanceof \PhpJs\Ast\Expression\ArrowFunction
        ) {
            $blocked = $this->initialNestedScope($node);
            $this->scanNestedBody($node->body, $blocked);
            return;
        }
        $this->visitChildren($node, function (Node $child): void {
            $this->checkNestedCapturesIn($child);
        });
    }

    /**
     * Build the initial set of names defined in a nested function's
     * own scope: its parameters, plus its own name (FunctionExpression
     * / FunctionDeclaration). Names in this set shadow outer locals
     * and are NOT considered captures.
     *
     * @return array<string, true>
     */
    private function initialNestedScope(Node $fnNode): array
    {
        $blocked = [];
        if ($fnNode instanceof FunctionDeclaration && $fnNode->id !== null) {
            $blocked[$fnNode->id->name] = true;
        }
        if (
            $fnNode instanceof \PhpJs\Ast\Expression\FunctionExpression
            && $fnNode->name !== null
        ) {
            $blocked[$fnNode->name] = true;
        }
        $params = match (true) {
            $fnNode instanceof FunctionDeclaration => $fnNode->params,
            $fnNode instanceof \PhpJs\Ast\Expression\FunctionExpression => $fnNode->params,
            $fnNode instanceof \PhpJs\Ast\Expression\ArrowFunction => $fnNode->params,
            default => [],
        };
        foreach ($params as $p) {
            if ($p instanceof Identifier) {
                $blocked[$p->name] = true;
            }
            if (
                $p instanceof \PhpJs\Ast\Pattern\AssignmentPattern
                && $p->left instanceof Identifier
            ) {
                $blocked[$p->left->name] = true;
            }
            if ($p instanceof \PhpJs\Ast\Pattern\RestElement && $p->argument instanceof Identifier) {
                $blocked[$p->argument->name] = true;
            }
        }
        return $blocked;
    }

    /**
     * Walk a nested function body recursively. For each Identifier
     * read, if its name matches an outer local AND isn't shadowed by
     * a binding in this nested scope (or any deeper one), throw
     * Bailout.
     *
     * @param array<string, true> $blocked
     */
    private function scanNestedBody(Node $node, array $blocked): void
    {
        if ($node instanceof Identifier) {
            if (isset($this->declaredLocals[$node->name]) && !isset($blocked[$node->name])) {
                throw new Bailout('nested fn captures outer local: ' . $node->name);
            }
            return;
        }
        if ($node instanceof VariableDeclaration) {
            foreach ($node->declarations as $decl) {
                $this->collectPatternNames($decl->id, $blocked);
                if ($decl->init !== null) {
                    $this->scanNestedBody($decl->init, $blocked);
                }
            }
            return;
        }
        if ($node instanceof FunctionDeclaration) {
            if ($node->id !== null) {
                $blocked[$node->id->name] = true;
            }
            $deeper = $this->initialNestedScope($node);
            $this->scanNestedBody($node->body, $deeper);
            return;
        }
        if (
            $node instanceof \PhpJs\Ast\Expression\FunctionExpression
            || $node instanceof \PhpJs\Ast\Expression\ArrowFunction
        ) {
            $deeper = $this->initialNestedScope($node);
            $this->scanNestedBody($node->body, $deeper);
            return;
        }
        if (
            $node instanceof \PhpJs\Ast\Expression\MemberExpression
            && !$node->computed
        ) {
            // For `obj.prop`, only `obj` is a name read; `prop` is a
            // property name, not an identifier reference. Walk only
            // the object side.
            $this->scanNestedBody($node->object, $blocked);
            return;
        }
        if ($node instanceof \PhpJs\Ast\Expression\Property && !$node->computed) {
            // Same reasoning for object-literal `{ key: value }`:
            // `key` is a property name, not a binding read. Only walk
            // value.
            $this->scanNestedBody($node->value, $blocked);
            return;
        }
        $this->visitChildren($node, function (Node $child) use ($blocked): void {
            $this->scanNestedBody($child, $blocked);
        });
    }

    /**
     * Walk a destructuring / param pattern collecting bound names
     * into $blocked.
     *
     * @param array<string, true> $blocked
     */
    private function collectPatternNames(Node $pattern, array &$blocked): void
    {
        if ($pattern instanceof Identifier) {
            $blocked[$pattern->name] = true;
            return;
        }
        if ($pattern instanceof \PhpJs\Ast\Pattern\ArrayPattern) {
            foreach ($pattern->elements as $el) {
                if ($el !== null) {
                    $this->collectPatternNames($el, $blocked);
                }
            }
            return;
        }
        if ($pattern instanceof \PhpJs\Ast\Pattern\ObjectPattern) {
            foreach ($pattern->properties as $prop) {
                if ($prop instanceof \PhpJs\Ast\Pattern\AssignmentProperty) {
                    $this->collectPatternNames($prop->value, $blocked);
                }
                if ($prop instanceof \PhpJs\Ast\Pattern\RestElement) {
                    $this->collectPatternNames($prop->argument, $blocked);
                }
            }
            return;
        }
        if ($pattern instanceof \PhpJs\Ast\Pattern\AssignmentPattern) {
            $this->collectPatternNames($pattern->left, $blocked);
            return;
        }
        if ($pattern instanceof \PhpJs\Ast\Pattern\RestElement) {
            $this->collectPatternNames($pattern->argument, $blocked);
            return;
        }
    }

    /**
     * Generic AST child-walker used by checkNestedCapturesIn /
     * scanNestedBody. Iterates every Node-typed property of $node
     * (including nodes inside arrays) and calls $visit on each.
     */
    private function visitChildren(Node $node, callable $visit): void
    {
        foreach ((array) $node as $value) {
            if ($value instanceof Node) {
                $visit($value);
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        $visit($item);
                    }
                }
            }
        }
    }

    /**
     * Pre-walk pass to flag declared locals used as a CallExpression
     * callee. `add5(s)` inside the loop tells us `add5` must hold a
     * JsFunction, so we mark the slot as 'function' — the prologue
     * defaults it to null and the call site dispatches the slot var
     * directly without an env lookup.
     */
    private function collectCalleeUsage(Node $node): void
    {
        if ($node instanceof CallExpression) {
            if (
                $node->callee instanceof Identifier
                && isset($this->declaredLocals[$node->callee->name])
            ) {
                $this->markLocalAsFunction($node->callee->name);
            }
            $this->collectCalleeUsage($node->callee);
            foreach ($node->arguments as $arg) {
                if ($arg instanceof \PhpJs\Ast\Expression\SpreadElement) {
                    continue;
                }
                $this->collectCalleeUsage($arg);
            }
            return;
        }
        if ($node instanceof BinaryExpression || $node instanceof LogicalExpression) {
            $this->collectCalleeUsage($node->left);
            $this->collectCalleeUsage($node->right);
            return;
        }
        if ($node instanceof UnaryExpression || $node instanceof UpdateExpression) {
            $this->collectCalleeUsage($node->argument);
            return;
        }
        if ($node instanceof AssignmentExpression) {
            $this->collectCalleeUsage($node->right);
            return;
        }
        if ($node instanceof ConditionalExpression) {
            $this->collectCalleeUsage($node->test);
            $this->collectCalleeUsage($node->consequent);
            $this->collectCalleeUsage($node->alternate);
            return;
        }
    }

    /**
     * @param array<int, Node|null> $params
     */
    private function emitPrologue(array $params): void
    {
        foreach ($params as $idx => $param) {
            if (!$param instanceof Identifier) {
                throw new Bailout('non-identifier param');
            }
            $name = $param->name;
            // Params are always numeric in our profile — inferring
            // object-typed parameters would require call-site type
            // info we don't have. Object-typed locals can only come
            // from ObjectExpression initializers in the body.
            if (($this->localTypes[$name] ?? 'numeric') !== 'numeric') {
                throw new Bailout('object-typed param');
            }
            $php = $this->slotVar($name);
            if ($this->numericMode) {
                // Numeric entry: caller (another JsToPhp closure) has
                // already validated the arg shape and passes raw
                // float values via $rawArgs. Default missing args to
                // NAN — JS spec says ToNumber(undefined) is NaN, and
                // a numeric body computing on a missing param should
                // propagate NaN rather than silently treat as 0.
                $this->emitLine($php . ' = $rawArgs[' . $idx . '] ?? NAN;');
            } else {
                $this->emitLine(
                    $php . ' = isset($args[' . $idx . ']) && $args[' . $idx . '] '
                    . 'instanceof \\PhpJs\\Value\\JsNumber ? $args[' . $idx . ']->value : null;'
                );
                $this->emitLine(
                    'if (' . $php . ' === null) { throw new \\PhpJs\\Bytecode\\Bailout("non-numeric arg"); }'
                );
            }
        }
        foreach ($this->declaredLocals as $name => $_) {
            $isParam = false;
            foreach ($params as $p) {
                if ($p instanceof Identifier && $p->name === $name) {
                    $isParam = true;
                    break;
                }
            }
            if (!$isParam) {
                $kind = $this->localTypes[$name] ?? 'numeric';
                if ($kind === 'object' || $kind === 'function' || $kind === 'array') {
                    // Initialised later (ObjectExpression / ArrayExpression
                    // assignment, FunctionDeclaration, or call result).
                    // Predeclare to null so reads on a control-flow path
                    // that never assigns are well-defined.
                    $this->emitLine($this->slotVar($name) . ' = null;');
                } elseif ($kind === 'string') {
                    $this->emitLine($this->slotVar($name) . ' = "";');
                } else {
                    $this->emitLine($this->slotVar($name) . ' = 0.0;');
                }
            }
        }
    }

    private function slotVar(string $name): string
    {
        // Distinct prefix per type so the emitted code never mixes
        // raw doubles with JsValue references at a name->variable
        // lookup. 'function' uses $_lf_, 'array' uses $_la_, 'string'
        // uses $_ls_ for raw PHP strings.
        $prefix = match ($this->localTypes[$name] ?? 'numeric') {
            'object' => '$_lo_',
            'function' => '$_lf_',
            'array' => '$_la_',
            'string' => '$_ls_',
            default => '$_l_',
        };
        return $prefix . preg_replace('/[^A-Za-z0-9_]/', '_', $name);
    }

    /**
     * Mark a local as object-typed, ensuring no conflicting prior
     * marker exists. The same local being seen as both numeric and
     * object on different code paths would force per-path type
     * unification we don't model — bail in that case.
     */
    private function markLocalAsObject(string $name): void
    {
        $existing = $this->localTypes[$name] ?? null;
        if ($existing !== null && $existing !== 'object') {
            throw new Bailout('local ' . $name . ' is mixed type');
        }
        $this->localTypes[$name] = 'object';
    }

    /**
     * Mark a local as function-typed (slot holds a JsFunction).
     * Triggered by either a FunctionDeclaration with this name or
     * any CallExpression whose callee is an Identifier matching
     * this local. Mixed (numeric / object) types bail.
     */
    private function markLocalAsFunction(string $name): void
    {
        $existing = $this->localTypes[$name] ?? null;
        if ($existing !== null && $existing !== 'function') {
            throw new Bailout('local ' . $name . ' is mixed type');
        }
        $this->localTypes[$name] = 'function';
    }

    /**
     * Mark a local as array-typed (slot holds a JsArray reference).
     * Triggered by an ArrayExpression initializer or assignment.
     * Mixed types bail.
     */
    private function markLocalAsArray(string $name): void
    {
        $existing = $this->localTypes[$name] ?? null;
        if ($existing !== null && $existing !== 'array') {
            throw new Bailout('local ' . $name . ' is mixed type');
        }
        $this->localTypes[$name] = 'array';
    }

    /**
     * Mark a local as string-typed (slot holds a raw PHP string).
     * Triggered by an ASCII string literal initializer or assignment.
     * Mixed types bail.
     */
    private function markLocalAsString(string $name): void
    {
        $existing = $this->localTypes[$name] ?? null;
        if ($existing !== null && $existing !== 'string') {
            throw new Bailout('local ' . $name . ' is mixed type');
        }
        $this->localTypes[$name] = 'string';
    }

    /**
     * ASCII-only string literal check. JS String.length counts UTF-16
     * code units; for ASCII-only strings, PHP's strlen returns the
     * same count, so we can stay raw. Any non-ASCII character forces
     * a bailout to the spec path.
     */
    private static function isAsciiStringLiteral(Node $node): bool
    {
        if (!$node instanceof Literal) {
            return false;
        }
        if (!is_string($node->value)) {
            return false;
        }
        // Check every byte is < 0x80.
        return preg_match('/[\\x80-\\xFF]/', $node->value) !== 1;
    }

    /**
     * Walk expressions for `local = ObjectExpression` patterns to
     * mark the local as object-typed during the pre-walk. Without
     * this, the obj-create benchmark's `last = {...}` loop would
     * leave `last` numeric and the ObjectExpression handling would
     * bail at emit time.
     */
    private function collectAssignmentTypes(Node $node): void
    {
        if (
            $node instanceof AssignmentExpression
            && $node->left instanceof Identifier
            && isset($this->declaredLocals[$node->left->name])
        ) {
            if (
                $node->operator === '='
                && $node->right instanceof \PhpJs\Ast\Expression\ObjectExpression
            ) {
                $this->markLocalAsObject($node->left->name);
            }
            if (
                $node->operator === '='
                && $node->right instanceof \PhpJs\Ast\Expression\ArrayExpression
            ) {
                $this->markLocalAsArray($node->left->name);
            }
            if (
                ($node->operator === '=' || $node->operator === '+=')
                && self::isAsciiStringLiteral($node->right)
            ) {
                $this->markLocalAsString($node->left->name);
            }
        }
    }

    private function freeVar(string $name): string
    {
        return '$_fv_' . preg_replace('/[^A-Za-z0-9_]/', '_', $name);
    }

    private function newTemp(string $prefix = 't'): string
    {
        return '$_' . $prefix . '_' . (++$this->tempCounter);
    }

    private function emitStatement(Node $node): void
    {
        if ($node instanceof EmptyStatement) {
            return;
        }
        if ($node instanceof FunctionDeclaration) {
            // Hoisted at scope entry via vmMakeFunction. Index the AST
            // node into nestedFnNodes so the closure can look it up
            // by integer at runtime; the runtime call materialises a
            // fresh JsFunction whose closure environment is $env. The
            // local slot was pre-declared as 'function' by collectLocals.
            if ($node->id === null) {
                throw new Bailout('anonymous function declaration');
            }
            $name = $node->id->name;
            if (!isset($this->nestedFnIndex[$name])) {
                $this->nestedFnIndex[$name] = count($this->nestedFnNodes);
                $this->nestedFnNodes[] = $node;
            }
            $idx = $this->nestedFnIndex[$name];
            $this->emitLine(
                $this->slotVar($name) . ' = $interp->vmMakeFunction($nestedFns['
                . $idx . '], $env);'
            );
            return;
        }
        if ($node instanceof BlockStatement) {
            foreach ($node->body as $inner) {
                $this->emitStatement($inner);
            }
            return;
        }
        if ($node instanceof ExpressionStatement) {
            $expr = $this->emitExpression($node->expression);
            $this->flushPending();
            $this->emitLine($expr . ';');
            return;
        }
        if ($node instanceof VariableDeclaration) {
            foreach ($node->declarations as $decl) {
                // Destructuring optimization: `const { a, b } = { a: x, b: y }`
                // is the destructure microbench's hot pattern. Recognise an
                // ObjectExpression source with all-identifier keys whose
                // names match the pattern's targets and assign directly,
                // skipping the JsObject construction entirely.
                if (
                    $decl->id instanceof \PhpJs\Ast\Pattern\ObjectPattern
                    && $decl->init instanceof \PhpJs\Ast\Expression\ObjectExpression
                ) {
                    $this->emitDestructureFromLiteral($decl->id, $decl->init);
                    continue;
                }
                if (!$decl->id instanceof Identifier) {
                    throw new Bailout('var decl pattern');
                }
                $name = $decl->id->name;
                $kind = $this->localTypes[$name] ?? 'numeric';
                if ($decl->init === null) {
                    if ($kind === 'object' || $kind === 'function' || $kind === 'array') {
                        $this->emitLine($this->slotVar($name) . ' = null;');
                    } elseif ($kind === 'string') {
                        $this->emitLine($this->slotVar($name) . ' = "";');
                    } else {
                        $this->emitLine($this->slotVar($name) . ' = 0.0;');
                    }
                } elseif ($kind === 'string' && self::isAsciiStringLiteral($decl->init)) {
                    /** @var Literal $lit */
                    $lit = $decl->init;
                    $this->emitLine(
                        $this->slotVar($name) . ' = ' . var_export($lit->value, true) . ';'
                    );
                } elseif (
                    $decl->init instanceof \PhpJs\Ast\Expression\ArrayExpression
                    && $kind === 'array'
                ) {
                    // Array literal init: empty `[]` → fresh JsArray.
                    // Non-empty literals would need element evaluation;
                    // bail until we extend support.
                    if ($decl->init->elements !== []) {
                        throw new Bailout('non-empty array literal');
                    }
                    $this->emitLine(
                        $this->slotVar($name) . ' = new \\PhpJs\\Value\\JsArray();'
                    );
                } elseif (
                    $decl->init instanceof \PhpJs\Ast\Expression\ObjectExpression
                    && $kind === 'object'
                ) {
                    // Object literal init: emit JsObject construction
                    // and assign the temp to the object-typed slot.
                    $temp = $this->emitObjectLiteral($decl->init);
                    $this->flushPending();
                    $this->emitLine($this->slotVar($name) . ' = ' . $temp . ';');
                } elseif ($kind === 'function' && $decl->init instanceof CallExpression) {
                    // Call returning a JsFunction (e.g. `const add5 =
                    // adder(5)`). Emit the call but skip the numeric
                    // unbox; a runtime check enforces the type so a
                    // wrong-type result throws Bailout cleanly.
                    $callRaw = $this->emitFunctionValuedCall($decl->init);
                    $this->flushPending();
                    $this->emitLine($this->slotVar($name) . ' = ' . $callRaw . ';');
                } else {
                    $val = $this->emitExpression($decl->init);
                    $this->flushPending();
                    $this->emitLine($this->slotVar($name) . ' = ' . $val . ';');
                }
            }
            return;
        }
        if ($node instanceof IfStatement) {
            $cond = $this->emitExpression($node->test);
            $this->flushPending();
            $this->emitLine('if (' . $cond . ') {');
            $this->indentLevel++;
            $this->emitStatement($node->consequent);
            $this->indentLevel--;
            if ($node->alternate !== null) {
                $this->emitLine('} else {');
                $this->indentLevel++;
                $this->emitStatement($node->alternate);
                $this->indentLevel--;
            }
            $this->emitLine('}');
            return;
        }
        if ($node instanceof ForStatement) {
            if ($node->init !== null) {
                if ($node->init instanceof VariableDeclaration) {
                    $this->emitStatement($node->init);
                } else {
                    $expr = $this->emitExpression($node->init);
                    $this->flushPending();
                    $this->emitLine($expr . ';');
                }
            }
            // The test / update are emitted at every iteration boundary.
            // To stay JIT-friendly, only support numeric tests / updates
            // that don't contain calls (so no pendingStatements need to
            // run mid-loop). Bail otherwise.
            $savedPending = $this->pendingStatements;
            $this->pendingStatements = [];
            $test = $node->test !== null ? $this->emitExpression($node->test) : 'true';
            if ($this->pendingStatements !== []) {
                throw new Bailout('for-test contains call');
            }
            $update = null;
            if ($node->update !== null) {
                $update = $this->emitExpression($node->update);
                if ($this->pendingStatements !== []) {
                    throw new Bailout('for-update contains call');
                }
            }
            $this->pendingStatements = $savedPending;
            $this->emitLine('while (' . $test . ') {');
            $this->indentLevel++;
            $this->emitStatement($node->body);
            if ($update !== null) {
                $this->emitLine($update . ';');
            }
            $this->indentLevel--;
            $this->emitLine('}');
            return;
        }
        if ($node instanceof WhileStatement) {
            $savedPending = $this->pendingStatements;
            $this->pendingStatements = [];
            $test = $this->emitExpression($node->test);
            if ($this->pendingStatements !== []) {
                throw new Bailout('while-test contains call');
            }
            $this->pendingStatements = $savedPending;
            $this->emitLine('while (' . $test . ') {');
            $this->indentLevel++;
            $this->emitStatement($node->body);
            $this->indentLevel--;
            $this->emitLine('}');
            return;
        }
        if ($node instanceof DoWhileStatement) {
            $this->emitLine('do {');
            $this->indentLevel++;
            $this->emitStatement($node->body);
            $this->indentLevel--;
            $savedPending = $this->pendingStatements;
            $this->pendingStatements = [];
            $test = $this->emitExpression($node->test);
            if ($this->pendingStatements !== []) {
                throw new Bailout('do-while test contains call');
            }
            $this->pendingStatements = $savedPending;
            $this->emitLine('} while (' . $test . ');');
            return;
        }
        if ($node instanceof BreakStatement) {
            if ($node->label !== null) {
                throw new Bailout('labeled break');
            }
            $this->emitLine('break;');
            return;
        }
        if ($node instanceof ContinueStatement) {
            if ($node->label !== null) {
                throw new Bailout('labeled continue');
            }
            $this->emitLine('continue;');
            return;
        }
        if ($node instanceof ReturnStatement) {
            if ($node->argument === null) {
                if ($this->numericMode) {
                    // No numeric value to surface; bail so the caller
                    // falls back to the standard entry which will
                    // produce JsUndefined per spec.
                    throw new Bailout('numeric mode: bare return');
                }
                $this->emitLine('return \\PhpJs\\Value\\JsUndefined::instance();');
            } else {
                $type = $this->inferExpressionType($node->argument);
                if ($this->numericMode && $type !== 'numeric') {
                    // Numeric-mode contract is a raw float return.
                    // Boolean / unknown returns can't satisfy it; bail
                    // so the standard entry handles this call.
                    throw new Bailout('numeric mode: non-numeric return');
                }
                $val = $this->emitExpression($node->argument);
                $this->flushPending();
                if ($this->numericMode) {
                    $this->emitLine('return (float)(' . $val . ');');
                } elseif ($type === 'boolean') {
                    $this->emitLine('return \\PhpJs\\Value\\JsBoolean::of((bool)(' . $val . '));');
                } else {
                    // Default to numeric wrap. inferExpressionType
                    // returns 'unknown' for shapes we can't classify
                    // statically; the JsNumber wrap is wrong for
                    // string / object / etc., so bail those before
                    // they reach this branch.
                    if ($type !== 'numeric') {
                        throw new Bailout('return type ' . $type . ' not yet wrappable');
                    }
                    $this->emitLine('return \\PhpJs\\Value\\JsNumber::of((float)(' . $val . '));');
                }
            }
            return;
        }
        throw new Bailout('unsupported stmt: ' . $node->type());
    }

    /**
     * Lower `const { a, b } = { a: x, b: y }` directly: each pattern
     * target gets a single numeric assignment from the matching
     * ObjectExpression property. Skips the intermediate JsObject
     * allocation. Bails if the pattern names don't all map to a
     * property key in the literal — falling back to the slow path
     * which the tree-walker handles via spec destructure semantics.
     */
    private function emitDestructureFromLiteral(
        \PhpJs\Ast\Pattern\ObjectPattern $pattern,
        \PhpJs\Ast\Expression\ObjectExpression $literal,
    ): void {
        // Build a key → AST value map from the literal so pattern
        // targets can fetch their values without a runtime lookup.
        $keyMap = [];
        foreach ($literal->properties as $prop) {
            if (!$prop instanceof \PhpJs\Ast\Expression\Property) {
                throw new Bailout('non-Property in destructure source');
            }
            if ($prop->kind !== 'init' || $prop->computed) {
                throw new Bailout('weird destructure source property');
            }
            $key = null;
            if ($prop->key instanceof Identifier) {
                $key = $prop->key->name;
            } elseif ($prop->key instanceof Literal && is_string($prop->key->value)) {
                $key = $prop->key->value;
            } else {
                throw new Bailout('weird destructure source key');
            }
            $keyMap[$key] = $prop->value;
        }
        foreach ($pattern->properties as $prop) {
            if (!$prop instanceof \PhpJs\Ast\Pattern\AssignmentProperty) {
                throw new Bailout('non-AssignmentProperty in pattern');
            }
            if ($prop->computed) {
                throw new Bailout('computed pattern key');
            }
            if (!$prop->value instanceof Identifier) {
                throw new Bailout('non-identifier pattern target');
            }
            $patKey = null;
            if ($prop->key instanceof Identifier) {
                $patKey = $prop->key->name;
            } elseif ($prop->key instanceof Literal && is_string($prop->key->value)) {
                $patKey = $prop->key->value;
            } else {
                throw new Bailout('weird pattern key');
            }
            if (!isset($keyMap[$patKey])) {
                throw new Bailout('pattern key missing in source');
            }
            $valueExpr = $this->emitExpression($keyMap[$patKey]);
            $this->flushPending();
            $this->emitLine(
                $this->slotVar($prop->value->name) . ' = ' . $valueExpr . ';'
            );
        }
    }

    private function flushPending(): void
    {
        foreach ($this->pendingStatements as $line) {
            $this->emitLine($line);
        }
        $this->pendingStatements = [];
    }

    /**
     * Lower an ObjectExpression to a $_obj_N PHP local holding a
     * fresh JsObject with the literal's properties pre-populated as
     * dataSlots. Returns the temp name. Only callable from contexts
     * where a JsValue (not raw double) is the expected slot type:
     * VariableDeclaration init for an object-typed local, or
     * AssignmentExpression `=` with an object-typed local LHS.
     */
    private function emitObjectLiteral(\PhpJs\Ast\Expression\ObjectExpression $node): string
    {
        $temp = $this->newTemp('obj');
        $this->pendingStatements[] = $temp . ' = new \\PhpJs\\Value\\JsObject();';
        foreach ($node->properties as $prop) {
            if (!$prop instanceof \PhpJs\Ast\Expression\Property) {
                throw new Bailout('non-Property in object literal');
            }
            if ($prop->kind !== 'init') {
                throw new Bailout('object literal getter/setter');
            }
            if ($prop->computed) {
                throw new Bailout('object literal computed key');
            }
            $key = null;
            if ($prop->key instanceof Identifier) {
                $key = $prop->key->name;
            } elseif ($prop->key instanceof Literal && is_string($prop->key->value)) {
                $key = $prop->key->value;
            } else {
                throw new Bailout('object literal weird key');
            }
            $valueRef = $this->emitJsValueExpression($prop->value);
            $this->pendingStatements[] = $temp . '->properties->dataSlots['
                . var_export($key, true) . '] = ' . $valueRef . ';';
        }
        return $temp;
    }

    /**
     * Emit a property value expression that produces a JsValue PHP
     * reference (for use inside dataSlots writes / array element
     * pushes). Recurses into nested object / array literals; primitive
     * literals lower to direct JsNumber / JsString / JsBoolean / JsNull
     * constructors. Anything not literal-shaped falls back to the
     * numeric pipeline + JsNumber wrap (legacy behavior).
     */
    private function emitJsValueExpression(Node $node): string
    {
        if ($node instanceof Literal) {
            if ($node->value === null) {
                return '\\PhpJs\\Value\\JsNull::instance()';
            }
            if (is_bool($node->value)) {
                return '\\PhpJs\\Value\\JsBoolean::of(' . ($node->value ? 'true' : 'false') . ')';
            }
            if (is_int($node->value) || is_float($node->value)) {
                return '\\PhpJs\\Value\\JsNumber::of((float) ' . (string) (float) $node->value . ')';
            }
            if (is_string($node->value)) {
                return 'new \\PhpJs\\Value\\JsString(' . var_export($node->value, true) . ')';
            }
            throw new Bailout('unknown literal type in object literal');
        }
        if ($node instanceof \PhpJs\Ast\Expression\ObjectExpression) {
            return $this->emitObjectLiteral($node);
        }
        if ($node instanceof \PhpJs\Ast\Expression\ArrayExpression) {
            $arrTemp = $this->newTemp('arr');
            $this->pendingStatements[] = $arrTemp . ' = new \\PhpJs\\Value\\JsArray();';
            foreach ($node->elements as $el) {
                if ($el === null) {
                    // Hole: bail; spec hole semantics need a sparse
                    // JsArray, but our tracked locals stay dense.
                    throw new Bailout('array literal hole in object literal');
                }
                if ($el instanceof \PhpJs\Ast\Expression\SpreadElement) {
                    throw new Bailout('spread in array literal');
                }
                $elRef = $this->emitJsValueExpression($el);
                $this->pendingStatements[] = $arrTemp . '->push(' . $elRef . ');';
            }
            return $arrTemp;
        }
        // Fallback: emit as numeric and wrap in JsNumber. This is the
        // legacy path for `{key: someNumericExpr}` where the value is
        // not a literal but resolves to a numeric raw double. The
        // numeric emitExpression bails for non-numeric subtrees.
        $valueExpr = $this->emitExpression($node);
        return '\\PhpJs\\Value\\JsNumber::of((float)(' . $valueExpr . '))';
    }

    /**
     * Compile a branch of a conditional / short-circuit expression in
     * isolation. Returns [pendingStatementLines, finalExpression] so
     * the caller can wrap the lines inside a guarded if-block.
     *
     * Important: we snapshot $this->freeVars too. Without that, a free
     * variable referenced only in branch A would mark the freeVars
     * entry as "already emitted" and branch B's emit would skip its
     * env->get even though the resulting PHP variable was scoped to
     * branch A's pending block. Restoring on exit makes the per-branch
     * emit independent — at the cost of duplicating env lookups when
     * BOTH branches reference the same free var, but that's still
     * correct and only wastes one env->get on the rare case.
     *
     * @return array{0: list<string>, 1: string}
     */
    private function captureBranch(Node $node): array
    {
        $savedPending = $this->pendingStatements;
        $savedFreeVars = $this->freeVars;
        $this->pendingStatements = [];
        $expr = $this->emitExpression($node);
        $branchPending = $this->pendingStatements;
        $this->pendingStatements = $savedPending;
        $this->freeVars = $savedFreeVars;
        return [$branchPending, $expr];
    }

    /** @phpstan-impure */
    private function emitExpression(Node $node): string
    {
        if ($node instanceof Literal) {
            if (is_int($node->value) || is_float($node->value)) {
                return (string) (float) $node->value;
            }
            if (is_bool($node->value)) {
                return $node->value ? 'true' : 'false';
            }
            throw new Bailout('non-numeric literal');
        }
        if ($node instanceof Identifier) {
            if (isset($this->declaredLocals[$node->name])) {
                // Non-numeric locals can't be used in the numeric
                // expression pipeline (the caller would feed a JsValue
                // ref into JsNumber::of() / arithmetic). The dedicated
                // member-read / member-call / call-as-callee handlers
                // bypass emitExpression entirely, so reaching this
                // branch with an object/function/array-typed local
                // means the local is being read in a context our
                // numeric pipeline can't lower. Bail.
                $kind = $this->localTypes[$node->name] ?? 'numeric';
                if ($kind !== 'numeric') {
                    throw new Bailout('non-numeric local in expression: ' . $node->name);
                }
                return $this->slotVar($node->name);
            }
            // Free variable: read from env, unbox to raw double on
            // first reference; subsequent uses pull the raw $_fv_*
            // PHP local. Bail if the free var is not a JsNumber so
            // the VM falls back gracefully.
            //
            // Cache strategy: gate the env lookup on Environment::
            // $globalBindingsVersion. The static persists across
            // closure invocations; if no binding has been written
            // since the previous call (closure / fib hot path), we
            // skip env->get entirely and reuse the cached raw value.
            // The env identity check guards against the same compiled
            // closure being reused on a different env; if so, the
            // cache invalidates and we re-resolve.
            $name = $node->name;
            if (!isset($this->freeVars[$name])) {
                $this->freeVars[$name] = true;
                $safe = preg_replace('/[^A-Za-z0-9_]/', '_', $name);
                $box = '$_fvbox_' . $safe;
                $php = $this->freeVar($name);
                $envCacheVar = '$_fvenv_' . $safe;
                $verCacheVar = '$_fvver_' . $safe;
                $this->pendingStatements[] = 'static ' . $envCacheVar
                    . ' = null, ' . $verCacheVar . ' = -1, ' . $php . ' = 0.0;';
                $this->pendingStatements[] = '$_curVer = \\PhpJs\\Runtime\\Environment::'
                    . '$globalBindingsVersion;';
                $this->pendingStatements[] = 'if ($env !== ' . $envCacheVar
                    . ' || $_curVer !== ' . $verCacheVar . ') {';
                $this->pendingStatements[] = '    ' . $box
                    . ' = $env->get(' . var_export($name, true) . ');';
                $this->pendingStatements[] = '    if (!(' . $box
                    . ' instanceof \\PhpJs\\Value\\JsNumber)) { throw new \\PhpJs\\Bytecode\\Bailout("non-numeric freevar"); }';
                // Update the cache only AFTER the JsNumber check
                // succeeds. If the value is not numeric we throw
                // Bailout, and the cache must stay unchanged so the
                // next call re-enters the if-block and re-throws,
                // routing again to the VM fallback. Mutating env /
                // version before the throw makes the cache appear
                // valid on retry and returns the stale default.
                $this->pendingStatements[] = '    ' . $envCacheVar . ' = $env;';
                $this->pendingStatements[] = '    ' . $verCacheVar . ' = $_curVer;';
                $this->pendingStatements[] = '    ' . $php . ' = ' . $box . '->value;';
                $this->pendingStatements[] = '}';
            }
            return $this->freeVar($name);
        }
        if ($node instanceof BinaryExpression) {
            $l = $this->emitExpression($node->left);
            $r = $this->emitExpression($node->right);
            switch ($node->operator) {
                case '+':
                case '-':
                case '*':
                case '/':
                case '%':
                case '<':
                case '>':
                case '<=':
                case '>=':
                    return '(' . $l . ' ' . $node->operator . ' ' . $r . ')';
                case '==':
                case '!=':
                    return '(' . $l . ' '
                        . ($node->operator === '==' ? '===' : '!==')
                        . ' ' . $r . ')';
                case '===':
                case '!==':
                    return '(' . $l . ' ' . $node->operator . ' ' . $r . ')';
                default:
                    throw new Bailout('binop ' . $node->operator);
            }
        }
        if ($node instanceof LogicalExpression) {
            // Short-circuit semantics: `a && b` and `a || b` must NOT
            // evaluate b when a's truthiness already determines the
            // result. Same lift-by-branch pattern as
            // ConditionalExpression to gate the right-side's pending
            // statements.
            $l = $this->emitExpression($node->left);
            $rightBranch = $this->captureBranch($node->right);
            $temp = $this->newTemp('lv');
            $this->pendingStatements[] = $temp . ' = ' . $l . ';';
            if ($node->operator === '&&') {
                $this->pendingStatements[] = 'if (' . $temp . ') {';
            } elseif ($node->operator === '||') {
                $this->pendingStatements[] = 'if (!(' . $temp . ')) {';
            } else {
                throw new Bailout('logical ' . $node->operator);
            }
            foreach ($rightBranch[0] as $line) {
                $this->pendingStatements[] = '    ' . $line;
            }
            $this->pendingStatements[] = '    ' . $temp . ' = ' . $rightBranch[1] . ';';
            $this->pendingStatements[] = '}';
            return $temp;
        }
        if ($node instanceof UnaryExpression && !$node->prefix) {
            throw new Bailout('postfix unary');
        }
        if ($node instanceof UnaryExpression) {
            $arg = $this->emitExpression($node->argument);
            switch ($node->operator) {
                case '-':
                    return '(-' . $arg . ')';
                case '+':
                    return '(+' . $arg . ')';
                case '!':
                    return '(!' . $arg . ')';
                default:
                    throw new Bailout('unary ' . $node->operator);
            }
        }
        if ($node instanceof UpdateExpression) {
            if (!$node->argument instanceof Identifier) {
                throw new Bailout('update on non-identifier');
            }
            if (!isset($this->declaredLocals[$node->argument->name])) {
                throw new Bailout('update on non-local');
            }
            $slot = $this->slotVar($node->argument->name);
            $op = $node->operator === '++' ? '+= 1' : '-= 1';
            return '(' . $slot . ' ' . $op . ')';
        }
        if ($node instanceof AssignmentExpression) {
            // obj.prop = value: lower to a direct dataSlots write on
            // the receiver's PHP local. Only supports identifier
            // receiver + identifier key (the obj-prop bench shape).
            if (
                $node->left instanceof \PhpJs\Ast\Expression\MemberExpression
                && !$node->left->computed
                && $node->left->object instanceof Identifier
                && $node->left->property instanceof Identifier
                && $node->operator === '='
            ) {
                $recvName = $node->left->object->name;
                if (
                    !isset($this->declaredLocals[$recvName])
                    || ($this->localTypes[$recvName] ?? 'numeric') !== 'object'
                ) {
                    throw new Bailout('member write on non-object local');
                }
                $recv = $this->slotVar($recvName);
                $key = $node->left->property->name;
                $val = $this->emitExpression($node->right);
                $temp = $this->newTemp('mw');
                $this->pendingStatements[] = $temp
                    . ' = \\PhpJs\\Value\\JsNumber::of((float)(' . $val . '));';
                $this->pendingStatements[] = $recv . '->properties->dataSlots['
                    . var_export($key, true) . '] = ' . $temp . ';';
                // Expression value of assignment is the assigned RHS;
                // most callers (ExpressionStatement) discard it, but
                // return the boxed JsValue so chained assignment works.
                return $temp;
            }
            // Computed bracket-write: arr[i] = numericExpr on an array-
            // typed local. Lowers to a denseElements write via
            // setDenseElement. Bounds-checks at runtime via the dense
            // mode guard so a sparse-mutation pattern bails to spec.
            if (
                $node->left instanceof \PhpJs\Ast\Expression\MemberExpression
                && $node->left->computed
                && $node->left->object instanceof Identifier
                && $node->operator === '='
            ) {
                $recvName = $node->left->object->name;
                if (
                    !isset($this->declaredLocals[$recvName])
                    || ($this->localTypes[$recvName] ?? 'numeric') !== 'array'
                ) {
                    throw new Bailout('computed write on non-array local');
                }
                $recv = $this->slotVar($recvName);
                $idxExpr = $this->emitExpression($node->left->property);
                $val = $this->emitExpression($node->right);
                $idxLocal = $this->newTemp('ai');
                $valTemp = $this->newTemp('av');
                $this->pendingStatements[] = $idxLocal . ' = (int) ' . $idxExpr . ';';
                $this->pendingStatements[] = $valTemp
                    . ' = \\PhpJs\\Value\\JsNumber::of((float)(' . $val . '));';
                $this->pendingStatements[] = 'if (!' . $recv
                    . '->isDenseMode()) { throw new \\PhpJs\\Bytecode\\Bailout("array write on non-dense receiver"); }';
                $this->pendingStatements[] = $recv . '->setDenseElement('
                    . $idxLocal . ', ' . $valTemp . ');';
                // Maintain length invariant: extending past current
                // length needs setLength to keep getLength() correct
                // for downstream reads (e.g. .length / dense iter).
                $this->pendingStatements[] = 'if (' . $idxLocal . ' >= ' . $recv
                    . '->getLength()) { ' . $recv . '->setLength('
                    . $idxLocal . ' + 1); }';
                return $valTemp;
            }
            if (!$node->left instanceof Identifier) {
                throw new Bailout('assign to non-identifier');
            }
            if (!isset($this->declaredLocals[$node->left->name])) {
                throw new Bailout('assign to non-local');
            }
            $name = $node->left->name;
            // local = ObjectExpression: lift to a fresh JsObject and
            // assign to the object-typed slot. Pre-walk has already
            // marked the local as object-typed.
            if (
                $node->operator === '='
                && $node->right instanceof \PhpJs\Ast\Expression\ObjectExpression
                && ($this->localTypes[$name] ?? null) === 'object'
            ) {
                $temp = $this->emitObjectLiteral($node->right);
                $this->pendingStatements[] = $this->slotVar($name) . ' = ' . $temp . ';';
                return $temp;
            }
            // String-typed local: only accept ASCII string literal
            // RHS for assignment / concat. The result expression is
            // the slot's PHP string, which is non-numeric — only
            // legal as a discarded ExpressionStatement value (which
            // the PHP eval'd code happily ignores).
            $kind = $this->localTypes[$name] ?? null;
            if ($kind === 'string') {
                if (!self::isAsciiStringLiteral($node->right)) {
                    throw new Bailout('non-ascii literal RHS for string local');
                }
                /** @var Literal $lit */
                $lit = $node->right;
                $rhs = var_export($lit->value, true);
                $slot = $this->slotVar($name);
                if ($node->operator === '=') {
                    return '(' . $slot . ' = ' . $rhs . ')';
                }
                if ($node->operator === '+=') {
                    return '(' . $slot . ' .= ' . $rhs . ')';
                }
                throw new Bailout('string assignment ' . $node->operator);
            }
            $slot = $this->slotVar($name);
            $val = $this->emitExpression($node->right);
            $op = $node->operator;
            if ($op === '=') {
                return '(' . $slot . ' = ' . $val . ')';
            }
            $allowed = ['+=', '-=', '*=', '/=', '%='];
            if (in_array($op, $allowed, true)) {
                return '(' . $slot . ' ' . $op . ' ' . $val . ')';
            }
            throw new Bailout('assignment ' . $op);
        }
        if ($node instanceof ConditionalExpression) {
            // Branches may contain calls that lift to pendingStatements.
            // Emitting the ternary inline would run BOTH sides'
            // pendings unconditionally — fatal for recursion shapes
            // like `n < 2 ? n : fib(n-1)+fib(n-2)`. Lower to a temp +
            // if/else so each branch's pendings run only on its arm.
            $test = $this->emitExpression($node->test);
            $consBranch = $this->captureBranch($node->consequent);
            $altBranch = $this->captureBranch($node->alternate);
            // Fast path: when neither branch lifted any statements
            // (no calls / no member reads / no free-var resolution),
            // emit a plain PHP ternary directly. Fewer lines, no temp,
            // and PHP can fold it into the surrounding expression.
            if ($consBranch[0] === [] && $altBranch[0] === []) {
                return '(' . $test . ' ? ' . $consBranch[1] . ' : ' . $altBranch[1] . ')';
            }
            $temp = $this->newTemp('cv');
            $this->pendingStatements[] = $temp . ' = null;';
            $this->pendingStatements[] = 'if (' . $test . ') {';
            foreach ($consBranch[0] as $line) {
                $this->pendingStatements[] = '    ' . $line;
            }
            $this->pendingStatements[] = '    ' . $temp . ' = ' . $consBranch[1] . ';';
            $this->pendingStatements[] = '} else {';
            foreach ($altBranch[0] as $line) {
                $this->pendingStatements[] = '    ' . $line;
            }
            $this->pendingStatements[] = '    ' . $temp . ' = ' . $altBranch[1] . ';';
            $this->pendingStatements[] = '}';
            return $temp;
        }
        if ($node instanceof CallExpression) {
            return $this->emitCallExpression($node);
        }
        if ($node instanceof \PhpJs\Ast\Expression\ObjectExpression) {
            // Object literals are only allowed as the RHS of a
            // VariableDeclaration or assignment to an object-typed
            // local. emitObjectLiteral handles both contexts; reaching
            // emitExpression with one means it appeared in an
            // arithmetic / member-of expression where the numeric
            // pipeline can't cope with a JsObject value. Bail so the
            // tree-walker handles it correctly (e.g. ToPrimitive
            // coercion via valueOf).
            throw new Bailout('object literal in numeric context');
        }
        if ($node instanceof \PhpJs\Ast\Expression\MemberExpression) {
            // Read of obj.prop or arr[i]. Receiver must be a known
            // object-typed local (dataSlots dereferenced, unbox to
            // numeric), an array-typed local with key 'length'
            // (returns the live length count as a numeric), an
            // array-typed local with a numeric computed key (dense
            // indexed read), or a string-typed local with .length.
            if (!$node->object instanceof Identifier) {
                throw new Bailout('member read non-identifier receiver');
            }
            $recvName = $node->object->name;
            if (!isset($this->declaredLocals[$recvName])) {
                throw new Bailout('member read on non-local');
            }
            $recvKind = $this->localTypes[$recvName] ?? 'numeric';
            // Computed bracket-access on an array-typed local: index
            // is a numeric expression, lowers to a denseElements lookup
            // with bounds + JsNumber unbox. Bails to spec path if the
            // receiver isn't dense at run time.
            if ($node->computed && $recvKind === 'array') {
                $idxExpr = $this->emitExpression($node->property);
                $recv = $this->slotVar($recvName);
                $idxLocal = $this->newTemp('ai');
                $valLocal = $this->newTemp('av');
                $this->pendingStatements[] = $idxLocal . ' = (int) ' . $idxExpr . ';';
                $this->pendingStatements[] = 'if (!' . $recv
                    . '->isDenseMode()) { throw new \\PhpJs\\Bytecode\\Bailout("array read on non-dense receiver"); }';
                $this->pendingStatements[] = $valLocal . ' = ' . $recv
                    . '->getDenseElements()[' . $idxLocal . '] ?? null;';
                $this->pendingStatements[] = 'if (!(' . $valLocal
                    . ' instanceof \\PhpJs\\Value\\JsNumber)) { throw new \\PhpJs\\Bytecode\\Bailout("non-numeric array element"); }';
                return $valLocal . '->value';
            }
            if ($node->computed) {
                throw new Bailout('computed member read on non-array local');
            }
            if (!$node->property instanceof Identifier) {
                throw new Bailout('member read non-identifier property');
            }
            $key = $node->property->name;
            if ($recvKind === 'array' && $key === 'length') {
                $recv = $this->slotVar($recvName);
                return '((float) ' . $recv . '->getLength())';
            }
            if ($recvKind === 'string' && $key === 'length') {
                // ASCII-only profile: byte length equals UTF-16 code
                // unit count, so strlen is correct without conversion.
                $recv = $this->slotVar($recvName);
                return '((float) strlen(' . $recv . '))';
            }
            if ($recvKind !== 'object') {
                throw new Bailout('member read on non-object local');
            }
            $recv = $this->slotVar($recvName);
            $temp = $this->newTemp('mr');
            $this->pendingStatements[] = $temp . ' = ' . $recv
                . '->properties->dataSlots[' . var_export($key, true) . '] ?? null;';
            $this->pendingStatements[] = 'if (!(' . $temp
                . ' instanceof \\PhpJs\\Value\\JsNumber)) { throw new \\PhpJs\\Bytecode\\Bailout("non-numeric member read"); }';
            return $temp . '->value';
        }
        throw new Bailout('unsupported expr: ' . $node->type());
    }

    /**
     * Lower a CallExpression into pendingStatements so the call result
     * lands in a uniquely-named temp PHP variable. The enclosing
     * expression then references the temp directly. The call itself
     * boxes each numeric arg as JsNumber, invokes either the callee's
     * cached phpCompiled closure (recursion / cross-compiled-fn) or
     * Interpreter::callFunction (non-compiled / native), unboxes the
     * return value, and returns the temp's name.
     */
    private function emitCallExpression(CallExpression $node): string
    {
        // Method call shortcut: `arr.push(val)` on an array-typed
        // local goes straight to JsArray::push without resolving the
        // method through the prototype chain. Returns the new length
        // as a numeric raw double, matching the VM inline path.
        if (
            $node->callee instanceof \PhpJs\Ast\Expression\MemberExpression
            && !$node->callee->computed
            && $node->callee->object instanceof Identifier
            && $node->callee->property instanceof Identifier
        ) {
            $recvName = $node->callee->object->name;
            if (
                isset($this->declaredLocals[$recvName])
                && ($this->localTypes[$recvName] ?? 'numeric') === 'array'
                && $node->callee->property->name === 'push'
                && count($node->arguments) === 1
                && !($node->arguments[0] instanceof \PhpJs\Ast\Expression\SpreadElement)
            ) {
                $argExpr = $this->emitExpression($node->arguments[0]);
                $recv = $this->slotVar($recvName);
                $valTemp = $this->newTemp('av');
                $this->pendingStatements[] = $valTemp
                    . ' = \\PhpJs\\Value\\JsNumber::of(' . $argExpr . ');';
                $this->pendingStatements[] = $recv . '->push(' . $valTemp . ');';
                return '((float) ' . $recv . '->getLength())';
            }
        }
        // Always prefer the callee's numeric entry when it exists,
        // regardless of caller mode. The result is a raw float that
        // can be plugged into either pipeline directly: numeric mode
        // uses it as-is, standard mode would otherwise have unboxed
        // a JsNumber to get the same value.
        $rawTemp = $this->emitNumericCallCore($node);
        if ($rawTemp !== null) {
            return $rawTemp;
        }
        $resultTemp = $this->emitCallCore($node);
        // The numeric pipeline expects a raw double. The callee's
        // contract is `JsNumber` — anything else triggers a Bailout
        // so the VM / tree-walker fallback handles non-numeric cases.
        $this->pendingStatements[] = 'if (!(' . $resultTemp
            . ' instanceof \\PhpJs\\Value\\JsNumber)) { throw new \\PhpJs\\Bytecode\\Bailout("non-numeric call result"); }';
        return $resultTemp . '->value';
    }

    /**
     * Lower a CallExpression to a numeric-entry dispatch. Returns
     * the raw-double temp PHP variable name (with ->something appended),
     * or null when the call cannot use the numeric path.
     *
     * The numeric entry signature is
     *   function(array $rawArgs, $env, $interp, $nestedFns): float
     * so args are passed as raw doubles and the return is a raw float.
     * If the callee doesn't have phpCompiledNumeric set, we fall back
     * to the standard call core (with full boxing).
     */
    private function emitNumericCallCore(CallExpression $node): ?string
    {
        if (!$node->callee instanceof Identifier) {
            return null;
        }
        // Same arg / callee resolution as emitCallCore but emitting
        // directly to numeric entry. Bail back (return null) if the
        // shape doesn't fit so the caller falls back to spec dispatch.
        $argRawExprs = [];
        foreach ($node->arguments as $arg) {
            if ($arg instanceof \PhpJs\Ast\Expression\SpreadElement) {
                return null;
            }
            $argRawExprs[] = $this->emitExpression($arg);
        }
        $calleeName = $node->callee->name;
        $isLocalFnCallee = false;
        if (isset($this->declaredLocals[$calleeName])) {
            if (($this->localTypes[$calleeName] ?? null) !== 'function') {
                return null;
            }
            $calleeRef = $this->slotVar($calleeName);
            $isLocalFnCallee = true;
        } else {
            $calleeRef = '$_fnc_' . preg_replace('/[^A-Za-z0-9_]/', '_', $calleeName);
            if (!isset($this->freeVars['__fnc_' . $calleeName])) {
                $this->freeVars['__fnc_' . $calleeName] = true;
                $this->pendingStatements[] = $calleeRef
                    . ' = $env->get(' . var_export($calleeName, true) . ');';
            }
        }
        $argsArr = '[' . implode(', ', $argRawExprs) . ']';
        $resultTemp = $this->newTemp('cn');
        if (!$isLocalFnCallee) {
            $this->pendingStatements[] = 'if (!(' . $calleeRef
                . ' instanceof \\PhpJs\\Value\\JsFunction)) { throw new \\PhpJs\\Bytecode\\Bailout("non-function callee"); }';
        }
        // Prefer phpCompiledNumeric. Fall back to phpCompiled with
        // JsNumber boxing of args + unboxing of result. If neither
        // exists, fall back to callFunction (and unbox).
        $boxedArgs = [];
        foreach ($argRawExprs as $expr) {
            $boxedArgs[] = '\\PhpJs\\Value\\JsNumber::of(' . $expr . ')';
        }
        $boxedArgsArr = '[' . implode(', ', $boxedArgs) . ']';
        $this->pendingStatements[] = $resultTemp . ' = '
            . $calleeRef . '->phpCompiledNumeric !== null'
            . ' ? (' . $calleeRef . '->phpCompiledNumeric)('
            . $argsArr . ', ' . $calleeRef . '->closure, $interp, '
            . $calleeRef . '->phpCompiledNodes)'
            . ' : null;';
        // null sentinel triggers spec-entry fallback. Use the same
        // pattern as the standard call core: phpCompiled or callFunction,
        // then unbox.
        $boxedResult = $this->newTemp('cnb');
        $this->pendingStatements[] = 'if (' . $resultTemp . ' === null) {';
        $this->pendingStatements[] = '    ' . $boxedResult . ' = '
            . $calleeRef . '->phpCompiled !== null'
            . ' ? (' . $calleeRef . '->phpCompiled)('
            . $boxedArgsArr . ', ' . $calleeRef . '->closure, $interp, '
            . $calleeRef . '->phpCompiledNodes)'
            . ' : $interp->callFunction(' . $calleeRef . ', '
            . '\\PhpJs\\Value\\JsUndefined::instance(), ' . $boxedArgsArr . ');';
        $this->pendingStatements[] = '    if (!(' . $boxedResult
            . ' instanceof \\PhpJs\\Value\\JsNumber)) { throw new \\PhpJs\\Bytecode\\Bailout("non-numeric call result"); }';
        $this->pendingStatements[] = '    ' . $resultTemp . ' = ' . $boxedResult . '->value;';
        $this->pendingStatements[] = '}';
        return $resultTemp;
    }

    /**
     * Lower a CallExpression but return the boxed JsValue (no unbox).
     * Used by VariableDeclaration init when the LHS is function-typed
     * — the call result must land in the slot as a JsFunction, not
     * an unboxed numeric.
     */
    private function emitFunctionValuedCall(CallExpression $node): string
    {
        $resultTemp = $this->emitCallCore($node);
        $this->pendingStatements[] = 'if (!(' . $resultTemp
            . ' instanceof \\PhpJs\\Value\\JsFunction)) { throw new \\PhpJs\\Bytecode\\Bailout("non-function call result"); }';
        return $resultTemp;
    }

    /**
     * Shared core for CallExpression lowering. Emits args, resolves
     * the callee (free var lookup or function-typed local slot),
     * dispatches via phpCompiled or Interpreter::callFunction, and
     * returns the name of the temp PHP variable holding the boxed
     * call result. Caller decides whether to unbox or assert type.
     */
    private function emitCallCore(CallExpression $node): string
    {
        if (!$node->callee instanceof Identifier) {
            throw new Bailout('non-identifier callee');
        }
        // Emit each arg as a numeric raw expression and box on the way
        // in. Calls within args produce their own pending statements,
        // which run before this call's; emit order matches JS spec
        // left-to-right argument evaluation.
        $argRawExprs = [];
        foreach ($node->arguments as $arg) {
            if ($arg instanceof \PhpJs\Ast\Expression\SpreadElement) {
                throw new Bailout('spread arg');
            }
            $argRawExprs[] = $this->emitExpression($arg);
        }
        $calleeName = $node->callee->name;
        // Resolve the callee. Function-typed local? Use the slot var
        // directly (FunctionDeclaration-bound or assigned earlier from
        // a function-valued call). Otherwise free-variable lookup
        // cached in a $_fnc_ local on first reference.
        $isLocalFnCallee = false;
        if (isset($this->declaredLocals[$calleeName])) {
            if (($this->localTypes[$calleeName] ?? null) !== 'function') {
                throw new Bailout('non-function-typed local callee');
            }
            $calleeRef = $this->slotVar($calleeName);
            $isLocalFnCallee = true;
        } else {
            $calleeRef = '$_fnc_' . preg_replace('/[^A-Za-z0-9_]/', '_', $calleeName);
            if (!isset($this->freeVars['__fnc_' . $calleeName])) {
                $this->freeVars['__fnc_' . $calleeName] = true;
                $this->pendingStatements[] = $calleeRef
                    . ' = $env->get(' . var_export($calleeName, true) . ');';
            }
        }
        // Box args, build the args array, dispatch.
        $boxedArgs = [];
        foreach ($argRawExprs as $expr) {
            $boxedArgs[] = '\\PhpJs\\Value\\JsNumber::of(' . $expr . ')';
        }
        $argsArr = '[' . implode(', ', $boxedArgs) . ']';
        $resultTemp = $this->newTemp('cr');
        // Direct closure-to-closure dispatch when both this body and
        // the callee are JsToPhp-compiled. Skips executeFunction's
        // setup (callStack push, sloppy this coerce, frame setup,
        // teardown) entirely. Falls back to callFunction for natives,
        // generators, and any uncompiled callee — those still need
        // the standard prologue / kind dispatch.
        // Guard: if the callee isn't a JsFunction we can't dispatch
        // numerically. Bail to VM so the spec-correct TypeError /
        // Proxy / etc. handling fires.
        // The instanceof JsFunction guard is only needed for free-var
        // callees, where env->get could return any JsValue. For
        // function-typed locals, the assignment site (FunctionDeclaration
        // emit / function-valued call init) already asserted JsFunction
        // and the slot type stays invariant — the per-call check is
        // pure overhead. Free-var callees still need the guard since
        // global / outer-scope bindings can change shape between calls.
        if (!$isLocalFnCallee) {
            $this->pendingStatements[] = 'if (!(' . $calleeRef
                . ' instanceof \\PhpJs\\Value\\JsFunction)) { throw new \\PhpJs\\Bytecode\\Bailout("non-function callee"); }';
        }
        // Direct closure dispatch when phpCompiled is set. The compile
        // path only runs for non-native callees (callFunctionInner
        // routes natives to their callable before reaching tryRunOnVm),
        // so phpCompiled !== null implies non-native. Direct property
        // access on $closure / phpCompiledNodes skips the getter
        // method dispatch in this hot path.
        $this->pendingStatements[] = $resultTemp . ' = '
            . $calleeRef . '->phpCompiled !== null'
            . ' ? (' . $calleeRef . '->phpCompiled)('
            . $argsArr . ', ' . $calleeRef . '->closure, $interp, '
            . $calleeRef . '->phpCompiledNodes)'
            . ' : $interp->callFunction(' . $calleeRef . ', '
            . '\\PhpJs\\Value\\JsUndefined::instance(), ' . $argsArr . ');';
        return $resultTemp;
    }

    private function emitLine(string $line): void
    {
        $this->out .= str_repeat('    ', $this->indentLevel) . $line . "\n";
    }
}
