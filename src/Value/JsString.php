<?php

declare(strict_types=1);

namespace PhpJs\Value;

class JsString implements JsValue
{
    private static ?JsObject $stringPrototype = null;

    public static function setStringPrototype(JsObject $proto): void
    {
        self::$stringPrototype = $proto;
    }

    public static function resetStringPrototype(): void
    {
        self::$stringPrototype = null;
    }

    public static function getStringPrototype(): ?JsObject
    {
        return self::$stringPrototype;
    }

    public function __construct(
        public readonly string $value,
    ) {
    }

    public function typeof(): string
    {
        return 'string';
    }

    public function toBoolean(): bool
    {
        return $this->value !== '';
    }

    public function toNumber(): float
    {
        return \PhpJs\Spec\TypeConversion::stringToNumber($this->value);
    }

    public function toInt32(): int
    {
        return (JsNumber::of($this->toNumber()))->toInt32();
    }

    public function toUint32(): int
    {
        return (JsNumber::of($this->toNumber()))->toUint32();
    }

    public function toJsString(): string
    {
        return $this->value;
    }

    public function display(): string
    {
        return $this->value;
    }

    /** JavaScript String.length (UTF-16 code unit count). */
    public function length(): int
    {
        $u16 = self::utf8ToUtf16LE($this->value);
        return (int) (strlen($u16) / 2);
    }

    /**
     * Convert a single UTF-16 code unit to an internal UTF-8/CESU-8 character.
     * Surrogates (U+D800-U+DFFF) are encoded as CESU-8 3-byte sequences.
     */
    public static function utf16CodeUnitToUtf8(int $codeUnit): string
    {
        if ($codeUnit >= 0xD800 && $codeUnit <= 0xDFFF) {
            // Surrogate: encode as CESU-8 (3-byte sequence like UTF-8 for BMP).
            return chr(0xE0 | ($codeUnit >> 12))
                 . chr(0x80 | (($codeUnit >> 6) & 0x3F))
                 . chr(0x80 | ($codeUnit & 0x3F));
        }
        // Normal BMP or ASCII: use mb_chr.
        return mb_chr($codeUnit, 'UTF-8');
    }

    /**
     * Convert an internal string (UTF-8 with possible CESU-8 lone surrogates)
     * to a UTF-16LE byte string. CESU-8 encodes lone surrogates as 3-byte
     * sequences (ED A0-BF 80-BF for high, ED B0-BF 80-BF for low). Standard
     * mb_convert_encoding rejects these as invalid UTF-8 so we handle them
     * manually.
     */
    public static function utf8ToUtf16LE(string $str): string
    {
        $len = strlen($str);
        $result = '';
        $i = 0;

        while ($i < $len) {
            $byte = ord($str[$i]);

            // CESU-8 surrogate detection: 3-byte sequence starting with 0xED
            // where the second byte is 0xA0-0xBF (surrogates U+D800-U+DFFF).
            if (
                $byte === 0xED
                && $i + 2 < $len
                && (ord($str[$i + 1]) & 0xE0) === 0xA0
            ) {
                $b2 = ord($str[$i + 1]);
                $b3 = ord($str[$i + 2]);
                $codeUnit = (($byte & 0x0F) << 12) | (($b2 & 0x3F) << 6) | ($b3 & 0x3F);
                $result .= pack('v', $codeUnit);
                $i += 3;
                continue;
            }

            // Regular UTF-8: find the sequence length.
            if ($byte < 0x80) {
                $result .= pack('v', $byte);
                $i += 1;
            } elseif (($byte & 0xE0) === 0xC0) {
                // 2-byte sequence.
                if ($i + 1 >= $len) {
                    $result .= pack('v', 0xFFFD);
                    $i++;
                    continue;
                }
                $cp = (($byte & 0x1F) << 6) | (ord($str[$i + 1]) & 0x3F);
                $result .= pack('v', $cp);
                $i += 2;
            } elseif (($byte & 0xF0) === 0xE0) {
                // 3-byte sequence (non-surrogate, handled above for surrogates).
                if ($i + 2 >= $len) {
                    $result .= pack('v', 0xFFFD);
                    $i++;
                    continue;
                }
                $cp = (($byte & 0x0F) << 12)
                    | ((ord($str[$i + 1]) & 0x3F) << 6)
                    | (ord($str[$i + 2]) & 0x3F);
                $result .= pack('v', $cp);
                $i += 3;
            } elseif (($byte & 0xF8) === 0xF0) {
                // 4-byte sequence (supplementary character -> surrogate pair).
                if ($i + 3 >= $len) {
                    $result .= pack('v', 0xFFFD);
                    $i++;
                    continue;
                }
                $cp = (($byte & 0x07) << 18)
                    | ((ord($str[$i + 1]) & 0x3F) << 12)
                    | ((ord($str[$i + 2]) & 0x3F) << 6)
                    | (ord($str[$i + 3]) & 0x3F);
                // Encode as surrogate pair.
                $cp -= 0x10000;
                $high = 0xD800 + ($cp >> 10);
                $low = 0xDC00 + ($cp & 0x3FF);
                $result .= pack('v', $high) . pack('v', $low);
                $i += 4;
            } else {
                // Invalid byte: replacement character.
                $result .= pack('v', 0xFFFD);
                $i++;
            }
        }

        return $result;
    }

    /**
     * Convert a UTF-16LE byte string back to internal UTF-8/CESU-8.
     * Lone surrogates are preserved as CESU-8 3-byte sequences.
     * Valid surrogate pairs are combined into proper UTF-8 4-byte sequences.
     */
    public static function utf16LEToUtf8(string $u16): string
    {
        $len = strlen($u16);
        $result = '';
        $i = 0;

        while ($i + 1 < $len) {
            $cu = ord($u16[$i]) | (ord($u16[$i + 1]) << 8);

            if ($cu >= 0xD800 && $cu <= 0xDBFF && $i + 3 < $len) {
                // High surrogate: check for trail.
                $next = ord($u16[$i + 2]) | (ord($u16[$i + 3]) << 8);
                if ($next >= 0xDC00 && $next <= 0xDFFF) {
                    // Valid surrogate pair: encode as UTF-8 4-byte sequence.
                    $cp = ($cu - 0xD800) * 0x400 + ($next - 0xDC00) + 0x10000;
                    $result .= chr(0xF0 | ($cp >> 18))
                             . chr(0x80 | (($cp >> 12) & 0x3F))
                             . chr(0x80 | (($cp >> 6) & 0x3F))
                             . chr(0x80 | ($cp & 0x3F));
                    $i += 4;
                    continue;
                }
                // Lone high surrogate: encode as CESU-8.
                $result .= self::utf16CodeUnitToUtf8($cu);
                $i += 2;
                continue;
            }

            if ($cu >= 0xDC00 && $cu <= 0xDFFF) {
                // Lone low surrogate: encode as CESU-8.
                $result .= self::utf16CodeUnitToUtf8($cu);
                $i += 2;
                continue;
            }

            // Regular BMP character.
            $result .= self::utf16CodeUnitToUtf8($cu);
            $i += 2;
        }

        return $result;
    }

    /**
     * Concatenate two internal strings, merging a trailing CESU-8 high
     * surrogate in $left with a leading CESU-8 low surrogate in $right
     * into a proper UTF-8 4-byte sequence. This ensures that string
     * concatenation like '\uD834' + '\uDF06' produces the same internal
     * bytes as the literal '\uD834\uDF06'.
     */
    public static function concatNormalize(string $left, string $right): string
    {
        $lLen = strlen($left);
        $rLen = strlen($right);

        // Quick path: if either is empty or too short, no merging possible.
        if ($lLen < 3 || $rLen < 3) {
            return $left . $right;
        }

        // Check if $left ends with a CESU-8 high surrogate (ED A0-AF xx).
        $b1 = ord($left[$lLen - 3]);
        $b2 = ord($left[$lLen - 2]);
        $b3 = ord($left[$lLen - 1]);

        if ($b1 !== 0xED || ($b2 & 0xF0) !== 0xA0) {
            return $left . $right;
        }

        // Check if $right starts with a CESU-8 low surrogate (ED B0-BF xx).
        $r1 = ord($right[0]);
        $r2 = ord($right[1]);
        $r3 = ord($right[2]);

        if ($r1 !== 0xED || ($r2 & 0xF0) !== 0xB0) {
            return $left . $right;
        }

        // Decode both surrogates.
        $high = (($b1 & 0x0F) << 12) | (($b2 & 0x3F) << 6) | ($b3 & 0x3F);
        $low = (($r1 & 0x0F) << 12) | (($r2 & 0x3F) << 6) | ($r3 & 0x3F);

        // Combine into a single code point.
        $cp = ($high - 0xD800) * 0x400 + ($low - 0xDC00) + 0x10000;

        // Encode as proper UTF-8.
        $utf8Char = chr(0xF0 | ($cp >> 18))
                  . chr(0x80 | (($cp >> 12) & 0x3F))
                  . chr(0x80 | (($cp >> 6) & 0x3F))
                  . chr(0x80 | ($cp & 0x3F));

        return substr($left, 0, $lLen - 3) . $utf8Char . substr($right, 3);
    }
}
