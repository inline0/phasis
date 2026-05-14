<?php

declare(strict_types=1);

namespace Phasis\Ast\Declaration;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

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
