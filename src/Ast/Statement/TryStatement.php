<?php

declare(strict_types=1);

namespace PhpJs\Ast\Statement;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

class TryStatement extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly BlockStatement $block,
        public readonly ?CatchClause $handler,
        public readonly ?BlockStatement $finalizer,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'TryStatement';
    }
}
