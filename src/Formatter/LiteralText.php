<?php

declare(strict_types=1);

namespace Phasis\Formatter;

/**
 * String and number reprinting rules matching prettier: preferred quotes with
 * escape minimization, and normalized number literals.
 */
final class LiteralText
{
    /**
     * Reprints a string literal from its raw source text (including the
     * original enclosing quotes) using the preferred quote character.
     */
    public static function printString(string $raw, FormatOptions $options): string
    {
        $rawContent = substr($raw, 1, -1);

        $preferred = $options->singleQuote ? "'" : '"';
        $alternate = $preferred === "'" ? '"' : "'";

        $preferredCount = substr_count($rawContent, $preferred);
        $alternateCount = substr_count($rawContent, $alternate);
        $enclosing = $preferredCount > $alternateCount ? $alternate : $preferred;

        return self::makeString($rawContent, $enclosing);
    }

    /**
     * Prettier's makeString: re-escape raw string content for the chosen
     * enclosing quote, dropping unnecessary escapes and keeping meaningful
     * ones.
     */
    public static function makeString(string $rawContent, string $enclosingQuote): string
    {
        $otherQuote = $enclosingQuote === '"' ? "'" : '"';

        $newContent = preg_replace_callback(
            '/\\\\(.)|(["\'])/su',
            static function (array $match) use ($enclosingQuote, $otherQuote): string {
                $escaped = $match[1];
                $quote = $match[2] ?? '';

                if ($escaped === $otherQuote) {
                    return $escaped;
                }
                if ($quote === $enclosingQuote) {
                    return '\\' . $quote;
                }
                if ($quote !== '') {
                    return $quote;
                }
                return preg_match('/^[^\\n\\r"\'0-7\\\\bfnrt-vx\\x{2028}\\x{2029}]$/su', $escaped) === 1
                    ? $escaped
                    : '\\' . $escaped;
            },
            $rawContent,
        );

        return $enclosingQuote . ($newContent ?? $rawContent) . $enclosingQuote;
    }

    /** Prettier's printNumber: lowercase, trimmed exponents, no bare or trailing dots. */
    public static function printNumber(string $raw): string
    {
        $out = strtolower($raw);
        $out = (string) preg_replace('/^([+-]?[\d.]+)e[+]?0*(\d)/', '$1e$2', $out);
        $out = (string) preg_replace('/^([+-])?\./', '${1}0.', $out);
        $out = (string) preg_replace('/(\.\d+?)0+(?=e|$)/', '$1', $out);
        return (string) preg_replace('/\.(?=e|$)/', '', $out);
    }
}
