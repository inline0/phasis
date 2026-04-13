<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class Identifier extends Node
{
    public function __construct(
        SourceLocation $location,
        public string $name,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'Identifier';
    }
}
