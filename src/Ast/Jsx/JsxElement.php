<?php

declare(strict_types=1);

namespace Phasis\Ast\Jsx;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

/**
 * A JSX element: `<a b="c">children</a>` or `<a />`.
 */
class JsxElement extends Node
{
    /**
     * @param Node[] $children
     */
    public function __construct(
        SourceLocation $location,
        public readonly JsxOpeningElement $openingElement,
        public readonly ?JsxClosingElement $closingElement,
        public readonly array $children,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'JsxElement';
    }
}
