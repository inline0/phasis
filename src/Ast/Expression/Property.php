<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class Property extends Node
{
    public function __construct(
        SourceLocation $location,
        public Node $key,
        public Node $value,
        public string $kind,
        public bool $computed,
        public bool $shorthand,
        public bool $method,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'Property';
    }
}
