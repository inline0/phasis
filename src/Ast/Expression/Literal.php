<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class Literal extends Node
{
    public function __construct(
        SourceLocation $location,
        public mixed $value,
        public string $raw,
        /** True when a string literal contained no escape sequences or line continuations. */
        public bool $verbatim = true,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'Literal';
    }
}
