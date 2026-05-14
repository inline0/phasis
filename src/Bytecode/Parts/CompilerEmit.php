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

/**
 * Compiler trait part: CompilerEmit. Composed into Compiler via
 * `use Parts\CompilerEmit;`.
 */
trait CompilerEmit
{
    private function emit(int $op, int ...$operands): void
    {
        $this->code[] = $op;
        foreach ($operands as $o) {
            $this->code[] = $o;
        }
    }

    /**
     * Emit a STORE_LOCAL to $slot. When $slot is a top-level program
     * var slot, also mirror the value onto globalEnv via STORE_NAME so
     * mid-program reads of globalThis.<name> (e.g. from Function-
     * constructor bodies) see the live value. Spec-required: per ES,
     * top-level `var` creates a property on the global object whose
     * value is updated synchronously by the var initializer / any
     * subsequent assignment.
     */
    private function emitStoreLocal(int $slot): void
    {
        $this->emit(Op::STORE_LOCAL, $slot);
        if (isset($this->programVarSlots[$slot])) {
            $this->emit(Op::LOAD_LOCAL, $slot);
            $this->emit(Op::STORE_NAME, $this->internName($this->programVarSlots[$slot]));
        }
    }

    /**
     * Best-effort source rendering for a method-call callee
     * (`obj.prop`). Mirrors Interpreter::renderCalleeNode so the
     * VM-compiled and tree-walker paths produce the same TypeError
     * text. Returns null when the receiver shape doesn't have an
     * obvious literal form, in which case the VM falls back to its
     * legacy stringification.
     */
    private function renderMethodCallDisplay(MemberExpression $callee): ?string
    {
        if ($callee->computed) {
            return null;
        }
        if (!($callee->property instanceof Identifier)) {
            return null;
        }
        $obj = $this->renderCalleeObject($callee->object);
        if ($obj === null) {
            return null;
        }
        return $obj . '.' . $callee->property->name;
    }

    private function renderCalleeObject(Node $node): ?string
    {
        if ($node instanceof Identifier) {
            return $node->name;
        }
        if ($node instanceof ThisExpression) {
            return 'this';
        }
        if ($node instanceof MemberExpression && !$node->computed && $node->property instanceof Identifier) {
            $parent = $this->renderCalleeObject($node->object);
            if ($parent === null) {
                return null;
            }
            return $parent . '.' . $node->property->name;
        }
        if ($node instanceof ArrayExpression && $node->elements === []) {
            return '[]';
        }
        if ($node instanceof ObjectExpression && $node->properties === []) {
            return '({})';
        }
        return null;
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
}
