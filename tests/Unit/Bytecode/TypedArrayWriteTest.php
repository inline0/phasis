<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class TypedArrayWriteTest extends TestCase
{
    public function testNumericStoresPreserveNodeCoercionsAndBufferChanges(): void
    {
        $engine = new Engine();
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/typed-array-writes.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/typed-array-writes.json',
            $engine->eval('JSON.stringify(typedArrayWriteResult)'),
        );
        foreach (['strictStore', 'sloppyStore'] as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled, 'The numeric stores must exercise bytecode.');
        }
    }

    public function testInterpreterMatchesTheSameNodeOracle(): void
    {
        $engine = new Engine();
        $engine->setExecutionBudget(10);
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/typed-array-writes.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/typed-array-writes.json',
            $engine->eval('JSON.stringify(typedArrayWriteResult)'),
        );
    }

    public function testImmutableViewsRejectWritesBeforeValueConversion(): void
    {
        $engine = new Engine();
        self::assertSame([false, 'TypeError', 0, 7], $engine->eval(<<<'JS'
const immutable = new Uint8Array(new Uint8Array([7]).buffer.transferToImmutable());
let coercions = 0;
const value = {valueOf() { coercions++; return 9; }};
function loose(view, key, value) { view[key] = value; return view[key] === value; }
function strict(view, key, value) { 'use strict'; view[key] = value; }
const result = [loose(immutable, 0, value)];
try { strict(immutable, 0, value); } catch (error) { result.push(error.name); }
result.push(coercions, immutable[0]);
result;
JS));
    }
}
