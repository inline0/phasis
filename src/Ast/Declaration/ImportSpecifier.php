<?php

declare(strict_types=1);

namespace PhpJs\Ast\Declaration;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

/**
 * Represents a single specifier in an import declaration.
 *
 * type = 'default':    import foo from 'source'      (local = foo)
 * type = 'namespace':  import * as foo from 'source'  (local = foo)
 * type = 'named':      import { a as b } from 'source' (imported = a, local = b)
 */
readonly class ImportSpecifier extends Node
{
    public function __construct(
        SourceLocation $location,
        public string $specType,
        public string $local,
        public ?string $imported = null,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ImportSpecifier';
    }
}
