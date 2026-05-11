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
    /**
     * Pending waitAsync waiters, keyed by buffer object hash + index.
     * Each entry holds the JsPromise to resolve when notified, plus the
     * original timeout (0/finite/infinite) so timed-out resolutions can
     * be modelled too.
     *
     * @var list<array{buffer: JsSharedArrayBuffer, index: int, promise: JsPromise}>
     */
    private static array $waitAsyncQueue = [];

    /**
     * Whether the current agent can suspend on Atomics.wait.
     *
     * Default false: a non-blocking host (the typical CanBlockIsFalse path)
     * throws TypeError when wait would suspend. Test262 tests carrying the
     * CanBlockIsTrue flag toggle this to true through setAgentCanSuspend()
     * so wait returns "timed-out" instead. PHP is single-threaded, so a
     * positive timeout is sleep-then-return-"timed-out" rather than a
     * real notify-driven wake-up.
     */
    private static bool $agentCanSuspend = false;

    /**
     * Toggle the agent-can-suspend flag.
     *
     * Used by Test262Runner around tests with the CanBlockIsTrue flag.
     * Callers should reset to false after the test completes.
     */
    public static function setAgentCanSuspend(bool $canSuspend): void
    {
        self::$agentCanSuspend = $canSuspend;
    }

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
        $m('pause', self::pauseFn(...), 0);

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
     *
     * Per spec ValidateAtomicAccess, ToIndex(requestIndex) is called before
     * the bounds check. ToIndex may invoke a `valueOf` that detaches the
     * underlying buffer, so we must re-validate the buffer (TypeError on
     * detached) before falling through to the RangeError bounds check.
     */
    private static function validateAtomicAccess(JsTypedArray $ta, JsValue $requestIndex): int
    {
        $index = TypeConversion::toIndex($requestIndex);
        // Detached-buffer check after coercion: a `valueOf`-driven detach
        // surfaces as a TypeError per sm/Atomics/detached-buffers.js.
        $ta->validateNotDetached();
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
            $coerced = JsNumber::of(TypeConversion::toIntegerOrInfinity($value));
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
                $ta->setIndex($index, JsNumber::of((float) $resultNum));
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
            // Truncate expected to the typed array's element width so a
            // BigInt like -5n on a BigUint64Array matches the stored
            // 2^64-5 (the array's element coercion).
            $expectedTruncBig = self::truncateBigIntForElementType($typeName, $expectedBig->value);
            if ($currentBig->value === $expectedTruncBig) {
                $ta->setIndex($index, $replacementBig);
            }
        } else {
            $expectedNum = (int) TypeConversion::toIntegerOrInfinity($expected);
            $replacementNum = TypeConversion::toIntegerOrInfinity($replacement);
            $currentNum = (int) TypeConversion::toNumber($current);

            // Per spec, the comparison happens after both expected and
            // current are coerced to the typed array's raw bytes. Truncate
            // expected to the same range the storage layer would use so
            // values like 12345 in an Int8Array (stored as 57) still pass
            // the equality check when expectedValue passes 12345.
            $expectedTruncated = self::truncateForElementType($typeName, $expectedNum);
            if ($currentNum === $expectedTruncated) {
                $ta->setIndex($index, JsNumber::of($replacementNum));
            }
        }

        return $current;
    }

    private static function truncateBigIntForElementType(string $typeName, string $value): string
    {
        // Use bcmath for arbitrary precision modulo + sign-extension.
        if ($typeName === 'BigInt64Array') {
            $mod = bcmod($value, '18446744073709551616'); // 2^64
            if (bccomp($mod, '0', 0) < 0) {
                $mod = bcadd($mod, '18446744073709551616', 0);
            }
            // Sign-extend to int64 range.
            if (bccomp($mod, '9223372036854775808', 0) >= 0) {
                $mod = bcsub($mod, '18446744073709551616', 0);
            }
            return $mod;
        }
        if ($typeName === 'BigUint64Array') {
            $mod = bcmod($value, '18446744073709551616');
            if (bccomp($mod, '0', 0) < 0) {
                $mod = bcadd($mod, '18446744073709551616', 0);
            }
            return $mod;
        }
        return $value;
    }

    private static function truncateForElementType(string $typeName, int $value): int
    {
        switch ($typeName) {
            case 'Int8Array':
                $b = $value & 0xFF;
                return $b >= 0x80 ? $b - 0x100 : $b;
            case 'Uint8Array':
            case 'Uint8ClampedArray':
                return $value & 0xFF;
            case 'Int16Array':
                $b = $value & 0xFFFF;
                return $b >= 0x8000 ? $b - 0x10000 : $b;
            case 'Uint16Array':
                return $value & 0xFFFF;
            case 'Int32Array':
                $b = $value & 0xFFFFFFFF;
                return $b >= 0x80000000 ? $b - 0x100000000 : $b;
            case 'Uint32Array':
                return $value & 0xFFFFFFFF;
            default:
                return $value;
        }
    }

    /**
     * Atomics.wait(int32Array, index, value, timeout?).
     *
     * Single-threaded: no real blocking is possible.
     * If the current value does not match the expected value, return "not-equal".
     * If it matches, return "timed-out" (we cannot actually suspend).
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

        $index = self::validateAtomicAccess(
            $ta,
            $args[1] ?? JsUndefined::instance(),
        );

        // Per spec step 3: convert value before timeout.
        $valueArg = $args[2] ?? JsUndefined::instance();
        $isBigInt = $ta->getTypeName() === 'BigInt64Array';
        if ($isBigInt) {
            $expected = TypeConversion::toBigInt($valueArg);
        } else {
            $expectedNum = TypeConversion::toNumber($valueArg);
        }

        // Per spec step 4: ToNumber(timeout) must be evaluated
        // (may throw for poisoned objects). Capture the coerced result so
        // we can drive the suspend-and-return-"timed-out" path below.
        $timeoutArg = $args[3] ?? JsUndefined::instance();
        $timeoutNum = $timeoutArg instanceof JsUndefined
            ? NAN
            : TypeConversion::toNumber($timeoutArg);

        // Compare current value with expected. Single-threaded: no real blocking.
        $current = $ta->getIndex($index);
        if ($isBigInt) {
            /** @var \PhpJs\Value\JsBigInt $expected */
            $currentBig = ($current instanceof \PhpJs\Value\JsBigInt)
                ? $current
                : TypeConversion::toBigInt($current);
            if ($currentBig->value !== $expected->value) {
                return new JsString('not-equal');
            }
        } else {
            $curNum = TypeConversion::toNumber($current);
            /** @var float $expectedNum */
            if ($curNum !== $expectedNum) {
                return new JsString('not-equal');
            }
        }

        // Per spec step 12: if AgentCanSuspend() is false, throw TypeError.
        // PHP is single-threaded, so by default we refuse to suspend. The
        // test262 CanBlockIsTrue runner toggles $agentCanSuspend on for
        // suspend-permitted tests; those wait calls fall through to the
        // single-threaded approximation below.
        if (!self::$agentCanSuspend) {
            throw new TypeError('Atomics.wait cannot suspend the current agent');
        }

        // Single-threaded "suspend": no other agent can notify us. Honour
        // ToNumber(timeout) per spec by treating NaN as +Infinity, then
        // max(q, 0) as the wait budget. Cap any actual sleep at 5ms to
        // keep tests fast (the value match has already been observed).
        // Real ECMAScript would block the agent; the only outcome we can
        // produce single-threaded is "timed-out".
        $waitMs = is_nan($timeoutNum) ? INF : max(0.0, $timeoutNum);
        if ($waitMs > 0.0 && is_finite($waitMs)) {
            $sleepMs = min($waitMs, 5.0);
            usleep((int) ($sleepMs * 1000));
        }

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
        $index = self::validateAtomicAccess($ta, $args[1] ?? JsUndefined::instance());

        $countArg = $args[2] ?? JsUndefined::instance();
        $count = $countArg instanceof JsUndefined
            ? PHP_INT_MAX
            : (int) min(PHP_INT_MAX, max(0.0, TypeConversion::toIntegerOrInfinity($countArg)));

        $buffer = $ta->getBuffer();
        $woken = 0;
        if (!$buffer instanceof JsSharedArrayBuffer) {
            return JsNumber::of(0.0);
        }
        // Walk the queue in registration order; resolve up to $count waiters
        // whose buffer + index match.
        $remaining = [];
        foreach (self::$waitAsyncQueue as $entry) {
            if (
                $woken < $count
                && $entry['buffer'] === $buffer
                && $entry['index'] === $index
                && $entry['promise']->getState() === JsPromise::STATE_PENDING
            ) {
                $entry['promise']->resolve(new JsString('ok'));
                $woken++;
                continue;
            }
            $remaining[] = $entry;
        }
        self::$waitAsyncQueue = $remaining;
        return JsNumber::of((float) $woken);
    }

    /**
     * Atomics.waitAsync(int32Array, index, value, timeout?).
     *
     * Single-threaded: returns {async: false, value: "not-equal"} when the
     * current value doesn't match expected, {async: false, value: "timed-out"}
     * when timeout is 0, and {async: true, value: <pending Promise>} when the
     * value matches and a positive timeout is passed. The pending Promise
     * resolves to "ok" when Atomics.notify wakes the waiter, or "timed-out"
     * if a finite timeout elapses (we don't model elapsed wall-clock time;
     * finite timeouts resolve to "timed-out" via a microtask).
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

        $valuesMatch = true;
        if ($isBigInt) {
            $expected = TypeConversion::toBigInt($valueArg);
            $currentBig = ($current instanceof \PhpJs\Value\JsBigInt)
                ? $current
                : TypeConversion::toBigInt($current);
            $valuesMatch = $currentBig->value === $expected->value;
        } else {
            $expected = (int) TypeConversion::toNumber($valueArg);
            $currentNum = (int) TypeConversion::toNumber($current);
            $valuesMatch = $currentNum === $expected;
        }

        $timeoutArg = $args[3] ?? JsUndefined::instance();
        $timeoutNum = $timeoutArg instanceof JsUndefined
            ? NAN
            : TypeConversion::toNumber($timeoutArg);
        $timeoutIsZero = !is_nan($timeoutNum) && $timeoutNum <= 0.0;

        if (!$valuesMatch) {
            return self::makeWaitAsyncResult(false, new JsString('not-equal'));
        }
        if ($timeoutIsZero) {
            return self::makeWaitAsyncResult(false, new JsString('timed-out'));
        }
        // Otherwise: build a real Promise and register the waiter so a
        // matching Atomics.notify resolves it.
        $promise = new JsPromise();
        self::$waitAsyncQueue[] = [
            'buffer' => $ta->getBuffer(),
            'index' => $index,
            'promise' => $promise,
        ];
        // Finite, non-infinite timeouts resolve to "timed-out" once the
        // current synchronous turn completes — we don't model wall-clock
        // sleep, so this is the closest spec-compatible behaviour for
        // single-threaded code that doesn't notify.
        if (!is_nan($timeoutNum) && is_finite($timeoutNum) && $timeoutNum > 0.0) {
            JsPromise::scheduleCallback(static function () use ($promise): void {
                if ($promise->getState() !== JsPromise::STATE_PENDING) {
                    return;
                }
                self::removeWaitAsyncEntry($promise);
                $promise->resolve(new JsString('timed-out'));
            });
        }
        return self::makeWaitAsyncResult(true, $promise);
    }

    private static function makeWaitAsyncResult(bool $async, JsValue $value): JsObject
    {
        $result = new JsObject();
        $result->set('async', new JsBoolean($async));
        $result->set('value', $value);
        return $result;
    }

    private static function removeWaitAsyncEntry(JsPromise $promise): void
    {
        foreach (self::$waitAsyncQueue as $i => $entry) {
            if ($entry['promise'] === $promise) {
                array_splice(self::$waitAsyncQueue, $i, 1);
                return;
            }
        }
    }

    /**
     * Atomics.pause(iterationNumber?).
     *
     * Per spec: a hint to the implementation that the caller is in a spin-wait loop.
     * Single-threaded: this is a no-op. Always returns undefined.
     */
    private static function pauseFn(JsValue $this_, array $args): JsValue
    {
        $iterationNumber = $args[0] ?? JsUndefined::instance();

        // Per spec step 1: if iterationNumber is not undefined, validate strictly.
        if (!$iterationNumber instanceof JsUndefined) {
            // Per spec: Type(iterationNumber) must be Number (not coerced).
            if (!$iterationNumber instanceof JsNumber) {
                throw new TypeError('Atomics.pause requires a Number argument');
            }
            // Per spec: IsIntegralNumber(iterationNumber) must be true.
            $n = $iterationNumber->value;
            if (is_nan($n) || is_infinite($n) || $n < 0 || floor($n) !== $n) {
                throw new TypeError('Atomics.pause requires a non-negative integral Number');
            }
        }

        return JsUndefined::instance();
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
            default => $a,
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
            default => $a,
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
        // Add explicit sign bit so the result can be interpreted correctly.
        $aSign = $aNeg ? '1' : '0';
        $bSign = $bNeg ? '1' : '0';
        $maxLen = max(strlen($aBin), strlen($bBin)) + 1;
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
                default => $aBit,
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
            $dec = $out ?: '0';
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
