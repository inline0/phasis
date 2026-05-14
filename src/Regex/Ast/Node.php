<?php

declare(strict_types=1);

namespace Phasis\Regex\Ast;

abstract class Node
{
    public function type(): string
    {
        $cls = static::class;
        $base = substr($cls, strrpos($cls, '\\') + 1);
        return $base;
    }
}
