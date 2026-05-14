<?php

declare(strict_types=1);

namespace Phasis\Ast\Expression;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

class ArrayExpression extends Node
{
    /**
     * @param (?Node)[] $elements
     */
    public function __construct(
        SourceLocation $location,
        public readonly array $elements,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ArrayExpression';
    }
}
