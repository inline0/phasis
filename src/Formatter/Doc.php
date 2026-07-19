<?php

declare(strict_types=1);

namespace Phasis\Formatter;

/**
 * Builders for the formatter's intermediate document language, mirroring the
 * Wadler-style command set prettier uses. A doc is either a string, a list of
 * docs (concatenation), or a typed array command built by these helpers. The
 * printer in DocPrinter lays a doc tree out against a print width.
 *
 * @phpstan-type DocNode string|array<int|string, mixed>
 */
final class Doc
{
    private static int $nextGroupId = 1;

    /**
     * @param array<int|string, mixed>|string $contents
     * @return array<string, mixed>
     */
    public static function group(array|string $contents, bool $shouldBreak = false, ?int $id = null): array
    {
        return [
            'type' => 'group',
            'contents' => $contents,
            'break' => $shouldBreak,
            'expandedStates' => null,
            'id' => $id,
        ];
    }

    /**
     * A group that tries each state in order and uses the first that fits;
     * the last state is used when none fit.
     *
     * @param array<int, mixed> $states
     * @return array<string, mixed>
     */
    public static function conditionalGroup(array $states): array
    {
        return [
            'type' => 'group',
            'contents' => $states[0],
            'break' => false,
            'expandedStates' => $states,
            'id' => null,
        ];
    }

    public static function newGroupId(): int
    {
        return self::$nextGroupId++;
    }

    /**
     * @return array<string, mixed>
     */
    public static function indent(mixed $contents): array
    {
        return ['type' => 'indent', 'contents' => $contents];
    }

    /**
     * @return array<string, mixed>
     */
    public static function line(): array
    {
        return ['type' => 'line', 'hard' => false, 'soft' => false, 'literal' => false];
    }

    /**
     * @return array<string, mixed>
     */
    public static function softline(): array
    {
        return ['type' => 'line', 'hard' => false, 'soft' => true, 'literal' => false];
    }

    /**
     * @return array<int, mixed>
     */
    public static function hardline(): array
    {
        return [
            ['type' => 'line', 'hard' => true, 'soft' => false, 'literal' => false],
            self::breakParent(),
        ];
    }

    /**
     * A hard line that does not re-indent: the next line starts at column zero.
     * Used inside template literals where whitespace is significant.
     *
     * @return array<int, mixed>
     */
    public static function literalline(): array
    {
        return [
            ['type' => 'line', 'hard' => true, 'soft' => false, 'literal' => true],
            self::breakParent(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function ifBreak(mixed $breakContents, mixed $flatContents = '', ?int $groupId = null): array
    {
        return [
            'type' => 'if-break',
            'breakContents' => $breakContents,
            'flatContents' => $flatContents,
            'groupId' => $groupId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function indentIfBreak(mixed $contents, int $groupId): array
    {
        return ['type' => 'indent-if-break', 'contents' => $contents, 'groupId' => $groupId];
    }

    /**
     * @return array<string, mixed>
     */
    public static function lineSuffix(mixed $contents): array
    {
        return ['type' => 'line-suffix', 'contents' => $contents];
    }

    /**
     * @return array<string, mixed>
     */
    public static function lineSuffixBoundary(): array
    {
        return ['type' => 'line-suffix-boundary'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function breakParent(): array
    {
        return ['type' => 'break-parent'];
    }

    /**
     * Alternates content and line docs, breaking only the lines that must
     * break to fit. Used for prose-like sequences.
     *
     * @param array<int, mixed> $parts
     * @return array<string, mixed>
     */
    public static function fill(array $parts): array
    {
        return ['type' => 'fill', 'parts' => $parts];
    }

    /**
     * Joins docs with a separator doc.
     *
     * @param array<int, mixed> $docs
     * @return array<int, mixed>
     */
    public static function join(mixed $separator, array $docs): array
    {
        $out = [];
        foreach ($docs as $index => $doc) {
            if ($index > 0) {
                $out[] = $separator;
            }
            $out[] = $doc;
        }
        return $out;
    }
}
