<?php

declare(strict_types=1);

namespace Phasis\Regex;

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
    use Parts\MatcherUnicodeProperty;
    use Parts\MatcherAtom;
    use Parts\MatcherQuantifier;
    use Parts\MatcherSequence;
    use Parts\MatcherUtf;

    /**
     * Input as array of code-units (uint16) in non-unicode mode, or code points in /u.
     *
     * @var list<int>
     */
    private array $input;
    private int $inputLen;
    private bool $ignoreCase;
    private bool $multiline;
    private bool $dotAll;
    private bool $unicode;
    private bool $sticky;

    /** Internal offset the current search began at; the `\G` anchor (Anchor::SCAN) matches only here. */
    private int $matchStart = 0;

    private Pattern $pattern;

    /**
     * Budget guard against catastrophic backtracking. Counts every
     * matchNode dispatch; once exhausted, throws
     * MatcherBudgetExceeded so the caller can fall back to PCRE2
     * instead of letting PHP's own execution-time limit kill the
     * whole chunk.
     */
    private int $stepBudget = 2_000_000;
    private int $stepsUsed = 0;

    /**
     * Resume-cache for internalIndexToUtf16. The matcher tends to call
     * the converter in monotonic order (capture[0] start then end, then
     * sub-captures left-to-right), so caching the previous (idx, cu)
     * lets us continue the walk from there in O(delta) instead of
     * O(idx). Cleared on every match()/matchTest() entry so a stale
     * walk from a prior input doesn't leak into the next.
     */
    private int $idxToCuCacheIdx = -1;
    private int $idxToCuCacheCu = 0;

    /**
     * Failure-memoization to defeat catastrophic backtracking. Keys are
     * "<nodeId>:<pos>:<dir>" for structural positions whose match (with their
     * AST-fixed continuation) has already failed this attempt. Sound only when
     * {@see $memoize} is set — i.e. the pattern has no backreferences and every
     * quantifier is `* + ?` — because then a position's failure is independent of
     * how it was reached and of capture state. Cleared per match()/matchTest().
     *
     * @var array<string, true>
     */
    private array $failMemo = [];
    private bool $memoize = false;

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
        $this->sticky = str_contains($flags, 'y');
        $this->memoize = self::memoizableNode($pattern->body);
    }

    /**
     * Whether failure-memoization is sound for this subtree: no backreferences
     * (capture-dependent matching) and no finite counted quantifier with max > 1
     * (whose body's continuation varies by iteration count). `* + ?` and
     * unbounded `{n,}` are fine — their continuation is structurally fixed.
     */
    private static function memoizableNode(Node $node): bool
    {
        if ($node instanceof Backreference) {
            return false;
        }
        if ($node instanceof Quantified) {
            if ($node->max !== null && $node->max > 1) {
                return false;
            }
            return self::memoizableNode($node->atom);
        }
        if ($node instanceof Sequence) {
            foreach ($node->terms as $term) {
                if (!self::memoizableNode($term)) {
                    return false;
                }
            }
            return true;
        }
        if ($node instanceof Disjunction) {
            foreach ($node->alternatives as $alt) {
                if (!self::memoizableNode($alt)) {
                    return false;
                }
            }
            return true;
        }
        if ($node instanceof Group || $node instanceof Lookaround || $node instanceof \Phasis\Regex\Ast\ModifierGroup) {
            return self::memoizableNode($node->body);
        }
        return true;
    }

    public function isMemoizing(): bool
    {
        return $this->memoize;
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
     *
     * @param bool $anchored Attempt the match only at $startCodeUnit (no forward
     *   scan) — used by the scanner to confirm a fast-path probe at its position.
     *
     * @return array{index: int, end: int, captures: list<?array{0:int,1:int,2:string}>}|null
     */
    public function match(string $inputUtf8, int $startCodeUnit, bool $anchored = false): ?array
    {
        $this->input = $this->unicode
            ? self::utf8ToCodePoints($inputUtf8)
            : self::utf8ToUtf16Units($inputUtf8);
        $this->inputLen = count($this->input);
        $this->stepsUsed = 0;
        $this->failMemo = [];
        $this->idxToCuCacheIdx = -1;
        $this->idxToCuCacheCu = 0;
        // In /u mode the caller hands us a UTF-16 code unit offset
        // (per spec 22.2.5.2.1 RegExpBuiltinExec step 6) but our
        // internal positions are codepoint indices. Convert here.
        // A UTF-16 index that lands inside a surrogate pair has no
        // codepoint anchor, so return null without attempting.
        $startInternal = $this->unicode
            ? $this->utf16IndexToInternal($startCodeUnit)
            : $startCodeUnit;
        if ($startInternal === null) {
            return null;
        }
        $this->matchStart = $startInternal;
        // Initialize capture array sized to groupCount + 1 (1-based).
        $captures = array_fill(0, $this->pattern->groupCount + 1, null);
        // Sticky (`y`): the match may begin only at the start offset; no forward
        // scan. This realises a leading-`\G` anchor (`PatternConverter` emits `y`).
        $limit = ($this->sticky || $anchored) ? $startInternal : $this->inputLen;
        for ($pos = $startInternal; $pos <= $limit; $pos++) {
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
     * Predicate variant of match() for `RegExp.prototype.test`. Returns
     * true on the first successful match without building the result
     * record (skips capture-slice extraction and per-group UTF-16
     * conversion). For inputs in the millions of code points (e.g.
     * test262's CharacterClassEscapes corpus) this is the difference
     * between two O(N) post-match walks and zero.
     */
    public function matchTest(string $inputUtf8, int $startCodeUnit): bool
    {
        // Vectorized fast path for `^\p{X}+$` / `^\P{X}+$` style
        // anchored-greedy unicode-property patterns. test262's property-
        // escape corpus sweeps these against ~1.1M-codepoint inputs and
        // the per-codepoint AST dispatch saturates the per-test wall
        // budget on slower CI runners. The fast path streams UTF-8
        // bytes through a single tight loop with an O(1) byte-table
        // membership lookup: no array materialisation, no method
        // dispatch per codepoint, no binary search.
        if ($startCodeUnit === 0 && $this->unicode && !$this->multiline) {
            $shape = $this->detectAnchoredPropertyShape();
            if ($shape !== null) {
                return self::sweepAnchoredProperty(
                    $inputUtf8,
                    $shape['table'],
                    $shape['propertyNegated'],
                    $shape['min'],
                    $shape['max'],
                );
            }
        }
        $this->input = $this->unicode
            ? self::utf8ToCodePoints($inputUtf8)
            : self::utf8ToUtf16Units($inputUtf8);
        $this->inputLen = count($this->input);
        $this->stepsUsed = 0;
        $this->failMemo = [];
        $this->idxToCuCacheIdx = -1;
        $this->idxToCuCacheCu = 0;
        $startInternal = $this->unicode
            ? $this->utf16IndexToInternal($startCodeUnit)
            : $startCodeUnit;
        if ($startInternal === null) {
            return false;
        }
        $this->matchStart = $startInternal;
        // Linear-scan fast path: body is a bare CharClass (`/\s/`,
        // `/\d/`, etc.) without case-folding. The outer
        // matchNode→matchCharClass→charClassMatchesCu chain dispatches
        // three times per input slot; inlining the per-CU check turns
        // a no-match scan over 1.1M codepoints from ~3M method calls
        // into a single tight while-loop.
        $body = $this->pattern->body;
        if ($body instanceof CharClass && !$this->ignoreCase && $body->properties === []) {
            $ranges = $body->ranges;
            $negated = $body->negated;
            $rc = count($ranges);
            $input = $this->input;
            $len = $this->sticky ? min($startInternal + 1, $this->inputLen) : $this->inputLen;
            for ($pos = $startInternal; $pos < $len; $pos++) {
                $cu = $input[$pos];
                $hit = false;
                for ($ri = 0; $ri < $rc; $ri++) {
                    $r = $ranges[$ri];
                    if ($cu >= $r[0] && $cu <= $r[1]) {
                        $hit = true;
                        break;
                    }
                }
                if ($negated ? !$hit : $hit) {
                    return true;
                }
            }
            return false;
        }
        $captures = array_fill(0, $this->pattern->groupCount + 1, null);
        $limit = $this->sticky ? $startInternal : $this->inputLen;
        for ($pos = $startInternal; $pos <= $limit; $pos++) {
            $caps = $captures;
            $end = $this->matchNode($this->pattern->body, $pos, $caps, /*direction=*/+1);
            if ($end !== null) {
                return true;
            }
        }
        return false;
    }
}
