<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class ArrayBindingTest extends TestCase
{
    public function testCompiledArrayBindingsMatchNodeIterationAndClosureOrder(): void
    {
        $engine = new Engine();
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/array-bindings.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/array-bindings.json',
            $engine->eval('JSON.stringify(arrayBindingResult)'),
        );
        foreach (['first', 'pair', 'elided', 'empty', 'rest', 'duplicate', 'captured'] as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled, $name . ' must exercise the bytecode path.');
        }
    }

    public function testInterpreterArrayBindingsMatchTheSameNodeOracle(): void
    {
        $engine = new Engine();
        $engine->setExecutionBudget(10);
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/array-bindings.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/array-bindings.json',
            $engine->eval('JSON.stringify(arrayBindingResult)'),
        );
        $function = $engine->getGlobalEnv()->get('first');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNull($function->compiled);
    }
}
