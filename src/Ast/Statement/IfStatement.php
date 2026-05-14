<?php

declare(strict_types=1);

namespace Phasis\Ast\Statement;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

class IfStatement extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly Node $test,
        public readonly Node $consequent,
        public readonly ?Node $alternate,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'IfStatement';
    }
}
