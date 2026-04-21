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
readonly class MetaProperty extends Node
{
    public function __construct(
        SourceLocation $location,
        public string $meta,
        public string $property,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'MetaProperty';
    }
}
