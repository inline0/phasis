<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class SpreadElement extends Node
{
    public function __construct(
        SourceLocation $location,
        public Node $argument,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'SpreadElement';
    }
}
