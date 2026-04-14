<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Runtime\Environment;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsArray;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;
use PhpJs\Object\PropertyDescriptor;

class StringPrototype
{
    public static function install(Environment $env): void
    {
        $proto = new JsObject();

        $proto->defineOwnProperty('charAt', PropertyDescriptor::data(JsFunction::fromCallable('charAt', self::charAt(), 1), true, false, true));
        $proto->defineOwnProperty('charCodeAt', PropertyDescriptor::data(JsFunction::fromCallable('charCodeAt', self::charCodeAt(), 1), true, false, true));
        $proto->defineOwnProperty('indexOf', PropertyDescriptor::data(JsFunction::fromCallable('indexOf', self::indexOf(), 1), true, false, true));
        $proto->defineOwnProperty('lastIndexOf', PropertyDescriptor::data(JsFunction::fromCallable('lastIndexOf', self::lastIndexOf(), 1), true, false, true));
        $proto->defineOwnProperty('includes', PropertyDescriptor::data(JsFunction::fromCallable('includes', self::includes(), 1), true, false, true));
        $proto->defineOwnProperty('startsWith', PropertyDescriptor::data(JsFunction::fromCallable('startsWith', self::startsWith(), 1), true, false, true));
        $proto->defineOwnProperty('endsWith', PropertyDescriptor::data(JsFunction::fromCallable('endsWith', self::endsWith(), 1), true, false, true));
        $proto->defineOwnProperty('slice', PropertyDescriptor::data(JsFunction::fromCallable('slice', self::slice(), 2), true, false, true));
        $proto->defineOwnProperty('substring', PropertyDescriptor::data(JsFunction::fromCallable('substring', self::substring(), 2), true, false, true));
        $proto->defineOwnProperty('toLowerCase', PropertyDescriptor::data(JsFunction::fromCallable('toLowerCase', self::toLowerCase(), 0), true, false, true));
        $proto->defineOwnProperty('toUpperCase', PropertyDescriptor::data(JsFunction::fromCallable('toUpperCase', self::toUpperCase(), 0), true, false, true));
        $proto->defineOwnProperty('trim', PropertyDescriptor::data(JsFunction::fromCallable('trim', self::trim(), 0), true, false, true));
        $proto->defineOwnProperty('trimStart', PropertyDescriptor::data(JsFunction::fromCallable('trimStart', self::trimStart(), 0), true, false, true));
        $proto->defineOwnProperty('trimEnd', PropertyDescriptor::data(JsFunction::fromCallable('trimEnd', self::trimEnd(), 0), true, false, true));
        $proto->defineOwnProperty('split', PropertyDescriptor::data(JsFunction::fromCallable('split', self::split(), 2), true, false, true));
        $proto->defineOwnProperty('replace', PropertyDescriptor::data(JsFunction::fromCallable('replace', self::replace(), 2), true, false, true));
        $proto->defineOwnProperty('repeat', PropertyDescriptor::data(JsFunction::fromCallable('repeat', self::repeat(), 1), true, false, true));
        $proto->defineOwnProperty('padStart', PropertyDescriptor::data(JsFunction::fromCallable('padStart', self::padStart(), 1), true, false, true));
        $proto->defineOwnProperty('padEnd', PropertyDescriptor::data(JsFunction::fromCallable('padEnd', self::padEnd(), 1), true, false, true));
        $proto->defineOwnProperty('concat', PropertyDescriptor::data(JsFunction::fromCallable('concat', self::concat(), 1), true, false, true));
        $proto->defineOwnProperty('at', PropertyDescriptor::data(JsFunction::fromCallable('at', self::at(), 1), true, false, true));
        $proto->defineOwnProperty('replaceAll', PropertyDescriptor::data(JsFunction::fromCallable('replaceAll', self::replaceAll(), 2), true, false, true));
        $proto->defineOwnProperty('search', PropertyDescriptor::data(JsFunction::fromCallable('search', self::search(), 1), true, false, true));
        $proto->defineOwnProperty('match', PropertyDescriptor::data(JsFunction::fromCallable('match', self::matchFn(), 1), true, false, true));
        $proto->defineOwnProperty('matchAll', PropertyDescriptor::data(JsFunction::fromCallable('matchAll', self::matchAll(), 1), true, false, true));
        $proto->defineOwnProperty('codePointAt', PropertyDescriptor::data(JsFunction::fromCallable('codePointAt', self::codePointAt(), 1), true, false, true));
        $proto->defineOwnProperty('normalize', PropertyDescriptor::data(JsFunction::fromCallable('normalize', self::normalize(), 0), true, false, true));
        $proto->defineOwnProperty('localeCompare', PropertyDescriptor::data(JsFunction::fromCallable('localeCompare', self::localeCompare(), 1), true, false, true));
        $proto->defineOwnProperty('toString', PropertyDescriptor::data(JsFunction::fromCallable('toString', self::toStringFn(), 0), true, false, true));
        $proto->defineOwnProperty('valueOf', PropertyDescriptor::data(JsFunction::fromCallable('valueOf', self::toStringFn(), 0), true, false, true));

        // Augment the existing String constructor with the prototype.
        $existing = $env->get('String');
        if ($existing instanceof JsFunction) {
            $existing->set('prototype', $proto);
            $proto->defineOwnProperty('constructor', PropertyDescriptor::data($existing, true, false, true));

            // Static methods on String constructor.
            $existing->set('fromCharCode', JsFunction::fromCallable('fromCharCode', self::fromCharCode(), 1));
            $existing->set('fromCodePoint', JsFunction::fromCallable('fromCodePoint', self::fromCodePoint(), 1));
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
            $limit = isset($args[1]) && !($args[1] instanceof JsUndefined)
                ? (int) TypeConversion::toNumber($args[1]) : PHP_INT_MAX;

            if ($separator instanceof JsUndefined) {
                return JsArray::fromArray([new JsString($str)]);
            }

            // RegExp separator
            if ($separator instanceof JsObject && $separator->has('source')) {
                $pattern = TypeConversion::toString($separator->get('source'));
                $flags = $separator->has('flags') ? TypeConversion::toString($separator->get('flags')) : '';
                $pcreFlags = '';
                if (str_contains($flags, 'i')) {
                    $pcreFlags .= 'i';
                }
                if (str_contains($flags, 'm')) {
                    $pcreFlags .= 'm';
                }
                $pcre = '/' . str_replace('/', '\\/', $pattern) . '/' . $pcreFlags . 'u';
                $parts = @preg_split($pcre, $str, $limit < PHP_INT_MAX ? (int) $limit + 1 : -1);
                if ($parts === false) {
                    $parts = [$str];
                }
                if ($limit < count($parts)) {
                    $parts = array_slice($parts, 0, $limit);
                }
                return JsArray::fromArray(array_map(fn($p) => new JsString($p), $parts));
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
            $searchArg = $args[0] ?? JsUndefined::instance();
            $replArg = $args[1] ?? JsUndefined::instance();

            // Check if search is a RegExp-like object (has source and flags)
            if ($searchArg instanceof JsObject && $searchArg->has('source')) {
                $pattern = TypeConversion::toString($searchArg->get('source'));
                $flags = $searchArg->has('flags') ? TypeConversion::toString($searchArg->get('flags')) : '';
                $pcreFlags = '';
                if (str_contains($flags, 'i')) {
                    $pcreFlags .= 'i';
                }
                if (str_contains($flags, 'm')) {
                    $pcreFlags .= 'm';
                }
                if (str_contains($flags, 's')) {
                    $pcreFlags .= 's';
                }
                $pcre = '/' . str_replace('/', '\\/', $pattern) . '/' . $pcreFlags . 'u';
                $isGlobal = str_contains($flags, 'g');
                $limit = $isGlobal ? -1 : 1;

                if ($replArg instanceof JsFunction) {
                    $result = @preg_replace_callback($pcre, function ($matches) use ($replArg, $str): string {
                        $jsArgs = array_map(fn($m) => new JsString($m), $matches);
                        $jsArgs[] = new JsNumber(0.0); // offset (simplified)
                        $jsArgs[] = new JsString($str);
                        $ret = $replArg->call(JsUndefined::instance(), $jsArgs);
                        return TypeConversion::toString($ret);
                    }, $str, $limit);
                } else {
                    $repl = TypeConversion::toString($replArg);
                    $result = @preg_replace($pcre, $repl, $str, $limit);
                }
                return new JsString($result ?? $str);
            }

            // String search — replace first occurrence only
            $search = TypeConversion::toString($searchArg);
            if ($replArg instanceof JsFunction) {
                $pos = strpos($str, $search);
                if ($pos === false) {
                    return new JsString($str);
                }
                $jsArgs = [new JsString($search), new JsNumber((float) $pos), new JsString($str)];
                $ret = $replArg->call(JsUndefined::instance(), $jsArgs);
                $replacement = TypeConversion::toString($ret);
            } else {
                $replacement = TypeConversion::toString($replArg);
            }

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

    private static function at(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $index = isset($args[0]) ? (int) TypeConversion::toNumber($args[0]) : 0;
            $len = mb_strlen($str, 'UTF-8');
            if ($index < 0) {
                $index = $len + $index;
            }
            if ($index < 0 || $index >= $len) {
                return JsUndefined::instance();
            }
            return new JsString(mb_substr($str, $index, 1, 'UTF-8'));
        };
    }

    private static function replaceAll(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $search = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';

            $replacer = $args[1] ?? JsUndefined::instance();

            if ($search === '') {
                if ($replacer instanceof JsFunction) {
                    $result = '';
                    $len = mb_strlen($str, 'UTF-8');
                    for ($i = 0; $i <= $len; $i++) {
                        $repVal = $replacer->call(JsUndefined::instance(), [
                            new JsString(''),
                            new JsNumber((float) $i),
                            new JsString($str),
                        ]);
                        $result .= TypeConversion::toString($repVal);
                        if ($i < $len) {
                            $result .= mb_substr($str, $i, 1, 'UTF-8');
                        }
                    }
                    return new JsString($result);
                }
                $replacement = TypeConversion::toString($replacer);
                $len = mb_strlen($str, 'UTF-8');
                $result = $replacement;
                for ($i = 0; $i < $len; $i++) {
                    $result .= mb_substr($str, $i, 1, 'UTF-8') . $replacement;
                }
                return new JsString($result);
            }

            if ($replacer instanceof JsFunction) {
                $result = '';
                $offset = 0;
                while (($pos = mb_strpos($str, $search, $offset, 'UTF-8')) !== false) {
                    $result .= mb_substr($str, $offset, $pos - $offset, 'UTF-8');
                    $repVal = $replacer->call(JsUndefined::instance(), [
                        new JsString($search),
                        new JsNumber((float) $pos),
                        new JsString($str),
                    ]);
                    $result .= TypeConversion::toString($repVal);
                    $offset = $pos + mb_strlen($search, 'UTF-8');
                }
                $result .= mb_substr($str, $offset, null, 'UTF-8');
                return new JsString($result);
            }

            $replacement = TypeConversion::toString($replacer);
            return new JsString(str_replace($search, $replacement, $str));
        };
    }

    private static function search(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $search = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            $pos = mb_strpos($str, $search, 0, 'UTF-8');
            return new JsNumber($pos === false ? -1.0 : (float) $pos);
        };
    }

    private static function matchFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $pattern = isset($args[0]) ? TypeConversion::toString($args[0]) : '';

            $pos = mb_strpos($str, $pattern, 0, 'UTF-8');
            if ($pos === false) {
                return JsNull::instance();
            }
            $result = JsArray::fromArray([new JsString($pattern)]);
            $result->set('index', new JsNumber((float) $pos));
            $result->set('input', new JsString($str));
            return $result;
        };
    }

    private static function matchAll(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $search = isset($args[0]) ? TypeConversion::toString($args[0]) : '';

            $matches = [];
            $offset = 0;
            if ($search === '') {
                $len = mb_strlen($str, 'UTF-8');
                for ($i = 0; $i <= $len; $i++) {
                    $match = JsArray::fromArray([new JsString('')]);
                    $match->set('index', new JsNumber((float) $i));
                    $match->set('input', new JsString($str));
                    $matches[] = $match;
                }
            } else {
                while (($pos = mb_strpos($str, $search, $offset, 'UTF-8')) !== false) {
                    $match = JsArray::fromArray([new JsString($search)]);
                    $match->set('index', new JsNumber((float) $pos));
                    $match->set('input', new JsString($str));
                    $matches[] = $match;
                    $offset = $pos + mb_strlen($search, 'UTF-8');
                }
            }

            $idx = 0;
            $iterator = new JsObject();
            $nextFn = function () use (&$idx, $matches): JsValue {
                $result = new JsObject();
                if ($idx < count($matches)) {
                    $result->set('value', $matches[$idx]);
                    $result->set('done', new JsBoolean(false));
                    $idx++;
                } else {
                    $result->set('value', JsUndefined::instance());
                    $result->set('done', new JsBoolean(true));
                }
                return $result;
            };
            $iterator->set('next', JsFunction::fromCallable('next', $nextFn));
            $iterSym = \PhpJs\BuiltIn\SymbolConstructor::iterator();
            $iterator->setBySymbol($iterSym, JsFunction::fromCallable(
                '[Symbol.iterator]',
                function () use ($iterator): JsValue {
                    return $iterator;
                },
            ));
            return $iterator;
        };
    }

    private static function codePointAt(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $index = isset($args[0]) ? (int) TypeConversion::toNumber($args[0]) : 0;
            $len = mb_strlen($str, 'UTF-8');
            if ($index < 0 || $index >= $len) {
                return JsUndefined::instance();
            }
            $char = mb_substr($str, $index, 1, 'UTF-8');
            return new JsNumber((float) mb_ord($char, 'UTF-8'));
        };
    }

    private static function normalize(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $form = isset($args[0]) && !($args[0] instanceof JsUndefined)
                ? TypeConversion::toString($args[0]) : 'NFC';
            if (function_exists('normalizer_normalize')) {
                /** @var int $formConst */
                $formConst = match (strtoupper($form)) {
                    'NFC' => 4, // Normalizer::FORM_C
                    'NFD' => 2, // Normalizer::FORM_D
                    'NFKC' => 5, // Normalizer::FORM_KC
                    'NFKD' => 3, // Normalizer::FORM_KD
                    default => throw new \PhpJs\Exceptions\RangeError(
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

    private static function localeCompare(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $that = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            $cmp = strcmp($str, $that);
            if ($cmp < 0) {
                return new JsNumber(-1.0);
            }
            if ($cmp > 0) {
                return new JsNumber(1.0);
            }
            return new JsNumber(0.0);
        };
    }

    private static function fromCharCode(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = '';
            foreach ($args as $arg) {
                $code = \PhpJs\Spec\TypeConversion::toUint16($arg);
                $str .= mb_chr($code, 'UTF-8');
            }
            return new JsString($str);
        };
    }

    private static function fromCodePoint(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = '';
            foreach ($args as $arg) {
                $code = (int) TypeConversion::toNumber($arg);
                if ($code < 0 || $code > 0x10FFFF || floor((float) $code) !== (float) $code) {
                    throw new \PhpJs\Exceptions\RangeError("Invalid code point {$code}");
                }
                $str .= mb_chr($code, 'UTF-8');
            }
            return new JsString($str);
        };
    }
}
