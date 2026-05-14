<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

class UrlSearchParamsTest extends TestCase
{
    private function eval(string $js): mixed
    {
        $engine = new Engine();
        return $engine->eval($js);
    }

    public function testConstructorString(): void
    {
        $result = $this->eval('new URLSearchParams("a=1&b=2").toString()');
        $this->assertSame('a=1&b=2', $result);
    }

    public function testConstructorStringWithLeadingQuestionMark(): void
    {
        $result = $this->eval('new URLSearchParams("?a=1&b=2").toString()');
        $this->assertSame('a=1&b=2', $result);
    }

    public function testConstructorArrayOfPairs(): void
    {
        $result = $this->eval('new URLSearchParams([["a","1"],["b","2"]]).toString()');
        $this->assertSame('a=1&b=2', $result);
    }

    public function testConstructorRecord(): void
    {
        $result = $this->eval('new URLSearchParams({a:"1",b:"2"}).toString()');
        $this->assertSame('a=1&b=2', $result);
    }

    public function testConstructorFromAnotherUSP(): void
    {
        $result = $this->eval(<<<'JS'
        const p = new URLSearchParams("a=1&b=2");
        const q = new URLSearchParams(p);
        q.toString();
        JS);
        $this->assertSame('a=1&b=2', $result);
    }

    public function testGetReturnsFirst(): void
    {
        $result = $this->eval('new URLSearchParams("a=1&a=2&a=3").get("a")');
        $this->assertSame('1', $result);
    }

    public function testGetAll(): void
    {
        $result = $this->eval('new URLSearchParams("a=1&b=2&a=3").getAll("a")');
        $this->assertSame(['1', '3'], $result);
    }

    public function testGetNullForMissing(): void
    {
        $result = $this->eval('new URLSearchParams("a=1").get("missing")');
        $this->assertNull($result);
    }

    public function testHas(): void
    {
        $result = $this->eval(<<<'JS'
        const p = new URLSearchParams("a=1&b=2");
        [p.has("a"), p.has("b"), p.has("c")];
        JS);
        $this->assertSame([true, true, false], $result);
    }

    public function testHasWithValue(): void
    {
        $result = $this->eval(<<<'JS'
        const p = new URLSearchParams("a=1&a=2");
        [p.has("a","1"), p.has("a","2"), p.has("a","3")];
        JS);
        $this->assertSame([true, true, false], $result);
    }

    public function testAppendVsSet(): void
    {
        $result = $this->eval(<<<'JS'
        const p = new URLSearchParams("a=1");
        p.append("a","2");
        const r1 = p.toString();
        p.set("a","3");
        const r2 = p.toString();
        [r1, r2];
        JS);
        $this->assertSame(['a=1&a=2', 'a=3'], $result);
    }

    public function testDeleteAll(): void
    {
        $result = $this->eval(<<<'JS'
        const p = new URLSearchParams("a=1&b=2&a=3");
        p.delete("a");
        p.toString();
        JS);
        $this->assertSame('b=2', $result);
    }

    public function testDeleteByValue(): void
    {
        $result = $this->eval(<<<'JS'
        const p = new URLSearchParams("a=1&a=2&a=3");
        p.delete("a","2");
        p.toString();
        JS);
        $this->assertSame('a=1&a=3', $result);
    }

    public function testSort(): void
    {
        $result = $this->eval(<<<'JS'
        const p = new URLSearchParams("c=3&a=1&b=2");
        p.sort();
        p.toString();
        JS);
        $this->assertSame('a=1&b=2&c=3', $result);
    }

    public function testSortStable(): void
    {
        $result = $this->eval(<<<'JS'
        const p = new URLSearchParams("a=1&b=2&a=3");
        p.sort();
        p.toString();
        JS);
        $this->assertSame('a=1&a=3&b=2', $result);
    }

    public function testIterationOrderMatchesInsertion(): void
    {
        $result = $this->eval(<<<'JS'
        const p = new URLSearchParams("b=2&a=1&c=3");
        const out = [];
        for (const [k,v] of p) out.push(k+"="+v);
        out;
        JS);
        $this->assertSame(['b=2', 'a=1', 'c=3'], $result);
    }

    public function testKeysValuesEntries(): void
    {
        $result = $this->eval(<<<'JS'
        const p = new URLSearchParams("a=1&b=2");
        [Array.from(p.keys()), Array.from(p.values()), Array.from(p.entries())];
        JS);
        $this->assertSame(
            [['a', 'b'], ['1', '2'], [['a', '1'], ['b', '2']]],
            $result,
        );
    }

    public function testSize(): void
    {
        $result = $this->eval(<<<'JS'
        const p = new URLSearchParams("a=1&b=2&a=3");
        p.size;
        JS);
        // Phasis coerces JsNumber to int when integral.
        $this->assertSame(3, $result);
    }

    public function testForEach(): void
    {
        $result = $this->eval(<<<'JS'
        const p = new URLSearchParams("a=1&b=2");
        const out = [];
        p.forEach((v,k) => out.push(k+":"+v));
        out;
        JS);
        $this->assertSame(['a:1', 'b:2'], $result);
    }

    public function testToStringRoundTrip(): void
    {
        $result = $this->eval(<<<'JS'
        const p = new URLSearchParams("a=hello%20world&b=1+2");
        p.toString();
        JS);
        // %20 → " " parsed → "hello world", then re-encoded to "hello+world".
        $this->assertSame('a=hello+world&b=1+2', $result);
    }

    public function testSymbolIterator(): void
    {
        $result = $this->eval(<<<'JS'
        const p = new URLSearchParams("a=1&b=2");
        typeof p[Symbol.iterator] === "function";
        JS);
        $this->assertTrue($result);
    }

    public function testSpreadIntoArray(): void
    {
        $result = $this->eval(<<<'JS'
        [...new URLSearchParams("x=1&y=2")];
        JS);
        $this->assertSame([['x', '1'], ['y', '2']], $result);
    }
}
