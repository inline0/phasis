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

final class TypedArraySetByteCopyTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function executionModes(): iterable
    {
        yield 'VM' => [false];
        yield 'AST' => [true];
    }

    #[DataProvider('executionModes')]
    public function testByteCopiesAndFallbacksMatchNode(bool $budgeted): void
    {
        $engine = new Engine();
        if ($budgeted) {
            $engine->setExecutionBudget(10);
        }
        $engine->execFile(__DIR__ . '/fixtures/typed-array-set-byte-copy.cjs');
        $observations = $engine->eval('JSON.stringify(typedArraySetObservations)');
        self::assertIsString($observations);
        self::assertJsonStringEqualsJsonFile(__DIR__ . '/fixtures/typed-array-set-byte-copy.json', $observations);
        self::assertSame(0, $engine->getInterpreter()->getCallStack()->depth());
    }

    public function testHostViewsAndBuffersKeepTheirElementHooks(): void
    {
        $engine = new Engine();
        $constructor = $engine->getGlobalEnv()->get('Uint8Array');
        self::assertInstanceOf(JsFunction::class, $constructor);
        $viewPrototype = $constructor->get('prototype');
        self::assertInstanceOf(JsObject::class, $viewPrototype);
        $bufferPrototype = $engine->getGlobalEnv()->getIntrinsic('ArrayBuffer.prototype');
        self::assertInstanceOf(JsObject::class, $bufferPrototype);
        $sourceBuffer = new JsArrayBuffer(2, $bufferPrototype);
        $sourceBuffer->setData("\x01\x02");
        $hostSource = new class ('Uint8Array', $sourceBuffer, 0, 2, $viewPrototype) extends JsTypedArray {
            public int $reads = 0;

            public function getIndex(int $index): JsValue
            {
                ++$this->reads;
                return parent::getIndex($index);
            }
        };
        $targetBuffer = new JsArrayBuffer(2, $bufferPrototype);
        $target = new JsTypedArray('Uint8Array', $targetBuffer, 0, 2, $viewPrototype);
        $engine->getGlobalEnv()->defineVar('source', $hostSource);
        $engine->getGlobalEnv()->defineVar('target', $target);
        $engine->eval('target.set(source);');
        self::assertSame("\x01\x02", $targetBuffer->getData());
        self::assertSame(2, $hostSource->reads);

        $hostTarget = new class ('Uint8Array', $targetBuffer, 0, 2, $viewPrototype) extends JsTypedArray {
            public int $writes = 0;

            public function setIndex(int $index, JsValue $value): void
            {
                ++$this->writes;
                parent::setIndex($index, $value);
            }
        };
        $engine->getGlobalEnv()->defineVar('source', new JsTypedArray('Uint8Array', $sourceBuffer, 0, 2, $viewPrototype));
        $engine->getGlobalEnv()->defineVar('target', $hostTarget);
        $engine->eval('target.set(source);');
        self::assertSame(2, $hostTarget->writes);

        $hostSourceBuffer = new class (2, $bufferPrototype) extends JsArrayBuffer {
            public int $reads = 0;

            public function readBytes(int $offset, int $length): string
            {
                ++$this->reads;
                return parent::readBytes($offset, $length);
            }
        };
        $hostSourceBuffer->setData("\x03\x04");
        $engine->getGlobalEnv()->defineVar('source', new JsTypedArray('Uint8Array', $hostSourceBuffer, 0, 2, $viewPrototype));
        $engine->getGlobalEnv()->defineVar('target', $target);
        $engine->eval('target.set(source);');
        self::assertSame("\x03\x04", $targetBuffer->getData());
        self::assertSame(2, $hostSourceBuffer->reads);

        $hostTargetBuffer = new class (2, $bufferPrototype) extends JsArrayBuffer {
            public int $writes = 0;

            public function writeBytes(int $offset, string $bytes): void
            {
                ++$this->writes;
                parent::writeBytes($offset, $bytes);
            }
        };
        $engine->getGlobalEnv()->defineVar('source', new JsTypedArray('Uint8Array', $sourceBuffer, 0, 2, $viewPrototype));
        $engine->getGlobalEnv()->defineVar('target', new JsTypedArray('Uint8Array', $hostTargetBuffer, 0, 2, $viewPrototype));
        $engine->eval('target.set(source);');
        self::assertSame("\x01\x02", $hostTargetBuffer->getData());
        self::assertSame(2, $hostTargetBuffer->writes);
    }

    public function testImmutableTargetRejectsBeforeOffsetCoercionAndImmutableSourceCanBeRead(): void
    {
        $engine = new Engine();
        self::assertSame([true, 0, [1, 2], [0, 1, 2, 0]], $engine->eval(<<<'JS'
const immutable = new Uint8Array([1, 2]).buffer.sliceToImmutable(0);
const source = new Uint8Array(immutable);
const target = new Uint8Array(4);
let calls = 0, threw = false;
try { source.set(new Uint8Array([9]), {valueOf() {calls++; return 0;}}); }
catch (error) {threw = error instanceof TypeError;}
target.set(source, 1);
[threw, calls, Array.from(source), Array.from(target)];
JS));
    }

    public function testForeignNativeMethodAndViewsKeepTheirRealms(): void
    {
        $foreign = new Engine();
        $foreign->eval('var source = new Uint8Array([1, 2]); var setter = Uint8Array.prototype.set;');
        $caller = new Engine();
        foreach (['source', 'setter'] as $name) {
            $caller->getGlobalEnv()->defineVar($name, $foreign->getGlobalEnv()->get($name));
        }
        $sourcePrototype = $foreign->getGlobalEnv()->getIntrinsic('Uint8Array.prototype');
        self::assertInstanceOf(JsObject::class, $sourcePrototype);
        $caller->getGlobalEnv()->defineVar('sourcePrototype', $sourcePrototype);
        $sibling = new Engine();
        $sibling->eval('new Uint8Array(1);');
        self::assertSame([true, [0, 1, 2, 0], true, true], $caller->eval(<<<'JS'
const target = new Uint8Array(4);
const result = setter.call(target, source, 1);
[result === undefined, Array.from(target), Object.getPrototypeOf(source) === sourcePrototype,
 Object.getPrototypeOf(target) === Uint8Array.prototype];
JS));
    }
}
