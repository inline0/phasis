<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Runtime\Environment;
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
use Phasis\Object\PropertyDescriptor;

class StringPrototype
{
    use String\StringIteration;
    use String\StringSearch;
    use String\StringCase;
    use String\StringEncoding;
    use String\StringPadding;
    use String\StringMisc;

    /** %StringIteratorPrototype%: shared prototype for all string iterators. */
    private static ?JsObject $stringIteratorPrototype = null;




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
            $fromCharCodeFn->builtinKind = 'string.fromCharCode';
            $existing->defineOwnProperty(
                'fromCharCode',
                \Phasis\Object\PropertyDescriptor::data($fromCharCodeFn, true, false, true),
            );
            $fromCodePointFn = JsFunction::fromCallable('fromCodePoint', self::fromCodePoint(), 1);
            $existing->defineOwnProperty(
                'fromCodePoint',
                \Phasis\Object\PropertyDescriptor::data($fromCodePointFn, true, false, true),
            );
            $existing->defineOwnProperty('raw', \Phasis\Object\PropertyDescriptor::data(
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
                throw new \Phasis\Exceptions\TypeError(
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
        \Phasis\Value\JsString::resetStringPrototype();
        \Phasis\Value\JsString::setStringPrototype($proto);

        // Store the prototype so the interpreter can access it for auto-boxing.
        $env->defineVar('__StringPrototype__', $proto);
    }


















    /**
     * Decode a UTF-8 byte string (potentially containing CESU-8 lone
     * surrogates) into an array of scalar code points. Lone surrogates
     * are preserved as their 0xD800..0xDFFF value.
     *
     * @return array<int, int>
     */
    private static function decodeUtf8WithSurrogates(string $str): array
    {
        $cps = [];
        $len = strlen($str);
        $i = 0;
        while ($i < $len) {
            $b0 = ord($str[$i]);
            if ($b0 < 0x80) {
                $cps[] = $b0;
                $i++;
                continue;
            }
            if (($b0 & 0xE0) === 0xC0 && $i + 1 < $len) {
                $b1 = ord($str[$i + 1]);
                $cps[] = (($b0 & 0x1F) << 6) | ($b1 & 0x3F);
                $i += 2;
                continue;
            }
            if (($b0 & 0xF0) === 0xE0 && $i + 2 < $len) {
                $b1 = ord($str[$i + 1]);
                $b2 = ord($str[$i + 2]);
                $cps[] = (($b0 & 0x0F) << 12) | (($b1 & 0x3F) << 6) | ($b2 & 0x3F);
                $i += 3;
                continue;
            }
            if (($b0 & 0xF8) === 0xF0 && $i + 3 < $len) {
                $b1 = ord($str[$i + 1]);
                $b2 = ord($str[$i + 2]);
                $b3 = ord($str[$i + 3]);
                $cps[] = (($b0 & 0x07) << 18) | (($b1 & 0x3F) << 12)
                    | (($b2 & 0x3F) << 6) | ($b3 & 0x3F);
                $i += 4;
                continue;
            }
            // Malformed byte: emit U+FFFD and advance.
            $cps[] = 0xFFFD;
            $i++;
        }
        return $cps;
    }

    private static function encodeUtf8(int $cp): string
    {
        if ($cp < 0x80) {
            return chr($cp);
        }
        if ($cp < 0x800) {
            return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return chr(0xE0 | ($cp >> 12))
                . chr(0x80 | (($cp >> 6) & 0x3F))
                . chr(0x80 | ($cp & 0x3F));
        }
        return chr(0xF0 | ($cp >> 18))
            . chr(0x80 | (($cp >> 12) & 0x3F))
            . chr(0x80 | (($cp >> 6) & 0x3F))
            . chr(0x80 | ($cp & 0x3F));
    }

    /** CESU-8 encoding (3 bytes) for a U+D800..U+DFFF lone surrogate. */
    private static function encodeCesu8Surrogate(int $cp): string
    {
        return chr(0xE0 | ($cp >> 12))
            . chr(0x80 | (($cp >> 6) & 0x3F))
            . chr(0x80 | ($cp & 0x3F));
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
}
