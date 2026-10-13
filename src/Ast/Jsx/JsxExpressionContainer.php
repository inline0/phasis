<?php

declare(strict_types=1);

namespace Phasis\Ast\Jsx;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

/**
 * `{expression}` as a child or attribute value.
 */
class JsxExpressionContainer extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly Node $expression,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'JsxExpressionContainer';
    }
}
