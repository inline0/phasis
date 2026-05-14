<?php

declare(strict_types=1);

namespace Phasis\Ast\Expression;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

class Literal extends Node
{
    /**
     * Memoised JsValue for this literal. The interpreter's evalLiteral
     * populates this on first visit so a Literal node visited many times
     * in a hot loop (e.g. `2` inside `i * 2 - 1`) does not re-allocate
     * a fresh JsValue per visit. Values are read-only — the cached
     * JsValue is the same instance every consumer receives.
     */
    public ?\Phasis\Value\JsValue $cached = null;

    public function __construct(
        SourceLocation $location,
        public readonly mixed $value,
        public readonly string $raw,
        /** True when a string literal contained no escape sequences or line continuations. */
        public readonly bool $verbatim = true,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'Literal';
    }
}
