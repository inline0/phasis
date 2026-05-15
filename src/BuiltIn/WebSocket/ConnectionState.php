<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\WebSocket;

/**
 * Mutable per-connection state for `StreamSocketTransport`. Kept as a
 * plain DTO so the static-method transport can pass it by reference
 * to its helpers without hauling around a giant closure capture list.
 */
final class ConnectionState
{
    public const PHASE_HANDSHAKE = 'handshake';
    public const PHASE_OPEN      = 'open';
    public const PHASE_CLOSING   = 'closing';
    public const PHASE_CLOSED    = 'closed';

    /** @var resource|null */
    public mixed $socket = null;

    public string $clientKey = '';

    public string $phase = self::PHASE_HANDSHAKE;

    /** Bytes received from the peer but not yet consumed. */
    public string $inbound = '';

    /** Bytes queued for write — the HTTP upgrade + framed frames. */
    public string $outbound = '';

    /** Continuation-frame opcode (text/binary) currently in flight. */
    public ?int $assemblyOpcode = null;

    /** Assembled bytes from the in-flight fragmented message. */
    public string $assemblyBuf = '';

    /** Event-loop timer id for the poll tick (so we can cancel it). */
    public ?int $timerId = null;

    /** Bookkeeping for close-frame round-trip. */
    public int $closeCodeSent = 0;
    public string $closeReasonSent = '';
}
