<?php

declare(strict_types=1);

namespace Phasis\Ast\Statement;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

class WithStatement extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly Node $object,
        public readonly Node $body,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'WithStatement';
    }
}
