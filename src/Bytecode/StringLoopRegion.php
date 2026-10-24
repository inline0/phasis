<?php

declare(strict_types=1);

namespace Phasis\Bytecode;

/** Shared region code and exit PCs contain no activation, realm or mutable inline-cache state. */
final readonly class StringLoopRegion
{
    /** @param list<int> $exitPcs */
    public function __construct(public \Closure $execute, public array $exitPcs)
    {
    }
}
