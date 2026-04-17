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
    private const FALLBACK_MAX_STRING_LENGTH = 268435456;
    private const MIN_SAFE_STRING_LENGTH = 16777216;
    private static ?int $maxStringLength = null;

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
                $data->set('index', new JsNumber((float) ($index + 1)));
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
                    $ch = mb_chr($cp, 'UTF-8');
                    $chars[] = new JsString($ch !== false ? $ch : '?');
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
        $data->set('index', new JsNumber(0.0));
        $data->set('total', new JsNumber((float) count($chars)));
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
        $d('toLocaleLowerCase', self::toLowerCase(), 0);
        $d('toLocaleUpperCase', self::toUpperCase(), 0);
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
            // Index by UTF-16 code units, not Unicode codepoints.
            $u16 = JsString::utf8ToUtf16LE($str);
            $len = (int) (strlen($u16) / 2);
            if ($index < 0 || $index >= $len) {
                return new JsNumber(NAN);
            }
            $cu = ord($u16[$index * 2]) | (ord($u16[$index * 2 + 1]) << 8);
            return new JsNumber((float) $cu);
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
            $len = mb_strlen($str, 'UTF-8');

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

            return new JsString(mb_substr($str, $from, $to - $from, 'UTF-8'));
        };
    }

    private static function substring(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $len = mb_strlen($str, 'UTF-8');

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
                        function ($matches) use ($replArg, $str, $nExpectedCaptures): string {
                            $byteOffset = is_array($matches[0]) ? $matches[0][1] : 0;
                            $matched = is_array($matches[0]) ? $matches[0][0] : $matches[0];
                            $charOffset = mb_strlen(substr($str, 0, $byteOffset), 'UTF-8');
                            $jsArgs = [new JsString($matched)];
                            // Add capture groups (pad with undefined for unmatched optional groups)
                            $captures = [];
                            for ($ci = 1; $ci < count($matches); $ci++) {
                                if (is_string($ci)) {
                                    continue; // skip named keys
                                }
                                $cap = $matches[$ci];
                                $captures[] = is_array($cap)
                                    ? ($cap[1] === -1 ? null : $cap[0])
                                    : ($cap === '' ? null : $cap);
                            }
                            // Pad to expected capture count
                            while (count($captures) < $nExpectedCaptures) {
                                $captures[] = null;
                            }
                            foreach ($captures as $cap) {
                                $jsArgs[] = $cap === null ? JsUndefined::instance() : new JsString($cap);
                            }
                            $jsArgs[] = new JsNumber((float) $charOffset);
                            $jsArgs[] = new JsString($str);
                            $ret = $replArg->call(JsUndefined::instance(), $jsArgs);
                            return TypeConversion::toString($ret);
                        },
                        $str,
                        $limit,
                        $count,
                        PREG_OFFSET_CAPTURE,
                    );
                } else {
                    // String replacement: apply GetSubstitution for $-patterns
                    $result = @preg_replace_callback(
                        $pcre,
                        function ($matches) use ($replStr, $str, $nExpectedCaptures): string {
                            $byteOffset = is_array($matches[0]) ? $matches[0][1] : 0;
                            $matched = is_array($matches[0]) ? $matches[0][0] : $matches[0];
                            $charOffset = mb_strlen(substr($str, 0, $byteOffset), 'UTF-8');
                            $captures = [];
                            $namedCaptures = null;
                            foreach ($matches as $key => $cap) {
                                if (is_string($key)) {
                                    if ($namedCaptures === null) {
                                        $namedCaptures = [];
                                    }
                                    $namedCaptures[$key] = is_array($cap)
                                        ? ($cap[1] === -1 ? null : $cap[0])
                                        : ($cap === '' ? null : $cap);
                                    continue;
                                }
                                if ($key === 0) {
                                    continue;
                                }
                                $captures[] = is_array($cap)
                                    ? ($cap[1] === -1 ? null : $cap[0])
                                    : ($cap === '' ? null : $cap);
                            }
                            // Pad to expected capture count
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
                        },
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
                $jsArgs = [new JsString($search), new JsNumber((float) $pos), new JsString($str)];
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

    /**
     * GetSubstitution(matched, str, position, captures, namedCaptures, replacement) per spec §22.1.3.17.1.
     * Processes $-sequences in replacement strings.
     *
     * @param list<string|null> $captures indexed captures (0-indexed internally, $n is 1-indexed)
     * @param array<string,string|null>|null $namedCaptures named capture groups or null
     */
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
        ?array $namedCaptures = null,
    ): string {
        $result = '';
        $len = strlen($replacement);
        $captureLen = count($captures);
        $i = 0;
        while ($i < $len) {
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
                    // Named capture: $<Name>
                    $closePos = strpos($replacement, '>', $i + 2);
                    if ($closePos !== false && $namedCaptures !== null) {
                        $name = substr($replacement, $i + 2, $closePos - $i - 2);
                        if (array_key_exists($name, $namedCaptures)) {
                            $result .= $namedCaptures[$name] ?? '';
                        } else {
                            $result .= '';
                        }
                        $i = $closePos + 1;
                    } elseif ($closePos !== false) {
                        // No named captures: output as literal
                        $name = substr($replacement, $i + 2, $closePos - $i - 2);
                        $result .= '$<' . $name . '>';
                        $i = $closePos + 1;
                    } else {
                        $result .= '$';
                        $i++;
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
                        new JsNumber((float) $pos),
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

    private static function replaceRegexpWithString(
        string $pcre,
        string $subject,
        string $replacement,
        bool $isGlobal,
    ): string {
        $flags = PREG_SET_ORDER | PREG_OFFSET_CAPTURE;
        if (defined('PREG_UNMATCHED_AS_NULL')) {
            $flags |= PREG_UNMATCHED_AS_NULL;
        }

        $matched = @preg_match_all($pcre, $subject, $matches, $flags);
        if ($matched !== null && $matched !== false && $matched > 0) {
            $result = '';
            $cursor = 0;
            $matchLimit = $isGlobal ? $matched : 1;

            for ($i = 0; $i < $matchLimit; $i++) {
                $match = $matches[$i];
                $fullMatch = (string) ($match[0][0] ?? '');
                $offset = (int) ($match[0][1] ?? 0);

                if ($offset < $cursor) {
                    continue;
                }

                self::appendWithLimit($result, substr($subject, $cursor, $offset - $cursor));
                self::appendWithLimit(
                    $result,
                    self::applyStringReplacement($replacement, $match, $subject, $offset, $fullMatch),
                );
                $cursor = $offset + strlen($fullMatch);
            }

            self::appendWithLimit($result, substr($subject, $cursor));
            return $result;
        }

        return $subject;
    }

    /**
     * @param array<int, array{0: ?string, 1: int}> $match
     */
    private static function applyStringReplacement(
        string $replacement,
        array $match,
        string $subject,
        int $offset,
        string $fullMatch,
    ): string {
        $result = '';
        $length = strlen($replacement);
        $captureCount = count($match) - 1;

        for ($i = 0; $i < $length; $i++) {
            $ch = $replacement[$i];
            if ($ch !== '$' || $i + 1 >= $length) {
                self::appendWithLimit($result, $ch);
                continue;
            }

            $next = $replacement[$i + 1];
            switch ($next) {
                case '$':
                    self::appendWithLimit($result, '$');
                    $i++;
                    continue 2;
                case '&':
                    self::appendWithLimit($result, $fullMatch);
                    $i++;
                    continue 2;
                case '`':
                    self::appendWithLimit($result, substr($subject, 0, $offset));
                    $i++;
                    continue 2;
                case "'":
                    self::appendWithLimit($result, substr($subject, $offset + strlen($fullMatch)));
                    $i++;
                    continue 2;
            }

            if ($next >= '0' && $next <= '9') {
                $captureIndex = null;
                $consume = 0;

                if ($i + 2 < $length) {
                    $nextDigit = $replacement[$i + 2];
                    if ($nextDigit >= '0' && $nextDigit <= '9') {
                        $candidate = (int) ($next . $nextDigit);
                        if ($candidate >= 1 && $candidate <= $captureCount) {
                            $captureIndex = $candidate;
                            $consume = 2;
                        }
                    }
                }

                if ($captureIndex === null && $next !== '0') {
                    $singleDigitCapture = (int) $next;
                    if ($singleDigitCapture <= $captureCount) {
                        $captureIndex = $singleDigitCapture;
                        $consume = 1;
                    }
                }

                if ($captureIndex !== null) {
                    $capture = $match[$captureIndex][0] ?? null;
                    if ($capture !== null) {
                        self::appendWithLimit($result, $capture);
                    }
                    $i += $consume;
                    continue;
                }

                if ($next === '0') {
                    self::appendWithLimit($result, '$0');
                    $i++;
                    continue;
                }
            }

            self::appendWithLimit($result, '$');
        }

        return $result;
    }

    private static function appendWithLimit(string &$buffer, string $fragment): void
    {
        if ($fragment === '') {
            return;
        }

        $newLength = strlen($buffer) + strlen($fragment);
        if ($newLength > self::maxStringLength()) {
            throw new \PhpJs\Exceptions\RangeError('Invalid string length');
        }

        $buffer .= $fragment;
    }

    private static function maxStringLength(): int
    {
        if (self::$maxStringLength !== null) {
            return self::$maxStringLength;
        }

        $memoryLimit = ini_get('memory_limit');
        if (!is_string($memoryLimit) || $memoryLimit === '' || $memoryLimit === '-1') {
            return self::$maxStringLength = self::FALLBACK_MAX_STRING_LENGTH;
        }

        $bytes = self::parseMemoryLimitToBytes($memoryLimit);
        if ($bytes === null) {
            return self::$maxStringLength = self::FALLBACK_MAX_STRING_LENGTH;
        }

        $safeLimit = max(
            self::MIN_SAFE_STRING_LENGTH,
            min(self::FALLBACK_MAX_STRING_LENGTH, intdiv($bytes, 4)),
        );

        return self::$maxStringLength = $safeLimit;
    }

    private static function parseMemoryLimitToBytes(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $unit = strtolower(substr($value, -1));
        if (ctype_alpha($unit)) {
            $number = substr($value, 0, -1);
        } else {
            $unit = '';
            $number = $value;
        }

        if (!is_numeric($number)) {
            return null;
        }

        $bytes = (float) $number;
        switch ($unit) {
            case 'g':
                $bytes *= 1024;
            case 'm':
                $bytes *= 1024;
            case 'k':
                $bytes *= 1024;
                break;
        }

        return $bytes > 0 ? (int) $bytes : null;
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
                        throw new \PhpJs\Exceptions\TypeError('RegExp[Symbol.search] is not a function');
                    }
                }
            }

            // Step 3: ToString(O)
            $str = self::extractString($this_);

            // Non-object or no @@search: convert to regexp and call builtin search.
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

            // Per spec: undefined/null argument → RegExpCreate("", undefined) → /(?:)/.
            // /(?:)/ matches at position 0 in any string.
            if ($searchArg instanceof JsUndefined || $searchArg instanceof JsNull) {
                return new JsNumber(0.0);
            }

            $search = TypeConversion::toString($searchArg);
            $pos = mb_strpos($str, $search, 0, 'UTF-8');
            return new JsNumber($pos === false ? -1.0 : (float) $pos);
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
                $elements = [];
                foreach ($matches as $key => $match) {
                    if (is_int($key)) {
                        $elements[] = ($match[1] === -1 || $match[0] === null)
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
                        throw new \PhpJs\Exceptions\TypeError('Symbol.matchAll is not a function');
                    }
                }
            }

            // Step 3: ToString(O)
            $str = self::extractString($this_);

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
            $u16 = JsString::utf8ToUtf16LE($str);
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
