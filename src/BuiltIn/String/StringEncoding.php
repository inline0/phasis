<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\String;

use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
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
 * StringPrototype trait part: StringEncoding. Composed into
 * StringPrototype via `use String\StringEncoding;`. `self::`/`$this->`
 * resolve into the composing class.
 */
trait StringEncoding
{
    private static function normalize(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $form = isset($args[0]) && !($args[0] instanceof JsUndefined)
                ? TypeConversion::toString($args[0]) : 'NFC';
            if (function_exists('normalizer_normalize')) {
                /** @var int $formConst */
                $formConst = match (strtoupper($form)) {
                    'NFC' => 16, // Normalizer::FORM_C
                    'NFD' => 4, // Normalizer::FORM_D
                    'NFKC' => 32, // Normalizer::FORM_KC
                    'NFKD' => 8, // Normalizer::FORM_KD
                    default => throw new \Phasis\Exceptions\RangeError(
                        'The normalization form should be one of NFC, NFD, NFKC, NFKD',
                    ),
                };
                /** @var string|false $normalized */
                $normalized = normalizer_normalize($str, $formConst);
                return new JsString($normalized !== false ? $normalized : $str);
            }
            return new JsString($str);
        };
    }

    private static function fromCharCode(): \Closure
    {
        return static function (JsValue $this_, array $args): JsValue {
            // Hot path for decodeURI UTF-8 sweeps: arguments are usually
            // small JsNumber integer values produced by bit ops, so skip
            // the full ToUint16 dispatch (toNumber + fmod) and inline the
            // truncation to a 16-bit unsigned code unit. utf16CodeUnitToUtf8
            // is also inlined to avoid a static-method call per char.
            $str = '';
            foreach ($args as $arg) {
                if ($arg instanceof JsNumber) {
                    $n = $arg->value;
                    if (is_nan($n) || is_infinite($n) || $n === 0.0) {
                        $code = 0;
                    } else {
                        // Sign-aware truncate then mask to 16 bits.
                        $trunc = ($n > 0) ? (int) $n : -(int) -$n;
                        $code = $trunc & 0xFFFF;
                    }
                } else {
                    $code = \Phasis\Spec\TypeConversion::toUint16($arg);
                }
                // Inline utf16CodeUnitToUtf8: 3-byte UTF-8/CESU-8 for any
                // codepoint >= 0x800 (BMP non-ASCII + surrogates), 2-byte
                // for 0x80-0x7FF, 1-byte for 0x00-0x7F.
                if ($code < 0x80) {
                    $str .= chr($code);
                } elseif ($code < 0x800) {
                    $str .= chr(0xC0 | ($code >> 6)) . chr(0x80 | ($code & 0x3F));
                } else {
                    $str .= chr(0xE0 | ($code >> 12))
                         . chr(0x80 | (($code >> 6) & 0x3F))
                         . chr(0x80 | ($code & 0x3F));
                }
            }
            return new JsString($str);
        };
    }

    private static function fromCodePoint(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = '';
            foreach ($args as $arg) {
                $num = TypeConversion::toNumber($arg);
                // Step 2b: If not integral, throw RangeError (NaN, Infinity, fractional).
                if (is_nan($num) || is_infinite($num) || floor($num) !== $num) {
                    throw new \Phasis\Exceptions\RangeError("Invalid code point {$num}");
                }
                $code = (int) $num;
                // Step 2c: If < 0 or > 0x10FFFF, throw RangeError.
                if ($code < 0 || $code > 0x10FFFF) {
                    throw new \Phasis\Exceptions\RangeError("Invalid code point {$code}");
                }
                if ($code >= 0xD800 && $code <= 0xDBFF) {
                    // Lone high surrogate: encode as CESU-8 so the byte
                    // stream round-trips back to a single UTF-16 code unit.
                    $str .= "\xED" . chr(0xA0 | (($code >> 6) & 0x0F)) . chr(0x80 | ($code & 0x3F));
                    continue;
                }
                if ($code >= 0xDC00 && $code <= 0xDFFF) {
                    // Lone low surrogate.
                    $str .= "\xED" . chr(0xB0 | (($code >> 6) & 0x0F)) . chr(0x80 | ($code & 0x3F));
                    continue;
                }
                $str .= mb_chr($code, 'UTF-8');
            }
            return new JsString($str);
        };
    }

    private static function isWellFormed(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $u16 = JsString::utf8ToUtf16LE($str);
            $u16Len = (int) (strlen($u16) / 2);
            for ($i = 0; $i < $u16Len; $i++) {
                $cu = ord($u16[$i * 2]) | (ord($u16[$i * 2 + 1]) << 8);
                if ($cu >= 0xD800 && $cu <= 0xDBFF) {
                    if ($i + 1 >= $u16Len) {
                        return new \Phasis\Value\JsBoolean(false);
                    }
                    $next = ord($u16[($i + 1) * 2]) | (ord($u16[($i + 1) * 2 + 1]) << 8);
                    if ($next < 0xDC00 || $next > 0xDFFF) {
                        return new \Phasis\Value\JsBoolean(false);
                    }
                    $i++;
                } elseif ($cu >= 0xDC00 && $cu <= 0xDFFF) {
                    return new \Phasis\Value\JsBoolean(false);
                }
            }
            return new \Phasis\Value\JsBoolean(true);
        };
    }

    private static function toWellFormed(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $u16 = JsString::utf8ToUtf16LE($str);
            $u16Len = (int) (strlen($u16) / 2);
            $out = '';
            $i = 0;
            while ($i < $u16Len) {
                $cu = ord($u16[$i * 2])
                    | (ord($u16[$i * 2 + 1]) << 8);
                if ($cu >= 0xD800 && $cu <= 0xDBFF) {
                    if ($i + 1 < $u16Len) {
                        $next = ord($u16[($i + 1) * 2])
                            | (ord($u16[($i + 1) * 2 + 1]) << 8);
                        if ($next >= 0xDC00 && $next <= 0xDFFF) {
                            // Valid pair: keep both code units.
                            $out .= substr($u16, $i * 2, 4);
                            $i += 2;
                            continue;
                        }
                    }
                    // Lone high surrogate: replace with U+FFFD.
                    $out .= pack('v', 0xFFFD);
                    $i++;
                } elseif ($cu >= 0xDC00 && $cu <= 0xDFFF) {
                    // Lone low surrogate: replace with U+FFFD.
                    $out .= pack('v', 0xFFFD);
                    $i++;
                } else {
                    $out .= substr($u16, $i * 2, 2);
                    $i++;
                }
            }
            // Convert UTF-16LE back to UTF-8, which properly
            // combines surrogate pairs into 4-byte sequences.
            return new JsString(JsString::utf16LEToUtf8($out));
        };
    }
}
