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
        while ($this->pos < $this->len) {
            $ch = $this->src[$this->pos];
            if ($ch === '|' || $ch === ')') {
                break;
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
        return new Literal((int) hexdec($hex));
    }

    private function parseNumericBackref(): Node
    {
        $num = '';
        while ($this->pos < $this->len && ctype_digit($this->src[$this->pos])) {
            $num .= $this->src[$this->pos++];
        }
        return new Backreference((int) $num, null);
    }

    private function parseNamedBackref(): Node
    {
        $this->pos++; // consume k
        if ($this->pos >= $this->len || $this->src[$this->pos] !== '<') {
            throw new SyntaxError('Invalid \\k named backreference');
        }
        $this->pos++;
        $name = '';
        while ($this->pos < $this->len && $this->src[$this->pos] !== '>') {
            $name .= $this->src[$this->pos++];
        }
        if ($this->pos >= $this->len || $this->src[$this->pos] !== '>') {
            throw new SyntaxError('Invalid \\k named backreference');
        }
        $this->pos++;
        return new Backreference(null, $name);
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
                $name = '';
                while ($this->pos < $this->len && $this->src[$this->pos] !== '>') {
                    $name .= $this->src[$this->pos++];
                }
                if ($this->pos >= $this->len) {
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
                    // $first was already non-null per the if guard.
                    $ranges[] = [$first, $second];
                } else {
                    $ranges[] = [$first, $first];
                    $ranges[] = [0x2D, 0x2D]; // literal -
                }
            } elseif ($first !== null) {
                $ranges[] = [$first, $first];
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
