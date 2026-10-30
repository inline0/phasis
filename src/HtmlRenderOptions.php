<?php

declare(strict_types=1);

namespace Phasis;

/** Opt-in spelling choices for Engine's non-hydratable HTML profile. */
final class HtmlRenderOptions
{
    /**
     * @param list<string> $quoteEmptyAttributes Attribute names whose empty string values must be written as `=""`.
     * @param bool $escapeNoscriptText Serialize text below noscript with HTML text escaping (`&`, `<`, `>`), not attribute escaping.
     * @param bool $reactScriptText Serialize a script's single string child as React does, escaping embedded script tags.
     * @param bool $literalTextQuotes Leave double quotes literal in ordinary text.
     * @param bool $escapeAttributeGreaterThan Escape `>` in attribute values.
     * @param bool $reactStyleText Serialize a style's single string child as React does, escaping embedded style tags.
     * @param list<string> $namedBooleanAttributes True boolean attributes written as `name="name"` instead of bare names.
     */
    public function __construct(
        public readonly array $quoteEmptyAttributes = [],
        public readonly bool $escapeNoscriptText = false,
        public readonly bool $reactScriptText = false,
        public readonly bool $literalTextQuotes = false,
        public readonly bool $escapeAttributeGreaterThan = false,
        public readonly bool $reactStyleText = false,
        public readonly array $namedBooleanAttributes = [],
    ) {
        foreach ($quoteEmptyAttributes as $name) {
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_:.-]*$/D', $name) !== 1) {
                throw new \InvalidArgumentException('Invalid HTML attribute name in quoteEmptyAttributes.');
            }
        }
        foreach ($namedBooleanAttributes as $name) {
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_:.-]*$/D', $name) !== 1) {
                throw new \InvalidArgumentException('Invalid HTML attribute name in namedBooleanAttributes.');
            }
        }
    }
}
