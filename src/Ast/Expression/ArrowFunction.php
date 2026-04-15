<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class ArrowFunction extends Node
{
    /**
     * @param Node[] $params
     */
    public function __construct(
        SourceLocation $location,
        public array $params,
        public Node $body,
        public bool $expression,
        public bool $async,
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
        return 'ArrowFunction';
    }
}
