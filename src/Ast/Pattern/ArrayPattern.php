<?php

declare(strict_types=1);

namespace PhpJs\Ast\Pattern;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class ArrayPattern extends Node
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
        return 'ArrayPattern';
    }
}
