<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use Phasis\Value\JsArrayBuffer;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsTypedArray;
use Phasis\Value\JsValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TypedArrayByteCopyTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function executionModes(): iterable
    {
        yield 'VM' => [false];
        yield 'AST' => [true];
    }

    #[DataProvider('executionModes')]
    public function testCopiesPreserveWindowsBitsAndFallbackSemantics(bool $budgeted): void
    {
        $engine = new Engine();
        if ($budgeted) {
            $engine->setExecutionBudget(10);
        }
        $engine->execFile(__DIR__ . '/fixtures/typed-array-byte-copy.cjs');
        $observations = $engine->eval('JSON.stringify(typedArrayCopyObservations)');
        self::assertIsString($observations);
        self::assertJsonStringEqualsJsonFile(__DIR__ . '/fixtures/typed-array-byte-copy.json', $observations);
    }

    public function testHostViewAndBufferSubclassesKeepTheirElementReads(): void
    {
        $engine = new Engine();
        $constructor = $engine->getGlobalEnv()->get('Uint8Array');
        self::assertInstanceOf(JsFunction::class, $constructor);
        $viewPrototype = $constructor->get('prototype');
        self::assertInstanceOf(JsObject::class, $viewPrototype);
        $bufferPrototype = $engine->getGlobalEnv()->getIntrinsic('ArrayBuffer.prototype');
        self::assertInstanceOf(JsObject::class, $bufferPrototype);
        $buffer = new JsArrayBuffer(2, $bufferPrototype);
        $buffer->setData("\x01\x02");
        $view = new class ('Uint8Array', $buffer, 0, 2, $viewPrototype) extends JsTypedArray {
            public int $reads = 0;

            public function getIndex(int $index): JsValue
            {
                ++$this->reads;
                return parent::getIndex($index);
            }
        };
        $engine->getGlobalEnv()->defineVar('source', $view);
        self::assertSame([1, 2], $engine->eval('Array.from(new Uint8Array(source))'));
        self::assertSame(2, $view->reads);

        $hostBuffer = new class (2, $bufferPrototype) extends JsArrayBuffer {
            public int $reads = 0;

            public function readBytes(int $offset, int $length): string
            {
                ++$this->reads;
                return parent::readBytes($offset, $length);
            }
        };
        $hostBuffer->setData("\x03\x04");
        $engine->getGlobalEnv()->defineVar('source', new JsTypedArray('Uint8Array', $hostBuffer, 0, 2, $viewPrototype));
        self::assertSame([3, 4], $engine->eval('Array.from(new Uint8Array(source))'));
        self::assertSame(2, $hostBuffer->reads);
    }

    public function testForeignConstructorKeepsItsViewAndBufferRealm(): void
    {
        $foreign = new Engine();
        $foreign->eval('function foreignCopy(source) { return new Uint8Array(source); } foreignCopy(new Uint8Array(0));');
        $caller = new Engine();
        $foreignValues = [
            'foreignCopy' => $foreign->getGlobalEnv()->get('foreignCopy'),
            'foreignViewPrototype' => $foreign->getGlobalEnv()->getIntrinsic('Uint8Array.prototype'),
            'foreignBufferPrototype' => $foreign->getGlobalEnv()->getIntrinsic('ArrayBuffer.prototype'),
        ];
        foreach ($foreignValues as $name => $value) {
            self::assertInstanceOf(JsValue::class, $value);
            $caller->getGlobalEnv()->defineVar($name, $value);
        }
        $sibling = new Engine();
        $sibling->eval('new Uint8Array(1);');
        for ($pass = 0; $pass < 3; $pass++) {
            self::assertSame([true, true, true, [1, 2]], $caller->eval(<<<'JS'
var source = new Uint8Array([1, 2]);
var result = foreignCopy(source);
[Object.getPrototypeOf(result) === foreignViewPrototype,
 Object.getPrototypeOf(result.buffer) === foreignBufferPrototype,
 result.buffer !== source.buffer, Array.from(result)];
JS));
        }
        $function = $foreign->getGlobalEnv()->get('foreignCopy');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNotNull($function->compiled);
    }
}
