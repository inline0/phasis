<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Streams;

use Phasis\BuiltIn\SymbolConstructor;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsPromise;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * WHATWG TransformStream + TransformStreamDefaultController.
 *
 * Spec: https://streams.spec.whatwg.org/#ts-class
 *
 * A TransformStream pairs a writable side and a readable side, where chunks
 * written through the writable side flow through a transformer's transform()
 * and out the readable side.
 *
 * Internal slots:
 *
 * TransformStream:
 *   [[IsTransformStream]]       bool
 *   [[Readable]]                JsObject (ReadableStream)
 *   [[Writable]]                JsObject (WritableStream)
 *   [[Controller]]              JsObject (TransformStreamDefaultController)
 *   [[Backpressure]]            bool
 *   [[BackpressureChangePromise]] JsPromise
 *
 * TransformStreamDefaultController:
 *   [[IsTransformStreamDefaultController]] bool
 *   [[Stream]]                  JsObject (TransformStream)
 *   [[TransformAlgorithm]]      \Closure(JsValue):JsPromise
 *   [[FlushAlgorithm]]          \Closure():JsPromise
 *   [[CancelAlgorithm]]         \Closure(JsValue):JsPromise
 *   [[Finished]]                bool
 */
final class TransformStream
{
    private static ?JsObject $prototype = null;
    private static ?JsObject $controllerPrototype = null;

    public static function getPrototype(): ?JsObject
    {
        return self::$prototype;
    }

    public static function install(Environment $env): JsFunction
    {
        self::$prototype = new JsObject();
        self::$controllerPrototype = new JsObject();

        self::buildControllerPrototype();
        self::buildStreamPrototype();

        $ctor = JsFunction::fromCallable(
            'TransformStream',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError("Constructor TransformStream requires 'new'");
                }
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsObject) {
                    $ntProto = $newTarget->get('prototype');
                    $useProto = $ntProto instanceof JsObject ? $ntProto : self::$prototype;
                    $this_->setPrototype($useProto);
                }
                $transformer = $args[0] ?? JsUndefined::instance();
                $writableStrategy = $args[1] ?? JsUndefined::instance();
                $readableStrategy = $args[2] ?? JsUndefined::instance();

                $writableHwm = StreamHelpers::extractHighWaterMark(
                    $writableStrategy instanceof JsUndefined ? null : $writableStrategy,
                    1.0
                );
                $writableSize = StreamHelpers::extractSizeAlgorithm(
                    $writableStrategy instanceof JsUndefined ? null : $writableStrategy
                );
                $readableHwm = StreamHelpers::extractHighWaterMark(
                    $readableStrategy instanceof JsUndefined ? null : $readableStrategy,
                    0.0
                );
                $readableSize = StreamHelpers::extractSizeAlgorithm(
                    $readableStrategy instanceof JsUndefined ? null : $readableStrategy
                );

                self::initialize(
                    $this_,
                    $transformer,
                    $writableHwm,
                    $writableSize,
                    $readableHwm,
                    $readableSize
                );
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
        $env->defineVar('TransformStream', $ctor);

        $ctorCtl = JsFunction::fromCallable(
            'TransformStreamDefaultController',
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
        $env->defineVar('TransformStreamDefaultController', $ctorCtl);
        return $ctor;
    }

    public static function isTransformStream(JsValue $v): bool
    {
        return $v instanceof JsObject && $v->getInternalProperty('[[IsTransformStream]]') === true;
    }

    public static function isController(JsValue $v): bool
    {
        return $v instanceof JsObject && $v->getInternalProperty('[[IsTransformStreamDefaultController]]') === true;
    }

    private static function buildStreamPrototype(): void
    {
        $proto = self::$prototype;
        $readableGetter = JsFunction::fromCallable(
            'get readable',
            function (JsValue $this_): JsValue {
                if (!self::isTransformStream($this_)) {
                    throw new TypeError('readable called on non-TransformStream');
                }
                /** @var JsObject $this_ */
                $r = $this_->getInternalProperty('[[Readable]]');
                return $r instanceof JsObject ? $r : JsUndefined::instance();
            },
            0,
        );
        $proto->defineOwnProperty(
            'readable',
            PropertyDescriptor::accessor($readableGetter, null, false, true)
        );

        $writableGetter = JsFunction::fromCallable(
            'get writable',
            function (JsValue $this_): JsValue {
                if (!self::isTransformStream($this_)) {
                    throw new TypeError('writable called on non-TransformStream');
                }
                /** @var JsObject $this_ */
                $w = $this_->getInternalProperty('[[Writable]]');
                return $w instanceof JsObject ? $w : JsUndefined::instance();
            },
            0,
        );
        $proto->defineOwnProperty(
            'writable',
            PropertyDescriptor::accessor($writableGetter, null, false, true)
        );

        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('TransformStream'), false, false, true)
        );
    }

    private static function buildControllerPrototype(): void
    {
        $proto = self::$controllerPrototype;

        $desiredSizeGetter = JsFunction::fromCallable(
            'get desiredSize',
            function (JsValue $this_): JsValue {
                if (!self::isController($this_)) {
                    throw new TypeError('desiredSize called on non-controller');
                }
                /** @var JsObject $this_ */
                $ts = $this_->getInternalProperty('[[Stream]]');
                if (!$ts instanceof JsObject) {
                    return JsNull::instance();
                }
                $readable = $ts->getInternalProperty('[[Readable]]');
                if (!$readable instanceof JsObject) {
                    return JsNull::instance();
                }
                $rsController = $readable->getInternalProperty('[[Controller]]');
                if (!$rsController instanceof JsObject) {
                    return JsNull::instance();
                }
                $d = \Phasis\BuiltIn\Streams\ReadableStream::getDesiredSize($rsController);
                return $d === null ? JsNull::instance() : JsNumber::of($d);
            },
            0,
        );
        $proto->defineOwnProperty(
            'desiredSize',
            PropertyDescriptor::accessor($desiredSizeGetter, null, false, true)
        );

        $enqueueFn = JsFunction::fromCallable(
            'enqueue',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isController($this_)) {
                    throw new TypeError('enqueue called on non-controller');
                }
                /** @var JsObject $this_ */
                self::controllerEnqueue($this_, $args[0] ?? JsUndefined::instance());
                return JsUndefined::instance();
            },
            1,
        );
        $proto->defineOwnProperty('enqueue', PropertyDescriptor::data($enqueueFn, true, false, true));

        $errorFn = JsFunction::fromCallable(
            'error',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isController($this_)) {
                    throw new TypeError('error called on non-controller');
                }
                /** @var JsObject $this_ */
                self::controllerError($this_, $args[0] ?? JsUndefined::instance());
                return JsUndefined::instance();
            },
            1,
        );
        $proto->defineOwnProperty('error', PropertyDescriptor::data($errorFn, true, false, true));

        $terminateFn = JsFunction::fromCallable(
            'terminate',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isController($this_)) {
                    throw new TypeError('terminate called on non-controller');
                }
                /** @var JsObject $this_ */
                self::controllerTerminate($this_);
                return JsUndefined::instance();
            },
            0,
        );
        $proto->defineOwnProperty('terminate', PropertyDescriptor::data($terminateFn, true, false, true));

        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('TransformStreamDefaultController'), false, false, true)
        );
    }

    /**
     * Initialize a TransformStream from a transformer object.
     */
    public static function initialize(
        JsObject $ts,
        JsValue $transformer,
        float $writableHwm,
        ?JsFunction $writableSize,
        float $readableHwm,
        ?JsFunction $readableSize
    ): void {
        $ts->setInternalProperty('[[IsTransformStream]]', true);
        $ts->setInternalProperty('[[Backpressure]]', false);
        $ts->setInternalProperty('[[BackpressureChangePromise]]', new JsPromise());

        // Extract transformer callbacks.
        $startFn = null;
        $transformFn = null;
        $flushFn = null;
        $cancelFn = null;
        if ($transformer instanceof JsObject) {
            $startFn = StreamHelpers::asFunctionOrNull($transformer->get('start'));
            $transformFn = StreamHelpers::asFunctionOrNull($transformer->get('transform'));
            $flushFn = StreamHelpers::asFunctionOrNull($transformer->get('flush'));
            $cancelFn = StreamHelpers::asFunctionOrNull($transformer->get('cancel'));
        }

        // Build controller.
        $controller = new JsObject(self::$controllerPrototype);
        $controller->setInternalProperty('[[IsTransformStreamDefaultController]]', true);
        $controller->setInternalProperty('[[Stream]]', $ts);
        $controller->setInternalProperty('[[Finished]]', false);

        $controller->setInternalProperty(
            '[[TransformAlgorithm]]',
            function (JsValue $chunk) use ($transformFn, $transformer, $controller): JsPromise {
                if ($transformFn === null) {
                    // Default: just enqueue the chunk.
                    self::controllerEnqueue($controller, $chunk);
                    return StreamHelpers::promiseResolved(JsUndefined::instance());
                }
                return StreamHelpers::promiseCall($transformFn, $transformer, [$chunk, $controller]);
            }
        );
        $controller->setInternalProperty(
            '[[FlushAlgorithm]]',
            function () use ($flushFn, $transformer, $controller): JsPromise {
                if ($flushFn === null) {
                    return StreamHelpers::promiseResolved(JsUndefined::instance());
                }
                return StreamHelpers::promiseCall($flushFn, $transformer, [$controller]);
            }
        );
        $controller->setInternalProperty(
            '[[CancelAlgorithm]]',
            function (JsValue $reason) use ($cancelFn, $transformer): JsPromise {
                if ($cancelFn === null) {
                    return StreamHelpers::promiseResolved(JsUndefined::instance());
                }
                return StreamHelpers::promiseCall($cancelFn, $transformer, [$reason]);
            }
        );

        $ts->setInternalProperty('[[Controller]]', $controller);

        // Build readable side.
        $readable = new JsObject(\Phasis\BuiltIn\Streams\ReadableStream::getPrototype());
        \Phasis\BuiltIn\Streams\ReadableStream::initializeStream($readable);
        $readableUnderlying = self::buildReadableUnderlyingSource($ts);
        \Phasis\BuiltIn\Streams\ReadableStream::setUpDefaultControllerFromUnderlyingSource(
            $readable,
            $readableUnderlying,
            $readableHwm,
            $readableSize
        );
        $ts->setInternalProperty('[[Readable]]', $readable);

        // Build writable side.
        $writable = new JsObject(\Phasis\BuiltIn\Streams\WritableStream::getPrototype());
        \Phasis\BuiltIn\Streams\WritableStream::initializeStream($writable);
        $writableUnderlying = self::buildWritableUnderlyingSink($ts);
        \Phasis\BuiltIn\Streams\WritableStream::setUpDefaultController(
            $writable,
            $writableUnderlying,
            $writableHwm,
            $writableSize
        );
        $ts->setInternalProperty('[[Writable]]', $writable);

        // Initialize backpressure to true so the first write waits for pull.
        self::setBackpressure($ts, true);

        // Call start(controller) on the transformer.
        $startResult = JsUndefined::instance();
        if ($startFn !== null) {
            try {
                $startResult = $startFn->call($transformer, [$controller]);
            } catch (\Throwable $e) {
                self::errorTransformStream($ts, StreamHelpers::exceptionToJsValue($e));
                return;
            }
        }
        $startPromise = StreamHelpers::toPromise($startResult);
        $startPromise->addRejectHandler(static function (JsValue $r) use ($ts): void {
            self::errorTransformStream($ts, $r);
        });
    }

    private static function buildReadableUnderlyingSource(JsObject $ts): JsObject
    {
        $src = new JsObject();
        $pullFn = JsFunction::fromCallable('pull', function (JsValue $this_, array $args) use ($ts): JsValue {
            // Per spec: a pull from the readable side clears backpressure
            // (resolving the existing bpc) and the algorithm returns a
            // resolved promise — NOT the new bpc, which is for the next round.
            self::setBackpressure($ts, false);
            return StreamHelpers::promiseResolved(JsUndefined::instance());
        }, 1);
        $src->defineOwnProperty('pull', PropertyDescriptor::data($pullFn, true, true, true));
        $cancelFn = JsFunction::fromCallable('cancel', function (JsValue $this_, array $args) use ($ts): JsValue {
            $reason = $args[0] ?? JsUndefined::instance();
            // Error the writable side and run cancel algorithm.
            $controller = $ts->getInternalProperty('[[Controller]]');
            $cancelAlgo = $controller instanceof JsObject ? $controller->getInternalProperty('[[CancelAlgorithm]]') : null;
            $cancelPromise = $cancelAlgo instanceof \Closure ? $cancelAlgo($reason) : StreamHelpers::promiseResolved(JsUndefined::instance());
            $writable = $ts->getInternalProperty('[[Writable]]');
            if ($writable instanceof JsObject) {
                \Phasis\BuiltIn\Streams\WritableStream::abortStream($writable, $reason);
            }
            return $cancelPromise;
        }, 1);
        $src->defineOwnProperty('cancel', PropertyDescriptor::data($cancelFn, true, true, true));
        return $src;
    }

    private static function buildWritableUnderlyingSink(JsObject $ts): JsObject
    {
        $sink = new JsObject();
        $writeFn = JsFunction::fromCallable('write', function (JsValue $this_, array $args) use ($ts): JsValue {
            $chunk = $args[0] ?? JsUndefined::instance();
            $controller = $ts->getInternalProperty('[[Controller]]');
            if (!$controller instanceof JsObject) {
                return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('No controller'));
            }
            // Helper that actually runs the transform algorithm on the chunk
            // and forwards the result/rejection to $outer.
            $runTransform = static function () use ($controller, $chunk, $ts): JsPromise {
                $transformAlgo = $controller->getInternalProperty('[[TransformAlgorithm]]');
                $result = new JsPromise();
                if (!$transformAlgo instanceof \Closure) {
                    $result->resolve(JsUndefined::instance());
                    return $result;
                }
                $p = $transformAlgo($chunk);
                $p->addFulfillHandler(static function (JsValue $_) use ($result): void {
                    $result->resolve(JsUndefined::instance());
                });
                $p->addRejectHandler(static function (JsValue $r) use ($result, $ts): void {
                    self::errorTransformStream($ts, $r);
                    $result->reject($r);
                });
                return $result;
            };
            // If backpressure is currently false, the transform can fire
            // immediately. Otherwise, wait on the change-promise.
            $backpressure = $ts->getInternalProperty('[[Backpressure]]');
            if ($backpressure !== true) {
                return $runTransform();
            }
            $bpc = $ts->getInternalProperty('[[BackpressureChangePromise]]');
            if (!$bpc instanceof JsPromise) {
                return $runTransform();
            }
            $outer = new JsPromise();
            $bpc->addFulfillHandler(static function (JsValue $_) use ($runTransform, $outer): void {
                $inner = $runTransform();
                $inner->addFulfillHandler(static function (JsValue $_) use ($outer): void {
                    $outer->resolve(JsUndefined::instance());
                });
                $inner->addRejectHandler(static function (JsValue $r) use ($outer): void {
                    $outer->reject($r);
                });
            });
            $bpc->addRejectHandler(static function (JsValue $r) use ($outer): void {
                $outer->reject($r);
            });
            return $outer;
        }, 2);
        $sink->defineOwnProperty('write', PropertyDescriptor::data($writeFn, true, true, true));

        $closeFn = JsFunction::fromCallable('close', function (JsValue $this_, array $args) use ($ts): JsValue {
            $controller = $ts->getInternalProperty('[[Controller]]');
            if (!$controller instanceof JsObject) {
                return StreamHelpers::promiseResolved(JsUndefined::instance());
            }
            $flushAlgo = $controller->getInternalProperty('[[FlushAlgorithm]]');
            $p = $flushAlgo instanceof \Closure ? $flushAlgo() : StreamHelpers::promiseResolved(JsUndefined::instance());
            $outer = new JsPromise();
            $p->addFulfillHandler(static function (JsValue $_) use ($ts, $outer): void {
                $readable = $ts->getInternalProperty('[[Readable]]');
                if ($readable instanceof JsObject) {
                    $rsCtrl = $readable->getInternalProperty('[[Controller]]');
                    if ($rsCtrl instanceof JsObject) {
                        \Phasis\BuiltIn\Streams\ReadableStream::defaultControllerClose($rsCtrl);
                    }
                }
                $outer->resolve(JsUndefined::instance());
            });
            $p->addRejectHandler(static function (JsValue $r) use ($ts, $outer): void {
                self::errorTransformStream($ts, $r);
                $outer->reject($r);
            });
            return $outer;
        }, 0);
        $sink->defineOwnProperty('close', PropertyDescriptor::data($closeFn, true, true, true));

        $abortFn = JsFunction::fromCallable('abort', function (JsValue $this_, array $args) use ($ts): JsValue {
            $reason = $args[0] ?? JsUndefined::instance();
            $controller = $ts->getInternalProperty('[[Controller]]');
            $cancelAlgo = $controller instanceof JsObject ? $controller->getInternalProperty('[[CancelAlgorithm]]') : null;
            $p = $cancelAlgo instanceof \Closure ? $cancelAlgo($reason) : StreamHelpers::promiseResolved(JsUndefined::instance());
            $outer = new JsPromise();
            $p->addFulfillHandler(static function (JsValue $_) use ($ts, $reason, $outer): void {
                $readable = $ts->getInternalProperty('[[Readable]]');
                if ($readable instanceof JsObject) {
                    \Phasis\BuiltIn\Streams\ReadableStream::readableStreamError($readable, $reason);
                }
                $outer->resolve(JsUndefined::instance());
            });
            $p->addRejectHandler(static function (JsValue $r) use ($outer): void {
                $outer->reject($r);
            });
            return $outer;
        }, 1);
        $sink->defineOwnProperty('abort', PropertyDescriptor::data($abortFn, true, true, true));
        return $sink;
    }

    public static function setBackpressure(JsObject $ts, bool $backpressure): void
    {
        $current = $ts->getInternalProperty('[[Backpressure]]');
        if ($current === $backpressure) {
            return;
        }
        if ($current === true && $backpressure === false) {
            // Backpressure clearing: resolve the existing change-promise so
            // any writers blocked on it can proceed.
            $bpc = $ts->getInternalProperty('[[BackpressureChangePromise]]');
            if ($bpc instanceof JsPromise && $bpc->getState() === 'pending') {
                $bpc->resolve(JsUndefined::instance());
            }
            // Reset for the next backpressure event.
            $ts->setInternalProperty('[[BackpressureChangePromise]]', new JsPromise());
        } else {
            // Going false -> true. The previous bpc may already be resolved
            // (it served the previous backpressure-clear). Install a fresh
            // pending promise so subsequent writes will wait on it.
            $bpc = $ts->getInternalProperty('[[BackpressureChangePromise]]');
            if (!$bpc instanceof JsPromise || $bpc->getState() !== 'pending') {
                $ts->setInternalProperty('[[BackpressureChangePromise]]', new JsPromise());
            }
        }
        $ts->setInternalProperty('[[Backpressure]]', $backpressure);
    }

    public static function controllerEnqueue(JsObject $controller, JsValue $chunk): void
    {
        $ts = $controller->getInternalProperty('[[Stream]]');
        if (!$ts instanceof JsObject) {
            return;
        }
        $readable = $ts->getInternalProperty('[[Readable]]');
        if (!$readable instanceof JsObject) {
            return;
        }
        $rsCtrl = $readable->getInternalProperty('[[Controller]]');
        if (!$rsCtrl instanceof JsObject) {
            return;
        }
        if (!\Phasis\BuiltIn\Streams\ReadableStream::canCloseOrEnqueue($rsCtrl)) {
            throw new TypeError('Readable side is not in a state to receive chunks');
        }
        try {
            \Phasis\BuiltIn\Streams\ReadableStream::defaultControllerEnqueue($rsCtrl, $chunk);
        } catch (\Throwable $e) {
            self::errorTransformStream($ts, StreamHelpers::exceptionToJsValue($e));
            throw $e;
        }
        $backpressure = \Phasis\BuiltIn\Streams\ReadableStream::getDesiredSize($rsCtrl);
        if ($backpressure !== null && $backpressure <= 0) {
            if ($ts->getInternalProperty('[[Backpressure]]') !== true) {
                self::setBackpressure($ts, true);
            }
        }
    }

    public static function controllerError(JsObject $controller, JsValue $error): void
    {
        $ts = $controller->getInternalProperty('[[Stream]]');
        if ($ts instanceof JsObject) {
            self::errorTransformStream($ts, $error);
        }
    }

    public static function controllerTerminate(JsObject $controller): void
    {
        $ts = $controller->getInternalProperty('[[Stream]]');
        if (!$ts instanceof JsObject) {
            return;
        }
        $readable = $ts->getInternalProperty('[[Readable]]');
        if ($readable instanceof JsObject) {
            $rsCtrl = $readable->getInternalProperty('[[Controller]]');
            if ($rsCtrl instanceof JsObject && \Phasis\BuiltIn\Streams\ReadableStream::canCloseOrEnqueue($rsCtrl)) {
                \Phasis\BuiltIn\Streams\ReadableStream::defaultControllerClose($rsCtrl);
            }
        }
        $err = StreamHelpers::createTypeError('TransformStream terminated');
        $writable = $ts->getInternalProperty('[[Writable]]');
        if ($writable instanceof JsObject) {
            $wsCtrl = $writable->getInternalProperty('[[Controller]]');
            if ($wsCtrl instanceof JsObject) {
                \Phasis\BuiltIn\Streams\WritableStream::controllerErrorIfNeeded($wsCtrl, $err);
            }
        }
    }

    public static function errorTransformStream(JsObject $ts, JsValue $error): void
    {
        $readable = $ts->getInternalProperty('[[Readable]]');
        if ($readable instanceof JsObject) {
            $rsCtrl = $readable->getInternalProperty('[[Controller]]');
            if ($rsCtrl instanceof JsObject) {
                \Phasis\BuiltIn\Streams\ReadableStream::defaultControllerError($rsCtrl, $error);
            }
        }
        $writable = $ts->getInternalProperty('[[Writable]]');
        if ($writable instanceof JsObject) {
            $wsCtrl = $writable->getInternalProperty('[[Controller]]');
            if ($wsCtrl instanceof JsObject) {
                \Phasis\BuiltIn\Streams\WritableStream::controllerErrorIfNeeded($wsCtrl, $error);
            }
        }
    }
}
