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
class ExportDeclaration extends Node
{
    /**
     * @param ExportSpecifier[] $specifiers
     */
    public function __construct(
        SourceLocation $location,
        public readonly ?Node $declaration = null,
        public readonly array $specifiers = [],
        public readonly ?string $source = null,
        public readonly bool $isDefault = false,
        public readonly bool $isAll = false,
        public readonly ?string $allAs = null,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ExportDeclaration';
    }
}
