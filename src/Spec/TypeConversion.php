<?php

declare(strict_types=1);

namespace Phasis\Spec;

use Phasis\Exceptions\TypeError;
use Phasis\Value\JsBigInt;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

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
            $toPrimSym = \Phasis\BuiltIn\SymbolConstructor::toPrimitive();
            $exoticToPrim = $value->getBySymbol($toPrimSym);
            if (!$exoticToPrim instanceof JsUndefined && !$exoticToPrim instanceof JsNull) {
                if (
                    !$exoticToPrim instanceof JsFunction
                    && !($exoticToPrim instanceof \Phasis\Value\JsProxy && $exoticToPrim->isCallable())
                ) {
                    throw new TypeError('Symbol.toPrimitive is not a function');
                }
                $hintStr = new \Phasis\Value\JsString($hint);
                $result = $exoticToPrim instanceof \Phasis\Value\JsProxy
                    ? $exoticToPrim->apply($value, [$hintStr])
                    : $exoticToPrim->call($value, [$hintStr]);
                if ($result instanceof JsObject) {
                    throw new TypeError('Cannot convert object to primitive value');
                }
                return $result;
            }

            // Spec OrdinaryToPrimitive (§7.1.1.1): when called from ToPrimitive
            // with a "default" hint and no @@toPrimitive method, treat hint as
            // "number". Date's "default → string" behavior comes from its own
            // @@toPrimitive (handled above), not from OrdinaryToPrimitive — so
            // deleting Date.prototype[@@toPrimitive] flips Date addition to
            // valueOf-first, matching V8.
            if ($hint === 'default') {
                $hint = 'number';
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
                } elseif ($method instanceof \Phasis\Value\JsProxy && $method->isCallable()) {
                    // Per spec, IsCallable accepts callable Proxy. The proxy
                    // routes the invocation through its apply trap so toString
                    // / valueOf overrides remain observable.
                    $result = $method->apply($value, []);
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
        return JsNumber::of(self::toNumber($primValue));
    }

    /**
     * 7.1.13 ToBigInt(argument).
     *
     * Converts a value to BigInt per the spec algorithm.
     * Throws TypeError for undefined, null, Number, Symbol.
     * Throws SyntaxError for non-parseable strings.
     */
    public static function toBigInt(JsValue $value): JsBigInt
    {
        $prim = self::toPrimitive($value, 'number');

        if ($prim instanceof JsBigInt) {
            return $prim;
        }
        if ($prim instanceof JsBoolean) {
            return new JsBigInt($prim->toBoolean() ? '1' : '0');
        }
        if ($prim instanceof JsString) {
            return self::stringToBigInt($prim->toJsString());
        }
        if ($prim instanceof JsUndefined) {
            throw new TypeError('Cannot convert undefined to a BigInt');
        }
        if ($prim instanceof JsNull) {
            throw new TypeError('Cannot convert null to a BigInt');
        }
        if ($prim instanceof JsNumber) {
            throw new TypeError('Cannot convert a Number value to a BigInt');
        }
        if ($prim instanceof JsSymbol) {
            throw new TypeError('Cannot convert a Symbol value to a BigInt');
        }

        throw new TypeError('Cannot convert value to a BigInt');
    }

    /**
     * StringToBigInt(argument) per spec 7.1.14.
     *
     * Parses a string as a BigInt integer literal.
     * Empty / whitespace-only strings return 0n per spec.
     * Returns a JsBigInt with a decimal value string (no 0x/0o/0b prefix).
     * Throws SyntaxError for invalid input.
     */
    public static function stringToBigInt(string $s): JsBigInt
    {
        $trimmed = trim($s);
        if ($trimmed === '') {
            return new JsBigInt('0');
        }

        // Non-decimal prefixes (0x, 0o, 0b) cannot have a sign prefix per spec.
        // Hex: 0x... or 0X...
        if (preg_match('/^0[xX]([0-9a-fA-F]+)$/', $trimmed, $m)) {
            return new JsBigInt(self::bigBaseToDecimal($m[1], 16));
        }

        // Octal: 0o... or 0O...
        if (preg_match('/^0[oO]([0-7]+)$/', $trimmed, $m)) {
            return new JsBigInt(self::bigBaseToDecimal($m[1], 8));
        }

        // Binary: 0b... or 0B...
        if (preg_match('/^0[bB]([01]+)$/', $trimmed, $m)) {
            return new JsBigInt(self::bigBaseToDecimal($m[1], 2));
        }

        // Decimal (optionally signed): digits only (no decimal point, no exponent).
        if (preg_match('/^-?[0-9]+$/', $trimmed)) {
            $negative = $trimmed[0] === '-';
            $digits = $negative ? substr($trimmed, 1) : $trimmed;
            $dec = ltrim($digits, '0') ?: '0';
            if ($dec === '0') {
                return new JsBigInt('0');
            }
            return new JsBigInt($negative ? '-' . $dec : $dec);
        }

        throw new \Phasis\Exceptions\SyntaxError("Cannot convert \"{$trimmed}\" to a BigInt");
    }

    /**
     * Convert digit string in given base (2, 8, or 16) to decimal string (pure PHP).
     */
    private static function bigBaseToDecimal(string $digits, int $base): string
    {
        $result = '0';
        for ($i = 0; $i < strlen($digits); $i++) {
            $d = ($base === 16) ? (int) hexdec($digits[$i]) : (int) $digits[$i];
            $result = self::bigMulAddSmall($result, $base, $d);
        }
        return $result;
    }

    /** Multiply non-negative decimal string by small int, then add another small int. */
    private static function bigMulAddSmall(string $a, int $mul, int $add): string
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
        // The optional-chain short-circuit sentinel reaches the spec's value
        // semantics layer as a regular `undefined`. Treat it identically here
        // so that string coercion via template literals, String(), `+`, etc.
        // produces "undefined" rather than the sentinel's empty fallback.
        if ($value instanceof \Phasis\Value\JsOptionalUndefined) {
            return 'undefined';
        }
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

        // Create a wrapper object with the primitive stored internally,
        // linked to the appropriate prototype if available.
        //
        // Resolve the prototype from the active realm's constructor first
        // (mirroring JsArray::__construct). The static caches on JsBoolean /
        // JsNumber / JsString are last-write-wins: when a child realm is
        // built via $262.createRealm(), its install() overwrites the static
        // with the child's prototype. Subsequent toObject() calls back in
        // the parent would then create wrappers whose [[Prototype]] points
        // at the CHILD realm's Number.prototype, so `wrapper instanceof
        // Number` (using the parent's Number) returns false. This breaks
        // assert.deepEqual's isBoxed() check (staging/sm/TypedArray/
        // every-and-some.js calls `assert.deepEqual(this, Object(1))` in
        // the callback of arr.every(cb, 1), expecting both sides to be
        // detected as boxed Number wrappers and compared by valueOf).
        $wrapperProto = null;
        if ($value instanceof JsBoolean) {
            $wrapperProto = self::resolveRealmPrototype('Boolean') ?? JsBoolean::getBooleanPrototype();
        } elseif ($value instanceof JsNumber) {
            $wrapperProto = self::resolveRealmPrototype('Number') ?? JsNumber::getNumberPrototype();
        } elseif ($value instanceof JsString) {
            $wrapperProto = self::resolveRealmPrototype('String') ?? JsString::getStringPrototype();
        }
        $wrapper = new JsObject($wrapperProto);
        $wrapper->defineOwnProperty(
            '[[PrimitiveValue]]',
            \Phasis\Object\PropertyDescriptor::data($value, false, false, false),
        );

        // Per spec, valueOf is inherited from the prototype (e.g. Boolean.prototype.valueOf),
        // not installed as an own property on wrapper objects. Only install a local valueOf
        // if no prototype chain is available.
        if (
            ($value instanceof JsBoolean || $value instanceof JsNumber || $value instanceof JsString)
            && $wrapperProto === null
        ) {
            $wrapper->defineOwnProperty('valueOf', \Phasis\Object\PropertyDescriptor::data(
                JsFunction::fromCallable('valueOf', fn() => $value, 0),
                true,
                false,
                true,
            ));
        }

        if ($value instanceof JsString) {
            // String exotic objects expose each UTF-16 code unit as an indexed
            // enumerable property (spec §10.4.3) — surrogate pairs decompose
            // into two distinct items so Array.from(string) without an
            // @@iterator returns each code unit separately.
            $str = $value->value;
            $u16 = JsString::utf8ToUtf16LE($str);
            $len = (int) (strlen($u16) / 2);
            for ($i = 0; $i < $len; $i++) {
                $codeUnit = ord($u16[$i * 2]) | (ord($u16[$i * 2 + 1]) << 8);
                $ch = JsString::utf16CodeUnitToUtf8($codeUnit);
                $wrapper->defineOwnProperty((string) $i, \Phasis\Object\PropertyDescriptor::data(
                    new JsString($ch),
                    false,
                    true,
                    false,
                ));
            }
            $wrapper->defineOwnProperty('length', \Phasis\Object\PropertyDescriptor::data(
                JsNumber::of((float) $len),
                false,
                false,
                false,
            ));
        }

        // BigInt wrappers: store [[BigIntData]] and link to BigInt.prototype per spec 21.2.4.
        if ($value instanceof JsBigInt) {
            $wrapper->defineOwnProperty(
                '[[BigIntData]]',
                \Phasis\Object\PropertyDescriptor::data($value, false, false, false),
            );
            $bigIntProto = JsBigInt::getPrototype();
            if ($bigIntProto !== null) {
                $wrapper->setPrototype($bigIntProto);
            }
        }

        // Symbol wrappers get Symbol.prototype
        if ($value instanceof JsSymbol) {
            $symProto = self::resolveRealmPrototype('Symbol') ?? JsSymbol::getSymbolPrototype();
            if ($symProto !== null) {
                $wrapper->setPrototype($symProto);
            }
        }

        return $wrapper;
    }

    /**
     * Look up the [[Prototype]] object that the active realm's constructor
     * exposes as `<name>.prototype`. Returns null when no interpreter is
     * active, the global env has no such binding, or the binding is not a
     * constructor function. Used by toObject to make primitive boxing
     * (`Object(1)`, `Object("s")`, …) hand back wrappers whose prototype
     * chain matches whichever realm is currently executing, even when a
     * child realm built via $262.createRealm() has clobbered the global
     * JsNumber / JsString / JsBoolean / JsSymbol static prototype caches.
     */
    private static function resolveRealmPrototype(string $ctorName): ?JsObject
    {
        $interp = \Phasis\Engine::getCurrentInterpreter();
        if ($interp === null) {
            return null;
        }
        $env = $interp->getGlobalEnv();
        if (!$env->has($ctorName)) {
            return null;
        }
        $ctor = $env->get($ctorName);
        if (!$ctor instanceof JsObject) {
            return null;
        }
        $proto = $ctor->get('prototype');
        return $proto instanceof JsObject ? $proto : null;
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
            throw new \Phasis\Exceptions\RangeError('Invalid index');
        }

        $index = (int) min($integerIndex, 9007199254740991.0);

        if ((float) $index !== $integerIndex) {
            throw new \Phasis\Exceptions\RangeError('Invalid index');
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
        // PHP's intval does not recognize the 0o prefix, so strip it.
        if (preg_match('/^0[oO]([0-7]+)$/', $trimmed, $octalMatch) === 1) {
            return (float) intval($octalMatch[1], 8);
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
     * TAB (U+0009), VT (U+000B), FF (U+000C), SP (U+0020), NBSP (U+00A0),
     * BOM/ZWNBSP (U+FEFF), and Unicode "Space_Separator" (Zs) category
     * (EXCLUDING U+180E which was removed from Zs in Unicode 6.3),
     * plus LF (U+000A), CR (U+000D), LS (U+2028), PS (U+2029).
     */
    public static function trimWhitespace(string $value): string
    {
        // Use Unicode-aware regex (u flag) with explicit codepoints.
        // U+180E is NOT included per modern ECMAScript (removed from Zs in Unicode 6.3).
        $ws = '[\x{0009}\x{000A}\x{000B}\x{000C}\x{000D}\x{0020}\x{00A0}'
            . '\x{1680}'            // U+1680 Ogham Space Mark (Zs category)
            . '\x{2000}-\x{200A}'   // U+2000..U+200A (en/em spaces etc.)
            . '\x{2028}'            // U+2028 Line Separator
            . '\x{2029}'            // U+2029 Paragraph Separator
            . '\x{202F}'            // U+202F Narrow No-Break Space
            . '\x{205F}'            // U+205F Medium Mathematical Space
            . '\x{3000}'            // U+3000 Ideographic Space
            . '\x{FEFF}'            // U+FEFF BOM (Zero Width No-Break Space)
            . ']';

        // Trim leading.
        $value = preg_replace('/^' . $ws . '+/u', '', $value) ?? $value;

        // Trim trailing.
        return preg_replace('/' . $ws . '+$/u', '', $value) ?? $value;
    }
}
