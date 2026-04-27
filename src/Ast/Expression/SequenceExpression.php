<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

class SequenceExpression extends Node
{
    /**
     * @param Node[] $expressions
     */
    public function __construct(
        SourceLocation $location,
        public readonly array $expressions,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'SequenceExpression';
    }
}
