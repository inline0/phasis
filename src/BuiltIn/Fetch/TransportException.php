<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Fetch;

/**
 * Exception thrown by `CurlTransport::send()` (and any drop-in transport
 * replacement) when the request cannot be delivered. Carries a `kind`
 * string the fetch driver maps to a spec-defined JS exception:
 *
 *   - "aborted"       → AbortError DOMException
 *   - "timeout"       → TypeError (per spec, network errors include timeouts)
 *   - "network-error" → TypeError
 */
final class TransportException extends \Exception
{
    public function __construct(string $message, public readonly string $kind = 'network-error')
    {
        parent::__construct($message);
    }
}
