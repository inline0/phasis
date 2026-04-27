<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

class ArrowFunction extends Node
{
    /**
     * @param Node[] $params
     */
    public function __construct(
        SourceLocation $location,
        public readonly array $params,
        public readonly Node $body,
        public readonly bool $expression,
        public readonly bool $async,
        public readonly ?string $sourceText = null,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ArrowFunction';
    }
}
