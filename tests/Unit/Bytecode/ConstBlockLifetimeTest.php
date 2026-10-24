<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class ConstBlockLifetimeTest extends TestCase
{
    public function testDisjointConstBlocksReuseSlotsWhilePreservingNodeSemantics(): void
    {
        $engine = new Engine();
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/const-block-lifetimes.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/const-block-lifetimes.json',
            $engine->eval('JSON.stringify(constBlockLifetimeResult)'),
        );
        foreach (['constSiblings', 'constBranches', 'constLoop', 'constWhile'] as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled, $name . ' must exercise bytecode.');
        }
        foreach (
            ['constWrite', 'constUpdate', 'constThenLet', 'letThenConst', 'constClosures',
            'constNested', 'constBeforeInit', 'constSelfInit', 'constOutside'] as $name
        ) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNull($function->compiled, $name . ' requires lexical environment semantics.');
            self::assertNull($function->phpCompiled);
        }
    }

    public function testInterpretedConstBlocksMatchTheSameNodeOracle(): void
    {
        $engine = new Engine();
        $engine->setExecutionBudget(10);
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/const-block-lifetimes.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/const-block-lifetimes.json',
            $engine->eval('JSON.stringify(constBlockLifetimeResult)'),
        );
    }
}
