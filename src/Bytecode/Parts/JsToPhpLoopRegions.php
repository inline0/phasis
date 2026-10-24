<?php

declare(strict_types=1);

namespace Phasis\Bytecode\Parts;

use Phasis\Ast\Expression\AssignmentExpression;
use Phasis\Ast\Expression\Identifier;
use Phasis\Ast\Expression\UpdateExpression;
use Phasis\Ast\Node;
use Phasis\Bytecode\Bailout;
use Phasis\Bytecode\StringLoopRegionPlan;

/** Pure loop regions share scalar emission without restarting their enclosing function. */
trait JsToPhpLoopRegions
{
    private ?StringLoopRegionPlan $loopRegion = null;

    /**
     * The closure receives the active VM arrays, not the frame-pool snapshot.
     * A null return means a guard declined before the body and the VM must continue normally.
     */
    public static function compileLoopRegion(StringLoopRegionPlan $plan, int $loopId): ?\Closure
    {
        $compiler = new self();
        $compiler->numericMode = true;
        $compiler->loopRegion = $plan;
        foreach ($plan->bindingTypes as $name => $type) {
            $compiler->declaredLocals[$name] = true;
            $compiler->localTypes[$name] = $type === 'number' ? 'numeric' : $type;
        }
        try {
            $compiler->emitRegionGuards();
            foreach ($plan->inputs as $name => $binding) {
                $compiler->emitRegionInput($name, $binding['slot'], $binding['type']);
            }
            foreach ($plan->outputs as $name => $_) {
                $compiler->emitLine($compiler->regionWrittenFlag($name) . ' = false;');
            }
            $compiler->emitLine('$_region_iterations = $loopCounts[' . $loopId . '] ?? 0;');
            $compiler->emitLine('try {');
            ++$compiler->indentLevel;
            $compiler->emitLine('while (true) {');
            ++$compiler->indentLevel;
            $compiler->emitLoopBudgetCheck();
            $condition = $compiler->emitScalarCondition($plan->loop->test);
            $compiler->flushPending();
            $compiler->emitLine('if (!(' . $condition . ')) { return ' . count($plan->exits) . '.0; }');
            $compiler->emitLoopTick('$_region_iterations');
            $compiler->emitStatement($plan->body);
            --$compiler->indentLevel;
            $compiler->emitLine('}');
            --$compiler->indentLevel;
            $compiler->emitLine('} finally {');
            ++$compiler->indentLevel;
            $compiler->emitLine('$loopCounts[' . $loopId . '] = $_region_iterations;');
            foreach ($plan->outputs as $name => $binding) {
                $class = $binding['type'] === 'boolean' ? 'JsBoolean' : 'JsNumber';
                $compiler->emitLine('if (' . $compiler->regionWrittenFlag($name) . ') {');
                ++$compiler->indentLevel;
                $compiler->emitLine('$locals[' . $binding['slot'] . '] = \\Phasis\\Value\\' . $class
                    . '::of(' . $compiler->slotVar($name) . ');');
                --$compiler->indentLevel;
                $compiler->emitLine('}');
            }
            --$compiler->indentLevel;
            $compiler->emitLine('}');
        } catch (Bailout) {
            return null;
        }
        $source = 'return static function (array &$locals, $interp, array &$loopCounts): ?float {'
            . "\n" . $compiler->out . '};';
        $factory = self::scannerClosureFactory($plan->loop, $source);
        if ($factory === null) {
            return null;
        }
        $closure = $factory([], null);
        return $closure instanceof \Closure ? $closure : null;
    }

    private function emitRegionGuards(): void
    {
        $this->emitLine('$_region_context = \\Phasis\\Engine::getCurrentInterpreter();');
        $this->emitLine('if ($interp->hasExecutionBudget() || $_region_context?->hasExecutionBudget()'
            . ' || $_region_context?->getRealm() !== $interp->getRealm() || $interp->getRealm() === null) { return null; }');
        $this->emitLine('if (!$interp->hasCachedStringIntrinsic("charCodeAt", "string.charCodeAt")) { return null; }');
    }

    private function emitRegionInput(string $name, int $slot, string $type): void
    {
        $variable = $this->slotVar($name);
        $class = match ($type) {
            'number' => 'JsNumber',
            'boolean' => 'JsBoolean',
            'string' => 'JsString',
            default => throw new Bailout('unknown region input type'),
        };
        $this->emitLine($variable . ' = $locals[' . $slot . '] ?? null;');
        $this->emitLine('if (!(' . $variable . ' instanceof \\Phasis\\Value\\' . $class . ')) { return null; }');
        if ($type === 'string') {
            $this->emitLine('if (get_class(' . $variable . ') !== \\Phasis\\Value\\JsString::class'
                . ' || !' . $variable . '->isAscii()) { return null; }');
        }
        $this->emitLine($variable . ' = ' . $variable . '->value;');
    }

    private function regionWrittenFlag(string $name): string
    {
        return '$_region_written_' . preg_replace('/[^A-Za-z0-9_]/', '_', $name);
    }

    private function emitRegionWrite(string $name): void
    {
        if ($this->loopRegion !== null && isset($this->loopRegion->outputs[$name])) {
            $this->emitLine($this->regionWrittenFlag($name) . ' = true;');
        }
    }

    private function emitRegionExpressionWrite(Node $expression): void
    {
        if ($expression instanceof AssignmentExpression && $expression->left instanceof Identifier) {
            $this->emitRegionWrite($expression->left->name);
        } elseif ($expression instanceof UpdateExpression && $expression->argument instanceof Identifier) {
            $this->emitRegionWrite($expression->argument->name);
        }
    }
}
