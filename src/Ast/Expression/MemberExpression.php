<?php

declare(strict_types=1);

namespace Phasis\Ast\Expression;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

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
