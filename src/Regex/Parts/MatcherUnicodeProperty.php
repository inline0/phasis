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
use Phasis\Regex\Unicode16Tables;

/**
 * Matcher trait part: MatcherUnicodeProperty. Composed into Matcher via
 * `use Parts\MatcherUnicodeProperty;`.
 */
trait MatcherUnicodeProperty
{
    /**
     * Detect the `^\p{X}+$` / `^\P{X}+$` anchored-greedy unicode-property
     * shape and pre-resolve its bundled-table ranges so the sweep loop
     * has no AST or table lookup to do per codepoint.
     *
     * Returns null if the pattern is anything else (mixed terms,
     * /i case-folding, /m multiline, bundle miss). The full matcher
     * picks it up unchanged in that case.
     *
     * @return array{table: string, propertyNegated: bool, min: int, max: ?int}|null
     */
    private function detectAnchoredPropertyShape(): ?array
    {
        if ($this->ignoreCase) {
            // /i adds case-fold variants per codepoint; the streaming
            // path would need to call canonicalize per codepoint, at
            // which point the AST path is fine. The test262 corpus
            // uses /u not /ui for property-escapes anyway.
            return null;
        }
        $body = $this->pattern->body;
        if (!$body instanceof Sequence) {
            return null;
        }
        $terms = $body->terms;
        if (count($terms) !== 3) {
            return null;
        }
        [$head, $mid, $tail] = $terms;
        if (
            !$head instanceof Anchor
            || $head->kind !== Anchor::START
            || !$tail instanceof Anchor
            || $tail->kind !== Anchor::END
        ) {
            return null;
        }
        if (!$mid instanceof Quantified) {
            return null;
        }
        if (!$mid->greedy) {
            // Lazy `+?` against an all-match string still has to match
            // the whole input (lazy still needs to satisfy `$`), so
            // semantically the sweep is identical. Be conservative for
            // now and bail to the AST path.
            return null;
        }
        $atom = $mid->atom;
        if (!$atom instanceof \Phasis\Regex\Ast\UnicodeProperty) {
            return null;
        }
        $ranges = self::resolvePropertyRanges($atom->name, $atom->value);
        if ($ranges === null) {
            // Property isn't in the bundled tables; let the AST path
            // handle it via IntlChar/PCRE2 fallbacks.
            return null;
        }
        $key = $atom->value === null ? $atom->name : $atom->name . '=' . $atom->value;
        return [
            'table' => self::propertyMembershipTable($key, $ranges),
            'propertyNegated' => $atom->negated,
            'min' => $mid->min,
            'max' => $mid->max,
        ];
    }

    /**
     * Resolve and cache the [start,end] code-point ranges for a
     * bundled Unicode property. Returns null when the property isn't
     * bundled (caller falls back to the AST matcher which can route
     * through IntlChar / PCRE2 for unknown names).
     *
     * @return list<array{int,int}>|null
     */
    private static function resolvePropertyRanges(string $name, ?string $value): ?array
    {
        static $cache = [];
        $key = $value === null ? $name : $name . '=' . $value;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $ranges = Unicode16Tables::PROPERTIES[$key] ?? null;
        $cache[$key] = $ranges;
        return $ranges;
    }

    /**
     * Build (and cache) a 0x110000-byte membership table for a
     * bundled property. Each byte is "\1" if the codepoint is in
     * the property, "\0" otherwise. The whole table fits in 1.1MB
     * of contiguous string memory and turns the inner-loop lookup
     * from a log2(R)-step binary search into a single string-index
     * read. For properties like General_Category=Letter (R≈680)
     * that's the difference between a ~0.9s and a ~0.15s sweep on
     * a 1.1M-codepoint test262 input.
     *
     * @param list<array{int,int}> $ranges
     */
    private static function propertyMembershipTable(string $key, array $ranges): string
    {
        static $cache = [];
        if (isset($cache[$key])) {
            // Promote to MRU by re-inserting (preserves insertion order).
            $val = $cache[$key];
            unset($cache[$key]);
            $cache[$key] = $val;
            return $val;
        }
        // Single allocation of the 1.1MB buffer, then in-place byte
        // writes for each range. PHP keeps the string in a single
        // buffer after the initial `str_repeat`, so per-byte writes
        // do not reallocate.
        $table = str_repeat("\0", 0x110000);
        foreach ($ranges as $r) {
            $start = $r[0];
            $end = $r[1];
            for ($cp = $start; $cp <= $end; $cp++) {
                $table[$cp] = "\1";
            }
        }
        // Cap memory: each entry is 1.1MB so 32 entries ~= 35MB. The
        // test262 property-escape corpus uses ~6 unique keys per test
        // and the runner periodically calls gc_mem_caches; this LRU
        // cap simply prevents the cache from growing across hundreds
        // of distinct properties within a single PHP process.
        $cache[$key] = $table;
        if (count($cache) > 32) {
            // Drop the least-recently-used (head of the array).
            array_shift($cache);
        }
        return $table;
    }

    /**
     * Walk the UTF-8 input once, decoding each codepoint inline and
     * looking up its membership in a 0x110000-byte property table.
     * Returns true iff:
     *   - propertyNegated XOR (codepoint is in property) holds for
     *     every codepoint, and
     *   - the codepoint count satisfies the quantifier's min/max.
     *
     * Lone surrogates encoded as CESU-8 (0xED 0xA0-0xBF 0x80-0xBF) decode
     * to D800-DFFF code points so the property test still gets the right
     * value for the test262 surrogate ranges.
     *
     * The input is processed in ~64KB chunks via `unpack('C*', ...)`. The
     * single unpack per chunk amortizes far better than per-byte `ord()`
     * calls (each `ord()` is a PHP function-call frame); for the 4MB
     * test262 inputs this halves wall time versus the ord-loop form.
     */
    private static function sweepAnchoredProperty(
        string $input,
        string $table,
        bool $propertyNegated,
        int $min,
        ?int $max,
    ): bool {
        $len = strlen($input);
        $count = 0;
        $pos = 0;
        // Match byte literal: "\1" if testing membership, "\0" if
        // testing non-membership. The mismatch case is the early-out.
        $miss = $propertyNegated ? "\1" : "\0";
        while ($pos < $len) {
            // Find the end of a UTF-8-aligned chunk. The chunk size cap
            // (~64KB) keeps the per-chunk unpack array bounded so we
            // don't materialise a 1.1M-entry integer array all at once.
            $chunkEnd = $pos + 65536;
            if ($chunkEnd > $len) {
                $chunkEnd = $len;
            }
            // Back up the chunk boundary off any continuation byte so a
            // multibyte sequence isn't split between chunks. The lead
            // byte rule is: high two bits === 10 means continuation.
            while ($chunkEnd < $len && (ord($input[$chunkEnd]) & 0xC0) === 0x80) {
                $chunkEnd--;
            }
            $bytes = unpack('C*', substr($input, $pos, $chunkEnd - $pos));
            $bLen = count($bytes);
            $j = 1;
            while ($j <= $bLen) {
                $b = $bytes[$j];
                if ($b < 0x80) {
                    $cp = $b;
                    $j++;
                } elseif (($b & 0xE0) === 0xC0 && $j + 1 <= $bLen) {
                    $cp = (($b & 0x1F) << 6) | ($bytes[$j + 1] & 0x3F);
                    $j += 2;
                } elseif (($b & 0xF0) === 0xE0 && $j + 2 <= $bLen) {
                    $cp = (($b & 0x0F) << 12)
                        | (($bytes[$j + 1] & 0x3F) << 6)
                        | ($bytes[$j + 2] & 0x3F);
                    $j += 3;
                } elseif (($b & 0xF8) === 0xF0 && $j + 3 <= $bLen) {
                    $cp = (($b & 0x07) << 18)
                        | (($bytes[$j + 1] & 0x3F) << 12)
                        | (($bytes[$j + 2] & 0x3F) << 6)
                        | ($bytes[$j + 3] & 0x3F);
                    $j += 4;
                } else {
                    $cp = $b;
                    $j++;
                }
                // For \p{X}+$ every codepoint must be in the set; for
                // \P{X}+$ every codepoint must be OUT of the set. A
                // single mismatch means the anchored pattern can't
                // match: no backtracking helps because ^ pins us to
                // position 0.
                if ($table[$cp] === $miss) {
                    return false;
                }
                $count++;
                if ($max !== null && $count > $max) {
                    return false;
                }
            }
            $pos = $chunkEnd;
        }
        return $count >= $min;
    }

    private function matchUnicodeProperty(
        \Phasis\Regex\Ast\UnicodeProperty $node,
        int $pos,
        int $direction,
    ): ?int {
        if ($direction > 0) {
            if ($pos >= $this->inputLen) {
                return null;
            }
            $cu = $this->input[$pos];
            if ($this->testUnicodeProperty($node, $cu)) {
                return $pos + 1;
            }
            return null;
        }
        if ($pos <= 0) {
            return null;
        }
        $cu = $this->input[$pos - 1];
        return $this->testUnicodeProperty($node, $cu) ? $pos - 1 : null;
    }

    private function testUnicodeProperty(\Phasis\Regex\Ast\UnicodeProperty $node, int $cp): bool
    {
        // Build the case-fold variants of the candidate. Per spec,
        // a candidate matches a CharSet under /i iff any variant is
        // in the (canonicalised) set; equivalently for \P{X}, a
        // candidate matches iff any variant is NOT in X.
        $variants = [$cp];
        if ($this->ignoreCase) {
            $variants[] = $this->canonicalize($cp);
            if ($cp >= 0x41 && $cp <= 0x5A) {
                $variants[] = $cp + 0x20;
            } elseif ($cp >= 0x61 && $cp <= 0x7A) {
                $variants[] = $cp - 0x20;
            }
            if ($cp >= 0x80 && class_exists(\IntlChar::class)) {
                $variants[] = \IntlChar::toupper($cp);
                $variants[] = \IntlChar::tolower($cp);
            }
        }
        $variants = array_unique($variants);
        if ($node->negated) {
            foreach ($variants as $v) {
                if (!$this->lookupUnicodeProperty($node->name, $node->value, $v)) {
                    return true;
                }
            }
            return false;
        }
        foreach ($variants as $v) {
            if ($this->lookupUnicodeProperty($node->name, $node->value, $v)) {
                return true;
            }
        }
        return false;
    }

    private function lookupUnicodeProperty(string $name, ?string $value, int $cp): bool
    {
        // Unicode 16 bundled tables take precedence over host ICU
        // (which on Ubuntu CI is ICU 74 = Unicode 14, two versions
        // behind the test262 fixtures). Falls back to IntlChar/PCRE2
        // for properties not present in the bundle.
        $bundled = self::lookupBundledProperty($name, $value, $cp);
        if ($bundled !== null) {
            return $bundled;
        }

        if (!class_exists(\IntlChar::class)) {
            return false;
        }
        // No `=` form: name is either a binary property, a special
        // ECMA-only property (Any, ASCII, Assigned), or a
        // General_Category alias.
        if ($value === null) {
            $special = self::specialEcmaProperty($name, $cp);
            if ($special !== null) {
                return $special;
            }
            $bin = self::resolveBinaryProperty($name);
            if ($bin !== null) {
                return \IntlChar::hasBinaryProperty($cp, $bin);
            }
            $gc = self::resolveGeneralCategory($name);
            if ($gc !== null) {
                $cat = \IntlChar::charType($cp);
                return self::generalCategoryMatches($gc, $cat);
            }
            // PHP's IntlChar omits a handful of binary-property
            // constants even on modern ICU (e.g. PROPERTY_EMOJI*,
            // PROPERTY_EXTENDED_PICTOGRAPHIC). Fall back to a
            // single-codepoint PCRE2 probe for those — PCRE2's
            // built-in Unicode tables generally know these
            // properties even when our IntlChar wrapper does not.
            return self::pcreBinaryPropertyProbe($name, $cp);
        }
        // Property=Value form (Script, General_Category, etc.).
        if (in_array($name, ['gc', 'General_Category'], true)) {
            $gc = self::resolveGeneralCategory($value);
            return $gc !== null && self::generalCategoryMatches($gc, \IntlChar::charType($cp));
        }
        if (in_array($name, ['sc', 'Script'], true)) {
            $sc = \IntlChar::getPropertyValueEnum(\IntlChar::PROPERTY_SCRIPT, $value);
            if ($sc < 0) {
                return false;
            }
            return \IntlChar::getIntPropertyValue($cp, \IntlChar::PROPERTY_SCRIPT) === $sc;
        }
        if (in_array($name, ['scx', 'Script_Extensions'], true)) {
            return self::matchesScriptExtensions($value, $cp);
        }
        return false;
    }

    /**
     * Look up a property/value combination in the bundled Unicode 16
     * tables (src/Regex/Unicode16Tables.php). Returns true/false if
     * the property is in our bundle, or null if the property is
     * unknown (caller falls back to IntlChar/PCRE2).
     *
     * Aliases are resolved by IntlChar before the lookup so e.g.
     * \p{sc=Cyrl} hits the same row as \p{Script=Cyrillic}. When
     * IntlChar can't resolve an alias we still try the literal key
     * — most aliases either ARE the canonical name or fail through.
     *
     * Hot-path: property-escape negative tests sweep ~1.1M codepoints
     * for tiny matchSymbols sets. Resolve the property's ranges array
     * ONCE per (name, value) pair and inline the binary search so the
     * per-codepoint cost is two array accesses + comparison.
     */
    private static function lookupBundledProperty(string $name, ?string $value, int $cp): ?bool
    {
        static $rangesCache = [];
        $cacheKey = $value === null ? $name : $name . '=' . $value;
        if (!array_key_exists($cacheKey, $rangesCache)) {
            $key = self::bundledPropertyKey($name, $value);
            $rangesCache[$cacheKey] = Unicode16Tables::PROPERTIES[$key] ?? null;
        }
        $ranges = $rangesCache[$cacheKey];
        if ($ranges === null) {
            return null;
        }
        // Inline binary search — function-call overhead dominates the
        // bundled-table dispatch when nonMatchSymbols expands to ~1.1M
        // codepoints. Keep this loop body free of dynamic dispatch.
        $lo = 0;
        $hi = count($ranges) - 1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            $range = $ranges[$mid];
            if ($cp < $range[0]) {
                $hi = $mid - 1;
            } elseif ($cp > $range[1]) {
                $lo = $mid + 1;
            } else {
                return true;
            }
        }
        return false;
    }

    /**
     * Form the bundled-table key directly from ($name, $value). The
     * Unicode 16 table generator (bin/build-unicode16-tables) emits
     * an entry for every alias form a fixture exercises, so
     * "Script=Cyrl", "Script=Cyrillic", "sc=Cyrl", and "sc=Cyrillic"
     * all resolve to the same ranges without runtime IntlChar
     * alias-resolution. That matters: on hosts whose ICU lags
     * Unicode 16 (e.g. Ubuntu CI ICU 74), IntlChar cannot resolve
     * aliases for newly-added properties like Ol_Onal/Onao, and any
     * runtime canonicalization that depends on host ICU silently
     * misses the bundled row.
     */
    private static function bundledPropertyKey(string $name, ?string $value): string
    {
        return $value === null ? $name : $name . '=' . $value;
    }

    /**
     * ECMAScript-only "binary" property aliases that aren't backed by
     * a Unicode binary property: Any (every code point), ASCII (cp in
     * 0x0..0x7F), Assigned (general category != Cn). Returns null if
     * $name is not one of these.
     */
    private static function specialEcmaProperty(string $name, int $cp): ?bool
    {
        return match ($name) {
            'Any' => true,
            'ASCII' => $cp <= 0x7F,
            'Assigned' => \IntlChar::charType($cp) !== \IntlChar::CHAR_CATEGORY_UNASSIGNED,
            default => null,
        };
    }

    /**
     * Single-codepoint PCRE2 probe for a binary property that
     * IntlChar's wrapper does not expose (PROPERTY_EMOJI*,
     * PROPERTY_EXTENDED_PICTOGRAPHIC). Caches the compiled probe
     * pattern per property alias. Returns false if PCRE2 also
     * does not know the property (we already tried IntlChar; if
     * neither knows it, the property simply does not match).
     */
    private static function pcreBinaryPropertyProbe(string $name, int $cp): bool
    {
        if ($cp >= 0xD800 && $cp <= 0xDFFF) {
            // Surrogate code points aren't valid UTF-8 input to
            // PCRE2; treat them as not in any Unicode property.
            return false;
        }
        $utf8 = \IntlChar::chr($cp);
        if ($utf8 === '') {
            return false;
        }
        static $cache = [];
        if (!array_key_exists($name, $cache)) {
            $probe = sprintf('/\\p{%s}/u', preg_quote($name, '/'));
            $ok = @preg_match($probe, '') !== false;
            $cache[$name] = $ok
                ? sprintf('/^\\p{%s}$/u', preg_quote($name, '/'))
                : null;
        }
        $pattern = $cache[$name];
        if ($pattern === null) {
            return false;
        }
        return (bool) @preg_match($pattern, $utf8);
    }

    /**
     * Per-codepoint Script_Extensions check. IntlChar exposes Script
     * but no direct Script_Extensions accessor; PCRE2 does, so we
     * precompute the matching code-point ranges once via PCRE2
     * (single preg_match_all over a long synthetic input) and then
     * answer each per-codepoint query with a binary search in
     * O(log ranges). Without the precomputation the matcher would
     * do one PCRE2 round-trip per input codepoint, which scales
     * O(input length) and times out on the test262 generated
     * Script_Extensions sweeps.
     */
    private static function matchesScriptExtensions(string $value, int $cp): bool
    {
        if ($cp >= 0xD800 && $cp <= 0xDFFF) {
            return false;
        }
        $ranges = self::scriptExtensionsRanges($value);
        if ($ranges === null) {
            // PCRE2 rejected the value alias. Fall back to primary
            // Script enum so we still produce a sensible answer.
            $sc = \IntlChar::getPropertyValueEnum(\IntlChar::PROPERTY_SCRIPT, $value);
            if ($sc < 0) {
                return false;
            }
            return \IntlChar::getIntPropertyValue($cp, \IntlChar::PROPERTY_SCRIPT) === $sc;
        }
        return self::codePointInRanges($cp, $ranges);
    }

    /**
     * @return list<array{0:int,1:int}>|null
     */
    private static function scriptExtensionsRanges(string $value): ?array
    {
        static $cache = [];
        if (array_key_exists($value, $cache)) {
            return $cache[$value];
        }
        $probe = sprintf('/\\p{scx=%s}/u', preg_quote($value, '/'));
        if (@preg_match($probe, '') === false) {
            $cache[$value] = null;
            return null;
        }
        $cache[$value] = self::buildPropertyRanges('scx=' . $value);
        return $cache[$value];
    }

    /**
     * Build the sorted list of [start, end] code-point ranges that
     * match the given PCRE2 \p{...} expression body. Walks all
     * code points 0..0x10FFFF (skipping surrogates) once via
     * IntlChar::chr to build a UTF-8 representation, then runs a
     * single preg_match_all per Unicode plane, decoding each
     * matched substring back to its leading code point. This
     * costs one PCRE2 invocation per plane (3 invocations total)
     * instead of one per code point.
     *
     * @return list<array{0:int,1:int}>
     */
    private static function buildPropertyRanges(string $propertyBody): array
    {
        $pattern = sprintf('/\\p{%s}+/u', $propertyBody);
        $ranges = [];
        // Three blocks: BMP up to surrogates, BMP past surrogates, and
        // supplementary planes. Surrogates can never be in any Unicode
        // property and don't UTF-8 encode anyway.
        $blocks = [
            [0x0000, 0xD7FF],
            [0xE000, 0xFFFF],
            [0x10000, 0x10FFFF],
        ];
        foreach ($blocks as [$blockStart, $blockEnd]) {
            $buf = '';
            for ($cp = $blockStart; $cp <= $blockEnd; $cp++) {
                $u = \IntlChar::chr($cp);
                if ($u !== '') {
                    $buf .= $u;
                }
            }
            $matches = [];
            if (@preg_match_all($pattern, $buf, $matches, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }
            foreach ($matches[0] as [$matchStr, $byteOffset]) {
                // Decode the run of UTF-8 inside the match into a
                // start and end code point. Because the buffer was
                // built by encoding sequential integers, the
                // matched bytes always decode cleanly.
                $cps = self::utf8ToCodePoints($matchStr);
                if ($cps === []) {
                    continue;
                }
                $ranges[] = [$cps[0], $cps[count($cps) - 1]];
            }
        }
        return $ranges;
    }

    /**
     * Binary search a code-point in sorted disjoint ranges.
     *
     * @param list<array{0:int,1:int}> $ranges
     */
    private static function codePointInRanges(int $cp, array $ranges): bool
    {
        $lo = 0;
        $hi = count($ranges) - 1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            [$s, $e] = $ranges[$mid];
            if ($cp < $s) {
                $hi = $mid - 1;
            } elseif ($cp > $e) {
                $lo = $mid + 1;
            } else {
                return true;
            }
        }
        return false;
    }

    private static function resolveBinaryProperty(string $name): ?int
    {
        // Aliases for binary property names accepted by ECMAScript.
        // Mapped to the IntlChar::PROPERTY_* constant name; we use
        // constant() so ICU builds without newer property constants
        // (e.g. PROPERTY_EMOJI on older PHP) cleanly fall through.
        $aliasToConstant = [
            // ECMA-only ASCII / Any / Assigned are handled in
            // specialEcmaProperty(); ASCII is NOT a Unicode binary
            // property despite the alias key making it look like one.
            'ASCII_Hex_Digit' => 'PROPERTY_ASCII_HEX_DIGIT',
            'AHex' => 'PROPERTY_ASCII_HEX_DIGIT',
            'Alphabetic' => 'PROPERTY_ALPHABETIC',
            'Alpha' => 'PROPERTY_ALPHABETIC',
            'Bidi_Control' => 'PROPERTY_BIDI_CONTROL',
            'Bidi_C' => 'PROPERTY_BIDI_CONTROL',
            'Bidi_Mirrored' => 'PROPERTY_BIDI_MIRRORED',
            'Bidi_M' => 'PROPERTY_BIDI_MIRRORED',
            'Case_Ignorable' => 'PROPERTY_CASE_IGNORABLE',
            'CI' => 'PROPERTY_CASE_IGNORABLE',
            'Cased' => 'PROPERTY_CASED',
            'Changes_When_Casefolded' => 'PROPERTY_CHANGES_WHEN_CASEFOLDED',
            'CWCF' => 'PROPERTY_CHANGES_WHEN_CASEFOLDED',
            'Changes_When_Casemapped' => 'PROPERTY_CHANGES_WHEN_CASEMAPPED',
            'CWCM' => 'PROPERTY_CHANGES_WHEN_CASEMAPPED',
            'Changes_When_Lowercased' => 'PROPERTY_CHANGES_WHEN_LOWERCASED',
            'CWL' => 'PROPERTY_CHANGES_WHEN_LOWERCASED',
            'Changes_When_NFKC_Casefolded' => 'PROPERTY_CHANGES_WHEN_NFKC_CASEFOLDED',
            'CWKCF' => 'PROPERTY_CHANGES_WHEN_NFKC_CASEFOLDED',
            'Changes_When_Titlecased' => 'PROPERTY_CHANGES_WHEN_TITLECASED',
            'CWT' => 'PROPERTY_CHANGES_WHEN_TITLECASED',
            'Changes_When_Uppercased' => 'PROPERTY_CHANGES_WHEN_UPPERCASED',
            'CWU' => 'PROPERTY_CHANGES_WHEN_UPPERCASED',
            'Dash' => 'PROPERTY_DASH',
            'Default_Ignorable_Code_Point' => 'PROPERTY_DEFAULT_IGNORABLE_CODE_POINT',
            'DI' => 'PROPERTY_DEFAULT_IGNORABLE_CODE_POINT',
            'Deprecated' => 'PROPERTY_DEPRECATED',
            'Dep' => 'PROPERTY_DEPRECATED',
            'Diacritic' => 'PROPERTY_DIACRITIC',
            'Dia' => 'PROPERTY_DIACRITIC',
            'Emoji' => 'PROPERTY_EMOJI',
            'Emoji_Component' => 'PROPERTY_EMOJI_COMPONENT',
            'EComp' => 'PROPERTY_EMOJI_COMPONENT',
            'Emoji_Modifier' => 'PROPERTY_EMOJI_MODIFIER',
            'EMod' => 'PROPERTY_EMOJI_MODIFIER',
            'Emoji_Modifier_Base' => 'PROPERTY_EMOJI_MODIFIER_BASE',
            'EBase' => 'PROPERTY_EMOJI_MODIFIER_BASE',
            'Emoji_Presentation' => 'PROPERTY_EMOJI_PRESENTATION',
            'EPres' => 'PROPERTY_EMOJI_PRESENTATION',
            'Extended_Pictographic' => 'PROPERTY_EXTENDED_PICTOGRAPHIC',
            'ExtPict' => 'PROPERTY_EXTENDED_PICTOGRAPHIC',
            'Extender' => 'PROPERTY_EXTENDER',
            'Ext' => 'PROPERTY_EXTENDER',
            'Grapheme_Base' => 'PROPERTY_GRAPHEME_BASE',
            'Gr_Base' => 'PROPERTY_GRAPHEME_BASE',
            'Grapheme_Extend' => 'PROPERTY_GRAPHEME_EXTEND',
            'Gr_Ext' => 'PROPERTY_GRAPHEME_EXTEND',
            'Hex_Digit' => 'PROPERTY_HEX_DIGIT',
            'Hex' => 'PROPERTY_HEX_DIGIT',
            'IDS_Binary_Operator' => 'PROPERTY_IDS_BINARY_OPERATOR',
            'IDSB' => 'PROPERTY_IDS_BINARY_OPERATOR',
            'IDS_Trinary_Operator' => 'PROPERTY_IDS_TRINARY_OPERATOR',
            'IDST' => 'PROPERTY_IDS_TRINARY_OPERATOR',
            'ID_Continue' => 'PROPERTY_ID_CONTINUE',
            'IDC' => 'PROPERTY_ID_CONTINUE',
            'ID_Start' => 'PROPERTY_ID_START',
            'IDS' => 'PROPERTY_ID_START',
            'Ideographic' => 'PROPERTY_IDEOGRAPHIC',
            'Ideo' => 'PROPERTY_IDEOGRAPHIC',
            'Join_Control' => 'PROPERTY_JOIN_CONTROL',
            'Join_C' => 'PROPERTY_JOIN_CONTROL',
            'Logical_Order_Exception' => 'PROPERTY_LOGICAL_ORDER_EXCEPTION',
            'LOE' => 'PROPERTY_LOGICAL_ORDER_EXCEPTION',
            'Lowercase' => 'PROPERTY_LOWERCASE',
            'Lower' => 'PROPERTY_LOWERCASE',
            'Math' => 'PROPERTY_MATH',
            'Noncharacter_Code_Point' => 'PROPERTY_NONCHARACTER_CODE_POINT',
            'NChar' => 'PROPERTY_NONCHARACTER_CODE_POINT',
            'Pattern_Syntax' => 'PROPERTY_PATTERN_SYNTAX',
            'Pat_Syn' => 'PROPERTY_PATTERN_SYNTAX',
            'Pattern_White_Space' => 'PROPERTY_PATTERN_WHITE_SPACE',
            'Pat_WS' => 'PROPERTY_PATTERN_WHITE_SPACE',
            'Quotation_Mark' => 'PROPERTY_QUOTATION_MARK',
            'QMark' => 'PROPERTY_QUOTATION_MARK',
            'Radical' => 'PROPERTY_RADICAL',
            'Regional_Indicator' => 'PROPERTY_REGIONAL_INDICATOR',
            'RI' => 'PROPERTY_REGIONAL_INDICATOR',
            'Sentence_Terminal' => 'PROPERTY_S_TERM',
            'STerm' => 'PROPERTY_S_TERM',
            'Soft_Dotted' => 'PROPERTY_SOFT_DOTTED',
            'SD' => 'PROPERTY_SOFT_DOTTED',
            'Terminal_Punctuation' => 'PROPERTY_TERMINAL_PUNCTUATION',
            'Term' => 'PROPERTY_TERMINAL_PUNCTUATION',
            'Unified_Ideograph' => 'PROPERTY_UNIFIED_IDEOGRAPH',
            'UIdeo' => 'PROPERTY_UNIFIED_IDEOGRAPH',
            'Uppercase' => 'PROPERTY_UPPERCASE',
            'Upper' => 'PROPERTY_UPPERCASE',
            'Variation_Selector' => 'PROPERTY_VARIATION_SELECTOR',
            'VS' => 'PROPERTY_VARIATION_SELECTOR',
            'White_Space' => 'PROPERTY_WHITE_SPACE',
            'space' => 'PROPERTY_WHITE_SPACE',
            'WSpace' => 'PROPERTY_WHITE_SPACE',
            'XID_Continue' => 'PROPERTY_XID_CONTINUE',
            'XIDC' => 'PROPERTY_XID_CONTINUE',
            'XID_Start' => 'PROPERTY_XID_START',
            'XIDS' => 'PROPERTY_XID_START',
        ];
        if (!isset($aliasToConstant[$name])) {
            return null;
        }
        $const = '\\IntlChar::' . $aliasToConstant[$name];
        return defined($const) ? (int) constant($const) : null;
    }

    private static function resolveGeneralCategory(string $name): ?string
    {
        // ECMA aliases: "L" → "Letter", "Lu" → "Uppercase_Letter", etc.
        $aliases = [
            'C' => 'Other', 'Cc' => 'Control', 'Cf' => 'Format',
            'Cn' => 'Unassigned', 'Co' => 'Private_Use', 'Cs' => 'Surrogate',
            'L' => 'Letter', 'LC' => 'Cased_Letter', 'Ll' => 'Lowercase_Letter',
            'Lm' => 'Modifier_Letter', 'Lo' => 'Other_Letter',
            'Lt' => 'Titlecase_Letter', 'Lu' => 'Uppercase_Letter',
            'M' => 'Mark', 'Mc' => 'Spacing_Mark', 'Me' => 'Enclosing_Mark',
            'Mn' => 'Nonspacing_Mark',
            'N' => 'Number', 'Nd' => 'Decimal_Number',
            'Nl' => 'Letter_Number', 'No' => 'Other_Number',
            'P' => 'Punctuation', 'Pc' => 'Connector_Punctuation',
            'Pd' => 'Dash_Punctuation', 'Pe' => 'Close_Punctuation',
            'Pf' => 'Final_Punctuation', 'Pi' => 'Initial_Punctuation',
            'Po' => 'Other_Punctuation', 'Ps' => 'Open_Punctuation',
            'S' => 'Symbol', 'Sc' => 'Currency_Symbol',
            'Sk' => 'Modifier_Symbol', 'Sm' => 'Math_Symbol',
            'So' => 'Other_Symbol',
            'Z' => 'Separator', 'Zl' => 'Line_Separator',
            'Zp' => 'Paragraph_Separator', 'Zs' => 'Space_Separator',
            // POSIX-style legacy aliases accepted by ECMA's
            // CanonicalizeUnicodePropertyName for General_Category.
            'cntrl' => 'Control',
            'digit' => 'Decimal_Number',
            'punct' => 'Punctuation',
            // Unicode "loose" alias for Mark (Combining_Mark per
            // PropertyValueAliases.txt).
            'Combining_Mark' => 'Mark',
        ];
        if (isset($aliases[$name])) {
            // Translate the alias to its long form so subsequent
            // lookups (in generalCategoryMatches) can resolve it.
            return $aliases[$name];
        }
        // Allow long names too.
        return in_array(
            $name,
            [
                'Letter', 'Cased_Letter', 'Uppercase_Letter', 'Lowercase_Letter',
                'Titlecase_Letter', 'Modifier_Letter', 'Other_Letter',
                'Mark', 'Spacing_Mark', 'Enclosing_Mark', 'Nonspacing_Mark',
                'Number', 'Decimal_Number', 'Letter_Number', 'Other_Number',
                'Punctuation', 'Connector_Punctuation', 'Dash_Punctuation',
                'Close_Punctuation', 'Final_Punctuation', 'Initial_Punctuation',
                'Other_Punctuation', 'Open_Punctuation',
                'Symbol', 'Currency_Symbol', 'Modifier_Symbol',
                'Math_Symbol', 'Other_Symbol',
                'Separator', 'Line_Separator', 'Paragraph_Separator', 'Space_Separator',
                'Other', 'Control', 'Format', 'Unassigned', 'Private_Use', 'Surrogate',
            ],
            true,
        ) ? $name : null;
    }

    private static function generalCategoryMatches(string $gc, int $charType): bool
    {
        // IntlChar::CHAR_CATEGORY_* is exposed; map ECMA gc names to
        // its numeric values.
        $map = [
            'Uppercase_Letter' => \IntlChar::CHAR_CATEGORY_UPPERCASE_LETTER,
            'Lu' => \IntlChar::CHAR_CATEGORY_UPPERCASE_LETTER,
            'Lowercase_Letter' => \IntlChar::CHAR_CATEGORY_LOWERCASE_LETTER,
            'Ll' => \IntlChar::CHAR_CATEGORY_LOWERCASE_LETTER,
            'Titlecase_Letter' => \IntlChar::CHAR_CATEGORY_TITLECASE_LETTER,
            'Lt' => \IntlChar::CHAR_CATEGORY_TITLECASE_LETTER,
            'Modifier_Letter' => \IntlChar::CHAR_CATEGORY_MODIFIER_LETTER,
            'Lm' => \IntlChar::CHAR_CATEGORY_MODIFIER_LETTER,
            'Other_Letter' => \IntlChar::CHAR_CATEGORY_OTHER_LETTER,
            'Lo' => \IntlChar::CHAR_CATEGORY_OTHER_LETTER,
            'Nonspacing_Mark' => \IntlChar::CHAR_CATEGORY_NON_SPACING_MARK,
            'Mn' => \IntlChar::CHAR_CATEGORY_NON_SPACING_MARK,
            'Spacing_Mark' => \IntlChar::CHAR_CATEGORY_COMBINING_SPACING_MARK,
            'Mc' => \IntlChar::CHAR_CATEGORY_COMBINING_SPACING_MARK,
            'Enclosing_Mark' => \IntlChar::CHAR_CATEGORY_ENCLOSING_MARK,
            'Me' => \IntlChar::CHAR_CATEGORY_ENCLOSING_MARK,
            'Decimal_Number' => \IntlChar::CHAR_CATEGORY_DECIMAL_DIGIT_NUMBER,
            'Nd' => \IntlChar::CHAR_CATEGORY_DECIMAL_DIGIT_NUMBER,
            'Letter_Number' => \IntlChar::CHAR_CATEGORY_LETTER_NUMBER,
            'Nl' => \IntlChar::CHAR_CATEGORY_LETTER_NUMBER,
            'Other_Number' => \IntlChar::CHAR_CATEGORY_OTHER_NUMBER,
            'No' => \IntlChar::CHAR_CATEGORY_OTHER_NUMBER,
            'Space_Separator' => \IntlChar::CHAR_CATEGORY_SPACE_SEPARATOR,
            'Zs' => \IntlChar::CHAR_CATEGORY_SPACE_SEPARATOR,
            'Line_Separator' => \IntlChar::CHAR_CATEGORY_LINE_SEPARATOR,
            'Zl' => \IntlChar::CHAR_CATEGORY_LINE_SEPARATOR,
            'Paragraph_Separator' => \IntlChar::CHAR_CATEGORY_PARAGRAPH_SEPARATOR,
            'Zp' => \IntlChar::CHAR_CATEGORY_PARAGRAPH_SEPARATOR,
            'Control' => \IntlChar::CHAR_CATEGORY_CONTROL_CHAR,
            'Cc' => \IntlChar::CHAR_CATEGORY_CONTROL_CHAR,
            'Format' => \IntlChar::CHAR_CATEGORY_FORMAT_CHAR,
            'Cf' => \IntlChar::CHAR_CATEGORY_FORMAT_CHAR,
            'Surrogate' => \IntlChar::CHAR_CATEGORY_SURROGATE,
            'Cs' => \IntlChar::CHAR_CATEGORY_SURROGATE,
            'Private_Use' => \IntlChar::CHAR_CATEGORY_PRIVATE_USE_CHAR,
            'Co' => \IntlChar::CHAR_CATEGORY_PRIVATE_USE_CHAR,
            'Unassigned' => \IntlChar::CHAR_CATEGORY_UNASSIGNED,
            'Cn' => \IntlChar::CHAR_CATEGORY_UNASSIGNED,
            'Connector_Punctuation' => \IntlChar::CHAR_CATEGORY_CONNECTOR_PUNCTUATION,
            'Pc' => \IntlChar::CHAR_CATEGORY_CONNECTOR_PUNCTUATION,
            'Dash_Punctuation' => \IntlChar::CHAR_CATEGORY_DASH_PUNCTUATION,
            'Pd' => \IntlChar::CHAR_CATEGORY_DASH_PUNCTUATION,
            'Open_Punctuation' => \IntlChar::CHAR_CATEGORY_START_PUNCTUATION,
            'Ps' => \IntlChar::CHAR_CATEGORY_START_PUNCTUATION,
            'Close_Punctuation' => \IntlChar::CHAR_CATEGORY_END_PUNCTUATION,
            'Pe' => \IntlChar::CHAR_CATEGORY_END_PUNCTUATION,
            'Initial_Punctuation' => \IntlChar::CHAR_CATEGORY_INITIAL_PUNCTUATION,
            'Pi' => \IntlChar::CHAR_CATEGORY_INITIAL_PUNCTUATION,
            'Final_Punctuation' => \IntlChar::CHAR_CATEGORY_FINAL_PUNCTUATION,
            'Pf' => \IntlChar::CHAR_CATEGORY_FINAL_PUNCTUATION,
            'Other_Punctuation' => \IntlChar::CHAR_CATEGORY_OTHER_PUNCTUATION,
            'Po' => \IntlChar::CHAR_CATEGORY_OTHER_PUNCTUATION,
            'Math_Symbol' => \IntlChar::CHAR_CATEGORY_MATH_SYMBOL,
            'Sm' => \IntlChar::CHAR_CATEGORY_MATH_SYMBOL,
            'Currency_Symbol' => \IntlChar::CHAR_CATEGORY_CURRENCY_SYMBOL,
            'Sc' => \IntlChar::CHAR_CATEGORY_CURRENCY_SYMBOL,
            'Modifier_Symbol' => \IntlChar::CHAR_CATEGORY_MODIFIER_SYMBOL,
            'Sk' => \IntlChar::CHAR_CATEGORY_MODIFIER_SYMBOL,
            'Other_Symbol' => \IntlChar::CHAR_CATEGORY_OTHER_SYMBOL,
            'So' => \IntlChar::CHAR_CATEGORY_OTHER_SYMBOL,
        ];
        if (isset($map[$gc])) {
            return $charType === $map[$gc];
        }
        // Aggregate categories.
        return match ($gc) {
            'Letter', 'L' => in_array($charType, [
                \IntlChar::CHAR_CATEGORY_UPPERCASE_LETTER,
                \IntlChar::CHAR_CATEGORY_LOWERCASE_LETTER,
                \IntlChar::CHAR_CATEGORY_TITLECASE_LETTER,
                \IntlChar::CHAR_CATEGORY_MODIFIER_LETTER,
                \IntlChar::CHAR_CATEGORY_OTHER_LETTER,
            ], true),
            'Cased_Letter', 'LC' => in_array($charType, [
                \IntlChar::CHAR_CATEGORY_UPPERCASE_LETTER,
                \IntlChar::CHAR_CATEGORY_LOWERCASE_LETTER,
                \IntlChar::CHAR_CATEGORY_TITLECASE_LETTER,
            ], true),
            'Mark', 'M' => in_array($charType, [
                \IntlChar::CHAR_CATEGORY_NON_SPACING_MARK,
                \IntlChar::CHAR_CATEGORY_COMBINING_SPACING_MARK,
                \IntlChar::CHAR_CATEGORY_ENCLOSING_MARK,
            ], true),
            'Number', 'N' => in_array($charType, [
                \IntlChar::CHAR_CATEGORY_DECIMAL_DIGIT_NUMBER,
                \IntlChar::CHAR_CATEGORY_LETTER_NUMBER,
                \IntlChar::CHAR_CATEGORY_OTHER_NUMBER,
            ], true),
            'Punctuation', 'P' => in_array($charType, [
                \IntlChar::CHAR_CATEGORY_CONNECTOR_PUNCTUATION,
                \IntlChar::CHAR_CATEGORY_DASH_PUNCTUATION,
                \IntlChar::CHAR_CATEGORY_START_PUNCTUATION,
                \IntlChar::CHAR_CATEGORY_END_PUNCTUATION,
                \IntlChar::CHAR_CATEGORY_INITIAL_PUNCTUATION,
                \IntlChar::CHAR_CATEGORY_FINAL_PUNCTUATION,
                \IntlChar::CHAR_CATEGORY_OTHER_PUNCTUATION,
            ], true),
            'Symbol', 'S' => in_array($charType, [
                \IntlChar::CHAR_CATEGORY_MATH_SYMBOL,
                \IntlChar::CHAR_CATEGORY_CURRENCY_SYMBOL,
                \IntlChar::CHAR_CATEGORY_MODIFIER_SYMBOL,
                \IntlChar::CHAR_CATEGORY_OTHER_SYMBOL,
            ], true),
            'Separator', 'Z' => in_array($charType, [
                \IntlChar::CHAR_CATEGORY_SPACE_SEPARATOR,
                \IntlChar::CHAR_CATEGORY_LINE_SEPARATOR,
                \IntlChar::CHAR_CATEGORY_PARAGRAPH_SEPARATOR,
            ], true),
            'Other', 'C' => in_array($charType, [
                \IntlChar::CHAR_CATEGORY_CONTROL_CHAR,
                \IntlChar::CHAR_CATEGORY_FORMAT_CHAR,
                \IntlChar::CHAR_CATEGORY_SURROGATE,
                \IntlChar::CHAR_CATEGORY_PRIVATE_USE_CHAR,
                \IntlChar::CHAR_CATEGORY_UNASSIGNED,
            ], true),
            default => false,
        };
    }
}
