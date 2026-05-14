<?php

declare(strict_types=1);

namespace Phasis\Ast\Expression;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

class NewExpression extends Node
{
    /**
     * @param Node[] $arguments
     * @param bool $hasArguments true when a parenthesized Arguments list was
     *     present in source (`new C()`, vs. bare `new C`). Determines whether
     *     this is a MemberExpression (can begin an OptionalChain) or a
     *     plain NewExpression (cannot).
     */
    public function __construct(
        SourceLocation $location,
        public readonly Node $callee,
        public readonly array $arguments,
        public readonly bool $hasArguments = true,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'NewExpression';
    }
}
