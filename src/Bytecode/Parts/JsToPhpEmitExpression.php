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
use Phasis\Bytecode\PhpNumericLiteral;

/**
 * JsToPhp trait part: JsToPhpEmitExpression. Composed into JsToPhp via
 * `use Parts\JsToPhpEmitExpression;`.
 */
trait JsToPhpEmitExpression
{
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
                case '+':
                case '-':
                case '*':
                case '/':
                case '%':
                case '**':
                case '&':
                case '|':
                case '^':
                case '<<':
                case '>>':
                case '>>>':
                    return 'numeric';
                case '<':
                case '>':
                case '<=':
                case '>=':
                case '==':
                case '!=':
                case '===':
                case '!==':
                    return 'boolean';
                default:
                    return 'unknown';
            }
        }
        if ($node instanceof UnaryExpression) {
            switch ($node->operator) {
                case '-':
                case '+':
                case '~':
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
            if (isset($this->scannerSubstitutions[$node->name])) {
                return 'numeric';
            }
            if (isset($this->declaredLocals[$node->name])) {
                $kind = $this->localTypes[$node->name] ?? 'numeric';
                return $kind === 'numeric' ? 'numeric' : ($kind === 'boolean' ? 'boolean' : 'unknown');
            }
            // Free var: emitExpression unboxes to numeric (with a
            // runtime JsNumber assertion).
            return 'numeric';
        }
        if ($node instanceof CallExpression) {
            $scannerType = $this->scannerCallType($node);
            if ($scannerType !== null) {
                return $scannerType;
            }
            // CallExpression results are not statically typeable — the
            // callee may return any JsValue, including JsUndefined. We
            // used to claim 'numeric' here, which let
            // `function fn() { return f(); }` enter the numeric
            // pipeline; on a non-numeric return PHP would throw a
            // Bailout and fall back to the standard interpreter, but
            // only AFTER the call had already run with full side
            // effects, leading to a second invocation. Marking as
            // unknown forces JsToPhp to bail at compile time so the
            // call only ever runs once.
            return 'unknown';
        }
        if ($node instanceof AssignmentExpression) {
            if ($node->operator === '=') {
                return $node->right instanceof CallExpression ? 'numeric' : $this->inferExpressionType($node->right);
            }
            return 'numeric';
        }
        if ($node instanceof \Phasis\Ast\Expression\MemberExpression) {
            // Member reads are unboxed numeric via the dataSlots path
            // (with runtime JsNumber assertion).
            return 'numeric';
        }
        return 'unknown';
    }

    private function scalarTruthiness(string $value, string $type): string
    {
        return match ($type) {
            'numeric' => '(' . $value . ' != 0.0 && !is_nan(' . $value . '))',
            'boolean' => $value,
            default => throw new Bailout('unknown scalar truthiness'),
        };
    }

    /** @phpstan-impure */
    private function emitScalarCondition(Node $node): string
    {
        $value = $this->emitExpression($node);
        $type = $this->inferExpressionType($node);
        if ($type === 'numeric') {
            $temporary = $this->newTemp('truthy');
            $this->pendingStatements[] = $temporary . ' = ' . $value . ';';
            $value = $temporary;
        }
        return $this->scalarTruthiness($value, $type);
    }

    /** @phpstan-impure */
    private function emitExpression(Node $node): string
    {
        if ($node instanceof Literal) {
            if (is_int($node->value) || is_float($node->value)) {
                return PhpNumericLiteral::render((float) $node->value);
            }
            if (is_bool($node->value)) {
                return $node->value ? 'true' : 'false';
            }
            throw new Bailout('non-numeric literal');
        }
        if ($node instanceof Identifier) {
            if (isset($this->scannerSubstitutions[$node->name])) {
                return $this->scannerSubstitutions[$node->name];
            }
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
                if ($kind !== 'numeric' && $kind !== 'boolean') {
                    throw new Bailout('non-numeric local in expression: ' . $node->name);
                }
                return $this->slotVar($node->name);
            }
            $this->recordRuntimeGuard();
            $name = $node->name;
            if (!isset($this->freeVars[$name])) {
                $this->freeVars[$name] = true;
                $safe = preg_replace('/[^A-Za-z0-9_]/', '_', $name);
                $box = '$_fvbox_' . $safe;
                $this->pendingStatements[] = $box . ' = $env->getDataBinding(' . var_export($name, true) . ');';
                $this->pendingStatements[] = 'if (!(' . $box
                    . ' instanceof \\Phasis\\Value\\JsNumber)) { throw new \\Phasis\\Bytecode\\Bailout("non-numeric freevar"); }';
                $this->pendingStatements[] = $this->freeVar($name) . ' = ' . $box . '->value;';
            }
            return $this->freeVar($name);
        }
        if ($node instanceof BinaryExpression) {
            $l = $this->emitExpression($node->left);
            if ($this->scannerPlan === null && $this->loopRegion === null) {
                $left = $this->newTemp('binary_left');
                $this->pendingStatements[] = $left . ' = ' . $l . ';';
                $l = $left;
            }
            $r = $this->emitExpression($node->right);
            if ($this->scannerPlan === null && $this->loopRegion === null) {
                $right = $this->newTemp('binary_right');
                $this->pendingStatements[] = $right . ' = ' . $r . ';';
                $r = $right;
            }
            switch ($node->operator) {
                case '+':
                case '-':
                case '*':
                case '<':
                case '>':
                case '<=':
                case '>=':
                    return '(' . $l . ' ' . $node->operator . ' ' . $r . ')';
                case '/':
                    // JS division-by-zero returns Infinity / -Infinity
                    // / NaN per IEEE-754. PHP's `/` operator throws
                    // DivisionByZeroError instead. fdiv() preserves the
                    // IEEE semantics that JS expects.
                    return 'fdiv((float)(' . $l . '), (float)(' . $r . '))';
                case '%':
                    // JS `%` is IEEE remainder (sign follows dividend,
                    // floats kept as floats). PHP `%` truncates to int
                    // and only works on ints. fmod gives the float
                    // semantics; fmod(x, 0) returns NaN matching JS.
                    return 'fmod((float)(' . $l . '), (float)(' . $r . '))';
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
            $truthy = $this->scalarTruthiness($temp, $this->inferExpressionType($node->left));
            if ($node->operator === '&&') {
                $this->pendingStatements[] = 'if (' . $truthy . ') {';
            } elseif ($node->operator === '||') {
                $this->pendingStatements[] = 'if (!(' . $truthy . ')) {';
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
            if ($node->operator === '!') {
                return '(!' . $this->emitScalarCondition($node->argument) . ')';
            }
            $arg = $this->emitExpression($node->argument);
            switch ($node->operator) {
                case '-':
                    return '(-' . $arg . ')';
                case '+':
                    return '(+' . $arg . ')';
                default:
                    throw new Bailout('unary ' . $node->operator);
            }
        }
        if ($node instanceof UpdateExpression) {
            return $this->emitLocalUpdate($node, false);
        }
        if ($node instanceof AssignmentExpression) {
            // obj.prop = value: lower to a direct dataSlots write on
            // the receiver's PHP local. Only supports identifier
            // receiver + identifier key (the obj-prop bench shape).
            if (
                $node->left instanceof \Phasis\Ast\Expression\MemberExpression
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
                if ($this->inferExpressionType($node->right) !== 'numeric') {
                    throw new Bailout('non-numeric private write');
                }
                $val = $this->emitExpression($node->right);
                $temp = $this->newTemp('mw');
                $this->recordRuntimeGuard();
                $this->pendingStatements[] = 'if (!(' . $recv . ' instanceof \\Phasis\\Value\\JsObject)'
                    . ' || get_class(' . $recv . ') !== \\Phasis\\Value\\JsObject::class'
                    . ' || !isset(' . $recv . '->properties->dataSlots[' . var_export($key, true) . '])) {'
                    . ' throw new \\Phasis\\Bytecode\\Bailout("member write requires an own data slot"); }';
                $this->pendingStatements[] = $temp
                    . ' = \\Phasis\\Value\\JsNumber::of((float)(' . $val . '));';
                $this->pendingStatements[] = $recv . '->properties->setDataSlot('
                    . var_export($key, true) . ', ' . $temp . ');';
                return $temp . '->value';
            }
            // Computed bracket-write: arr[i] = numericExpr on an array-
            // typed local. Lowers to a denseElements write via
            // setDenseElement. Bounds-checks at runtime via the dense
            // mode guard so a sparse-mutation pattern bails to spec.
            if (
                $node->left instanceof \Phasis\Ast\Expression\MemberExpression
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
                $index = $this->newTemp('array_index');
                $this->pendingStatements[] = $index . ' = ' . $idxExpr . ';';
                if ($this->inferExpressionType($node->right) !== 'numeric') {
                    throw new Bailout('non-numeric private write');
                }
                $val = $this->emitExpression($node->right);
                $idxLocal = $this->newTemp('ai');
                $valTemp = $this->newTemp('av');
                $this->pendingStatements[] = $valTemp
                    . ' = \\Phasis\\Value\\JsNumber::of((float)(' . $val . '));';
                $this->recordRuntimeGuard();
                $this->pendingStatements[] = 'if (!(' . $recv . ' instanceof \\Phasis\\Value\\JsArray)'
                    . ' || get_class(' . $recv . ') !== \\Phasis\\Value\\JsArray::class || !' . $recv . '->isDenseMode()'
                    . ' || !is_float(' . $index . ') || !is_finite(' . $index . ') || ' . $index . ' < 0 || floor(' . $index . ') !== ' . $index
                    . ' || ' . $index . ' >= ' . $recv . '->getLength()) {'
                    . ' throw new \\Phasis\\Bytecode\\Bailout("array write requires an own dense index"); }';
                $this->pendingStatements[] = $idxLocal . ' = (int) ' . $index . ';';
                $this->pendingStatements[] = 'if ((' . $recv . '->getDenseElements()[' . $idxLocal . '] ?? null) === null) {'
                    . ' throw new \\Phasis\\Bytecode\\Bailout("array write requires a present index"); }';
                $this->pendingStatements[] = $recv . '->setDenseElement('
                    . $idxLocal . ', ' . $valTemp . ');';
                return $valTemp . '->value';
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
                && $node->right instanceof \Phasis\Ast\Expression\ObjectExpression
                && ($this->localTypes[$name] ?? null) === 'object'
            ) {
                $temp = $this->emitObjectLiteral($node->right);
                $this->pendingStatements[] = $this->slotVar($name) . ' = ' . $temp . ';';
                return $temp;
            }
            // local = new Ctor(args): lift to vmNewExpression and
            // assign the resulting JsObject to the object-typed slot.
            // Mirrors the VariableDeclarator-with-NewExpression-init
            // path in emitStatement so a re-assignment in a loop
            // (`p = new Point(i, i+1)` after `let p;`) still benefits
            // from JsToPhp lowering instead of forcing a bailout.
            if (
                $node->operator === '='
                && $node->right instanceof \Phasis\Ast\Expression\NewExpression
                && ($this->localTypes[$name] ?? null) === 'object'
            ) {
                $newTemp = $this->emitNewExpression($node->right);
                $this->pendingStatements[] = $this->slotVar($name) . ' = ' . $newTemp . ';';
                return $newTemp;
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
            if (!in_array($kind ?? 'numeric', ['numeric', 'boolean'], true)) {
                throw new Bailout('assignment changes a non-scalar local type');
            }
            $slot = $this->slotVar($name);
            $val = $this->emitExpression($node->right);
            $expected = ($this->localTypes[$name] ?? 'numeric') === 'boolean' ? 'boolean' : 'numeric';
            if (
                $this->inferExpressionType($node->right) !== $expected
                && !($expected === 'numeric' && $node->right instanceof CallExpression)
            ) {
                throw new Bailout('assignment changes a scalar local type');
            }
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
            $test = $this->emitScalarCondition($node->test);
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
        if ($node instanceof \Phasis\Ast\Expression\ObjectExpression) {
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
        if ($node instanceof \Phasis\Ast\Expression\MemberExpression) {
            // Read of obj.prop or arr[i]. Receiver must be a known
            // object-typed local (dataSlots dereferenced, unbox to
            // numeric), an array-typed local with key 'length'
            // (returns the live length count as a numeric), an
            // array-typed local with a numeric computed key (dense
            // indexed read), or a string-typed local with .length.
            // ThisExpression receiver is allowed too: lowers to
            // `$thisValue->properties->dataSlots['key']` with the
            // same JsNumber unbox + Bailout guard as object-typed
            // locals. Enables `this.v + x` inside method bodies.
            if (
                $node->object instanceof \Phasis\Ast\Expression\ThisExpression
                && !$node->computed
                && $node->property instanceof Identifier
            ) {
                $key = $node->property->name;
                $temp = $this->newTemp('tmr');
                $this->recordRuntimeGuard();
                $this->pendingStatements[] = 'if (!($thisValue instanceof \\Phasis\\Value\\JsObject)) { '
                    . 'throw new \\Phasis\\Bytecode\\Bailout("this not object"); }';
                $this->pendingStatements[] = $temp . ' = $thisValue'
                    . '->properties->dataSlots[' . var_export($key, true) . '] ?? null;';
                $this->pendingStatements[] = 'if (!(' . $temp
                    . ' instanceof \\Phasis\\Value\\JsNumber)) { throw new \\Phasis\\Bytecode\\Bailout("non-numeric this-member"); }';
                return $temp . '->value';
            }
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
                $index = $this->newTemp('array_index');
                $this->pendingStatements[] = $index . ' = ' . $idxExpr . ';';
                $this->recordRuntimeGuard();
                $this->pendingStatements[] = 'if (!(' . $recv . ' instanceof \\Phasis\\Value\\JsArray)'
                    . ' || get_class(' . $recv . ') !== \\Phasis\\Value\\JsArray::class || !' . $recv . '->isDenseMode()'
                    . ' || !is_float(' . $index . ') || !is_finite(' . $index . ') || ' . $index . ' < 0 || floor(' . $index . ') !== ' . $index
                    . ' || ' . $index . ' >= ' . $recv . '->getLength()) {'
                    . ' throw new \\Phasis\\Bytecode\\Bailout("array read requires an own dense index"); }';
                $this->pendingStatements[] = $idxLocal . ' = (int) ' . $index . ';';
                $this->pendingStatements[] = $valLocal . ' = ' . $recv
                    . '->getDenseElements()[' . $idxLocal . '] ?? null;';
                $this->pendingStatements[] = 'if (!(' . $valLocal
                    . ' instanceof \\Phasis\\Value\\JsNumber)) { throw new \\Phasis\\Bytecode\\Bailout("non-numeric array element"); }';
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
            $this->recordRuntimeGuard();
            $this->pendingStatements[] = 'if (!(' . $recv . ' instanceof \\Phasis\\Value\\JsObject)) {'
                . ' throw new \\Phasis\\Bytecode\\Bailout("member receiver unavailable"); }';
            $this->pendingStatements[] = $temp . ' = ' . $recv
                . '->properties->dataSlots[' . var_export($key, true) . '] ?? null;';
            $this->pendingStatements[] = 'if (!(' . $temp
                . ' instanceof \\Phasis\\Value\\JsNumber)) { throw new \\Phasis\\Bytecode\\Bailout("non-numeric member read"); }';
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
        $scannerCall = $this->emitScannerCall($node);
        if ($scannerCall !== null) {
            return $scannerCall;
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
        $this->recordRuntimeGuard();
        // The numeric pipeline expects a raw double. The callee's
        // contract is `JsNumber` — anything else triggers a Bailout
        // so the VM / tree-walker fallback handles non-numeric cases.
        $this->pendingStatements[] = 'if (!(' . $resultTemp
            . ' instanceof \\Phasis\\Value\\JsNumber)) { throw new \\Phasis\\Bytecode\\Bailout("non-numeric call result"); }';
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
        $this->recordRuntimeGuard();
        $name = $node->callee->name;
        if (isset($this->declaredLocals[$name])) {
            if (($this->localTypes[$name] ?? null) !== 'function') {
                return null;
            }
            $callee = $this->slotVar($name);
        } else {
            $callee = $this->newTemp('numeric_callee');
            $this->pendingStatements[] = $callee . ' = $env->getDataBinding(' . var_export($name, true) . ');';
        }
        $this->pendingStatements[] = 'if (!(' . $callee . ' instanceof \\Phasis\\Value\\JsFunction)'
            . ' || ' . $callee . '->phpCompiledNumeric === null || !' . $this->emitSameRealmGuard($callee)
            . ' || $interp->hasExecutionBudget() || (\\Phasis\\Engine::getCurrentInterpreter()?->hasExecutionBudget() ?? false)) {'
            . ' throw new \\Phasis\\Bytecode\\Bailout("numeric callee unavailable"); }';
        $arguments = [];
        foreach ($node->arguments as $argument) {
            if ($argument instanceof \Phasis\Ast\Expression\SpreadElement) {
                throw new Bailout('spread numeric argument');
            }
            $arguments[] = $this->emitNumericArgument($argument);
        }
        $this->recordRuntimeGuard();
        $result = $this->newTemp('numeric_result');
        $this->pendingStatements[] = $result . ' = (' . $callee . '->phpCompiledNumeric)([' . implode(', ', $arguments)
            . '], \\Phasis\\Value\\JsUndefined::instance(), ' . $callee . '->closure, $interp, '
            . $callee . '->phpCompiledNodes);';
        $this->recordObservableEffect();
        return $result;
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
        $this->recordRuntimeGuard();
        $this->pendingStatements[] = 'if (!(' . $resultTemp
            . ' instanceof \\Phasis\\Value\\JsFunction)) { throw new \\Phasis\\Bytecode\\Bailout("non-function call result"); }';
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
        if (
            $node->callee instanceof \Phasis\Ast\Expression\MemberExpression && !$node->callee->computed
            && $node->callee->object instanceof Identifier && $node->callee->property instanceof Identifier
        ) {
            return $this->emitMethodCallCore($node);
        }
        if (!$node->callee instanceof Identifier) {
            throw new Bailout('non-identifier callee');
        }
        $name = $node->callee->name;
        if (isset($this->declaredLocals[$name])) {
            if (($this->localTypes[$name] ?? null) !== 'function') {
                throw new Bailout('non-function-typed local callee');
            }
            if (!isset($this->initializedLocalFunctions[$name])) {
                throw new Bailout('local call requires interpreter function hoists');
            }
            $callee = $this->slotVar($name);
        } else {
            $this->recordRuntimeGuard();
            $callee = $this->newTemp('callee');
            $this->pendingStatements[] = $callee . ' = $env->getDataBinding(' . var_export($name, true) . ');';
            $this->pendingStatements[] = 'if (' . $callee . ' === null) {'
                . ' throw new \\Phasis\\Bytecode\\Bailout("observable callee lookup"); }';
        }
        $arguments = [];
        foreach ($node->arguments as $argument) {
            if ($argument instanceof \Phasis\Ast\Expression\SpreadElement) {
                throw new Bailout('spread arg');
            }
            $arguments[] = '\\Phasis\\Value\\JsNumber::of(' . $this->emitNumericArgument($argument) . ')';
        }
        return $this->emitBoxedDispatch(
            $callee,
            '\\Phasis\\Value\\JsUndefined::instance()',
            '[' . implode(', ', $arguments) . ']',
            $name,
        );
    }

    private function emitLocalUpdate(UpdateExpression $node, bool $discard): string
    {
        if (!$node->argument instanceof Identifier || !isset($this->declaredLocals[$node->argument->name])) {
            throw new Bailout('update on non-local identifier');
        }
        if (($this->localTypes[$node->argument->name] ?? 'numeric') !== 'numeric') {
            throw new Bailout('update on a non-numeric local');
        }
        $slot = $this->slotVar($node->argument->name);
        $operation = $slot . ($node->operator === '++' ? ' += 1.0' : ' -= 1.0');
        if ($node->prefix || $discard) {
            return '(' . $operation . ')';
        }
        $previous = $this->newTemp('postfix');
        $this->pendingStatements[] = $previous . ' = ' . $slot . ';';
        $this->pendingStatements[] = $operation . ';';
        return $previous;
    }

    private function emitNumericArgument(Node $argument): string
    {
        if ($this->inferExpressionType($argument) !== 'numeric') {
            throw new Bailout('non-numeric call argument');
        }
        $value = $this->emitExpression($argument);
        $temporary = $this->newTemp('argument');
        $this->pendingStatements[] = $temporary . ' = ' . $value . ';';
        return $temporary;
    }

    private function emitBoxedDispatch(string $callee, string $receiver, string $arguments, string $display): string
    {
        $result = $this->newTemp('call_result');
        $packed = $this->newTemp('call_arguments');
        $this->pendingStatements[] = $packed . ' = ' . $arguments . ';';
        $arguments = $packed;
        $fallback = '$interp->vmCallValue(' . $callee . ', ' . $receiver . ', ' . $arguments
            . ', ' . var_export($display, true) . ')';
        $this->pendingStatements[] = 'try {';
        $this->pendingStatements[] = '    ' . $result . ' = ' . $callee . ' instanceof \\Phasis\\Value\\JsFunction'
            . ' && ' . $callee . '->phpCompiled !== null && ' . $this->emitSameRealmGuard($callee)
            . ' && !$interp->hasExecutionBudget()'
            . ' && !(\\Phasis\\Engine::getCurrentInterpreter()?->hasExecutionBudget() ?? false)'
            . ' ? (' . $callee . '->phpCompiled)(' . $arguments . ', ' . $receiver . ', '
            . $callee . '->closure, $interp, ' . $callee . '->phpCompiledNodes) : ' . $fallback . ';';
        $this->pendingStatements[] = '} catch (\\Phasis\\Bytecode\\Bailout) {';
        $this->pendingStatements[] = '    ' . $result . ' = ' . $fallback . ';';
        $this->pendingStatements[] = '}';
        $this->recordObservableEffect();
        return $result;
    }

    /** Direct PHP dispatch cannot establish a foreign function's execution realm. */
    private function emitSameRealmGuard(string $callee): string
    {
        return '(' . $callee . '->realm === null || ' . $callee
            . '->realm === (\\Phasis\\Engine::getCurrentInterpreter() ?? $interp)->getRealm())';
    }

    /**
     * Lower `new Ctor(args)` where Ctor is an Identifier resolved
     * either as a function-typed local (from the same compile frame)
     * or via env free-variable lookup. Boxes args as JsNumber and
     * dispatches to `$interp->vmNewExpression()`. Returns the temp
     * PHP variable holding the construct result (a JsValue, usually
     * JsObject — the caller bailouts on non-object).
     */
    private function emitNewExpression(\Phasis\Ast\Expression\NewExpression $node): string
    {
        $this->recordObservableEffect();
        if (!$node->callee instanceof Identifier) {
            throw new Bailout('new with non-identifier callee');
        }
        $ctorName = $node->callee->name;
        // Prefer the function-typed local slot when the constructor was
        // declared in this compile frame (e.g. a nested
        // `function Inner(...)` factory). The slot read is one PHP local
        // dereference vs. an env->get walk.
        if (
            isset($this->declaredLocals[$ctorName])
            && ($this->localTypes[$ctorName] ?? null) === 'function'
        ) {
            if (!isset($this->initializedLocalFunctions[$ctorName])) {
                throw new Bailout('local constructor requires interpreter function hoists');
            }
            $ctorRef = $this->slotVar($ctorName);
        } else {
            $ctorRef = '$_ctor_' . preg_replace('/[^A-Za-z0-9_]/', '_', $ctorName);
            $this->pendingStatements[] = $ctorRef
                . ' = $env->get(' . var_export($ctorName, true) . ');';
        }
        $argRawExprs = [];
        foreach ($node->arguments as $arg) {
            if ($arg instanceof \Phasis\Ast\Expression\SpreadElement) {
                throw new Bailout('spread arg in new');
            }
            $argRawExprs[] = $this->emitNumericArgument($arg);
        }
        $boxedArgs = [];
        foreach ($argRawExprs as $expr) {
            $boxedArgs[] = '\\Phasis\\Value\\JsNumber::of(' . $expr . ')';
        }
        $argsArr = '[' . implode(', ', $boxedArgs) . ']';
        $resultTemp = $this->newTemp('nw');
        $this->pendingStatements[] = $resultTemp
            . ' = $interp->vmNewExpression(' . $ctorRef . ', ' . $argsArr . ', $env);';
        return $resultTemp;
    }

    /**
     * Lower `obj.method(args)` where obj is an object-typed local
     * and `method` is a plain identifier (no computed access). Emits:
     *
     *   $recv = ...;       // object-typed slot of obj
     *   $method = $recv->get('name');
     *   if (!($method instanceof JsFunction)) bail;
     *   $args = [boxed args];
     *   $r = $method->phpCompiled !== null
     *      ? ($method->phpCompiled)($args, $recv, $method->closure, $interp, $method->phpCompiledNodes)
     *      : $interp->callFunction($method, $recv, $args);
     *
     * Receiver passes as `$thisValue` so the called body's
     * `this.member` reads resolve correctly via JsToPhp's
     * ThisExpression emit. Result is the boxed JsValue; the
     * caller can unbox or assert type via emitCall's wrapper.
     */
    private function emitMethodCallCore(CallExpression $node): string
    {
        /** @var \Phasis\Ast\Expression\MemberExpression $callee */
        $callee = $node->callee;
        /** @var Identifier $recvId */
        $recvId = $callee->object;
        /** @var Identifier $propId */
        $propId = $callee->property;
        $recvName = $recvId->name;
        if (!isset($this->declaredLocals[$recvName])) {
            throw new Bailout('method call on non-local receiver');
        }
        $recvKind = $this->localTypes[$recvName] ?? 'numeric';
        if ($recvKind !== 'object') {
            throw new Bailout('method call on non-object local: ' . $recvName);
        }
        // Eval args before resolving method per spec — except JS spec
        // actually says: callee is evaluated first, THEN args. But for
        // `obj.method(a,b)` the order is: obj → method-lookup → a → b.
        // We do the same: emit receiver+lookup as pendings first,
        // then arg expressions in source order.
        $recv = $this->slotVar($recvName);
        $methodTemp = $this->newTemp('mc');
        $propLit = var_export($propId->name, true);
        $this->recordRuntimeGuard();
        $this->pendingStatements[] = 'if (!(' . $recv . ' instanceof \\Phasis\\Value\\JsObject)) {'
            . ' throw new \\Phasis\\Bytecode\\Bailout("method receiver unavailable"); }';
        $this->recordObservableEffect();
        $this->pendingStatements[] = $methodTemp . ' = ' . $recv . '->get(' . $propLit . ');';
        $argRawExprs = [];
        foreach ($node->arguments as $arg) {
            if ($arg instanceof \Phasis\Ast\Expression\SpreadElement) {
                throw new Bailout('spread arg in method call');
            }
            $argRawExprs[] = $this->emitNumericArgument($arg);
        }
        $boxedArgs = [];
        foreach ($argRawExprs as $expr) {
            $boxedArgs[] = '\\Phasis\\Value\\JsNumber::of(' . $expr . ')';
        }
        $argsArr = '[' . implode(', ', $boxedArgs) . ']';
        return $this->emitBoxedDispatch($methodTemp, $recv, $argsArr, $recvName . '.' . $propId->name);
    }
}
