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

    /** Whether this TypedArray auto-tracks the buffer's byte length. */
    private bool $autoLength = false;

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
        'Float16Array' => [2, 'H', false, false],
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
        // Symbol.iterator is installed on %TypedArray%.prototype by the
        // constructor setup (pointing at values()). Do not install a
        // per-instance copy here: that would leak the symbol into the
        // object's own keys, breaking Reflect.ownKeys and enumeration.
    }

    /**
     * Throw TypeError if the underlying buffer is detached or the view is out of bounds.
     * Per spec (ValidateTypedArray), a typed array backed by a resizable buffer
     * is out of bounds if its fixed view extends beyond the buffer's current byte length.
     */
    public function validateNotDetached(): void
    {
        if ($this->buffer->isDetached()) {
            throw new \PhpJs\Exceptions\TypeError(
                'Cannot perform %TypedArray%.prototype method'
                . ' on a detached ArrayBuffer'
            );
        }
        if ($this->isOutOfBounds()) {
            throw new \PhpJs\Exceptions\TypeError(
                'Cannot perform %TypedArray%.prototype method'
                . ' on an out-of-bounds TypedArray'
            );
        }
    }

    /**
     * Integer-Indexed exotic [[PreventExtensions]] per spec 10.4.5.2.
     *
     * Per the integer-indexed exotic invariants, preventing extensions on a
     * TypedArray backed by a resizable buffer must always fail: future
     * resizes could add or invalidate integer index descriptors, which would
     * otherwise be an invariant violation on a non-extensible view.
     * SharedArrayBuffers that are growable have the same property.
     */
    public function preventExtensions(): bool
    {
        if ($this->buffer->isResizable()) {
            return false;
        }
        return parent::preventExtensions();
    }

    /**
     * Whether this TypedArray's view is out of bounds for its buffer.
     * Only relevant for fixed-length views on resizable buffers.
     */
    public function isOutOfBounds(): bool
    {
        if ($this->buffer->isDetached()) {
            return true;
        }
        if (!$this->buffer->isResizable()) {
            return false;
        }
        $bufLen = $this->buffer->getByteLength();
        if ($this->autoLength) {
            // Auto-length views are out of bounds only if byteOffset > bufLen.
            return $this->byteOffset > $bufLen;
        }
        // Fixed-length view on resizable buffer.
        $viewEnd = $this->byteOffset + $this->length * $this->bytesPerElement;
        return $viewEnd > $bufLen;
    }

    /**
     * Integer-Indexed exotic [[Delete]] per spec 10.4.5.7.
     *
     * For canonical numeric index keys, return true if the index is not a
     * valid integer index (out of bounds, negative, fractional, or "-0")
     * and false otherwise. Non-numeric keys delegate to ordinary delete.
     */
    /**
     * Integer-Indexed exotic [[OwnPropertyKeys]] per spec 10.4.5.6.
     *
     * Returns all integer indices (as strings) in ascending order, then any
     * string-keyed own properties in insertion order, then symbol-keyed own
     * properties in insertion order.
     *
     * @return list<\PhpJs\Value\JsValue>
     */
    public function ordinaryOwnPropertyKeys(): array
    {
        $keys = [];
        if (!$this->buffer->isDetached() && !$this->isOutOfBounds()) {
            $len = $this->getLength();
            for ($i = 0; $i < $len; $i++) {
                $keys[] = new \PhpJs\Value\JsString((string) $i);
            }
        }
        // Ordinary string/symbol keys follow; filter out any that collide
        // with the integer indices already emitted (e.g. stale properties).
        $rest = parent::ordinaryOwnPropertyKeys();
        foreach ($rest as $k) {
            if ($k instanceof \PhpJs\Value\JsString) {
                if (
                    ctype_digit($k->value)
                    && self::isCanonicalNumericIndex($k->value)
                    && (int) $k->value < $this->getLength()
                ) {
                    continue;
                }
            }
            $keys[] = $k;
        }
        return $keys;
    }

    /**
     * Run the spec's ToNumber or ToBigInt coercion on a value that is about
     * to be stored into a typed array element. This runs even when the
     * index turns out to be invalid (out of bounds, fractional, detached)
     * so a throwing valueOf still surfaces the error.
     */
    /**
     * Run the spec-mandated ToNumber/ToBigInt coercion that happens before
     * the validity check in TypedArraySetElement, and return the coerced
     * value wrapped so setIndex() does not re-coerce (avoiding a duplicate
     * valueOf/Symbol.toPrimitive call).
     */
    private function coerceTypedArrayValue(JsValue $value): JsValue
    {
        if ($this->isBigInt) {
            return \PhpJs\Spec\TypeConversion::toBigInt($value);
        }
        $num = \PhpJs\Spec\TypeConversion::toNumber($value);
        return new \PhpJs\Value\JsNumber($num);
    }

    public function delete(string $name, bool $strict = false): bool
    {
        if (self::isCanonicalNumericIndex($name)) {
            // CanonicalNumericIndexString but maybe not a valid integer index.
            if ($name === '-0' || $name === 'NaN' || $name === 'Infinity' || $name === '-Infinity') {
                return true;
            }
            $num = (float) $name;
            if (
                $num >= 0
                && $num === floor($num)
                && !is_infinite($num)
                && $num < $this->getLength()
                && !$this->buffer->isDetached()
            ) {
                if ($strict) {
                    throw new \PhpJs\Exceptions\TypeError(
                        "Cannot delete property '{$name}' of TypedArray",
                    );
                }
                return false; // Valid index: cannot delete.
            }
            return true; // Numeric but invalid: delete succeeds silently.
        }
        // Non-canonical key: ordinary delete path.
        return parent::delete($name, $strict);
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

    /** Mark this TypedArray as auto-tracking the buffer's byte length. */
    public function setAutoLength(bool $auto): void
    {
        $this->autoLength = $auto;
    }

    public function isAutoLength(): bool
    {
        return $this->autoLength;
    }

    public function getLength(): int
    {
        if ($this->buffer->isDetached()) {
            return 0;
        }
        if ($this->autoLength) {
            $bufLen = $this->buffer->getByteLength();
            if ($this->byteOffset > $bufLen) {
                return 0;
            }
            $remaining = $bufLen - $this->byteOffset;
            return intdiv($remaining, $this->bytesPerElement);
        }
        // Fixed-length view on resizable buffer: out of bounds means 0.
        if ($this->buffer->isResizable()) {
            $viewEnd = $this->byteOffset + $this->length * $this->bytesPerElement;
            if ($viewEnd > $this->buffer->getByteLength()) {
                return 0;
            }
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
        if ($index < 0 || $index >= $this->getLength()) {
            return JsUndefined::instance();
        }

        $offset = $this->byteOffset + $index * $this->bytesPerElement;
        $bytes = substr($this->buffer->getData(), $offset, $this->bytesPerElement);

        if (strlen($bytes) < $this->bytesPerElement) {
            return new JsNumber(0.0);
        }

        // Float16Array uses custom half-precision IEEE 754 decode.
        if ($this->packFormat === 'H') {
            $u16 = unpack('v', $bytes);
            return new JsNumber(self::float16Decode($u16 === false ? 0 : $u16[1]));
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
        if ($index < 0 || $index >= $this->getLength()) {
            return;
        }

        $num = $this->coerceValue($value);

        // Float16Array uses custom half-precision IEEE 754 encode.
        if ($this->packFormat === 'H') {
            $packed = pack('v', self::float16Encode((float) $num));
        } else {
            $packed = pack($this->packFormat, $num);
        }

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
        if ($this->typeName === 'Float16Array' || $this->typeName === 'Float32Array' || $this->typeName === 'Float64Array') {
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
        // Numeric strings: is_numeric covers integers and floats. Verify
        // round-trip using JS's ToString semantics (not PHP's (string)(float)
        // which emits "1.0E+21" for 1e21 and "1.0E-6" for 1e-6).
        if (is_numeric($name)) {
            $f = (float) $name;
            $jsString = (new JsNumber($f))->toJsString();
            return $jsString === $name;
        }
        return false;
    }

    public function get(string $name): JsValue
    {
        // Per spec, `length`, `byteLength`, `byteOffset`, `buffer`, and
        // `BYTES_PER_ELEMENT` live as getters on %TypedArrayPrototype% (or
        // constructor). An own property installed via Object.defineProperty
        // must shadow them, so check own properties first.
        if (
            ($name === 'length' || $name === 'byteLength' || $name === 'byteOffset'
            || $name === 'buffer' || $name === 'BYTES_PER_ELEMENT')
            && $this->hasOwnProperty($name)
        ) {
            return parent::get($name);
        }
        if ($name === 'length') {
            return new JsNumber((float) $this->getLength());
        }
        if ($name === 'byteLength') {
            return new JsNumber((float) ($this->getLength() * $this->bytesPerElement));
        }
        if ($name === 'byteOffset') {
            // Per spec: byteOffset is 0 when buffer is detached or out of bounds.
            if ($this->buffer->isDetached() || $this->isOutOfBounds()) {
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

        // Numeric index access: only round-trippable digit strings.
        if (ctype_digit($name) && self::isCanonicalNumericIndex($name)) {
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
        // CanonicalNumericIndexString intercept per spec 10.4.5.5: even for
        // invalid integer indices (fractional, NaN, OOB), ToNumber/ToBigInt
        // runs BEFORE the validity check so a throwing valueOf surfaces.
        if (self::isCanonicalNumericIndex($name)) {
            $coerced = $this->coerceTypedArrayValue($value);
            if (ctype_digit($name)) {
                $index = (int) $name;
                if ($index >= 0 && $index < $this->getLength()) {
                    $this->setIndex($index, $coerced);
                }
            }
            return;
        }

        parent::set($name, $value, $strict);
    }

    /**
     * Integer-Indexed exotic [[Set]] per spec 10.4.5.5. If the key is a
     * CanonicalNumericIndexString, always intercept: when receiver is this
     * typed array, call TypedArraySetElement; otherwise short-circuit return
     * true for invalid indices and defer to OrdinarySet for valid ones.
     */
    public function internalSet(string $name, JsValue $value, JsObject $receiver): bool
    {
        if (self::isCanonicalNumericIndex($name)) {
            $isSelf = $receiver === $this;
            if ($isSelf) {
                // Coerce value via ToNumber/ToBigInt before validity check.
                $coerced = $this->coerceTypedArrayValue($value);
                if (ctype_digit($name)) {
                    $index = (int) $name;
                    if ($index >= 0 && $index < $this->getLength()) {
                        $this->setIndex($index, $coerced);
                        return true;
                    }
                }
                // Invalid integer index with receiver==this: per TypedArraySetElement,
                // return true but do not set anything.
                return true;
            }
            // Receiver is not this typed array. Invalid indices short-circuit
            // to true without performing any coercion or write.
            if (ctype_digit($name)) {
                $index = (int) $name;
                if ($index >= 0 && $index < $this->getLength()) {
                    // Valid index + different receiver: fall through to OrdinarySet.
                    return parent::internalSet($name, $value, $receiver);
                }
            }
            return true;
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
        if (ctype_digit($name) && self::isCanonicalNumericIndex($name)) {
            $index = (int) $name;
            if ($index >= 0 && $index < $this->getLength()) {
                // Per spec 10.4.5.1, TypedArray integer indices are
                // writable, enumerable, and (since ES2023) configurable.
                return \PhpJs\Object\PropertyDescriptor::data(
                    $this->getIndex($index),
                    true,
                    true,
                    true,
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
        // ctype_digit catches only round-trippable small integer strings.
        // Very large digit strings like "1000000000000000000000" are NOT a
        // CanonicalNumericIndexString per spec (ToString(ToNumber(x)) !== x
        // because JS renders 1e21 in scientific form) so they must be
        // treated as ordinary string keys.
        if (
            ctype_digit($name)
            && self::isCanonicalNumericIndex($name)
        ) {
            $index = (int) $name;
            if ($index < 0 || $index >= $this->getLength()) {
                return false;
            }
            if ($desc->isAccessorDescriptor()) {
                return false;
            }
            // Per spec 10.4.5.3 IntegerIndexedExoticObject.[[DefineOwnProperty]]:
            // the new descriptor must be compatible with {writable: true,
            // enumerable: true, configurable: true}. Explicit false attributes
            // cause the define to fail.
            if (
                $desc->writable === false
                || $desc->enumerable === false
                || $desc->configurable === false
            ) {
                return false;
            }
            // Per spec step b.vi, the value coercion (ToNumber/ToBigInt) runs
            // via IntegerIndexedElementSet, so valueOf side effects surface
            // after the validity+descriptor checks succeed.
            if ($desc->value !== null) {
                $coerced = $this->coerceTypedArrayValue($desc->value);
                $this->setIndex($index, $coerced);
            }
            return true;
        }

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

        if (
            ctype_digit($name)
            && self::isCanonicalNumericIndex($name)
            && (int) $name < $this->getLength()
        ) {
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
        $len = $this->getLength();
        for ($i = 0; $i < $len; $i++) {
            $keys[] = (string) $i;
        }
        return array_merge($keys, parent::ownKeys());
    }

    /**
     * Override so Object.keys / Object.values / Object.entries see the
     * typed array's integer indices. JsObject's default only lists keys
     * from the property map, which doesn't store TypedArray elements.
     *
     * @return list<string>
     */
    public function getOwnPropertyNames(): array
    {
        $keys = [];
        $len = $this->getLength();
        for ($i = 0; $i < $len; $i++) {
            $keys[] = (string) $i;
        }
        return array_merge($keys, parent::getOwnPropertyNames());
    }

    /** @return list<string> */
    public function getOwnEnumerableKeys(): array
    {
        $keys = [];
        $len = $this->getLength();
        for ($i = 0; $i < $len; $i++) {
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
        $len = $this->getLength();
        if ($begin < 0) {
            $begin = max(0, $len + $begin);
        }
        $begin = min($begin, $len);

        if ($end === null) {
            $end = $len;
        } elseif ($end < 0) {
            $end = max(0, $len + $end);
        }
        $end = min($end, $len);

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
        $len = $this->getLength();
        if ($begin < 0) {
            $begin = max(0, $len + $begin);
        }
        $begin = min($begin, $len);

        if ($end === null) {
            $end = $len;
        } elseif ($end < 0) {
            $end = max(0, $len + $end);
        }
        $end = min($end, $len);

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
        $len = $this->getLength();
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
    public function fillTyped(JsValue $value, int $start = 0, ?int $end = null, ?int $capturedLen = null): self
    {
        // Per spec fill uses the length captured BEFORE argument coercion
        // for bounds clamping. Writes outside the current buffer bounds
        // silently no-op (IntegerIndexedElementSet invalidates).
        $len = $capturedLen ?? $this->getLength();
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
        $len = $this->getLength();
        $mid = (int) ($len / 2);
        for ($i = 0; $i < $mid; $i++) {
            $j = $len - 1 - $i;
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
        $len = $this->getLength();
        if ($fromIndex < 0) {
            $fromIndex = max(0, $len + $fromIndex);
        }
        for ($i = $fromIndex; $i < $len; $i++) {
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
        $len = $this->getLength();
        if ($fromIndex < 0) {
            $fromIndex = max(0, $len + $fromIndex);
        }
        for ($i = $fromIndex; $i < $len; $i++) {
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
        $len = $this->getLength();
        for ($i = 0; $i < $len; $i++) {
            $el = $this->getIndex($i);
            $parts[] = $el->toJsString();
        }
        return implode($separator, $parts);
    }

    /** Convert all elements to a list of JsValues. */
    public function toList(): array
    {
        $result = [];
        $len = $this->getLength();
        for ($i = 0; $i < $len; $i++) {
            $result[] = $this->getIndex($i);
        }
        return $result;
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

    /** SameValueZero for includes (NaN === NaN, undefined === undefined). */
    private function sameValueZero(JsValue $a, JsValue $b): bool
    {
        if ($a instanceof JsUndefined && $b instanceof JsUndefined) {
            return true;
        }
        if ($a instanceof JsNull && $b instanceof JsNull) {
            return true;
        }
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
        if ($result[0] === '-') {
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
        $len = $this->getLength();
        $max = min($len, 10);
        for ($i = 0; $i < $max; $i++) {
            $el = $this->getIndex($i);
            $parts[] = $el->display();
        }
        $suffix = $len > 10 ? ', ...' : '';
        return $this->typeName . '(' . $len . ') [ ' . implode(', ', $parts) . $suffix . ' ]';
    }

    /**
     * Decode a 16-bit unsigned integer as an IEEE 754 half-precision float.
     *
     * Format: 1 sign bit, 5 exponent bits (bias 15), 10 mantissa bits.
     */
    public static function float16Decode(int $half): float
    {
        $sign = ($half >> 15) & 1;
        $exp = ($half >> 10) & 0x1F;
        $frac = $half & 0x3FF;

        if ($exp === 0) {
            if ($frac === 0) {
                return $sign ? -0.0 : 0.0;
            }
            $val = ($frac / 1024.0) * (2.0 ** -14);
            return $sign ? -$val : $val;
        }

        if ($exp === 0x1F) {
            if ($frac === 0) {
                return $sign ? -INF : INF;
            }
            return NAN;
        }

        $val = (1.0 + $frac / 1024.0) * (2.0 ** ($exp - 15));
        return $sign ? -$val : $val;
    }

    /**
     * Encode a PHP float as an IEEE 754 half-precision 16-bit unsigned integer.
     *
     * Uses round-to-nearest-even (banker's rounding) per spec.
     */
    public static function float16Encode(float $value): int
    {
        if (is_nan($value)) {
            return 0x7E00;
        }

        $sign = 0;
        // Detect negative zero via bit pattern (avoids division by zero in PHP 8.4).
        $isNegZero = $value === 0.0
            && (unpack('J', pack('E', $value))[1] & (1 << 63)) !== 0;
        if ($value < 0.0 || $isNegZero) {
            $sign = 1;
            $value = -$value;
        }

        if ($value === INF) {
            return ($sign << 15) | 0x7C00;
        }

        if ($value === 0.0) {
            return $sign << 15;
        }

        $bits = unpack('J', pack('E', $value));
        $f64bits = $bits[1];
        $f64exp = (int) (($f64bits >> 52) & 0x7FF);
        $f64frac = $f64bits & 0x000FFFFFFFFFFFFF;
        $unbiasedExp = $f64exp - 1023;

        if ($unbiasedExp > 15) {
            return ($sign << 15) | 0x7C00;
        }

        if ($unbiasedExp >= -14) {
            $halfExp = $unbiasedExp + 15;
            $halfFrac = (int) ($f64frac >> 42);
            $remainder = $f64frac & 0x3FFFFFFFFFF;
            $halfway = 0x20000000000;
            if ($remainder > $halfway || ($remainder === $halfway && ($halfFrac & 1) !== 0)) {
                $halfFrac++;
                if ($halfFrac > 0x3FF) {
                    // halfFrac overflow rolls into halfExp. Upstream the
                    // unbiasedExp > 15 branch already returned ±Inf, so
                    // halfExp at most reaches 31 here, never the 0x1F+1
                    // overflow that would round up to ±Inf again.
                    $halfFrac = 0;
                    $halfExp++;
                }
            }
            return ($sign << 15) | ($halfExp << 10) | $halfFrac;
        }

        $shift = -14 - $unbiasedExp;
        $fullSignificand = $f64frac | (1 << 52);
        $totalShift = 42 + $shift;
        // For very small values (exp much below -24) the result rounds to
        // zero — but values just above 2^-25 must still round up to the
        // smallest subnormal. Handle totalShift up to 63 by treating the
        // whole significand as a remainder against the halfway bit.
        if ($totalShift >= 64) {
            return $sign << 15;
        }
        $halfFrac = $totalShift >= 63 ? 0 : (int) ($fullSignificand >> $totalShift);
        if ($totalShift < 63) {
            $mask = (1 << $totalShift) - 1;
            $remainder = (int) ($fullSignificand & $mask);
        } else {
            $mask = (1 << 63) - 1;
            $remainder = $fullSignificand;
        }
        $halfway = 1 << ($totalShift - 1);
        if ($remainder > $halfway || ($remainder === $halfway && ($halfFrac & 1) !== 0)) {
            $halfFrac++;
            if ($halfFrac > 0x3FF) {
                return ($sign << 15) | (1 << 10);
            }
        }
        return ($sign << 15) | $halfFrac;
    }
}
