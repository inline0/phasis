<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class NewExpression extends Node
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
        public Node $callee,
        public array $arguments,
        public bool $hasArguments = true,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'NewExpression';
    }
}
