<?php

declare(strict_types=1);

namespace Phasis\Bytecode\Parts;

use Phasis\Ast\Node;
use Phasis\Ast\Statement\WhileStatement;
use Phasis\Bytecode\CompiledFunction;
use Phasis\Bytecode\JsToPhp;
use Phasis\Bytecode\Op;
use Phasis\Bytecode\StringLoopRegion;
use Phasis\Bytecode\StringLoopRegionPlan;
use Phasis\Bytecode\StringLoopRegionVerifier;
use Phasis\Value\JsFunction;

/** Collect pure regions while preserving the ordinary bytecode as their fallback. */
trait CompilerStringLoopRegions
{
    private bool $regionFunction = false;

    /** @var list<array{plan: StringLoopRegionPlan, opcode: int, loopId: int, entry: int, normalExit: int}> */
    private array $pendingLoopRegions = [];

    /** @var array<int, int> Statement identity to its first-effect PC; -1 rejects ambiguous reuse. */
    private array $regionStatementPcs = [];

    private function ordinaryRegionFunction(JsFunction $function): bool
    {
        return !$function->isClassConstructor() && !$function->isDerivedConstructor() && $function->getBoundTarget() === null;
    }

    private function recordRegionStatement(Node $node): void
    {
        if ($this->pendingLoopRegions === []) {
            return;
        }
        $identity = spl_object_id($node);
        $pc = count($this->code);
        $previous = $this->regionStatementPcs[$identity] ?? $pc;
        $this->regionStatementPcs[$identity] = $previous === $pc ? $pc : -1;
    }

    private function beginStringLoopRegion(WhileStatement $node, int $loopId): ?int
    {
        if (
            !$this->regionFunction || $this->inGenerator || $this->parametersFromEnvironment
            || $this->needsArguments || $this->capturedLocals !== []
        ) {
            return null;
        }
        $plan = StringLoopRegionPlan::analyze($node, $this->localSlots, $this->capturedLocals, $this->constSlots);
        if ($plan === null) {
            return null;
        }
        $index = count($this->pendingLoopRegions);
        $this->pendingLoopRegions[] = [
            'plan' => $plan, 'opcode' => count($this->code), 'loopId' => $loopId,
            'entry' => -1, 'normalExit' => -1,
        ];
        $this->emit(Op::STRING_LOOP_REGION, $index);
        return $index;
    }

    private function finishStringLoopRegion(?int $index, int $entry, int $normalExit): void
    {
        if ($index === null) {
            return;
        }
        $this->pendingLoopRegions[$index]['entry'] = $entry;
        $this->pendingLoopRegions[$index]['normalExit'] = $normalExit;
    }

    /** Verify complete handler tables and bytecode before generating any executable region. */
    private function finalizeStringLoopRegions(CompiledFunction $function): void
    {
        $function->loopRegions = array_fill(0, count($this->pendingLoopRegions), null);
        if (
            $this->parametersFromEnvironment || $this->needsArguments || $this->capturedLocals !== []
            || $this->arrayAssignmentReferences || $this->interpretedExpressions !== [] || $this->classNodes !== []
        ) {
            return;
        }
        foreach ($this->pendingLoopRegions as $index => $pending) {
            $entry = $pending['entry'];
            $loopId = $pending['loopId'];
            if ($entry < 0 || ($function->code[$entry] ?? null) !== Op::CHECK_BUDGET) {
                continue;
            }
            $plan = $pending['plan'];
            $exits = [];
            foreach ($plan->exits as $node) {
                $pc = $this->regionStatementPcs[spl_object_id($node)] ?? -1;
                if ($pc < 0) {
                    continue 2;
                }
                $exits[] = $pc;
            }
            if (
                !StringLoopRegionVerifier::verify(
                    $function,
                    $plan,
                    $entry,
                    $pending['normalExit'],
                    $pending['opcode'],
                    $loopId,
                    $exits,
                )
            ) {
                continue;
            }
            $executor = JsToPhp::compileLoopRegion($plan, $loopId);
            if ($executor === null) {
                continue;
            }
            $exits[] = $pending['normalExit'];
            $function->loopRegions[$index] = new StringLoopRegion($executor, $exits);
        }
    }
}
