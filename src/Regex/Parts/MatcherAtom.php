<?php

declare(strict_types=1);

namespace Phasis\Regex\Parts;

use Phasis\Regex\Ast\Anchor;
use Phasis\Regex\Ast\Backreference;
use Phasis\Regex\Ast\CharClass;
use Phasis\Regex\Ast\Disjunction;
use Phasis\Regex\Ast\Group;
use Phasis\Regex\Ast\Literal;
use Phasis\Regex\Ast\Lookaround;
use Phasis\Regex\Ast\Node;
use Phasis\Regex\Ast\Pattern;
use Phasis\Regex\Ast\Quantified;
use Phasis\Regex\Ast\Sequence;
use Phasis\Regex\MatcherBudgetExceeded;
use Phasis\Regex\FoldTable;

/**
 * Matcher trait part: MatcherAtom. Composed into Matcher via
 * `use Parts\MatcherAtom;`.
 */
trait MatcherAtom
{
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
        if (++$this->stepsUsed > $this->stepBudget) {
            throw new MatcherBudgetExceeded(
                'regex matcher step budget exhausted; pattern likely needs a non-backtracking matcher'
            );
        }
        if ($node instanceof Literal) {
            return $this->matchLiteral($node->codePoint, $pos, $direction);
        }
        if ($node instanceof CharClass) {
            return $this->matchCharClass($node, $pos, $direction);
        }
        if ($node instanceof \Phasis\Regex\Ast\Dot) {
            // `.` honours the currently-active dotAll flag (which can
            // be flipped by an enclosing (?s:...) / (?-s:...) group).
            $cc = $this->dotAll ? CharClass::any() : CharClass::dotNoDotAll();
            return $this->matchCharClass($cc, $pos, $direction);
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
        if ($node instanceof \Phasis\Regex\Ast\ModifierGroup) {
            return $this->matchModifierGroup($node, $pos, $captures, $direction);
        }
        if ($node instanceof \Phasis\Regex\Ast\UnicodeProperty) {
            return $this->matchUnicodeProperty($node, $pos, $direction);
        }
        return null;
    }

    /**
     * Apply the inline modifier flags for the group's body, run the
     * body, then restore.
     *
     * @param array<int, ?array{0:int,1:int}> $captures
     */
    private function matchModifierGroup(
        \Phasis\Regex\Ast\ModifierGroup $g,
        int $pos,
        array &$captures,
        int $direction,
    ): ?int {
        $savedI = $this->ignoreCase;
        $savedM = $this->multiline;
        $savedS = $this->dotAll;
        if ($g->addI) {
            $this->ignoreCase = true;
        }
        if ($g->addM) {
            $this->multiline = true;
        }
        if ($g->addS) {
            $this->dotAll = true;
        }
        if ($g->removeI) {
            $this->ignoreCase = false;
        }
        if ($g->removeM) {
            $this->multiline = false;
        }
        if ($g->removeS) {
            $this->dotAll = false;
        }
        try {
            return $this->matchNode($g->body, $pos, $captures, $direction);
        } finally {
            $this->ignoreCase = $savedI;
            $this->multiline = $savedM;
            $this->dotAll = $savedS;
        }
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
     * Spec Canonicalize. In /u mode, uses the simple case-folding
     * table from CaseFolding.txt (ICU's IntlChar::foldCase when
     * available, with a small fallback for the cases mb_strtolower
     * misses). In non-/u mode, uppercases per ECMA-262 §22.2.2.7
     * with the ASCII-result guard.
     */
    private function canonicalize(int $cp): int
    {
        if ($this->unicode) {
            if ($cp >= 0x41 && $cp <= 0x5A) {
                return $cp + 0x20;
            }
            if ($cp < 0x80) {
                return $cp;
            }
            // Unicode 16 simple/common case-fold pairs that ICU 74
            // (Ubuntu CI) doesn't know but ICU 76+ does. Override the
            // host-foldCase so /ui matching is host-independent. Add
            // more here when test262 turns up additional mismatches.
            // Full ICU 78 (Unicode 16) simple case-fold table.
            // Consulted before IntlChar so host ICU drift can't change
            // match results — Ubuntu CI ships ICU 70/74 which miss
            // a few hundred Unicode 14/15/16 fold equivalences that
            // our table covers. On hosts that already know these
            // pairs the override result matches the host result, so
            // it's a no-op in steady state.
            $override = FoldTable::fold($cp);
            if ($override !== null) {
                return $override;
            }
            if (class_exists(\IntlChar::class)) {
                return \IntlChar::foldCase($cp);
            }
            // Special cases mb_strtolower doesn't handle.
            if ($cp === 0x017F) {
                return 0x73;
            }
            if ($cp === 0x212A) {
                return 0x6B;
            }
            if ($cp < 0x10000) {
                $ch = mb_chr($cp, 'UTF-8');
                // mb_chr returns false for invalid codepoints (lone
                // surrogates, anything above 0x10FFFF). Falling through
                // to mb_strtolower(false) triggers a PHP TypeError that
                // bubbles out of the regex engine. Pass the cp through.
                if (!is_string($ch) || $ch === '') {
                    return $cp;
                }
                return mb_ord(mb_strtolower($ch, 'UTF-8'), 'UTF-8');
            }
            return $cp;
        }
        // Non-/u mode: ASCII fast path then mb_strtoupper.
        if ($cp >= 0x61 && $cp <= 0x7A) {
            return $cp - 0x20;
        }
        if ($cp < 0x10000) {
            $ch = mb_chr($cp, 'UTF-8');
            if (!is_string($ch) || $ch === '') {
                return $cp;
            }
            $upper = mb_strtoupper($ch, 'UTF-8');
            $folded = mb_ord($upper, 'UTF-8');
            // ECMA-262 §22.2.2.7.5 step 2.g: when the candidate is
            // non-ASCII but its uppercase folds to ASCII, suppress
            // the fold so a non-Latin1 letter does not collide
            // with an ASCII letter (e.g. ſ.toUpperCase() = S
            // — yet /S/i must NOT match ſ).
            if ($cp >= 128 && $folded < 128) {
                return $cp;
            }
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
        // Hot path: no case-folding, single-candidate. Skip the
        // candidates array allocation and inner-loop dispatch — the
        // CharacterClassEscapes corpus tests `\D+` / `\W+` / `\S+`
        // against >1M codepoints, so per-call array overhead matters.
        if (!$this->ignoreCase) {
            $matched = false;
            foreach ($cc->ranges as $range) {
                if ($cu >= $range[0] && $cu <= $range[1]) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched && $cc->properties !== []) {
                foreach ($cc->properties as $prop) {
                    if ($this->testUnicodeProperty($prop, $cu)) {
                        $matched = true;
                        break;
                    }
                }
            }
            return $cc->negated ? !$matched : $matched;
        }
        // Spec CharacterSetMatcher canonicalizes both the candidate
        // and each set member. For ASCII letters we just check both
        // the case-shifted candidate and its Canonicalize result
        // against each range; that covers the bulk of tests without
        // needing an exhaustive fold expansion of the range. The
        // no-/i case is handled by the early return above.
        $candidates = [$cu, $this->canonicalize($cu)];
        if ($cu >= 0x41 && $cu <= 0x5A) {
            $candidates[] = $cu + 0x20;
        } elseif ($cu >= 0x61 && $cu <= 0x7A) {
            $candidates[] = $cu - 0x20;
        }
        if ($this->unicode && $cu >= 0x80 && class_exists(\IntlChar::class)) {
            $upper = \IntlChar::toupper($cu);
            if (is_int($upper)) {
                $candidates[] = $upper;
            }
            $lower = \IntlChar::tolower($cu);
            if (is_int($lower)) {
                $candidates[] = $lower;
            }
        }
        $matched = false;
        foreach ($cc->ranges as [$lo, $hi]) {
            foreach ($candidates as $c) {
                if ($c >= $lo && $c <= $hi) {
                    $matched = true;
                    break 2;
                }
            }
        }
        if (!$matched && $this->unicode) {
            // Per spec CharacterSetMatcher: canonicalize the set member
            // too, not just the candidate. The candidates list already
            // contains canonicalize($cu); now check whether each range
            // endpoint canonicalizes to a value in the candidates list.
            // Most range endpoints are ASCII or non-folded so this is
            // cheap; the wider sweep catches Unicode 16 fold pairs
            // (e.g. /[ΐ]/ui matching "ΐ") that the unidirectional
            // canonicalize($cu) miss.
            foreach ($cc->ranges as [$lo, $hi]) {
                if ($lo === $hi) {
                    $foldedLo = $this->canonicalize($lo);
                    foreach ($candidates as $c) {
                        if ($c === $foldedLo) {
                            $matched = true;
                            break 2;
                        }
                    }
                }
            }
        }
        if (!$matched && $cc->properties !== []) {
            foreach ($cc->properties as $prop) {
                foreach ($candidates as $c) {
                    if ($this->testUnicodeProperty($prop, $c)) {
                        $matched = true;
                        break 2;
                    }
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
            case Anchor::SCAN:
                return $pos === $this->matchStart ? $pos : null;
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
        if (
            ($cu >= 0x30 && $cu <= 0x39)
            || ($cu >= 0x41 && $cu <= 0x5A)
            || $cu === 0x5F
            || ($cu >= 0x61 && $cu <= 0x7A)
        ) {
            return true;
        }
        // Per ECMA-262 GetWordCharacters: under /u + /i, characters
        // whose Canonicalize lands in the basic word set are also
        // word characters.
        if ($this->ignoreCase && $this->unicode) {
            $folded = $this->canonicalize($cu);
            if (
                ($folded >= 0x30 && $folded <= 0x39)
                || ($folded >= 0x41 && $folded <= 0x5A)
                || $folded === 0x5F
                || ($folded >= 0x61 && $folded <= 0x7A)
            ) {
                return true;
            }
        }
        return false;
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
