<?php

declare(strict_types=1);

namespace Phasis\Ast\Expression;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

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
