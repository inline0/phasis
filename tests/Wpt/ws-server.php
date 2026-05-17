<?php

declare(strict_types=1);

/**
 * Phasis WPT WebSocket test server.
 *
 * Implements just enough of RFC 6455 to drive the imported subset of
 * `tests/Wpt/upstream/websockets/*.any.js` fixtures. The same
 * `FrameCodec` the engine uses is reused on the server side so the
 * encoder/decoder stays trusted-by-test.
 *
 * Endpoints (path-routed after the HTTP Upgrade):
 *   /echo                     — bounce every text/binary frame back.
 *   /handshake_sleep_2        — sleep 2s before accepting handshake.
 *   /handshake_no_extensions  — handshake without Sec-WebSocket-Extensions.
 *   /delayed-passive-close    — echo, but wait 1s before responding to
 *                               a peer close (Close-delayed.any.js timing).
 *   /server-close             — accept, then issue close from server.
 *
 * The server runs `stream_socket_server`. One process per client; we
 * fork via `pcntl_fork` when available, otherwise serialize. Tests in
 * our suite are inherently low-concurrency, so a queue is fine.
 *
 * Run: `php tests/Wpt/ws-server.php 8888`
 */

require __DIR__ . '/../../vendor/autoload.php';

use Phasis\BuiltIn\WebSocket\FrameCodec;

$port = (int) ($argv[1] ?? 8888);
$listen = sprintf('tcp://127.0.0.1:%d', $port);

$errno = 0;
$errstr = '';
$server = @stream_socket_server($listen, $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "ws-server: bind failed on {$listen}: {$errstr} (errno {$errno})\n");
    exit(1);
}

stream_set_blocking($server, false);

while (true) {
    $reads = [$server];
    $writes = null;
    $excepts = null;
    if (stream_select($reads, $writes, $excepts, 1, 0) > 0) {
        $client = @stream_socket_accept($server, 1);
        if ($client !== false) {
            handle_client($client);
            fclose($client);
        }
    }
}

/** Handle one client connection start-to-finish. */
function handle_client($client): void
{
    stream_set_blocking($client, true);
    stream_set_timeout($client, 5);

    $request = '';
    while (!feof($client) && strpos($request, "\r\n\r\n") === false) {
        $chunk = @fread($client, 4096);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $request .= $chunk;
        if (strlen($request) > 65535) {
            return;
        }
    }
    if ($request === '') {
        return;
    }

    [$method, $path, $headers] = parse_request($request);
    if ($method !== 'GET') {
        return;
    }

    $hKey = strtolower($headers['sec-websocket-key'] ?? '');
    if ($hKey === '') {
        // Not a WebSocket request — bail.
        fwrite($client, "HTTP/1.1 400 Bad Request\r\n\r\n");
        return;
    }

    // /handshake_sleep_2 — delay the handshake (close-connecting-async)
    if (str_contains($path, '/handshake_sleep_2')) {
        sleep(2);
    }

    $accept = base64_encode(sha1(
        $hKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11',
        true,
    ));

    $resp = "HTTP/1.1 101 Switching Protocols\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Accept: {$accept}\r\n";
    // Echo back one subprotocol if the client sent any (just pick the
    // first non-empty one).
    $protocols = $headers['sec-websocket-protocol'] ?? '';
    if ($protocols !== '') {
        $first = trim(explode(',', $protocols)[0]);
        if ($first !== '') {
            $resp .= "Sec-WebSocket-Protocol: {$first}\r\n";
        }
    }
    if (!str_contains($path, '/handshake_no_extensions')) {
        // The Create-extensions-empty test asserts `wsocket.extensions
        // === ""`, which means the server returned no extensions header.
        // We never negotiate any (no permessage-deflate), so it stays
        // empty by virtue of not sending the header at all.
    }
    $resp .= "\r\n";
    fwrite($client, $resp);

    if (str_contains($path, '/server-close')) {
        // Initiate a clean close right after the handshake.
        $payload = pack('n', 1000) . 'server-initiated';
        fwrite($client, FrameCodec::encode(FrameCodec::OP_CLOSE, $payload));
        return;
    }

    // Echo loop: decode incoming frames, mirror text/binary, respond
    // to ping with pong, terminate on close.
    $buffer = '';
    stream_set_timeout($client, 30);
    while (!feof($client)) {
        $chunk = @fread($client, 16384);
        if ($chunk === false || $chunk === '') {
            $meta = stream_get_meta_data($client);
            if (!empty($meta['timed_out'])) {
                return;
            }
            break;
        }
        $buffer .= $chunk;
        while (strlen($buffer) > 0) {
            try {
                $decoded = FrameCodec::decode($buffer);
            } catch (\Throwable $e) {
                return;
            }
            if ($decoded === null) {
                break;
            }
            /** @var \Phasis\BuiltIn\WebSocket\DecodedFrame $frame */
            $frame = $decoded['frame'];
            $buffer = $decoded['rest'];

            if ($frame->opcode === FrameCodec::OP_CLOSE) {
                // /delayed-passive-close: pause before echoing the
                // close back so the client observes a ~1s gap between
                // calling close() and seeing 'close' event.
                if (str_contains($path, '/delayed-passive-close')) {
                    sleep(1);
                }
                fwrite($client, FrameCodec::encode(FrameCodec::OP_CLOSE, $frame->payload));
                return;
            }
            if ($frame->opcode === FrameCodec::OP_PING) {
                fwrite($client, FrameCodec::encode(FrameCodec::OP_PONG, $frame->payload));
                continue;
            }
            if ($frame->opcode === FrameCodec::OP_PONG) {
                continue;
            }
            if ($frame->opcode === FrameCodec::OP_TEXT || $frame->opcode === FrameCodec::OP_BINARY) {
                fwrite($client, FrameCodec::encode($frame->opcode, $frame->payload));
            }
        }
    }
}

/**
 * Parse an HTTP request prologue into method, path, and lowercased
 * header map. Used only to inspect Sec-WebSocket-Key / -Protocol —
 * we don't need full HTTP semantics here.
 *
 * @return array{0:string,1:string,2:array<string,string>}
 */
function parse_request(string $req): array
{
    $lines = preg_split('/\r\n/', $req, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (empty($lines)) {
        return ['', '/', []];
    }
    $request = array_shift($lines);
    $parts = preg_split('/\s+/', $request) ?: [];
    $method = $parts[0] ?? '';
    $path = $parts[1] ?? '/';
    $headers = [];
    foreach ($lines as $line) {
        $colon = strpos($line, ':');
        if ($colon === false) {
            continue;
        }
        $name = strtolower(trim(substr($line, 0, $colon)));
        $value = trim(substr($line, $colon + 1));
        $headers[$name] = $value;
    }
    return [$method, $path, $headers];
}
