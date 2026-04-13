<?php

declare(strict_types=1);

namespace PhpJs\Ast\Statement;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class SwitchStatement extends Node
{
    /**
     * @param SwitchCase[] $cases
     */
    public function __construct(
        SourceLocation $location,
        public Node $discriminant,
        public array $cases,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'SwitchStatement';
    }
}
