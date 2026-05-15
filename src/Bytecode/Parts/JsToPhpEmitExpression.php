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
            if (isset($this->declaredLocals[$node->name])) {
                $kind = $this->localTypes[$node->name] ?? 'numeric';
                return $kind === 'numeric' ? 'numeric' : 'unknown';
            }
            // Free var: emitExpression unboxes to numeric (with a
            // runtime JsNumber assertion).
            return 'numeric';
        }
        if ($node instanceof CallExpression) {
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
            // Result of an assignment is the assigned value; most
            // assignments in our profile deal with numeric slots.
            return 'numeric';
        }
        if ($node instanceof \Phasis\Ast\Expression\MemberExpression) {
            // Member reads are unboxed numeric via the dataSlots path
            // (with runtime JsNumber assertion).
            return 'numeric';
        }
        return 'unknown';
    }

    /** @phpstan-impure */
    private function emitExpression(Node $node): string
    {
        if ($node instanceof Literal) {
            if (is_int($node->value) || is_float($node->value)) {
                // Emit numerical literals with a .0 suffix when they
                // would otherwise look like ints to PHP. JS's `===`
                // compares by value-only; PHP's `===` is type+value,
                // so `(float)0 !== (int)0` in PHP. Forcing the literal
                // through `(float)` keeps both sides float-typed and
                // matches JS Number semantics exactly.
                $f = (float) $node->value;
                if (is_finite($f) && $f === (float) (int) $f) {
                    return $f >= 0 ? sprintf('%d.0', (int) $f) : sprintf('-%d.0', -(int) $f);
                }
                return (string) $f;
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
                $this->pendingStatements[] = '$_curVer = \\Phasis\\Runtime\\Environment::'
                    . '$globalBindingsVersion;';
                $this->pendingStatements[] = 'if ($env !== ' . $envCacheVar
                    . ' || $_curVer !== ' . $verCacheVar . ') {';
                $this->pendingStatements[] = '    ' . $box
                    . ' = $env->get(' . var_export($name, true) . ');';
                $this->pendingStatements[] = '    if (!(' . $box
                    . ' instanceof \\Phasis\\Value\\JsNumber)) { throw new \\Phasis\\Bytecode\\Bailout("non-numeric freevar"); }';
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
                $val = $this->emitExpression($node->right);
                $temp = $this->newTemp('mw');
                $this->pendingStatements[] = $temp
                    . ' = \\Phasis\\Value\\JsNumber::of((float)(' . $val . '));';
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
                $val = $this->emitExpression($node->right);
                $idxLocal = $this->newTemp('ai');
                $valTemp = $this->newTemp('av');
                $this->pendingStatements[] = $idxLocal . ' = (int) ' . $idxExpr . ';';
                $this->pendingStatements[] = $valTemp
                    . ' = \\Phasis\\Value\\JsNumber::of((float)(' . $val . '));';
                $this->pendingStatements[] = 'if (!' . $recv
                    . '->isDenseMode()) { throw new \\Phasis\\Bytecode\\Bailout("array write on non-dense receiver"); }';
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
                && $node->right instanceof \Phasis\Ast\Expression\ObjectExpression
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
                $this->pendingStatements[] = $idxLocal . ' = (int) ' . $idxExpr . ';';
                $this->pendingStatements[] = 'if (!' . $recv
                    . '->isDenseMode()) { throw new \\Phasis\\Bytecode\\Bailout("array read on non-dense receiver"); }';
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
        // Method call shortcut: `arr.push(val)` on an array-typed
        // local goes straight to JsArray::push without resolving the
        // method through the prototype chain. Returns the new length
        // as a numeric raw double, matching the VM inline path.
        if (
            $node->callee instanceof \Phasis\Ast\Expression\MemberExpression
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
                && !($node->arguments[0] instanceof \Phasis\Ast\Expression\SpreadElement)
            ) {
                $argExpr = $this->emitExpression($node->arguments[0]);
                $recv = $this->slotVar($recvName);
                $valTemp = $this->newTemp('av');
                $this->pendingStatements[] = $valTemp
                    . ' = \\Phasis\\Value\\JsNumber::of(' . $argExpr . ');';
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
        // Same arg / callee resolution as emitCallCore but emitting
        // directly to numeric entry. Bail back (return null) if the
        // shape doesn't fit so the caller falls back to spec dispatch.
        $argRawExprs = [];
        foreach ($node->arguments as $arg) {
            if ($arg instanceof \Phasis\Ast\Expression\SpreadElement) {
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
                . ' instanceof \\Phasis\\Value\\JsFunction)) { throw new \\Phasis\\Bytecode\\Bailout("non-function callee"); }';
        }
        // Prefer phpCompiledNumeric. Fall back to phpCompiled with
        // JsNumber boxing of args + unboxing of result. If neither
        // exists, fall back to callFunction (and unbox).
        $boxedArgs = [];
        foreach ($argRawExprs as $expr) {
            $boxedArgs[] = '\\Phasis\\Value\\JsNumber::of(' . $expr . ')';
        }
        $boxedArgsArr = '[' . implode(', ', $boxedArgs) . ']';
        $this->pendingStatements[] = $resultTemp . ' = '
            . $calleeRef . '->phpCompiledNumeric !== null'
            . ' ? (' . $calleeRef . '->phpCompiledNumeric)('
            . $argsArr . ', \\Phasis\\Value\\JsUndefined::instance(), '
            . $calleeRef . '->closure, $interp, '
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
            . $boxedArgsArr . ', \\Phasis\\Value\\JsUndefined::instance(), '
            . $calleeRef . '->closure, $interp, '
            . $calleeRef . '->phpCompiledNodes)'
            . ' : $interp->callFunction(' . $calleeRef . ', '
            . '\\Phasis\\Value\\JsUndefined::instance(), ' . $boxedArgsArr . ');';
        $this->pendingStatements[] = '    if (!(' . $boxedResult
            . ' instanceof \\Phasis\\Value\\JsNumber)) { throw new \\Phasis\\Bytecode\\Bailout("non-numeric call result"); }';
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
        // MemberExpression callee — `obj.method(args)`. Receiver
        // must be a known object-typed local; method name must be
        // an identifier (non-computed). Emits a runtime lookup +
        // JsFunction guard + dispatch with the receiver passed as
        // `$thisValue`. Falls back to bailout if the resolved
        // method isn't BC- or JsToPhp-compiled (callFunction
        // handles the spec-correct path).
        if (
            $node->callee instanceof \Phasis\Ast\Expression\MemberExpression
            && !$node->callee->computed
            && $node->callee->object instanceof Identifier
            && $node->callee->property instanceof Identifier
        ) {
            return $this->emitMethodCallCore($node);
        }
        if (!$node->callee instanceof Identifier) {
            throw new Bailout('non-identifier callee');
        }
        // Emit each arg as a numeric raw expression and box on the way
        // in. Calls within args produce their own pending statements,
        // which run before this call's; emit order matches JS spec
        // left-to-right argument evaluation.
        $argRawExprs = [];
        foreach ($node->arguments as $arg) {
            if ($arg instanceof \Phasis\Ast\Expression\SpreadElement) {
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
            $boxedArgs[] = '\\Phasis\\Value\\JsNumber::of(' . $expr . ')';
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
                . ' instanceof \\Phasis\\Value\\JsFunction)) { throw new \\Phasis\\Bytecode\\Bailout("non-function callee"); }';
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
            . $argsArr . ', \\Phasis\\Value\\JsUndefined::instance(), '
            . $calleeRef . '->closure, $interp, '
            . $calleeRef . '->phpCompiledNodes)'
            . ' : $interp->callFunction(' . $calleeRef . ', '
            . '\\Phasis\\Value\\JsUndefined::instance(), ' . $argsArr . ');';
        return $resultTemp;
    }

    /**
     * Lower `new Ctor(args)` where Ctor is an Identifier (resolved
     * via env free-variable lookup; local-callee NewExpression
     * isn't supported yet). Boxes args as JsNumber and dispatches
     * to `$interp->vmNewExpression()`. Returns the temp PHP
     * variable holding the construct result (a JsValue, usually
     * JsObject — the caller bailouts on non-object).
     */
    private function emitNewExpression(\Phasis\Ast\Expression\NewExpression $node): string
    {
        if (!$node->callee instanceof Identifier) {
            throw new Bailout('new with non-identifier callee');
        }
        $argRawExprs = [];
        foreach ($node->arguments as $arg) {
            if ($arg instanceof \Phasis\Ast\Expression\SpreadElement) {
                throw new Bailout('spread arg in new');
            }
            $argRawExprs[] = $this->emitExpression($arg);
        }
        $ctorName = $node->callee->name;
        $ctorRef = '$_ctor_' . preg_replace('/[^A-Za-z0-9_]/', '_', $ctorName);
        if (!isset($this->freeVars['__ctor_' . $ctorName])) {
            $this->freeVars['__ctor_' . $ctorName] = true;
            $this->pendingStatements[] = $ctorRef
                . ' = $env->get(' . var_export($ctorName, true) . ');';
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
        $this->pendingStatements[] = $methodTemp . ' = '
            . $recv . '->get(' . var_export($propId->name, true) . ');';
        $this->pendingStatements[] = 'if (!(' . $methodTemp
            . ' instanceof \\Phasis\\Value\\JsFunction)) { '
            . 'throw new \\Phasis\\Bytecode\\Bailout("non-function method"); }';
        $argRawExprs = [];
        foreach ($node->arguments as $arg) {
            if ($arg instanceof \Phasis\Ast\Expression\SpreadElement) {
                throw new Bailout('spread arg in method call');
            }
            $argRawExprs[] = $this->emitExpression($arg);
        }
        $boxedArgs = [];
        foreach ($argRawExprs as $expr) {
            $boxedArgs[] = '\\Phasis\\Value\\JsNumber::of(' . $expr . ')';
        }
        $argsArr = '[' . implode(', ', $boxedArgs) . ']';
        $resultTemp = $this->newTemp('mr');
        $this->pendingStatements[] = $resultTemp . ' = '
            . $methodTemp . '->phpCompiled !== null'
            . ' ? (' . $methodTemp . '->phpCompiled)('
            . $argsArr . ', ' . $recv . ', '
            . $methodTemp . '->closure, $interp, '
            . $methodTemp . '->phpCompiledNodes)'
            . ' : $interp->callFunction(' . $methodTemp . ', '
            . $recv . ', ' . $argsArr . ');';
        return $resultTemp;
    }
}
