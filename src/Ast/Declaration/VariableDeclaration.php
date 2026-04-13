<?php

declare(strict_types=1);

namespace PhpJs\Ast\Declaration;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class VariableDeclaration extends Node
{
    /**
     * @param VariableDeclarator[] $declarations
     */
    public function __construct(
        SourceLocation $location,
        public string $kind,
        public array $declarations,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'VariableDeclaration';
    }
}
