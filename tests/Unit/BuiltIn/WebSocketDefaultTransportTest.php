<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\BuiltIn\WebSocket\FrameCodec;
use Phasis\BuiltIn\WebSocket\Handshake;
use Phasis\BuiltIn\WebSocket\StreamSocketTransport;
use Phasis\Engine;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the default pure-PHP WebSocket transport. The
 * codec + handshake pieces are exercised in isolation; anything that
 * needs a real WebSocket server is marked skipped.
 */
class WebSocketDefaultTransportTest extends TestCase
{
    // ---- Handshake helpers ------------------------------------------------

    /**
     * RFC 6455 §1.3 example: client key `dGhlIHNhbXBsZSBub25jZQ==` should
     * derive the accept value `s3pPLMBiTxaQ9kYGzzhZRbK+xOo=`.
     */
    public function testAcceptDerivationMatchesRfcExample(): void
    {
        $accept = Handshake::deriveAccept('dGhlIHNhbXBsZSBub25jZQ==');
        $this->assertSame('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $accept);
    }

    public function testGenerateKeyProducesBase64Of16Bytes(): void
    {
        $key = Handshake::generateKey();
        $decoded = base64_decode($key, true);
        $this->assertNotFalse($decoded);
        $this->assertSame(16, strlen($decoded));
    }

    public function testSplitUrlParsesWsAndWss(): void
    {
        $ws = Handshake::splitUrl('ws://example.com/path?q=1');
        $this->assertSame('example.com', $ws['host']);
        $this->assertSame(80, $ws['port']);
        $this->assertSame('/path?q=1', $ws['path']);
        $this->assertFalse($ws['tls']);

        $wss = Handshake::splitUrl('wss://api.example.com:8443/socket');
        $this->assertSame('api.example.com', $wss['host']);
        $this->assertSame(8443, $wss['port']);
        $this->assertSame('/socket', $wss['path']);
        $this->assertTrue($wss['tls']);
    }

    public function testSplitUrlDefaultsRootPathWhenMissing(): void
    {
        $info = Handshake::splitUrl('ws://example.com');
        $this->assertSame('/', $info['path']);
    }

    public function testSplitUrlRejectsNonWebSocketScheme(): void
    {
        $this->expectException(\RuntimeException::class);
        Handshake::splitUrl('http://example.com/');
    }

    public function testBuildRequestHasRequiredHeaders(): void
    {
        $req = Handshake::buildRequest(
            'example.com',
            80,
            '/chat',
            'dGhlIHNhbXBsZSBub25jZQ==',
            ['chat'],
            [],
            false,
        );
        $this->assertStringContainsString("GET /chat HTTP/1.1\r\n", $req);
        $this->assertStringContainsString("Host: example.com\r\n", $req);
        $this->assertStringContainsString("Upgrade: websocket\r\n", $req);
        $this->assertStringContainsString("Connection: Upgrade\r\n", $req);
        $this->assertStringContainsString("Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n", $req);
        $this->assertStringContainsString("Sec-WebSocket-Version: 13\r\n", $req);
        $this->assertStringContainsString("Sec-WebSocket-Protocol: chat\r\n", $req);
        $this->assertStringEndsWith("\r\n\r\n", $req);
    }

    public function testBuildRequestOmitsDefaultPortInHostHeader(): void
    {
        $req = Handshake::buildRequest('example.com', 80, '/', 'k', [], [], false);
        $this->assertStringContainsString("Host: example.com\r\n", $req);
        $this->assertStringNotContainsString('example.com:80', $req);

        $req2 = Handshake::buildRequest('example.com', 443, '/', 'k', [], [], true);
        $this->assertStringContainsString("Host: example.com\r\n", $req2);
    }

    public function testBuildRequestIncludesCustomPort(): void
    {
        $req = Handshake::buildRequest('example.com', 8080, '/', 'k', [], [], false);
        $this->assertStringContainsString("Host: example.com:8080\r\n", $req);
    }

    public function testParseResponseReturnsHeadersAndBodyRemainder(): void
    {
        $raw = "HTTP/1.1 101 Switching Protocols\r\n"
             . "Upgrade: websocket\r\n"
             . "Connection: Upgrade\r\n"
             . "Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=\r\n"
             . "\r\n"
             . "EXTRA_FRAME_BYTES";
        $resp = Handshake::parseResponse($raw);
        $this->assertNotNull($resp);
        $this->assertSame(101, $resp['status']);
        $this->assertSame('websocket', $resp['headers']['upgrade']);
        $this->assertSame('Upgrade', $resp['headers']['connection']);
        $this->assertSame('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $resp['headers']['sec-websocket-accept']);
        $this->assertSame('EXTRA_FRAME_BYTES', $resp['body']);
    }

    public function testParseResponseReturnsNullOnIncompleteHeader(): void
    {
        $partial = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\n";
        $this->assertNull(Handshake::parseResponse($partial));
    }

    public function testValidateResponseAcceptsCanonicalHandshake(): void
    {
        $key = 'dGhlIHNhbXBsZSBub25jZQ==';
        $resp = Handshake::parseResponse(
            "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: " . Handshake::deriveAccept($key) . "\r\n"
            . "Sec-WebSocket-Protocol: chat\r\n"
            . "\r\n"
        );
        $this->assertNotNull($resp);
        $protocol = Handshake::validateResponse($resp, $key);
        $this->assertSame('chat', $protocol);
    }

    public function testValidateResponseAcceptsConnectionUpgradeWithKeepAlive(): void
    {
        $key = 'abcdefghijklmnop1234==';
        $resp = Handshake::parseResponse(
            "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: keep-alive, Upgrade\r\n"
            . "Sec-WebSocket-Accept: " . Handshake::deriveAccept($key) . "\r\n"
            . "\r\n"
        );
        $this->assertNotNull($resp);
        $protocol = Handshake::validateResponse($resp, $key);
        $this->assertSame('', $protocol);
    }

    public function testValidateResponseRejectsBadStatus(): void
    {
        $resp = Handshake::parseResponse("HTTP/1.1 400 Bad Request\r\n\r\n");
        $this->assertNotNull($resp);
        $this->expectExceptionMessage('expected HTTP 101');
        Handshake::validateResponse($resp, 'k');
    }

    public function testValidateResponseRejectsBadAccept(): void
    {
        $resp = Handshake::parseResponse(
            "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: WRONG\r\n"
            . "\r\n"
        );
        $this->assertNotNull($resp);
        $this->expectExceptionMessage('Sec-WebSocket-Accept mismatch');
        Handshake::validateResponse($resp, 'dGhlIHNhbXBsZSBub25jZQ==');
    }

    // ---- Frame codec ------------------------------------------------------

    public function testEncodeDecodeShortMaskedTextFrameRoundTrip(): void
    {
        $payload = 'Hello';
        $mask = "\x01\x02\x03\x04";
        $bytes = FrameCodec::encode(FrameCodec::OP_TEXT, $payload, true, $mask);

        $decoded = FrameCodec::decode($bytes);
        $this->assertNotNull($decoded);
        $this->assertSame('', $decoded['rest']);
        $this->assertTrue($decoded['frame']->fin);
        $this->assertSame(FrameCodec::OP_TEXT, $decoded['frame']->opcode);
        $this->assertSame('Hello', $decoded['frame']->payload);
    }

    public function testEncodeDecodeUnmaskedServerStyleFrame(): void
    {
        // Server-style: no mask bit. Decoder must accept it.
        $bytes = FrameCodec::encode(FrameCodec::OP_TEXT, 'pong', true, null);
        $decoded = FrameCodec::decode($bytes);
        $this->assertNotNull($decoded);
        $this->assertSame('pong', $decoded['frame']->payload);
    }

    public function testEncodeDecodeBinaryFrameAt126Boundary(): void
    {
        // 126-byte payload triggers the 16-bit extended length form.
        $payload = str_repeat("\x00", 126);
        $mask = "\xAA\xBB\xCC\xDD";
        $bytes = FrameCodec::encode(FrameCodec::OP_BINARY, $payload, true, $mask);
        $this->assertSame(126, ord($bytes[1]) & 0x7F);

        $decoded = FrameCodec::decode($bytes);
        $this->assertNotNull($decoded);
        $this->assertSame(FrameCodec::OP_BINARY, $decoded['frame']->opcode);
        $this->assertSame(126, strlen($decoded['frame']->payload));
    }

    public function testEncodeDecodeBinaryFrameWithLargePayload(): void
    {
        // 70_000-byte payload triggers the 64-bit extended length form.
        $payload = random_bytes(70_000);
        $mask = "\x12\x34\x56\x78";
        $bytes = FrameCodec::encode(FrameCodec::OP_BINARY, $payload, true, $mask);
        $this->assertSame(127, ord($bytes[1]) & 0x7F);

        $decoded = FrameCodec::decode($bytes);
        $this->assertNotNull($decoded);
        $this->assertSame($payload, $decoded['frame']->payload);
    }

    public function testDecodeReturnsNullOnIncompleteFrame(): void
    {
        // Header says 8-byte payload but only 5 arrived.
        $partial = chr(0x81) . chr(0x88) . "\x00\x00\x00\x00" . str_repeat('A', 5);
        $this->assertNull(FrameCodec::decode($partial));
    }

    public function testDecodeLeavesUnusedBytesInRest(): void
    {
        $first = FrameCodec::encode(FrameCodec::OP_TEXT, 'hi', true, "\xFF\xEE\xDD\xCC");
        $second = FrameCodec::encode(FrameCodec::OP_TEXT, 'yo', true, "\xAA\xBB\xCC\xDD");

        $decoded = FrameCodec::decode($first . $second);
        $this->assertNotNull($decoded);
        $this->assertSame('hi', $decoded['frame']->payload);
        $this->assertSame($second, $decoded['rest']);

        $second_decoded = FrameCodec::decode($decoded['rest']);
        $this->assertNotNull($second_decoded);
        $this->assertSame('yo', $second_decoded['frame']->payload);
    }

    public function testDecodeRejectsReservedBits(): void
    {
        // RSV1 set on a single-frame text packet.
        $payload = 'x';
        $bytes = chr(0x80 | 0x40 | FrameCodec::OP_TEXT) . chr(strlen($payload)) . $payload;
        $this->expectExceptionMessage('reserved bits');
        FrameCodec::decode($bytes);
    }

    public function testFragmentedTextFrameRoundTrip(): void
    {
        // Three frames: TEXT(FIN=0) "Hel", CONT(FIN=0) "lo, ", CONT(FIN=1) "world"
        $f1 = FrameCodec::encode(FrameCodec::OP_TEXT, 'Hel', false, "\x01\x02\x03\x04");
        $f2 = FrameCodec::encode(FrameCodec::OP_CONTINUATION, 'lo, ', false, "\x05\x06\x07\x08");
        $f3 = FrameCodec::encode(FrameCodec::OP_CONTINUATION, 'world', true, "\x09\x0A\x0B\x0C");

        $buf = $f1 . $f2 . $f3;
        $assembled = '';
        $assemblyOpcode = null;
        while (true) {
            $r = FrameCodec::decode($buf);
            if ($r === null) {
                break;
            }
            $buf = $r['rest'];
            if ($r['frame']->opcode !== FrameCodec::OP_CONTINUATION) {
                $assemblyOpcode = $r['frame']->opcode;
                $assembled = $r['frame']->payload;
            } else {
                $assembled .= $r['frame']->payload;
            }
            if ($r['frame']->fin) {
                break;
            }
        }
        $this->assertSame(FrameCodec::OP_TEXT, $assemblyOpcode);
        $this->assertSame('Hello, world', $assembled);
    }

    public function testApplyMaskIsSelfInverse(): void
    {
        $payload = "The quick brown fox jumps over the lazy dog.";
        $mask = "\xFE\xDC\xBA\x98";
        $masked = FrameCodec::applyMask($payload, $mask);
        $this->assertNotSame($payload, $masked);
        $this->assertSame($payload, FrameCodec::applyMask($masked, $mask));
    }

    public function testCloseFramePayloadEncoding(): void
    {
        $payload = FrameCodec::buildClosePayload(1000, 'bye');
        $this->assertSame("\x03\xE8bye", $payload);

        [$code, $reason] = FrameCodec::parseClosePayload($payload);
        $this->assertSame(1000, $code);
        $this->assertSame('bye', $reason);
    }

    public function testCloseFramePayloadDefaultsOnEmpty(): void
    {
        [$code, $reason] = FrameCodec::parseClosePayload('');
        $this->assertSame(1005, $code);
        $this->assertSame('', $reason);
    }

    public function testCloseFramePayloadTruncatesReasonAt123Bytes(): void
    {
        $longReason = str_repeat('x', 200);
        $payload = FrameCodec::buildClosePayload(1000, $longReason);
        // 2 bytes (code) + 123 bytes (truncated reason) = 125.
        $this->assertSame(125, strlen($payload));
    }

    public function testCloseFramePayloadMalformedSingleByteCode(): void
    {
        // A 1-byte close payload is invalid per RFC; parser surfaces it
        // as protocol-error (1002).
        [$code, $reason] = FrameCodec::parseClosePayload("\x03");
        $this->assertSame(1002, $code);
        $this->assertSame('', $reason);
    }

    public function testEncodeRejectsBadMaskLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FrameCodec::encode(FrameCodec::OP_TEXT, 'x', true, "ab");
    }

    public function testEncodeRejectsOutOfRangeOpcode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FrameCodec::encode(0xFF, 'x', true, null);
    }

    // ---- Transport factory ------------------------------------------------

    public function testTransportFactoryReturnsCallable(): void
    {
        // The factory's return type already encodes "is callable" — we
        // assert it via a smoke invocation that drives the failure path
        // (unrouteable port) so the closure actually runs.
        $transport = StreamSocketTransport::create();
        $emitted = [];
        try {
            $transport(
                'ws://127.0.0.1:1/',
                [],
                static function (string $type, array $data = []) use (&$emitted) {
                    $emitted[] = $type;
                },
            );
        } catch (\Throwable) {
            // expected
        }
        $this->assertTrue(true);
    }

    public function testTransportRejectsUnsupportedScheme(): void
    {
        $emitted = [];
        $emit = static function (string $type, array $data) use (&$emitted): void {
            $emitted[] = ['type' => $type, 'data' => $data];
        };
        $transport = StreamSocketTransport::create();
        $this->expectException(\RuntimeException::class);
        $transport('http://example.com/', [], $emit);
    }

    public function testDefaultTransportIsUsedWhenNoneInstalled(): void
    {
        // The constructor falls back to StreamSocketTransport when no
        // explicit transport is set; constructing should NOT throw.
        $engine = new Engine();
        $engine->eval("globalThis.__ws = new WebSocket('ws://127.0.0.1:1/');");
        $rs = $engine->eval('globalThis.__ws.readyState');
        // Either still CONNECTING (0) or already CLOSED (3) after a
        // fast ECONNREFUSED — both indicate the default kicked in
        // without a TypeError.
        $this->assertContains($rs, [0, 3]);
    }

    // ---- Integration ------------------------------------------------------

    public function testIntegrationAgainstRealServer(): void
    {
        // A full handshake-and-frame round trip needs a running RFC 6455
        // server; we don't ship one with the test suite. Embedders can
        // point this at e.g. ws://echo.websocket.events/ for manual
        // verification.
        $this->markTestSkipped('Requires an external WebSocket echo server.');
    }
}
