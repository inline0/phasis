<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class ArrayAssignmentTest extends TestCase
{
    public function testCompiledAssignmentsPreserveNodeReferencesAndIteratorOrder(): void
    {
        $engine = new Engine();
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/array-assignments.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/array-assignments.json',
            $engine->eval('JSON.stringify(arrayAssignmentResult)'),
        );
        $functions = ['first', 'pair', 'elided', 'empty', 'rest', 'duplicate', 'captured',
            'member', 'restMember', 'receiver', 'changedBase', 'partial', 'arrow'];
        foreach ($functions as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled, $name . ' must exercise the bytecode path.');
        }
    }

    public function testInterpretedAssignmentsMatchTheSameNodeOracle(): void
    {
        $engine = new Engine();
        $engine->setExecutionBudget(10);
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/array-assignments.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/array-assignments.json',
            $engine->eval('JSON.stringify(arrayAssignmentResult)'),
        );
        $function = $engine->getGlobalEnv()->get('first');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNull($function->compiled);
    }
}
