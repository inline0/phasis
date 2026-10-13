<?php

declare(strict_types=1);

namespace Phasis\Ast\Jsx;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

/**
 * `{...children}` as a child.
 */
class JsxSpreadChild extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly Node $expression,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'JsxSpreadChild';
    }
}
