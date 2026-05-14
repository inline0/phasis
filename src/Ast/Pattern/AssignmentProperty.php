<?php

declare(strict_types=1);

namespace Phasis\Ast\Pattern;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

class AssignmentProperty extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly Node $key,
        public readonly Node $value,
        public readonly bool $computed,
        public readonly bool $shorthand,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'AssignmentProperty';
    }
}
