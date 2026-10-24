<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class ShadowedBlockBindingTest extends TestCase
{
    public function testUnrelatedNestedBindingsDoNotForceAnOuterBlockEnvironment(): void
    {
        $engine = $this->checkOracle(false);
        foreach (['literalKeys', 'innerLexical', 'innerDeadZone', 'innerThrow'] as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled, $name);
        }
        $fallbacks = [
            'mixedCapture', 'defaultCapture', 'computedCapture', 'shorthandCapture', 'nestedClassEval',
            'nestedClassKeyEval', 'nestedEval', 'nestedWith', 'siblingBlocks',
        ];
        foreach ($fallbacks as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNull($function->compiled, $name);
        }
    }

    public function testBudgetedExecutionMatchesTheSameNodeOracle(): void
    {
        $this->checkOracle(true);
    }

    private function checkOracle(bool $budgeted): Engine
    {
        $engine = new Engine();
        if ($budgeted) {
            $engine->setExecutionBudget(10);
        }
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/shadowed-block-bindings.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/shadowed-block-bindings.json',
            $engine->eval('JSON.stringify(shadowedBlockResult)'),
        );
        return $engine;
    }
}
