<?php

declare(strict_types=1);

namespace PhpJs\Ast\Pattern;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

class ObjectPattern extends Node
{
    /**
     * @param Node[] $properties
     */
    public function __construct(
        SourceLocation $location,
        public readonly array $properties,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ObjectPattern';
    }
}
