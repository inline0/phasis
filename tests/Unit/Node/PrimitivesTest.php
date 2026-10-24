<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Node;

use Phasis\Engine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrimitivesTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function modules(): iterable
    {
        foreach (['buffer', 'events', 'stream', 'stream-web'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('modules')]
    public function testPrimitivesMatchNodeOracle(string $name): void
    {
        $engine = new Engine(node: true);
        $engine->setExecutionBudget(20);
        $engine->execFile(__DIR__ . '/fixtures/primitives/' . $name . '.mjs');
        self::assertNull($engine->eval('globalThis.__nodePrimitiveError'), $engine->getConsoleOutput());
        $result = $engine->eval('globalThis.__nodePrimitiveResult');
        self::assertIsString($result, 'The asynchronous probe must finish.');
        self::assertJsonStringEqualsJsonFile(__DIR__ . '/fixtures/primitives/' . $name . '.json', $result);
    }
}
