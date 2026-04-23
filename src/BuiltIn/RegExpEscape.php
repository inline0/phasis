<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Object\PropertyDescriptor;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

/**
 * RegExp.escape(string) per ES spec proposal.
 *
 * Escapes a string so it can be used literally in a RegExp.
 * Syntax characters and / are backslash-escaped. Control characters
 * use \t, \n, \v, \f, \r escapes. Other punctuators, whitespace,
 * line terminators, and surrogates use \xHH or \uHHHH escapes.
 * A leading digit or ASCII letter uses \xHH.
 * All other code points pass through unchanged.
 */
class RegExpEscape
{
    public static function install(JsFunction $regExpCtor): void
    {
        $escapeFn = JsFunction::fromCallable('escape', self::implementation(), 1);
        $escapeFn->setNonConstructable();
        $regExpCtor->defineOwnProperty(
            'escape',
            PropertyDescriptor::data($escapeFn, true, false, true),
        );
    }

    private static function implementation(): \Closure
    {
        return static function (JsValue $this_, array $args): JsValue {
            $arg = $args[0] ?? JsUndefined::instance();
            if (!$arg instanceof JsString) {
                throw new \PhpJs\Exceptions\TypeError(
                    TypeConversion::toString($arg) . ' is not a string',
                );
            }
            $str = $arg->value;
            if ($str === '') {
                return new JsString('');
            }

            $otherPunctuators = ",-=<>#&!%:;@~'`\"";

            // Iterate over UTF-16 code units so lone surrogates (stored as
            // CESU-8 in the internal representation) and surrogate pairs are
            // handled per spec. EncodeForRegExpEscape operates on code points
            // where a valid surrogate pair counts as one point, but when
            // deciding on escape we look at individual code units first so
            // lone surrogates still trigger the escape branch.
            $utf16 = JsString::utf8ToUtf16LE($str);
            $unitCount = intdiv(strlen($utf16), 2);

            $result = '';
            $isFirst = true;
            $i = 0;
            while ($i < $unitCount) {
                $cu = unpack('v', substr($utf16, $i * 2, 2))[1];
                // Decode code point: combine with low surrogate if valid pair.
                $cp = $cu;
                $consumed = 1;
                if (
                    $cu >= 0xD800 && $cu <= 0xDBFF
                    && $i + 1 < $unitCount
                ) {
                    $cu2 = unpack('v', substr($utf16, ($i + 1) * 2, 2))[1];
                    if ($cu2 >= 0xDC00 && $cu2 <= 0xDFFF) {
                        $cp = 0x10000 + (($cu - 0xD800) << 10) + ($cu2 - 0xDC00);
                        $consumed = 2;
                    }
                }

                // Step 4a: first character that is a digit or ASCII letter -> \xHH.
                if ($isFirst) {
                    $isFirst = false;
                    if (
                        ($cp >= 0x30 && $cp <= 0x39)
                        || ($cp >= 0x41 && $cp <= 0x5A)
                        || ($cp >= 0x61 && $cp <= 0x7A)
                    ) {
                        $result .= '\\x' . str_pad(dechex($cp), 2, '0', STR_PAD_LEFT);
                        $i += $consumed;
                        continue;
                    }
                }

                // Step 1: SyntaxCharacter or SOLIDUS.
                if ($cp < 0x80 && strpos('^$\\.*+?()[]{}|/', chr($cp)) !== false) {
                    $result .= '\\' . chr($cp);
                    $i += $consumed;
                    continue;
                }

                // Step 2: Control escapes.
                if ($cp === 0x09) {
                    $result .= '\\t';
                    $i += $consumed;
                    continue;
                }
                if ($cp === 0x0A) {
                    $result .= '\\n';
                    $i += $consumed;
                    continue;
                }
                if ($cp === 0x0B) {
                    $result .= '\\v';
                    $i += $consumed;
                    continue;
                }
                if ($cp === 0x0C) {
                    $result .= '\\f';
                    $i += $consumed;
                    continue;
                }
                if ($cp === 0x0D) {
                    $result .= '\\r';
                    $i += $consumed;
                    continue;
                }

                // Steps 3-5: other punctuators, whitespace/line terminators, surrogates.
                $needsEscape = false;
                if ($cp < 0x80 && strpos($otherPunctuators, chr($cp)) !== false) {
                    $needsEscape = true;
                }
                if (
                    !$needsEscape && ($cp === 0x20 || $cp === 0xA0 || $cp === 0xFEFF
                    || $cp === 0x1680 || ($cp >= 0x2000 && $cp <= 0x200A)
                    || $cp === 0x202F || $cp === 0x205F || $cp === 0x3000)
                ) {
                    $needsEscape = true;
                }
                if (!$needsEscape && ($cp === 0x2028 || $cp === 0x2029)) {
                    $needsEscape = true;
                }
                if (
                    !$needsEscape
                    && $consumed === 1
                    && $cu >= 0xD800 && $cu <= 0xDFFF
                ) {
                    // Lone surrogate.
                    $needsEscape = true;
                }

                if ($needsEscape) {
                    if ($consumed === 1 && $cu >= 0xD800 && $cu <= 0xDFFF) {
                        // Emit the lone surrogate as-is via \uHHHH.
                        $result .= '\\u' . str_pad(dechex($cu), 4, '0', STR_PAD_LEFT);
                    } elseif ($cp <= 0xFF) {
                        $result .= '\\x' . str_pad(dechex($cp), 2, '0', STR_PAD_LEFT);
                    } elseif ($cp <= 0xFFFF) {
                        $result .= '\\u' . str_pad(dechex($cp), 4, '0', STR_PAD_LEFT);
                    } else {
                        $cp2 = $cp - 0x10000;
                        $high = 0xD800 + (($cp2 >> 10) & 0x3FF);
                        $low = 0xDC00 + ($cp2 & 0x3FF);
                        $result .= '\\u' . str_pad(dechex($high), 4, '0', STR_PAD_LEFT);
                        $result .= '\\u' . str_pad(dechex($low), 4, '0', STR_PAD_LEFT);
                    }
                    $i += $consumed;
                    continue;
                }

                // Step 6: pass through unchanged. Re-encode to UTF-8.
                if ($consumed === 2) {
                    $pairBytes = substr($utf16, $i * 2, 4);
                    $result .= JsString::utf16LEToUtf8($pairBytes);
                } else {
                    $cuBytes = substr($utf16, $i * 2, 2);
                    $result .= JsString::utf16LEToUtf8($cuBytes);
                }
                $i += $consumed;
            }

            return new JsString($result);
        };
    }
}
