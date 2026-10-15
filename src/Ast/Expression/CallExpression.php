<?php

declare(strict_types=1);

namespace Phasis\Ast\Expression;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

class CallExpression extends Node
{
    /**
     * Set by the parser when the source wraps this node in parentheses.
     * Parentheses end an optional chain: in `(a?.b).c` a short-circuit
     * of `a?.b` does not skip `.c`, which then reads from undefined.
     */
    public bool $parenthesized = false;

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
