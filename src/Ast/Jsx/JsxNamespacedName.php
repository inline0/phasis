<?php

declare(strict_types=1);

namespace Phasis\Ast\Jsx;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

/**
 * A namespaced name such as `svg:rect` or `xlink:href`.
 */
class JsxNamespacedName extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly JsxIdentifier $namespace,
        public readonly JsxIdentifier $name,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'JsxNamespacedName';
    }
}
