<?php

declare(strict_types=1);

namespace Phasis\Ast\Expression;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

class PrivateIdentifier extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly string $name,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'PrivateIdentifier';
    }
}
