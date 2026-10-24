<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class RelationalObjectTest extends TestCase
{
    public function testCompiledObjectOperatorsMatchNode(): void
    {
        $engine = new Engine();
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/relational-objects.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/relational-objects.json',
            $engine->eval('JSON.stringify(relationalObjectResult)'),
        );
        foreach (['contains', 'instance', 'containsOrdered', 'instanceOrdered'] as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled, $name . ' must exercise the bytecode path.');
        }
    }

    public function testInterpreterObjectOperatorsMatchTheSameNodeOracle(): void
    {
        $engine = new Engine();
        $engine->setExecutionBudget(10);
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/relational-objects.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/relational-objects.json',
            $engine->eval('JSON.stringify(relationalObjectResult)'),
        );
        $function = $engine->getGlobalEnv()->get('contains');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNull($function->compiled);
    }
}
