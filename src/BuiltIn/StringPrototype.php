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
    /** %StringIteratorPrototype%: shared prototype for all string iterators. */
    private static ?JsObject $stringIteratorPrototype = null;

    /** Reset the shared string iterator prototype (for engine reset). */
    public static function resetStringIteratorPrototype(): void
    {
        self::$stringIteratorPrototype = null;
    }

    /**
     * Get or create the %StringIteratorPrototype% intrinsic.
     */
    public static function getStringIteratorPrototype(?JsObject $iteratorPrototype = null): JsObject
    {
        if (self::$stringIteratorPrototype !== null) {
            return self::$stringIteratorPrototype;
        }

        $proto = new JsObject($iteratorPrototype);

        $nextFn = JsFunction::fromCallable('next', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new \PhpJs\Exceptions\TypeError(
                    'Method String Iterator.prototype.next called on incompatible receiver',
                );
            }
            $slotDesc = $this_->getOwnPropertyDescriptor('[[StringIteratorData]]');
            if ($slotDesc === null) {
                throw new \PhpJs\Exceptions\TypeError(
                    'Method String Iterator.prototype.next called on incompatible receiver',
                );
            }
            $data = $slotDesc->value;
            if (!$data instanceof JsObject) {
                $result = new JsObject();
                $result->set('value', JsUndefined::instance());
                $result->set('done', new JsBoolean(true));
                return $result;
            }
            $charsVal = $data->get('chars');
            $indexVal = $data->get('index');
            $totalVal = $data->get('total');
            $index = ($indexVal instanceof JsNumber) ? (int) $indexVal->value : 0;
            $total = ($totalVal instanceof JsNumber) ? (int) $totalVal->value : 0;

            $result = new JsObject();
            if ($index < $total) {
                $char = $charsVal instanceof JsObject ? $charsVal->get((string) $index) : JsUndefined::instance();
                $data->set('index', JsNumber::of((float) ($index + 1)));
                $result->set('value', $char);
                $result->set('done', new JsBoolean(false));
            } else {
                $this_->defineOwnProperty(
                    '[[StringIteratorData]]',
                    PropertyDescriptor::data(JsUndefined::instance(), false, false, false),
                );
                $result->set('value', JsUndefined::instance());
                $result->set('done', new JsBoolean(true));
            }
            return $result;
        }, 0);
        $nextFn->setNonConstructable();
        $proto->defineOwnProperty('next', PropertyDescriptor::data($nextFn, true, false, true));

        // Symbol.toStringTag = "String Iterator".
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('String Iterator'), false, false, true),
        );

        self::$stringIteratorPrototype = $proto;
        return $proto;
    }

    /**
     * Create a string iterator object with proper prototype chain.
     *
     * Per spec 22.1.5.2.1: iteration yields code points (combining surrogate
     * pairs), but lone surrogates are yielded individually.
     */
    public static function createStringIterator(JsString $str): JsObject
    {
        $proto = self::$stringIteratorPrototype;
        $iterator = new JsObject($proto);

        // Build character list using UTF-16 code units, combining valid
        // surrogate pairs into single code points.
        $chars = [];
        $u16 = JsString::utf8ToUtf16LE($str->value);
        $u16Len = (int) (strlen($u16) / 2);
        $i = 0;
        while ($i < $u16Len) {
            $cu = ord($u16[$i * 2]) | (ord($u16[$i * 2 + 1]) << 8);
            if ($cu >= 0xD800 && $cu <= 0xDBFF && $i + 1 < $u16Len) {
                $next = ord($u16[($i + 1) * 2]) | (ord($u16[($i + 1) * 2 + 1]) << 8);
                if ($next >= 0xDC00 && $next <= 0xDFFF) {
                    // Valid surrogate pair: combine into a single code point.
                    $cp = ($cu - 0xD800) * 0x400 + ($next - 0xDC00) + 0x10000;
                    $chars[] = new JsString(mb_chr($cp, 'UTF-8'));
                    $i += 2;
                    continue;
                }
            }
            // Lone surrogate or BMP character.
            $chars[] = new JsString(JsString::utf16CodeUnitToUtf8($cu));
            $i++;
        }
        $charsArr = JsArray::fromArray($chars);

        $data = new JsObject();
        $data->set('chars', $charsArr);
        $data->set('index', JsNumber::of(0.0));
        $data->set('total', JsNumber::of((float) count($chars)));
        $iterator->defineOwnProperty(
            '[[StringIteratorData]]',
            PropertyDescriptor::data($data, false, false, false),
        );

        return $iterator;
    }

    public static function install(Environment $env): void
    {
        self::resetStringIteratorPrototype();
        $iteratorPrototype = $env->has('__IteratorPrototype__')
            ? $env->get('__IteratorPrototype__')
            : null;
        self::getStringIteratorPrototype(
            $iteratorPrototype instanceof JsObject ? $iteratorPrototype : null,
        );

        $proto = new JsObject();

        // Per spec, String.prototype is itself a String object with [[PrimitiveValue]] = "".
        $proto->defineOwnProperty(
            '[[PrimitiveValue]]',
            PropertyDescriptor::data(new JsString(''), false, false, false),
        );

        $d = static fn (string $n, \Closure $fn, int $len) => $proto->defineOwnProperty(
            $n,
            PropertyDescriptor::data(JsFunction::fromCallable($n, $fn, $len), true, false, true),
        );

        // Tagged install for hot prototype methods that have a VM
        // CALL_METHOD inline path. The fn object is reused (same
        // descriptor), the only difference is the builtinKind marker.
        $tagged = static function (string $n, \Closure $fn, int $len, string $kind) use ($proto): void {
            $jf = JsFunction::fromCallable($n, $fn, $len);
            $jf->builtinKind = $kind;
            $proto->defineOwnProperty(
                $n,
                PropertyDescriptor::data($jf, true, false, true),
            );
        };

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
        $d('toLocaleLowerCase', self::toLocaleLowerCase(), 0);
        $d('toLocaleUpperCase', self::toLocaleUpperCase(), 0);
        $d('trim', self::trim(), 0);
        $d('trimStart', self::trimStart(), 0);
        $d('trimEnd', self::trimEnd(), 0);
        $tagged('split', self::split(), 2, 'string.split');
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
        $d('isWellFormed', self::isWellFormed(), 0);
        $d('toWellFormed', self::toWellFormed(), 0);

        // String.prototype.length is 0 per spec (it is the empty string object).
        $proto->defineOwnProperty('length', PropertyDescriptor::data(JsNumber::of(0), false, false, false));

        // AnnexB methods: B.2.3.1 String.prototype.substr(start, length)
        // Operates on UTF-16 code units per spec.
        $d('substr', function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $size = self::utf16Length($str);

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

            // Step 9: resultLength = min(max(end, 0), size - intStart)
            $resultLength = (int) min(max($rawLength, 0), $size - $intStart);

            if ($resultLength <= 0) {
                return new JsString('');
            }

            // Extract using UTF-16 code unit indices.
            $u16 = JsString::utf8ToUtf16LE($str);
            $sliced = substr($u16, $intStart * 2, $resultLength * 2);
            return new JsString(JsString::utf16LEToUtf8($sliced));
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
            $existing->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
            $proto->defineOwnProperty('constructor', PropertyDescriptor::data($existing, true, false, true));

            // Static methods on String constructor — non-enumerable per spec.
            $fromCharCodeFn = JsFunction::fromCallable('fromCharCode', self::fromCharCode(), 1);
            $existing->defineOwnProperty(
                'fromCharCode',
                \PhpJs\Object\PropertyDescriptor::data($fromCharCodeFn, true, false, true),
            );
            $fromCodePointFn = JsFunction::fromCallable('fromCodePoint', self::fromCodePoint(), 1);
            $existing->defineOwnProperty(
                'fromCodePoint',
                \PhpJs\Object\PropertyDescriptor::data($fromCodePointFn, true, false, true),
            );
            $existing->defineOwnProperty('raw', \PhpJs\Object\PropertyDescriptor::data(
                JsFunction::fromCallable('raw', self::rawFn(), 1),
                true,
                false,
                true,
            ));
        }

        // String.prototype[Symbol.iterator] per spec 22.1.3.34.
        $iterSym = SymbolConstructor::iterator();
        $iterFn = JsFunction::fromCallable('[Symbol.iterator]', function (JsValue $this_): JsValue {
            // RequireObjectCoercible(this value), then ToString.
            if ($this_ instanceof JsNull || $this_ instanceof JsUndefined) {
                throw new \PhpJs\Exceptions\TypeError(
                    'String.prototype[Symbol.iterator] called on null or undefined',
                );
            }
            $str = $this_ instanceof JsString ? $this_ : new JsString(TypeConversion::toString($this_));
            return self::createStringIterator($str);
        }, 0);
        $proto->definePropertyBySymbol(
            $iterSym,
            PropertyDescriptor::data($iterFn, true, false, true),
        );

        // Register the prototype so TypeConversion::toObject can link String wrapper objects.
        \PhpJs\Value\JsString::resetStringPrototype();
        \PhpJs\Value\JsString::setStringPrototype($proto);

        // Store the prototype so the interpreter can access it for auto-boxing.
        $env->defineVar('__StringPrototype__', $proto);
    }

    private static function rawFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            // String.raw`template` receives a template object as first arg, then substitutions.
            $template = $args[0] ?? JsUndefined::instance();
            if (!$template instanceof JsObject) {
                throw new \PhpJs\Exceptions\TypeError('String.raw: template argument must be an object');
            }
            $rawVal = $template->get('raw');
            if (!$rawVal instanceof JsObject) {
                throw new \PhpJs\Exceptions\TypeError('String.raw: template.raw must be an object');
            }
            $rawLen = ($rawVal instanceof JsArray)
                ? $rawVal->getLength()
                : (int) TypeConversion::toNumber($rawVal->get('length'));
            if ($rawLen === 0) {
                return new JsString('');
            }
            $result = '';
            for ($i = 0; $i < $rawLen; $i++) {
                $result .= TypeConversion::toString($rawVal->get((string) $i));
                // Substitutions are appended only for indices 0..rawLen-2 (spec §21.1.2.4 step 12.f).
                // If fewer substitutions are provided than needed, use empty string (spec step 12.g).
                if ($i < $rawLen - 1) {
                    $sub = $args[$i + 1] ?? new JsString('');
                    $result .= TypeConversion::toString($sub);
                }
            }
            return new JsString($result);
        };
    }

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
                !($prim instanceof JsUndefined)
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
            // Per spec charAt uses UTF-16 code unit indices.
            $u16 = JsString::utf8ToUtf16LE($str);
            $len = (int) (strlen($u16) / 2);
            if ($index < 0 || $index >= $len) {
                return new JsString('');
            }
            $codeUnit = ord($u16[$index * 2]) | (ord($u16[$index * 2 + 1]) << 8);
            return new JsString(JsString::utf16CodeUnitToUtf8($codeUnit));
        };
    }

    private static function charCodeAt(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $index = isset($args[0]) ? (int) TypeConversion::toNumber($args[0]) : 0;
            // Index by UTF-16 code units, not Unicode codepoints.
            $u16 = JsString::utf8ToUtf16LE($str);
            $len = (int) (strlen($u16) / 2);
            if ($index < 0 || $index >= $len) {
                return JsNumber::of(NAN);
            }
            $cu = ord($u16[$index * 2]) | (ord($u16[$index * 2 + 1]) << 8);
            return JsNumber::of((float) $cu);
        };
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
                throw new \PhpJs\Exceptions\TypeError(
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
                throw new \PhpJs\Exceptions\TypeError(
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
                throw new \PhpJs\Exceptions\TypeError(
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

    private static function slice(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            // Per spec, String.prototype.slice indices are UTF-16 code unit
            // offsets, not code point offsets, so work on the UTF-16LE form.
            $u16 = JsString::utf8ToUtf16LE($str);
            $len = (int) (strlen($u16) / 2);

            // Per spec 22.1.3.22: intStart = ToIntegerOrInfinity(start).
            $startArg = $args[0] ?? JsUndefined::instance();
            $startFloat = TypeConversion::toIntegerOrInfinity($startArg);
            if ($startFloat === -INF) {
                $from = 0;
            } elseif ($startFloat < 0) {
                $from = (int) max($len + $startFloat, 0);
            } else {
                $from = (int) min($startFloat, $len);
            }

            // Per spec: if end is undefined, intEnd = len; else intEnd = ToIntegerOrInfinity(end).
            $endArg = $args[1] ?? JsUndefined::instance();
            if ($endArg instanceof JsUndefined) {
                $to = $len;
            } else {
                $endFloat = TypeConversion::toIntegerOrInfinity($endArg);
                if ($endFloat === -INF) {
                    $to = 0;
                } elseif ($endFloat < 0) {
                    $to = (int) max($len + $endFloat, 0);
                } elseif ($endFloat >= $len) {
                    $to = $len;
                } else {
                    $to = (int) $endFloat;
                }
            }

            if ($from >= $to) {
                return new JsString('');
            }

            $sliceBytes = substr($u16, $from * 2, ($to - $from) * 2);
            return new JsString(JsString::utf16LEToUtf8($sliceBytes));
        };
    }

    private static function substring(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            // Per spec, substring operates on UTF-16 code unit offsets, not
            // code point offsets.
            $u16 = JsString::utf8ToUtf16LE($str);
            $len = (int) (strlen($u16) / 2);

            // Per spec: ToIntegerOrInfinity(start). NaN -> 0.
            $startArg = $args[0] ?? JsUndefined::instance();
            $startRaw = TypeConversion::toIntegerOrInfinity($startArg);

            // Per spec: end is undefined -> len, otherwise ToIntegerOrInfinity(end).
            $endArg = $args[1] ?? JsUndefined::instance();
            $endRaw = $endArg instanceof JsUndefined
                ? (float) $len
                : TypeConversion::toIntegerOrInfinity($endArg);

            // Clamp to [0, length]. Use float min to avoid (int) INF issue.
            $startClamped = max(0.0, min($startRaw, (float) $len));
            $endClamped = max(0.0, min($endRaw, (float) $len));
            $start = (int) $startClamped;
            $end = (int) $endClamped;

            // If start > end, swap them.
            if ($start > $end) {
                [$start, $end] = [$end, $start];
            }

            if ($start === $end) {
                return new JsString('');
            }
            $subBytes = substr($u16, $start * 2, ($end - $start) * 2);
            return new JsString(JsString::utf16LEToUtf8($subBytes));
        };
    }

    private static function toLowerCase(): \Closure
    {
        return function (JsValue $this_): JsValue {
            return new JsString(self::caseFoldPreserveSurrogates(self::extractString($this_), false));
        };
    }

    private static function toUpperCase(): \Closure
    {
        return function (JsValue $this_): JsValue {
            return new JsString(self::caseFoldPreserveSurrogates(self::extractString($this_), true));
        };
    }

    /**
     * Apply mb_strtoupper / mb_strtolower while preserving CESU-8 lone-surrogate
     * sequences (3-byte 0xED [0xA0-0xBF] [0x80-0xBF]). mb_* treats these as
     * invalid UTF-8 and substitutes "?" — splitting around them and casing the
     * surrounding valid UTF-8 segments individually keeps lone surrogates
     * round-tripping through toUpperCase / toLowerCase.
     */
    private static function caseFoldPreserveSurrogates(string $str, bool $upper): string
    {
        $len = strlen($str);
        $result = '';
        $segStart = 0;
        $i = 0;
        while ($i < $len) {
            if (
                ord($str[$i]) === 0xED
                && $i + 2 < $len
                && (ord($str[$i + 1]) & 0xE0) === 0xA0
            ) {
                if ($i > $segStart) {
                    $seg = substr($str, $segStart, $i - $segStart);
                    $result .= $upper
                        ? mb_strtoupper($seg, 'UTF-8')
                        : mb_strtolower($seg, 'UTF-8');
                }
                $result .= substr($str, $i, 3);
                $i += 3;
                $segStart = $i;
                continue;
            }
            $i++;
        }
        if ($segStart < $len) {
            $seg = substr($str, $segStart);
            $result .= $upper
                ? mb_strtoupper($seg, 'UTF-8')
                : mb_strtolower($seg, 'UTF-8');
        }
        return $result;
    }

    private static function toLocaleLowerCase(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $locale = self::pickToLocaleCaseLocale($args[0] ?? null);
            $lang = $locale !== null ? strtolower(strtok($locale, '-_')) : null;
            // Lithuanian: insert U+0307 immediately after lowercased I/J/Į
            // when the following combining sequence contains a class 230 mark
            // (with possible class 220 marks between). ICU's lt-Lower
            // transliterator returns the marks in canonical order, so the dot
            // ends up *after* class 220 — the spec demands it right after the
            // base letter. Hand-roll the rule.
            if ($lang === 'lt') {
                $ltResult = self::lithuanianLower($str);
                if ($ltResult !== null) {
                    return new JsString($ltResult);
                }
            }
            $result = self::applyLocaleCaseTransliterator($str, $locale, 'Lower');
            if ($result !== null) {
                return new JsString($result);
            }
            return new JsString(mb_strtolower($str, 'UTF-8'));
        };
    }

    private static function toLocaleUpperCase(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $locale = self::pickToLocaleCaseLocale($args[0] ?? null);
            $lang = $locale !== null ? strtolower(strtok($locale, '-_')) : null;
            if ($lang === 'lt') {
                $ltResult = self::lithuanianUpper($str);
                if ($ltResult !== null) {
                    return new JsString($ltResult);
                }
            }
            $result = self::applyLocaleCaseTransliterator($str, $locale, 'Upper');
            if ($result !== null) {
                return new JsString($result);
            }
            return new JsString(mb_strtoupper($str, 'UTF-8'));
        };
    }

    private static function lithuanianLower(string $str): ?string
    {
        if (!class_exists(\IntlChar::class)) {
            return null;
        }
        $cps = mb_str_split($str, 1, 'UTF-8');
        $n = count($cps);
        $result = '';
        for ($i = 0; $i < $n; $i++) {
            $cp = mb_ord($cps[$i], 'UTF-8');
            if ($cp === 0x0049 || $cp === 0x004A || $cp === 0x012E) {
                $hasClass230 = false;
                for ($j = $i + 1; $j < $n; $j++) {
                    $cpJ = mb_ord($cps[$j], 'UTF-8');
                    $ccc = \IntlChar::getCombiningClass($cpJ);
                    if ($ccc === 0) {
                        break;
                    }
                    if ($ccc === 230) {
                        $hasClass230 = true;
                        break;
                    }
                }
                $lower = match ($cp) {
                    0x0049 => 0x0069,
                    0x004A => 0x006A,
                    0x012E => 0x012F,
                };
                $result .= mb_chr($lower, 'UTF-8');
                if ($hasClass230) {
                    $result .= mb_chr(0x0307, 'UTF-8');
                }
                continue;
            }
            // Precomposed I-with-accent-above: decompose to i + U+0307 + accent.
            $precomposed = match ($cp) {
                0x00CC => [0x0069, 0x0307, 0x0300],
                0x00CD => [0x0069, 0x0307, 0x0301],
                0x0128 => [0x0069, 0x0307, 0x0303],
                default => null,
            };
            if ($precomposed !== null) {
                foreach ($precomposed as $rcp) {
                    $result .= mb_chr($rcp, 'UTF-8');
                }
                continue;
            }
            $result .= mb_strtolower($cps[$i], 'UTF-8');
        }
        return $result;
    }

    private static function lithuanianUpper(string $str): ?string
    {
        if (!class_exists(\IntlChar::class)) {
            return null;
        }
        // SpecialCasing.txt: when uppercasing in lt, U+0307 (combining dot
        // above) is removed if preceded by a Soft_Dotted character with no
        // intervening class 0 or class 230 character. Walk backward when we
        // see U+0307 to apply that condition.
        $cps = mb_str_split($str, 1, 'UTF-8');
        $n = count($cps);
        $result = '';
        for ($i = 0; $i < $n; $i++) {
            $cp = mb_ord($cps[$i], 'UTF-8');
            if ($cp === 0x0307) {
                $afterSoftDotted = false;
                for ($j = $i - 1; $j >= 0; $j--) {
                    $cpJ = mb_ord($cps[$j], 'UTF-8');
                    $ccc = \IntlChar::getCombiningClass($cpJ);
                    if ($ccc === 0) {
                        if (\IntlChar::hasBinaryProperty($cpJ, \IntlChar::PROPERTY_SOFT_DOTTED)) {
                            $afterSoftDotted = true;
                        }
                        break;
                    }
                    if ($ccc === 230) {
                        break;
                    }
                }
                if ($afterSoftDotted) {
                    continue;
                }
            }
            $result .= mb_strtoupper($cps[$i], 'UTF-8');
        }
        return $result;
    }

    /**
     * Resolve the locales argument to a single locale identifier
     * (or null when none is provided / valid). Accepts a string or
     * an array; the first entry wins.
     */
    private static function pickToLocaleCaseLocale(mixed $arg): ?string
    {
        if ($arg === null || $arg instanceof \PhpJs\Value\JsUndefined) {
            return null;
        }
        if ($arg instanceof JsString) {
            return $arg->value;
        }
        if ($arg instanceof \PhpJs\Value\JsObject) {
            // Array of locales: pick the first.
            $len = $arg->get('length');
            if ($len instanceof \PhpJs\Value\JsNumber && $len->value > 0) {
                $first = $arg->get('0');
                if ($first instanceof JsString) {
                    return $first->value;
                }
            }
        }
        return null;
    }

    /**
     * Apply ICU's locale-aware case transliteration. PHP exposes
     * Transliterator IDs of the form `<locale>-Upper` /
     * `<locale>-Lower` for the locales whose CLDR SpecialCasing
     * rules require it (tr, az, lt). Returns null when no
     * locale-specific transliterator is needed (caller falls back
     * to the locale-independent mb_strto{lower,upper}).
     */
    private static function applyLocaleCaseTransliterator(
        string $str,
        ?string $locale,
        string $direction,
    ): ?string {
        if ($locale === null || $locale === '' || !class_exists(\Transliterator::class)) {
            return null;
        }
        $lang = strtolower(strtok($locale, '-_'));
        // Only languages where SpecialCasing differs from the default.
        $useLocale = match ($lang) {
            'tr', 'az', 'lt' => $lang,
            default => null,
        };
        if ($useLocale === null) {
            return null;
        }
        $id = $useLocale . '-' . $direction;
        $tx = \Transliterator::create($id);
        if ($tx === null) {
            return null;
        }
        $result = $tx->transliterate($str);
        return is_string($result) ? $result : null;
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

            // Per spec §21.1.3.20 step 2-3: GetMethod(separator, @@split).
            // null/undefined → use default; non-callable → TypeError;
            // callable → invoke.
            if ($separator instanceof JsObject) {
                $splitSym = SymbolConstructor::split();
                $splitter = $separator->getBySymbol($splitSym);
                if ($splitter instanceof JsFunction) {
                    return $splitter->call($separator, [$this_, $limitArg]);
                }
                if ($splitter instanceof \PhpJs\Value\JsHTMLDDA) {
                    // HTMLDDA's [[Call]] returns null.
                    return JsNull::instance();
                }
                if (
                    $splitter instanceof \PhpJs\Value\JsProxy
                    && $splitter->isCallable()
                ) {
                    return $splitter->apply($separator, [$this_, $limitArg]);
                }
                if (
                    !$splitter instanceof JsUndefined
                    && !$splitter instanceof JsNull
                ) {
                    throw new \PhpJs\Exceptions\TypeError(
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
                throw new \PhpJs\Exceptions\TypeError(
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
                        if ($replacer instanceof \PhpJs\Value\JsHTMLDDA) {
                            return JsNull::instance();
                        }
                        throw new \PhpJs\Exceptions\TypeError('Symbol.replace is not a function');
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

    /**
     * Count UTF-16 code units in a UTF-8/CESU-8 string.
     */
    private static function utf16Length(string $str): int
    {
        return (new JsString($str))->length();
    }

    /**
     * Truncate a string to a given number of UTF-16 code units.
     * Surrogate pairs that remain complete are combined into proper UTF-8.
     */
    private static function utf16Truncate(string $str, int $maxCodeUnits): string
    {
        $u16 = JsString::utf8ToUtf16LE($str);
        $u16Len = (int) (strlen($u16) / 2);
        if ($u16Len <= $maxCodeUnits) {
            return $str;
        }
        $result = '';
        $i = 0;
        while ($i < $maxCodeUnits) {
            $cu = ord($u16[$i * 2]) | (ord($u16[$i * 2 + 1]) << 8);
            // Check for complete surrogate pair within bounds.
            if (
                $cu >= 0xD800 && $cu <= 0xDBFF
                && $i + 1 < $maxCodeUnits
            ) {
                $lo = ord($u16[($i + 1) * 2]) | (ord($u16[($i + 1) * 2 + 1]) << 8);
                if ($lo >= 0xDC00 && $lo <= 0xDFFF) {
                    $cp = 0x10000 + ($cu - 0xD800) * 0x400 + ($lo - 0xDC00);
                    $result .= mb_chr($cp, 'UTF-8');
                    $i += 2;
                    continue;
                }
            }
            $result .= JsString::utf16CodeUnitToUtf8($cu);
            $i++;
        }
        return $result;
    }

    private static function padStart(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $targetLength = isset($args[0]) ? (int) TypeConversion::toNumber($args[0]) : 0;
            $fillArg = $args[1] ?? JsUndefined::instance();
            $padStr = $fillArg instanceof JsUndefined ? ' ' : TypeConversion::toString($fillArg);

            $currentLen = self::utf16Length($str);
            if ($currentLen >= $targetLength || $padStr === '') {
                return new JsString($str);
            }

            $needed = $targetLength - $currentLen;
            $padLen = self::utf16Length($padStr);
            $reps = (int) ceil($needed / max($padLen, 1));
            $pad = str_repeat($padStr, $reps);
            $pad = self::utf16Truncate($pad, $needed);

            return new JsString($pad . $str);
        };
    }

    private static function padEnd(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $targetLength = isset($args[0]) ? (int) TypeConversion::toNumber($args[0]) : 0;
            $fillArg = $args[1] ?? JsUndefined::instance();
            $padStr = $fillArg instanceof JsUndefined ? ' ' : TypeConversion::toString($fillArg);

            $currentLen = self::utf16Length($str);
            if ($currentLen >= $targetLength || $padStr === '') {
                return new JsString($str);
            }

            $needed = $targetLength - $currentLen;
            $padLen = self::utf16Length($padStr);
            $reps = (int) ceil($needed / max($padLen, 1));
            $pad = str_repeat($padStr, $reps);
            $pad = self::utf16Truncate($pad, $needed);

            return new JsString($str . $pad);
        };
    }

    private static function concat(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            foreach ($args as $arg) {
                $str = JsString::concatNormalize($str, TypeConversion::toString($arg));
            }
            return new JsString($str);
        };
    }

    private static function toStringFn(): \Closure
    {
        return function (JsValue $this_): JsValue {
            // Per spec: thisStringValue(this value) — throws TypeError if not a
            // String primitive or a String wrapper object.
            if ($this_ instanceof JsString) {
                return $this_;
            }
            if ($this_ instanceof JsObject) {
                $prim = $this_->get('[[PrimitiveValue]]');
                if ($prim instanceof JsString) {
                    return $prim;
                }
            }
            throw new \PhpJs\Exceptions\TypeError(
                'String.prototype.valueOf requires that \'this\' be a String',
            );
        };
    }

    private static function at(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            // Spec requires ToIntegerOrInfinity so infinite indices fail
            // the bounds check instead of coercing to 0.
            $relative = isset($args[0])
                ? TypeConversion::toIntegerOrInfinity($args[0])
                : 0.0;
            $u16 = JsString::utf8ToUtf16LE($str);
            $len = (int) (strlen($u16) / 2);
            $k = $relative >= 0 ? $relative : $len + $relative;
            if ($k < 0 || $k >= $len) {
                return JsUndefined::instance();
            }
            $index = (int) $k;
            $codeUnit = ord($u16[$index * 2]) | (ord($u16[$index * 2 + 1]) << 8);
            return new JsString(JsString::utf16CodeUnitToUtf8($codeUnit));
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
                throw new \PhpJs\Exceptions\RangeError(
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
                throw new \PhpJs\Exceptions\TypeError(
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
                            throw new \PhpJs\Exceptions\TypeError(
                                'String.prototype.replaceAll called with a non-global RegExp argument'
                            );
                        }
                        $flags = TypeConversion::toString($flagsVal);
                        if (!str_contains($flags, 'g')) {
                            throw new \PhpJs\Exceptions\TypeError(
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
                        if ($replacer instanceof \PhpJs\Value\JsHTMLDDA) {
                            return JsNull::instance();
                        }
                        throw new \PhpJs\Exceptions\TypeError(
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
                throw new \PhpJs\Exceptions\TypeError(
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
                        if ($searcher instanceof \PhpJs\Value\JsHTMLDDA) {
                            return JsNull::instance();
                        }
                        throw new \PhpJs\Exceptions\TypeError('RegExp[Symbol.search] is not a function');
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
            $rx = \PhpJs\Engine::createRegExp($patternStr, '');
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
                throw new \PhpJs\Exceptions\TypeError(
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
                        if ($matcher instanceof \PhpJs\Value\JsHTMLDDA) {
                            return JsNull::instance();
                        }
                        throw new \PhpJs\Exceptions\TypeError('RegExp[Symbol.match] is not a function');
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
                    $rx = \PhpJs\Engine::createRegExp($patternStr, '');
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
                throw new \PhpJs\Exceptions\TypeError(
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
                            throw new \PhpJs\Exceptions\TypeError(
                                'String.prototype.matchAll called with a non-global RegExp argument'
                            );
                        }
                        $flagsStr = TypeConversion::toString($flagsVal);
                        if (!str_contains($flagsStr, 'g')) {
                            throw new \PhpJs\Exceptions\TypeError(
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
                        if ($matcher instanceof \PhpJs\Value\JsHTMLDDA) {
                            return JsNull::instance();
                        }
                        throw new \PhpJs\Exceptions\TypeError('Symbol.matchAll is not a function');
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
            $rx = \PhpJs\Engine::createRegExp($R, 'g');
            if ($rx !== null) {
                $matchAllSym = SymbolConstructor::matchAll();
                $matcher = $rx->getBySymbol($matchAllSym);
                if ($matcher instanceof JsFunction) {
                    return $matcher->call($rx, [new JsString($str)]);
                }
                // Per spec, Invoke throws TypeError if the method is not callable.
                throw new \PhpJs\Exceptions\TypeError(
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
            $u16 = JsString::utf8ToUtf16LE($str);
            $size = (int) (strlen($u16) / 2);

            if ($pos < 0 || $pos >= $size) {
                return JsUndefined::instance();
            }

            $position = (int) $pos;
            $first = ord($u16[$position * 2]) | (ord($u16[$position * 2 + 1]) << 8);

            // If first is not a leading surrogate, or position+1 == size, return first.
            if ($first < 0xD800 || $first > 0xDBFF || $position + 1 === $size) {
                return JsNumber::of((float) $first);
            }

            $second = ord($u16[($position + 1) * 2]) | (ord($u16[($position + 1) * 2 + 1]) << 8);

            // If second is not a trailing surrogate, return first.
            if ($second < 0xDC00 || $second > 0xDFFF) {
                return JsNumber::of((float) $first);
            }

            // UTF-16 decode: (lead - 0xD800) * 1024 + (trail - 0xDC00) + 0x10000.
            $cp = ($first - 0xD800) * 0x400 + ($second - 0xDC00) + 0x10000;
            return JsNumber::of((float) $cp);
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
                    'NFC' => 16, // Normalizer::FORM_C
                    'NFD' => 4, // Normalizer::FORM_D
                    'NFKC' => 32, // Normalizer::FORM_KC
                    'NFKD' => 8, // Normalizer::FORM_KD
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
            $localesArg = $args[1] ?? \PhpJs\Value\JsUndefined::instance();
            $optionsArg = $args[2] ?? \PhpJs\Value\JsUndefined::instance();
            // Delegate to Intl.Collator when available so locale /
            // options validation matches the spec exactly. localeCompare
            // is "Same as Intl.Collator(locales, options).compare(this, that)".
            if (class_exists(\Collator::class) && extension_loaded('intl')) {
                $env = \PhpJs\Engine::getCurrentInterpreter()?->getGlobalEnv();
                $intlObj = $env?->get('Intl', false);
                if ($intlObj instanceof \PhpJs\Value\JsObject) {
                    $colCtor = $intlObj->get('Collator');
                    if ($colCtor instanceof \PhpJs\Value\JsFunction) {
                        $colProto = $colCtor->get('prototype');
                        $colObj = new \PhpJs\Value\JsObject(
                            $colProto instanceof \PhpJs\Value\JsObject ? $colProto : null,
                        );
                        $colObj->defineOwnProperty(
                            '[[NewTarget]]',
                            \PhpJs\Object\PropertyDescriptor::data($colCtor, false, false, false),
                        );
                        ($colCtor->getNativeCallable())($colObj, [$localesArg, $optionsArg]);
                        $interp = \PhpJs\Engine::getCurrentInterpreter();
                        $compareGetter = $colProto instanceof \PhpJs\Value\JsObject
                            ? $colProto->getOwnPropertyDescriptor('compare')
                            : null;
                        if (
                            $interp !== null
                            && $compareGetter !== null
                            && $compareGetter->get instanceof \PhpJs\Value\JsFunction
                        ) {
                            $bound = $interp->callFunction(
                                $compareGetter->get,
                                $colObj,
                                [],
                            );
                            if ($bound instanceof \PhpJs\Value\JsFunction) {
                                $result = $interp->callFunction(
                                    $bound,
                                    \PhpJs\Value\JsUndefined::instance(),
                                    [new JsString($str), new JsString($that)],
                                );
                                if ($result instanceof JsNumber) {
                                    return $result;
                                }
                            }
                        }
                    }
                }
            }
            // Fallback: PHP strcmp with no locale awareness.
            $cmp = strcmp($str, $that);
            if ($cmp < 0) {
                return JsNumber::of(-1.0);
            }
            if ($cmp > 0) {
                return JsNumber::of(1.0);
            }
            return JsNumber::of(0.0);
        };
    }

    private static function fromCharCode(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = '';
            foreach ($args as $arg) {
                $code = \PhpJs\Spec\TypeConversion::toUint16($arg);
                // Use utf16CodeUnitToUtf8 to handle surrogates (U+D800-U+DFFF)
                // which mb_chr rejects as invalid UTF-8. Surrogates are stored
                // internally as CESU-8 3-byte sequences.
                $str .= JsString::utf16CodeUnitToUtf8($code);
            }
            return new JsString($str);
        };
    }

    private static function fromCodePoint(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = '';
            foreach ($args as $arg) {
                $num = TypeConversion::toNumber($arg);
                // Step 2b: If not integral, throw RangeError (NaN, Infinity, fractional).
                if (is_nan($num) || is_infinite($num) || floor($num) !== $num) {
                    throw new \PhpJs\Exceptions\RangeError("Invalid code point {$num}");
                }
                $code = (int) $num;
                // Step 2c: If < 0 or > 0x10FFFF, throw RangeError.
                if ($code < 0 || $code > 0x10FFFF) {
                    throw new \PhpJs\Exceptions\RangeError("Invalid code point {$code}");
                }
                if ($code >= 0xD800 && $code <= 0xDBFF) {
                    // Lone high surrogate: encode as CESU-8 so the byte
                    // stream round-trips back to a single UTF-16 code unit.
                    $str .= "\xED" . chr(0xA0 | (($code >> 6) & 0x0F)) . chr(0x80 | ($code & 0x3F));
                    continue;
                }
                if ($code >= 0xDC00 && $code <= 0xDFFF) {
                    // Lone low surrogate.
                    $str .= "\xED" . chr(0xB0 | (($code >> 6) & 0x0F)) . chr(0x80 | ($code & 0x3F));
                    continue;
                }
                $str .= mb_chr($code, 'UTF-8');
            }
            return new JsString($str);
        };
    }

    private static function isWellFormed(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $u16 = JsString::utf8ToUtf16LE($str);
            $u16Len = (int) (strlen($u16) / 2);
            for ($i = 0; $i < $u16Len; $i++) {
                $cu = ord($u16[$i * 2]) | (ord($u16[$i * 2 + 1]) << 8);
                if ($cu >= 0xD800 && $cu <= 0xDBFF) {
                    if ($i + 1 >= $u16Len) {
                        return new \PhpJs\Value\JsBoolean(false);
                    }
                    $next = ord($u16[($i + 1) * 2]) | (ord($u16[($i + 1) * 2 + 1]) << 8);
                    if ($next < 0xDC00 || $next > 0xDFFF) {
                        return new \PhpJs\Value\JsBoolean(false);
                    }
                    $i++;
                } elseif ($cu >= 0xDC00 && $cu <= 0xDFFF) {
                    return new \PhpJs\Value\JsBoolean(false);
                }
            }
            return new \PhpJs\Value\JsBoolean(true);
        };
    }

    private static function toWellFormed(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $u16 = JsString::utf8ToUtf16LE($str);
            $u16Len = (int) (strlen($u16) / 2);
            $out = '';
            $i = 0;
            while ($i < $u16Len) {
                $cu = ord($u16[$i * 2])
                    | (ord($u16[$i * 2 + 1]) << 8);
                if ($cu >= 0xD800 && $cu <= 0xDBFF) {
                    if ($i + 1 < $u16Len) {
                        $next = ord($u16[($i + 1) * 2])
                            | (ord($u16[($i + 1) * 2 + 1]) << 8);
                        if ($next >= 0xDC00 && $next <= 0xDFFF) {
                            // Valid pair: keep both code units.
                            $out .= substr($u16, $i * 2, 4);
                            $i += 2;
                            continue;
                        }
                    }
                    // Lone high surrogate: replace with U+FFFD.
                    $out .= pack('v', 0xFFFD);
                    $i++;
                } elseif ($cu >= 0xDC00 && $cu <= 0xDFFF) {
                    // Lone low surrogate: replace with U+FFFD.
                    $out .= pack('v', 0xFFFD);
                    $i++;
                } else {
                    $out .= substr($u16, $i * 2, 2);
                    $i++;
                }
            }
            // Convert UTF-16LE back to UTF-8, which properly
            // combines surrogate pairs into 4-byte sequences.
            return new JsString(JsString::utf16LEToUtf8($out));
        };
    }
}
