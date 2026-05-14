<?php

declare(strict_types=1);

namespace Phasis\Ast\Declaration;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

/**
 * A single specifier in an export declaration: export { local as exported }.
 */
class ExportSpecifier extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly string $local,
        public readonly string $exported,
        public readonly bool $localIsString = false,
        public readonly bool $exportedIsString = false,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ExportSpecifier';
    }
}
