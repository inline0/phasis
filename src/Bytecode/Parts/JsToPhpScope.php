<?php

declare(strict_types=1);

namespace Phasis\Bytecode\Parts;

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
use Phasis\Bytecode\Bailout;

/**
 * JsToPhp trait part: JsToPhpScope. Composed into JsToPhp via
 * `use Parts\JsToPhpScope;`.
 */
trait JsToPhpScope
{
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
            if ($node->kind === 'using' || $node->kind === 'await using') {
                // `using` declarations register a disposable on the
                // current env, perform the spec's "must be Object" check,
                // and run Symbol.dispose on scope exit. JsToPhp models
                // locals as plain PHP variables and has no disposal hook;
                // bail to the tree walker (or bytecode VM, which also
                // bails).
                throw new Bailout('using declaration');
            }
            foreach ($node->declarations as $decl) {
                if ($decl->id instanceof \Phasis\Ast\Pattern\ObjectPattern) {
                    // Object destructuring: collect each shorthand /
                    // identifier-bound property as a numeric local.
                    foreach ($decl->id->properties as $prop) {
                        if (!$prop instanceof \Phasis\Ast\Pattern\AssignmentProperty) {
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
                if ($node->kind === 'const') {
                    $this->constLocals[$name] = true;
                }
                if ($decl->init === null) {
                    // Track no-init declarations so the post-collect
                    // pass can detect the read-as-undefined pattern.
                    // Removed from the set the moment any assignment
                    // to this name appears in the body.
                    $this->unassignedDeclares[$name] = true;
                }
                if ($decl->init instanceof \Phasis\Ast\Expression\ObjectExpression) {
                    $this->markLocalAsObject($name);
                }
                if ($decl->init instanceof \Phasis\Ast\Expression\NewExpression) {
                    // `const c = new C(...)` — type-promote the slot
                    // to object so subsequent reads / method calls
                    // see a JsObject. The emit path's NewExpression
                    // handler invokes vmNewExpression and Bailout-
                    // guards a non-object return.
                    $this->markLocalAsObject($name);
                }
                if ($decl->init instanceof \Phasis\Ast\Expression\ArrayExpression) {
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
            || $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Expression\ArrowFunction
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
            $fnNode instanceof \Phasis\Ast\Expression\FunctionExpression
            && $fnNode->name !== null
        ) {
            $blocked[$fnNode->name] = true;
        }
        $params = match (true) {
            $fnNode instanceof FunctionDeclaration => $fnNode->params,
            $fnNode instanceof \Phasis\Ast\Expression\FunctionExpression => $fnNode->params,
            $fnNode instanceof \Phasis\Ast\Expression\ArrowFunction => $fnNode->params,
            default => [],
        };
        foreach ($params as $p) {
            if ($p instanceof Identifier) {
                $blocked[$p->name] = true;
            }
            if (
                $p instanceof \Phasis\Ast\Pattern\AssignmentPattern
                && $p->left instanceof Identifier
            ) {
                $blocked[$p->left->name] = true;
            }
            if ($p instanceof \Phasis\Ast\Pattern\RestElement && $p->argument instanceof Identifier) {
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
            $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Expression\ArrowFunction
        ) {
            $deeper = $this->initialNestedScope($node);
            $this->scanNestedBody($node->body, $deeper);
            return;
        }
        if (
            $node instanceof \Phasis\Ast\Expression\MemberExpression
            && !$node->computed
        ) {
            // For `obj.prop`, only `obj` is a name read; `prop` is a
            // property name, not an identifier reference. Walk only
            // the object side.
            $this->scanNestedBody($node->object, $blocked);
            return;
        }
        if ($node instanceof \Phasis\Ast\Expression\Property && !$node->computed) {
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
        if ($pattern instanceof \Phasis\Ast\Pattern\ArrayPattern) {
            foreach ($pattern->elements as $el) {
                if ($el !== null) {
                    $this->collectPatternNames($el, $blocked);
                }
            }
            return;
        }
        if ($pattern instanceof \Phasis\Ast\Pattern\ObjectPattern) {
            foreach ($pattern->properties as $prop) {
                if ($prop instanceof \Phasis\Ast\Pattern\AssignmentProperty) {
                    $this->collectPatternNames($prop->value, $blocked);
                }
                if ($prop instanceof \Phasis\Ast\Pattern\RestElement) {
                    $this->collectPatternNames($prop->argument, $blocked);
                }
            }
            return;
        }
        if ($pattern instanceof \Phasis\Ast\Pattern\AssignmentPattern) {
            $this->collectPatternNames($pattern->left, $blocked);
            return;
        }
        if ($pattern instanceof \Phasis\Ast\Pattern\RestElement) {
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
                if ($arg instanceof \Phasis\Ast\Expression\SpreadElement) {
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
        if ($node instanceof UpdateExpression) {
            // ++ / -- counts as an assignment to the target. Track
            // for the unassigned-declare bail and the const-mutation
            // bail.
            if ($node->argument instanceof Identifier) {
                $this->assignedNames[$node->argument->name] = true;
            }
            $this->collectCalleeUsage($node->argument);
            return;
        }
        if ($node instanceof UnaryExpression) {
            $this->collectCalleeUsage($node->argument);
            return;
        }
        if ($node instanceof AssignmentExpression) {
            // Track assignment targets for the unassigned-declare /
            // const-mutation bails. AssignmentExpression also fires
            // through collectAssignmentTypes for Identifier targets
            // already, but that path only sets assignedNames when the
            // local is in declaredLocals at that moment — top-level
            // walk-order means a `for (const i = 0; ...; i++)` update
            // wouldn't be caught there because i isn't yet declared
            // by the time the parent ForStatement walks the update.
            // Catch it here.
            if ($node->left instanceof Identifier) {
                $this->assignedNames[$node->left->name] = true;
            }
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
            // Mark this declared local as assigned somewhere in the
            // body. After the full collect pass, any local still
            // present in $unassignedDeclares is provably read as
            // its prologue default — for numeric locals that's 0
            // not undefined, so we bail compile.
            $this->assignedNames[$node->left->name] = true;
            if (
                $node->operator === '='
                && $node->right instanceof \Phasis\Ast\Expression\ObjectExpression
            ) {
                $this->markLocalAsObject($node->left->name);
            }
            if (
                $node->operator === '='
                && $node->right instanceof \Phasis\Ast\Expression\NewExpression
            ) {
                // `local = new Ctor(...)` re-assignment: same as the
                // declarator-with-NewExpression-init pre-walk, mark the
                // slot object-typed so the emit-side AssignmentExpression
                // path lowers through vmNewExpression instead of bailing.
                $this->markLocalAsObject($node->left->name);
            }
            if (
                $node->operator === '='
                && $node->right instanceof \Phasis\Ast\Expression\ArrayExpression
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
}
