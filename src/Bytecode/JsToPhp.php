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
     *
     * Determined by a pre-walk that looks at every declarator init and
     * assignment. A local that is ever assigned an ObjectExpression
     * (and never assigned a numeric expression) becomes 'object';
     * a local declared as a FunctionDeclaration or used as a callee
     * becomes 'function'; a local initialised with an ArrayExpression
     * (or assigned one) becomes 'array'; everything else stays
     * 'numeric'. Mixed types on different paths is a compile-time
     * bailout so the emitted PHP slot variable is unambiguous.
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

    public static function compile(JsFunction $fn): ?\Closure
    {
        $body = $fn->getBody();
        $isExpressionBody = !$body instanceof BlockStatement;
        if ($isExpressionBody && !$body instanceof Node) {
            return null;
        }
        $compiler = new self();
        try {
            $params = $fn->getParams();
            foreach ($params as $p) {
                if (!$p instanceof Identifier) {
                    return null;
                }
                $compiler->declaredLocals[$p->name] = true;
            }
            if ($isExpressionBody) {
                // Arrow expression body: `x => expr`. The whole body
                // is an expression to return. Emit prologue + a single
                // synthetic ReturnStatement.
                $compiler->emitPrologue($params);
                $value = $compiler->emitExpression($body);
                $compiler->flushPending();
                $compiler->emitLine(
                    'return \\PhpJs\\Value\\JsNumber::of((float)(' . $value . '));'
                );
            } else {
                $compiler->collectLocals($body->body);
                // Safety check: nested FunctionDeclaration / Function /
                // ArrowFunction bodies might read outer locals via the
                // JS environment chain, but our compiled closure stores
                // outer locals in PHP variables that $env never sees.
                // If any nested fn references an outer-local name as a
                // free variable, bail so the spec-correct env-chain
                // semantics still hold.
                $compiler->checkNestedCaptures($body->body);
                $compiler->emitPrologue($params);
                foreach ($body->body as $stmt) {
                    $compiler->emitStatement($stmt);
                }
                $compiler->emitLine('return \\PhpJs\\Value\\JsUndefined::instance();');
            }
        } catch (Bailout) {
            return null;
        }
        $php = "return function (\$args, \$env, \$interp, \$nestedFns) {\n"
            . $compiler->out
            . "};";
        try {
            /** @var \Closure $closure */
            $closure = eval($php);
        } catch (\Throwable) {
            return null;
        }
        $fn->phpCompiledNodes = $compiler->nestedFnNodes;
        return $closure;
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
            $this->emitLine(
                $php . ' = isset($args[' . $idx . ']) && $args[' . $idx . '] '
                . 'instanceof \\PhpJs\\Value\\JsNumber ? $args[' . $idx . ']->value : null;'
            );
            $this->emitLine(
                'if (' . $php . ' === null) { throw new \\PhpJs\\Bytecode\\Bailout("non-numeric arg"); }'
            );
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
        // lookup. 'function' uses $_lf_, 'array' uses $_la_ for the
        // boxed JsValue references.
        $prefix = match ($this->localTypes[$name] ?? 'numeric') {
            'object' => '$_lo_',
            'function' => '$_lf_',
            'array' => '$_la_',
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
            && $node->operator === '='
            && $node->left instanceof Identifier
        ) {
            if (
                $node->right instanceof \PhpJs\Ast\Expression\ObjectExpression
                && isset($this->declaredLocals[$node->left->name])
            ) {
                $this->markLocalAsObject($node->left->name);
            }
            if (
                $node->right instanceof \PhpJs\Ast\Expression\ArrayExpression
                && isset($this->declaredLocals[$node->left->name])
            ) {
                $this->markLocalAsArray($node->left->name);
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
                    } else {
                        $this->emitLine($this->slotVar($name) . ' = 0.0;');
                    }
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
                $this->emitLine('return \\PhpJs\\Value\\JsUndefined::instance();');
            } else {
                $val = $this->emitExpression($node->argument);
                $this->flushPending();
                $this->emitLine('return \\PhpJs\\Value\\JsNumber::of((float)(' . $val . '));');
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
            // Property values are emitted via the numeric pipeline.
            // Function-expression / nested-object property values bail
            // (the inner emitExpression encounters them in the numeric
            // context and refuses), which is exactly what we want for
            // safety against shape-mismatched literals like
            // `{valueOf: function() {...}}` that test262 uses.
            $valueExpr = $this->emitExpression($prop->value);
            $this->pendingStatements[] = $temp . '->properties->dataSlots['
                . var_export($key, true) . '] = \\PhpJs\\Value\\JsNumber::of((float)('
                . $valueExpr . '));';
        }
        return $temp;
    }

    /**
     * Compile a branch of a conditional / short-circuit expression in
     * isolation. Returns [pendingStatementLines, finalExpression] so
     * the caller can wrap the lines inside a guarded if-block.
     *
     * @return array{0: list<string>, 1: string}
     */
    private function captureBranch(Node $node): array
    {
        $savedPending = $this->pendingStatements;
        $this->pendingStatements = [];
        $expr = $this->emitExpression($node);
        $branchPending = $this->pendingStatements;
        $this->pendingStatements = $savedPending;
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
            $name = $node->name;
            if (!isset($this->freeVars[$name])) {
                $this->freeVars[$name] = true;
                $box = '$_fvbox_' . preg_replace('/[^A-Za-z0-9_]/', '_', $name);
                $php = $this->freeVar($name);
                $this->pendingStatements[] = $box . ' = $env->get(' . var_export($name, true) . ');';
                $this->pendingStatements[] = 'if (!(' . $box
                    . ' instanceof \\PhpJs\\Value\\JsNumber)) { throw new \\PhpJs\\Bytecode\\Bailout("non-numeric freevar"); }';
                $this->pendingStatements[] = $php . ' = ' . $box . '->value;';
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
            // Read of obj.prop. Receiver must be a known object-typed
            // local (dataSlots dereferenced, unbox to numeric) or an
            // array-typed local with key 'length' (returns the live
            // length count as a numeric).
            if ($node->computed) {
                throw new Bailout('member read computed key');
            }
            if (!$node->object instanceof Identifier) {
                throw new Bailout('member read non-identifier receiver');
            }
            if (!$node->property instanceof Identifier) {
                throw new Bailout('member read non-identifier property');
            }
            $recvName = $node->object->name;
            $key = $node->property->name;
            $recvKind = $this->localTypes[$recvName] ?? 'numeric';
            if (!isset($this->declaredLocals[$recvName])) {
                throw new Bailout('member read on non-local');
            }
            if ($recvKind === 'array' && $key === 'length') {
                $recv = $this->slotVar($recvName);
                return '((float) ' . $recv . '->getLength())';
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
        $resultTemp = $this->emitCallCore($node);
        // The numeric pipeline expects a raw double. The callee's
        // contract is `JsNumber` — anything else triggers a Bailout
        // so the VM / tree-walker fallback handles non-numeric cases.
        $this->pendingStatements[] = 'if (!(' . $resultTemp
            . ' instanceof \\PhpJs\\Value\\JsNumber)) { throw new \\PhpJs\\Bytecode\\Bailout("non-numeric call result"); }';
        return $resultTemp . '->value';
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
        if (isset($this->declaredLocals[$calleeName])) {
            if (($this->localTypes[$calleeName] ?? null) !== 'function') {
                throw new Bailout('non-function-typed local callee');
            }
            $calleeRef = $this->slotVar($calleeName);
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
        $this->pendingStatements[] = 'if (!(' . $calleeRef
            . ' instanceof \\PhpJs\\Value\\JsFunction)) { throw new \\PhpJs\\Bytecode\\Bailout("non-function callee"); }';
        $this->pendingStatements[] = $resultTemp . ' = ('
            . $calleeRef . '->phpCompiled !== null'
            . ' && ' . $calleeRef . '->getNativeCallable() === null'
            . ')'
            . ' ? (' . $calleeRef . '->phpCompiled)('
            . $argsArr . ', ' . $calleeRef . '->getClosure(), $interp, '
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
