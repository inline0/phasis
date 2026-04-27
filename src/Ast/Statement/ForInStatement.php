<?php

declare(strict_types=1);

namespace PhpJs\Ast\Statement;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

class ForInStatement extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly Node $left,
        public readonly Node $right,
        public readonly Node $body,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ForInStatement';
    }
}
