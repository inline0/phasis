<?php

declare(strict_types=1);

namespace Phasis\Ast\Statement;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

class DebuggerStatement extends Node
{
    public function __construct(
        SourceLocation $location,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'DebuggerStatement';
    }
}
