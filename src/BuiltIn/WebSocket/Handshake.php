<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\WebSocket;

/**
 * Pure helpers for the RFC 6455 §4 opening handshake — separated from
 * the transport so they can be unit-tested without any sockets.
 *
 * The pieces here:
 *   - generateKey()       — 16 random bytes, base64-encoded for the
 *                           Sec-WebSocket-Key request header.
 *   - deriveAccept()      — SHA-1(key + magic-GUID), base64-encoded;
 *                           must match the server's
 *                           Sec-WebSocket-Accept response header.
 *   - buildRequest()      — assemble the HTTP/1.1 Upgrade request bytes.
 *   - parseResponse()     — parse the server's 101 response into a
 *                           status code + lowercased header map +
 *                           leftover-body bytes (anything past the
 *                           first blank line is already-arrived WS
 *                           frame data we need to feed to the codec).
 *   - splitUrl()          — split ws:// or wss:// into host / port /
 *                           path / TLS-flag.
 */
final class Handshake
{
    /** RFC 6455 §1.3 magic GUID. */
    public const MAGIC = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** Base64 of 16 random bytes — the value of `Sec-WebSocket-Key`. */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(16));
    }

    /** Compute `Sec-WebSocket-Accept` from the client key (RFC 6455 §4.2.2 step 5.4). */
    public static function deriveAccept(string $clientKey): string
    {
        return base64_encode(sha1($clientKey . self::MAGIC, true));
    }

    /**
     * Assemble the request bytes. Caller has already done URL parsing
     * via splitUrl().
     *
     * @param list<string> $protocols
     * @param array<string, string> $extraHeaders  case-preserved extra
     *                                             headers (e.g. Origin)
     */
    public static function buildRequest(
        string $host,
        int $port,
        string $path,
        string $key,
        array $protocols = [],
        array $extraHeaders = [],
        bool $tls = false,
    ): string {
        // The Host header omits the default port (80 / 443) — many
        // servers reject `example.com:443` for wss when the cert CN is
        // unqualified.
        $defaultPort = $tls ? 443 : 80;
        $hostHeader = $port === $defaultPort ? $host : "{$host}:{$port}";

        $lines = [
            "GET {$path} HTTP/1.1",
            "Host: {$hostHeader}",
            "Upgrade: websocket",
            "Connection: Upgrade",
            "Sec-WebSocket-Key: {$key}",
            "Sec-WebSocket-Version: 13",
        ];
        if ($protocols !== []) {
            $lines[] = "Sec-WebSocket-Protocol: " . implode(', ', $protocols);
        }
        foreach ($extraHeaders as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        return implode("\r\n", $lines) . "\r\n\r\n";
    }

    /**
     * Parse the server's HTTP response. Returns:
     *   status   : int (HTTP status line)
     *   headers  : array<string, string> (lowercased name → last value)
     *   body     : string (bytes after the header terminator — these
     *              already belong to the WebSocket framing layer)
     *
     * Returns null if the header terminator (`\r\n\r\n`) isn't yet in
     * the buffer.
     *
     * @return array{status:int, headers:array<string, string>, body:string}|null
     */
    public static function parseResponse(string $buf): ?array
    {
        $sep = strpos($buf, "\r\n\r\n");
        if ($sep === false) {
            return null;
        }
        $head = substr($buf, 0, $sep);
        $body = substr($buf, $sep + 4);

        // explode() always returns at least one element, so the head
        // line is the first element. Empty string surfaces a clear
        // error on the next check.
        $lines = explode("\r\n", $head);
        $statusLine = (string) array_shift($lines);
        if ($statusLine === '') {
            throw new \RuntimeException('WebSocket: empty status line');
        }
        // "HTTP/1.1 101 Switching Protocols"
        if (!preg_match('#^HTTP/1\.[01]\s+(\d{3})\s*#', $statusLine, $m)) {
            throw new \RuntimeException("WebSocket: bad status line: " . $statusLine);
        }
        $status = (int) $m[1];

        $headers = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            // Last-write-wins for duplicates — fine for the headers
            // we care about (Upgrade, Connection, Sec-WebSocket-*).
            $headers[$name] = $value;
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    /**
     * Split `ws://host:port/path` or `wss://host/path` into parts.
     * Throws on malformed URLs. Default port is 80 for ws, 443 for wss.
     *
     * @return array{host:string, port:int, path:string, tls:bool}
     */
    public static function splitUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException("WebSocket: invalid URL: {$url}");
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'ws' && $scheme !== 'wss') {
            throw new \RuntimeException("WebSocket: unsupported scheme: {$scheme}");
        }
        $tls = $scheme === 'wss';
        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? (int) $parts['port'] : ($tls ? 443 : 80);
        $path = (string) ($parts['path'] ?? '/');
        if (isset($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }
        return ['host' => $host, 'port' => $port, 'path' => $path, 'tls' => $tls];
    }

    /**
     * Validate the server's 101 response: status code, Upgrade /
     * Connection tokens, Sec-WebSocket-Accept match. Throws on any
     * mismatch with a human-readable diagnostic. Returns the
     * negotiated subprotocol (empty when none).
     *
     * @param array{status:int, headers:array<string, string>, body:string} $response
     */
    public static function validateResponse(array $response, string $clientKey): string
    {
        if ($response['status'] !== 101) {
            throw new \RuntimeException(
                "WebSocket: expected HTTP 101, got {$response['status']}"
            );
        }
        $headers = $response['headers'];
        $upgrade = strtolower($headers['upgrade'] ?? '');
        if ($upgrade !== 'websocket') {
            throw new \RuntimeException(
                "WebSocket: missing or wrong Upgrade header: '{$upgrade}'"
            );
        }
        $connection = strtolower($headers['connection'] ?? '');
        // The Connection header may be a list ("upgrade, keep-alive");
        // accept any token equal to "upgrade".
        $connOk = false;
        foreach (explode(',', $connection) as $tok) {
            if (trim($tok) === 'upgrade') {
                $connOk = true;
                break;
            }
        }
        if (!$connOk) {
            throw new \RuntimeException(
                "WebSocket: missing or wrong Connection header: '{$connection}'"
            );
        }
        $expectedAccept = self::deriveAccept($clientKey);
        $accept = $headers['sec-websocket-accept'] ?? '';
        if ($accept !== $expectedAccept) {
            throw new \RuntimeException(
                "WebSocket: Sec-WebSocket-Accept mismatch (got '{$accept}', expected '{$expectedAccept}')"
            );
        }
        return $headers['sec-websocket-protocol'] ?? '';
    }
}
