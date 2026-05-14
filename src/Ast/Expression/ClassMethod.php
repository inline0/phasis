<?php

declare(strict_types=1);

namespace Phasis\Ast\Expression;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

class ClassMethod extends Node
{
    /**
     * @param Node[] $decorators
     */
    public function __construct(
        SourceLocation $location,
        public readonly Node $key,
        public readonly FunctionExpression $value,
        public readonly string $kind,
        public readonly bool $static,
        public readonly bool $computed,
        public readonly array $decorators = [],
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ClassMethod';
    }
}
