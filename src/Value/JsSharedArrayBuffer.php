<?php

declare(strict_types=1);

namespace PhpJs\Value;

/**
 * JavaScript SharedArrayBuffer object.
 *
 * Single-threaded fake: identical to ArrayBuffer but carries the
 * [[ArrayBufferData]] shared semantics flag. In a real multi-threaded
 * environment this would use shared memory; here it is just a marker
 * so that typeof checks and Atomics validation pass correctly.
 */
class JsSharedArrayBuffer extends JsArrayBuffer
{
    /** SharedArrayBuffer.prototype set during install. */
    private static ?JsObject $sharedDefaultPrototype = null;

    public static function setSharedDefaultPrototype(JsObject $proto): void
    {
        self::$sharedDefaultPrototype = $proto;
    }

    public static function getSharedDefaultPrototype(): ?JsObject
    {
        return self::$sharedDefaultPrototype;
    }

    public function __construct(int $byteLength, ?JsObject $prototype = null, ?int $maxByteLength = null)
    {
        parent::__construct($byteLength, $prototype ?? self::$sharedDefaultPrototype, $maxByteLength);
    }

    /** SharedArrayBuffer is never detachable per spec. */
    public function detach(): void
    {
        throw new \PhpJs\Exceptions\TypeError('Cannot detach a SharedArrayBuffer');
    }

    /** SharedArrayBuffer does not support transfer per spec. */
    public function transfer(?int $newLength = null): JsArrayBuffer
    {
        throw new \PhpJs\Exceptions\TypeError('Cannot transfer a SharedArrayBuffer');
    }

    /** SharedArrayBuffer does not support transferToFixedLength per spec. */
    public function transferToFixedLength(?int $newLength = null): JsArrayBuffer
    {
        throw new \PhpJs\Exceptions\TypeError('Cannot transfer a SharedArrayBuffer');
    }

    public function get(string $name): JsValue
    {
        // SharedArrayBuffer does not have a detached getter.
        if ($name === 'detached') {
            return parent::get($name);
        }
        return parent::get($name);
    }

    public function toJsString(): string
    {
        return '[object SharedArrayBuffer]';
    }

    public function display(): string
    {
        return 'SharedArrayBuffer { byteLength: ' . $this->getByteLength() . ' }';
    }
}
