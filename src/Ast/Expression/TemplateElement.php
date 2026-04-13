<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class TemplateElement extends Node
{
    public function __construct(
        SourceLocation $location,
        public string $rawValue,
        public string $cookedValue,
        public bool $tail,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'TemplateElement';
    }
}
