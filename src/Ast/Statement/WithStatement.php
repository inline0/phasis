<?php

declare(strict_types=1);

namespace PhpJs\Ast\Statement;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class WithStatement extends Node
{
    public function __construct(
        SourceLocation $location,
        public Node $object,
        public Node $body,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'WithStatement';
    }
}
