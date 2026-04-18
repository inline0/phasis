<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Ast\Statement\BlockStatement;
use PhpJs\Lexer\SourceLocation;

readonly class StaticBlock extends Node
{
    public function __construct(
        SourceLocation $location,
        public BlockStatement $body,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'StaticBlock';
    }
}
