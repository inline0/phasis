<?php

declare(strict_types=1);

namespace PhpJs\Ast\Statement;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class IfStatement extends Node
{
    public function __construct(
        SourceLocation $location,
        public Node $test,
        public Node $consequent,
        public ?Node $alternate,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'IfStatement';
    }
}
