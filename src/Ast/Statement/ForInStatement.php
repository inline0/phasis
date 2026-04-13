<?php

declare(strict_types=1);

namespace PhpJs\Ast\Statement;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class ForInStatement extends Node
{
    public function __construct(
        SourceLocation $location,
        public Node $left,
        public Node $right,
        public Node $body,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ForInStatement';
    }
}
