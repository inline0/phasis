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
use Phasis\Value\JsTypedArray;
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
 * Byte-backed bodies materialize a stream on first access. Stream-backed
 * bodies retain the original stream, and consumption awaits every chunk.
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
    /**
     * Mark an object as a body host (Request/Response) and stash the
     * buffered bytes + (optionally) the original JS-side chunks list
     * that {@see BodyExtraction::extractBody()} captured when the
     * source was a ReadableStream. The chunks list is replayed by
     * `getOrCreateBodyStream()` so reads see the same JS values that
     * were enqueued upstream, satisfying the WPT structureClone tests.
     *
     * @param list<\Phasis\Value\JsValue>|null $chunks
     */
    public static function markAsBodyHost(
        JsObject $instance,
        ?string $bytes,
        ?string $contentType,
        ?array $chunks = null,
        ?JsObject $stream = null,
    ): void {
        $instance->setInternalProperty('[[HasBody]]', true);
        $instance->setInternalProperty('[[BodyBytes]]', $bytes);
        $instance->setInternalProperty('[[BodyChunks]]', $chunks);
        $instance->setInternalProperty('[[BodyUsed]]', false);
        $instance->setInternalProperty('[[BodyStream]]', $stream);
        $instance->setInternalProperty('[[BodyContentType]]', $contentType);
        $instance->setInternalProperty('[[BodyReplayable]]', $stream === null);
    }

    /** Read the buffered body bytes; null when no body. */
    public static function bodyBytes(JsObject $instance): ?string
    {
        $b = $instance->getInternalProperty('[[BodyBytes]]');
        return is_string($b) ? $b : null;
    }

    public static function isBodyUsed(JsObject $instance): bool
    {
        // Per spec, bodyUsed is `body is null ? false : body.stream
        // is disturbed`. Mirror that: an explicit [[BodyUsed]] flag
        // (set by our text() / blob() / etc. paths) wins; otherwise
        // fall back to the stream's [[Disturbed]] state, which
        // ReadableStream.cancel() / getReader().read() set.
        if ($instance->getInternalProperty('[[BodyUsed]]') === true) {
            return true;
        }
        $stream = $instance->getInternalProperty('[[BodyStream]]');
        if ($stream instanceof JsObject && $stream->getInternalProperty('[[Disturbed]]') === true) {
            return true;
        }
        return false;
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
        // If the original body was a ReadableStream we kept the
        // enqueued JS values around — re-use them so .body reads see
        // the same chunks (and same identity for the original side,
        // structured-cloned for the cloned side).
        $chunks = $instance->getInternalProperty('[[BodyChunks]]');
        if (is_array($chunks) && !empty($chunks)) {
            $stream = StreamHelpers::createReadableStreamFromChunks($chunks);
        } else {
            $stream = StreamHelpers::createReadableStreamFromBytes($bytes);
        }
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
                return self::consumeConverted($instance, static function (string $bytes): JsValue {
                    $ab = new JsArrayBuffer(strlen($bytes));
                    if ($bytes !== '') {
                        $ab->writeBytes(0, $bytes);
                    }
                    return $ab;
                });
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
                $ct = self::contentTypeFromHeaders($instance) ?? '';
                return self::consumeConverted(
                    $instance,
                    static fn (string $bytes): JsValue => BlobConstructor::createBlob($bytes, $ct),
                );
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
                return self::consumeConverted(
                    $instance,
                    static fn (string $bytes): JsValue => TextEncoderConstructor::makeUint8Array($bytes),
                );
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
                return self::consumeConverted(
                    $instance,
                    static fn (string $bytes): JsValue => new JsString($bytes),
                );
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
                return self::consumeConverted($instance, static function (string $bytes): JsValue {
                    try {
                        return \Phasis\BuiltIn\JsonObject::parseSource($bytes);
                    } catch (\Throwable $e) {
                        return JsPromise::rejected(
                            StreamHelpers::createTypeError('Failed to parse JSON: ' . $e->getMessage()),
                        );
                    }
                });
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
                $ct = self::contentTypeFromHeaders($instance) ?? (self::bodyContentType($instance) ?? '');
                $rawBytes = self::bodyBytes($instance);
                // Per spec, a Response with a null body and a
                // multipart/form-data Content-Type rejects with
                // TypeError — there's no payload to find the
                // boundary delimiters in. The urlencoded branch is
                // tolerant: an empty body parses as an empty list.
                if ($rawBytes === null && str_starts_with(strtolower(trim($ct)), 'multipart/form-data')) {
                    return JsPromise::rejected(
                        StreamHelpers::createTypeError('Failed to parse FormData: no body')
                    );
                }
                return self::consumeConverted($instance, static function (string $bytes) use ($ct, $env): JsValue {
                    try {
                        return self::parseFormDataFromBytes($bytes, $ct, $env);
                    } catch (\Throwable $e) {
                        return JsPromise::rejected(
                            StreamHelpers::createTypeError('Failed to parse FormData: ' . $e->getMessage()),
                        );
                    }
                });
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
     * Consume the associated stream, preserving asynchronous production,
     * backpressure and rejection. Resolves with its complete byte string.
     */
    public static function consumeBytes(JsObject $instance): JsPromise
    {
        $error = self::checkConsumable($instance);
        if ($error !== null) {
            return $error;
        }
        $stream = self::getOrCreateBodyStream($instance);
        if ($stream === null) {
            return JsPromise::resolved(new JsString(''));
        }
        self::markUsed($instance);
        $reader = ReadableStream::acquireDefaultReader($stream);
        $result = new JsPromise();
        $bytes = '';
        $pump = null;
        $pump = static function () use ($reader, $result, $instance, &$bytes, &$pump): void {
            $read = ReadableStream::defaultReaderRead($reader);
            $read->addFulfillHandler(static function (JsValue $value) use ($result, $instance, &$bytes, &$pump): void {
                if (!$value instanceof JsObject) {
                    $result->reject(StreamHelpers::createTypeError('Invalid body stream read result'));
                    return;
                }
                if (TypeConversion::toBoolean($value->get('done'))) {
                    $instance->setInternalProperty('[[BodyBytes]]', $bytes);
                    $result->resolve(new JsString($bytes));
                    return;
                }
                $chunk = $value->get('value');
                if (!$chunk instanceof JsTypedArray || $chunk->getTypeName() !== 'Uint8Array') {
                    $result->reject(StreamHelpers::createTypeError('Body stream chunks must be Uint8Array values'));
                    return;
                }
                $bytes .= $chunk->getBuffer()->readBytes($chunk->getByteOffset(), $chunk->getLength());
                JsPromise::scheduleCallback($pump);
            });
            $read->addRejectHandler(static function (JsValue $reason) use ($result): void {
                $result->reject($reason);
            });
        };
        $pump();
        return $result;
    }

    /** @param \Closure(string): JsValue $convert */
    private static function consumeConverted(JsObject $instance, \Closure $convert): JsPromise
    {
        return self::consumeBytes($instance)->then([JsFunction::fromCallable(
            '',
            static function (JsValue $_this, array $args) use ($convert): JsValue {
                $bytes = $args[0] ?? JsUndefined::instance();
                return $convert($bytes instanceof JsString ? $bytes->value : '');
            },
            1,
        )]);
    }

    /** Clone a body by teeing its live stream, cloning chunks on the second branch. */
    public static function cloneBody(JsObject $source, JsObject $target): void
    {
        $stream = self::getOrCreateBodyStream($source);
        self::markAsBodyHost($target, self::bodyBytes($source), self::bodyContentType($source));
        $target->setInternalProperty('[[BodyReplayable]]', $source->getInternalProperty('[[BodyReplayable]]'));
        if ($stream === null) {
            return;
        }
        $branches = \Phasis\BuiltIn\Streams\PipeOperations::tee($stream, true);
        $source->setInternalProperty('[[BodyStream]]', $branches->get('0'));
        $target->setInternalProperty('[[BodyStream]]', $branches->get('1'));
    }

    /** Transfer a request body through a proxy stream while retaining its reader lock. */
    public static function transferBodyStream(JsObject $source, Environment $env): JsObject
    {
        $stream = self::getOrCreateBodyStream($source);
        if ($stream === null) {
            throw new \LogicException('Cannot transfer a null body');
        }
        $reader = ReadableStream::acquireDefaultReader($stream);
        self::markUsed($source);
        $underlying = new JsObject();
        $underlying->set('pull', JsFunction::fromCallable('', static function (JsValue $_this, array $args) use ($reader): JsValue {
            $controller = $args[0] ?? JsUndefined::instance();
            return ReadableStream::defaultReaderRead($reader)->then([JsFunction::fromCallable(
                '',
                static function (JsValue $_this, array $args) use ($controller): JsValue {
                    $result = $args[0] ?? JsUndefined::instance();
                    if ($controller instanceof JsObject && $result instanceof JsObject) {
                        if (TypeConversion::toBoolean($result->get('done'))) {
                            ReadableStream::defaultControllerClose($controller);
                        } else {
                            ReadableStream::defaultControllerEnqueue($controller, $result->get('value'));
                        }
                    }
                    return JsUndefined::instance();
                },
            )]);
        }));
        $underlying->set('cancel', JsFunction::fromCallable('', static function (JsValue $_this, array $args) use ($stream): JsValue {
            return ReadableStream::readableStreamCancel($stream, $args[0] ?? JsUndefined::instance());
        }));
        $constructor = $env->get('ReadableStream');
        if (!$constructor instanceof JsFunction) {
            throw new \LogicException('ReadableStream is unavailable');
        }
        $proxy = $constructor->construct([$underlying]);
        if (!$proxy instanceof JsObject) {
            throw new \LogicException('ReadableStream constructor returned a non-object');
        }
        return $proxy;
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
