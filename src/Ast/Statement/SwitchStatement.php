<?php

declare(strict_types=1);

namespace Phasis\Ast\Statement;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

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
