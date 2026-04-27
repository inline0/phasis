<?php

declare(strict_types=1);

namespace PhpJs\Ast\Statement;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

class BlockStatement extends Node
{
    /**
     * Memoised by execBlockStatement: whether the body has top-level
     * lexical declarations (`let`, `const`, `using`, `await using`,
     * `class`) that hoistEvalLexicalDeclarations needs to TDZ-declare.
     * False-cache lets the hot loop body skip the second hoist pass.
     */
    public ?bool $hoistsLexicalCache = null;

    /**
     * Memoised by execBlockStatement: whether the body has anything
     * the var/function-decl branch of hoistDeclarations would touch
     * (var declarations, function declarations, class declarations,
     * or nested control flow that contains them).
     */
    public ?bool $hoistsVarOrFuncCache = null;

    /**
     * @param Node[] $body
     */
    public function __construct(
        SourceLocation $location,
        public readonly array $body,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'BlockStatement';
    }
}
