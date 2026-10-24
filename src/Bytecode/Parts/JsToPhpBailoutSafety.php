<?php

declare(strict_types=1);

namespace Phasis\Bytecode\Parts;

use Phasis\Bytecode\Bailout;

/** A speculative failure may restart a function only before observable effects. */
trait JsToPhpBailoutSafety
{
    private int $runtimeGuardCount = 0;

    private int $observableEffectCount = 0;

    /** @var array<int, true> */
    private array $topLevelFunctionDeclarations = [];

    /** @var array<string, true> */
    private array $initializedLocalFunctions = [];

    private function prepareLocalFunctionDeclarations(\Phasis\Ast\Statement\BlockStatement $body): void
    {
        $counts = [];
        foreach ($body->body as $statement) {
            if ($statement instanceof \Phasis\Ast\Declaration\FunctionDeclaration && $statement->id !== null) {
                $name = $statement->id->name;
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }
        foreach ($body->body as $statement) {
            if (
                $statement instanceof \Phasis\Ast\Declaration\FunctionDeclaration && $statement->id !== null
                && $counts[$statement->id->name] === 1
            ) {
                $this->topLevelFunctionDeclarations[spl_object_id($statement)] = true;
            }
        }
    }

    private function recordRuntimeGuard(): void
    {
        if ($this->observableEffectCount !== 0) {
            throw new Bailout('speculative guard after observable effect');
        }
        ++$this->runtimeGuardCount;
    }

    private function recordObservableEffect(): void
    {
        ++$this->observableEffectCount;
    }

    /** @return array{int, int} */
    private function beginLoopSafety(): array
    {
        return [$this->runtimeGuardCount, $this->observableEffectCount];
    }

    /** @param array{int, int} $before */
    private function finishLoopSafety(array $before): void
    {
        if ($this->runtimeGuardCount > $before[0] && $this->observableEffectCount > $before[1]) {
            throw new Bailout('loop backedge can replay an observable effect');
        }
    }
}
