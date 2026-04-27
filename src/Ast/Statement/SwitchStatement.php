<?php

declare(strict_types=1);

namespace PhpJs\Ast\Statement;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

class SwitchStatement extends Node
{
    /**
     * @param SwitchCase[] $cases
     */
    public function __construct(
        SourceLocation $location,
        public readonly Node $discriminant,
        public readonly array $cases,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'SwitchStatement';
    }
}
