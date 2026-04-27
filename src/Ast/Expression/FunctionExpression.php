<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

class FunctionExpression extends Node
{
    /**
     * @param Node[] $params
     */
    public function __construct(
        SourceLocation $location,
        public readonly ?string $name,
        public readonly array $params,
        public readonly Node $body,
        public readonly bool $generator,
        public readonly bool $async,
        public readonly ?string $sourceText = null,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'FunctionExpression';
    }
}
