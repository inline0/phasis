<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class SpreadCallTest extends TestCase
{
    public function testCompiledCallsPreserveNodeLookupIterationAndReceiverSemantics(): void
    {
        $engine = new Engine();
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/spread-calls.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/spread-calls.json',
            $engine->eval('JSON.stringify(spreadCallResult)'),
        );
        foreach (['callMethod', 'spreadCall', 'spreadMethod', 'spreadNew', 'tailSpread', 'tailMethod'] as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled, $name . ' must exercise bytecode.');
        }
    }

    public function testInterpreterUsesTheSameIteratorSemantics(): void
    {
        $engine = new Engine();
        $engine->setExecutionBudget(10);
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/spread-calls.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/spread-calls.json',
            $engine->eval('JSON.stringify(spreadCallResult)'),
        );
    }
}
