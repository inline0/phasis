<?php

declare(strict_types=1);

namespace Phasis\Bytecode\Parts;

use Phasis\Ast\Declaration\ClassDeclaration;
use Phasis\Ast\Declaration\FunctionDeclaration;
use Phasis\Ast\Declaration\VariableDeclaration;
use Phasis\Ast\Expression\ArrowFunction;
use Phasis\Ast\Expression\ClassExpression;
use Phasis\Ast\Expression\FunctionExpression;
use Phasis\Ast\Node;
use Phasis\Ast\Statement\SwitchStatement;
use Phasis\Bytecode\CompilerBailout;
use Phasis\Bytecode\Op;

trait CompilerSwitch
{
    private bool $switchScopesValidated = false;

    private function validateSwitchBindings(SwitchStatement $node): void
    {
        if ($this->forOfBody === null || $this->inGenerator) {
            throw new CompilerBailout('switch requires an ordinary function body');
        }
        if (!$this->switchScopesValidated) {
            $this->validateForOfBlockScopes($this->forOfBody, $this->forOfBody);
            $this->switchScopesValidated = true;
        }
        foreach ($node->cases as $case) {
            foreach ($case->consequent as $statement) {
                if (
                    ($statement instanceof VariableDeclaration && $statement->kind !== 'var')
                    || $statement instanceof ClassDeclaration || $statement instanceof FunctionDeclaration
                ) {
                    // Shared case-block lexical bindings exist before case
                    // expressions run. Flat local slots cannot model that TDZ.
                    throw new CompilerBailout('switch case-block lexical environment');
                }
                $this->validateSwitchLocalInitialization($statement);
            }
        }
    }

    private function validateSwitchLocalInitialization(Node $node): void
    {
        if ($node instanceof FunctionDeclaration || $node instanceof ClassDeclaration) {
            throw new CompilerBailout('switch declaration needs a nested environment');
        }
        if ($node instanceof FunctionExpression || $node instanceof ArrowFunction || $node instanceof ClassExpression) {
            return;
        }
        if ($node instanceof VariableDeclaration && $node->kind === 'let') {
            foreach ($node->declarations as $declaration) {
                if ($declaration->init === null) {
                    throw new CompilerBailout('switch lexical initialization requires a fresh binding');
                }
            }
        }
        foreach (get_object_vars($node) as $value) {
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node) {
                    $this->validateSwitchLocalInitialization($child);
                }
            }
        }
    }

    private function compileSwitch(SwitchStatement $node): void
    {
        $this->validateSwitchBindings($node);
        $valueSlot = count($this->localNames);
        $this->localNames[] = '[[switchValue]]';
        $this->compileExpression($node->discriminant);
        $this->emit(Op::STORE_LOCAL, $valueSlot);

        $caseJumps = [];
        foreach ($node->cases as $index => $case) {
            if ($case->test !== null) {
                $this->emit(Op::LOAD_LOCAL, $valueSlot);
                $this->compileExpression($case->test);
                $this->emit(Op::SEQ);
                $caseJumps[$index] = $this->emitJump(Op::JUMP_IF_TRUE);
            }
        }
        $noMatch = $this->emitJump(Op::JUMP);
        $hasDefault = false;
        // A switch is a break target, but continue must find an outer loop.
        $this->loopStack[] = ['continue' => -2, 'breaks' => [], 'continues' => []];
        foreach ($node->cases as $index => $case) {
            if ($case->test === null) {
                $this->patchJumpToHere($noMatch);
                $hasDefault = true;
            } else {
                $this->patchJumpToHere($caseJumps[$index]);
            }
            foreach ($case->consequent as $statement) {
                $this->compileStatement($statement);
            }
        }
        if (!$hasDefault) {
            $this->patchJumpToHere($noMatch);
        }
        $frame = array_pop($this->loopStack);
        foreach ($frame['breaks'] as $jump) {
            $this->patchJumpToHere($jump);
        }
    }
}
