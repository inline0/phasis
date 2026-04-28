<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

class ClassExpression extends Node
{
    /**
     * @param Node[] $body Class body elements (ClassMethod, ClassProperty, StaticBlock).
     * @param Node[] $decorators
     */
    public function __construct(
        SourceLocation $location,
        public readonly ?Identifier $id,
        public readonly ?Node $superClass,
        public readonly array $body,
        public readonly ?string $sourceText = null,
        public readonly array $decorators = [],
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ClassExpression';
    }
}
