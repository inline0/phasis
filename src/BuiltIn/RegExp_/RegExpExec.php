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

/**
 * RegExpPrototype trait part: RegExpExec. Composed into
 * RegExpPrototype via `use RegExp_\RegExpExec;`.
 */
trait RegExpExec
{
    /**
     * @param list<string> $groups
     */
    /**
     * Return true when the receiver's `exec` slot resolves (via the property
     * descriptor chain) to the intrinsic RegExp.prototype.exec, without
     * triggering a [[Get]] that would be observable to user-installed traps
     * or accessor side effects.
     */
    private static function isIntrinsicExec(JsObject $obj): bool
    {
        $current = $obj;
        $guard = 0;
        while ($current !== null && $guard++ < 32) {
            $desc = $current->getOwnPropertyDescriptor('exec');
            if ($desc !== null) {
                if ($desc->isAccessorDescriptor()) {
                    return false;
                }
                return $desc->value === self::$intrinsicExec;
            }
            $current = $current->getPrototype();
        }
        return false;
    }

    /**
     * @param array<mixed> $groups
     */
    public static function recordLegacyMatch(
        string $input,
        string $lastMatch,
        string $leftContext,
        string $rightContext,
        string $lastParen,
        array $groups,
    ): void {
        self::$legacyInput = $input;
        self::$legacyLastMatch = $lastMatch;
        self::$legacyLeftContext = $leftContext;
        self::$legacyRightContext = $rightContext;
        self::$legacyLastParen = $lastParen;
        self::$legacyGroups = $groups;
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
                throw new \Phasis\Exceptions\TypeError('RegExp.prototype.exec called on non-object');
            }

            // Step 1: confirm R has [[RegExpMatcher]] (we use [[PCREPattern]]).
            if ($this_->getOwnPropertyDescriptor('[[PCREPattern]]') === null) {
                throw new \Phasis\Exceptions\TypeError('RegExp.prototype.exec called on incompatible receiver');
            }

            // Per spec: if no argument, convert undefined to "undefined".
            $str = isset($args[0]) ? TypeConversion::toString($args[0])
                : TypeConversion::toString(\Phasis\Value\JsUndefined::instance());
            // lastIndex is a UTF-16 code unit offset (spec
            // 22.2.7.2 RegExpBuiltinExec step 6), so $strLen must be the
            // UTF-16 code unit count too — mb_strlen gives codepoints,
            // which would short-circuit any iteration that has stepped
            // past `mb_strlen` units into the second half of an astral
            // character (e.g. /./gu over '👨‍👩‍👧‍👦').
            $strLen = (int) (strlen(JsString::utf8ToUtf16LE($str)) / 2);

            // Per spec step 4: read lastIndex first so any observable side
            // effects (e.g. a valueOf that calls regExp.compile) settle the
            // pattern/flags before we read them for the actual match.
            $lastIndexVal = $this_->get('lastIndex');
            $lastIndex = TypeConversion::toLength($lastIndexVal);

            // Per RegExpBuiltinExec, `global` and `sticky` come from the
            // [[OriginalFlags]] internal slot — NOT the public getter.
            // User overrides like Object.defineProperty(r, 'global', ...)
            // must not influence whether lastIndex is updated.
            $origFlagsDesc = $this_->getOwnPropertyDescriptor('[[OriginalFlags]]');
            $origFlags = ($origFlagsDesc !== null && $origFlagsDesc->value instanceof JsString)
                ? $origFlagsDesc->value->value
                : '';
            $isGlobal = str_contains($origFlags, 'g');
            $isSticky = str_contains($origFlags, 'y');
            $hasIndices = str_contains($origFlags, 'd');

            // Read the (potentially recompiled) pattern after lastIndex
            // coercion. compile() inside the ToLength call would have
            // overwritten [[PCREPattern]] by this point. The slot is
            // guaranteed present by the early check above; only its
            // (possibly mutated) value type still needs validation.
            $pcrePatternDesc = $this_->getOwnPropertyDescriptor('[[PCREPattern]]');
            $pcrePatternVal = $pcrePatternDesc->value;
            if (!$pcrePatternVal instanceof JsString) {
                throw new \Phasis\Exceptions\TypeError('RegExp.prototype.exec called on incompatible receiver');
            }
            $pcrePattern = $pcrePatternVal->value;

            if (!$isGlobal && !$isSticky) {
                $lastIndex = 0;
            }

            if ($lastIndex > $strLen) {
                if ($isGlobal || $isSticky) {
                    $this_->set('lastIndex', JsNumber::of(0.0), true);
                }
                return JsNull::instance();
            }

            // lastIndex is a UTF-16 code-unit offset; convert to UTF-8 byte
            // offset by walking the codepoints (each non-BMP char counts as
            // two code units).
            $byteOffset = self::utf16IndexToByteOffset($str, $lastIndex);

            // Custom-matcher fast path: when the pattern uses a feature
            // that PCRE2 cannot match exactly (lookbehind capture order,
            // capture reset between quantifier iterations), the
            // RegExp compiler stashed a parsed AST and the matcher
            // decides the result. Falls back to PCRE2 if our matcher
            // returns null for a pattern PCRE2 would have matched.
            $customAstDesc = $this_->getOwnPropertyDescriptor('[[CustomRegexAst]]');
            $customFlagsDesc = $this_->getOwnPropertyDescriptor('[[CustomRegexFlags]]');
            if (
                $customAstDesc !== null
                && $customAstDesc->value instanceof \Phasis\Value\JsHostValue
                && $customFlagsDesc !== null
                && $customFlagsDesc->value instanceof JsString
            ) {
                $budgetBlown = false;
                $customResult = null;
                try {
                    $customResult = self::execCustomMatcher(
                        $this_,
                        $customAstDesc->value->value,
                        $customFlagsDesc->value->value,
                        $str,
                        $lastIndex,
                        $isGlobal,
                        $isSticky,
                        $hasIndices,
                    );
                } catch (\Phasis\Regex\MatcherBudgetExceeded) {
                    // Pattern triggered catastrophic backtracking
                    // in our tree-walker; let PCRE2 handle it
                    // instead of failing the whole test chunk.
                    $budgetBlown = true;
                }
                if (!$budgetBlown) {
                    if ($customResult !== null) {
                        return $customResult;
                    }
                    // Custom matcher returned null = no match. Per
                    // RegExp semantics for global/sticky, advance
                    // lastIndex per spec.
                    if ($isGlobal || $isSticky) {
                        $this_->set('lastIndex', JsNumber::of(0.0), true);
                    }
                    return JsNull::instance();
                }
                // Fall through to PCRE2 path.
            }

            $pregResult = @preg_match($pcrePattern, $str, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $byteOffset);
            if ($pregResult === false && preg_last_error() === PREG_BAD_UTF8_ERROR) {
                // PCRE2 with /u rejects lone-surrogate input as invalid
                // UTF-8. The custom matcher walks JS strings code unit by
                // code unit and handles them per spec. Compile the AST
                // on demand and route the match through it. Cache the
                // AST on the regex object so subsequent execs reuse it.
                $sourceVal = $this_->get('source');
                $flagsVal = $this_->get('flags');
                $patternStr = $sourceVal instanceof JsString ? $sourceVal->value : '';
                $patFlags = $flagsVal instanceof JsString ? $flagsVal->value : '';
                try {
                    $regexAst = (new \Phasis\Regex\Parser($patternStr, $patFlags))->parse();
                    $this_->defineOwnProperty(
                        '[[CustomRegexAst]]',
                        PropertyDescriptor::data(
                            new \Phasis\Value\JsHostValue($regexAst),
                            false,
                            false,
                            false,
                        ),
                    );
                    $this_->defineOwnProperty(
                        '[[CustomRegexFlags]]',
                        PropertyDescriptor::data(
                            new JsString($patFlags),
                            false,
                            false,
                            false,
                        ),
                    );
                    $custom = self::execCustomMatcher(
                        $this_,
                        $regexAst,
                        $patFlags,
                        $str,
                        $lastIndex,
                        $isGlobal,
                        $isSticky,
                        $hasIndices,
                    );
                    if ($custom !== null) {
                        return $custom;
                    }
                    if ($isGlobal || $isSticky) {
                        $this_->set('lastIndex', JsNumber::of(0.0), true);
                    }
                    return JsNull::instance();
                } catch (\Throwable) {
                    // Parser couldn't model the pattern; fall through
                    // to the no-match path below.
                }
            }
            if ($pregResult === 1) {
                $matchBytePos = $matches[0][1];
                if ($isSticky && $matchBytePos !== $byteOffset) {
                    $this_->set('lastIndex', JsNumber::of(0.0), true);
                    return JsNull::instance();
                }

                // Apply ES-compliant fixes for repeated groups if needed.
                $matches = self::applyRepeatedGroupFixes($this_, $matches, $str, $pcrePattern);

                // Per spec, regex match index is a UTF-16 code-unit offset,
                // not a codepoint count — characters outside the BMP count as
                // two units. PCRE gives us a byte offset; convert through
                // UTF-16LE length.
                $matchCharPos = (int) (strlen(
                    JsString::utf8ToUtf16LE(substr($str, 0, $matches[0][1]))
                ) / 2);
                $matchStr = $matches[0][0];
                $matchCharLen = (int) (strlen(JsString::utf8ToUtf16LE($matchStr)) / 2);

                if ($isGlobal || $isSticky) {
                    $this_->set('lastIndex', JsNumber::of((float) ($matchCharPos + $matchCharLen)), true);
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
                // Per spec these properties are added with CreateDataProperty,
                // which bypasses any setter on the result's prototype chain
                // (e.g. tests that install a `groups` setter on
                // Array.prototype). Use defineOwnProperty.
                $result->defineOwnProperty(
                    'index',
                    PropertyDescriptor::data(JsNumber::of((float) $matchCharPos), true, true, true),
                );
                $result->defineOwnProperty(
                    'input',
                    PropertyDescriptor::data(new JsString($str), true, true, true),
                );

                $groups = JsObject::createNullPrototype();
                $hasGroups = false;
                $groupNameMapDesc = $this_->getOwnPropertyDescriptor('[[GroupNameMap]]');
                $groupNameMap = ($groupNameMapDesc !== null && $groupNameMapDesc->value instanceof JsObject)
                    ? $groupNameMapDesc->value
                    : null;
                // Pre-populate every distinct named group with undefined so
                // that names that did not participate in the match still
                // appear in source order (per duplicate-named-groups
                // proposal).
                $namedListDesc = $this_->getOwnPropertyDescriptor('[[NamedGroupNames]]');
                if ($namedListDesc !== null && $namedListDesc->value instanceof JsArray) {
                    $hasGroups = true;
                    $len = $namedListDesc->value->getLength();
                    for ($ni = 0; $ni < $len; $ni++) {
                        $nameVal = $namedListDesc->value->get((string) $ni);
                        if ($nameVal instanceof JsString) {
                            $groups->defineOwnProperty($nameVal->value, PropertyDescriptor::data(
                                JsUndefined::instance(),
                            ));
                        }
                    }
                }
                foreach ($matches as $key => $match) {
                    if (is_string($key)) {
                        $hasGroups = true;
                        if ($groupNameMap !== null) {
                            $orig = $groupNameMap->get($key);
                            if ($orig instanceof JsString) {
                                $key = $orig->value;
                            }
                        }
                        // Per spec: CreateDataProperty(groups, s, capturedValue).
                        // Skip when this group did not match — the slot
                        // was already pre-populated with undefined and we
                        // must not overwrite a successfully matched name.
                        if ($match[1] === -1 || $match[0] === null) {
                            continue;
                        }
                        $groups->defineOwnProperty($key, PropertyDescriptor::data(
                            new JsString($match[0]),
                        ));
                    }
                }
                $result->defineOwnProperty(
                    'groups',
                    PropertyDescriptor::data(
                        $hasGroups ? $groups : JsUndefined::instance(),
                        true,
                        true,
                        true,
                    ),
                );

                // Per spec sec-makematchindicesindexpairarray, when the
                // regex has the d flag emit an `indices` array on the
                // result with [start, end] pairs per capture, plus a
                // groups object mapping named captures to their pairs.
                if (str_contains($origFlags, 'd')) {
                    $indicesArr = [];
                    foreach ($matches as $key => $m) {
                        if (!is_int($key)) {
                            continue;
                        }
                        if ($m[1] === -1 || $m[0] === null) {
                            $indicesArr[] = JsUndefined::instance();
                            continue;
                        }
                        $startCp = (int) (strlen(JsString::utf8ToUtf16LE(substr($str, 0, $m[1]))) / 2);
                        $endCp = $startCp + (int) (strlen(JsString::utf8ToUtf16LE($m[0])) / 2);
                        $indicesArr[] = JsArray::fromArray([
                            JsNumber::of((float) $startCp),
                            JsNumber::of((float) $endCp),
                        ]);
                    }
                    $iArr = JsArray::fromArray($indicesArr);
                    $iGrp = JsObject::createNullPrototype();
                    $iHasGrp = false;
                    // Pre-populate every distinct named group with
                    // undefined so that names that did not participate in
                    // the match still appear in source order on the
                    // indices.groups object too.
                    if ($namedListDesc !== null && $namedListDesc->value instanceof JsArray) {
                        $iHasGrp = true;
                        $nlen = $namedListDesc->value->getLength();
                        for ($ni = 0; $ni < $nlen; $ni++) {
                            $nameVal = $namedListDesc->value->get((string) $ni);
                            if ($nameVal instanceof JsString) {
                                $iGrp->defineOwnProperty($nameVal->value, PropertyDescriptor::data(
                                    JsUndefined::instance(),
                                    true,
                                    true,
                                    true,
                                ));
                            }
                        }
                    }
                    foreach ($matches as $ik => $im) {
                        if (!is_string($ik)) {
                            continue;
                        }
                        $iHasGrp = true;
                        if ($groupNameMap !== null) {
                            $orig = $groupNameMap->get($ik);
                            if ($orig instanceof JsString) {
                                $ik = $orig->value;
                            }
                        }
                        if ($im[1] === -1 || $im[0] === null) {
                            // Don't overwrite a non-matching alternative
                            // when an earlier match populated the same
                            // name (e.g. duplicate named groups).
                            continue;
                        }
                        $sCp = (int) (strlen(JsString::utf8ToUtf16LE(substr($str, 0, $im[1]))) / 2);
                        $eCp = $sCp + (int) (strlen(JsString::utf8ToUtf16LE($im[0])) / 2);
                        $iGrp->defineOwnProperty($ik, PropertyDescriptor::data(
                            JsArray::fromArray([
                                JsNumber::of((float) $sCp),
                                JsNumber::of((float) $eCp),
                            ]),
                            true,
                            true,
                            true,
                        ));
                    }
                    $iArr->defineOwnProperty('groups', PropertyDescriptor::data(
                        $iHasGrp ? $iGrp : JsUndefined::instance(),
                        true,
                        true,
                        true,
                    ));
                    $result->defineOwnProperty('indices', PropertyDescriptor::data(
                        $iArr,
                        true,
                        true,
                        true,
                    ));
                }

                // Annex B legacy: record state for RegExp.lastMatch / $1..$9 etc.
                $matchByteEnd = $matches[0][1] + strlen((string) $matches[0][0]);
                $leftCtxBytes = substr($str, 0, $matches[0][1]);
                $rightCtxBytes = (string) substr($str, $matchByteEnd);
                $groupStrings = [];
                $lastNonEmpty = '';
                foreach ($matches as $key => $match) {
                    if (is_int($key) && $key > 0) {
                        $g = ($match[1] === -1 || $match[0] === null) ? '' : (string) $match[0];
                        $groupStrings[] = $g;
                        if ($g !== '') {
                            $lastNonEmpty = $g;
                        }
                    }
                }
                self::recordLegacyMatch(
                    $str,
                    (string) $matches[0][0],
                    $leftCtxBytes,
                    $rightCtxBytes,
                    $lastNonEmpty,
                    $groupStrings,
                );

                return $result;
            }

            if ($isGlobal || $isSticky) {
                $this_->set('lastIndex', JsNumber::of(0.0), true);
            }
            return JsNull::instance();
        };
    }

    /**
     * Apply ES-compliant fixes for repeated group captures and nullable quantifiers.
     * Analyzes the original ES pattern and post-processes PCRE match results.
     *
     * @param array<int|string, array{0: ?string, 1: int}> $matches
     * @return array<int|string, array{0: ?string, 1: int}>
     */
    /**
     * Run the in-engine ECMAScript regex matcher (src/Regex/Matcher.php)
     * and convert its result into the same shape exec() returns from
     * the PCRE2 path.
     */
    private static function execCustomMatcher(
        JsObject $this_,
        \Phasis\Regex\Ast\Pattern $ast,
        string $flags,
        string $str,
        int $lastIndex,
        bool $isGlobal,
        bool $isSticky,
        bool $hasIndices,
    ): ?JsObject {
        $matcher = new \Phasis\Regex\Matcher($ast, $flags);
        $startCu = $lastIndex;
        $stickyOnly = $isSticky;
        $match = null;
        if ($stickyOnly) {
            // Sticky: only attempt at lastIndex; reject any match that
            // starts past the requested anchor.
            $match = $matcher->match($str, $startCu);
            if ($match === null || $match['index'] !== $startCu) {
                $match = null;
            }
        } else {
            // Matcher::match already advances internally from
            // $startCu, returning the earliest match at or after
            // that position. Calling it once is sufficient. Wrapping
            // it in an outer for-loop that re-tries each successive
            // start offset would re-walk the whole input on every
            // iteration (quadratic on long no-match inputs).
            $match = $matcher->match($str, $startCu);
        }
        if ($match === null) {
            return null;
        }
        $matchCharPos = $match['index'];
        $matchCharEnd = $match['end'];
        $matchStr = $match['captures'][0][2] ?? '';
        if ($isGlobal || $isSticky) {
            $this_->set('lastIndex', JsNumber::of((float) $matchCharEnd), true);
        }
        $elements = [];
        foreach ($match['captures'] as $i => $cap) {
            if ($cap === null) {
                $elements[] = JsUndefined::instance();
            } else {
                $elements[] = new JsString($cap[2]);
            }
        }
        $result = JsArray::fromArray($elements);
        $result->defineOwnProperty(
            'index',
            PropertyDescriptor::data(JsNumber::of((float) $matchCharPos), true, true, true),
        );
        $result->defineOwnProperty(
            'input',
            PropertyDescriptor::data(new JsString($str), true, true, true),
        );

        // groups object — pre-populate every named group with
        // undefined, then overwrite with matched values.
        $groups = JsObject::createNullPrototype();
        $hasGroups = false;
        $namedListDesc = $this_->getOwnPropertyDescriptor('[[NamedGroupNames]]');
        if ($namedListDesc !== null && $namedListDesc->value instanceof JsArray) {
            $hasGroups = true;
            $nlen = $namedListDesc->value->getLength();
            for ($ni = 0; $ni < $nlen; $ni++) {
                $nameVal = $namedListDesc->value->get((string) $ni);
                if ($nameVal instanceof JsString) {
                    $groups->defineOwnProperty($nameVal->value, PropertyDescriptor::data(
                        JsUndefined::instance(),
                    ));
                }
            }
        }
        foreach ($ast->indexToName as $idx => $name) {
            $cap = $match['captures'][$idx] ?? null;
            if ($cap === null) {
                continue;
            }
            $hasGroups = true;
            $groups->defineOwnProperty($name, PropertyDescriptor::data(
                new JsString($cap[2]),
            ));
        }
        $result->defineOwnProperty(
            'groups',
            PropertyDescriptor::data(
                $hasGroups ? $groups : JsUndefined::instance(),
                true,
                true,
                true,
            ),
        );

        // indices array per /d flag.
        if ($hasIndices) {
            $indicesArr = [];
            foreach ($match['captures'] as $i => $cap) {
                if ($cap === null) {
                    $indicesArr[] = JsUndefined::instance();
                } else {
                    $indicesArr[] = JsArray::fromArray([
                        JsNumber::of((float) $cap[0]),
                        JsNumber::of((float) $cap[1]),
                    ]);
                }
            }
            $iArr = JsArray::fromArray($indicesArr);
            $iGrp = JsObject::createNullPrototype();
            $iHasGrp = false;
            if ($namedListDesc !== null && $namedListDesc->value instanceof JsArray) {
                $iHasGrp = true;
                $nlen = $namedListDesc->value->getLength();
                for ($ni = 0; $ni < $nlen; $ni++) {
                    $nameVal = $namedListDesc->value->get((string) $ni);
                    if ($nameVal instanceof JsString) {
                        $iGrp->defineOwnProperty($nameVal->value, PropertyDescriptor::data(
                            JsUndefined::instance(),
                            true,
                            true,
                            true,
                        ));
                    }
                }
            }
            foreach ($ast->indexToName as $idx => $name) {
                $cap = $match['captures'][$idx] ?? null;
                if ($cap === null) {
                    continue;
                }
                $iHasGrp = true;
                $iGrp->defineOwnProperty($name, PropertyDescriptor::data(
                    JsArray::fromArray([
                        JsNumber::of((float) $cap[0]),
                        JsNumber::of((float) $cap[1]),
                    ]),
                    true,
                    true,
                    true,
                ));
            }
            $iArr->defineOwnProperty('groups', PropertyDescriptor::data(
                $iHasGrp ? $iGrp : JsUndefined::instance(),
                true,
                true,
                true,
            ));
            $result->defineOwnProperty('indices', PropertyDescriptor::data(
                $iArr,
                true,
                true,
                true,
            ));
        }

        return $result;
    }

    /**
     * @param array<mixed> $matches
     * @return array<mixed>
     */
    private static function applyRepeatedGroupFixes(
        JsObject $regexp,
        array $matches,
        string $str,
        string $pcrePattern,
    ): array {
        // Get the original ES pattern from internal slot.
        $srcDesc = $regexp->getOwnPropertyDescriptor('[[OriginalSource]]');
        if ($srcDesc === null || !$srcDesc->value instanceof JsString) {
            return $matches;
        }
        $esPattern = $srcDesc->value->value;

        // Analyze the pattern for repeated groups.
        $analysis = \Phasis\Runtime\Interpreter::analyzeRepeatedGroups($esPattern);
        if (empty($analysis['repeatedGroups']) && empty($analysis['nullableNonCapturingGroups'])) {
            return $matches;
        }

        // Extract PCRE flags from the compiled pattern.
        $lastSlash = strrpos($pcrePattern, '/');
        $pcreFlags = $lastSlash !== false ? substr($pcrePattern, $lastSlash + 1) : 'u';

        // Build transform function using the current interpreter.
        $interp = \Phasis\Engine::getCurrentInterpreter();
        if ($interp === null) {
            return $matches;
        }
        $flagsDesc = $regexp->getOwnPropertyDescriptor("[[OriginalFlags]]");
        $esFlags = ($flagsDesc !== null && $flagsDesc->value instanceof JsString)
            ? $flagsDesc->value->value : "";
        $transformFn = static function (string $esSubPattern) use ($interp, $esFlags): string {
            $transformed = $interp->transformEsPatternForPcre($esSubPattern, $esFlags);
            return $interp->escapeForPcreDelimiter($transformed);
        };

        // Fix 1: Extend match for nullable quantified groups.
        $matches = \Phasis\Runtime\Interpreter::fixNullableQuantifier(
            $matches,
            $analysis,
            $str,
            $pcreFlags,
            $transformFn,
        );

        // Fix 2: Reset captures inside repeated groups to last iteration values.
        $matches = \Phasis\Runtime\Interpreter::fixRepeatedGroupCaptures(
            $matches,
            $analysis,
            $pcreFlags,
            $transformFn,
        );

        // Fix 3: Reset captures inside nullable non-capturing groups.
        $matches = \Phasis\Runtime\Interpreter::fixNullableNonCapturingGroupCaptures(
            $matches,
            $analysis,
        );

        return $matches;
    }

    /**
     * Predicate fast path for `RegExp.prototype.test`. Returns true /
     * false when the receiver still has the intrinsic exec and a
     * [[CustomRegexAst]] internal slot, so we can decide the boolean
     * without materialising the JsObject result (capture slicing,
     * indices array, named groups). Returns null to signal the caller
     * should fall back to the spec-shaped slow path — anything with
     * a custom exec override, sticky/global lastIndex side effects,
     * or only-PCRE2 routing where Annex B legacy recording is still
     * required.
     *
     * For a 1.1M-codepoint input matched by `/^\D+$/u`, the slow path
     * would call internalIndexToUtf16 twice (start + end) and slice
     * the entire input — three O(N) walks that don't influence the
     * boolean. This path skips them.
     */
    private static function regExpTestFast(JsObject $rx, string $S): ?bool
    {
        if (!self::isIntrinsicExec($rx)) {
            return null;
        }
        $pcreDesc = $rx->getOwnPropertyDescriptor('[[PCREPattern]]');
        if ($pcreDesc === null || !$pcreDesc->value instanceof JsString) {
            return null;
        }
        $origFlagsDesc = $rx->getOwnPropertyDescriptor('[[OriginalFlags]]');
        $origFlags = ($origFlagsDesc !== null && $origFlagsDesc->value instanceof JsString)
            ? $origFlagsDesc->value->value
            : '';
        // Sticky / global write to lastIndex on every call (and on no
        // match, reset to 0). Annex B legacy state recording on a
        // successful match is also observable. Both are intentional
        // side effects of the spec's RegExpExec abstract op; defer to
        // the slow path so those observable effects stay correct.
        if (
            str_contains($origFlags, 'g')
            || str_contains($origFlags, 'y')
        ) {
            return null;
        }
        // Custom matcher path: this is the only branch in the slow
        // path that already skips Annex B legacy state recording, so
        // bypassing the JsObject build here matches existing
        // observable behaviour exactly. Short-circuiting the PCRE2
        // branch would also have to update the legacy slots after a
        // successful match, so that case stays on the slow path.
        $customAstDesc = $rx->getOwnPropertyDescriptor('[[CustomRegexAst]]');
        $customFlagsDesc = $rx->getOwnPropertyDescriptor('[[CustomRegexFlags]]');
        if (
            $customAstDesc !== null
            && $customAstDesc->value instanceof \Phasis\Value\JsHostValue
            && $customFlagsDesc !== null
            && $customFlagsDesc->value instanceof JsString
        ) {
            try {
                $matcher = new \Phasis\Regex\Matcher(
                    $customAstDesc->value->value,
                    $customFlagsDesc->value->value,
                );
                return $matcher->matchTest($S, 0);
            } catch (\Phasis\Regex\MatcherBudgetExceeded) {
                // Pattern triggered catastrophic backtracking in the
                // tree-walker; let the slow path try PCRE2 instead.
                return null;
            }
        }
        // PCRE2-first-attempt fast path: try preg_match. If PCRE2
        // accepts the input, no Annex B legacy state recording was
        // observable yet on this receiver (see slow path), so a
        // successful preg_match is the same predicate-only outcome
        // — but recording legacy state is a real spec side effect
        // even for `.test()`. Skip the bool result on PCRE2 success
        // and let the slow path record. On PCRE2 failure with a bad
        // UTF-8 input we compile the AST once (caching it on the
        // receiver), then use matchTest. This is the case that
        // dominates the test262 CharacterClassEscapes corpus where
        // every input is a 1.1M-codepoint string with lone
        // surrogates that PCRE2 refuses under /u.
        $pcrePattern = $pcreDesc->value->value;
        $pregResult = @preg_match($pcrePattern, $S);
        if ($pregResult === 1) {
            // Defer to slow path so Annex B legacy slots get the
            // capture data. Skipping that would silently drop
            // RegExp.lastMatch updates the slow path makes.
            return null;
        }
        if ($pregResult === false && preg_last_error() === PREG_BAD_UTF8_ERROR) {
            // Compile the AST once and cache it so subsequent test()
            // calls on the same receiver hit the cached-AST branch
            // above without re-parsing. Mirrors the cache the slow
            // path installs after its first PCRE2 failure.
            $sourceVal = $rx->get('source');
            $flagsVal = $rx->get('flags');
            $patternStr = $sourceVal instanceof JsString ? $sourceVal->value : '';
            $patFlags = $flagsVal instanceof JsString ? $flagsVal->value : '';
            try {
                $regexAst = (new \Phasis\Regex\Parser($patternStr, $patFlags))->parse();
                $rx->defineOwnProperty(
                    '[[CustomRegexAst]]',
                    PropertyDescriptor::data(
                        new \Phasis\Value\JsHostValue($regexAst),
                        false,
                        false,
                        false,
                    ),
                );
                $rx->defineOwnProperty(
                    '[[CustomRegexFlags]]',
                    PropertyDescriptor::data(
                        new JsString($patFlags),
                        false,
                        false,
                        false,
                    ),
                );
                $matcher = new \Phasis\Regex\Matcher($regexAst, $patFlags);
                return $matcher->matchTest($S, 0);
            } catch (\Phasis\Regex\MatcherBudgetExceeded) {
                return null;
            } catch (\Throwable) {
                return null;
            }
        }
        // 0 (no match) or any other outcome: slow path decides.
        return null;
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
            throw new \Phasis\Exceptions\TypeError(
                'RegExp exec must return an Object or null'
            );
        }
        // Per spec 22.2.5.2.1 step 6: throw TypeError if R lacks [[RegExpMatcher]].
        $pcreDesc = $rx->getOwnPropertyDescriptor('[[PCREPattern]]');
        if ($pcreDesc === null) {
            throw new \Phasis\Exceptions\TypeError(
                'Method RegExp.prototype.exec called on incompatible receiver'
            );
        }
        // Step 7: Return RegExpBuiltinExec(R, S).
        $builtinExec = self::execMethod();
        $result = $builtinExec($rx, [new JsString($S)]);
        if ($result instanceof JsNull || $result instanceof JsObject) {
            return $result;
        }
        return JsNull::instance();
    }
}
