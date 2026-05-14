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
 * Matcher trait part: MatcherUtf. Composed into Matcher via
 * `use Parts\MatcherUtf;`.
 */
trait MatcherUtf
{
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
     * @return array{index: int, end: int, captures: list<?array{0:int,1:int,2:string}>}
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
        // Resume from the cached watermark when the new index is at or
        // beyond it; capture-build emits indices in roughly increasing
        // order so this hits often. A cache miss (idx behind watermark)
        // restarts from zero — same cost as the original loop.
        if ($idx >= $this->idxToCuCacheIdx && $this->idxToCuCacheIdx >= 0) {
            $i = $this->idxToCuCacheIdx;
            $out = $this->idxToCuCacheCu;
        } else {
            $i = 0;
            $out = 0;
        }
        $cap = min($idx, $this->inputLen);
        $input = $this->input;
        for (; $i < $cap; $i++) {
            $out += $input[$i] >= 0x10000 ? 2 : 1;
        }
        $this->idxToCuCacheIdx = $i;
        $this->idxToCuCacheCu = $out;
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
}
