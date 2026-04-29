<?php

declare(strict_types=1);

namespace PhpJs\Regex\Ast;

/** Concatenation of terms inside an alternative. */
class Sequence extends Node
{
    /** @param list<Node> $terms */
    public function __construct(public readonly array $terms)
    {
    }
}
