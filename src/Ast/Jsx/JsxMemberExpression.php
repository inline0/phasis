<?php

declare(strict_types=1);

namespace Phasis\Ast\Jsx;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

/**
 * A dotted tag name such as `Foo.Bar`.
 */
class JsxMemberExpression extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly Node $object,
        public readonly JsxIdentifier $property,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'JsxMemberExpression';
    }
}
