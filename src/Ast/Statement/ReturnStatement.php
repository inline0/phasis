<?php

declare(strict_types=1);

namespace PhpJs\Ast\Statement;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class ReturnStatement extends Node
{
    public function __construct(
        SourceLocation $location,
        public ?Node $argument,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ReturnStatement';
    }
}
