<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class FinallyCompletionTest extends TestCase
{
    public function testCompiledFinalizersMatchNodeCompletionAndEvaluationOrder(): void
    {
        $engine = new Engine();
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/finally-completions.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/finally-completions.json',
            $engine->eval('JSON.stringify(finallyCompletionResult)'),
        );
        foreach (['complete', 'nested', 'outerCatch', 'returnExpression', 'looped', 'finallyTail'] as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled, $name . ' must exercise the bytecode path.');
        }
        self::assertSame(5000, $engine->eval('finallyTail(5000, 0)'));
    }

    public function testInterpreterFinalizersMatchTheSameNodeOracle(): void
    {
        $engine = new Engine();
        $engine->setExecutionBudget(10);
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/finally-completions.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/finally-completions.json',
            $engine->eval('JSON.stringify(finallyCompletionResult)'),
        );
        $function = $engine->getGlobalEnv()->get('complete');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNull($function->compiled);
    }
}
