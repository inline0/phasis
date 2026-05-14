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
 * StringPrototype trait part: StringPadding. Composed into
 * StringPrototype via `use String\StringPadding;`. `self::`/`$this->`
 * resolve into the composing class.
 */
trait StringPadding
{
    private static function trim(): \Closure
    {
        return function (JsValue $this_): JsValue {
            return new JsString(TypeConversion::trimWhitespace(self::extractString($this_)));
        };
    }

    private static function trimStart(): \Closure
    {
        return function (JsValue $this_): JsValue {
            $str = self::extractString($this_);
            // Left-trim JS whitespace.
            $pattern = '/^[\x09\x0A\x0B\x0C\x0D\x20\xC2\xA0'
                . '\xE2\x80\x80-\xE2\x80\x8A'
                . '\xE2\x80\xA8\xE2\x80\xA9\xE2\x80\xAF'
                . '\xE2\x81\x9F\xE3\x80\x80\xEF\xBB\xBF]+/';
            return new JsString(preg_replace($pattern, '', $str) ?? $str);
        };
    }

    private static function trimEnd(): \Closure
    {
        return function (JsValue $this_): JsValue {
            $str = self::extractString($this_);
            // Right-trim JS whitespace.
            $pattern = '/[\x09\x0A\x0B\x0C\x0D\x20\xC2\xA0'
                . '\xE2\x80\x80-\xE2\x80\x8A'
                . '\xE2\x80\xA8\xE2\x80\xA9\xE2\x80\xAF'
                . '\xE2\x81\x9F\xE3\x80\x80\xEF\xBB\xBF]+$/';
            return new JsString(preg_replace($pattern, '', $str) ?? $str);
        };
    }

    private static function repeat(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $n = isset($args[0])
                ? TypeConversion::toIntegerOrInfinity($args[0])
                : 0.0;

            if ($n < 0 || $n === INF) {
                throw new \Phasis\Exceptions\RangeError('Invalid count value');
            }

            $count = (int) $n;

            return new JsString(str_repeat($str, $count));
        };
    }

    private static function padStart(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $targetLength = isset($args[0]) ? (int) TypeConversion::toNumber($args[0]) : 0;
            $fillArg = $args[1] ?? JsUndefined::instance();
            $padStr = $fillArg instanceof JsUndefined ? ' ' : TypeConversion::toString($fillArg);

            $currentLen = self::utf16Length($str);
            if ($currentLen >= $targetLength || $padStr === '') {
                return new JsString($str);
            }

            $needed = $targetLength - $currentLen;
            $padLen = self::utf16Length($padStr);
            $reps = (int) ceil($needed / max($padLen, 1));
            $pad = str_repeat($padStr, $reps);
            $pad = self::utf16Truncate($pad, $needed);

            return new JsString($pad . $str);
        };
    }

    private static function padEnd(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $targetLength = isset($args[0]) ? (int) TypeConversion::toNumber($args[0]) : 0;
            $fillArg = $args[1] ?? JsUndefined::instance();
            $padStr = $fillArg instanceof JsUndefined ? ' ' : TypeConversion::toString($fillArg);

            $currentLen = self::utf16Length($str);
            if ($currentLen >= $targetLength || $padStr === '') {
                return new JsString($str);
            }

            $needed = $targetLength - $currentLen;
            $padLen = self::utf16Length($padStr);
            $reps = (int) ceil($needed / max($padLen, 1));
            $pad = str_repeat($padStr, $reps);
            $pad = self::utf16Truncate($pad, $needed);

            return new JsString($str . $pad);
        };
    }
}
