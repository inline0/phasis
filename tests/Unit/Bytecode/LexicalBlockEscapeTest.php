<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Bytecode\Compiler;
use Phasis\Bytecode\JsToPhp;
use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class LexicalBlockEscapeTest extends TestCase
{
    public function testExitedBlockBindingsUseTheInterpreterScopeModel(): void
    {
        $engine = $this->checkOracle(false);
        foreach (['escapedString', 'escapedNumeric', 'escapedRead', 'escapedCompound', 'escapedUpdate', 'escapedTypeof', 'escapedSloppy'] as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNull($function->compiled, $name);
            self::assertNull($function->phpCompiled, $name);
        }
        $valid = $engine->getGlobalEnv()->get('validBlock');
        self::assertInstanceOf(JsFunction::class, $valid);
        self::assertInstanceOf(\Closure::class, JsToPhp::compile($valid));
        self::assertNotNull((new Compiler())->compile($valid));
    }

    public function testBudgetedInterpreterMatchesTheSameNodeOracle(): void
    {
        $this->checkOracle(true);
    }

    private function checkOracle(bool $budgeted): Engine
    {
        $engine = new Engine();
        if ($budgeted) {
            $engine->setExecutionBudget(10);
        }
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/lexical-block-escape.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/lexical-block-escape.json',
            $engine->eval('JSON.stringify(lexicalBlockEscapeResult)'),
        );
        return $engine;
    }
}
