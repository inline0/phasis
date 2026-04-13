<?php

declare(strict_types=1);

namespace PhpJs\Ast\Statement;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class CatchClause extends Node
{
    public function __construct(
        SourceLocation $location,
        public ?Node $param,
        public BlockStatement $body,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'CatchClause';
    }
}
