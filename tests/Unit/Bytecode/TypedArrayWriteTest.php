<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use Phasis\Value\JsArrayBuffer;
use Phasis\Value\JsNumber;
use Phasis\Value\JsTypedArray;
use Phasis\Value\JsValue;
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

    public function testNumericStoresRetainHostTypedArrayOverrides(): void
    {
        $buffer = new JsArrayBuffer(2);
        $view = new class ('Uint8Array', $buffer, 0, 2) extends JsTypedArray {
            public function setIndex(int $index, JsValue $value): void
            {
                parent::setIndex($index + 1, $value);
            }
        };
        self::assertTrue($view->setIntegerIndex(0, JsNumber::of(31)));
        self::assertSame('001f', bin2hex($buffer->getData()));
    }

    public function testPrimitiveNumericStoresStillRejectImmutableBuffers(): void
    {
        $buffer = new JsArrayBuffer(1);
        $view = new JsTypedArray('Uint8Array', $buffer, 0, 1);
        $view->setIntegerIndex(0, JsNumber::of(7));
        $buffer->makeImmutable();
        self::assertFalse($view->setIntegerIndex(0, JsNumber::of(31)));
        self::assertSame('07', bin2hex($buffer->getData()));
    }
}
