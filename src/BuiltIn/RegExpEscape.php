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

            $controlEscapes = [
                "\x09" => '\\t',
                "\x0A" => '\\n',
                "\x0B" => '\\v',
                "\x0C" => '\\f',
                "\x0D" => '\\r',
            ];
            $otherPunctuators = ",-=<>#&!%:;@~'`\"";

            $result = '';
            $isFirst = true;
            $len = mb_strlen($str, 'UTF-8');
            for ($i = 0; $i < $len; $i++) {
                $char = mb_substr($str, $i, 1, 'UTF-8');
                $cp = mb_ord($char, 'UTF-8');

                // Step 4a: first character that is a digit or ASCII letter -> \xHH.
                if ($isFirst) {
                    $isFirst = false;
                    if (
                        ($cp >= 0x30 && $cp <= 0x39)
                        || ($cp >= 0x41 && $cp <= 0x5A)
                        || ($cp >= 0x61 && $cp <= 0x7A)
                    ) {
                        $result .= '\\x' . str_pad(dechex($cp), 2, '0', STR_PAD_LEFT);
                        continue;
                    }
                }

                // Step 1: SyntaxCharacter or SOLIDUS.
                if ($cp < 0x80 && strpos('^$\\.*+?()[]{}|/', $char) !== false) {
                    $result .= '\\' . $char;
                    continue;
                }

                // Step 2: Control escapes.
                if (isset($controlEscapes[$char])) {
                    $result .= $controlEscapes[$char];
                    continue;
                }

                // Steps 3-5: other punctuators, whitespace/line terminators, surrogates.
                $needsEscape = false;
                if ($cp < 0x80 && strpos($otherPunctuators, $char) !== false) {
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
                if (!$needsEscape && $cp >= 0xD800 && $cp <= 0xDFFF) {
                    $needsEscape = true;
                }

                if ($needsEscape) {
                    if ($cp <= 0xFF) {
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
                    continue;
                }

                // Step 6: pass through unchanged.
                $result .= $char;
            }

            return new JsString($result);
        };
    }
}
