<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TypedArrayReadTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function executionModes(): iterable
    {
        yield 'bytecode' => [false];
        yield 'interpreter' => [true];
    }

    #[DataProvider('executionModes')]
    public function testIndexedReadsPreserveNodeKeysAndBufferChanges(bool $interpret): void
    {
        $engine = new Engine();
        if ($interpret) {
            $engine->setExecutionBudget(10);
        }
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/typed-array-reads.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/typed-array-reads.json',
            $engine->eval('JSON.stringify(typedArrayReadResult)'),
        );
        $function = $engine->getGlobalEnv()->get('indexedRead');
        self::assertInstanceOf(JsFunction::class, $function);
        if ($interpret) {
            self::assertNull($function->compiled);
        } else {
            self::assertNotNull($function->compiled, 'Numeric reads must exercise bytecode.');
        }
    }
}
