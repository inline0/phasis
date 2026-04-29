<?php

declare(strict_types=1);

namespace PhpJs\Regex\Ast;

/**
 * A single code point literal. Stored as the integer code point so the
 * matcher can compare against UTF-16 code units (or code points in /u
 * mode) without re-decoding.
 */
class Literal extends Node
{
    public function __construct(public readonly int $codePoint)
    {
    }
}
