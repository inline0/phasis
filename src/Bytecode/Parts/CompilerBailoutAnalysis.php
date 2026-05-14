<?php

declare(strict_types=1);

namespace Phasis\Bytecode\Parts;

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
use Phasis\Bytecode\CompilerBailout;

/**
 * Compiler trait part: CompilerBailoutAnalysis. Composed into Compiler via
 * `use Parts\CompilerBailoutAnalysis;`.
 */
trait CompilerBailoutAnalysis
{
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
            $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Expression\ArrowFunction
            || $node instanceof FunctionDeclaration
            || $node instanceof \Phasis\Ast\Expression\ClassExpression
            || $node instanceof \Phasis\Ast\Declaration\ClassDeclaration
        ) {
            // Writes inside a nested function bind that function's
            // local env, not the program scope. Stop here.
            return false;
        }
        if ($node instanceof \Phasis\Ast\Expression\AssignmentExpression) {
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
        if (!($node instanceof \Phasis\Ast\Expression\MemberExpression)) {
            return false;
        }
        $obj = $node->object;
        if ($obj instanceof \Phasis\Ast\Expression\ThisExpression) {
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
        if ($node instanceof \Phasis\Ast\Statement\WithStatement) {
            throw new CompilerBailout('with statement');
        }
        if ($node instanceof \Phasis\Ast\Statement\TryStatement) {
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
                && !($node->handler->param instanceof \Phasis\Ast\Expression\Identifier)
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
            $node instanceof \Phasis\Ast\Expression\YieldExpression
            || $node instanceof \Phasis\Ast\Expression\AwaitExpression
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
        if ($node instanceof \Phasis\Ast\Expression\ArrowFunction) {
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
            $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Declaration\FunctionDeclaration
        ) {
            if (self::nodeReferencesEval($node)) {
                throw new CompilerBailout('nested function references eval');
            }
            return;
        }
        if (
            $node instanceof \Phasis\Ast\Expression\ClassExpression
            || $node instanceof \Phasis\Ast\Declaration\ClassDeclaration
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
            $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Expression\ArrowFunction
            || $node instanceof FunctionDeclaration
            || $node instanceof \Phasis\Ast\Expression\ClassExpression
            || $node instanceof \Phasis\Ast\Declaration\ClassDeclaration
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
            $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Expression\ArrowFunction
            || $node instanceof FunctionDeclaration
            || $node instanceof \Phasis\Ast\Expression\ClassExpression
            || $node instanceof \Phasis\Ast\Declaration\ClassDeclaration
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
        if ($node instanceof \Phasis\Ast\Expression\FunctionExpression) {
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
        } elseif ($node instanceof \Phasis\Ast\Expression\ArrowFunction) {
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
            $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Expression\ArrowFunction
            || $node instanceof FunctionDeclaration
            || $node instanceof \Phasis\Ast\Expression\ClassExpression
            || $node instanceof \Phasis\Ast\Declaration\ClassDeclaration
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
     * @param array<int, \Phasis\Ast\Node> $statements
     */
    private static function programHasUseStrictDirective(array $statements): bool
    {
        foreach ($statements as $stmt) {
            if (!$stmt instanceof \Phasis\Ast\Statement\ExpressionStatement) {
                return false;
            }
            $expr = $stmt->expression;
            if (!$expr instanceof \Phasis\Ast\Expression\Literal) {
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

    private static function bytecodeBailsForTailCall(\Phasis\Ast\Node $body): bool
    {
        if ($body instanceof ReturnStatement) {
            return $body->argument !== null
                && self::tailCallExpr($body->argument);
        }
        if (
            $body instanceof \Phasis\Ast\Declaration\FunctionDeclaration
            || $body instanceof \Phasis\Ast\Expression\FunctionExpression
            || $body instanceof \Phasis\Ast\Expression\ArrowFunction
        ) {
            return false;
        }
        foreach ((array) $body as $value) {
            if ($value instanceof \Phasis\Ast\Node) {
                if (self::bytecodeBailsForTailCall($value)) {
                    return true;
                }
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof \Phasis\Ast\Node && self::bytecodeBailsForTailCall($item)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private static function tailCallExpr(\Phasis\Ast\Node $node): bool
    {
        if ($node instanceof CallExpression) {
            return true;
        }
        if ($node instanceof ConditionalExpression) {
            return self::tailCallExpr($node->consequent)
                || self::tailCallExpr($node->alternate);
        }
        if ($node instanceof \Phasis\Ast\Expression\LogicalExpression) {
            return self::tailCallExpr($node->left)
                || self::tailCallExpr($node->right);
        }
        if ($node instanceof \Phasis\Ast\Expression\SequenceExpression) {
            $exprs = $node->expressions;
            return $exprs !== [] && self::tailCallExpr($exprs[count($exprs) - 1]);
        }
        return false;
    }
}
