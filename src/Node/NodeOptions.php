<?php

declare(strict_types=1);

namespace Phasis\Node;

/** Host configuration for an opt-in Node-compatible realm. */
final readonly class NodeOptions
{
    /**
     * @param ?string $cwd Initial working directory; null uses the PHP working directory.
     * @param ?array<string, string> $env Environment variables; null inherits the PHP environment.
     * @param list<string> $argv Program arguments, including the executable and script when supplied.
     */
    public function __construct(
        public ?string $cwd = null,
        public ?array $env = null,
        public array $argv = [],
    ) {
    }
}
