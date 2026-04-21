<?php

declare(strict_types=1);

namespace PhpJs\Value;

/**
 * Represents an object with the [[IsHTMLDDA]] internal slot.
 *
 * Per Annex B 3.7 of the spec, this is a callable object where:
 * - typeof returns "undefined"
 * - ToBoolean returns false
 * - Abstract equality with null and undefined returns true
 *
 * The only real-world example is document.all in browsers.
 * test262 uses $262.IsHTMLDDA to test this behavior.
 */
class JsHTMLDDA extends JsObject
{
    public function typeof(): string
    {
        return 'undefined';
    }

    public function toBoolean(): bool
    {
        return false;
    }

    public function toNumber(): float
    {
        return NAN;
    }

    public function toJsString(): string
    {
        return 'undefined';
    }

    public function display(): string
    {
        return 'undefined';
    }

    /**
     * Check if a value has the [[IsHTMLDDA]] internal slot.
     */
    public static function isHTMLDDA(JsValue $value): bool
    {
        return $value instanceof self;
    }
}
