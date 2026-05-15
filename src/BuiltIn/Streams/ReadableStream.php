<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Streams;

use Phasis\BuiltIn\SymbolConstructor;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Value\JsArray;
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
 * WHATWG Streams: ReadableStream, ReadableStreamDefaultController,
 * ReadableStreamDefaultReader.
 *
 * Spec: https://streams.spec.whatwg.org/#rs-class
 *
 * Internal slots stored on the JsObject instances via setInternalProperty:
 *
 * ReadableStream (instance):
 *   [[IsReadableStream]]       — bool sentinel (brand check)
 *   [[State]]                  — 'readable' | 'closed' | 'errored'
 *   [[StoredError]]            — JsValue (when errored)
 *   [[Reader]]                 — JsObject|null (the locked reader, if any)
 *   [[Controller]]             — JsObject (controller, default or byte)
 *   [[Disturbed]]              — bool (whether body has been read from)
 *   [[ControllerType]]         — 'default' | 'byte'
 *
 * ReadableStreamDefaultController:
 *   [[IsReadableStreamDefaultController]] — bool
 *   [[Stream]]                 — JsObject (the stream)
 *   [[queue]]                  — list<array{value, size}> (via StreamHelpers)
 *   [[queueTotalSize]]         — float
 *   [[Started]]                — bool
 *   [[Pulling]]                — bool
 *   [[PullAgain]]              — bool
 *   [[CloseRequested]]         — bool
 *   [[StrategyHWM]]            — float
 *   [[StrategySizeAlgorithm]]  — JsFunction|null
 *   [[PullAlgorithm]]          — \Closure(): JsPromise
 *   [[CancelAlgorithm]]        — \Closure(JsValue): JsPromise
 *
 * ReadableStreamDefaultReader:
 *   [[IsReadableStreamDefaultReader]] — bool
 *   [[Stream]]                 — JsObject|null
 *   [[ClosedPromise]]          — JsPromise
 *   [[ReadRequests]]           — list<array{resolve:\Closure,reject:\Closure}>
 */
final class ReadableStream
{
    /** ReadableStream.prototype */
    private static ?JsObject $prototype = null;
    /** Default controller prototype */
    private static ?JsObject $controllerPrototype = null;
    /** Default reader prototype */
    private static ?JsObject $readerPrototype = null;

    public static function getPrototype(): ?JsObject
    {
        return self::$prototype;
    }

    public static function getControllerPrototype(): ?JsObject
    {
        return self::$controllerPrototype;
    }

    public static function getReaderPrototype(): ?JsObject
    {
        return self::$readerPrototype;
    }

    public static function install(Environment $env): JsFunction
    {
        self::$prototype = new JsObject();
        self::$controllerPrototype = new JsObject();
        self::$readerPrototype = new JsObject();

        self::buildControllerPrototype();
        self::buildReaderPrototype();
        self::buildStreamPrototype();

        $constructor = JsFunction::fromCallable(
            'ReadableStream',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError("Constructor ReadableStream requires 'new'");
                }
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsObject) {
                    $ntProto = $newTarget->get('prototype');
                    $useProto = $ntProto instanceof JsObject ? $ntProto : self::$prototype;
                    $this_->setPrototype($useProto);
                }

                $underlyingSource = $args[0] ?? JsUndefined::instance();
                $strategy = $args[1] ?? JsUndefined::instance();

                self::initializeStream($this_);
                $type = JsUndefined::instance();
                if ($underlyingSource instanceof JsObject) {
                    $type = $underlyingSource->get('type');
                }
                if ($type instanceof JsString && $type->value === 'bytes') {
                    \Phasis\BuiltIn\Streams\ByteStream::setUpReadableByteStreamControllerFromUnderlyingSource(
                        $this_,
                        $underlyingSource,
                        StreamHelpers::extractHighWaterMark(
                            $strategy instanceof JsUndefined ? null : $strategy,
                            0.0
                        ),
                    );
                } else {
                    if (!$type instanceof JsUndefined) {
                        throw new \Phasis\Exceptions\RangeError('Invalid type for ReadableStream');
                    }
                    $sizeAlgorithm = StreamHelpers::extractSizeAlgorithm(
                        $strategy instanceof JsUndefined ? null : $strategy
                    );
                    $highWaterMark = StreamHelpers::extractHighWaterMark(
                        $strategy instanceof JsUndefined ? null : $strategy,
                        1.0
                    );
                    self::setUpDefaultControllerFromUnderlyingSource(
                        $this_,
                        $underlyingSource,
                        $highWaterMark,
                        $sizeAlgorithm
                    );
                }
                return $this_;
            },
            0,
        );
        $constructor->setConstructable();
        $constructor->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data(self::$prototype, false, false, false)
        );
        self::$prototype->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($constructor, true, false, true)
        );

        // ReadableStream.from(asyncIterable)
        $fromFn = JsFunction::fromCallable(
            'from',
            function (JsValue $this_, array $args): JsValue {
                $iterable = $args[0] ?? JsUndefined::instance();
                return self::readableStreamFromIterable($iterable);
            },
            1,
        );
        $constructor->defineOwnProperty(
            'from',
            PropertyDescriptor::data($fromFn, true, false, true)
        );

        $env->defineVar('ReadableStream', $constructor);

        // Install the controller constructor (not directly callable from JS but the
        // class is exposed for instanceof checks).
        self::installControllerConstructor($env);
        self::installReaderConstructor($env);
        return $constructor;
    }

    private static function installControllerConstructor(Environment $env): void
    {
        $ctor = JsFunction::fromCallable(
            'ReadableStreamDefaultController',
            function (JsValue $this_, array $args): JsValue {
                throw new TypeError('Illegal constructor');
            },
            0,
        );
        $ctor->setConstructable();
        $ctor->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data(self::$controllerPrototype, false, false, false)
        );
        self::$controllerPrototype->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($ctor, true, false, true)
        );
        $env->defineVar('ReadableStreamDefaultController', $ctor);
    }

    private static function installReaderConstructor(Environment $env): void
    {
        $ctor = JsFunction::fromCallable(
            'ReadableStreamDefaultReader',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError("Constructor ReadableStreamDefaultReader requires 'new'");
                }
                $stream = $args[0] ?? JsUndefined::instance();
                if (!self::isReadableStream($stream)) {
                    throw new TypeError('Argument must be a ReadableStream');
                }
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsObject) {
                    $ntProto = $newTarget->get('prototype');
                    $useProto = $ntProto instanceof JsObject ? $ntProto : self::$readerPrototype;
                    $this_->setPrototype($useProto);
                }
                self::setUpDefaultReader($this_, $stream);
                return $this_;
            },
            1,
        );
        $ctor->setConstructable();
        $ctor->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data(self::$readerPrototype, false, false, false)
        );
        self::$readerPrototype->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($ctor, true, false, true)
        );
        $env->defineVar('ReadableStreamDefaultReader', $ctor);
    }

    /**
     * Initialize stream internal slots to defaults.
     */
    public static function initializeStream(JsObject $stream): void
    {
        $stream->setInternalProperty('[[IsReadableStream]]', true);
        $stream->setInternalProperty('[[State]]', StreamHelpers::STATE_READABLE);
        $stream->setInternalProperty('[[StoredError]]', JsUndefined::instance());
        $stream->setInternalProperty('[[Reader]]', null);
        $stream->setInternalProperty('[[Controller]]', null);
        $stream->setInternalProperty('[[Disturbed]]', false);
        $stream->setInternalProperty('[[ControllerType]]', 'default');
    }

    /**
     * Brand check: is $v a ReadableStream?
     */
    public static function isReadableStream(JsValue $v): bool
    {
        return $v instanceof JsObject && $v->getInternalProperty('[[IsReadableStream]]') === true;
    }

    public static function isReadableStreamDefaultController(JsValue $v): bool
    {
        return $v instanceof JsObject && $v->getInternalProperty('[[IsReadableStreamDefaultController]]') === true;
    }

    public static function isReadableStreamDefaultReader(JsValue $v): bool
    {
        return $v instanceof JsObject && $v->getInternalProperty('[[IsReadableStreamDefaultReader]]') === true;
    }

    public static function isReadableStreamLocked(JsObject $stream): bool
    {
        return $stream->getInternalProperty('[[Reader]]') !== null;
    }

    private static function buildStreamPrototype(): void
    {
        $proto = self::$prototype;

        // locked getter
        $lockedGetter = JsFunction::fromCallable(
            'get locked',
            function (JsValue $this_): JsValue {
                if (!self::isReadableStream($this_)) {
                    throw new TypeError('ReadableStream.prototype.locked called on incompatible receiver');
                }
                /** @var JsObject $this_ */
                return JsBoolean::of(self::isReadableStreamLocked($this_));
            },
            0,
        );
        $proto->defineOwnProperty(
            'locked',
            PropertyDescriptor::accessor($lockedGetter, null, false, true)
        );

        // cancel(reason)
        $cancelFn = JsFunction::fromCallable(
            'cancel',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isReadableStream($this_)) {
                    return StreamHelpers::promiseRejected(
                        StreamHelpers::createTypeError('cancel called on non-ReadableStream')
                    );
                }
                /** @var JsObject $this_ */
                if (self::isReadableStreamLocked($this_)) {
                    return StreamHelpers::promiseRejected(
                        StreamHelpers::createTypeError('Cannot cancel a locked stream')
                    );
                }
                return self::readableStreamCancel($this_, $args[0] ?? JsUndefined::instance());
            },
            1,
        );
        $proto->defineOwnProperty('cancel', PropertyDescriptor::data($cancelFn, true, false, true));

        // getReader({mode}?)
        $getReaderFn = JsFunction::fromCallable(
            'getReader',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isReadableStream($this_)) {
                    throw new TypeError('getReader called on non-ReadableStream');
                }
                /** @var JsObject $this_ */
                $options = $args[0] ?? JsUndefined::instance();
                $mode = JsUndefined::instance();
                if ($options instanceof JsObject) {
                    $mode = $options->get('mode');
                }
                // Per WebIDL, the enum conversion runs ToString first
                // (observable: WPT verifies `{ toString() { ... } }` is
                // invoked). Then we validate against the allowed set.
                if (!$mode instanceof JsUndefined) {
                    $modeStr = \Phasis\Spec\TypeConversion::toString($mode);
                    if ($modeStr === 'byob') {
                        return \Phasis\BuiltIn\Streams\ByteStream::acquireBYOBReader($this_);
                    }
                    throw new TypeError("Invalid reader mode: '{$modeStr}'");
                }
                return self::acquireDefaultReader($this_);
            },
            0,
        );
        $proto->defineOwnProperty('getReader', PropertyDescriptor::data($getReaderFn, true, false, true));

        // tee()
        $teeFn = JsFunction::fromCallable(
            'tee',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isReadableStream($this_)) {
                    throw new TypeError('tee called on non-ReadableStream');
                }
                /** @var JsObject $this_ */
                return \Phasis\BuiltIn\Streams\PipeOperations::tee($this_);
            },
            0,
        );
        $proto->defineOwnProperty('tee', PropertyDescriptor::data($teeFn, true, false, true));

        // pipeTo
        $pipeToFn = JsFunction::fromCallable(
            'pipeTo',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isReadableStream($this_)) {
                    return StreamHelpers::promiseRejected(
                        StreamHelpers::createTypeError('pipeTo called on non-ReadableStream')
                    );
                }
                /** @var JsObject $this_ */
                $dest = $args[0] ?? JsUndefined::instance();
                $options = $args[1] ?? JsUndefined::instance();
                return \Phasis\BuiltIn\Streams\PipeOperations::pipeTo($this_, $dest, $options);
            },
            1,
        );
        $proto->defineOwnProperty('pipeTo', PropertyDescriptor::data($pipeToFn, true, false, true));

        // pipeThrough
        $pipeThroughFn = JsFunction::fromCallable(
            'pipeThrough',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isReadableStream($this_)) {
                    throw new TypeError('pipeThrough called on non-ReadableStream');
                }
                /** @var JsObject $this_ */
                $transform = $args[0] ?? JsUndefined::instance();
                $options = $args[1] ?? JsUndefined::instance();
                return \Phasis\BuiltIn\Streams\PipeOperations::pipeThrough($this_, $transform, $options);
            },
            1,
        );
        $proto->defineOwnProperty('pipeThrough', PropertyDescriptor::data($pipeThroughFn, true, false, true));

        // values({preventCancel})
        $valuesFn = JsFunction::fromCallable(
            'values',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isReadableStream($this_)) {
                    throw new TypeError('values called on non-ReadableStream');
                }
                /** @var JsObject $this_ */
                $options = $args[0] ?? JsUndefined::instance();
                $preventCancel = false;
                if ($options instanceof JsObject) {
                    $preventCancel = \Phasis\Spec\TypeConversion::toBoolean($options->get('preventCancel'));
                }
                return self::createAsyncIterator($this_, $preventCancel);
            },
            0,
        );
        $proto->defineOwnProperty('values', PropertyDescriptor::data($valuesFn, true, false, true));

        // [Symbol.asyncIterator] = values
        $proto->definePropertyBySymbol(
            SymbolConstructor::asyncIterator(),
            PropertyDescriptor::data($valuesFn, true, false, true)
        );

        // Symbol.toStringTag
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('ReadableStream'), false, false, true)
        );
    }

    private static function buildControllerPrototype(): void
    {
        $proto = self::$controllerPrototype;

        // desiredSize getter
        $desiredSizeGetter = JsFunction::fromCallable(
            'get desiredSize',
            function (JsValue $this_): JsValue {
                if (!self::isReadableStreamDefaultController($this_)) {
                    throw new TypeError('desiredSize called on non-controller');
                }
                /** @var JsObject $this_ */
                $size = self::getDesiredSize($this_);
                if ($size === null) {
                    return JsNull::instance();
                }
                return JsNumber::of($size);
            },
            0,
        );
        $proto->defineOwnProperty(
            'desiredSize',
            PropertyDescriptor::accessor($desiredSizeGetter, null, false, true)
        );

        // close()
        $closeFn = JsFunction::fromCallable(
            'close',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isReadableStreamDefaultController($this_)) {
                    throw new TypeError('close called on non-controller');
                }
                /** @var JsObject $this_ */
                if (!self::canCloseOrEnqueue($this_)) {
                    throw new TypeError('Cannot close controller (already closed/errored)');
                }
                self::defaultControllerClose($this_);
                return JsUndefined::instance();
            },
            0,
        );
        $proto->defineOwnProperty('close', PropertyDescriptor::data($closeFn, true, false, true));

        // enqueue(chunk)
        $enqueueFn = JsFunction::fromCallable(
            'enqueue',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isReadableStreamDefaultController($this_)) {
                    throw new TypeError('enqueue called on non-controller');
                }
                /** @var JsObject $this_ */
                if (!self::canCloseOrEnqueue($this_)) {
                    throw new TypeError('Cannot enqueue (already closed/errored)');
                }
                self::defaultControllerEnqueue($this_, $args[0] ?? JsUndefined::instance());
                return JsUndefined::instance();
            },
            1,
        );
        $proto->defineOwnProperty('enqueue', PropertyDescriptor::data($enqueueFn, true, false, true));

        // error(e)
        $errorFn = JsFunction::fromCallable(
            'error',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isReadableStreamDefaultController($this_)) {
                    throw new TypeError('error called on non-controller');
                }
                /** @var JsObject $this_ */
                self::defaultControllerError($this_, $args[0] ?? JsUndefined::instance());
                return JsUndefined::instance();
            },
            1,
        );
        $proto->defineOwnProperty('error', PropertyDescriptor::data($errorFn, true, false, true));

        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('ReadableStreamDefaultController'), false, false, true)
        );
    }

    private static function buildReaderPrototype(): void
    {
        $proto = self::$readerPrototype;

        // read()
        $readFn = JsFunction::fromCallable(
            'read',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isReadableStreamDefaultReader($this_)) {
                    return StreamHelpers::promiseRejected(
                        StreamHelpers::createTypeError('read called on non-reader')
                    );
                }
                /** @var JsObject $this_ */
                if ($this_->getInternalProperty('[[Stream]]') === null) {
                    return StreamHelpers::promiseRejected(
                        StreamHelpers::createTypeError('Reader is not attached to a stream')
                    );
                }
                return self::defaultReaderRead($this_);
            },
            0,
        );
        $proto->defineOwnProperty('read', PropertyDescriptor::data($readFn, true, false, true));

        // releaseLock()
        $releaseLockFn = JsFunction::fromCallable(
            'releaseLock',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isReadableStreamDefaultReader($this_)) {
                    throw new TypeError('releaseLock called on non-reader');
                }
                /** @var JsObject $this_ */
                if ($this_->getInternalProperty('[[Stream]]') === null) {
                    return JsUndefined::instance();
                }
                self::defaultReaderRelease($this_);
                return JsUndefined::instance();
            },
            0,
        );
        $proto->defineOwnProperty('releaseLock', PropertyDescriptor::data($releaseLockFn, true, false, true));

        // cancel(reason)
        $cancelFn = JsFunction::fromCallable(
            'cancel',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isReadableStreamDefaultReader($this_)) {
                    return StreamHelpers::promiseRejected(
                        StreamHelpers::createTypeError('cancel called on non-reader')
                    );
                }
                /** @var JsObject $this_ */
                $stream = $this_->getInternalProperty('[[Stream]]');
                if (!$stream instanceof JsObject) {
                    return StreamHelpers::promiseRejected(
                        StreamHelpers::createTypeError('Reader is not attached to a stream')
                    );
                }
                return self::readableStreamCancel($stream, $args[0] ?? JsUndefined::instance());
            },
            1,
        );
        $proto->defineOwnProperty('cancel', PropertyDescriptor::data($cancelFn, true, false, true));

        // closed getter
        $closedGetter = JsFunction::fromCallable(
            'get closed',
            function (JsValue $this_): JsValue {
                if (!self::isReadableStreamDefaultReader($this_)) {
                    return StreamHelpers::promiseRejected(
                        StreamHelpers::createTypeError('closed called on non-reader')
                    );
                }
                /** @var JsObject $this_ */
                $closed = $this_->getInternalProperty('[[ClosedPromise]]');
                return $closed instanceof JsPromise ? $closed : StreamHelpers::promiseResolved(JsUndefined::instance());
            },
            0,
        );
        $proto->defineOwnProperty(
            'closed',
            PropertyDescriptor::accessor($closedGetter, null, false, true)
        );

        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('ReadableStreamDefaultReader'), false, false, true)
        );
    }

    // ------------------------------------------------------------------
    // Spec abstract operations
    // ------------------------------------------------------------------

    /**
     * SetUpReadableStreamDefaultControllerFromUnderlyingSource.
     */
    public static function setUpDefaultControllerFromUnderlyingSource(
        JsObject $stream,
        JsValue $underlyingSource,
        float $highWaterMark,
        ?JsFunction $sizeAlgorithm
    ): void {
        $controller = new JsObject(self::$controllerPrototype);
        $controller->setInternalProperty('[[IsReadableStreamDefaultController]]', true);
        $controller->setInternalProperty('[[Stream]]', $stream);
        StreamHelpers::resetQueue($controller);
        $controller->setInternalProperty('[[Started]]', false);
        $controller->setInternalProperty('[[Pulling]]', false);
        $controller->setInternalProperty('[[PullAgain]]', false);
        $controller->setInternalProperty('[[CloseRequested]]', false);
        $controller->setInternalProperty('[[StrategyHWM]]', $highWaterMark);
        $controller->setInternalProperty('[[StrategySizeAlgorithm]]', $sizeAlgorithm);

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

        $pullAlgorithm = function () use (&$pullFn, $controller, $underlyingSource): JsPromise {
            if ($pullFn === null) {
                return StreamHelpers::promiseResolved(JsUndefined::instance());
            }
            return StreamHelpers::promiseCall($pullFn, $underlyingSource, [$controller]);
        };
        $cancelAlgorithm = function (JsValue $reason) use (&$cancelFn, $underlyingSource): JsPromise {
            if ($cancelFn === null) {
                return StreamHelpers::promiseResolved(JsUndefined::instance());
            }
            return StreamHelpers::promiseCall($cancelFn, $underlyingSource, [$reason]);
        };
        $controller->setInternalProperty('[[PullAlgorithm]]', $pullAlgorithm);
        $controller->setInternalProperty('[[CancelAlgorithm]]', $cancelAlgorithm);

        $stream->setInternalProperty('[[Controller]]', $controller);
        $stream->setInternalProperty('[[ControllerType]]', 'default');

        // Start algorithm
        $startResult = JsUndefined::instance();
        if ($startFn !== null) {
            try {
                $startResult = $startFn->call($underlyingSource, [$controller]);
            } catch (\Throwable $e) {
                self::defaultControllerError($controller, StreamHelpers::exceptionToJsValue($e));
                return;
            }
        }
        $startPromise = StreamHelpers::toPromise($startResult);
        // Per WHATWG Streams §SetUpReadableStreamDefaultController step 14
        // ("Upon fulfillment of startPromise..."): the reaction runs as a
        // microtask even when the start algorithm returned synchronously.
        // Our addFulfillHandler invokes immediately on already-fulfilled
        // promises, so wrap the [[Started]]/pullIfNeeded transition in
        // scheduleCallback to preserve spec ordering. Without this defer,
        // a stream constructed without a start algorithm fires pull
        // synchronously during construction, which (a) lets pull observe
        // the source closure in TDZ for `const stream = new ReadableStream`
        // patterns, and (b) loses the race against a synchronous cancel
        // that should preempt the first pull (async-iterator return() →
        // ReadableStreamCancel sets state to closed).
        $startPromise->addFulfillHandler(static function (JsValue $_) use ($controller): void {
            JsPromise::scheduleCallback(static function () use ($controller): void {
                $controller->setInternalProperty('[[Started]]', true);
                self::defaultControllerCallPullIfNeeded($controller);
            });
        });
        $startPromise->addRejectHandler(static function (JsValue $reason) use ($controller): void {
            JsPromise::scheduleCallback(static function () use ($controller, $reason): void {
                self::defaultControllerError($controller, $reason);
            });
        });
    }

    /**
     * ReadableStreamDefaultControllerCallPullIfNeeded.
     */
    public static function defaultControllerCallPullIfNeeded(JsObject $controller): void
    {
        if (!self::defaultControllerShouldCallPull($controller)) {
            return;
        }
        if ($controller->getInternalProperty('[[Pulling]]') === true) {
            $controller->setInternalProperty('[[PullAgain]]', true);
            return;
        }
        $controller->setInternalProperty('[[Pulling]]', true);
        $pullAlgorithm = $controller->getInternalProperty('[[PullAlgorithm]]');
        if (!$pullAlgorithm instanceof \Closure) {
            return;
        }
        $promise = $pullAlgorithm();
        $promise->addFulfillHandler(static function (JsValue $_) use ($controller): void {
            $controller->setInternalProperty('[[Pulling]]', false);
            if ($controller->getInternalProperty('[[PullAgain]]') === true) {
                $controller->setInternalProperty('[[PullAgain]]', false);
                self::defaultControllerCallPullIfNeeded($controller);
            }
        });
        $promise->addRejectHandler(static function (JsValue $reason) use ($controller): void {
            self::defaultControllerError($controller, $reason);
        });
    }

    /**
     * ReadableStreamDefaultControllerShouldCallPull.
     */
    public static function defaultControllerShouldCallPull(JsObject $controller): bool
    {
        $stream = $controller->getInternalProperty('[[Stream]]');
        if (!$stream instanceof JsObject) {
            return false;
        }
        if (!self::canCloseOrEnqueue($controller)) {
            return false;
        }
        if ($controller->getInternalProperty('[[Started]]') !== true) {
            return false;
        }
        // If locked and pending read requests exist, must pull.
        $reader = $stream->getInternalProperty('[[Reader]]');
        if ($reader instanceof JsObject) {
            $requests = $reader->getInternalProperty('[[ReadRequests]]') ?? [];
            if ($requests !== []) {
                return true;
            }
        }
        $desired = self::getDesiredSize($controller);
        return $desired !== null && $desired > 0;
    }

    public static function getDesiredSize(JsObject $controller): ?float
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

    /**
     * ReadableStreamDefaultControllerCanCloseOrEnqueue.
     */
    public static function canCloseOrEnqueue(JsObject $controller): bool
    {
        if ($controller->getInternalProperty('[[CloseRequested]]') === true) {
            return false;
        }
        $stream = $controller->getInternalProperty('[[Stream]]');
        if (!$stream instanceof JsObject) {
            return false;
        }
        return $stream->getInternalProperty('[[State]]') === StreamHelpers::STATE_READABLE;
    }

    /**
     * ReadableStreamDefaultControllerEnqueue.
     */
    public static function defaultControllerEnqueue(JsObject $controller, JsValue $chunk): void
    {
        if (!self::canCloseOrEnqueue($controller)) {
            return;
        }
        $stream = $controller->getInternalProperty('[[Stream]]');
        if (!$stream instanceof JsObject) {
            return;
        }
        $reader = $stream->getInternalProperty('[[Reader]]');
        if (
            $reader instanceof JsObject
            && $reader->getInternalProperty('[[IsReadableStreamDefaultReader]]') === true
            && self::hasPendingReadRequests($reader)
        ) {
            self::fulfillReadRequest($reader, $chunk, false);
        } else {
            $size = 1.0;
            $sizeAlgo = $controller->getInternalProperty('[[StrategySizeAlgorithm]]');
            if ($sizeAlgo instanceof JsFunction) {
                try {
                    $sized = $sizeAlgo->call(JsUndefined::instance(), [$chunk]);
                    $size = (float) \Phasis\Spec\TypeConversion::toNumber($sized);
                } catch (\Throwable $e) {
                    // Per spec, abrupt size completions must:
                    //  1) error the controller with the SAME JsValue
                    //  2) propagate the same JS error as the result of
                    //     enqueue (re-throw the same JsThrowable).
                    $jsErr = StreamHelpers::exceptionToJsValue($e);
                    self::defaultControllerError($controller, $jsErr);
                    throw new \Phasis\Exceptions\JsThrowable($jsErr);
                }
            }
            try {
                StreamHelpers::enqueueValueWithSize($controller, $chunk, $size);
            } catch (\Throwable $e) {
                // Same dual semantics for invalid sizes (NaN, Infinity, < 0)
                $jsErr = StreamHelpers::exceptionToJsValue($e);
                self::defaultControllerError($controller, $jsErr);
                throw new \Phasis\Exceptions\JsThrowable($jsErr);
            }
        }
        self::defaultControllerCallPullIfNeeded($controller);
    }

    /**
     * ReadableStreamDefaultControllerClose.
     */
    public static function defaultControllerClose(JsObject $controller): void
    {
        if (!self::canCloseOrEnqueue($controller)) {
            return;
        }
        $controller->setInternalProperty('[[CloseRequested]]', true);
        if (StreamHelpers::queueLength($controller) === 0) {
            self::defaultControllerClearAlgorithms($controller);
            self::readableStreamClose($controller->getInternalProperty('[[Stream]]'));
        }
    }

    /**
     * ReadableStreamDefaultControllerError.
     */
    public static function defaultControllerError(JsObject $controller, JsValue $error): void
    {
        $stream = $controller->getInternalProperty('[[Stream]]');
        if (!$stream instanceof JsObject) {
            return;
        }
        if ($stream->getInternalProperty('[[State]]') !== StreamHelpers::STATE_READABLE) {
            return;
        }
        StreamHelpers::resetQueue($controller);
        self::defaultControllerClearAlgorithms($controller);
        self::readableStreamError($stream, $error);
    }

    public static function defaultControllerClearAlgorithms(JsObject $controller): void
    {
        $controller->setInternalProperty('[[PullAlgorithm]]', null);
        $controller->setInternalProperty('[[CancelAlgorithm]]', null);
        $controller->setInternalProperty('[[StrategySizeAlgorithm]]', null);
    }

    /**
     * ReadableStreamClose: set state to closed and resolve pending reads.
     */
    public static function readableStreamClose(JsObject $stream): void
    {
        if ($stream->getInternalProperty('[[State]]') !== StreamHelpers::STATE_READABLE) {
            return;
        }
        $stream->setInternalProperty('[[State]]', StreamHelpers::STATE_CLOSED);
        $reader = $stream->getInternalProperty('[[Reader]]');
        if (!$reader instanceof JsObject) {
            return;
        }
        $closedPromise = $reader->getInternalProperty('[[ClosedPromise]]');
        if ($closedPromise instanceof JsPromise) {
            $closedPromise->resolve(JsUndefined::instance());
        }
        if ($reader->getInternalProperty('[[IsReadableStreamDefaultReader]]') === true) {
            $requests = $reader->getInternalProperty('[[ReadRequests]]') ?? [];
            $reader->setInternalProperty('[[ReadRequests]]', []);
            foreach ($requests as $req) {
                ($req['resolve'])(StreamHelpers::readResult(JsUndefined::instance(), true));
            }
        } elseif ($reader->getInternalProperty('[[IsReadableStreamBYOBReader]]') === true) {
            \Phasis\BuiltIn\Streams\ByteStream::byobReaderCloseRequests($reader);
        }
    }

    /**
     * ReadableStreamError: transition to errored state, reject pending reads.
     */
    public static function readableStreamError(JsObject $stream, JsValue $error): void
    {
        if ($stream->getInternalProperty('[[State]]') !== StreamHelpers::STATE_READABLE) {
            return;
        }
        $stream->setInternalProperty('[[State]]', StreamHelpers::STATE_ERRORED);
        $stream->setInternalProperty('[[StoredError]]', $error);
        $reader = $stream->getInternalProperty('[[Reader]]');
        if (!$reader instanceof JsObject) {
            return;
        }
        $closedPromise = $reader->getInternalProperty('[[ClosedPromise]]');
        if ($closedPromise instanceof JsPromise) {
            $closedPromise->reject($error);
            StreamHelpers::markPromiseHandled($closedPromise);
        }
        if ($reader->getInternalProperty('[[IsReadableStreamDefaultReader]]') === true) {
            $requests = $reader->getInternalProperty('[[ReadRequests]]') ?? [];
            $reader->setInternalProperty('[[ReadRequests]]', []);
            foreach ($requests as $req) {
                ($req['reject'])($error);
            }
        } elseif ($reader->getInternalProperty('[[IsReadableStreamBYOBReader]]') === true) {
            \Phasis\BuiltIn\Streams\ByteStream::byobReaderErrorRequests($reader, $error);
        }
    }

    /**
     * ReadableStreamCancel: drain queue, call cancelAlgorithm, return promise.
     */
    public static function readableStreamCancel(JsObject $stream, JsValue $reason): JsPromise
    {
        $stream->setInternalProperty('[[Disturbed]]', true);
        $state = $stream->getInternalProperty('[[State]]');
        if ($state === StreamHelpers::STATE_CLOSED) {
            return StreamHelpers::promiseResolved(JsUndefined::instance());
        }
        if ($state === StreamHelpers::STATE_ERRORED) {
            return StreamHelpers::promiseRejected($stream->getInternalProperty('[[StoredError]]'));
        }
        self::readableStreamClose($stream);
        // PerformReadableStreamCancel:
        $controller = $stream->getInternalProperty('[[Controller]]');
        if (!$controller instanceof JsObject) {
            return StreamHelpers::promiseResolved(JsUndefined::instance());
        }
        $controllerType = $stream->getInternalProperty('[[ControllerType]]');
        if ($controllerType === 'byte') {
            \Phasis\BuiltIn\Streams\ByteStream::byteControllerClearPendingPullIntos($controller);
            StreamHelpers::resetQueue($controller);
        } else {
            StreamHelpers::resetQueue($controller);
        }
        $cancelAlgo = $controller->getInternalProperty('[[CancelAlgorithm]]');
        if (!$cancelAlgo instanceof \Closure) {
            return StreamHelpers::promiseResolved(JsUndefined::instance());
        }
        $cancelPromise = $cancelAlgo($reason);
        // Clear algorithms after invocation.
        $controller->setInternalProperty('[[PullAlgorithm]]', null);
        $controller->setInternalProperty('[[CancelAlgorithm]]', null);
        $controller->setInternalProperty('[[StrategySizeAlgorithm]]', null);
        // Wrap to discard the cancel value.
        $result = new JsPromise();
        $cancelPromise->addFulfillHandler(static function (JsValue $_) use ($result): void {
            $result->resolve(JsUndefined::instance());
        });
        $cancelPromise->addRejectHandler(static function (JsValue $r) use ($result): void {
            $result->reject($r);
        });
        return $result;
    }

    /**
     * Build a default reader and lock it to the stream.
     */
    public static function acquireDefaultReader(JsObject $stream): JsObject
    {
        if (self::isReadableStreamLocked($stream)) {
            throw new TypeError('ReadableStream is locked');
        }
        $reader = new JsObject(self::$readerPrototype);
        self::setUpDefaultReader($reader, $stream);
        return $reader;
    }

    public static function setUpDefaultReader(JsObject $reader, JsObject $stream): void
    {
        if (self::isReadableStreamLocked($stream)) {
            throw new TypeError('ReadableStream is locked');
        }
        $reader->setInternalProperty('[[IsReadableStreamDefaultReader]]', true);
        $reader->setInternalProperty('[[Stream]]', $stream);
        $reader->setInternalProperty('[[ReadRequests]]', []);

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

    /**
     * ReadableStreamDefaultReaderRead.
     */
    public static function defaultReaderRead(JsObject $reader): JsPromise
    {
        $stream = $reader->getInternalProperty('[[Stream]]');
        if (!$stream instanceof JsObject) {
            return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('Reader has no stream'));
        }
        $stream->setInternalProperty('[[Disturbed]]', true);
        $state = $stream->getInternalProperty('[[State]]');
        if ($state === StreamHelpers::STATE_CLOSED) {
            return StreamHelpers::promiseResolved(StreamHelpers::readResult(JsUndefined::instance(), true));
        }
        if ($state === StreamHelpers::STATE_ERRORED) {
            return StreamHelpers::promiseRejected($stream->getInternalProperty('[[StoredError]]'));
        }

        $controller = $stream->getInternalProperty('[[Controller]]');
        if (!$controller instanceof JsObject) {
            return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('Stream has no controller'));
        }

        // If queue has chunks, dequeue and return immediately.
        if (StreamHelpers::queueLength($controller) > 0) {
            $value = StreamHelpers::dequeueValue($controller);
            // After dequeue the queue may now be empty; PHPStan can't track
            // queueLength's mutation through the helper so we recompute.
            $remaining = StreamHelpers::queueLength($controller);
            if ($controller->getInternalProperty('[[CloseRequested]]') === true && $remaining === 0) {
                self::defaultControllerClearAlgorithms($controller);
                self::readableStreamClose($stream);
            } else {
                self::defaultControllerCallPullIfNeeded($controller);
            }
            return StreamHelpers::promiseResolved(StreamHelpers::readResult($value ?? JsUndefined::instance(), false));
        }

        $promise = new JsPromise();
        $resolve = static function (JsValue $v) use ($promise): void {
            $promise->resolve($v);
        };
        $reject = static function (JsValue $r) use ($promise): void {
            $promise->reject($r);
        };
        $requests = $reader->getInternalProperty('[[ReadRequests]]') ?? [];
        $requests[] = ['resolve' => $resolve, 'reject' => $reject];
        $reader->setInternalProperty('[[ReadRequests]]', $requests);

        self::defaultControllerCallPullIfNeeded($controller);
        return $promise;
    }

    public static function hasPendingReadRequests(JsObject $reader): bool
    {
        $requests = $reader->getInternalProperty('[[ReadRequests]]') ?? [];
        return $requests !== [];
    }

    public static function fulfillReadRequest(JsObject $reader, JsValue $chunk, bool $done): void
    {
        $requests = $reader->getInternalProperty('[[ReadRequests]]') ?? [];
        if ($requests === []) {
            return;
        }
        $req = array_shift($requests);
        $reader->setInternalProperty('[[ReadRequests]]', $requests);
        ($req['resolve'])(StreamHelpers::readResult($chunk, $done));
    }

    /**
     * ReadableStreamReaderGenericRelease.
     */
    public static function defaultReaderRelease(JsObject $reader): void
    {
        $stream = $reader->getInternalProperty('[[Stream]]');
        if (!$stream instanceof JsObject) {
            return;
        }
        // Per spec §ReadableStreamReaderGenericRelease step 4: if the
        // stream's state is "readable" reject reader.[[closedPromise]]
        // with TypeError, ELSE set reader.[[closedPromise]] to a freshly-
        // rejected promise. Either way, mark handled. Then step 5
        // detaches the reader. The "replace with fresh rejected promise"
        // path is observable: WPT verifies `r.closed !== oldClosed` after
        // releaseLock on a closed/errored stream.
        $closedPromise = $reader->getInternalProperty('[[ClosedPromise]]');
        $err = StreamHelpers::createTypeError('Reader was released');
        $state = $stream->getInternalProperty('[[State]]');
        if ($state === StreamHelpers::STATE_READABLE && $closedPromise instanceof JsPromise) {
            $closedPromise->reject($err);
            StreamHelpers::markPromiseHandled($closedPromise);
        } else {
            // For closed/errored streams the original closedPromise is
            // already settled; replace with a new pre-rejected one so
            // identity changes (per spec §release-reader).
            $fresh = StreamHelpers::promiseRejected($err);
            StreamHelpers::markPromiseHandled($fresh);
            $reader->setInternalProperty('[[ClosedPromise]]', $fresh);
        }

        // Error pending read requests with TypeError.
        $requests = $reader->getInternalProperty('[[ReadRequests]]') ?? [];
        $reader->setInternalProperty('[[ReadRequests]]', []);
        foreach ($requests as $req) {
            ($req['reject'])($err);
        }

        $stream->setInternalProperty('[[Reader]]', null);
        $reader->setInternalProperty('[[Stream]]', null);
    }

    // ------------------------------------------------------------------
    // Async iterator (values())
    // ------------------------------------------------------------------

    /** Cache for the stream async-iterator prototype object. */
    private static ?JsObject $asyncIteratorPrototype = null;

    /** Reset the async-iterator prototype cache (called on Engine reset). */
    public static function resetAsyncIteratorPrototype(): void
    {
        self::$asyncIteratorPrototype = null;
    }

    /**
     * Build (or fetch the cached) prototype that backs the stream async
     * iterator. Per the spec it inherits from %AsyncIteratorPrototype% so
     * `Object.getPrototypeOf(stream.values())` chains to the shared
     * AsyncIterator root.
     */
    private static function getAsyncIteratorPrototype(): JsObject
    {
        if (self::$asyncIteratorPrototype !== null) {
            return self::$asyncIteratorPrototype;
        }
        // Locate %AsyncIteratorPrototype% via the binding installed by the
        // global env. Fall back to a bare prototype if unavailable (no
        // async generator support compiled in).
        $parent = null;
        $realm = \Phasis\Engine::getCurrentInterpreter()?->getRealm();
        if ($realm !== null) {
            $env = $realm->getGlobalEnv();
            if ($env->has('__AsyncIteratorPrototype__')) {
                $candidate = $env->get('__AsyncIteratorPrototype__');
                if ($candidate instanceof JsObject) {
                    $parent = $candidate;
                }
            }
        }
        $proto = $parent !== null ? new JsObject($parent) : new JsObject();

        $nextFn = self::buildAsyncIterNext();
        $proto->defineOwnProperty('next', PropertyDescriptor::data($nextFn, true, true, true));

        $returnFn = self::buildAsyncIterReturn();
        $proto->defineOwnProperty('return', PropertyDescriptor::data($returnFn, true, true, true));

        $asyncIterFn = JsFunction::fromCallable(
            '[Symbol.asyncIterator]',
            static function (JsValue $this_): JsValue {
                return $this_;
            },
            0,
        );
        $proto->definePropertyBySymbol(
            SymbolConstructor::asyncIterator(),
            PropertyDescriptor::data($asyncIterFn, true, false, true)
        );

        self::$asyncIteratorPrototype = $proto;
        return $proto;
    }

    /**
     * Spec "nextSteps" for the async iterator: read one chunk, handle
     * done/error, and resolve with an iter-result. Used by both the direct
     * next() call and the ongoing-promise chained version.
     */
    private static function asyncIterRunNextSteps(JsObject $iter): JsPromise
    {
        if ($iter->getInternalProperty('[[IsFinished]]') === true) {
            return StreamHelpers::promiseResolved(StreamHelpers::readResult(JsUndefined::instance(), true));
        }
        $reader = $iter->getInternalProperty('[[Reader]]');
        if (!$reader instanceof JsObject) {
            return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('No reader'));
        }
        $readPromise = self::defaultReaderRead($reader);
        $outer = new JsPromise();
        $readPromise->addFulfillHandler(static function (JsValue $result) use ($iter, $reader, $outer): void {
            if (!$result instanceof JsObject) {
                $outer->resolve($result);
                return;
            }
            $done = \Phasis\Spec\TypeConversion::toBoolean($result->get('done'));
            if ($done) {
                $iter->setInternalProperty('[[IsFinished]]', true);
                self::defaultReaderRelease($reader);
            }
            $outer->resolve($result);
        });
        $readPromise->addRejectHandler(static function (JsValue $reason) use ($iter, $reader, $outer): void {
            $iter->setInternalProperty('[[IsFinished]]', true);
            self::defaultReaderRelease($reader);
            $outer->reject($reason);
        });
        return $outer;
    }

    /**
     * Spec "returnSteps" for the async iterator: cancel the stream (unless
     * preventCancel) and resolve with the supplied value as a done
     * iter-result. Mirrors WHATWG Streams §default-asynciterator-return.
     */
    private static function asyncIterRunReturnSteps(JsObject $iter, JsValue $value): JsPromise
    {
        if ($iter->getInternalProperty('[[IsFinished]]') === true) {
            return StreamHelpers::promiseResolved(StreamHelpers::readResult($value, true));
        }
        $iter->setInternalProperty('[[IsFinished]]', true);
        $reader = $iter->getInternalProperty('[[Reader]]');
        $preventCancel = $iter->getInternalProperty('[[PreventCancel]]');
        if (!$reader instanceof JsObject) {
            return StreamHelpers::promiseResolved(StreamHelpers::readResult($value, true));
        }
        $stream = $reader->getInternalProperty('[[Stream]]');
        if (!$preventCancel && $stream instanceof JsObject) {
            $cancelPromise = self::readableStreamCancel($stream, $value);
            self::defaultReaderRelease($reader);
            $outer = new JsPromise();
            $cancelPromise->addFulfillHandler(static function () use ($value, $outer): void {
                $outer->resolve(StreamHelpers::readResult($value, true));
            });
            $cancelPromise->addRejectHandler(static function (JsValue $r) use ($outer): void {
                $outer->reject($r);
            });
            return $outer;
        }
        self::defaultReaderRelease($reader);
        return StreamHelpers::promiseResolved(StreamHelpers::readResult($value, true));
    }

    /**
     * Serialize an async iterator operation against [[OngoingPromise]]:
     * per WHATWG Streams §4.5.10, next()/return() chain off the previous
     * in-flight promise. This is what makes patterns like `it.next();
     * it.return()` (no awaiting) deterministic — return()'s cancel steps
     * do not race the in-flight read; they run only after it settles.
     */
    private static function asyncIterEnqueue(JsObject $iter, \Closure $steps): JsPromise
    {
        $ongoing = $iter->getInternalProperty('[[OngoingPromise]]');
        $newOngoing = new JsPromise();
        $runSteps = static function () use ($steps, $newOngoing): void {
            $stepsPromise = $steps();
            if (!$stepsPromise instanceof JsPromise) {
                $newOngoing->resolve(JsUndefined::instance());
                return;
            }
            $stepsPromise->addFulfillHandler(static function (JsValue $v) use ($newOngoing): void {
                $newOngoing->resolve($v);
            });
            $stepsPromise->addRejectHandler(static function (JsValue $r) use ($newOngoing): void {
                $newOngoing->reject($r);
            });
        };
        if ($ongoing instanceof JsPromise) {
            // Run steps on either fulfillment OR rejection of the ongoing
            // promise — both branches schedule the operation, only the
            // propagation differs.
            $ongoing->addFulfillHandler(static function (JsValue $_) use ($runSteps): void {
                $runSteps();
            });
            $ongoing->addRejectHandler(static function (JsValue $_) use ($runSteps): void {
                $runSteps();
            });
        } else {
            $runSteps();
        }
        $iter->setInternalProperty('[[OngoingPromise]]', $newOngoing);
        // Once this op settles, clear the slot if it's still us. The spec
        // wording is "Set iterator.[[ongoingPromise]] to undefined" inside
        // the chunk/close/error steps; serializing on the same JsPromise
        // gives equivalent observable behaviour.
        $newOngoing->addFulfillHandler(static function (JsValue $_) use ($iter, $newOngoing): void {
            if ($iter->getInternalProperty('[[OngoingPromise]]') === $newOngoing) {
                $iter->setInternalProperty('[[OngoingPromise]]', null);
            }
        });
        $newOngoing->addRejectHandler(static function (JsValue $_) use ($iter, $newOngoing): void {
            if ($iter->getInternalProperty('[[OngoingPromise]]') === $newOngoing) {
                $iter->setInternalProperty('[[OngoingPromise]]', null);
            }
        });
        return $newOngoing;
    }

    private static function buildAsyncIterNext(): JsFunction
    {
        return JsFunction::fromCallable(
            'next',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('next called on non-object'));
                }
                $iter = $this_;
                return self::asyncIterEnqueue($iter, static function () use ($iter): JsPromise {
                    return self::asyncIterRunNextSteps($iter);
                });
            },
            0,
        );
    }

    private static function buildAsyncIterReturn(): JsFunction
    {
        return JsFunction::fromCallable(
            'return',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('return called on non-object'));
                }
                $value = $args[0] ?? JsUndefined::instance();
                $iter = $this_;
                return self::asyncIterEnqueue($iter, static function () use ($iter, $value): JsPromise {
                    return self::asyncIterRunReturnSteps($iter, $value);
                });
            },
            1,
        );
    }

    /**
     * Build the async iterator INSTANCE returned by stream.values(). The
     * instance carries per-iteration state internal slots; the methods
     * (next/return) live on a shared prototype that chains to
     * %AsyncIteratorPrototype% per the spec.
     */
    public static function createAsyncIterator(JsObject $stream, bool $preventCancel): JsObject
    {
        $reader = self::acquireDefaultReader($stream);
        $iter = new JsObject(self::getAsyncIteratorPrototype());
        $iter->setInternalProperty('[[StreamAsyncIterator]]', true);
        $iter->setInternalProperty('[[Reader]]', $reader);
        $iter->setInternalProperty('[[PreventCancel]]', $preventCancel);
        $iter->setInternalProperty('[[IsFinished]]', false);
        $iter->setInternalProperty('[[OngoingPromise]]', null);
        return $iter;
    }


    // ------------------------------------------------------------------
    // ReadableStream.from(asyncIterable)
    // ------------------------------------------------------------------

    public static function readableStreamFromIterable(JsValue $iterable): JsObject
    {
        if (!$iterable instanceof JsObject && !$iterable instanceof JsString) {
            throw new TypeError('ReadableStream.from requires an iterable');
        }
        $iteratorRecord = self::getIteratorFlattenable($iterable);

        $stream = new JsObject(self::$prototype);
        self::initializeStream($stream);

        $pullAlgorithm = function () use ($stream, $iteratorRecord): JsPromise {
            $controller = $stream->getInternalProperty('[[Controller]]');
            if (!$controller instanceof JsObject) {
                return StreamHelpers::promiseResolved(JsUndefined::instance());
            }
            try {
                $nextResult = $iteratorRecord['next']->call($iteratorRecord['iterator'], []);
            } catch (\Throwable $e) {
                return StreamHelpers::promiseRejected(StreamHelpers::exceptionToJsValue($e));
            }
            $nextPromise = StreamHelpers::toPromise($nextResult);
            $outer = new JsPromise();
            $nextPromise->addFulfillHandler(function (JsValue $iterResult) use ($controller, $outer): void {
                if (!$iterResult instanceof JsObject) {
                    $outer->reject(StreamHelpers::createTypeError('Iterator result is not an object'));
                    return;
                }
                $done = \Phasis\Spec\TypeConversion::toBoolean($iterResult->get('done'));
                $value = $iterResult->get('value');
                if ($done) {
                    self::defaultControllerClose($controller);
                } else {
                    self::defaultControllerEnqueue($controller, $value);
                }
                $outer->resolve(JsUndefined::instance());
            });
            $nextPromise->addRejectHandler(static function (JsValue $r) use ($outer): void {
                $outer->reject($r);
            });
            return $outer;
        };
        $cancelAlgorithm = function (JsValue $reason) use ($iteratorRecord): JsPromise {
            $returnMethod = null;
            $iter = $iteratorRecord['iterator'];
            $r = $iter->get('return');
            if ($r instanceof JsFunction) {
                $returnMethod = $r;
            }
            if ($returnMethod === null) {
                return StreamHelpers::promiseResolved(JsUndefined::instance());
            }
            try {
                $result = $returnMethod->call($iter, [$reason]);
            } catch (\Throwable $e) {
                return StreamHelpers::promiseRejected(StreamHelpers::exceptionToJsValue($e));
            }
            return StreamHelpers::toPromise($result);
        };

        $controller = new JsObject(self::$controllerPrototype);
        $controller->setInternalProperty('[[IsReadableStreamDefaultController]]', true);
        $controller->setInternalProperty('[[Stream]]', $stream);
        StreamHelpers::resetQueue($controller);
        $controller->setInternalProperty('[[Started]]', true);
        $controller->setInternalProperty('[[Pulling]]', false);
        $controller->setInternalProperty('[[PullAgain]]', false);
        $controller->setInternalProperty('[[CloseRequested]]', false);
        $controller->setInternalProperty('[[StrategyHWM]]', 0.0);
        $controller->setInternalProperty('[[StrategySizeAlgorithm]]', null);
        $controller->setInternalProperty('[[PullAlgorithm]]', $pullAlgorithm);
        $controller->setInternalProperty('[[CancelAlgorithm]]', $cancelAlgorithm);
        $stream->setInternalProperty('[[Controller]]', $controller);
        $stream->setInternalProperty('[[ControllerType]]', 'default');

        return $stream;
    }

    /**
     * GetIteratorFlattenable equivalent: prefer @@asyncIterator, fall back to @@iterator.
     *
     * @return array{iterator:JsObject, next:JsFunction}
     */
    private static function getIteratorFlattenable(JsValue $iterable): array
    {
        $asyncIterSym = SymbolConstructor::asyncIterator();
        $syncIterSym = SymbolConstructor::iterator();

        $iteratorMethod = null;
        if ($iterable instanceof JsObject) {
            $m = $iterable->getBySymbol($asyncIterSym);
            if ($m instanceof JsFunction) {
                $iteratorMethod = $m;
            }
        }
        if ($iteratorMethod === null && $iterable instanceof JsObject) {
            $m = $iterable->getBySymbol($syncIterSym);
            if ($m instanceof JsFunction) {
                $iteratorMethod = $m;
            }
        }
        if ($iteratorMethod === null) {
            throw new TypeError('Value is not iterable');
        }
        $iterator = $iteratorMethod->call($iterable, []);
        if (!$iterator instanceof JsObject) {
            throw new TypeError('Iterator method did not return an object');
        }
        $nextMethod = $iterator->get('next');
        if (!$nextMethod instanceof JsFunction) {
            throw new TypeError('Iterator has no next() method');
        }
        return ['iterator' => $iterator, 'next' => $nextMethod];
    }
}
