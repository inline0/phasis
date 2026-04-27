<?php

declare(strict_types=1);

namespace PhpJs\Ast\Statement;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

class ForStatement extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly ?Node $init,
        public readonly ?Node $test,
        public readonly ?Node $update,
        public readonly Node $body,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ForStatement';
    }
}
