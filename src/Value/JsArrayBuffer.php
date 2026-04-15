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
    private string $data;
    private int $byteLength;
    private bool $detached = false;

    public function __construct(int $byteLength, ?JsObject $prototype = null)
    {
        parent::__construct($prototype);
        $this->byteLength = $byteLength;
        $this->data = str_repeat("\0", $byteLength);
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
     * ArrayBuffer.prototype.slice(begin, end).
     *
     * Returns a new ArrayBuffer containing a copy of the bytes from begin
     * (inclusive) to end (exclusive).
     */
    public function sliceBuffer(int $begin, int $end): self
    {
        $begin = max(0, $begin < 0 ? $this->byteLength + $begin : $begin);
        $end = min($this->byteLength, $end < 0 ? $this->byteLength + $end : $end);
        $newLen = max(0, $end - $begin);

        $newBuffer = new self($newLen, $this->getPrototype());
        if ($newLen > 0) {
            $newBuffer->data = substr($this->data, $begin, $newLen);
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
}
