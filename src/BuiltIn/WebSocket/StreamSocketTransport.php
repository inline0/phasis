<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\WebSocket;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * Pure-PHP WebSocket transport — `stream_socket_client` + RFC 6455
 * framing, no extensions beyond what ships with stock PHP.
 *
 * Lifecycle:
 *
 *   1. The transport callable is invoked from the WebSocket constructor.
 *      It parses the URL, opens a non-blocking TCP/TLS socket, queues
 *      the HTTP/1.1 Upgrade request, and registers a polling task on
 *      the realm's event loop. It returns immediately with `send` /
 *      `close` handles so the constructor never blocks.
 *
 *   2. Each poll tick:
 *        - reads available bytes (non-blocking) into the inbound buffer;
 *        - while in CONNECTING: tries to parse a complete HTTP response
 *          and, on success, validates the handshake and fires `open`;
 *        - while OPEN: pulls complete frames off the buffer, dispatches
 *          text / binary frames as `message`, replies to pings with
 *          pongs, and handles close frames by echoing a close back +
 *          firing `close`;
 *        - flushes any pending outbound bytes — `send()` queues bytes
 *          and lets the poll loop write them so the JS API stays
 *          synchronous from the caller's view.
 *
 *   3. The poll task is implemented as a setInterval-style timer
 *      wrapped in a native JsFunction (the EventLoop only takes
 *      JsFunction callbacks). When the connection terminates we cancel
 *      the timer so the loop can drain cleanly.
 *
 * Limitations (intentional, documented honestly):
 *
 *   - Fragmented frames (FIN=0 continuation chains) are reassembled
 *     for text/binary, but interleaved control frames inside a
 *     fragmented message ARE handled (RFC 6455 §5.4 requires this).
 *   - No `permessage-deflate` or any other extension — the request
 *     does not advertise Sec-WebSocket-Extensions and we reject
 *     responses that carry RSV bits.
 *   - The poller runs as a setInterval on the JS event loop, so it
 *     ONLY makes progress while the embedder drives the loop
 *     (`Engine::runEventLoop()`, `tick()`, `runUntilEmpty()`).
 *     `runUntilEmpty()` with a virtual-clock jump will busy-poll the
 *     socket; real-time embedders should use `runEventLoop()`.
 *   - The polling interval is fixed at ~5ms; suitable for typical
 *     interactive use (10–200ms server pushes) without burning CPU.
 *     Replace with a real `stream_select`-blocking driver for
 *     high-throughput workloads.
 *   - UTF-8 validation on text frames is NOT enforced; we surface the
 *     raw bytes as a JS string. Browsers are stricter (they fail the
 *     connection on invalid UTF-8); embedders that need that can wrap
 *     the transport.
 */
final class StreamSocketTransport
{
    /** Poll interval in milliseconds. */
    private const POLL_MS = 5.0;

    /** Read chunk size — bigger ⇒ fewer syscalls, more memory. */
    private const READ_CHUNK = 16384;

    /** Connect timeout (seconds). */
    private const CONNECT_TIMEOUT = 10.0;

    /**
     * Factory: returns the callable that satisfies the WebSocket
     * transport contract. Suitable for installing as the realm
     * default in WebSocketConstructor.
     *
     * @return callable(string, list<string>, callable): array{send: callable, close: callable}
     */
    public static function create(): callable
    {
        return static function (string $url, array $protocols, callable $emit): array {
            return self::connect($url, $protocols, $emit);
        };
    }

    /**
     * @param list<string> $protocols
     * @return array{send: callable(string, bool): void, close: callable(int, string): void}
     */
    private static function connect(string $url, array $protocols, callable $emit): array
    {
        $parts = Handshake::splitUrl($url);
        $key = Handshake::generateKey();
        $request = Handshake::buildRequest(
            $parts['host'],
            $parts['port'],
            $parts['path'],
            $key,
            $protocols,
            [],
            $parts['tls'],
        );

        // stream_socket_client wants `tls://` for the TLS variant; the
        // ws/wss scheme is JS-side only.
        $transport = $parts['tls'] ? 'tls' : 'tcp';
        $remote = sprintf('%s://%s:%d', $transport, $parts['host'], $parts['port']);

        $errno = 0;
        $errstr = '';
        // Async connect: stream_socket_client(..., STREAM_CLIENT_ASYNC_CONNECT)
        // returns a socket that may still be in the middle of TCP/TLS
        // handshake. The poll loop drives it to completion.
        $sock = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
        );
        if ($sock === false) {
            throw new \RuntimeException(
                "WebSocket: connect failed ({$errno}): {$errstr}"
            );
        }
        stream_set_blocking($sock, false);

        $state = new ConnectionState();
        $state->socket = $sock;
        $state->clientKey = $key;
        $state->phase = ConnectionState::PHASE_HANDSHAKE;
        $state->outbound = $request; // queue the HTTP upgrade for the first writable tick

        $realm = Engine::getCurrentRealm();
        $loop = $realm?->getEventLoop();
        if ($loop === null) {
            // No loop ⇒ no way to drive non-blocking I/O. Fall back to
            // a synchronous drive: pump until handshake completes or
            // the deadline expires. This keeps the transport usable in
            // tests / non-event-loop embedders even if the read loop
            // can't progress past `open`.
            self::pumpSynchronouslyUntilOpen($state, $emit);
        } else {
            // Wrap the poll-tick closure as a native JsFunction so the
            // EventLoop's setInterval can call it. Native callables
            // receive (JsValue $this_, array $args).
            $tickFn = JsFunction::fromCallable(
                '__phasisWsPollTick',
                static function (JsValue $thisVal, array $args) use ($state, $emit, $loop): JsValue {
                    self::pollTick($state, $emit);
                    // Cancel ourselves once fully closed so the loop
                    // can drain.
                    if ($state->phase === ConnectionState::PHASE_CLOSED && $state->timerId !== null) {
                        $loop->clear($state->timerId);
                        $state->timerId = null;
                    }
                    return JsUndefined::instance();
                },
                0,
            );
            $state->timerId = $loop->setInterval($tickFn, self::POLL_MS);
        }

        $send = static function (string $data, bool $isBinary) use ($state, $emit): void {
            if (
                $state->phase === ConnectionState::PHASE_CLOSED
                || $state->phase === ConnectionState::PHASE_CLOSING
            ) {
                return;
            }
            $opcode = $isBinary ? FrameCodec::OP_BINARY : FrameCodec::OP_TEXT;
            self::enqueueFrame($state, $opcode, $data);
            // Best-effort immediate flush so latency stays low; the
            // poll loop will catch any partial write.
            self::flushOutbound($state, $emit);
        };

        $close = static function (int $code, string $reason) use ($state, $emit): void {
            if ($state->phase === ConnectionState::PHASE_CLOSED) {
                return;
            }
            if ($state->phase === ConnectionState::PHASE_CLOSING) {
                return;
            }
            $state->phase = ConnectionState::PHASE_CLOSING;
            $state->closeCodeSent = $code;
            $state->closeReasonSent = $reason;
            $payload = FrameCodec::buildClosePayload($code, $reason);
            self::enqueueFrame($state, FrameCodec::OP_CLOSE, $payload);
            self::flushOutbound($state, $emit);
        };

        return ['send' => $send, 'close' => $close];
    }

    /**
     * One pass of the polling state machine. Safe to call any time —
     * does nothing once the connection is closed.
     */
    private static function pollTick(ConnectionState $state, callable $emit): void
    {
        if ($state->phase === ConnectionState::PHASE_CLOSED) {
            return;
        }
        try {
            self::flushOutbound($state, $emit);
            self::readIncoming($state);
            self::driveHandshakeOrFrames($state, $emit);
        } catch (\Throwable $e) {
            self::failConnection($state, $emit, $e->getMessage());
        }
    }

    /** Drain everything available on the socket into the inbound buffer. */
    private static function readIncoming(ConnectionState $state): void
    {
        if ($state->socket === null) {
            return;
        }
        // stream_select with a 0-second timeout polls without blocking.
        $r = [$state->socket];
        $w = null;
        $e = null;
        $ready = @stream_select($r, $w, $e, 0, 0);
        if ($ready === false || $ready === 0) {
            return;
        }
        // Keep reading while bytes arrive; stop on empty read (closed)
        // or fewer-than-chunk (would-block).
        while (true) {
            $chunk = @fread($state->socket, self::READ_CHUNK);
            if ($chunk === false) {
                throw new \RuntimeException('WebSocket: fread failed');
            }
            if ($chunk === '') {
                if (feof($state->socket)) {
                    throw new \RuntimeException('WebSocket: connection closed by peer');
                }
                return;
            }
            $state->inbound .= $chunk;
            if (strlen($chunk) < self::READ_CHUNK) {
                return;
            }
        }
    }

    /**
     * Try to flush queued outbound bytes via a non-blocking write.
     * Partial writes are normal under backpressure — we keep the
     * remainder for the next tick.
     */
    private static function flushOutbound(ConnectionState $state, callable $emit): void
    {
        if ($state->socket === null || $state->outbound === '') {
            return;
        }
        $w = [$state->socket];
        $r = null;
        $e = null;
        // 0-second select — we never block from inside flushOutbound.
        $ready = @stream_select($r, $w, $e, 0, 0);
        if ($ready === false || $ready === 0) {
            return;
        }
        $written = @fwrite($state->socket, $state->outbound);
        if ($written === false) {
            throw new \RuntimeException('WebSocket: fwrite failed');
        }
        if ($written > 0) {
            $state->outbound = substr($state->outbound, $written);
        }
    }

    /**
     * State-aware progression: depending on current phase, either
     * parse the HTTP handshake response or decode WebSocket frames
     * from the inbound buffer.
     */
    private static function driveHandshakeOrFrames(ConnectionState $state, callable $emit): void
    {
        if ($state->phase === ConnectionState::PHASE_HANDSHAKE) {
            $resp = Handshake::parseResponse($state->inbound);
            if ($resp === null) {
                return; // wait for more bytes
            }
            $protocol = Handshake::validateResponse($resp, $state->clientKey);
            // Anything past `\r\n\r\n` is already WebSocket frame data.
            $state->inbound = $resp['body'];
            $state->phase = ConnectionState::PHASE_OPEN;
            $emit('open', ['protocol' => $protocol, 'extensions' => '']);
        }

        if (
            $state->phase === ConnectionState::PHASE_OPEN
            || $state->phase === ConnectionState::PHASE_CLOSING
        ) {
            self::decodeFrames($state, $emit);
        }
    }

    /** Pull complete frames off the inbound buffer and dispatch them. */
    private static function decodeFrames(ConnectionState $state, callable $emit): void
    {
        while (true) {
            $decoded = FrameCodec::decode($state->inbound);
            if ($decoded === null) {
                return;
            }
            $state->inbound = $decoded['rest'];
            $frame = $decoded['frame'];

            // Control frames (opcode ≥ 8) are never fragmented and may
            // appear interleaved with a fragmented data message — they
            // get handled inline without disturbing the assembly buffer.
            if ($frame->opcode >= 0x8) {
                self::handleControlFrame($state, $emit, $frame);
                continue;
            }

            // Data frames: continuation or initial.
            if ($frame->opcode === FrameCodec::OP_CONTINUATION) {
                if ($state->assemblyOpcode === null) {
                    throw new \RuntimeException('WebSocket: unexpected continuation frame');
                }
                $state->assemblyBuf .= $frame->payload;
            } else {
                if ($state->assemblyOpcode !== null) {
                    throw new \RuntimeException(
                        'WebSocket: new data frame before previous fragmented message finished'
                    );
                }
                $state->assemblyOpcode = $frame->opcode;
                $state->assemblyBuf = $frame->payload;
            }

            if ($frame->fin) {
                $opcode = $state->assemblyOpcode;
                $payload = $state->assemblyBuf;
                $state->assemblyOpcode = null;
                $state->assemblyBuf = '';
                $emit('message', [
                    'data' => $payload,
                    'isBinary' => $opcode === FrameCodec::OP_BINARY,
                ]);
            }
        }
    }

    private static function handleControlFrame(
        ConnectionState $state,
        callable $emit,
        DecodedFrame $frame,
    ): void {
        switch ($frame->opcode) {
            case FrameCodec::OP_PING:
                // Reply with a pong carrying the same payload (§5.5.2).
                self::enqueueFrame($state, FrameCodec::OP_PONG, $frame->payload);
                self::flushOutbound($state, $emit);
                break;

            case FrameCodec::OP_PONG:
                // No application-layer pong handling exposed yet — we
                // just consume it. A future "did the server respond to
                // our ping?" keepalive can hook here.
                break;

            case FrameCodec::OP_CLOSE:
                [$code, $reason] = FrameCodec::parseClosePayload($frame->payload);
                $wasClean = true;
                if ($state->phase === ConnectionState::PHASE_OPEN) {
                    // Server-initiated close: echo a close frame back.
                    $state->phase = ConnectionState::PHASE_CLOSING;
                    $echo = FrameCodec::buildClosePayload($code, '');
                    self::enqueueFrame($state, FrameCodec::OP_CLOSE, $echo);
                    self::flushOutbound($state, $emit);
                }
                self::shutdownSocket($state);
                $state->phase = ConnectionState::PHASE_CLOSED;
                $emit('close', [
                    'code' => $code,
                    'reason' => $reason,
                    'wasClean' => $wasClean,
                ]);
                break;
        }
    }

    /** Encode + mask + append to the outbound buffer. */
    private static function enqueueFrame(ConnectionState $state, int $opcode, string $payload): void
    {
        // Client frames MUST be masked (§5.3). random_bytes gives the
        // 4-byte mask key per frame.
        $mask = random_bytes(4);
        $state->outbound .= FrameCodec::encode($opcode, $payload, true, $mask);
    }

    /**
     * Mark the connection failed and emit error + close. Idempotent —
     * a transport-level failure can race with a normal close.
     */
    private static function failConnection(
        ConnectionState $state,
        callable $emit,
        string $message,
    ): void {
        if ($state->phase === ConnectionState::PHASE_CLOSED) {
            return;
        }
        $state->phase = ConnectionState::PHASE_CLOSED;
        self::shutdownSocket($state);
        $emit('error', ['message' => $message]);
        $emit('close', [
            'code' => 1006,
            'reason' => $message,
            'wasClean' => false,
        ]);
    }

    private static function shutdownSocket(ConnectionState $state): void
    {
        if ($state->socket !== null) {
            @fclose($state->socket);
            $state->socket = null;
        }
    }

    /**
     * Fallback drive path used only when there is no realm event loop
     * (i.e. the transport is invoked outside an Engine, which is rare
     * in practice — embedder tests sometimes do this). Pumps the
     * socket synchronously with short stream_select waits until the
     * handshake completes or a 5-second budget expires. After that
     * the caller gets whatever state we landed in; without a loop
     * driver there is no read pump for incoming frames, so the
     * connection is essentially useless past `open`. We do this only
     * to keep the API contract — `send()` / `close()` still work for
     * one-shot writes.
     */
    private static function pumpSynchronouslyUntilOpen(
        ConnectionState $state,
        callable $emit,
    ): void {
        $deadline = microtime(true) + self::CONNECT_TIMEOUT;
        while (microtime(true) < $deadline) {
            try {
                self::flushOutbound($state, $emit);
                // Block up to 100ms waiting for handshake bytes.
                $r = [$state->socket];
                $w = null;
                $e = null;
                @stream_select($r, $w, $e, 0, 100_000);
                self::readIncoming($state);
                self::driveHandshakeOrFrames($state, $emit);
                if ($state->phase !== ConnectionState::PHASE_HANDSHAKE) {
                    return;
                }
            } catch (\Throwable $e) {
                self::failConnection($state, $emit, $e->getMessage());
                return;
            }
        }
        self::failConnection($state, $emit, 'WebSocket: handshake timeout');
    }
}
