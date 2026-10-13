<?php

declare(strict_types=1);

namespace Phasis\Ast\Jsx;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

/**
 * Text between tags. `value` has HTML entities decoded; `raw` is the source text.
 */
class JsxText extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly string $value,
        public readonly string $raw,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'JsxText';
    }
}
