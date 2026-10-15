<?php

declare(strict_types=1);

namespace Phasis\Ast\Jsx;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

/**
 * A JSX fragment: `<>children</>`.
 */
class JsxFragment extends Node
{
    /**
     * @param Node[] $children
     */
    public function __construct(
        SourceLocation $location,
        public readonly JsxOpeningFragment $openingFragment,
        public readonly JsxClosingFragment $closingFragment,
        public readonly array $children,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'JsxFragment';
    }
}
