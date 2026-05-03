<?php

declare(strict_types=1);

namespace PhpJs\Regex;

use PhpJs\Exceptions\SyntaxError;
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
 * ECMAScript regex pattern parser. Builds an AST from the source
 * pattern (without delimiters / flags). The output is consumed by
 * the matcher which walks the tree against an input string.
 *
 * Implements the syntactic subset needed to fix the test262
 * regressions PCRE2 cannot match exactly:
 *
 *   - Variable-length lookbehinds with right-to-left capture order.
 *   - Capture group reset between iterations of a quantified group.
 *   - UTF-16 code-unit semantics in non-unicode mode.
 *
 * Out of scope (handled by PCRE2 still):
 *   - /v flag set operations and property-of-strings escapes.
 */
class Parser
{
    private string $src;
    private int $pos = 0;
    private int $len;
    private bool $unicode;
    private int $groupCount = 0;
    private int $totalGroups = 0;
    /**
     * Pending trail surrogate produced when readCodePoint decoded a
     * 4-byte UTF-8 SourceCharacter in non-/u mode. Per spec the
     * supplementary codepoint is two UTF-16 code units, so the next
     * parseAtom must yield the trail half before advancing further.
     * -1 = none pending.
     */
    private int $pendingTrailSurrogate = -1;
    /** @var array<int, string> */
    private array $indexToName = [];
    /** @var list<string> Ordered, distinct named groups. */
    private array $groupNames = [];
    /** @var array<string, true> */
    private array $seenNames = [];

    public function __construct(string $source, string $flags)
    {
        $this->src = $source;
        $this->len = strlen($source);
        $this->unicode = str_contains($flags, 'u') || str_contains($flags, 'v');
        $this->totalGroups = $this->countGroupsForward($source);
    }

    /**
     * Count the total number of capturing groups in the pattern in
     * advance. parseNumericBackref needs this so it can fall back to
     * an Annex B octal/identity escape when \N references a group that
     * does not exist anywhere in the pattern.
     */
    private function countGroupsForward(string $src): int
    {
        $count = 0;
        $len = strlen($src);
        $i = 0;
        $inClass = false;
        while ($i < $len) {
            $ch = $src[$i];
            if ($ch === '\\') {
                $i += 2;
                continue;
            }
            if (!$inClass && $ch === '[') {
                $inClass = true;
                $i++;
                continue;
            }
            if ($inClass && $ch === ']') {
                $inClass = false;
                $i++;
                continue;
            }
            if (!$inClass && $ch === '(' && ($i + 1 >= $len || $src[$i + 1] !== '?')) {
                $count++;
            } elseif (
                !$inClass
                && $ch === '('
                && $i + 2 < $len
                && $src[$i + 1] === '?'
                && $src[$i + 2] === '<'
                && ($i + 3 < $len && $src[$i + 3] !== '=' && $src[$i + 3] !== '!')
            ) {
                $count++;
            }
            $i++;
        }
        return $count;
    }

    public function parse(): Pattern
    {
        $body = $this->parseDisjunction();
        if ($this->pos !== $this->len) {
            throw new SyntaxError('Invalid regular expression: trailing input');
        }
        return new Pattern($body, $this->groupCount, $this->groupNames, $this->indexToName);
    }

    private function parseDisjunction(): Node
    {
        $alts = [$this->parseAlternative()];
        while ($this->pos < $this->len && $this->src[$this->pos] === '|') {
            $this->pos++;
            $alts[] = $this->parseAlternative();
        }
        if (count($alts) === 1) {
            return $alts[0];
        }
        return new Disjunction($alts);
    }

    private function parseAlternative(): Node
    {
        $terms = [];
        while ($this->pos < $this->len || $this->pendingTrailSurrogate !== -1) {
            if ($this->pendingTrailSurrogate === -1) {
                $ch = $this->src[$this->pos];
                if ($ch === '|' || $ch === ')') {
                    break;
                }
            }
            $terms[] = $this->parseTerm();
        }
        if (count($terms) === 1) {
            return $terms[0];
        }
        return new Sequence($terms);
    }

    private function parseTerm(): Node
    {
        $atom = $this->parseAtom();
        // When parseAtom split a non-/u astral SourceCharacter, the
        // trail half is held in $pendingTrailSurrogate and any
        // quantifier must attach to the trail (not this lead).
        if ($this->pendingTrailSurrogate !== -1) {
            return $atom;
        }
        if ($this->pos >= $this->len) {
            return $atom;
        }
        $ch = $this->src[$this->pos];
        if ($ch === '*' || $ch === '+' || $ch === '?' || $ch === '{') {
            return $this->wrapWithQuantifier($atom);
        }
        return $atom;
    }

    private function wrapWithQuantifier(Node $atom): Node
    {
        $ch = $this->src[$this->pos];
        if ($ch === '*') {
            $this->pos++;
            $min = 0;
            $max = null;
        } elseif ($ch === '+') {
            $this->pos++;
            $min = 1;
            $max = null;
        } elseif ($ch === '?') {
            $this->pos++;
            $min = 0;
            $max = 1;
        } else {
            // {n}, {n,}, {n,m}
            $start = $this->pos;
            $this->pos++; // consume {
            $minStr = '';
            while ($this->pos < $this->len && ctype_digit($this->src[$this->pos])) {
                $minStr .= $this->src[$this->pos++];
            }
            if ($minStr === '') {
                // Not a quantifier; rewind and treat `{` as literal.
                $this->pos = $start;
                return $atom;
            }
            $maxStr = null;
            $hasComma = false;
            if ($this->pos < $this->len && $this->src[$this->pos] === ',') {
                $hasComma = true;
                $this->pos++;
                $maxStr = '';
                while ($this->pos < $this->len && ctype_digit($this->src[$this->pos])) {
                    $maxStr .= $this->src[$this->pos++];
                }
            }
            if ($this->pos >= $this->len || $this->src[$this->pos] !== '}') {
                // Malformed quantifier; rewind.
                $this->pos = $start;
                return $atom;
            }
            $this->pos++; // consume }
            $min = (int) $minStr;
            if (!$hasComma) {
                $max = $min;
            } elseif ($maxStr === '') {
                $max = null;
            } else {
                $max = (int) $maxStr;
                if ($max < $min) {
                    throw new SyntaxError('Invalid regular expression: numbers out of order in {} quantifier');
                }
            }
        }
        $greedy = true;
        if ($this->pos < $this->len && $this->src[$this->pos] === '?') {
            $greedy = false;
            $this->pos++;
        }
        return new Quantified($atom, $min, $max, $greedy);
    }

    private function parseAtom(): Node
    {
        if ($this->pendingTrailSurrogate !== -1) {
            $cp = $this->pendingTrailSurrogate;
            $this->pendingTrailSurrogate = -1;
            return new Literal($cp);
        }
        $ch = $this->src[$this->pos];
        if ($ch === '.') {
            $this->pos++;
            return new \PhpJs\Regex\Ast\Dot();
        }
        if ($ch === '^') {
            $this->pos++;
            return new Anchor(Anchor::START);
        }
        if ($ch === '$') {
            $this->pos++;
            return new Anchor(Anchor::END);
        }
        if ($ch === '\\') {
            return $this->parseEscape();
        }
        if ($ch === '(') {
            return $this->parseGroup();
        }
        if ($ch === '[') {
            return $this->parseCharClass();
        }
        // Plain literal char (single byte for ASCII; multi-byte for UTF-8).
        return $this->parseLiteralChar();
    }

    private function parseLiteralChar(): Node
    {
        $cp = $this->readCodePoint();
        // Non-/u: a 4-byte UTF-8 SourceCharacter is a supplementary
        // codepoint that the spec treats as two UTF-16 code-unit
        // atoms. Split into a Literal(highSurrogate) plus a deferred
        // Literal(lowSurrogate) so a quantifier on the immediately-
        // following position attaches to the trail half only.
        if (!$this->unicode && $cp > 0xFFFF) {
            $cp -= 0x10000;
            $high = 0xD800 + ($cp >> 10);
            $low = 0xDC00 + ($cp & 0x3FF);
            $this->pendingTrailSurrogate = $low;
            return new Literal($high);
        }
        return new Literal($cp);
    }

    /** Read one code point at current position. Advances $pos past it. */
    private function readCodePoint(): int
    {
        $b = ord($this->src[$this->pos]);
        if ($b < 0x80) {
            $this->pos++;
            return $b;
        }
        if (($b & 0xE0) === 0xC0 && $this->pos + 1 < $this->len) {
            $b2 = ord($this->src[$this->pos + 1]);
            $cp = (($b & 0x1F) << 6) | ($b2 & 0x3F);
            $this->pos += 2;
            return $cp;
        }
        if (($b & 0xF0) === 0xE0 && $this->pos + 2 < $this->len) {
            $b2 = ord($this->src[$this->pos + 1]);
            $b3 = ord($this->src[$this->pos + 2]);
            $cp = (($b & 0x0F) << 12) | (($b2 & 0x3F) << 6) | ($b3 & 0x3F);
            $this->pos += 3;
            return $cp;
        }
        if (($b & 0xF8) === 0xF0 && $this->pos + 3 < $this->len) {
            $b2 = ord($this->src[$this->pos + 1]);
            $b3 = ord($this->src[$this->pos + 2]);
            $b4 = ord($this->src[$this->pos + 3]);
            $cp = (($b & 0x07) << 18) | (($b2 & 0x3F) << 12) | (($b3 & 0x3F) << 6) | ($b4 & 0x3F);
            $this->pos += 4;
            return $cp;
        }
        // Malformed UTF-8 byte; treat as single code unit.
        $this->pos++;
        return $b;
    }

    private function parseEscape(): Node
    {
        $this->pos++; // consume \
        if ($this->pos >= $this->len) {
            throw new SyntaxError('Invalid regular expression: \\ at end');
        }
        $ch = $this->src[$this->pos];
        switch ($ch) {
            case 'd':
                $this->pos++;
                return CharClass::digit();
            case 'D':
                $this->pos++;
                return CharClass::digit(true);
            case 'w':
                $this->pos++;
                return CharClass::word();
            case 'W':
                $this->pos++;
                return CharClass::word(true);
            case 's':
                $this->pos++;
                return CharClass::whitespace();
            case 'S':
                $this->pos++;
                return CharClass::whitespace(true);
            case 'b':
                $this->pos++;
                return new Anchor(Anchor::WORD_BOUNDARY);
            case 'B':
                $this->pos++;
                return new Anchor(Anchor::NON_WORD_BOUNDARY);
            case 'n':
                $this->pos++;
                return new Literal(0x0A);
            case 'r':
                $this->pos++;
                return new Literal(0x0D);
            case 't':
                $this->pos++;
                return new Literal(0x09);
            case 'v':
                $this->pos++;
                return new Literal(0x0B);
            case 'f':
                $this->pos++;
                return new Literal(0x0C);
            case '0':
                $this->pos++;
                return new Literal(0x00);
            case 'x':
                $this->pos++;
                if ($this->pos + 2 > $this->len) {
                    throw new SyntaxError('Invalid \\x escape');
                }
                $hex = substr($this->src, $this->pos, 2);
                if (!ctype_xdigit($hex)) {
                    throw new SyntaxError('Invalid \\x escape');
                }
                $this->pos += 2;
                return new Literal((int) hexdec($hex));
            case 'u':
                $this->pos++;
                return $this->parseUnicodeEscape();
            case 'k':
                return $this->parseNamedBackref();
            case 'p':
            case 'P':
                if ($this->unicode) {
                    return $this->parseUnicodePropertyEscape($ch === 'P');
                }
                break;
            case 'c':
                // \cX → control char (X mod 32) when X is A-Z/a-z.
                // In /u mode this is mandatory; in non-/u (Annex B
                // B.1.4) when the following char is not a control
                // letter the sequence is treated as literal `\c`,
                // i.e. emit backslash then c and let the next atom
                // pick up X.
                if ($this->pos + 1 < $this->len) {
                    $cl = $this->src[$this->pos + 1];
                    $isLetter = ($cl >= 'A' && $cl <= 'Z') || ($cl >= 'a' && $cl <= 'z');
                    if ($isLetter) {
                        $this->pos += 2;
                        return new Literal(ord($cl) & 0x1F);
                    }
                }
                if ($this->unicode) {
                    throw new SyntaxError('Invalid \\c escape');
                }
                // Non-/u: literal `\c`. We're currently positioned
                // at 'c' (the backslash was consumed); produce a
                // sequence of two literals.
                $this->pos++; // consume c
                return new Sequence([
                    new Literal(0x5C),
                    new Literal(0x63),
                ]);
        }
        if (ctype_digit($ch)) {
            return $this->parseNumericBackref();
        }
        // IdentityEscape: literal char.
        $cp = $this->readCodePoint();
        return new Literal($cp);
    }

    private function parseUnicodeEscape(): Node
    {
        // \uXXXX or (in /u mode) \u{XXXXXX}
        if ($this->pos < $this->len && $this->src[$this->pos] === '{' && $this->unicode) {
            $this->pos++;
            $hex = '';
            while ($this->pos < $this->len && $this->src[$this->pos] !== '}') {
                $hex .= $this->src[$this->pos++];
            }
            if ($this->pos >= $this->len || $this->src[$this->pos] !== '}') {
                throw new SyntaxError('Invalid \\u{...} escape');
            }
            $this->pos++; // consume }
            if ($hex === '' || !ctype_xdigit($hex)) {
                throw new SyntaxError('Invalid \\u{...} escape');
            }
            return new Literal((int) hexdec($hex));
        }
        if ($this->pos + 4 > $this->len) {
            throw new SyntaxError('Invalid \\u escape');
        }
        $hex = substr($this->src, $this->pos, 4);
        if (!ctype_xdigit($hex)) {
            throw new SyntaxError('Invalid \\u escape');
        }
        $this->pos += 4;
        $cp = (int) hexdec($hex);
        // /u mode: an adjacent `\uHHHH\uLLLL` lead+trail pair forms a
        // single supplementary codepoint atom. Without combining, the
        // pair parses as two separate atoms and a quantifier on the
        // trail half (`/🐸?/u`) only makes the trail
        // optional, breaking the spec rule that the surrogate pair is
        // one Atom.
        if (
            $this->unicode
            && $cp >= 0xD800
            && $cp <= 0xDBFF
            && $this->pos + 5 < $this->len
            && $this->src[$this->pos] === '\\'
            && $this->src[$this->pos + 1] === 'u'
            && $this->src[$this->pos + 2] !== '{'
        ) {
            $loHex = substr($this->src, $this->pos + 2, 4);
            if (ctype_xdigit($loHex)) {
                $lo = (int) hexdec($loHex);
                if ($lo >= 0xDC00 && $lo <= 0xDFFF) {
                    $this->pos += 6;
                    $cp = 0x10000 + (($cp - 0xD800) << 10) + ($lo - 0xDC00);
                }
            }
        }
        return new Literal($cp);
    }

    private function parseNumericBackref(): Node
    {
        $startPos = $this->pos;
        $num = '';
        while ($this->pos < $this->len && ctype_digit($this->src[$this->pos])) {
            $num .= $this->src[$this->pos++];
        }
        $refNum = (int) $num;
        // In non-/u mode, Annex B B.1.4.1.1 says \N where N is not a
        // valid backreference (no group N exists anywhere in the
        // pattern) is treated as a LegacyOctalEscapeSequence (digits
        // 0-7) or an identity escape (digits 8-9). The custom matcher
        // would otherwise treat it as a backreference to an
        // unparticipated group and match the empty string, which
        // diverges from V8/SpiderMonkey for /\b(\w+) \2\b/.
        if (!$this->unicode && $refNum > $this->totalGroups) {
            // Re-walk the digits as octal/identity. parseAtom already
            // consumed the leading digit; restart at $startPos.
            $this->pos = $startPos;
            $first = $this->src[$startPos];
            if ($first >= '0' && $first <= '7') {
                $maxLen = ($first >= '4' && $first <= '7') ? 2 : 3;
                $oct = '';
                while (
                    $this->pos < $this->len
                    && $this->src[$this->pos] >= '0'
                    && $this->src[$this->pos] <= '7'
                    && strlen($oct) < $maxLen
                ) {
                    $oct .= $this->src[$this->pos++];
                }
                return new Literal((int) octdec($oct));
            }
            // \8 or \9: identity escape.
            $this->pos++;
            return new Literal(ord($first));
        }
        return new Backreference($refNum, null);
    }

    private function parseUnicodePropertyEscape(bool $negated): Node
    {
        $this->pos++; // consume p / P
        if ($this->pos >= $this->len || $this->src[$this->pos] !== '{') {
            throw new SyntaxError('Invalid \\p escape');
        }
        $this->pos++; // consume {
        $body = '';
        while ($this->pos < $this->len && $this->src[$this->pos] !== '}') {
            $body .= $this->src[$this->pos++];
        }
        if ($this->pos >= $this->len) {
            throw new SyntaxError('Unterminated \\p escape');
        }
        $this->pos++; // consume }
        if ($body === '') {
            throw new SyntaxError('Empty \\p escape');
        }
        if (str_contains($body, '=')) {
            [$name, $value] = explode('=', $body, 2);
            return new \PhpJs\Regex\Ast\UnicodeProperty($name, $value, $negated);
        }
        return new \PhpJs\Regex\Ast\UnicodeProperty($body, null, $negated);
    }

    private function parseNamedBackref(): Node
    {
        $this->pos++; // consume k
        if ($this->pos >= $this->len || $this->src[$this->pos] !== '<') {
            throw new SyntaxError('Invalid \\k named backreference');
        }
        $this->pos++;
        $name = $this->readGroupName();
        if ($this->pos >= $this->len || $this->src[$this->pos] !== '>') {
            throw new SyntaxError('Invalid \\k named backreference');
        }
        $this->pos++;
        return new Backreference(null, $name);
    }

    /**
     * Read a group-name token, decoding \\uXXXX / \\u{XXXX} escapes
     * to their UTF-8 representation. Per ECMA-262 group names follow
     * IdentifierName grammar where unicode escapes encode the same
     * code point as the literal character — `(?<a\\u{62}>...)` and
     * `(?<ab>...)` must produce the same group name so the result
     * object's accessor works either way.
     */
    private function readGroupName(): string
    {
        $name = '';
        $pendingHigh = -1; // pending lead surrogate awaiting trail (-1 = none)
        while ($this->pos < $this->len && $this->src[$this->pos] !== '>') {
            $ch = $this->src[$this->pos];
            if ($ch === '\\' && $this->pos + 1 < $this->len && $this->src[$this->pos + 1] === 'u') {
                $this->pos += 2;
                if ($this->pos < $this->len && $this->src[$this->pos] === '{') {
                    $this->pos++;
                    $hex = '';
                    while ($this->pos < $this->len && $this->src[$this->pos] !== '}') {
                        $hex .= $this->src[$this->pos++];
                    }
                    if ($this->pos < $this->len && $this->src[$this->pos] === '}') {
                        $this->pos++;
                    }
                } else {
                    if ($this->pos + 4 > $this->len) {
                        throw new SyntaxError('Invalid \\u escape in group name');
                    }
                    $hex = substr($this->src, $this->pos, 4);
                    $this->pos += 4;
                }
                if ($hex === '' || !ctype_xdigit($hex)) {
                    throw new SyntaxError('Invalid \\u escape in group name');
                }
                $cp = (int) hexdec($hex);
                // Combine adjacent surrogate-pair escapes into the
                // astral codepoint they encode. The RegExpIdentifierName
                // grammar identifies the name by codepoints (not
                // UTF-16 code units), so the same name must result
                // whether written as `\\uHHHH\\uLLLL`, `\\u{XXXXX}`,
                // or the raw codepoint character. This applies in
                // both /u and non-/u modes — group identifiers are
                // codepoints either way.
                if ($pendingHigh >= 0 && $cp >= 0xDC00 && $cp <= 0xDFFF) {
                    $cp = 0x10000 + (($pendingHigh - 0xD800) << 10) + ($cp - 0xDC00);
                    $pendingHigh = -1;
                    $name .= mb_chr($cp, 'UTF-8') ?: '';
                    continue;
                }
                if ($pendingHigh >= 0) {
                    $name .= mb_chr($pendingHigh, 'UTF-8') ?: '';
                    $pendingHigh = -1;
                }
                if ($cp >= 0xD800 && $cp <= 0xDBFF) {
                    $pendingHigh = $cp;
                    continue;
                }
                $name .= mb_chr($cp, 'UTF-8') ?: '';
                continue;
            }
            if ($pendingHigh >= 0) {
                $name .= mb_chr($pendingHigh, 'UTF-8') ?: '';
                $pendingHigh = -1;
            }
            $name .= $ch;
            $this->pos++;
        }
        if ($pendingHigh >= 0) {
            $name .= mb_chr($pendingHigh, 'UTF-8') ?: '';
        }
        return $name;
    }

    private function parseGroup(): Node
    {
        $this->pos++; // consume (
        // (?:...) non-capturing
        // (?=...), (?!...) lookahead
        // (?<=...), (?<!...) lookbehind
        // (?<name>...) named capturing
        if ($this->pos + 1 < $this->len && $this->src[$this->pos] === '?') {
            $next = $this->src[$this->pos + 1];
            if ($next === ':') {
                $this->pos += 2;
                $body = $this->parseDisjunction();
                $this->expect(')');
                return new Group($body, -1, null);
            }
            if ($next === '=' || $next === '!') {
                $this->pos += 2;
                $body = $this->parseDisjunction();
                $this->expect(')');
                return new Lookaround($body, behind: false, negative: $next === '!');
            }
            if ($next === '<' && $this->pos + 2 < $this->len) {
                $third = $this->src[$this->pos + 2];
                if ($third === '=' || $third === '!') {
                    $this->pos += 3;
                    $body = $this->parseDisjunction();
                    $this->expect(')');
                    return new Lookaround($body, behind: true, negative: $third === '!');
                }
                // Named capturing: (?<name>...)
                $this->pos += 2;
                $name = $this->readGroupName();
                if ($this->pos >= $this->len || $this->src[$this->pos] !== '>') {
                    throw new SyntaxError('Invalid named group');
                }
                $this->pos++; // consume >
                $idx = ++$this->groupCount;
                $this->indexToName[$idx] = $name;
                if (!isset($this->seenNames[$name])) {
                    $this->seenNames[$name] = true;
                    $this->groupNames[] = $name;
                }
                $body = $this->parseDisjunction();
                $this->expect(')');
                return new Group($body, $idx, $name);
            }
            // Inline modifier (?ims:...) / (?ims-:...) / (?-ims:...).
            // Parse the flag overrides and emit a ModifierGroup node
            // so the matcher can apply them during the body's match.
            $j = $this->pos + 1;
            $addI = $addM = $addS = false;
            $remI = $remM = $remS = false;
            $sawAdd = false;
            while ($j < $this->len) {
                $c = $this->src[$j];
                if ($c === 'i') {
                    $addI = true;
                    $sawAdd = true;
                } elseif ($c === 'm') {
                    $addM = true;
                    $sawAdd = true;
                } elseif ($c === 's') {
                    $addS = true;
                    $sawAdd = true;
                } else {
                    break;
                }
                $j++;
            }
            $sawSub = false;
            if ($j < $this->len && $this->src[$j] === '-') {
                $j++;
                while ($j < $this->len) {
                    $c = $this->src[$j];
                    if ($c === 'i') {
                        $remI = true;
                        $sawSub = true;
                    } elseif ($c === 'm') {
                        $remM = true;
                        $sawSub = true;
                    } elseif ($c === 's') {
                        $remS = true;
                        $sawSub = true;
                    } else {
                        break;
                    }
                    $j++;
                }
            }
            if (($sawAdd || $sawSub) && $j < $this->len && $this->src[$j] === ':') {
                $this->pos = $j + 1;
                $body = $this->parseDisjunction();
                $this->expect(')');
                return new \PhpJs\Regex\Ast\ModifierGroup(
                    $body,
                    $addI,
                    $addM,
                    $addS,
                    $remI,
                    $remM,
                    $remS,
                );
            }
        }
        $idx = ++$this->groupCount;
        $body = $this->parseDisjunction();
        $this->expect(')');
        return new Group($body, $idx, null);
    }

    private function expect(string $ch): void
    {
        if ($this->pos >= $this->len || $this->src[$this->pos] !== $ch) {
            throw new SyntaxError("Invalid regular expression: expected '{$ch}'");
        }
        $this->pos++;
    }

    private function parseCharClass(): Node
    {
        $this->pos++; // consume [
        $negated = false;
        if ($this->pos < $this->len && $this->src[$this->pos] === '^') {
            $negated = true;
            $this->pos++;
        }
        $ranges = [];
        $negatedRanges = []; // For unioning negative escapes.
        while ($this->pos < $this->len && $this->src[$this->pos] !== ']') {
            $first = $this->parseClassAtom($negatedRanges);
            // /u mode: an adjacent high+low surrogate pair encodes
            // a single astral codepoint (UTF16Decode in spec terms).
            // Combine them so [\\uD834\\uDF06]/u matches U+1D306.
            if (
                $this->unicode
                && $first !== null
                && $first >= 0xD800 && $first <= 0xDBFF
                && $this->pos < $this->len
                && $this->src[$this->pos] !== ']'
                && $this->src[$this->pos] !== '-'
            ) {
                $savePos = $this->pos;
                $second = $this->parseClassAtom($negatedRanges);
                if ($second !== null && $second >= 0xDC00 && $second <= 0xDFFF) {
                    $first = 0x10000 + (($first - 0xD800) << 10) + ($second - 0xDC00);
                } else {
                    // Not a pair; rewind so the next iteration sees
                    // the second atom as a standalone class member.
                    $this->pos = $savePos;
                }
            }
            // Range: a-b.
            if (
                $first !== null
                && $this->pos + 1 < $this->len
                && $this->src[$this->pos] === '-'
                && $this->src[$this->pos + 1] !== ']'
            ) {
                $this->pos++; // consume -
                $second = $this->parseClassAtom($negatedRanges);
                if ($second !== null) {
                    // Per ECMA-262 §22.2.1.6, the first endpoint
                    // must be <= the second; otherwise SyntaxError.
                    if ($first > $second) {
                        throw new SyntaxError(
                            'Invalid regular expression: range out of order in character class'
                        );
                    }
                    $ranges[] = [$first, $second];
                } else {
                    $ranges[] = [$first, $first];
                    $ranges[] = [0x2D, 0x2D]; // literal -
                }
            } elseif ($first !== null) {
                if (!$this->unicode && $first > 0xFFFF) {
                    // Non-/u: a raw astral SourceCharacter inside a
                    // class is two UTF-16 code-unit atoms per spec.
                    // Split into lead/trail so each surrogate is its
                    // own class member.
                    $cp = $first - 0x10000;
                    $high = 0xD800 + ($cp >> 10);
                    $low = 0xDC00 + ($cp & 0x3FF);
                    $ranges[] = [$high, $high];
                    $ranges[] = [$low, $low];
                } else {
                    $ranges[] = [$first, $first];
                }
            }
        }
        if ($this->pos >= $this->len) {
            throw new SyntaxError('Invalid char class: unterminated');
        }
        $this->pos++; // consume ]
        // If we collected negative escapes (\D, \W, \S), they expand
        // to all-but-X. Inside a char class, that means we should
        // union with their inverse. The simplest correct behavior is
        // to fall back to an "any except X" check. For basic
        // correctness, just merge them into the base ranges as wide
        // ranges and rely on negation.
        foreach ($negatedRanges as $r) {
            $ranges[] = $r;
        }
        return new CharClass($ranges, $negated);
    }

    /**
     * Parse one atom inside a char class. Returns the integer code
     * point for a single-char atom, or null when the atom expanded to
     * multiple ranges (already merged into $ranges by the caller).
     *
     * @param-out list<array{0:int,1:int}> $extraRanges
     * @param list<array{0:int,1:int}> $extraRanges
     */
    private function parseClassAtom(array &$extraRanges): ?int
    {
        if ($this->src[$this->pos] !== '\\') {
            return $this->readCodePoint();
        }
        $this->pos++;
        if ($this->pos >= $this->len) {
            throw new SyntaxError('Invalid escape in char class');
        }
        $ch = $this->src[$this->pos];
        switch ($ch) {
            case 'd':
                $this->pos++;
                $extraRanges[] = [0x30, 0x39];
                return null;
            case 'D':
                // Approximation: outside the digit range. Encoded as
                // two big complementary ranges.
                $this->pos++;
                $extraRanges[] = [0x00, 0x2F];
                $extraRanges[] = [0x3A, 0x10FFFF];
                return null;
            case 'w':
                $this->pos++;
                $extraRanges[] = [0x30, 0x39];
                $extraRanges[] = [0x41, 0x5A];
                $extraRanges[] = [0x5F, 0x5F];
                $extraRanges[] = [0x61, 0x7A];
                return null;
            case 'W':
                $this->pos++;
                $extraRanges[] = [0x00, 0x2F];
                $extraRanges[] = [0x3A, 0x40];
                $extraRanges[] = [0x5B, 0x5E];
                $extraRanges[] = [0x60, 0x60];
                $extraRanges[] = [0x7B, 0x10FFFF];
                return null;
            case 's':
                $this->pos++;
                $extraRanges[] = [0x09, 0x0D];
                $extraRanges[] = [0x20, 0x20];
                $extraRanges[] = [0xA0, 0xA0];
                $extraRanges[] = [0x1680, 0x1680];
                $extraRanges[] = [0x2000, 0x200A];
                $extraRanges[] = [0x2028, 0x2029];
                $extraRanges[] = [0x202F, 0x202F];
                $extraRanges[] = [0x205F, 0x205F];
                $extraRanges[] = [0x3000, 0x3000];
                $extraRanges[] = [0xFEFF, 0xFEFF];
                return null;
            case 'S':
                $this->pos++;
                // Complement of \s. Use big spans.
                $extraRanges[] = [0x00, 0x08];
                $extraRanges[] = [0x0E, 0x1F];
                $extraRanges[] = [0x21, 0x9F];
                $extraRanges[] = [0xA1, 0x167F];
                $extraRanges[] = [0x1681, 0x1FFF];
                $extraRanges[] = [0x200B, 0x2027];
                $extraRanges[] = [0x202A, 0x202E];
                $extraRanges[] = [0x2030, 0x205E];
                $extraRanges[] = [0x2060, 0x2FFF];
                $extraRanges[] = [0x3001, 0xFEFE];
                $extraRanges[] = [0xFF00, 0x10FFFF];
                return null;
            case 'n':
                $this->pos++;
                return 0x0A;
            case 'r':
                $this->pos++;
                return 0x0D;
            case 't':
                $this->pos++;
                return 0x09;
            case 'v':
                $this->pos++;
                return 0x0B;
            case 'f':
                $this->pos++;
                return 0x0C;
            case '0':
                $this->pos++;
                return 0x00;
            case 'b':
                $this->pos++;
                return 0x08; // backspace inside class
            case 'x':
                $this->pos++;
                if ($this->pos + 2 > $this->len) {
                    throw new SyntaxError('Invalid \\x in char class');
                }
                $hex = substr($this->src, $this->pos, 2);
                $this->pos += 2;
                return (int) hexdec($hex);
            case 'u':
                $this->pos++;
                $node = $this->parseUnicodeEscape();
                /** @var Literal $node */
                return $node->codePoint;
        }
        // Identity escape: literal next char.
        $cp = $this->readCodePoint();
        return $cp;
    }
}
