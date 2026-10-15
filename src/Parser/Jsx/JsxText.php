<?php

declare(strict_types=1);

namespace Phasis\Parser\Jsx;

use Phasis\Value\JsString;

/**
 * Text handling for JSX, matching esbuild byte for byte: HTML entity
 * decoding (the XHTML entity set plus numeric references) and the
 * whitespace rule for text between tags.
 */
final class JsxText
{
    /** @var array<string, string>|null */
    private static ?array $entities = null;

    /**
     * Clean text between tags the way esbuild does. Whitespace is trimmed on
     * the raw text, line by line, before entities are decoded: lines that
     * are only whitespace disappear, leading whitespace goes from every line
     * but the first, trailing whitespace from every line but the last, and
     * the remaining lines are joined with one space. Returns null when
     * nothing is left, meaning the text produces no child.
     */
    public static function cleanChild(string $raw): ?string
    {
        $out = '';
        $hasOutput = false;
        $first = 0;
        $afterLast = -1;
        $len = strlen($raw);
        $i = 0;
        while ($i < $len) {
            $c = $raw[$i];
            $width = 1;
            $isNewline = false;
            $isSpace = false;
            if ($c === "\n" || $c === "\r") {
                $isNewline = true;
            } elseif ($c === ' ' || $c === "\t") {
                $isSpace = true;
            } elseif (ord($c) >= 0x80) {
                $width = self::utf8Width(ord($c));
                $char = substr($raw, $i, $width);
                if ($char === "\u{2028}" || $char === "\u{2029}") {
                    $isNewline = true;
                } elseif (self::isUnusualWhitespace($char)) {
                    $isSpace = true;
                }
            } elseif ($c === "\v" || $c === "\f") {
                $isSpace = true;
            }
            if ($isNewline) {
                if ($first !== -1 && $afterLast !== -1 && $afterLast > $first) {
                    if ($hasOutput) {
                        $out .= ' ';
                    }
                    $out .= self::decodeEntities(substr($raw, $first, $afterLast - $first));
                    $hasOutput = true;
                }
                $first = -1;
            } elseif (!$isSpace) {
                $afterLast = $i + $width;
                if ($first === -1) {
                    $first = $i;
                }
            }
            $i += $width;
        }
        if ($first !== -1) {
            if ($hasOutput) {
                $out .= ' ';
            }
            $out .= self::decodeEntities(substr($raw, $first));
            $hasOutput = true;
        }
        return $hasOutput ? $out : null;
    }

    /**
     * Decode `&name;`, `&#123;` and `&#x1F600;` references. Unknown names and
     * malformed references stay literal. Numeric values are turned into
     * UTF-16 code units the way esbuild does, so out-of-range values wrap
     * and surrogates survive as lone code units.
     */
    public static function decodeEntities(string $text): string
    {
        if (!str_contains($text, '&')) {
            return $text;
        }
        $entities = self::$entities ??= self::buildEntityTable();
        $out = '';
        $len = strlen($text);
        $i = 0;
        while ($i < $len) {
            $amp = strpos($text, '&', $i);
            if ($amp === false) {
                $out = JsString::concatNormalize($out, substr($text, $i));
                break;
            }
            $out = JsString::concatNormalize($out, substr($text, $i, $amp - $i));
            $semi = strpos($text, ';', $amp + 1);
            if ($semi === false || $semi === $amp + 1) {
                $out .= '&';
                $i = $amp + 1;
                continue;
            }
            $entity = substr($text, $amp + 1, $semi - $amp - 1);
            $decoded = null;
            if ($entity[0] === '#') {
                $number = substr($entity, 1);
                $base = 10;
                if (strlen($number) > 1 && $number[0] === 'x') {
                    $number = substr($number, 1);
                    $base = 16;
                }
                $value = self::parseGoInt32($number, $base);
                if ($value !== null) {
                    $decoded = self::codePointToInternal($value);
                }
            } elseif (isset($entities[$entity])) {
                $decoded = $entities[$entity];
            }
            if ($decoded === null) {
                $out .= '&';
                $i = $amp + 1;
                continue;
            }
            $out = JsString::concatNormalize($out, $decoded);
            $i = $semi + 1;
        }
        return $out;
    }

    /** Go's strconv.ParseInt(s, base, 32): optional sign, digits only, must fit in int32. */
    private static function parseGoInt32(string $s, int $base): ?int
    {
        $pattern = $base === 16 ? '/^[+-]?[0-9a-fA-F]+$/' : '/^[+-]?[0-9]+$/';
        if (preg_match($pattern, $s) !== 1) {
            return null;
        }
        $negative = $s[0] === '-';
        $digits = ltrim($s, '+-');
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return 0;
        }
        if (strlen($digits) > 10) {
            return null;
        }
        $value = intval($digits, $base);
        $value = $negative ? -$value : $value;
        if ($value > 2147483647 || $value < -2147483648) {
            return null;
        }
        return $value;
    }

    /** Encode a decoded numeric reference as esbuild's UTF-16 units, in the engine's internal form. */
    private static function codePointToInternal(int $c): string
    {
        if ($c <= 0xFFFF) {
            return JsString::utf16CodeUnitToUtf8($c & 0xFFFF);
        }
        $c -= 0x10000;
        $high = 0xD800 + (($c >> 10) & 0x3FF);
        $low = 0xDC00 + ($c & 0x3FF);
        return JsString::concatNormalize(
            JsString::utf16CodeUnitToUtf8($high),
            JsString::utf16CodeUnitToUtf8($low),
        );
    }

    /** @return array<string, string> */
    private static function buildEntityTable(): array
    {
        // PHP's XHTML table is exactly the 253 names esbuild decodes.
        $table = [];
        foreach (get_html_translation_table(HTML_ENTITIES, ENT_QUOTES | ENT_XHTML, 'UTF-8') as $char => $entity) {
            $name = substr($entity, 1, -1);
            // PHP spells the apostrophe numerically; JSX knows it as `apos`.
            $table[$name === '#039' ? 'apos' : $name] = $char;
        }
        return $table;
    }

    private static function utf8Width(int $byte): int
    {
        return match (true) {
            $byte >= 0xF0 => 4,
            $byte >= 0xE0 => 3,
            $byte >= 0xC0 => 2,
            default => 1,
        };
    }

    /** Non-ASCII whitespace esbuild trims at line edges (its js_lexer.IsWhitespace). */
    private static function isUnusualWhitespace(string $char): bool
    {
        return in_array($char, [
            "\u{00A0}", "\u{1680}", "\u{2000}", "\u{2001}", "\u{2002}", "\u{2003}", "\u{2004}",
            "\u{2005}", "\u{2006}", "\u{2007}", "\u{2008}", "\u{2009}", "\u{200A}", "\u{202F}",
            "\u{205F}", "\u{3000}", "\u{FEFF}",
        ], true);
    }
}
