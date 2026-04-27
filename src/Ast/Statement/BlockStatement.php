<?php

declare(strict_types=1);

namespace PhpJs\Ast\Statement;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

class BlockStatement extends Node
{
    /**
     * @param Node[] $body
     */
    public function __construct(
        SourceLocation $location,
        public readonly array $body,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'BlockStatement';
    }
}
