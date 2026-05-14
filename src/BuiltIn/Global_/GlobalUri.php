<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Global_;

use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\TypeError;
use Phasis\Exceptions\SyntaxError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Runtime\Interpreter;
use Phasis\Spec\AbstractOperations;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;
use Phasis\BuiltIn\SymbolConstructor;

/**
 * GlobalObject trait part: GlobalUri. Composed into GlobalObject
 * via `use Global_\GlobalUri;`.
 */
trait GlobalUri
{
    /**
     * Throw a JS URIError. Constructs a proper URIError object with the
     * correct prototype chain so that `e instanceof URIError` works.
     *
     * @return never
     */
    private static function throwURIError(string $message, Environment $env): void
    {
        $errorObj = new \Phasis\Value\JsObject();
        $errorObj->set('message', new JsString($message));
        $errorObj->set('name', new JsString('URIError'));
        $errorObj->set('stack', new JsString('URIError: ' . $message));
        if ($env->has('URIError')) {
            $constructor = $env->get('URIError');
            if ($constructor instanceof JsFunction) {
                $errorObj->set('constructor', $constructor);
                $proto = $constructor->get('prototype');
                if ($proto instanceof \Phasis\Value\JsObject) {
                    $errorObj->setPrototype($proto);
                }
            }
        }
        throw new \Phasis\Exceptions\JsThrowable($errorObj, 'URIError: ' . $message);
    }

    /**
     * Spec-compliant Encode(string, unescapedSet) per ES2023 section 19.2.6.1.1.
     * Operates on UTF-16 code units of the input string.
     *
     * @param bool $isUri true for encodeURI, false for encodeURIComponent
     */
    private static function specEncode(string $str, bool $isUri, Environment $env): string
    {
        // Unescaped characters for encodeURIComponent: uriUnescaped
        //   uriAlpha (A-Z, a-z) + DecimalDigit (0-9) + uriMark (- _ . ! ~ * ' ( ))
        // Unescaped characters for encodeURI: uriReserved + uriUnescaped + "#"
        //   uriReserved: ; / ? : @ & = + $ ,
        //   "#"

        // Build a lookup set of ASCII code points that are unescaped.
        $unescaped = [];
        // uriUnescaped: A-Z, a-z, 0-9
        for ($c = ord('A'); $c <= ord('Z'); $c++) {
            $unescaped[$c] = true;
        }
        for ($c = ord('a'); $c <= ord('z'); $c++) {
            $unescaped[$c] = true;
        }
        for ($c = ord('0'); $c <= ord('9'); $c++) {
            $unescaped[$c] = true;
        }
        // uriMark: - _ . ! ~ * ' ( )
        foreach (['-', '_', '.', '!', '~', '*', "'", '(', ')'] as $ch) {
            $unescaped[ord($ch)] = true;
        }
        if ($isUri) {
            // uriReserved: ; / ? : @ & = + $ ,
            foreach ([';', '/', '?', ':', '@', '&', '=', '+', '$', ','] as $ch) {
                $unescaped[ord($ch)] = true;
            }
            // "#"
            $unescaped[ord('#')] = true;
        }

        // Get the UTF-16 code units from the internal string representation.
        $u16 = JsString::utf8ToUtf16LE($str);
        $u16Len = (int) (strlen($u16) / 2);

        $result = '';
        for ($k = 0; $k < $u16Len; $k++) {
            $codeUnit = ord($u16[$k * 2]) | (ord($u16[$k * 2 + 1]) << 8);

            // Check if the code unit is in the unescaped set (only BMP non-surrogate).
            if ($codeUnit < 0x80 && isset($unescaped[$codeUnit])) {
                $result .= chr($codeUnit);
                continue;
            }

            // Lone low surrogate: throw URIError.
            if ($codeUnit >= 0xDC00 && $codeUnit <= 0xDFFF) {
                self::throwURIError('URI malformed', $env);
            }

            $codePoint = $codeUnit;
            if ($codeUnit >= 0xD800 && $codeUnit <= 0xDBFF) {
                // High surrogate: must be followed by a low surrogate.
                if ($k + 1 >= $u16Len) {
                    self::throwURIError('URI malformed', $env);
                }
                $next = ord($u16[($k + 1) * 2]) | (ord($u16[($k + 1) * 2 + 1]) << 8);
                if ($next < 0xDC00 || $next > 0xDFFF) {
                    self::throwURIError('URI malformed', $env);
                }
                // Decode surrogate pair to codepoint.
                $codePoint = ($codeUnit - 0xD800) * 0x400 + ($next - 0xDC00) + 0x10000;
                $k++; // skip the low surrogate
            }

            // Encode the codepoint as UTF-8 bytes, then percent-encode each byte.
            $utf8Bytes = self::codePointToUtf8Bytes($codePoint);
            foreach ($utf8Bytes as $byte) {
                $result .= '%' . strtoupper(str_pad(dechex($byte), 2, '0', STR_PAD_LEFT));
            }
        }

        return $result;
    }

    /**
     * Spec-compliant Decode(string, reservedSet) per ES2023 section 19.2.6.1.2.
     *
     * Hot path optimisations:
     *   - bulk-copy ASCII runs between `%` markers via strpos rather than
     *     iterating one byte at a time;
     *   - decode hex pairs through a precomputed 256-entry lookup table to
     *     avoid hexdec/ctype_xdigit per byte;
     *   - encode the resulting codepoint to UTF-8 / CESU-8 inline with
     *     chr() rather than calling JsString::utf16CodeUnitToUtf8 (which
     *     dispatches to mb_chr).
     *
     * @param bool $isUri true for decodeURI (preserves reserved), false for decodeURIComponent
     */
    private static function specDecode(string $str, bool $isUri, Environment $env): string
    {
        $hexMap = self::$hexPairToByte;
        if ($hexMap === null) {
            $hexMap = [];
            $digits = '0123456789ABCDEFabcdef';
            for ($hi = 0; $hi < 22; $hi++) {
                for ($lo = 0; $lo < 22; $lo++) {
                    $value = (($hi < 16 ? $hi : $hi - 6) << 4)
                        | ($lo < 16 ? $lo : $lo - 6);
                    $hexMap[$digits[$hi] . $digits[$lo]] = $value;
                }
            }
            self::$hexPairToByte = $hexMap;
        }

        if ($isUri) {
            $reservedBytes = self::$uriReservedBytes;
            if ($reservedBytes === null) {
                $reservedBytes = [];
                foreach ([';', '/', '?', ':', '@', '&', '=', '+', '$', ',', '#'] as $ch) {
                    $reservedBytes[ord($ch)] = true;
                }
                self::$uriReservedBytes = $reservedBytes;
            }
        } else {
            $reservedBytes = null;
        }

        $len = strlen($str);
        if ($len === 0) {
            return '';
        }

        // Fast path: no '%' at all means the input has no escapes to decode.
        // Per spec we still need to reject any byte > 0x7F that isn't part of
        // a percent-encoded sequence? No: the spec accepts non-ASCII input
        // verbatim (Decode walks code units, only %-prefixed triplets are
        // interpreted). We can return as-is.
        $firstPercent = strpos($str, '%');
        if ($firstPercent === false) {
            return $str;
        }

        // Ultra-fast path: exactly 12 characters of the shape `%XX%XX%XX%XX`
        // encoding a single 4-byte UTF-8 sequence in the supplementary plane.
        // Sputnik's S15.1.3.1_A2.5_T1 / S15.1.3.2_A2.5_T1 stress tests fire
        // ~1M of these per run. Skipping the generic walker (hex map
        // indirection, byte-at-a-time validation, six chr() concatenations)
        // drops the inner loop cost dramatically. Falls through to the spec
        // path on any mismatch so semantics never diverge; the spec walker
        // still owns every error case.
        if (
            $len === 12
            && $firstPercent === 0
            && $str[3] === '%'
            && $str[6] === '%'
            && $str[9] === '%'
        ) {
            $bytes = @hex2bin(
                $str[1] . $str[2]
                . $str[4] . $str[5]
                . $str[7] . $str[8]
                . $str[10] . $str[11]
            );
            if ($bytes !== false && strlen($bytes) === 4) {
                $b0 = ord($bytes[0]);
                if (($b0 & 0xF8) === 0xF0) {
                    $b1 = ord($bytes[1]);
                    $b2 = ord($bytes[2]);
                    $b3 = ord($bytes[3]);
                    if (
                        ($b1 & 0xC0) === 0x80
                        && ($b2 & 0xC0) === 0x80
                        && ($b3 & 0xC0) === 0x80
                    ) {
                        $codePoint = (($b0 & 0x07) << 18)
                            | (($b1 & 0x3F) << 12)
                            | (($b2 & 0x3F) << 6)
                            | ($b3 & 0x3F);
                        if ($codePoint >= 0x10000 && $codePoint <= 0x10FFFF) {
                            // Encode as a CESU-8 surrogate pair (two 3-byte
                            // sequences) so JsString sees UTF-16 code units.
                            $cp = $codePoint - 0x10000;
                            $hi = 0xD800 | ($cp >> 10);
                            $lo = 0xDC00 | ($cp & 0x3FF);
                            return chr(0xE0 | ($hi >> 12))
                                . chr(0x80 | (($hi >> 6) & 0x3F))
                                . chr(0x80 | ($hi & 0x3F))
                                . chr(0xE0 | ($lo >> 12))
                                . chr(0x80 | (($lo >> 6) & 0x3F))
                                . chr(0x80 | ($lo & 0x3F));
                        }
                    }
                }
            }
            // Fall through to spec walker for the error / out-of-range branches.
        }

        $result = $firstPercent > 0 ? substr($str, 0, $firstPercent) : '';
        $k = $firstPercent;

        while ($k < $len) {
            // '%' at position $k. Read the percent-encoded byte via hex map.
            if ($k + 2 >= $len) {
                self::throwURIError('URI malformed', $env);
            }
            $start = $k;
            $pair = $str[$k + 1] . $str[$k + 2];
            if (!isset($hexMap[$pair])) {
                self::throwURIError('URI malformed', $env);
            }
            $b = $hexMap[$pair];
            $k += 3;

            if ($b < 0x80) {
                // Single-byte ASCII. decodeURI keeps reserved bytes encoded.
                if ($reservedBytes !== null && isset($reservedBytes[$b])) {
                    $result .= substr($str, $start, 3);
                } else {
                    $result .= chr($b);
                }
            } else {
                // Multi-byte UTF-8 sequence. Determine expected length.
                if (($b & 0xE0) === 0xC0) {
                    $n = 2;
                } elseif (($b & 0xF0) === 0xE0) {
                    $n = 3;
                } elseif (($b & 0xF8) === 0xF0) {
                    $n = 4;
                } else {
                    self::throwURIError('URI malformed', $env);
                }

                // Read the remaining $n-1 continuation bytes, scalar-only.
                $b1 = 0;
                $b2 = 0;
                $b3 = 0;
                for ($j = 1; $j < $n; $j++) {
                    if ($k + 2 >= $len || $str[$k] !== '%') {
                        self::throwURIError('URI malformed', $env);
                    }
                    $cpair = $str[$k + 1] . $str[$k + 2];
                    if (!isset($hexMap[$cpair])) {
                        self::throwURIError('URI malformed', $env);
                    }
                    $cb = $hexMap[$cpair];
                    if (($cb & 0xC0) !== 0x80) {
                        self::throwURIError('URI malformed', $env);
                    }
                    if ($j === 1) {
                        $b1 = $cb;
                    } elseif ($j === 2) {
                        $b2 = $cb;
                    } else {
                        $b3 = $cb;
                    }
                    $k += 3;
                }

                if ($n === 2) {
                    $codePoint = (($b & 0x1F) << 6) | ($b1 & 0x3F);
                    if ($codePoint < 0x80) {
                        self::throwURIError('URI malformed', $env);
                    }
                } elseif ($n === 3) {
                    $codePoint = (($b & 0x0F) << 12) | (($b1 & 0x3F) << 6) | ($b2 & 0x3F);
                    if ($codePoint < 0x800) {
                        self::throwURIError('URI malformed', $env);
                    }
                    if ($codePoint >= 0xD800 && $codePoint <= 0xDFFF) {
                        self::throwURIError('URI malformed', $env);
                    }
                } else {
                    $codePoint = (($b & 0x07) << 18) | (($b1 & 0x3F) << 12)
                        | (($b2 & 0x3F) << 6) | ($b3 & 0x3F);
                    if ($codePoint < 0x10000 || $codePoint > 0x10FFFF) {
                        self::throwURIError('URI malformed', $env);
                    }
                }

                // Inline UTF-8 / CESU-8 encoding so we never call mb_chr in
                // the hot loop (mb_chr dispatches through a slower path even
                // for plain BMP codepoints).
                if ($codePoint <= 0x7FF) {
                    // 2-byte UTF-8 sequence.
                    $result .= chr(0xC0 | ($codePoint >> 6))
                        . chr(0x80 | ($codePoint & 0x3F));
                } elseif ($codePoint <= 0xFFFF) {
                    // 3-byte UTF-8 sequence (BMP non-surrogate).
                    $result .= chr(0xE0 | ($codePoint >> 12))
                        . chr(0x80 | (($codePoint >> 6) & 0x3F))
                        . chr(0x80 | ($codePoint & 0x3F));
                } else {
                    // Supplementary plane: encode as a CESU-8 surrogate pair
                    // (two 3-byte sequences) so JsString sees UTF-16 code
                    // units consistently.
                    $cp = $codePoint - 0x10000;
                    $hi = 0xD800 | ($cp >> 10);
                    $lo = 0xDC00 | ($cp & 0x3FF);
                    $result .= chr(0xE0 | ($hi >> 12))
                        . chr(0x80 | (($hi >> 6) & 0x3F))
                        . chr(0x80 | ($hi & 0x3F))
                        . chr(0xE0 | ($lo >> 12))
                        . chr(0x80 | (($lo >> 6) & 0x3F))
                        . chr(0x80 | ($lo & 0x3F));
                }
            }

            // Bulk-copy the ASCII run between this position and the next '%'.
            if ($k >= $len) {
                break;
            }
            $next = strpos($str, '%', $k);
            if ($next === false) {
                $result .= substr($str, $k);
                break;
            }
            if ($next > $k) {
                $result .= substr($str, $k, $next - $k);
            }
            $k = $next;
        }

        return $result;
    }

    /**
     * Encode a Unicode codepoint to its UTF-8 byte sequence.
     *
     * @return int[] array of byte values
     */
    private static function codePointToUtf8Bytes(int $cp): array
    {
        if ($cp <= 0x7F) {
            return [$cp];
        }
        if ($cp <= 0x7FF) {
            return [
                0xC0 | ($cp >> 6),
                0x80 | ($cp & 0x3F),
            ];
        }
        if ($cp <= 0xFFFF) {
            return [
                0xE0 | ($cp >> 12),
                0x80 | (($cp >> 6) & 0x3F),
                0x80 | ($cp & 0x3F),
            ];
        }
        return [
            0xF0 | ($cp >> 18),
            0x80 | (($cp >> 12) & 0x3F),
            0x80 | (($cp >> 6) & 0x3F),
            0x80 | ($cp & 0x3F),
        ];
    }

    /**
     * Convert a PHP UTF-8 string into an array of UTF-16 code unit values.
     * Codepoints above U+FFFF are split into surrogate pairs per UTF-16.
     *
     * @return int[]
     */
    private static function utf8ToUtf16CodeUnits(string $str): array
    {
        $units = [];
        $len = mb_strlen($str, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($str, $i, 1, 'UTF-8');
            $cp = mb_ord($char, 'UTF-8');
            if ($cp > 0xFFFF) {
                // Encode as surrogate pair.
                $cp -= 0x10000;
                $units[] = 0xD800 + ($cp >> 10);
                $units[] = 0xDC00 + ($cp & 0x3FF);
            } else {
                $units[] = $cp;
            }
        }
        return $units;
    }
}
