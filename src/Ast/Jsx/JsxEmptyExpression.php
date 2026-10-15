<?php

declare(strict_types=1);

namespace Phasis\Ast\Jsx;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

/**
 * The empty expression in `{}`, or in a container holding only a comment.
 */
class JsxEmptyExpression extends Node
{
    public function __construct(
        SourceLocation $location,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'JsxEmptyExpression';
    }
}
