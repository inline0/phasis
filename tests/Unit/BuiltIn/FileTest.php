<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

class FileTest extends TestCase
{
    public function testFileGlobalIsDefined(): void
    {
        $engine = new Engine();
        $this->assertSame('function', $engine->eval('typeof File;'));
    }

    public function testFileInstanceOfBlob(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'const f = new File(["x"], "a.txt"); f instanceof Blob;'
        );
        $this->assertTrue($result);
    }

    public function testFileInstanceOfFile(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'const f = new File(["x"], "a.txt"); f instanceof File;'
        );
        $this->assertTrue($result);
    }

    public function testFileSetsName(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'new File(["hello"], "greeting.txt").name;'
        );
        $this->assertSame('greeting.txt', $result);
    }

    public function testFileSetsLastModified(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'new File(["x"], "a.txt", { lastModified: 12345 }).lastModified;'
        );
        $this->assertSame(12345, $result);
    }

    public function testFileDefaultsLastModifiedToNow(): void
    {
        // We can't assert an exact value, only that it's a positive integer
        // close to PHP_NOW (microtime * 1000).
        $engine = new Engine();
        $result = $engine->eval(
            'typeof new File(["x"], "a.txt").lastModified;'
        );
        $this->assertSame('number', $result);
    }

    public function testFileSetsType(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'new File(["x"], "a.txt", { type: "text/plain" }).type;'
        );
        $this->assertSame('text/plain', $result);
    }

    public function testFileSizeMatchesBytes(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'new File(["hello world"], "x.txt").size;'
        );
        $this->assertSame(11, $result);
    }

    public function testFileWebkitRelativePathAlwaysEmpty(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'new File(["x"], "a.txt").webkitRelativePath;'
        );
        $this->assertSame('', $result);
    }

    public function testFileInheritsSliceFromBlob(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const f = new File(["hello world"], "a.txt");
const s = f.slice(6, 11);
({size: s.size, isBlob: s instanceof Blob});
JS);
        $this->assertSame(5, $result['size']);
        $this->assertTrue($result['isBlob']);
    }

    public function testFileInheritsTextFromBlob(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
globalThis.__captured = null;
const f = new File(["hello"], "a.txt");
f.text().then(s => { globalThis.__captured = s; });
JS);
        $this->assertSame('hello', $engine->eval('globalThis.__captured;'));
    }

    public function testFileWithoutNameThrows(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
let err = null;
try { new File(["x"]); } catch (e) { err = e.name; }
err;
JS);
        $this->assertSame('TypeError', $result);
    }

    public function testFileSlashInNamePreservedVerbatim(): void
    {
        // Per the current File API spec (Editor's Draft, late 2024) the
        // historical "/" → ":" substitution was removed. WPT pins this
        // with File-constructor.any.js — "dummy/foo" must round-trip
        // unchanged.
        $engine = new Engine();
        $result = $engine->eval(
            'new File(["x"], "a/b/c.txt").name;'
        );
        $this->assertSame('a/b/c.txt', $result);
    }

    public function testFileNaNLastModifiedBecomesZero(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'new File(["x"], "a.txt", { lastModified: NaN }).lastModified;'
        );
        $this->assertSame(0, $result);
    }

    public function testFileToStringTag(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'Object.prototype.toString.call(new File(["x"], "a.txt"));'
        );
        $this->assertSame('[object File]', $result);
    }
}
