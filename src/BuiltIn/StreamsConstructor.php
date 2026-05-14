<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\BuiltIn\Streams\ByteStream;
use Phasis\BuiltIn\Streams\QueuingStrategy;
use Phasis\BuiltIn\Streams\ReadableStream;
use Phasis\BuiltIn\Streams\TransformStream;
use Phasis\BuiltIn\Streams\WritableStream;
use Phasis\Runtime\Environment;

/**
 * Entry point for the WHATWG Streams API.
 *
 * Installs the 14 streams-related globals on the given environment:
 *   - ReadableStream
 *   - ReadableStreamDefaultController
 *   - ReadableStreamDefaultReader
 *   - ReadableByteStreamController
 *   - ReadableStreamBYOBReader
 *   - ReadableStreamBYOBRequest
 *   - WritableStream
 *   - WritableStreamDefaultController
 *   - WritableStreamDefaultWriter
 *   - TransformStream
 *   - TransformStreamDefaultController
 *   - ByteLengthQueuingStrategy
 *   - CountQueuingStrategy
 *
 * Spec: https://streams.spec.whatwg.org/
 */
final class StreamsConstructor
{
    public static function install(Environment $env): void
    {
        // Order matters: ReadableStream registers prototypes used by TransformStream.
        ReadableStream::install($env);
        ByteStream::install($env);
        WritableStream::install($env);
        TransformStream::install($env);
        QueuingStrategy::install($env);
    }
}
