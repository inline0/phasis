<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Streams;

use Phasis\BuiltIn\SymbolConstructor;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsPromise;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * WHATWG Streams: WritableStream + DefaultController + DefaultWriter.
 *
 * Spec: https://streams.spec.whatwg.org/#ws-class
 *
 * Internal slots used:
 *
 * WritableStream:
 *   [[IsWritableStream]]     bool
 *   [[State]]                'writable'|'closed'|'erroring'|'errored'
 *   [[StoredError]]          JsValue
 *   [[Writer]]               JsObject|null
 *   [[Controller]]           JsObject|null
 *   [[Backpressure]]         bool
 *   [[CloseRequest]]         array{resolve,reject,promise:JsPromise}|null
 *   [[InFlightWriteRequest]] array|null
 *   [[InFlightCloseRequest]] array|null
 *   [[PendingAbortRequest]]  array|null
 *   [[WriteRequests]]        list<array{resolve,reject}>
 *
 * WritableStreamDefaultController:
 *   [[IsWritableStreamDefaultController]] bool
 *   [[Stream]]               JsObject
 *   [[queue]]                list
 *   [[queueTotalSize]]       float
 *   [[Started]]              bool
 *   [[StrategyHWM]]          float
 *   [[StrategySizeAlgorithm]] JsFunction|null
 *   [[WriteAlgorithm]]       \Closure(JsValue):JsPromise
 *   [[CloseAlgorithm]]       \Closure():JsPromise
 *   [[AbortAlgorithm]]       \Closure(JsValue):JsPromise
 *   [[AbortController]]      JsObject|null  (an AbortController for signal exposure)
 *
 * WritableStreamDefaultWriter:
 *   [[IsWritableStreamDefaultWriter]] bool
 *   [[Stream]]               JsObject|null
 *   [[ClosedPromise]]        JsPromise
 *   [[ReadyPromise]]         JsPromise
 *   [[ClosedPromiseFulfilled]] bool
 */
final class WritableStream
{
    private static ?JsObject $prototype = null;
    private static ?JsObject $controllerPrototype = null;
    private static ?JsObject $writerPrototype = null;

    public static function getPrototype(): ?JsObject
    {
        return self::$prototype;
    }

    public static function getControllerPrototype(): ?JsObject
    {
        return self::$controllerPrototype;
    }

    public static function getWriterPrototype(): ?JsObject
    {
        return self::$writerPrototype;
    }

    public static function install(Environment $env): JsFunction
    {
        self::$prototype = new JsObject();
        self::$controllerPrototype = new JsObject();
        self::$writerPrototype = new JsObject();

        self::buildControllerPrototype();
        self::buildWriterPrototype();
        self::buildStreamPrototype();

        $ctor = JsFunction::fromCallable(
            'WritableStream',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError("Constructor WritableStream requires 'new'");
                }
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsObject) {
                    $ntProto = $newTarget->get('prototype');
                    $useProto = $ntProto instanceof JsObject ? $ntProto : self::$prototype;
                    $this_->setPrototype($useProto);
                }
                $underlyingSink = $args[0] ?? JsUndefined::instance();
                $strategy = $args[1] ?? JsUndefined::instance();

                $type = JsUndefined::instance();
                if ($underlyingSink instanceof JsObject) {
                    $type = $underlyingSink->get('type');
                }
                if (!$type instanceof JsUndefined) {
                    throw new \Phasis\Exceptions\RangeError('Invalid type for WritableStream');
                }
                $sizeAlgo = StreamHelpers::extractSizeAlgorithm(
                    $strategy instanceof JsUndefined ? null : $strategy
                );
                $hwm = StreamHelpers::extractHighWaterMark(
                    $strategy instanceof JsUndefined ? null : $strategy,
                    1.0
                );

                self::initializeStream($this_);
                self::setUpDefaultController($this_, $underlyingSink, $hwm, $sizeAlgo);
                return $this_;
            },
            0,
        );
        $ctor->setConstructable();
        $ctor->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data(self::$prototype, false, false, false)
        );
        self::$prototype->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($ctor, true, false, true)
        );
        $env->defineVar('WritableStream', $ctor);

        $ctorCtl = JsFunction::fromCallable(
            'WritableStreamDefaultController',
            function (JsValue $this_, array $args): JsValue {
                throw new TypeError('Illegal constructor');
            },
            0,
        );
        $ctorCtl->setConstructable();
        $ctorCtl->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data(self::$controllerPrototype, false, false, false)
        );
        self::$controllerPrototype->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($ctorCtl, true, false, true)
        );
        $env->defineVar('WritableStreamDefaultController', $ctorCtl);

        $ctorWriter = JsFunction::fromCallable(
            'WritableStreamDefaultWriter',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError("Constructor WritableStreamDefaultWriter requires 'new'");
                }
                $stream = $args[0] ?? JsUndefined::instance();
                if (!self::isWritableStream($stream)) {
                    throw new TypeError('Argument must be a WritableStream');
                }
                /** @var JsObject $stream */
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsObject) {
                    $ntProto = $newTarget->get('prototype');
                    $useProto = $ntProto instanceof JsObject ? $ntProto : self::$writerPrototype;
                    $this_->setPrototype($useProto);
                }
                self::setUpDefaultWriter($this_, $stream);
                return $this_;
            },
            1,
        );
        $ctorWriter->setConstructable();
        $ctorWriter->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data(self::$writerPrototype, false, false, false)
        );
        self::$writerPrototype->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($ctorWriter, true, false, true)
        );
        $env->defineVar('WritableStreamDefaultWriter', $ctorWriter);
        return $ctor;
    }

    public static function isWritableStream(JsValue $v): bool
    {
        return $v instanceof JsObject && $v->getInternalProperty('[[IsWritableStream]]') === true;
    }

    public static function isWritableStreamDefaultController(JsValue $v): bool
    {
        return $v instanceof JsObject && $v->getInternalProperty('[[IsWritableStreamDefaultController]]') === true;
    }

    public static function isWritableStreamDefaultWriter(JsValue $v): bool
    {
        return $v instanceof JsObject && $v->getInternalProperty('[[IsWritableStreamDefaultWriter]]') === true;
    }

    public static function isLocked(JsObject $stream): bool
    {
        return $stream->getInternalProperty('[[Writer]]') !== null;
    }

    public static function initializeStream(JsObject $stream): void
    {
        $stream->setInternalProperty('[[IsWritableStream]]', true);
        $stream->setInternalProperty('[[State]]', StreamHelpers::WS_WRITABLE);
        $stream->setInternalProperty('[[StoredError]]', JsUndefined::instance());
        $stream->setInternalProperty('[[Writer]]', null);
        $stream->setInternalProperty('[[Controller]]', null);
        $stream->setInternalProperty('[[Backpressure]]', false);
        $stream->setInternalProperty('[[CloseRequest]]', null);
        $stream->setInternalProperty('[[InFlightWriteRequest]]', null);
        $stream->setInternalProperty('[[InFlightCloseRequest]]', null);
        $stream->setInternalProperty('[[PendingAbortRequest]]', null);
        $stream->setInternalProperty('[[WriteRequests]]', []);
    }

    private static function buildStreamPrototype(): void
    {
        $proto = self::$prototype;

        $lockedGetter = JsFunction::fromCallable(
            'get locked',
            function (JsValue $this_): JsValue {
                if (!self::isWritableStream($this_)) {
                    throw new TypeError('locked called on non-WritableStream');
                }
                /** @var JsObject $this_ */
                return JsBoolean::of(self::isLocked($this_));
            },
            0,
        );
        $proto->defineOwnProperty(
            'locked',
            PropertyDescriptor::accessor($lockedGetter, null, false, true)
        );

        $abortFn = JsFunction::fromCallable(
            'abort',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isWritableStream($this_)) {
                    return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('abort called on non-WritableStream'));
                }
                /** @var JsObject $this_ */
                if (self::isLocked($this_)) {
                    return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('Cannot abort a locked stream'));
                }
                return self::abortStream($this_, $args[0] ?? JsUndefined::instance());
            },
            1,
        );
        $proto->defineOwnProperty('abort', PropertyDescriptor::data($abortFn, true, false, true));

        $closeFn = JsFunction::fromCallable(
            'close',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isWritableStream($this_)) {
                    return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('close called on non-WritableStream'));
                }
                /** @var JsObject $this_ */
                if (self::isLocked($this_)) {
                    return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('Cannot close a locked stream'));
                }
                if (self::closeQueuedOrInFlight($this_)) {
                    return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('Stream already closing'));
                }
                return self::closeStream($this_);
            },
            0,
        );
        $proto->defineOwnProperty('close', PropertyDescriptor::data($closeFn, true, false, true));

        $getWriterFn = JsFunction::fromCallable(
            'getWriter',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isWritableStream($this_)) {
                    throw new TypeError('getWriter called on non-WritableStream');
                }
                /** @var JsObject $this_ */
                return self::acquireDefaultWriter($this_);
            },
            0,
        );
        $proto->defineOwnProperty('getWriter', PropertyDescriptor::data($getWriterFn, true, false, true));

        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('WritableStream'), false, false, true)
        );
    }

    private static function buildControllerPrototype(): void
    {
        $proto = self::$controllerPrototype;

        // signal getter — exposes a JS AbortSignal if available.
        $signalGetter = JsFunction::fromCallable(
            'get signal',
            function (JsValue $this_): JsValue {
                if (!self::isWritableStreamDefaultController($this_)) {
                    throw new TypeError('signal called on non-controller');
                }
                /** @var JsObject $this_ */
                $signal = $this_->getInternalProperty('[[Signal]]');
                if ($signal instanceof JsObject) {
                    return $signal;
                }
                // Synthesize a minimal placeholder signal-like object.
                $placeholder = new JsObject();
                $placeholder->defineOwnProperty(
                    'aborted',
                    PropertyDescriptor::data(JsBoolean::of(false), true, true, true)
                );
                $placeholder->defineOwnProperty(
                    'reason',
                    PropertyDescriptor::data(JsUndefined::instance(), true, true, true)
                );
                return $placeholder;
            },
            0,
        );
        $proto->defineOwnProperty(
            'signal',
            PropertyDescriptor::accessor($signalGetter, null, false, true)
        );

        $errorFn = JsFunction::fromCallable(
            'error',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isWritableStreamDefaultController($this_)) {
                    throw new TypeError('error called on non-controller');
                }
                /** @var JsObject $this_ */
                $stream = $this_->getInternalProperty('[[Stream]]');
                if ($stream instanceof JsObject && $stream->getInternalProperty('[[State]]') === StreamHelpers::WS_WRITABLE) {
                    self::controllerError($this_, $args[0] ?? JsUndefined::instance());
                }
                return JsUndefined::instance();
            },
            1,
        );
        $proto->defineOwnProperty('error', PropertyDescriptor::data($errorFn, true, false, true));

        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('WritableStreamDefaultController'), false, false, true)
        );
    }

    private static function buildWriterPrototype(): void
    {
        $proto = self::$writerPrototype;

        $closedGetter = JsFunction::fromCallable(
            'get closed',
            function (JsValue $this_): JsValue {
                if (!self::isWritableStreamDefaultWriter($this_)) {
                    return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('closed on non-writer'));
                }
                /** @var JsObject $this_ */
                $p = $this_->getInternalProperty('[[ClosedPromise]]');
                return $p instanceof JsPromise ? $p : StreamHelpers::promiseResolved(JsUndefined::instance());
            },
            0,
        );
        $proto->defineOwnProperty('closed', PropertyDescriptor::accessor($closedGetter, null, false, true));

        $readyGetter = JsFunction::fromCallable(
            'get ready',
            function (JsValue $this_): JsValue {
                if (!self::isWritableStreamDefaultWriter($this_)) {
                    return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('ready on non-writer'));
                }
                /** @var JsObject $this_ */
                $p = $this_->getInternalProperty('[[ReadyPromise]]');
                return $p instanceof JsPromise ? $p : StreamHelpers::promiseResolved(JsUndefined::instance());
            },
            0,
        );
        $proto->defineOwnProperty('ready', PropertyDescriptor::accessor($readyGetter, null, false, true));

        $desiredSizeGetter = JsFunction::fromCallable(
            'get desiredSize',
            function (JsValue $this_): JsValue {
                if (!self::isWritableStreamDefaultWriter($this_)) {
                    throw new TypeError('desiredSize on non-writer');
                }
                /** @var JsObject $this_ */
                $stream = $this_->getInternalProperty('[[Stream]]');
                if (!$stream instanceof JsObject) {
                    throw new TypeError('Writer not associated with a stream');
                }
                $state = $stream->getInternalProperty('[[State]]');
                if ($state === StreamHelpers::WS_ERRORED || $state === StreamHelpers::WS_ERRORING) {
                    return JsNull::instance();
                }
                if ($state === StreamHelpers::WS_CLOSED) {
                    return JsNumber::of(0.0);
                }
                $ctrl = $stream->getInternalProperty('[[Controller]]');
                if (!$ctrl instanceof JsObject) {
                    return JsNumber::of(0.0);
                }
                $hwm = (float) ($ctrl->getInternalProperty('[[StrategyHWM]]') ?? 0.0);
                $size = StreamHelpers::queueSize($ctrl);
                return JsNumber::of($hwm - $size);
            },
            0,
        );
        $proto->defineOwnProperty(
            'desiredSize',
            PropertyDescriptor::accessor($desiredSizeGetter, null, false, true)
        );

        $abortFn = JsFunction::fromCallable(
            'abort',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isWritableStreamDefaultWriter($this_)) {
                    return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('abort on non-writer'));
                }
                /** @var JsObject $this_ */
                $stream = $this_->getInternalProperty('[[Stream]]');
                if (!$stream instanceof JsObject) {
                    return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('Writer detached'));
                }
                return self::abortStream($stream, $args[0] ?? JsUndefined::instance());
            },
            1,
        );
        $proto->defineOwnProperty('abort', PropertyDescriptor::data($abortFn, true, false, true));

        $closeFn = JsFunction::fromCallable(
            'close',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isWritableStreamDefaultWriter($this_)) {
                    return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('close on non-writer'));
                }
                /** @var JsObject $this_ */
                $stream = $this_->getInternalProperty('[[Stream]]');
                if (!$stream instanceof JsObject) {
                    return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('Writer detached'));
                }
                if (self::closeQueuedOrInFlight($stream)) {
                    return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('Already closing'));
                }
                return self::closeStream($stream);
            },
            0,
        );
        $proto->defineOwnProperty('close', PropertyDescriptor::data($closeFn, true, false, true));

        $releaseLockFn = JsFunction::fromCallable(
            'releaseLock',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isWritableStreamDefaultWriter($this_)) {
                    throw new TypeError('releaseLock on non-writer');
                }
                /** @var JsObject $this_ */
                $stream = $this_->getInternalProperty('[[Stream]]');
                if (!$stream instanceof JsObject) {
                    return JsUndefined::instance();
                }
                self::writerRelease($this_);
                return JsUndefined::instance();
            },
            0,
        );
        $proto->defineOwnProperty('releaseLock', PropertyDescriptor::data($releaseLockFn, true, false, true));

        $writeFn = JsFunction::fromCallable(
            'write',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isWritableStreamDefaultWriter($this_)) {
                    return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('write on non-writer'));
                }
                /** @var JsObject $this_ */
                $stream = $this_->getInternalProperty('[[Stream]]');
                if (!$stream instanceof JsObject) {
                    return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('Writer detached'));
                }
                return self::writerWrite($this_, $args[0] ?? JsUndefined::instance());
            },
            1,
        );
        $proto->defineOwnProperty('write', PropertyDescriptor::data($writeFn, true, false, true));

        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('WritableStreamDefaultWriter'), false, false, true)
        );
    }

    // ------------------------------------------------------------------
    // Set up the default controller
    // ------------------------------------------------------------------

    public static function setUpDefaultController(
        JsObject $stream,
        JsValue $underlyingSink,
        float $highWaterMark,
        ?JsFunction $sizeAlgorithm
    ): void {
        $controller = new JsObject(self::$controllerPrototype);
        $controller->setInternalProperty('[[IsWritableStreamDefaultController]]', true);
        $controller->setInternalProperty('[[Stream]]', $stream);
        StreamHelpers::resetQueue($controller);
        $controller->setInternalProperty('[[Started]]', false);
        $controller->setInternalProperty('[[StrategyHWM]]', $highWaterMark);
        $controller->setInternalProperty('[[StrategySizeAlgorithm]]', $sizeAlgorithm);

        $startFn = null;
        $writeFn = null;
        $closeFn = null;
        $abortFn = null;
        if ($underlyingSink instanceof JsObject) {
            $startFn = StreamHelpers::asFunctionOrNull($underlyingSink->get('start'));
            $writeFn = StreamHelpers::asFunctionOrNull($underlyingSink->get('write'));
            $closeFn = StreamHelpers::asFunctionOrNull($underlyingSink->get('close'));
            $abortFn = StreamHelpers::asFunctionOrNull($underlyingSink->get('abort'));
        }

        $controller->setInternalProperty(
            '[[WriteAlgorithm]]',
            function (JsValue $chunk) use ($writeFn, $controller, $underlyingSink): JsPromise {
                if ($writeFn === null) {
                    return StreamHelpers::promiseResolved(JsUndefined::instance());
                }
                return StreamHelpers::promiseCall($writeFn, $underlyingSink, [$chunk, $controller]);
            }
        );
        $controller->setInternalProperty(
            '[[CloseAlgorithm]]',
            function () use ($closeFn, $underlyingSink): JsPromise {
                if ($closeFn === null) {
                    return StreamHelpers::promiseResolved(JsUndefined::instance());
                }
                return StreamHelpers::promiseCall($closeFn, $underlyingSink, []);
            }
        );
        $controller->setInternalProperty(
            '[[AbortAlgorithm]]',
            function (JsValue $reason) use ($abortFn, $underlyingSink): JsPromise {
                if ($abortFn === null) {
                    return StreamHelpers::promiseResolved(JsUndefined::instance());
                }
                return StreamHelpers::promiseCall($abortFn, $underlyingSink, [$reason]);
            }
        );

        $stream->setInternalProperty('[[Controller]]', $controller);

        // Set initial backpressure based on hwm.
        $backpressure = self::shouldApplyBackpressure($controller);
        $stream->setInternalProperty('[[Backpressure]]', $backpressure);

        $startResult = JsUndefined::instance();
        if ($startFn !== null) {
            try {
                $startResult = $startFn->call($underlyingSink, [$controller]);
            } catch (\Throwable $e) {
                self::controllerError($controller, StreamHelpers::exceptionToJsValue($e));
                return;
            }
        }
        $startPromise = StreamHelpers::toPromise($startResult);
        $startPromise->addFulfillHandler(static function (JsValue $_) use ($controller): void {
            $controller->setInternalProperty('[[Started]]', true);
            self::controllerAdvanceQueueIfNeeded($controller);
        });
        $startPromise->addRejectHandler(static function (JsValue $r) use ($controller): void {
            $controller->setInternalProperty('[[Started]]', true);
            self::controllerError($controller, $r);
        });
    }

    public static function shouldApplyBackpressure(JsObject $controller): bool
    {
        $hwm = (float) ($controller->getInternalProperty('[[StrategyHWM]]') ?? 0.0);
        $size = StreamHelpers::queueSize($controller);
        return $size > $hwm;
    }

    // ------------------------------------------------------------------
    // Writer setup
    // ------------------------------------------------------------------

    public static function acquireDefaultWriter(JsObject $stream): JsObject
    {
        if (self::isLocked($stream)) {
            throw new TypeError('WritableStream is locked');
        }
        $writer = new JsObject(self::$writerPrototype);
        self::setUpDefaultWriter($writer, $stream);
        return $writer;
    }

    public static function setUpDefaultWriter(JsObject $writer, JsObject $stream): void
    {
        if (self::isLocked($stream)) {
            throw new TypeError('WritableStream is locked');
        }
        $writer->setInternalProperty('[[IsWritableStreamDefaultWriter]]', true);
        $writer->setInternalProperty('[[Stream]]', $stream);

        $closed = new JsPromise();
        $ready = new JsPromise();
        $state = $stream->getInternalProperty('[[State]]');
        if ($state === StreamHelpers::WS_WRITABLE) {
            if ($stream->getInternalProperty('[[Backpressure]]') === true && !self::closeQueuedOrInFlight($stream)) {
                // ready stays pending
            } else {
                $ready->resolve(JsUndefined::instance());
            }
        } elseif ($state === StreamHelpers::WS_ERRORING) {
            $ready->reject($stream->getInternalProperty('[[StoredError]]'));
            StreamHelpers::markPromiseHandled($ready);
        } elseif ($state === StreamHelpers::WS_ERRORED) {
            $err = $stream->getInternalProperty('[[StoredError]]');
            $ready->reject($err);
            StreamHelpers::markPromiseHandled($ready);
            $closed->reject($err);
            StreamHelpers::markPromiseHandled($closed);
        } elseif ($state === StreamHelpers::WS_CLOSED) {
            $ready->resolve(JsUndefined::instance());
            $closed->resolve(JsUndefined::instance());
        }
        $writer->setInternalProperty('[[ClosedPromise]]', $closed);
        $writer->setInternalProperty('[[ReadyPromise]]', $ready);
        $stream->setInternalProperty('[[Writer]]', $writer);
    }

    public static function writerRelease(JsObject $writer): void
    {
        $stream = $writer->getInternalProperty('[[Stream]]');
        if (!$stream instanceof JsObject) {
            return;
        }
        $err = StreamHelpers::createTypeError('Writer was released');
        // Reject closed & ready if pending.
        $closed = $writer->getInternalProperty('[[ClosedPromise]]');
        if ($closed instanceof JsPromise && $closed->getState() === 'pending') {
            $closed->reject($err);
            StreamHelpers::markPromiseHandled($closed);
        }
        $ready = $writer->getInternalProperty('[[ReadyPromise]]');
        if ($ready instanceof JsPromise && $ready->getState() === 'pending') {
            $ready->reject($err);
            StreamHelpers::markPromiseHandled($ready);
        }
        $stream->setInternalProperty('[[Writer]]', null);
        $writer->setInternalProperty('[[Stream]]', null);
    }

    // ------------------------------------------------------------------
    // Write / close / abort algorithms
    // ------------------------------------------------------------------

    public static function writerWrite(JsObject $writer, JsValue $chunk): JsPromise
    {
        $stream = $writer->getInternalProperty('[[Stream]]');
        if (!$stream instanceof JsObject) {
            return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('Writer not attached'));
        }
        $controller = $stream->getInternalProperty('[[Controller]]');
        if (!$controller instanceof JsObject) {
            return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('No controller'));
        }
        if (self::closeQueuedOrInFlight($stream)) {
            return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('Cannot write: closing'));
        }
        $state = $stream->getInternalProperty('[[State]]');
        if ($state === StreamHelpers::WS_ERRORED) {
            return StreamHelpers::promiseRejected($stream->getInternalProperty('[[StoredError]]'));
        }
        if ($state === StreamHelpers::WS_CLOSED) {
            return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('Stream closed'));
        }
        if ($state === StreamHelpers::WS_ERRORING) {
            return StreamHelpers::promiseRejected($stream->getInternalProperty('[[StoredError]]'));
        }

        // Compute chunk size.
        $chunkSize = 1.0;
        $sizeAlgo = $controller->getInternalProperty('[[StrategySizeAlgorithm]]');
        if ($sizeAlgo instanceof JsFunction) {
            try {
                $s = $sizeAlgo->call(JsUndefined::instance(), [$chunk]);
                $chunkSize = (float) \Phasis\Spec\TypeConversion::toNumber($s);
            } catch (\Throwable $e) {
                self::controllerErrorIfNeeded($controller, StreamHelpers::exceptionToJsValue($e));
                return StreamHelpers::promiseRejected(StreamHelpers::exceptionToJsValue($e));
            }
        }

        $promise = new JsPromise();
        $resolve = static function (JsValue $v) use ($promise): void {
            $promise->resolve($v);
        };
        $reject = static function (JsValue $r) use ($promise): void {
            $promise->reject($r);
        };
        $writes = $stream->getInternalProperty('[[WriteRequests]]') ?? [];
        $writes[] = ['resolve' => $resolve, 'reject' => $reject];
        $stream->setInternalProperty('[[WriteRequests]]', $writes);

        // Enqueue the write into the controller queue.
        try {
            StreamHelpers::enqueueValueWithSize($controller, $chunk, $chunkSize);
        } catch (\Throwable $e) {
            self::controllerErrorIfNeeded($controller, StreamHelpers::exceptionToJsValue($e));
            return StreamHelpers::promiseRejected(StreamHelpers::exceptionToJsValue($e));
        }

        // Update backpressure.
        $bp = self::shouldApplyBackpressure($controller);
        if ($bp !== $stream->getInternalProperty('[[Backpressure]]')) {
            $stream->setInternalProperty('[[Backpressure]]', $bp);
            $writer = $stream->getInternalProperty('[[Writer]]');
            if ($writer instanceof JsObject) {
                if ($bp) {
                    $writer->setInternalProperty('[[ReadyPromise]]', new JsPromise());
                } else {
                    $ready = $writer->getInternalProperty('[[ReadyPromise]]');
                    if ($ready instanceof JsPromise) {
                        $ready->resolve(JsUndefined::instance());
                    }
                }
            }
        }

        self::controllerAdvanceQueueIfNeeded($controller);
        return $promise;
    }

    public static function closeQueuedOrInFlight(JsObject $stream): bool
    {
        return $stream->getInternalProperty('[[CloseRequest]]') !== null
            || $stream->getInternalProperty('[[InFlightCloseRequest]]') !== null;
    }

    public static function closeStream(JsObject $stream): JsPromise
    {
        $state = $stream->getInternalProperty('[[State]]');
        if ($state === StreamHelpers::WS_CLOSED) {
            return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('Stream closed'));
        }
        if ($state === StreamHelpers::WS_ERRORED) {
            return StreamHelpers::promiseRejected($stream->getInternalProperty('[[StoredError]]'));
        }
        $promise = new JsPromise();
        $req = ['resolve' => static function (JsValue $v) use ($promise): void {
            $promise->resolve($v);
        }, 'reject' => static function (JsValue $r) use ($promise): void {
            $promise->reject($r);
        }, 'promise' => $promise];
        $stream->setInternalProperty('[[CloseRequest]]', $req);
        // Send a sentinel "close" through the queue.
        $controller = $stream->getInternalProperty('[[Controller]]');
        if ($controller instanceof JsObject) {
            // Trigger advance to process close.
            self::controllerAdvanceQueueIfNeeded($controller);
        }
        return $promise;
    }

    public static function abortStream(JsObject $stream, JsValue $reason): JsPromise
    {
        $state = $stream->getInternalProperty('[[State]]');
        if ($state === StreamHelpers::WS_CLOSED) {
            return StreamHelpers::promiseResolved(JsUndefined::instance());
        }
        if ($state === StreamHelpers::WS_ERRORED) {
            return StreamHelpers::promiseResolved(JsUndefined::instance());
        }
        $existing = $stream->getInternalProperty('[[PendingAbortRequest]]');
        if ($existing !== null) {
            return $existing['promise'];
        }
        $promise = new JsPromise();
        $req = [
            'reason' => $reason,
            'promise' => $promise,
        ];
        $stream->setInternalProperty('[[PendingAbortRequest]]', $req);

        // Reject pending writes.
        $writes = $stream->getInternalProperty('[[WriteRequests]]') ?? [];
        $stream->setInternalProperty('[[WriteRequests]]', []);
        foreach ($writes as $w) {
            ($w['reject'])($reason);
        }

        // Run the controller's abort algorithm.
        $controller = $stream->getInternalProperty('[[Controller]]');
        if ($controller instanceof JsObject) {
            $abortAlgo = $controller->getInternalProperty('[[AbortAlgorithm]]');
            if ($abortAlgo instanceof \Closure) {
                $abortPromise = $abortAlgo($reason);
                $abortPromise->addFulfillHandler(static function (JsValue $_) use ($stream, $promise, $reason): void {
                    self::finishErrorWith($stream, $reason);
                    $promise->resolve(JsUndefined::instance());
                });
                $abortPromise->addRejectHandler(static function (JsValue $r) use ($stream, $promise, $reason): void {
                    self::finishErrorWith($stream, $reason);
                    $promise->reject($r);
                });
            } else {
                self::finishErrorWith($stream, $reason);
                $promise->resolve(JsUndefined::instance());
            }
        } else {
            self::finishErrorWith($stream, $reason);
            $promise->resolve(JsUndefined::instance());
        }
        return $promise;
    }

    private static function finishErrorWith(JsObject $stream, JsValue $error): void
    {
        $stream->setInternalProperty('[[State]]', StreamHelpers::WS_ERRORED);
        $stream->setInternalProperty('[[StoredError]]', $error);

        // Reject CloseRequest if any.
        $closeReq = $stream->getInternalProperty('[[CloseRequest]]');
        if ($closeReq !== null) {
            $stream->setInternalProperty('[[CloseRequest]]', null);
            ($closeReq['reject'])($error);
        }
        // Reject writer's closed/ready.
        $writer = $stream->getInternalProperty('[[Writer]]');
        if ($writer instanceof JsObject) {
            $closed = $writer->getInternalProperty('[[ClosedPromise]]');
            if ($closed instanceof JsPromise && $closed->getState() === 'pending') {
                $closed->reject($error);
                StreamHelpers::markPromiseHandled($closed);
            }
            $ready = $writer->getInternalProperty('[[ReadyPromise]]');
            if ($ready instanceof JsPromise && $ready->getState() === 'pending') {
                $ready->reject($error);
                StreamHelpers::markPromiseHandled($ready);
            }
        }
    }

    // ------------------------------------------------------------------
    // Controller advance loop
    // ------------------------------------------------------------------

    public static function controllerAdvanceQueueIfNeeded(JsObject $controller): void
    {
        $stream = $controller->getInternalProperty('[[Stream]]');
        if (!$stream instanceof JsObject) {
            return;
        }
        if ($controller->getInternalProperty('[[Started]]') !== true) {
            return;
        }
        if ($stream->getInternalProperty('[[InFlightWriteRequest]]') !== null) {
            return;
        }
        $state = $stream->getInternalProperty('[[State]]');
        if ($state === StreamHelpers::WS_ERRORING || $state === StreamHelpers::WS_ERRORED) {
            return;
        }
        if (StreamHelpers::queueLength($controller) === 0) {
            // If close requested and queue empty, dispatch close.
            if ($stream->getInternalProperty('[[CloseRequest]]') !== null) {
                self::processCloseRequest($controller);
            }
            return;
        }
        self::processWriteRequest($controller);
    }

    private static function processWriteRequest(JsObject $controller): void
    {
        $stream = $controller->getInternalProperty('[[Stream]]');
        if (!$stream instanceof JsObject) {
            return;
        }
        $chunk = StreamHelpers::dequeueValue($controller);
        if ($chunk === null) {
            return;
        }
        // Pop the first write request promise from the stream.
        $writes = $stream->getInternalProperty('[[WriteRequests]]') ?? [];
        if ($writes === []) {
            return;
        }
        $writeReq = array_shift($writes);
        $stream->setInternalProperty('[[WriteRequests]]', $writes);
        $stream->setInternalProperty('[[InFlightWriteRequest]]', $writeReq);

        $writeAlgo = $controller->getInternalProperty('[[WriteAlgorithm]]');
        if (!$writeAlgo instanceof \Closure) {
            ($writeReq['resolve'])(JsUndefined::instance());
            $stream->setInternalProperty('[[InFlightWriteRequest]]', null);
            self::controllerAdvanceQueueIfNeeded($controller);
            return;
        }

        $promise = $writeAlgo($chunk);
        $promise->addFulfillHandler(static function (JsValue $_) use ($stream, $controller, $writeReq): void {
            $stream->setInternalProperty('[[InFlightWriteRequest]]', null);
            ($writeReq['resolve'])(JsUndefined::instance());
            // Reduce backpressure if needed.
            $bp = self::shouldApplyBackpressure($controller);
            if ($bp !== $stream->getInternalProperty('[[Backpressure]]')) {
                $stream->setInternalProperty('[[Backpressure]]', $bp);
                $writer = $stream->getInternalProperty('[[Writer]]');
                if ($writer instanceof JsObject) {
                    if ($bp) {
                        $writer->setInternalProperty('[[ReadyPromise]]', new JsPromise());
                    } else {
                        $ready = $writer->getInternalProperty('[[ReadyPromise]]');
                        if ($ready instanceof JsPromise) {
                            $ready->resolve(JsUndefined::instance());
                        }
                    }
                }
            }
            self::controllerAdvanceQueueIfNeeded($controller);
        });
        $promise->addRejectHandler(static function (JsValue $reason) use ($stream, $controller, $writeReq): void {
            $stream->setInternalProperty('[[InFlightWriteRequest]]', null);
            ($writeReq['reject'])($reason);
            self::controllerErrorIfNeeded($controller, $reason);
        });
    }

    private static function processCloseRequest(JsObject $controller): void
    {
        $stream = $controller->getInternalProperty('[[Stream]]');
        if (!$stream instanceof JsObject) {
            return;
        }
        $req = $stream->getInternalProperty('[[CloseRequest]]');
        if ($req === null) {
            return;
        }
        $stream->setInternalProperty('[[CloseRequest]]', null);
        $stream->setInternalProperty('[[InFlightCloseRequest]]', $req);
        $closeAlgo = $controller->getInternalProperty('[[CloseAlgorithm]]');
        if (!$closeAlgo instanceof \Closure) {
            $stream->setInternalProperty('[[State]]', StreamHelpers::WS_CLOSED);
            $stream->setInternalProperty('[[InFlightCloseRequest]]', null);
            ($req['resolve'])(JsUndefined::instance());
            $writer = $stream->getInternalProperty('[[Writer]]');
            if ($writer instanceof JsObject) {
                $closed = $writer->getInternalProperty('[[ClosedPromise]]');
                if ($closed instanceof JsPromise) {
                    $closed->resolve(JsUndefined::instance());
                }
            }
            return;
        }
        $p = $closeAlgo();
        $p->addFulfillHandler(static function (JsValue $_) use ($stream, $req): void {
            $stream->setInternalProperty('[[InFlightCloseRequest]]', null);
            $stream->setInternalProperty('[[State]]', StreamHelpers::WS_CLOSED);
            ($req['resolve'])(JsUndefined::instance());
            $writer = $stream->getInternalProperty('[[Writer]]');
            if ($writer instanceof JsObject) {
                $closed = $writer->getInternalProperty('[[ClosedPromise]]');
                if ($closed instanceof JsPromise && $closed->getState() === 'pending') {
                    $closed->resolve(JsUndefined::instance());
                }
            }
        });
        $p->addRejectHandler(static function (JsValue $r) use ($stream, $req): void {
            $stream->setInternalProperty('[[InFlightCloseRequest]]', null);
            ($req['reject'])($r);
            self::finishErrorWith($stream, $r);
        });
    }

    public static function controllerError(JsObject $controller, JsValue $error): void
    {
        self::controllerErrorIfNeeded($controller, $error);
    }

    public static function controllerErrorIfNeeded(JsObject $controller, JsValue $error): void
    {
        $stream = $controller->getInternalProperty('[[Stream]]');
        if (!$stream instanceof JsObject) {
            return;
        }
        $state = $stream->getInternalProperty('[[State]]');
        if ($state === StreamHelpers::WS_ERRORED || $state === StreamHelpers::WS_ERRORING || $state === StreamHelpers::WS_CLOSED) {
            return;
        }
        $stream->setInternalProperty('[[State]]', StreamHelpers::WS_ERRORING);
        $stream->setInternalProperty('[[StoredError]]', $error);
        // Reject pending writes.
        $writes = $stream->getInternalProperty('[[WriteRequests]]') ?? [];
        $stream->setInternalProperty('[[WriteRequests]]', []);
        foreach ($writes as $w) {
            ($w['reject'])($error);
        }
        StreamHelpers::resetQueue($controller);
        self::finishErrorWith($stream, $error);
    }
}
