<?php

declare(strict_types=1);

namespace Phasis\Ast\Expression;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

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
