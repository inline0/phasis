<?php

declare(strict_types=1);

namespace Phasis\Ast\Declaration;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

/**
 * Represents a single specifier in an import declaration.
 *
 * type = 'default':    import foo from 'source'      (local = foo)
 * type = 'namespace':  import * as foo from 'source'  (local = foo)
 * type = 'named':      import { a as b } from 'source' (imported = a, local = b)
 */
class ImportSpecifier extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly string $specType,
        public readonly string $local,
        public readonly ?string $imported = null,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ImportSpecifier';
    }
}
