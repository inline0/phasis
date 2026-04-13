<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class ObjectExpression extends Node
{
    /**
     * @param Node[] $properties
     */
    public function __construct(
        SourceLocation $location,
        public array $properties,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ObjectExpression';
    }
}
