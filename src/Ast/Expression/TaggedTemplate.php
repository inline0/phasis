<?php

declare(strict_types=1);

namespace Phasis\Ast\Expression;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

class TaggedTemplate extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly Node $tag,
        public readonly TemplateLiteral $quasi,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'TaggedTemplate';
    }
}
