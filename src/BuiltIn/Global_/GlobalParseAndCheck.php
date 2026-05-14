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
 * GlobalObject trait part: GlobalParseAndCheck. Composed into GlobalObject
 * via `use Global_\GlobalParseAndCheck;`.
 */
trait GlobalParseAndCheck
{
    private static function parseInt(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $string = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            $radixArg = $args[1] ?? JsUndefined::instance();
            $radix = $radixArg instanceof JsUndefined
                ? 0
                : TypeConversion::toInt32($radixArg);

            // Strip leading/trailing ECMAScript whitespace per spec.
            // Do NOT use \s — PCRE2 \s includes U+180E which ES removed.
            $ws = '[\x09\x0A\x0B\x0C\x0D\x20'
                . '\x{00A0}\x{FEFF}\x{1680}'
                . '\x{2000}-\x{200A}'
                . '\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]';
            $replaced = preg_replace(
                '/^' . $ws . '+|' . $ws . '+$/u',
                '',
                $string,
            );
            // preg_replace returns null on invalid UTF-8; fall back to ASCII trim.
            $string = $replaced ?? trim($string, " \t\n\r\x0B\x0C");
            if ($string === '') {
                return JsNumber::of(NAN);
            }

            $negative = false;
            if ($string[0] === '-') {
                $negative = true;
                $string = substr($string, 1);
            } elseif ($string[0] === '+') {
                $string = substr($string, 1);
            }

            if ($radix === 0) {
                if (str_starts_with($string, '0x') || str_starts_with($string, '0X')) {
                    $radix = 16;
                    $string = substr($string, 2);
                } else {
                    $radix = 10;
                }
            } elseif ($radix === 16) {
                if (str_starts_with($string, '0x') || str_starts_with($string, '0X')) {
                    $string = substr($string, 2);
                }
            }

            if ($radix < 2 || $radix > 36) {
                return JsNumber::of(NAN);
            }

            $validChars = substr('0123456789abcdefghijklmnopqrstuvwxyz', 0, $radix);
            $result = '';
            for ($i = 0; $i < strlen($string); $i++) {
                $ch = strtolower($string[$i]);
                if (!str_contains($validChars, $ch)) {
                    break;
                }
                $result .= $ch;
            }

            if ($result === '') {
                return JsNumber::of(NAN);
            }

            // Use float arithmetic to avoid PHP_INT overflow.
            $value = 0.0;
            for ($j = 0; $j < strlen($result); $j++) {
                $digit = strpos(
                    '0123456789abcdefghijklmnopqrstuvwxyz',
                    $result[$j],
                );
                $value = $value * $radix + $digit;
            }
            return JsNumber::of($negative ? -$value : $value);
        };
    }

    private static function parseFloat(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $string = isset($args[0])
                ? TypeConversion::toString($args[0])
                : 'undefined';
            // Strip leading ECMAScript whitespace per spec (no \s — excludes U+180E).
            $ws = '[\x09\x0A\x0B\x0C\x0D\x20'
                . '\x{00A0}\x{FEFF}\x{1680}'
                . '\x{2000}-\x{200A}'
                . '\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]';
            $replaced = preg_replace(
                '/^' . $ws . '+/u',
                '',
                $string,
            );
            // preg_replace returns null on invalid UTF-8; fall back to ASCII ltrim.
            $string = $replaced ?? ltrim($string, " \t\n\r\x0B\x0C");

            if ($string === '') {
                return JsNumber::of(NAN);
            }

            // Check for Infinity prefix (not exact match).
            if (
                str_starts_with($string, 'Infinity')
                || str_starts_with($string, '+Infinity')
            ) {
                return JsNumber::of(INF);
            }
            if (str_starts_with($string, '-Infinity')) {
                return JsNumber::of(-INF);
            }

            if (
                preg_match(
                    '/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?/',
                    $string,
                    $matches,
                )
            ) {
                return JsNumber::of((float) $matches[0]);
            }

            return JsNumber::of(NAN);
        };
    }

    private static function isNaN(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $value = isset($args[0]) ? TypeConversion::toNumber($args[0]) : NAN;
            return new JsBoolean(is_nan($value));
        };
    }

    private static function isFinite(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $value = isset($args[0]) ? TypeConversion::toNumber($args[0]) : NAN;
            return new JsBoolean(is_finite($value));
        };
    }
}
