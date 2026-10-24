<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class OptionalChainTest extends TestCase
{
    public function testCompiledChainsMatchNodeShortCircuitAndReferenceSemantics(): void
    {
        $engine = new Engine();
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/optional-chains.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/optional-chains.json',
            $engine->eval('JSON.stringify(optionalChainResult)'),
        );
        $functions = ['property', 'computed', 'method', 'optionalMethod', 'direct', 'continuation',
            'callContinuation', 'groupedProperty', 'groupedMethod', 'groupedOptionalMethod',
            'computedMethod', 'nested', 'spread', 'tail', 'tailUndefined', 'normalized', 'protectedCall'];
        foreach ($functions as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled, $name . ' must exercise the bytecode path.');
        }
        self::assertSame(5000, $engine->eval('tail(5000, 0)'));
    }

    public function testInterpretedChainsMatchTheSameNodeOracle(): void
    {
        $engine = new Engine();
        $engine->setExecutionBudget(10);
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/optional-chains.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/optional-chains.json',
            $engine->eval('JSON.stringify(optionalChainResult)'),
        );
        $function = $engine->getGlobalEnv()->get('property');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNull($function->compiled);
    }
}
