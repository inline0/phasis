<?php

declare(strict_types=1);

namespace PhpJs\Ast\Statement;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class ForStatement extends Node
{
    public function __construct(
        SourceLocation $location,
        public ?Node $init,
        public ?Node $test,
        public ?Node $update,
        public Node $body,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ForStatement';
    }
}
