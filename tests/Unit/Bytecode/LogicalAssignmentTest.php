<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class LogicalAssignmentTest extends TestCase
{
    public function testCompiledAssignmentsPreserveNodeShortCircuitAndReferenceSemantics(): void
    {
        $engine = new Engine();
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/logical-assignments.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/logical-assignments.json',
            $engine->eval('JSON.stringify(logicalAssignmentResult)'),
        );
        foreach (['assignAnd', 'assignOr', 'assignNull', 'memberAnd', 'memberOr', 'memberNull', 'captured'] as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled, $name . ' must exercise bytecode.');
        }
    }

    public function testInterpreterMatchesTheSameOracle(): void
    {
        $engine = new Engine();
        $engine->setExecutionBudget(10);
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/logical-assignments.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/logical-assignments.json',
            $engine->eval('JSON.stringify(logicalAssignmentResult)'),
        );
    }
}
