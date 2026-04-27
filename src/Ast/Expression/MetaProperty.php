<?php

declare(strict_types=1);

namespace PhpJs\Ast\Expression;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

/**
 * import.meta or new.target
 *
 * MetaProperty covers both `import.meta` and `new.target`.
 * The meta and property fields identify which one.
 */
class MetaProperty extends Node
{
    public function __construct(
        SourceLocation $location,
        public readonly string $meta,
        public readonly string $property,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'MetaProperty';
    }
}
