<?php

declare(strict_types=1);

namespace PhpJs\Ast\Pattern;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class AssignmentProperty extends Node
{
    public function __construct(
        SourceLocation $location,
        public Node $key,
        public Node $value,
        public bool $computed,
        public bool $shorthand,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'AssignmentProperty';
    }
}
