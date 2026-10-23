<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class BlockLifetimeTest extends TestCase
{
    public function testIndependentBlocksCanReuseSlotsWithoutChangingNodeScopeSemantics(): void
    {
        $engine = new Engine();
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/block-lifetimes.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/block-lifetimes.json',
            $engine->eval('JSON.stringify(blockLifetimeResult)'),
        );
        foreach (['siblings', 'branches', 'looping', 'abrupt'] as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled, $name . ' must exercise bytecode.');
        }
        foreach (['leaked', 'closures', 'nested', 'parameter', 'hoisted', 'beforeInit', 'selfInit', 'constMix'] as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNull($function->compiled, $name . ' requires the lexical environment path.');
            self::assertNull($function->phpCompiled, $name . ' must also preserve lexical scopes in PHP compilation.');
        }
    }

    public function testInterpreterMatchesTheSameNodeOracle(): void
    {
        $engine = new Engine();
        $engine->setExecutionBudget(10);
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/block-lifetimes.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/block-lifetimes.json',
            $engine->eval('JSON.stringify(blockLifetimeResult)'),
        );
    }
}
