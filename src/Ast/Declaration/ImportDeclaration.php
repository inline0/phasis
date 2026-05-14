<?php

declare(strict_types=1);

namespace Phasis\Ast\Declaration;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

/**
 * import defaultExport from 'source';
 * import { export1, export2 } from 'source';
 * import * as name from 'source';
 * import 'source';
 */
class ImportDeclaration extends Node
{
    /**
     * @param ImportSpecifier[] $specifiers
     */
    public function __construct(
        SourceLocation $location,
        public readonly array $specifiers,
        public readonly string $source,
        public readonly ?Node $attributes = null,
        /**
         * Phase per the source-phase-imports / import-defer proposals.
         * "evaluation" is the normal binding; "source" requests the
         * SourceTextModule (always rejects with SyntaxError);
         * "defer" requests the deferred namespace exotic object.
         */
        public readonly string $phase = 'evaluation',
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ImportDeclaration';
    }
}
