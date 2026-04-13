<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

class CallFrame
{
    public function __construct(
        public readonly string $name,
        public readonly int $line,
    ) {
    }
}
