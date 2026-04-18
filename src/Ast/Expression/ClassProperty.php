<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class ClassProperty extends Node
{
    public function __construct(
        SourceLocation $location,
        public Node $key,
        public ?Node $value,
        public bool $static,
        public bool $computed,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ClassProperty';
    }
}
