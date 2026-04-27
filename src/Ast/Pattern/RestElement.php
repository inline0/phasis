<?php

declare(strict_types=1);

namespace PhpJs\Ast\Pattern;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

class RestElement extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly Node $argument,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'RestElement';
    }
}
