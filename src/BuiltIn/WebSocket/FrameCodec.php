<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\WebSocket;

/**
 * RFC 6455 §5.2 frame codec — pure byte-string encode / decode.
 *
 * Encoding is deterministic except for the 4-byte client mask, which
 * is randomised per frame. Decoding is streaming: feed bytes into
 * `decode()` along with the running buffer and it returns either a
 * decoded frame plus the leftover bytes, or null when the buffer is
 * short of a complete frame.
 *
 * Scope: this codec handles the wire format only — single-frame text /
 * binary / close / ping / pong. Fragmentation (FIN=0 continuation
 * chains) is surfaced to callers via the raw fin/opcode fields; the
 * transport layer is responsible for reassembly. Extensions
 * (permessage-deflate etc.) are NOT supported; RSV bits must be zero
 * on input and are always zero on output.
 */
final class FrameCodec
{
    public const OP_CONTINUATION = 0x0;
    public const OP_TEXT         = 0x1;
    public const OP_BINARY       = 0x2;
    public const OP_CLOSE        = 0x8;
    public const OP_PING         = 0x9;
    public const OP_PONG         = 0xA;

    public const MAX_PAYLOAD = 1 << 30; // 1 GiB — a sanity ceiling.

    /**
     * Encode one frame. `$mask` is the four-byte client mask; pass
     * `null` to skip masking (server-to-client direction — only useful
     * in tests).
     *
     * @return string  raw wire bytes
     */
    public static function encode(
        int $opcode,
        string $payload,
        bool $fin = true,
        ?string $mask = null,
    ): string {
        if ($opcode < 0 || $opcode > 0xF) {
            throw new \InvalidArgumentException("opcode out of range: {$opcode}");
        }
        $b1 = ($fin ? 0x80 : 0x00) | ($opcode & 0x0F);

        $len = strlen($payload);
        if ($len > self::MAX_PAYLOAD) {
            throw new \InvalidArgumentException("payload too large: {$len}");
        }

        $maskBit = $mask !== null ? 0x80 : 0x00;
        $header = chr($b1);

        if ($len < 126) {
            $header .= chr($maskBit | $len);
        } elseif ($len <= 0xFFFF) {
            $header .= chr($maskBit | 126) . pack('n', $len);
        } else {
            // 64-bit big-endian. PHP's `J` pack always emits 8 bytes
            // even on 32-bit but the high bits will be zero for sane
            // payloads (our MAX_PAYLOAD ceiling is 1 GiB).
            $header .= chr($maskBit | 127) . pack('J', $len);
        }

        if ($mask !== null) {
            if (strlen($mask) !== 4) {
                throw new \InvalidArgumentException("mask must be exactly 4 bytes");
            }
            $header .= $mask;
            $payload = self::applyMask($payload, $mask);
        }

        return $header . $payload;
    }

    /**
     * Attempt to decode a single frame from `$buf`. On success, returns
     * `['frame' => DecodedFrame, 'rest' => string]`. On need-more-data,
     * returns null and the caller should keep buffering. Throws on
     * protocol violation (reserved bits set, masked server→client
     * frame is fine; unmasked client→server frame from a client is the
     * usual concern but this codec is symmetric, so the transport
     * decides).
     *
     * @return array{frame: DecodedFrame, rest: string}|null
     */
    public static function decode(string $buf): ?array
    {
        $len = strlen($buf);
        if ($len < 2) {
            return null;
        }

        $b1 = ord($buf[0]);
        $b2 = ord($buf[1]);

        $fin = ($b1 & 0x80) !== 0;
        $rsv = ($b1 & 0x70) >> 4;
        $opcode = $b1 & 0x0F;
        $masked = ($b2 & 0x80) !== 0;
        $payLen = $b2 & 0x7F;

        if ($rsv !== 0) {
            throw new \RuntimeException("RFC 6455: reserved bits set without negotiated extension");
        }

        $offset = 2;

        if ($payLen === 126) {
            if ($len < $offset + 2) {
                return null;
            }
            /** @var array{1:int} $u */
            $u = unpack('n', substr($buf, $offset, 2));
            $payLen = $u[1];
            $offset += 2;
        } elseif ($payLen === 127) {
            if ($len < $offset + 8) {
                return null;
            }
            /** @var array{1:int} $u */
            $u = unpack('J', substr($buf, $offset, 8));
            $payLen = $u[1];
            $offset += 8;
        }

        if ($payLen < 0 || $payLen > self::MAX_PAYLOAD) {
            throw new \RuntimeException("RFC 6455: payload length out of range");
        }

        $mask = null;
        if ($masked) {
            if ($len < $offset + 4) {
                return null;
            }
            $mask = substr($buf, $offset, 4);
            $offset += 4;
        }

        if ($len < $offset + $payLen) {
            return null;
        }

        $payload = substr($buf, $offset, $payLen);
        $offset += $payLen;

        if ($mask !== null && $payload !== '') {
            $payload = self::applyMask($payload, $mask);
        }

        return [
            'frame' => new DecodedFrame($fin, $opcode, $payload),
            'rest'  => substr($buf, $offset),
        ];
    }

    /**
     * XOR mask (RFC 6455 §5.3). Symmetric — same routine applies and
     * removes the mask.
     */
    public static function applyMask(string $payload, string $mask): string
    {
        $len = strlen($payload);
        if ($len === 0) {
            return '';
        }
        // Build a mask of the same length by repeating the 4-byte key
        // then XOR in one shot. ~3× faster than per-byte for typical
        // frame sizes vs. an explicit loop.
        $full = str_repeat($mask, intdiv($len, 4) + 1);
        return $payload ^ substr($full, 0, $len);
    }

    /**
     * Parse the two-byte close-frame payload prefix (RFC 6455 §5.5.1).
     * Returns `[code, reason]`. Empty payload → 1005 + ''. Malformed
     * (1-byte) payload → 1002 (protocol error).
     *
     * @return array{0:int, 1:string}
     */
    public static function parseClosePayload(string $payload): array
    {
        $len = strlen($payload);
        if ($len === 0) {
            return [1005, ''];
        }
        if ($len === 1) {
            return [1002, ''];
        }
        /** @var array{1:int} $u */
        $u = unpack('n', substr($payload, 0, 2));
        $code = $u[1];
        $reason = $len > 2 ? substr($payload, 2) : '';
        return [$code, $reason];
    }

    /** Pack a close-frame payload (code + UTF-8 reason). */
    public static function buildClosePayload(int $code, string $reason): string
    {
        if ($code === 0) {
            // 0 means "no code"; send an empty payload.
            return '';
        }
        // Per RFC, reason MUST be valid UTF-8 and total payload ≤125 bytes.
        // We truncate the reason at 123 bytes (2-byte code + 123).
        if (strlen($reason) > 123) {
            $reason = substr($reason, 0, 123);
        }
        return pack('n', $code) . $reason;
    }
}
