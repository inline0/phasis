<?php

declare(strict_types=1);

namespace Phasis\Ast\Expression;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

class Identifier extends Node
{
    /**
     * Lazy-resolved scope depth: the number of `parent` hops between
     * the env this identifier was first read in and the env that
     * owns the binding. -1 means "resolved via the global linked
     * object, do not cache fast-path access". null means "not yet
     * resolved" (first read fills it in if the resolution is
     * cacheable). The interpreter only consults the cache when no
     * with-environment is reachable from the current chain and the
     * program is statically free of direct eval — both checks gate
     * cache writes too.
     */
    public ?int $resolvedDepth = null;

    public function __construct(
        SourceLocation $location,
        public readonly string $name,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'Identifier';
    }
}
