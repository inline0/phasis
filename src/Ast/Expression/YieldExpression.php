<?php

declare(strict_types=1);

namespace Phasis\Ast\Expression;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

class YieldExpression extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly ?Node $argument,
        public readonly bool $delegate,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'YieldExpression';
    }
}
