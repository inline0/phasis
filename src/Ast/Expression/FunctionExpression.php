<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class FunctionExpression extends Node
{
    /**
     * @param Node[] $params
     */
    public function __construct(
        SourceLocation $location,
        public ?string $name,
        public array $params,
        public Node $body,
        public bool $generator,
        public bool $async,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'FunctionExpression';
    }
}
