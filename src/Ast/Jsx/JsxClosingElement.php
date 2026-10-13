<?php

declare(strict_types=1);

namespace Phasis\Ast\Jsx;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

/**
 * The closing tag of a JSX element.
 */
class JsxClosingElement extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly Node $name,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'JsxClosingElement';
    }
}
