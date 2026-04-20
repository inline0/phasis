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

    public function __construct(int $byteLength, ?JsObject $prototype = null)
    {
        parent::__construct($prototype ?? self::$defaultPrototype);

        if ($byteLength < 0 || $byteLength > self::maxAllocatableByteLength()) {
            throw new \PhpJs\Exceptions\RangeError('Invalid array buffer length');
        }

        $this->byteLength = $byteLength;
        $this->data = $byteLength === 0 ? '' : str_repeat("\0", $byteLength);
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

    public function detach(): void
    {
        $this->detached = true;
        $this->data = '';
        $this->byteLength = 0;
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
