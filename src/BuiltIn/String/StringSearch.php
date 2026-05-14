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
 * StringPrototype trait part: StringSearch. Composed into
 * StringPrototype via `use String\StringSearch;`. `self::`/`$this->`
 * resolve into the composing class.
 */
trait StringSearch
{
    /**
     * Build a JS match-result array from a PHP preg_match $matches with
     * PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL flags.
     *
     * @param array<int|string, mixed> $matches
     */
    private static function buildMatchResult(array $matches, string $str, bool $includeGroups): JsArray
    {
        $elements = [];
        foreach ($matches as $key => $match) {
            if (is_int($key)) {
                $elements[] = self::matchCaptureToJs($match);
            }
        }
        $result = JsArray::fromArray($elements);

        $firstMatch = $matches[0];
        $byteOffset = is_array($firstMatch) ? (is_int($firstMatch[1] ?? null) ? $firstMatch[1] : 0) : 0;
        $charPos = mb_strlen(substr($str, 0, $byteOffset), 'UTF-8');
        $result->set('index', JsNumber::of((float) $charPos));
        $result->set('input', new JsString($str));

        if ($includeGroups) {
            $groups = JsObject::createNullPrototype();
            $hasGroups = false;
            foreach ($matches as $key => $match) {
                if (is_string($key)) {
                    $hasGroups = true;
                    $groups->defineOwnProperty($key, PropertyDescriptor::data(
                        self::matchCaptureToJs($match),
                    ));
                }
            }
            $result->set('groups', $hasGroups ? $groups : JsUndefined::instance());
        } else {
            $result->set('groups', JsUndefined::instance());
        }

        return $result;
    }

    /**
     * Convert a single capture entry from preg_match (PREG_OFFSET_CAPTURE |
     * PREG_UNMATCHED_AS_NULL) into a JsValue. Unmatched groups are [null,-1].
     *
     * @param mixed $match
     */
    private static function matchCaptureToJs($match): JsValue
    {
        if (!is_array($match)) {
            return JsUndefined::instance();
        }
        $value = $match[0] ?? null;
        $offset = $match[1] ?? -1;
        if ($value === null || $offset === -1) {
            return JsUndefined::instance();
        }
        return new JsString((string) $value);
    }

    private static function indexOf(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $search = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';

            // Per §22.1.3.9 String.prototype.indexOf: positions are UTF-16
            // code unit offsets. Convert both haystack and needle to UTF-16LE
            // byte strings and search by byte, then divide by 2. Using
            // mb_strpos with UTF-8 counts codepoints which misaligns for
            // strings that contain surrogate pairs (chars above U+FFFF).
            $hayU16 = JsString::utf8ToUtf16LE($str);
            $needleU16 = JsString::utf8ToUtf16LE($search);
            $hayLen = strlen($hayU16) / 2;

            $posFloat = isset($args[1])
                ? TypeConversion::toIntegerOrInfinity($args[1])
                : 0.0;
            if (is_nan($posFloat)) {
                $posFloat = 0.0;
            }
            $fromIndex = (int) max(0, min($posFloat === INF ? $hayLen : $posFloat, $hayLen));

            if ($fromIndex >= $hayLen) {
                if ($search === '' && $fromIndex === (int) $hayLen) {
                    return JsNumber::of((float) $hayLen);
                }
                return JsNumber::of(-1.0);
            }

            // strpos over UTF-16LE bytes could in principle match at an
            // odd byte offset (misaligned with the code-unit grid) when a
            // needle's byte pattern coincidentally appears across code
            // units. Keep advancing past odd matches.
            $start = $fromIndex * 2;
            $byteOffset = strpos($hayU16, $needleU16, $start);
            while ($byteOffset !== false && ($byteOffset & 1) !== 0) {
                $byteOffset = strpos($hayU16, $needleU16, $byteOffset + 1);
            }
            if ($byteOffset === false) {
                return JsNumber::of(-1.0);
            }
            return JsNumber::of((float) ($byteOffset / 2));
        };
    }

    private static function lastIndexOf(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $search = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            // Work in UTF-16 code units per §22.1.3.11.
            $hayU16 = JsString::utf8ToUtf16LE($str);
            $needleU16 = JsString::utf8ToUtf16LE($search);
            $strLen = (int) (strlen($hayU16) / 2);
            if (isset($args[1])) {
                $numPos = TypeConversion::toNumber($args[1]);
                // Per spec: if numPos is NaN, let pos be +Infinity (search entire string).
                $fromIndex = is_nan($numPos) ? $strLen : (int) $numPos;
            } else {
                $fromIndex = $strLen;
            }
            $fromIndex = max(0, min($fromIndex, $strLen));

            if ($search === '') {
                return JsNumber::of((float) $fromIndex);
            }

            $needleLen = (int) (strlen($needleU16) / 2);
            // strrpos-on-bytes with a limited search slice, then align to
            // even byte offsets to stay on UTF-16 code-unit boundaries.
            $searchLimit = ($fromIndex + $needleLen) * 2;
            $slice = substr($hayU16, 0, $searchLimit);
            $byteOffset = strrpos($slice, $needleU16);
            while ($byteOffset !== false && ($byteOffset & 1) !== 0) {
                if ($byteOffset === 0) {
                    $byteOffset = false;
                    break;
                }
                $slice2 = substr($slice, 0, $byteOffset);
                $byteOffset = strrpos($slice2, $needleU16);
            }
            if ($byteOffset === false) {
                return JsNumber::of(-1.0);
            }
            $pos = (int) ($byteOffset / 2);
            if ($pos > $fromIndex) {
                return JsNumber::of(-1.0);
            }
            return JsNumber::of((float) $pos);
        };
    }

    /**
     * IsRegExp(argument) per spec 7.2.8.
     * Returns true if argument has @@match set to a truthy value,
     * or if argument is a RegExp-like object (has 'source' and 'flags').
     */
    private static function isRegExp(JsValue $value): bool
    {
        if (!$value instanceof JsObject) {
            return false;
        }
        // Check Symbol.match first.
        $matchSym = SymbolConstructor::match();
        $matcher = $value->getBySymbol($matchSym);
        if (!$matcher instanceof JsUndefined) {
            return TypeConversion::toBoolean($matcher);
        }
        // Fall back: if it looks like a RegExp (has 'source' property).
        return $value->has('source') && $value->has('flags');
    }

    private static function includes(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $searchArg = $args[0] ?? JsUndefined::instance();

            // Per spec step 3: throw TypeError if searchString is a RegExp.
            if (self::isRegExp($searchArg)) {
                throw new \Phasis\Exceptions\TypeError(
                    'String.prototype.includes called with a RegExp searchString'
                );
            }

            $search = TypeConversion::toString($searchArg);
            $strLen = mb_strlen($str, 'UTF-8');

            // Per spec: pos = ToIntegerOrInfinity(position), start = clamp(pos, 0, len).
            $posFloat = isset($args[1]) ? TypeConversion::toIntegerOrInfinity($args[1]) : 0.0;
            if ($posFloat === INF || $posFloat >= $strLen) {
                return new JsBoolean($search === '');
            }
            $fromIndex = max(0, (int) $posFloat);

            if ($search === '') {
                return new JsBoolean(true);
            }
            $pos = mb_strpos($str, $search, $fromIndex, 'UTF-8');
            return new JsBoolean($pos !== false);
        };
    }

    private static function startsWith(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $searchArg = $args[0] ?? JsUndefined::instance();

            // Per spec: throw TypeError if searchString is a RegExp.
            if (self::isRegExp($searchArg)) {
                throw new \Phasis\Exceptions\TypeError(
                    'String.prototype.startsWith called with a RegExp searchString'
                );
            }

            $search = TypeConversion::toString($searchArg);
            $strLen = mb_strlen($str, 'UTF-8');
            $posFloat = isset($args[1]) ? TypeConversion::toIntegerOrInfinity($args[1]) : 0.0;
            $position = (int) max(0.0, min($posFloat === INF ? (float) $strLen : $posFloat, (float) $strLen));

            $sub = mb_substr($str, $position, mb_strlen($search, 'UTF-8'), 'UTF-8');
            return new JsBoolean($sub === $search);
        };
    }

    private static function endsWith(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $searchArg = $args[0] ?? JsUndefined::instance();

            // Per spec: throw TypeError if searchString is a RegExp.
            if (self::isRegExp($searchArg)) {
                throw new \Phasis\Exceptions\TypeError(
                    'String.prototype.endsWith called with a RegExp searchString'
                );
            }

            $search = TypeConversion::toString($searchArg);
            $strLen = mb_strlen($str, 'UTF-8');

            if (isset($args[1]) && !($args[1] instanceof JsUndefined)) {
                $posFloat = TypeConversion::toIntegerOrInfinity($args[1]);
                $endPosition = (int) max(0.0, min($posFloat === INF ? (float) $strLen : $posFloat, (float) $strLen));
            } else {
                $endPosition = $strLen;
            }

            $searchLen = mb_strlen($search, 'UTF-8');
            // Empty search string always returns true.
            if ($searchLen === 0) {
                return new JsBoolean(true);
            }
            $start = $endPosition - $searchLen;
            if ($start < 0) {
                return new JsBoolean(false);
            }

            $sub = mb_substr($str, $start, $searchLen, 'UTF-8');
            return new JsBoolean($sub === $search);
        };
    }

    private static function split(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $separator = $args[0] ?? JsUndefined::instance();
            $limitArg = $args[1] ?? JsUndefined::instance();

            // Per spec §21.1.3.20 step 2-3: GetMethod(separator, @@split).
            // null/undefined → use default; non-callable → TypeError;
            // callable → invoke.
            if ($separator instanceof JsObject) {
                $splitSym = SymbolConstructor::split();
                $splitter = $separator->getBySymbol($splitSym);
                if ($splitter instanceof JsFunction) {
                    return $splitter->call($separator, [$this_, $limitArg]);
                }
                if ($splitter instanceof \Phasis\Value\JsHTMLDDA) {
                    // HTMLDDA's [[Call]] returns null.
                    return JsNull::instance();
                }
                if (
                    $splitter instanceof \Phasis\Value\JsProxy
                    && $splitter->isCallable()
                ) {
                    return $splitter->apply($separator, [$this_, $limitArg]);
                }
                if (
                    !$splitter instanceof JsUndefined
                    && !$splitter instanceof JsNull
                ) {
                    throw new \Phasis\Exceptions\TypeError(
                        'String.prototype.split: @@split is not callable'
                    );
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
            // The spec requires the conversion happens exactly once, so cache
            // the result for the non-regex branch below.
            $isRegExp = $separator instanceof JsObject && $separator->has('source');
            $separatorString = null;
            if (!$isRegExp) {
                $separatorString = TypeConversion::toString($separator);
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
                        if ($m[$i][0] === null) {
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
                        $charIdx = mb_strlen(substr($str, 0, $matchStart), 'UTF-8');
                        $charLen = strlen(mb_substr($str, $charIdx, 1, 'UTF-8'));
                        $q = $matchStart + max($charLen, 1);
                    } else {
                        $q = $e;
                    }
                }

                // Push the trailing substring (from last split point to end).
                $result[] = new JsString(substr($str, $p));
                return JsArray::fromArray($result);
            }

            $sep = $separatorString ?? TypeConversion::toString($separator);

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
            // Step 1: RequireObjectCoercible
            if ($this_ instanceof JsUndefined || $this_ instanceof JsNull) {
                throw new \Phasis\Exceptions\TypeError(
                    'String.prototype.replace called on null or undefined'
                );
            }

            $searchArg = $args[0] ?? JsUndefined::instance();
            $replArg = $args[1] ?? JsUndefined::instance();

            // Step 2: Check Symbol.replace on searchValue BEFORE converting O to string.
            if (!$searchArg instanceof JsUndefined && !$searchArg instanceof JsNull) {
                if ($searchArg instanceof JsObject) {
                    $replaceSym = SymbolConstructor::replace();
                    $replacer = $searchArg->getBySymbol($replaceSym);
                    if (!$replacer instanceof JsUndefined && !$replacer instanceof JsNull) {
                        if ($replacer instanceof JsFunction) {
                            return $replacer->call($searchArg, [$this_, $replArg]);
                        }
                        if ($replacer instanceof \Phasis\Value\JsHTMLDDA) {
                            return JsNull::instance();
                        }
                        throw new \Phasis\Exceptions\TypeError('Symbol.replace is not a function');
                    }
                }
            }

            // Step 3: ToString(O)
            $str = self::extractString($this_);
            $functionalReplace = $replArg instanceof JsFunction;

            // RegExp-like search
            if ($searchArg instanceof JsObject && $searchArg->has('source')) {
                // For regexp path, ToString(replaceValue) per spec step 6 (before matching)
                $replStr = $functionalReplace ? '' : TypeConversion::toString($replArg);

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
                $escapedPattern = preg_replace('#(?<!\\\\)/#', '\\/', $pattern);
                $pcre = '/' . $escapedPattern . '/' . $pcreFlags . 'u';
                $isGlobal = str_contains($flags, 'g');
                $limit = $isGlobal ? -1 : 1;
                // Count expected capture groups (PHP omits unmatched optional groups)
                $nExpectedCaptures = self::countCaptureGroups($pattern);

                if ($functionalReplace) {
                    $result = @preg_replace_callback(
                        $pcre,
                        static fn (array $matches): string
                            => self::functionalReplaceCallback($matches, $replArg, $str, $nExpectedCaptures),
                        $str,
                        $limit,
                        $count,
                        PREG_OFFSET_CAPTURE,
                    );
                } else {
                    // String replacement: apply GetSubstitution for $-patterns
                    $result = @preg_replace_callback(
                        $pcre,
                        static fn (array $matches): string
                            => self::stringReplaceCallback($matches, $replStr, $str, $nExpectedCaptures),
                        $str,
                        $limit,
                        $count,
                        PREG_OFFSET_CAPTURE,
                    );
                }
                return new JsString($result ?? $str);
            }

            // String search — replace first occurrence only.
            // Step 4: ToString(searchValue) - must happen before ToString(replaceValue)
            $search = TypeConversion::toString($searchArg);
            // Step 6: ToString(replaceValue) if not callable
            $replStr = $functionalReplace ? '' : TypeConversion::toString($replArg);

            $pos = mb_strpos($str, $search, 0, 'UTF-8');
            if ($pos === false) {
                return new JsString($str);
            }

            if ($functionalReplace) {
                $jsArgs = [new JsString($search), JsNumber::of((float) $pos), new JsString($str)];
                $ret = $replArg->call(JsUndefined::instance(), $jsArgs);
                $replacement = TypeConversion::toString($ret);
            } else {
                $replacement = self::getSubstitution($search, $str, $pos, [], $replStr);
            }

            $before = mb_substr($str, 0, $pos, 'UTF-8');
            $after = mb_substr($str, $pos + mb_strlen($search, 'UTF-8'), null, 'UTF-8');
            return new JsString($before . $replacement . $after);
        };
    }

    /**
     * GetSubstitution(matched, str, position, captures, namedCaptures, replacement) per spec §22.1.3.17.1.
     * Processes $-sequences in replacement strings.
     *
     * @param list<string|null> $captures indexed captures (0-indexed internally, $n is 1-indexed)
     * @param array<string,string|null>|null $namedCaptures named capture groups or null
     */
    /**
     * preg_replace_callback callback for functional replacement on a regex match.
     *
     * @param array<int|string, mixed> $matches
     */
    private static function functionalReplaceCallback(
        array $matches,
        JsFunction $replArg,
        string $str,
        int $nExpectedCaptures,
    ): string {
        $first = $matches[0];
        $byteOffset = is_array($first) && isset($first[1]) && is_int($first[1]) ? $first[1] : 0;
        $matched = is_array($first) ? (string) ($first[0] ?? '') : (string) $first;
        $charOffset = mb_strlen(substr($str, 0, $byteOffset), 'UTF-8');
        $jsArgs = [new JsString($matched)];

        $captures = [];
        for ($ci = 1; $ci < count($matches); $ci++) {
            $captures[] = self::captureValue($matches[$ci]);
        }
        while (count($captures) < $nExpectedCaptures) {
            $captures[] = null;
        }
        foreach ($captures as $cap) {
            $jsArgs[] = $cap === null ? JsUndefined::instance() : new JsString($cap);
        }
        $jsArgs[] = JsNumber::of((float) $charOffset);
        $jsArgs[] = new JsString($str);

        $ret = $replArg->call(JsUndefined::instance(), $jsArgs);
        return TypeConversion::toString($ret);
    }

    /**
     * preg_replace_callback callback for string replacement (with $-pattern substitution).
     *
     * @param array<int|string, mixed> $matches
     */
    private static function stringReplaceCallback(
        array $matches,
        string $replStr,
        string $str,
        int $nExpectedCaptures,
    ): string {
        $first = $matches[0];
        $byteOffset = is_array($first) && isset($first[1]) && is_int($first[1]) ? $first[1] : 0;
        $matched = is_array($first) ? (string) ($first[0] ?? '') : (string) $first;
        $charOffset = mb_strlen(substr($str, 0, $byteOffset), 'UTF-8');

        $captures = [];
        $namedCaptures = null;
        foreach ($matches as $key => $cap) {
            if (is_string($key)) {
                if ($namedCaptures === null) {
                    $namedCaptures = [];
                }
                $namedCaptures[$key] = self::captureValue($cap);
                continue;
            }
            if ($key === 0) {
                continue;
            }
            $captures[] = self::captureValue($cap);
        }
        while (count($captures) < $nExpectedCaptures) {
            $captures[] = null;
        }

        return self::getSubstitution(
            $matched,
            $str,
            $charOffset,
            $captures,
            $replStr,
            $namedCaptures,
        );
    }

    /**
     * Extract a capture string from a preg_match entry, returning null for unmatched.
     *
     * @param mixed $cap
     */
    private static function captureValue($cap): ?string
    {
        if (is_array($cap)) {
            $offset = $cap[1] ?? -1;
            if ($offset === -1) {
                return null;
            }
            $value = $cap[0] ?? null;
            return $value === null ? null : (string) $value;
        }
        if ($cap === '' || $cap === null) {
            return null;
        }
        return (string) $cap;
    }

    /**
     * Count capturing groups in a regex pattern (not counting non-capturing groups like (?:...)).
     */
    public static function countCaptureGroups(string $pattern): int
    {
        $count = 0;
        $len = strlen($pattern);
        $inCharClass = false;
        for ($i = 0; $i < $len; $i++) {
            $ch = $pattern[$i];
            if ($ch === '\\' && $i + 1 < $len) {
                $i++; // skip escaped char
                continue;
            }
            if ($ch === '[' && !$inCharClass) {
                $inCharClass = true;
                continue;
            }
            if ($ch === ']' && $inCharClass) {
                $inCharClass = false;
                continue;
            }
            if ($inCharClass) {
                continue;
            }
            if ($ch === '(') {
                // Check if non-capturing: (?:, (?=, (?!, (?<=, (?<!
                if ($i + 1 < $len && $pattern[$i + 1] === '?') {
                    if ($i + 2 < $len) {
                        $next2 = $pattern[$i + 2];
                        if ($next2 === ':' || $next2 === '=' || $next2 === '!') {
                            continue; // non-capturing
                        }
                        if (
                            $next2 === '<' && $i + 3 < $len
                            && ($pattern[$i + 3] === '=' || $pattern[$i + 3] === '!')
                        ) {
                            continue; // lookbehind
                        }
                        if ($next2 === '<') {
                            // Named group (?<name>): IS a capturing group
                            $count++;
                            continue;
                        }
                    }
                    continue; // other non-capturing forms
                }
                $count++;
            }
        }
        return $count;
    }

    /**
     * @param array<mixed> $captures
     * @param array<mixed> $namedCaptures
     */
    public static function getSubstitution(
        string $matched,
        string $str,
        int $position,
        array $captures,
        string $replacement,
        array|JsObject|null $namedCaptures = null,
    ): string {
        // Implementation cap: throw RangeError before exhausting PHP memory on
        // pathological replacements (e.g. `$1` repeated 2^16 times against a
        // 2^20-char capture would expand to 2^36 bytes). Spec allows engines
        // to throw on out-of-memory; SM throws InternalError, V8 throws
        // RangeError. Match V8 here so the test262 catch-all works.
        $maxLen = 256 * 1024 * 1024;
        $result = '';
        $len = strlen($replacement);
        $captureLen = count($captures);
        $i = 0;
        while ($i < $len) {
            if (strlen($result) > $maxLen) {
                throw new \Phasis\Exceptions\RangeError(
                    'Invalid string length'
                );
            }
            $ch = $replacement[$i];
            if ($ch !== '$' || $i + 1 >= $len) {
                $result .= $ch;
                $i++;
                continue;
            }
            $next = $replacement[$i + 1];
            switch ($next) {
                case '$':
                    $result .= '$';
                    $i += 2;
                    break;
                case '&':
                    $result .= $matched;
                    $i += 2;
                    break;
                case '`':
                    $result .= mb_substr($str, 0, $position, 'UTF-8');
                    $i += 2;
                    break;
                case "'":
                    $result .= mb_substr($str, $position + mb_strlen($matched, 'UTF-8'), null, 'UTF-8');
                    $i += 2;
                    break;
                case '<':
                    // Named capture: $<Name>. Per spec, $< is only literal
                    // when the regex has no named captures; in that case
                    // the rest after `<` is processed normally as if `$<`
                    // was a two-character literal.
                    if ($namedCaptures !== null) {
                        $closePos = strpos($replacement, '>', $i + 2);
                        if ($closePos !== false) {
                            $name = substr($replacement, $i + 2, $closePos - $i - 2);
                            if ($namedCaptures instanceof JsObject) {
                                // Per spec, lookup uses Get which walks the
                                // prototype chain.
                                $val = $namedCaptures->get($name);
                                if (!$val instanceof JsUndefined) {
                                    $result .= TypeConversion::toString($val);
                                }
                            } elseif (array_key_exists($name, $namedCaptures)) {
                                $result .= $namedCaptures[$name] ?? '';
                            }
                            // If name not found, append nothing per spec.
                            $i = $closePos + 1;
                        } else {
                            $result .= '$<';
                            $i += 2;
                        }
                    } else {
                        // No named captures: $< is literal but the rest is
                        // re-parsed as ordinary substitution chars.
                        $result .= '$<';
                        $i += 2;
                    }
                    break;
                default:
                    // $n or $nn (capture group reference) per spec §22.1.3.17.1 step 5.f
                    if ($next >= '0' && $next <= '9') {
                        $digitCount = 1;
                        $index = (int) $next;

                        // Try two-digit if next char is also a digit
                        if ($i + 2 < $len && $replacement[$i + 2] >= '0' && $replacement[$i + 2] <= '9') {
                            $twoIndex = (int) ($next . $replacement[$i + 2]);
                            if ($twoIndex <= $captureLen) {
                                // Use two-digit
                                $digitCount = 2;
                                $index = $twoIndex;
                            }
                            // Else: twoIndex > captureLen → fall back to single digit (digitCount stays 1)
                        }

                        if ($index >= 1 && $index <= $captureLen) {
                            $result .= $captures[$index - 1] ?? '';
                        } else {
                            // Not a valid capture index: output as literal reference
                            $suffix = $digitCount === 2 ? ($replacement[$i + 2] ?? '') : '';
                            $result .= '$' . substr($next . $suffix, 0, $digitCount);
                        }
                        $i += 1 + $digitCount;
                    } else {
                        $result .= '$';
                        $i++;
                    }
                    break;
            }
        }
        return $result;
    }

    private static function replaceAll(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            // Step 1: RequireObjectCoercible (throw if null/undefined, but do NOT call ToString yet).
            if ($this_ instanceof JsUndefined || $this_ instanceof JsNull) {
                throw new \Phasis\Exceptions\TypeError(
                    'String.prototype.replaceAll called on null or undefined'
                );
            }

            $searchArg = $args[0] ?? JsUndefined::instance();
            $replaceValue = $args[1] ?? JsUndefined::instance();

            // Step 2: If searchValue is not undefined/null, handle isRegExp and Symbol.replace.
            // Per spec, this happens BEFORE ToString(O) (step 3).
            if (!$searchArg instanceof JsUndefined && !$searchArg instanceof JsNull) {
                if ($searchArg instanceof JsObject) {
                    // Step 2a: IsRegExp check (uses Symbol.match getter).
                    $matchSym = SymbolConstructor::match();
                    $matchVal = $searchArg->getBySymbol($matchSym);
                    $isRegExp = $matchVal instanceof JsUndefined
                        ? ($searchArg->has('source') && $searchArg->has('flags'))
                        : TypeConversion::toBoolean($matchVal);

                    if ($isRegExp) {
                        // Step 2b: Get flags, RequireObjectCoercible, then check for 'g'.
                        $flagsVal = $searchArg->get('flags');
                        if ($flagsVal instanceof JsUndefined || $flagsVal instanceof JsNull) {
                            throw new \Phasis\Exceptions\TypeError(
                                'String.prototype.replaceAll called with a non-global RegExp argument'
                            );
                        }
                        $flags = TypeConversion::toString($flagsVal);
                        if (!str_contains($flags, 'g')) {
                            throw new \Phasis\Exceptions\TypeError(
                                'String.prototype.replaceAll called with a non-global RegExp argument'
                            );
                        }
                    }

                    // Step 2c: GetMethod(searchValue, @@replace).
                    $replaceSym = SymbolConstructor::replace();
                    $replacer = $searchArg->getBySymbol($replaceSym);
                    // Step 2d: If replacer is not undefined/null, call it with (O, replaceValue).
                    // Pass the original O (this_), NOT a stringified version.
                    if (!$replacer instanceof JsUndefined && !$replacer instanceof JsNull) {
                        if ($replacer instanceof JsFunction) {
                            return $replacer->call($searchArg, [$this_, $replaceValue]);
                        }
                        if ($replacer instanceof \Phasis\Value\JsHTMLDDA) {
                            return JsNull::instance();
                        }
                        throw new \Phasis\Exceptions\TypeError(
                            'Symbol.replace is not a function'
                        );
                    }
                }
            }

            // Step 3: ToString(O) - only called after searchValue has been fully handled.
            $str = self::extractString($this_);

            $search = TypeConversion::toString($searchArg);
            $functionalReplace = $replaceValue instanceof JsFunction;

            if (!$functionalReplace) {
                $replStr = TypeConversion::toString($replaceValue);
            } else {
                $replStr = '';
            }

            $searchLen = mb_strlen($search, 'UTF-8');
            $result = '';
            $offset = 0;
            $advanceBy = max(1, $searchLen);

            while (true) {
                $pos = $search === ''
                    ? ($offset <= mb_strlen($str, 'UTF-8') ? $offset : -1)
                    : mb_strpos($str, $search, $offset, 'UTF-8');

                if ($pos === false || $pos === -1) {
                    break;
                }

                $result .= mb_substr($str, $offset, $pos - $offset, 'UTF-8');

                if ($functionalReplace) {
                    $repVal = $replaceValue->call(JsUndefined::instance(), [
                        new JsString($search),
                        JsNumber::of((float) $pos),
                        new JsString($str),
                    ]);
                    $result .= TypeConversion::toString($repVal);
                } else {
                    $result .= self::getSubstitution($search, $str, $pos, [], $replStr);
                }

                $offset = $pos + $advanceBy;
                if ($search === '') {
                    if ($offset <= mb_strlen($str, 'UTF-8')) {
                        $result .= mb_substr($str, $offset - 1, 1, 'UTF-8');
                    }
                }
            }
            $result .= mb_substr($str, $offset, null, 'UTF-8');
            return new JsString($result);
        };
    }

    private static function search(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            // Step 1: RequireObjectCoercible
            if ($this_ instanceof JsUndefined || $this_ instanceof JsNull) {
                throw new \Phasis\Exceptions\TypeError(
                    'String.prototype.search called on null or undefined'
                );
            }

            $searchArg = $args[0] ?? JsUndefined::instance();

            // Step 2: If regexp is not undefined/null, check for @@search.
            if (!$searchArg instanceof JsUndefined && !$searchArg instanceof JsNull) {
                if ($searchArg instanceof JsObject) {
                    $searchSym = SymbolConstructor::search();
                    $searcher = $searchArg->getBySymbol($searchSym);
                    if (!$searcher instanceof JsUndefined && !$searcher instanceof JsNull) {
                        if ($searcher instanceof JsFunction) {
                            return $searcher->call($searchArg, [$this_]);
                        }
                        if ($searcher instanceof \Phasis\Value\JsHTMLDDA) {
                            return JsNull::instance();
                        }
                        throw new \Phasis\Exceptions\TypeError('RegExp[Symbol.search] is not a function');
                    }
                }
            }

            // Step 3: ToString(O)
            $str = self::extractString($this_);

            // Step 6: Let rx be RegExpCreate(regexp, undefined).
            // Step 8: Return Invoke(rx, @@search, S).
            $patternStr = $searchArg instanceof JsUndefined
                ? ''
                : TypeConversion::toString($searchArg);
            $rx = \Phasis\Engine::createRegExp($patternStr, '');
            if ($rx !== null) {
                $searchSym = SymbolConstructor::search();
                $searcher = $rx->getBySymbol($searchSym);
                if ($searcher instanceof JsFunction) {
                    return $searcher->call($rx, [new JsString($str)]);
                }
            }

            // Fallback: manual PCRE if no RegExp engine available.
            $pattern = $patternStr === '' ? '(?:)' : $patternStr;
            $pcre = '/' . str_replace('/', '\\/', $pattern) . '/u';
            if (@preg_match($pcre, $str, $matches, PREG_OFFSET_CAPTURE)) {
                $charPos = mb_strlen(substr($str, 0, $matches[0][1]), 'UTF-8');
                return JsNumber::of((float) $charPos);
            }
            return JsNumber::of(-1.0);
        };
    }

    private static function matchFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            // Step 1: RequireObjectCoercible
            if ($this_ instanceof JsUndefined || $this_ instanceof JsNull) {
                throw new \Phasis\Exceptions\TypeError(
                    'String.prototype.match called on null or undefined'
                );
            }

            $searchArg = $args[0] ?? JsUndefined::instance();

            // Step 2: If regexp is not undefined/null, check for @@match.
            if (!$searchArg instanceof JsUndefined && !$searchArg instanceof JsNull) {
                if ($searchArg instanceof JsObject) {
                    $matchSym = SymbolConstructor::match();
                    $matcher = $searchArg->getBySymbol($matchSym);
                    if (!$matcher instanceof JsUndefined && !$matcher instanceof JsNull) {
                        if ($matcher instanceof JsFunction) {
                            return $matcher->call($searchArg, [$this_]);
                        }
                        if ($matcher instanceof \Phasis\Value\JsHTMLDDA) {
                            return JsNull::instance();
                        }
                        throw new \Phasis\Exceptions\TypeError('RegExp[Symbol.match] is not a function');
                    }
                }
            }

            // Step 3: ToString(O)
            $str = self::extractString($this_);

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
                    $searchArg->set('lastIndex', JsNumber::of(0.0));
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
                    return self::buildMatchResult($matches, $str, true);
                }
                return JsNull::instance();
            }

            // Per spec step 6: Let rx be RegExpCreate(regexp, undefined).
            // Step 7: Return Invoke(rx, @@match, S).
            // Create a new RegExp from the argument and delegate to @@match.
            $regExpConstructor = null;
            $globalEnv = null;
            // Try to get RegExp constructor from the environment.
            // The environment isn't directly accessible here, so use the
            // constructor stored on RegExp.prototype or string prototype's constructor chain.
            if ($this_ instanceof JsObject) {
                // Walk up from String.prototype to find the global scope.
                $ctor = $this_->get('constructor');
                if ($ctor instanceof JsFunction) {
                    $globalObj = $ctor->get('__globalEnv__');
                }
            }
            // Fall back to looking up RegExp via the string prototype's env.
            $strProto = JsString::getStringPrototype();
            if ($strProto !== null) {
                $ctorVal = $strProto->get('constructor');
                if ($ctorVal instanceof JsFunction) {
                    // The String constructor lives in the same env as RegExp.
                    // Use a static reference to the interpreter for RegExp creation.
                    $patternStr = $searchArg instanceof JsUndefined
                        ? ''
                        : TypeConversion::toString($searchArg);
                    $rx = \Phasis\Engine::createRegExp($patternStr, '');
                    if ($rx !== null) {
                        // Invoke @@match on the new regex.
                        $matchSym = SymbolConstructor::match();
                        $matcher = $rx->getBySymbol($matchSym);
                        if ($matcher instanceof JsFunction) {
                            return $matcher->call($rx, [new JsString($str)]);
                        }
                    }
                }
            }
            // Final fallback: manual PCRE match.
            $patternStr = $searchArg instanceof JsUndefined ? '(?:)' : TypeConversion::toString($searchArg);
            $escaped = str_replace('/', '\\/', $patternStr);
            $pcre = '/' . $escaped . '/u';
            if (@preg_match($pcre, $str, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
                return self::buildMatchResult($matches, $str, false);
            }
            return JsNull::instance();
        };
    }

    private static function matchAll(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            // Step 1: RequireObjectCoercible
            if ($this_ instanceof JsUndefined || $this_ instanceof JsNull) {
                throw new \Phasis\Exceptions\TypeError(
                    'String.prototype.matchAll called on null or undefined'
                );
            }

            $searchArg = $args[0] ?? JsUndefined::instance();

            // Step 2: If regexp is not undefined/null, handle RegExp and @@matchAll delegation.
            if (!$searchArg instanceof JsUndefined && !$searchArg instanceof JsNull) {
                if ($searchArg instanceof JsObject) {
                    // Step 2a: IsRegExp check.
                    $matchSym = SymbolConstructor::match();
                    $matchVal = $searchArg->getBySymbol($matchSym);
                    $isRegExp = $matchVal instanceof JsUndefined
                        ? ($searchArg->has('source') && $searchArg->has('flags'))
                        : TypeConversion::toBoolean($matchVal);

                    if ($isRegExp) {
                        // Step 2b: Get flags, RequireObjectCoercible(flags), check 'g'.
                        $flagsVal = $searchArg->get('flags');
                        if ($flagsVal instanceof JsUndefined || $flagsVal instanceof JsNull) {
                            throw new \Phasis\Exceptions\TypeError(
                                'String.prototype.matchAll called with a non-global RegExp argument'
                            );
                        }
                        $flagsStr = TypeConversion::toString($flagsVal);
                        if (!str_contains($flagsStr, 'g')) {
                            throw new \Phasis\Exceptions\TypeError(
                                'String.prototype.matchAll called with a non-global RegExp argument'
                            );
                        }
                    }

                    // Step 2c: GetMethod(regexp, @@matchAll).
                    $matchAllSym = SymbolConstructor::matchAll();
                    $matcher = $searchArg->getBySymbol($matchAllSym);
                    if (!$matcher instanceof JsUndefined && !$matcher instanceof JsNull) {
                        if ($matcher instanceof JsFunction) {
                            return $matcher->call($searchArg, [$this_]);
                        }
                        if ($matcher instanceof \Phasis\Value\JsHTMLDDA) {
                            return JsNull::instance();
                        }
                        throw new \Phasis\Exceptions\TypeError('Symbol.matchAll is not a function');
                    }
                }
            }

            // Step 3: Let S be ? ToString(O).
            $str = self::extractString($this_);

            // Step 4: Let rx be ? RegExpCreate(regexp, "g").
            // Per spec, RegExpCreate passes regexp to the RegExp constructor.
            // If searchArg is undefined, the pattern is "" (empty).
            // If searchArg is a RegExp object, extract its source pattern.
            if ($searchArg instanceof JsUndefined) {
                $R = '(?:)';
            } elseif ($searchArg instanceof JsObject && $searchArg->has('[[OriginalSource]]')) {
                $sourceVal = $searchArg->get('[[OriginalSource]]');
                $R = $sourceVal instanceof JsString ? $sourceVal->value : TypeConversion::toString($searchArg);
            } else {
                $R = TypeConversion::toString($searchArg);
            }

            // Step 5: Let rx be ? RegExpCreate(R, "g").
            // Step 6: Return ? Invoke(rx, @@matchAll, S).
            $rx = \Phasis\Engine::createRegExp($R, 'g');
            if ($rx !== null) {
                $matchAllSym = SymbolConstructor::matchAll();
                $matcher = $rx->getBySymbol($matchAllSym);
                if ($matcher instanceof JsFunction) {
                    return $matcher->call($rx, [new JsString($str)]);
                }
                // Per spec, Invoke throws TypeError if the method is not callable.
                throw new \Phasis\Exceptions\TypeError(
                    'RegExp.prototype[Symbol.matchAll] is not a function'
                );
            }

            // Fallback: manual matching if no RegExp engine available.
            $allMatches = [];
            $search = $R;
            $offset = 0;
            if ($search === '') {
                $len = mb_strlen($str, 'UTF-8');
                for ($i = 0; $i <= $len; $i++) {
                    $match = JsArray::fromArray([new JsString('')]);
                    $match->set('index', JsNumber::of((float) $i));
                    $match->set('input', new JsString($str));
                    $allMatches[] = $match;
                }
            } else {
                $escaped = str_replace('/', '\\/', $search);
                $pcre = '/' . $escaped . '/gu';
                $byteOffset = 0;
                while (@preg_match($pcre, $str, $m, PREG_OFFSET_CAPTURE, $byteOffset)) {
                    $match = JsArray::fromArray([new JsString($m[0][0])]);
                    $charPos = mb_strlen(substr($str, 0, $m[0][1]), 'UTF-8');
                    $match->set('index', JsNumber::of((float) $charPos));
                    $match->set('input', new JsString($str));
                    $allMatches[] = $match;
                    $matchLen = strlen($m[0][0]);
                    $byteOffset = $m[0][1] + ($matchLen > 0 ? $matchLen : 1);
                    if ($byteOffset > strlen($str)) {
                        break;
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
            $iterSym = \Phasis\BuiltIn\SymbolConstructor::iterator();
            $iterator->setBySymbol($iterSym, JsFunction::fromCallable(
                '[Symbol.iterator]',
                function () use ($iterator): JsValue {
                    return $iterator;
                },
            ));
            return $iterator;
        };
    }
}
