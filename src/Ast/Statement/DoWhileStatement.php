<?php

declare(strict_types=1);

namespace PhpJs\Ast\Statement;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class DoWhileStatement extends Node
{
    public function __construct(
        SourceLocation $location,
        public Node $body,
        public Node $test,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'DoWhileStatement';
    }
}
