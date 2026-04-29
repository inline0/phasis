<?php

declare(strict_types=1);

namespace PhpJs\Regex\Ast;

/** ^ $ \b \B */
class Anchor extends Node
{
    public const START = 'start';
    public const END = 'end';
    public const WORD_BOUNDARY = 'wordBoundary';
    public const NON_WORD_BOUNDARY = 'nonWordBoundary';

    public function __construct(public readonly string $kind)
    {
    }
}
