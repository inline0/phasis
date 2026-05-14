<?php

declare(strict_types=1);

namespace Phasis\Ast\Expression;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

class TemplateLiteral extends Node
{
    /**
     * @param TemplateElement[] $quasis
     * @param Node[] $expressions
     */
    public function __construct(
        SourceLocation $location,
        public readonly array $quasis,
        public readonly array $expressions,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'TemplateLiteral';
    }
}
