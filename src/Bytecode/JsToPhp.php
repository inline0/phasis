<?php

declare(strict_types=1);

namespace PhpJs\Bytecode;

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
     *   - 'numeric' (default): PHP raw double, stored in $_l_NAME.
     *   - 'object'           : JsObject reference, stored in $_lo_NAME.
     *
     * Determined by a pre-walk that looks at every declarator init and
     * assignment. A local that is ever assigned an ObjectExpression
     * (and never assigned a numeric expression) becomes 'object';
     * everything else stays 'numeric'. Mixed (numeric + object on
     * different paths) is a compile-time bailout — the slot type
     * choice has to be unambiguous so the emitted PHP can pick the
     * right variable name.
     *
     * @var array<string, string>
     */
    private array $localTypes = [];

    /** @var array<string, true> Free variables referenced by the body. */
    private array $freeVars = [];

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
                $compiler->emitPrologue($params);
                foreach ($body->body as $stmt) {
                    $compiler->emitStatement($stmt);
                }
                $compiler->emitLine('return \\PhpJs\\Value\\JsUndefined::instance();');
            }
        } catch (Bailout) {
            return null;
        }
        $php = "return function (\$args, \$env, \$interp) {\n"
            . $compiler->out
            . "};";
        try {
            /** @var \Closure $closure */
            $closure = eval($php);
        } catch (\Throwable) {
            return null;
        }
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
            }
            return;
        }
        if ($node instanceof ExpressionStatement) {
            $this->collectAssignmentTypes($node->expression);
            return;
        }
        if ($node instanceof BlockStatement) {
            $this->collectLocals($node->body);
            return;
        }
        if ($node instanceof IfStatement) {
            $this->collectLocalsIn($node->consequent);
            if ($node->alternate !== null) {
                $this->collectLocalsIn($node->alternate);
            }
            return;
        }
        if ($node instanceof ForStatement) {
            if ($node->init instanceof VariableDeclaration) {
                $this->collectLocalsIn($node->init);
            }
            $this->collectLocalsIn($node->body);
            return;
        }
        if ($node instanceof WhileStatement || $node instanceof DoWhileStatement) {
            $this->collectLocalsIn($node->body);
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
                if (($this->localTypes[$name] ?? 'numeric') === 'object') {
                    // Initialised by an ObjectExpression assignment
                    // later. Pre-declare to silence "undefined" reads
                    // on a control-flow path that never assigns.
                    $this->emitLine($this->slotVar($name) . ' = null;');
                } else {
                    $this->emitLine($this->slotVar($name) . ' = 0.0;');
                }
            }
        }
    }

    private function slotVar(string $name): string
    {
        // Object-typed locals get a distinct prefix so the emitted
        // code doesn't accidentally treat them as raw doubles
        // anywhere a name->variable lookup happens.
        $prefix = ($this->localTypes[$name] ?? 'numeric') === 'object'
            ? '$_lo_'
            : '$_l_';
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
        if (($this->localTypes[$name] ?? null) === 'numeric') {
            throw new Bailout('local ' . $name . ' is mixed numeric/object');
        }
        $this->localTypes[$name] = 'object';
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
            && $node->right instanceof \PhpJs\Ast\Expression\ObjectExpression
        ) {
            if (isset($this->declaredLocals[$node->left->name])) {
                $this->markLocalAsObject($node->left->name);
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
                if ($decl->init === null) {
                    if (($this->localTypes[$name] ?? 'numeric') === 'object') {
                        $this->emitLine($this->slotVar($name) . ' = null;');
                    } else {
                        $this->emitLine($this->slotVar($name) . ' = 0.0;');
                    }
                } elseif (
                    $decl->init instanceof \PhpJs\Ast\Expression\ObjectExpression
                    && ($this->localTypes[$name] ?? null) === 'object'
                ) {
                    // Object literal init: emit JsObject construction
                    // and assign the temp to the object-typed slot.
                    $temp = $this->emitObjectLiteral($decl->init);
                    $this->flushPending();
                    $this->emitLine($this->slotVar($name) . ' = ' . $temp . ';');
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
            // local; the dataSlots access yields a JsValue that we
            // unbox to a numeric raw double for downstream arithmetic.
            if ($node->computed) {
                throw new Bailout('member read computed key');
            }
            if (!$node->object instanceof Identifier) {
                throw new Bailout('member read non-identifier receiver');
            }
            $recvName = $node->object->name;
            if (
                !isset($this->declaredLocals[$recvName])
                || ($this->localTypes[$recvName] ?? 'numeric') !== 'object'
            ) {
                throw new Bailout('member read on non-object local');
            }
            if (!$node->property instanceof Identifier) {
                throw new Bailout('member read non-identifier property');
            }
            $key = $node->property->name;
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
        // Resolve the callee once. Most call sites (recursion, top-level
        // helper) point to the same JsFunction every time; cache the
        // lookup in a $_fc_ temp local once per unique callee name.
        $calleeName = $node->callee->name;
        if (isset($this->declaredLocals[$calleeName])) {
            // Local variable holds the callee value (rare for our
            // numeric profile but legal). Bail since we need a
            // JsFunction at runtime and the local would be a raw
            // double in our representation.
            throw new Bailout('callee is local');
        }
        $calleeRef = '$_fnc_' . preg_replace('/[^A-Za-z0-9_]/', '_', $calleeName);
        if (!isset($this->freeVars['__fnc_' . $calleeName])) {
            $this->freeVars['__fnc_' . $calleeName] = true;
            $this->pendingStatements[] = $calleeRef
                . ' = $env->get(' . var_export($calleeName, true) . ');';
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
            . $argsArr . ', ' . $calleeRef . '->getClosure(), $interp)'
            . ' : $interp->callFunction(' . $calleeRef . ', '
            . '\\PhpJs\\Value\\JsUndefined::instance(), ' . $argsArr . ');';
        $this->pendingStatements[] = 'if (!(' . $resultTemp
            . ' instanceof \\PhpJs\\Value\\JsNumber)) { throw new \\PhpJs\\Bytecode\\Bailout("non-numeric call result"); }';
        return $resultTemp . '->value';
    }

    private function emitLine(string $line): void
    {
        $this->out .= str_repeat('    ', $this->indentLevel) . $line . "\n";
    }
}
