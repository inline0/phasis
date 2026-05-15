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

/**
 * JsToPhp trait part: JsToPhpEligibility. Composed into JsToPhp via
 * `use Parts\JsToPhpEligibility;`.
 */
trait JsToPhpEligibility
{
    /**
     * Walk a function body looking for identifier references to
     * `arguments` / `this` / `new.target`. These resolve through the
     * per-invocation environment that executeFunction sets up; the
     * JsToPhp closure runs in the function's lexical $closure env
     * which has no such bindings. Touching them would either throw
     * ReferenceError ("arguments is not defined") or — worse —
     * silently read an outer-scope binding with the same name.
     */
    /**
     * True when $body (a function body) contains a let / const
     * declaration whose Temporal Dead Zone the JsToPhp emit can't
     * model safely. JsToPhp gives every declared local a
     * function-scoped PHP slot pre-initialised in the prologue;
     * that breaks TDZ semantics whenever the source reads the
     * binding before its `let` / `const`.
     *
     * Two distinct TDZ shapes are flagged:
     *   (a) A `let` / `const` declarator's init reads the binding
     *       being declared (`const x = x + 1`).
     *   (b) Inside the same lexical block (or the function body),
     *       any statement preceding the declaration reads the
     *       declared name. If no preceding statement reads it, the
     *       transpiler can treat the binding as function-scoped:
     *       the slot's prologue default is overwritten by the
     *       declarator's init before any read fires, matching spec
     *       observable behaviour. This widens acceptance to shapes
     *       like `if (cond) { s = n; const v = s * 2; ... }` that
     *       previously bailed on the strict "non-decl precedes
     *       let" heuristic.
     *
     * Hot patterns like `for (...) { const {a,b} = ...; ... }` and
     * `for (let i = 0; ...; i++) { stmt; const k = ...; ... }`
     * both stay on the fast path; only genuinely TDZ-sensitive
     * code (reading the binding before its `let`/`const` decl)
     * falls back to the tree-walker.
     */
    private static function bodyHasNestedLexical(Node $body): bool
    {
        if (!$body instanceof BlockStatement) {
            return false;
        }
        return self::blockHasUnsafeLexical($body->body);
    }

    /**
     * Walk a sequence of statements that share a lexical block scope
     * and decide whether any let / const inside (or transitively
     * inside, when recursing into non-block-introducing children)
     * is TDZ-risky. Returns true if compilation should bail.
     *
     * @param Node[] $statements
     */
    private static function blockHasUnsafeLexical(array $statements): bool
    {
        $prefix = [];
        foreach ($statements as $stmt) {
            if (
                $stmt instanceof VariableDeclaration
                && ($stmt->kind === 'let' || $stmt->kind === 'const')
            ) {
                $declNames = self::collectDeclNames($stmt);
                if ($declNames !== []) {
                    foreach ($prefix as $prior) {
                        if (self::readsAnyName($prior, $declNames)) {
                            return true;
                        }
                    }
                }
                foreach ($stmt->declarations as $decl) {
                    if (
                        $decl->id instanceof Identifier
                        && $decl->init !== null
                        && self::initReadsName($decl->init, $decl->id->name)
                    ) {
                        return true;
                    }
                }
            }
            if (self::stmtHasNestedLexical($stmt)) {
                return true;
            }
            $prefix[] = $stmt;
        }
        return false;
    }

    /**
     * Collect the binding names introduced by a single let / const
     * VariableDeclaration. Handles plain identifiers and the simple
     * destructuring shapes that JsToPhp's emit pipeline accepts.
     *
     * @return list<string>
     */
    private static function collectDeclNames(VariableDeclaration $node): array
    {
        $names = [];
        foreach ($node->declarations as $decl) {
            $id = $decl->id;
            if ($id instanceof Identifier) {
                $names[] = $id->name;
                continue;
            }
            if ($id instanceof \Phasis\Ast\Pattern\ObjectPattern) {
                foreach ($id->properties as $prop) {
                    if (
                        $prop instanceof \Phasis\Ast\Pattern\AssignmentProperty
                        && $prop->value instanceof Identifier
                    ) {
                        $names[] = $prop->value->name;
                    }
                }
                continue;
            }
            if ($id instanceof \Phasis\Ast\Pattern\ArrayPattern) {
                foreach ($id->elements as $el) {
                    if ($el instanceof Identifier) {
                        $names[] = $el->name;
                    }
                }
                continue;
            }
        }
        return $names;
    }

    /**
     * True when $node (typically a statement or sub-expression in
     * source order BEFORE a let/const declaration) contains an
     * Identifier read of any name in $names. Skips:
     *   - The .property side of non-computed MemberExpression
     *     (`obj.x` doesn't read a binding `x`).
     *   - The .key side of non-computed object-literal Property.
     *   - Nested function / arrow bodies (their scope is separate;
     *     a closure capturing the outer name lexically would still
     *     fail at the call site, but the call has to happen AFTER
     *     the declaration in source order — and TDZ is dynamic, not
     *     lexical, so a closure captured before the decl that's
     *     called after the decl is spec-legal). For the simple
     *     code we accept, checkNestedCaptures already enforces
     *     no-outer-capture nested fns, making this exclusion safe.
     *
     * @param list<string> $names
     */
    private static function readsAnyName(Node $node, array $names): bool
    {
        if ($node instanceof Identifier) {
            return in_array($node->name, $names, true);
        }
        if (
            $node instanceof FunctionDeclaration
            || $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Expression\ArrowFunction
        ) {
            return false;
        }
        if ($node instanceof \Phasis\Ast\Expression\MemberExpression) {
            if (self::readsAnyName($node->object, $names)) {
                return true;
            }
            if ($node->computed && self::readsAnyName($node->property, $names)) {
                return true;
            }
            return false;
        }
        if ($node instanceof \Phasis\Ast\Expression\Property) {
            if ($node->computed && self::readsAnyName($node->key, $names)) {
                return true;
            }
            return self::readsAnyName($node->value, $names);
        }
        foreach ((array) $node as $value) {
            if ($value instanceof Node) {
                if (self::readsAnyName($value, $names)) {
                    return true;
                }
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node && self::readsAnyName($item, $names)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Walk an init expression looking for an Identifier reference
     * to the name being declared. Used to flag self-referential
     * let / const inits (`const x = x + 1`) which are TDZ violations
     * the JsToPhp emit can't model — function-local x is already
     * declared as a PHP local with a default value before the init
     * expression evaluates, so the read silently succeeds.
     */
    private static function initReadsName(Node $node, string $name): bool
    {
        if ($node instanceof Identifier) {
            return $node->name === $name;
        }
        if (
            $node instanceof FunctionDeclaration
            || $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Expression\ArrowFunction
        ) {
            // Nested function: shadows the binding only if it has a
            // param of the same name; conservatively bail.
            return false;
        }
        // Non-computed member expressions (`args.x`) carry the property
        // name as an Identifier under .property, but it isn't a
        // variable read — `args.foo` doesn't read a binding called
        // `foo`. Skip it so `const foo = args.foo;` doesn't get
        // flagged as a self-referential init. Also skip the .key of
        // a non-computed object-literal Property for the same reason.
        if ($node instanceof \Phasis\Ast\Expression\MemberExpression) {
            if (self::initReadsName($node->object, $name)) {
                return true;
            }
            if ($node->computed && self::initReadsName($node->property, $name)) {
                return true;
            }
            return false;
        }
        if ($node instanceof \Phasis\Ast\Expression\Property) {
            if ($node->computed && self::initReadsName($node->key, $name)) {
                return true;
            }
            return self::initReadsName($node->value, $name);
        }
        foreach ((array) $node as $value) {
            if ($value instanceof Node) {
                if (self::initReadsName($value, $name)) {
                    return true;
                }
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node && self::initReadsName($item, $name)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Recurse into $node looking for nested BlockStatement scopes;
     * each one is analysed independently by blockHasUnsafeLexical
     * so a TDZ-risky let / const anywhere in the body bails. Doesn't
     * descend into nested function bodies — those introduce their
     * own scopes and their own JsToPhp compile attempt.
     */
    private static function stmtHasNestedLexical(Node $node): bool
    {
        if ($node instanceof BlockStatement) {
            return self::blockHasUnsafeLexical($node->body);
        }
        if (
            $node instanceof FunctionDeclaration
            || $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Expression\ArrowFunction
        ) {
            return false;
        }
        foreach ((array) $node as $value) {
            if ($value instanceof Node) {
                if (self::stmtHasNestedLexical($value)) {
                    return true;
                }
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node && self::stmtHasNestedLexical($item)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Walks a function body looking for any conditional construct
     * (IfStatement / ConditionalExpression / SwitchStatement). Used
     * by the no-init-local guard to decide whether the local could
     * be observably read before its first assignment along some
     * control-flow path. Without conditionals, the source-order
     * walk is the actual execution order, so a no-init local with
     * an unconditional assign-then-read is safe.
     */
    private static function bodyHasConditional(Node $node): bool
    {
        if (
            $node instanceof IfStatement
            || $node instanceof ConditionalExpression
            || $node instanceof \Phasis\Ast\Statement\SwitchStatement
            || $node instanceof \Phasis\Ast\Statement\TryStatement
            || $node instanceof LogicalExpression
        ) {
            return true;
        }
        if (
            $node instanceof FunctionDeclaration
            || $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Expression\ArrowFunction
        ) {
            return false;
        }
        foreach ((array) $node as $value) {
            if ($value instanceof Node) {
                if (self::bodyHasConditional($value)) {
                    return true;
                }
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node && self::bodyHasConditional($item)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * True when $body contains a ReturnStatement whose argument is
     * (or could resolve to) a CallExpression in tail position. Used
     * to bail strict-mode JsToPhp compile so the tree-walker's
     * TailCallThunk + callFunction trampoline handles the recursion
     * without growing the PHP call stack.
     *
     * Conservatively returns true for any return-with-call shape,
     * including ternary / logical-short-circuit / sequence wrappers
     * around a call. Doesn't descend into nested functions.
     */
    private static function bodyHasReturnCall(Node $body): bool
    {
        if ($body instanceof ReturnStatement) {
            return $body->argument !== null && self::exprHasTailCall($body->argument);
        }
        if (
            $body instanceof FunctionDeclaration
            || $body instanceof \Phasis\Ast\Expression\FunctionExpression
            || $body instanceof \Phasis\Ast\Expression\ArrowFunction
        ) {
            return false;
        }
        foreach ((array) $body as $value) {
            if ($value instanceof Node) {
                if (self::bodyHasReturnCall($value)) {
                    return true;
                }
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node && self::bodyHasReturnCall($item)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private static function exprHasTailCall(Node $node): bool
    {
        if ($node instanceof CallExpression) {
            return true;
        }
        if ($node instanceof ConditionalExpression) {
            return self::exprHasTailCall($node->consequent)
                || self::exprHasTailCall($node->alternate);
        }
        if ($node instanceof LogicalExpression) {
            return self::exprHasTailCall($node->left)
                || self::exprHasTailCall($node->right);
        }
        if ($node instanceof \Phasis\Ast\Expression\SequenceExpression) {
            $exprs = $node->expressions;
            return $exprs !== [] && self::exprHasTailCall($exprs[count($exprs) - 1]);
        }
        return false;
    }

    private static function bodyReferencesPerCallBindings(Node $node): bool
    {
        if ($node instanceof Identifier) {
            // 'super' / 'arguments' / 'new.target' — all per-call.
            return $node->name === 'arguments' || $node->name === 'super';
        }
        if ($node instanceof \Phasis\Ast\Expression\ThisExpression) {
            return true;
        }
        if ($node instanceof \Phasis\Ast\Expression\MetaProperty) {
            return true;
        }
        // Don't descend into nested function bodies — those have their
        // own arguments / this binding scopes. The outer body's
        // reference is what matters.
        if (
            $node instanceof FunctionDeclaration
            || $node instanceof \Phasis\Ast\Expression\FunctionExpression
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
}
