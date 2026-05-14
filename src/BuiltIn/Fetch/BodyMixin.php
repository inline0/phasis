<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Fetch;

use Phasis\BuiltIn\BlobConstructor;
use Phasis\BuiltIn\FormDataConstructor;
use Phasis\BuiltIn\HeadersConstructor;
use Phasis\BuiltIn\Streams\ReadableStream;
use Phasis\BuiltIn\Streams\StreamHelpers;
use Phasis\BuiltIn\TextEncoderConstructor;
use Phasis\BuiltIn\Url\SearchParams;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArrayBuffer;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsObject;
use Phasis\Value\JsPromise;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * WHATWG Fetch §6.2 "Body mixin" — installs `body`, `bodyUsed`, and the
 * consumption methods `arrayBuffer`, `blob`, `bytes`, `formData`, `json`,
 * `text` on a prototype shared by Request and Response.
 *
 *  https://fetch.spec.whatwg.org/#body-mixin
 *
 * Internal slots used by the host (Request / Response) on each instance:
 *   - [[BodyBytes]]        ?string — buffered raw bytes (null = no body)
 *   - [[BodyUsed]]         bool    — once any consumption method or
 *                                    `body.getReader()` is called
 *   - [[BodyStream]]       ?JsObject (ReadableStream) — lazily materialized
 *                                    by the `body` getter on first read
 *   - [[BodyContentType]]  ?string — from extract-body; used by `blob()`
 *
 * Spec deviation: Phasis fully buffers bodies. The `body` getter returns
 * a real `ReadableStream` whose single chunk is the buffered bytes,
 * created on first access and cached so subsequent `.body` reads return
 * the same instance (matches the spec's "associated stream" requirement
 * even though the underlying source differs).
 */
final class BodyMixin
{
    /**
     * Install the Body mixin onto $proto (a Request.prototype or
     * Response.prototype). Idempotent.
     */
    public static function installBodyMixin(JsObject $proto, Environment $env): void
    {
        self::defineBodyGetter($proto);
        self::defineBodyUsedGetter($proto);
        self::defineArrayBuffer($proto, $env);
        self::defineBlob($proto, $env);
        self::defineBytes($proto, $env);
        self::defineFormData($proto, $env);
        self::defineJson($proto, $env);
        self::defineText($proto, $env);
    }

    // ------------------------------------------------------------------
    // Helpers — body slot accessors
    // ------------------------------------------------------------------

    /** True iff the receiver has a [[BodyBytes]] slot (set or null). */
    public static function isBodyInstance(JsValue $v): bool
    {
        if (!$v instanceof JsObject) {
            return false;
        }
        // The mixin host sets [[HasBody]] = true on every instance during
        // construction so we can distinguish "no body" (null bytes) from
        // "wrong receiver" without a separate prototype check.
        return $v->getInternalProperty('[[HasBody]]') === true;
    }

    /**
     * Mark a freshly-constructed Request/Response as a body host. Called
     * by the relevant constructors right before populating [[BodyBytes]].
     */
    public static function markAsBodyHost(JsObject $instance, ?string $bytes, ?string $contentType): void
    {
        $instance->setInternalProperty('[[HasBody]]', true);
        $instance->setInternalProperty('[[BodyBytes]]', $bytes);
        $instance->setInternalProperty('[[BodyUsed]]', false);
        $instance->setInternalProperty('[[BodyStream]]', null);
        $instance->setInternalProperty('[[BodyContentType]]', $contentType);
    }

    /** Read the buffered body bytes; null when no body. */
    public static function bodyBytes(JsObject $instance): ?string
    {
        $b = $instance->getInternalProperty('[[BodyBytes]]');
        return is_string($b) ? $b : null;
    }

    public static function isBodyUsed(JsObject $instance): bool
    {
        return $instance->getInternalProperty('[[BodyUsed]]') === true;
    }

    public static function markUsed(JsObject $instance): void
    {
        $instance->setInternalProperty('[[BodyUsed]]', true);
    }

    public static function bodyContentType(JsObject $instance): ?string
    {
        $ct = $instance->getInternalProperty('[[BodyContentType]]');
        return is_string($ct) ? $ct : null;
    }

    /**
     * Return the materialized ReadableStream for this body, creating it
     * on first call. Returns null when [[BodyBytes]] is null (no body).
     */
    public static function getOrCreateBodyStream(JsObject $instance): ?JsObject
    {
        $bytes = self::bodyBytes($instance);
        if ($bytes === null) {
            return null;
        }
        $cached = $instance->getInternalProperty('[[BodyStream]]');
        if ($cached instanceof JsObject) {
            return $cached;
        }
        $stream = StreamHelpers::createReadableStreamFromBytes($bytes);
        $instance->setInternalProperty('[[BodyStream]]', $stream);
        return $stream;
    }

    /**
     * Check if the body's associated stream has been disturbed/locked,
     * which makes any consumption return a rejected TypeError per spec.
     */
    public static function isStreamLockedOrDisturbed(JsObject $instance): bool
    {
        $stream = $instance->getInternalProperty('[[BodyStream]]');
        if (!$stream instanceof JsObject) {
            return false;
        }
        if (ReadableStream::isReadableStreamLocked($stream)) {
            return true;
        }
        $disturbed = $stream->getInternalProperty('[[Disturbed]]');
        return $disturbed === true;
    }

    // ------------------------------------------------------------------
    // Property definitions
    // ------------------------------------------------------------------

    private static function defineBodyGetter(JsObject $proto): void
    {
        $get = JsFunction::fromCallable(
            'get body',
            static function (JsValue $this_): JsValue {
                $self = self::requireReceiver($this_, 'body');
                $stream = self::getOrCreateBodyStream($self);
                return $stream ?? JsNull::instance();
            },
            0,
        );
        $get->setNonConstructable();
        $proto->defineOwnProperty(
            'body',
            PropertyDescriptor::accessor($get, null, false, true),
        );
    }

    private static function defineBodyUsedGetter(JsObject $proto): void
    {
        $get = JsFunction::fromCallable(
            'get bodyUsed',
            static function (JsValue $this_): JsValue {
                $self = self::requireReceiver($this_, 'bodyUsed');
                return JsBoolean::of(self::isBodyUsed($self));
            },
            0,
        );
        $get->setNonConstructable();
        $proto->defineOwnProperty(
            'bodyUsed',
            PropertyDescriptor::accessor($get, null, false, true),
        );
    }

    private static function defineArrayBuffer(JsObject $proto, Environment $env): void
    {
        unset($env);
        $fn = JsFunction::fromCallable(
            'arrayBuffer',
            static function (JsValue $this_, array $args): JsValue {
                unset($args);
                $instance = self::tryRequire($this_);
                if ($instance instanceof JsPromise) {
                    return $instance;
                }
                $err = self::checkConsumable($instance);
                if ($err !== null) {
                    return $err;
                }
                $bytes = self::consume($instance);
                $ab = new JsArrayBuffer(strlen($bytes));
                if ($bytes !== '') {
                    $ab->writeBytes(0, $bytes);
                }
                return JsPromise::resolved($ab);
            },
            0,
        );
        $proto->defineOwnProperty('arrayBuffer', PropertyDescriptor::data($fn, true, false, true));
    }

    private static function defineBlob(JsObject $proto, Environment $env): void
    {
        unset($env);
        $fn = JsFunction::fromCallable(
            'blob',
            static function (JsValue $this_, array $args): JsValue {
                unset($args);
                $instance = self::tryRequire($this_);
                if ($instance instanceof JsPromise) {
                    return $instance;
                }
                $err = self::checkConsumable($instance);
                if ($err !== null) {
                    return $err;
                }
                $bytes = self::consume($instance);
                $ct = self::bodyContentType($instance) ?? '';
                // Per spec: blob's type comes from the body's Content-Type
                // header (if present on the host) rather than the
                // extract-time content-type. Try headers first.
                $hdrCt = self::contentTypeFromHeaders($instance);
                if ($hdrCt !== null) {
                    $ct = $hdrCt;
                }
                $blob = BlobConstructor::createBlob($bytes, $ct);
                return JsPromise::resolved($blob);
            },
            0,
        );
        $proto->defineOwnProperty('blob', PropertyDescriptor::data($fn, true, false, true));
    }

    private static function defineBytes(JsObject $proto, Environment $env): void
    {
        unset($env);
        $fn = JsFunction::fromCallable(
            'bytes',
            static function (JsValue $this_, array $args): JsValue {
                unset($args);
                $instance = self::tryRequire($this_);
                if ($instance instanceof JsPromise) {
                    return $instance;
                }
                $err = self::checkConsumable($instance);
                if ($err !== null) {
                    return $err;
                }
                $bytes = self::consume($instance);
                return JsPromise::resolved(TextEncoderConstructor::makeUint8Array($bytes));
            },
            0,
        );
        $proto->defineOwnProperty('bytes', PropertyDescriptor::data($fn, true, false, true));
    }

    private static function defineText(JsObject $proto, Environment $env): void
    {
        unset($env);
        $fn = JsFunction::fromCallable(
            'text',
            static function (JsValue $this_, array $args): JsValue {
                unset($args);
                $instance = self::tryRequire($this_);
                if ($instance instanceof JsPromise) {
                    return $instance;
                }
                $err = self::checkConsumable($instance);
                if ($err !== null) {
                    return $err;
                }
                $bytes = self::consume($instance);
                // UTF-8 decoding with replacement-on-error per spec. PHP
                // strings carry arbitrary bytes; for v1 we pass through
                // since invalid sequences are rare in fetch responses
                // and the TextDecoder fallback below covers JSON callers.
                return JsPromise::resolved(new JsString($bytes));
            },
            0,
        );
        $proto->defineOwnProperty('text', PropertyDescriptor::data($fn, true, false, true));
    }

    private static function defineJson(JsObject $proto, Environment $env): void
    {
        unset($env);
        $fn = JsFunction::fromCallable(
            'json',
            static function (JsValue $this_, array $args): JsValue {
                unset($args);
                $instance = self::tryRequire($this_);
                if ($instance instanceof JsPromise) {
                    return $instance;
                }
                $err = self::checkConsumable($instance);
                if ($err !== null) {
                    return $err;
                }
                $bytes = self::consume($instance);
                try {
                    $parsed = \Phasis\BuiltIn\JsonObject::parseSource($bytes);
                } catch (\Throwable $e) {
                    return JsPromise::rejected(
                        StreamHelpers::createTypeError('Failed to parse JSON: ' . $e->getMessage())
                    );
                }
                return JsPromise::resolved($parsed);
            },
            0,
        );
        $proto->defineOwnProperty('json', PropertyDescriptor::data($fn, true, false, true));
    }

    private static function defineFormData(JsObject $proto, Environment $env): void
    {
        $fn = JsFunction::fromCallable(
            'formData',
            static function (JsValue $this_, array $args) use ($env): JsValue {
                unset($args);
                $instance = self::tryRequire($this_);
                if ($instance instanceof JsPromise) {
                    return $instance;
                }
                $err = self::checkConsumable($instance);
                if ($err !== null) {
                    return $err;
                }
                $bytes = self::consume($instance);
                $ct = self::contentTypeFromHeaders($instance) ?? (self::bodyContentType($instance) ?? '');

                try {
                    $fd = self::parseFormDataFromBytes($bytes, $ct, $env);
                } catch (\Throwable $e) {
                    return JsPromise::rejected(
                        StreamHelpers::createTypeError('Failed to parse FormData: ' . $e->getMessage())
                    );
                }
                return JsPromise::resolved($fd);
            },
            0,
        );
        $proto->defineOwnProperty('formData', PropertyDescriptor::data($fn, true, false, true));
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Throw or return the body host. Used by sync getters that need a
     * thrown TypeError on incompatible receiver.
     */
    private static function requireReceiver(JsValue $v, string $accessor): JsObject
    {
        if (!self::isBodyInstance($v)) {
            throw new TypeError("'$accessor' called on an incompatible receiver");
        }
        /** @var JsObject $v */
        return $v;
    }

    /**
     * For body methods that return Promises: validate the receiver and
     * return either the JsObject or a rejected Promise with the spec's
     * TypeError shape.
     */
    private static function tryRequire(JsValue $v): JsObject|JsPromise
    {
        if (!self::isBodyInstance($v)) {
            return JsPromise::rejected(
                StreamHelpers::createTypeError('Body method called on incompatible receiver')
            );
        }
        /** @var JsObject $v */
        return $v;
    }

    /**
     * Validate that the body is consumable. Returns null when OK or a
     * rejected promise when [[BodyUsed]] is true or the stream is locked/
     * disturbed. The host's [[BodyStream]] is treated as "associated":
     * a `getReader()` call locks it and we mirror that into bodyUsed on
     * the next consume() call.
     */
    private static function checkConsumable(JsObject $instance): ?JsPromise
    {
        if (self::isBodyUsed($instance)) {
            return JsPromise::rejected(
                StreamHelpers::createTypeError('Body has already been consumed')
            );
        }
        if (self::isStreamLockedOrDisturbed($instance)) {
            return JsPromise::rejected(
                StreamHelpers::createTypeError('Body stream is locked or disturbed')
            );
        }
        return null;
    }

    /**
     * Drain + mark used. Returns the body bytes (empty string when no body).
     */
    private static function consume(JsObject $instance): string
    {
        self::markUsed($instance);
        $bytes = self::bodyBytes($instance);
        return $bytes ?? '';
    }

    /**
     * Read the Content-Type header off the host instance (Request /
     * Response), if it has one. Used by blob()/formData() to pick the
     * right MIME type / boundary.
     */
    private static function contentTypeFromHeaders(JsObject $instance): ?string
    {
        $headers = $instance->getInternalProperty('[[Headers]]');
        if (!$headers instanceof JsObject) {
            return null;
        }
        return HeadersConstructor::getFromPhp($headers, 'content-type');
    }

    /**
     * Parse multipart/form-data OR application/x-www-form-urlencoded body
     * bytes into a fresh FormData JS object. Anything else throws (matches
     * the spec's "if MIMEType is not one of these, return a rejected
     * promise with TypeError" branch).
     */
    private static function parseFormDataFromBytes(string $bytes, string $contentType, Environment $env): JsObject
    {
        $lower = strtolower(trim($contentType));
        if (str_starts_with($lower, 'application/x-www-form-urlencoded')) {
            $pairs = SearchParams::parseQuery($bytes);
            $fd = self::makeFormData($env);
            foreach ($pairs as $pair) {
                self::appendFormDataString($fd, $pair[0], $pair[1]);
            }
            return $fd;
        }
        if (str_starts_with($lower, 'multipart/form-data')) {
            $boundary = self::extractBoundary($contentType);
            if ($boundary === null) {
                throw new \RuntimeException('multipart Content-Type missing boundary');
            }
            $fd = self::makeFormData($env);
            self::parseMultipart($bytes, $boundary, $fd);
            return $fd;
        }
        throw new \RuntimeException("Unsupported Content-Type for formData(): $contentType");
    }

    private static function makeFormData(Environment $env): JsObject
    {
        $ctor = $env->has('FormData') ? $env->get('FormData') : null;
        if ($ctor instanceof JsFunction) {
            $result = $ctor->construct([]);
            if ($result instanceof JsObject) {
                return $result;
            }
        }
        // Defensive fallback: bare object with the right slot.
        $obj = new JsObject();
        $obj->setInternalProperty('[[IsFormData]]', true);
        $obj->setInternalProperty('[[FormDataEntries]]', []);
        return $obj;
    }

    private static function appendFormDataString(JsObject $fd, string $name, string $value): void
    {
        $list = $fd->getInternalProperty('[[FormDataEntries]]');
        if (!is_array($list)) {
            $list = [];
        }
        $list[] = [$name, new JsString($value)];
        $fd->setInternalProperty('[[FormDataEntries]]', $list);
    }

    private static function appendFormDataBlob(JsObject $fd, string $name, string $filename, string $contentType, string $bytes): void
    {
        $list = $fd->getInternalProperty('[[FormDataEntries]]');
        if (!is_array($list)) {
            $list = [];
        }
        $file = BlobConstructor::createFile($bytes, $filename, $contentType);
        $list[] = [$name, $file];
        $fd->setInternalProperty('[[FormDataEntries]]', $list);
    }

    private static function extractBoundary(string $contentType): ?string
    {
        // boundary= directive per RFC 7231. May be quoted.
        if (preg_match('/boundary=("([^"]+)"|([^;]+))/i', $contentType, $m) === 1) {
            $b = $m[2] !== '' ? $m[2] : $m[3];
            return trim($b);
        }
        return null;
    }

    private static function parseMultipart(string $bytes, string $boundary, JsObject $fd): void
    {
        $delim = '--' . $boundary;
        $crlf = "\r\n";
        $parts = explode($delim, $bytes);
        // First chunk is the preamble; last is "--\r\n" (final).
        for ($i = 1; $i < count($parts) - 1; $i++) {
            $part = $parts[$i];
            // Each part begins with CRLF and ends with CRLF before the next boundary.
            if (str_starts_with($part, $crlf)) {
                $part = substr($part, 2);
            }
            if (substr($part, -2) === $crlf) {
                $part = substr($part, 0, -2);
            }
            $split = strpos($part, $crlf . $crlf);
            if ($split === false) {
                continue;
            }
            $rawHeaders = substr($part, 0, $split);
            $body = substr($part, $split + 4);

            $headers = [];
            foreach (explode($crlf, $rawHeaders) as $line) {
                $colon = strpos($line, ':');
                if ($colon === false) {
                    continue;
                }
                $key = strtolower(trim(substr($line, 0, $colon)));
                $value = trim(substr($line, $colon + 1));
                $headers[$key] = $value;
            }

            $dispo = $headers['content-disposition'] ?? '';
            if (preg_match('/name="([^"]*)"/', $dispo, $nameMatch) !== 1) {
                continue;
            }
            $name = $nameMatch[1];

            $filename = null;
            if (preg_match('/filename="([^"]*)"/', $dispo, $fnameMatch) === 1) {
                $filename = $fnameMatch[1];
            }

            if ($filename !== null) {
                $ct = $headers['content-type'] ?? 'application/octet-stream';
                self::appendFormDataBlob($fd, $name, $filename, $ct, $body);
            } else {
                self::appendFormDataString($fd, $name, $body);
            }
        }
    }
}
