<?php

declare(strict_types=1);

namespace Phasis\Ast\Pattern;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

class AssignmentPattern extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly Node $left,
        public readonly Node $right,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'AssignmentPattern';
    }
}
