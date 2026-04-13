<?php

declare(strict_types=1);

namespace PhpJs\Ast\Pattern;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class AssignmentPattern extends Node
{
    public function __construct(
        SourceLocation $location,
        public Node $left,
        public Node $right,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'AssignmentPattern';
    }
}
