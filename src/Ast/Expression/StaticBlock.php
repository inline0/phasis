<?php

declare(strict_types=1);

namespace Phasis\Ast\Expression;

use Phasis\Ast\Node;
use Phasis\Ast\Statement\BlockStatement;
use Phasis\Lexer\SourceLocation;

class StaticBlock extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly BlockStatement $body,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'StaticBlock';
    }
}
