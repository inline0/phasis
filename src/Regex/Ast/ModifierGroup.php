<?php

declare(strict_types=1);

namespace PhpJs\Regex\Ast;

/**
 * (?ims:Disjunction) / (?ims-:Disjunction) — inline-modifier group.
 *
 * Adds and/or removes the i, m, s flags for the duration of the
 * matched body. The matcher saves the current flag state, applies
 * the override, runs the body, then restores.
 */
class ModifierGroup extends Node
{
    public function __construct(
        public readonly Node $body,
        public readonly bool $addI,
        public readonly bool $addM,
        public readonly bool $addS,
        public readonly bool $removeI,
        public readonly bool $removeM,
        public readonly bool $removeS,
    ) {
    }
}
