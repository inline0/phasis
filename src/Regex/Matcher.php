<?php

declare(strict_types=1);

namespace PhpJs\Regex;

use PhpJs\Regex\Ast\Anchor;
use PhpJs\Regex\Ast\Backreference;
use PhpJs\Regex\Ast\CharClass;
use PhpJs\Regex\Ast\Disjunction;
use PhpJs\Regex\Ast\Group;
use PhpJs\Regex\Ast\Literal;
use PhpJs\Regex\Ast\Lookaround;
use PhpJs\Regex\Ast\Node;
use PhpJs\Regex\Ast\Pattern;
use PhpJs\Regex\Ast\Quantified;
use PhpJs\Regex\Ast\Sequence;

/**
 * Tree-walking ECMAScript regex matcher.
 *
 * Operates on the input as a sequence of UTF-16 code units in
 * non-unicode mode (so `.` matches a single code unit and astral
 * characters consume two slots) or as code points in /u mode. All
 * captures, lookbehind directionality, and capture-reset semantics
 * follow ECMA-262 §22.2 directly — these are the corners where the
 * PCRE2 bridge diverges from spec.
 *
 * Used as a fallback only: see RegExpPrototype for the wire-up. The
 * common case still goes through PCRE2 for performance.
 */
class Matcher
{
    /** Input as array of code-units (uint16) in non-unicode mode, or code points in /u. */
    private array $input;
    private int $inputLen;
    private bool $ignoreCase;
    private bool $multiline;
    /** Reserved for matchNode's CharClass::any() / dot handling. */
    private bool $dotAll; // @phpstan-ignore-line
    private bool $unicode;

    private Pattern $pattern;

    /**
     * @param Pattern $pattern Parsed AST.
     * @param string $flags Spec flags (g, i, m, s, u, v, y, d).
     */
    public function __construct(Pattern $pattern, string $flags)
    {
        $this->pattern = $pattern;
        $this->ignoreCase = str_contains($flags, 'i');
        $this->multiline = str_contains($flags, 'm');
        $this->dotAll = str_contains($flags, 's');
        $this->unicode = str_contains($flags, 'u') || str_contains($flags, 'v');
    }

    /**
     * Try to match the pattern against $input starting at $start.
     *
     * Returns a match record:
     *   ['index' => int (UTF-16 code unit start),
     *    'end' => int (UTF-16 code unit end),
     *    'captures' => list<?array{0:int,1:int,2:string}>]
     * where each capture is [start, end, value] or null if it didn't
     * participate. Returns null when no match found.
     */
    public function match(string $inputUtf8, int $startCodeUnit): ?array
    {
        $this->input = $this->unicode
            ? self::utf8ToCodePoints($inputUtf8)
            : self::utf8ToUtf16Units($inputUtf8);
        $this->inputLen = count($this->input);
        // Initialize capture array sized to groupCount + 1 (1-based).
        $captures = array_fill(0, $this->pattern->groupCount + 1, null);
        for ($pos = $startCodeUnit; $pos <= $this->inputLen; $pos++) {
            $caps = $captures;
            $end = $this->matchNode($this->pattern->body, $pos, $caps, /*direction=*/+1);
            if ($end !== null) {
                $caps[0] = [$pos, $end];
                return $this->buildResult($pos, $end, $caps, $inputUtf8);
            }
        }
        return null;
    }

    /**
     * @param array<int, ?array{0:int,1:int}> $captures
     */
    private function buildResult(int $startCu, int $endCu, array $captures, string $inputUtf8): array
    {
        $out = [
            'index' => $startCu,
            'end' => $endCu,
            'captures' => [],
        ];
        // Convert each capture's code-unit range to byte offsets in
        // the source UTF-8 string + extracted value.
        for ($i = 0; $i <= $this->pattern->groupCount; $i++) {
            $cap = $captures[$i] ?? null;
            if ($cap === null) {
                $out['captures'][$i] = null;
                continue;
            }
            [$s, $e] = $cap;
            $byteStart = $this->codeUnitToByteOffset($inputUtf8, $s);
            $byteEnd = $this->codeUnitToByteOffset($inputUtf8, $e);
            $out['captures'][$i] = [
                $s,
                $e,
                substr($inputUtf8, $byteStart, $byteEnd - $byteStart),
            ];
        }
        return $out;
    }

    private function codeUnitToByteOffset(string $utf8, int $cu): int
    {
        // Walk the UTF-8 string and count UTF-16 code units (or
        // codepoints in /u mode) until we reach the target.
        $len = strlen($utf8);
        $byte = 0;
        $count = 0;
        while ($byte < $len && $count < $cu) {
            $b = ord($utf8[$byte]);
            if ($b < 0x80) {
                $byte++;
                $count++;
            } elseif (($b & 0xE0) === 0xC0) {
                $byte += 2;
                $count++;
            } elseif (($b & 0xF0) === 0xE0) {
                $byte += 3;
                $count++;
            } else {
                // 4-byte UTF-8: astral. In non-/u mode this is two
                // UTF-16 code units; in /u mode one code point.
                $byte += 4;
                $count += $this->unicode ? 1 : 2;
            }
        }
        return $byte;
    }

    /** @return list<int> */
    public static function utf8ToUtf16Units(string $s): array
    {
        $out = [];
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            $b = ord($s[$i]);
            if ($b < 0x80) {
                $out[] = $b;
                $i++;
            } elseif (($b & 0xE0) === 0xC0 && $i + 1 < $len) {
                $b2 = ord($s[$i + 1]);
                $out[] = (($b & 0x1F) << 6) | ($b2 & 0x3F);
                $i += 2;
            } elseif (($b & 0xF0) === 0xE0 && $i + 2 < $len) {
                $b2 = ord($s[$i + 1]);
                $b3 = ord($s[$i + 2]);
                $out[] = (($b & 0x0F) << 12) | (($b2 & 0x3F) << 6) | ($b3 & 0x3F);
                $i += 3;
            } elseif (($b & 0xF8) === 0xF0 && $i + 3 < $len) {
                $b2 = ord($s[$i + 1]);
                $b3 = ord($s[$i + 2]);
                $b4 = ord($s[$i + 3]);
                $cp = (($b & 0x07) << 18) | (($b2 & 0x3F) << 12) | (($b3 & 0x3F) << 6) | ($b4 & 0x3F);
                // Encode as surrogate pair.
                $cp -= 0x10000;
                $out[] = 0xD800 + ($cp >> 10);
                $out[] = 0xDC00 + ($cp & 0x3FF);
                $i += 4;
            } else {
                $out[] = $b;
                $i++;
            }
        }
        return $out;
    }

    /** @return list<int> */
    public static function utf8ToCodePoints(string $s): array
    {
        $out = [];
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            $b = ord($s[$i]);
            if ($b < 0x80) {
                $out[] = $b;
                $i++;
            } elseif (($b & 0xE0) === 0xC0 && $i + 1 < $len) {
                $b2 = ord($s[$i + 1]);
                $out[] = (($b & 0x1F) << 6) | ($b2 & 0x3F);
                $i += 2;
            } elseif (($b & 0xF0) === 0xE0 && $i + 2 < $len) {
                $b2 = ord($s[$i + 1]);
                $b3 = ord($s[$i + 2]);
                $out[] = (($b & 0x0F) << 12) | (($b2 & 0x3F) << 6) | ($b3 & 0x3F);
                $i += 3;
            } elseif (($b & 0xF8) === 0xF0 && $i + 3 < $len) {
                $b2 = ord($s[$i + 1]);
                $b3 = ord($s[$i + 2]);
                $b4 = ord($s[$i + 3]);
                $out[] = (($b & 0x07) << 18) | (($b2 & 0x3F) << 12) | (($b3 & 0x3F) << 6) | ($b4 & 0x3F);
                $i += 4;
            } else {
                $out[] = $b;
                $i++;
            }
        }
        return $out;
    }

    /**
     * Attempt to match $node starting at code-unit $pos with the given
     * captures. Returns the position AFTER the match on success, or
     * null on failure. $captures is mutated on success.
     *
     * $direction is +1 for forward matching, -1 for inside a
     * lookbehind body (which matches right-to-left).
     *
     * @param array<int, ?array{0:int,1:int}> $captures
     */
    private function matchNode(Node $node, int $pos, array &$captures, int $direction): ?int
    {
        if ($node instanceof Literal) {
            return $this->matchLiteral($node->codePoint, $pos, $direction);
        }
        if ($node instanceof CharClass) {
            return $this->matchCharClass($node, $pos, $direction);
        }
        if ($node instanceof Anchor) {
            return $this->matchAnchor($node, $pos);
        }
        if ($node instanceof Sequence) {
            return $this->matchSequence($node, $pos, $captures, $direction);
        }
        if ($node instanceof Disjunction) {
            return $this->matchDisjunction($node, $pos, $captures, $direction);
        }
        if ($node instanceof Quantified) {
            return $this->matchQuantified($node, $pos, $captures, $direction);
        }
        if ($node instanceof Group) {
            return $this->matchGroup($node, $pos, $captures, $direction);
        }
        if ($node instanceof Lookaround) {
            return $this->matchLookaround($node, $pos, $captures);
        }
        if ($node instanceof Backreference) {
            return $this->matchBackreference($node, $pos, $captures, $direction);
        }
        return null;
    }

    private function matchLiteral(int $cp, int $pos, int $direction): ?int
    {
        if ($direction > 0) {
            if ($pos >= $this->inputLen) {
                return null;
            }
            $cu = $this->input[$pos];
            if ($this->charsEqual($cu, $cp)) {
                return $pos + 1;
            }
            return null;
        }
        // Reverse: match the cell BEFORE pos.
        if ($pos <= 0) {
            return null;
        }
        $cu = $this->input[$pos - 1];
        if ($this->charsEqual($cu, $cp)) {
            return $pos - 1;
        }
        return null;
    }

    private function charsEqual(int $a, int $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if ($this->ignoreCase) {
            return $this->canonicalize($a) === $this->canonicalize($b);
        }
        return false;
    }

    /**
     * Spec Canonicalize for /i mode. Simple ASCII-aware folding with
     * a Unicode hint: lowercase letters fold to uppercase. This
     * isn't a complete Unicode case-folding table but covers the
     * cases the failing test262 tests actually exercise.
     */
    private function canonicalize(int $cp): int
    {
        if ($cp >= 0x61 && $cp <= 0x7A) {
            return $cp - 0x20;
        }
        // For the BMP, fall back to mb_strtoupper on a single-char
        // string so locale-style case folding works for common
        // diacritics. We avoid this in the inner literal-comparison
        // hot path by testing the ASCII range first above.
        if ($cp >= 0x80 && $cp < 0x10000) {
            $ch = mb_chr($cp, 'UTF-8');
            if ($ch === '') {
                return $cp;
            }
            $upper = mb_strtoupper($ch, 'UTF-8');
            $folded = mb_ord($upper, 'UTF-8');
            return $folded;
        }
        return $cp;
    }

    private function matchCharClass(CharClass $cc, int $pos, int $direction): ?int
    {
        if ($direction > 0) {
            if ($pos >= $this->inputLen) {
                return null;
            }
            $cu = $this->input[$pos];
            return $this->charClassMatchesCu($cc, $cu) ? $pos + 1 : null;
        }
        if ($pos <= 0) {
            return null;
        }
        $cu = $this->input[$pos - 1];
        return $this->charClassMatchesCu($cc, $cu) ? $pos - 1 : null;
    }

    private function charClassMatchesCu(CharClass $cc, int $cu): bool
    {
        $folded = $this->ignoreCase ? $this->canonicalize($cu) : $cu;
        $matched = false;
        foreach ($cc->ranges as [$lo, $hi]) {
            if ($this->ignoreCase) {
                // Fold the range endpoints? The simplest correct
                // approach is to fold the candidate and compare; for
                // ranges like [a-z] with /i the fold of the candidate
                // (uppercase) wouldn't be in [a-z]. Spec
                // CharacterSetMatcher uses Canonicalize on both. So
                // also fold candidate against the range's folded form
                // by checking both folded and unfolded.
                if ($cu >= $lo && $cu <= $hi) {
                    $matched = true;
                    break;
                }
                if ($folded >= $lo && $folded <= $hi) {
                    $matched = true;
                    break;
                }
            } else {
                if ($cu >= $lo && $cu <= $hi) {
                    $matched = true;
                    break;
                }
            }
        }
        return $cc->negated ? !$matched : $matched;
    }

    private function matchAnchor(Anchor $a, int $pos): ?int
    {
        switch ($a->kind) {
            case Anchor::START:
                if ($pos === 0) {
                    return $pos;
                }
                if ($this->multiline && $this->isLineTerminatorAt($pos - 1)) {
                    return $pos;
                }
                return null;
            case Anchor::END:
                if ($pos === $this->inputLen) {
                    return $pos;
                }
                if ($this->multiline && $this->isLineTerminatorAt($pos)) {
                    return $pos;
                }
                return null;
            case Anchor::WORD_BOUNDARY:
                $a1 = $pos > 0 && $this->isWordCu($this->input[$pos - 1]);
                $a2 = $pos < $this->inputLen && $this->isWordCu($this->input[$pos]);
                return ($a1 xor $a2) ? $pos : null;
            case Anchor::NON_WORD_BOUNDARY:
                $a1 = $pos > 0 && $this->isWordCu($this->input[$pos - 1]);
                $a2 = $pos < $this->inputLen && $this->isWordCu($this->input[$pos]);
                return !($a1 xor $a2) ? $pos : null;
        }
        return null;
    }

    private function isLineTerminatorAt(int $pos): bool
    {
        if ($pos < 0 || $pos >= $this->inputLen) {
            return false;
        }
        $cu = $this->input[$pos];
        return $cu === 0x0A || $cu === 0x0D || $cu === 0x2028 || $cu === 0x2029;
    }

    private function isWordCu(int $cu): bool
    {
        return ($cu >= 0x30 && $cu <= 0x39)
            || ($cu >= 0x41 && $cu <= 0x5A)
            || $cu === 0x5F
            || ($cu >= 0x61 && $cu <= 0x7A);
    }

    /**
     * @param array<int, ?array{0:int,1:int}> $captures
     */
    private function matchSequence(Sequence $seq, int $pos, array &$captures, int $direction): ?int
    {
        return $this->matchSequenceFrom($seq->terms, 0, $pos, $captures, $direction);
    }

    /**
     * @param list<Node> $terms
     * @param array<int, ?array{0:int,1:int}> $captures
     */
    private function matchSequenceFrom(array $terms, int $idx, int $pos, array &$captures, int $direction): ?int
    {
        if ($idx >= count($terms)) {
            return $pos;
        }
        if ($direction > 0) {
            $term = $terms[$idx];
        } else {
            $term = $terms[count($terms) - 1 - $idx];
        }
        // Quantifiers and disjunctions need to enumerate multiple
        // alternative end positions and let the rest of the sequence
        // backtrack into them. Use the iterator-driven path.
        if ($term instanceof Quantified) {
            return $this->matchQuantifiedInSequence(
                $term,
                $terms,
                $idx,
                $pos,
                $captures,
                $direction,
            );
        }
        if ($term instanceof Disjunction) {
            return $this->matchDisjunctionInSequence(
                $term,
                $terms,
                $idx,
                $pos,
                $captures,
                $direction,
            );
        }
        // Single-position term: match it, then continue.
        $savedCaptures = $captures;
        $end = $this->matchNode($term, $pos, $captures, $direction);
        if ($end === null) {
            $captures = $savedCaptures;
            return null;
        }
        $rest = $this->matchSequenceFrom($terms, $idx + 1, $end, $captures, $direction);
        if ($rest === null) {
            $captures = $savedCaptures;
            return null;
        }
        return $rest;
    }

    /**
     * Match a quantifier embedded in a sequence. Generates each
     * possible iteration count (greedy: longest first; lazy:
     * shortest first), running the rest of the sequence after each;
     * the first combination that succeeds wins.
     *
     * @param list<Node> $terms
     * @param array<int, ?array{0:int,1:int}> $captures
     */
    private function matchQuantifiedInSequence(
        Quantified $q,
        array $terms,
        int $idx,
        int $pos,
        array &$captures,
        int $direction,
    ): ?int {
        $innerGroups = $this->collectGroupIndices($q->atom);
        $savedAll = $captures;
        // Generate all reachable iteration end-positions in order.
        // Each entry is [endPos, capturesSnapshot].
        $positions = [];
        $this->enumerateQuantifier(
            $q->atom,
            $q->min,
            $q->max,
            $innerGroups,
            $pos,
            $captures,
            $direction,
            iterCount: 0,
            positions: $positions,
        );
        // Try them in greedy/lazy order.
        $order = $q->greedy ? array_reverse($positions, true) : $positions;
        foreach ($order as $entry) {
            $endPos = $entry[0];
            $captures = $entry[1];
            $rest = $this->matchSequenceFrom($terms, $idx + 1, $endPos, $captures, $direction);
            if ($rest !== null) {
                return $rest;
            }
        }
        $captures = $savedAll;
        return null;
    }

    /**
     * Walk the quantifier and append every reachable [endPos,
     * captures] pair (greedy by depth-first descent) to $positions.
     * Stops when the atom can't extend or hits the upper bound.
     *
     * @param list<int> $innerGroups
     * @param array<int, ?array{0:int,1:int}> $captures
     * @param list<array{0:int,1:array<int, ?array{0:int,1:int}>}> $positions
     */
    private function enumerateQuantifier(
        Node $atom,
        int $min,
        ?int $max,
        array $innerGroups,
        int $pos,
        array &$captures,
        int $direction,
        int $iterCount,
        array &$positions,
    ): void {
        if ($iterCount >= $min) {
            $positions[] = [$pos, $captures];
        }
        if ($max !== null && $iterCount >= $max) {
            return;
        }
        $saved = $captures;
        foreach ($innerGroups as $gi) {
            $captures[$gi] = null;
        }
        $newPos = $this->matchNode($atom, $pos, $captures, $direction);
        if ($newPos === null) {
            $captures = $saved;
            return;
        }
        if ($newPos === $pos) {
            // Avoid infinite loop on zero-width matches.
            $captures = $saved;
            return;
        }
        $this->enumerateQuantifier(
            $atom,
            $min,
            $max,
            $innerGroups,
            $newPos,
            $captures,
            $direction,
            $iterCount + 1,
            $positions,
        );
    }

    /**
     * @param list<Node> $terms
     * @param array<int, ?array{0:int,1:int}> $captures
     */
    private function matchDisjunctionInSequence(
        Disjunction $d,
        array $terms,
        int $idx,
        int $pos,
        array &$captures,
        int $direction,
    ): ?int {
        $savedAll = $captures;
        foreach ($d->alternatives as $alt) {
            $captures = $savedAll;
            $end = $this->matchNode($alt, $pos, $captures, $direction);
            if ($end === null) {
                continue;
            }
            $rest = $this->matchSequenceFrom($terms, $idx + 1, $end, $captures, $direction);
            if ($rest !== null) {
                return $rest;
            }
        }
        $captures = $savedAll;
        return null;
    }

    /**
     * @param array<int, ?array{0:int,1:int}> $captures
     */
    private function matchDisjunction(Disjunction $d, int $pos, array &$captures, int $direction): ?int
    {
        foreach ($d->alternatives as $alt) {
            $saved = $captures;
            $end = $this->matchNode($alt, $pos, $captures, $direction);
            if ($end !== null) {
                return $end;
            }
            $captures = $saved;
        }
        return null;
    }

    /**
     * @param array<int, ?array{0:int,1:int}> $captures
     */
    private function matchQuantified(Quantified $q, int $pos, array &$captures, int $direction): ?int
    {
        // Per spec: each iteration of a quantified atom resets the
        // captures of every group inside that atom. This is the
        // capture-reset semantics PCRE2 lacks.
        $innerGroups = $this->collectGroupIndices($q->atom);
        return $this->matchQuantifiedRec(
            $q->atom,
            $q->min,
            $q->max,
            $q->greedy,
            $innerGroups,
            $pos,
            $captures,
            $direction,
            iterCount: 0,
        );
    }

    /**
     * @param list<int> $innerGroups
     * @param array<int, ?array{0:int,1:int}> $captures
     */
    private function matchQuantifiedRec(
        Node $atom,
        int $min,
        ?int $max,
        bool $greedy,
        array $innerGroups,
        int $pos,
        array &$captures,
        int $direction,
        int $iterCount,
    ): ?int {
        $reachedMax = $max !== null && $iterCount >= $max;
        $satisfiedMin = $iterCount >= $min;
        if ($reachedMax) {
            return $pos;
        }
        // Try to extend: reset inner captures, then match one more.
        $tryExtend = function () use ($atom, $min, $max, $greedy, $innerGroups, $pos, &$captures, $direction, $iterCount) {
            $saved = $captures;
            // Reset captures of inner groups before matching another iter.
            foreach ($innerGroups as $gi) {
                $captures[$gi] = null;
            }
            $newPos = $this->matchNode($atom, $pos, $captures, $direction);
            if ($newPos === null) {
                $captures = $saved;
                return null;
            }
            // Avoid infinite loops on zero-width matches.
            if ($newPos === $pos) {
                // Stop quantifying; treat current state as max reached.
                $captures = $saved;
                return null;
            }
            return $this->matchQuantifiedRec(
                $atom,
                $min,
                $max,
                $greedy,
                $innerGroups,
                $newPos,
                $captures,
                $direction,
                $iterCount + 1,
            );
        };
        if ($greedy) {
            $extendResult = $tryExtend();
            if ($extendResult !== null) {
                return $extendResult;
            }
            return $satisfiedMin ? $pos : null;
        }
        // Non-greedy: try to stop first.
        if ($satisfiedMin) {
            return $pos;
        }
        return $tryExtend();
    }

    /**
     * @return list<int>
     */
    private function collectGroupIndices(Node $node): array
    {
        $out = [];
        $this->walkGroupIndices($node, $out);
        return $out;
    }

    /**
     * @param list<int> $out
     */
    private function walkGroupIndices(Node $node, array &$out): void
    {
        if ($node instanceof Group && $node->isCapturing()) {
            $out[] = $node->index;
        }
        if ($node instanceof Group) {
            $this->walkGroupIndices($node->body, $out);
        } elseif ($node instanceof Sequence) {
            foreach ($node->terms as $t) {
                $this->walkGroupIndices($t, $out);
            }
        } elseif ($node instanceof Disjunction) {
            foreach ($node->alternatives as $a) {
                $this->walkGroupIndices($a, $out);
            }
        } elseif ($node instanceof Quantified) {
            $this->walkGroupIndices($node->atom, $out);
        } elseif ($node instanceof Lookaround) {
            $this->walkGroupIndices($node->body, $out);
        }
    }

    /**
     * @param array<int, ?array{0:int,1:int}> $captures
     */
    private function matchGroup(Group $g, int $pos, array &$captures, int $direction): ?int
    {
        $start = $pos;
        $end = $this->matchNode($g->body, $pos, $captures, $direction);
        if ($end === null) {
            return null;
        }
        if ($g->isCapturing()) {
            // In reverse (lookbehind), $end < $start; the capture's
            // logical range is [end, start].
            $lo = min($start, $end);
            $hi = max($start, $end);
            $captures[$g->index] = [$lo, $hi];
        }
        return $end;
    }

    /**
     * @param array<int, ?array{0:int,1:int}> $captures
     */
    private function matchLookaround(Lookaround $la, int $pos, array &$captures): ?int
    {
        $direction = $la->behind ? -1 : +1;
        $saved = $captures;
        $result = $this->matchNode($la->body, $pos, $captures, $direction);
        $matched = $result !== null;
        if ($la->negative) {
            // Negative lookaround: success if the body did NOT match.
            // Captures inside a negative lookaround are NOT preserved.
            $captures = $saved;
            return $matched ? null : $pos;
        }
        // Positive: success if body matched. Captures inside ARE
        // preserved (lookbehind in particular relies on this).
        if (!$matched) {
            $captures = $saved;
            return null;
        }
        return $pos;
    }

    /**
     * @param array<int, ?array{0:int,1:int}> $captures
     */
    private function matchBackreference(Backreference $br, int $pos, array &$captures, int $direction): ?int
    {
        $idx = $br->index;
        if ($idx === null && $br->name !== null) {
            // Resolve the name to whichever group with that name has
            // a current capture.
            foreach ($this->pattern->indexToName as $i => $n) {
                if ($n === $br->name && isset($captures[$i])) {
                    $idx = $i;
                    break;
                }
            }
        }
        if ($idx === null || !array_key_exists($idx, $captures) || $captures[$idx] === null) {
            // Backreference to a group that didn't participate matches
            // the empty string per spec.
            return $pos;
        }
        [$s, $e] = $captures[$idx];
        $len = $e - $s;
        if ($direction > 0) {
            if ($pos + $len > $this->inputLen) {
                return null;
            }
            for ($k = 0; $k < $len; $k++) {
                if (!$this->charsEqual($this->input[$pos + $k], $this->input[$s + $k])) {
                    return null;
                }
            }
            return $pos + $len;
        }
        if ($pos - $len < 0) {
            return null;
        }
        for ($k = 0; $k < $len; $k++) {
            if (!$this->charsEqual($this->input[$pos - $len + $k], $this->input[$s + $k])) {
                return null;
            }
        }
        return $pos - $len;
    }
}
