<?php

declare(strict_types=1);

namespace Phasis\Regex\Ast;

/** ^ $ \b \B \G */
class Anchor extends Node
{
    public const START = 'start';
    public const END = 'end';
    public const WORD_BOUNDARY = 'wordBoundary';
    public const NON_WORD_BOUNDARY = 'nonWordBoundary';
    /** `\G`: matches only at the offset where the search began (Oniguruma scan anchor). */
    public const SCAN = 'scan';

    public function __construct(public readonly string $kind)
    {
    }
}
