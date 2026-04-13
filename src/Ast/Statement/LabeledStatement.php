<?php

declare(strict_types=1);

namespace PhpJs\Ast\Statement;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class LabeledStatement extends Node
{
    public function __construct(
        SourceLocation $location,
        public string $label,
        public Node $body,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'LabeledStatement';
    }
}
