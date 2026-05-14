<?php

declare(strict_types=1);

namespace Phasis\Ast\Pattern;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

class RestElement extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly Node $argument,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'RestElement';
    }
}
