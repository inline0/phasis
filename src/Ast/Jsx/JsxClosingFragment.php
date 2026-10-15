<?php

declare(strict_types=1);

namespace Phasis\Ast\Jsx;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

/**
 * The `</>` that closes a fragment.
 */
class JsxClosingFragment extends Node
{
    public function __construct(
        SourceLocation $location,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'JsxClosingFragment';
    }
}
