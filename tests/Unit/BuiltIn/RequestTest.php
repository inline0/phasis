<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase
{
    public function testRequestGlobalIsDefined(): void
    {
        $engine = new Engine();
        $this->assertSame('function', $engine->eval('typeof Request;'));
    }

    public function testConstructorFromUrlString(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const req = new Request("https://api.example.com/users");
[req.method, req.url, req.bodyUsed, typeof req.signal];
JS);
        $this->assertSame(['GET', 'https://api.example.com/users', false, 'object'], $result);
    }

    public function testInvalidUrlThrows(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
let caught = null;
try { new Request("not a valid url"); } catch (e) { caught = e.name; }
caught;
JS);
        $this->assertSame('TypeError', $result);
    }

    public function testConstructorFromAnotherRequestCopiesHeaders(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const a = new Request("https://api.example.com/", { method: "POST", headers: { "X-Foo": "1" }, body: "hi" });
const b = new Request(a, { method: "PUT" });
[b.method, b.url, b.headers.get("x-foo")];
JS);
        $this->assertSame(['PUT', 'https://api.example.com/', '1'], $result);
    }

    public function testDefaultMethodIsGet(): void
    {
        $engine = new Engine();
        $this->assertSame('GET', $engine->eval('new Request("https://x/").method;'));
    }

    public function testStandardMethodIsUppercased(): void
    {
        $engine = new Engine();
        $this->assertSame('POST', $engine->eval(
            'new Request("https://x/", { method: "post", body: "x" }).method;'
        ));
    }

    public function testForbiddenMethodThrows(): void
    {
        foreach (['CONNECT', 'TRACE', 'TRACK', 'connect', 'trace'] as $m) {
            $engine = new Engine();
            $result = $engine->eval(<<<JS
let caught = null;
try { new Request("https://x/", { method: "$m" }); } catch (e) { caught = e.name; }
caught;
JS);
            $this->assertSame('TypeError', $result, "method=$m");
        }
    }

    public function testBodyWithGetThrows(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
let caught = null;
try { new Request("https://x/", { method: "GET", body: "hello" }); } catch (e) { caught = e.name; }
caught;
JS);
        $this->assertSame('TypeError', $result);
    }

    public function testBodyWithHeadThrows(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
let caught = null;
try { new Request("https://x/", { method: "HEAD", body: "hello" }); } catch (e) { caught = e.name; }
caught;
JS);
        $this->assertSame('TypeError', $result);
    }

    public function testStringBodyDefaultsContentTypeToTextPlain(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const req = new Request("https://x/", { method: "POST", body: "hello" });
req.headers.get("content-type");
JS);
        $this->assertSame('text/plain;charset=UTF-8', $result);
    }

    public function testExplicitContentTypeIsNotOverridden(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const req = new Request("https://x/", {
  method: "POST",
  headers: { "Content-Type": "application/json" },
  body: "x"
});
req.headers.get("content-type");
JS);
        $this->assertSame('application/json', $result);
    }

    public function testUrlSearchParamsBodyContentType(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const usp = new URLSearchParams();
usp.append("a", "1");
const req = new Request("https://x/", { method: "POST", body: usp });
req.headers.get("content-type");
JS);
        $this->assertSame('application/x-www-form-urlencoded;charset=UTF-8', $result);
    }

    public function testFormDataBodyContentType(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const fd = new FormData();
fd.append("a", "1");
const req = new Request("https://x/", { method: "POST", body: fd });
const ct = req.headers.get("content-type");
ct.startsWith("multipart/form-data; boundary=");
JS);
        $this->assertTrue($result);
    }

    public function testBlobBodyContentType(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const b = new Blob(["hi"], { type: "text/markdown" });
const req = new Request("https://x/", { method: "POST", body: b });
req.headers.get("content-type");
JS);
        $this->assertSame('text/markdown', $result);
    }

    public function testTextConsumesBodyAndSetsBodyUsed(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const req = new Request("https://x/", { method: "POST", body: "hello" });
globalThis.__before = req.bodyUsed;
req.text().then(t => { globalThis.__collected = t; globalThis.__used = req.bodyUsed; });
JS);
        $this->assertSame(false, $engine->eval('globalThis.__before;'));
        $this->assertSame(true, $engine->eval('globalThis.__used;'));
        $this->assertSame('hello', $engine->eval('globalThis.__collected;'));
    }

    public function testJsonBodyConsumption(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const req = new Request("https://x/", {
  method: "POST",
  headers: { "Content-Type": "application/json" },
  body: JSON.stringify({ a: 1, b: "hello" })
});
req.json().then(d => { globalThis.__collected = d; });
JS);
        $this->assertSame(['a' => 1, 'b' => 'hello'], $engine->eval('globalThis.__collected;'));
    }

    public function testBodyGetterReturnsReadableStream(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const req = new Request("https://x/", { method: "POST", body: "abc" });
req.body instanceof ReadableStream;
JS);
        $this->assertTrue($result);
    }

    public function testBodyGetterReturnsNullForNoBody(): void
    {
        $engine = new Engine();
        $result = $engine->eval('new Request("https://x/").body === null;');
        $this->assertTrue($result);
    }

    public function testStreamConsumptionYieldsBytes(): void
    {
        $engine = new Engine();
        // Iterate over body chunks and decode the concatenation.
        $engine->eval(<<<'JS'
const req = new Request("https://x/", { method: "POST", body: "alpha-beta" });
const reader = req.body.getReader();
const chunks = [];
function pump() {
  return reader.read().then(({value, done: d}) => {
    if (d) {
      globalThis.__done = true;
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
        $this->assertSame(true, $engine->eval('globalThis.__done;'));
        $this->assertSame('alpha-beta', $engine->eval('globalThis.__collected;'));
    }

    public function testSignalDefaultsToFreshAbortSignal(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const req = new Request("https://x/");
[req.signal.aborted, req.signal instanceof AbortSignal];
JS);
        $this->assertSame([false, true], $result);
    }

    public function testInvalidSignalThrows(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
let caught = null;
try { new Request("https://x/", { signal: "not a signal" }); } catch (e) { caught = e.name; }
caught;
JS);
        $this->assertSame('TypeError', $result);
    }

    public function testSignalIsStoredVerbatim(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const c = new AbortController();
const req = new Request("https://x/", { signal: c.signal });
req.signal === c.signal;
JS);
        $this->assertTrue($result);
    }

    public function testClone(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const a = new Request("https://x/", { method: "POST", body: "hello", headers: { "X-Test": "1" } });
const b = a.clone();
a.text().then(t => { globalThis.__aText = t; });
b.text().then(t => { globalThis.__bText = t; });
globalThis.__bHeader = b.headers.get("x-test");
JS);
        $this->assertSame('hello', $engine->eval('globalThis.__aText;'));
        $this->assertSame('hello', $engine->eval('globalThis.__bText;'));
        $this->assertSame('1', $engine->eval('globalThis.__bHeader;'));
    }

    public function testCloneAfterConsumeThrows(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const req = new Request("https://x/", { method: "POST", body: "x" });
req.text();
let caught = null;
try { req.clone(); } catch (e) { caught = e.name; }
caught;
JS);
        $this->assertSame('TypeError', $result);
    }

    public function testConsumeTwiceRejectsTypeError(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const req = new Request("https://x/", { method: "POST", body: "x" });
req.text();
req.text().catch(e => { globalThis.__err = e.name; });
JS);
        $this->assertSame('TypeError', $engine->eval('globalThis.__err;'));
    }

    public function testReadOnlyProperties(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const req = new Request("https://x/", { method: "POST", body: "x" });
const desc = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(req), "method");
[desc.set, typeof desc.get];
JS);
        $this->assertSame([null, 'function'], $result);
    }

    public function testBodyFromTypedArray(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const bytes = new Uint8Array([72, 101, 108, 108, 111]);
const req = new Request("https://x/", { method: "POST", body: bytes });
req.text().then(t => { globalThis.__collected = t; });
JS);
        $this->assertSame('Hello', $engine->eval('globalThis.__collected;'));
    }

    public function testBodyFromArrayBuffer(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const buf = new Uint8Array([65, 66, 67]).buffer;
const req = new Request("https://x/", { method: "POST", body: buf });
req.text().then(t => { globalThis.__collected = t; });
JS);
        $this->assertSame('ABC', $engine->eval('globalThis.__collected;'));
    }

    public function testInitOptions(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const req = new Request("https://x/", {
  method: "POST",
  body: "x",
  mode: "cors",
  credentials: "include",
  cache: "no-store",
  redirect: "manual",
  referrer: "no-referrer",
  referrerPolicy: "no-referrer",
  integrity: "sha256-abc",
  keepalive: true,
  priority: "high",
});
[req.mode, req.credentials, req.cache, req.redirect, req.referrer, req.referrerPolicy, req.integrity, req.keepalive, req.priority];
JS);
        $this->assertSame(
            ['cors', 'include', 'no-store', 'manual', 'no-referrer', 'no-referrer', 'sha256-abc', true, 'high'],
            $result,
        );
    }

    public function testToStringTag(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'const req = new Request("https://x/"); Object.prototype.toString.call(req);'
        );
        $this->assertSame('[object Request]', $result);
    }

    public function testConstructorRequiresNew(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
let caught = null;
try { Request("https://x/"); } catch (e) { caught = e.name; }
caught;
JS);
        $this->assertSame('TypeError', $result);
    }
}
