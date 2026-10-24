<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsArray;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InlineTailCallTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function executionModes(): iterable
    {
        yield 'bytecode' => [false];
        yield 'interpreter' => [true];
    }

    #[DataProvider('executionModes')]
    public function testTailReturnsMatchNodeAcrossCallerAndCalleeKinds(bool $interpret): void
    {
        $engine = new Engine(node: true);
        if ($interpret) {
            $engine->setExecutionBudget(1_000_000);
        }
        $engine->execFile(__DIR__ . '/fixtures/inline-tail-calls.cjs');
        $result = $engine->eval('globalThis.__inlineTailObservations');
        self::assertIsString($result);
        self::assertJsonStringEqualsJsonFile(__DIR__ . '/fixtures/inline-tail-calls.expected.json', $result);
        if (!$interpret) {
            $functions = $engine->getGlobalEnv()->get('__inlineTailFunctions');
            self::assertInstanceOf(JsArray::class, $functions);
            foreach ($functions->getDenseElements() as $function) {
                self::assertInstanceOf(JsFunction::class, $function);
                self::assertTrue($function->vmInlineCallCache, $function->getName() . ' must use inline calls.');
            }
        }
    }

    public function testDeepTailRecursionFromAnInlineCalleeDoesNotRetainTheReturningFrame(): void
    {
        $engine = new Engine();
        self::assertSame([20000, 20001], $engine->eval(<<<'JS'
'use strict';
function tail(count, total) {
  return count === 0 ? total : tail(count - 1, total + 1);
}
function intermediate(count) { return tail(count, 0); }
function caller(count) {
  const first = intermediate(count);
  const second = intermediate(count + 1);
  return [first, second];
}
intermediate(1);
caller(1);
caller(20000);
JS));
        $function = $engine->getGlobalEnv()->get('intermediate');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertTrue($function->vmInlineCallCache);
    }

    public function testTailTargetObservesTheCallerAfterTheInlineFrameIsRemoved(): void
    {
        $engine = new Engine();
        $engine->setGlobal('stackDepth', fn (): int => $engine->getInterpreter()->getCallStack()->depth());
        self::assertSame([1, 1], $engine->eval(<<<'JS'
'use strict';
function returning() { return stackDepth(); }
function caller() { const value = returning(); return value; }
returning();
[caller(), caller()];
JS));
        self::assertSame(0, $engine->getInterpreter()->getCallStack()->depth());
    }
}
