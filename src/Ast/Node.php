<?php

declare(strict_types=1);

namespace PhpJs\Ast;

use PhpJs\Lexer\SourceLocation;

abstract readonly class Node
{
    public function __construct(
        public SourceLocation $location,
    ) {
    }

    abstract public function type(): string;
}
