<?php

declare(strict_types=1);

namespace Phasis\Node;

use Phasis\Value\JsFunction;

/** One nonblocking socket owned by a Node realm. */
final class SocketState
{
    public bool $referenced = true;
    public bool $paused = false;
    public bool $connecting = false;
    public bool $readEnded = false;
    public bool $writeEnded = false;
    public bool $ending = false;
    public string $output = '';
    public ?JsFunction $writeCallback = null;
    public bool $tlsNegotiating = false;
    public bool $tlsHandshakeStarted = false;
    public bool $tlsServer = false;
    public bool $tls = false;
    public int $tlsMethod = 0;
    public float $tlsDeadline = 0;
    /** @var list<string> */
    public array $tlsFiles = [];
    /** @var list<string> */
    public array $tlsAuthorities = [];

    /**
     * @param resource $stream
     * @param array{0: array<int, \Phasis\Value\JsObject>, 1: array<int, \Phasis\Value\JsValue>} $context
     */
    public function __construct(
        public readonly int $id,
        public mixed $stream,
        public readonly bool $server,
        public ?JsFunction $callback,
        public readonly array $context,
    ) {
    }
}
