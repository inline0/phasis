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
     * declaration that's preceded by a non-declaration statement
     * either at the function body's top level OR in any nested
     * block. JsToPhp models all locals as function-scoped PHP
     * variables predeclared with default values in the prologue;
     * that breaks the spec Temporal Dead Zone, where reading the
     * binding before its let/const init must throw ReferenceError.
     * Bailing compile keeps the tree-walker (which honours TDZ)
     * authoritative for these patterns.
     *
     * Hot patterns like `for (...) { const {a,b} = ...; ... }` keep
     * the const as the first statement inside the block and don't
     * trip the check, so JsToPhp stays on the fast path.
     */
    private static function bodyHasNestedLexical(Node $body): bool
    {
        if (!$body instanceof BlockStatement) {
            return false;
        }
        // Top-level let/const after a non-decl statement is also a
        // TDZ shape; treat the function body as if it were a nested
        // block (innerDepth = 1). A let/const followed by reads is
        // safe — only a non-decl PRECEDING the let/const matters.
        // Also bail if any let/const declarator's init expression
        // reads the binding it's about to declare (`const x = x + 1`),
        // which is a TDZ violation per spec.
        $sawNonDecl = false;
        foreach ($body->body as $stmt) {
            if (
                $stmt instanceof VariableDeclaration
                && ($stmt->kind === 'let' || $stmt->kind === 'const')
            ) {
                if ($sawNonDecl) {
                    return true;
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
            if (
                !($stmt instanceof VariableDeclaration)
                && !($stmt instanceof FunctionDeclaration)
            ) {
                $sawNonDecl = true;
            }
            if (self::stmtHasNestedLexical($stmt, 1)) {
                return true;
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

    private static function stmtHasNestedLexical(Node $node, int $blockDepth): bool
    {
        if ($node instanceof BlockStatement) {
            // Entering this block bumps blockDepth for its inner
            // statements. Flag a let / const as TDZ-risky when
            // either (a) it's preceded by a non-declaration statement
            // in the same block, or (b) its init expression reads
            // the binding being declared (`const x = x + 1`).
            $innerDepth = $blockDepth + 1;
            $sawNonDecl = false;
            foreach ($node->body as $inner) {
                if (
                    $inner instanceof VariableDeclaration
                    && ($inner->kind === 'let' || $inner->kind === 'const')
                ) {
                    if ($innerDepth > 0 && $sawNonDecl) {
                        return true;
                    }
                    foreach ($inner->declarations as $decl) {
                        if (
                            $decl->id instanceof Identifier
                            && $decl->init !== null
                            && self::initReadsName($decl->init, $decl->id->name)
                        ) {
                            return true;
                        }
                    }
                }
                if (
                    !($inner instanceof VariableDeclaration)
                    && !($inner instanceof FunctionDeclaration)
                ) {
                    $sawNonDecl = true;
                }
                if (self::stmtHasNestedLexical($inner, $innerDepth)) {
                    return true;
                }
            }
            return false;
        }
        if (
            $node instanceof FunctionDeclaration
            || $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Expression\ArrowFunction
        ) {
            // Nested function: its body has its own scope.
            return false;
        }
        foreach ((array) $node as $value) {
            if ($value instanceof Node) {
                if (self::stmtHasNestedLexical($value, $blockDepth)) {
                    return true;
                }
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node && self::stmtHasNestedLexical($item, $blockDepth)) {
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
