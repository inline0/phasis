<?php

declare(strict_types=1);

namespace PhpJs\Ast\Statement;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

class ContinueStatement extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly ?string $label,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ContinueStatement';
    }
}
