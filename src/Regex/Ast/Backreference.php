<?php

declare(strict_types=1);

namespace PhpJs\Regex\Ast;

/** \1, \2, \k<name> */
class Backreference extends Node
{
    public function __construct(
        public readonly ?int $index = null,
        public readonly ?string $name = null,
    ) {
    }
}
