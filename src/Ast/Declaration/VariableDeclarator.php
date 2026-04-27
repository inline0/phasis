<?php

declare(strict_types=1);

namespace PhpJs\Ast\Declaration;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

class VariableDeclarator extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly Node $id,
        public readonly ?Node $init,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'VariableDeclarator';
    }
}
