<?php

declare(strict_types=1);

namespace Phasis\Ast\Expression;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

class CallExpression extends Node
{
    /**
     * @param Node[] $arguments
     */
    public function __construct(
        SourceLocation $location,
        public readonly Node $callee,
        public readonly array $arguments,
        public readonly bool $optional = false,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'CallExpression';
    }
}
