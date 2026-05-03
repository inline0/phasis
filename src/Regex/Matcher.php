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
    private bool $dotAll;
    private bool $unicode;

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
        $this->stepsUsed = 0;
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
        // Initialize capture array sized to groupCount + 1 (1-based).
        $captures = array_fill(0, $this->pattern->groupCount + 1, null);
        for ($pos = $startInternal; $pos <= $this->inputLen; $pos++) {
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
     * Convert a UTF-16 code unit offset to the matcher's internal
     * codepoint index when /u is active. Returns null if the offset
     * lands inside a surrogate pair.
     */
    private function utf16IndexToInternal(int $cu): ?int
    {
        if (!$this->unicode) {
            return $cu;
        }
        if ($cu <= 0) {
            return 0;
        }
        $cuPos = 0;
        for ($cpIdx = 0; $cpIdx < $this->inputLen; $cpIdx++) {
            if ($cuPos === $cu) {
                return $cpIdx;
            }
            $cp = $this->input[$cpIdx];
            $width = $cp >= 0x10000 ? 2 : 1;
            if ($cuPos + $width > $cu) {
                return null; // mid-surrogate
            }
            $cuPos += $width;
        }
        if ($cu >= $cuPos) {
            return $this->inputLen;
        }
        return null;
    }

    /**
     * @param array<int, ?array{0:int,1:int}> $captures
     */
    private function buildResult(int $startCu, int $endCu, array $captures, string $inputUtf8): array
    {
        // In /u mode the matcher's internal indices count code
        // POINTS. Spec requires match.index and indices entries to
        // be UTF-16 code UNIT offsets — astrals span 2 units. In
        // non-/u mode the internal counter already matches code
        // units, so the conversion is a no-op.
        $out = [
            'index' => $this->internalIndexToUtf16($startCu),
            'end' => $this->internalIndexToUtf16($endCu),
            'captures' => [],
        ];
        for ($i = 0; $i <= $this->pattern->groupCount; $i++) {
            $cap = $captures[$i] ?? null;
            if ($cap === null) {
                $out['captures'][$i] = null;
                continue;
            }
            [$s, $e] = $cap;
            $out['captures'][$i] = [
                $this->internalIndexToUtf16($s),
                $this->internalIndexToUtf16($e),
                $this->sliceCapture($inputUtf8, $s, $e),
            ];
        }
        return $out;
    }

    /**
     * Convert an internal matcher index (code points in /u mode,
     * code units in non-/u mode) to a UTF-16 code unit offset for
     * the public match record.
     */
    private function internalIndexToUtf16(int $idx): int
    {
        if (!$this->unicode) {
            return $idx;
        }
        $out = 0;
        for ($i = 0; $i < $idx && $i < $this->inputLen; $i++) {
            $cp = $this->input[$i];
            $out += $cp >= 0x10000 ? 2 : 1;
        }
        return $out;
    }

    /**
     * Extract the matched slice from internal positions $s..$e. In
     * non-/u mode positions are UTF-16 code unit indices; if the
     * slice covers half of a surrogate pair we must emit just that
     * unit as CESU-8, not the whole UTF-8 codepoint, so the caller
     * sees the lone surrogate the matcher actually consumed (per
     * ECMA-262 22.2.2.1 PatternMatch on UTF-16 code units).
     */
    private function sliceCapture(string $inputUtf8, int $s, int $e): string
    {
        if ($this->unicode) {
            $byteStart = $this->codeUnitToByteOffset($inputUtf8, $s);
            $byteEnd = $this->codeUnitToByteOffset($inputUtf8, $e);
            return substr($inputUtf8, $byteStart, $byteEnd - $byteStart);
        }
        $out = '';
        $i = $s;
        $lim = min($e, $this->inputLen);
        while ($i < $lim) {
            $cu = $this->input[$i];
            // Adjacent valid surrogate pair: emit as a single UTF-8
            // 4-byte codepoint so byte-level string comparisons match
            // values built from `\u{1F438}` literals (stored as 4-byte
            // UTF-8). Without this merge, the captured slice would be
            // two CESU-8 3-byte sequences and `===` against the 4-byte
            // codepoint string fails by byte even though the UTF-16
            // code-unit sequences are identical.
            if (
                $cu >= 0xD800
                && $cu <= 0xDBFF
                && $i + 1 < $lim
                && $this->input[$i + 1] >= 0xDC00
                && $this->input[$i + 1] <= 0xDFFF
            ) {
                $cp = 0x10000 + (($cu - 0xD800) << 10) + ($this->input[$i + 1] - 0xDC00);
                $out .= chr(0xF0 | ($cp >> 18))
                    . chr(0x80 | (($cp >> 12) & 0x3F))
                    . chr(0x80 | (($cp >> 6) & 0x3F))
                    . chr(0x80 | ($cp & 0x3F));
                $i += 2;
                continue;
            }
            if ($cu < 0x80) {
                $out .= chr($cu);
            } elseif ($cu < 0x800) {
                $out .= chr(0xC0 | ($cu >> 6)) . chr(0x80 | ($cu & 0x3F));
            } else {
                $out .= chr(0xE0 | ($cu >> 12))
                    . chr(0x80 | (($cu >> 6) & 0x3F))
                    . chr(0x80 | ($cu & 0x3F));
            }
            $i++;
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
        if ($node instanceof \PhpJs\Regex\Ast\Dot) {
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
        if ($node instanceof \PhpJs\Regex\Ast\ModifierGroup) {
            return $this->matchModifierGroup($node, $pos, $captures, $direction);
        }
        if ($node instanceof \PhpJs\Regex\Ast\UnicodeProperty) {
            return $this->matchUnicodeProperty($node, $pos, $direction);
        }
        return null;
    }

    private function matchUnicodeProperty(
        \PhpJs\Regex\Ast\UnicodeProperty $node,
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

    private function testUnicodeProperty(\PhpJs\Regex\Ast\UnicodeProperty $node, int $cp): bool
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
        if (!class_exists(\IntlChar::class)) {
            return false;
        }
        // No `=` form: name is either a binary property or a
        // General_Category alias.
        if ($value === null) {
            $bin = self::resolveBinaryProperty($name);
            if ($bin !== null) {
                return \IntlChar::hasBinaryProperty($cp, $bin);
            }
            $gc = self::resolveGeneralCategory($name);
            if ($gc !== null) {
                $cat = \IntlChar::charType($cp);
                return self::generalCategoryMatches($gc, $cat);
            }
            return false;
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
            $sc = \IntlChar::getPropertyValueEnum(\IntlChar::PROPERTY_SCRIPT, $value);
            if ($sc < 0) {
                return false;
            }
            // Approximation via primary script — IntlChar lacks a
            // direct Script_Extensions accessor.
            return \IntlChar::getIntPropertyValue($cp, \IntlChar::PROPERTY_SCRIPT) === $sc;
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
            'ASCII' => 'PROPERTY_ASCII_HEX_DIGIT',
            'ASCII_Hex_Digit' => 'PROPERTY_ASCII_HEX_DIGIT',
            'Alphabetic' => 'PROPERTY_ALPHABETIC',
            'Alpha' => 'PROPERTY_ALPHABETIC',
            'Bidi_Control' => 'PROPERTY_BIDI_CONTROL',
            'Bidi_Mirrored' => 'PROPERTY_BIDI_MIRRORED',
            'Case_Ignorable' => 'PROPERTY_CASE_IGNORABLE',
            'Cased' => 'PROPERTY_CASED',
            'Changes_When_Casefolded' => 'PROPERTY_CHANGES_WHEN_CASEFOLDED',
            'Changes_When_Casemapped' => 'PROPERTY_CHANGES_WHEN_CASEMAPPED',
            'Changes_When_Lowercased' => 'PROPERTY_CHANGES_WHEN_LOWERCASED',
            'Changes_When_NFKC_Casefolded' => 'PROPERTY_CHANGES_WHEN_NFKC_CASEFOLDED',
            'Changes_When_Titlecased' => 'PROPERTY_CHANGES_WHEN_TITLECASED',
            'Changes_When_Uppercased' => 'PROPERTY_CHANGES_WHEN_UPPERCASED',
            'Dash' => 'PROPERTY_DASH',
            'Default_Ignorable_Code_Point' => 'PROPERTY_DEFAULT_IGNORABLE_CODE_POINT',
            'Deprecated' => 'PROPERTY_DEPRECATED',
            'Diacritic' => 'PROPERTY_DIACRITIC',
            'Emoji' => 'PROPERTY_EMOJI',
            'Emoji_Component' => 'PROPERTY_EMOJI_COMPONENT',
            'Emoji_Modifier' => 'PROPERTY_EMOJI_MODIFIER',
            'Emoji_Modifier_Base' => 'PROPERTY_EMOJI_MODIFIER_BASE',
            'Emoji_Presentation' => 'PROPERTY_EMOJI_PRESENTATION',
            'Extender' => 'PROPERTY_EXTENDER',
            'Grapheme_Base' => 'PROPERTY_GRAPHEME_BASE',
            'Grapheme_Extend' => 'PROPERTY_GRAPHEME_EXTEND',
            'Hex_Digit' => 'PROPERTY_HEX_DIGIT',
            'IDS_Binary_Operator' => 'PROPERTY_IDS_BINARY_OPERATOR',
            'IDS_Trinary_Operator' => 'PROPERTY_IDS_TRINARY_OPERATOR',
            'ID_Continue' => 'PROPERTY_ID_CONTINUE',
            'ID_Start' => 'PROPERTY_ID_START',
            'Ideographic' => 'PROPERTY_IDEOGRAPHIC',
            'Join_Control' => 'PROPERTY_JOIN_CONTROL',
            'Logical_Order_Exception' => 'PROPERTY_LOGICAL_ORDER_EXCEPTION',
            'Lowercase' => 'PROPERTY_LOWERCASE',
            'Math' => 'PROPERTY_MATH',
            'Noncharacter_Code_Point' => 'PROPERTY_NONCHARACTER_CODE_POINT',
            'Pattern_Syntax' => 'PROPERTY_PATTERN_SYNTAX',
            'Pattern_White_Space' => 'PROPERTY_PATTERN_WHITE_SPACE',
            'Quotation_Mark' => 'PROPERTY_QUOTATION_MARK',
            'Radical' => 'PROPERTY_RADICAL',
            'Regional_Indicator' => 'PROPERTY_REGIONAL_INDICATOR',
            'Sentence_Terminal' => 'PROPERTY_S_TERM',
            'Soft_Dotted' => 'PROPERTY_SOFT_DOTTED',
            'Terminal_Punctuation' => 'PROPERTY_TERMINAL_PUNCTUATION',
            'Unified_Ideograph' => 'PROPERTY_UNIFIED_IDEOGRAPH',
            'Uppercase' => 'PROPERTY_UPPERCASE',
            'Variation_Selector' => 'PROPERTY_VARIATION_SELECTOR',
            'White_Space' => 'PROPERTY_WHITE_SPACE',
            'XID_Continue' => 'PROPERTY_XID_CONTINUE',
            'XID_Start' => 'PROPERTY_XID_START',
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
        ];
        if (isset($aliases[$name])) {
            return $name;
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

    /**
     * Apply the inline modifier flags for the group's body, run the
     * body, then restore.
     *
     * @param array<int, ?array{0:int,1:int}> $captures
     */
    private function matchModifierGroup(
        \PhpJs\Regex\Ast\ModifierGroup $g,
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
                if ($ch === '') {
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
            if ($ch === '') {
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
        $candidates = [$cu];
        if ($this->ignoreCase) {
            // Spec CharacterSetMatcher canonicalizes both the candidate
            // and each set member. For ASCII letters we just check
            // both the case-shifted candidate and its Canonicalize
            // result against each range; that covers the bulk of
            // tests without needing an exhaustive fold expansion of
            // the range.
            $candidates[] = $this->canonicalize($cu);
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
