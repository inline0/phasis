<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Object\PropertyDescriptor;
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

/**
 * Implements RegExp.prototype[Symbol.search], [Symbol.match], [Symbol.replace],
 * [Symbol.matchAll], and [Symbol.split] per the ECMAScript specification.
 *
 * These are installed on RegExp.prototype so that String.prototype.search/match/
 * replace/matchAll/split can delegate to them via the Symbol method lookup.
 */
class RegExpPrototype
{
    public static function install(JsObject $proto): void
    {
        $addMethod = static function (string $name, \Closure $fn, int $length) use ($proto): void {
            $func = JsFunction::fromCallable($name, $fn, $length);
            $proto->defineOwnProperty($name, PropertyDescriptor::data($func, true, false, true));
        };

        $addSymbol = static function (
            \PhpJs\Value\JsSymbol $sym,
            string $name,
            \Closure $fn,
            int $length,
        ) use ($proto): void {
            $func = JsFunction::fromCallable($name, $fn, $length);
            $proto->definePropertyBySymbol($sym, PropertyDescriptor::data($func, true, false, true));
        };

        // Per spec §22.2.6.2: RegExp.prototype.exec reads [[PCREPattern]] from this.
        $addMethod('exec', self::execMethod(), 1);

        // Per spec §22.2.6.14: RegExp.prototype.test calls exec.
        $addMethod('test', static function (JsValue $this_, array $args): JsValue {
            $execMethod = $this_ instanceof JsObject ? $this_->get('exec') : null;
            if ($execMethod instanceof JsFunction) {
                $result = $execMethod->call($this_, $args);
                return new JsBoolean(!$result instanceof JsNull);
            }
            return new JsBoolean(false);
        }, 1);

        // Per spec §22.2.6.15: RegExp.prototype.toString returns /source/flags.
        $addMethod('toString', static function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new \PhpJs\Exceptions\TypeError('RegExp.prototype.toString called on non-object');
            }
            $source = TypeConversion::toString($this_->get('source'));
            $flags = TypeConversion::toString($this_->get('flags'));
            return new JsString("/{$source}/{$flags}");
        }, 0);

        // Accessor properties on RegExp.prototype for source, flags, global, etc.
        // These read own data properties from each RegExp instance.
        // Instances have their own shadow data properties, so the accessor is only
        // invoked when querying RegExp.prototype itself (not instances).
        $flagAccessor = static function (string $propName) use ($proto): void {
            $getter = JsFunction::fromCallable(
                'get ' . $propName,
                static function (JsValue $this_, array $args) use ($propName): JsValue {
                    if (!$this_ instanceof JsObject) {
                        throw new \PhpJs\Exceptions\TypeError("get {$propName} called on non-object");
                    }
                // For RegExp.prototype itself, return undefined per spec.
                    $pcrePattern = $this_->getOwnPropertyDescriptor('[[PCREPattern]]');
                    if ($pcrePattern === null) {
                        return JsUndefined::instance();
                    }
                    return $this_->get($propName);
                },
                0,
            );
            $proto->defineOwnProperty($propName, \PhpJs\Object\PropertyDescriptor::accessor(
                get: $getter,
                set: null,
                enumerable: false,
                configurable: true,
            ));
        };

        $flagAccessor('source');
        $flagAccessor('flags');
        $flagAccessor('global');
        $flagAccessor('ignoreCase');
        $flagAccessor('multiline');
        $flagAccessor('dotAll');
        $flagAccessor('unicode');
        $flagAccessor('unicodeSets');
        $flagAccessor('sticky');
        $flagAccessor('hasIndices');

        // Annex B: RegExp.prototype.compile(pattern, flags).
        // Re-initializes the regexp in-place with new pattern and flags.
        $addMethod('compile', static function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new \PhpJs\Exceptions\TypeError(
                    'Method RegExp.prototype.compile called on incompatible receiver',
                );
            }
            // Check for [[RegExpMatcher]] internal slot (we use [[PCREPattern]]).
            $pcreDesc = $this_->getOwnPropertyDescriptor('[[PCREPattern]]');
            if ($pcreDesc === null) {
                throw new \PhpJs\Exceptions\TypeError(
                    'Method RegExp.prototype.compile called on incompatible receiver',
                );
            }

            $patternArg = $args[0] ?? JsUndefined::instance();
            $flagsArg = $args[1] ?? JsUndefined::instance();

            // If pattern is a RegExp object, flags must be undefined.
            $isRegExp = false;
            if ($patternArg instanceof JsObject) {
                $patternPcre = $patternArg->getOwnPropertyDescriptor('[[PCREPattern]]');
                if ($patternPcre !== null) {
                    $isRegExp = true;
                }
            }

            if ($isRegExp) {
                if (!$flagsArg instanceof JsUndefined) {
                    throw new \PhpJs\Exceptions\TypeError(
                        'Cannot supply flags when constructing one RegExp from another',
                    );
                }
                // Read from [[OriginalSource]]/[[OriginalFlags]] internal slots
                // to avoid triggering user-defined accessors on the instance.
                /** @var JsObject $patternArg */
                $srcDesc = $patternArg->getOwnPropertyDescriptor('[[OriginalSource]]');
                $flgDesc = $patternArg->getOwnPropertyDescriptor('[[OriginalFlags]]');
                if ($srcDesc !== null && $srcDesc->value !== null) {
                    $p = TypeConversion::toString($srcDesc->value);
                } else {
                    $p = TypeConversion::toString($patternArg->get('source'));
                    if ($p === '(?:)') {
                        $p = '';
                    }
                }
                if ($flgDesc !== null && $flgDesc->value !== null) {
                    $f = TypeConversion::toString($flgDesc->value);
                } else {
                    $f = TypeConversion::toString($patternArg->get('flags'));
                }
            } else {
                $p = $patternArg instanceof JsUndefined
                    ? ''
                    : TypeConversion::toString($patternArg);
                $f = $flagsArg instanceof JsUndefined
                    ? ''
                    : TypeConversion::toString($flagsArg);
            }

            // Create a temporary regexp to validate and get compiled data.
            // This propagates SyntaxError for invalid patterns/flags.
            $temp = \PhpJs\Engine::createRegExpOrThrow($p, $f);

            // Copy properties from temp to this in-place.
            $propsToUpdate = [
                'source', 'flags', 'global', 'ignoreCase', 'multiline',
                'dotAll', 'unicode', 'unicodeSets', 'sticky', 'hasIndices',
                '[[PCREPattern]]', '[[OriginalSource]]', '[[OriginalFlags]]',
                'exec', 'test', 'toString',
            ];
            foreach ($propsToUpdate as $prop) {
                $desc = $temp->getOwnPropertyDescriptor($prop);
                if ($desc !== null) {
                    $this_->defineOwnProperty($prop, $desc);
                }
            }

            // Set lastIndex to 0 per spec. This may throw if lastIndex is non-writable.
            $this_->set('lastIndex', new JsNumber(0.0), true);

            return $this_;
        }, 2);

        $addSymbol(SymbolConstructor::search(), '[Symbol.search]', self::symbolSearch(), 1);
        $addSymbol(SymbolConstructor::match(), '[Symbol.match]', self::symbolMatch(), 1);
        $addSymbol(SymbolConstructor::replace(), '[Symbol.replace]', self::symbolReplace(), 2);
        $addSymbol(SymbolConstructor::matchAll(), '[Symbol.matchAll]', self::symbolMatchAll(), 1);
        $addSymbol(SymbolConstructor::split(), '[Symbol.split]', self::symbolSplit(), 2);
    }

    /**
     * RegExp.prototype.exec(string) - spec §22.2.6.2
     *
     * Reads the [[PCREPattern]] internal slot from `this` and executes the match.
     * This is a shared prototype method; each RegExp instance also has its own
     * exec that shadows this (preserving backward compatibility for direct invocation).
     */
    private static function execMethod(): \Closure
    {
        return static function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new \PhpJs\Exceptions\TypeError('RegExp.prototype.exec called on non-object');
            }

            $pcrePatternDesc = $this_->getOwnPropertyDescriptor('[[PCREPattern]]');
            if ($pcrePatternDesc === null || !$pcrePatternDesc->value instanceof JsString) {
                throw new \PhpJs\Exceptions\TypeError('RegExp.prototype.exec called on incompatible receiver');
            }
            $pcrePattern = $pcrePatternDesc->value->value;

            $str = isset($args[0]) ? TypeConversion::toString($args[0]) : '';
            $strLen = mb_strlen($str, 'UTF-8');

            $isGlobal  = TypeConversion::toBoolean($this_->get('global'));
            $isSticky  = TypeConversion::toBoolean($this_->get('sticky'));

            if ($isGlobal || $isSticky) {
                $lastIndexVal = $this_->get('lastIndex');
                $lastIndex = (int) TypeConversion::toNumber($lastIndexVal);
            } else {
                $lastIndex = 0;
            }

            if ($lastIndex < 0 || $lastIndex > $strLen) {
                if ($isGlobal || $isSticky) {
                    $this_->set('lastIndex', new JsNumber(0.0), true);
                }
                return JsNull::instance();
            }

            $byteOffset = strlen(mb_substr($str, 0, $lastIndex, 'UTF-8'));

            if (@preg_match($pcrePattern, $str, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $byteOffset)) {
                $matchBytePos = $matches[0][1];
                if ($isSticky && $matchBytePos !== $byteOffset) {
                    $this_->set('lastIndex', new JsNumber(0.0), true);
                    return JsNull::instance();
                }
                $matchCharPos = mb_strlen(substr($str, 0, $matchBytePos), 'UTF-8');
                $matchStr = $matches[0][0];
                $matchCharLen = mb_strlen($matchStr, 'UTF-8');

                if ($isGlobal || $isSticky) {
                    $this_->set('lastIndex', new JsNumber((float) ($matchCharPos + $matchCharLen)), true);
                }

                $elements = [];
                foreach ($matches as $key => $match) {
                    if (is_int($key)) {
                        $elements[] = ($match[1] === -1 || $match[0] === null)
                            ? JsUndefined::instance()
                            : new JsString($match[0]);
                    }
                }
                $result = JsArray::fromArray($elements);
                $result->set('index', new JsNumber((float) $matchCharPos));
                $result->set('input', new JsString($str));

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

            if ($isGlobal || $isSticky) {
                $this_->set('lastIndex', new JsNumber(0.0), true);
            }
            return JsNull::instance();
        };
    }

    /**
     * RegExp.prototype[@@search](string) - spec §22.2.6.11
     *
     * Returns the index of the first match, or -1 if no match.
     */
    private static function symbolSearch(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new \PhpJs\Exceptions\TypeError(
                    'Method RegExp.prototype[@@search] called on incompatible receiver'
                );
            }

            $S = TypeConversion::toString($args[0] ?? JsUndefined::instance());

            // Save previousLastIndex.
            $previousLastIndex = $this_->get('lastIndex');

            // If previousLastIndex != 0, reset to 0.
            if (!self::sameValueZero($previousLastIndex, new JsNumber(0.0))) {
                $this_->set('lastIndex', new JsNumber(0.0));
            }

            // Call exec.
            $result = self::regExpExec($this_, $S);

            // Restore lastIndex if changed.
            $currentLastIndex = $this_->get('lastIndex');
            if (!self::sameValueZero($currentLastIndex, $previousLastIndex)) {
                $this_->set('lastIndex', $previousLastIndex);
            }

            if ($result instanceof JsNull) {
                return new JsNumber(-1.0);
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
                throw new \PhpJs\Exceptions\TypeError(
                    'Method RegExp.prototype[@@match] called on incompatible receiver'
                );
            }

            $S = TypeConversion::toString($args[0] ?? JsUndefined::instance());
            $global = TypeConversion::toBoolean($this_->get('global'));

            if (!$global) {
                // Non-global: single RegExpExec call.
                return self::regExpExec($this_, $S);
            }

            // Global: iterate all matches.
            $fullUnicode = TypeConversion::toBoolean($this_->get('unicode'))
                || TypeConversion::toBoolean($this_->get('unicodeSets'));

            // Per spec: Set(rx, "lastIndex", +0, Throw=true).
            $this_->set('lastIndex', new JsNumber(0.0), true);

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
                    $this_->set('lastIndex', new JsNumber((float) $nextIndex), true);
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
                throw new \PhpJs\Exceptions\TypeError(
                    'Method RegExp.prototype[@@replace] called on incompatible receiver'
                );
            }

            $string = $args[0] ?? JsUndefined::instance();
            $replaceValue = $args[1] ?? JsUndefined::instance();

            $S = TypeConversion::toString($string);
            $lengthS = mb_strlen($S, 'UTF-8');

            $functionalReplace = $replaceValue instanceof JsFunction;
            if (!$functionalReplace) {
                $replaceStr = TypeConversion::toString($replaceValue);
            } else {
                $replaceStr = '';
            }

            $global = TypeConversion::toBoolean($this_->get('global'));
            $fullUnicode = false;

            if ($global) {
                $fullUnicode = TypeConversion::toBoolean($this_->get('unicode'))
                    || TypeConversion::toBoolean($this_->get('unicodeSets'));
                // Per spec step 10.c: Set(rx, "lastIndex", +0, Throw=true).
                $this_->set('lastIndex', new JsNumber(0.0), true);
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
                            $this_->set('lastIndex', new JsNumber((float) $nextIndex), true);
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
                $matchLength = mb_strlen($matched, 'UTF-8');

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
                // Per spec: if namedCaptures is not undefined, Set namedCaptures to ToObject(namedCaptures).
                // Note: ToObject(null) throws TypeError - do NOT skip null.
                $namedCaptures = null;
                $groupsVal = $result->get('groups');
                if (!$groupsVal instanceof JsUndefined) {
                    // ToObject throws for null - spec §14.l.i.1
                    $groupsObj = TypeConversion::toObject($groupsVal);
                    $namedCaptures = [];
                    foreach ($groupsObj->getOwnPropertyNames() as $key) {
                        $val = $groupsObj->get($key);
                        $namedCaptures[$key] = $val instanceof JsUndefined ? null : TypeConversion::toString($val);
                    }
                }

                if ($functionalReplace) {
                    // Build args: matched, ...captures, position, S[, namedCaptures]
                    $callArgs = [new JsString($matched)];
                    foreach ($captures as $cap) {
                        $callArgs[] = $cap === null ? JsUndefined::instance() : new JsString($cap);
                    }
                    $callArgs[] = new JsNumber((float) $position);
                    $callArgs[] = new JsString($S);
                    if ($namedCaptures !== null) {
                        $groupsObj = new JsObject(null);
                        foreach ($namedCaptures as $k => $v) {
                            $groupsObj->set($k, $v === null ? JsUndefined::instance() : new JsString($v));
                        }
                        $callArgs[] = $groupsObj;
                    }
                    $replValue = $replaceValue->call(JsUndefined::instance(), $callArgs);
                    $replacement = TypeConversion::toString($replValue);
                } else {
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
                    $accumulatedResult .= mb_substr($S, $nextSourcePosition, $position - $nextSourcePosition, 'UTF-8');
                    $accumulatedResult .= $replacement;
                    $nextSourcePosition = $position + $matchLength;
                }
            }

            return new JsString($accumulatedResult . mb_substr($S, $nextSourcePosition, null, 'UTF-8'));
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
                throw new \PhpJs\Exceptions\TypeError(
                    'Method RegExp.prototype[@@matchAll] called on incompatible receiver'
                );
            }

            $S = TypeConversion::toString($args[0] ?? JsUndefined::instance());

            // Get flags to create a copy with the same flags.
            $flagsVal = $this_->get('flags');
            $flags = TypeConversion::toString($flagsVal);
            $global = str_contains($flags, 'g');
            $fullUnicode = str_contains($flags, 'u') || str_contains($flags, 'v');

            // Create a copy of the regexp (using the exec method from the same object
            // but with lastIndex propagated). Per spec we should use SpeciesConstructor
            // but for simplicity we use the same object and save/restore state.
            // Actually per spec we create a new regexp. For now, clone the state.
            // We'll use the original regexp's exec but track state in our iterator.
            $lastIndexVal = $this_->get('lastIndex');
            $startIndex = (int) TypeConversion::toNumber($lastIndexVal);

            // We need a "matcher" - use the original rx since exec is instance-specific.
            // Per spec, we create a copy; approximate by using original rx with saved lastIndex.
            $matcher = $this_;

            // Create the iterator closure over $matcher, $S, $global, $fullUnicode.
            $done = false;
            $currentIndex = $startIndex;

            $iterator = new JsObject();
            $nextFn = function () use (&$done, $matcher, $S, $global, $fullUnicode, &$currentIndex): JsValue {
                $result = new JsObject();
                if ($done) {
                    $result->set('value', JsUndefined::instance());
                    $result->set('done', new JsBoolean(true));
                    return $result;
                }

                // Set lastIndex before exec.
                if ($global) {
                    $matcher->set('lastIndex', new JsNumber((float) $currentIndex));
                }

                $match = self::regExpExec($matcher, $S);

                if ($match instanceof JsNull) {
                    $done = true;
                    $result->set('value', JsUndefined::instance());
                    $result->set('done', new JsBoolean(true));
                    return $result;
                }

                if ($global) {
                    $matchStr = TypeConversion::toString($match->get('0'));
                    if ($matchStr === '') {
                        // Advance to avoid infinite loop.
                        $nextIndex = $fullUnicode
                            ? self::advanceStringIndex($S, $currentIndex)
                            : $currentIndex + 1;
                        $currentIndex = $nextIndex;
                        $matcher->set('lastIndex', new JsNumber((float) $currentIndex));
                    } else {
                        $currentIndex = (int) TypeConversion::toNumber($matcher->get('lastIndex'));
                    }
                } else {
                    $done = true;
                }

                $result->set('value', $match);
                $result->set('done', new JsBoolean(false));
                return $result;
            };

            $iterator->set('next', JsFunction::fromCallable('next', $nextFn, 0));
            $iterSym = SymbolConstructor::iterator();
            $iterator->setBySymbol($iterSym, JsFunction::fromCallable(
                '[Symbol.iterator]',
                function () use ($iterator): JsValue {
                    return $iterator;
                },
            ));
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
                throw new \PhpJs\Exceptions\TypeError(
                    'Method RegExp.prototype[@@split] called on incompatible receiver'
                );
            }

            $string = $args[0] ?? JsUndefined::instance();
            $limitArg = $args[1] ?? JsUndefined::instance();

            $S = TypeConversion::toString($string);
            $lim = $limitArg instanceof JsUndefined
                ? 0xFFFFFFFF
                : TypeConversion::toUint32($limitArg);

            if ($lim === 0) {
                return JsArray::fromArray([]);
            }

            // Per spec step 4-10: create a copy via SpeciesConstructor with 'y' flag.
            // This allows side effects (like Symbol.match getters that recompile the
            // regex) to take effect before splitting begins.
            $splitter = $this_;
            $regExpCtor = \PhpJs\Engine::$currentInterpreter
                ? \PhpJs\Engine::$currentInterpreter->getGlobalValue('RegExp')
                : null;
            if ($regExpCtor instanceof \PhpJs\Value\JsFunction) {
                // SpeciesConstructor: check rx.constructor[Symbol.species] or default to RegExp.
                $C = $regExpCtor;
                $ctorVal = $this_->get('constructor');
                if ($ctorVal instanceof JsObject) {
                    $speciesSymbol = SymbolConstructor::species();
                    $speciesVal = $ctorVal->getBySymbol($speciesSymbol);
                    if ($speciesVal instanceof \PhpJs\Value\JsFunction) {
                        $C = $speciesVal;
                    } elseif (!($speciesVal instanceof JsUndefined) && !($speciesVal instanceof \PhpJs\Value\JsNull)) {
                        // Use default
                    }
                }
                // Get flags and add 'y'.
                $flags = TypeConversion::toString($this_->get('flags'));
                if (!str_contains($flags, 'y')) {
                    $flags .= 'y';
                }
                // Construct(C, [rx, newFlags]): calls new C(rx, flags).
                // This triggers IsRegExp on rx which may access Symbol.match.
                $interp = \PhpJs\Engine::$currentInterpreter;
                if ($interp !== null) {
                    $splitter = $interp->callNew($C, [$this_, new JsString($flags)]);
                    if (!$splitter instanceof JsObject) {
                        $splitter = $this_;
                    }
                }
            }

            // Get the PCRE pattern from the splitter (which may differ from the
            // original if side effects recompiled the regex).
            /** @var JsObject $splitter */
            $pcrePatternDesc = $splitter->getOwnPropertyDescriptor('[[PCREPattern]]');
            $pcrePattern = ($pcrePatternDesc !== null && $pcrePatternDesc->value instanceof JsString)
                ? $pcrePatternDesc->value->value
                : null;

            // If no compiled PCRE, fall back to exec-based approach via prototype exec.
            if ($pcrePattern === null) {
                return self::symbolSplitViaExec($splitter, $S, $lim);
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
     * Perform a sticky match at exact byte offset. Returns the match array
     * (PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL format) or null.
     *
     * @return array<int|string, array{0: ?string, 1: int}>|null
     */
    private static function stickyMatchAt(string $pcrePattern, string $str, int $byteOffset): ?array
    {
        if (@preg_match($pcrePattern, $str, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $byteOffset) !== 1) {
            return null;
        }
        // Sticky: match must start exactly at byteOffset.
        if ($matches[0][1] !== $byteOffset) {
            return null;
        }
        return $matches;
    }

    /**
     * Fallback split using exec for objects without [[PCREPattern]].
     */
    private static function symbolSplitViaExec(JsObject $rx, string $S, int $lim): JsArray
    {
        $size = mb_strlen($S, 'UTF-8');

        if ($size === 0) {
            $rx->set('lastIndex', new JsNumber(0.0));
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
            $rx->set('lastIndex', new JsNumber((float) $q));
            $z = self::regExpExec($rx, $S);

            if ($z instanceof JsNull) {
                $q++;
                continue;
            }

            $e = (int) TypeConversion::toNumber($rx->get('lastIndex'));
            if ($e === $q) {
                $q++;
                continue;
            }

            $matchStart = (int) TypeConversion::toNumber($z->get('index'));
            $T = mb_substr($S, $p, $matchStart - $p, 'UTF-8');
            $A[] = new JsString($T);
            $lengthA++;
            if ($lengthA === $lim) {
                return JsArray::fromArray($A);
            }

            $p = $e;
            $nCaptures = max((int) TypeConversion::toNumber($z->get('length')) - 1, 0);
            for ($i = 1; $i <= $nCaptures; $i++) {
                $A[] = $z->get((string) $i);
                $lengthA++;
                if ($lengthA === $lim) {
                    return JsArray::fromArray($A);
                }
            }
            $q = $p;
        }

        $A[] = new JsString(mb_substr($S, $p, null, 'UTF-8'));
        return JsArray::fromArray($A);
    }

    /**
     * Abstract operation RegExpExec(R, S) - spec §22.2.7.
     * Calls R.exec(S) and returns the result.
     */
    private static function regExpExec(JsObject $rx, string $S): JsObject|JsNull
    {
        $execVal = $rx->get('exec');
        if ($execVal instanceof JsFunction) {
            $result = $execVal->call($rx, [new JsString($S)]);
            if ($result instanceof JsNull) {
                return $result;
            }
            if ($result instanceof JsObject) {
                return $result;
            }
            throw new \PhpJs\Exceptions\TypeError(
                'RegExp exec must return an Object or null'
            );
        }
        return JsNull::instance();
    }

    /**
     * Advance string index by one Unicode code point (for fullUnicode mode).
     */
    private static function advanceStringIndex(string $S, int $index): int
    {
        // In UTF-8 we just advance by one character (code point).
        // For truly correct UTF-16 surrogate pair handling we'd need UTF-16 semantics,
        // but for most cases this is sufficient.
        $len = mb_strlen($S, 'UTF-8');
        if ($index + 1 >= $len) {
            return $index + 1;
        }
        return $index + 1;
    }

    /**
     * SameValue comparison for lastIndex tracking.
     * For numbers, distinguishes +0 and -0 (unlike ===).
     */
    private static function sameValueZero(JsValue $x, JsValue $y): bool
    {
        if ($x instanceof JsNumber && $y instanceof JsNumber) {
            if (is_nan($x->value) && is_nan($y->value)) {
                return true;
            }
            return $x->value === $y->value;
        }
        if ($x instanceof JsString && $y instanceof JsString) {
            return $x->value === $y->value;
        }
        if ($x instanceof JsBoolean && $y instanceof JsBoolean) {
            return $x->value === $y->value;
        }
        if ($x instanceof JsNull && $y instanceof JsNull) {
            return true;
        }
        if ($x instanceof JsUndefined && $y instanceof JsUndefined) {
            return true;
        }
        return $x === $y;
    }
}
