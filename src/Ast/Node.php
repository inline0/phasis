<?php

declare(strict_types=1);

namespace Phasis\Ast;

use Phasis\Lexer\SourceLocation;

abstract class Node
{
    public function __construct(
        public readonly SourceLocation $location,
    ) {
    }

    abstract public function type(): string;
}
