<?php

declare(strict_types=1);

namespace Phasis\Regex\Ast;

/** A quantified atom: x*, x+, x?, x{n}, x{n,m}. */
class Quantified extends Node
{
    public function __construct(
        public readonly Node $atom,
        public readonly int $min,
        public readonly ?int $max,   // null => unbounded
        public readonly bool $greedy = true,
    ) {
    }
}
