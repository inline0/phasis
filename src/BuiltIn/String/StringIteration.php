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
 * StringPrototype trait part: StringIteration. Composed into
 * StringPrototype via `use String\StringIteration;`. `self::`/`$this->`
 * resolve into the composing class.
 */
trait StringIteration
{
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
                throw new \Phasis\Exceptions\TypeError(
                    'Method String Iterator.prototype.next called on incompatible receiver',
                );
            }
            $slotDesc = $this_->getOwnPropertyDescriptor('[[StringIteratorData]]');
            if ($slotDesc === null) {
                throw new \Phasis\Exceptions\TypeError(
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
            throw new \Phasis\Exceptions\TypeError(
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
}
