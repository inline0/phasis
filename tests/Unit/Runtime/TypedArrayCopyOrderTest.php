<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TypedArrayCopyOrderTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function executionModes(): iterable
    {
        yield 'VM' => [false];
        yield 'AST' => [true];
    }

    #[DataProvider('executionModes')]
    public function testPrototypeLookupPrecedesSourceValidation(bool $budgeted): void
    {
        $engine = new Engine();
        if ($budgeted) {
            $engine->setExecutionBudget(10);
        }
        $engine->execFile(__DIR__ . '/fixtures/typed-array-copy-order.cjs');
        $observations = $engine->eval('JSON.stringify(copyOrderObservations)');
        self::assertIsString($observations);
        self::assertJsonStringEqualsJsonFile(__DIR__ . '/fixtures/typed-array-copy-order.json', $observations);
        if (!$budgeted) {
            $copy = $engine->getGlobalEnv()->get('constructCopy');
            self::assertInstanceOf(JsFunction::class, $copy);
            self::assertNotNull($copy->compiled);
        }
        self::assertSame(0, $engine->getInterpreter()->getCallStack()->depth());
    }
}
