<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Bytecode\Op;
use Phasis\Engine;
use Phasis\Exceptions\BudgetExceeded;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class ForOfTest extends TestCase
{
    public function testCompiledLoopsMatchNodeIteratorOrderingAndCompletions(): void
    {
        $engine = $this->checkOracle(false);
        $compiledNames = [
            'collectLoop', 'stopLoop', 'continueLoop', 'returnLoop',
            'throwLoop', 'nestedLoop', 'varCaptureLoop', 'resetLetLoop',
        ];
        foreach ($compiledNames as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled, $name);
            $opcodes = [];
            for ($pc = 0; $pc < count($function->compiled->code); $pc += 1 + Op::operandCount($function->compiled->code[$pc])) {
                $opcodes[] = $function->compiled->code[$pc];
            }
            self::assertContains(Op::ITERATOR_NEXT, $opcodes, $name);
        }
        $interpretedNames = [
            'letCaptureLoop', 'outsideLoop', 'headerTdzLoop', 'shadowLoop',
            'blockOutsideLoop', 'catchCaptureLoop', 'classOutsideLoop',
        ];
        foreach ($interpretedNames as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNull($function->compiled, $name . ' needs the interpreter scope model.');
        }
    }

    public function testBudgetedInterpreterMatchesTheSameNodeOracle(): void
    {
        $this->checkOracle(true);
    }

    public function testBudgetEnabledDuringIterationPrecedesLoopLimitAndTheNextStep(): void
    {
        $engine = new Engine();
        $expire = false;
        $engine->setGlobal('expireBudget', static function () use ($engine, &$expire): void {
            if ($expire) {
                $engine->setExecutionBudget(-1);
            }
        });
        self::assertSame(2, $engine->eval(<<<'JS'
function budgetLoop(input) {
    let count = 0;
    for (const value of input) {
        if (count === 0) expireBudget();
        count++;
    }
    return count;
}
budgetLoop([1, 2]);
JS));
        $function = $engine->getGlobalEnv()->get('budgetLoop');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNotNull($function->compiled);
        $expire = true;
        $engine->setLimit('maxLoopIterations', 1);
        $this->expectException(BudgetExceeded::class);
        $engine->eval('budgetLoop([1, 2])');
    }

    private function checkOracle(bool $budgeted): Engine
    {
        $engine = new Engine();
        if ($budgeted) {
            $engine->setExecutionBudget(10);
        }
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/for-of.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/for-of.json',
            $engine->eval('JSON.stringify(forOfResult)'),
        );
        return $engine;
    }
}
