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
use Phasis\Bytecode\Op;
use Phasis\Bytecode\CompilerBailout;

/**
 * Compiler trait part: CompilerExpression. Composed into Compiler via
 * `use Parts\CompilerExpression;`.
 */
trait CompilerExpression
{
    private function compileExpression(Node $node): void
    {
        if ($node instanceof Literal) {
            $this->compileLiteral($node);
            return;
        }
        if ($node instanceof Identifier) {
            $this->compileIdentifier($node);
            return;
        }
        if ($node instanceof BinaryExpression) {
            $this->compileBinary($node);
            return;
        }
        if ($node instanceof ConditionalExpression) {
            $this->compileConditional($node);
            return;
        }
        if ($node instanceof CallExpression) {
            $this->compileCall($node);
            return;
        }
        if ($node instanceof MemberExpression) {
            $this->compileMember($node);
            return;
        }
        if ($node instanceof AssignmentExpression) {
            $this->compileAssignment($node);
            return;
        }
        if ($node instanceof ThisExpression) {
            $this->emit(Op::LOAD_THIS);
            return;
        }
        if ($node instanceof UpdateExpression) {
            $this->compileUpdate($node);
            return;
        }
        if ($node instanceof UnaryExpression) {
            $this->compileUnary($node);
            return;
        }
        if ($node instanceof LogicalExpression) {
            $this->compileLogical($node);
            return;
        }
        if ($node instanceof SequenceExpression) {
            $count = count($node->expressions);
            for ($i = 0; $i < $count; $i++) {
                $this->compileExpression($node->expressions[$i]);
                if ($i !== $count - 1) {
                    $this->emit(Op::POP);
                }
            }
            return;
        }
        if ($node instanceof ObjectExpression) {
            $this->compileObjectLiteral($node);
            return;
        }
        if ($node instanceof ArrayExpression) {
            $this->compileArrayLiteral($node);
            return;
        }
        if ($node instanceof TemplateLiteral) {
            $this->compileTemplate($node);
            return;
        }
        if ($node instanceof NewExpression) {
            $this->compileNew($node);
            return;
        }
        if (
            $node instanceof \Phasis\Ast\Expression\ArrowFunction
            || $node instanceof \Phasis\Ast\Expression\FunctionExpression
        ) {
            $this->compileNestedFunction($node);
            return;
        }
        if ($node instanceof \Phasis\Ast\Expression\ClassExpression) {
            if ($this->capturesOuterLocal($node)) {
                throw new CompilerBailout('class expression captures outer local');
            }
            $idx = count($this->classNodes);
            $this->classNodes[] = $node;
            $this->emit(Op::MAKE_CLASS, $idx);
            return;
        }
        throw new CompilerBailout('unsupported expression: ' . $node->type());
    }

    private function compileObjectLiteral(ObjectExpression $node): void
    {
        $this->emit(Op::NEW_OBJECT);
        // Stack: [obj]
        foreach ($node->properties as $prop) {
            if ($prop instanceof SpreadElement) {
                throw new CompilerBailout('spread in object literal');
            }
            if (!$prop instanceof Property) {
                throw new CompilerBailout('object literal: ' . get_class($prop));
            }
            if ($prop->kind !== 'init') {
                throw new CompilerBailout('getter/setter literal');
            }
            if ($prop->method) {
                throw new CompilerBailout('method shorthand');
            }
            if ($prop->computed) {
                // [expr]: val — push key, push val, SET_COMPUTED.
                $this->compileExpression($prop->key);
                $this->compileExpression($prop->value);
                $this->emit(Op::SET_COMPUTED);
                continue;
            }
            // Static name (Identifier or string Literal).
            if ($prop->key instanceof Identifier) {
                $name = $prop->key->name;
            } elseif ($prop->key instanceof Literal) {
                if (!is_string($prop->key->value) && !is_int($prop->key->value) && !is_float($prop->key->value)) {
                    throw new CompilerBailout('non-string-or-numeric literal key');
                }
                $name = (string) $prop->key->value;
            } else {
                throw new CompilerBailout('non-identifier non-literal key');
            }
            // `__proto__: value` is the special prototype-set sugar
            // when shorthand=false (method=false has already bailed
            // above); the tree-walker handles it via setPrototype rather
            // than defining a property. Bail to keep spec semantics.
            if ($name === '__proto__' && !$prop->shorthand) {
                throw new CompilerBailout('__proto__ literal');
            }
            $this->compileExpression($prop->value);
            $this->emit(Op::SET_PROP, $this->internName($name));
        }
        // Stack: [obj] (unchanged net by SET_PROP / SET_COMPUTED).
    }

    private function compileArrayLiteral(ArrayExpression $node): void
    {
        // Use NEW_ARRAY <count> for dense literals with no holes /
        // spread. Sparse / spread arrays bail.
        $count = count($node->elements);
        $hasHoles = false;
        $hasSpread = false;
        foreach ($node->elements as $el) {
            if ($el === null) {
                $hasHoles = true;
            } elseif ($el instanceof SpreadElement) {
                $hasSpread = true;
            }
        }
        if ($hasHoles || $hasSpread) {
            throw new CompilerBailout('array literal with hole/spread');
        }
        foreach ($node->elements as $el) {
            $this->compileExpression($el);
        }
        $this->emit(Op::NEW_ARRAY, $count);
    }

    private function compileTemplate(TemplateLiteral $node): void
    {
        // Untagged template: alternate quasis and expressions, build a
        // string by concatenation. ToString happens via ADD's string
        // path when one operand is a string.
        $quasis = $node->quasis;
        $expressions = $node->expressions;
        $count = count($quasis);
        if ($count === 0) {
            $this->emit(Op::LOAD_CONST, $this->internConst(new JsString(''), 's:'));
            return;
        }
        for ($i = 0; $i < $count; $i++) {
            $part = $quasis[$i];
            $cookedRaw = $part->cookedValue;
            if ($cookedRaw === null) {
                throw new CompilerBailout('template element with invalid escape');
            }
            $idx = $this->internConst(new JsString($cookedRaw), 's:' . $cookedRaw);
            $this->emit(Op::LOAD_CONST, $idx);
            if ($i > 0) {
                // After the first quasi, concatenate with whatever
                // is already on the stack from the previous expr.
                $this->emit(Op::ADD);
            }
            if ($i < count($expressions)) {
                $this->compileExpression($expressions[$i]);
                // Per spec 13.2.8.6 SubstitutionTemplate evaluation:
                // each ${expr} is coerced via ToString directly (which
                // uses ToPrimitive("string") for objects), NOT via
                // `+`'s default hint. The distinction is observable
                // when @@toPrimitive is monkey-patched (e.g. Symbol
                // wrapper with @@toPrimitive=null should reach
                // Symbol.prototype.toString and yield "Symbol()"
                // rather than the default-hint path that surfaces a
                // raw JsSymbol primitive and throws on ToString).
                $this->emit(Op::TO_STRING);
                $this->emit(Op::ADD);
            }
        }
    }

    private function compileNew(NewExpression $node): void
    {
        foreach ($node->arguments as $arg) {
            if ($arg instanceof SpreadElement) {
                throw new CompilerBailout('spread in new');
            }
        }
        $this->compileExpression($node->callee);
        foreach ($node->arguments as $arg) {
            $this->compileExpression($arg);
        }
        $this->emit(Op::NEW_CALL, count($node->arguments));
    }

    /**
     * Compile `i++` / `i--` / `++i` / `--i` when the result is unused
     * (ExpressionStatement at top level, ForStatement::update slot) into
     * a single INC_LOCAL / DEC_LOCAL op. Returns true on success so the
     * caller can skip the standard compile-then-pop path.
     */
    private function tryEmitDiscardedUpdate(\Phasis\Ast\Node $expr): bool
    {
        if (!$expr instanceof UpdateExpression) {
            return false;
        }
        $arg = $expr->argument;
        if (!$arg instanceof Identifier) {
            return false;
        }
        $name = $arg->name;
        if ($name === 'eval' || $name === 'arguments' || $name === '[[NewTarget]]') {
            return false;
        }
        if (!isset($this->localSlots[$name])) {
            return false;
        }
        $slot = $this->localSlots[$name];
        if (isset($this->constSlots[$slot])) {
            return false;
        }
        $this->emit($expr->operator === '++' ? Op::INC_LOCAL : Op::DEC_LOCAL, $slot);
        return true;
    }

    private function compileUpdate(UpdateExpression $node): void
    {
        $arg = $node->argument;
        $isInc = $node->operator === '++';
        if ($arg instanceof Identifier) {
            $name = $arg->name;
            if ($name === 'eval' || $name === 'arguments') {
                throw new CompilerBailout('update eval/arguments');
            }
            if (isset($this->localSlots[$name])) {
                $slot = $this->localSlots[$name];
                if (isset($this->constSlots[$slot])) {
                    throw new CompilerBailout('update of const');
                }
                $this->emit(Op::LOAD_LOCAL, $slot);
                // Per §13.4 (UpdateExpression) and §6.1.6.1.10 NumericAdd:
                // both ++x and x++ start with ToNumber(oldValue). Op::ADD
                // on a JsString left operand is a string-concat, so emit
                // Op::POS first to coerce the loaded value to a numeric.
                // For postfix, we DUP after coercion so the expression
                // result is the ToNumber(oldValue) (e.g. y = x-- with x=true
                // returns 1, not JsBoolean). For prefix, the post-ADD/SUB
                // value is the result. Op::POS on JsNumber is a no-op so
                // the steady-state numeric path is unchanged. BigInt is
                // covered by the wider compileUpdate bailout above.
                $this->emit(Op::POS);
                if (!$node->prefix) {
                    $this->emit(Op::DUP);
                }
                $oneIdx = $this->internConst(JsNumber::of(1.0), 'n:1');
                $this->emit(Op::LOAD_CONST, $oneIdx);
                $this->emit($isInc ? Op::ADD : Op::SUB);
                if ($node->prefix) {
                    $this->emit(Op::DUP);
                }
                $this->emitStoreLocal($slot);
                return;
            }
            // Free variable: bail to tree-walker (Reference path is
            // spec-correct for Update on names that may resolve via
            // a with-environment etc.).
            throw new CompilerBailout('update free var');
        }
        // Member targets (`o.x++`, `o[k]++`) come in a later phase.
        throw new CompilerBailout('update non-identifier');
    }

    private function compileUnary(UnaryExpression $node): void
    {
        if (!$node->prefix) {
            throw new CompilerBailout('postfix unary');
        }
        $op = $node->operator;
        // `delete`, `typeof` on identifiers, `void` are easy enough,
        // but the spec edge cases (delete on with-bindings, typeof
        // on undeclared) are subtle. Bail to tree-walker for safety.
        if ($op === 'delete' || $op === 'typeof') {
            throw new CompilerBailout('delete/typeof');
        }
        if ($op === 'void') {
            $this->compileExpression($node->argument);
            $this->emit(Op::POP);
            $this->emit(Op::LOAD_UNDEF);
            return;
        }
        $this->compileExpression($node->argument);
        switch ($op) {
            case '-':
                $this->emit(Op::NEG);
                return;
            case '+':
                $this->emit(Op::POS);
                return;
            case '!':
                $this->emit(Op::NOT);
                return;
            case '~':
                $this->emit(Op::BNOT);
                return;
            default:
                throw new CompilerBailout('unary: ' . $op);
        }
    }

    private function compileLogical(LogicalExpression $node): void
    {
        // && and ||: short-circuit by peeking.
        if ($node->operator === '&&' || $node->operator === '||') {
            $this->compileExpression($node->left);
            $jmp = $node->operator === '&&'
                ? $this->emitJump(Op::JUMP_IF_FALSY_KEEP)
                : $this->emitJump(Op::JUMP_IF_TRUTHY_KEEP);
            $this->compileExpression($node->right);
            $this->patchJumpToHere($jmp);
            return;
        }
        if ($node->operator === '??') {
            // VM's JUMP_IF_NULLISH pops on jump and keeps on fall-
            // through, so the @nullish path starts with a clean stack
            // and can compile right directly.
            $this->compileExpression($node->left);
            $jmp = $this->emitJump(Op::JUMP_IF_NULLISH);
            // not nullish: left already on stack; skip right.
            $jmpEnd = $this->emitJump(Op::JUMP);
            $this->patchJumpToHere($jmp);
            $this->compileExpression($node->right);
            $this->patchJumpToHere($jmpEnd);
            return;
        }
        throw new CompilerBailout('logical: ' . $node->operator);
    }

    private function compileLiteral(Literal $node): void
    {
        $value = $node->value;
        if ($value === null) {
            $this->emit(Op::LOAD_NULL);
            return;
        }
        if (is_bool($value)) {
            $this->emit($value ? Op::LOAD_TRUE : Op::LOAD_FALSE);
            return;
        }
        if (is_int($value) || is_float($value)) {
            // Use a full-precision round-trippable string as the dedupe
            // key. Plain string concat ((string)$value) goes through
            // PHP's `precision` ini (default 14 digits), so a numerically
            // distinct float like 1.000000000000001 and a plain `1` would
            // both stringify to "1" and share a constant slot — making
            // `a[1] = -1.000000000000001` read back as -1 because the
            // dedupe handed the second literal the JsNumber stored for
            // the first. serialize() emits the IEEE 754 round-trip form.
            $idx = $this->internConst(
                JsNumber::of((float) $value),
                'n:' . serialize((float) $value),
            );
            $this->emit(Op::LOAD_CONST, $idx);
            return;
        }
        if (is_string($value)) {
            // RegExp literal: bail — fresh regex per evaluation, plus
            // BigInt literal sharing the string slot.
            if (str_starts_with($node->raw, '__REGEXP__')) {
                throw new CompilerBailout('regexp literal');
            }
            if (str_starts_with($node->raw, '__BIGINT__')) {
                throw new CompilerBailout('bigint literal');
            }
            $idx = $this->internConst(new JsString($value), 's:' . $value);
            $this->emit(Op::LOAD_CONST, $idx);
            return;
        }
        throw new CompilerBailout('unknown literal kind');
    }

    private function compileIdentifier(Identifier $node): void
    {
        $name = $node->name;
        if ($name === 'undefined') {
            $this->emit(Op::LOAD_UNDEF);
            return;
        }
        if (isset($this->localSlots[$name])) {
            $this->emit(Op::LOAD_LOCAL, $this->localSlots[$name]);
            return;
        }
        // Free variable — let the VM walk the env via the existing
        // (already cached) Environment::get path.
        $this->emit(Op::LOAD_NAME, $this->internName($name));
    }

    private function compileBinary(BinaryExpression $node): void
    {
        // Bail on operators that need special left-side handling (in,
        // instanceof, with-private-field) for now. They're not on the
        // hot path of fib / loop microbenchmarks.
        $op = $node->operator;
        $opcode = match ($op) {
            '+'   => Op::ADD,
            '-'   => Op::SUB,
            '*'   => Op::MUL,
            '/'   => Op::DIV,
            '%'   => Op::MOD,
            '**'  => Op::POW,
            '<'   => Op::LT,
            '>'   => Op::GT,
            '<='  => Op::LE,
            '>='  => Op::GE,
            '=='  => Op::EQ,
            '!='  => Op::NEQ,
            '===' => Op::SEQ,
            '!==' => Op::SNEQ,
            '&'   => Op::BAND,
            '|'   => Op::BOR,
            '^'   => Op::BXOR,
            '<<'  => Op::SHL,
            '>>'  => Op::SHR,
            '>>>' => Op::USHR,
            default => throw new CompilerBailout('binary operator: ' . $op),
        };
        $this->compileExpression($node->left);
        $this->compileExpression($node->right);
        $this->emit($opcode);
    }

    private function compileConditional(ConditionalExpression $node): void
    {
        $this->compileExpression($node->test);
        $jmpElse = $this->emitJump(Op::JUMP_IF_FALSE);
        $this->compileExpression($node->consequent);
        $jmpEnd = $this->emitJump(Op::JUMP);
        $this->patchJumpToHere($jmpElse);
        $this->compileExpression($node->alternate);
        $this->patchJumpToHere($jmpEnd);
    }

    private function compileCall(CallExpression $node): void
    {
        // No optional calls / direct eval / spread args yet.
        if ($node->optional) {
            throw new CompilerBailout('optional call');
        }
        if ($node->callee instanceof Identifier && $node->callee->name === 'eval') {
            throw new CompilerBailout('direct eval');
        }
        if ($node->callee instanceof Identifier && $node->callee->name === 'super') {
            throw new CompilerBailout('super call');
        }
        foreach ($node->arguments as $arg) {
            if ($arg instanceof SpreadElement) {
                throw new CompilerBailout('spread call argument');
            }
        }

        // Method call: `obj.m(...)` and `obj[k](...)`. Emit
        // CALL_METHOD with the receiver on the stack so the dispatcher
        // can use it as `this`.
        if (
            $node->callee instanceof MemberExpression
            && !$node->callee->optional
            && !($node->callee->property instanceof PrivateIdentifier)
            && !($node->callee->object instanceof Identifier && $node->callee->object->name === 'super')
        ) {
            if ($node->callee->computed) {
                throw new CompilerBailout('computed method call');
            }
            if (!($node->callee->property instanceof Identifier)) {
                throw new CompilerBailout('non-identifier method name');
            }
            // Spec evaluation order: look up the method (and surface
            // any TypeError on null/undefined receivers) BEFORE
            // evaluating any arguments. Stack layout:
            //   [..., obj, obj]                via DUP
            //   [..., obj, method]             via LOAD_MEMBER
            //   [..., obj, method, arg0..argN] after arg compile
            // CALL_METHOD argc pops method + args, peeks obj as
            // `this`, leaves result.
            $this->compileExpression($node->callee->object);
            $this->emit(Op::DUP);
            $this->emit(Op::LOAD_MEMBER, $this->internName($node->callee->property->name));
            foreach ($node->arguments as $arg) {
                $this->compileExpression($arg);
            }
            // Render the call-site source for the "is not a function"
            // error before emit so the PC we record actually points at
            // the CALL_METHOD opcode.
            $display = $this->renderMethodCallDisplay($node->callee);
            $callMethodPc = count($this->code);
            $this->emit(Op::CALL_METHOD, count($node->arguments));
            if ($display !== null) {
                $this->callMethodDisplays[$callMethodPc] = $display;
            }
            return;
        }

        // Direct function call: callee is anything except a member
        // expression (i.e. `f(...)` not `obj.m(...)`).
        $this->compileExpression($node->callee);
        foreach ($node->arguments as $arg) {
            $this->compileExpression($arg);
        }
        $this->emit(Op::CALL, count($node->arguments));
    }

    private function compileMember(MemberExpression $node): void
    {
        if ($node->optional) {
            throw new CompilerBailout('optional member');
        }
        if ($node->object instanceof Identifier && $node->object->name === 'super') {
            throw new CompilerBailout('super member access');
        }
        if ($node->property instanceof PrivateIdentifier) {
            throw new CompilerBailout('private field access');
        }
        if ($node->computed) {
            $this->compileExpression($node->object);
            $this->compileExpression($node->property);
            $this->emit(Op::LOAD_COMPUTED);
            return;
        }
        if (!($node->property instanceof Identifier)) {
            throw new CompilerBailout('non-identifier member property');
        }
        $this->compileExpression($node->object);
        $this->emit(Op::LOAD_MEMBER, $this->internName($node->property->name));
    }

    private function compileAssignment(AssignmentExpression $node): void
    {
        if ($node->leftParenthesized) {
            throw new CompilerBailout('parenthesized lhs');
        }
        $left = $node->left;
        $op = $node->operator;

        // Compound op (`+=`, `-=`, ...) gets lowered to:
        //   load lhs; compile rhs; <op>; <store-keep>
        // Logical compound (`&&=` / `||=` / `??=`) goes to the
        // tree-walker because of subtle short-circuit semantics
        // around with-environments.
        $binaryOpcode = match ($op) {
            '='   => null,
            '+='  => Op::ADD,
            '-='  => Op::SUB,
            '*='  => Op::MUL,
            '/='  => Op::DIV,
            '%='  => Op::MOD,
            '**=' => Op::POW,
            '&='  => Op::BAND,
            '|='  => Op::BOR,
            '^='  => Op::BXOR,
            '<<=' => Op::SHL,
            '>>=' => Op::SHR,
            '>>>='=> Op::USHR,
            default => throw new CompilerBailout('logical compound assignment'),
        };

        if ($left instanceof Identifier) {
            $name = $left->name;
            if ($name === 'eval' || $name === 'arguments') {
                throw new CompilerBailout('assign to eval/arguments');
            }
            $isLocal = isset($this->localSlots[$name]);

            if ($isLocal && isset($this->constSlots[$this->localSlots[$name]])) {
                throw new CompilerBailout('assignment to const');
            }

            // Strict-mode write to a non-local identifier: bail so the
            // tree-walker can pre-resolve the LHS reference per spec
            // (12.15.4 step 1: lref is evaluated BEFORE the RHS).
            // The VM's STORE_NAME runs after RHS, so a side-effecting
            // RHS that creates the global property would mask the
            // unresolvable LHS — strict mode must surface ReferenceError
            // (test262 language/identifier-resolution/
            // assign-to-global-undefined.js).
            if (!$isLocal && $this->programIsStrict) {
                throw new CompilerBailout('strict-mode non-local identifier assignment');
            }
            if ($op === '=') {
                $this->compileExpression($node->right);
                $this->emit(Op::DUP);
                if ($isLocal) {
                    $this->emitStoreLocal($this->localSlots[$name]);
                } else {
                    $this->emit(Op::STORE_NAME, $this->internName($name));
                }
                return;
            }
            // Compound on identifier:
            //   load lhs; compile rhs; <op>; DUP; STORE_*
            if ($isLocal) {
                $this->emit(Op::LOAD_LOCAL, $this->localSlots[$name]);
            } else {
                $this->emit(Op::LOAD_NAME, $this->internName($name));
            }
            $this->compileExpression($node->right);
            $this->emit($binaryOpcode);
            $this->emit(Op::DUP);
            if ($isLocal) {
                $this->emitStoreLocal($this->localSlots[$name]);
            } else {
                $this->emit(Op::STORE_NAME, $this->internName($name));
            }
            return;
        }

        if ($left instanceof MemberExpression) {
            if ($left->optional) {
                throw new CompilerBailout('optional assign');
            }
            if ($left->object instanceof Identifier && $left->object->name === 'super') {
                throw new CompilerBailout('super assign');
            }
            if ($left->property instanceof PrivateIdentifier) {
                throw new CompilerBailout('private assign');
            }
            if ($left->computed) {
                // Computed `obj[key] = rhs` (and compound `obj[key] op= rhs`).
                // Hot in code like `codePoints[length++] = codePoint`. Without
                // this path the entire enclosing function falls back to the
                // tree-walker, which adds a 5-10x dispatch overhead per
                // iteration. Compound forms only emit when no in-bytecode op
                // for the operator exists; logical compound (`&&=` etc.)
                // already bailed before this branch.
                if ($op === '=') {
                    // Stack: obj, key, val. STORE_COMPUTED writes and
                    // re-pushes val so the assignment expression yields rhs.
                    $this->compileExpression($left->object);
                    $this->compileExpression($left->property);
                    $this->compileExpression($node->right);
                    $this->emit(Op::STORE_COMPUTED);
                    return;
                }
                // Compound `obj[key] op= rhs`. Each side must be evaluated
                // once. The spec order is:
                //   1. Evaluate obj.
                //   2. Evaluate key (and ToPropertyKey).
                //   3. GetValue using obj+key.
                //   4. Evaluate rhs.
                //   5. ApplyOp.
                //   6. PutValue using obj+key.
                // We don't have a stack-shuffle op, so route through the
                // tree-walker for compound on a computed target. The plain
                // `=` form is the actually hot case.
                throw new CompilerBailout('compound computed assign');
            }
            if (!($left->property instanceof Identifier)) {
                throw new CompilerBailout('non-identifier assign target');
            }
            $nameIdx = $this->internName($left->property->name);

            if ($op === '=') {
                $this->compileExpression($left->object);
                $this->compileExpression($node->right);
                $this->emit(Op::STORE_MEMBER, $nameIdx);
                return;
            }
            // Compound on `obj.x`:
            //   compile obj; DUP; LOAD_MEMBER name; compile rhs;
            //   <op>; STORE_MEMBER name
            // Stack at STORE_MEMBER: [..., obj, newVal] → [..., newVal]
            $this->compileExpression($left->object);
            $this->emit(Op::DUP);
            $this->emit(Op::LOAD_MEMBER, $nameIdx);
            $this->compileExpression($node->right);
            $this->emit($binaryOpcode);
            $this->emit(Op::STORE_MEMBER, $nameIdx);
            return;
        }

        throw new CompilerBailout('unsupported assignment target');
    }
}
