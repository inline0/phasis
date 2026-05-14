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

/**
 * Matcher trait part: MatcherSequence. Composed into Matcher via
 * `use Parts\MatcherSequence;`.
 */
trait MatcherSequence
{
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
        if (
            $term instanceof Group
            && (
                $term->body instanceof Quantified
                || $term->body instanceof Disjunction
                || $term->body instanceof Sequence
            )
        ) {
            // A capturing/non-capturing group whose body is variable-
            // length (quantifier, alternation, sub-sequence) needs to
            // participate in backtracking too — otherwise a lazy
            // quantifier inside a capturing group settles for the
            // shortest length and the rest of the sequence can never
            // ask it to extend.
            return $this->matchGroupInSequence(
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
     * @param list<Node> $terms
     * @param array<int, ?array{0:int,1:int}> $captures
     */
    private function matchGroupInSequence(
        Group $g,
        array $terms,
        int $idx,
        int $pos,
        array &$captures,
        int $direction,
    ): ?int {
        // Pass the body's own terms (or the body itself for non-Sequence
        // bodies) so the multi-end-position machinery sees each inner
        // term and can backtrack into shorter alternatives. Wrapping a
        // Sequence as `[$g->body]` would funnel it through the
        // single-end-position single-term path and lose backtracking
        // when the rest of the outer sequence rejects the greedy match.
        $bodyTerms = $g->body instanceof Sequence ? $g->body->terms : [$g->body];
        $savedAll = $captures;
        $result = $this->matchSequenceWithContinuation(
            $bodyTerms,
            $pos,
            $captures,
            $direction,
            function (int $end, array &$caps) use ($g, $terms, $idx, $direction, $pos): ?int {
                if ($g->isCapturing()) {
                    $lo = min($pos, $end);
                    $hi = max($pos, $end);
                    $caps[$g->index] = [$lo, $hi];
                }
                return $this->matchSequenceFrom($terms, $idx + 1, $end, $caps, $direction);
            },
        );
        if ($result === null) {
            $captures = $savedAll;
        }
        return $result;
    }

    /**
     * Match a sequence of terms, calling $cont for each successful
     * end position. Returns the first non-null result.
     *
     * @param list<Node> $terms
     * @param array<int, ?array{0:int,1:int}> $captures
     * @param \Closure(int, array<int, ?array{0:int,1:int}>): ?int $cont
     */
    private function matchSequenceWithContinuation(
        array $terms,
        int $pos,
        array &$captures,
        int $direction,
        \Closure $cont,
    ): ?int {
        return $this->matchSeqWithCont($terms, 0, $pos, $captures, $direction, $cont);
    }

    /**
     * @param list<Node> $terms
     * @param array<int, ?array{0:int,1:int}> $captures
     * @param \Closure(int, array<int, ?array{0:int,1:int}>): ?int $cont
     */
    private function matchSeqWithCont(
        array $terms,
        int $idx,
        int $pos,
        array &$captures,
        int $direction,
        \Closure $cont,
    ): ?int {
        if ($idx >= count($terms)) {
            return $cont($pos, $captures);
        }
        $term = $direction > 0 ? $terms[$idx] : $terms[count($terms) - 1 - $idx];
        if ($term instanceof Quantified) {
            $innerGroups = $this->collectGroupIndices($term->atom);
            // Lazy quantifier with a varying atom (e.g. `((.*\n?)*?)`)
            // explodes exponentially under enumerateQuantifierMulti
            // because the DFS materialises every reachable state
            // before any continuation is tried. Stream iter-by-iter
            // instead: try the rest of the sequence after each
            // additional iteration so the lazy semantic stops at the
            // first viable depth.
            if (!$term->greedy && $this->atomCanVary($term->atom)) {
                $rest = $this->matchLazyQuantifierStreaming(
                    $term->atom,
                    $term->min,
                    $term->max,
                    $innerGroups,
                    $pos,
                    $captures,
                    $direction,
                    function (int $end, array &$caps) use ($terms, $idx, $direction, $cont): ?int {
                        return $this->matchSeqWithCont($terms, $idx + 1, $end, $caps, $direction, $cont);
                    },
                );
                if ($rest !== null) {
                    return $rest;
                }
                return null;
            }
            $positions = [];
            $this->enumerateQuantifier(
                $term->atom,
                $term->min,
                $term->max,
                $innerGroups,
                $pos,
                $captures,
                $direction,
                iterCount: 0,
                positions: $positions,
            );
            $order = $term->greedy ? array_reverse($positions, true) : $positions;
            foreach ($order as $entry) {
                $captures = $entry[1];
                $rest = $this->matchSeqWithCont($terms, $idx + 1, $entry[0], $captures, $direction, $cont);
                if ($rest !== null) {
                    return $rest;
                }
            }
            return null;
        }
        if ($term instanceof Disjunction) {
            $saved = $captures;
            foreach ($term->alternatives as $alt) {
                $captures = $saved;
                // Alternatives with variable-length bodies need to be
                // routed through the multi-end-position enumerator so
                // the rest of the outer sequence can backtrack into a
                // shorter inner choice. matchNode would only yield one
                // (typically greedy-max) end position.
                $altTerms = $this->atomToSequenceTerms($alt);
                if ($altTerms !== null) {
                    $rest = $this->matchSeqWithCont(
                        $altTerms,
                        0,
                        $pos,
                        $captures,
                        $direction,
                        function (int $end, array &$caps) use ($terms, $idx, $direction, $cont): ?int {
                            return $this->matchSeqWithCont($terms, $idx + 1, $end, $caps, $direction, $cont);
                        },
                    );
                    if ($rest !== null) {
                        return $rest;
                    }
                    continue;
                }
                $end = $this->matchNode($alt, $pos, $captures, $direction);
                if ($end === null) {
                    continue;
                }
                $rest = $this->matchSeqWithCont($terms, $idx + 1, $end, $captures, $direction, $cont);
                if ($rest !== null) {
                    return $rest;
                }
            }
            $captures = $saved;
            return null;
        }
        if (
            $term instanceof Group
            && (
                $term->body instanceof Quantified
                || $term->body instanceof Disjunction
                || $term->body instanceof Sequence
            )
        ) {
            $startPos = $pos;
            // Unwrap a Sequence body so its terms participate directly
            // in the multi-end-position machinery instead of going
            // through the single-end-position single-term path.
            $bodyTerms = $term->body instanceof Sequence ? $term->body->terms : [$term->body];
            return $this->matchSeqWithCont(
                $bodyTerms,
                0,
                $pos,
                $captures,
                $direction,
                function (int $end, array &$caps) use ($term, $terms, $idx, $direction, $cont, $startPos): ?int {
                    if ($term->isCapturing()) {
                        $lo = min($startPos, $end);
                        $hi = max($startPos, $end);
                        $caps[$term->index] = [$lo, $hi];
                    }
                    return $this->matchSeqWithCont($terms, $idx + 1, $end, $caps, $direction, $cont);
                },
            );
        }
        $saved = $captures;
        $end = $this->matchNode($term, $pos, $captures, $direction);
        if ($end === null) {
            $captures = $saved;
            return null;
        }
        $rest = $this->matchSeqWithCont($terms, $idx + 1, $end, $captures, $direction, $cont);
        if ($rest === null) {
            $captures = $saved;
            return null;
        }
        return $rest;
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
}
