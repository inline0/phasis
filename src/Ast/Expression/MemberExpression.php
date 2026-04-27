<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

class MemberExpression extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly Node $object,
        public readonly Node $property,
        public readonly bool $computed,
        public readonly bool $optional,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'MemberExpression';
    }
}
