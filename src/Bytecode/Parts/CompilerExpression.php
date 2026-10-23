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
            // Ordinary (non-arrow, non-generator) function bodies read
            // `this` straight from the frame: the receiver is fixed for
            // the whole call and every dispatch path populates
            // Frame->thisValue with the coerced value. Arrows (lexical
            // this), generators (snapshot resume), and program bodies
            // keep the env-chain walk.
            $this->emit($this->thisFromFrame ? Op::LOAD_THIS_FRAME : Op::LOAD_THIS);
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
        if ($node instanceof \Phasis\Ast\Expression\YieldExpression) {
            $this->compileYieldExpression($node);
            return;
        }
        throw new CompilerBailout('unsupported expression: ' . $node->type());
    }

    /**
     * Emit `Op::YIELD` for a non-delegate `yield <expr>` or bare
     * `yield`. The argument (or undefined) is pushed first, then
     * YIELD pops it and returns the snapshot. On resume the
     * dispatch loop pushes the resume value onto the stack, so
     * the yield expression's result lives at top-of-stack for the
     * next opcode to consume.
     *
     * Only reachable when the enclosing function is a generator
     * that passed `generatorBodyIsSnapshotSafe()`; otherwise the
     * compiler bailed at `scanBailout`.
     */
    private function compileYieldExpression(\Phasis\Ast\Expression\YieldExpression $node): void
    {
        if (!$this->inGenerator) {
            throw new CompilerBailout('yield outside generator');
        }
        if ($node->delegate) {
            // generatorBodyIsSnapshotSafe should already have
            // bailed; defensive guard for direct calls.
            throw new CompilerBailout('yield* delegation');
        }
        if ($node->argument !== null) {
            $this->compileExpression($node->argument);
        } else {
            $this->emit(Op::LOAD_UNDEF);
        }
        $this->emit(Op::YIELD);
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
                if (self::initNeedsNamedEvaluation($prop->value)) {
                    throw new CompilerBailout('object property needs named evaluation');
                }
                // Coercion of the key must finish before evaluating the value.
                $this->compileExpression($prop->key);
                $this->emit(Op::TO_PROPERTY_KEY);
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
            if ($name === '__proto__' && !$prop->shorthand) {
                $this->compileExpression($prop->value);
                $this->emit(Op::SET_LITERAL_PROTOTYPE);
                continue;
            }
            // Prototype initializers do not perform property name inference.
            if (self::initNeedsNamedEvaluation($prop->value)) {
                throw new CompilerBailout('object property needs named evaluation');
            }
            $this->compileExpression($prop->value);
            $this->emit(Op::SET_PROP, $this->internName($name));
        }
        // Stack: [obj] (unchanged net by SET_PROP / SET_COMPUTED).
    }

    private function compileArrayLiteral(ArrayExpression $node): void
    {
        // Dense literals can pack their values in one instruction. Spread
        // operands must finish iteration before evaluating the next element.
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
            $this->emit(Op::NEW_ARRAY, 0);
            foreach ($node->elements as $element) {
                if ($element === null) {
                    $this->emit(Op::ARRAY_HOLE);
                } elseif ($element instanceof SpreadElement) {
                    $this->compileExpression($element->argument);
                    $this->emit(Op::ARRAY_SPREAD);
                } else {
                    $this->compileExpression($element);
                    $this->emit(Op::ARRAY_APPEND);
                }
            }
            return;
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
        $this->compileExpression($node->callee);
        $this->emit(Op::NEW_CALL, $this->compileArguments($node->arguments));
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
        if (!isset($this->localSlots[$name]) || isset($this->capturedLocals[$name])) {
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
            if (isset($this->localSlots[$name]) && !isset($this->capturedLocals[$name])) {
                $slot = $this->localSlots[$name];
                if (isset($this->constSlots[$slot])) {
                    throw new CompilerBailout('update of const');
                }
                $this->emitLoadLocal($slot);
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
            // Keep reference resolution and coercion in one instruction so
            // getters or ToNumeric cannot redirect the eventual write.
            $this->emit(Op::UPDATE_NAME, $this->internName($name), (int) $isInc, (int) $node->prefix);
            return;
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
        if ($op === 'typeof') {
            if ($node->argument instanceof Identifier && !isset($this->localSlots[$node->argument->name])) {
                $this->emit(Op::TYPEOF_NAME, $this->internName($node->argument->name));
            } else {
                $this->compileExpression($node->argument);
                $this->emit(Op::TYPEOF);
            }
            return;
        }
        if ($op === 'delete') {
            $this->compileDelete($node->argument);
            return;
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

    private function compileDelete(Node $argument): void
    {
        if ($argument instanceof Identifier) {
            if ($argument->name === '[[NewTarget]]' || $argument->name === '[[ImportMeta]]') {
                $this->emit(Op::LOAD_TRUE);
            } elseif (isset($this->localSlots[$argument->name])) {
                // Deleting a declarative binding does not read its value or TDZ.
                $this->emit(Op::LOAD_FALSE);
            } else {
                $this->emit(Op::DELETE_NAME, $this->internName($argument->name));
            }
            return;
        }
        if ($argument instanceof MemberExpression) {
            if (
                $argument->optional || $argument->property instanceof PrivateIdentifier
                || ($argument->object instanceof Identifier && $argument->object->name === 'super')
            ) {
                throw new CompilerBailout('optional/private/super delete reference');
            }
            $this->compileExpression($argument->object);
            if ($argument->computed) {
                $this->compileExpression($argument->property);
            } elseif ($argument->property instanceof Identifier) {
                $this->emit(Op::LOAD_CONST, $this->internConst(
                    new JsString($argument->property->name),
                    's:' . $argument->property->name,
                ));
            } else {
                throw new CompilerBailout('non-identifier delete property');
            }
            $this->emit(Op::DELETE_PROPERTY);
            return;
        }
        $this->compileExpression($argument);
        $this->emit(Op::POP);
        $this->emit(Op::LOAD_TRUE);
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
            if (str_starts_with($node->raw, '__REGEXP__')) {
                if (!preg_match('#^/(.+)/([dgimsuvy]*)$#s', $value, $parts)) {
                    throw new CompilerBailout('invalid regexp literal');
                }
                $this->emit(Op::NEW_REGEXP, $this->internName($parts[1]), $this->internName($parts[2]));
                return;
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
        if (isset($this->localSlots[$name])) {
            $this->emitLoadLocal($this->localSlots[$name]);
            return;
        }
        // Free variable — let the VM walk the env via the existing
        // (already cached) Environment::get path.
        $this->emit(Op::LOAD_NAME, $this->internName($name));
    }

    private function compileBinary(BinaryExpression $node): void
    {
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
            'in' => Op::IN,
            'instanceof' => Op::INSTANCEOF,
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
        if ($this->chainHasOptionalLink($node)) {
            $this->compileOptionalChain($node);
            return;
        }
        if ($node->callee instanceof Identifier && $node->callee->name === 'eval') {
            throw new CompilerBailout('direct eval');
        }
        if ($node->callee instanceof Identifier && $node->callee->name === 'super') {
            throw new CompilerBailout('super call');
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
            if ($node->callee->computed) {
                $this->compileExpression($node->callee->property);
                $this->emit(Op::LOAD_COMPUTED);
            } elseif ($node->callee->property instanceof Identifier) {
                $this->emit(Op::LOAD_MEMBER, $this->internName($node->callee->property->name));
            } else {
                throw new CompilerBailout('non-identifier method name');
            }
            $argc = $this->compileArguments($node->arguments);
            // Render the call-site source for the "is not a function"
            // error before emit so the PC we record actually points at
            // the CALL_METHOD opcode.
            $display = $this->renderMethodCallDisplay($node->callee);
            $callMethodPc = count($this->code);
            $this->emit(Op::CALL_METHOD, $argc);
            if ($display !== null) {
                $this->callMethodDisplays[$callMethodPc] = $display;
            }
            return;
        }

        // Direct function call: callee is anything except a member
        // expression (i.e. `f(...)` not `obj.m(...)`).
        $this->compileExpression($node->callee);
        $this->emit(Op::CALL, $this->compileArguments($node->arguments));
    }

    /**
     * Return the fixed argument count, or -1 for an internal dense argument
     * array. The array is never exposed to JavaScript; spread consumes each
     * iterator before evaluating the next argument.
     *
     * @param list<Node> $arguments
     */
    private function compileArguments(array $arguments): int
    {
        $hasSpread = false;
        foreach ($arguments as $argument) {
            $hasSpread = $hasSpread || $argument instanceof SpreadElement;
        }
        if ($hasSpread) {
            $this->emit(Op::NEW_ARRAY, 0);
        }
        foreach ($arguments as $argument) {
            if ($argument instanceof SpreadElement) {
                $this->compileExpression($argument->argument);
                $this->emit(Op::ARRAY_SPREAD);
            } else {
                $this->compileExpression($argument);
                if ($hasSpread) {
                    $this->emit(Op::ARRAY_APPEND);
                }
            }
        }
        return $hasSpread ? -1 : count($arguments);
    }

    private function compileMember(MemberExpression $node): void
    {
        if ($this->chainHasOptionalLink($node)) {
            $this->compileOptionalChain($node);
            return;
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

    /** Parentheses affect propagation, but still require reference-preserving lowering. */
    private function chainHasOptionalLink(MemberExpression|CallExpression $node): bool
    {
        while ($node instanceof MemberExpression || $node instanceof CallExpression) {
            if ($node->optional) {
                return true;
            }
            $node = $node instanceof MemberExpression ? $node->object : $node->callee;
        }
        return false;
    }

    /**
     * Nullish branches leave the whole chain at once. No sentinel can escape
     * into bindings or comparisons, and a grouped method retains its receiver.
     */
    private function compileOptionalChain(
        MemberExpression|CallExpression $node,
        bool $reference = false,
        bool $tail = false,
    ): void {
        $shortCircuits = [];
        $this->compileChainLink($node, $shortCircuits, $reference, $tail);
        if ($shortCircuits === []) {
            return;
        }
        $ends = [$this->emitJump(Op::JUMP)];
        foreach ($shortCircuits as $branch) {
            $this->patchJumpToHere($branch['jump']);
            for ($discard = 0; $discard < $branch['discard']; $discard++) {
                $this->emit(Op::POP);
            }
            if ($reference) {
                $this->emit(Op::LOAD_UNDEF);
            }
            $this->emit(Op::LOAD_UNDEF);
            $ends[] = $this->emitJump(Op::JUMP);
        }
        foreach ($ends as $end) {
            $this->patchJumpToHere($end);
        }
    }

    /** @param list<array{jump: int, discard: int}> $shortCircuits */
    private function compileChainOperand(Node $node, array &$shortCircuits, bool $reference = false): void
    {
        if ($node instanceof MemberExpression || $node instanceof CallExpression) {
            if ($node->parenthesized) {
                // `(a?.b).c` must read `.c` from the completed undefined value.
                // `(a?.b)()` additionally keeps the successful method receiver.
                $this->compileOptionalChain($node, $reference);
            } else {
                $this->compileChainLink($node, $shortCircuits, $reference);
            }
            return;
        }
        $this->compileExpression($node);
    }

    /** @param list<array{jump: int, discard: int}> $shortCircuits */
    private function compileChainLink(
        MemberExpression|CallExpression $node,
        array &$shortCircuits,
        bool $reference = false,
        bool $tail = false,
    ): void {
        if ($node instanceof MemberExpression) {
            if (
                $node->property instanceof PrivateIdentifier
                || ($node->object instanceof Identifier && $node->object->name === 'super')
            ) {
                throw new CompilerBailout('private/super optional chain');
            }
            $this->compileChainOperand($node->object, $shortCircuits);
            if ($node->optional) {
                $shortCircuits[] = ['jump' => $this->emitJump(Op::JUMP_IF_NULLISH), 'discard' => 0];
            }
            if ($reference) {
                $this->emit(Op::DUP);
            }
            if ($node->computed) {
                $this->compileExpression($node->property);
                $this->emit(Op::LOAD_COMPUTED);
            } elseif ($node->property instanceof Identifier) {
                $this->emit(Op::LOAD_MEMBER, $this->internName($node->property->name));
            } else {
                throw new CompilerBailout('non-identifier optional member');
            }
            return;
        }
        if ($node->callee instanceof Identifier && ($node->callee->name === 'eval' || $node->callee->name === 'super')) {
            throw new CompilerBailout('eval/super chain call');
        }
        $method = $node->callee instanceof MemberExpression;
        $this->compileChainOperand($node->callee, $shortCircuits, $method);
        if ($node->optional) {
            $shortCircuits[] = ['jump' => $this->emitJump(Op::JUMP_IF_NULLISH), 'discard' => $method ? 1 : 0];
        }
        $argc = $this->compileArguments($node->arguments);
        $pc = count($this->code);
        $this->emit($tail
            ? ($method ? Op::TAIL_CALL_METHOD : Op::TAIL_CALL)
            : ($method ? Op::CALL_METHOD : Op::CALL), $argc);
        if ($tail) {
            $this->hasTailCalls = true;
        }
        if ($node->callee instanceof MemberExpression) {
            $display = $this->renderMethodCallDisplay($node->callee);
            if ($display !== null) {
                $this->callMethodDisplays[$pc] = $display;
            }
        }
    }

    private function compileAssignment(AssignmentExpression $node): void
    {
        if ($node->leftParenthesized) {
            throw new CompilerBailout('parenthesized lhs');
        }
        $left = $node->left;
        $op = $node->operator;
        if ($op === '=' && ($left instanceof ArrayPattern || $left instanceof ArrayExpression)) {
            $this->compileArrayAssignment($left, $node->right);
            return;
        }
        if ($op === '&&=' || $op === '||=' || $op === '??=') {
            $this->compileLogicalAssignment($node);
            return;
        }

        // Compound op (`+=`, `-=`, ...) gets lowered to:
        //   load lhs; compile rhs; <op>; <store-keep>
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

            // The program environment owns its global bindings. Keep the
            // complete assignment on the interpreter so a RHS that creates
            // a missing global cannot change its already resolved reference.
            if (!$isLocal && $this->programIsStrict) {
                if ($op !== '=') {
                    throw new CompilerBailout('strict-mode non-local compound assignment');
                }
                $this->checkInterpretedExpressionBindings($node);
                $index = count($this->interpretedExpressions);
                $this->interpretedExpressions[] = $node;
                $this->emit(Op::EVAL_EXPRESSION, $index);
                return;
            }
            if ($op === '=') {
                if (self::initNeedsNamedEvaluation($node->right)) {
                    throw new CompilerBailout('identifier assignment needs named evaluation');
                }
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
                $this->emitLoadLocal($this->localSlots[$name]);
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

    /** AST evaluation cannot observe values stored only in compiled local slots. */
    private function checkInterpretedExpressionBindings(Node $node): void
    {
        if (
            $node instanceof Identifier && isset($this->localSlots[$node->name])
            && !isset($this->capturedLocals[$node->name])
        ) {
            throw new CompilerBailout('interpreted expression depends on a local slot');
        }
        if ($node instanceof ThisExpression) {
            $this->hasLoadThis = true;
        }
        foreach (get_object_vars($node) as $value) {
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node) {
                    $this->checkInterpretedExpressionBindings($child);
                }
            }
        }
    }

    /** Keep each reference and write beside its iterator step, including abrupt closing. */
    private function compileArrayAssignment(ArrayPattern|ArrayExpression $pattern, Node $right): void
    {
        $elements = [];
        foreach ($pattern->elements as $element) {
            if ($element === null) {
                $elements[] = null;
                continue;
            }
            $rest = $element instanceof RestElement || $element instanceof SpreadElement;
            $target = $rest ? $element->argument : $element;
            if ($target instanceof Identifier) {
                if ($target->name === 'eval' || $target->name === 'arguments') {
                    throw new CompilerBailout('array assignment to eval/arguments');
                }
                $slot = $this->localSlots[$target->name] ?? null;
                if ($slot !== null) {
                    if (isset($this->constSlots[$slot])) {
                        throw new CompilerBailout('array assignment to const');
                    }
                    $elements[] = [
                        'slot' => $slot,
                        'capturedName' => isset($this->capturedLocals[$target->name]) ? $target->name : null,
                        'rest' => $rest,
                    ];
                    continue;
                }
            } elseif (
                !$target instanceof MemberExpression || $target->optional
                || $target->property instanceof PrivateIdentifier
                || ($target->object instanceof Identifier && $target->object->name === 'super')
            ) {
                throw new CompilerBailout('complex array assignment target');
            }
            // Member references can invoke arbitrary getters and computed-key
            // expressions. Their local dependencies use captured bindings, so
            // the shared reference evaluator observes the live activation.
            if ($this->inGenerator) {
                throw new CompilerBailout('generator array assignment reference');
            }
            $elements[] = ['target' => $target, 'rest' => $rest];
            $this->arrayAssignmentReferences = true;
        }
        $this->compileExpression($right);
        $this->emit(Op::DUP);
        $index = count($this->arrayBindings);
        $this->arrayBindings[] = ['kind' => 'assignment', 'elements' => $elements];
        $this->emit(Op::BIND_ARRAY, $index);
    }

    private function compileLogicalAssignment(AssignmentExpression $node): void
    {
        $left = $node->left;
        $receiverSlot = null;
        $nameIndex = null;
        if ($left instanceof Identifier) {
            $slot = $this->localSlots[$left->name] ?? null;
            if ($slot === null || isset($this->constSlots[$slot]) || $left->name === 'arguments') {
                throw new CompilerBailout('logical assignment requires a mutable local binding');
            }
            if (self::initNeedsNamedEvaluation($node->right)) {
                throw new CompilerBailout('logical assignment needs named evaluation');
            }
            $this->emitLoadLocal($slot);
        } elseif (
            $left instanceof MemberExpression && !$left->optional && !$left->computed
            && $left->property instanceof Identifier
            && !($left->object instanceof Identifier && $left->object->name === 'super')
        ) {
            // Save the receiver before its getter or RHS can change the
            // source binding. Each reference and getter is evaluated once.
            $receiverSlot = count($this->localNames);
            $this->localNames[] = '[[logicalReceiver]]';
            $nameIndex = $this->internName($left->property->name);
            $this->compileExpression($left->object);
            $this->emit(Op::DUP);
            $this->emit(Op::STORE_LOCAL, $receiverSlot);
            $this->emit(Op::LOAD_MEMBER, $nameIndex);
        } else {
            throw new CompilerBailout('unsupported logical assignment reference');
        }

        if ($node->operator === '??=') {
            $assign = $this->emitJump(Op::JUMP_IF_NULLISH);
            $end = $this->emitJump(Op::JUMP);
            $this->patchJumpToHere($assign);
        } else {
            $end = $this->emitJump($node->operator === '&&='
                ? Op::JUMP_IF_FALSY_KEEP : Op::JUMP_IF_TRUTHY_KEEP);
        }
        if ($receiverSlot !== null) {
            $this->emit(Op::LOAD_LOCAL, $receiverSlot);
        }
        $this->compileExpression($node->right);
        if ($receiverSlot !== null) {
            $this->emit(Op::STORE_MEMBER, $nameIndex);
        } else {
            $this->emit(Op::DUP);
            $this->emitStoreLocal($slot);
        }
        $this->patchJumpToHere($end);
    }
}
