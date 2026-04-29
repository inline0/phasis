<?php

declare(strict_types=1);

namespace PhpJs\Regex\Ast;

/**
 * `\p{Name}` / `\p{Name=Value}` / `\P{...}` Unicode property escape.
 *
 * Stored verbatim and resolved by the matcher at match time using
 * IntlChar lookups (no compile-time table generation). The
 * General_Category short forms (Lu, Ll, etc.) and binary
 * properties are accepted; full Script/Script_Extensions support
 * piggy-backs on IntlChar::getIntPropertyValue.
 */
class UnicodeProperty extends Node
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $value,
        public readonly bool $negated,
    ) {
    }
}
