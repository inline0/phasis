<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

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
