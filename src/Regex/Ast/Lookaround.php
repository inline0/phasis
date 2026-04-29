<?php

declare(strict_types=1);

namespace PhpJs\Regex\Ast;

/** (?=...) (?!...) (?<=...) (?<!...) */
class Lookaround extends Node
{
    public function __construct(
        public readonly Node $body,
        public readonly bool $behind,    // true = lookbehind, false = lookahead
        public readonly bool $negative,  // true = negative
    ) {
    }
}
