<?php

declare(strict_types=1);

namespace PhpJs\Ast\Declaration;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class VariableDeclarator extends Node
{
    public function __construct(
        SourceLocation $location,
        public Node $id,
        public ?Node $init,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'VariableDeclarator';
    }
}
