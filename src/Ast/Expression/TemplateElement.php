<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

class TemplateElement extends Node
{
    /**
     * @param string $rawValue The raw template text (escape sequences preserved).
     * @param string|null $cookedValue The cooked value, or null when the element
     *                                 contains an invalid escape sequence (ES2018+
     *                                 tagged template literal revision).
     */
    public function __construct(
        SourceLocation $location,
        public readonly string $rawValue,
        public readonly ?string $cookedValue,
        public readonly bool $tail,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'TemplateElement';
    }
}
