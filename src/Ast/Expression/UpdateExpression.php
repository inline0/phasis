<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

class UpdateExpression extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly string $operator,
        public readonly Node $argument,
        public readonly bool $prefix,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'UpdateExpression';
    }
}
