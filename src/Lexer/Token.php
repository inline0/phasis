<?php

declare(strict_types=1);

namespace PhpJs\Lexer;

readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $value,
        public SourceLocation $location,
        public bool $lineTerminatorBefore = false,
        public ?string $rawValue = null,
        /** True when a template element contains an invalid escape sequence (ES2018+). */
        public bool $cookedInvalid = false,
    ) {
    }
}
