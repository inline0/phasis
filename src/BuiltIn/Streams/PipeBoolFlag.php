<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Streams;

/**
 * Mutable bool flag, used for pipeTo's done state. Defined at namespace
 * level so PHPStan's narrow-inferred-types pass treats the property as
 * `bool` rather than the literal-false initializer.
 */
final class PipeBoolFlag
{
    public function __construct(public bool $value = false)
    {
    }
}
