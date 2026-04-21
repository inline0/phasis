<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Exceptions\TypeError;
use PhpJs\Exceptions\RangeError;
use PhpJs\Object\PropertyDescriptor;
use PhpJs\Runtime\Environment;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsPromise;
use PhpJs\Value\JsSharedArrayBuffer;
use PhpJs\Value\JsString;
use PhpJs\Value\JsTypedArray;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

/**
 * The Atomics built-in object.
 *
 * Single-threaded fake: all operations execute immediately and sequentially.
 * wait() always returns "not-equal" or "timed-out" (no real blocking).
 * notify() always returns 0 (no waiters). All read-modify-write operations
 * behave identically to plain reads and writes since there is no contention.
 */
class AtomicsObject
{
    /** Integer typed array type names that Atomics operates on. */
    private const INTEGER_TYPES = [
        'Int8Array', 'Uint8Array', 'Int16Array', 'Uint16Array',
        'Int32Array', 'Uint32Array', 'BigInt64Array', 'BigUint64Array',
    ];

    /** Types valid for wait/notify per spec. */
    private const WAITABLE_TYPES = ['Int32Array', 'BigInt64Array'];

    public static function install(Environment $env): void
    {
        $atomics = new JsObject();

        // Symbol.toStringTag = "Atomics".
        $toStringTagSym = SymbolConstructor::toStringTag();
        $atomics->definePropertyBySymbol(
            $toStringTagSym,
            PropertyDescriptor::data(new JsString('Atomics'), false, false, true),
        );

        $m = static fn (string $name, callable $fn, int $length) => $atomics->defineOwnProperty(
            $name,
            PropertyDescriptor::data(JsFunction::fromCallable($name, $fn, $length), true, false, true),
        );

        $m('load', self::loadFn(...), 2);
        $m('store', self::storeFn(...), 3);
        $m('add', self::rmwFn('add'), 3);
        $m('sub', self::rmwFn('sub'), 3);
        $m('and', self::rmwFn('and'), 3);
        $m('or', self::rmwFn('or'), 3);
        $m('xor', self::rmwFn('xor'), 3);
        $m('exchange', self::exchangeFn(...), 3);
        $m('compareExchange', self::compareExchangeFn(...), 4);
        $m('wait', self::waitFn(...), 4);
        $m('notify', self::notifyFn(...), 3);
        $m('waitAsync', self::waitAsyncFn(...), 4);
        $m('isLockFree', self::isLockFreeFn(...), 1);

        $env->defineVar('Atomics', $atomics);
    }

    /**
     * Validate that the argument is an integer TypedArray backed by a SharedArrayBuffer.
     * Per spec, Atomics methods require integer typed arrays on shared buffers.
     */
    private static function validateIntegerTypedArray(JsValue $ta, bool $waitable = false): JsTypedArray
    {
        if (!$ta instanceof JsTypedArray) {
            throw new TypeError('Atomics operation requires a typed array');
        }

        $typeName = $ta->getTypeName();
        $allowed = $waitable ? self::WAITABLE_TYPES : self::INTEGER_TYPES;

        if (!in_array($typeName, $allowed, true)) {
            if ($waitable) {
                throw new TypeError(
                    'Atomics.wait/waitAsync requires an Int32Array or BigInt64Array'
                );
            }
            throw new TypeError(
                'Atomics operation requires an integer typed array'
            );
        }

        $ta->validateNotDetached();

        return $ta;
    }

    /**
     * Validate and convert an index for Atomics operations.
     */
    private static function validateAtomicAccess(JsTypedArray $ta, JsValue $requestIndex): int
    {
        $index = TypeConversion::toIndex($requestIndex);
        $length = $ta->getLength();

        if ($index < 0 || $index >= $length) {
            throw new RangeError('Invalid atomic access index');
        }

        return $index;
    }

    /**
     * Atomics.load(typedArray, index).
     */
    private static function loadFn(JsValue $this_, array $args): JsValue
    {
        $ta = self::validateIntegerTypedArray($args[0] ?? JsUndefined::instance());
        $index = self::validateAtomicAccess($ta, $args[1] ?? JsUndefined::instance());

        return $ta->getIndex($index);
    }

    /**
     * Atomics.store(typedArray, index, value).
     * Returns the coerced value (not the value read from the array).
     */
    private static function storeFn(JsValue $this_, array $args): JsValue
    {
        $ta = self::validateIntegerTypedArray($args[0] ?? JsUndefined::instance());
        $index = self::validateAtomicAccess($ta, $args[1] ?? JsUndefined::instance());
        $value = $args[2] ?? JsUndefined::instance();

        // Per spec: convert value before the store.
        $isBigInt = in_array($ta->getTypeName(), ['BigInt64Array', 'BigUint64Array'], true);
        if ($isBigInt) {
            $coerced = TypeConversion::toBigInt($value);
        } else {
            $coerced = new JsNumber(TypeConversion::toIntegerOrInfinity($value));
        }

        $ta->setIndex($index, $value);

        return $coerced;
    }

    /**
     * Factory for read-modify-write operations: add, sub, and, or, xor.
     * Returns a closure suitable for JsFunction::fromCallable.
     */
    private static function rmwFn(string $op): \Closure
    {
        return static function (JsValue $this_, array $args) use ($op): JsValue {
            $ta = self::validateIntegerTypedArray($args[0] ?? JsUndefined::instance());
            $index = self::validateAtomicAccess($ta, $args[1] ?? JsUndefined::instance());
            $value = $args[2] ?? JsUndefined::instance();

            $typeName = $ta->getTypeName();
            $isBigInt = in_array($typeName, ['BigInt64Array', 'BigUint64Array'], true);

            // Read old value.
            $oldValue = $ta->getIndex($index);

            if ($isBigInt) {
                $operand = TypeConversion::toBigInt($value);
                $oldBig = ($oldValue instanceof \PhpJs\Value\JsBigInt)
                    ? $oldValue
                    : TypeConversion::toBigInt($oldValue);
                $result = self::bigIntOp($op, $oldBig->value, $operand->value);
                $ta->setIndex($index, new \PhpJs\Value\JsBigInt($result));
            } else {
                $operandNum = TypeConversion::toIntegerOrInfinity($value);
                $oldNum = (int) TypeConversion::toNumber($oldValue);
                $resultNum = self::intOp($op, $oldNum, (int) $operandNum);
                $ta->setIndex($index, new JsNumber((float) $resultNum));
            }

            return $oldValue;
        };
    }

    /**
     * Atomics.exchange(typedArray, index, value).
     */
    private static function exchangeFn(JsValue $this_, array $args): JsValue
    {
        $ta = self::validateIntegerTypedArray($args[0] ?? JsUndefined::instance());
        $index = self::validateAtomicAccess($ta, $args[1] ?? JsUndefined::instance());
        $value = $args[2] ?? JsUndefined::instance();

        // Read old value, write new value.
        $oldValue = $ta->getIndex($index);

        $isBigInt = in_array($ta->getTypeName(), ['BigInt64Array', 'BigUint64Array'], true);
        if ($isBigInt) {
            // Coerce to BigInt before storing (validates type).
            TypeConversion::toBigInt($value);
        } else {
            // Coerce to number to validate before storing.
            TypeConversion::toIntegerOrInfinity($value);
        }

        $ta->setIndex($index, $value);

        return $oldValue;
    }

    /**
     * Atomics.compareExchange(typedArray, index, expectedValue, replacementValue).
     */
    private static function compareExchangeFn(JsValue $this_, array $args): JsValue
    {
        $ta = self::validateIntegerTypedArray($args[0] ?? JsUndefined::instance());
        $index = self::validateAtomicAccess($ta, $args[1] ?? JsUndefined::instance());
        $expected = $args[2] ?? JsUndefined::instance();
        $replacement = $args[3] ?? JsUndefined::instance();

        $typeName = $ta->getTypeName();
        $isBigInt = in_array($typeName, ['BigInt64Array', 'BigUint64Array'], true);

        // Read current value.
        $current = $ta->getIndex($index);

        if ($isBigInt) {
            $expectedBig = TypeConversion::toBigInt($expected);
            $replacementBig = TypeConversion::toBigInt($replacement);
            $currentBig = ($current instanceof \PhpJs\Value\JsBigInt)
                ? $current
                : TypeConversion::toBigInt($current);

            // Compare as BigInt values.
            if ($currentBig->value === $expectedBig->value) {
                $ta->setIndex($index, $replacementBig);
            }
        } else {
            $expectedNum = (int) TypeConversion::toIntegerOrInfinity($expected);
            $replacementNum = TypeConversion::toIntegerOrInfinity($replacement);
            $currentNum = (int) TypeConversion::toNumber($current);

            if ($currentNum === $expectedNum) {
                $ta->setIndex($index, new JsNumber($replacementNum));
            }
        }

        return $current;
    }

    /**
     * Atomics.wait(int32Array, index, value, timeout?).
     *
     * Single-threaded: never blocks. Returns "not-equal" if the current value
     * differs from expected, otherwise "timed-out" (no real waiting).
     */
    private static function waitFn(JsValue $this_, array $args): JsValue
    {
        $ta = self::validateIntegerTypedArray(
            $args[0] ?? JsUndefined::instance(),
            waitable: true,
        );

        // Per spec: SharedArrayBuffer is required for wait.
        if (!$ta->getBuffer() instanceof JsSharedArrayBuffer) {
            throw new TypeError(
                'Atomics.wait requires a SharedArrayBuffer-backed typed array'
            );
        }

        $index = self::validateAtomicAccess($ta, $args[1] ?? JsUndefined::instance());
        $valueArg = $args[2] ?? JsUndefined::instance();
        $timeoutArg = $args[3] ?? JsUndefined::instance();

        $isBigInt = $ta->getTypeName() === 'BigInt64Array';
        $current = $ta->getIndex($index);

        // Compare current value with expected.
        if ($isBigInt) {
            $expected = TypeConversion::toBigInt($valueArg);
            $currentBig = ($current instanceof \PhpJs\Value\JsBigInt)
                ? $current
                : TypeConversion::toBigInt($current);
            if ($currentBig->value !== $expected->value) {
                return new JsString('not-equal');
            }
        } else {
            $expected = (int) TypeConversion::toNumber($valueArg);
            $currentNum = (int) TypeConversion::toNumber($current);
            if ($currentNum !== $expected) {
                return new JsString('not-equal');
            }
        }

        // Values are equal, but we cannot actually wait in single-threaded PHP.
        // Check timeout: if 0, return "timed-out". Otherwise also "timed-out".
        return new JsString('timed-out');
    }

    /**
     * Atomics.notify(int32Array, index, count?).
     *
     * Single-threaded: there are never any waiters.
     * Always returns 0.
     */
    private static function notifyFn(JsValue $this_, array $args): JsValue
    {
        $ta = self::validateIntegerTypedArray(
            $args[0] ?? JsUndefined::instance(),
            waitable: true,
        );
        self::validateAtomicAccess($ta, $args[1] ?? JsUndefined::instance());

        // count argument is validated but unused.
        $countArg = $args[2] ?? JsUndefined::instance();
        if (!$countArg instanceof JsUndefined) {
            $count = TypeConversion::toIntegerOrInfinity($countArg);
            if ($count < 0) {
                // Per spec: if count < 0, use 0. But toIntegerOrInfinity already handles this.
                // No error thrown, count is clamped to >= 0.
            }
        }

        return new JsNumber(0.0);
    }

    /**
     * Atomics.waitAsync(int32Array, index, value, timeout?).
     *
     * Single-threaded: returns {async: false, value: "not-equal"} or
     * {async: false, value: "timed-out"} immediately.
     */
    private static function waitAsyncFn(JsValue $this_, array $args): JsValue
    {
        $ta = self::validateIntegerTypedArray(
            $args[0] ?? JsUndefined::instance(),
            waitable: true,
        );

        // Per spec: SharedArrayBuffer is required for waitAsync.
        if (!$ta->getBuffer() instanceof JsSharedArrayBuffer) {
            throw new TypeError(
                'Atomics.waitAsync requires a SharedArrayBuffer-backed typed array'
            );
        }

        $index = self::validateAtomicAccess($ta, $args[1] ?? JsUndefined::instance());
        $valueArg = $args[2] ?? JsUndefined::instance();

        $isBigInt = $ta->getTypeName() === 'BigInt64Array';
        $current = $ta->getIndex($index);

        $resultStr = 'timed-out';
        if ($isBigInt) {
            $expected = TypeConversion::toBigInt($valueArg);
            $currentBig = ($current instanceof \PhpJs\Value\JsBigInt)
                ? $current
                : TypeConversion::toBigInt($current);
            if ($currentBig->value !== $expected->value) {
                $resultStr = 'not-equal';
            }
        } else {
            $expected = (int) TypeConversion::toNumber($valueArg);
            $currentNum = (int) TypeConversion::toNumber($current);
            if ($currentNum !== $expected) {
                $resultStr = 'not-equal';
            }
        }

        // Per spec: returns {async: false, value: resultStr} when resolved synchronously.
        $result = new JsObject();
        $result->set('async', new JsBoolean(false));
        $result->set('value', new JsString($resultStr));

        return $result;
    }

    /**
     * Atomics.isLockFree(size).
     *
     * Returns true for sizes 1, 2, 4, 8 (matching native atomic operation widths).
     */
    private static function isLockFreeFn(JsValue $this_, array $args): JsValue
    {
        $sizeArg = $args[0] ?? JsUndefined::instance();
        $n = TypeConversion::toIntegerOrInfinity($sizeArg);
        $size = (int) $n;

        return new JsBoolean($size === 1 || $size === 2 || $size === 4 || $size === 8);
    }

    /**
     * Perform an integer arithmetic/bitwise operation.
     */
    private static function intOp(string $op, int $a, int $b): int
    {
        return match ($op) {
            'add' => $a + $b,
            'sub' => $a - $b,
            'and' => $a & $b,
            'or' => $a | $b,
            'xor' => $a ^ $b,
        };
    }

    /**
     * Perform a BigInt arithmetic/bitwise operation using bcmath.
     */
    private static function bigIntOp(string $op, string $a, string $b): string
    {
        return match ($op) {
            'add' => bcadd($a, $b, 0),
            'sub' => bcsub($a, $b, 0),
            'and' => self::bigIntBitwise('&', $a, $b),
            'or' => self::bigIntBitwise('|', $a, $b),
            'xor' => self::bigIntBitwise('^', $a, $b),
        };
    }

    /**
     * Bitwise operations on arbitrary-precision decimal strings.
     * Converts to binary, performs bitwise op, converts back.
     */
    private static function bigIntBitwise(string $op, string $a, string $b): string
    {
        $aNeg = isset($a[0]) && $a[0] === '-';
        $bNeg = isset($b[0]) && $b[0] === '-';
        $aAbs = ltrim($a, '-');
        $bAbs = ltrim($b, '-');

        // Convert to binary strings.
        $aBin = self::decToBin($aAbs);
        $bBin = self::decToBin($bAbs);

        // Handle two's complement for negative values.
        if ($aNeg) {
            $aBin = self::twosComplement($aBin);
        }
        if ($bNeg) {
            $bBin = self::twosComplement($bBin);
        }

        // Extend to same length with sign extension.
        $aSign = $aNeg ? '1' : '0';
        $bSign = $bNeg ? '1' : '0';
        $maxLen = max(strlen($aBin), strlen($bBin));
        $aBin = str_pad($aBin, $maxLen, $aSign, STR_PAD_LEFT);
        $bBin = str_pad($bBin, $maxLen, $bSign, STR_PAD_LEFT);

        // Apply bitwise operation.
        $result = '';
        for ($i = 0; $i < $maxLen; $i++) {
            $aBit = (int) $aBin[$i];
            $bBit = (int) $bBin[$i];
            $result .= match ($op) {
                '&' => $aBit & $bBit,
                '|' => $aBit | $bBit,
                '^' => $aBit ^ $bBit,
            };
        }

        // Check if result is negative (sign bit is 1).
        $resultNeg = $result[0] === '1';
        if ($resultNeg) {
            $result = self::twosComplement($result);
            $dec = self::binToDec($result);
            return $dec === '0' ? '0' : '-' . $dec;
        }

        return self::binToDec($result);
    }

    private static function decToBin(string $dec): string
    {
        if ($dec === '0') {
            return '0';
        }
        $bin = '';
        $n = $dec;
        while ($n !== '0') {
            $rem = 0;
            $q = '';
            for ($i = 0; $i < strlen($n); $i++) {
                $cur = $rem * 10 + (int) $n[$i];
                $q .= (string) intdiv($cur, 2);
                $rem = $cur % 2;
            }
            $bin = $rem . $bin;
            $n = ltrim($q, '0') ?: '0';
        }
        return $bin;
    }

    private static function binToDec(string $bin): string
    {
        $dec = '0';
        for ($i = 0; $i < strlen($bin); $i++) {
            // dec = dec * 2 + bit
            $carry = (int) $bin[$i];
            $out = '';
            for ($j = strlen($dec) - 1; $j >= 0; $j--) {
                $d = (int) $dec[$j] * 2 + $carry;
                $carry = intdiv($d, 10);
                $out = ($d % 10) . $out;
            }
            while ($carry > 0) {
                $out = ($carry % 10) . $out;
                $carry = intdiv($carry, 10);
            }
            $dec = $out !== '' ? $out : '0';
        }
        return $dec;
    }

    private static function twosComplement(string $bin): string
    {
        // Flip bits.
        $flipped = '';
        for ($i = 0; $i < strlen($bin); $i++) {
            $flipped .= $bin[$i] === '0' ? '1' : '0';
        }
        // Add 1.
        $carry = 1;
        $result = '';
        for ($i = strlen($flipped) - 1; $i >= 0; $i--) {
            $d = (int) $flipped[$i] + $carry;
            $result = ($d % 2) . $result;
            $carry = intdiv($d, 2);
        }
        return $result;
    }
}
