<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Streams;

use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Value\JsArray;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsPromise;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * Pipe operations: pipeTo, pipeThrough, tee.
 *
 * Spec sections:
 *   - https://streams.spec.whatwg.org/#rs-pipe-to
 *   - https://streams.spec.whatwg.org/#rs-pipe-through
 *   - https://streams.spec.whatwg.org/#rs-tee
 */
final class PipeOperations
{
    /**
     * pipeTo(dest, options) — pumps chunks from $source's reader into $dest's writer.
     */
    public static function pipeTo(JsObject $source, JsValue $dest, JsValue $options): JsPromise
    {
        if (!\Phasis\BuiltIn\Streams\WritableStream::isWritableStream($dest)) {
            return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('pipeTo destination must be WritableStream'));
        }
        /** @var JsObject $dest */
        if (\Phasis\BuiltIn\Streams\ReadableStream::isReadableStreamLocked($source)) {
            return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('Source is locked'));
        }
        if (\Phasis\BuiltIn\Streams\WritableStream::isLocked($dest)) {
            return StreamHelpers::promiseRejected(StreamHelpers::createTypeError('Destination is locked'));
        }

        $preventClose = false;
        $preventAbort = false;
        $preventCancel = false;
        $signal = null;
        if ($options instanceof JsObject) {
            $preventClose = \Phasis\Spec\TypeConversion::toBoolean($options->get('preventClose'));
            $preventAbort = \Phasis\Spec\TypeConversion::toBoolean($options->get('preventAbort'));
            $preventCancel = \Phasis\Spec\TypeConversion::toBoolean($options->get('preventCancel'));
            $sigVal = $options->get('signal');
            if ($sigVal instanceof JsObject) {
                $signal = $sigVal;
            }
        }

        $reader = \Phasis\BuiltIn\Streams\ReadableStream::acquireDefaultReader($source);
        $writer = \Phasis\BuiltIn\Streams\WritableStream::acquireDefaultWriter($dest);

        $outer = new JsPromise();
        // Mutable holder for the finished-flag. Use a named class so PHPStan
        // doesn't narrow the property to literal false.
        $finished = new PipeBoolFlag();

        $finish = function (
            bool $isError,
            JsValue $errOrUndef
        ) use (
            $outer,
            $reader,
            $writer,
            $finished
        ): void {
            if ($finished->value) {
                return;
            }
            $finished->value = true;
            \Phasis\BuiltIn\Streams\ReadableStream::defaultReaderRelease($reader);
            \Phasis\BuiltIn\Streams\WritableStream::writerRelease($writer);
            if ($isError) {
                $outer->reject($errOrUndef);
            } else {
                $outer->resolve(JsUndefined::instance());
            }
        };

        // Wire up signal abort.
        if ($signal instanceof JsObject) {
            $aborted = $signal->get('aborted');
            if (\Phasis\Spec\TypeConversion::toBoolean($aborted)) {
                $reason = $signal->get('reason');
                if (!$preventAbort) {
                    \Phasis\BuiltIn\Streams\WritableStream::abortStream($dest, $reason);
                }
                if (!$preventCancel) {
                    \Phasis\BuiltIn\Streams\ReadableStream::readableStreamCancel($source, $reason);
                }
                $finish(true, $reason);
                return $outer;
            }
            // Attach addEventListener('abort') if EventTarget shape exists.
            $addEventListener = $signal->get('addEventListener');
            if ($addEventListener instanceof JsFunction) {
                $abortCb = function (
                    JsValue $this_,
                    array $args
                ) use (
                    $signal,
                    $source,
                    $dest,
                    $finish,
                    $preventAbort,
                    $preventCancel
                ): JsValue {
                    $reason = $signal->get('reason');
                    if (!$preventAbort) {
                        \Phasis\BuiltIn\Streams\WritableStream::abortStream($dest, $reason);
                    }
                    if (!$preventCancel) {
                        \Phasis\BuiltIn\Streams\ReadableStream::readableStreamCancel($source, $reason);
                    }
                    $finish(true, $reason);
                    return JsUndefined::instance();
                };
                $listener = JsFunction::fromCallable('', $abortCb, 1);
                try {
                    $addEventListener->call($signal, [new JsString('abort'), $listener]);
                } catch (\Throwable) {
                    // Not a real EventTarget; ignore.
                }
            }
        }

        // The pump loop runs as microtasks. Each iteration reads, writes, and
        // recurses until done.
        $pump = null;
        $pump = function () use (&$pump, $reader, $writer, $source, $dest, $preventClose, $preventAbort, $preventCancel, $finish, $finished): void {
            if ($finished->value) {
                return;
            }
            // Check source state via reader.
            $sourceState = $source->getInternalProperty('[[State]]');
            if ($sourceState === StreamHelpers::STATE_ERRORED) {
                $err = $source->getInternalProperty('[[StoredError]]');
                if (!$preventAbort) {
                    \Phasis\BuiltIn\Streams\WritableStream::abortStream($dest, $err);
                }
                $finish(true, $err);
                return;
            }
            $destState = $dest->getInternalProperty('[[State]]');
            if (
                $destState === StreamHelpers::WS_ERRORED
                || $destState === StreamHelpers::WS_ERRORING
            ) {
                $err = $dest->getInternalProperty('[[StoredError]]');
                if (!$preventCancel) {
                    \Phasis\BuiltIn\Streams\ReadableStream::readableStreamCancel($source, $err);
                }
                $finish(true, $err);
                return;
            }
            // Wait for writer ready.
            $ready = $writer->getInternalProperty('[[ReadyPromise]]');
            if (!$ready instanceof JsPromise) {
                $finish(true, StreamHelpers::createTypeError('Writer has no ready promise'));
                return;
            }
            $ready->addFulfillHandler(function (JsValue $_) use (&$pump, $reader, $writer, $dest, $preventClose, $finish, $finished): void {
                if ($finished->value) {
                    return;
                }
                $readPromise = \Phasis\BuiltIn\Streams\ReadableStream::defaultReaderRead($reader);
                $readPromise->addFulfillHandler(function (JsValue $result) use (&$pump, $writer, $dest, $preventClose, $finish, $finished): void {
                    if ($finished->value) {
                        return;
                    }
                    if (!$result instanceof JsObject) {
                        $finish(true, StreamHelpers::createTypeError('Read result is not an object'));
                        return;
                    }
                    $done = \Phasis\Spec\TypeConversion::toBoolean($result->get('done'));
                    $value = $result->get('value');
                    if ($done) {
                        if (!$preventClose) {
                            $closeP = \Phasis\BuiltIn\Streams\WritableStream::closeStream($dest);
                            $closeP->addFulfillHandler(static function (JsValue $_) use ($finish): void {
                                $finish(false, JsUndefined::instance());
                            });
                            $closeP->addRejectHandler(static function (JsValue $r) use ($finish): void {
                                $finish(true, $r);
                            });
                            return;
                        }
                        $finish(false, JsUndefined::instance());
                        return;
                    }
                    $writeP = \Phasis\BuiltIn\Streams\WritableStream::writerWrite($writer, $value);
                    $writeP->addRejectHandler(static function (JsValue $r) use ($finish): void {
                        $finish(true, $r);
                    });
                    // Continue pumping regardless of when this write completes;
                    // the writer queue handles backpressure via the ready promise.
                    StreamHelpers::enqueueMicrotask($pump);
                });
                $readPromise->addRejectHandler(function (JsValue $reason) use ($dest, $finish, $finished): void {
                    if ($finished->value) {
                        return;
                    }
                    \Phasis\BuiltIn\Streams\WritableStream::abortStream($dest, $reason);
                    $finish(true, $reason);
                });
            });
            $ready->addRejectHandler(function (JsValue $reason) use ($source, $preventCancel, $finish, $finished): void {
                if ($finished->value) {
                    return;
                }
                if (!$preventCancel) {
                    \Phasis\BuiltIn\Streams\ReadableStream::readableStreamCancel($source, $reason);
                }
                $finish(true, $reason);
            });
        };
        StreamHelpers::enqueueMicrotask($pump);
        return $outer;
    }

    /**
     * pipeThrough(transform, options) — connects source through a TransformStream,
     * returning the transform's readable side.
     */
    public static function pipeThrough(JsObject $source, JsValue $transform, JsValue $options): JsObject
    {
        if (!$transform instanceof JsObject) {
            throw new TypeError('pipeThrough requires a {readable,writable} pair');
        }
        $readable = $transform->get('readable');
        $writable = $transform->get('writable');
        if (
            !\Phasis\BuiltIn\Streams\ReadableStream::isReadableStream($readable)
            || !\Phasis\BuiltIn\Streams\WritableStream::isWritableStream($writable)
        ) {
            throw new TypeError('pipeThrough: object lacks valid readable/writable');
        }
        if (\Phasis\BuiltIn\Streams\ReadableStream::isReadableStreamLocked($source)) {
            throw new TypeError('pipeThrough: source is locked');
        }
        /** @var JsObject $writable */
        if (\Phasis\BuiltIn\Streams\WritableStream::isLocked($writable)) {
            throw new TypeError('pipeThrough: writable is locked');
        }
        $pipePromise = self::pipeTo($source, $writable, $options instanceof JsObject ? $options : JsUndefined::instance());
        // Mark as handled.
        StreamHelpers::markPromiseHandled($pipePromise);
        /** @var JsObject $readable */
        return $readable;
    }

    /**
     * tee() — branches a readable stream into two independent readable streams.
     *
     * Returns a JsArray holding two ReadableStreams.
     */
    public static function tee(JsObject $stream): JsArray
    {
        $reader = \Phasis\BuiltIn\Streams\ReadableStream::acquireDefaultReader($stream);
        $closedOrErrored = new class () {
            public bool $value = false;
        };
        $canceled1 = new class () {
            public bool $value = false;
            public JsValue $reason;
            public function __construct()
            {
                $this->reason = JsUndefined::instance();
            }
        };
        $canceled2 = new class () {
            public bool $value = false;
            public JsValue $reason;
            public function __construct()
            {
                $this->reason = JsUndefined::instance();
            }
        };
        $cancelPromise = new JsPromise();
        $branches = new class () {
            public ?JsObject $b1 = null;
            public ?JsObject $b2 = null;
        };
        $pulling = new class () {
            public bool $value = false;
        };

        $pullAlgorithm = function () use ($branches, $reader, $canceled1, $canceled2, $pulling): JsPromise {
            if ($pulling->value) {
                return StreamHelpers::promiseResolved(JsUndefined::instance());
            }
            $pulling->value = true;
            $readPromise = \Phasis\BuiltIn\Streams\ReadableStream::defaultReaderRead($reader);
            $outer = new JsPromise();
            $readPromise->addFulfillHandler(function (JsValue $result) use ($branches, $canceled1, $canceled2, $outer, $pulling): void {
                if (!$result instanceof JsObject) {
                    $outer->reject(StreamHelpers::createTypeError('Read result not object'));
                    $pulling->value = false;
                    return;
                }
                $done = \Phasis\Spec\TypeConversion::toBoolean($result->get('done'));
                $value = $result->get('value');
                if ($done) {
                    if (!$canceled1->value && $branches->b1 instanceof JsObject) {
                        $c1 = $branches->b1->getInternalProperty('[[Controller]]');
                        if ($c1 instanceof JsObject) {
                            \Phasis\BuiltIn\Streams\ReadableStream::defaultControllerClose($c1);
                        }
                    }
                    if (!$canceled2->value && $branches->b2 instanceof JsObject) {
                        $c2 = $branches->b2->getInternalProperty('[[Controller]]');
                        if ($c2 instanceof JsObject) {
                            \Phasis\BuiltIn\Streams\ReadableStream::defaultControllerClose($c2);
                        }
                    }
                    $outer->resolve(JsUndefined::instance());
                    $pulling->value = false;
                    return;
                }
                if (!$canceled1->value && $branches->b1 instanceof JsObject) {
                    $c1 = $branches->b1->getInternalProperty('[[Controller]]');
                    if ($c1 instanceof JsObject && \Phasis\BuiltIn\Streams\ReadableStream::canCloseOrEnqueue($c1)) {
                        \Phasis\BuiltIn\Streams\ReadableStream::defaultControllerEnqueue($c1, $value);
                    }
                }
                if (!$canceled2->value && $branches->b2 instanceof JsObject) {
                    $c2 = $branches->b2->getInternalProperty('[[Controller]]');
                    if ($c2 instanceof JsObject && \Phasis\BuiltIn\Streams\ReadableStream::canCloseOrEnqueue($c2)) {
                        \Phasis\BuiltIn\Streams\ReadableStream::defaultControllerEnqueue($c2, $value);
                    }
                }
                $outer->resolve(JsUndefined::instance());
                $pulling->value = false;
            });
            $readPromise->addRejectHandler(function (JsValue $reason) use ($branches, $outer, $pulling): void {
                if ($branches->b1 instanceof JsObject) {
                    $c1 = $branches->b1->getInternalProperty('[[Controller]]');
                    if ($c1 instanceof JsObject) {
                        \Phasis\BuiltIn\Streams\ReadableStream::defaultControllerError($c1, $reason);
                    }
                }
                if ($branches->b2 instanceof JsObject) {
                    $c2 = $branches->b2->getInternalProperty('[[Controller]]');
                    if ($c2 instanceof JsObject) {
                        \Phasis\BuiltIn\Streams\ReadableStream::defaultControllerError($c2, $reason);
                    }
                }
                $outer->reject($reason);
                $pulling->value = false;
            });
            return $outer;
        };

        $cancelAlgorithm1 = function (JsValue $reason) use ($stream, $canceled1, $canceled2, $cancelPromise): JsPromise {
            $canceled1->value = true;
            $canceled1->reason = $reason;
            if ($canceled2->value) {
                $p = \Phasis\BuiltIn\Streams\ReadableStream::readableStreamCancel($stream, $reason);
                StreamHelpers::markPromiseHandled($p);
                if ($cancelPromise->getState() === 'pending') {
                    $cancelPromise->resolve(JsUndefined::instance());
                }
            }
            return $cancelPromise;
        };
        $cancelAlgorithm2 = function (JsValue $reason) use ($stream, $canceled1, $canceled2, $cancelPromise): JsPromise {
            $canceled2->value = true;
            $canceled2->reason = $reason;
            if ($canceled1->value) {
                $p = \Phasis\BuiltIn\Streams\ReadableStream::readableStreamCancel($stream, $reason);
                StreamHelpers::markPromiseHandled($p);
                if ($cancelPromise->getState() === 'pending') {
                    $cancelPromise->resolve(JsUndefined::instance());
                }
            }
            return $cancelPromise;
        };

        $branches->b1 = self::buildBranch($pullAlgorithm, $cancelAlgorithm1);
        $branches->b2 = self::buildBranch($pullAlgorithm, $cancelAlgorithm2);

        return JsArray::fromArray([$branches->b1, $branches->b2]);
    }

    /**
     * Helper: create a ReadableStream branch with given pull/cancel algorithms.
     */
    private static function buildBranch(\Closure $pullAlgorithm, \Closure $cancelAlgorithm): JsObject
    {
        $proto = \Phasis\BuiltIn\Streams\ReadableStream::getPrototype();
        $stream = new JsObject($proto);
        \Phasis\BuiltIn\Streams\ReadableStream::initializeStream($stream);

        $controller = new JsObject(\Phasis\BuiltIn\Streams\ReadableStream::getControllerPrototype());
        $controller->setInternalProperty('[[IsReadableStreamDefaultController]]', true);
        $controller->setInternalProperty('[[Stream]]', $stream);
        StreamHelpers::resetQueue($controller);
        $controller->setInternalProperty('[[Started]]', true);
        $controller->setInternalProperty('[[Pulling]]', false);
        $controller->setInternalProperty('[[PullAgain]]', false);
        $controller->setInternalProperty('[[CloseRequested]]', false);
        $controller->setInternalProperty('[[StrategyHWM]]', 1.0);
        $controller->setInternalProperty('[[StrategySizeAlgorithm]]', null);
        $controller->setInternalProperty('[[PullAlgorithm]]', $pullAlgorithm);
        $controller->setInternalProperty('[[CancelAlgorithm]]', $cancelAlgorithm);
        $stream->setInternalProperty('[[Controller]]', $controller);
        $stream->setInternalProperty('[[ControllerType]]', 'default');

        return $stream;
    }
}
