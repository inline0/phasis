<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class TemplateLiteral extends Node
{
    /**
     * @param TemplateElement[] $quasis
     * @param Node[] $expressions
     */
    public function __construct(
        SourceLocation $location,
        public array $quasis,
        public array $expressions,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'TemplateLiteral';
    }
}
