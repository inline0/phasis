<?php

declare(strict_types=1);

namespace Phasis\Ast\Statement;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

class DoWhileStatement extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly Node $body,
        public readonly Node $test,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'DoWhileStatement';
    }
}
