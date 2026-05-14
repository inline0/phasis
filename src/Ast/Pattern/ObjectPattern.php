<?php

declare(strict_types=1);

namespace Phasis\Ast\Pattern;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

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
