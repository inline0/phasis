<?php

declare(strict_types=1);

namespace Phasis\Ast\Expression;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

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
        /**
         * Phase per the source-phase-imports / import-defer proposals.
         * "evaluation" (the default) loads and evaluates the module.
         * "source" requests the module's SourceTextModule, which always
         * rejects with SyntaxError per spec.
         * "defer" defers evaluation; not implemented as a distinct phase
         * — runtime rejects with SyntaxError to match the SourceTextModule
         * abrupt-completion shape.
         */
        public readonly string $phase = 'evaluation',
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ImportExpression';
    }
}
