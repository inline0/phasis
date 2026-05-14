<?php

declare(strict_types=1);

namespace Phasis\Ast\Declaration;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

class VariableDeclaration extends Node
{
    /**
     * @param VariableDeclarator[] $declarations
     */
    public function __construct(
        SourceLocation $location,
        public readonly string $kind,
        public readonly array $declarations,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'VariableDeclaration';
    }
}
