<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

class TextDecoderTest extends TestCase
{
    public function testDefaultsToUtf8(): void
    {
        $engine = new Engine();
        $result = $engine->eval('new TextDecoder().encoding;');
        $this->assertSame('utf-8', $result);
    }

    public function testDefaultFlags(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const d = new TextDecoder();
({fatal: d.fatal, ignoreBOM: d.ignoreBOM});
JS);
        $this->assertFalse($result['fatal']);
        $this->assertFalse($result['ignoreBOM']);
    }

    public function testCanonicalEncodingAliases(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
[
    new TextDecoder("UTF-8").encoding,
    new TextDecoder("utf8").encoding,
    new TextDecoder("UTF-16").encoding,
    new TextDecoder("utf-16le").encoding,
    new TextDecoder("UTF-16BE").encoding,
];
JS);
        $this->assertSame(['utf-8', 'utf-8', 'utf-16le', 'utf-16le', 'utf-16be'], $result);
    }

    public function testUnknownEncodingThrowsRangeError(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
let name = "none";
try { new TextDecoder("bogus"); } catch (e) { name = e.constructor.name; }
name;
JS);
        $this->assertSame('RangeError', $result);
    }

    public function testDecodeRoundTripUtf8(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const enc = new TextEncoder();
const dec = new TextDecoder();
dec.decode(enc.encode("café 中 😀"));
JS);
        $this->assertSame('café 中 😀', $result);
    }

    public function testDecodeWithUndefinedInputReturnsEmpty(): void
    {
        $engine = new Engine();
        $result = $engine->eval('new TextDecoder().decode();');
        $this->assertSame('', $result);
    }

    public function testDecodeAcceptsArrayBuffer(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const buf = new Uint8Array([104, 105]).buffer;
new TextDecoder().decode(buf);
JS);
        $this->assertSame('hi', $result);
    }

    public function testDecodeAcceptsDataView(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const ab = new Uint8Array([104, 105]).buffer;
const dv = new DataView(ab);
new TextDecoder().decode(dv);
JS);
        $this->assertSame('hi', $result);
    }

    public function testDecodeStripsBomByDefault(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const bytes = new Uint8Array([0xEF, 0xBB, 0xBF, 0x68, 0x69]);
new TextDecoder().decode(bytes);
JS);
        $this->assertSame('hi', $result);
    }

    public function testDecodeKeepsBomWhenIgnoreBomTrue(): void
    {
        $engine = new Engine();
        // U+FEFF -> in UTF-8 is EF BB BF, so the JS string starts with U+FEFF + "hi".
        $result = $engine->eval(<<<'JS'
const bytes = new Uint8Array([0xEF, 0xBB, 0xBF, 0x68, 0x69]);
const d = new TextDecoder("utf-8", {ignoreBOM: true});
const s = d.decode(bytes);
[s.length, s.charCodeAt(0), s.charCodeAt(1), s.charCodeAt(2)];
JS);
        $this->assertSame([3, 65279, 104, 105], $result);
    }

    public function testFatalRejectsInvalidUtf8(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
let name = "none";
const d = new TextDecoder("utf-8", {fatal: true});
try {
    d.decode(new Uint8Array([0xC0])); // Invalid leading byte.
} catch (e) {
    name = e.constructor.name;
}
name;
JS);
        $this->assertSame('TypeError', $result);
    }

    public function testNonFatalReplacesInvalidUtf8(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const d = new TextDecoder();
const out = d.decode(new Uint8Array([0xC0, 0x61])); // bad lead + "a"
[out.length, out.charCodeAt(0), out.charCodeAt(1)];
JS);
        // U+FFFD (0xFFFD) then "a" (0x61).
        $this->assertSame([2, 65533, 97], $result);
    }

    public function testUtf16LeDecodesAscii(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
new TextDecoder("utf-16le").decode(new Uint8Array([0x48, 0, 0x69, 0]));
JS);
        $this->assertSame('Hi', $result);
    }

    public function testUtf16BeDecodesAscii(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
new TextDecoder("utf-16be").decode(new Uint8Array([0, 0x48, 0, 0x69]));
JS);
        $this->assertSame('Hi', $result);
    }

    public function testUtf16LeDecodesSurrogatePair(): void
    {
        $engine = new Engine();
        // U+1F600 -> surrogate pair D83D DE00, little-endian bytes: 3D D8 00 DE.
        $result = $engine->eval(<<<'JS'
new TextDecoder("utf-16le").decode(new Uint8Array([0x3D, 0xD8, 0x00, 0xDE]));
JS);
        $this->assertSame('😀', $result);
    }

    public function testStreamingUtf8(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const d = new TextDecoder("utf-8");
// "中" -> E4 B8 AD; feed in two chunks.
const a = d.decode(new Uint8Array([0xE4, 0xB8]), {stream: true});
const b = d.decode(new Uint8Array([0xAD]));
[a, b];
JS);
        $this->assertSame(['', '中'], $result);
    }

    public function testStreamingFlushesIncompleteAtEnd(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const d = new TextDecoder("utf-8");
const a = d.decode(new Uint8Array([0xE4, 0xB8]), {stream: true});
// End of stream without finishing the sequence: emit replacement.
const b = d.decode();
[a, b.length, b.charCodeAt(0)];
JS);
        $this->assertSame(['', 1, 65533], $result);
    }

    public function testStreamingFatalThrowsAtEndOfStream(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const d = new TextDecoder("utf-8", {fatal: true});
let name = "none";
d.decode(new Uint8Array([0xE4, 0xB8]), {stream: true});
try { d.decode(); } catch (e) { name = e.constructor.name; }
name;
JS);
        $this->assertSame('TypeError', $result);
    }

    public function testStreamingUtf16Le(): void
    {
        $engine = new Engine();
        // Split across a code unit boundary inside the data; "Hello" in utf-16le.
        // H=48 00, e=65 00, l=6C 00, l=6C 00, o=6F 00.
        // First chunk: 48 00 65 -> can decode "H", hold 65.
        $result = $engine->eval(<<<'JS'
const d = new TextDecoder("utf-16le");
const a = d.decode(new Uint8Array([0x48, 0x00, 0x65]), {stream: true});
const b = d.decode(new Uint8Array([0x00, 0x6C, 0x00, 0x6C, 0x00, 0x6F, 0x00]));
[a, b];
JS);
        $this->assertSame(['H', 'ello'], $result);
    }

    public function testConstructorThrowsWithoutNew(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
let n = "none";
try { TextDecoder(); } catch (e) { n = e.constructor.name; }
n;
JS);
        $this->assertSame('TypeError', $result);
    }

    public function testDecodeNonBufferSourceThrows(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
let n = "none";
try { new TextDecoder().decode("not bytes"); } catch (e) { n = e.constructor.name; }
n;
JS);
        $this->assertSame('TypeError', $result);
    }

    public function testToStringTag(): void
    {
        $engine = new Engine();
        $result = $engine->eval('Object.prototype.toString.call(new TextDecoder());');
        $this->assertSame('[object TextDecoder]', $result);
    }
}
