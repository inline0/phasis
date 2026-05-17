<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Streams;

use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Value\JsArray;
use Phasis\Value\JsArrayBuffer;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsDataView;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsPromise;
use Phasis\Value\JsString;
use Phasis\Value\JsTypedArray;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * Shared helpers and abstract operations from the WHATWG Streams spec.
 *
 * These functions implement the spec-named abstract operations using
 * Phasis-internal slot accessors. Internal-slot names ("[[…]]") are
 * stored via JsObject::setInternalProperty so they're invisible to JS
 * but accessible from PHP.
 *
 * Spec: https://streams.spec.whatwg.org/
 *
 * Key conventions:
 * - "stream", "controller", "reader" parameters are JsObject instances
 *   carrying the appropriate "[[…]]" internal slots.
 * - "Promises" are JsPromise instances; pending-state semantics match
 *   the rest of Phasis (synchronous resolution with microtask draining).
 * - When the spec returns a rejected promise, we return JsPromise::rejected.
 * - When the spec says "throw a TypeError", we throw \Phasis\Exceptions\TypeError.
 */
final class StreamHelpers
{
    /** Stream states. */
    public const STATE_READABLE = 'readable';
    public const STATE_CLOSED = 'closed';
    public const STATE_ERRORED = 'errored';

    /** WritableStream states. */
    public const WS_WRITABLE = 'writable';
    public const WS_CLOSED = 'closed';
    public const WS_ERRORING = 'erroring';
    public const WS_ERRORED = 'errored';

    /**
     * Create a JS Error object suitable for use as a stream's stored error
     * value. We synthesize via the global TypeError constructor when
     * available; otherwise build a plain object.
     */
    public static function createTypeError(string $message): JsValue
    {
        $realm = \Phasis\Engine::getCurrentRealm();
        if ($realm !== null) {
            $env = $realm->getGlobalEnv();
            if ($env->has('TypeError')) {
                $ctor = $env->get('TypeError');
                if ($ctor instanceof JsFunction) {
                    try {
                        return $ctor->construct([new JsString($message)]);
                    } catch (\Throwable) {
                        // Fall through to plain object.
                    }
                }
            }
        }
        $err = new JsObject();
        $err->defineOwnProperty('name', PropertyDescriptor::data(new JsString('TypeError'), true, false, true));
        $err->defineOwnProperty('message', PropertyDescriptor::data(new JsString($message), true, false, true));
        return $err;
    }

    /** Make a new pending JsPromise. */
    public static function newPromise(): JsPromise
    {
        return new JsPromise();
    }

    /** Resolved promise of $value. */
    public static function promiseResolved(JsValue $value): JsPromise
    {
        return JsPromise::resolved($value);
    }

    /** Rejected promise of $reason. */
    public static function promiseRejected(JsValue $reason): JsPromise
    {
        $p = JsPromise::rejected($reason);
        self::markPromiseHandled($p);
        return $p;
    }

    /**
     * Mark a JsPromise as "handled" by attaching no-op handlers. Useful for
     * promises like cancelPromise that we know are handled by the spec
     * algorithm but whose rejection wouldn't otherwise have a then().
     */
    public static function markPromiseHandled(JsPromise $p): void
    {
        $p->addFulfillHandler(static function (JsValue $_): void {
        });
        $p->addRejectHandler(static function (JsValue $_): void {
        });
    }

    /**
     * Set the value of the [[Resolve]] / [[Reject]] capability for a promise.
     * Returns [resolve, reject] closures bound to the given promise.
     *
     * @return array{0: \Closure, 1: \Closure}
     */
    public static function resolversFor(JsPromise $promise): array
    {
        $resolve = static function (JsValue $v) use ($promise): void {
            $promise->resolve($v);
        };
        $reject = static function (JsValue $r) use ($promise): void {
            $promise->reject($r);
        };
        return [$resolve, $reject];
    }

    /**
     * Convert any thrown PHP exception during a callback into a JsValue.
     */
    public static function exceptionToJsValue(\Throwable $e): JsValue
    {
        if ($e instanceof \Phasis\Exceptions\JsThrowable) {
            return $e->jsValue;
        }
        if ($e instanceof \Phasis\Exceptions\RuntimeError) {
            $interp = JsFunction::getInterpreterInstance();
            if ($interp !== null) {
                return $interp->phpExceptionToJsValue($e);
            }
        }
        return new JsString($e->getMessage());
    }

    /**
     * Call a JS function with given thisArg and args; convert any thrown
     * value to a JsValue and return [success, valueOrError].
     *
     * @param array<int, JsValue> $args
     * @return array{0: bool, 1: JsValue}
     */
    public static function callSafely(JsFunction $fn, JsValue $this_, array $args): array
    {
        try {
            return [true, $fn->call($this_, $args)];
        } catch (\Throwable $e) {
            return [false, self::exceptionToJsValue($e)];
        }
    }

    /**
     * Convert a JS function result to a Promise. If the function returned a
     * thenable, adopt its state; otherwise wrap as resolved/rejected.
     *
     * @param array<int, JsValue> $args
     */
    public static function promiseCall(JsFunction $fn, JsValue $this_, array $args): JsPromise
    {
        try {
            $result = $fn->call($this_, $args);
        } catch (\Throwable $e) {
            return self::promiseRejected(self::exceptionToJsValue($e));
        }
        return self::toPromise($result);
    }

    /**
     * Coerce a JsValue to a JsPromise. If already a promise, return as-is.
     * If a thenable, adopt. Otherwise wrap as resolved.
     */
    public static function toPromise(JsValue $value): JsPromise
    {
        if ($value instanceof JsPromise) {
            return $value;
        }
        $p = new JsPromise();
        $p->resolve($value);
        return $p;
    }

    /**
     * Spec: GetV(value, name) — Get a property from a value, throwing if null/undefined.
     */
    public static function getProperty(JsValue $value, string $name): JsValue
    {
        if ($value instanceof JsUndefined || $value instanceof \Phasis\Value\JsNull) {
            throw new TypeError("Cannot read property '{$name}' of " . ($value instanceof JsUndefined ? 'undefined' : 'null'));
        }
        if ($value instanceof JsObject) {
            return $value->get($name);
        }
        return JsUndefined::instance();
    }

    /**
     * Read a "highWaterMark" from a queuing strategy. If undefined, returns null.
     * If invalid (NaN, negative), throws RangeError.
     */
    public static function extractHighWaterMark(?JsValue $strategy, float $defaultHwm): float
    {
        if ($strategy === null || $strategy instanceof JsUndefined) {
            return $defaultHwm;
        }
        if (!$strategy instanceof JsObject) {
            return $defaultHwm;
        }
        $hwm = $strategy->get('highWaterMark');
        if ($hwm instanceof JsUndefined) {
            return $defaultHwm;
        }
        $n = \Phasis\Spec\TypeConversion::toNumber($hwm);
        if (is_nan($n) || $n < 0) {
            throw new \Phasis\Exceptions\RangeError('Invalid highWaterMark');
        }
        return (float) $n;
    }

    /**
     * Read a "size" function from a queuing strategy. Returns null if not present.
     */
    public static function extractSizeAlgorithm(?JsValue $strategy): ?JsFunction
    {
        if ($strategy === null || !$strategy instanceof JsObject) {
            return null;
        }
        $size = $strategy->get('size');
        if ($size instanceof JsUndefined) {
            return null;
        }
        if (!$size instanceof JsFunction) {
            throw new TypeError('size must be a function');
        }
        return $size;
    }

    /**
     * Schedule a callback as a microtask, so it runs after the current
     * synchronous work completes.
     */
    public static function enqueueMicrotask(\Closure $cb): void
    {
        JsPromise::scheduleCallback($cb);
    }

    /**
     * Spec: DequeueValue / EnqueueValueWithSize / ResetQueue.
     * We implement these as static helpers on a "queue container" object
     * that carries "[[queue]]" (list<array{value:JsValue,size:float}>) and
     * "[[queueTotalSize]]" (float) internal slots.
     */
    public static function resetQueue(JsObject $container): void
    {
        $container->setInternalProperty('[[queue]]', []);
        $container->setInternalProperty('[[queueTotalSize]]', 0.0);
    }

    public static function enqueueValueWithSize(JsObject $container, JsValue $value, float $size): void
    {
        if (is_nan($size) || is_infinite($size) || $size < 0) {
            throw new \Phasis\Exceptions\RangeError('Invalid chunk size');
        }
        $queue = $container->getInternalProperty('[[queue]]') ?? [];
        $queue[] = ['value' => $value, 'size' => $size];
        $container->setInternalProperty('[[queue]]', $queue);
        $total = ($container->getInternalProperty('[[queueTotalSize]]') ?? 0.0) + $size;
        $container->setInternalProperty('[[queueTotalSize]]', (float) $total);
    }

    /**
     * Pop the first queued chunk and return its value. Returns null if empty.
     */
    public static function dequeueValue(JsObject $container): ?JsValue
    {
        $queue = $container->getInternalProperty('[[queue]]') ?? [];
        if ($queue === []) {
            return null;
        }
        $first = array_shift($queue);
        $container->setInternalProperty('[[queue]]', $queue);
        $total = ($container->getInternalProperty('[[queueTotalSize]]') ?? 0.0) - $first['size'];
        if ($total < 0) {
            $total = 0.0;
        }
        $container->setInternalProperty('[[queueTotalSize]]', (float) $total);
        return $first['value'];
    }

    public static function peekQueueValue(JsObject $container): ?JsValue
    {
        $queue = $container->getInternalProperty('[[queue]]') ?? [];
        if ($queue === []) {
            return null;
        }
        return $queue[0]['value'];
    }

    public static function queueSize(JsObject $container): float
    {
        return (float) ($container->getInternalProperty('[[queueTotalSize]]') ?? 0.0);
    }

    /**
     * @phpstan-impure
     */
    public static function queueLength(JsObject $container): int
    {
        $queue = $container->getInternalProperty('[[queue]]') ?? [];
        return count($queue);
    }

    /**
     * Check if a value is an ArrayBufferView (TypedArray or DataView).
     */
    public static function isArrayBufferView(JsValue $value): bool
    {
        return $value instanceof JsTypedArray || $value instanceof JsDataView;
    }

    /**
     * Check if a value is an ArrayBuffer or SharedArrayBuffer.
     */
    public static function isArrayBuffer(JsValue $value): bool
    {
        return $value instanceof JsArrayBuffer;
    }

    /**
     * Get the underlying buffer + byteOffset + byteLength of an ArrayBufferView.
     *
     * @return array{0: JsArrayBuffer, 1: int, 2: int}
     */
    public static function unwrapView(JsValue $view): array
    {
        if ($view instanceof JsTypedArray) {
            $buf = $view->getBuffer();
            $offset = $view->getByteOffset();
            $bpe = $view->getBytesPerElement();
            $byteLen = $view->getLength() * $bpe;
            return [$buf, $offset, $byteLen];
        }
        if ($view instanceof JsDataView) {
            return [$view->getBuffer(), $view->getByteOffset(), $view->getByteLength()];
        }
        throw new TypeError('Not an ArrayBufferView');
    }

    /**
     * Create a fresh Uint8Array wrapping the given bytes string.
     */
    public static function makeUint8Array(string $bytes): JsTypedArray
    {
        return \Phasis\BuiltIn\TextEncoderConstructor::makeUint8Array($bytes);
    }

    /**
     * Create an object literal { value, done }.
     */
    public static function readResult(JsValue $value, bool $done): JsObject
    {
        $obj = new JsObject();
        $obj->defineOwnProperty(
            'value',
            PropertyDescriptor::data($value, true, true, true)
        );
        $obj->defineOwnProperty(
            'done',
            PropertyDescriptor::data(JsBoolean::of($done), true, true, true)
        );
        return $obj;
    }

    /**
     * Get a stream / reader / writer / controller's internal slot value as JsObject (or null).
     */
    public static function slotObject(JsObject $owner, string $slot): ?JsObject
    {
        $val = $owner->getInternalProperty($slot);
        return $val instanceof JsObject ? $val : null;
    }

    /**
     * Convert any JsValue to a callable JsFunction or return null.
     */
    public static function asFunctionOrNull(JsValue $v): ?JsFunction
    {
        return $v instanceof JsFunction ? $v : null;
    }

    /**
     * Build a real `ReadableStream` JsObject whose single chunk is a fresh
     * Uint8Array wrapping the supplied PHP byte string. The stream is
     * pre-started and pre-enqueued, then `close()`d, so the first read on
     * a default reader yields `{value: Uint8Array(bytes), done: false}` and
     * the second yields `{value: undefined, done: true}`.
     *
     * Used by the Body mixin (`Request.body` / `Response.body` getters) to
     * surface fully-buffered request/response bodies as spec-compliant
     * ReadableStream instances without forcing the caller to construct an
     * underlying source. The result passes `body instanceof ReadableStream`
     * and `Symbol.toStringTag === 'ReadableStream'`.
     *
     * Empty input still produces a valid, immediately-closed stream so
     * `for await (const chunk of body) { … }` simply finishes without
     * yielding anything (matches browser behavior for a fetch with an
     * empty body).
     */
    public static function createReadableStreamFromBytes(string $bytes): JsObject
    {
        $proto = \Phasis\BuiltIn\Streams\ReadableStream::getPrototype();
        $controllerProto = \Phasis\BuiltIn\Streams\ReadableStream::getControllerPrototype();
        if (!$proto instanceof JsObject || !$controllerProto instanceof JsObject) {
            // Streams have not been installed yet — fall back to a bare
            // stream that will still pass the brand check via the slot.
            $proto = new JsObject();
            $controllerProto = new JsObject();
        }

        $stream = new JsObject($proto);
        \Phasis\BuiltIn\Streams\ReadableStream::initializeStream($stream);

        $controller = new JsObject($controllerProto);
        $controller->setInternalProperty('[[IsReadableStreamDefaultController]]', true);
        $controller->setInternalProperty('[[Stream]]', $stream);
        self::resetQueue($controller);
        $controller->setInternalProperty('[[Started]]', true);
        $controller->setInternalProperty('[[Pulling]]', false);
        $controller->setInternalProperty('[[PullAgain]]', false);
        $controller->setInternalProperty('[[CloseRequested]]', false);
        $controller->setInternalProperty('[[StrategyHWM]]', 0.0);
        $controller->setInternalProperty('[[StrategySizeAlgorithm]]', null);
        $controller->setInternalProperty(
            '[[PullAlgorithm]]',
            static fn(): JsPromise => self::promiseResolved(JsUndefined::instance())
        );
        $controller->setInternalProperty(
            '[[CancelAlgorithm]]',
            static fn(JsValue $_): JsPromise => self::promiseResolved(JsUndefined::instance())
        );
        $stream->setInternalProperty('[[Controller]]', $controller);
        $stream->setInternalProperty('[[ControllerType]]', 'default');

        if ($bytes !== '') {
            $chunk = self::makeUint8Array($bytes);
            \Phasis\BuiltIn\Streams\ReadableStream::defaultControllerEnqueue($controller, $chunk);
        }
        \Phasis\BuiltIn\Streams\ReadableStream::defaultControllerClose($controller);

        return $stream;
    }

    /**
     * Variant that re-enqueues an arbitrary list of JS values onto a
     * fresh ReadableStream — identity preserved. Used by BodyMixin to
     * materialize `response.body` for a Response that was originally
     * constructed from a ReadableStream so reads return the same JS
     * instances the upstream code enqueued.
     *
     * @param list<JsValue> $chunks
     */
    public static function createReadableStreamFromChunks(array $chunks): JsObject
    {
        $proto = \Phasis\BuiltIn\Streams\ReadableStream::getPrototype();
        $controllerProto = \Phasis\BuiltIn\Streams\ReadableStream::getControllerPrototype();
        if (!$proto instanceof JsObject || !$controllerProto instanceof JsObject) {
            $proto = new JsObject();
            $controllerProto = new JsObject();
        }

        $stream = new JsObject($proto);
        \Phasis\BuiltIn\Streams\ReadableStream::initializeStream($stream);

        $controller = new JsObject($controllerProto);
        $controller->setInternalProperty('[[IsReadableStreamDefaultController]]', true);
        $controller->setInternalProperty('[[Stream]]', $stream);
        self::resetQueue($controller);
        $controller->setInternalProperty('[[Started]]', true);
        $controller->setInternalProperty('[[Pulling]]', false);
        $controller->setInternalProperty('[[PullAgain]]', false);
        $controller->setInternalProperty('[[CloseRequested]]', false);
        $controller->setInternalProperty('[[StrategyHWM]]', 0.0);
        $controller->setInternalProperty('[[StrategySizeAlgorithm]]', null);
        $controller->setInternalProperty(
            '[[PullAlgorithm]]',
            static fn(): JsPromise => self::promiseResolved(JsUndefined::instance())
        );
        $controller->setInternalProperty(
            '[[CancelAlgorithm]]',
            static fn(JsValue $_): JsPromise => self::promiseResolved(JsUndefined::instance())
        );
        $stream->setInternalProperty('[[Controller]]', $controller);
        $stream->setInternalProperty('[[ControllerType]]', 'default');

        foreach ($chunks as $chunk) {
            if ($chunk instanceof JsValue) {
                \Phasis\BuiltIn\Streams\ReadableStream::defaultControllerEnqueue($controller, $chunk);
            }
        }
        \Phasis\BuiltIn\Streams\ReadableStream::defaultControllerClose($controller);

        return $stream;
    }

    /**
     * Variant of createReadableStreamFromBytes that returns a stream backed
     * by a ReadableByteStreamController. The result supports both default
     * and BYOB readers (per the WHATWG Blob.stream() spec which requires a
     * byte-stream-typed result).
     *
     * Empty Blobs produce a stream that closes without enqueueing any
     * chunk; non-empty Blobs enqueue a single Uint8Array snapshot.
     */
    public static function createReadableByteStreamFromBytes(string $bytes): JsObject
    {
        $proto = \Phasis\BuiltIn\Streams\ReadableStream::getPrototype();
        $controllerProto = \Phasis\BuiltIn\Streams\ByteStream::getControllerPrototype();
        if (!$proto instanceof JsObject || !$controllerProto instanceof JsObject) {
            // Streams not installed yet — bail to the default-controller
            // variant so callers still receive a usable stream.
            return self::createReadableStreamFromBytes($bytes);
        }

        $stream = new JsObject($proto);
        \Phasis\BuiltIn\Streams\ReadableStream::initializeStream($stream);

        $controller = new JsObject($controllerProto);
        $controller->setInternalProperty('[[IsReadableByteStreamController]]', true);
        $controller->setInternalProperty('[[Stream]]', $stream);
        self::resetQueue($controller);
        $controller->setInternalProperty('[[Started]]', true);
        $controller->setInternalProperty('[[Pulling]]', false);
        $controller->setInternalProperty('[[PullAgain]]', false);
        $controller->setInternalProperty('[[CloseRequested]]', false);
        $controller->setInternalProperty('[[StrategyHWM]]', 0.0);
        $controller->setInternalProperty('[[PendingPullIntos]]', []);
        $controller->setInternalProperty('[[ByobRequest]]', null);
        $controller->setInternalProperty('[[AutoAllocateChunkSize]]', null);
        $controller->setInternalProperty(
            '[[PullAlgorithm]]',
            static fn(): JsPromise => self::promiseResolved(JsUndefined::instance())
        );
        $controller->setInternalProperty(
            '[[CancelAlgorithm]]',
            static fn(JsValue $_): JsPromise => self::promiseResolved(JsUndefined::instance())
        );
        $stream->setInternalProperty('[[Controller]]', $controller);
        $stream->setInternalProperty('[[ControllerType]]', 'byte');

        if ($bytes !== '') {
            $chunk = self::makeUint8Array($bytes);
            \Phasis\BuiltIn\Streams\ByteStream::byteControllerEnqueue($controller, $chunk);
        }
        \Phasis\BuiltIn\Streams\ByteStream::byteControllerClose($controller);

        return $stream;
    }
}
