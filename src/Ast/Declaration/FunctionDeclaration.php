<?php

declare(strict_types=1);

namespace PhpJs\Ast\Declaration;

use PhpJs\Ast\Expression\Identifier;
use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

class FunctionDeclaration extends Node
{
    /**
     * @param Node[] $params
     */
    public function __construct(
        SourceLocation $location,
        public readonly ?Identifier $id,
        public readonly array $params,
        public readonly Node $body,
        public readonly bool $generator,
        public readonly bool $async,
        public readonly bool $expression = false,
        public readonly ?string $sourceText = null,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'FunctionDeclaration';
    }
}
