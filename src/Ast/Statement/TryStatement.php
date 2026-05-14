<?php

declare(strict_types=1);

namespace Phasis\Ast\Statement;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

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
