<?php

declare(strict_types=1);

namespace PhpJs\Ast\Declaration;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

/**
 * import defaultExport from 'source';
 * import { export1, export2 } from 'source';
 * import * as name from 'source';
 * import 'source';
 */
readonly class ImportDeclaration extends Node
{
    /**
     * @param ImportSpecifier[] $specifiers
     */
    public function __construct(
        SourceLocation $location,
        public array $specifiers,
        public string $source,
        public ?Node $attributes = null,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ImportDeclaration';
    }
}
