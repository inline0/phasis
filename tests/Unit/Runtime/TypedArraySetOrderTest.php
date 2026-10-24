<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TypedArraySetOrderTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function executionModes(): iterable
    {
        yield 'VM' => [false];
        yield 'AST' => [true];
    }

    #[DataProvider('executionModes')]
    public function testBoundsErrorsPrecedeContentMismatchAfterSourceValidation(bool $budgeted): void
    {
        $engine = new Engine();
        if ($budgeted) {
            $engine->setExecutionBudget(10);
        }
        $engine->execFile(__DIR__ . '/fixtures/typed-array-set-order.cjs');
        $observations = $engine->eval('JSON.stringify(typedArraySetOrderObservations)');
        self::assertIsString($observations);
        self::assertJsonStringEqualsJsonFile(__DIR__ . '/fixtures/typed-array-set-order.json', $observations);
        self::assertSame(0, $engine->getInterpreter()->getCallStack()->depth());
    }
}
