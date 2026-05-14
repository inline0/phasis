<?php

declare(strict_types=1);

namespace Phasis\Ast\Expression;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

class Property extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly Node $key,
        public readonly Node $value,
        public readonly string $kind,
        public readonly bool $computed,
        public readonly bool $shorthand,
        public readonly bool $method,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'Property';
    }
}
