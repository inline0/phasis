<?php

declare(strict_types=1);

namespace PhpJs\Ast;

use PhpJs\Lexer\SourceLocation;

abstract class Node
{
    public function __construct(
        public readonly SourceLocation $location,
    ) {
    }

    abstract public function type(): string;
}
