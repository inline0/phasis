<?php

declare(strict_types=1);

namespace Phasis\Ast\Expression;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

class SpreadElement extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly Node $argument,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'SpreadElement';
    }
}
