<?php

declare(strict_types=1);

namespace Phasis\Ast\Jsx;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

/**
 * `{...props}` inside a tag.
 */
class JsxSpreadAttribute extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly Node $argument,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'JsxSpreadAttribute';
    }
}
