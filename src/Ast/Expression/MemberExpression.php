<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class MemberExpression extends Node
{
    public function __construct(
        SourceLocation $location,
        public Node $object,
        public Node $property,
        public bool $computed,
        public bool $optional,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'MemberExpression';
    }
}
