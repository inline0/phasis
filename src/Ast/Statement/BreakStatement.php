<?php

declare(strict_types=1);

namespace PhpJs\Ast\Statement;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class BreakStatement extends Node
{
    public function __construct(
        SourceLocation $location,
        public ?string $label,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'BreakStatement';
    }
}
