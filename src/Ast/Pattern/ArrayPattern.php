<?php

declare(strict_types=1);

namespace Phasis\Ast\Pattern;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

class ArrayPattern extends Node
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
        return 'ArrayPattern';
    }
}
