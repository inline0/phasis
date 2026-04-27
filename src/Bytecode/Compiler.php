<?php

declare(strict_types=1);

namespace PhpJs\Bytecode;

use PhpJs\Ast\Expression\AssignmentExpression;
use PhpJs\Ast\Expression\BinaryExpression;
use PhpJs\Ast\Expression\CallExpression;
use PhpJs\Ast\Expression\ConditionalExpression;
use PhpJs\Ast\Expression\Identifier;
use PhpJs\Ast\Expression\Literal;
use PhpJs\Ast\Expression\MemberExpression;
use PhpJs\Ast\Expression\PrivateIdentifier;
use PhpJs\Ast\Expression\SpreadElement;
use PhpJs\Ast\Expression\ThisExpression;
use PhpJs\Ast\Node;
use PhpJs\Ast\Statement\BlockStatement;
use PhpJs\Ast\Statement\ExpressionStatement;
use PhpJs\Ast\Statement\ReturnStatement;
use PhpJs\Value\JsBigInt;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

/**
 * AST → bytecode lowering. Phase 2 supports the minimal subset
 * needed to run fib(): arithmetic, comparison, conditional
 * expression, return, simple identifier callee calls, parameter
 * locals, free-variable name lookups. Anything else throws
 * CompilerBailout and JsFunction falls back to the tree-walker.
 */
final class Compiler
{
    /** @var list<int> */
    private array $code = [];

    /** @var list<JsValue> */
    private array $consts = [];

    /** @var list<string> */
    private array $names = [];

    /** @var array<string,int> Reverse map for de-duping constants by string key. */
    private array $constIndex = [];

    /** @var array<string,int> Reverse map for de-duping names. */
    private array $nameIndex = [];

    /** @var list<string> Slot index → identifier name, for diagnostics. */
    private array $localNames = [];

    /** @var array<string,int> Identifier name → slot index. */
    private array $localSlots = [];

    /** @var list<int> */
    private array $paramSlots = [];

    public function compile(JsFunction $fn): CompiledFunction
    {
        // Phase 2 limit: only ordinary functions. Generators, async,
        // class constructors, arrows-with-special-this, methods all
        // bail. Native callables don't have an AST body.
        if ($fn->isGenerator() || $fn->isAsync() || $fn->isNative() || $fn->isClassConstructor()) {
            throw new CompilerBailout('non-ordinary function kind');
        }
        $body = $fn->getBody();
        if (!$body instanceof BlockStatement) {
            // Arrow expression bodies — handle later.
            throw new CompilerBailout('non-block function body');
        }

        // Parameters: only plain Identifier params (no defaults, no
        // rest, no destructuring) are supported in Phase 2. Each
        // parameter gets a numbered local slot.
        foreach ($fn->getParams() as $param) {
            if (!$param instanceof Identifier) {
                throw new CompilerBailout('non-simple parameter');
            }
            $slot = $this->declareLocal($param->name);
            $this->paramSlots[] = $slot;
        }

        // Body: visit each top-level statement. Anything we don't
        // recognise blows up via CompilerBailout.
        foreach ($body->body as $stmt) {
            $this->compileStatement($stmt);
        }

        // Implicit `return undefined` at the end so the VM always
        // exits via OP_RET.
        $this->emit(Op::LOAD_UNDEF);
        $this->emit(Op::RET);

        return new CompiledFunction(
            code: $this->code,
            consts: $this->consts,
            names: $this->names,
            localNames: $this->localNames,
            paramSlots: $this->paramSlots,
            slotCount: max(1, count($this->localNames)),
        );
    }

    private function declareLocal(string $name): int
    {
        if (isset($this->localSlots[$name])) {
            return $this->localSlots[$name];
        }
        $slot = count($this->localNames);
        $this->localNames[] = $name;
        $this->localSlots[$name] = $slot;
        return $slot;
    }

    private function emit(int $op, int ...$operands): void
    {
        $this->code[] = $op;
        foreach ($operands as $o) {
            $this->code[] = $o;
        }
    }

    /**
     * Reserve a placeholder operand for a forward jump and return its
     * index so the caller can patch it once the target offset is known.
     */
    private function emitJump(int $op): int
    {
        $this->code[] = $op;
        $this->code[] = 0; // placeholder
        return count($this->code) - 1;
    }

    private function patchJumpToHere(int $operandIndex): void
    {
        // Operand semantics: pc += operand. The dispatcher reads the
        // operand at $pc + 1 then advances $pc += operand. So the
        // operand is "from the opcode pc to the target pc". Compute
        // target - opcode_pc.
        $opcodePc = $operandIndex - 1;
        $targetPc = count($this->code);
        $this->code[$operandIndex] = $targetPc - $opcodePc;
    }

    private function compileStatement(Node $node): void
    {
        if ($node instanceof ReturnStatement) {
            if ($node->argument === null) {
                $this->emit(Op::LOAD_UNDEF);
            } else {
                $this->compileExpression($node->argument);
            }
            $this->emit(Op::RET);
            return;
        }
        if ($node instanceof ExpressionStatement) {
            $this->compileExpression($node->expression);
            // Discard the value — expression-statement results are
            // not visible at the bytecode level (the spec's normal
            // completion value is fed by executeBody, but the VM
            // returns RET-only so trailing expr stmts are discarded).
            $this->emit(Op::POP);
            return;
        }
        if ($node instanceof BlockStatement) {
            // Inline the block's statements. Phase 2 doesn't yet
            // support let/const inside nested blocks — bail if any
            // would create new bindings.
            foreach ($node->body as $inner) {
                $this->compileStatement($inner);
            }
            return;
        }
        throw new CompilerBailout('unsupported statement: ' . $node->type());
    }

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
        throw new CompilerBailout('unsupported expression: ' . $node->type());
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
            $idx = $this->internConst(new JsNumber((float) $value), 'n:' . $value);
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
            // Only dot-style for Phase 3; computed method calls would
            // need a CALL_COMPUTED_METHOD opcode and bail until then.
            if ($node->callee->computed) {
                throw new CompilerBailout('computed method call');
            }
            if (!($node->callee->property instanceof Identifier)) {
                throw new CompilerBailout('non-identifier method name');
            }
            $this->compileExpression($node->callee->object);
            foreach ($node->arguments as $arg) {
                $this->compileExpression($arg);
            }
            $this->emit(
                Op::CALL_METHOD,
                count($node->arguments),
                $this->internName($node->callee->property->name),
            );
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
        // Only plain `=` so far. Compound (`+=`, `-=`, ...) and
        // logical (`&&=`, `||=`, `??=`) variants come in Phase 5.
        if ($node->operator !== '=') {
            throw new CompilerBailout('compound assignment');
        }
        if ($node->leftParenthesized) {
            throw new CompilerBailout('parenthesized lhs');
        }
        $left = $node->left;

        if ($left instanceof Identifier) {
            $name = $left->name;
            if ($name === 'eval' || $name === 'arguments') {
                throw new CompilerBailout('assign to eval/arguments');
            }
            // RHS first so its value sits on top of the stack; we DUP
            // before storing so the assignment-expression result stays
            // on the stack for the parent consumer.
            $this->compileExpression($node->right);
            if (isset($this->localSlots[$name])) {
                $this->emit(Op::DUP);
                $this->emit(Op::STORE_LOCAL, $this->localSlots[$name]);
            } else {
                $this->emit(Op::DUP);
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
            // Stack layout: [obj, val] for STORE_MEMBER. The opcode
            // pops obj and val, performs the write, and leaves val on
            // top so the parent consumer sees the assignment value.
            $this->compileExpression($left->object);
            $this->compileExpression($node->right);
            if ($left->computed) {
                throw new CompilerBailout('computed assign'); // Phase 5
            }
            if (!($left->property instanceof Identifier)) {
                throw new CompilerBailout('non-identifier assign target');
            }
            $this->emit(Op::STORE_MEMBER, $this->internName($left->property->name));
            return;
        }

        throw new CompilerBailout('unsupported assignment target');
    }

    private function internConst(JsValue $value, string $key): int
    {
        if (isset($this->constIndex[$key])) {
            return $this->constIndex[$key];
        }
        $idx = count($this->consts);
        $this->consts[] = $value;
        $this->constIndex[$key] = $idx;
        return $idx;
    }

    private function internName(string $name): int
    {
        if (isset($this->nameIndex[$name])) {
            return $this->nameIndex[$name];
        }
        $idx = count($this->names);
        $this->names[] = $name;
        $this->nameIndex[$name] = $idx;
        return $idx;
    }
}
