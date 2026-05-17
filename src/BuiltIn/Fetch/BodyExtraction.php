<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Fetch;

use Phasis\BuiltIn\BlobConstructor;
use Phasis\BuiltIn\FormDataConstructor;
use Phasis\BuiltIn\HeadersConstructor;
use Phasis\BuiltIn\Streams\ReadableStream;
use Phasis\BuiltIn\Url\SearchParams;
use Phasis\Exceptions\TypeError;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArrayBuffer;
use Phasis\Value\JsDataView;
use Phasis\Value\JsNull;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsTypedArray;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * WHATWG Fetch §6.2 "extract a body".
 *
 *  https://fetch.spec.whatwg.org/#concept-bodyinit-extract
 *
 * Given a JS body init value, returns a normalized byte string (the
 * buffered representation Phasis carries internally) plus a Content-Type
 * default and length, then populates the supplied Headers object's
 * `Content-Type` header if it is not already set.
 *
 * Phasis buffers all bodies up front. The `stream` returned by the spec's
 * extract-body algorithm is materialized on demand from the buffered
 * bytes by `BodyMixin::installBodyMixin()` (via
 * `StreamHelpers::createReadableStreamFromBytes`). Carrying the raw
 * bytes through the value type avoids re-encoding round trips on every
 * body method call and lets `clone()` be a cheap reference copy.
 *
 * Supported body sources, in spec order:
 *   - Blob / File         → bytes, Content-Type defaults to blob.type
 *   - BufferSource        → copy of underlying ArrayBuffer bytes, no
 *                           default Content-Type
 *   - FormData            → multipart/form-data with a fresh boundary
 *   - URLSearchParams     → form-urlencoded, charset=UTF-8
 *   - String              → UTF-8 bytes, text/plain;charset=UTF-8
 *   - ReadableStream      → consumed eagerly into bytes (Phasis-only
 *                           limitation; spec keeps the stream live)
 */
final class BodyExtraction
{
    /**
     * @return array{bytes:string,contentType:?string,length:?int,sourceKind:string}
     */
    public static function extractBody(
        JsValue $source,
        JsObject $headers,
        Environment $env,
        bool $keepalive = false,
    ): array {
        unset($env, $keepalive);

        if ($source instanceof JsUndefined || $source instanceof JsNull) {
            throw new \LogicException('extractBody called with null/undefined source');
        }

        // ----- Blob / File -------------------------------------------------
        if (BlobConstructor::isBlob($source)) {
            /** @var JsObject $source */
            $bytes = BlobConstructor::getBytes($source);
            $type = BlobConstructor::getType($source);
            $contentType = $type === '' ? null : $type;
            return [
                'bytes' => $bytes,
                'contentType' => $contentType,
                'length' => strlen($bytes),
                'sourceKind' => 'blob',
            ];
        }

        // ----- ArrayBuffer -------------------------------------------------
        if ($source instanceof JsArrayBuffer) {
            $bytes = $source->getData();
            return [
                'bytes' => $bytes,
                'contentType' => null,
                'length' => strlen($bytes),
                'sourceKind' => 'arraybuffer',
            ];
        }

        // ----- TypedArray / DataView (ArrayBufferView) ---------------------
        if ($source instanceof JsTypedArray) {
            $buf = $source->getBuffer();
            $offset = $source->getByteOffset();
            $byteLen = $source->getLength() * $source->getBytesPerElement();
            $bytes = $buf->readBytes($offset, $byteLen);
            return [
                'bytes' => $bytes,
                'contentType' => null,
                'length' => strlen($bytes),
                'sourceKind' => 'bufferview',
            ];
        }
        if ($source instanceof JsDataView) {
            $buf = $source->getBuffer();
            $bytes = $buf->readBytes($source->getByteOffset(), $source->getByteLength());
            return [
                'bytes' => $bytes,
                'contentType' => null,
                'length' => strlen($bytes),
                'sourceKind' => 'bufferview',
            ];
        }

        // ----- FormData ----------------------------------------------------
        if (FormDataConstructor::isFormData($source)) {
            /** @var JsObject $source */
            // An empty FormData extracts to a zero-byte body — see
            // WPT response-consume-empty.any.js ("Consume empty
            // FormData response body as text" expects length === 0).
            // Browsers skip the multipart wrapper entirely in that
            // case and we follow suit.
            $entries = FormDataConstructor::getEntries($source);
            if (empty($entries)) {
                return [
                    'bytes' => '',
                    'contentType' => 'multipart/form-data; boundary=' . self::generateBoundary(),
                    'length' => 0,
                    'sourceKind' => 'formdata',
                ];
            }
            $boundary = self::generateBoundary();
            $bytes = self::serializeFormData($source, $boundary);
            return [
                'bytes' => $bytes,
                'contentType' => 'multipart/form-data; boundary=' . $boundary,
                'length' => strlen($bytes),
                'sourceKind' => 'formdata',
            ];
        }

        // ----- URLSearchParams --------------------------------------------
        if ($source instanceof JsObject && self::isUrlSearchParams($source)) {
            $bytes = self::serializeSearchParams($source);
            return [
                'bytes' => $bytes,
                'contentType' => 'application/x-www-form-urlencoded;charset=UTF-8',
                'length' => strlen($bytes),
                'sourceKind' => 'urlsearchparams',
            ];
        }

        // ----- ReadableStream ---------------------------------------------
        // Phasis fully buffers bodies; we drain the stream synchronously
        // by walking its default-controller queue. Streams that have not
        // already enqueued all of their chunks are consumed lazily in
        // text()/json()/etc. — but for extract-body we materialize what
        // is currently available so Content-Length can be computed for
        // the request descriptor that fetch() builds.
        if (ReadableStream::isReadableStream($source)) {
            /** @var JsObject $source */
            if (ReadableStream::isReadableStreamLocked($source)) {
                throw new TypeError('Failed to construct: body stream is locked');
            }
            $chunks = self::snapshotStreamChunks($source);
            $bytes = self::drainStream($source);
            return [
                'bytes' => $bytes,
                'contentType' => null,
                'length' => null, // streams have indeterminate length per spec
                'sourceKind' => 'stream',
                // Preserve the original JS-side chunks so the host's
                // `body` stream can replay them with identity
                // preserved (response.clone() then structured-clones
                // them for the cloned half).
                'chunks' => $chunks,
            ];
        }

        // ----- String / fallback ToString ---------------------------------
        // Any other type goes through ToString per the spec's "scalar value
        // string" branch (matches text/plain;charset=UTF-8 with NUL/BOM
        // pass-through since we already store as UTF-8 bytes).
        $str = TypeConversion::toString($source);
        return [
            'bytes' => $str,
            'contentType' => 'text/plain;charset=UTF-8',
            'length' => strlen($str),
            'sourceKind' => 'string',
        ];
    }

    /**
     * After extract-body, set Content-Type on the headers object if not
     * already present. Called by Request/Response constructors with the
     * result from extractBody().
     *
     * @param array{contentType:?string} $extracted
     */
    public static function maybeSetContentType(JsObject $headers, array $extracted): void
    {
        $ct = $extracted['contentType'] ?? null;
        if ($ct === null) {
            return;
        }
        $existing = HeadersConstructor::getFromPhp($headers, 'content-type');
        if ($existing !== null) {
            return;
        }
        HeadersConstructor::appendFromPhp($headers, 'Content-Type', $ct);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private static function isUrlSearchParams(JsObject $v): bool
    {
        return $v->getInternalProperty('[[SearchParamsList]]') !== null;
    }

    /**
     * Drain a ReadableStream into a single byte string by walking the
     * default controller's internal queue. Each chunk must be a
     * BufferSource (Uint8Array / ArrayBuffer / DataView); anything else
     * is ToString'd as a defensive fallback. Closes / locks state are
     * NOT mutated — the spec's `extract-body` treats the stream as
     * opaque, so we leave it alone after reading what is currently
     * enqueued.
     */
    public static function drainStream(JsObject $stream): string
    {
        $controller = $stream->getInternalProperty('[[Controller]]');
        if (!$controller instanceof JsObject) {
            return '';
        }
        $queue = $controller->getInternalProperty('[[queue]]');
        if (!is_array($queue)) {
            return '';
        }
        $out = '';
        foreach ($queue as $entry) {
            if (!is_array($entry) || !isset($entry['value'])) {
                continue;
            }
            $out .= self::chunkToBytes($entry['value']);
        }
        return $out;
    }

    /**
     * Return the list of JS-side chunks currently enqueued on a
     * ReadableStream's default controller. Used in addition to
     * {@see drainStream()} so we can preserve the original chunk
     * references when a Response is constructed from a stream — the
     * `response.body` materializer then replays the same chunks
     * (identity preserved), and `response.clone()` structured-clones
     * each chunk for the cloned half.
     *
     * @return list<JsValue>
     */
    public static function snapshotStreamChunks(JsObject $stream): array
    {
        $controller = $stream->getInternalProperty('[[Controller]]');
        if (!$controller instanceof JsObject) {
            return [];
        }
        $queue = $controller->getInternalProperty('[[queue]]');
        if (!is_array($queue)) {
            return [];
        }
        $out = [];
        foreach ($queue as $entry) {
            if (!is_array($entry) || !isset($entry['value'])) {
                continue;
            }
            $val = $entry['value'];
            if ($val instanceof JsValue) {
                $out[] = $val;
            }
        }
        return $out;
    }

    private static function chunkToBytes(JsValue $chunk): string
    {
        if ($chunk instanceof JsString) {
            return $chunk->value;
        }
        if ($chunk instanceof JsArrayBuffer) {
            return $chunk->getData();
        }
        if ($chunk instanceof JsTypedArray) {
            $buf = $chunk->getBuffer();
            $byteLen = $chunk->getLength() * $chunk->getBytesPerElement();
            return $buf->readBytes($chunk->getByteOffset(), $byteLen);
        }
        if ($chunk instanceof JsDataView) {
            return $chunk->getBuffer()->readBytes($chunk->getByteOffset(), $chunk->getByteLength());
        }
        return TypeConversion::toString($chunk);
    }

    private static function generateBoundary(): string
    {
        try {
            $rand = random_bytes(16);
        } catch (\Throwable) {
            $rand = pack('N4', mt_rand(), mt_rand(), mt_rand(), mt_rand());
        }
        return '----phasisFormBoundary' . bin2hex($rand);
    }

    /**
     * Serialize a FormData JS object as multipart/form-data per RFC 7578.
     * Each entry value is either a JsString or a Blob/File. Blobs carry
     * a filename + content-type that we emit; plain strings get no
     * Content-Type sub-header (the consumer interprets them as text/plain
     * by default).
     */
    public static function serializeFormData(JsObject $formData, string $boundary): string
    {
        $crlf = "\r\n";
        $out = '';
        foreach (FormDataConstructor::getEntries($formData) as $entry) {
            $name = self::escapeMultipartName($entry[0]);
            $value = $entry[1];
            $out .= '--' . $boundary . $crlf;

            if (BlobConstructor::isBlob($value)) {
                /** @var JsObject $value */
                $bytes = BlobConstructor::getBytes($value);
                $type = BlobConstructor::getType($value);
                $filename = 'blob';
                if (BlobConstructor::isFile($value)) {
                    $fn = $value->getInternalProperty('[[FileName]]');
                    if (is_string($fn) && $fn !== '') {
                        $filename = $fn;
                    }
                }
                $filename = self::escapeMultipartName($filename);
                $out .= 'Content-Disposition: form-data; name="' . $name . '"; filename="' . $filename . '"' . $crlf;
                $out .= 'Content-Type: ' . ($type !== '' ? $type : 'application/octet-stream') . $crlf;
                $out .= $crlf;
                $out .= $bytes . $crlf;
            } else {
                $str = TypeConversion::toString($value);
                $out .= 'Content-Disposition: form-data; name="' . $name . '"' . $crlf;
                $out .= $crlf;
                $out .= $str . $crlf;
            }
        }
        $out .= '--' . $boundary . '--' . $crlf;
        return $out;
    }

    private static function escapeMultipartName(string $name): string
    {
        // Per HTML §multipart/form-data, replace 0x0a / 0x0d / 0x22 with
        // their %0A / %0D / %22 escapes. Other bytes pass through.
        return strtr($name, [
            "\r" => '%0D',
            "\n" => '%0A',
            '"' => '%22',
        ]);
    }

    /**
     * Serialize URLSearchParams into application/x-www-form-urlencoded
     * bytes. Delegates to SearchParams' own serializer for spec-equal
     * encoding (space → '+', etc.).
     */
    private static function serializeSearchParams(JsObject $usp): string
    {
        $list = $usp->getInternalProperty('[[SearchParamsList]]');
        if (!is_array($list)) {
            return '';
        }
        /** @var list<array{0:string,1:string}> $list */
        return SearchParams::serializeQuery($list);
    }
}
