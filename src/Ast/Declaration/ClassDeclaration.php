<?php

declare(strict_types=1);

namespace PhpJs\Ast\Declaration;

use PhpJs\Ast\Expression\Identifier;
use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

class ClassDeclaration extends Node
{
    /**
     * @param Node[] $body
     * @param Node[] $decorators
     */
    public function __construct(
        SourceLocation $location,
        public readonly ?Identifier $id,
        public readonly ?Node $superClass,
        public readonly array $body,
        public readonly ?string $sourceText = null,
        public readonly array $decorators = [],
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ClassDeclaration';
    }
}
