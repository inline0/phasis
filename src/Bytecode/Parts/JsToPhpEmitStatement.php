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
 * JsToPhp trait part: JsToPhpEmitStatement. Composed into JsToPhp via
 * `use Parts\JsToPhpEmitStatement;`.
 */
trait JsToPhpEmitStatement
{
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
                    . 'instanceof \\Phasis\\Value\\JsNumber ? $args[' . $idx . ']->value : null;'
                );
                $this->emitLine(
                    'if (' . $php . ' === null) { throw new \\Phasis\\Bytecode\\Bailout("non-numeric arg"); }'
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
            // For a discarded call result we don't need the JsNumber
            // unbox assertion that emitCallExpression appends — it
            // forces a Bailout when the callee returns anything but a
            // number, which then triggers the slow interpreter path
            // and re-runs the call (with all its side effects). Skip
            // straight to emitCallCore so the call only runs once.
            if ($node->expression instanceof CallExpression) {
                $resultTemp = $this->emitCallCore($node->expression);
                $this->flushPending();
                $this->emitLine($resultTemp . ';');
                return;
            }
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
                    $decl->id instanceof \Phasis\Ast\Pattern\ObjectPattern
                    && $decl->init instanceof \Phasis\Ast\Expression\ObjectExpression
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
                    $decl->init instanceof \Phasis\Ast\Expression\ArrayExpression
                    && $kind === 'array'
                ) {
                    // Array literal init: empty `[]` → fresh JsArray.
                    // Non-empty literals would need element evaluation;
                    // bail until we extend support.
                    if ($decl->init->elements !== []) {
                        throw new Bailout('non-empty array literal');
                    }
                    $this->emitLine(
                        $this->slotVar($name) . ' = new \\Phasis\\Value\\JsArray();'
                    );
                } elseif (
                    $decl->init instanceof \Phasis\Ast\Expression\ObjectExpression
                    && $kind === 'object'
                ) {
                    // Object literal init: emit JsObject construction
                    // and assign the temp to the object-typed slot.
                    $temp = $this->emitObjectLiteral($decl->init);
                    $this->flushPending();
                    $this->emitLine($this->slotVar($name) . ' = ' . $temp . ';');
                } elseif (
                    $decl->init instanceof \Phasis\Ast\Expression\NewExpression
                    && $kind === 'object'
                ) {
                    // `const c = new Ctor(args)` — resolve Ctor as a
                    // free-variable (or local function-typed slot,
                    // not yet supported), box args, dispatch to
                    // vmNewExpression. Result must be JsObject;
                    // otherwise Bailout so the tree-walker handles
                    // the construct-returns-primitive corner case.
                    $newTemp = $this->emitNewExpression($decl->init);
                    $this->flushPending();
                    $this->emitLine('if (!(' . $newTemp
                        . ' instanceof \\Phasis\\Value\\JsObject)) { '
                        . 'throw new \\Phasis\\Bytecode\\Bailout("new returned non-object"); }');
                    $this->emitLine($this->slotVar($name) . ' = ' . $newTemp . ';');
                } elseif ($kind === 'function' && $decl->init instanceof CallExpression) {
                    // Call returning a JsFunction (e.g. `const add5 =
                    // adder(5)`). Emit the call but skip the numeric
                    // unbox; a runtime check enforces the type so a
                    // wrong-type result throws Bailout cleanly.
                    $callRaw = $this->emitFunctionValuedCall($decl->init);
                    $this->flushPending();
                    $this->emitLine($this->slotVar($name) . ' = ' . $callRaw . ';');
                } elseif ($kind === 'numeric' && $decl->init instanceof CallExpression) {
                    // `let r = callExpr()` where r's static type defaults
                    // to numeric. emitExpression would emit the call THEN
                    // a runtime `instanceof JsNumber` check that throws
                    // Bailout for any non-numeric return. The call's side
                    // effects (counter++, Atomics.notify, etc.) have
                    // already executed by the time the Bailout fires, and
                    // the tree-walker fallback re-runs the entire function
                    // body, double-invoking the callee. Bail at compile
                    // time so the function never enters phpCompiled in the
                    // first place: the tree-walker runs once, side effects
                    // happen once, semantics stay spec-correct. We accept
                    // the perf hit for default-numeric-typed init-from-call
                    // patterns because we can't statically prove the
                    // callee returns a JsNumber.
                    throw new Bailout('let X = callExpr() with default numeric type');
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
            // When the test or update needs deferred statements (free-
            // variable resolution caches, lifted call results), rewrite
            // the loop as `while (true) { … if (!cond) break; body;
            // update; }` so the pending statements can run inside the
            // body. Eliminates a major class of JIT bailouts: every
            // `for (let i = 0; i < arr.length; i++)` shape, every for-
            // loop that references a closed-over local in its test.
            $savedPending = $this->pendingStatements;
            $this->pendingStatements = [];
            $test = $node->test !== null ? $this->emitExpression($node->test) : 'true';
            $testPending = $this->pendingStatements;
            $this->pendingStatements = [];
            $update = null;
            $updatePending = [];
            if ($node->update !== null) {
                $update = $this->emitExpression($node->update);
                $updatePending = $this->pendingStatements;
            }
            $this->pendingStatements = $savedPending;
            if ($testPending === [] && $updatePending === []) {
                // Cheap path: clean test + update, emit a plain `while`.
                $this->emitLine('while (' . $test . ') {');
                $this->indentLevel++;
                $this->emitStatement($node->body);
                if ($update !== null) {
                    $this->emitLine($update . ';');
                }
                $this->indentLevel--;
                $this->emitLine('}');
            } else {
                // Rewrite shape: while (true) { pending; if (!test)
                // break; body; update_pending; update; }.
                $this->emitLine('while (true) {');
                $this->indentLevel++;
                foreach ($testPending as $line) {
                    $this->emitLine($line);
                }
                $this->emitLine('if (!(' . $test . ')) { break; }');
                $this->emitStatement($node->body);
                if ($update !== null) {
                    foreach ($updatePending as $line) {
                        $this->emitLine($line);
                    }
                    $this->emitLine($update . ';');
                }
                $this->indentLevel--;
                $this->emitLine('}');
            }
            return;
        }
        if ($node instanceof WhileStatement) {
            $savedPending = $this->pendingStatements;
            $this->pendingStatements = [];
            $test = $this->emitExpression($node->test);
            $testPending = $this->pendingStatements;
            $this->pendingStatements = $savedPending;
            if ($testPending === []) {
                $this->emitLine('while (' . $test . ') {');
                $this->indentLevel++;
                $this->emitStatement($node->body);
                $this->indentLevel--;
                $this->emitLine('}');
            } else {
                // Hoist test-pending statements into the loop body so
                // free-var lookups and lifted calls inside the test
                // condition no longer kick the function out of the JIT.
                $this->emitLine('while (true) {');
                $this->indentLevel++;
                foreach ($testPending as $line) {
                    $this->emitLine($line);
                }
                $this->emitLine('if (!(' . $test . ')) { break; }');
                $this->emitStatement($node->body);
                $this->indentLevel--;
                $this->emitLine('}');
            }
            return;
        }
        if ($node instanceof DoWhileStatement) {
            $savedPending = $this->pendingStatements;
            $this->pendingStatements = [];
            $test = $this->emitExpression($node->test);
            $testPending = $this->pendingStatements;
            $this->pendingStatements = $savedPending;
            $this->emitLine('do {');
            $this->indentLevel++;
            $this->emitStatement($node->body);
            if ($testPending !== []) {
                foreach ($testPending as $line) {
                    $this->emitLine($line);
                }
            }
            $this->indentLevel--;
            if ($testPending === []) {
                $this->emitLine('} while (' . $test . ');');
            } else {
                // The PHP `do { … } while (X);` can't host extra
                // statements between body and the test; rewrite using
                // a flag so the pending lines run before the test.
                $this->emitLine('} while (' . $test . ');');
            }
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
                $this->emitLine('return \\Phasis\\Value\\JsUndefined::instance();');
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
                    $this->emitLine('return \\Phasis\\Value\\JsBoolean::of((bool)(' . $val . '));');
                } else {
                    // Default to numeric wrap. inferExpressionType
                    // returns 'unknown' for shapes we can't classify
                    // statically; the JsNumber wrap is wrong for
                    // string / object / etc., so bail those before
                    // they reach this branch.
                    if ($type !== 'numeric') {
                        throw new Bailout('return type ' . $type . ' not yet wrappable');
                    }
                    $this->emitLine('return \\Phasis\\Value\\JsNumber::of((float)(' . $val . '));');
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
        \Phasis\Ast\Pattern\ObjectPattern $pattern,
        \Phasis\Ast\Expression\ObjectExpression $literal,
    ): void {
        // Build a key → AST value map from the literal so pattern
        // targets can fetch their values without a runtime lookup.
        $keyMap = [];
        foreach ($literal->properties as $prop) {
            if (!$prop instanceof \Phasis\Ast\Expression\Property) {
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
            if (!$prop instanceof \Phasis\Ast\Pattern\AssignmentProperty) {
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
    private function emitObjectLiteral(\Phasis\Ast\Expression\ObjectExpression $node): string
    {
        $temp = $this->newTemp('obj');
        $this->pendingStatements[] = $temp . ' = new \\Phasis\\Value\\JsObject();';
        foreach ($node->properties as $prop) {
            if (!$prop instanceof \Phasis\Ast\Expression\Property) {
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
                return '\\Phasis\\Value\\JsNull::instance()';
            }
            if (is_bool($node->value)) {
                return '\\Phasis\\Value\\JsBoolean::of(' . ($node->value ? 'true' : 'false') . ')';
            }
            if (is_int($node->value) || is_float($node->value)) {
                return '\\Phasis\\Value\\JsNumber::of((float) ' . (string) (float) $node->value . ')';
            }
            if (is_string($node->value)) {
                return 'new \\Phasis\\Value\\JsString(' . var_export($node->value, true) . ')';
            }
            throw new Bailout('unknown literal type in object literal');
        }
        if ($node instanceof \Phasis\Ast\Expression\ObjectExpression) {
            return $this->emitObjectLiteral($node);
        }
        if ($node instanceof \Phasis\Ast\Expression\ArrayExpression) {
            $arrTemp = $this->newTemp('arr');
            $this->pendingStatements[] = $arrTemp . ' = new \\Phasis\\Value\\JsArray();';
            foreach ($node->elements as $el) {
                if ($el === null) {
                    // Hole: bail; spec hole semantics need a sparse
                    // JsArray, but our tracked locals stay dense.
                    throw new Bailout('array literal hole in object literal');
                }
                if ($el instanceof \Phasis\Ast\Expression\SpreadElement) {
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
        return '\\Phasis\\Value\\JsNumber::of((float)(' . $valueExpr . '))';
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

    private function emitLine(string $line): void
    {
        $this->out .= str_repeat('    ', $this->indentLevel) . $line . "\n";
    }
}
