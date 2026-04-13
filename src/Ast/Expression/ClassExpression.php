<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class ClassExpression extends Node
{
    /**
     * @param ClassMethod[] $body
     */
    public function __construct(
        SourceLocation $location,
        public ?Identifier $id,
        public ?Node $superClass,
        public array $body,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ClassExpression';
    }
}
