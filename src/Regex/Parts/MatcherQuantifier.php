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
 * Matcher trait part: MatcherQuantifier. Composed into Matcher via
 * `use Parts\MatcherQuantifier;`.
 */
trait MatcherQuantifier
{
    /**
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
        // Lazy quantifier with a varying atom needs streaming
        // iter-by-iter enumeration; otherwise enumerateQuantifierMulti
        // explodes for shapes like `((.*\n?)*?)<\/body>`.
        if (!$q->greedy && $this->atomCanVary($q->atom)) {
            $rest = $this->matchLazyQuantifierStreaming(
                $q->atom,
                $q->min,
                $q->max,
                $innerGroups,
                $pos,
                $captures,
                $direction,
                function (int $end, array &$caps) use ($terms, $idx, $direction): ?int {
                    return $this->matchSequenceFrom($terms, $idx + 1, $end, $caps, $direction);
                },
            );
            if ($rest !== null) {
                return $rest;
            }
            $captures = $savedAll;
            return null;
        }
        // Streaming fast path: when the atom is a plain CharClass with
        // no inner capture groups, the per-iteration captures snapshot
        // is the same on every position and the only thing that
        // changes is $pos. Walk to the greedy maximum first, then
        // back-track one step at a time trying the continuation. This
        // avoids materialising a 1.1M-entry positions array (one per
        // iteration) for patterns like `^\D+$` over the test262
        // CharacterClassEscapes corpus.
        if (
            ($q->atom instanceof CharClass || $q->atom instanceof \Phasis\Regex\Ast\Dot)
            && empty($innerGroups)
        ) {
            $cc = $q->atom instanceof CharClass
                ? $q->atom
                : ($this->dotAll ? CharClass::any() : CharClass::dotNoDotAll());
            $rest = $this->matchCharClassQuantifierStreaming(
                $cc,
                $q->min,
                $q->max,
                $q->greedy,
                $pos,
                $captures,
                $direction,
                $terms,
                $idx,
            );
            if ($rest !== null) {
                return $rest;
            }
            $captures = $savedAll;
            return null;
        }
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
     * Greedy / lazy CharClass quantifier driver that streams the
     * continuation instead of materialising a positions array. The
     * atom matches at most one input slot per iteration with no
     * capture-state side effects, so backtracking is just a position
     * decrement (greedy) or increment (lazy). Caller is
     * matchQuantifiedInSequence after it has confirmed the atom is
     * CharClass / Dot with no inner capture groups.
     *
     * @param list<Node> $terms
     * @param array<int, ?array{0:int,1:int}> $captures
     */
    private function matchCharClassQuantifierStreaming(
        CharClass $cc,
        int $min,
        ?int $max,
        bool $greedy,
        int $pos,
        array &$captures,
        int $direction,
        array $terms,
        int $idx,
    ): ?int {
        $startPos = $pos;
        $end = $direction > 0 ? $this->inputLen : 0;
        $maxOrSentinel = $max ?? PHP_INT_MAX;
        // Walk forward (or backward) until $max iterations or until
        // the class no longer matches. Track the iteration count so
        // we can compare against $min when trying continuations. The
        // tight-loop variants below inline the range check for common
        // shapes (`\D`, `\W`, `\S` after the build step rewrite into
        // negated single-range ASCII classes) so we don't pay the
        // method-dispatch + candidates-array cost on every byte.
        $ranges = $cc->ranges;
        $negated = $cc->negated;
        $rangeCount = count($ranges);
        $iter = 0;
        $cur = $pos;
        if (
            !$this->ignoreCase
            && $rangeCount === 1
            && $direction > 0
            && $cc->properties === []
        ) {
            // Single-range hot path. `\D` (negated [0-9]) and `\d`
            // (positive [0-9]) both fit. Walking 1.1M codepoints this
            // way is roughly 3x faster than the per-call helper.
            $lo = $ranges[0][0];
            $hi = $ranges[0][1];
            $input = $this->input;
            if ($negated) {
                while ($iter < $maxOrSentinel && $cur < $end) {
                    $cu = $input[$cur];
                    if ($cu >= $lo && $cu <= $hi) {
                        break;
                    }
                    $cur++;
                    $iter++;
                }
            } else {
                while ($iter < $maxOrSentinel && $cur < $end) {
                    $cu = $input[$cur];
                    if ($cu < $lo || $cu > $hi) {
                        break;
                    }
                    $cur++;
                    $iter++;
                }
            }
        } elseif (!$this->ignoreCase && $direction > 0 && $cc->properties === []) {
            // Multi-range hot path. Inline the range loop so we save
            // one method dispatch per input slot for `\W` (4 ranges)
            // and `\S` (10 ranges). The match condition has to test
            // every range until one hits, but the per-iteration
            // overhead of charClassMatchesCu (candidates array
            // alloc + nested foreach) is gone.
            $input = $this->input;
            while ($iter < $maxOrSentinel && $cur < $end) {
                $cu = $input[$cur];
                $hit = false;
                for ($ri = 0; $ri < $rangeCount; $ri++) {
                    $r = $ranges[$ri];
                    if ($cu >= $r[0] && $cu <= $r[1]) {
                        $hit = true;
                        break;
                    }
                }
                if ($negated ? $hit : !$hit) {
                    break;
                }
                $cur++;
                $iter++;
            }
        } else {
            while (true) {
                if ($iter >= $maxOrSentinel) {
                    break;
                }
                if ($direction > 0) {
                    if ($cur >= $end) {
                        break;
                    }
                    if (!$this->charClassMatchesCu($cc, $this->input[$cur])) {
                        break;
                    }
                    $cur++;
                } else {
                    if ($cur <= $end) {
                        break;
                    }
                    if (!$this->charClassMatchesCu($cc, $this->input[$cur - 1])) {
                        break;
                    }
                    $cur--;
                }
                $iter++;
            }
        }
        // $iter = greedy maximum; $cur = position after greedy walk.
        if ($greedy) {
            // Try the longest first, then peel back one iteration at
            // a time until $min. Each retry passes a fresh captures
            // snapshot — same as the positions-array path.
            for ($k = $iter; $k >= $min; $k--) {
                $tryPos = $direction > 0 ? $startPos + $k : $startPos - $k;
                $caps = $captures;
                $rest = $this->matchSequenceFrom($terms, $idx + 1, $tryPos, $caps, $direction);
                if ($rest !== null) {
                    $captures = $caps;
                    return $rest;
                }
            }
            return null;
        }
        // Lazy: try shortest first, walk up to greedy max.
        for ($k = $min; $k <= $iter; $k++) {
            $tryPos = $direction > 0 ? $startPos + $k : $startPos - $k;
            $caps = $captures;
            $rest = $this->matchSequenceFrom($terms, $idx + 1, $tryPos, $caps, $direction);
            if ($rest !== null) {
                $captures = $caps;
                return $rest;
            }
        }
        return null;
    }

    /**
     * Streaming lazy-quantifier driver. Tries the continuation $cont
     * after each iteration count starting from $min, stopping at the
     * first depth where the rest of the sequence accepts. This avoids
     * the exponential explosion of enumerateQuantifierMulti for
     * shapes like `((.*\n?)*?)<\/body>` where the lazy outer would
     * otherwise materialise every reachable [end, captures] state
     * before any continuation gets to fail.
     *
     * For each iteration count we still need to handle the inner
     * atom's own variability: when the inner atom is a Group whose
     * body matches at multiple lengths, we enumerate the inner ends
     * (shortest first, since we want the lazy outer's smallest total
     * match) and try the continuation at each. If none accept, add
     * one more outer iteration and recurse.
     *
     * @param list<int> $innerGroups
     * @param array<int, ?array{0:int,1:int}> $captures
     * @param \Closure(int, array<int, ?array{0:int,1:int}>): ?int $cont
     */
    private function matchLazyQuantifierStreaming(
        Node $atom,
        int $min,
        ?int $max,
        array $innerGroups,
        int $pos,
        array &$captures,
        int $direction,
        \Closure $cont,
    ): ?int {
        // First try with min iterations (which may be 0). We arrive
        // here with $iterCount=0, so for min>0 we accumulate the
        // minimum eagerly before any continuation attempt.
        return $this->lazyQuantifierStep(
            $atom,
            $min,
            $max,
            $innerGroups,
            $pos,
            $captures,
            $direction,
            iterCount: 0,
            cont: $cont,
        );
    }

    /**
     * @param list<int> $innerGroups
     * @param array<int, ?array{0:int,1:int}> $captures
     * @param \Closure(int, array<int, ?array{0:int,1:int}>): ?int $cont
     */
    private function lazyQuantifierStep(
        Node $atom,
        int $min,
        ?int $max,
        array $innerGroups,
        int $pos,
        array &$captures,
        int $direction,
        int $iterCount,
        \Closure $cont,
    ): ?int {
        // If we've satisfied min, try the continuation here first
        // (lazy semantics: prefer fewer iterations). Pass captures
        // through by reference so the cont's updates propagate up
        // when it succeeds.
        if ($iterCount >= $min) {
            $rest = $cont($pos, $captures);
            if ($rest !== null) {
                return $rest;
            }
        }
        // Hit the upper bound: cannot extend further.
        if ($max !== null && $iterCount >= $max) {
            return null;
        }
        // Add one more iteration. Reset inner-group captures per spec
        // RepeatMatcher (each iteration starts with fresh inner caps).
        $cleared = $captures;
        foreach ($innerGroups as $gi) {
            $cleared[$gi] = null;
        }
        // Enumerate the atom's reachable end positions from $pos in
        // body-preference order. enumerateAtomEnds drives the body
        // through matchSeqWithCont which already applies the body's
        // own greedy/lazy ordering: a greedy inner quantifier yields
        // longest-end first. The lazy outer wants the fewest
        // iterations, so we trust this body order at each iteration.
        $atomEnds = $this->enumerateAtomEnds($atom, $pos, $cleared, $direction);
        foreach ($atomEnds as [$newPos, $newCaps]) {
            if ($newPos === $pos) {
                // Zero-width iteration. Per RepeatMatcher this only
                // counts toward min; once min is satisfied another
                // zero-width attempt would loop forever. Below min,
                // count it but don't recurse on the same position.
                if ($iterCount < $min) {
                    $rest = $this->lazyQuantifierStep(
                        $atom,
                        $min,
                        $max,
                        $innerGroups,
                        $newPos,
                        $newCaps,
                        $direction,
                        $iterCount + 1,
                        $cont,
                    );
                    if ($rest !== null) {
                        $captures = $newCaps;
                        return $rest;
                    }
                }
                continue;
            }
            $rest = $this->lazyQuantifierStep(
                $atom,
                $min,
                $max,
                $innerGroups,
                $newPos,
                $newCaps,
                $direction,
                $iterCount + 1,
                $cont,
            );
            if ($rest !== null) {
                $captures = $newCaps;
                return $rest;
            }
        }
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
        // For atoms that can match at multiple lengths from a fixed start
        // (Group with a variable-length body, alternation), the iterative
        // loop below would only see the greedy-max inner match per outer
        // iteration. Switch to a depth-first variant that enumerates
        // every reachable [endPos, captures] pair so subsequent terms
        // (e.g. backreferences) can backtrack into a shorter inner match.
        if ($this->atomCanVary($atom)) {
            $startPos = $pos;
            $this->enumerateQuantifierMulti(
                $atom,
                $min,
                $max,
                $innerGroups,
                $pos,
                $captures,
                $direction,
                $iterCount,
                $positions,
            );
            // Caller (matchQuantifiedInSequence) array_reverses for
            // greedy and expects positions in ascending match-length
            // order. Sort by absolute distance from the iteration's
            // start position so reversal yields longest-first.
            usort($positions, function (array $a, array $b) use ($startPos, $direction): int {
                $da = $direction > 0 ? $a[0] - $startPos : $startPos - $a[0];
                $db = $direction > 0 ? $b[0] - $startPos : $startPos - $b[0];
                return $da <=> $db;
            });
            return;
        }
        // Hot path: when the atom is a plain CharClass (e.g. `\D`, `[a-z]`)
        // with no inner capture groups, every iteration consumes exactly
        // one input slot and leaves captures untouched. Walk the input
        // tightly without re-entering matchNode for each step. This
        // turns `^\D+$` against a 1M-codepoint input from a million
        // matchNode dispatches (each charging the step budget) into a
        // single linear scan. Required to keep approach 3 inside budget.
        if ($atom instanceof CharClass && empty($innerGroups)) {
            $this->enumerateCharClassQuantifier(
                $atom,
                $min,
                $max,
                $pos,
                $captures,
                $direction,
                $iterCount,
                $positions,
            );
            return;
        }
        // Iterative loop instead of recursion so a quantifier matching
        // 100k+ times (e.g. `.+` against a long input) does not blow
        // the PHP call stack.
        while (true) {
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
                // Zero-width iteration. Per ECMA-262 RepeatMatcher,
                // each iteration counts toward min, but once min
                // has been satisfied another zero-width attempt at
                // the same position would loop forever; that path
                // returns failure. Below min, count the iteration
                // and keep looping; at or above min, bail.
                if ($iterCount >= $min) {
                    return;
                }
                $iterCount++;
                continue;
            }
            $pos = $newPos;
            $iterCount++;
        }
    }

    /**
     * Tight CharClass quantifier scan. The atom matches at most one
     * input slot per iteration and never mutates captures, so we can
     * walk $this->input directly without re-dispatching through
     * matchNode (which would charge the step budget per slot).
     *
     * Mirrors the standard enumerateQuantifier loop semantics: emit
     * each reachable end-position once iterCount has met $min, stop
     * at $max or when the class no longer matches.
     *
     * @param array<int, ?array{0:int,1:int}> $captures
     * @param list<array{0:int,1:array<int, ?array{0:int,1:int}>}> $positions
     */
    private function enumerateCharClassQuantifier(
        CharClass $cc,
        int $min,
        ?int $max,
        int $pos,
        array $captures,
        int $direction,
        int $iterCount,
        array &$positions,
    ): void {
        $end = $direction > 0 ? $this->inputLen : 0;
        while (true) {
            if ($iterCount >= $min) {
                $positions[] = [$pos, $captures];
            }
            if ($max !== null && $iterCount >= $max) {
                return;
            }
            if ($direction > 0) {
                if ($pos >= $end) {
                    return;
                }
                $cu = $this->input[$pos];
                if (!$this->charClassMatchesCu($cc, $cu)) {
                    return;
                }
                $pos++;
            } else {
                if ($pos <= $end) {
                    return;
                }
                $cu = $this->input[$pos - 1];
                if (!$this->charClassMatchesCu($cc, $cu)) {
                    return;
                }
                $pos--;
            }
            $iterCount++;
        }
    }

    /**
     * Depth-first quantifier enumerator for atoms whose body can
     * match at multiple lengths from one start position. Pushes
     * every [endPos, captures] pair into $positions; caller is
     * expected to sort by length and apply greedy/lazy ordering.
     *
     * @param list<int> $innerGroups
     * @param array<int, ?array{0:int,1:int}> $captures
     * @param list<array{0:int,1:array<int, ?array{0:int,1:int}>}> $positions
     */
    private function enumerateQuantifierMulti(
        Node $atom,
        int $min,
        ?int $max,
        array $innerGroups,
        int $pos,
        array $captures,
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
        $cleared = $captures;
        foreach ($innerGroups as $gi) {
            $cleared[$gi] = null;
        }
        $atomEnds = $this->enumerateAtomEnds($atom, $pos, $cleared, $direction);
        foreach ($atomEnds as $entry) {
            [$newPos, $newCaps] = $entry;
            if ($newPos === $pos) {
                // Zero-width: only count toward min, never enumerate
                // further (would loop forever).
                if ($iterCount < $min) {
                    $this->enumerateQuantifierMulti(
                        $atom,
                        $min,
                        $max,
                        $innerGroups,
                        $newPos,
                        $newCaps,
                        $direction,
                        $iterCount + 1,
                        $positions,
                    );
                }
                continue;
            }
            $this->enumerateQuantifierMulti(
                $atom,
                $min,
                $max,
                $innerGroups,
                $newPos,
                $newCaps,
                $direction,
                $iterCount + 1,
                $positions,
            );
        }
    }

    /**
     * Convert an alt/atom into a list of sequence terms for the
     * multi-end-position machinery. Returns null when the node is a
     * single-end-position match (Literal, CharClass, Anchor, ...) where
     * the caller's matchNode path is fine.
     *
     * @return list<Node>|null
     */
    private function atomToSequenceTerms(Node $node): ?array
    {
        if ($node instanceof Sequence) {
            return $node->terms;
        }
        if ($node instanceof Group) {
            if (!$this->bodyCanVary($node->body)) {
                return null;
            }
            // Synthesise a one-term wrapper so the Group still gets
            // its capture set inside matchSeqWithCont's Group branch.
            return [$node];
        }
        if ($node instanceof Quantified || $node instanceof Disjunction) {
            return [$node];
        }
        return null;
    }

    /**
     * Whether an atom can match at multiple lengths from a single
     * start position (so its quantifier needs the full enumerator).
     */
    private function atomCanVary(Node $atom): bool
    {
        if ($atom instanceof Group) {
            return $this->bodyCanVary($atom->body);
        }
        if ($atom instanceof Disjunction) {
            return true;
        }
        if ($atom instanceof Sequence) {
            return $this->bodyCanVary($atom);
        }
        return false;
    }

    private function bodyCanVary(Node $body): bool
    {
        if ($body instanceof Quantified) {
            return $body->min !== $body->max;
        }
        if ($body instanceof Disjunction) {
            return true;
        }
        if ($body instanceof Sequence) {
            foreach ($body->terms as $term) {
                if ($term instanceof Quantified && $term->min !== $term->max) {
                    return true;
                }
                if ($term instanceof Disjunction) {
                    return true;
                }
                if ($term instanceof Group && $this->bodyCanVary($term->body)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Enumerate every reachable [endPos, captures] pair for matching
     * one occurrence of $atom from $pos. Variable-length atoms yield
     * multiple entries; fixed-length atoms yield zero or one entry.
     *
     * @param array<int, ?array{0:int,1:int}> $captures
     * @return list<array{0:int,1:array<int, ?array{0:int,1:int}>}>
     */
    private function enumerateAtomEnds(Node $atom, int $pos, array $captures, int $direction): array
    {
        if ($atom instanceof Group) {
            $results = [];
            $body = $atom->body;
            $bodyTerms = $body instanceof Sequence ? $body->terms : [$body];
            $caps = $captures;
            $this->matchSequenceWithContinuation(
                $bodyTerms,
                $pos,
                $caps,
                $direction,
                function (int $end, array &$innerCaps) use ($atom, $pos, &$results): ?int {
                    $snapshot = $innerCaps;
                    if ($atom->isCapturing()) {
                        $lo = min($pos, $end);
                        $hi = max($pos, $end);
                        $snapshot[$atom->index] = [$lo, $hi];
                    }
                    $results[] = [$end, $snapshot];
                    return null; // force backtracking to enumerate more
                },
            );
            return $results;
        }
        if ($atom instanceof Disjunction) {
            $results = [];
            foreach ($atom->alternatives as $alt) {
                $caps = $captures;
                $end = $this->matchNode($alt, $pos, $caps, $direction);
                if ($end !== null) {
                    $results[] = [$end, $caps];
                }
            }
            return $results;
        }
        if ($atom instanceof Sequence) {
            $results = [];
            $caps = $captures;
            $this->matchSequenceWithContinuation(
                $atom->terms,
                $pos,
                $caps,
                $direction,
                function (int $end, array &$innerCaps) use (&$results): ?int {
                    $results[] = [$end, $innerCaps];
                    return null;
                },
            );
            return $results;
        }
        $caps = $captures;
        $end = $this->matchNode($atom, $pos, $caps, $direction);
        return $end === null ? [] : [[$end, $caps]];
    }

    /**
     * @param array<int, ?array{0:int,1:int}> $captures
     */
    private function matchQuantified(Quantified $q, int $pos, array &$captures, int $direction): ?int
    {
        // Iterative quantifier: greedy goes as far as possible (then
        // returns the longest position), lazy returns the shortest
        // valid position. Per spec the captures of inner groups reset
        // on each iteration. The iterative form avoids deep recursion
        // for `.+` matching long inputs.
        $innerGroups = $this->collectGroupIndices($q->atom);
        if (!$q->greedy) {
            // Lazy: return as soon as min is satisfied.
            if ($q->min === 0) {
                return $pos;
            }
        }
        $iterCount = 0;
        $lastValid = $q->min === 0 ? $pos : null;
        while (true) {
            if ($q->max !== null && $iterCount >= $q->max) {
                break;
            }
            $saved = $captures;
            foreach ($innerGroups as $gi) {
                $captures[$gi] = null;
            }
            $newPos = $this->matchNode($q->atom, $pos, $captures, $direction);
            if ($newPos === null || $newPos === $pos) {
                $captures = $saved;
                break;
            }
            $pos = $newPos;
            $iterCount++;
            if ($iterCount >= $q->min) {
                $lastValid = $pos;
                if (!$q->greedy) {
                    return $pos;
                }
            }
        }
        return $lastValid;
    }
}
