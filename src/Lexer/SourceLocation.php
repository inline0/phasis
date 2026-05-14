<?php

declare(strict_types=1);

namespace Phasis\Lexer;

readonly class SourceLocation
{
    public function __construct(
        public int $line,
        public int $column,
        public int $offset,
    ) {
    }
}
