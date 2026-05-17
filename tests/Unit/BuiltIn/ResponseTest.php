<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

class ResponseTest extends TestCase
{
    public function testResponseGlobalIsDefined(): void
    {
        $engine = new Engine();
        $this->assertSame('function', $engine->eval('typeof Response;'));
    }

    public function testEmptyResponseDefaults(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const r = new Response();
[r.status, r.statusText, r.ok, r.type, r.redirected, r.url, r.body === null];
JS);
        // Per spec, a Response constructed directly (not produced by
        // fetch) has type "default" — "basic" is reserved for the
        // fetch pipeline's same-origin successful responses.
        $this->assertSame([200, '', true, 'default', false, '', true], $result);
    }

    public function testStatusValidation(): void
    {
        foreach ([99, 600, 1000] as $status) {
            $engine = new Engine();
            $result = $engine->eval(<<<JS
let caught = null;
try { new Response("x", { status: $status }); } catch (e) { caught = e.name; }
caught;
JS);
            $this->assertSame('RangeError', $result, "status=$status");
        }
    }

    public function testStatusRangeOk(): void
    {
        $engine = new Engine();
        $this->assertSame(
            [200, 599],
            $engine->eval(<<<'JS'
[new Response("a", { status: 200 }).status, new Response("a", { status: 599 }).status];
JS)
        );
    }

    public function testOkComputedFromStatus(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const values = [200, 204, 299, 300, 400, 500].map(s => {
  try { return new Response(s === 204 ? null : "x", { status: s }).ok; }
  catch(e) { return e.name; }
});
values;
JS);
        $this->assertSame([true, true, true, false, false, false], $result);
    }

    public function testNullBodyStatusRejectsBody(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
let caught = null;
try { new Response("x", { status: 204 }); } catch (e) { caught = e.name; }
caught;
JS);
        $this->assertSame('TypeError', $result);
    }

    public function testStatusText(): void
    {
        $engine = new Engine();
        $this->assertSame(
            'Not Found',
            $engine->eval('new Response(null, { status: 404, statusText: "Not Found" }).statusText;')
        );
    }

    public function testTextConsumption(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const r = new Response("hello world");
r.text().then(t => { globalThis.__collected = t; });
JS);
        $this->assertSame('hello world', $engine->eval('globalThis.__collected;'));
    }

    public function testJsonConsumption(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const r = new Response(JSON.stringify({ a: 1, b: "x" }), { headers: { "Content-Type": "application/json" }});
r.json().then(d => { globalThis.__collected = d; });
JS);
        $this->assertSame(['a' => 1, 'b' => 'x'], $engine->eval('globalThis.__collected;'));
    }

    public function testBodyUsedAfterConsume(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const r = new Response("x");
const before = r.bodyUsed;
r.text();
[before, r.bodyUsed];
JS);
        $this->assertSame([false, true], $result);
    }

    public function testBodyReturnsReadableStream(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const r = new Response("hi");
r.body instanceof ReadableStream;
JS);
        $this->assertTrue($result);
    }

    public function testResponseErrorStatic(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const r = Response.error();
[r.status, r.type, r.ok];
JS);
        $this->assertSame([0, 'error', false], $result);
    }

    public function testResponseRedirectStatic(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const r = Response.redirect("https://example.com/x", 301);
[r.status, r.headers.get("location")];
JS);
        $this->assertSame([301, 'https://example.com/x'], $result);
    }

    public function testResponseRedirectDefaults302(): void
    {
        $engine = new Engine();
        $this->assertSame(302, $engine->eval('Response.redirect("https://example.com/x").status;'));
    }

    public function testResponseRedirectStatusValidation(): void
    {
        foreach ([200, 300, 304, 999, -1] as $status) {
            $engine = new Engine();
            $result = $engine->eval(<<<JS
let caught = null;
try { Response.redirect("https://example.com/x", $status); } catch (e) { caught = e.name; }
caught;
JS);
            $this->assertSame('RangeError', $result, "redirect status=$status");
        }
    }

    public function testResponseRedirectValidStatuses(): void
    {
        foreach ([301, 302, 303, 307, 308] as $status) {
            $engine = new Engine();
            $result = $engine->eval(<<<JS
Response.redirect("https://example.com/", $status).status;
JS);
            $this->assertSame($status, $result, "redirect status=$status");
        }
    }

    public function testResponseRedirectInvalidUrl(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
let caught = null;
try { Response.redirect("not a url"); } catch (e) { caught = e.name; }
caught;
JS);
        $this->assertSame('TypeError', $result);
    }

    public function testResponseJsonStatic(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const r = Response.json({ hello: "world" });
globalThis.__ct = r.headers.get("content-type");
globalThis.__status = r.status;
r.json().then(d => { globalThis.__body = d; });
JS);
        $this->assertSame('application/json', $engine->eval('globalThis.__ct;'));
        $this->assertSame(200, $engine->eval('globalThis.__status;'));
        $this->assertSame(['hello' => 'world'], $engine->eval('globalThis.__body;'));
    }

    public function testResponseJsonStaticWithInit(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const r = Response.json({ x: 1 }, { status: 201, statusText: "Created" });
[r.status, r.statusText, r.headers.get("content-type")];
JS);
        $this->assertSame([201, 'Created', 'application/json'], $result);
    }

    public function testResponseJsonForcesContentType(): void
    {
        $engine = new Engine();
        // Per WHATWG spec, Response.json only sets Content-Type when
        // the init didn't already provide one — a caller-supplied
        // value wins.
        $result = $engine->eval(<<<'JS'
const r = Response.json({ x: 1 }, { headers: { "Content-Type": "text/plain" } });
r.headers.get("content-type");
JS);
        $this->assertSame('text/plain', $result);
    }

    public function testResponseJsonRejectsUndefined(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
let caught = null;
try { Response.json(undefined); } catch (e) { caught = e.name; }
caught;
JS);
        $this->assertSame('TypeError', $result);
    }

    public function testHeadersFromInit(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const r = new Response("x", { headers: { "X-Custom": "value", "Content-Type": "text/html" } });
[r.headers.get("x-custom"), r.headers.get("content-type")];
JS);
        $this->assertSame(['value', 'text/html'], $result);
    }

    public function testStringBodyDefaultsContentType(): void
    {
        $engine = new Engine();
        $this->assertSame(
            'text/plain;charset=UTF-8',
            $engine->eval('new Response("hello").headers.get("content-type");')
        );
    }

    public function testClone(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const a = new Response("hello", { status: 201, headers: { "X-Test": "yes" } });
const b = a.clone();
a.text().then(t => { globalThis.__aText = t; });
b.text().then(t => { globalThis.__bText = t; });
globalThis.__bStatus = b.status;
globalThis.__bHeader = b.headers.get("x-test");
JS);
        $this->assertSame('hello', $engine->eval('globalThis.__aText;'));
        $this->assertSame('hello', $engine->eval('globalThis.__bText;'));
        $this->assertSame(201, $engine->eval('globalThis.__bStatus;'));
        $this->assertSame('yes', $engine->eval('globalThis.__bHeader;'));
    }

    public function testCloneAfterConsumeThrows(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const r = new Response("x");
r.text();
let caught = null;
try { r.clone(); } catch (e) { caught = e.name; }
caught;
JS);
        $this->assertSame('TypeError', $result);
    }

    public function testReadOnlyProperties(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const r = new Response("x");
const desc = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(r), "status");
[desc.set, typeof desc.get];
JS);
        $this->assertSame([null, 'function'], $result);
    }

    public function testToStringTag(): void
    {
        $engine = new Engine();
        $this->assertSame(
            '[object Response]',
            $engine->eval('Object.prototype.toString.call(new Response());')
        );
    }

    public function testConstructorRequiresNew(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
let caught = null;
try { Response(); } catch (e) { caught = e.name; }
caught;
JS);
        $this->assertSame('TypeError', $result);
    }

    public function testStreamConsumption(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const r = new Response("foo-bar-baz");
const reader = r.body.getReader();
const chunks = [];
function pump() {
  return reader.read().then(({ value, done }) => {
    if (done) {
      const dec = new TextDecoder();
      const total = chunks.reduce((acc, ch) => {
        const merged = new Uint8Array(acc.length + ch.length);
        merged.set(acc, 0);
        merged.set(ch, acc.length);
        return merged;
      }, new Uint8Array(0));
      globalThis.__collected = dec.decode(total);
      return;
    }
    chunks.push(value);
    return pump();
  });
}
pump();
JS);
        $this->assertSame('foo-bar-baz', $engine->eval('globalThis.__collected;'));
    }

    public function testBlobBody(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const blob = new Blob(["hi"], { type: "text/html" });
const r = new Response(blob);
r.text().then(t => { globalThis.__bodyText = t; });
globalThis.__ct = r.headers.get("content-type");
JS);
        $this->assertSame('hi', $engine->eval('globalThis.__bodyText;'));
        $this->assertSame('text/html', $engine->eval('globalThis.__ct;'));
    }
}
