<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\RegExp_;

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
use Phasis\BuiltIn\StringPrototype;

/**
 * RegExpPrototype trait part: RegExpSymbolHooks. Composed into
 * RegExpPrototype via `use RegExp_\RegExpSymbolHooks;`.
 */
trait RegExpSymbolHooks
{
    /**
     * RegExp.prototype[@@search](string) - spec §22.2.6.11
     *
     * Returns the index of the first match, or -1 if no match.
     */
    private static function symbolSearch(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new \Phasis\Exceptions\TypeError(
                    'Method RegExp.prototype[@@search] called on incompatible receiver'
                );
            }

            $S = TypeConversion::toString($args[0] ?? JsUndefined::instance());

            // Save previousLastIndex.
            $previousLastIndex = $this_->get('lastIndex');

            // Per spec, the comparisons use SameValue (not SameValueZero),
            // so -0 vs +0 is observable: if lastIndex was -0 and exec set
            // it to +0 the spec considers them different and restores -0.
            if (!\Phasis\Spec\AbstractOperations::sameValue($previousLastIndex, JsNumber::of(0.0))) {
                $this_->set('lastIndex', JsNumber::of(0.0), true);
            }

            // Call exec.
            $result = self::regExpExec($this_, $S);

            // Restore lastIndex if changed.
            $currentLastIndex = $this_->get('lastIndex');
            if (!\Phasis\Spec\AbstractOperations::sameValue($currentLastIndex, $previousLastIndex)) {
                $this_->set('lastIndex', $previousLastIndex, true);
            }

            if ($result instanceof JsNull) {
                return JsNumber::of(-1.0);
            }

            return $result->get('index');
        };
    }

    /**
     * RegExp.prototype[@@match](string) - spec §22.2.6.9
     *
     * Returns match result(s) or null.
     */
    private static function symbolMatch(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new \Phasis\Exceptions\TypeError(
                    'Method RegExp.prototype[@@match] called on incompatible receiver'
                );
            }

            $S = TypeConversion::toString($args[0] ?? JsUndefined::instance());

            // Per spec step 4: Let flags be ? ToString(? Get(rx, "flags")).
            $flags = TypeConversion::toString($this_->get('flags'));
            $global = str_contains($flags, 'g');

            if (!$global) {
                // Non-global: single RegExpExec call.
                return self::regExpExec($this_, $S);
            }

            // Global: iterate all matches.
            // Per spec 22.2.6.8 step 6.a, fullUnicode is derived from the
            // already-read flags string (not a fresh Get(R, "unicode")).
            $fullUnicode = str_contains($flags, 'u') || str_contains($flags, 'v');

            // Per spec: Set(rx, "lastIndex", +0, Throw=true).
            $this_->set('lastIndex', JsNumber::of(0.0), true);

            $elements = [];
            $n = 0;

            while (true) {
                $result = self::regExpExec($this_, $S);
                if ($result instanceof JsNull) {
                    if ($n === 0) {
                        return JsNull::instance();
                    }
                    return JsArray::fromArray($elements);
                }

                $matchStr = TypeConversion::toString($result->get('0'));
                $elements[] = new JsString($matchStr);
                $n++;

                // If empty match, advance lastIndex to avoid infinite loop.
                if ($matchStr === '') {
                    // Per spec: ToLength(Get(rx, "lastIndex")).
                    $thisIndex = TypeConversion::toLength($this_->get('lastIndex'));
                    $nextIndex = $fullUnicode
                        ? self::advanceStringIndex($S, $thisIndex)
                        : $thisIndex + 1;
                    $this_->set('lastIndex', JsNumber::of((float) $nextIndex), true);
                }
            }
        };
    }

    /**
     * RegExp.prototype[@@replace](string, replaceValue) - spec §22.2.6.10
     *
     * Replaces match(es) in string, used by String.prototype.replace/replaceAll.
     */
    private static function symbolReplace(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new \Phasis\Exceptions\TypeError(
                    'Method RegExp.prototype[@@replace] called on incompatible receiver'
                );
            }

            $string = $args[0] ?? JsUndefined::instance();
            $replaceValue = $args[1] ?? JsUndefined::instance();

            $S = TypeConversion::toString($string);
            // $lengthS is used to clamp the match's UTF-16 index, which
            // exec returns in UTF-16 code units — so codepoint count
            // would clip away astrals past mb_strlen.
            $lengthS = (int) (strlen(JsString::utf8ToUtf16LE($S)) / 2);

            $functionalReplace = $replaceValue instanceof JsFunction;
            if (!$functionalReplace) {
                $replaceStr = TypeConversion::toString($replaceValue);
            } else {
                $replaceStr = '';
            }

            // Per spec step 7: Let flags be ? ToString(? Get(rx, "flags")).
            $flags = TypeConversion::toString($this_->get('flags'));
            // Per spec step 8: If flags contains "g", let global be true.
            $global = str_contains($flags, 'g');
            $fullUnicode = false;

            if ($global) {
                // Per spec step 9a: fullUnicode if flags contain "u" or "v".
                $fullUnicode = str_contains($flags, 'u') || str_contains($flags, 'v');
                // Per spec step 10.c: Set(rx, "lastIndex", +0, Throw=true).
                $this_->set('lastIndex', JsNumber::of(0.0), true);
            }

            // Collect all results first.
            $results = [];
            $done = false;
            while (!$done) {
                $result = self::regExpExec($this_, $S);
                if ($result instanceof JsNull) {
                    $done = true;
                } else {
                    $results[] = $result;
                    if (!$global) {
                        $done = true;
                    } else {
                        $matchStr = TypeConversion::toString($result->get('0'));
                        if ($matchStr === '') {
                            // Per spec: ToLength(Get(rx, "lastIndex")).
                            $thisIndex = TypeConversion::toLength($this_->get('lastIndex'));
                            $nextIndex = $fullUnicode
                                ? self::advanceStringIndex($S, $thisIndex)
                                : $thisIndex + 1;
                            $this_->set('lastIndex', JsNumber::of((float) $nextIndex), true);
                        }
                    }
                }
            }

            // Build result string.
            $accumulatedResult = '';
            $nextSourcePosition = 0;

            foreach ($results as $result) {
                // Get capture count from result length.
                $nCaptures = max((int) TypeConversion::toNumber($result->get('length')) - 1, 0);
                $matched = TypeConversion::toString($result->get('0'));
                // $position arrives in UTF-16 code units (the contract
                // for exec's match.index and the value passed to a
                // functional replacer per spec 22.2.6.10 step 14.k.iii).
                // Match length is also expressed in UTF-16 units so the
                // matchEnd arithmetic below stays in the same space.
                $matchLength = (int) (strlen(JsString::utf8ToUtf16LE($matched)) / 2);

                $position = TypeConversion::toIntegerOrInfinity($result->get('index'));
                $position = (int) max(0, min($position, $lengthS));

                // Build captures array.
                $captures = [];
                for ($i = 1; $i <= $nCaptures; $i++) {
                    $capN = $result->get((string) $i);
                    if ($capN instanceof JsUndefined) {
                        $captures[] = null;
                    } else {
                        $captures[] = TypeConversion::toString($capN);
                    }
                }

                // Named capture groups.
                $groupsVal = $result->get('groups');

                if ($functionalReplace) {
                    // Build args: matched, ...captures, position, S[, namedCaptures]
                    $callArgs = [new JsString($matched)];
                    foreach ($captures as $cap) {
                        $callArgs[] = $cap === null ? JsUndefined::instance() : new JsString($cap);
                    }
                    $callArgs[] = JsNumber::of((float) $position);
                    $callArgs[] = new JsString($S);
                    // Per spec step 14.k.iv: if namedCaptures is not undefined,
                    // append it directly (no ToObject for functional replace).
                    if (!$groupsVal instanceof JsUndefined) {
                        $callArgs[] = $groupsVal;
                    }
                    $replValue = $replaceValue->call(JsUndefined::instance(), $callArgs);
                    $replacement = TypeConversion::toString($replValue);
                } else {
                    // Per spec step 14.l.i: if namedCaptures is not undefined,
                    // Set namedCaptures to ToObject(namedCaptures). Pass the
                    // JsObject directly so $<name> uses Get (which walks the
                    // prototype chain) rather than only own-property lookup.
                    $namedCaptures = null;
                    if (!$groupsVal instanceof JsUndefined) {
                        $namedCaptures = TypeConversion::toObject($groupsVal);
                    }
                    $replacement = StringPrototype::getSubstitution(
                        $matched,
                        $S,
                        $position,
                        $captures,
                        $replaceStr,
                        $namedCaptures
                    );
                }

                if ($position >= $nextSourcePosition) {
                    // Slice in UTF-16 code unit space — splitting a
                    // surrogate pair (e.g. /^|\udf06/g where one match
                    // ends inside an astral) requires emitting just
                    // the lone surrogate; utf8ToUtf16LE + utf16LEToUtf8
                    // round-trips that via CESU-8.
                    $accumulatedResult .= self::sliceUtf16Range($S, $nextSourcePosition, $position);
                    $accumulatedResult .= $replacement;
                    $nextSourcePosition = $position + $matchLength;
                }
            }

            return new JsString(
                $accumulatedResult . self::sliceUtf16Range($S, $nextSourcePosition, PHP_INT_MAX)
            );
        };
    }

    /**
     * RegExp.prototype[@@matchAll](string) - spec §22.2.6.9a
     *
     * Creates an iterator that yields each match result.
     */
    private static function symbolMatchAll(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new \Phasis\Exceptions\TypeError(
                    'Method RegExp.prototype[@@matchAll] called on incompatible receiver'
                );
            }

            $S = TypeConversion::toString($args[0] ?? JsUndefined::instance());

            // Per spec step 4-8: C = SpeciesConstructor(R, %RegExp%);
            // flags = ToString(R.flags); matcher = Construct(C, R, flags);
            // matcher.lastIndex = R.lastIndex.
            $globalRegExp = null;
            $interp = \Phasis\Engine::getCurrentInterpreter();
            if ($interp !== null) {
                $g = $interp->getGlobalValue('RegExp');
                if ($g instanceof JsFunction) {
                    $globalRegExp = $g;
                }
            }
            $C = $globalRegExp;
            $rCtor = $this_->get('constructor');
            if ($rCtor instanceof JsObject) {
                $species = $rCtor->getBySymbol(SymbolConstructor::species());
                if ($species instanceof JsUndefined || $species instanceof JsNull) {
                    // Use default %RegExp% — already in $C.
                } elseif (
                    ($species instanceof JsFunction && $species->isConstructable())
                    || ($species instanceof \Phasis\Value\JsProxy && $species->isConstructable())
                ) {
                    /** @var JsFunction|\Phasis\Value\JsProxy $species */
                    $C = $species;
                } else {
                    throw new \Phasis\Exceptions\TypeError(
                        'Species constructor must be a constructor'
                    );
                }
            } elseif (!$rCtor instanceof JsUndefined) {
                throw new \Phasis\Exceptions\TypeError(
                    'RegExp constructor must be an object'
                );
            }

            $flagsVal = $this_->get('flags');
            $flags = TypeConversion::toString($flagsVal);
            $global = str_contains($flags, 'g');
            $fullUnicode = str_contains($flags, 'u') || str_contains($flags, 'v');

            if ($C === null) {
                throw new \Phasis\Exceptions\TypeError(
                    'RegExp constructor is not available'
                );
            }
            $matcherV = $C->construct([$this_, new JsString($flags)]);
            if (!$matcherV instanceof JsObject) {
                throw new \Phasis\Exceptions\TypeError(
                    'Species constructor must return an object'
                );
            }
            $matcher = $matcherV;

            $lastIndexVal = $this_->get('lastIndex');
            $startIndex = TypeConversion::toLength($lastIndexVal);
            $matcher->set('lastIndex', JsNumber::of((float) $startIndex), true);

            // Create the iterator closure over $matcher, $S, $global, $fullUnicode.
            $done = false;
            $currentIndex = $startIndex;

            // Store iterator state in internal slots on the iterator object.
            $iterator = new JsObject(self::getRegExpStringIteratorProto());
            $iterator->defineOwnProperty(
                '[[Done]]',
                \Phasis\Object\PropertyDescriptor::data(
                    new JsBoolean(false),
                    true,
                    false,
                    false,
                ),
            );
            $iterator->defineOwnProperty(
                '[[IteratingRegExp]]',
                \Phasis\Object\PropertyDescriptor::data(
                    $matcher,
                    false,
                    false,
                    false,
                ),
            );
            $iterator->defineOwnProperty(
                '[[IteratedString]]',
                \Phasis\Object\PropertyDescriptor::data(
                    new JsString($S),
                    false,
                    false,
                    false,
                ),
            );
            $iterator->defineOwnProperty(
                '[[Global]]',
                \Phasis\Object\PropertyDescriptor::data(
                    new JsBoolean($global),
                    false,
                    false,
                    false,
                ),
            );
            $iterator->defineOwnProperty(
                '[[Unicode]]',
                \Phasis\Object\PropertyDescriptor::data(
                    new JsBoolean($fullUnicode),
                    false,
                    false,
                    false,
                ),
            );
            return $iterator;
        };
    }

    /**
     * RegExp.prototype[@@split](string, limit) - spec §22.2.6.12
     *
     * Splits string by regexp matches.
     */
    private static function symbolSplit(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new \Phasis\Exceptions\TypeError(
                    'Method RegExp.prototype[@@split] called on incompatible receiver'
                );
            }

            $string = $args[0] ?? JsUndefined::instance();
            $limitArg = $args[1] ?? JsUndefined::instance();

            $S = TypeConversion::toString($string);

            // Per spec step 4-10: create a copy via SpeciesConstructor with 'y' flag.
            // This allows side effects (like Symbol.match getters that recompile the
            // regex) to take effect before splitting begins.
            $splitter = $this_;
            $unicodeMatching = false;
            $regExpCtor = \Phasis\Engine::getCurrentInterpreter()
                ? \Phasis\Engine::getCurrentInterpreter()->getGlobalValue('RegExp')
                : null;
            if ($regExpCtor instanceof \Phasis\Value\JsFunction) {
                // SpeciesConstructor: per spec 7.3.20:
                //   1. C = Get(O, "constructor"); 2. If C undefined, return default;
                //   3. If Type(C) is not Object, throw TypeError;
                //   4. S = Get(C, @@species); 5. If S null/undefined, return default;
                //   6. If IsConstructor(S), return S; 7. Else throw TypeError.
                $C = $regExpCtor;
                $ctorVal = $this_->get('constructor');
                if (!($ctorVal instanceof JsUndefined)) {
                    if (!$ctorVal instanceof JsObject) {
                        throw new \Phasis\Exceptions\TypeError(
                            'Property `constructor` is not an object'
                        );
                    }
                    $speciesSymbol = SymbolConstructor::species();
                    $speciesVal = $ctorVal->getBySymbol($speciesSymbol);
                    if ($speciesVal instanceof JsUndefined || $speciesVal instanceof \Phasis\Value\JsNull) {
                        // Use default
                    } elseif ($speciesVal instanceof \Phasis\Value\JsFunction && $speciesVal->isConstructable()) {
                        $C = $speciesVal;
                    } else {
                        throw new \Phasis\Exceptions\TypeError(
                            '@@species must be a constructor'
                        );
                    }
                }
                // Get flags. Per spec 22.2.5.13 step 5-7: read flags first, then
                // derive unicodeMatching, then construct newFlags.
                $flags = TypeConversion::toString($this_->get('flags'));
                $unicodeMatching = str_contains($flags, 'u') || str_contains($flags, 'v');
                if (!str_contains($flags, 'y')) {
                    $flags .= 'y';
                }
                // Construct(C, [rx, newFlags]): calls new C(rx, flags).
                // This triggers IsRegExp on rx which may access Symbol.match.
                $interp = \Phasis\Engine::getCurrentInterpreter();
                if ($interp !== null) {
                    $splitter = $interp->callNew($C, [$this_, new JsString($flags)]);
                    if (!$splitter instanceof JsObject) {
                        $splitter = $this_;
                    }
                }
            }

            // Step 13: ToUint32(limit) happens after splitter creation.
            $lim = $limitArg instanceof JsUndefined
                ? 0xFFFFFFFF
                : TypeConversion::toUint32($limitArg);

            if ($lim === 0) {
                return JsArray::fromArray([]);
            }

            // Get the PCRE pattern from the splitter (which may differ from the
            // original if side effects recompiled the regex).
            /** @var JsObject $splitter */
            $pcrePatternDesc = $splitter->getOwnPropertyDescriptor('[[PCREPattern]]');
            $pcrePattern = ($pcrePatternDesc !== null && $pcrePatternDesc->value instanceof JsString)
                ? $pcrePatternDesc->value->value
                : null;

            // If no compiled PCRE, OR the splitter's exec has been
            // overridden, fall back to the spec exec-based approach via
            // the prototype lookup. Walk the property descriptor chain
            // ourselves rather than calling ->get('exec'); the latter
            // is observable as an extra get-trap in tests that monkeypatch
            // RegExp.prototype.exec (sm/RegExp/split-trace, etc.).
            $execIsIntrinsic = self::$intrinsicExec !== null
                && self::isIntrinsicExec($splitter);
            if ($pcrePattern === null || !$execIsIntrinsic) {
                return self::symbolSplitViaExec($splitter, $S, $lim, $unicodeMatching);
            }

            $size = mb_strlen($S, 'UTF-8');

            $A = [];
            $lengthA = 0;

            // Empty string special case per spec step 11.
            if ($size === 0) {
                if (self::stickyMatchAt($pcrePattern, $S, 0) !== null) {
                    return JsArray::fromArray([]);
                }
                return JsArray::fromArray([new JsString($S)]);
            }

            $p = 0; // End of last match (character offset).
            $q = 0; // Current search position (character offset).

            while ($q < $size) {
                // Per spec, the splitter has the 'y' flag so it must match
                // at exactly position q. We simulate sticky matching.
                $byteOffset = strlen(mb_substr($S, 0, $q, 'UTF-8'));
                $match = self::stickyMatchAt($pcrePattern, $S, $byteOffset);

                if ($match === null) {
                    // No match at position q: advance by one character.
                    $q++;
                    continue;
                }

                // Match found at position q. Compute e (end of match in char offset).
                $matchStr = $match[0][0];
                $matchCharLen = mb_strlen($matchStr, 'UTF-8');
                $e = $q + $matchCharLen;

                if ($e === $p) {
                    // Zero-width match at the same split point: advance to avoid
                    // infinite loop (spec step 13.c.iii.2).
                    $q++;
                    continue;
                }

                // Append the substring from p to q (before the match).
                $T = mb_substr($S, $p, $q - $p, 'UTF-8');
                $A[] = new JsString($T);
                $lengthA++;
                if ($lengthA === $lim) {
                    return JsArray::fromArray($A);
                }

                $p = $e;

                // Append capture groups (indices 1..n).
                $nCaptures = 0;
                foreach ($match as $key => $val) {
                    if (is_int($key) && $key > 0) {
                        $nCaptures = max($nCaptures, $key);
                    }
                }
                for ($i = 1; $i <= $nCaptures; $i++) {
                    if (!isset($match[$i]) || $match[$i][1] === -1 || $match[$i][0] === null) {
                        $A[] = JsUndefined::instance();
                    } else {
                        $A[] = new JsString($match[$i][0]);
                    }
                    $lengthA++;
                    if ($lengthA === $lim) {
                        return JsArray::fromArray($A);
                    }
                }

                $q = $p;
            }

            // Append remainder.
            $T = mb_substr($S, $p, null, 'UTF-8');
            $A[] = new JsString($T);
            return JsArray::fromArray($A);
        };
    }

    /**
     * Fallback split using exec for objects without [[PCREPattern]].
     *
     * Per spec 22.2.5.13, the split loop indexes the input by UTF-16 code
     * units, and in unicodeMatching mode advances the cursor with
     * AdvanceStringIndex (which steps over surrogate pairs as one unit).
     * sm/RegExp/split-trace observes this exact step pattern via a Proxy
     * splitter, so we must use UTF-16 indices and AdvanceStringIndex here.
     */
    private static function symbolSplitViaExec(JsObject $rx, string $S, int $lim, bool $unicodeMatching = false): JsArray
    {
        // $size, $p, $q are UTF-16 code unit indices.
        $u16 = JsString::utf8ToUtf16LE($S);
        $size = (int) (strlen($u16) / 2);

        if ($size === 0) {
            // Per spec 22.2.5.13 step 24: empty target — call regExpExec
            // once and return [] or [S]. Do NOT set lastIndex (the test
            // sm/RegExp/split-trace observes this).
            $z = self::regExpExec($rx, $S);
            if (!$z instanceof JsNull) {
                return JsArray::fromArray([]);
            }
            return JsArray::fromArray([new JsString($S)]);
        }

        $A = [];
        $lengthA = 0;
        $p = 0;
        $q = 0;

        while ($q < $size) {
            $rx->set('lastIndex', JsNumber::of((float) $q));
            $z = self::regExpExec($rx, $S);

            if ($z instanceof JsNull) {
                $q = $unicodeMatching
                    ? self::advanceStringIndex($S, $q)
                    : $q + 1;
                continue;
            }

            // Per spec step 24.d.i: e = ToLength(Get(splitter, "lastIndex")).
            // Per step 24.d.ii: clamp e to size. Per step 24.d.iii: if e === p,
            // advance q (not q === e) — comparing to p is what lets the loop
            // observe lastIndex updates from a custom exec.
            $eVal = TypeConversion::toLength($rx->get('lastIndex'));
            $e = min($eVal, $size);
            if ($e === $p) {
                $q = $unicodeMatching
                    ? self::advanceStringIndex($S, $q)
                    : $q + 1;
                continue;
            }

            $T = self::sliceUtf16Range($S, $p, $q);
            $A[] = new JsString($T);
            $lengthA++;
            if ($lengthA === $lim) {
                return JsArray::fromArray($A);
            }

            $p = $e;
            // Per spec 24.d.iv.7: ToLength(Get(z, "length")). This is observable
            // (a Symbol or throwing valueOf must propagate).
            $lenVal = $z->get('length');
            $nCaptures = max(TypeConversion::toLength($lenVal) - 1, 0);
            for ($i = 1; $i <= $nCaptures; $i++) {
                $A[] = $z->get((string) $i);
                $lengthA++;
                if ($lengthA === $lim) {
                    return JsArray::fromArray($A);
                }
            }
            $q = $p;
        }

        $A[] = new JsString(self::sliceUtf16Range($S, $p, $size));
        return JsArray::fromArray($A);
    }
}
