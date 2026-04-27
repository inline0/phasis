<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

class ConditionalExpression extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly Node $test,
        public readonly Node $consequent,
        public readonly Node $alternate,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ConditionalExpression';
    }
}
