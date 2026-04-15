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

        $d = static fn (string $n, \Closure $fn, int $len) => $proto->defineOwnProperty(
            $n,
            PropertyDescriptor::data(JsFunction::fromCallable($n, $fn, $len), true, false, true),
        );

        $d('charAt', self::charAt(), 1);
        $d('charCodeAt', self::charCodeAt(), 1);
        $d('indexOf', self::indexOf(), 1);
        $d('lastIndexOf', self::lastIndexOf(), 1);
        $d('includes', self::includes(), 1);
        $d('startsWith', self::startsWith(), 1);
        $d('endsWith', self::endsWith(), 1);
        $d('slice', self::slice(), 2);
        $d('substring', self::substring(), 2);
        $d('toLowerCase', self::toLowerCase(), 0);
        $d('toUpperCase', self::toUpperCase(), 0);
        $d('trim', self::trim(), 0);
        $d('trimStart', self::trimStart(), 0);
        $d('trimEnd', self::trimEnd(), 0);
        $d('split', self::split(), 2);
        $d('replace', self::replace(), 2);
        $d('repeat', self::repeat(), 1);
        $d('padStart', self::padStart(), 1);
        $d('padEnd', self::padEnd(), 1);
        $d('concat', self::concat(), 1);
        $d('at', self::at(), 1);
        $d('replaceAll', self::replaceAll(), 2);
        $d('search', self::search(), 1);
        $d('matchAll', self::matchAll(), 1);
        $d('codePointAt', self::codePointAt(), 1);
        $d('normalize', self::normalize(), 0);
        $d('localeCompare', self::localeCompare(), 1);

        // String.prototype.length is 0 per spec (it is the empty string object).
        $proto->defineOwnProperty('length', PropertyDescriptor::data(new JsNumber(0), false, false, false));

        // AnnexB methods: B.2.3.1 String.prototype.substr(start, length)
        $d('substr', function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $size = mb_strlen($str, 'UTF-8');

            // Step 4: intStart = ToIntegerOrInfinity(start)
            $intStart = isset($args[0])
                ? TypeConversion::toIntegerOrInfinity($args[0])
                : 0.0;

            // Steps 5-7: normalize intStart
            if ($intStart === -INF) {
                $intStart = 0;
            } elseif ($intStart < 0) {
                $intStart = (int) max($size + $intStart, 0);
            } else {
                $intStart = (int) min($intStart, $size);
            }

            // Step 8: intLength (keep as float for Infinity handling)
            $lengthArg = $args[1] ?? JsUndefined::instance();
            $rawLength = $lengthArg instanceof JsUndefined
                ? (float) $size
                : TypeConversion::toIntegerOrInfinity($lengthArg);

            // Step 9: clamp intLength to [0, size] (float min/max handles INF)
            $intLength = (int) min(max($rawLength, 0), $size);

            // Step 10: intEnd
            $intEnd = (int) min($intStart + $intLength, $size);

            if ($intEnd <= $intStart) {
                return new JsString('');
            }

            return new JsString(mb_substr($str, $intStart, $intEnd - $intStart, 'UTF-8'));
        }, 2);

        // trimLeft/trimRight must be the SAME function object as trimStart/trimEnd
        // per B.2.3.12 and B.2.3.15, including .name = "trimStart"/"trimEnd".
        $trimStartFn = $proto->get('trimStart');
        $trimEndFn = $proto->get('trimEnd');
        $proto->defineOwnProperty(
            'trimLeft',
            PropertyDescriptor::data($trimStartFn, true, false, true),
        );
        $proto->defineOwnProperty(
            'trimRight',
            PropertyDescriptor::data($trimEndFn, true, false, true),
        );

        // AnnexB HTML methods
        $htmlTag = function (string $tag, ?string $attr = null) {
            return function (JsValue $this_, array $args) use ($tag, $attr): JsValue {
                $str = self::extractString($this_);
                if ($attr !== null) {
                    $val = isset($args[0]) ? TypeConversion::toString($args[0]) : '';
                    $val = str_replace('"', '&quot;', $val);
                    return new JsString("<{$tag} {$attr}=\"{$val}\">{$str}</{$tag}>");
                }
                return new JsString("<{$tag}>{$str}</{$tag}>");
            };
        };
        $d('anchor', $htmlTag('a', 'name'), 1);
        $d('big', $htmlTag('big'), 0);
        $d('blink', $htmlTag('blink'), 0);
        $d('bold', $htmlTag('b'), 0);
        $d('fixed', $htmlTag('tt'), 0);
        $d('fontcolor', $htmlTag('font', 'color'), 1);
        $d('fontsize', $htmlTag('font', 'size'), 1);
        $d('italics', $htmlTag('i'), 0);
        $d('link', $htmlTag('a', 'href'), 1);
        $d('small', $htmlTag('small'), 0);
        $d('strike', $htmlTag('strike'), 0);
        $d('sub', $htmlTag('sub'), 0);
        $d('sup', $htmlTag('sup'), 0);

        // match uses a different static method name to avoid PHP keyword conflict.
        $proto->defineOwnProperty(
            'match',
            PropertyDescriptor::data(JsFunction::fromCallable('match', self::matchFn(), 1), true, false, true),
        );
        $proto->defineOwnProperty(
            'toString',
            PropertyDescriptor::data(JsFunction::fromCallable('toString', self::toStringFn(), 0), true, false, true),
        );
        $proto->defineOwnProperty(
            'valueOf',
            PropertyDescriptor::data(JsFunction::fromCallable('valueOf', self::toStringFn(), 0), true, false, true),
        );

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

    /**
     * RequireObjectCoercible(this) then coerce to string.
     *
     * Per spec, all String.prototype methods must reject null and undefined
     * with a TypeError before any coercion occurs.
     */
    private static function extractString(JsValue $this_): string
    {
        if ($this_ instanceof JsUndefined || $this_ instanceof JsNull) {
            throw new \PhpJs\Exceptions\TypeError(
                'String.prototype method called on null or undefined',
            );
        }
        if ($this_ instanceof JsString) {
            return $this_->value;
        }
        if ($this_ instanceof JsObject) {
            $prim = $this_->get('[[PrimitiveValue]]');
            if ($prim instanceof JsString) {
                return $prim->value;
            }
            // Object wrapping a non-string primitive (e.g. new Object(42),
            // new Object(true)): convert the inner primitive to string so
            // that String.prototype methods called on the wrapper produce
            // the same result as in V8.
            if (
                $prim instanceof JsValue
                && !($prim instanceof JsUndefined)
                && !($prim instanceof JsObject)
            ) {
                return TypeConversion::toString($prim);
            }
        }
        return TypeConversion::toString($this_);
    }

    private static function charAt(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            // ToInteger(pos): call ToNumber first (may throw TypeError for
            // objects without valueOf/toString, e.g. Object.create(null)).
            $posNum = isset($args[0]) ? TypeConversion::toNumber($args[0]) : 0.0;
            $index = (int) $posNum;
            if (is_nan($posNum)) {
                $index = 0;
            }
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
            $separator = $args[0] ?? JsUndefined::instance();
            $limitArg = $args[1] ?? JsUndefined::instance();

            // Per spec §21.1.3.20 step 2-3: check Symbol.split on separator
            if ($separator instanceof JsObject && !$separator instanceof JsNull) {
                $splitSym = SymbolConstructor::split();
                if ($splitSym !== null) {
                    $splitter = $separator->getBySymbol($splitSym);
                    if ($splitter instanceof JsFunction) {
                        return $splitter->call($separator, [$this_, $limitArg]);
                    }
                }
            }

            $str = self::extractString($this_);

            // Per spec: if limit is undefined, lim = 2^32 - 1; else lim = ToUint32(limit).
            $lim = $limitArg instanceof JsUndefined
                ? 0xFFFFFFFF
                : TypeConversion::toUint32($limitArg);

            if ($separator instanceof JsUndefined) {
                if ($lim === 0) {
                    return JsArray::fromArray([]);
                }
                return JsArray::fromArray([new JsString($str)]);
            }

            // Per spec step 7: ToString(separator) must be called before
            // checking if lim is 0. This ensures side effects from the
            // coercion are observable even when the result is unused.
            $isRegExp = $separator instanceof JsObject && $separator->has('source');
            if (!$isRegExp) {
                // Trigger ToString(separator) which may throw.
                TypeConversion::toString($separator);
            }

            // If lim is 0, return an empty array.
            if ($lim === 0) {
                return JsArray::fromArray([]);
            }

            // RegExp separator: implement the ES spec algorithm
            // (22.2.5.13 RegExp.prototype[@@split]) manually because
            // preg_split handles zero-length matches differently.
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
                if (str_contains($flags, 's')) {
                    $pcreFlags .= 's';
                }
                $pcre = '/' . str_replace('/', '\\/', $pattern) . '/' . $pcreFlags . 'u';

                $size = strlen($str);
                if ($size === 0) {
                    // Empty string: if regex matches empty string, return []; else [""].
                    if (@preg_match($pcre, '', $m) === 1) {
                        return JsArray::fromArray([]);
                    }
                    return JsArray::fromArray([new JsString($str)]);
                }

                // Implement 22.2.5.13 RegExp.prototype[@@split] manually.
                // p = end of last match (byte offset), q = current search pos.
                $result = [];
                $p = 0;
                $q = 0;

                while ($q < $size) {
                    // Try to find a match starting from offset q.
                    if (@preg_match($pcre, $str, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $q) !== 1) {
                        break;
                    }

                    $matchStart = $m[0][1];
                    $matchLen = strlen((string) $m[0][0]);
                    $e = $matchStart + $matchLen;

                    // Per spec: if the match starts at or beyond size, treat as no match.
                    if ($matchStart >= $size) {
                        break;
                    }

                    // Per spec step 12.c.iii.2: if e === p, advance q by one
                    // character and retry (prevents infinite loop on zero-length
                    // matches at the same split point).
                    if ($e === $p) {
                        $charLen = strlen(mb_substr($str, mb_strlen(substr($str, 0, $q), 'UTF-8'), 1, 'UTF-8'));
                        $q += max($charLen, 1);
                        continue;
                    }

                    // Push the substring between last split and match start.
                    $result[] = new JsString(substr($str, $p, $matchStart - $p));
                    if (count($result) >= $lim) {
                        return JsArray::fromArray($result);
                    }

                    // Push capture groups (indices 1..n).
                    for ($i = 1, $cnt = count($m); $i < $cnt; $i++) {
                        if (!is_array($m[$i]) || $m[$i][0] === null || $m[$i][1] === -1) {
                            $result[] = JsUndefined::instance();
                        } else {
                            $result[] = new JsString($m[$i][0]);
                        }
                        if (count($result) >= $lim) {
                            return JsArray::fromArray($result);
                        }
                    }

                    $p = $e;

                    // For zero-length matches, advance q by one character.
                    if ($matchLen === 0) {
                        $charLen = strlen(mb_substr($str, mb_strlen(substr($str, 0, $matchStart), 'UTF-8'), 1, 'UTF-8'));
                        $q = $matchStart + max($charLen, 1);
                    } else {
                        $q = $e;
                    }
                }

                // Push the trailing substring (from last split point to end).
                $result[] = new JsString(substr($str, $p));
                return JsArray::fromArray($result);
            }

            $sep = TypeConversion::toString($separator);

            if ($sep === '') {
                // Split into individual characters.
                $chars = [];
                $len = mb_strlen($str, 'UTF-8');
                for ($i = 0; $i < $len && $i < $lim; $i++) {
                    $chars[] = new JsString(mb_substr($str, $i, 1, 'UTF-8'));
                }
                return JsArray::fromArray($chars);
            }

            $parts = explode($sep, $str);
            if ($lim < count($parts)) {
                $parts = array_slice($parts, 0, $lim);
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
            $n = isset($args[0])
                ? TypeConversion::toIntegerOrInfinity($args[0])
                : 0.0;

            if ($n < 0 || $n === INF) {
                throw new \PhpJs\Exceptions\RangeError('Invalid count value');
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
            $searchArg = $args[0] ?? JsUndefined::instance();

            // RegExp argument: use PCRE to find the position.
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
                if (@preg_match($pcre, $str, $matches, PREG_OFFSET_CAPTURE)) {
                    // Convert byte offset to character position.
                    $charPos = mb_strlen(substr($str, 0, $matches[0][1]), 'UTF-8');
                    return new JsNumber((float) $charPos);
                }
                return new JsNumber(-1.0);
            }

            $search = TypeConversion::toString($searchArg);
            $pos = mb_strpos($str, $search, 0, 'UTF-8');
            return new JsNumber($pos === false ? -1.0 : (float) $pos);
        };
    }

    private static function matchFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $searchArg = $args[0] ?? JsUndefined::instance();

            // RegExp argument: use exec() for non-global, preg_match_all for global.
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

                if ($isGlobal) {
                    // Global match: return array of all match strings, no capture groups.
                    // Reset lastIndex to 0 per spec.
                    $searchArg->set('lastIndex', new JsNumber(0.0));
                    $count = @preg_match_all($pcre, $str, $matches);
                    if ($count === 0 || $count === false) {
                        return JsNull::instance();
                    }
                    $elements = [];
                    foreach ($matches[0] as $m) {
                        $elements[] = new JsString($m);
                    }
                    return JsArray::fromArray($elements);
                }

                // Non-global: return first match with capture groups, index, and input.
                // PREG_UNMATCHED_AS_NULL ensures non-participating groups still
                // appear in $matches (as null) so the result array has the
                // correct length matching JS behavior.
                if (@preg_match($pcre, $str, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
                    $numericCount = 0;
                    $elements = [];
                    foreach ($matches as $key => $match) {
                        if (is_int($key)) {
                            $elements[] = ($match[1] === -1 || $match[0] === null)
                                ? JsUndefined::instance()
                                : new JsString($match[0]);
                            $numericCount++;
                        }
                    }
                    $result = JsArray::fromArray($elements);
                    $charPos = mb_strlen(substr($str, 0, $matches[0][1]), 'UTF-8');
                    $result->set('index', new JsNumber((float) $charPos));
                    $result->set('input', new JsString($str));

                    // Named capture groups.
                    $groups = new JsObject(null);
                    $hasGroups = false;
                    foreach ($matches as $key => $match) {
                        if (is_string($key)) {
                            $hasGroups = true;
                            $groups->set($key, ($match[1] === -1 || $match[0] === null)
                                ? JsUndefined::instance()
                                : new JsString($match[0]));
                        }
                    }
                    $result->set('groups', $hasGroups ? $groups : JsUndefined::instance());

                    return $result;
                }
                return JsNull::instance();
            }

            // Per spec (22.1.3.11 step 6), non-RegExp arguments are coerced
            // to a RegExp via RegExpCreate(regexp, undefined). The raw value
            // (not ToString) is passed to the RegExp constructor, so
            // match(undefined) produces /(?:)/ (matches empty string).
            if ($searchArg instanceof JsUndefined) {
                $pcre = '/(?:)/u';
            } else {
                $pattern = TypeConversion::toString($searchArg);
                $escaped = preg_quote($pattern, '/');
                $pcre = '/' . $escaped . '/u';
            }
            if (@preg_match($pcre, $str, $matches, PREG_OFFSET_CAPTURE)) {
                $elements = [];
                foreach ($matches as $key => $match) {
                    if (is_int($key)) {
                        $elements[] = $match[1] === -1
                            ? JsUndefined::instance()
                            : new JsString($match[0]);
                    }
                }
                $result = JsArray::fromArray($elements);
                $charPos = mb_strlen(substr($str, 0, $matches[0][1]), 'UTF-8');
                $result->set('index', new JsNumber((float) $charPos));
                $result->set('input', new JsString($str));
                $result->set('groups', JsUndefined::instance());
                return $result;
            }
            return JsNull::instance();
        };
    }

    private static function matchAll(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $searchArg = $args[0] ?? JsUndefined::instance();

            $allMatches = [];

            // RegExp argument.
            if ($searchArg instanceof JsObject && $searchArg->has('source')) {
                $flags = $searchArg->has('flags') ? TypeConversion::toString($searchArg->get('flags')) : '';
                // matchAll requires the global flag per spec.
                if (!str_contains($flags, 'g')) {
                    throw new \PhpJs\Exceptions\TypeError(
                        'String.prototype.matchAll called with a non-global RegExp argument',
                    );
                }
                $pattern = TypeConversion::toString($searchArg->get('source'));
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

                $byteOffset = 0;
                while (@preg_match($pcre, $str, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $byteOffset)) {
                    $numericElements = [];
                    foreach ($m as $key => $val) {
                        if (is_int($key)) {
                            $numericElements[] = ($val[1] === -1 || $val[0] === null)
                                ? JsUndefined::instance()
                                : new JsString($val[0]);
                        }
                    }
                    $match = JsArray::fromArray($numericElements);
                    $charPos = mb_strlen(substr($str, 0, $m[0][1]), 'UTF-8');
                    $match->set('index', new JsNumber((float) $charPos));
                    $match->set('input', new JsString($str));

                    $groups = new JsObject(null);
                    $hasGroups = false;
                    foreach ($m as $key => $val) {
                        if (is_string($key)) {
                            $hasGroups = true;
                            $groups->set($key, ($val[1] === -1 || $val[0] === null)
                                ? JsUndefined::instance()
                                : new JsString($val[0]));
                        }
                    }
                    $match->set('groups', $hasGroups ? $groups : JsUndefined::instance());

                    $allMatches[] = $match;

                    // Advance past the match. For zero-length matches, advance by one byte.
                    $matchLen = strlen($m[0][0]);
                    $byteOffset = $m[0][1] + ($matchLen > 0 ? $matchLen : 1);
                    if ($byteOffset > strlen($str)) {
                        break;
                    }
                }
            } else {
                // String argument: treat as a literal string.
                $search = TypeConversion::toString($searchArg);
                $offset = 0;
                if ($search === '') {
                    $len = mb_strlen($str, 'UTF-8');
                    for ($i = 0; $i <= $len; $i++) {
                        $match = JsArray::fromArray([new JsString('')]);
                        $match->set('index', new JsNumber((float) $i));
                        $match->set('input', new JsString($str));
                        $allMatches[] = $match;
                    }
                } else {
                    while (($pos = mb_strpos($str, $search, $offset, 'UTF-8')) !== false) {
                        $match = JsArray::fromArray([new JsString($search)]);
                        $match->set('index', new JsNumber((float) $pos));
                        $match->set('input', new JsString($str));
                        $allMatches[] = $match;
                        $offset = $pos + mb_strlen($search, 'UTF-8');
                    }
                }
            }

            $idx = 0;
            $iterator = new JsObject();
            $nextFn = function () use (&$idx, $allMatches): JsValue {
                $result = new JsObject();
                if ($idx < count($allMatches)) {
                    $result->set('value', $allMatches[$idx]);
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
            $pos = isset($args[0]) ? TypeConversion::toIntegerOrInfinity($args[0]) : 0.0;

            // Convert UTF-8 string to UTF-16 code units to match JS semantics.
            $u16 = mb_convert_encoding($str, 'UTF-16LE', 'UTF-8');
            $size = (int) (strlen($u16) / 2);

            if ($pos < 0 || $pos >= $size) {
                return JsUndefined::instance();
            }

            $position = (int) $pos;
            $first = ord($u16[$position * 2]) | (ord($u16[$position * 2 + 1]) << 8);

            // If first is not a leading surrogate, or position+1 == size, return first.
            if ($first < 0xD800 || $first > 0xDBFF || $position + 1 === $size) {
                return new JsNumber((float) $first);
            }

            $second = ord($u16[($position + 1) * 2]) | (ord($u16[($position + 1) * 2 + 1]) << 8);

            // If second is not a trailing surrogate, return first.
            if ($second < 0xDC00 || $second > 0xDFFF) {
                return new JsNumber((float) $first);
            }

            // UTF-16 decode: (lead - 0xD800) * 1024 + (trail - 0xDC00) + 0x10000.
            $cp = ($first - 0xD800) * 0x400 + ($second - 0xDC00) + 0x10000;
            return new JsNumber((float) $cp);
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
