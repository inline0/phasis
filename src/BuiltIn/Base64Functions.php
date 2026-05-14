<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Exceptions\JsThrowable;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsFunction;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * HTML §8.7 base64 utilities — atob / btoa as global functions.
 *
 * Specs:
 *   https://html.spec.whatwg.org/multipage/webappapis.html#atob
 *   https://infra.spec.whatwg.org/#forgiving-base64
 *
 * Both throw `DOMException("...", "InvalidCharacterError")` on malformed
 * input, matching browser behaviour.
 */
class Base64Functions
{
    /** ASCII whitespace per Infra: TAB, LF, FF, CR, space. */
    private const ASCII_WHITESPACE = "\t\n\f\r ";

    public static function install(Environment $env): void
    {
        $btoa = JsFunction::fromCallable(
            'btoa',
            static function (JsValue $this_, array $args) use ($env): JsValue {
                $input = $args[0] ?? JsUndefined::instance();
                $str = TypeConversion::toString($input);
                return new JsString(self::btoa($env, $str));
            },
            1,
        );
        $atob = JsFunction::fromCallable(
            'atob',
            static function (JsValue $this_, array $args) use ($env): JsValue {
                $input = $args[0] ?? JsUndefined::instance();
                $str = TypeConversion::toString($input);
                return new JsString(self::atob($env, $str));
            },
            1,
        );

        $env->defineVar('btoa', $btoa);
        $env->defineVar('atob', $atob);

        // Also install onto the global object so `globalThis.btoa` works
        // (mirrors how `console` is exposed); writable/configurable to match
        // how Web APIs are typically configured on the global.
        $globalObj = $env->getLinkedObject();
        if ($globalObj !== null) {
            $globalObj->defineOwnProperty(
                'btoa',
                PropertyDescriptor::data($btoa, true, false, true),
            );
            $globalObj->defineOwnProperty(
                'atob',
                PropertyDescriptor::data($atob, true, false, true),
            );
        }
    }

    /**
     * btoa(data) per HTML §8.7.1:
     *   1. If `data` contains any character whose code point is > U+00FF,
     *      throw `InvalidCharacterError` DOMException.
     *   2. Otherwise, return the isomorphic encoding of `data` as base64.
     *
     * The input arrives as our internal UTF-8/CESU-8 representation; we
     * iterate UTF-16 code units to enforce the 0..255 range correctly.
     */
    public static function btoa(Environment $env, string $jsStringValue): string
    {
        $u16 = JsString::utf8ToUtf16LE($jsStringValue);
        $len = strlen($u16);
        $bytes = '';
        for ($i = 0; $i + 1 < $len; $i += 2) {
            $cu = ord($u16[$i]) | (ord($u16[$i + 1]) << 8);
            if ($cu > 0xFF) {
                throw new JsThrowable(
                    DomExceptionConstructor::create(
                        $env,
                        'The string to be encoded contains characters outside of the Latin1 range.',
                        'InvalidCharacterError',
                    ),
                );
            }
            $bytes .= chr($cu);
        }
        return base64_encode($bytes);
    }

    /**
     * atob(data) per HTML §8.7.2 + Infra "forgiving-base64 decode":
     *   1. Remove ASCII whitespace (TAB, LF, FF, CR, space).
     *   2. If length % 4 == 0 and the string ends with "=" or "==",
     *      strip up to two trailing "=".
     *   3. If new length % 4 == 1, throw InvalidCharacterError.
     *   4. If any remaining char is not in the base64 alphabet
     *      (A-Z a-z 0-9 + /), throw InvalidCharacterError.
     *   5. Decode and return the bytes as a Latin-1 (isomorphic) string.
     */
    public static function atob(Environment $env, string $jsStringValue): string
    {
        // The input string only contains characters whose code points fit in
        // one UTF-16 code unit for any valid base64 — any non-ASCII char is
        // already an error per Infra step 4. Walking code units gives us a
        // single throw path for invalid input regardless of how PHP would
        // re-decode the UTF-8 bytes.
        $u16 = JsString::utf8ToUtf16LE($jsStringValue);
        $len = strlen($u16);

        // Step 1: strip ASCII whitespace, collecting valid bytes < 128.
        $stripped = '';
        for ($i = 0; $i + 1 < $len; $i += 2) {
            $cu = ord($u16[$i]) | (ord($u16[$i + 1]) << 8);
            if ($cu > 0xFF) {
                self::throwInvalidChar($env);
            }
            $ch = chr($cu);
            if (strpos(self::ASCII_WHITESPACE, $ch) !== false) {
                continue;
            }
            $stripped .= $ch;
        }

        $strippedLen = strlen($stripped);

        // Step 2: at most two trailing "=" iff total length % 4 == 0.
        if ($strippedLen >= 4 && $strippedLen % 4 === 0) {
            $trail = 0;
            if ($stripped[$strippedLen - 1] === '=') {
                $trail++;
                if ($stripped[$strippedLen - 2] === '=') {
                    $trail++;
                }
            }
            if ($trail > 0) {
                $stripped = substr($stripped, 0, $strippedLen - $trail);
                $strippedLen -= $trail;
            }
        }

        // Step 3: length % 4 == 1 is unrecoverable.
        if ($strippedLen % 4 === 1) {
            self::throwInvalidChar($env);
        }

        // Step 4: validate alphabet.
        for ($i = 0; $i < $strippedLen; $i++) {
            $c = $stripped[$i];
            if (!self::isBase64Alphabet($c)) {
                self::throwInvalidChar($env);
            }
        }

        // Step 5: re-add padding so PHP's strict decoder accepts the buffer,
        // then decode. We already validated the alphabet, so failure here
        // means an internal bug — guard with a throw to be safe.
        $padded = $stripped;
        $mod = $strippedLen % 4;
        if ($mod === 2) {
            $padded .= '==';
        } elseif ($mod === 3) {
            $padded .= '=';
        }

        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            self::throwInvalidChar($env);
        }

        // Each byte (0..255) becomes one UTF-16 code unit in the output.
        // Build a UTF-16LE buffer then convert back to internal encoding so
        // bytes 0x80..0xFF land as proper 2-byte UTF-8 sequences (Latin-1
        // codepoints), matching what JS sees as `String.fromCharCode(b)`.
        $u16Out = '';
        $declen = strlen($decoded);
        for ($i = 0; $i < $declen; $i++) {
            $u16Out .= pack('v', ord($decoded[$i]));
        }
        return JsString::utf16LEToUtf8($u16Out);
    }

    private static function isBase64Alphabet(string $c): bool
    {
        // A-Z a-z 0-9 + /
        return ($c >= 'A' && $c <= 'Z')
            || ($c >= 'a' && $c <= 'z')
            || ($c >= '0' && $c <= '9')
            || $c === '+'
            || $c === '/';
    }

    /** @return never */
    private static function throwInvalidChar(Environment $env): void
    {
        throw new JsThrowable(
            DomExceptionConstructor::create(
                $env,
                'The string to be decoded is not correctly encoded.',
                'InvalidCharacterError',
            ),
        );
    }
}
