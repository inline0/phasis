<?php

declare(strict_types=1);

namespace PhpJs\Ast\Declaration;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

/**
 * A single specifier in an export declaration: export { local as exported }.
 */
readonly class ExportSpecifier extends Node
{
    public function __construct(
        SourceLocation $location,
        public string $local,
        public string $exported,
        public bool $localIsString = false,
        public bool $exportedIsString = false,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ExportSpecifier';
    }
}
