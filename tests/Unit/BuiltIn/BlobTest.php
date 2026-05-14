<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

class BlobTest extends TestCase
{
    public function testBlobGlobalIsDefined(): void
    {
        $engine = new Engine();
        $this->assertSame('function', $engine->eval('typeof Blob;'));
    }

    public function testEmptyBlob(): void
    {
        $engine = new Engine();
        $result = $engine->eval('const b = new Blob(); ({size: b.size, type: b.type});');
        $this->assertSame(0, $result['size']);
        $this->assertSame('', $result['type']);
    }

    public function testBlobFromStringParts(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'const b = new Blob(["hello ", "world"], { type: "text/plain" }); '
            . '({size: b.size, type: b.type});'
        );
        $this->assertSame(11, $result['size']);
        $this->assertSame('text/plain', $result['type']);
    }

    public function testBlobTypeLowercases(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'new Blob([], { type: "Text/HTML" }).type;'
        );
        $this->assertSame('text/html', $result);
    }

    public function testBlobTypeStripsNonAscii(): void
    {
        $engine = new Engine();
        // Non-ASCII byte should cause type to be set to empty string per spec.
        $result = $engine->eval(
            'new Blob([], { type: "text/pé" }).type;'
        );
        $this->assertSame('', $result);
    }

    public function testBlobFromArrayBuffer(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const ab = new Uint8Array([72, 105]).buffer;
const b = new Blob([ab]);
({size: b.size});
JS);
        $this->assertSame(2, $result['size']);
    }

    public function testBlobFromUint8Array(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const bytes = new Uint8Array([65, 66, 67]);
const b = new Blob([bytes]);
({size: b.size});
JS);
        $this->assertSame(3, $result['size']);
    }

    public function testBlobFromNestedBlob(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const inner = new Blob(["hello"]);
const outer = new Blob([inner, " world"]);
({size: outer.size});
JS);
        $this->assertSame(11, $result['size']);
    }

    public function testBlobFromMixedParts(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const a = new Uint8Array([72, 105]);    // "Hi"
const b = " there";                     // " there"
const blob = new Blob([a, b, new Blob(["!"])]);
({size: blob.size});
JS);
        $this->assertSame(2 + 6 + 1, $result['size']);
    }

    public function testBlobSliceFullRange(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const b = new Blob(["hello world"]);
const slice = b.slice();
slice.size;
JS);
        $this->assertSame(11, $result);
    }

    public function testBlobSliceRange(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const b = new Blob(["hello world"]);
const slice = b.slice(6, 11);
({size: slice.size, type: slice.type});
JS);
        $this->assertSame(5, $result['size']);
        $this->assertSame('', $result['type']);
    }

    public function testBlobSliceWithContentType(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const b = new Blob(["hello world"], { type: "application/octet-stream" });
const s = b.slice(0, 5, "text/plain");
({size: s.size, type: s.type});
JS);
        $this->assertSame(5, $result['size']);
        $this->assertSame('text/plain', $result['type']);
    }

    public function testBlobSliceNegativeIndex(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const b = new Blob(["hello world"]);
const s = b.slice(-5);
s.size;
JS);
        $this->assertSame(5, $result);
    }

    public function testBlobSliceRoundTripText(): void
    {
        $engine = new Engine();
        // Script schedules the .then() handler; the microtask drain at the
        // end of eval() runs it. Read the captured global in a separate
        // eval() so we observe the post-drain state.
        $engine->eval(<<<'JS'
globalThis.__out = "";
const b = new Blob(["hello world"]);
const slice = b.slice(6, 11);
slice.text().then(s => { globalThis.__out = s; });
JS);
        $this->assertSame('world', $engine->eval('globalThis.__out;'));
    }

    public function testBlobTextReturnsPromise(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const b = new Blob(["hi"]);
const p = b.text();
typeof p.then;
JS);
        $this->assertSame('function', $result);
    }

    public function testBlobTextResolvesToString(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
globalThis.__captured = null;
const b = new Blob(["hello ", "world"]);
b.text().then(s => { globalThis.__captured = s; });
JS);
        $this->assertSame('hello world', $engine->eval('globalThis.__captured;'));
    }

    public function testBlobTextDecodesUtf8(): void
    {
        $engine = new Engine();
        // "héllo" UTF-8 byte sequence
        $engine->eval(<<<'JS'
globalThis.__captured = null;
const bytes = new Uint8Array([0x68, 0xC3, 0xA9, 0x6C, 0x6C, 0x6F]);
const b = new Blob([bytes]);
b.text().then(s => { globalThis.__captured = s; });
JS);
        $this->assertSame('héllo', $engine->eval('globalThis.__captured;'));
    }

    public function testBlobArrayBufferReturnsPromise(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const b = new Blob(["abc"]);
const p = b.arrayBuffer();
typeof p.then;
JS);
        $this->assertSame('function', $result);
    }

    public function testBlobArrayBufferResolvesToArrayBuffer(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
globalThis.__len = -1;
globalThis.__isAB = false;
const b = new Blob(["abc"]);
b.arrayBuffer().then(buf => {
    globalThis.__len = buf.byteLength;
    globalThis.__isAB = buf instanceof ArrayBuffer;
});
JS);
        $result = $engine->eval('({len: globalThis.__len, isAB: globalThis.__isAB});');
        $this->assertSame(3, $result['len']);
        $this->assertTrue($result['isAB']);
    }

    public function testBlobBytesReturnsUint8Array(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
globalThis.__arr = null;
const b = new Blob(["abc"]);
b.bytes().then(u => { globalThis.__arr = Array.from(u); });
JS);
        $this->assertSame([97, 98, 99], $engine->eval('globalThis.__arr;'));
    }

    public function testBlobStreamThrowsTypeError(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const b = new Blob(["abc"]);
let err = null;
try { b.stream(); } catch (e) { err = e.name + ":" + e.message.includes("ReadableStream"); }
err;
JS);
        $this->assertSame('TypeError:true', $result);
    }

    public function testBlobToStringTag(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'Object.prototype.toString.call(new Blob());'
        );
        $this->assertSame('[object Blob]', $result);
    }

    public function testBlobConstructorWithoutNewThrows(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
let err = null;
try { Blob(); } catch (e) { err = e.name; }
err;
JS);
        $this->assertSame('TypeError', $result);
    }

    public function testBlobConstructorRejectsNonIterableParts(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
let err = null;
try { new Blob(42); } catch (e) { err = e.name; }
err;
JS);
        $this->assertSame('TypeError', $result);
    }

    public function testBlobConstructorAcceptsNullOptions(): void
    {
        // Per spec we treat null/undefined as "no options"; bare new Blob([]) should not throw.
        $engine = new Engine();
        $result = $engine->eval(
            'new Blob([], undefined).size;'
        );
        $this->assertSame(0, $result);
    }
}
