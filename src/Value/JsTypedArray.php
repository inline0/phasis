<?php

declare(strict_types=1);

namespace PhpJs\Value;

use PhpJs\BuiltIn\SymbolConstructor;
use PhpJs\Spec\TypeConversion;

/**
 * JavaScript TypedArray object.
 *
 * Provides a typed view over an ArrayBuffer. Supports all standard typed
 * array variants: Int8Array, Uint8Array, Uint8ClampedArray, Int16Array,
 * Uint16Array, Int32Array, Uint32Array, Float32Array, Float64Array,
 * BigInt64Array, BigUint64Array.
 *
 * Uses PHP's pack/unpack for byte-level type conversions.
 */
class JsTypedArray extends JsObject
{
    private JsArrayBuffer $buffer;
    private int $byteOffset;
    private int $length;
    private string $typeName;
    private int $bytesPerElement;

    /**
     * Pack format character for the element type.
     * Used with pack() and unpack() for byte conversion.
     */
    private string $packFormat;

    /** Whether this is a BigInt typed array. */
    private bool $isBigInt;

    /** Whether values should be clamped (Uint8ClampedArray). */
    private bool $clamped;

    /**
     * Type configuration table: name => [bytesPerElement, packFormat, isBigInt, isClamped].
     *
     * @var array<string, array{int, string, bool, bool}>
     */
    public const TYPES = [
        'Int8Array' => [1, 'c', false, false],
        'Uint8Array' => [1, 'C', false, false],
        'Uint8ClampedArray' => [1, 'C', false, true],
        'Int16Array' => [2, 's', false, false],
        'Uint16Array' => [2, 'v', false, false],
        'Int32Array' => [4, 'l', false, false],
        'Uint32Array' => [4, 'V', false, false],
        'Float32Array' => [4, 'g', false, false],
        'Float64Array' => [8, 'e', false, false],
        'BigInt64Array' => [8, 'q', true, false],
        'BigUint64Array' => [8, 'Q', true, false],
    ];

    public function __construct(
        string $typeName,
        JsArrayBuffer $buffer,
        int $byteOffset,
        int $length,
        ?JsObject $prototype = null,
    ) {
        parent::__construct($prototype);

        if (!isset(self::TYPES[$typeName])) {
            throw new \PhpJs\Exceptions\TypeError("Invalid typed array type: {$typeName}");
        }

        [$this->bytesPerElement, $this->packFormat, $this->isBigInt, $this->clamped] = self::TYPES[$typeName];
        $this->typeName = $typeName;
        $this->buffer = $buffer;
        $this->byteOffset = $byteOffset;
        $this->length = $length;

        $this->installSymbolIterator();
    }

    /**
     * Throw TypeError if the underlying buffer is detached.
     */
    private function validateNotDetached(): void
    {
        if ($this->buffer->isDetached()) {
            throw new \PhpJs\Exceptions\TypeError(
                'Cannot perform %TypedArray%.prototype method'
                . ' on a detached ArrayBuffer'
            );
        }
    }

    /**
     * Create a typed array from a length (allocates a new buffer).
     */
    public static function fromLength(
        string $typeName,
        int $length,
        ?JsObject $prototype = null,
    ): self {
        $bpe = self::TYPES[$typeName][0];
        $buffer = new JsArrayBuffer($length * $bpe);
        return new self($typeName, $buffer, 0, $length, $prototype);
    }

    /**
     * Create a typed array from an existing array-like source.
     *
     * @param list<JsValue> $elements
     */
    public static function fromArray(
        string $typeName,
        array $elements,
        ?JsObject $prototype = null,
    ): self {
        $ta = self::fromLength($typeName, count($elements), $prototype);
        foreach ($elements as $i => $el) {
            $ta->setIndex($i, $el);
        }
        return $ta;
    }

    public function getTypeName(): string
    {
        return $this->typeName;
    }

    public function getBuffer(): JsArrayBuffer
    {
        return $this->buffer;
    }

    public function getByteOffset(): int
    {
        return $this->byteOffset;
    }

    public function getLength(): int
    {
        // Per spec: if the viewed ArrayBuffer is detached, length is 0.
        if ($this->buffer->isDetached()) {
            return 0;
        }
        return $this->length;
    }

    public function getBytesPerElement(): int
    {
        return $this->bytesPerElement;
    }

    public function isBigIntArray(): bool
    {
        return $this->isBigInt;
    }

    /** Get element at typed index. */
    public function getIndex(int $index): JsValue
    {
        // Per spec IntegerIndexedElementGet: return undefined for detached buffers.
        if ($this->buffer->isDetached()) {
            return JsUndefined::instance();
        }
        if ($index < 0 || $index >= $this->length) {
            return JsUndefined::instance();
        }

        $offset = $this->byteOffset + $index * $this->bytesPerElement;
        $bytes = substr($this->buffer->getData(), $offset, $this->bytesPerElement);

        if (strlen($bytes) < $this->bytesPerElement) {
            return new JsNumber(0.0);
        }

        $unpacked = unpack($this->packFormat . 'val', $bytes);
        if ($unpacked === false) {
            return new JsNumber(0.0);
        }

        $val = $unpacked['val'];

        if ($this->isBigInt) {
            // For BigUint64Array, PHP returns signed int but we need unsigned string.
            if ($this->typeName === 'BigUint64Array' && $val < 0) {
                // Add 2^64 to convert negative signed to unsigned.
                $strVal = bcadd((string) $val, '18446744073709551616');
                return new JsBigInt($strVal);
            }
            return new JsBigInt((string) $val);
        }

        // Int16Array uses machine-native signed short 's', which may need
        // byte-order fixup on big-endian systems. For simplicity, we rely
        // on the fact that nearly all contemporary systems are little-endian.
        // Float types are already IEEE 754 via 'g' (LE float) and 'e' (LE double).
        return new JsNumber((float) $val);
    }

    /** Set element at typed index. */
    public function setIndex(int $index, JsValue $value): void
    {
        // Per spec IntegerIndexedElementSet: silently fail for detached buffers.
        if ($this->buffer->isDetached()) {
            return;
        }
        if ($index < 0 || $index >= $this->length) {
            return;
        }

        $num = $this->coerceValue($value);
        $packed = pack($this->packFormat, $num);

        $offset = $this->byteOffset + $index * $this->bytesPerElement;
        $data = $this->buffer->getData();

        // Write packed bytes into the buffer.
        for ($i = 0; $i < $this->bytesPerElement; $i++) {
            $data[$offset + $i] = $packed[$i];
        }

        $this->buffer->setData($data);
    }

    /**
     * Coerce a JS value to the numeric type appropriate for this typed array.
     *
     * For clamped arrays, clamps to [0, 255].
     * For integer arrays, truncates to the appropriate range via pack/unpack.
     * For BigInt arrays, converts to int.
     */
    private function coerceValue(JsValue $value): int|float
    {
        if ($this->isBigInt) {
            // Per spec: for BigInt typed arrays, use ToBigInt which throws TypeError
            // for Number, undefined, null, and Symbol values.
            $bigInt = TypeConversion::toBigInt($value);
            $strVal = $bigInt->value;

            // ToBigInt64 / ToBigUint64: modulo 2^64 with proper sign handling.
            return self::bigIntModulo($strVal, $this->typeName === 'BigInt64Array');
        }

        // Use TypeConversion::toNumber for proper ToPrimitive handling on objects.
        $num = TypeConversion::toNumber($value);

        if ($this->clamped) {
            if (is_nan($num)) {
                return 0;
            }
            // Spec uses "round half to even" (banker's rounding) for Uint8Clamped.
            $rounded = round($num, 0, PHP_ROUND_HALF_EVEN);
            return (int) max(0, min(255, $rounded));
        }

        // For float arrays, preserve NaN and Infinity.
        if ($this->typeName === 'Float32Array' || $this->typeName === 'Float64Array') {
            return $num;
        }

        // For integer arrays, NaN and Infinity become 0.
        if (is_nan($num) || is_infinite($num)) {
            return 0;
        }

        // For integer arrays, truncate to integer.
        return (int) $num;
    }

    /**
     * Whether a property key is a CanonicalNumericIndexString per spec 7.1.21.
     *
     * A string is a CanonicalNumericIndexString when ToString(ToNumber(s)) === s.
     * This includes "NaN", "Infinity", "-Infinity", "-0", and any numeric string
     * whose JS ToString round-trips (e.g. "1.5", "0", "100"). TypedArray exotic
     * methods intercept all such keys.
     */
    private static function isCanonicalNumericIndex(string $name): bool
    {
        if ($name === '-0') {
            return true;
        }
        // JS special values.
        if ($name === 'NaN' || $name === 'Infinity' || $name === '-Infinity') {
            return true;
        }
        // Numeric strings: is_numeric covers integers and floats.
        // Verify round-trip: the PHP string form of the float must match exactly.
        if (is_numeric($name)) {
            $f = (float) $name;
            return (string) $f === $name;
        }
        return false;
    }

    public function get(string $name): JsValue
    {
        if ($name === 'length') {
            return new JsNumber((float) $this->getLength());
        }
        if ($name === 'byteLength') {
            return new JsNumber((float) ($this->getLength() * $this->bytesPerElement));
        }
        if ($name === 'byteOffset') {
            // Per spec: byteOffset is 0 when buffer is detached.
            if ($this->buffer->isDetached()) {
                return new JsNumber(0.0);
            }
            return new JsNumber((float) $this->byteOffset);
        }
        if ($name === 'buffer') {
            return $this->buffer;
        }
        if ($name === 'BYTES_PER_ELEMENT') {
            return new JsNumber((float) $this->bytesPerElement);
        }

        // Numeric index access (digit-only fast path).
        if (ctype_digit($name)) {
            return $this->getIndex((int) $name);
        }

        // CanonicalNumericIndexString: intercept keys like "NaN", "-0", "1.5".
        // These are never forwarded to the prototype chain.
        if (self::isCanonicalNumericIndex($name)) {
            return JsUndefined::instance();
        }

        return parent::get($name);
    }

    public function set(string $name, JsValue $value, bool $strict = false): void
    {
        // Numeric index access.
        if (ctype_digit($name)) {
            $index = (int) $name;
            $this->setIndex($index, $value);
            return;
        }

        // CanonicalNumericIndexString: intercept keys like "NaN", "-0", "1.5".
        // Non-integer numeric indices are silently ignored.
        if (self::isCanonicalNumericIndex($name)) {
            return;
        }

        parent::set($name, $value, $strict);
    }

    /**
     * Override internalSet for Reflect.set and spec-compliant [[Set]] on integer indices.
     */
    public function internalSet(string $name, JsValue $value, JsObject $receiver): bool
    {
        if (ctype_digit($name)) {
            $index = (int) $name;
            if ($index >= 0 && $index < $this->length) {
                $this->setIndex($index, $value);
                return true;
            }
            return false;
        }

        // CanonicalNumericIndexString: TypedArray exotic [[Set]] intercepts all
        // such keys. If it is not a valid integer index, return false so the
        // property is NOT created on the receiver.
        if (self::isCanonicalNumericIndex($name)) {
            return false;
        }

        return parent::internalSet($name, $value, $receiver);
    }

    /**
     * Override getOwnPropertyDescriptor for integer-indexed properties.
     *
     * Per spec, TypedArray numeric indices appear as writable, enumerable,
     * non-configurable data properties. CanonicalNumericIndexStrings that are
     * not valid integer indices return null.
     */
    public function getOwnPropertyDescriptor(
        string $name,
    ): ?\PhpJs\Object\PropertyDescriptor {
        if (ctype_digit($name)) {
            $index = (int) $name;
            if ($index >= 0 && $index < $this->length) {
                return \PhpJs\Object\PropertyDescriptor::data(
                    $this->getIndex($index),
                    true,
                    true,
                    false,
                );
            }
            return null;
        }

        // CanonicalNumericIndexString: return null for non-integer numeric keys.
        if (self::isCanonicalNumericIndex($name)) {
            return null;
        }

        return parent::getOwnPropertyDescriptor($name);
    }

    /**
     * Override defineOwnProperty for integer-indexed properties.
     *
     * Per spec, defining a numeric index property on a TypedArray sets the
     * value if the descriptor is compatible, otherwise silently fails.
     * CanonicalNumericIndexStrings that are not valid integer indices return false.
     */
    public function defineOwnProperty(
        string $name,
        \PhpJs\Object\PropertyDescriptor $desc,
    ): bool {
        if (ctype_digit($name)) {
            $index = (int) $name;
            if ($index < 0 || $index >= $this->length) {
                return false;
            }
            if ($desc->isAccessorDescriptor()) {
                return false;
            }
            if (
                $desc->writable === false
                || $desc->enumerable === false
                || $desc->configurable === true
            ) {
                return false;
            }
            if ($desc->value !== null) {
                $this->setIndex($index, $desc->value);
            }
            return true;
        }

        // CanonicalNumericIndexString: return false for non-integer numeric keys.
        if (self::isCanonicalNumericIndex($name)) {
            return false;
        }

        return parent::defineOwnProperty($name, $desc);
    }

    public function has(string $name): bool
    {
        if (
            $name === 'length' || $name === 'byteLength' || $name === 'byteOffset'
            || $name === 'buffer' || $name === 'BYTES_PER_ELEMENT'
        ) {
            return true;
        }

        if (ctype_digit($name) && (int) $name < $this->length) {
            return true;
        }

        // CanonicalNumericIndexString: return false for non-integer numeric keys.
        // Do not delegate to prototype chain.
        if (self::isCanonicalNumericIndex($name)) {
            return false;
        }

        return parent::has($name);
    }

    /** @return list<string> */
    public function ownKeys(): array
    {
        $keys = [];
        for ($i = 0; $i < $this->length; $i++) {
            $keys[] = (string) $i;
        }
        return array_merge($keys, parent::ownKeys());
    }

    /** @return list<string> */
    public function getOwnEnumerableKeys(): array
    {
        $keys = [];
        for ($i = 0; $i < $this->length; $i++) {
            $keys[] = (string) $i;
        }
        return $keys;
    }

    /**
     * TypedArray.prototype.set(source, offset).
     *
     * Copies values from source into this typed array at the given offset.
     *
     * @param list<JsValue> $elements
     */
    public function setFromArray(array $elements, int $offset = 0): void
    {
        foreach ($elements as $i => $el) {
            $this->setIndex($offset + $i, $el);
        }
    }

    /**
     * TypedArray.prototype.subarray(begin, end).
     *
     * Returns a new typed array that shares the same buffer, with adjusted
     * byte offset and length.
     */
    public function subarray(int $begin, ?int $end = null): self
    {
        if ($begin < 0) {
            $begin = max(0, $this->length + $begin);
        }
        $begin = min($begin, $this->length);

        if ($end === null) {
            $end = $this->length;
        } elseif ($end < 0) {
            $end = max(0, $this->length + $end);
        }
        $end = min($end, $this->length);

        $newLen = max(0, $end - $begin);
        $newByteOffset = $this->byteOffset + $begin * $this->bytesPerElement;

        return new self(
            $this->typeName,
            $this->buffer,
            $newByteOffset,
            $newLen,
            $this->getPrototype(),
        );
    }

    /**
     * TypedArray.prototype.slice(begin, end).
     *
     * Returns a new typed array with a new buffer containing a copy of the
     * elements from begin to end.
     */
    public function sliceTyped(int $begin, ?int $end = null): self
    {
        if ($begin < 0) {
            $begin = max(0, $this->length + $begin);
        }
        $begin = min($begin, $this->length);

        if ($end === null) {
            $end = $this->length;
        } elseif ($end < 0) {
            $end = max(0, $this->length + $end);
        }
        $end = min($end, $this->length);

        $newLen = max(0, $end - $begin);
        $result = self::fromLength($this->typeName, $newLen, $this->getPrototype());

        for ($i = 0; $i < $newLen; $i++) {
            $result->setIndex($i, $this->getIndex($begin + $i));
        }

        return $result;
    }

    /**
     * TypedArray.prototype.copyWithin(target, start, end).
     */
    public function copyWithinTyped(int $target, int $start, ?int $end = null): self
    {
        $len = $this->length;
        if ($target < 0) {
            $target = max(0, $len + $target);
        }
        $target = min($target, $len);
        if ($start < 0) {
            $start = max(0, $len + $start);
        }
        $start = min($start, $len);
        if ($end === null) {
            $end = $len;
        } elseif ($end < 0) {
            $end = max(0, $len + $end);
        }
        $end = min($end, $len);

        $count = min($end - $start, $len - $target);

        // Copy into a temp buffer to handle overlapping regions.
        $temp = [];
        for ($i = 0; $i < $count; $i++) {
            $temp[] = $this->getIndex($start + $i);
        }
        for ($i = 0; $i < $count; $i++) {
            $this->setIndex($target + $i, $temp[$i]);
        }

        return $this;
    }

    /**
     * TypedArray.prototype.fill(value, start, end).
     */
    public function fillTyped(JsValue $value, int $start = 0, ?int $end = null): self
    {
        if ($start < 0) {
            $start = max(0, $this->length + $start);
        }
        if ($end === null) {
            $end = $this->length;
        } elseif ($end < 0) {
            $end = max(0, $this->length + $end);
        }
        $end = min($end, $this->length);

        for ($i = $start; $i < $end; $i++) {
            $this->setIndex($i, $value);
        }

        return $this;
    }

    /**
     * TypedArray.prototype.reverse().
     */
    public function reverseTyped(): self
    {
        $mid = (int) ($this->length / 2);
        for ($i = 0; $i < $mid; $i++) {
            $j = $this->length - 1 - $i;
            $a = $this->getIndex($i);
            $b = $this->getIndex($j);
            $this->setIndex($i, $b);
            $this->setIndex($j, $a);
        }
        return $this;
    }

    /**
     * TypedArray.prototype.indexOf(searchElement, fromIndex).
     */
    public function indexOfTyped(JsValue $search, int $fromIndex = 0): int
    {
        if ($fromIndex < 0) {
            $fromIndex = max(0, $this->length + $fromIndex);
        }
        for ($i = $fromIndex; $i < $this->length; $i++) {
            $el = $this->getIndex($i);
            if ($this->strictEquals($el, $search)) {
                return $i;
            }
        }
        return -1;
    }

    /**
     * TypedArray.prototype.includes(searchElement, fromIndex).
     */
    public function includesTyped(JsValue $search, int $fromIndex = 0): bool
    {
        if ($fromIndex < 0) {
            $fromIndex = max(0, $this->length + $fromIndex);
        }
        for ($i = $fromIndex; $i < $this->length; $i++) {
            $el = $this->getIndex($i);
            if ($this->sameValueZero($el, $search)) {
                return true;
            }
        }
        return false;
    }

    /**
     * TypedArray.prototype.join(separator).
     */
    public function joinTyped(string $separator = ','): string
    {
        $parts = [];
        for ($i = 0; $i < $this->length; $i++) {
            $el = $this->getIndex($i);
            $parts[] = $el->toJsString();
        }
        return implode($separator, $parts);
    }

    /** Convert all elements to a list of JsValues. */
    public function toList(): array
    {
        $result = [];
        for ($i = 0; $i < $this->length; $i++) {
            $result[] = $this->getIndex($i);
        }
        return $result;
    }

    /** Install Symbol.iterator for for-of support. */
    private function installSymbolIterator(): void
    {
        $ta = $this;
        $iterSym = SymbolConstructor::iterator();
        $factory = function () use ($ta, $iterSym): JsValue {
            $index = 0;
            $iterator = new JsObject();
            $nextFn = function () use ($ta, &$index): JsValue {
                $result = new JsObject();
                if ($index < $ta->getLength()) {
                    $result->set('value', $ta->getIndex($index));
                    $result->set('done', new JsBoolean(false));
                    $index++;
                } else {
                    $result->set('value', JsUndefined::instance());
                    $result->set('done', new JsBoolean(true));
                }
                return $result;
            };
            $iterator->set('next', JsFunction::fromCallable('next', $nextFn));
            $iterator->setBySymbol($iterSym, JsFunction::fromCallable(
                '[Symbol.iterator]',
                function (JsValue $self_): JsValue {
                    return $self_;
                },
            ));
            return $iterator;
        };
        $this->setBySymbol($iterSym, JsFunction::fromCallable('[Symbol.iterator]', $factory));
    }

    /** Strict equality comparison for indexOf. */
    private function strictEquals(JsValue $a, JsValue $b): bool
    {
        if ($a instanceof JsNumber && $b instanceof JsNumber) {
            if (is_nan($a->value) || is_nan($b->value)) {
                return false;
            }
            return $a->value === $b->value;
        }
        if ($a instanceof JsBigInt && $b instanceof JsBigInt) {
            return $a->value === $b->value;
        }
        return false;
    }

    /** SameValueZero for includes (NaN === NaN). */
    private function sameValueZero(JsValue $a, JsValue $b): bool
    {
        if ($a instanceof JsNumber && $b instanceof JsNumber) {
            if (is_nan($a->value) && is_nan($b->value)) {
                return true;
            }
            return $a->value === $b->value;
        }
        if ($a instanceof JsBigInt && $b instanceof JsBigInt) {
            return $a->value === $b->value;
        }
        return false;
    }

    /**
     * Convert a BigInt string to a 64-bit signed integer (PHP int) for buffer storage.
     *
     * Both BigInt64Array and BigUint64Array store values as 64-bit patterns in
     * the buffer. PHP int is always signed 64-bit. We compute the value modulo
     * 2^64 and always return a signed two's complement PHP int. For BigUint64Array,
     * getIndex() converts back to unsigned.
     *
     * Uses bcmath for arbitrary-precision arithmetic.
     */
    private static function bigIntModulo(string $strVal, bool $signed): int
    {
        // 2^64 = 18446744073709551616
        $mod = '18446744073709551616';
        // 2^63 = 9223372036854775808
        $half = '9223372036854775808';

        // Compute value mod 2^64, normalized to [0, 2^64).
        $result = bcmod($strVal, $mod);
        if ($result !== '' && $result[0] === '-') {
            $result = bcadd($result, $mod);
        }

        // Always convert to signed two's complement for PHP int storage.
        // Values >= 2^63 become negative (two's complement).
        if (bccomp($result, $half) >= 0) {
            $result = bcsub($result, $mod);
        }

        return (int) $result;
    }

    public function toJsString(): string
    {
        return $this->joinTyped(',');
    }

    public function display(): string
    {
        $parts = [];
        $max = min($this->length, 10);
        for ($i = 0; $i < $max; $i++) {
            $el = $this->getIndex($i);
            $parts[] = $el->display();
        }
        $suffix = $this->length > 10 ? ', ...' : '';
        return $this->typeName . '(' . $this->length . ') [ ' . implode(', ', $parts) . $suffix . ' ]';
    }
}
