<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class ArrayExpression extends Node
{
    /**
     * @param (?Node)[] $elements
     */
    public function __construct(
        SourceLocation $location,
        public array $elements,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ArrayExpression';
    }
}
