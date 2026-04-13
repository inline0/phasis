<?php

declare(strict_types=1);

namespace PhpJs\Ast\Statement;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class TryStatement extends Node
{
    public function __construct(
        SourceLocation $location,
        public BlockStatement $block,
        public ?CatchClause $handler,
        public ?BlockStatement $finalizer,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'TryStatement';
    }
}
