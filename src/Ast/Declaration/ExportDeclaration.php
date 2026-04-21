<?php

declare(strict_types=1);

namespace PhpJs\Ast\Declaration;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

/**
 * export default expr;
 * export { a, b as c };
 * export var x = 1;
 * export function foo() {}
 * export class Bar {}
 * export { a } from 'source';
 * export * from 'source';
 * export * as ns from 'source';
 */
readonly class ExportDeclaration extends Node
{
    /**
     * @param ExportSpecifier[] $specifiers
     */
    public function __construct(
        SourceLocation $location,
        public ?Node $declaration = null,
        public array $specifiers = [],
        public ?string $source = null,
        public bool $isDefault = false,
        public bool $isAll = false,
        public ?string $allAs = null,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ExportDeclaration';
    }
}
