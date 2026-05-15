<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

/**
 * WebSocket: state machine, event dispatch, send/close, pluggable
 * transport contract. A mock transport drives the lifecycle
 * synchronously so we can assert ordering without a real network.
 */
class WebSocketTest extends TestCase
{
    /**
     * Build an Engine with a mock WebSocket transport. The transport
     * captures every send/close call and exposes the emit callback
     * back to the test so we can synthesize 'open'/'message'/'close'
     * events on demand.
     *
     * @param array<string, mixed> $captured  populated by the mock
     */
    private function engineWithMockTransport(array &$captured): Engine
    {
        $engine = new Engine();
        $engine->setWebSocketTransport(function (string $url, array $protocols, callable $emit) use (&$captured) {
            $captured['url'] = $url;
            $captured['protocols'] = $protocols;
            $captured['emit'] = $emit;
            $captured['sends'] = [];
            $captured['closes'] = [];
            return [
                'send' => function ($data, $isBinary) use (&$captured) {
                    $captured['sends'][] = ['data' => $data, 'isBinary' => $isBinary];
                },
                'close' => function ($code, $reason) use (&$captured) {
                    $captured['closes'][] = ['code' => $code, 'reason' => $reason];
                },
            ];
        });
        return $engine;
    }

    public function testConstructorWithoutExplicitTransportUsesDefault(): void
    {
        // Without setWebSocketTransport(), the constructor falls back
        // to the pure-PHP StreamSocketTransport. Connecting to an
        // unrouteable port either fails fast (constructor catches the
        // throw and converts it to async error+close, leaving state
        // CLOSED) or stays CONNECTING until the OS reports timeout.
        // Both are valid outcomes — we just assert no PHP exception
        // leaks out of `new WebSocket(...)`.
        $engine = new Engine();
        $engine->eval("globalThis.__ws = new WebSocket('ws://127.0.0.1:1/'); globalThis.__rs = globalThis.__ws.readyState;");
        $rs = $engine->eval('globalThis.__rs');
        $this->assertContains($rs, [0, 3]);
    }

    public function testConstructorPassesUrlAndProtocols(): void
    {
        $captured = [];
        $engine = $this->engineWithMockTransport($captured);
        $engine->eval(<<<'JS'
new WebSocket('wss://example.com/path', ['chat', 'superchat']);
JS);
        $this->assertSame('wss://example.com/path', $captured['url']);
        $this->assertSame(['chat', 'superchat'], $captured['protocols']);
    }

    public function testReadyStateStartsAtConnecting(): void
    {
        $captured = [];
        $engine = $this->engineWithMockTransport($captured);
        $engine->eval(<<<'JS'
const ws = new WebSocket('wss://example.com/');
globalThis.__rs = ws.readyState;
JS);
        $this->assertSame(0, $engine->eval('globalThis.__rs'));
    }

    public function testEmitOpenFiresOpenEventAndAdvancesState(): void
    {
        $captured = [];
        $engine = $this->engineWithMockTransport($captured);
        $engine->eval(<<<'JS'
globalThis.__events = [];
const ws = new WebSocket('wss://example.com/');
ws.onopen = e => globalThis.__events.push('open:' + ws.readyState);
JS);
        // Drive the transport from the PHP side.
        ($captured['emit'])('open', ['protocol' => 'chat']);
        $this->assertSame(['open:1'], $engine->eval('globalThis.__events'));
        $this->assertSame(1, $engine->eval('ws.readyState'));
        $this->assertSame('chat', $engine->eval('ws.protocol'));
    }

    public function testTextMessageDeliveredAsString(): void
    {
        $captured = [];
        $engine = $this->engineWithMockTransport($captured);
        $engine->eval(<<<'JS'
globalThis.__received = null;
const ws = new WebSocket('wss://example.com/');
ws.onmessage = e => { globalThis.__received = e.data; globalThis.__type = typeof e.data; };
JS);
        ($captured['emit'])('open');
        ($captured['emit'])('message', ['data' => 'hello', 'isBinary' => false]);
        $this->assertSame('hello', $engine->eval('globalThis.__received'));
        $this->assertSame('string', $engine->eval('globalThis.__type'));
    }

    public function testBinaryMessageDeliveredAsArrayBuffer(): void
    {
        $captured = [];
        $engine = $this->engineWithMockTransport($captured);
        $engine->eval(<<<'JS'
const ws = new WebSocket('wss://example.com/');
ws.onmessage = e => {
    globalThis.__isBuf = e.data instanceof ArrayBuffer;
    globalThis.__len = e.data.byteLength;
    globalThis.__hex = Array.from(new Uint8Array(e.data))
        .map(b => b.toString(16)).join(',');
};
JS);
        ($captured['emit'])('open');
        ($captured['emit'])('message', ['data' => "\xDE\xAD\xBE\xEF", 'isBinary' => true]);
        $this->assertTrue($engine->eval('globalThis.__isBuf'));
        $this->assertSame(4, $engine->eval('globalThis.__len'));
        $this->assertSame('de,ad,be,ef', $engine->eval('globalThis.__hex'));
    }

    public function testSendForwardsStringPayload(): void
    {
        $captured = [];
        $engine = $this->engineWithMockTransport($captured);
        $engine->eval(<<<'JS'
const ws = new WebSocket('wss://example.com/');
ws.onopen = () => ws.send('hello server');
JS);
        ($captured['emit'])('open');
        $this->assertCount(1, $captured['sends']);
        $this->assertSame('hello server', $captured['sends'][0]['data']);
        $this->assertFalse($captured['sends'][0]['isBinary']);
    }

    public function testSendForwardsBinaryPayload(): void
    {
        $captured = [];
        $engine = $this->engineWithMockTransport($captured);
        $engine->eval(<<<'JS'
const ws = new WebSocket('wss://example.com/');
ws.onopen = () => {
    const buf = new Uint8Array([1, 2, 3, 4]);
    ws.send(buf);
};
JS);
        ($captured['emit'])('open');
        $this->assertCount(1, $captured['sends']);
        $this->assertSame("\x01\x02\x03\x04", $captured['sends'][0]['data']);
        $this->assertTrue($captured['sends'][0]['isBinary']);
    }

    public function testSendBeforeOpenThrows(): void
    {
        $captured = [];
        $engine = $this->engineWithMockTransport($captured);
        $threw = $engine->eval(<<<'JS'
const ws = new WebSocket('wss://example.com/');
let threw = false;
try { ws.send('too early'); } catch (e) { threw = e instanceof TypeError; }
threw
JS);
        $this->assertTrue($threw);
    }

    public function testCloseTransitionsThroughClosingToClosed(): void
    {
        $captured = [];
        $engine = $this->engineWithMockTransport($captured);
        $engine->eval(<<<'JS'
globalThis.__events = [];
const ws = new WebSocket('wss://example.com/');
ws.onopen = () => globalThis.__events.push('open:' + ws.readyState);
ws.onclose = e => globalThis.__events.push('close:' + ws.readyState + ':' + e.code + ':' + e.reason);
globalThis.__ws = ws;
JS);
        ($captured['emit'])('open');
        $engine->eval('globalThis.__ws.close(1000, "bye");');
        // Closing should have called the transport close.
        $this->assertCount(1, $captured['closes']);
        $this->assertSame(1000, $captured['closes'][0]['code']);
        $this->assertSame('bye', $captured['closes'][0]['reason']);
        // Mid-close state.
        $this->assertSame(2, $engine->eval('globalThis.__ws.readyState'));
        // Transport confirms.
        ($captured['emit'])('close', ['code' => 1000, 'reason' => 'bye', 'wasClean' => true]);
        $this->assertSame(['open:1', 'close:3:1000:bye'], $engine->eval('globalThis.__events'));
    }

    public function testCloseRejectsInvalidCode(): void
    {
        $captured = [];
        $engine = $this->engineWithMockTransport($captured);
        $threw = $engine->eval(<<<'JS'
(function () {
    const ws = new WebSocket('wss://example.com/');
    try { ws.close(500); return false; } catch (e) { return e instanceof TypeError; }
})()
JS);
        $this->assertTrue($threw);
    }

    public function testErrorEventFires(): void
    {
        $captured = [];
        $engine = $this->engineWithMockTransport($captured);
        $engine->eval(<<<'JS'
globalThis.__errorMsg = null;
const ws = new WebSocket('wss://example.com/');
ws.onerror = e => globalThis.__errorMsg = e.message;
JS);
        ($captured['emit'])('error', ['message' => 'something broke']);
        $this->assertSame('something broke', $engine->eval('globalThis.__errorMsg'));
    }

    public function testAddRemoveEventListener(): void
    {
        $captured = [];
        $engine = $this->engineWithMockTransport($captured);
        $engine->eval(<<<'JS'
globalThis.__c = 0;
const ws = new WebSocket('wss://example.com/');
const cb = () => globalThis.__c++;
ws.addEventListener('open', cb);
ws.addEventListener('open', cb);  // duplicate — should be deduped
ws.addEventListener('open', () => globalThis.__c++);  // distinct cb
JS);
        ($captured['emit'])('open');
        $this->assertSame(2, $engine->eval('globalThis.__c'));
    }

    public function testBinaryTypeAccessor(): void
    {
        $captured = [];
        $engine = $this->engineWithMockTransport($captured);
        $result = $engine->eval(<<<'JS'
const ws = new WebSocket('wss://example.com/');
const a = ws.binaryType;
ws.binaryType = 'blob';
const b = ws.binaryType;
ws.binaryType = 'invalid';   // silently ignored per spec
const c = ws.binaryType;
[a, b, c].join(',')
JS);
        $this->assertSame('arraybuffer,blob,blob', $result);
    }

    public function testReadyStateConstantsExposed(): void
    {
        $captured = [];
        $engine = $this->engineWithMockTransport($captured);
        $result = $engine->eval(<<<'JS'
[
  WebSocket.CONNECTING, WebSocket.OPEN,
  WebSocket.CLOSING, WebSocket.CLOSED,
  new WebSocket('wss://x/').CLOSED
].join(',')
JS);
        $this->assertSame('0,1,2,3,3', $result);
    }

    public function testLazyWebSocketExposed(): void
    {
        $engine = new Engine();
        $this->assertSame('function', $engine->eval('typeof WebSocket'));
    }
}
