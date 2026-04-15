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
 * ES spec abstract comparison and operator algorithms.
 *
 * Sections 7.2 (testing and comparison) and 13.15 (addition).
 */
final class AbstractOperations
{
    /**
     * 7.2.14 Abstract Equality Comparison (x == y).
     *
     * Follows the spec table exactly. Returns true or false.
     * null == undefined is true. Type coercion applies.
     */
    public static function abstractEquals(JsValue $x, JsValue $y): bool
    {
        // 1. If x and y are the same type, use strict equality.
        if (self::sameType($x, $y)) {
            return self::strictEquals($x, $y);
        }

        // 2. null == undefined -> true (and vice versa).
        if ($x instanceof JsNull && $y instanceof JsUndefined) {
            return true;
        }
        if ($x instanceof JsUndefined && $y instanceof JsNull) {
            return true;
        }

        // 3. Number == String -> Number == ToNumber(String).
        if ($x instanceof JsNumber && $y instanceof JsString) {
            return self::abstractEquals($x, new JsNumber(TypeConversion::toNumber($y)));
        }
        if ($x instanceof JsString && $y instanceof JsNumber) {
            return self::abstractEquals(new JsNumber(TypeConversion::toNumber($x)), $y);
        }

        // 4. Boolean == anything -> ToNumber(Boolean) == anything.
        if ($x instanceof JsBoolean) {
            return self::abstractEquals(new JsNumber(TypeConversion::toNumber($x)), $y);
        }
        if ($y instanceof JsBoolean) {
            return self::abstractEquals($x, new JsNumber(TypeConversion::toNumber($y)));
        }

        // 5. Object == String/Number/BigInt/Symbol -> ToPrimitive(Object) == other.
        if (
            $x instanceof JsObject
            && ($y instanceof JsString || $y instanceof JsNumber || $y instanceof JsSymbol
                || $y instanceof JsBigInt)
        ) {
            return self::abstractEquals(TypeConversion::toPrimitive($x), $y);
        }
        if (
            ($x instanceof JsString || $x instanceof JsNumber || $x instanceof JsSymbol
                || $x instanceof JsBigInt)
            && $y instanceof JsObject
        ) {
            return self::abstractEquals($x, TypeConversion::toPrimitive($y));
        }

        // 6. BigInt == String -> StringToBigInt, then compare.
        if ($x instanceof JsBigInt && $y instanceof JsString) {
            $n = self::stringToBigInt($y->value);
            if ($n === null) {
                return false;
            }
            return self::abstractEquals($x, $n);
        }

        // 7. String == BigInt -> reverse.
        if ($x instanceof JsString && $y instanceof JsBigInt) {
            return self::abstractEquals($y, $x);
        }

        // 12. BigInt == Number or Number == BigInt -> compare mathematical values.
        if ($x instanceof JsBigInt && $y instanceof JsNumber) {
            return self::bigIntEqualsNumber($x, $y);
        }
        if ($x instanceof JsNumber && $y instanceof JsBigInt) {
            return self::bigIntEqualsNumber($y, $x);
        }

        // All other combinations -> false.
        return false;
    }

    /**
     * 7.2.15 Strict Equality Comparison (x === y).
     *
     * Different types -> false.
     * NaN !== NaN. +0 === -0.
     * Objects: same reference only.
     */
    public static function strictEquals(JsValue $x, JsValue $y): bool
    {
        // Different types -> false.
        if (!self::sameType($x, $y)) {
            return false;
        }

        // undefined === undefined.
        if ($x instanceof JsUndefined) {
            return true;
        }

        // null === null.
        if ($x instanceof JsNull) {
            return true;
        }

        // Number: NaN !== NaN, +0 === -0.
        if ($x instanceof JsNumber && $y instanceof JsNumber) {
            // NaN is not equal to anything, including itself.
            if (is_nan($x->value) || is_nan($y->value)) {
                return false;
            }

            // +0 === -0 (PHP float comparison handles this correctly).
            return $x->value === $y->value;
        }

        // String: same sequence of code units.
        if ($x instanceof JsString && $y instanceof JsString) {
            return $x->value === $y->value;
        }

        // Boolean: same value.
        if ($x instanceof JsBoolean && $y instanceof JsBoolean) {
            return $x->value === $y->value;
        }

        // Symbol: same identity (reference equality).
        if ($x instanceof JsSymbol && $y instanceof JsSymbol) {
            return $x === $y;
        }

        // BigInt: same numeric value.
        if ($x instanceof JsBigInt && $y instanceof JsBigInt) {
            return $x->value === $y->value;
        }

        // Object: same reference.
        if ($x instanceof JsObject && $y instanceof JsObject) {
            return $x === $y;
        }

        return false;
    }

    /**
     * 7.2.13 Abstract Relational Comparison (x < y).
     *
     * Returns true, false, or null (null means "undefined" per spec, i.e. NaN was involved).
     *
     * leftFirst controls evaluation order:
     * true  -> evaluate left then right (used for <, <=)
     * false -> evaluate right then left (used for >, >=)
     */
    public static function abstractRelational(
        JsValue $x,
        JsValue $y,
        bool $leftFirst = true,
    ): ?bool {
        // Convert to primitives with "number" hint.
        if ($leftFirst) {
            $px = TypeConversion::toPrimitive($x, 'number');
            $py = TypeConversion::toPrimitive($y, 'number');
        } else {
            $py = TypeConversion::toPrimitive($y, 'number');
            $px = TypeConversion::toPrimitive($x, 'number');
        }

        // 3. If both are strings, use lexicographic comparison by UTF-16 code units.
        if ($px instanceof JsString && $py instanceof JsString) {
            return self::utf16LessThan($px->value, $py->value);
        }

        // 4a. BigInt < String: parse the string as BigInt, compare as BigInts.
        if ($px instanceof JsBigInt && $py instanceof JsString) {
            $ny = self::stringToBigInt($py->value);
            if ($ny === null) {
                return null;
            }
            return self::bigIntLessThan($px, $ny);
        }

        // 4b. String < BigInt: parse the string as BigInt, compare as BigInts.
        if ($px instanceof JsString && $py instanceof JsBigInt) {
            $nx = self::stringToBigInt($px->value);
            if ($nx === null) {
                return null;
            }
            return self::bigIntLessThan($nx, $py);
        }

        // 4c. Otherwise use ToNumeric (not ToNumber) to preserve BigInt type.
        $nx = TypeConversion::toNumeric($px);
        $ny = TypeConversion::toNumeric($py);

        // 4d. If same type, use that type's comparison.
        if ($nx instanceof JsBigInt && $ny instanceof JsBigInt) {
            return self::bigIntLessThan($nx, $ny);
        }

        if ($nx instanceof JsNumber && $ny instanceof JsNumber) {
            return self::numberLessThan($nx->value, $ny->value);
        }

        // 4e-4i. Cross-type: one is BigInt, the other is Number.
        if ($nx instanceof JsBigInt && $ny instanceof JsNumber) {
            return self::bigIntLessThanNumber($nx, $ny);
        }

        // Number < BigInt.
        if ($nx instanceof JsNumber && $ny instanceof JsBigInt) {
            return self::numberLessThanBigInt($nx, $ny);
        }

        return null;
    }

    /**
     * Compare two UTF-8 strings using UTF-16 code unit ordering.
     *
     * For strings containing only BMP characters (no supplementary planes),
     * this is equivalent to strcmp. The slow path converts to UTF-16 code units
     * and compares element-wise, handling surrogate pairs correctly.
     */
    private static function utf16LessThan(string $a, string $b): bool
    {
        // Fast path: for pure ASCII, byte comparison is correct.
        if (!preg_match('/[\x80-\xff]/', $a) && !preg_match('/[\x80-\xff]/', $b)) {
            return strcmp($a, $b) < 0;
        }

        // Convert both to arrays of UTF-16 code units.
        $unitsA = self::toUtf16CodeUnits($a);
        $unitsB = self::toUtf16CodeUnits($b);
        $lenA = count($unitsA);
        $lenB = count($unitsB);
        $minLen = min($lenA, $lenB);

        for ($i = 0; $i < $minLen; $i++) {
            if ($unitsA[$i] !== $unitsB[$i]) {
                return $unitsA[$i] < $unitsB[$i];
            }
        }

        return $lenA < $lenB;
    }

    /** @return int[] Array of UTF-16 code unit values. */
    private static function toUtf16CodeUnits(string $utf8): array
    {
        $units = [];
        $len = mb_strlen($utf8, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $cp = mb_ord(mb_substr($utf8, $i, 1, 'UTF-8'), 'UTF-8');
            if ($cp >= 0x10000) {
                // Supplementary character: encode as surrogate pair.
                $cp -= 0x10000;
                $units[] = 0xD800 | ($cp >> 10);
                $units[] = 0xDC00 | ($cp & 0x3FF);
            } else {
                $units[] = $cp;
            }
        }
        return $units;
    }

    /**
     * 13.15.3 ApplyStringOrNumericBinaryOperator: Addition (+).
     *
     * If either operand is a string after ToPrimitive, concatenate.
     * Otherwise, add as numbers.
     */
    public static function add(JsValue $left, JsValue $right): JsValue
    {
        $lprim = TypeConversion::toPrimitive($left);
        $rprim = TypeConversion::toPrimitive($right);

        // If either side is a string, concatenate.
        if ($lprim instanceof JsString || $rprim instanceof JsString) {
            $lstr = TypeConversion::toString($lprim);
            $rstr = TypeConversion::toString($rprim);
            return new JsString($lstr . $rstr);
        }

        // Otherwise numeric addition.
        $lnum = TypeConversion::toNumber($lprim);
        $rnum = TypeConversion::toNumber($rprim);

        return new JsNumber($lnum + $rnum);
    }

    /**
     * 13.5.3 typeof operator.
     *
     * Returns a JsString with the type name.
     */
    public static function typeofOperator(JsValue $value): JsString
    {
        // The typeof() method on each value class returns the correct string,
        // including "object" for null and "function" for callables.
        return new JsString($value->typeof());
    }

    /**
     * 13.10.2 InstanceofOperator(V, target).
     *
     * target must be an object. Checks target[Symbol.hasInstance], then falls
     * back to OrdinaryHasInstance (walking the prototype chain).
     */
    public static function instanceofOperator(JsValue $left, JsValue $right): bool
    {
        if (!$right instanceof JsObject) {
            throw new TypeError('Right-hand side of instanceof is not an object');
        }

        // 13.10.2 step 2: let instOfHandler = GetMethod(C, @@hasInstance).
        // getBySymbol may invoke a getter that throws; let the exception propagate.
        $instOfHandler = $right->getBySymbol(
            \PhpJs\BuiltIn\SymbolConstructor::hasInstance()
        );

        // GetMethod returns undefined for both undefined and null property values.
        // Step 4: if instOfHandler is defined, return ToBoolean(Call(instOfHandler, C, [O])).
        if (!$instOfHandler instanceof JsUndefined && !$instOfHandler instanceof JsNull) {
            if (!$instOfHandler instanceof JsFunction) {
                throw new TypeError('Right-hand side of instanceof is not callable');
            }
            $result = $instOfHandler->call($right, [$left]);
            return TypeConversion::toBoolean($result);
        }

        // OrdinaryHasInstance: right must be callable.
        if (!$right instanceof JsFunction) {
            throw new TypeError('Right-hand side of instanceof is not callable');
        }

        // Left must be an object for prototype chain walking.
        if (!$left instanceof JsObject) {
            return false;
        }

        // Get the prototype property of the constructor.
        $proto = $right->get('prototype');
        if (!$proto instanceof JsObject) {
            throw new TypeError('Function has non-object prototype in instanceof check');
        }

        // Walk the prototype chain of $left.
        $current = $left->getPrototype();
        while ($current !== null) {
            if ($current === $proto) {
                return true;
            }
            $current = $current->getPrototype();
        }

        return false;
    }

    /**
     * 7.2.10 SameValue(x, y).
     *
     * Like strict equality but: NaN is equal to NaN, +0 is not equal to -0.
     * Used by Object.is() and Map key comparison.
     */
    public static function sameValue(JsValue $x, JsValue $y): bool
    {
        if (!self::sameType($x, $y)) {
            return false;
        }

        if ($x instanceof JsNumber && $y instanceof JsNumber) {
            // NaN === NaN under SameValue.
            if (is_nan($x->value) && is_nan($y->value)) {
                return true;
            }

            // +0 !== -0 under SameValue.
            if ($x->value === 0.0 && $y->value === 0.0) {
                return self::isNegativeZero($x->value) === self::isNegativeZero($y->value);
            }

            return $x->value === $y->value;
        }

        // For non-number types, SameValue behaves like strict equality.
        return self::strictEquals($x, $y);
    }

    /**
     * 7.2.11 SameValueZero(x, y).
     *
     * Like SameValue but +0 equals -0.
     * Used by Array.prototype.includes, Set, and Map.
     */
    public static function sameValueZero(JsValue $x, JsValue $y): bool
    {
        if (!self::sameType($x, $y)) {
            return false;
        }

        if ($x instanceof JsNumber && $y instanceof JsNumber) {
            // NaN === NaN under SameValueZero.
            if (is_nan($x->value) && is_nan($y->value)) {
                return true;
            }

            return $x->value === $y->value;
        }

        return self::strictEquals($x, $y);
    }

    /**
     * Check whether two JsValues are the same JS type.
     */
    private static function sameType(JsValue $x, JsValue $y): bool
    {
        // undefined.
        if ($x instanceof JsUndefined && $y instanceof JsUndefined) {
            return true;
        }

        // null.
        if ($x instanceof JsNull && $y instanceof JsNull) {
            return true;
        }

        // boolean.
        if ($x instanceof JsBoolean && $y instanceof JsBoolean) {
            return true;
        }

        // number.
        if ($x instanceof JsNumber && $y instanceof JsNumber) {
            return true;
        }

        // string.
        if ($x instanceof JsString && $y instanceof JsString) {
            return true;
        }

        // symbol.
        if ($x instanceof JsSymbol && $y instanceof JsSymbol) {
            return true;
        }

        // bigint.
        if ($x instanceof JsBigInt && $y instanceof JsBigInt) {
            return true;
        }

        // object (includes functions and arrays since they extend JsObject).
        // Primitive types are checked above, so reaching here means both are objects.
        if ($x instanceof JsObject && $y instanceof JsObject) {
            return true;
        }

        return false;
    }

    /**
     * Detect negative zero using the IEEE 754 sign bit.
     *
     * Uses pack/unpack to inspect the binary representation instead of
     * division, which avoids PHPStan's division-by-zero diagnostic.
     */
    private static function isNegativeZero(float $value): bool
    {
        if ($value !== 0.0) {
            return false;
        }

        // Pack as big-endian double (8 bytes). The sign bit is the MSB.
        $packed = pack('E', $value);
        $bytes = unpack('C8', $packed);

        return $bytes !== false && ($bytes[1] & 0x80) !== 0;
    }

    /**
     * Number less-than comparison per spec.
     *
     * Returns true, false, or null (NaN involved).
     */
    private static function numberLessThan(float $x, float $y): ?bool
    {
        if (is_nan($x) || is_nan($y)) {
            return null;
        }

        if ($x === $y) {
            return false;
        }

        if ($x === INF) {
            return false;
        }
        if ($y === INF) {
            return true;
        }
        if ($y === -INF) {
            return false;
        }
        if ($x === -INF) {
            return true;
        }

        return $x < $y;
    }

    /**
     * BigInt::lessThan(x, y) per spec.
     *
     * Both operands are BigInt. Uses bcmath for arbitrary precision comparison.
     */
    private static function bigIntLessThan(JsBigInt $x, JsBigInt $y): bool
    {
        return self::bigStrComp(self::bigIntToDecimal($x->value), self::bigIntToDecimal($y->value)) < 0;
    }

    /**
     * BigInt < Number cross-type comparison per spec steps 4e-4h.
     *
     * Returns true, false, or null (NaN yields undefined).
     */
    private static function bigIntLessThanNumber(JsBigInt $bigint, JsNumber $num): ?bool
    {
        // 4e. If the Number is NaN, return undefined.
        if (is_nan($num->value)) {
            return null;
        }

        // 4f/4g. Infinity checks.
        if ($num->value === INF) {
            return true;
        }
        if ($num->value === -INF) {
            return false;
        }

        return self::compareBigIntToFloat(self::bigIntToDecimal($bigint->value), $num->value) < 0;
    }

    /**
     * Number < BigInt cross-type comparison per spec steps 4e-4h.
     *
     * Returns true, false, or null (NaN yields undefined).
     */
    private static function numberLessThanBigInt(JsNumber $num, JsBigInt $bigint): ?bool
    {
        if (is_nan($num->value)) {
            return null;
        }

        if ($num->value === -INF) {
            return true;
        }
        if ($num->value === INF) {
            return false;
        }

        return self::compareBigIntToFloat(self::bigIntToDecimal($bigint->value), $num->value) > 0;
    }

    /**
     * BigInt == Number comparison per spec step 12.
     *
     * NaN and infinities are never equal to any BigInt.
     * Otherwise compare mathematical values.
     */
    private static function bigIntEqualsNumber(JsBigInt $bigint, JsNumber $num): bool
    {
        // 12a. NaN or infinity -> false.
        if (is_nan($num->value) || is_infinite($num->value)) {
            return false;
        }

        // A non-integer float can never equal a BigInt.
        if ($num->value !== floor($num->value)) {
            return false;
        }

        return self::compareBigIntToFloat(self::bigIntToDecimal($bigint->value), $num->value) === 0;
    }

    /**
     * Compare a BigInt (as bcmath string) to a float, returning -1, 0, or 1.
     *
     * This handles the precision gap between IEEE 754 doubles and arbitrary-precision
     * integers. For floats that are exact integers within the safe integer range,
     * a direct bcmath comparison suffices. For larger floats, we convert the float
     * to its exact integer representation via sprintf and use bcmath.
     */
    private static function compareBigIntToFloat(string $bigintStr, float $floatVal): int
    {
        // If the float is not an integer value, compare the BigInt to the floor and ceil.
        if ($floatVal !== floor($floatVal)) {
            $floored = floor($floatVal);
            $flooredStr = self::floatToExactIntString($floored);
            $cmpFloor = self::bigStrComp($bigintStr, $flooredStr);
            if ($cmpFloor <= 0) {
                // bigint <= floor(float) means bigint < float.
                return -1;
            }
            // bigint > floor(float) means bigint >= ceil(float) means bigint > float.
            return 1;
        }

        // The float is an exact integer. Convert to its exact decimal string.
        $floatStr = self::floatToExactIntString($floatVal);
        return self::bigStrComp($bigintStr, $floatStr);
    }

    /**
     * Convert an integer-valued float to its exact decimal string representation.
     *
     * sprintf('%.0f', $v) works for floats that are exact integers up to about 1e308.
     * This is needed because (float) casting to (string) uses scientific notation for
     * large values, which bcmath cannot parse.
     */
    private static function floatToExactIntString(float $value): string
    {
        if ($value === 0.0) {
            return '0';
        }

        $negative = $value < 0;
        $value = abs($value);

        // For values within PHP int range, direct cast is exact.
        if ($value <= PHP_INT_MAX) {
            $str = (string) (int) $value;
            return $negative ? '-' . $str : $str;
        }

        // For larger values, use sprintf to get the full decimal representation
        // without scientific notation.
        $str = sprintf('%.0f', $value);
        return $negative ? '-' . $str : $str;
    }

    /**
     * 7.1.14 StringToBigInt(string).
     *
     * Attempts to parse a string as a BigInt value.
     * Returns a JsBigInt if the string is a valid integer representation,
     * or null if it cannot be parsed (equivalent to NaN in the spec).
     *
     * Supports decimal integers, hex (0x), octal (0o), and binary (0b) prefixes.
     * Does not support fractional parts, exponents, or trailing 'n'.
     */
    private static function stringToBigInt(string $value): ?JsBigInt
    {
        $trimmed = TypeConversion::trimWhitespace($value);

        // Empty string -> 0n.
        if ($trimmed === '') {
            return new JsBigInt('0');
        }

        // "-0" -> 0n (BigInt has no negative zero).
        if ($trimmed === '-0') {
            return new JsBigInt('0');
        }

        // Hex: 0x or 0X prefix.
        if (preg_match('/^0[xX]([0-9a-fA-F]+)$/', $trimmed, $matches) === 1) {
            $dec = self::hexToBigIntString($matches[1]);
            return $dec !== null ? new JsBigInt($dec) : null;
        }

        // Octal: 0o or 0O prefix.
        if (preg_match('/^0[oO]([0-7]+)$/', $trimmed, $matches) === 1) {
            $dec = self::baseToBigIntString($matches[1], 8);
            return $dec !== null ? new JsBigInt($dec) : null;
        }

        // Binary: 0b or 0B prefix.
        if (preg_match('/^0[bB]([01]+)$/', $trimmed, $matches) === 1) {
            $dec = self::baseToBigIntString($matches[1], 2);
            return $dec !== null ? new JsBigInt($dec) : null;
        }

        // Decimal integer: optional sign, digits only. No decimal points, no exponents.
        if (preg_match('/^[+-]?[0-9]+$/', $trimmed) === 1) {
            // Normalize: strip leading zeros but preserve sign.
            $sign = '';
            $digits = $trimmed;
            if ($digits[0] === '+') {
                $digits = substr($digits, 1);
            } elseif ($digits[0] === '-') {
                $sign = '-';
                $digits = substr($digits, 1);
            }
            $digits = ltrim($digits, '0') ?: '0';
            $result = $sign . $digits;
            // "-0" normalizes to "0".
            if ($result === '-0') {
                $result = '0';
            }
            return new JsBigInt($result);
        }

        // Anything else (decimals, exponents, "Infinity", non-numeric) -> null (NaN).
        return null;
    }

    /**
     * Normalize a BigInt value string to a decimal string for bcmath.
     *
     * JsBigInt may store hex (0x...), octal (0o...), or binary (0b...) notation.
     * This converts any such notation to a pure decimal string that bcmath can handle.
     */
    private static function bigIntToDecimal(string $value): string
    {
        // Handle negative prefix.
        $negative = false;
        $v = $value;
        if ($v !== '' && $v[0] === '-') {
            $negative = true;
            $v = substr($v, 1);
        }

        // Hex: 0x or 0X.
        if (preg_match('/^0[xX]([0-9a-fA-F]+)$/', $v, $matches) === 1) {
            $dec = self::hexToBigIntString($matches[1]);
            return $negative ? '-' . $dec : ($dec ?? '0');
        }

        // Octal: 0o or 0O.
        if (preg_match('/^0[oO]([0-7]+)$/', $v, $matches) === 1) {
            $dec = self::baseToBigIntString($matches[1], 8);
            return $negative ? '-' . $dec : ($dec ?? '0');
        }

        // Binary: 0b or 0B.
        if (preg_match('/^0[bB]([01]+)$/', $v, $matches) === 1) {
            $dec = self::baseToBigIntString($matches[1], 2);
            return $negative ? '-' . $dec : ($dec ?? '0');
        }

        // Already decimal (or zero). Return as is.
        return $value;
    }

    /**
     * Convert a hex digit string to a decimal string (pure PHP).
     */
    private static function hexToBigIntString(string $hex): ?string
    {
        $result = '0';
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i++) {
            $result = self::bigStrMulAddSmall($result, 16, (int) hexdec($hex[$i]));
        }
        return $result;
    }

    /**
     * Convert a digit string in a given base (2 or 8) to a decimal string (pure PHP).
     */
    private static function baseToBigIntString(string $digits, int $base): ?string
    {
        $result = '0';
        $len = strlen($digits);
        for ($i = 0; $i < $len; $i++) {
            $result = self::bigStrMulAddSmall($result, $base, (int) $digits[$i]);
        }
        return $result;
    }

    /** Public wrapper for signed BigInt decimal string comparison (for external callers). */
    public static function bigStrCompPublic(string $a, string $b): int
    {
        return self::bigStrComp($a, $b);
    }

    /** Compare two non-negative decimal strings. Returns -1, 0, 1. */
    private static function bigStrCompUnsigned(string $a, string $b): int
    {
        $la = strlen($a);
        $lb = strlen($b);
        if ($la !== $lb) {
            return $la < $lb ? -1 : 1;
        }
        return strcmp($a, $b) <=> 0;
    }

    /** Signed decimal string comparison. Returns -1, 0, 1. */
    private static function bigStrComp(string $a, string $b): int
    {
        $aNeg = isset($a[0]) && $a[0] === '-';
        $bNeg = isset($b[0]) && $b[0] === '-';
        if ($aNeg !== $bNeg) {
            return $aNeg ? -1 : 1;
        }
        $cmp = self::bigStrCompUnsigned(ltrim($a, '-'), ltrim($b, '-'));
        return $aNeg ? -$cmp : $cmp;
    }

    /**
     * Multiply non-negative decimal string by small integer, then add another small integer.
     * Replaces the bcadd(bcmul($result, $base), $digit) pattern in base conversion loops.
     */
    private static function bigStrMulAddSmall(string $a, int $mul, int $add): string
    {
        if ($a === '0' && $add === 0) {
            return '0';
        }
        $carry = $add;
        $result = '';
        for ($i = strlen($a) - 1; $i >= 0; $i--) {
            $prod = (int) $a[$i] * $mul + $carry;
            $carry = intdiv($prod, 10);
            $result = ($prod % 10) . $result;
        }
        while ($carry > 0) {
            $result = ($carry % 10) . $result;
            $carry = intdiv($carry, 10);
        }
        return $result !== '' ? $result : '0';
    }
}
