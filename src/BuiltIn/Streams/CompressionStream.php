<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Streams;

use Phasis\BuiltIn\TextEncoderConstructor;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArrayBuffer;
use Phasis\Value\JsDataView;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsTypedArray;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * WHATWG Compression Streams — `CompressionStream` and
 * `DecompressionStream`. Both are TransformStream-backed wrappers
 * over PHP's incremental zlib context (`deflate_init` /
 * `deflate_add` and `inflate_init` / `inflate_add`).
 *
 * Supported formats: `gzip`, `deflate`, `deflate-raw`. Any other
 * string value (or non-string that ToString()s to something else)
 * makes the constructor throw a TypeError, matching the spec.
 *
 * Chunks accepted on the writable side: any BufferSource
 * (ArrayBuffer / TypedArray / DataView). Anything else is a
 * TypeError per spec. Strings are NOT auto-encoded — JS callers
 * should pipe through a TextEncoderStream first.
 *
 * Output chunks on the readable side are always Uint8Arrays.
 */
final class CompressionStream
{
    public static function install(Environment $env): void
    {
        $env->defineVar('CompressionStream', self::makeConstructor(/* compress */ true));
        $env->defineVar('DecompressionStream', self::makeConstructor(/* compress */ false));
    }

    private static function makeConstructor(bool $compress): JsFunction
    {
        $className = $compress ? 'CompressionStream' : 'DecompressionStream';
        $proto = new JsObject();
        $ctor = JsFunction::fromCallable(
            $className,
            static function (JsValue $this_, array $args) use ($proto, $compress, $className): JsValue {
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError("Constructor {$className} requires 'new'");
                }
                $formatArg = $args[0] ?? JsUndefined::instance();
                // WebIDL DOMString: ToString() can throw — let it propagate.
                $format = TypeConversion::toString($formatArg);
                $encoding = self::encodingFromFormat($format);
                if ($encoding === null) {
                    throw new TypeError(
                        "Failed to construct '{$className}': "
                        . "Unsupported format \"{$format}\". Must be one of "
                        . "\"gzip\", \"deflate\", or \"deflate-raw\".",
                    );
                }
                $this_->setPrototype($proto);
                self::initInstance($this_, $encoding, $compress);
                return $this_;
            },
            1,
        );
        $ctor->setConstructable();
        $ctor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($ctor, true, false, true));

        // Readable / writable getters bridge to the wrapped
        // TransformStream's matching slots.
        $proto->defineOwnProperty(
            'readable',
            PropertyDescriptor::accessor(
                JsFunction::fromCallable('get readable', static function (JsValue $this_) use ($className): JsValue {
                    if (!$this_ instanceof JsObject || $this_->getInternalProperty('[[IsCompressionStream]]') !== true) {
                        throw new TypeError("'{$className}.prototype.readable' getter called on incompatible receiver");
                    }
                    $inner = $this_->getInternalProperty('[[TransformStream]]');
                    if (!$inner instanceof JsObject) {
                        throw new TypeError('compression stream is uninitialised');
                    }
                    $readable = $inner->getInternalProperty('[[Readable]]');
                    return $readable instanceof JsObject ? $readable : JsUndefined::instance();
                }, 0),
                null,
                false,
                true,
            ),
        );
        $proto->defineOwnProperty(
            'writable',
            PropertyDescriptor::accessor(
                JsFunction::fromCallable('get writable', static function (JsValue $this_) use ($className): JsValue {
                    if (!$this_ instanceof JsObject || $this_->getInternalProperty('[[IsCompressionStream]]') !== true) {
                        throw new TypeError("'{$className}.prototype.writable' getter called on incompatible receiver");
                    }
                    $inner = $this_->getInternalProperty('[[TransformStream]]');
                    if (!$inner instanceof JsObject) {
                        throw new TypeError('compression stream is uninitialised');
                    }
                    $writable = $inner->getInternalProperty('[[Writable]]');
                    return $writable instanceof JsObject ? $writable : JsUndefined::instance();
                }, 0),
                null,
                false,
                true,
            ),
        );

        return $ctor;
    }

    /**
     * Map the JS-facing format string onto the ZLIB_ENCODING_*
     * constant that PHP's incremental zlib APIs accept. Returns
     * null when the format is unsupported.
     */
    private static function encodingFromFormat(string $format): ?int
    {
        return match ($format) {
            'gzip' => ZLIB_ENCODING_GZIP,
            'deflate' => ZLIB_ENCODING_DEFLATE,
            'deflate-raw' => ZLIB_ENCODING_RAW,
            default => null,
        };
    }

    /**
     * Wire the underlying TransformStream so each `write(chunk)`
     * feeds bytes into the deflate/inflate context, and the
     * context's output is enqueued onto the readable side. The
     * writer's `close()` flushes the trailing block; the readable
     * side closes once the final flush is enqueued.
     */
    private static function initInstance(JsObject $instance, int $encoding, bool $compress): void
    {
        $instance->setInternalProperty('[[IsCompressionStream]]', true);

        // Each stream owns one incremental zlib context. We carry
        // it inside a "transformer" object whose start/transform/
        // flush callables close over it.
        $ctx = $compress ? deflate_init($encoding) : inflate_init($encoding);
        if ($ctx === false) {
            throw new TypeError('Failed to initialise zlib context');
        }

        $transform = JsFunction::fromCallable(
            'transform',
            static function (JsValue $this_, array $args) use (&$ctx, $compress): JsValue {
                $chunk = $args[0] ?? JsUndefined::instance();
                $controller = $args[1] ?? null;
                if (!$controller instanceof JsObject) {
                    throw new TypeError('compression transform missing controller');
                }
                $bytes = self::bufferSourceBytes($chunk);
                if ($bytes === null) {
                    throw new TypeError(
                        'Chunk type is not supported. CompressionStream / DecompressionStream '
                        . 'only accept BufferSource chunks.',
                    );
                }
                if ($bytes === '') {
                    return JsUndefined::instance();
                }
                // Suppress the PHP-level "data error" warning that
                // inflate_add emits before returning false — WPT
                // fixtures deliberately feed corrupt streams and
                // expect the failure to surface as a TypeError on
                // the JS side, not a PHPUnit warning.
                $out = $compress
                    ? @deflate_add($ctx, $bytes, ZLIB_NO_FLUSH)
                    : @inflate_add($ctx, $bytes, ZLIB_NO_FLUSH);
                if ($out === false) {
                    throw new TypeError(
                        $compress
                            ? 'Compression failed'
                            : 'DecompressionStream input was corrupt',
                    );
                }
                if ($out !== '') {
                    TransformStream::controllerEnqueue(
                        $controller,
                        TextEncoderConstructor::makeUint8Array($out),
                    );
                }
                return JsUndefined::instance();
            },
            2,
        );

        $flush = JsFunction::fromCallable(
            'flush',
            static function (JsValue $this_, array $args) use (&$ctx, $compress): JsValue {
                $controller = $args[0] ?? null;
                if (!$controller instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $out = $compress
                    ? @deflate_add($ctx, '', ZLIB_FINISH)
                    : @inflate_add($ctx, '', ZLIB_FINISH);
                if ($out === false) {
                    throw new TypeError(
                        $compress
                            ? 'Compression flush failed'
                            : 'DecompressionStream input ended mid-stream',
                    );
                }
                if ($out !== '') {
                    TransformStream::controllerEnqueue(
                        $controller,
                        TextEncoderConstructor::makeUint8Array($out),
                    );
                }
                return JsUndefined::instance();
            },
            1,
        );

        $transformer = new JsObject();
        $transformer->defineOwnProperty(
            'transform',
            PropertyDescriptor::data($transform, true, false, true),
        );
        $transformer->defineOwnProperty(
            'flush',
            PropertyDescriptor::data($flush, true, false, true),
        );

        $ts = new JsObject(TransformStream::getPrototype());
        TransformStream::initialize($ts, $transformer, 1.0, null, 0.0, null);
        $instance->setInternalProperty('[[TransformStream]]', $ts);
    }

    /**
     * Extract raw bytes from a BufferSource chunk
     * (Uint8Array / typed view / ArrayBuffer / DataView). Returns
     * null when the chunk is not a BufferSource — caller surfaces
     * that as the spec-mandated TypeError.
     */
    private static function bufferSourceBytes(JsValue $chunk): ?string
    {
        if ($chunk instanceof JsArrayBuffer) {
            return $chunk->readBytes(0, $chunk->getByteLength());
        }
        if ($chunk instanceof JsTypedArray) {
            return $chunk->getBuffer()->readBytes(
                $chunk->getByteOffset(),
                $chunk->getLength() * $chunk->getBytesPerElement(),
            );
        }
        if ($chunk instanceof JsDataView) {
            return $chunk->getBuffer()->readBytes(
                $chunk->getByteOffset(),
                $chunk->getByteLength(),
            );
        }
        return null;
    }
}
