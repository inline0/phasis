<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComputedDataReadTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function executionModes(): iterable
    {
        yield 'VM' => [false];
        yield 'AST' => [true];
    }

    #[DataProvider('executionModes')]
    public function testLiveDataAndFallbackEffectsMatchNode(bool $budgeted): void
    {
        $engine = new Engine();
        if ($budgeted) {
            $engine->setExecutionBudget(10);
        }
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/computed-data-reads.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/computed-data-reads.json',
            $engine->eval('JSON.stringify(computedDataReads)'),
        );
        if (!$budgeted) {
            $function = $engine->getGlobalEnv()->get('readComputed');
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled);
        }
        self::assertSame(0, $engine->getInterpreter()->getCallStack()->depth());
    }
}
