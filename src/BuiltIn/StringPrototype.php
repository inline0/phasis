<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Runtime\Environment;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsArray;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

class StringPrototype
{
    public static function install(Environment $env): void
    {
        $proto = new JsObject();

        $proto->set('charAt', JsFunction::fromCallable('charAt', self::charAt()));
        $proto->set('charCodeAt', JsFunction::fromCallable('charCodeAt', self::charCodeAt()));
        $proto->set('indexOf', JsFunction::fromCallable('indexOf', self::indexOf()));
        $proto->set('lastIndexOf', JsFunction::fromCallable('lastIndexOf', self::lastIndexOf()));
        $proto->set('includes', JsFunction::fromCallable('includes', self::includes()));
        $proto->set('startsWith', JsFunction::fromCallable('startsWith', self::startsWith()));
        $proto->set('endsWith', JsFunction::fromCallable('endsWith', self::endsWith()));
        $proto->set('slice', JsFunction::fromCallable('slice', self::slice()));
        $proto->set('substring', JsFunction::fromCallable('substring', self::substring()));
        $proto->set('toLowerCase', JsFunction::fromCallable('toLowerCase', self::toLowerCase()));
        $proto->set('toUpperCase', JsFunction::fromCallable('toUpperCase', self::toUpperCase()));
        $proto->set('trim', JsFunction::fromCallable('trim', self::trim()));
        $proto->set('trimStart', JsFunction::fromCallable('trimStart', self::trimStart()));
        $proto->set('trimEnd', JsFunction::fromCallable('trimEnd', self::trimEnd()));
        $proto->set('split', JsFunction::fromCallable('split', self::split()));
        $proto->set('replace', JsFunction::fromCallable('replace', self::replace()));
        $proto->set('repeat', JsFunction::fromCallable('repeat', self::repeat()));
        $proto->set('padStart', JsFunction::fromCallable('padStart', self::padStart()));
        $proto->set('padEnd', JsFunction::fromCallable('padEnd', self::padEnd()));
        $proto->set('concat', JsFunction::fromCallable('concat', self::concat()));
        $proto->set('toString', JsFunction::fromCallable('toString', self::toStringFn()));
        $proto->set('valueOf', JsFunction::fromCallable('valueOf', self::toStringFn()));

        // Augment the existing String constructor with the prototype.
        $existing = $env->get('String');
        if ($existing instanceof JsFunction) {
            $existing->set('prototype', $proto);
            $proto->set('constructor', $existing);
        }

        // Store the prototype so the interpreter can access it for auto-boxing.
        $env->defineVar('__StringPrototype__', $proto);
    }

    private static function extractString(JsValue $this_): string
    {
        if ($this_ instanceof JsString) {
            return $this_->value;
        }
        if ($this_ instanceof JsObject) {
            $prim = $this_->get('[[PrimitiveValue]]');
            if ($prim instanceof JsString) {
                return $prim->value;
            }
        }
        return TypeConversion::toString($this_);
    }

    private static function charAt(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $index = isset($args[0]) ? (int) TypeConversion::toNumber($args[0]) : 0;
            $len = mb_strlen($str, 'UTF-8');
            if ($index < 0 || $index >= $len) {
                return new JsString('');
            }
            return new JsString(mb_substr($str, $index, 1, 'UTF-8'));
        };
    }

    private static function charCodeAt(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $index = isset($args[0]) ? (int) TypeConversion::toNumber($args[0]) : 0;
            $len = mb_strlen($str, 'UTF-8');
            if ($index < 0 || $index >= $len) {
                return new JsNumber(NAN);
            }
            $char = mb_substr($str, $index, 1, 'UTF-8');
            return new JsNumber((float) mb_ord($char, 'UTF-8'));
        };
    }

    private static function indexOf(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $search = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            $fromIndex = isset($args[1]) ? (int) TypeConversion::toNumber($args[1]) : 0;

            if ($fromIndex < 0) {
                $fromIndex = 0;
            }

            $pos = mb_strpos($str, $search, $fromIndex, 'UTF-8');
            return new JsNumber($pos === false ? -1.0 : (float) $pos);
        };
    }

    private static function lastIndexOf(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $search = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            $fromIndex = isset($args[1]) ? (int) TypeConversion::toNumber($args[1]) : mb_strlen($str, 'UTF-8');

            if ($search === '') {
                return new JsNumber((float) min($fromIndex, mb_strlen($str, 'UTF-8')));
            }

            $pos = mb_strrpos($str, $search, 0, 'UTF-8');
            if ($pos === false) {
                return new JsNumber(-1.0);
            }
            // Only consider positions up to fromIndex.
            if ($pos > $fromIndex) {
                // Search again in the limited range.
                $sub = mb_substr($str, 0, $fromIndex + mb_strlen($search, 'UTF-8'), 'UTF-8');
                $pos = mb_strrpos($sub, $search, 0, 'UTF-8');
                if ($pos === false) {
                    return new JsNumber(-1.0);
                }
            }
            return new JsNumber((float) $pos);
        };
    }

    private static function includes(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $search = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            $fromIndex = isset($args[1]) ? (int) TypeConversion::toNumber($args[1]) : 0;

            if ($fromIndex < 0) {
                $fromIndex = 0;
            }

            $pos = mb_strpos($str, $search, $fromIndex, 'UTF-8');
            return new JsBoolean($pos !== false);
        };
    }

    private static function startsWith(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $search = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            $position = isset($args[1]) ? (int) TypeConversion::toNumber($args[1]) : 0;

            if ($position < 0) {
                $position = 0;
            }

            $sub = mb_substr($str, $position, mb_strlen($search, 'UTF-8'), 'UTF-8');
            return new JsBoolean($sub === $search);
        };
    }

    private static function endsWith(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $search = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            $strLen = mb_strlen($str, 'UTF-8');
            $endPosition = isset($args[1]) ? (int) TypeConversion::toNumber($args[1]) : $strLen;

            if ($endPosition > $strLen) {
                $endPosition = $strLen;
            }

            $searchLen = mb_strlen($search, 'UTF-8');
            $start = $endPosition - $searchLen;
            if ($start < 0) {
                return new JsBoolean(false);
            }

            $sub = mb_substr($str, $start, $searchLen, 'UTF-8');
            return new JsBoolean($sub === $search);
        };
    }

    private static function slice(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $len = mb_strlen($str, 'UTF-8');
            $start = isset($args[0]) ? (int) TypeConversion::toNumber($args[0]) : 0;
            $end = isset($args[1]) ? (int) TypeConversion::toNumber($args[1]) : $len;

            if ($start < 0) {
                $start = max($len + $start, 0);
            }
            if ($end < 0) {
                $end = max($len + $end, 0);
            }

            $start = min($start, $len);
            $end = min($end, $len);

            if ($start >= $end) {
                return new JsString('');
            }

            return new JsString(mb_substr($str, $start, $end - $start, 'UTF-8'));
        };
    }

    private static function substring(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $len = mb_strlen($str, 'UTF-8');
            $start = isset($args[0]) ? (int) TypeConversion::toNumber($args[0]) : 0;
            $end = isset($args[1]) ? (int) TypeConversion::toNumber($args[1]) : $len;

            // Clamp to [0, length].
            $start = max(0, min($start, $len));
            $end = max(0, min($end, $len));

            // If start > end, swap them.
            if ($start > $end) {
                [$start, $end] = [$end, $start];
            }

            return new JsString(mb_substr($str, $start, $end - $start, 'UTF-8'));
        };
    }

    private static function toLowerCase(): \Closure
    {
        return function (JsValue $this_): JsValue {
            return new JsString(mb_strtolower(self::extractString($this_), 'UTF-8'));
        };
    }

    private static function toUpperCase(): \Closure
    {
        return function (JsValue $this_): JsValue {
            return new JsString(mb_strtoupper(self::extractString($this_), 'UTF-8'));
        };
    }

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

    private static function split(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $separator = $args[0] ?? JsUndefined::instance();
            $limit = isset($args[1]) ? (int) TypeConversion::toNumber($args[1]) : PHP_INT_MAX;

            if ($separator instanceof JsUndefined) {
                return JsArray::fromArray([new JsString($str)]);
            }

            $sep = TypeConversion::toString($separator);

            if ($sep === '') {
                // Split into individual characters.
                $chars = [];
                $len = mb_strlen($str, 'UTF-8');
                for ($i = 0; $i < $len && $i < $limit; $i++) {
                    $chars[] = new JsString(mb_substr($str, $i, 1, 'UTF-8'));
                }
                return JsArray::fromArray($chars);
            }

            $parts = explode($sep, $str);
            if ($limit < count($parts)) {
                $parts = array_slice($parts, 0, $limit);
            }

            $jsParts = array_map(fn(string $p) => new JsString($p), $parts);
            return JsArray::fromArray($jsParts);
        };
    }

    private static function replace(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $search = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            $replacement = isset($args[1]) ? TypeConversion::toString($args[1]) : 'undefined';

            // Replace first occurrence only.
            $pos = strpos($str, $search);
            if ($pos === false) {
                return new JsString($str);
            }

            $result = substr($str, 0, $pos) . $replacement . substr($str, $pos + strlen($search));
            return new JsString($result);
        };
    }

    private static function repeat(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $count = isset($args[0]) ? (int) TypeConversion::toNumber($args[0]) : 0;

            if ($count < 0) {
                throw new \PhpJs\Exceptions\RangeError('Invalid count value');
            }

            return new JsString(str_repeat($str, $count));
        };
    }

    private static function padStart(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $targetLength = isset($args[0]) ? (int) TypeConversion::toNumber($args[0]) : 0;
            $padStr = isset($args[1]) ? TypeConversion::toString($args[1]) : ' ';

            $currentLen = mb_strlen($str, 'UTF-8');
            if ($currentLen >= $targetLength || $padStr === '') {
                return new JsString($str);
            }

            $needed = $targetLength - $currentLen;
            $padLen = mb_strlen($padStr, 'UTF-8');
            $pad = '';
            while (mb_strlen($pad, 'UTF-8') < $needed) {
                $pad .= $padStr;
            }
            $pad = mb_substr($pad, 0, $needed, 'UTF-8');

            return new JsString($pad . $str);
        };
    }

    private static function padEnd(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $targetLength = isset($args[0]) ? (int) TypeConversion::toNumber($args[0]) : 0;
            $padStr = isset($args[1]) ? TypeConversion::toString($args[1]) : ' ';

            $currentLen = mb_strlen($str, 'UTF-8');
            if ($currentLen >= $targetLength || $padStr === '') {
                return new JsString($str);
            }

            $needed = $targetLength - $currentLen;
            $padLen = mb_strlen($padStr, 'UTF-8');
            $pad = '';
            while (mb_strlen($pad, 'UTF-8') < $needed) {
                $pad .= $padStr;
            }
            $pad = mb_substr($pad, 0, $needed, 'UTF-8');

            return new JsString($str . $pad);
        };
    }

    private static function concat(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            foreach ($args as $arg) {
                $str .= TypeConversion::toString($arg);
            }
            return new JsString($str);
        };
    }

    private static function toStringFn(): \Closure
    {
        return function (JsValue $this_): JsValue {
            return new JsString(self::extractString($this_));
        };
    }
}
