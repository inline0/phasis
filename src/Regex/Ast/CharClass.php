<?php

declare(strict_types=1);

namespace PhpJs\Regex\Ast;

/**
 * `[abc]` / `[^abc]` / `[a-z]` / `\d` etc.
 *
 * The class is represented as a list of [from, to] inclusive integer
 * code-point ranges. The matcher accepts a code unit / code point
 * if it falls within any range and `negated` is false (or none of
 * them and `negated` is true).
 */
class CharClass extends Node
{
    /**
     * @param list<array{0:int,1:int}> $ranges
     * @param list<UnicodeProperty> $properties Additional /v-flag
     *        members like `\p{ASCII_Hex_Digit}` that live inside the
     *        class. Membership tests OR these with `$ranges`.
     */
    public function __construct(
        public readonly array $ranges,
        public readonly bool $negated = false,
        public readonly array $properties = [],
    ) {
    }

    /** Match `.` outside dotAll mode: any char except line terminators. */
    public static function dotNoDotAll(): self
    {
        // ECMAScript LineTerminator: \n \r    .
        return new self([
            [0x0A, 0x0A],
            [0x0D, 0x0D],
            [0x2028, 0x2028],
            [0x2029, 0x2029],
        ], negated: true);
    }

    /** Match any character (dotAll mode). */
    public static function any(): self
    {
        return new self([[0, 0x10FFFF]], negated: false);
    }

    /** \d */
    public static function digit(bool $negated = false): self
    {
        return new self([[0x30, 0x39]], $negated);
    }

    /** \w */
    public static function word(bool $negated = false): self
    {
        return new self([
            [0x30, 0x39],   // 0-9
            [0x41, 0x5A],   // A-Z
            [0x5F, 0x5F],   // _
            [0x61, 0x7A],   // a-z
        ], $negated);
    }

    /** \s — whitespace per spec (covers ASCII WS + Unicode WS + BOM). */
    public static function whitespace(bool $negated = false): self
    {
        return new self([
            [0x09, 0x0D],   // \t \n \v \f \r
            [0x20, 0x20],   // space
            [0xA0, 0xA0],   // NBSP
            [0x1680, 0x1680],
            [0x2000, 0x200A],
            [0x2028, 0x2029],
            [0x202F, 0x202F],
            [0x205F, 0x205F],
            [0x3000, 0x3000],
            [0xFEFF, 0xFEFF],
        ], $negated);
    }
}
