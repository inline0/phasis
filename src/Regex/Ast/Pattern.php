<?php

declare(strict_types=1);

namespace PhpJs\Regex\Ast;

/** Top-level pattern node. */
class Pattern extends Node
{
    /**
     * @param list<string> $groupNames Distinct named groups in source order.
     * @param array<mixed> $indexToName
     */
    public function __construct(
        public readonly Node $body,
        public readonly int $groupCount,
        public readonly array $groupNames,
        /** Map of group index → name (1-based). @var array<int, string> */
        public readonly array $indexToName,
    ) {
    }
}
