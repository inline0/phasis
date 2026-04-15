<?php

declare(strict_types=1);

namespace PhpJs\Spec;

use PhpJs\Exceptions\TypeError;
use PhpJs\Value\JsBigInt;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsSymbol;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

/**
 * ES spec type conversion algorithms (section 7.1).
 *
 * Central location for all type coercion so value classes and the interpreter
 * can share them without circular dependencies.
 */
final class TypeConversion
{
    /**
     * 7.1.1 ToPrimitive(input, preferredType).
     *
     * If input is already a primitive, return it unchanged.
     * If input is an object, call the appropriate conversion method:
     * hint "number" or "default": try valueOf() first, then toString().
     * hint "string": try toString() first, then valueOf().
     *
     * @param string $hint One of "default", "number", "string".
     */
    private static int $toPrimitiveDepth = 0;

    public static function toPrimitive(JsValue $value, string $hint = 'default'): JsValue
    {
        // Primitives pass through.
        if (!$value instanceof JsObject) {
            return $value;
        }

        // Guard against infinite recursion
        if (self::$toPrimitiveDepth > 10) {
            throw new TypeError('Cannot convert object to primitive value');
        }
        self::$toPrimitiveDepth++;

        try {
            // Check for [Symbol.toPrimitive] method first (spec step 1.a).
            $toPrimSym = \PhpJs\BuiltIn\SymbolConstructor::toPrimitive();
            $exoticToPrim = $value->getBySymbol($toPrimSym);
            if (!$exoticToPrim instanceof JsUndefined && !$exoticToPrim instanceof JsNull) {
                if (!$exoticToPrim instanceof JsFunction) {
                    throw new TypeError('Symbol.toPrimitive is not a function');
                }
                $hintStr = new \PhpJs\Value\JsString($hint);
                $result = $exoticToPrim->call($value, [$hintStr]);
                if ($result instanceof JsObject) {
                    throw new TypeError('Cannot convert object to primitive value');
                }
                return $result;
            }

            // For Date objects the default hint is "string"; for everything else it is "number".
            if ($hint === 'default') {
                $hint = \PhpJs\BuiltIn\DateConstructor::isDateObject($value) ? 'string' : 'number';
            }

            $methodNames = $hint === 'string'
                ? ['toString', 'valueOf']
                : ['valueOf', 'toString'];

            foreach ($methodNames as $methodName) {
                $method = $value->get($methodName);
                if ($method instanceof JsFunction) {
                    $result = $method->call($value, []);
                    if (!$result instanceof JsObject) {
                        return $result;
                    }
                }
            }

            throw new TypeError('Cannot convert object to primitive value');
        } finally {
            self::$toPrimitiveDepth--;
        }
    }

    /**
     * 7.1.2 ToBoolean(argument).
     *
     * undefined -> false, null -> false, boolean -> identity,
     * number -> false if +0/-0/NaN else true,
     * string -> false if empty else true,
     * symbol -> true, object -> true (always).
     */
    public static function toBoolean(JsValue $value): bool
    {
        return $value->toBoolean();
    }

    /**
     * 7.1.3 ToNumeric(value).
     *
     * Let primValue be ToPrimitive(value, number).
     * If Type(primValue) is BigInt, return primValue.
     * Return ToNumber(primValue).
     */
    public static function toNumeric(JsValue $value): JsValue
    {
        $primValue = self::toPrimitive($value, 'number');
        if ($primValue instanceof JsBigInt) {
            return $primValue;
        }
        return new JsNumber(self::toNumber($primValue));
    }

    /**
     * 7.1.3 ToNumber(argument).
     *
     * undefined -> NaN, null -> +0, boolean -> 1 or 0,
     * number -> identity, string -> StringToNumber,
     * symbol -> TypeError, object -> ToPrimitive("number") then ToNumber.
     */
    public static function toNumber(JsValue $value): float
    {
        if ($value instanceof JsUndefined) {
            return NAN;
        }

        if ($value instanceof JsNull) {
            return 0.0;
        }

        if ($value instanceof JsBoolean) {
            return $value->value ? 1.0 : 0.0;
        }

        if ($value instanceof JsNumber) {
            return $value->value;
        }

        if ($value instanceof JsString) {
            return self::stringToNumber($value->value);
        }

        if ($value instanceof JsSymbol) {
            throw new TypeError('Cannot convert a Symbol value to a number');
        }

        if ($value instanceof JsBigInt) {
            throw new TypeError('Cannot convert a BigInt value to a number');
        }

        // Object: ToPrimitive with "number" hint, then recurse.
        if ($value instanceof JsObject) {
            $primitive = self::toPrimitive($value, 'number');
            return self::toNumber($primitive);
        }

        return NAN;
    }

    /**
     * 7.1.4 ToIntegerOrInfinity(argument).
     *
     * Let number be ToNumber(argument).
     * If NaN or +0 or -0 -> return 0.
     * If +Infinity -> return +Infinity.
     * If -Infinity -> return -Infinity.
     * Otherwise truncate toward zero.
     */
    public static function toIntegerOrInfinity(JsValue $value): float
    {
        $number = self::toNumber($value);

        if (is_nan($number) || $number === 0.0) {
            return 0.0;
        }

        if (is_infinite($number)) {
            return $number;
        }

        return ($number > 0 ? 1.0 : -1.0) * floor(abs($number));
    }

    /**
     * Legacy ToInteger. Same as ToIntegerOrInfinity for practical purposes.
     */
    public static function toInteger(JsValue $value): float
    {
        return self::toIntegerOrInfinity($value);
    }

    /**
     * 7.1.5 ToInt32(argument).
     *
     * Let number be ToNumber(argument).
     * If NaN, +0, -0, +Infinity, -Infinity -> return 0.
     * Let int be truncate(number) modulo 2^32.
     * If int >= 2^31 then return int - 2^32, else return int.
     */
    public static function toInt32(JsValue $value): int
    {
        $number = self::toNumber($value);

        if (is_nan($number) || is_infinite($number) || $number === 0.0) {
            return 0;
        }

        $n = ($number > 0 ? 1.0 : -1.0) * floor(abs($number));
        $int32 = fmod($n, 4294967296.0); // 2^32

        if ($int32 < 0) {
            $int32 += 4294967296.0;
        }

        if ($int32 >= 2147483648.0) { // 2^31
            $int32 -= 4294967296.0;
        }

        return (int) $int32;
    }

    /**
     * 7.1.6 ToUint32(argument).
     *
     * Let number be ToNumber(argument).
     * If NaN, +0, -0, +Infinity, -Infinity -> return 0.
     * Let int be truncate(number) modulo 2^32.
     */
    public static function toUint32(JsValue $value): int
    {
        $number = self::toNumber($value);

        if (is_nan($number) || is_infinite($number) || $number === 0.0) {
            return 0;
        }

        $n = ($number > 0 ? 1.0 : -1.0) * floor(abs($number));
        $int32 = fmod($n, 4294967296.0); // 2^32

        if ($int32 < 0) {
            $int32 += 4294967296.0;
        }

        return (int) $int32;
    }

    /**
     * 6.1.6.1.9 Number::leftShift(x, y).
     *
     * ToInt32(x) << (ToUint32(y) & 0x1F), result truncated to Int32.
     * PHP uses 64-bit integers, so the raw shift result must be re-truncated
     * to the signed 32-bit range. Returns a float suitable for JsNumber.
     */
    public static function leftShift(JsValue $x, JsValue $y): float
    {
        $lval = self::toInt32($x);
        $rval = self::toUint32($y) & 0x1F;
        $result = $lval << $rval;

        return (float) self::truncateToInt32($result);
    }

    /**
     * 6.1.6.1.10 Number::signedRightShift(x, y).
     *
     * ToInt32(x) >> (ToUint32(y) & 0x1F). PHP's >> is arithmetic (sign-preserving)
     * on 64-bit, which matches the spec because the input is already a valid
     * signed 32-bit integer and a right shift cannot exceed that range.
     */
    public static function signedRightShift(JsValue $x, JsValue $y): float
    {
        $lval = self::toInt32($x);
        $rval = self::toUint32($y) & 0x1F;

        return (float) ($lval >> $rval);
    }

    /**
     * 6.1.6.1.11 Number::unsignedRightShift(x, y).
     *
     * ToUint32(x) >>> (ToUint32(y) & 0x1F). The left operand is converted to
     * an unsigned 32-bit integer first (ToUint32), then shifted right, producing
     * a non-negative 32-bit result.
     *
     * Note: The spec says the left operand uses ToUint32 (not ToInt32) for >>>,
     * unlike << and >> which use ToInt32. On 64-bit PHP, ToUint32 already
     * produces a non-negative value in the range [0, 2^32 - 1], so a normal
     * PHP >> gives the correct unsigned shift.
     */
    public static function unsignedRightShift(JsValue $x, JsValue $y): float
    {
        $lval = self::toUint32($x);
        $rval = self::toUint32($y) & 0x1F;

        if ($rval === 0) {
            return (float) $lval;
        }

        return (float) ($lval >> $rval);
    }

    /**
     * Truncate a PHP 64-bit integer to the signed 32-bit range [-2^31, 2^31 - 1].
     *
     * After a left shift on PHP's 64-bit integers, the result may exceed the
     * 32-bit range. This applies the modulo-2^32 then signed-adjustment
     * algorithm from the ToInt32 spec to a raw integer (not a JsValue).
     */
    public static function truncateToInt32(int $value): int
    {
        // Fast path: already in 32-bit signed range.
        if ($value >= -2147483648 && $value <= 2147483647) {
            return $value;
        }

        // Modulo 2^32 via bitmask. On 64-bit PHP this gives the low 32 bits.
        $uint32 = $value & 0xFFFFFFFF;

        // Convert to signed: if bit 31 is set, subtract 2^32.
        if ($uint32 >= 0x80000000) {
            return $uint32 - 0x100000000;
        }

        return $uint32;
    }

    /**
     * 7.1.17 ToString(argument).
     *
     * undefined -> "undefined", null -> "null",
     * boolean -> "true" or "false",
     * number -> NumberToString (NaN, Infinity, -0 -> "0", etc.),
     * string -> identity,
     * symbol -> TypeError,
     * bigint -> BigInt numeric string representation,
     * object -> ToPrimitive("string") then ToString.
     */
    public static function toString(JsValue $value): string
    {
        if ($value instanceof JsUndefined) {
            return 'undefined';
        }

        if ($value instanceof JsNull) {
            return 'null';
        }

        if ($value instanceof JsBoolean) {
            return $value->value ? 'true' : 'false';
        }

        if ($value instanceof JsNumber) {
            return $value->toJsString();
        }

        if ($value instanceof JsString) {
            return $value->value;
        }

        if ($value instanceof JsSymbol) {
            throw new TypeError('Cannot convert a Symbol value to a string');
        }

        if ($value instanceof JsBigInt) {
            return $value->toJsString();
        }

        // Object: ToPrimitive with "string" hint, then recurse.
        if ($value instanceof JsObject) {
            $primitive = self::toPrimitive($value, 'string');
            return self::toString($primitive);
        }

        return '';
    }

    /**
     * 7.1.18 ToObject(argument).
     *
     * undefined -> TypeError.
     * null -> TypeError.
     * boolean -> Boolean wrapper object.
     * number -> Number wrapper object.
     * string -> String wrapper object.
     * symbol -> Symbol wrapper object.
     * object -> return as-is.
     *
     * Wrapper objects are plain JsObjects with the primitive stored as an internal value.
     * Full wrapper support (Boolean.prototype, etc.) will come with built-in constructors.
     * For now, store the wrapped value in an internal property.
     */
    public static function toObject(JsValue $value): JsObject
    {
        if ($value instanceof JsUndefined) {
            throw new TypeError('Cannot convert undefined to object');
        }

        if ($value instanceof JsNull) {
            throw new TypeError('Cannot convert null to object');
        }

        if ($value instanceof JsObject) {
            return $value;
        }

        // Create a wrapper object with the primitive stored internally.
        $wrapper = new JsObject();
        $wrapper->defineOwnProperty('[[PrimitiveValue]]', \PhpJs\Object\PropertyDescriptor::data($value, false, false, false));

        // Install valueOf that returns the wrapped primitive, matching the spec behavior
        // for Boolean, Number, and String wrapper objects.
        if ($value instanceof JsBoolean || $value instanceof JsNumber || $value instanceof JsString) {
            $wrapper->defineOwnProperty('valueOf', \PhpJs\Object\PropertyDescriptor::data(
                JsFunction::fromCallable('valueOf', fn() => $value, 0),
                true,
                false,
                true,
            ));
        }

        if ($value instanceof JsString) {
            // String wrapper objects expose each character as an indexed enumerable property.
            $str = $value->value;
            $len = mb_strlen($str, 'UTF-8');
            for ($i = 0; $i < $len; $i++) {
                $ch = mb_substr($str, $i, 1, 'UTF-8');
                $wrapper->defineOwnProperty((string) $i, \PhpJs\Object\PropertyDescriptor::data(
                    new JsString($ch),
                    false,
                    true,
                    false,
                ));
            }
            $wrapper->defineOwnProperty('length', \PhpJs\Object\PropertyDescriptor::data(
                new JsNumber((float) $len),
                false,
                false,
                false,
            ));
        }

        // BigInt wrappers need valueOf to return the primitive for ToPrimitive to work.
        if ($value instanceof JsBigInt) {
            $wrapper->defineOwnProperty('valueOf', \PhpJs\Object\PropertyDescriptor::data(
                JsFunction::fromCallable('valueOf', fn() => $value, 0),
            ));
        }

        // Symbol wrappers get Symbol.prototype
        if ($value instanceof JsSymbol) {
            $symProto = JsSymbol::getSymbolPrototype();
            if ($symProto !== null) {
                $wrapper->setPrototype($symProto);
            }
        }

        return $wrapper;
    }

    /**
     * 7.1.14 ToPropertyKey(argument).
     *
     * Let key = ToPrimitive(argument, "string").
     * If key is a Symbol, return it.
     * Otherwise, return ToString(key).
     */
    public static function toPropertyKey(JsValue $value): JsValue
    {
        $key = self::toPrimitive($value, 'string');

        if ($key instanceof JsSymbol) {
            return $key;
        }

        return new JsString(self::toString($key));
    }

    /**
     * 7.1.7 ToUint16(argument).
     */
    public static function toUint16(JsValue $value): int
    {
        $number = self::toNumber($value);

        if (is_nan($number) || is_infinite($number) || $number === 0.0) {
            return 0;
        }

        $n = ($number > 0 ? 1.0 : -1.0) * floor(abs($number));
        $int16 = (int) fmod($n, 65536.0); // 2^16

        if ($int16 < 0) {
            $int16 += 65536;
        }

        return $int16;
    }

    /**
     * 7.1.15 ToLength(argument).
     *
     * Let len = ToIntegerOrInfinity(argument).
     * If len <= 0 return 0.
     * Return min(len, 2^53 - 1).
     */
    public static function toLength(JsValue $value): int
    {
        $len = self::toIntegerOrInfinity($value);

        if ($len <= 0) {
            return 0;
        }

        return (int) min($len, 9007199254740991.0); // 2^53 - 1
    }

    /**
     * 7.1.16 ToIndex(argument).
     *
     * If undefined, return 0.
     * Let integerIndex = ToIntegerOrInfinity(value).
     * If integerIndex < 0 or integerIndex > 2^53 - 1, throw RangeError.
     * Return ToLength(integerIndex).
     */
    public static function toIndex(JsValue $value): int
    {
        if ($value instanceof JsUndefined) {
            return 0;
        }

        $integerIndex = self::toIntegerOrInfinity($value);

        if ($integerIndex < 0) {
            throw new \PhpJs\Exceptions\RangeError('Invalid index');
        }

        $index = (int) min($integerIndex, 9007199254740991.0);

        if ((float) $index !== $integerIndex) {
            throw new \PhpJs\Exceptions\RangeError('Invalid index');
        }

        return $index;
    }

    /**
     * Convert a JS string to a number per ES spec StringToNumber.
     *
     * Rules:
     * - Empty or whitespace-only string -> 0.
     * - "Infinity" / "+Infinity" / "-Infinity" -> INF / -INF.
     * - Hex "0x..." -> parse as hex.
     * - Octal "0o..." -> parse as octal.
     * - Binary "0b..." -> parse as binary.
     * - Standard decimal/exponential -> parse as float.
     * - Everything else -> NAN.
     */
    public static function stringToNumber(string $value): float
    {
        $trimmed = self::trimWhitespace($value);

        if ($trimmed === '') {
            return 0.0;
        }

        if ($trimmed === 'Infinity' || $trimmed === '+Infinity') {
            return INF;
        }

        if ($trimmed === '-Infinity') {
            return -INF;
        }

        // Hex: 0x or 0X. Per spec, StringNumericLiteral does not allow sign before 0x.
        if (preg_match('/^0[xX][0-9a-fA-F]+$/', $trimmed) === 1) {
            return (float) intval($trimmed, 16);
        }

        // Octal: 0o or 0O. No sign prefix.
        if (preg_match('/^0[oO][0-7]+$/', $trimmed) === 1) {
            return (float) intval($trimmed, 8);
        }

        // Binary: 0b or 0B. No sign prefix.
        if (preg_match('/^0[bB][01]+$/', $trimmed) === 1) {
            return (float) intval($trimmed, 2);
        }

        // Standard numeric string: optional sign, digits with optional decimal, optional exponent.
        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/', $trimmed) === 1) {
            return (float) $trimmed;
        }

        return NAN;
    }

    /**
     * Trim JS whitespace characters from both ends of a string.
     *
     * JS WhiteSpace (11.2) and LineTerminator (11.3) code points:
     * TAB, VT, FF, SP, NBSP, BOM, and any Unicode "Space_Separator" category,
     * plus LF, CR, LS (U+2028), PS (U+2029).
     */
    public static function trimWhitespace(string $value): string
    {
        // Match all JS-defined whitespace and line terminators at start and end.
        $pattern = '/^[\x09\x0A\x0B\x0C\x0D\x20\xC2\xA0'
            . '\xE2\x80\x80-\xE2\x80\x8A'  // U+2000..U+200A (en/em spaces etc.)
            . '\xE2\x80\xA8'                 // U+2028 Line Separator
            . '\xE2\x80\xA9'                 // U+2029 Paragraph Separator
            . '\xE2\x80\xAF'                 // U+202F Narrow No-Break Space
            . '\xE2\x81\x9F'                 // U+205F Medium Mathematical Space
            . '\xE3\x80\x80'                 // U+3000 Ideographic Space
            . '\xEF\xBB\xBF'                 // U+FEFF BOM (Zero Width No-Break Space)
            . ']+/';

        // Trim leading.
        $value = preg_replace($pattern, '', $value) ?? $value;

        // Trim trailing. Same character set but anchored at end.
        $tailPattern = '/[\x09\x0A\x0B\x0C\x0D\x20\xC2\xA0'
            . '\xE2\x80\x80-\xE2\x80\x8A'
            . '\xE2\x80\xA8'
            . '\xE2\x80\xA9'
            . '\xE2\x80\xAF'
            . '\xE2\x81\x9F'
            . '\xE3\x80\x80'
            . '\xEF\xBB\xBF'
            . ']+$/';

        return preg_replace($tailPattern, '', $value) ?? $value;
    }
}
