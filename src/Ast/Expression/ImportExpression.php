<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

/**
 * import(source) or import(source, options)
 *
 * Per spec, ImportCall is not a regular function call. It is a syntactic
 * form that takes one required AssignmentExpression and an optional second
 * AssignmentExpression (import attributes / options).
 */
class ImportExpression extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly Node $source,
        public readonly ?Node $options = null,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ImportExpression';
    }
}
