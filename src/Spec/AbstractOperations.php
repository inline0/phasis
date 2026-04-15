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

        // 5. Object == String/Number/Symbol -> ToPrimitive(Object) == other.
        if (
            $x instanceof JsObject
            && ($y instanceof JsString || $y instanceof JsNumber || $y instanceof JsSymbol)
        ) {
            return self::abstractEquals(TypeConversion::toPrimitive($x), $y);
        }
        if (
            ($x instanceof JsString || $x instanceof JsNumber || $x instanceof JsSymbol)
            && $y instanceof JsObject
        ) {
            return self::abstractEquals($x, TypeConversion::toPrimitive($y));
        }

        // 6. All other combinations -> false.
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

        // If both are strings, use lexicographic comparison.
        if ($px instanceof JsString && $py instanceof JsString) {
            $cmp = strcmp($px->value, $py->value);
            return $cmp < 0;
        }

        // Otherwise, convert both to numbers and compare.
        $nx = TypeConversion::toNumber($px);
        $ny = TypeConversion::toNumber($py);

        // NaN compared to anything -> undefined (null here).
        if (is_nan($nx) || is_nan($ny)) {
            return null;
        }

        // +0 and -0 are equal.
        if ($nx === $ny) {
            return false;
        }

        // +Infinity is greater than everything.
        if ($nx === INF) {
            return false;
        }
        // -Infinity is less than everything.
        if ($ny === INF) {
            return true;
        }

        if ($ny === -INF) {
            return false;
        }
        if ($nx === -INF) {
            return true;
        }

        return $nx < $ny;
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
}
