<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Object\PropertyDescriptor;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * Implements RegExp.prototype[Symbol.search], [Symbol.match], [Symbol.replace],
 * [Symbol.matchAll], and [Symbol.split] per the ECMAScript specification.
 *
 * These are installed on RegExp.prototype so that String.prototype.search/match/
 * replace/matchAll/split can delegate to them via the Symbol method lookup.
 */
class RegExpPrototype
{
    use RegExp_\RegExpExec;
    use RegExp_\RegExpSymbolHooks;
    use RegExp_\RegExpHelpers;

    private static ?JsObject $regExpStringIteratorProto = null;

    /**
     * The intrinsic RegExp.prototype.exec captured at install time. Used so
     * the optimised match/replace/split paths can verify the receiver still
     * has the spec-default exec before bypassing it. If something replaced
     * RegExp.prototype.exec or set an own `exec` on the receiver, fall back
     * to the slow path that calls exec via the prototype lookup.
     */
    private static ?JsValue $intrinsicExec = null;

    /**
     * Annex B legacy state (input, lastMatch, etc.) shared between the
     * RegExp constructor's static accessors and exec(). Engine.php
     * installs the accessors that read these slots; a successful match
     * here writes them back via recordLegacyMatch.
     */
    public static string $legacyInput = '';
    public static string $legacyLastMatch = '';
    public static string $legacyLeftContext = '';
    public static string $legacyRightContext = '';
    public static string $legacyLastParen = '';
    /** @var list<string> */
    public static array $legacyGroups = [];







    public static function install(JsObject $proto): void
    {
        $addMethod = static function (string $name, \Closure $fn, int $length) use ($proto): void {
            $func = JsFunction::fromCallable($name, $fn, $length);
            $proto->defineOwnProperty($name, PropertyDescriptor::data($func, true, false, true));
        };

        $addSymbol = static function (
            \Phasis\Value\JsSymbol $sym,
            string $name,
            \Closure $fn,
            int $length,
        ) use ($proto): void {
            $func = JsFunction::fromCallable($name, $fn, $length);
            $proto->definePropertyBySymbol($sym, PropertyDescriptor::data($func, true, false, true));
        };

        // Per spec §22.2.6.2: RegExp.prototype.exec reads [[PCREPattern]] from this.
        $addMethod('exec', self::execMethod(), 1);
        // Snapshot the intrinsic exec so the optimised match/split/replace
        // paths can detect overrides and route through the slow path.
        $execDesc = $proto->getOwnPropertyDescriptor('exec');
        if ($execDesc !== null) {
            self::$intrinsicExec = $execDesc->value;
        }

        // Per spec 22.2.6.14: RegExp.prototype.test calls RegExpExec.
        $addMethod('test', static function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new \Phasis\Exceptions\TypeError(
                    'Method RegExp.prototype.test called on incompatible receiver'
                );
            }
            $str = TypeConversion::toString($args[0] ?? JsUndefined::instance());
            // Fast path: when exec is unmodified, run the underlying
            // matcher with a bool return so we can skip building the
            // result array (capture slicing, internalIndexToUtf16
            // conversion of every group) entirely. Falls through to
            // the spec-shaped slow path for anything custom.
            $bool = self::regExpTestFast($this_, $str);
            if ($bool !== null) {
                return new JsBoolean($bool);
            }
            $result = self::regExpExec($this_, $str);
            return new JsBoolean(!$result instanceof JsNull);
        }, 1);

        // Per spec §22.2.6.15: RegExp.prototype.toString returns /source/flags.
        $addMethod('toString', static function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new \Phasis\Exceptions\TypeError('RegExp.prototype.toString called on non-object');
            }
            $source = TypeConversion::toString($this_->get('source'));
            $flags = TypeConversion::toString($this_->get('flags'));
            return new JsString("/{$source}/{$flags}");
        }, 0);

        // Per spec, source/global/ignoreCase/multiline/dotAll/unicode/unicodeSets/
        // sticky/hasIndices are accessor properties on RegExp.prototype that read
        // from internal slots on the receiver.

        // Map of flag property names to their flag character in [[OriginalFlags]].
        $flagCharMap = [
            'global' => 'g',
            'ignoreCase' => 'i',
            'multiline' => 'm',
            'dotAll' => 's',
            'unicode' => 'u',
            'unicodeSets' => 'v',
            'sticky' => 'y',
            'hasIndices' => 'd',
        ];

        $flagAccessor = static function (string $propName) use ($proto, $flagCharMap): void {
            $flagChar = $flagCharMap[$propName] ?? null;
            $getter = JsFunction::fromCallable(
                'get ' . $propName,
                static function (JsValue $this_, array $args) use ($proto, $propName, $flagChar): JsValue {
                    if (!$this_ instanceof JsObject) {
                        throw new \Phasis\Exceptions\TypeError("get {$propName} called on non-object");
                    }
                    // Per spec, internal slots like [[OriginalFlags]] /
                    // [[RegExpMatcher]] are not exposed through Proxy
                    // [[GetOwnProperty]] traps. A Proxy wrapping a RegExp
                    // does not itself have the slot — accessing the source/
                    // flags getters with such a Proxy as `this` must throw.
                    if ($this_ instanceof \Phasis\Value\JsProxy) {
                        throw new \Phasis\Exceptions\TypeError(
                            "get {$propName} requires that 'this' be a RegExp object",
                        );
                    }
                    // Per spec §22.2.6: if R does not have [[OriginalFlags]]:
                    //   - If R is %RegExp.prototype% and propName is 'source',
                    //     return "(?:)".
                    //   - Otherwise, if R is %RegExp.prototype% and propName
                    //     is a flag getter, return undefined.
                    //   - Otherwise throw TypeError.
                    $origFlagsDesc = $this_->getOwnPropertyDescriptor('[[OriginalFlags]]');
                    if ($origFlagsDesc === null) {
                        if ($this_ === $proto) {
                            if ($propName === 'source') {
                                return new JsString('(?:)');
                            }
                            return JsUndefined::instance();
                        }
                        throw new \Phasis\Exceptions\TypeError(
                            "get {$propName} requires that 'this' be a RegExp object"
                        );
                    }
                    if ($flagChar !== null) {
                        $origFlags = ($origFlagsDesc->value instanceof JsString)
                            ? $origFlagsDesc->value->value : '';
                        return new JsBoolean(str_contains($origFlags, $flagChar));
                    }
                    // source: per §22.2.6.13 / EscapeRegExpPattern, `/` and
                    // LineTerminator code points in the pattern must be escaped
                    // so the resulting string can round-trip through
                    // `/` + source + `/` + flags.
                    $srcDesc = $this_->getOwnPropertyDescriptor('[[OriginalSource]]');
                    if ($srcDesc && $srcDesc->value instanceof JsString) {
                        $src = $srcDesc->value->value;
                        if ($src === '') {
                            return new JsString('(?:)');
                        }
                        return new JsString(self::escapeRegExpPattern($src));
                    }
                    return new JsString('(?:)');
                },
                0,
            );
            $proto->defineOwnProperty($propName, PropertyDescriptor::accessor(
                get: $getter,
                set: null,
                enumerable: false,
                configurable: true,
            ));
        };

        $flagAccessor('source');

        // RegExp.prototype.flags is a special getter per spec 22.2.6.7.
        // It builds the flags string by reading individual flag properties,
        // not from an internal slot.  This allows it to work with plain objects.
        $flagsGetter = JsFunction::fromCallable(
            'get flags',
            static function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    throw new \Phasis\Exceptions\TypeError('get flags called on non-object');
                }
                $result = '';
                if (TypeConversion::toBoolean($this_->get('hasIndices'))) {
                    $result .= 'd';
                }
                if (TypeConversion::toBoolean($this_->get('global'))) {
                    $result .= 'g';
                }
                if (TypeConversion::toBoolean($this_->get('ignoreCase'))) {
                    $result .= 'i';
                }
                if (TypeConversion::toBoolean($this_->get('multiline'))) {
                    $result .= 'm';
                }
                if (TypeConversion::toBoolean($this_->get('dotAll'))) {
                    $result .= 's';
                }
                if (TypeConversion::toBoolean($this_->get('unicode'))) {
                    $result .= 'u';
                }
                if (TypeConversion::toBoolean($this_->get('unicodeSets'))) {
                    $result .= 'v';
                }
                if (TypeConversion::toBoolean($this_->get('sticky'))) {
                    $result .= 'y';
                }
                return new JsString($result);
            },
            0,
        );
        $proto->defineOwnProperty('flags', PropertyDescriptor::accessor(
            get: $flagsGetter,
            set: null,
            enumerable: false,
            configurable: true,
        ));

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
                throw new \Phasis\Exceptions\TypeError(
                    'Method RegExp.prototype.compile called on incompatible receiver',
                );
            }
            // Check for [[RegExpMatcher]] internal slot (we use [[PCREPattern]]).
            $pcreDesc = $this_->getOwnPropertyDescriptor('[[PCREPattern]]');
            if ($pcreDesc === null) {
                throw new \Phasis\Exceptions\TypeError(
                    'Method RegExp.prototype.compile called on incompatible receiver',
                );
            }

            // Per Annex B B.2.5.1 step 3: legacy features ride on the
            // RegExp instance's [[LegacyFeaturesEnabled]] slot, which we
            // approximate by the receiver's prototype identity. A regex
            // from a different realm reaches a different RegExp.prototype
            // (each Engine installs its own), so compile-from-other-realm
            // surfaces as TypeError per legacy-regexp tests.
            $currentRealm = \Phasis\Engine::getCurrentRealm();
            if ($currentRealm !== null) {
                $env = $currentRealm->getGlobalEnv();
                $thisRegExpProto = null;
                if ($env->has('RegExp')) {
                    $ctor = $env->get('RegExp');
                    if ($ctor instanceof JsObject) {
                        $maybeProto = $ctor->get('prototype');
                        if ($maybeProto instanceof JsObject) {
                            $thisRegExpProto = $maybeProto;
                        }
                    }
                }
                if ($thisRegExpProto !== null && $this_->getPrototype() !== $thisRegExpProto) {
                    throw new \Phasis\Exceptions\TypeError(
                        'Method RegExp.prototype.compile called on incompatible receiver',
                    );
                }
            }

            // Per Annex B step 3: if [[LegacyFeaturesEnabled]] is false, throw TypeError.
            $legacyDesc = $this_->getOwnPropertyDescriptor("[[LegacyFeaturesEnabled]]");
            if (
                $legacyDesc !== null && $legacyDesc->value instanceof \Phasis\Value\JsBoolean
                && !$legacyDesc->value->toBoolean()
            ) {
                throw new \Phasis\Exceptions\TypeError(
                    "Method RegExp.prototype.compile called on incompatible receiver",
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
                    throw new \Phasis\Exceptions\TypeError(
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
            $temp = \Phasis\Engine::createRegExpOrThrow($p, $f);

            // Force-update internal slots. These are non-writable, non-configurable
            // data properties, so defineOwnProperty would silently reject the change.
            // Use defineProperty (direct map set) to bypass validation since these
            // are engine-internal slots, not user-visible properties.
            $internalSlots = [
                '[[OriginalSource]]',
                '[[OriginalFlags]]',
                '[[PCREPattern]]',
                '[[GroupNameMap]]',
                '[[NamedGroupNames]]',
                '[[CustomRegexAst]]',
                '[[CustomRegexFlags]]',
            ];
            foreach ($internalSlots as $slot) {
                $desc = $temp->getOwnPropertyDescriptor($slot);
                if ($desc !== null) {
                    $this_->defineProperty($slot, $desc);
                } else {
                    // Slot absent on the freshly-compiled regex — drop
                    // any stale slot from the previous pattern so
                    // exec() does not route through a leftover AST.
                    $this_->forceDelete($slot);
                }
            }

            // Remove own exec/test/toString so the dynamic prototype versions are
            // used. The per-instance closures from createRegExpObject capture stale
            // state (the old pattern, flags, global/sticky booleans). The prototype
            // methods read everything from the receiver at call time.
            $this_->forceDelete('exec');
            $this_->forceDelete('test');
            $this_->forceDelete('toString');

            // Set lastIndex to 0 per spec. This may throw if lastIndex is non-writable.
            $this_->set('lastIndex', JsNumber::of(0.0), true);

            return $this_;
        }, 2);

        $addSymbol(SymbolConstructor::search(), '[Symbol.search]', self::symbolSearch(), 1);
        $addSymbol(SymbolConstructor::match(), '[Symbol.match]', self::symbolMatch(), 1);
        $addSymbol(SymbolConstructor::replace(), '[Symbol.replace]', self::symbolReplace(), 2);
        $addSymbol(SymbolConstructor::matchAll(), '[Symbol.matchAll]', self::symbolMatchAll(), 1);
        $addSymbol(SymbolConstructor::split(), '[Symbol.split]', self::symbolSplit(), 2);
    }













    /**
     * Advance string index by one Unicode code point (for fullUnicode mode).
     *
     * Per spec 22.2.7.4 AdvanceStringIndex: in unicode mode, when the unit
     * at $index is a high surrogate followed by a low surrogate, the pair
     * counts as one code point, so advance by 2 UTF-16 code units; else 1.
     * $S is the input string in UTF-8 byte form; $index is in UTF-16 code
     * units.
     */
    /**
     * Slice $str by UTF-16 code unit range [$from, $to). Astral
     * codepoints in $str span 2 code units; if a slice boundary lies
     * inside an astral pair we must emit just the lone surrogate
     * (otherwise mid-pair endpoints would silently round to the
     * codepoint boundary and duplicate or drop bytes). The conversion
     * to UTF-16LE + back through utf16LEToUtf8 keeps lone surrogates
     * as CESU-8 3-byte sequences, which is the same encoding the
     * engine uses elsewhere for lone surrogates.
     */
    private static function sliceUtf16Range(string $str, int $from, int $to): string
    {
        $u16 = JsString::utf8ToUtf16LE($str);
        $totalCu = (int) (strlen($u16) / 2);
        if ($from < 0) {
            $from = 0;
        }
        if ($to > $totalCu) {
            $to = $totalCu;
        }
        if ($from >= $to) {
            return '';
        }
        $sliced = substr($u16, $from * 2, ($to - $from) * 2);
        return JsString::utf16LEToUtf8($sliced);
    }

    private static function utf16IndexToByteOffset(string $str, int $cuIndex): int
    {
        if ($cuIndex <= 0) {
            return 0;
        }
        $byteLen = strlen($str);
        $byte = 0;
        $cu = 0;
        while ($cu < $cuIndex && $byte < $byteLen) {
            $b = ord($str[$byte]);
            if ($b < 0x80) {
                $byte += 1;
                $cu += 1;
            } elseif ($b < 0xC0) {
                $byte += 1;
            } elseif ($b < 0xE0) {
                $byte += 2;
                $cu += 1;
            } elseif ($b < 0xF0) {
                $byte += 3;
                $cu += 1;
            } else {
                // 4-byte UTF-8 → surrogate pair (2 UTF-16 code units).
                if ($cu + 1 >= $cuIndex) {
                    // The requested position lands inside a surrogate pair;
                    // step past the whole codepoint to keep the offset on a
                    // valid UTF-8 boundary.
                    $byte += 4;
                    $cu += 2;
                } else {
                    $byte += 4;
                    $cu += 2;
                }
            }
        }
        return $byte;
    }
}
