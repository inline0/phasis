<?php

declare(strict_types=1);

namespace PhpJs\Regex\Ast;

/**
 * A capturing or non-capturing group. Capturing groups have an index
 * (1-based, -1 for non-capturing) and optional name.
 */
class Group extends Node
{
    public function __construct(
        public readonly Node $body,
        public readonly int $index = -1,
        public readonly ?string $name = null,
    ) {
    }

    public function isCapturing(): bool
    {
        return $this->index > 0;
    }
}
