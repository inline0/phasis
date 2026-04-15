<?php

declare(strict_types=1);

namespace PhpJs\Ast\Declaration;

use PhpJs\Ast\Expression\Identifier;
use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class FunctionDeclaration extends Node
{
    /**
     * @param Node[] $params
     */
    public function __construct(
        SourceLocation $location,
        public Identifier $id,
        public array $params,
        public Node $body,
        public bool $generator,
        public bool $async,
        public bool $expression = false,
        private ?string $sourceText = null,
    ) {
        parent::__construct($location);
    }

    public function getSourceText(): ?string
    {
        return $this->sourceText;
    }

    public function type(): string
    {
        return 'FunctionDeclaration';
    }
}
