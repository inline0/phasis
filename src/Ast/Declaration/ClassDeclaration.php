<?php

declare(strict_types=1);

namespace PhpJs\Ast\Declaration;

use PhpJs\Ast\Expression\Identifier;
use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class ClassDeclaration extends Node
{
    /**
     * @param Node[] $body
     */
    public function __construct(
        SourceLocation $location,
        public ?Identifier $id,
        public ?Node $superClass,
        public array $body,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ClassDeclaration';
    }
}
