<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\WebSocket;

/**
 * Immutable result of decoding a single RFC 6455 frame. Exposes the
 * unmasked payload and the header bits we actually consume. RSV bits
 * are validated as zero by the codec, so they're not carried here.
 */
final class DecodedFrame
{
    public function __construct(
        public readonly bool $fin,
        public readonly int $opcode,
        public readonly string $payload,
    ) {
    }
}
