<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class AssignmentExpression extends Node
{
    public function __construct(
        SourceLocation $location,
        public string $operator,
        public Node $left,
        public Node $right,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'AssignmentExpression';
    }
}
