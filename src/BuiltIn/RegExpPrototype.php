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
     * Clear the cached %RegExpStringIteratorPrototype% so fresh realms get a
     * prototype whose [[Prototype]] points at the realm's own %IteratorPrototype%.
     */
    public static function resetStringIteratorProto(): void
    {
        self::$regExpStringIteratorProto = null;
        self::$intrinsicExec = null;
    }

    /** %RegExpStringIteratorPrototype%: inherits from %IteratorPrototype%. */
    private static function getRegExpStringIteratorProto(): JsObject
    {
        if (self::$regExpStringIteratorProto !== null) {
            return self::$regExpStringIteratorProto;
        }
        $proto = new JsObject();

        // Set [[Prototype]] to %IteratorPrototype%.
        $iterProto = JsFunction::getInterpreterInstance()
            ?->getGlobalEnv()->get('__IteratorPrototype__');
        if ($iterProto instanceof JsObject) {
            $proto->setPrototype($iterProto);
        }

        // next method per spec 22.2.9.1.1.
        $nextFn = JsFunction::fromCallable(
            'next',
            function (JsValue $this_): JsValue {
                if (
                    !$this_ instanceof JsObject
                    || !$this_->hasOwnProperty('[[IteratingRegExp]]')
                ) {
                    throw new \Phasis\Exceptions\TypeError(
                        'next called on non-RegExp string iterator'
                    );
                }
                $doneVal = $this_->get('[[Done]]');
                if ($doneVal instanceof JsBoolean && $doneVal->value) {
                    return self::iterResult(JsUndefined::instance(), true);
                }
                $R = $this_->get('[[IteratingRegExp]]');
                if (!$R instanceof JsObject) {
                    return self::iterResult(JsUndefined::instance(), true);
                }
                $S = TypeConversion::toString($this_->get('[[IteratedString]]'));
                $global = $this_->get('[[Global]]');
                $isGlobal = $global instanceof JsBoolean && $global->value;
                $fullUnicode = $this_->get('[[Unicode]]');
                $isUnicode = $fullUnicode instanceof JsBoolean
                    && $fullUnicode->value;
                $match = self::regExpExec($R, $S);
                if ($match instanceof JsNull) {
                    $this_->set('[[Done]]', new JsBoolean(true));
                    return self::iterResult(JsUndefined::instance(), true);
                }
                if ($isGlobal) {
                    $matchStr = TypeConversion::toString($match->get('0'));
                    if ($matchStr === '') {
                        $li = (int) TypeConversion::toNumber($R->get('lastIndex'));
                        $next = $isUnicode
                            ? self::advanceStringIndex($S, $li) : $li + 1;
                        $R->set('lastIndex', JsNumber::of((float) $next));
                    }
                } else {
                    $this_->set('[[Done]]', new JsBoolean(true));
                }
                return self::iterResult($match, false);
            },
            0,
        );
        $proto->defineOwnProperty(
            'next',
            PropertyDescriptor::data($nextFn, true, false, true),
        );

        // Symbol.toStringTag
        $tagSym = SymbolConstructor::toStringTag();
        $proto->definePropertyBySymbol(
            $tagSym,
            PropertyDescriptor::data(
                new JsString('RegExp String Iterator'),
                false,
                false,
                true,
            ),
        );

        self::$regExpStringIteratorProto = $proto;
        return $proto;
    }

    /**
     * §22.2.6.13 EscapeRegExpPattern: escape `/` and LineTerminator code
     * points in a regexp source so `/` + src + `/` forms a valid literal.
     * Escaping inside character classes differs per spec (char classes may
     * contain `/` unescaped), but V8 and SpiderMonkey escape them anyway
     * and test262 checks the round-trip not the specific form.
     */
    private static function escapeRegExpPattern(string $source): string
    {
        $out = '';
        $len = strlen($source);
        $inClass = false;
        for ($i = 0; $i < $len; $i++) {
            $ch = $source[$i];
            if ($ch === '\\' && $i + 1 < $len) {
                // A backslash followed by a line terminator in the pattern
                // source must stringify as `\` + the LineTerminator's
                // escape letter (e.g. \<LF> -> \n) per EscapeRegExpPattern;
                // the existing `\` plays the role of the escape backslash.
                $next = $source[$i + 1];
                if ($next === "\n") {
                    $out .= '\\n';
                    $i++;
                    continue;
                }
                if ($next === "\r") {
                    $out .= '\\r';
                    $i++;
                    continue;
                }
                if (
                    $next === "\xE2"
                    && $i + 3 < $len
                    && $source[$i + 2] === "\x80"
                    && ($source[$i + 3] === "\xA8" || $source[$i + 3] === "\xA9")
                ) {
                    $out .= $source[$i + 3] === "\xA8" ? '\\u2028' : '\\u2029';
                    $i += 3;
                    continue;
                }
                $out .= $ch . $next;
                $i++;
                continue;
            }
            if ($ch === '[') {
                $inClass = true;
                $out .= $ch;
                continue;
            }
            if ($ch === ']') {
                $inClass = false;
                $out .= $ch;
                continue;
            }
            if ($ch === '/' && !$inClass) {
                $out .= '\\/';
                continue;
            }
            // Line terminators LF / CR in pattern must be escaped.
            if ($ch === "\n") {
                $out .= '\\n';
                continue;
            }
            if ($ch === "\r") {
                $out .= '\\r';
                continue;
            }
            // U+2028 / U+2029 as UTF-8 sequences.
            if (
                $ch === "\xE2"
                && $i + 2 < $len
                && $source[$i + 1] === "\x80"
                && ($source[$i + 2] === "\xA8" || $source[$i + 2] === "\xA9")
            ) {
                $out .= $source[$i + 2] === "\xA8" ? '\\u2028' : '\\u2029';
                $i += 2;
                continue;
            }
            $out .= $ch;
        }
        return $out;
    }

    private static function iterResult(JsValue $value, bool $done): JsObject
    {
        $obj = new JsObject();
        $obj->set('value', $value);
        $obj->set('done', new JsBoolean($done));
        return $obj;
    }

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

    private static function advanceStringIndex(string $S, int $index): int
    {
        $u16 = JsString::utf8ToUtf16LE($S);
        $u16Len = (int) (strlen($u16) / 2);
        if ($index + 1 >= $u16Len) {
            return $index + 1;
        }
        $offset = $index * 2;
        $high = ord($u16[$offset]) | (ord($u16[$offset + 1]) << 8);
        if ($high < 0xD800 || $high > 0xDBFF) {
            return $index + 1;
        }
        $low = ord($u16[$offset + 2]) | (ord($u16[$offset + 3]) << 8);
        if ($low < 0xDC00 || $low > 0xDFFF) {
            return $index + 1;
        }
        return $index + 2;
    }
}
