<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class SequenceExpression extends Node
{
    /**
     * @param Node[] $expressions
     */
    public function __construct(
        SourceLocation $location,
        public array $expressions,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'SequenceExpression';
    }
}
