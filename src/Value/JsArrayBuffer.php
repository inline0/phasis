<?php

declare(strict_types=1);

namespace PhpJs\Value;

/**
 * JavaScript ArrayBuffer object.
 *
 * Wraps a PHP string as raw binary data. Each character in the PHP string
 * represents one byte. Uses pack/unpack for all typed access.
 */
class JsArrayBuffer extends JsObject
{
    private const FALLBACK_MAX_BYTE_LENGTH = 268435456; // 256 MiB
    private const MIN_SAFE_BYTE_LENGTH = 16777216; // 16 MiB

    /** ArrayBuffer.prototype set during installArrayBuffer(). */
    private static ?JsObject $defaultPrototype = null;

    public static function setDefaultPrototype(JsObject $proto): void
    {
        self::$defaultPrototype = $proto;
    }

    public static function getDefaultPrototype(): ?JsObject
    {
        return self::$defaultPrototype;
    }

    private string $data;
    private int $byteLength;
    private bool $detached = false;

    /** Maximum byte length for resizable buffers. null means fixed-length. */
    private ?int $maxByteLength = null;

    /** Whether this buffer was created as resizable (preserved across detach). */
    private bool $wasResizable = false;

    public function __construct(int $byteLength, ?JsObject $prototype = null, ?int $maxByteLength = null)
    {
        parent::__construct($prototype ?? self::$defaultPrototype);

        if ($byteLength < 0 || $byteLength > self::maxAllocatableByteLength()) {
            throw new \PhpJs\Exceptions\RangeError('Invalid array buffer length');
        }

        if ($maxByteLength !== null) {
            if ($maxByteLength < 0 || $maxByteLength > self::maxAllocatableByteLength()) {
                throw new \PhpJs\Exceptions\RangeError('Invalid array buffer length');
            }
            if ($byteLength > $maxByteLength) {
                throw new \PhpJs\Exceptions\RangeError('Invalid array buffer length');
            }
            $this->maxByteLength = $maxByteLength;
            $this->wasResizable = true;
            // Allocate maxByteLength capacity but only expose byteLength.
            $this->data = $maxByteLength === 0 ? '' : str_repeat("\0", $maxByteLength);
        } else {
            $this->data = $byteLength === 0 ? '' : str_repeat("\0", $byteLength);
        }

        $this->byteLength = $byteLength;
    }

    public function getByteLength(): int
    {
        return $this->byteLength;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function setData(string $data): void
    {
        $this->data = $data;
    }

    public function isDetached(): bool
    {
        return $this->detached;
    }

    /** Whether this is a resizable ArrayBuffer (preserved across detach). */
    public function isResizable(): bool
    {
        if ($this->detached) {
            return $this->wasResizable;
        }
        return $this->maxByteLength !== null;
    }

    /** Return the max byte length, or the current byteLength for fixed buffers. */
    public function getMaxByteLength(): int
    {
        return $this->maxByteLength ?? $this->byteLength;
    }

    /**
     * Resize a resizable ArrayBuffer.
     *
     * Per spec: throws TypeError if not resizable, RangeError if newByteLength > maxByteLength.
     */
    public function resize(int $newByteLength): void
    {
        if ($this->detached) {
            throw new \PhpJs\Exceptions\TypeError(
                'Cannot resize a detached ArrayBuffer'
            );
        }
        if ($this->maxByteLength === null) {
            throw new \PhpJs\Exceptions\TypeError(
                'ArrayBuffer is not resizable'
            );
        }
        if ($newByteLength < 0 || $newByteLength > $this->maxByteLength) {
            throw new \PhpJs\Exceptions\RangeError(
                'Invalid array buffer length'
            );
        }
        // Zero-fill new bytes if growing, truncate tracking if shrinking.
        // The backing store is always maxByteLength; we only change the exposed length.
        // Zero out bytes beyond newByteLength when shrinking so re-grow gives zeros.
        if ($newByteLength < $this->byteLength) {
            for ($i = $newByteLength; $i < $this->byteLength; $i++) {
                $this->data[$i] = "\0";
            }
        }
        $this->byteLength = $newByteLength;
    }

    /**
     * ArrayBuffer.prototype.transfer(newLength?).
     *
     * Creates a new ArrayBuffer with the data from this buffer, detaches this buffer.
     * If no newLength, uses the current byteLength.
     * The new buffer is always resizable if this buffer was resizable (with same maxByteLength),
     * unless newLength > maxByteLength, in which case the new buffer is fixed.
     */
    public function transfer(?int $newLength = null): self
    {
        if ($this->detached) {
            throw new \PhpJs\Exceptions\TypeError(
                'Cannot transfer a detached ArrayBuffer'
            );
        }

        $newLen = $newLength ?? $this->byteLength;
        if ($newLen < 0) {
            throw new \PhpJs\Exceptions\RangeError('Invalid array buffer length');
        }

        // Determine if the new buffer should be resizable.
        $newMax = null;
        if ($this->maxByteLength !== null) {
            if ($newLen <= $this->maxByteLength) {
                $newMax = $this->maxByteLength;
            }
            // If newLen > maxByteLength, the new buffer is fixed-length.
        }

        $newBuffer = new self($newLen, $this->getPrototype(), $newMax);

        // Copy data.
        $copyLen = min($newLen, $this->byteLength);
        if ($copyLen > 0) {
            $newData = $newBuffer->data;
            for ($i = 0; $i < $copyLen; $i++) {
                $newData[$i] = $this->data[$i];
            }
            $newBuffer->data = $newData;
        }

        $this->detach();

        return $newBuffer;
    }

    /**
     * ArrayBuffer.prototype.transferToFixedLength(newLength?).
     *
     * Like transfer, but the new buffer is always fixed-length.
     */
    public function transferToFixedLength(?int $newLength = null): self
    {
        if ($this->detached) {
            throw new \PhpJs\Exceptions\TypeError(
                'Cannot transfer a detached ArrayBuffer'
            );
        }

        $newLen = $newLength ?? $this->byteLength;
        if ($newLen < 0) {
            throw new \PhpJs\Exceptions\RangeError('Invalid array buffer length');
        }

        // Always fixed-length (no maxByteLength).
        $newBuffer = new self($newLen, $this->getPrototype(), null);

        // Copy data.
        $copyLen = min($newLen, $this->byteLength);
        if ($copyLen > 0) {
            $newData = $newBuffer->data;
            for ($i = 0; $i < $copyLen; $i++) {
                $newData[$i] = $this->data[$i];
            }
            $newBuffer->data = $newData;
        }

        $this->detach();

        return $newBuffer;
    }

    public function detach(): void
    {
        $this->detached = true;
        $this->data = '';
        $this->byteLength = 0;
        $this->maxByteLength = null;
    }

    /** Read a single byte at the given offset. */
    public function getByte(int $offset): int
    {
        if ($offset < 0 || $offset >= $this->byteLength) {
            return 0;
        }
        return ord($this->data[$offset]);
    }

    /** Write a single byte at the given offset. */
    public function setByte(int $offset, int $value): void
    {
        if ($offset < 0 || $offset >= $this->byteLength) {
            return;
        }
        $this->data[$offset] = chr($value & 0xFF);
    }

    /**
     * ArrayBuffer.prototype.slice(begin, end) core logic.
     *
     * Returns a new ArrayBuffer containing a copy of the bytes from begin
     * (inclusive) to end (exclusive). Accepts float to handle Infinity.
     * The caller (TypedArrayConstructor) handles SpeciesConstructor.
     *
     * @return array{int, int, string} Tuple of [newLen, begin, slicedData].
     */
    public function computeSlice(float $begin, float $end): array
    {
        $len = $this->byteLength;

        // Resolve relative begin per spec.
        if ($begin < 0) {
            $first = (int) max($len + $begin, 0);
        } else {
            $first = (int) min($begin, $len);
        }

        // Resolve relative end per spec.
        if ($end < 0) {
            $final = (int) max($len + $end, 0);
        } else {
            $final = (int) min($end, $len);
        }

        $newLen = max(0, $final - $first);
        $slicedData = $newLen > 0 ? substr($this->data, $first, $newLen) : '';

        return [$newLen, $first, $slicedData];
    }

    /**
     * Simple slice that creates a new ArrayBuffer directly.
     * Used when SpeciesConstructor is not needed.
     */
    public function sliceBuffer(int $begin, int $end): self
    {
        [$newLen, , $slicedData] = $this->computeSlice((float) $begin, (float) $end);
        $newBuffer = new self($newLen, $this->getPrototype());
        if ($newLen > 0) {
            $newBuffer->data = $slicedData;
        }
        return $newBuffer;
    }

    public function get(string $name): JsValue
    {
        if ($name === 'byteLength') {
            return new JsNumber((float) $this->byteLength);
        }
        if ($name === 'maxByteLength') {
            return new JsNumber((float) $this->getMaxByteLength());
        }
        if ($name === 'resizable') {
            return new JsBoolean($this->isResizable());
        }
        if ($name === 'detached') {
            return new JsBoolean($this->detached);
        }

        return parent::get($name);
    }

    public function toJsString(): string
    {
        return '[object ArrayBuffer]';
    }

    public function display(): string
    {
        return 'ArrayBuffer { byteLength: ' . $this->byteLength . ' }';
    }

    private static function maxAllocatableByteLength(): int
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $memoryLimit = self::parseIniByteSize((string) ini_get('memory_limit'));
        if ($memoryLimit === null || $memoryLimit <= 0) {
            return $cached = self::FALLBACK_MAX_BYTE_LENGTH;
        }

        $reserved = 64 * 1024 * 1024;
        $usable = max(self::MIN_SAFE_BYTE_LENGTH, $memoryLimit - $reserved);

        return $cached = max(
            self::MIN_SAFE_BYTE_LENGTH,
            min(self::FALLBACK_MAX_BYTE_LENGTH, intdiv($usable, 2)),
        );
    }

    private static function parseIniByteSize(string $value): ?int
    {
        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === '-1') {
            return null;
        }

        if (!preg_match('/^(?<number>\d+)(?<suffix>[KMG]?)$/i', $trimmed, $matches)) {
            return null;
        }

        $bytes = (int) $matches['number'];
        $suffix = strtoupper($matches['suffix']);

        return match ($suffix) {
            'G' => $bytes * 1024 * 1024 * 1024,
            'M' => $bytes * 1024 * 1024,
            'K' => $bytes * 1024,
            default => $bytes,
        };
    }
}
