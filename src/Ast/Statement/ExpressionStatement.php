<?php

declare(strict_types=1);

namespace Phasis\Ast\Statement;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

class ExpressionStatement extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly Node $expression,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ExpressionStatement';
    }
}
