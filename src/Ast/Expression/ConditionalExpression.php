<?php

declare(strict_types=1);

namespace Phasis\Ast\Expression;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

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
