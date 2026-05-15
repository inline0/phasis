<?php

declare(strict_types=1);

namespace Phasis\Runtime;

/**
 * One module in `LazyBuiltinRegistry`: the names it installs, the
 * other modules it depends on, the install factory, and a one-shot
 * realized flag.
 */
final class LazyBuiltinEntry
{
    /** Flips to true the first time `realize()` runs the factory. */
    public bool $realized = false;

    /**
     * @param list<string>    $names
     * @param list<string>    $deps
     * @param callable():void $factory
     */
    public function __construct(
        public readonly array $names,
        public readonly array $deps,
        public mixed $factory,
    ) {
    }
}
