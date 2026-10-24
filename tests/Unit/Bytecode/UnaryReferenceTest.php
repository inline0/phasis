<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class UnaryReferenceTest extends TestCase
{
    public function testInterpreterDeletionMatchesTheSameNodeOracle(): void
    {
        $engine = new Engine();
        $engine->setExecutionBudget(10);
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/unary-references.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/unary-references.json',
            $engine->eval('JSON.stringify(unaryReferenceResult)'),
        );
        $function = $engine->getGlobalEnv()->get('deleteOrdered');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNull($function->compiled, 'The budgeted engine must exercise the interpreter path.');
    }

    public function testCompiledTypeofAndDeleteMatchNodeReferenceSemantics(): void
    {
        $engine = new Engine();
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/unary-references.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/unary-references.json',
            $engine->eval('JSON.stringify(unaryReferenceResult)'),
        );
        $names = [
            'typeOfValue', 'unknownType', 'lexicalType', 'shadowedUndefined', 'deleteMember', 'deleteComputed',
            'deleteStrict', 'deleteGlobal', 'deleteLocal', 'deleteSideEffect', 'deleteOrdered',
        ];
        foreach ($names as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled, $name . ' must exercise the bytecode path.');
        }
    }
}
