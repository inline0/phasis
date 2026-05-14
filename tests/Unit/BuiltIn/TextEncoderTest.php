<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

class TextEncoderTest extends TestCase
{
    public function testEncodingIsUtf8(): void
    {
        $engine = new Engine();
        $result = $engine->eval('new TextEncoder().encoding;');
        $this->assertSame('utf-8', $result);
    }

    public function testEncodeAscii(): void
    {
        $engine = new Engine();
        $result = $engine->eval('Array.from(new TextEncoder().encode("hi"));');
        $this->assertSame([104, 105], $result);
    }

    public function testEncodeEmptyStringReturnsZeroLength(): void
    {
        $engine = new Engine();
        $result = $engine->eval('new TextEncoder().encode("").length;');
        $this->assertSame(0, $result);
    }

    public function testEncodeTwoByteCharacter(): void
    {
        // "é" -> U+00E9 -> 0xC3 0xA9.
        $engine = new Engine();
        $result = $engine->eval('Array.from(new TextEncoder().encode("é"));');
        $this->assertSame([195, 169], $result);
    }

    public function testEncodeThreeByteCharacter(): void
    {
        // "中" -> U+4E2D -> 0xE4 0xB8 0xAD.
        $engine = new Engine();
        $result = $engine->eval('Array.from(new TextEncoder().encode("中"));');
        $this->assertSame([228, 184, 173], $result);
    }

    public function testEncodeFourByteSurrogatePair(): void
    {
        // "😀" -> U+1F600 -> 0xF0 0x9F 0x98 0x80.
        $engine = new Engine();
        $result = $engine->eval('Array.from(new TextEncoder().encode("😀"));');
        $this->assertSame([240, 159, 152, 128], $result);
    }

    public function testEncodeLoneHighSurrogateProducesReplacement(): void
    {
        // U+D800 alone -> U+FFFD (EF BF BD).
        $engine = new Engine();
        $result = $engine->eval('Array.from(new TextEncoder().encode("\uD800"));');
        $this->assertSame([239, 191, 189], $result);
    }

    public function testEncodeLoneLowSurrogateProducesReplacement(): void
    {
        $engine = new Engine();
        $result = $engine->eval('Array.from(new TextEncoder().encode("\uDC00"));');
        $this->assertSame([239, 191, 189], $result);
    }

    public function testEncodeReturnsUint8Array(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'const b = new TextEncoder().encode("a"); '
            . '({type: b.constructor.name, len: b.length, byte0: b[0]});'
        );
        $this->assertSame('Uint8Array', $result['type']);
        $this->assertSame(1, $result['len']);
        $this->assertSame(97, $result['byte0']);
    }

    public function testEncodeIntoFullBuffer(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const enc = new TextEncoder();
const buf = new Uint8Array(5);
const r = enc.encodeInto("hello", buf);
({read: r.read, written: r.written, bytes: Array.from(buf)});
JS);
        $this->assertSame(5, $result['read']);
        $this->assertSame(5, $result['written']);
        $this->assertSame([104, 101, 108, 108, 111], $result['bytes']);
    }

    public function testEncodeIntoStopsBeforePartialCodepoint(): void
    {
        // "héllo" -> "h" (1) + "é" (2) + "l" (1) + ... fits in 3 if we stop after "é".
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const enc = new TextEncoder();
const buf = new Uint8Array(3);
const r = enc.encodeInto("héllo", buf);
({read: r.read, written: r.written});
JS);
        $this->assertSame(2, $result['read']);
        $this->assertSame(3, $result['written']);
    }

    public function testEncodeIntoSurrogatePairCountsTwoCodeUnits(): void
    {
        // "😀" -> 4 bytes, 2 UTF-16 code units.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const enc = new TextEncoder();
const buf = new Uint8Array(4);
const r = enc.encodeInto("😀", buf);
({read: r.read, written: r.written});
JS);
        $this->assertSame(2, $result['read']);
        $this->assertSame(4, $result['written']);
    }

    public function testEncodeIntoInsufficientBufferForSurrogatePairWritesNothing(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const enc = new TextEncoder();
const buf = new Uint8Array(3);
const r = enc.encodeInto("😀", buf);
({read: r.read, written: r.written});
JS);
        $this->assertSame(0, $result['read']);
        $this->assertSame(0, $result['written']);
    }

    public function testConstructorThrowsWithoutNew(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
let msg = "noop";
try { TextEncoder(); } catch (e) { msg = e.constructor.name; }
msg;
JS);
        $this->assertSame('TypeError', $result);
    }

    public function testToStringTag(): void
    {
        $engine = new Engine();
        $result = $engine->eval('Object.prototype.toString.call(new TextEncoder());');
        $this->assertSame('[object TextEncoder]', $result);
    }
}
