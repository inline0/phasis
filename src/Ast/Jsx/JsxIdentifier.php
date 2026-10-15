<?php

declare(strict_types=1);

namespace Phasis\Ast\Jsx;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

/**
 * A JSX name. Unlike JS identifiers it may contain `-` and keywords.
 */
class JsxIdentifier extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly string $name,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'JsxIdentifier';
    }
}
