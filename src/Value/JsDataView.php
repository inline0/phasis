<?php

declare(strict_types=1);

namespace PhpJs\Value;

/**
 * JavaScript DataView object.
 *
 * Provides mixed-type access to an ArrayBuffer with explicit byte order control.
 * Each get/set method reads or writes a specific type at a given byte offset,
 * with an optional littleEndian parameter (default is big-endian per spec).
 */
class JsDataView extends JsObject
{
    private JsArrayBuffer $buffer;
    private int $byteOffset;
    private ?int $byteLength;

    /** Whether this DataView auto-tracks the buffer's byte length. */
    private bool $autoLength;

    public function __construct(
        JsArrayBuffer $buffer,
        int $byteOffset = 0,
        ?int $byteLength = null,
        ?JsObject $prototype = null,
    ) {
        parent::__construct($prototype);
        $this->buffer = $buffer;
        $this->byteOffset = $byteOffset;
        // When constructed on a resizable buffer without explicit length,
        // the DataView auto-tracks the buffer's current byte length.
        if ($byteLength === null && $buffer->isResizable()) {
            $this->byteLength = null;
            $this->autoLength = true;
        } else {
            $this->byteLength = $byteLength ?? ($buffer->getByteLength() - $byteOffset);
            $this->autoLength = false;
        }
    }

    public function getBuffer(): JsArrayBuffer
    {
        return $this->buffer;
    }

    public function getByteOffset(): int
    {
        return $this->byteOffset;
    }

    public function getByteLength(): int
    {
        if ($this->autoLength) {
            $bufLen = $this->buffer->getByteLength();
            if ($this->byteOffset > $bufLen) {
                return 0;
            }
            return $bufLen - $this->byteOffset;
        }
        return $this->byteLength ?? 0;
    }

    /** Whether this DataView auto-tracks the buffer's byte length. */
    public function isAutoLength(): bool
    {
        return $this->autoLength;
    }

    public function get(string $name): JsValue
    {
        // Let prototype accessors handle buffer, byteOffset, byteLength so that
        // the spec-mandated detached buffer checks in the getters are executed.
        return parent::get($name);
    }

    // --- Signed integer reads ---

    public function getInt8(int $offset): int
    {
        $this->checkBounds($offset, 1);
        $val = $this->readRaw($offset, 1);
        $byte = ord($val[0]);
        return $byte >= 128 ? $byte - 256 : $byte;
    }

    public function getInt16(int $offset, bool $littleEndian = false): int
    {
        $this->checkBounds($offset, 2);
        $raw = $this->readRaw($offset, 2);
        if ($littleEndian) {
            $val = unpack('v', $raw);
        } else {
            $val = unpack('n', $raw);
        }
        $u = $val[1];
        return $u >= 32768 ? $u - 65536 : $u;
    }

    public function getInt32(int $offset, bool $littleEndian = false): int
    {
        $this->checkBounds($offset, 4);
        $raw = $this->readRaw($offset, 4);
        if ($littleEndian) {
            $val = unpack('V', $raw);
        } else {
            $val = unpack('N', $raw);
        }
        $u = $val[1];
        if ($u >= 2147483648) {
            return (int) ($u - 4294967296);
        }
        return (int) $u;
    }

    // --- Unsigned integer reads ---

    public function getUint8(int $offset): int
    {
        $this->checkBounds($offset, 1);
        $val = $this->readRaw($offset, 1);
        return ord($val[0]);
    }

    public function getUint16(int $offset, bool $littleEndian = false): int
    {
        $this->checkBounds($offset, 2);
        $raw = $this->readRaw($offset, 2);
        if ($littleEndian) {
            $val = unpack('v', $raw);
        } else {
            $val = unpack('n', $raw);
        }
        return $val[1];
    }

    public function getUint32(int $offset, bool $littleEndian = false): int
    {
        $this->checkBounds($offset, 4);
        $raw = $this->readRaw($offset, 4);
        if ($littleEndian) {
            $val = unpack('V', $raw);
        } else {
            $val = unpack('N', $raw);
        }
        return (int) $val[1];
    }

    // --- Float reads ---

    public function getFloat16(int $offset, bool $littleEndian = false): float
    {
        $this->checkBounds($offset, 2);
        $raw = $this->readRaw($offset, 2);
        if (!$littleEndian) {
            $raw = strrev($raw);
        }
        $val = unpack('v', $raw);
        return JsTypedArray::float16Decode($val[1]);
    }

    public function getFloat32(int $offset, bool $littleEndian = false): float
    {
        $this->checkBounds($offset, 4);
        $raw = $this->readRaw($offset, 4);
        if (!$littleEndian) {
            $raw = strrev($raw);
        }
        $val = unpack('g', $raw);
        return (float) $val[1];
    }

    public function getFloat64(int $offset, bool $littleEndian = false): float
    {
        $this->checkBounds($offset, 8);
        $raw = $this->readRaw($offset, 8);
        if (!$littleEndian) {
            $raw = strrev($raw);
        }
        $val = unpack('e', $raw);
        return (float) $val[1];
    }

    // --- Signed integer writes ---

    public function setInt8(int $offset, int $value): void
    {
        $this->checkBounds($offset, 1);
        $this->writeRaw($offset, chr($value & 0xFF));
    }

    public function setInt16(int $offset, int $value, bool $littleEndian = false): void
    {
        $this->checkBounds($offset, 2);
        $value = $value & 0xFFFF;
        if ($littleEndian) {
            $packed = pack('v', $value);
        } else {
            $packed = pack('n', $value);
        }
        $this->writeRaw($offset, $packed);
    }

    public function setInt32(int $offset, int $value, bool $littleEndian = false): void
    {
        $this->checkBounds($offset, 4);
        $value = $value & 0xFFFFFFFF;
        if ($littleEndian) {
            $packed = pack('V', $value);
        } else {
            $packed = pack('N', $value);
        }
        $this->writeRaw($offset, $packed);
    }

    // --- Unsigned integer writes ---

    public function setUint8(int $offset, int $value): void
    {
        $this->checkBounds($offset, 1);
        $this->writeRaw($offset, chr($value & 0xFF));
    }

    public function setUint16(int $offset, int $value, bool $littleEndian = false): void
    {
        $this->checkBounds($offset, 2);
        $value = $value & 0xFFFF;
        if ($littleEndian) {
            $packed = pack('v', $value);
        } else {
            $packed = pack('n', $value);
        }
        $this->writeRaw($offset, $packed);
    }

    public function setUint32(int $offset, int $value, bool $littleEndian = false): void
    {
        $this->checkBounds($offset, 4);
        if ($littleEndian) {
            $packed = pack('V', $value);
        } else {
            $packed = pack('N', $value);
        }
        $this->writeRaw($offset, $packed);
    }

    // --- Float writes ---

    public function setFloat16(int $offset, float $value, bool $littleEndian = false): void
    {
        $this->checkBounds($offset, 2);
        $packed = pack('v', JsTypedArray::float16Encode($value));
        if (!$littleEndian) {
            $packed = strrev($packed);
        }
        $this->writeRaw($offset, $packed);
    }

    public function setFloat32(int $offset, float $value, bool $littleEndian = false): void
    {
        $this->checkBounds($offset, 4);
        $packed = pack('g', $value);
        if (!$littleEndian) {
            $packed = strrev($packed);
        }
        $this->writeRaw($offset, $packed);
    }

    public function setFloat64(int $offset, float $value, bool $littleEndian = false): void
    {
        $this->checkBounds($offset, 8);
        $packed = pack('e', $value);
        if (!$littleEndian) {
            $packed = strrev($packed);
        }
        $this->writeRaw($offset, $packed);
    }

    // --- BigInt reads/writes ---

    public function getBigInt64(int $offset, bool $littleEndian = false): int
    {
        $this->checkBounds($offset, 8);
        $raw = $this->readRaw($offset, 8);
        if (!$littleEndian) {
            $raw = strrev($raw);
        }
        $val = unpack('q', $raw);
        return (int) $val[1];
    }

    public function getBigUint64(int $offset, bool $littleEndian = false): int
    {
        $this->checkBounds($offset, 8);
        $raw = $this->readRaw($offset, 8);
        if (!$littleEndian) {
            $raw = strrev($raw);
        }
        $val = unpack('Q', $raw);
        return (int) $val[1];
    }

    public function setBigInt64(int $offset, int $value, bool $littleEndian = false): void
    {
        $this->checkBounds($offset, 8);
        $packed = pack('q', $value);
        if (!$littleEndian) {
            $packed = strrev($packed);
        }
        $this->writeRaw($offset, $packed);
    }

    public function setBigUint64(int $offset, int $value, bool $littleEndian = false): void
    {
        $this->checkBounds($offset, 8);
        $packed = pack('Q', $value);
        if (!$littleEndian) {
            $packed = strrev($packed);
        }
        $this->writeRaw($offset, $packed);
    }

    // --- Internal helpers ---

    /**
     * Whether this DataView's view is out of bounds for its buffer.
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
            return $this->byteOffset > $bufLen;
        }
        // Fixed-length DataView on resizable buffer.
        $viewEnd = $this->byteOffset + ($this->byteLength ?? 0);
        return $viewEnd > $bufLen;
    }

    /**
     * Validate the buffer is not detached and the view is not out of bounds.
     * Per spec, this check runs after ToIndex(offset) but before the bounds check.
     */
    public function validateNotDetached(): void
    {
        if ($this->buffer->isDetached()) {
            throw new \PhpJs\Exceptions\TypeError(
                'Cannot perform DataView operation on a detached ArrayBuffer'
            );
        }
        if ($this->isOutOfBounds()) {
            throw new \PhpJs\Exceptions\TypeError(
                'Cannot perform DataView operation on an out-of-bounds DataView'
            );
        }
    }

    private function readRaw(int $offset, int $length): string
    {
        $absOffset = $this->byteOffset + $offset;
        return substr($this->buffer->getData(), $absOffset, $length);
    }

    private function writeRaw(int $offset, string $bytes): void
    {
        $absOffset = $this->byteOffset + $offset;
        $data = $this->buffer->getData();
        for ($i = 0; $i < strlen($bytes); $i++) {
            $data[$absOffset + $i] = $bytes[$i];
        }
        $this->buffer->setData($data);
    }

    private function checkBounds(int $offset, int $size): void
    {
        $len = $this->getByteLength();
        if ($offset < 0 || $offset + $size > $len) {
            throw new \PhpJs\Exceptions\RangeError(
                'Offset is outside the bounds of the DataView'
            );
        }
    }

    public function toJsString(): string
    {
        return '[object DataView]';
    }

    public function display(): string
    {
        return 'DataView { byteLength: ' . $this->byteLength
            . ', byteOffset: ' . $this->byteOffset . ' }';
    }
}
