<?php

declare(strict_types=1);

namespace Phasis\Formatter;

/**
 * Lays out a document tree against the print width. This is prettier's
 * printDocToString algorithm: a command stack over (indent, mode, doc)
 * triples, a fits() lookahead for group flattening, break propagation for
 * documents containing hard lines, and a line-suffix queue for trailing
 * comments.
 */
final class DocPrinter
{
    private const MODE_BREAK = 1;
    private const MODE_FLAT = 2;

    public function __construct(private readonly FormatOptions $options)
    {
    }

    public function print(mixed $doc): string
    {
        $this->propagateBreaks($doc);

        $width = $this->options->printWidth;
        $indentUnit = $this->options->indentString();
        $indentWidth = $this->options->useTabs ? $this->options->tabWidth : strlen($indentUnit);

        $pos = 0;
        $shouldRemeasure = false;
        /** @var array<int, string> $out */
        $out = [];
        /** @var array<int, array{0: int, 1: int, 2: mixed}> $commands stack of [indentLevel, mode, doc] */
        $commands = [[0, self::MODE_BREAK, $doc]];
        /** @var array<int, array{0: int, 1: int, 2: mixed}> $lineSuffixes */
        $lineSuffixes = [];
        /** @var array<int, int> $groupModeMap */
        $groupModeMap = [];

        while ($commands !== []) {
            [$indent, $mode, $current] = array_pop($commands);

            if (is_string($current)) {
                if ($current !== '') {
                    $out[] = $current;
                    $pos += self::textWidth($current);
                }
                continue;
            }

            if (array_is_list($current)) {
                for ($i = count($current) - 1; $i >= 0; $i--) {
                    $commands[] = [$indent, $mode, $current[$i]];
                }
                continue;
            }

            switch ($current['type']) {
                case 'indent':
                    $commands[] = [$indent + 1, $mode, $current['contents']];
                    break;

                case 'group':
                    if ($mode === self::MODE_FLAT && !$shouldRemeasure && !$current['break']) {
                        $commands[] = [$indent, self::MODE_FLAT, $current['contents']];
                        break;
                    }
                    $shouldRemeasure = false;
                    if ($current['break']) {
                        if ($current['expandedStates'] !== null) {
                            $states = $current['expandedStates'];
                            $commands[] = [$indent, self::MODE_BREAK, $states[count($states) - 1]];
                        } else {
                            $commands[] = [$indent, self::MODE_BREAK, $current['contents']];
                        }
                        if ($current['id'] !== null) {
                            $groupModeMap[$current['id']] = self::MODE_BREAK;
                        }
                        break;
                    }
                    $flatCommand = [$indent, self::MODE_FLAT, $current['contents']];
                    $fits = $this->fits(
                        $flatCommand,
                        $commands,
                        $width - $pos,
                        $lineSuffixes !== [],
                        $groupModeMap,
                        false,
                    );
                    if ($current['expandedStates'] === null) {
                        $chosenMode = $fits ? self::MODE_FLAT : self::MODE_BREAK;
                        $commands[] = [$indent, $chosenMode, $current['contents']];
                    } else {
                        $states = $current['expandedStates'];
                        if ($fits) {
                            $chosenMode = self::MODE_FLAT;
                            $commands[] = $flatCommand;
                        } else {
                            $chosenMode = self::MODE_BREAK;
                            $chosen = $states[count($states) - 1];
                            for ($i = 1; $i < count($states); $i++) {
                                $stateCommand = [$indent, self::MODE_FLAT, $states[$i]];
                                if (
                                    $this->fits(
                                        $stateCommand,
                                        $commands,
                                        $width - $pos,
                                        $lineSuffixes !== [],
                                        $groupModeMap,
                                        false,
                                    )
                                ) {
                                    $chosen = $states[$i];
                                    $chosenMode = self::MODE_FLAT;
                                    break;
                                }
                            }
                            $commands[] = [$indent, $chosenMode, $chosen];
                        }
                    }
                    if ($current['id'] !== null) {
                        $groupModeMap[$current['id']] = $chosenMode;
                    }
                    break;

                case 'if-break':
                    $groupMode = $current['groupId'] !== null
                        ? ($groupModeMap[$current['groupId']] ?? self::MODE_FLAT)
                        : $mode;
                    $contents = $groupMode === self::MODE_BREAK
                        ? $current['breakContents']
                        : $current['flatContents'];
                    if ($contents !== '' && $contents !== null) {
                        $commands[] = [$indent, $mode, $contents];
                    }
                    break;

                case 'indent-if-break':
                    $groupMode = $groupModeMap[$current['groupId']] ?? self::MODE_FLAT;
                    $commands[] = $groupMode === self::MODE_BREAK
                        ? [$indent + 1, $mode, $current['contents']]
                        : [$indent, $mode, $current['contents']];
                    break;

                case 'line-suffix':
                    $lineSuffixes[] = [$indent, $mode, $current['contents']];
                    break;

                case 'line-suffix-boundary':
                    if ($lineSuffixes !== []) {
                        $commands[] = [
                            $indent,
                            $mode,
                            ['type' => 'line', 'hard' => true, 'soft' => false, 'literal' => false],
                        ];
                    }
                    break;

                case 'break-parent':
                    break;

                case 'fill':
                    $this->printFill($current['parts'], $indent, $mode, $commands, $pos, $width, $lineSuffixes, $groupModeMap);
                    break;

                case 'line':
                    if ($mode === self::MODE_FLAT && !$current['hard']) {
                        if (!$current['soft']) {
                            $out[] = ' ';
                            $pos += 1;
                        }
                        break;
                    }
                    if ($mode === self::MODE_FLAT) {
                        $shouldRemeasure = true;
                    }
                    if ($lineSuffixes !== []) {
                        $commands[] = [$indent, $mode, $current];
                        for ($i = count($lineSuffixes) - 1; $i >= 0; $i--) {
                            $commands[] = $lineSuffixes[$i];
                        }
                        $lineSuffixes = [];
                        break;
                    }
                    if ($current['literal']) {
                        $out[] = "\n";
                        $pos = 0;
                    } else {
                        self::trimTrailing($out);
                        $out[] = "\n" . str_repeat($indentUnit, $indent);
                        $pos = $indent * $indentWidth;
                    }
                    break;

                default:
                    throw new \LogicException('Unknown doc command: ' . var_export($current['type'], true));
            }
        }

        if ($lineSuffixes !== []) {
            $tail = [];
            foreach ($lineSuffixes as [$suffixIndent, $suffixMode, $suffixDoc]) {
                $tail[] = $suffixDoc;
            }
            $printer = new self($this->options);
            $out[] = $printer->print($tail);
        }

        return implode('', $out);
    }

    /**
     * @param array{0: int, 1: int, 2: mixed} $next
     * @param array<int, array{0: int, 1: int, 2: mixed}> $restCommands
     * @param array<int, int> $groupModeMap
     */
    private function fits(
        array $next,
        array $restCommands,
        int $remaining,
        bool $hasLineSuffix,
        array $groupModeMap,
        bool $mustBeFlat,
    ): bool {
        $restIndex = count($restCommands);
        $commands = [$next];

        while ($remaining >= 0) {
            if ($commands === []) {
                if ($restIndex === 0) {
                    return true;
                }
                $restIndex--;
                $commands[] = $restCommands[$restIndex];
                continue;
            }

            [$indent, $mode, $current] = array_pop($commands);

            if (is_string($current)) {
                $remaining -= self::textWidth($current);
                continue;
            }

            if (array_is_list($current)) {
                for ($i = count($current) - 1; $i >= 0; $i--) {
                    $commands[] = [$indent, $mode, $current[$i]];
                }
                continue;
            }

            switch ($current['type']) {
                case 'indent':
                    $commands[] = [$indent + 1, $mode, $current['contents']];
                    break;

                case 'group':
                    if ($mustBeFlat && $current['break']) {
                        return false;
                    }
                    $groupMode = $current['break'] ? self::MODE_BREAK : $mode;
                    $contents = $current['expandedStates'] !== null && $groupMode === self::MODE_BREAK
                        ? $current['expandedStates'][count($current['expandedStates']) - 1]
                        : $current['contents'];
                    $commands[] = [$indent, $groupMode, $contents];
                    break;

                case 'if-break':
                    $groupMode = $current['groupId'] !== null
                        ? ($groupModeMap[$current['groupId']] ?? self::MODE_FLAT)
                        : $mode;
                    $contents = $groupMode === self::MODE_BREAK
                        ? $current['breakContents']
                        : $current['flatContents'];
                    if ($contents !== '' && $contents !== null) {
                        $commands[] = [$indent, $mode, $contents];
                    }
                    break;

                case 'indent-if-break':
                    $groupMode = $groupModeMap[$current['groupId']] ?? self::MODE_FLAT;
                    $commands[] = $groupMode === self::MODE_BREAK
                        ? [$indent + 1, $mode, $current['contents']]
                        : [$indent, $mode, $current['contents']];
                    break;

                case 'line':
                    if ($mode === self::MODE_BREAK) {
                        return true;
                    }
                    if ($current['hard']) {
                        return true;
                    }
                    if (!$current['soft']) {
                        $remaining -= 1;
                    }
                    break;

                case 'line-suffix':
                    $hasLineSuffix = true;
                    break;

                case 'line-suffix-boundary':
                    if ($hasLineSuffix) {
                        return true;
                    }
                    break;

                case 'break-parent':
                    break;

                case 'fill':
                    for ($i = count($current['parts']) - 1; $i >= 0; $i--) {
                        $commands[] = [$indent, $mode, $current['parts'][$i]];
                    }
                    break;

                default:
                    throw new \LogicException('Unknown doc command in fits: ' . var_export($current['type'], true));
            }
        }

        return false;
    }

    /**
     * Fill layout: measure content/separator pairs and break only the
     * separators whose following content does not fit.
     *
     * @param array<int, mixed> $parts
     * @param array<int, array{0: int, 1: int, 2: mixed}> $commands
     * @param array<int, array{0: int, 1: int, 2: mixed}> $lineSuffixes
     * @param array<int, int> $groupModeMap
     */
    private function printFill(
        array $parts,
        int $indent,
        int $mode,
        array &$commands,
        int $pos,
        int $width,
        array $lineSuffixes,
        array $groupModeMap,
    ): void {
        if ($parts === []) {
            return;
        }

        $content = $parts[0];
        $contentFits = $this->fits(
            [$indent, self::MODE_FLAT, $content],
            [],
            $width - $pos,
            $lineSuffixes !== [],
            $groupModeMap,
            true,
        );

        if (count($parts) === 1) {
            $commands[] = [$indent, $contentFits ? self::MODE_FLAT : self::MODE_BREAK, $content];
            return;
        }

        $separator = $parts[1];
        if (count($parts) === 2) {
            if ($contentFits) {
                $commands[] = [$indent, self::MODE_FLAT, $separator];
                $commands[] = [$indent, self::MODE_FLAT, $content];
            } else {
                $commands[] = [$indent, self::MODE_BREAK, $separator];
                $commands[] = [$indent, self::MODE_BREAK, $content];
            }
            return;
        }

        $remainingParts = array_slice($parts, 2);
        $nextContent = $remainingParts[0];
        $pairFits = $this->fits(
            [$indent, self::MODE_FLAT, [$content, $separator, $nextContent]],
            [],
            $width - $pos,
            $lineSuffixes !== [],
            $groupModeMap,
            true,
        );

        $commands[] = [$indent, $mode, ['type' => 'fill', 'parts' => $remainingParts]];
        if ($pairFits) {
            $commands[] = [$indent, self::MODE_FLAT, $separator];
            $commands[] = [$indent, self::MODE_FLAT, $content];
        } elseif ($contentFits) {
            $commands[] = [$indent, self::MODE_BREAK, $separator];
            $commands[] = [$indent, self::MODE_FLAT, $content];
        } else {
            $commands[] = [$indent, self::MODE_BREAK, $separator];
            $commands[] = [$indent, self::MODE_BREAK, $content];
        }
    }

    /**
     * Marks every group that transitively contains a hard line or explicit
     * break-parent as broken, bottom-up.
     */
    private function propagateBreaks(mixed &$doc): bool
    {
        if (is_string($doc)) {
            return false;
        }

        if (array_is_list($doc)) {
            $breaks = false;
            foreach ($doc as &$child) {
                if ($this->propagateBreaks($child)) {
                    $breaks = true;
                }
            }
            unset($child);
            return $breaks;
        }

        switch ($doc['type']) {
            case 'break-parent':
                return true;

            case 'line':
                return $doc['hard'];

            case 'group':
                $childBreaks = $this->propagateBreaks($doc['contents']);
                if ($doc['expandedStates'] !== null) {
                    foreach ($doc['expandedStates'] as &$state) {
                        $this->propagateBreaks($state);
                    }
                    unset($state);
                    return $doc['break'];
                }
                if ($childBreaks) {
                    $doc['break'] = true;
                }
                return $childBreaks || $doc['break'];

            case 'indent':
            case 'line-suffix':
                $childBreaks = $this->propagateBreaks($doc['contents']);
                return $doc['type'] === 'line-suffix' ? false : $childBreaks;

            case 'indent-if-break':
                return $this->propagateBreaks($doc['contents']);

            case 'if-break':
                $this->propagateBreaks($doc['breakContents']);
                $this->propagateBreaks($doc['flatContents']);
                return false;

            case 'fill':
                $breaks = false;
                foreach ($doc['parts'] as &$part) {
                    if ($this->propagateBreaks($part)) {
                        $breaks = true;
                    }
                }
                unset($part);
                return $breaks;

            default:
                return false;
        }
    }

    /**
     * Removes trailing spaces and tabs from the accumulated output.
     *
     * @param array<int, string> $out
     */
    private static function trimTrailing(array &$out): void
    {
        while ($out !== []) {
            $last = $out[count($out) - 1];
            $trimmed = rtrim($last, " \t");
            if ($trimmed === $last) {
                break;
            }
            array_pop($out);
            if ($trimmed !== '') {
                $out[] = $trimmed;
                break;
            }
        }
    }

    private static function textWidth(string $text): int
    {
        if (preg_match('//u', $text) !== 1 || strlen($text) === mb_strlen($text)) {
            return strlen($text);
        }
        return mb_strwidth($text, 'UTF-8');
    }
}
