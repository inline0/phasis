<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class TaggedTemplate extends Node
{
    public function __construct(
        SourceLocation $location,
        public Node $tag,
        public TemplateLiteral $quasi,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'TaggedTemplate';
    }
}
