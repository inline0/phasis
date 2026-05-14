<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

class FormDataTest extends TestCase
{
    public function testFormDataGlobalIsDefined(): void
    {
        $engine = new Engine();
        $this->assertSame('function', $engine->eval('typeof FormData;'));
    }

    public function testEmptyFormData(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const fd = new FormData();
({hasKey: fd.has("x"), getKey: fd.get("x"), all: fd.getAll("x").length});
JS);
        $this->assertFalse($result['hasKey']);
        $this->assertNull($result['getKey']);
        $this->assertSame(0, $result['all']);
    }

    public function testAppendAndGet(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const fd = new FormData();
fd.append("name", "alice");
fd.get("name");
JS);
        $this->assertSame('alice', $result);
    }

    public function testHasReturnsTrueAfterAppend(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const fd = new FormData();
fd.append("x", "1");
fd.has("x");
JS);
        $this->assertTrue($result);
    }

    public function testAppendMultipleValuesForSameKey(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const fd = new FormData();
fd.append("k", "v1");
fd.append("k", "v2");
fd.append("k", "v3");
fd.getAll("k");
JS);
        $this->assertSame(['v1', 'v2', 'v3'], $result);
    }

    public function testGetReturnsFirstValue(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const fd = new FormData();
fd.append("k", "first");
fd.append("k", "second");
fd.get("k");
JS);
        $this->assertSame('first', $result);
    }

    public function testGetReturnsNullForMissing(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const fd = new FormData();
fd.get("missing");
JS);
        $this->assertNull($result);
    }

    public function testDeleteRemovesAllValuesForKey(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const fd = new FormData();
fd.append("a", "1");
fd.append("b", "2");
fd.append("a", "3");
fd.delete("a");
({hasA: fd.has("a"), hasB: fd.has("b"), allA: fd.getAll("a").length});
JS);
        $this->assertFalse($result['hasA']);
        $this->assertTrue($result['hasB']);
        $this->assertSame(0, $result['allA']);
    }

    public function testSetReplacesExistingEntries(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const fd = new FormData();
fd.append("k", "v1");
fd.append("k", "v2");
fd.set("k", "v3");
fd.getAll("k");
JS);
        $this->assertSame(['v3'], $result);
    }

    public function testSetVsAppendDifference(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const fd = new FormData();
fd.append("k", "v1");
fd.append("k", "v2");
fd.set("k", "v3");          // replaces, leaves one entry
fd.append("k", "v4");       // appends
fd.getAll("k");
JS);
        $this->assertSame(['v3', 'v4'], $result);
    }

    public function testSetOnEmptyAppends(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const fd = new FormData();
fd.set("k", "v");
fd.getAll("k");
JS);
        $this->assertSame(['v'], $result);
    }

    public function testInsertionOrderPreservedAcrossKeys(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const fd = new FormData();
fd.append("a", "1");
fd.append("b", "2");
fd.append("a", "3");
fd.append("c", "4");
const out = [];
for (const [k, v] of fd) out.push(k + "=" + v);
out;
JS);
        $this->assertSame(['a=1', 'b=2', 'a=3', 'c=4'], $result);
    }

    public function testIterableViaSymbolIterator(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const fd = new FormData();
fd.append("a", "1");
fd.append("b", "2");
[...fd].length;
JS);
        $this->assertSame(2, $result);
    }

    public function testKeysIterator(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const fd = new FormData();
fd.append("a", "1");
fd.append("b", "2");
fd.append("a", "3");
Array.from(fd.keys());
JS);
        $this->assertSame(['a', 'b', 'a'], $result);
    }

    public function testValuesIterator(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const fd = new FormData();
fd.append("a", "1");
fd.append("b", "2");
Array.from(fd.values());
JS);
        $this->assertSame(['1', '2'], $result);
    }

    public function testEntriesIterator(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const fd = new FormData();
fd.append("a", "1");
fd.append("b", "2");
Array.from(fd.entries()).map(([k, v]) => k + ":" + v);
JS);
        $this->assertSame(['a:1', 'b:2'], $result);
    }

    public function testForEach(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const fd = new FormData();
fd.append("a", "1");
fd.append("b", "2");
const out = [];
fd.forEach((value, key) => out.push(key + ":" + value));
out;
JS);
        $this->assertSame(['a:1', 'b:2'], $result);
    }

    public function testValueIsStringifiedForNonBlob(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const fd = new FormData();
fd.append("num", 42);
fd.append("bool", true);
({n: fd.get("num"), nType: typeof fd.get("num"), b: fd.get("bool")});
JS);
        $this->assertSame('42', $result['n']);
        $this->assertSame('string', $result['nType']);
        $this->assertSame('true', $result['b']);
    }

    public function testBlobValueWithFilenameBecomesFile(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const fd = new FormData();
fd.append("file", new Blob(["data"]), "x.bin");
const f = fd.get("file");
({isFile: f instanceof File, isBlob: f instanceof Blob, name: f.name, size: f.size});
JS);
        $this->assertTrue($result['isFile']);
        $this->assertTrue($result['isBlob']);
        $this->assertSame('x.bin', $result['name']);
        $this->assertSame(4, $result['size']);
    }

    public function testBlobValueWithoutFilenameUsesBlobDefault(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const fd = new FormData();
fd.append("file", new Blob(["data"]));
const f = fd.get("file");
({isFile: f instanceof File, name: f.name});
JS);
        $this->assertTrue($result['isFile']);
        $this->assertSame('blob', $result['name']);
    }

    public function testFileValuePreservedWithoutFilenameOverride(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const fd = new FormData();
const f = new File(["data"], "real.txt");
fd.append("file", f);
fd.get("file").name;
JS);
        $this->assertSame('real.txt', $result);
    }

    public function testFileValueWithFilenameOverridden(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const fd = new FormData();
const f = new File(["data"], "real.txt");
fd.append("file", f, "override.bin");
fd.get("file").name;
JS);
        $this->assertSame('override.bin', $result);
    }

    public function testSetWithBlobAndFilename(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const fd = new FormData();
fd.set("file", new Blob(["abc"]), "x.bin");
const f = fd.get("file");
({name: f.name, size: f.size, isFile: f instanceof File});
JS);
        $this->assertSame('x.bin', $result['name']);
        $this->assertSame(3, $result['size']);
        $this->assertTrue($result['isFile']);
    }

    public function testFormDataToStringTag(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'Object.prototype.toString.call(new FormData());'
        );
        $this->assertSame('[object FormData]', $result);
    }

    public function testConstructorWithoutNewThrows(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
let err = null;
try { FormData(); } catch (e) { err = e.name; }
err;
JS);
        $this->assertSame('TypeError', $result);
    }

    public function testAppendRequiresTwoArgs(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const fd = new FormData();
let err = null;
try { fd.append("only"); } catch (e) { err = e.name; }
err;
JS);
        $this->assertSame('TypeError', $result);
    }

    public function testFormDataConstructorRejectsNonFormArgument(): void
    {
        // Per WebIDL the optional argument is HTMLFormElement?. Since we
        // do not implement HTMLFormElement, any non-undefined argument
        // (including null and plain objects) must be rejected with a
        // TypeError. WPT verifies this with `new FormData(null)` and
        // `new FormData("string")`.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
let err = null;
try { new FormData({}); } catch (e) { err = e.name; }
err;
JS);
        $this->assertSame('TypeError', $result);
    }
}
