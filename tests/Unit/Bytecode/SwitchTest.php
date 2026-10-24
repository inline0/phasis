<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class SwitchTest extends TestCase
{
    public function testCaseSelectionAndAbruptCompletionsMatchNode(): void
    {
        $engine = $this->checkOracle(false);
        foreach (['selectedCase', 'strictCases', 'noDefault', 'nestedSwitches', 'forOfSwitch', 'finallySwitch'] as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled, $name);
        }
        foreach (['caseBlockTdz', 'blockScopeEscape', 'resetSwitchLet', 'finallyContinue'] as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNull($function->compiled, $name);
        }
    }

    public function testBudgetedInterpreterMatchesTheSameOracle(): void
    {
        $this->checkOracle(true);
    }

    private function checkOracle(bool $budgeted): Engine
    {
        $engine = new Engine();
        if ($budgeted) {
            $engine->setExecutionBudget(10);
        }
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/switch.js'));
        self::assertJsonStringEqualsJsonFile(__DIR__ . '/fixtures/switch.json', $engine->eval('JSON.stringify(switchResults)'));
        return $engine;
    }
}
