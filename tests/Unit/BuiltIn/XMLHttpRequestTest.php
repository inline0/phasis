<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

/**
 * XMLHttpRequest end-to-end: state machine, event ordering, response
 * shaping, header round-tripping. The fetch transport is replaced
 * with a deterministic mock so tests don't touch the network.
 */
class XMLHttpRequestTest extends TestCase
{
    private function engineWithMockTransport(callable $respond): Engine
    {
        $engine = new Engine();
        $engine->setFetchTransport(function (array $req, $signal) use ($respond) {
            return $respond($req);
        });
        return $engine;
    }

    public function testRequestLifecycleFiresEventsInOrder(): void
    {
        $engine = $this->engineWithMockTransport(fn ($r) => [
            'status' => 200,
            'statusText' => 'OK',
            'headers' => [['content-type', 'text/plain']],
            'body' => 'hi',
            'finalUrl' => $r['url'],
        ]);
        $engine->eval(<<<'JS'
globalThis.__events = [];
const xhr = new XMLHttpRequest();
xhr.onloadstart = () => globalThis.__events.push('loadstart');
xhr.onreadystatechange = () => globalThis.__events.push('rs:' + xhr.readyState);
xhr.onprogress = () => globalThis.__events.push('progress');
xhr.onload = () => globalThis.__events.push('load');
xhr.onerror = () => globalThis.__events.push('error');
xhr.onloadend = () => globalThis.__events.push('loadend');
xhr.open('GET', '/foo');
xhr.send();
JS);
        $this->assertSame(
            ['rs:1', 'loadstart', 'rs:2', 'rs:3', 'progress', 'rs:4', 'load', 'loadend'],
            $engine->eval('globalThis.__events'),
        );
    }

    public function testResponseTextDefault(): void
    {
        $engine = $this->engineWithMockTransport(fn ($r) => [
            'status' => 200,
            'headers' => [],
            'body' => 'plain body',
        ]);
        $engine->eval(<<<'JS'
const xhr = new XMLHttpRequest();
xhr.onload = () => {
    globalThis.__text = xhr.responseText;
    globalThis.__resp = xhr.response;
};
xhr.open('GET', '/');
xhr.send();
JS);
        $this->assertSame('plain body', $engine->eval('globalThis.__text'));
        $this->assertSame('plain body', $engine->eval('globalThis.__resp'));
    }

    public function testResponseTypeJson(): void
    {
        $engine = $this->engineWithMockTransport(fn ($r) => [
            'status' => 200,
            'headers' => [],
            'body' => '{"a":1,"b":[2,3]}',
        ]);
        $engine->eval(<<<'JS'
const xhr = new XMLHttpRequest();
xhr.onload = () => {
    globalThis.__a = xhr.response.a;
    globalThis.__b = xhr.response.b.join(',');
};
xhr.open('GET', '/');
xhr.responseType = 'json';
xhr.send();
JS);
        $this->assertSame(1, $engine->eval('globalThis.__a'));
        $this->assertSame('2,3', $engine->eval('globalThis.__b'));
    }

    public function testResponseTypeArrayBuffer(): void
    {
        $engine = $this->engineWithMockTransport(fn ($r) => [
            'status' => 200,
            'headers' => [],
            'body' => "\xDE\xAD\xBE\xEF",
        ]);
        $engine->eval(<<<'JS'
const xhr = new XMLHttpRequest();
xhr.onload = () => {
    globalThis.__hex = Array.from(new Uint8Array(xhr.response))
        .map(b => b.toString(16)).join(',');
    globalThis.__len = xhr.response.byteLength;
};
xhr.open('GET', '/');
xhr.responseType = 'arraybuffer';
xhr.send();
JS);
        $this->assertSame('de,ad,be,ef', $engine->eval('globalThis.__hex'));
        $this->assertSame(4, $engine->eval('globalThis.__len'));
    }

    public function testReadingResponseTextWithNonTextTypeThrows(): void
    {
        $engine = $this->engineWithMockTransport(fn ($r) => [
            'status' => 200,
            'headers' => [],
            'body' => '{}',
        ]);
        $threw = $engine->eval(<<<'JS'
(function () {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', '/');
    xhr.responseType = 'json';
    xhr.send();
    try { void xhr.responseText; return false; } catch (e) { return e instanceof TypeError; }
})()
JS);
        $this->assertTrue($threw);
    }

    public function testRequestHeadersForwardedToTransport(): void
    {
        $captured = null;
        $engine = $this->engineWithMockTransport(function ($r) use (&$captured) {
            $captured = $r['headers'];
            return ['status' => 204, 'headers' => [], 'body' => ''];
        });
        $engine->eval(<<<'JS'
const xhr = new XMLHttpRequest();
xhr.open('POST', '/api');
xhr.setRequestHeader('X-Custom', 'value');
xhr.setRequestHeader('Content-Type', 'application/json');
xhr.send('{"a":1}');
JS);
        // captured headers as list of [name, value] pairs.
        $names = array_column($captured, 0);
        $this->assertContains('X-Custom', $names);
        $this->assertContains('Content-Type', $names);
    }

    public function testRequestBodyForwarded(): void
    {
        $capturedBody = null;
        $engine = $this->engineWithMockTransport(function ($r) use (&$capturedBody) {
            $capturedBody = $r['body'];
            return ['status' => 200, 'headers' => [], 'body' => ''];
        });
        $engine->eval(<<<'JS'
const xhr = new XMLHttpRequest();
xhr.open('POST', '/');
xhr.send('hello body');
JS);
        $this->assertSame('hello body', $capturedBody);
    }

    public function testGetResponseHeaderCaseInsensitive(): void
    {
        $engine = $this->engineWithMockTransport(fn ($r) => [
            'status' => 200,
            'headers' => [
                ['Content-Type', 'application/xml'],
                ['X-Trace', 'abc'],
            ],
            'body' => '',
        ]);
        $engine->eval(<<<'JS'
const xhr = new XMLHttpRequest();
xhr.onload = () => {
    globalThis.__ct = xhr.getResponseHeader('content-type');
    globalThis.__x = xhr.getResponseHeader('X-TRACE');
    globalThis.__missing = xhr.getResponseHeader('missing');
};
xhr.open('GET', '/');
xhr.send();
JS);
        $this->assertSame('application/xml', $engine->eval('globalThis.__ct'));
        $this->assertSame('abc', $engine->eval('globalThis.__x'));
        $this->assertNull($engine->eval('globalThis.__missing'));
    }

    public function testGetAllResponseHeadersCombinesAndLowercases(): void
    {
        $engine = $this->engineWithMockTransport(fn ($r) => [
            'status' => 200,
            'headers' => [
                ['Set-Cookie', 'a=1'],
                ['Set-Cookie', 'b=2'],
                ['X-One', 'x'],
            ],
            'body' => '',
        ]);
        $engine->eval(<<<'JS'
const xhr = new XMLHttpRequest();
xhr.onload = () => { globalThis.__all = xhr.getAllResponseHeaders(); };
xhr.open('GET', '/');
xhr.send();
JS);
        $all = $engine->eval('globalThis.__all');
        $this->assertStringContainsString("set-cookie: a=1, b=2\r\n", $all);
        $this->assertStringContainsString("x-one: x\r\n", $all);
    }

    public function testErrorEventFiresOnTransportFailure(): void
    {
        $engine = new Engine();
        $engine->setFetchTransport(function () {
            throw new \Phasis\BuiltIn\Fetch\TransportException(
                'network down',
                'network-error',
            );
        });
        $engine->eval(<<<'JS'
globalThis.__events = [];
const xhr = new XMLHttpRequest();
xhr.onerror = () => globalThis.__events.push('error');
xhr.onload = () => globalThis.__events.push('load');
xhr.onloadend = () => { globalThis.__events.push('loadend'); globalThis.__status = xhr.status; };
xhr.open('GET', '/');
xhr.send();
JS);
        $this->assertSame(['error', 'loadend'], $engine->eval('globalThis.__events'));
        $this->assertSame(0, $engine->eval('globalThis.__status'));
    }

    public function testAddEventListenerWorks(): void
    {
        $engine = $this->engineWithMockTransport(fn () => [
            'status' => 200,
            'headers' => [],
            'body' => 'hi',
        ]);
        $engine->eval(<<<'JS'
globalThis.__count = 0;
const xhr = new XMLHttpRequest();
xhr.addEventListener('load', () => globalThis.__count++);
xhr.addEventListener('load', () => globalThis.__count++);  // 2 listeners
xhr.open('GET', '/');
xhr.send();
JS);
        $this->assertSame(2, $engine->eval('globalThis.__count'));
    }

    public function testRemoveEventListenerCancels(): void
    {
        $engine = $this->engineWithMockTransport(fn () => [
            'status' => 200,
            'headers' => [],
            'body' => 'hi',
        ]);
        $engine->eval(<<<'JS'
globalThis.__count = 0;
const xhr = new XMLHttpRequest();
const handler = () => globalThis.__count++;
xhr.addEventListener('load', handler);
xhr.removeEventListener('load', handler);
xhr.open('GET', '/');
xhr.send();
JS);
        $this->assertSame(0, $engine->eval('globalThis.__count'));
    }

    public function testReadyStateConstantsExposedOnConstructorAndInstance(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
[
  XMLHttpRequest.UNSENT, XMLHttpRequest.OPENED,
  XMLHttpRequest.HEADERS_RECEIVED, XMLHttpRequest.LOADING,
  XMLHttpRequest.DONE,
  new XMLHttpRequest().DONE
].join(',')
JS);
        $this->assertSame('0,1,2,3,4,4', $result);
    }

    public function testLazyXhrExposed(): void
    {
        $engine = new Engine();
        $this->assertSame('function', $engine->eval('typeof XMLHttpRequest'));
    }
}
