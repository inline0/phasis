<?php

declare(strict_types=1);

namespace Phasis\Ast\Jsx;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

/**
 * The opening tag of a JSX element.
 */
class JsxOpeningElement extends Node
{
    /**
     * @param Node[] $attributes JsxAttribute or JsxSpreadAttribute nodes
     */
    public function __construct(
        SourceLocation $location,
        public readonly Node $name,
        public readonly array $attributes,
        public readonly bool $selfClosing,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'JsxOpeningElement';
    }
}
