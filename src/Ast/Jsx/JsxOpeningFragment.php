<?php

declare(strict_types=1);

namespace Phasis\Ast\Jsx;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

/**
 * The `<>` that opens a fragment.
 */
class JsxOpeningFragment extends Node
{
    public function __construct(
        SourceLocation $location,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'JsxOpeningFragment';
    }
}
