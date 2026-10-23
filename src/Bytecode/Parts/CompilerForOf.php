<?php

declare(strict_types=1);

namespace Phasis\Bytecode\Parts;

use Phasis\Ast\Declaration\ClassDeclaration;
use Phasis\Ast\Declaration\FunctionDeclaration;
use Phasis\Ast\Declaration\VariableDeclaration;
use Phasis\Ast\Expression\ArrowFunction;
use Phasis\Ast\Expression\ClassExpression;
use Phasis\Ast\Expression\FunctionExpression;
use Phasis\Ast\Expression\Identifier;
use Phasis\Ast\Expression\MemberExpression;
use Phasis\Ast\Expression\Property;
use Phasis\Ast\Node;
use Phasis\Ast\Statement\BlockStatement;
use Phasis\Ast\Statement\CatchClause;
use Phasis\Ast\Statement\ForOfStatement;
use Phasis\Ast\Statement\ForStatement;
use Phasis\Bytecode\CompilerBailout;
use Phasis\Bytecode\HandlerEntry;
use Phasis\Bytecode\Op;

trait CompilerForOf
{
    private ?BlockStatement $forOfBody = null;

    private bool $forOfScopesValidated = false;

    private int $forOfBodyDepth = 0;

    /**
     * Flat local slots can represent only nonescaping iteration bindings.
     * Captures are rejected by collectCapturedLocals; this walk also rejects
     * lexical names used outside their block or in their iterable expression.
     */
    private function validateForOfScopes(BlockStatement $body): void
    {
        if ($this->forOfScopesValidated) {
            return;
        }
        $loops = [];
        $this->collectForOfNodes($body, $loops);
        if ($loops === []) {
            return;
        }
        foreach ($loops as $loop) {
            if (
                $this->inGenerator || $loop->await || !$loop->left instanceof VariableDeclaration
                || !in_array($loop->left->kind, ['var', 'let', 'const'], true)
                || count($loop->left->declarations) !== 1
                || !$loop->left->declarations[0]->id instanceof Identifier
            ) {
                throw new CompilerBailout('unsupported for-of binding or function kind');
            }
            if ($loop->left->kind !== 'var') {
                $name = $loop->left->declarations[0]->id->name;
                if (
                    $this->forOfNameOutsideScope($body, $name, $loop)
                    || $this->forOfNameOutsideScope($loop->right, $name, null)
                ) {
                    throw new CompilerBailout('for-of lexical binding escapes its iteration scope');
                }
            }
        }
        $this->validateForOfBlockScopes($body, $body);
        $this->forOfScopesValidated = true;
    }

    /** @param list<ForOfStatement> $loops */
    private function collectForOfNodes(Node $node, array &$loops): void
    {
        if ($this->forOfNestedScope($node)) {
            return;
        }
        if ($node instanceof ForOfStatement) {
            $loops[] = $node;
        }
        foreach (get_object_vars($node) as $value) {
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node) {
                    $this->collectForOfNodes($child, $loops);
                }
            }
        }
    }

    private function validateForOfBlockScopes(Node $node, BlockStatement $body): void
    {
        if ($this->forOfNestedScope($node)) {
            return;
        }
        if ($node instanceof CatchClause && $node->param instanceof Identifier) {
            $name = $node->param->name;
            if (
                $this->forOfNameOutsideScope($body, $name, $node)
                || $this->forOfNameHasVarBinding($body, $name)
            ) {
                // A catch binding is fresh on every entry, including when a
                // var declaration of the same name occurs inside its body.
                throw new CompilerBailout('for-of catch binding needs a separate environment');
            }
        }
        $declarations = [];
        if ($node instanceof BlockStatement && $node !== $body) {
            $declarations = $node->body;
        } elseif ($node instanceof ForStatement && $node->init instanceof VariableDeclaration) {
            $declarations = [$node->init];
        }
        foreach ($declarations as $declaration) {
            if ($declaration instanceof ClassDeclaration && $declaration->id !== null) {
                if ($this->forOfNameOutsideScope($body, $declaration->id->name, $node)) {
                    throw new CompilerBailout('for-of class binding escapes its block');
                }
                continue;
            }
            if (!$declaration instanceof VariableDeclaration || $declaration->kind === 'var') {
                continue;
            }
            foreach ($declaration->declarations as $binding) {
                $names = [];
                $this->collectPatternBoundNames($binding->id, $names);
                foreach ($names as $name => $_) {
                    if ($this->forOfNameOutsideScope($body, $name, $node)) {
                        throw new CompilerBailout('for-of function has an escaping block binding');
                    }
                }
            }
        }
        foreach (get_object_vars($node) as $value) {
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node) {
                    $this->validateForOfBlockScopes($child, $body);
                }
            }
        }
    }

    private function forOfNameHasVarBinding(Node $node, string $name): bool
    {
        if ($this->forOfNestedScope($node)) {
            return false;
        }
        if ($node instanceof VariableDeclaration && $node->kind === 'var') {
            foreach ($node->declarations as $declaration) {
                $names = [];
                $this->collectPatternBoundNames($declaration->id, $names);
                if (isset($names[$name])) {
                    return true;
                }
            }
        }
        foreach (get_object_vars($node) as $value) {
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node && $this->forOfNameHasVarBinding($child, $name)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function forOfNameOutsideScope(Node $node, string $name, ?Node $scope): bool
    {
        if ($node === $scope) {
            return false;
        }
        if ($node instanceof Identifier) {
            return $node->name === $name;
        }
        if ($node instanceof MemberExpression && !$node->computed) {
            return $this->forOfNameOutsideScope($node->object, $name, $scope);
        }
        if ($node instanceof Property && !$node->computed) {
            return $this->forOfNameOutsideScope($node->value, $name, $scope);
        }
        foreach (get_object_vars($node) as $value) {
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node && $this->forOfNameOutsideScope($child, $name, $scope)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function forOfNestedScope(Node $node): bool
    {
        return $node instanceof FunctionDeclaration || $node instanceof FunctionExpression
            || $node instanceof ArrowFunction || $node instanceof ClassDeclaration || $node instanceof ClassExpression;
    }

    private function compileForOf(ForOfStatement $node): void
    {
        // Program bindings and generators use different lifetime machinery.
        if (
            !$this->forOfScopesValidated || $this->inGenerator || $node->await || !$node->left instanceof VariableDeclaration
            || count($node->left->declarations) !== 1 || !$node->left->declarations[0]->id instanceof Identifier
        ) {
            throw new CompilerBailout('unsupported for-of statement');
        }
        $name = $node->left->declarations[0]->id->name;
        if (!isset($this->localSlots[$name]) || !in_array($node->left->kind, ['var', 'let', 'const'], true)) {
            throw new CompilerBailout('for-of requires an ordinary local binding');
        }
        $iteratorSlot = count($this->localNames);
        $this->localNames[] = '[[iterator]]';
        $this->localNames[] = '[[iteratorNext]]';
        $valueSlot = count($this->localNames);
        $this->localNames[] = '[[iteratorCompletionValue]]';
        $completionSlot = count($this->localNames);
        $this->localNames[] = '[[iteratorCompletion]]';

        $this->compileExpression($node->right);
        $this->emit(Op::ITERATOR_INIT, $iteratorSlot);
        $loopId = $this->loopCount++;
        $this->emit(Op::LOOP_INIT, $loopId);
        $loopStart = count($this->code);
        $nextPc = count($this->code);
        $this->emit(Op::ITERATOR_NEXT, $iteratorSlot, $loopId, 0);

        // IteratorStepValue failures do not close the iterator. Only binding
        // initialization and the body are inside the synthetic close handler.
        $bodyStart = count($this->code);
        $slot = $this->localSlots[$name];
        $this->emitInitializeLocal($slot, $node->left->kind);
        if ($node->left->kind === 'const') {
            $this->constSlots[$slot] = true;
        }
        $tailCallsWereEnabled = $this->tailCallsEnabled;
        $this->tailCallsEnabled = false;
        $this->finallyContexts[] = [
            'valueSlot' => $valueSlot,
            'completionSlot' => $completionSlot,
            'jumps' => [],
        ];
        $this->finallyLoopBoundaries[] = count($this->loopStack);
        $this->loopStack[] = ['continue' => -1, 'breaks' => [], 'continues' => []];
        $this->forOfBodyDepth++;
        try {
            $this->compileStatement($node->body);
        } finally {
            $this->forOfBodyDepth--;
        }
        $bodyEnd = count($this->code);
        $this->emit(Op::JUMP, $loopStart - count($this->code));
        $frame = array_pop($this->loopStack);
        $breakTarget = count($this->code);
        $this->emit(Op::LOAD_UNDEF);
        $this->emit(Op::STORE_LOCAL, $completionSlot);
        $breakClose = $this->emitJump(Op::JUMP);
        $catchPc = count($this->code);
        $this->emit(Op::LOAD_FALSE);
        $this->emit(Op::STORE_LOCAL, $completionSlot);
        $context = array_pop($this->finallyContexts);
        foreach ([$breakClose, ...$context['jumps']] as $jump) {
            $this->patchJumpToHere($jump);
        }
        array_pop($this->finallyLoopBoundaries);
        $this->tailCallsEnabled = $tailCallsWereEnabled;
        $this->emit(Op::ITERATOR_CLOSE, $iteratorSlot, $completionSlot, $valueSlot);
        $this->emit(Op::LOAD_LOCAL, $completionSlot);
        $afterBreak = $this->emitJump(Op::JUMP_IF_FALSE);
        $this->emit(Op::LOAD_LOCAL, $valueSlot);
        $this->emitReturnCompletion();
        $this->patchJumpToHere($afterBreak);
        $this->code[$nextPc + 3] = count($this->code) - $nextPc;
        foreach ($frame['continues'] as $index) {
            $this->code[$index] = $loopStart - ($index - 1);
        }
        foreach ($frame['breaks'] as $index) {
            $this->code[$index] = $breakTarget - ($index - 1);
        }
        $this->handlers[] = new HandlerEntry(
            tryStart: $bodyStart,
            tryEnd: $bodyEnd,
            catchPc: $catchPc,
            exceptionSlot: $valueSlot,
            stackBase: $this->tryEntryStackDepth,
        );
    }
}
