<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Streams;

use Phasis\BuiltIn\SymbolConstructor;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Value\JsArrayBuffer;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsDataView;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsPromise;
use Phasis\Value\JsString;
use Phasis\Value\JsTypedArray;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * WHATWG Streams byte-stream classes:
 *   - ReadableByteStreamController
 *   - ReadableStreamBYOBReader
 *   - ReadableStreamBYOBRequest
 *
 * Spec: https://streams.spec.whatwg.org/#rbs-controller-class
 *
 * Internal-slot summary:
 *
 * ReadableByteStreamController:
 *   [[IsReadableByteStreamController]]    bool
 *   [[Stream]]                            JsObject
 *   [[queue]]                             list<array{value, size}>  (each value is JsTypedArray Uint8Array)
 *   [[queueTotalSize]]                    float
 *   [[Started]]                           bool
 *   [[Pulling]]                           bool
 *   [[PullAgain]]                         bool
 *   [[CloseRequested]]                    bool
 *   [[StrategyHWM]]                       float
 *   [[PullAlgorithm]]                     \Closure
 *   [[CancelAlgorithm]]                   \Closure
 *   [[PendingPullIntos]]                  list<PullIntoDescriptor>
 *   [[AutoAllocateChunkSize]]             int|null
 *   [[ByobRequest]]                       JsObject|null
 *
 * Pull-into descriptor is an associative array:
 *   { buffer: JsArrayBuffer, byteOffset, byteLength, bytesFilled,
 *     elementSize, viewConstructor: 'Uint8Array'..., readerType: 'default'|'byob'|'none',
 *     minimumFill }
 *
 * ReadableStreamBYOBReader:
 *   [[IsReadableStreamBYOBReader]]        bool
 *   [[Stream]]                            JsObject|null
 *   [[ClosedPromise]]                     JsPromise
 *   [[ReadIntoRequests]]                  list<array{resolve,reject,view}>
 *
 * ReadableStreamBYOBRequest:
 *   [[IsReadableStreamBYOBRequest]]       bool
 *   [[Controller]]                        JsObject|null
 *   [[View]]                              JsValue (Uint8Array or null)
 */
final class ByteStream
{
    private static ?JsObject $controllerPrototype = null;
    private static ?JsObject $byobReaderPrototype = null;
    private static ?JsObject $byobRequestPrototype = null;

    public static function install(Environment $env): void
    {
        self::$controllerPrototype = new JsObject();
        self::$byobReaderPrototype = new JsObject();
        self::$byobRequestPrototype = new JsObject();

        self::buildControllerPrototype();
        self::buildByobReaderPrototype();
        self::buildByobRequestPrototype();

        $ctorByte = JsFunction::fromCallable(
            'ReadableByteStreamController',
            function (JsValue $this_, array $args): JsValue {
                throw new TypeError('Illegal constructor');
            },
            0,
        );
        $ctorByte->setConstructable();
        $ctorByte->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data(self::$controllerPrototype, false, false, false)
        );
        self::$controllerPrototype->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($ctorByte, true, false, true)
        );
        $env->defineVar('ReadableByteStreamController', $ctorByte);

        $ctorByobReader = JsFunction::fromCallable(
            'ReadableStreamBYOBReader',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError("Constructor ReadableStreamBYOBReader requires 'new'");
                }
                $stream = $args[0] ?? JsUndefined::instance();
                if (!\Phasis\BuiltIn\Streams\ReadableStream::isReadableStream($stream)) {
                    throw new TypeError('Argument must be a ReadableStream');
                }
                /** @var JsObject $stream */
                if ($stream->getInternalProperty('[[ControllerType]]') !== 'byte') {
                    throw new TypeError('Cannot construct BYOB reader for non-byte stream');
                }
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsObject) {
                    $ntProto = $newTarget->get('prototype');
                    $useProto = $ntProto instanceof JsObject ? $ntProto : self::$byobReaderPrototype;
                    $this_->setPrototype($useProto);
                }
                self::setUpBYOBReader($this_, $stream);
                return $this_;
            },
            1,
        );
        $ctorByobReader->setConstructable();
        $ctorByobReader->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data(self::$byobReaderPrototype, false, false, false)
        );
        self::$byobReaderPrototype->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($ctorByobReader, true, false, true)
        );
        $env->defineVar('ReadableStreamBYOBReader', $ctorByobReader);

        $ctorByobRequest = JsFunction::fromCallable(
            'ReadableStreamBYOBRequest',
            function (JsValue $this_, array $args): JsValue {
                throw new TypeError('Illegal constructor');
            },
            0,
        );
        $ctorByobRequest->setConstructable();
        $ctorByobRequest->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data(self::$byobRequestPrototype, false, false, false)
        );
        self::$byobRequestPrototype->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($ctorByobRequest, true, false, true)
        );
        $env->defineVar('ReadableStreamBYOBRequest', $ctorByobRequest);
    }

    public static function getControllerPrototype(): ?JsObject
    {
        return self::$controllerPrototype;
    }

    public static function getByobReaderPrototype(): ?JsObject
    {
        return self::$byobReaderPrototype;
    }

    public static function isReadableByteStreamController(JsValue $v): bool
    {
        return $v instanceof JsObject && $v->getInternalProperty('[[IsReadableByteStreamController]]') === true;
    }

    public static function isReadableStreamBYOBReader(JsValue $v): bool
    {
        return $v instanceof JsObject && $v->getInternalProperty('[[IsReadableStreamBYOBReader]]') === true;
    }

    public static function isReadableStreamBYOBRequest(JsValue $v): bool
    {
        return $v instanceof JsObject && $v->getInternalProperty('[[IsReadableStreamBYOBRequest]]') === true;
    }

    private static function buildControllerPrototype(): void
    {
        $proto = self::$controllerPrototype;

        $byobRequestGetter = JsFunction::fromCallable(
            'get byobRequest',
            function (JsValue $this_): JsValue {
                if (!self::isReadableByteStreamController($this_)) {
                    throw new TypeError('byobRequest called on non-controller');
                }
                /** @var JsObject $this_ */
                return self::byteControllerGetBYOBRequest($this_);
            },
            0,
        );
        $proto->defineOwnProperty(
            'byobRequest',
            PropertyDescriptor::accessor($byobRequestGetter, null, false, true)
        );

        $desiredSizeGetter = JsFunction::fromCallable(
            'get desiredSize',
            function (JsValue $this_): JsValue {
                if (!self::isReadableByteStreamController($this_)) {
                    throw new TypeError('desiredSize called on non-controller');
                }
                /** @var JsObject $this_ */
                $d = self::byteControllerDesiredSize($this_);
                return $d === null ? JsNull::instance() : JsNumber::of($d);
            },
            0,
        );
        $proto->defineOwnProperty(
            'desiredSize',
            PropertyDescriptor::accessor($desiredSizeGetter, null, false, true)
        );

        $closeFn = JsFunction::fromCallable(
            'close',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isReadableByteStreamController($this_)) {
                    throw new TypeError('close called on non-controller');
                }
                /** @var JsObject $this_ */
                self::byteControllerClose($this_);
                return JsUndefined::instance();
            },
            0,
        );
        $proto->defineOwnProperty('close', PropertyDescriptor::data($closeFn, true, false, true));

        $enqueueFn = JsFunction::fromCallable(
            'enqueue',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isReadableByteStreamController($this_)) {
                    throw new TypeError('enqueue called on non-controller');
                }
                /** @var JsObject $this_ */
                $chunk = $args[0] ?? JsUndefined::instance();
                if (!StreamHelpers::isArrayBufferView($chunk)) {
                    throw new TypeError('Chunk must be an ArrayBufferView');
                }
                self::byteControllerEnqueue($this_, $chunk);
                return JsUndefined::instance();
            },
            1,
        );
        $proto->defineOwnProperty('enqueue', PropertyDescriptor::data($enqueueFn, true, false, true));

        $errorFn = JsFunction::fromCallable(
            'error',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isReadableByteStreamController($this_)) {
                    throw new TypeError('error called on non-controller');
                }
                /** @var JsObject $this_ */
                self::byteControllerError($this_, $args[0] ?? JsUndefined::instance());
                return JsUndefined::instance();
            },
            1,
        );
        $proto->defineOwnProperty('error', PropertyDescriptor::data($errorFn, true, false, true));

        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('ReadableByteStreamController'), false, false, true)
        );
    }

    private static function buildByobReaderPrototype(): void
    {
        $proto = self::$byobReaderPrototype;

        $readFn = JsFunction::fromCallable(
            'read',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isReadableStreamBYOBReader($this_)) {
                    return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('read called on non-BYOB-reader'));
                }
                /** @var JsObject $this_ */
                $view = $args[0] ?? JsUndefined::instance();
                if (!StreamHelpers::isArrayBufferView($view)) {
                    return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('Argument must be an ArrayBufferView'));
                }
                $options = $args[1] ?? JsUndefined::instance();
                $minRead = 1;
                if ($options instanceof JsObject) {
                    $m = $options->get('min');
                    if (!$m instanceof JsUndefined) {
                        $n = (int) \Phasis\Spec\TypeConversion::toNumber($m);
                        if ($n < 1) {
                            return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('min must be >= 1'));
                        }
                        $minRead = $n;
                    }
                }
                return self::byobReaderRead($this_, $view, $minRead);
            },
            1,
        );
        $proto->defineOwnProperty('read', PropertyDescriptor::data($readFn, true, false, true));

        $releaseLockFn = JsFunction::fromCallable(
            'releaseLock',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isReadableStreamBYOBReader($this_)) {
                    throw new TypeError('releaseLock called on non-BYOB-reader');
                }
                /** @var JsObject $this_ */
                if ($this_->getInternalProperty('[[Stream]]') === null) {
                    return JsUndefined::instance();
                }
                self::byobReaderRelease($this_);
                return JsUndefined::instance();
            },
            0,
        );
        $proto->defineOwnProperty('releaseLock', PropertyDescriptor::data($releaseLockFn, true, false, true));

        $cancelFn = JsFunction::fromCallable(
            'cancel',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isReadableStreamBYOBReader($this_)) {
                    return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('cancel called on non-BYOB-reader'));
                }
                /** @var JsObject $this_ */
                $stream = $this_->getInternalProperty('[[Stream]]');
                if (!$stream instanceof JsObject) {
                    return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('Reader is not attached'));
                }
                return \Phasis\BuiltIn\Streams\ReadableStream::readableStreamCancel($stream, $args[0] ?? JsUndefined::instance());
            },
            1,
        );
        $proto->defineOwnProperty('cancel', PropertyDescriptor::data($cancelFn, true, false, true));

        $closedGetter = JsFunction::fromCallable(
            'get closed',
            function (JsValue $this_): JsValue {
                if (!self::isReadableStreamBYOBReader($this_)) {
                    return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('closed called on non-BYOB-reader'));
                }
                /** @var JsObject $this_ */
                $p = $this_->getInternalProperty('[[ClosedPromise]]');
                return $p instanceof JsPromise ? $p : StreamHelpers::promiseResolved(JsUndefined::instance());
            },
            0,
        );
        $proto->defineOwnProperty(
            'closed',
            PropertyDescriptor::accessor($closedGetter, null, false, true)
        );

        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('ReadableStreamBYOBReader'), false, false, true)
        );
    }

    private static function buildByobRequestPrototype(): void
    {
        $proto = self::$byobRequestPrototype;

        $viewGetter = JsFunction::fromCallable(
            'get view',
            function (JsValue $this_): JsValue {
                if (!self::isReadableStreamBYOBRequest($this_)) {
                    throw new TypeError('view called on non-BYOBRequest');
                }
                /** @var JsObject $this_ */
                $v = $this_->getInternalProperty('[[View]]');
                return $v instanceof JsValue ? $v : JsNull::instance();
            },
            0,
        );
        $proto->defineOwnProperty(
            'view',
            PropertyDescriptor::accessor($viewGetter, null, false, true)
        );

        $respondFn = JsFunction::fromCallable(
            'respond',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isReadableStreamBYOBRequest($this_)) {
                    throw new TypeError('respond called on non-BYOBRequest');
                }
                /** @var JsObject $this_ */
                $bytesWritten = (int) \Phasis\Spec\TypeConversion::toNumber($args[0] ?? JsUndefined::instance());
                $controller = $this_->getInternalProperty('[[Controller]]');
                if (!$controller instanceof JsObject) {
                    throw new TypeError('BYOBRequest has no controller');
                }
                self::byteControllerRespond($controller, $bytesWritten);
                return JsUndefined::instance();
            },
            1,
        );
        $proto->defineOwnProperty('respond', PropertyDescriptor::data($respondFn, true, false, true));

        $respondWithNewViewFn = JsFunction::fromCallable(
            'respondWithNewView',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isReadableStreamBYOBRequest($this_)) {
                    throw new TypeError('respondWithNewView called on non-BYOBRequest');
                }
                /** @var JsObject $this_ */
                $view = $args[0] ?? JsUndefined::instance();
                if (!StreamHelpers::isArrayBufferView($view)) {
                    throw new TypeError('Argument must be ArrayBufferView');
                }
                $controller = $this_->getInternalProperty('[[Controller]]');
                if (!$controller instanceof JsObject) {
                    throw new TypeError('BYOBRequest has no controller');
                }
                self::byteControllerRespondWithNewView($controller, $view);
                return JsUndefined::instance();
            },
            1,
        );
        $proto->defineOwnProperty(
            'respondWithNewView',
            PropertyDescriptor::data($respondWithNewViewFn, true, false, true)
        );

        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('ReadableStreamBYOBRequest'), false, false, true)
        );
    }

    // ------------------------------------------------------------------
    // Set up the controller for a byte stream
    // ------------------------------------------------------------------

    public static function setUpReadableByteStreamControllerFromUnderlyingSource(
        JsObject $stream,
        JsValue $underlyingSource,
        float $highWaterMark
    ): void {
        $controller = new JsObject(self::$controllerPrototype);
        $controller->setInternalProperty('[[IsReadableByteStreamController]]', true);
        $controller->setInternalProperty('[[Stream]]', $stream);
        StreamHelpers::resetQueue($controller);
        $controller->setInternalProperty('[[Started]]', false);
        $controller->setInternalProperty('[[Pulling]]', false);
        $controller->setInternalProperty('[[PullAgain]]', false);
        $controller->setInternalProperty('[[CloseRequested]]', false);
        $controller->setInternalProperty('[[StrategyHWM]]', $highWaterMark);
        $controller->setInternalProperty('[[PendingPullIntos]]', []);
        $controller->setInternalProperty('[[ByobRequest]]', null);
        $controller->setInternalProperty('[[AutoAllocateChunkSize]]', null);

        if ($underlyingSource instanceof JsObject) {
            $auto = $underlyingSource->get('autoAllocateChunkSize');
            if (!$auto instanceof JsUndefined) {
                $val = (int) \Phasis\Spec\TypeConversion::toNumber($auto);
                if ($val > 0) {
                    $controller->setInternalProperty('[[AutoAllocateChunkSize]]', $val);
                }
            }
        }

        $startFn = null;
        $pullFn = null;
        $cancelFn = null;
        if ($underlyingSource instanceof JsObject) {
            $sm = $underlyingSource->get('start');
            $pm = $underlyingSource->get('pull');
            $cm = $underlyingSource->get('cancel');
            $startFn = $sm instanceof JsFunction ? $sm : null;
            $pullFn = $pm instanceof JsFunction ? $pm : null;
            $cancelFn = $cm instanceof JsFunction ? $cm : null;
        }

        $controller->setInternalProperty('[[PullAlgorithm]]', function () use ($pullFn, $controller, $underlyingSource): JsPromise {
            if ($pullFn === null) {
                return StreamHelpers::promiseResolved(JsUndefined::instance());
            }
            return StreamHelpers::promiseCall($pullFn, $underlyingSource, [$controller]);
        });
        $controller->setInternalProperty('[[CancelAlgorithm]]', function (JsValue $reason) use ($cancelFn, $underlyingSource): JsPromise {
            if ($cancelFn === null) {
                return StreamHelpers::promiseResolved(JsUndefined::instance());
            }
            return StreamHelpers::promiseCall($cancelFn, $underlyingSource, [$reason]);
        });

        $stream->setInternalProperty('[[Controller]]', $controller);
        $stream->setInternalProperty('[[ControllerType]]', 'byte');

        $startResult = JsUndefined::instance();
        if ($startFn !== null) {
            try {
                $startResult = $startFn->call($underlyingSource, [$controller]);
            } catch (\Throwable $e) {
                self::byteControllerError($controller, StreamHelpers::exceptionToJsValue($e));
                return;
            }
        }
        $startPromise = StreamHelpers::toPromise($startResult);
        $startPromise->addFulfillHandler(static function (JsValue $_) use ($controller): void {
            $controller->setInternalProperty('[[Started]]', true);
            self::byteControllerCallPullIfNeeded($controller);
        });
        $startPromise->addRejectHandler(static function (JsValue $reason) use ($controller): void {
            self::byteControllerError($controller, $reason);
        });
    }

    public static function byteControllerDesiredSize(JsObject $controller): ?float
    {
        $stream = $controller->getInternalProperty('[[Stream]]');
        if (!$stream instanceof JsObject) {
            return null;
        }
        $state = $stream->getInternalProperty('[[State]]');
        if ($state === StreamHelpers::STATE_ERRORED) {
            return null;
        }
        if ($state === StreamHelpers::STATE_CLOSED) {
            return 0.0;
        }
        $hwm = (float) ($controller->getInternalProperty('[[StrategyHWM]]') ?? 0.0);
        $size = StreamHelpers::queueSize($controller);
        return $hwm - $size;
    }

    public static function byteControllerShouldCallPull(JsObject $controller): bool
    {
        $stream = $controller->getInternalProperty('[[Stream]]');
        if (!$stream instanceof JsObject) {
            return false;
        }
        if ($stream->getInternalProperty('[[State]]') !== StreamHelpers::STATE_READABLE) {
            return false;
        }
        if ($controller->getInternalProperty('[[CloseRequested]]') === true) {
            return false;
        }
        if ($controller->getInternalProperty('[[Started]]') !== true) {
            return false;
        }
        // BYOB read pending?
        $reader = $stream->getInternalProperty('[[Reader]]');
        if ($reader instanceof JsObject) {
            if ($reader->getInternalProperty('[[IsReadableStreamDefaultReader]]') === true) {
                $requests = $reader->getInternalProperty('[[ReadRequests]]') ?? [];
                if ($requests !== []) {
                    return true;
                }
            } elseif ($reader->getInternalProperty('[[IsReadableStreamBYOBReader]]') === true) {
                $requests = $reader->getInternalProperty('[[ReadIntoRequests]]') ?? [];
                if ($requests !== []) {
                    return true;
                }
            }
        }
        $desired = self::byteControllerDesiredSize($controller);
        return $desired !== null && $desired > 0;
    }

    public static function byteControllerCallPullIfNeeded(JsObject $controller): void
    {
        if (!self::byteControllerShouldCallPull($controller)) {
            return;
        }
        if ($controller->getInternalProperty('[[Pulling]]') === true) {
            $controller->setInternalProperty('[[PullAgain]]', true);
            return;
        }
        $controller->setInternalProperty('[[Pulling]]', true);
        $pullAlgo = $controller->getInternalProperty('[[PullAlgorithm]]');
        if (!$pullAlgo instanceof \Closure) {
            return;
        }
        $p = $pullAlgo();
        $p->addFulfillHandler(static function (JsValue $_) use ($controller): void {
            $controller->setInternalProperty('[[Pulling]]', false);
            if ($controller->getInternalProperty('[[PullAgain]]') === true) {
                $controller->setInternalProperty('[[PullAgain]]', false);
                self::byteControllerCallPullIfNeeded($controller);
            }
        });
        $p->addRejectHandler(static function (JsValue $reason) use ($controller): void {
            self::byteControllerError($controller, $reason);
        });
    }

    /**
     * Get or create the BYOBRequest object associated with the controller.
     */
    public static function byteControllerGetBYOBRequest(JsObject $controller): JsValue
    {
        $existing = $controller->getInternalProperty('[[ByobRequest]]');
        if ($existing instanceof JsObject) {
            return $existing;
        }
        $pending = $controller->getInternalProperty('[[PendingPullIntos]]') ?? [];
        if ($pending === []) {
            return JsNull::instance();
        }
        $firstDesc = $pending[0];
        // Build Uint8Array view over remaining space.
        $remaining = $firstDesc['byteLength'] - $firstDesc['bytesFilled'];
        $view = self::makeUint8ArrayView(
            $firstDesc['buffer'],
            $firstDesc['byteOffset'] + $firstDesc['bytesFilled'],
            $remaining
        );
        $req = new JsObject(self::$byobRequestPrototype);
        $req->setInternalProperty('[[IsReadableStreamBYOBRequest]]', true);
        $req->setInternalProperty('[[Controller]]', $controller);
        $req->setInternalProperty('[[View]]', $view);
        $controller->setInternalProperty('[[ByobRequest]]', $req);
        return $req;
    }

    private static function makeUint8ArrayView(JsArrayBuffer $buffer, int $offset, int $length): JsTypedArray
    {
        // Resolve Uint8Array prototype.
        $proto = null;
        $realm = \Phasis\Engine::getCurrentRealm();
        if ($realm !== null) {
            $env = $realm->getGlobalEnv();
            if ($env->has('Uint8Array')) {
                $ctor = $env->get('Uint8Array');
                if ($ctor instanceof JsObject) {
                    $p = $ctor->get('prototype');
                    if ($p instanceof JsObject) {
                        $proto = $p;
                    }
                }
            }
        }
        return new JsTypedArray('Uint8Array', $buffer, $offset, $length, $proto);
    }

    // ------------------------------------------------------------------
    // Enqueue / close / error
    // ------------------------------------------------------------------

    public static function byteControllerEnqueue(JsObject $controller, JsValue $chunk): void
    {
        $stream = $controller->getInternalProperty('[[Stream]]');
        if (!$stream instanceof JsObject) {
            return;
        }
        if ($stream->getInternalProperty('[[State]]') !== StreamHelpers::STATE_READABLE) {
            throw new TypeError('Stream is not readable');
        }
        if ($controller->getInternalProperty('[[CloseRequested]]') === true) {
            throw new TypeError('Cannot enqueue: close requested');
        }

        [$buffer, $offset, $byteLen] = StreamHelpers::unwrapView($chunk);

        // Invalidate any existing BYOB request.
        self::byteControllerInvalidateBYOBRequest($controller);

        $reader = $stream->getInternalProperty('[[Reader]]');
        // Default-reader path: dispatch as a Uint8Array.
        if (
            $reader instanceof JsObject
            && $reader->getInternalProperty('[[IsReadableStreamDefaultReader]]') === true
        ) {
            if (\Phasis\BuiltIn\Streams\ReadableStream::hasPendingReadRequests($reader) && $byteLen > 0) {
                // Slice buffer into a new Uint8Array so the chunk has a stable backing.
                $bytes = $buffer->readBytes($offset, $byteLen);
                $u8 = StreamHelpers::makeUint8Array($bytes);
                \Phasis\BuiltIn\Streams\ReadableStream::fulfillReadRequest($reader, $u8, false);
                self::byteControllerCallPullIfNeeded($controller);
                return;
            }
            // No pending request; push to queue (as Uint8Array slice).
            $bytes = $buffer->readBytes($offset, $byteLen);
            $u8 = StreamHelpers::makeUint8Array($bytes);
            StreamHelpers::enqueueValueWithSize($controller, $u8, (float) $byteLen);
            self::byteControllerCallPullIfNeeded($controller);
            return;
        }

        // BYOB reader path: copy into pending pull-into view, fulfill read requests.
        if (
            $reader instanceof JsObject
            && $reader->getInternalProperty('[[IsReadableStreamBYOBReader]]') === true
        ) {
            // Push to queue as a snapshot; respond logic will consume.
            $bytes = $buffer->readBytes($offset, $byteLen);
            $u8 = StreamHelpers::makeUint8Array($bytes);
            StreamHelpers::enqueueValueWithSize($controller, $u8, (float) $byteLen);
            self::byteControllerProcessPullIntoDescriptorsUsingQueue($controller);
            self::byteControllerCallPullIfNeeded($controller);
            return;
        }

        // No reader at all: queue.
        $bytes = $buffer->readBytes($offset, $byteLen);
        $u8 = StreamHelpers::makeUint8Array($bytes);
        StreamHelpers::enqueueValueWithSize($controller, $u8, (float) $byteLen);
        self::byteControllerCallPullIfNeeded($controller);
    }

    public static function byteControllerClose(JsObject $controller): void
    {
        $stream = $controller->getInternalProperty('[[Stream]]');
        if (!$stream instanceof JsObject) {
            return;
        }
        if ($controller->getInternalProperty('[[CloseRequested]]') === true) {
            return;
        }
        if ($stream->getInternalProperty('[[State]]') !== StreamHelpers::STATE_READABLE) {
            return;
        }
        if (StreamHelpers::queueSize($controller) > 0) {
            $controller->setInternalProperty('[[CloseRequested]]', true);
            return;
        }
        $pending = $controller->getInternalProperty('[[PendingPullIntos]]') ?? [];
        if ($pending !== []) {
            $first = $pending[0];
            if ($first['bytesFilled'] > 0) {
                $err = StreamHelpers::createTypeError('Cannot close: pending BYOB read with partial fill');
                self::byteControllerError($controller, $err);
                throw new TypeError('Cannot close: pending BYOB read with partial fill');
            }
        }
        self::byteControllerClearAlgorithms($controller);
        \Phasis\BuiltIn\Streams\ReadableStream::readableStreamClose($stream);
    }

    public static function byteControllerError(JsObject $controller, JsValue $error): void
    {
        $stream = $controller->getInternalProperty('[[Stream]]');
        if (!$stream instanceof JsObject) {
            return;
        }
        if ($stream->getInternalProperty('[[State]]') !== StreamHelpers::STATE_READABLE) {
            return;
        }
        self::byteControllerClearPendingPullIntos($controller);
        StreamHelpers::resetQueue($controller);
        self::byteControllerClearAlgorithms($controller);
        \Phasis\BuiltIn\Streams\ReadableStream::readableStreamError($stream, $error);
    }

    public static function byteControllerClearAlgorithms(JsObject $controller): void
    {
        $controller->setInternalProperty('[[PullAlgorithm]]', null);
        $controller->setInternalProperty('[[CancelAlgorithm]]', null);
    }

    public static function byteControllerClearPendingPullIntos(JsObject $controller): void
    {
        self::byteControllerInvalidateBYOBRequest($controller);
        $controller->setInternalProperty('[[PendingPullIntos]]', []);
    }

    public static function byteControllerInvalidateBYOBRequest(JsObject $controller): void
    {
        $req = $controller->getInternalProperty('[[ByobRequest]]');
        if (!$req instanceof JsObject) {
            return;
        }
        $req->setInternalProperty('[[Controller]]', null);
        $req->setInternalProperty('[[View]]', JsNull::instance());
        $controller->setInternalProperty('[[ByobRequest]]', null);
    }

    // ------------------------------------------------------------------
    // BYOB Reader
    // ------------------------------------------------------------------

    public static function acquireBYOBReader(JsObject $stream): JsObject
    {
        if ($stream->getInternalProperty('[[ControllerType]]') !== 'byte') {
            throw new TypeError('Cannot acquire BYOB reader on default stream');
        }
        if (\Phasis\BuiltIn\Streams\ReadableStream::isReadableStreamLocked($stream)) {
            throw new TypeError('Stream is locked');
        }
        $reader = new JsObject(self::$byobReaderPrototype);
        self::setUpBYOBReader($reader, $stream);
        return $reader;
    }

    public static function setUpBYOBReader(JsObject $reader, JsObject $stream): void
    {
        if (\Phasis\BuiltIn\Streams\ReadableStream::isReadableStreamLocked($stream)) {
            throw new TypeError('Stream is locked');
        }
        $reader->setInternalProperty('[[IsReadableStreamBYOBReader]]', true);
        $reader->setInternalProperty('[[Stream]]', $stream);
        $reader->setInternalProperty('[[ReadIntoRequests]]', []);

        $closed = new JsPromise();
        $state = $stream->getInternalProperty('[[State]]');
        if ($state === StreamHelpers::STATE_CLOSED) {
            $closed->resolve(JsUndefined::instance());
        } elseif ($state === StreamHelpers::STATE_ERRORED) {
            $closed->reject($stream->getInternalProperty('[[StoredError]]'));
            StreamHelpers::markPromiseHandled($closed);
        }
        $reader->setInternalProperty('[[ClosedPromise]]', $closed);

        $stream->setInternalProperty('[[Reader]]', $reader);
    }

    public static function byobReaderRelease(JsObject $reader): void
    {
        $stream = $reader->getInternalProperty('[[Stream]]');
        if (!$stream instanceof JsObject) {
            return;
        }
        $closedPromise = $reader->getInternalProperty('[[ClosedPromise]]');
        if ($closedPromise instanceof JsPromise && $closedPromise->getState() === 'pending') {
            $err = StreamHelpers::createTypeError('Reader was released');
            $closedPromise->reject($err);
            StreamHelpers::markPromiseHandled($closedPromise);
        }

        $requests = $reader->getInternalProperty('[[ReadIntoRequests]]') ?? [];
        $reader->setInternalProperty('[[ReadIntoRequests]]', []);
        $err = StreamHelpers::createTypeError('Reader was released');
        foreach ($requests as $req) {
            ($req['reject'])($err);
        }

        $stream->setInternalProperty('[[Reader]]', null);
        $reader->setInternalProperty('[[Stream]]', null);
    }

    public static function byobReaderCloseRequests(JsObject $reader): void
    {
        $requests = $reader->getInternalProperty('[[ReadIntoRequests]]') ?? [];
        $reader->setInternalProperty('[[ReadIntoRequests]]', []);
        foreach ($requests as $req) {
            ($req['resolve'])(StreamHelpers::readResult(JsUndefined::instance(), true));
        }
    }

    public static function byobReaderErrorRequests(JsObject $reader, JsValue $error): void
    {
        $requests = $reader->getInternalProperty('[[ReadIntoRequests]]') ?? [];
        $reader->setInternalProperty('[[ReadIntoRequests]]', []);
        foreach ($requests as $req) {
            ($req['reject'])($error);
        }
    }

    public static function byobReaderRead(JsObject $reader, JsValue $view, int $minRead): JsPromise
    {
        $stream = $reader->getInternalProperty('[[Stream]]');
        if (!$stream instanceof JsObject) {
            return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('Reader has no stream'));
        }
        $stream->setInternalProperty('[[Disturbed]]', true);
        $state = $stream->getInternalProperty('[[State]]');
        if ($state === StreamHelpers::STATE_ERRORED) {
            return StreamHelpers::promiseRejected($stream->getInternalProperty('[[StoredError]]'));
        }

        [$buffer, $offset, $byteLen] = StreamHelpers::unwrapView($view);
        $elementSize = 1;
        $viewCtor = 'Uint8Array';
        if ($view instanceof JsTypedArray) {
            $elementSize = $view->getBytesPerElement();
            $viewCtor = $view->getTypeName();
        } elseif ($view instanceof JsDataView) {
            $elementSize = 1;
            $viewCtor = 'DataView';
        }

        // Construct a fresh buffer to hold the read (per spec TransferArrayBuffer).
        $newBuffer = new JsArrayBuffer($buffer->getByteLength());
        $newBuffer->writeBytes(0, $buffer->readBytes(0, $buffer->getByteLength()));

        $descriptor = [
            'buffer' => $newBuffer,
            'byteOffset' => $offset,
            'byteLength' => $byteLen,
            'bytesFilled' => 0,
            'elementSize' => $elementSize,
            'viewConstructor' => $viewCtor,
            'readerType' => 'byob',
            'minimumFill' => max(1, $minRead) * $elementSize,
        ];

        $promise = new JsPromise();
        $resolve = static function (JsValue $v) use ($promise): void {
            $promise->resolve($v);
        };
        $reject = static function (JsValue $r) use ($promise): void {
            $promise->reject($r);
        };
        $requests = $reader->getInternalProperty('[[ReadIntoRequests]]') ?? [];
        $requests[] = ['resolve' => $resolve, 'reject' => $reject, 'descriptor' => &$descriptor];
        $reader->setInternalProperty('[[ReadIntoRequests]]', $requests);

        $controller = $stream->getInternalProperty('[[Controller]]');
        if ($controller instanceof JsObject) {
            $pending = $controller->getInternalProperty('[[PendingPullIntos]]') ?? [];
            $pending[] = $descriptor;
            $controller->setInternalProperty('[[PendingPullIntos]]', $pending);

            if ($state === StreamHelpers::STATE_CLOSED) {
                $emptyView = self::makeUint8ArrayView($newBuffer, $offset, 0);
                $reader->setInternalProperty('[[ReadIntoRequests]]', []);
                $promise->resolve(StreamHelpers::readResult($emptyView, true));
                return $promise;
            }
            self::byteControllerProcessPullIntoDescriptorsUsingQueue($controller);
            self::byteControllerCallPullIfNeeded($controller);
        }

        return $promise;
    }

    public static function byteControllerProcessPullIntoDescriptorsUsingQueue(JsObject $controller): void
    {
        while (true) {
            $pending = $controller->getInternalProperty('[[PendingPullIntos]]') ?? [];
            if ($pending === []) {
                return;
            }
            if (StreamHelpers::queueLength($controller) === 0) {
                return;
            }
            $first = &$pending[0];
            // Copy from queue chunks into descriptor until either filled or queue empty.
            while ($first['bytesFilled'] < $first['byteLength']) {
                if (StreamHelpers::queueLength($controller) === 0) {
                    break;
                }
                $queue = $controller->getInternalProperty('[[queue]]') ?? [];
                $head = &$queue[0];
                /** @var JsTypedArray $headView */
                $headView = $head['value'];
                $headBytes = $headView->getBuffer()->readBytes($headView->getByteOffset(), $headView->getLength());
                $need = $first['byteLength'] - $first['bytesFilled'];
                $copyLen = min($need, strlen($headBytes));
                $first['buffer']->writeBytes($first['byteOffset'] + $first['bytesFilled'], substr($headBytes, 0, $copyLen));
                $first['bytesFilled'] += $copyLen;
                if ($copyLen === strlen($headBytes)) {
                    array_shift($queue);
                    $controller->setInternalProperty('[[queue]]', $queue);
                    $total = ($controller->getInternalProperty('[[queueTotalSize]]') ?? 0.0) - $copyLen;
                    $controller->setInternalProperty('[[queueTotalSize]]', max(0.0, (float) $total));
                } else {
                    $remaining = substr($headBytes, $copyLen);
                    $newU8 = StreamHelpers::makeUint8Array($remaining);
                    $head['value'] = $newU8;
                    $head['size'] = (float) strlen($remaining);
                    $controller->setInternalProperty('[[queue]]', $queue);
                    $total = ($controller->getInternalProperty('[[queueTotalSize]]') ?? 0.0) - $copyLen;
                    $controller->setInternalProperty('[[queueTotalSize]]', max(0.0, (float) $total));
                }
            }
            unset($first);
            if ($pending[0]['bytesFilled'] >= $pending[0]['minimumFill']) {
                $desc = $pending[0];
                array_shift($pending);
                $controller->setInternalProperty('[[PendingPullIntos]]', $pending);
                self::byobCommitPullInto($controller, $desc);
                continue;
            }
            break;
        }
    }

    /**
     * @param array{
     *   buffer: JsArrayBuffer,
     *   byteOffset: int,
     *   byteLength: int,
     *   bytesFilled: int,
     *   elementSize: int,
     *   viewConstructor: string,
     *   readerType: string,
     *   minimumFill: int
     * } $desc
     */
    private static function byobCommitPullInto(JsObject $controller, array $desc): void
    {
        $stream = $controller->getInternalProperty('[[Stream]]');
        if (!$stream instanceof JsObject) {
            return;
        }
        $reader = $stream->getInternalProperty('[[Reader]]');
        if (!$reader instanceof JsObject) {
            return;
        }
        $view = self::byobBuildView($desc);
        if ($desc['readerType'] === 'byob') {
            $requests = $reader->getInternalProperty('[[ReadIntoRequests]]') ?? [];
            if ($requests === []) {
                return;
            }
            $req = array_shift($requests);
            $reader->setInternalProperty('[[ReadIntoRequests]]', $requests);
            ($req['resolve'])(StreamHelpers::readResult($view, false));
        } elseif ($desc['readerType'] === 'default') {
            // Auto-allocated; this is fulfilling a default read.
            \Phasis\BuiltIn\Streams\ReadableStream::fulfillReadRequest($reader, $view, false);
        }
    }

    /**
     * @param array{buffer:JsArrayBuffer,byteOffset:int,bytesFilled:int,elementSize:int,viewConstructor:string} $desc
     */
    private static function byobBuildView(array $desc): JsTypedArray
    {
        $proto = self::resolveTypedArrayPrototype($desc['viewConstructor']);
        $length = intdiv($desc['bytesFilled'], $desc['elementSize']);
        return new JsTypedArray($desc['viewConstructor'], $desc['buffer'], $desc['byteOffset'], $length, $proto);
    }

    private static function resolveTypedArrayPrototype(string $typeName): ?JsObject
    {
        $realm = \Phasis\Engine::getCurrentRealm();
        if ($realm === null) {
            return null;
        }
        $env = $realm->getGlobalEnv();
        if (!$env->has($typeName)) {
            return null;
        }
        $ctor = $env->get($typeName);
        if (!$ctor instanceof JsObject) {
            return null;
        }
        $proto = $ctor->get('prototype');
        return $proto instanceof JsObject ? $proto : null;
    }

    // ------------------------------------------------------------------
    // Respond / respondWithNewView
    // ------------------------------------------------------------------

    public static function byteControllerRespond(JsObject $controller, int $bytesWritten): void
    {
        $pending = $controller->getInternalProperty('[[PendingPullIntos]]') ?? [];
        if ($pending === []) {
            throw new TypeError('No pending BYOB read');
        }
        $first = &$pending[0];
        $stream = $controller->getInternalProperty('[[Stream]]');
        $state = $stream instanceof JsObject ? $stream->getInternalProperty('[[State]]') : null;
        if ($state === StreamHelpers::STATE_CLOSED) {
            if ($bytesWritten !== 0) {
                throw new TypeError('Cannot respond bytes after close');
            }
        } else {
            if ($first['bytesFilled'] + $bytesWritten > $first['byteLength']) {
                throw new \Phasis\Exceptions\RangeError('bytesWritten exceeds remaining BYOB capacity');
            }
        }
        $first['bytesFilled'] += $bytesWritten;
        unset($first);
        $controller->setInternalProperty('[[PendingPullIntos]]', $pending);
        self::byteControllerInvalidateBYOBRequest($controller);
        self::byobRespondInternal($controller, $bytesWritten);
    }

    public static function byteControllerRespondWithNewView(JsObject $controller, JsValue $view): void
    {
        $pending = $controller->getInternalProperty('[[PendingPullIntos]]') ?? [];
        if ($pending === []) {
            throw new TypeError('No pending BYOB read');
        }
        [$buffer, $offset, $byteLen] = StreamHelpers::unwrapView($view);
        $first = &$pending[0];
        if ($first['byteOffset'] + $first['bytesFilled'] !== $offset) {
            throw new \Phasis\Exceptions\RangeError('View offset does not match pending pull-into');
        }
        if ($byteLen + $first['bytesFilled'] > $first['byteLength']) {
            throw new \Phasis\Exceptions\RangeError('New view too large');
        }
        // Copy bytes from new view into first descriptor's buffer.
        $first['buffer']->writeBytes(
            $first['byteOffset'] + $first['bytesFilled'],
            $buffer->readBytes($offset, $byteLen)
        );
        $first['bytesFilled'] += $byteLen;
        unset($first);
        $controller->setInternalProperty('[[PendingPullIntos]]', $pending);
        self::byteControllerInvalidateBYOBRequest($controller);
        self::byobRespondInternal($controller, $byteLen);
    }

    private static function byobRespondInternal(JsObject $controller, int $bytesWritten): void
    {
        $stream = $controller->getInternalProperty('[[Stream]]');
        if (!$stream instanceof JsObject) {
            return;
        }
        $state = $stream->getInternalProperty('[[State]]');
        $pending = $controller->getInternalProperty('[[PendingPullIntos]]') ?? [];
        if ($pending === []) {
            return;
        }
        if ($state === StreamHelpers::STATE_CLOSED) {
            $first = $pending[0];
            if ($first['bytesFilled'] !== 0) {
                throw new TypeError('Cannot close: partial BYOB fill');
            }
            // Detach pending pull-into, return done.
            array_shift($pending);
            $controller->setInternalProperty('[[PendingPullIntos]]', $pending);
            $reader = $stream->getInternalProperty('[[Reader]]');
            if ($reader instanceof JsObject && $reader->getInternalProperty('[[IsReadableStreamBYOBReader]]') === true) {
                $view = self::byobBuildView($first);
                $requests = $reader->getInternalProperty('[[ReadIntoRequests]]') ?? [];
                if ($requests !== []) {
                    $req = array_shift($requests);
                    $reader->setInternalProperty('[[ReadIntoRequests]]', $requests);
                    ($req['resolve'])(StreamHelpers::readResult($view, true));
                }
            }
            return;
        }
        $first = $pending[0];
        if ($first['bytesFilled'] < $first['minimumFill']) {
            return;
        }
        array_shift($pending);
        $controller->setInternalProperty('[[PendingPullIntos]]', $pending);
        self::byobCommitPullInto($controller, $first);
        self::byteControllerCallPullIfNeeded($controller);
    }
}
