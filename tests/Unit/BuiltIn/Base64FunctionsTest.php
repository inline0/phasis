<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

class Base64FunctionsTest extends TestCase
{
    public function testBtoaSimpleAscii(): void
    {
        $engine = new Engine();
        $result = $engine->eval('btoa("hello");');
        $this->assertSame('aGVsbG8=', $result);
    }

    public function testBtoaEmptyString(): void
    {
        $engine = new Engine();
        $result = $engine->eval('btoa("");');
        $this->assertSame('', $result);
    }

    public function testRoundTripAscii(): void
    {
        $engine = new Engine();
        $result = $engine->eval('atob(btoa("the quick brown fox jumps over the lazy dog"));');
        $this->assertSame('the quick brown fox jumps over the lazy dog', $result);
    }

    public function testRoundTripLatin1WithHighBytes(): void
    {
        // \xFF is the highest valid Latin-1 byte. Should round-trip.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
var s = String.fromCharCode(0xC9, 0xFF, 0x00, 0x7F);
var roundTrip = atob(btoa(s));
[roundTrip.length, roundTrip.charCodeAt(0), roundTrip.charCodeAt(1),
 roundTrip.charCodeAt(2), roundTrip.charCodeAt(3)];
JS);
        $this->assertSame([4, 0xC9, 0xFF, 0, 0x7F], $result);
    }

    public function testBtoaThrowsOnNonLatin1(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
var err;
try {
  btoa("café"); // é is U+00E9 — OK
  btoa("Ā"); // U+0100 — out of Latin-1, MUST throw
  err = "no-throw";
} catch (e) {
  err = e;
}
[err.name, err.code, err instanceof DOMException];
JS);
        $this->assertSame(['InvalidCharacterError', 5, true], $result);
    }

    public function testBtoaAllowsHighLatin1(): void
    {
        // Sanity-check: codepoints in 0x80..0xFF range MUST be allowed.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
btoa("café");
JS);
        // "café" in Latin-1 is c=0x63 a=0x61 f=0x66 é=0xE9 -> Y2Fmw6k= is UTF-8.
        // But spec says btoa treats the JS string as a sequence of code units
        // (each < 256), so "café" (with é as a single U+00E9 code unit) encodes
        // as Y2Fm6Q==.
        $this->assertSame('Y2Fm6Q==', $result);
    }

    public function testAtobStripsWhitespace(): void
    {
        $engine = new Engine();
        // base64 of "hi" is "aGk=" — pad it with all five ASCII whitespace chars.
        $result = $engine->eval('atob(" a\tG\nk\f=\r");');
        $this->assertSame('hi', $result);
    }

    public function testAtobAcceptsUnpaddedInput(): void
    {
        $engine = new Engine();
        // "any" base64-encoded is YW55. Without padding, length % 4 = 0 — fine.
        $result = $engine->eval('atob("YW55");');
        $this->assertSame('any', $result);
    }

    public function testAtobThrowsOnGarbage(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
var err;
try { atob("!!not-base64!!"); err = "no-throw"; }
catch (e) { err = [e.name, e.code]; }
err;
JS);
        $this->assertSame(['InvalidCharacterError', 5], $result);
    }

    public function testAtobThrowsOnModFourEqualsOne(): void
    {
        // Length-mod-4 == 1 is unrecoverable per Infra step 3.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
var err;
try { atob("a"); err = "no-throw"; }
catch (e) { err = e.name; }
err;
JS);
        $this->assertSame('InvalidCharacterError', $result);
    }

    public function testAtobErrorIsDomExceptionInstance(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
var caught;
try { atob("Ā"); } catch (e) { caught = e; }
caught instanceof DOMException && caught instanceof Error;
JS);
        $this->assertTrue($result);
    }
}
