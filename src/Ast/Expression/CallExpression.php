<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class CallExpression extends Node
{
    /**
     * @param Node[] $arguments
     */
    public function __construct(
        SourceLocation $location,
        public Node $callee,
        public array $arguments,
        public bool $optional = false,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'CallExpression';
    }
}
