<?php

/**
 * Ported from React 19.3.0's Fizz server renderer (ReactFizzServer,
 * ReactFizzNewContext, ReactFizzTreeContext, ReactFizzThenable,
 * ReactFizzClassComponent), as built into react-dom-server.node.production.js
 * (prerender) and react-dom-server-legacy.node.production.js
 * (renderToString, renderToStaticMarkup).
 *
 * Copyright (c) Meta Platforms, Inc. and affiliates.
 *
 * This source code is licensed under the MIT license found in the
 * LICENSE file in the root directory of this source tree
 * (third-party/react/LICENSE in Phasis).
 *
 * Not ported: resuming (replay tasks, postponed state), SuspenseList rows,
 * the onHeaders Link header, and component stacks in error info.
 */

declare(strict_types=1);

namespace Phasis\BuiltIn\Jsx\React\Fizz;

use Phasis\BuiltIn\Jsx\Js;
use Phasis\BuiltIn\Jsx\React\ReactModule;
use Phasis\BuiltIn\Jsx\React\ReactSymbols;
use Phasis\Engine;
use Phasis\Exceptions\JsThrowable;
use Phasis\Object\PropertyDescriptor;
use Phasis\Value\JsArray;
use Phasis\Value\JsBigInt;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsElement;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsPromise;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

final class Server
{
    /** `_currentValue` for the Fizz renderer, `_currentValue2` for the legacy one. */
    public static string $contextField = '_currentValue';

    private static ?ContextNode $currentActiveSnapshot = null;
    private static ?Request $currentRequest = null;
    private static int $flushedByteSize = 0;
    private static bool $flushingPartialBoundaries = false;
    private static bool $flushingShell = false;
    private static ?JsObject $suspendedThenable = null;

    /** @var array<int, JsObject> React's SuspenseException, one Error object per realm. */
    private static array $suspenseException = [];

    private static ?JsObject $emptyContextObject = null;
    private static ?JsObject $classComponentUpdater = null;

    // Request creation and the public entry points.

    public static function resolveRequest(): ?Request
    {
        return self::$currentRequest;
    }

    /**
     * renderToString / renderToStaticMarkup (React's legacy renderToStringImpl).
     */
    public static function renderToString(JsValue $children, JsValue $options, bool $generateStaticMarkup): string
    {
        $abortReason = Js::makeError($generateStaticMarkup
            ? 'The server used "renderToStaticMarkup" which does not support Suspense. If you intended to have the server wait for the '
                . 'suspended component please switch to "renderToPipeableStream" which supports Suspense on the server'
            : 'The server used "renderToString" which does not support Suspense. If you intended for this Suspense boundary to render '
                . 'the fallback content on the server consider throwing an Error somewhere within the Suspense boundary. If you intended '
                . 'to have the server wait for the suspended component please switch to "renderToPipeableStream" which supports Suspense '
                . 'on the server');
        $prefix = $options instanceof JsObject ? Js::get($options, 'identifierPrefix') : JsUndefined::instance();
        $resumableState = new ResumableState($prefix instanceof JsUndefined ? '' : Js::str($prefix));
        $renderState = Dom::createRenderState($resumableState, null, null, $generateStaticMarkup);
        $renderState->legacy = true;
        $readyToStream = false;
        $request = self::createRequest($children, $resumableState, $renderState, new FormatContext(0, null, 0, null), INF);
        $request->legacy = true;
        $request->onShellReady = static function () use (&$readyToStream): void {
            $readyToStream = true;
        };
        $request->flushScheduled = $request->destination !== null;
        self::performWork($request);
        if ($request->status === Request::OPENING) {
            $request->status = Request::OPEN;
        }
        self::abort($request, $abortReason);
        $destination = new Destination();
        $fatal = null;
        self::startFlowing($request, $destination, $fatal);
        if ($fatal !== null && $fatal !== $abortReason) {
            Js::throw($fatal);
        }
        if (!$readyToStream) {
            throw Js::error(
                'A component suspended while responding to synchronous input. This will cause the UI to be replaced '
                . 'with a loading indicator. To fix, updates that suspend should be wrapped with startTransition.',
            );
        }
        return $destination->buffer;
    }

    /**
     * Start a prerender (react-dom/static). $onAllReady runs once every task
     * has settled; $onFatal runs on a fatal error. Rendering is driven by
     * microtasks, so the caller runs the event loop.
     *
     * @param \Closure(Request): void $onAllReady
     * @param \Closure(JsValue): void $onFatal
     */
    public static function prerender(JsValue $children, JsValue $options, \Closure $onAllReady, \Closure $onFatal): Request
    {
        $opt = static fn (string $name): JsValue => $options instanceof JsObject ? Js::get($options, $name) : JsUndefined::instance();
        $resumableState = Dom::createResumableState($options);
        $importMap = $opt('importMap');
        $renderState = Dom::createRenderState($resumableState, null, $importMap instanceof JsUndefined ? null : $importMap);
        $namespace = $opt('namespaceURI');
        $chunkSize = $opt('progressiveChunkSize');
        $request = self::createRequest(
            $children,
            $resumableState,
            $renderState,
            Dom::createRootFormatContext($namespace instanceof JsUndefined ? null : Js::str($namespace)),
            $chunkSize instanceof JsUndefined ? 12800.0 : (float) Js::str($chunkSize),
        );
        $request->trackedPostpones = true;
        $onError = $opt('onError');
        if ($onError instanceof JsFunction) {
            $request->onError = static function (JsValue $error, array $info) use ($onError): ?string {
                $digest = Js::call($onError, JsUndefined::instance(), [$error, Js::object()]);
                if (Js::isNullish($digest) || $digest instanceof JsString) {
                    return $digest instanceof JsString && $digest->value !== '' ? $digest->value : null;
                }
                return null;
            };
        } else {
            $request->onError = self::defaultErrorHandler(...);
        }
        $request->onAllReady = static function () use ($request, $onAllReady): void {
            $onAllReady($request);
        };
        $request->onFatalError = $onFatal;
        $signal = $opt('signal');
        if ($signal instanceof JsObject) {
            self::attachAbortSignal($request, $signal);
        }
        self::startWork($request);
        return $request;
    }

    /** Flush a finished prerender into a string. */
    public static function flushToString(Request $request): string
    {
        $destination = new Destination();
        $fatal = null;
        self::startFlowing($request, $destination, $fatal);
        if ($fatal !== null) {
            Js::throw($fatal);
        }
        return $destination->buffer;
    }

    private static function createRequest(
        JsValue $children,
        ResumableState $resumableState,
        RenderState $renderState,
        FormatContext $rootFormatContext,
        float $progressiveChunkSize,
    ): Request {
        ReactSymbols::init();
        $request = new Request($resumableState, $renderState, $rootFormatContext, $progressiveChunkSize);
        $request->onError = static fn (JsValue $error, array $info): ?string => null;
        $rootSegment = self::createPendingSegment($request, 0, null, $rootFormatContext, false, false);
        $rootSegment->parentFlushed = true;
        $task = self::createRenderTask(
            $request,
            null,
            $children,
            -1,
            null,
            $rootSegment,
            null,
            null,
            $request->abortableTasks,
            null,
            $rootFormatContext,
            null,
            new TreeContext(1, ''),
        );
        $request->pingedTasks[] = $task;
        return $request;
    }

    /**
     * React's defaultErrorHandler: console.error(error), no digest.
     *
     * @param array<string, mixed> $info
     */
    private static function defaultErrorHandler(JsValue $error, array $info): null
    {
        $interpreter = Engine::getCurrentInterpreter();
        $console = $interpreter?->getGlobalEnv()->get('console');
        if ($console instanceof JsObject) {
            $log = Js::get($console, 'error');
            if ($log instanceof JsFunction) {
                Js::call($log, $console, [$error]);
            }
        }
        return null;
    }

    private static function startWork(Request $request): void
    {
        $request->flushScheduled = $request->destination !== null;
        JsPromise::scheduleCallback(static function () use ($request): void {
            self::performWork($request);
        });
        self::setImmediate(static function () use ($request): void {
            if ($request->status === Request::OPENING) {
                $request->status = Request::OPEN;
            }
        });
    }

    /** Node's setImmediate, as a zero-delay macrotask. */
    private static function setImmediate(\Closure $callback): void
    {
        $engine = Engine::getCurrentRealm();
        if ($engine === null) {
            $callback();
            return;
        }
        $engine->getEventLoop()->setTimeout(Js::fn('', static function () use ($callback): JsValue {
            $callback();
            return JsUndefined::instance();
        }), 0);
    }

    public static function enqueueFlush(Request $request): void
    {
        // Phasis flushes once, when the render is complete; there is no
        // destination to stream into while work is pending.
    }

    private static function pingTask(Request $request, Task $task): void
    {
        $request->pingedTasks[] = $task;
        if (count($request->pingedTasks) !== 1) {
            return;
        }
        $request->flushScheduled = $request->destination !== null;
        if ($request->legacy) {
            self::performWork($request);
        } elseif ($request->trackedPostpones || $request->status === Request::OPENING) {
            JsPromise::scheduleCallback(static function () use ($request): void {
                self::performWork($request);
            });
        } else {
            self::setImmediate(static function () use ($request): void {
                self::performWork($request);
            });
        }
    }

    private static function createSuspenseBoundary(
        Request $request,
        TaskSet $fallbackAbortableTasks,
        ?BoundaryPreamble $preamble,
        bool $defer,
    ): Boundary {
        return new Boundary($fallbackAbortableTasks, $preamble, $defer);
    }

    /**
     * @param list<JsValue>|null $thenableState
     * @param array<int, mixed>|null $keyPath
     */
    private static function createRenderTask(
        Request $request,
        ?array $thenableState,
        JsValue $node,
        int $childIndex,
        ?Boundary $blockedBoundary,
        Segment $blockedSegment,
        ?PreambleState $blockedPreamble,
        ?HoistableState $hoistableState,
        TaskSet $abortSet,
        ?array $keyPath,
        FormatContext $formatContext,
        ?ContextNode $context,
        TreeContext $treeContext,
    ): Task {
        $request->allPendingTasks++;
        if ($blockedBoundary === null) {
            $request->pendingRootTasks++;
        } else {
            $blockedBoundary->pendingTasks++;
        }
        $task = new Task(
            $node,
            $childIndex,
            $blockedBoundary,
            $blockedSegment,
            $blockedPreamble,
            $hoistableState,
            $abortSet,
            $keyPath,
            $formatContext,
            $context,
            $treeContext,
            $thenableState,
        );
        $task->pingResolve = Js::fn('', static function () use ($request, $task): JsValue {
            self::pingTask($request, $task);
            return JsUndefined::instance();
        }, 0);
        $task->pingReject = Js::fn('', static function (JsValue $this_, array $args) use ($request, $task): JsValue {
            if ($request->aborted) {
                if ($task->abortSet->delete($task)) {
                    self::finishAbortedTask($task, $request, $args[0] ?? JsUndefined::instance());
                }
            } else {
                self::pingTask($request, $task);
            }
            return JsUndefined::instance();
        }, 1);
        $abortSet->add($task);
        return $task;
    }

    private static function createPendingSegment(
        Request $request,
        int $index,
        ?Boundary $boundary,
        FormatContext $parentFormatContext,
        bool $lastPushedText,
        bool $textEmbedded,
    ): Segment {
        return new Segment($index, $boundary, $parentFormatContext, $lastPushedText, $textEmbedded);
    }

    /** @param array<string, mixed> $errorInfo */
    private static function logRecoverableError(Request $request, JsValue $error, array $errorInfo = []): ?string
    {
        if (self::isRecoverableError($error)) {
            ($request->onBrowserBailout)($error);
            return '';
        }
        return ($request->onError)($error, $errorInfo);
    }

    private static function fatalError(Request $request, JsValue $error): void
    {
        if ($request->pendingRootTasks !== 0) {
            ($request->onShellError)($error);
        }
        ($request->onFatalError)($error);
        if ($request->destination !== null) {
            $request->status = Request::CLOSED;
        } else {
            $request->status = Request::ABORTING;
            if (!$request->aborted) {
                $request->fatalError = $error;
            }
        }
    }

    // Recoverable errors (react-dom's browser()).

    public static function createRecoverableError(JsObject $recoverable): JsObject
    {
        $reason = Js::get($recoverable, '_reason');
        if ($reason instanceof JsFunction) {
            try {
                $initializedReason = Js::call($reason, JsUndefined::instance());
            } catch (\Throwable $e) {
                Js::thrownValue($e);
                $initializedReason = new JsString('The reason for browser-only rendering could not be determined because its initializer threw.');
            }
        } else {
            $initializedReason = $reason;
        }
        $error = Js::makeError(
            'Browser-only rendering was requested by `browser()`.',
            'Error',
            $reason instanceof JsUndefined ? null : $initializedReason,
        );
        $error->definePropertyBySymbol(ReactSymbols::$recoverable, PropertyDescriptor::data(JsBoolean::of(true), false, false, false));
        return $error;
    }

    private static function isRecoverableError(JsValue $error): bool
    {
        if (!$error instanceof JsObject || $error instanceof JsFunction) {
            return false;
        }
        $flag = $error->getBySymbol(ReactSymbols::$recoverable);
        return $flag instanceof JsBoolean && $flag->value;
    }

    private static function cloneRecoverableErrorAsFatal(JsObject $recoverableError): JsObject
    {
        $cause = Js::hasOwn($recoverableError, 'cause') ? Js::get($recoverableError, 'cause') : null;
        $fatal = Js::makeError(
            "The server render could not complete because client rendering was requested outside a Suspense "
            . "boundary. See this error's cause for additional details.",
            'Error',
            $cause,
        );
        $stack = Js::get($recoverableError, 'stack');
        if (!$stack instanceof JsUndefined) {
            $stackText = Js::str($stack);
            $frameStart = strpos($stackText, "\n");
            $fatal->set('stack', new JsString(
                Js::str(Js::get($fatal, 'name')) . ': ' . Js::str(Js::get($fatal, 'message'))
                . ($frameStart === false ? '' : substr($stackText, $frameStart))
            ));
        } else {
            $fatal->set('stack', JsUndefined::instance());
        }
        return $fatal;
    }

    // Thenables.

    private static function suspenseException(): JsObject
    {
        $interpreter = Engine::getCurrentInterpreter();
        $key = $interpreter === null ? 0 : spl_object_id($interpreter);
        return self::$suspenseException[$key] ??= Js::makeError(
            "Suspense Exception: This is not a real error! It's an implementation detail of `use` to interrupt "
            . "the current render. You must either rethrow it immediately, or move the `use` call outside of the "
            . "`try/catch` block. Capturing without rethrowing will lead to unexpected behavior.\n\nTo handle "
            . "async errors, wrap your component in an error boundary, or call the promise's `.catch` method and "
            . "pass the result to `use`.",
        );
    }

    /** @param list<JsValue> $thenableState */
    public static function trackUsedThenable(array &$thenableState, JsObject $thenable, int $index): JsValue
    {
        $previous = $thenableState[$index] ?? null;
        $noop = Js::fn('', static fn (): JsValue => JsUndefined::instance());
        if ($previous === null) {
            $thenableState[] = $thenable;
        } elseif ($previous !== $thenable) {
            Js::call(Js::get($thenable, 'then'), $thenable, [$noop, $noop]);
            $thenable = $previous;
            if (!$thenable instanceof JsObject) {
                Js::throw($thenable);
            }
        }
        $status = Js::get($thenable, 'status');
        if ($status instanceof JsString && $status->value === 'fulfilled') {
            return Js::get($thenable, 'value');
        }
        if ($status instanceof JsString && $status->value === 'rejected') {
            $reason = Js::get($thenable, 'reason');
            if ($reason instanceof JsUndefined && !Js::has($thenable, 'reason')) {
                throw Js::error(
                    "A rejected Promise was passed to React without a `reason` property. React threw a generic error "
                    . "from where the Promise was used to assist in identifying the problematic Promise. Make sure that "
                    . "instrumented Promises correctly set the `reason` property when setting `status` to `'rejected'`.",
                );
            }
            Js::throw($reason);
        }
        if ($status instanceof JsString) {
            Js::call(Js::get($thenable, 'then'), $thenable, [$noop, $noop]);
        } else {
            $thenable->set('status', new JsString('pending'));
            Js::call(Js::get($thenable, 'then'), $thenable, [
                Js::fn('', static function (JsValue $this_, array $args) use ($thenable): JsValue {
                    $current = Js::get($thenable, 'status');
                    if ($current instanceof JsString && $current->value === 'pending') {
                        $thenable->set('status', new JsString('fulfilled'));
                        $thenable->set('value', $args[0] ?? JsUndefined::instance());
                    }
                    return JsUndefined::instance();
                }, 1),
                Js::fn('', static function (JsValue $this_, array $args) use ($thenable): JsValue {
                    $current = Js::get($thenable, 'status');
                    if ($current instanceof JsString && $current->value === 'pending') {
                        $thenable->set('status', new JsString('rejected'));
                        $thenable->set('reason', $args[0] ?? JsUndefined::instance());
                    }
                    return JsUndefined::instance();
                }, 1),
            ]);
        }
        $status = Js::get($thenable, 'status');
        if ($status instanceof JsString && $status->value === 'fulfilled') {
            return Js::get($thenable, 'value');
        }
        if ($status instanceof JsString && $status->value === 'rejected') {
            Js::throw(Js::get($thenable, 'reason'));
        }
        self::$suspendedThenable = $thenable;
        Js::throw(self::suspenseException());
    }

    /** True for the error use() throws to suspend (React's SuspenseException). */
    public static function isSuspenseException(JsValue $value): bool
    {
        return $value === self::suspenseException();
    }

    /** The thenable the last use() call suspended on. */
    public static function takeSuspendedThenable(): JsObject
    {
        return self::getSuspendedThenable();
    }

    private static function getSuspendedThenable(): JsObject
    {
        if (self::$suspendedThenable === null) {
            throw Js::error('Expected a suspended thenable. This is a bug in React. Please file an issue.');
        }
        $thenable = self::$suspendedThenable;
        self::$suspendedThenable = null;
        return $thenable;
    }

    // Context stack.

    private static function setContextValue(JsObject $context, JsValue $value): void
    {
        $context->set(self::$contextField, $value);
    }

    private static function popToNearestCommonAncestor(ContextNode $prev, ContextNode $next): void
    {
        if ($prev === $next) {
            return;
        }
        self::setContextValue($prev->context, $prev->parentValue);
        $parentPrev = $prev->parent;
        $parentNext = $next->parent;
        if ($parentPrev === null) {
            if ($parentNext !== null) {
                throw Js::error('The stacks must reach the root at the same time. This is a bug in React.');
            }
        } else {
            if ($parentNext === null) {
                throw Js::error('The stacks must reach the root at the same time. This is a bug in React.');
            }
            self::popToNearestCommonAncestor($parentPrev, $parentNext);
        }
        self::setContextValue($next->context, $next->value);
    }

    private static function popAllPrevious(ContextNode $prev): void
    {
        self::setContextValue($prev->context, $prev->parentValue);
        if ($prev->parent !== null) {
            self::popAllPrevious($prev->parent);
        }
    }

    private static function pushAllNext(ContextNode $next): void
    {
        if ($next->parent !== null) {
            self::pushAllNext($next->parent);
        }
        self::setContextValue($next->context, $next->value);
    }

    private static function popPreviousToCommonLevel(ContextNode $prev, ContextNode $next): void
    {
        self::setContextValue($prev->context, $prev->parentValue);
        $parentPrev = $prev->parent;
        if ($parentPrev === null) {
            throw Js::error('The depth must equal at least at zero before reaching the root. This is a bug in React.');
        }
        if ($parentPrev->depth === $next->depth) {
            self::popToNearestCommonAncestor($parentPrev, $next);
        } else {
            self::popPreviousToCommonLevel($parentPrev, $next);
        }
    }

    private static function popNextToCommonLevel(ContextNode $prev, ContextNode $next): void
    {
        $parentNext = $next->parent;
        if ($parentNext === null) {
            throw Js::error('The depth must equal at least at zero before reaching the root. This is a bug in React.');
        }
        if ($prev->depth === $parentNext->depth) {
            self::popToNearestCommonAncestor($prev, $parentNext);
        } else {
            self::popNextToCommonLevel($prev, $parentNext);
        }
        self::setContextValue($next->context, $next->value);
    }

    private static function switchContext(?ContextNode $newSnapshot): void
    {
        $prev = self::$currentActiveSnapshot;
        if ($prev === $newSnapshot) {
            return;
        }
        if ($prev === null) {
            self::pushAllNext($newSnapshot);
        } elseif ($newSnapshot === null) {
            self::popAllPrevious($prev);
        } elseif ($prev->depth === $newSnapshot->depth) {
            self::popToNearestCommonAncestor($prev, $newSnapshot);
        } elseif ($prev->depth > $newSnapshot->depth) {
            self::popPreviousToCommonLevel($prev, $newSnapshot);
        } else {
            self::popNextToCommonLevel($prev, $newSnapshot);
        }
        self::$currentActiveSnapshot = $newSnapshot;
    }

    // Tree context (useId).

    private static function clz32(int $x): int
    {
        $x &= 0xFFFFFFFF;
        return $x === 0 ? 32 : 32 - strlen(decbin($x));
    }

    public static function getTreeId(TreeContext $context): string
    {
        $id = $context->id;
        return base_convert((string) ($id & ~(1 << (32 - self::clz32($id) - 1))), 10, 32) . $context->overflow;
    }

    private static function pushTreeContext(TreeContext $base, int $totalChildren, int $index): TreeContext
    {
        $baseIdWithLeadingBit = $base->id;
        $baseOverflow = $base->overflow;
        $baseLength = 32 - self::clz32($baseIdWithLeadingBit) - 1;
        $baseId = $baseIdWithLeadingBit & ~(1 << $baseLength);
        $slot = $index + 1;
        $length = 32 - self::clz32($totalChildren) + $baseLength;
        if ($length > 30) {
            $numberOfOverflowBits = $baseLength - ($baseLength % 5);
            $newOverflow = base_convert((string) ($baseId & ((1 << $numberOfOverflowBits) - 1)), 10, 32);
            $restOfBaseId = $baseId >> $numberOfOverflowBits;
            $restOfBaseLength = $baseLength - $numberOfOverflowBits;
            $restOfLength = 32 - self::clz32($totalChildren) + $restOfBaseLength;
            return new TreeContext(
                (1 << $restOfLength) | ($slot << $restOfBaseLength) | $restOfBaseId,
                $newOverflow . $baseOverflow,
            );
        }
        return new TreeContext((1 << $length) | ($slot << $baseLength) | $baseId, $baseOverflow);
    }

    // Class components.

    private static function emptyContextObject(): JsObject
    {
        return self::$emptyContextObject ??= new JsObject();
    }

    private static function classComponentUpdater(): JsObject
    {
        if (self::$classComponentUpdater === null) {
            self::$classComponentUpdater = Js::object([
                'enqueueSetState' => Js::fn('enqueueSetState', static function (JsValue $this_, array $args): JsValue {
                    $internals = Js::get($args[0] ?? JsUndefined::instance(), '_reactInternals');
                    $queue = Js::get($internals, 'queue');
                    if (!$queue instanceof JsNull && $queue instanceof JsArray) {
                        $queue->push($args[1] ?? JsUndefined::instance());
                    }
                    return JsUndefined::instance();
                }, 2),
                'enqueueReplaceState' => Js::fn('enqueueReplaceState', static function (JsValue $this_, array $args): JsValue {
                    $internals = Js::get($args[0] ?? JsUndefined::instance(), '_reactInternals');
                    if ($internals instanceof JsObject) {
                        $internals->set('replace', JsBoolean::of(true));
                        $internals->set('queue', new JsArray([$args[1] ?? JsUndefined::instance()]));
                    }
                    return JsUndefined::instance();
                }, 2),
                'enqueueForceUpdate' => Js::fn('enqueueForceUpdate', static fn (): JsValue => JsUndefined::instance()),
            ]);
        }
        return self::$classComponentUpdater;
    }

    /** @param array<int, mixed> $keyPath */
    private static function renderClassComponent(Request $request, Task $task, array $keyPath, JsFunction $type, JsObject $props): void
    {
        $newProps = $props;
        if (Js::has($props, 'ref')) {
            $newProps = new JsObject();
            foreach (Js::forInKeys($props) as $name) {
                if ($name !== 'ref') {
                    $newProps->set($name, Js::get($props, $name));
                }
            }
        }
        $defaultProps = Js::get($type, 'defaultProps');
        if ($defaultProps->toBoolean() && $defaultProps instanceof JsObject) {
            if ($newProps === $props) {
                $copy = new JsObject();
                foreach (Js::ownEnumerableKeys($props) as $name) {
                    $copy->set($name, Js::get($props, $name));
                }
                $newProps = $copy;
            }
            foreach (Js::forInKeys($defaultProps) as $name) {
                if (Js::get($newProps, $name) instanceof JsUndefined) {
                    $newProps->set($name, Js::get($defaultProps, $name));
                }
            }
        }
        $contextType = Js::get($type, 'contextType');
        $context = $contextType instanceof JsObject && !$contextType instanceof JsFunction
            ? Js::get($contextType, self::$contextField)
            : self::emptyContextObject();
        $instance = Js::construct($type, [$newProps, $context]);
        if (!$instance instanceof JsObject) {
            throw Js::error('Class constructor did not return an object');
        }
        $state = Js::get($instance, 'state');
        $initialState = $state instanceof JsUndefined ? JsNull::instance() : $state;
        $instance->set('updater', self::classComponentUpdater());
        $instance->set('props', $newProps);
        $instance->set('state', $initialState);
        $internals = Js::object(['queue' => new JsArray(), 'replace' => JsBoolean::of(false)]);
        $instance->set('_reactInternals', $internals);
        $contextType = Js::get($type, 'contextType');
        $instance->set('context', $contextType instanceof JsObject && !$contextType instanceof JsFunction
            ? Js::get($contextType, self::$contextField)
            : self::emptyContextObject());
        $getDerivedStateFromProps = Js::get($type, 'getDerivedStateFromProps');
        if ($getDerivedStateFromProps instanceof JsFunction) {
            $partialState = Js::call($getDerivedStateFromProps, JsUndefined::instance(), [$newProps, $initialState]);
            $instance->set(
                'state',
                Js::isNullish($partialState) ? $initialState : self::assign(self::assign(new JsObject(), $initialState), $partialState),
            );
        }
        if (
            !Js::get($type, 'getDerivedStateFromProps') instanceof JsFunction
            && !Js::get($instance, 'getSnapshotBeforeUpdate') instanceof JsFunction
            && (
                Js::get($instance, 'UNSAFE_componentWillMount') instanceof JsFunction
                || Js::get($instance, 'componentWillMount') instanceof JsFunction
            )
        ) {
            $oldState = Js::get($instance, 'state');
            $willMount = Js::get($instance, 'componentWillMount');
            if ($willMount instanceof JsFunction) {
                Js::call($willMount, $instance);
            }
            $unsafeWillMount = Js::get($instance, 'UNSAFE_componentWillMount');
            if ($unsafeWillMount instanceof JsFunction) {
                Js::call($unsafeWillMount, $instance);
            }
            if ($oldState !== Js::get($instance, 'state')) {
                Js::call(
                    Js::get(self::classComponentUpdater(), 'enqueueReplaceState'),
                    self::classComponentUpdater(),
                    [$instance, Js::get($instance, 'state'), JsNull::instance()],
                );
            }
            $queue = Js::get($internals, 'queue');
            if ($queue instanceof JsArray && $queue->getLength() > 0) {
                $oldQueue = Js::elements($queue);
                $oldReplace = Js::get($internals, 'replace')->toBoolean();
                $internals->set('queue', JsNull::instance());
                $internals->set('replace', JsBoolean::of(false));
                if ($oldReplace && count($oldQueue) === 1) {
                    $instance->set('state', $oldQueue[0]);
                } else {
                    $nextState = $oldReplace ? $oldQueue[0] : Js::get($instance, 'state');
                    $dontMutate = true;
                    for ($i = $oldReplace ? 1 : 0; $i < count($oldQueue); $i++) {
                        $partial = $oldQueue[$i];
                        $partialState = $partial instanceof JsFunction
                            ? Js::call($partial, $instance, [$nextState, $newProps, JsUndefined::instance()])
                            : $partial;
                        if (!Js::isNullish($partialState)) {
                            if ($dontMutate) {
                                $dontMutate = false;
                                $nextState = self::assign(self::assign(new JsObject(), $nextState), $partialState);
                            } else {
                                self::assign($nextState, $partialState);
                            }
                        }
                    }
                    $instance->set('state', $nextState);
                }
            } else {
                $internals->set('queue', JsNull::instance());
            }
        }
        $nextChildren = Js::call(Js::get($instance, 'render'), $instance);
        if ($request->aborted) {
            Js::throw(JsNull::instance());
        }
        $prevKeyPath = $task->keyPath;
        $task->keyPath = $keyPath;
        self::renderNodeDestructive($request, $task, $nextChildren, -1);
        $task->keyPath = $prevKeyPath;
    }

    /** Object.assign(target, source). */
    private static function assign(JsValue $target, JsValue $source): JsObject
    {
        if (!$target instanceof JsObject) {
            $target = new JsObject();
        }
        if ($source instanceof JsObject) {
            foreach (Js::ownEnumerableKeys($source) as $key) {
                $target->set($key, Js::get($source, $key), true);
            }
        } elseif ($source instanceof JsString) {
            foreach (mb_str_split($source->value) as $i => $ch) {
                $target->set((string) $i, new JsString($ch), true);
            }
        }
        return $target;
    }

    // Rendering.

    /** @param array<int, mixed>|null $keyPath */
    private static function finishFunctionComponent(
        Request $request,
        Task $task,
        ?array $keyPath,
        JsValue $children,
        bool $hasId,
        int $actionStateCount,
        int $actionStateMatchingIndex,
    ): void {
        $prevKeyPath = $task->keyPath;
        $task->keyPath = $keyPath;
        if ($hasId) {
            $prevTreeContext = $task->treeContext;
            $task->treeContext = self::pushTreeContext($prevTreeContext, 1, 0);
            self::renderNode($request, $task, $children, -1);
            $task->treeContext = $prevTreeContext;
        } else {
            self::renderNodeDestructive($request, $task, $children, -1);
        }
        $task->keyPath = $prevKeyPath;
    }

    /** @param array<int, mixed> $keyPath */
    private static function renderElement(Request $request, Task $task, array $keyPath, JsValue $type, JsObject $props, JsValue $ref): void
    {
        if ($type instanceof JsFunction) {
            $prototype = Js::get($type, 'prototype');
            if ($prototype instanceof JsObject && Js::get($prototype, 'isReactComponent')->toBoolean()) {
                self::renderClassComponent($request, $task, $keyPath, $type, $props);
                return;
            }
            $value = Hooks::renderWithHooks($request, $task, $keyPath, $type, $props, JsUndefined::instance());
            if ($request->aborted) {
                Js::throw(JsNull::instance());
            }
            self::finishFunctionComponent(
                $request,
                $task,
                $keyPath,
                $value,
                Hooks::$localIdCounter !== 0,
                Hooks::$actionStateCounter,
                Hooks::$actionStateMatchingIndex,
            );
            return;
        }
        if ($type instanceof JsString) {
            self::renderHostElement($request, $task, $keyPath, $type->value, $props);
            return;
        }
        if ($type instanceof JsSymbol) {
            self::renderSpecialElement($request, $task, $keyPath, $type, $props);
            return;
        }
        if ($type instanceof JsObject) {
            $typeof = Js::get($type, '$$typeof');
            if ($typeof === ReactSymbols::$forwardRef) {
                $propsWithoutRef = $props;
                if (Js::has($props, 'ref')) {
                    $propsWithoutRef = new JsObject();
                    foreach (Js::forInKeys($props) as $name) {
                        if ($name !== 'ref') {
                            $propsWithoutRef->set($name, Js::get($props, $name));
                        }
                    }
                }
                $children = Hooks::renderWithHooks($request, $task, $keyPath, Js::get($type, 'render'), $propsWithoutRef, $ref);
                self::finishFunctionComponent(
                    $request,
                    $task,
                    $keyPath,
                    $children,
                    Hooks::$localIdCounter !== 0,
                    Hooks::$actionStateCounter,
                    Hooks::$actionStateMatchingIndex,
                );
                return;
            }
            if ($typeof === ReactSymbols::$memo) {
                self::renderElement($request, $task, $keyPath, Js::get($type, 'type'), $props, $ref);
                return;
            }
            if ($typeof === ReactSymbols::$context) {
                $children = Js::get($props, 'children');
                $prevKeyPath = $task->keyPath;
                $nextValue = Js::get($props, 'value');
                $prevValue = Js::get($type, self::$contextField);
                self::setContextValue($type, $nextValue);
                $prevNode = self::$currentActiveSnapshot;
                $newNode = new ContextNode($prevNode, $prevNode === null ? 0 : $prevNode->depth + 1, $type, $prevValue, $nextValue);
                self::$currentActiveSnapshot = $newNode;
                $task->context = $newNode;
                $task->keyPath = $keyPath;
                self::renderNodeDestructive($request, $task, $children, -1);
                $prevSnapshot = self::$currentActiveSnapshot;
                if ($prevSnapshot === null) {
                    throw Js::error('Tried to pop a Context at the root of the app. This is a bug in React.');
                }
                self::setContextValue($prevSnapshot->context, $prevSnapshot->parentValue);
                self::$currentActiveSnapshot = $prevSnapshot->parent;
                $task->context = $prevSnapshot->parent;
                $task->keyPath = $prevKeyPath;
                return;
            }
            if ($typeof === ReactSymbols::$consumer) {
                $render = Js::get($props, 'children');
                $newChildren = Js::call($render, JsUndefined::instance(), [Js::get(Js::get($type, '_context'), self::$contextField)]);
                $prevKeyPath = $task->keyPath;
                $task->keyPath = $keyPath;
                self::renderNodeDestructive($request, $task, $newChildren, -1);
                $task->keyPath = $prevKeyPath;
                return;
            }
            if ($typeof === ReactSymbols::$lazy) {
                $component = Js::call(Js::get($type, '_init'), JsUndefined::instance(), [Js::get($type, '_payload')]);
                if ($request->aborted) {
                    Js::throw(JsNull::instance());
                }
                self::renderElement($request, $task, $keyPath, $component, $props, $ref);
                return;
            }
        }
        throw Js::error(
            'Element type is invalid: expected a string (for built-in components) or a class/function (for composite components) '
            . 'but got: ' . ($type instanceof JsUndefined || $type instanceof JsNull ? $type->display() : $type->typeof()) . '.',
        );
    }

    /** @param array<int, mixed> $keyPath */
    private static function renderHostElement(Request $request, Task $task, array $keyPath, string $type, JsObject $props): void
    {
        $segment = $task->blockedSegment;
        if ($segment === null) {
            $children = Js::get($props, 'children');
            $prevContext = $task->formatContext;
            $prevKeyPath = $task->keyPath;
            $task->formatContext = Dom::getChildFormatContext($prevContext, $type, $props);
            $task->keyPath = $keyPath;
            self::renderNode($request, $task, $children, -1);
            $task->formatContext = $prevContext;
            $task->keyPath = $prevKeyPath;
            return;
        }
        $children = Dom::pushStartInstance(
            $segment->chunks,
            $type,
            $props,
            $request->resumableState,
            $request->renderState,
            $task->blockedPreamble,
            $task->hoistableState,
            $task->formatContext,
            $segment->lastPushedText,
        );
        $segment->lastPushedText = false;
        $prevContext = $task->formatContext;
        $prevKeyPath = $task->keyPath;
        $task->keyPath = $keyPath;
        $task->formatContext = Dom::getChildFormatContext($prevContext, $type, $props);
        if ($task->formatContext->insertionMode === Dom::HTML_HEAD_MODE) {
            $preambleSegment = self::createPendingSegment($request, 0, null, $task->formatContext, false, false);
            $segment->preambleChildren[] = $preambleSegment;
            $task->blockedSegment = $preambleSegment;
            try {
                self::renderNode($request, $task, $children ?? JsNull::instance(), -1);
                Dom::pushSegmentFinale($preambleSegment, $request->renderState);
                $preambleSegment->status = Segment::COMPLETED;
                self::finishedSegment($request, $task->blockedBoundary, $preambleSegment);
            } finally {
                $task->blockedSegment = $segment;
            }
        } elseif ($children !== null) {
            self::renderNode($request, $task, $children, -1);
        }
        $task->formatContext = $prevContext;
        $task->keyPath = $prevKeyPath;
        switch ($type) {
            case 'title':
            case 'style':
            case 'script':
            case 'area':
            case 'base':
            case 'br':
            case 'col':
            case 'embed':
            case 'hr':
            case 'img':
            case 'input':
            case 'keygen':
            case 'link':
            case 'meta':
            case 'param':
            case 'source':
            case 'track':
            case 'wbr':
                $segment->lastPushedText = false;
                return;
            case 'body':
                if ($prevContext->insertionMode <= Dom::HTML_HTML_MODE) {
                    $request->resumableState->hasBody = true;
                    $segment->lastPushedText = false;
                    return;
                }
                break;
            case 'html':
                if ($prevContext->insertionMode === Dom::ROOT_HTML_MODE) {
                    $request->resumableState->hasHtml = true;
                    $segment->lastPushedText = false;
                    return;
                }
                break;
            case 'head':
                if ($prevContext->insertionMode <= Dom::HTML_HTML_MODE) {
                    $segment->lastPushedText = false;
                    return;
                }
                break;
        }
        $segment->chunks[] = '</' . $type . '>';
        $segment->lastPushedText = false;
    }

    /** @param array<int, mixed> $keyPath */
    private static function renderSpecialElement(Request $request, Task $task, array $keyPath, JsSymbol $type, JsObject $props): void
    {
        if (
            $type === ReactSymbols::$fragment
            || $type === ReactSymbols::$strictMode
            || $type === ReactSymbols::$profiler
            || $type === ReactSymbols::$legacyHidden
        ) {
            $prevKeyPath = $task->keyPath;
            $task->keyPath = $keyPath;
            self::renderNodeDestructive($request, $task, Js::get($props, 'children'), -1);
            $task->keyPath = $prevKeyPath;
            return;
        }
        if ($type === ReactSymbols::$activity) {
            $mode = Js::get($props, 'mode');
            $hidden = $mode instanceof JsString && $mode->value === 'hidden';
            $segment = $task->blockedSegment;
            if ($segment === null) {
                if (!$hidden) {
                    $prevKeyPath = $task->keyPath;
                    $task->keyPath = $keyPath;
                    self::renderNode($request, $task, Js::get($props, 'children'), -1);
                    $task->keyPath = $prevKeyPath;
                }
                return;
            }
            if ($hidden) {
                return;
            }
            if (!$request->renderState->generateStaticMarkup) {
                $segment->chunks[] = '<!--&-->';
            }
            $segment->lastPushedText = false;
            $prevKeyPath = $task->keyPath;
            $task->keyPath = $keyPath;
            self::renderNode($request, $task, Js::get($props, 'children'), -1);
            $task->keyPath = $prevKeyPath;
            if (!$request->renderState->generateStaticMarkup) {
                $segment->chunks[] = '<!--/&-->';
            }
            $segment->lastPushedText = false;
            return;
        }
        if ($type === ReactSymbols::$viewTransition) {
            self::renderViewTransition($request, $task, $keyPath, $props);
            return;
        }
        if ($type === ReactSymbols::$scope) {
            throw Js::error('ReactDOMServer does not yet support scope components.');
        }
        if ($type === ReactSymbols::$suspense) {
            self::renderSuspenseBoundary($request, $task, $keyPath, $props);
            return;
        }
        if ($type === ReactSymbols::$suspenseList) {
            // SuspenseList is not exported by React 19.3; its rows render in order.
            $prevKeyPath = $task->keyPath;
            $task->keyPath = $keyPath;
            self::renderNodeDestructive($request, $task, Js::get($props, 'children'), -1);
            $task->keyPath = $prevKeyPath;
            return;
        }
        throw Js::error(
            'Element type is invalid: expected a string (for built-in components) or a class/function (for composite components) but got: symbol.',
        );
    }

    /** @param array<int, mixed> $keyPath */
    private static function renderViewTransition(Request $request, Task $task, array $keyPath, JsObject $props): void
    {
        $prevContext = $task->formatContext;
        $prevKeyPath = $task->keyPath;
        $name = Js::get($props, 'name');
        $named = !Js::isNullish($name) && !($name instanceof JsString && $name->value === 'auto');
        if ($request->legacy) {
            if (!$named) {
                Dom::makeId($request->resumableState, self::getTreeId($task->treeContext), 0);
            }
        } else {
            $autoName = $named ? Js::str($name) : Dom::makeId($request->resumableState, self::getTreeId($task->treeContext), 0);
            $resumableState = $request->resumableState;
            $default = Js::get($props, 'default');
            $update = Dom::getViewTransitionClassName($default, Js::get($props, 'update')) ?? 'auto';
            $enter = Dom::getViewTransitionClassName($default, Js::get($props, 'enter')) ?? 'auto';
            $exit = Dom::getViewTransitionClassName($default, Js::get($props, 'exit')) ?? 'auto';
            $share = Dom::getViewTransitionClassName($default, Js::get($props, 'share'));
            if (Js::isNullish($name)) {
                $parent = $prevContext->viewTransition;
                if ($parent !== null) {
                    $vtName = $parent->name;
                    $share = $parent->share;
                } else {
                    $vtName = 'auto';
                    $share = 'none';
                }
            } else {
                $vtName = Js::str($name);
                $share ??= 'auto';
                if (($prevContext->tagScope & 4) !== 0) {
                    $resumableState->instructions |= 128;
                }
            }
            if (($prevContext->tagScope & 8) !== 0) {
                $resumableState->instructions |= 128;
            } else {
                $exit = 'none';
            }
            if (($prevContext->tagScope & 16) !== 0) {
                $resumableState->instructions |= 128;
            } else {
                $enter = 'none';
            }
            $viewTransition = new ViewTransitionState($update, $enter, $exit, $share, 'none', 'none', $vtName, $autoName, 0);
            $subtreeScope = $prevContext->tagScope & -25;
            $subtreeScope = $update !== 'none' ? $subtreeScope | 32 : $subtreeScope & -33;
            if ($enter !== 'none') {
                $subtreeScope |= 64;
            }
            $task->formatContext = new FormatContext($prevContext->insertionMode, $prevContext->selectedValue, $subtreeScope, $viewTransition);
        }
        $task->keyPath = $keyPath;
        if ($named) {
            self::renderNodeDestructive($request, $task, Js::get($props, 'children'), -1);
        } else {
            $prevTreeContext = $task->treeContext;
            $task->treeContext = self::pushTreeContext($prevTreeContext, 1, 0);
            self::renderNode($request, $task, Js::get($props, 'children'), -1);
            $task->treeContext = $prevTreeContext;
        }
        $task->formatContext = $prevContext;
        $task->keyPath = $prevKeyPath;
    }

    /** @param array<int, mixed> $keyPath */
    private static function renderSuspenseBoundary(Request $request, Task $task, array $keyPath, JsObject $props): void
    {
        $prevKeyPath = $task->keyPath;
        $prevContext = $task->formatContext;
        $parentBoundary = $task->blockedBoundary;
        $parentPreamble = $task->blockedPreamble;
        $parentHoistableState = $task->hoistableState;
        $parentSegment = $task->blockedSegment;
        if ($parentSegment === null) {
            throw Js::error('Suspense needs a segment to render into. This is a bug in Phasis.');
        }
        $fallback = Js::get($props, 'fallback');
        $content = Js::get($props, 'children');
        $fallbackAbortSet = new TaskSet();
        $preamble = !$request->legacy && $task->formatContext->insertionMode < Dom::HTML_MODE ? new BoundaryPreamble() : null;
        $newBoundary = self::createSuspenseBoundary($request, $fallbackAbortSet, $preamble, false);
        $boundarySegment = self::createPendingSegment($request, count($parentSegment->chunks), $newBoundary, $task->formatContext, false, false);
        $parentSegment->children[] = $boundarySegment;
        $parentSegment->lastPushedText = false;
        $contentRootSegment = self::createPendingSegment($request, 0, null, $task->formatContext, false, false);
        $contentRootSegment->parentFlushed = true;
        $fallbackKeyPath = [$keyPath[0], 'Suspense Fallback', $keyPath[2]];
        if ($request->trackedPostpones) {
            $task->blockedSegment = $boundarySegment;
            $task->blockedPreamble = $preamble?->fallback;
            $task->keyPath = $fallbackKeyPath;
            $task->formatContext = Dom::getSuspenseFallbackFormatContext($request->resumableState, $prevContext);
            try {
                self::renderNode($request, $task, $fallback, -1);
                Dom::pushSegmentFinale($boundarySegment, $request->renderState);
                $boundarySegment->status = Segment::COMPLETED;
                self::finishedSegment($request, $parentBoundary, $boundarySegment);
            } catch (\Throwable $e) {
                $boundarySegment->status = $request->aborted ? Segment::ABORTED : Segment::ERRORED;
                throw $e;
            } finally {
                $task->blockedSegment = $parentSegment;
                $task->blockedPreamble = $parentPreamble;
                $task->keyPath = $prevKeyPath;
                $task->formatContext = $prevContext;
            }
            $primaryTask = self::createRenderTask(
                $request,
                null,
                $content,
                -1,
                $newBoundary,
                $contentRootSegment,
                $preamble?->content,
                $newBoundary->contentState,
                $task->abortSet,
                $keyPath,
                Dom::getSuspenseContentFormatContext($request->resumableState, $task->formatContext),
                $task->context,
                $task->treeContext,
            );
            $request->pingedTasks[] = $primaryTask;
            return;
        }
        $task->blockedBoundary = $newBoundary;
        $task->blockedPreamble = $preamble?->content;
        $task->hoistableState = $newBoundary->contentState;
        $task->blockedSegment = $contentRootSegment;
        $task->keyPath = $keyPath;
        $task->formatContext = Dom::getSuspenseContentFormatContext($request->resumableState, $prevContext);
        $completedInline = false;
        try {
            self::renderNode($request, $task, $content, -1);
            Dom::pushSegmentFinale($contentRootSegment, $request->renderState);
            $contentRootSegment->status = Segment::COMPLETED;
            self::finishedSegment($request, $newBoundary, $contentRootSegment);
            self::queueCompletedSegment($newBoundary, $contentRootSegment);
            if ($newBoundary->pendingTasks === 0 && $newBoundary->status === Boundary::PENDING) {
                $newBoundary->status = Boundary::COMPLETED;
                if (!self::isEligibleForOutlining($request, $newBoundary)) {
                    if ($request->pendingRootTasks === 0 && $task->blockedPreamble !== null) {
                        self::preparePreamble($request);
                    }
                    $completedInline = true;
                }
            }
        } catch (\Throwable $e) {
            $thrown = Js::thrownValue($e);
            $newBoundary->status = Boundary::CLIENT_RENDERED;
            if ($request->aborted) {
                $contentRootSegment->status = Segment::ABORTED;
                $error = $request->fatalError ?? JsUndefined::instance();
            } else {
                $contentRootSegment->status = Segment::ERRORED;
                $error = $thrown;
            }
            $newBoundary->errorDigest = self::logRecoverableError($request, $error);
        } finally {
            $task->blockedBoundary = $parentBoundary;
            $task->blockedPreamble = $parentPreamble;
            $task->hoistableState = $parentHoistableState;
            $task->blockedSegment = $parentSegment;
            $task->keyPath = $prevKeyPath;
            $task->formatContext = $prevContext;
        }
        if ($completedInline) {
            return;
        }
        $fallbackTask = self::createRenderTask(
            $request,
            null,
            $fallback,
            -1,
            $parentBoundary,
            $boundarySegment,
            $preamble?->fallback,
            $newBoundary->fallbackState,
            $fallbackAbortSet,
            $fallbackKeyPath,
            Dom::getSuspenseFallbackFormatContext($request->resumableState, $task->formatContext),
            $task->context,
            $task->treeContext,
        );
        $request->pingedTasks[] = $fallbackTask;
    }

    private static function renderNodeDestructive(Request $request, Task $task, JsValue $node, int $childIndex): void
    {
        $task->node = $node;
        $task->childIndex = $childIndex;
        self::retryNode($request, $task);
    }

    private static function retryNode(Request $request, Task $task): void
    {
        $node = $task->node;
        $childIndex = $task->childIndex;
        if ($node instanceof JsString) {
            $segment = $task->blockedSegment;
            if ($segment !== null) {
                $segment->lastPushedText = Dom::pushTextInstance($segment->chunks, $node->value, $request->renderState, $segment->lastPushedText);
            }
            return;
        }
        if ($node instanceof JsNumber || $node instanceof JsBigInt) {
            $segment = $task->blockedSegment;
            if ($segment !== null) {
                $segment->lastPushedText = Dom::pushTextInstance($segment->chunks, Js::str($node), $request->renderState, $segment->lastPushedText);
            }
            return;
        }
        if (!$node instanceof JsObject || $node instanceof JsFunction) {
            return;
        }
        $typeof = $node->properties->dataSlots['$$typeof'] ?? Js::get($node, '$$typeof');
        if ($typeof === ReactSymbols::$element) {
            $type = Js::get($node, 'type');
            $key = Js::get($node, 'key');
            $props = Js::get($node, 'props');
            if (!$props instanceof JsObject) {
                $props = Js::object();
            }
            $refProp = Js::get($props, 'ref');
            $ref = $refProp instanceof JsUndefined ? JsNull::instance() : $refProp;
            $name = self::getComponentNameFromType($type);
            $keyOrIndex = Js::isNullish($key) || $key === ReactSymbols::$optimisticKey
                ? ($childIndex === -1 ? 0 : $childIndex)
                : Js::str($key);
            self::renderElement($request, $task, [$task->keyPath, $name, $keyOrIndex], $type, $props, $ref);
            return;
        }
        if ($typeof === ReactSymbols::$portal) {
            throw Js::error(
                'Portals are not currently supported by the server renderer. Render them conditionally so that they '
                . 'only appear on the client render.',
            );
        }
        if ($typeof === ReactSymbols::$lazy) {
            $resolved = Js::call(Js::get($node, '_init'), JsUndefined::instance(), [Js::get($node, '_payload')]);
            if ($request->aborted) {
                Js::throw(JsNull::instance());
            }
            self::renderNodeDestructive($request, $task, $resolved, $childIndex);
            return;
        }
        if (Js::isArray($node)) {
            self::renderChildrenArray($request, $task, Js::elements($node), $childIndex);
            return;
        }
        $iteratorFn = ReactModule::getIteratorFn($node);
        if ($iteratorFn !== null) {
            $iterator = Js::call($iteratorFn, $node);
            if ($iterator->toBoolean()) {
                $step = Js::call(Js::get($iterator, 'next'), $iterator);
                if (!Js::get($step, 'done')->toBoolean()) {
                    $children = [];
                    do {
                        $children[] = Js::get($step, 'value');
                        $step = Js::call(Js::get($iterator, 'next'), $iterator);
                    } while (!Js::get($step, 'done')->toBoolean());
                    self::renderChildrenArray($request, $task, $children, $childIndex);
                }
                return;
            }
        }
        if (Js::get($node, 'then') instanceof JsFunction) {
            $task->thenableState = null;
            self::renderNodeDestructive($request, $task, Hooks::unwrapThenable($node), $childIndex);
            return;
        }
        if ($typeof === ReactSymbols::$context) {
            self::renderNodeDestructive($request, $task, Js::get($node, self::$contextField), $childIndex);
            return;
        }
        $tag = Js::objectTag($node);
        throw Js::error(
            'Objects are not valid as a React child (found: '
            . ($tag === '[object Object]' ? 'object with keys {' . implode(', ', Js::ownEnumerableKeys($node)) . '}' : $tag)
            . '). If you meant to render a collection of children, use an array instead.'
        );
    }

    /** @param list<JsValue> $children */
    private static function renderChildrenArray(Request $request, Task $task, array $children, int $childIndex): void
    {
        $prevKeyPath = $task->keyPath;
        if ($childIndex !== -1) {
            $task->keyPath = [$task->keyPath, 'Fragment', $childIndex];
        }
        $prevTreeContext = $task->treeContext;
        $total = count($children);
        foreach ($children as $i => $child) {
            $task->treeContext = self::pushTreeContext($prevTreeContext, $total, $i);
            self::renderNode($request, $task, $child, $i);
        }
        $task->treeContext = $prevTreeContext;
        $task->keyPath = $prevKeyPath;
    }

    /** @param list<JsValue>|null $thenableState */
    private static function spawnNewSuspendedRenderTask(Request $request, Task $task, ?array $thenableState): Task
    {
        $segment = $task->blockedSegment;
        if ($segment === null) {
            throw Js::error('A suspended task needs a segment. This is a bug in Phasis.');
        }
        $newSegment = self::createPendingSegment($request, count($segment->chunks), null, $task->formatContext, $segment->lastPushedText, true);
        $segment->children[] = $newSegment;
        $segment->lastPushedText = false;
        return self::createRenderTask(
            $request,
            $thenableState,
            $task->node,
            $task->childIndex,
            $task->blockedBoundary,
            $newSegment,
            $task->blockedPreamble,
            $task->hoistableState,
            $task->abortSet,
            $task->keyPath,
            $task->formatContext,
            $task->context,
            $task->treeContext,
        );
    }

    private static function renderNode(Request $request, Task $task, JsValue $node, int $childIndex): void
    {
        $previousFormatContext = $task->formatContext;
        $previousContext = $task->context;
        $previousKeyPath = $task->keyPath;
        $previousTreeContext = $task->treeContext;
        $segment = $task->blockedSegment;
        if ($segment === null) {
            throw Js::error('Replaying is not supported by Phasis.');
        }
        $childrenLength = count($segment->children);
        $chunkLength = count($segment->chunks);
        try {
            self::renderNodeDestructive($request, $task, $node, $childIndex);
            return;
        } catch (\Throwable $e) {
            $thrownValue = Js::thrownValue($e);
            Hooks::resetHooksState();
            if (count($segment->children) > $childrenLength) {
                array_splice($segment->children, $childrenLength);
            }
            if (count($segment->chunks) > $chunkLength) {
                array_splice($segment->chunks, $chunkLength);
            }
            $isSuspense = $thrownValue === self::suspenseException();
            $x = $isSuspense ? self::getSuspendedThenable() : $thrownValue;
            if (!$request->aborted && $x instanceof JsObject && !$x instanceof JsFunction) {
                if (Js::get($x, 'then') instanceof JsFunction) {
                    $thenableState = $isSuspense ? Hooks::getThenableStateAfterSuspending() : null;
                    $newTask = self::spawnNewSuspendedRenderTask($request, $task, $thenableState);
                    Js::call(Js::get($x, 'then'), $x, [$newTask->pingResolve, $newTask->pingReject]);
                    $task->formatContext = $previousFormatContext;
                    $task->context = $previousContext;
                    $task->keyPath = $previousKeyPath;
                    $task->treeContext = $previousTreeContext;
                    self::switchContext($previousContext);
                    return;
                }
                if (Js::get($x, 'message') instanceof JsString && Js::str(Js::get($x, 'message')) === 'Maximum call stack size exceeded') {
                    $thenableState = $isSuspense ? Hooks::getThenableStateAfterSuspending() : null;
                    $request->pingedTasks[] = self::spawnNewSuspendedRenderTask($request, $task, $thenableState);
                    $task->formatContext = $previousFormatContext;
                    $task->context = $previousContext;
                    $task->keyPath = $previousKeyPath;
                    $task->treeContext = $previousTreeContext;
                    self::switchContext($previousContext);
                    return;
                }
            }
            $task->formatContext = $previousFormatContext;
            $task->context = $previousContext;
            $task->keyPath = $previousKeyPath;
            $task->treeContext = $previousTreeContext;
            self::switchContext($previousContext);
            if ($x === $thrownValue) {
                throw $e;
            }
            Js::throw($x);
        }
    }

    public static function getComponentNameFromType(JsValue $type): ?string
    {
        if (Js::isNullish($type)) {
            return null;
        }
        if ($type instanceof JsFunction) {
            if (Js::get($type, '$$typeof') === ReactSymbols::$clientReference) {
                return null;
            }
            $displayName = Js::get($type, 'displayName');
            if ($displayName->toBoolean()) {
                return Js::str($displayName);
            }
            $name = Js::get($type, 'name');
            return $name->toBoolean() ? Js::str($name) : null;
        }
        if ($type instanceof JsString) {
            return $type->value;
        }
        if ($type instanceof JsSymbol) {
            return match ($type) {
                ReactSymbols::$fragment => 'Fragment',
                ReactSymbols::$profiler => 'Profiler',
                ReactSymbols::$strictMode => 'StrictMode',
                ReactSymbols::$suspense => 'Suspense',
                ReactSymbols::$suspenseList => 'SuspenseList',
                ReactSymbols::$activity => 'Activity',
                ReactSymbols::$viewTransition => 'ViewTransition',
                default => null,
            };
        }
        if ($type instanceof JsObject) {
            $typeof = Js::get($type, '$$typeof');
            switch ($typeof) {
                case ReactSymbols::$portal:
                    return 'Portal';
                case ReactSymbols::$context:
                    $displayName = Js::get($type, 'displayName');
                    return $displayName->toBoolean() ? Js::str($displayName) : 'Context';
                case ReactSymbols::$consumer:
                    $displayName = Js::get(Js::get($type, '_context'), 'displayName');
                    return ($displayName->toBoolean() ? Js::str($displayName) : 'Context') . '.Consumer';
                case ReactSymbols::$forwardRef:
                    $displayName = Js::get($type, 'displayName');
                    if ($displayName->toBoolean()) {
                        return Js::str($displayName);
                    }
                    $inner = Js::get($type, 'render');
                    $innerName = Js::get($inner, 'displayName');
                    if (!$innerName->toBoolean()) {
                        $innerName = Js::get($inner, 'name');
                    }
                    $innerString = $innerName->toBoolean() ? Js::str($innerName) : '';
                    return $innerString !== '' ? 'ForwardRef(' . $innerString . ')' : 'ForwardRef';
                case ReactSymbols::$memo:
                    $displayName = Js::get($type, 'displayName');
                    if ($displayName->toBoolean()) {
                        return Js::str($displayName);
                    }
                    return self::getComponentNameFromType(Js::get($type, 'type')) ?? 'Memo';
                case ReactSymbols::$lazy:
                    try {
                        return self::getComponentNameFromType(
                            Js::call(Js::get($type, '_init'), JsUndefined::instance(), [Js::get($type, '_payload')]),
                        );
                    } catch (\Throwable $e) {
                        Js::thrownValue($e);
                        return null;
                    }
            }
        }
        return null;
    }

    // Task completion.

    private static function abortTaskSoft(Request $request, Task $task): void
    {
        $segment = $task->blockedSegment;
        if ($segment !== null) {
            $segment->status = Segment::ABORTED;
            self::finishedTask($request, $task->blockedBoundary, $segment);
        }
    }

    private static function abortTask(Task $task, Request $request): void
    {
        if ($task === $request->currentTask) {
            return;
        }
        $boundary = $task->blockedBoundary;
        if ($task->blockedSegment !== null) {
            $task->blockedSegment->status = Segment::ABORTED;
        }
        if ($boundary !== null) {
            foreach ($boundary->fallbackAbortableTasks->all() as $fallbackTask) {
                self::abortTask($fallbackTask, $request);
            }
        }
    }

    private static function finishAbortedTask(Task $task, Request $request, JsValue $error): void
    {
        if ($task === $request->currentTask) {
            return;
        }
        $boundary = $task->blockedBoundary;
        $segment = $task->blockedSegment;
        if ($segment !== null && $segment->status !== Segment::ABORTED) {
            return;
        }
        $isRecoverableReason = self::isRecoverableError($error);
        if ($boundary === null) {
            if ($isRecoverableReason && $error instanceof JsObject) {
                $fatal = self::cloneRecoverableErrorAsFatal($error);
                self::logRecoverableError($request, $fatal);
                if ($request->status !== Request::ABORTING && $request->status !== Request::CLOSED) {
                    self::fatalError($request, $fatal);
                }
            } else {
                self::logRecoverableError($request, $error);
                if ($request->status !== Request::ABORTING && $request->status !== Request::CLOSED) {
                    self::fatalError($request, $error);
                }
            }
            return;
        }
        if ($boundary->status !== Boundary::CLIENT_RENDERED) {
            $boundary->status = Boundary::CLIENT_RENDERED;
            $boundary->errorDigest = self::logRecoverableError($request, $error);
            if ($boundary->parentFlushed) {
                $request->clientRenderedBoundaries[] = $boundary;
            }
        }
        $boundary->pendingTasks--;
        foreach ($boundary->fallbackAbortableTasks->all() as $fallbackTask) {
            self::finishAbortedTask($fallbackTask, $request, $error);
        }
        $boundary->fallbackAbortableTasks->clear();
        $request->allPendingTasks--;
        if ($request->allPendingTasks === 0) {
            self::completeAll($request);
        }
    }

    private static function completeShell(Request $request): void
    {
        if (!$request->trackedPostpones) {
            self::preparePreamble($request);
        }
        ($request->onShellReady)();
    }

    private static function completeAll(Request $request): void
    {
        self::preparePreamble($request);
        ($request->onAllReady)();
    }

    private static function queueCompletedSegment(Boundary $boundary, Segment $segment): void
    {
        if (
            $segment->chunks === []
            && count($segment->children) === 1
            && $segment->children[0]->boundary === null
            && $segment->children[0]->id === -1
        ) {
            $childSegment = $segment->children[0];
            $childSegment->id = $segment->id;
            $childSegment->parentFlushed = true;
            if (
                $childSegment->status === Segment::COMPLETED
                || $childSegment->status === Segment::ABORTED
                || $childSegment->status === Segment::ERRORED
            ) {
                self::queueCompletedSegment($boundary, $childSegment);
            }
        } else {
            $boundary->completedSegments[] = $segment;
        }
    }

    private static function finishedSegment(Request $request, ?Boundary $boundary, Segment $segment): void
    {
        if ($request->legacy) {
            return;
        }
        $size = 0;
        foreach ($segment->chunks as $chunk) {
            $size += strlen($chunk);
        }
        if ($boundary === null) {
            $request->byteSize += $size;
        } else {
            $boundary->byteSize += $size;
        }
    }

    private static function isEligibleForOutlining(Request $request, Boundary $boundary): bool
    {
        if ($request->legacy) {
            return ($boundary->byteSize > 500 || $boundary->defer) && $boundary->preamble === null;
        }
        return ($boundary->byteSize > 500 || Resources::hasSuspenseyContent($boundary->contentState, false) || $boundary->defer)
            && $boundary->preamble === null;
    }

    private static function finishedTask(Request $request, ?Boundary $boundary, ?Segment $segment): void
    {
        $request->allPendingTasks--;
        if ($boundary === null) {
            if ($segment !== null && $segment->parentFlushed) {
                if ($request->completedRootSegment !== null) {
                    throw Js::error('There can only be one root segment. This is a bug in React.');
                }
                $request->completedRootSegment = $segment;
            }
            $request->pendingRootTasks--;
            if ($request->pendingRootTasks === 0) {
                self::completeShell($request);
            }
        } else {
            $boundary->pendingTasks--;
            if ($boundary->status !== Boundary::CLIENT_RENDERED) {
                if ($boundary->pendingTasks === 0) {
                    if ($boundary->status === Boundary::PENDING) {
                        $boundary->status = Boundary::COMPLETED;
                    }
                    if (
                        $segment !== null
                        && $segment->parentFlushed
                        && ($segment->status === Segment::COMPLETED || $segment->status === Segment::ABORTED)
                    ) {
                        self::queueCompletedSegment($boundary, $segment);
                    }
                    if ($boundary->parentFlushed) {
                        $request->completedBoundaries[] = $boundary;
                    }
                    if ($boundary->status === Boundary::COMPLETED) {
                        if (!self::isEligibleForOutlining($request, $boundary)) {
                            $request->allPendingTasks++;
                            foreach ($boundary->fallbackAbortableTasks->all() as $fallbackTask) {
                                self::abortTaskSoft($request, $fallbackTask);
                            }
                            $boundary->fallbackAbortableTasks->clear();
                            $request->allPendingTasks--;
                        }
                        if ($request->pendingRootTasks === 0 && !$request->trackedPostpones && $boundary->preamble !== null) {
                            self::preparePreamble($request);
                        }
                    }
                } elseif (
                    $segment !== null
                    && $segment->parentFlushed
                    && ($segment->status === Segment::COMPLETED || $segment->status === Segment::ABORTED)
                ) {
                    self::queueCompletedSegment($boundary, $segment);
                    if (count($boundary->completedSegments) === 1 && $boundary->parentFlushed) {
                        $request->partialBoundaries[] = $boundary;
                    }
                }
            }
        }
        if ($request->allPendingTasks === 0) {
            self::completeAll($request);
        }
    }

    // The work loop.

    public static function performWork(Request $request): void
    {
        if ($request->aborted || $request->status > Request::OPEN) {
            return;
        }
        $prevContext = self::$currentActiveSnapshot;
        $prevDispatcher = ReactModule::$dispatcher;
        ReactModule::$dispatcher = Hooks::dispatcher();
        $prevRequest = self::$currentRequest;
        self::$currentRequest = $request;
        $prevResumableState = Hooks::$currentResumableState;
        Hooks::$currentResumableState = $request->resumableState;
        $prevContextField = self::$contextField;
        self::$contextField = $request->legacy ? '_currentValue2' : '_currentValue';
        try {
            $pingedTasks = &$request->pingedTasks;
            for ($i = 0; $i < count($pingedTasks); $i++) {
                self::retryTask($request, $pingedTasks[$i]);
            }
            array_splice($pingedTasks, 0, $i);
            if ($request->destination !== null) {
                self::flushCompletedQueues($request, $request->destination);
            }
        } catch (\Throwable $e) {
            $error = Js::thrownValue($e);
            self::logRecoverableError($request, $error);
            self::fatalError($request, $error);
        } finally {
            Hooks::$currentResumableState = $prevResumableState;
            ReactModule::$dispatcher = $prevDispatcher;
            if ($prevDispatcher !== null) {
                self::switchContext($prevContext);
            }
            self::$currentRequest = $prevRequest;
            self::$contextField = $prevContextField;
        }
    }

    private static function retryTask(Request $request, Task $task): void
    {
        $segment = $task->blockedSegment;
        if ($segment === null || $segment->status !== Segment::PENDING) {
            return;
        }
        $prevTask = $request->currentTask;
        $request->currentTask = $task;
        self::switchContext($task->context);
        $childrenLength = count($segment->children);
        $chunkLength = count($segment->chunks);
        $startNode = $task->node;
        try {
            self::retryNode($request, $task);
            Dom::pushSegmentFinale($segment, $request->renderState);
            $task->abortSet->delete($task);
            $segment->status = Segment::COMPLETED;
            self::finishedSegment($request, $task->blockedBoundary, $segment);
            self::finishedTask($request, $task->blockedBoundary, $segment);
        } catch (\Throwable $e) {
            $thrownValue = Js::thrownValue($e);
            Hooks::resetHooksState();
            if (count($segment->children) > $childrenLength) {
                array_splice($segment->children, $childrenLength);
            }
            if (count($segment->chunks) > $chunkLength) {
                array_splice($segment->chunks, $chunkLength);
            }
            $isSuspense = $thrownValue === self::suspenseException();
            $x = $isSuspense ? self::getSuspendedThenable() : $thrownValue;
            if ($request->aborted) {
                if ($isSuspense) {
                    $task->thenableState = Hooks::getThenableStateAfterSuspending();
                }
                $request->currentTask = $prevTask;
                self::abortTask($task, $request);
                $task->abortSet->delete($task);
                self::finishAbortedTask($task, $request, $request->fatalError ?? JsUndefined::instance());
                return;
            }
            if ($x instanceof JsObject && !$x instanceof JsFunction) {
                if (Js::get($x, 'then') instanceof JsFunction) {
                    $segment->status = Segment::PENDING;
                    $task->thenableState = $isSuspense ? Hooks::getThenableStateAfterSuspending() : null;
                    Js::call(Js::get($x, 'then'), $x, [$task->pingResolve, $task->pingReject]);
                    return;
                }
                $message = Js::get($x, 'message');
                if ($message instanceof JsString && $message->value === 'Maximum call stack size exceeded' && $task->node !== $startNode) {
                    $segment->status = Segment::PENDING;
                    $task->thenableState = null;
                    $request->pingedTasks[] = $task;
                    return;
                }
            }
            $task->abortSet->delete($task);
            $segment->status = Segment::ERRORED;
            $boundary = $task->blockedBoundary;
            $request->allPendingTasks--;
            if ($boundary === null) {
                if (self::isRecoverableError($x) && $x instanceof JsObject) {
                    $fatal = self::cloneRecoverableErrorAsFatal($x);
                    self::logRecoverableError($request, $fatal);
                    self::fatalError($request, $fatal);
                } else {
                    self::logRecoverableError($request, $x);
                    self::fatalError($request, $x);
                }
            } else {
                $digest = self::logRecoverableError($request, $x);
                $boundary->pendingTasks--;
                if ($boundary->status !== Boundary::CLIENT_RENDERED) {
                    $boundary->status = Boundary::CLIENT_RENDERED;
                    $boundary->errorDigest = $digest;
                    if ($boundary->parentFlushed) {
                        $request->clientRenderedBoundaries[] = $boundary;
                    }
                    if ($request->pendingRootTasks === 0 && !$request->trackedPostpones && $boundary->preamble !== null) {
                        self::preparePreamble($request);
                    }
                }
                if ($request->allPendingTasks === 0) {
                    self::completeAll($request);
                }
            }
        } finally {
            $request->currentTask = $prevTask;
        }
    }

    // Preamble.

    /** @param list<list<Segment>> $collected */
    private static function preparePreambleFromSubtree(Request $request, Segment $segment, array &$collected): bool
    {
        if ($segment->preambleChildren !== []) {
            $collected[] = $segment->preambleChildren;
        }
        $pending = false;
        foreach ($segment->children as $child) {
            $pending = self::preparePreambleFromSegment($request, $child, $collected) || $pending;
        }
        return $pending;
    }

    /** @param list<list<Segment>> $collected */
    private static function preparePreambleFromSegment(Request $request, Segment $segment, array &$collected): bool
    {
        $boundary = $segment->boundary;
        if ($boundary === null) {
            return self::preparePreambleFromSubtree($request, $segment, $collected);
        }
        $preamble = $boundary->preamble;
        if ($preamble === null) {
            return false;
        }
        switch ($boundary->status) {
            case Boundary::COMPLETED:
                Dom::hoistPreambleState($request->renderState, $preamble->content);
                $request->byteSize += $boundary->byteSize;
                $root = $boundary->completedSegments[0] ?? null;
                if ($root === null) {
                    throw Js::error('A previously unvisited boundary must have exactly one root segment. This is a bug in React.');
                }
                return self::preparePreambleFromSubtree($request, $root, $collected);
            case Boundary::POSTPONED:
                if ($request->trackedPostpones) {
                    return true;
                }
                // fall through
            case Boundary::CLIENT_RENDERED:
                if ($segment->status === Segment::COMPLETED) {
                    Dom::hoistPreambleState($request->renderState, $preamble->fallback);
                    return self::preparePreambleFromSubtree($request, $segment, $collected);
                }
                // fall through
            default:
                return true;
        }
    }

    private static function preparePreamble(Request $request): void
    {
        if ($request->completedRootSegment === null || $request->completedPreambleSegments !== null) {
            return;
        }
        $collected = [];
        $originalByteSize = $request->byteSize;
        $hasPendingPreambles = self::preparePreambleFromSegment($request, $request->completedRootSegment, $collected);
        $preamble = $request->renderState->preamble;
        if ($hasPendingPreambles === false || ($preamble->headChunks !== null && $preamble->bodyChunks !== null)) {
            $request->completedPreambleSegments = $collected;
        } else {
            $request->byteSize = $originalByteSize;
        }
    }

    // Flushing.

    private static function flushSubtree(Request $request, Destination $destination, Segment $segment, ?HoistableState $hoistableState): void
    {
        $segment->parentFlushed = true;
        switch ($segment->status) {
            case Segment::PENDING:
                $segment->id = $request->nextSegmentId++;
                // fall through
            case Segment::POSTPONED:
                $segment->lastPushedText = false;
                $segment->textEmbedded = false;
                $destination->buffer .= '<template id="' . $request->renderState->placeholderPrefix . dechex($segment->id) . '"></template>';
                return;
            case Segment::COMPLETED:
                $segment->status = Segment::FLUSHED;
                $chunks = $segment->chunks;
                $chunkIdx = 0;
                $buffer = '';
                foreach ($segment->children as $child) {
                    for (; $chunkIdx < $child->index; $chunkIdx++) {
                        $buffer .= $chunks[$chunkIdx];
                    }
                    $destination->buffer .= $buffer;
                    $buffer = '';
                    self::flushSegment($request, $destination, $child, $hoistableState);
                }
                $count = count($chunks);
                for (; $chunkIdx < $count; $chunkIdx++) {
                    $buffer .= $chunks[$chunkIdx];
                }
                $destination->buffer .= $buffer;
                return;
            case Segment::ABORTED:
                return;
            default:
                throw Js::error('Aborted, errored or already flushed boundaries should not be flushed again. This is a bug in React.');
        }
    }

    private static function flushSegment(Request $request, Destination $destination, Segment $segment, ?HoistableState $hoistableState): void
    {
        $boundary = $segment->boundary;
        if ($boundary === null) {
            self::flushSubtree($request, $destination, $segment, $hoistableState);
            return;
        }
        $segment->boundary = null;
        $boundary->parentFlushed = true;
        $static = $request->renderState->generateStaticMarkup;
        if ($boundary->status === Boundary::CLIENT_RENDERED) {
            if (!$static) {
                $destination->buffer .= '<!--$!--><template';
                if ($boundary->errorDigest !== null) {
                    $destination->buffer .= ' data-dgst="' . Dom::escapeText($boundary->errorDigest) . '"';
                }
                $destination->buffer .= '></template>';
            }
            self::flushSubtree($request, $destination, $segment, $hoistableState);
            if (!$static) {
                $destination->buffer .= '<!--/$-->';
            }
            return;
        }
        if ($boundary->status !== Boundary::COMPLETED) {
            if ($boundary->status === Boundary::PENDING) {
                $boundary->rootSegmentID = $request->nextSegmentId++;
            }
            if ($boundary->completedSegments !== []) {
                $request->partialBoundaries[] = $boundary;
            }
            self::writeStartPendingSuspenseBoundary($destination, $request->renderState, $boundary->rootSegmentID);
            if ($hoistableState !== null) {
                Resources::hoistHoistables($hoistableState, $boundary->fallbackState);
            }
            self::flushSubtree($request, $destination, $segment, $hoistableState);
            $destination->buffer .= '<!--/$-->';
            return;
        }
        if (
            !self::$flushingPartialBoundaries
            && self::isEligibleForOutlining($request, $boundary)
            && (
                self::$flushedByteSize + $boundary->byteSize > $request->progressiveChunkSize
                || (!$request->legacy && Resources::hasSuspenseyContent($boundary->contentState, self::$flushingShell))
                || $boundary->defer
            )
        ) {
            $boundary->rootSegmentID = $request->nextSegmentId++;
            $request->completedBoundaries[] = $boundary;
            self::writeStartPendingSuspenseBoundary($destination, $request->renderState, $boundary->rootSegmentID);
            self::flushSubtree($request, $destination, $segment, $hoistableState);
            $destination->buffer .= '<!--/$-->';
            return;
        }
        self::$flushedByteSize += $boundary->byteSize;
        if ($hoistableState !== null) {
            Resources::hoistHoistables($hoistableState, $boundary->contentState);
        }
        if (!$static) {
            $destination->buffer .= '<!--$-->';
        }
        if (count($boundary->completedSegments) !== 1) {
            throw Js::error('A previously unvisited boundary must have exactly one root segment. This is a bug in React.');
        }
        self::flushSegment($request, $destination, $boundary->completedSegments[0], $hoistableState);
        if (!$static) {
            $destination->buffer .= '<!--/$-->';
        }
    }

    private static function writeStartPendingSuspenseBoundary(Destination $destination, RenderState $renderState, int $id): void
    {
        $destination->buffer .= '<!--$?--><template id="' . $renderState->boundaryPrefix . dechex($id) . '"></template>';
    }

    private static function writeStartSegment(Destination $destination, RenderState $renderState, FormatContext $formatContext, int $id): void
    {
        $id = $renderState->segmentPrefix . dechex($id);
        $destination->buffer .= match ($formatContext->insertionMode) {
            Dom::ROOT_HTML_MODE, Dom::HTML_HTML_MODE, Dom::HTML_HEAD_MODE, Dom::HTML_MODE => '<div hidden id="' . $id . '">',
            Dom::SVG_MODE => '<svg aria-hidden="true" style="display:none" id="' . $id . '">',
            Dom::MATHML_MODE => '<math aria-hidden="true" style="display:none" id="' . $id . '">',
            Dom::HTML_TABLE_MODE => '<table hidden id="' . $id . '">',
            Dom::HTML_TABLE_BODY_MODE => '<table hidden><tbody id="' . $id . '">',
            Dom::HTML_TABLE_ROW_MODE => '<table hidden><tr id="' . $id . '">',
            Dom::HTML_COLGROUP_MODE => '<table hidden><colgroup id="' . $id . '">',
            default => throw Js::error('Unknown insertion mode. This is a bug in React.'),
        };
    }

    private static function writeEndSegment(Destination $destination, FormatContext $formatContext): void
    {
        $destination->buffer .= match ($formatContext->insertionMode) {
            Dom::ROOT_HTML_MODE, Dom::HTML_HTML_MODE, Dom::HTML_HEAD_MODE, Dom::HTML_MODE => '</div>',
            Dom::SVG_MODE => '</svg>',
            Dom::MATHML_MODE => '</math>',
            Dom::HTML_TABLE_MODE => '</table>',
            Dom::HTML_TABLE_BODY_MODE => '</tbody></table>',
            Dom::HTML_TABLE_ROW_MODE => '</tr></table>',
            Dom::HTML_COLGROUP_MODE => '</colgroup></table>',
            default => throw Js::error('Unknown insertion mode. This is a bug in React.'),
        };
    }

    private static function flushSegmentContainer(Request $request, Destination $destination, Segment $segment, ?HoistableState $hoistableState): void
    {
        self::writeStartSegment($destination, $request->renderState, $segment->parentFormatContext, $segment->id);
        self::flushSegment($request, $destination, $segment, $hoistableState);
        self::writeEndSegment($destination, $segment->parentFormatContext);
    }

    private static function flushCompletedBoundary(Request $request, Destination $destination, Boundary $boundary): void
    {
        self::$flushedByteSize = $boundary->byteSize;
        foreach ($boundary->completedSegments as $segment) {
            self::flushPartiallyCompletedSegment($request, $destination, $boundary, $segment);
        }
        $boundary->completedSegments = [];
        Resources::writeHoistablesForBoundary($destination, $boundary->contentState, $request->renderState);
        $resumableState = $request->resumableState;
        $renderState = $request->renderState;
        $requiresStyleInsertion = $renderState->stylesToHoist;
        $requiresViewTransitions = ($resumableState->instructions & 128) !== 0;
        $renderState->stylesToHoist = false;
        $out = $renderState->startInlineScript . '>';
        if ($requiresStyleInsertion) {
            if (($resumableState->instructions & 4) === 0) {
                $resumableState->instructions |= 4;
                $out .= Scripts::CLIENT_RENDER_SCRIPT_FUNCTION_ONLY;
            }
            if (($resumableState->instructions & 2) === 0) {
                $resumableState->instructions |= 2;
                $out .= Scripts::COMPLETE_BOUNDARY_SCRIPT_FUNCTION_ONLY;
            }
            if ($requiresViewTransitions && ($resumableState->instructions & 256) === 0) {
                $resumableState->instructions |= 256;
                $out .= Scripts::VIEW_TRANSITION_SCRIPT;
            }
            if (($resumableState->instructions & 8) === 0) {
                $resumableState->instructions |= 8;
                $out .= Scripts::COMPLETE_BOUNDARY_WITH_STYLES_SCRIPT_1FULL_PARTIAL;
            } else {
                $out .= '$RR("';
            }
        } else {
            if (($resumableState->instructions & 2) === 0) {
                $resumableState->instructions |= 2;
                $out .= Scripts::COMPLETE_BOUNDARY_SCRIPT_FUNCTION_ONLY;
            }
            if ($requiresViewTransitions && ($resumableState->instructions & 256) === 0) {
                $resumableState->instructions |= 256;
                $out .= Scripts::VIEW_TRANSITION_SCRIPT;
            }
            $out .= '$RC("';
        }
        $id = dechex($boundary->rootSegmentID);
        $out .= $renderState->boundaryPrefix . $id . '","' . $renderState->segmentPrefix . $id;
        $destination->buffer .= $out;
        if ($requiresStyleInsertion) {
            $destination->buffer .= '",';
            Resources::writeStyleResourceDependenciesInJS($destination, $boundary->contentState);
        } else {
            $destination->buffer .= '"';
        }
        $destination->buffer .= ')</script>';
        self::writeBootstrap($destination, $renderState);
    }

    private static function flushPartiallyCompletedSegment(Request $request, Destination $destination, Boundary $boundary, Segment $segment): void
    {
        if ($segment->status === Segment::FLUSHED) {
            return;
        }
        $hoistableState = $boundary->contentState;
        $segmentID = $segment->id;
        if ($segmentID === -1) {
            $segment->id = $boundary->rootSegmentID;
            if ($segment->id === -1) {
                throw Js::error('A root segment ID must have been assigned by now. This is a bug in React.');
            }
            self::flushSegmentContainer($request, $destination, $segment, $hoistableState);
            return;
        }
        if ($segmentID === $boundary->rootSegmentID) {
            self::flushSegmentContainer($request, $destination, $segment, $hoistableState);
            return;
        }
        self::flushSegmentContainer($request, $destination, $segment, $hoistableState);
        $resumableState = $request->resumableState;
        $renderState = $request->renderState;
        $out = $renderState->startInlineScript . '>';
        if (($resumableState->instructions & 1) === 0) {
            $resumableState->instructions |= 1;
            $out .= Scripts::COMPLETE_SEGMENT_SCRIPT_1FULL;
        } else {
            $out .= '$RS("';
        }
        $hex = dechex($segmentID);
        $destination->buffer .= $out . $renderState->segmentPrefix . $hex . '","' . $renderState->placeholderPrefix . $hex . '")</script>';
    }

    private static function writeBootstrap(Destination $destination, RenderState $renderState): void
    {
        foreach ($renderState->bootstrapChunks as $chunk) {
            $destination->buffer .= $chunk;
        }
        $renderState->bootstrapChunks = [];
    }

    private static function flushCompletedQueues(Request $request, Destination $destination): void
    {
        try {
            if ($request->pendingRootTasks > 0) {
                return;
            }
            $completedRootSegment = $request->completedRootSegment;
            if ($completedRootSegment !== null) {
                if ($completedRootSegment->status === Segment::POSTPONED) {
                    return;
                }
                $completedPreambleSegments = $request->completedPreambleSegments;
                if ($completedPreambleSegments === null) {
                    return;
                }
                self::$flushedByteSize = $request->byteSize;
                $resumableState = $request->resumableState;
                $renderState = $request->renderState;
                $preamble = $renderState->preamble;
                $htmlChunks = $preamble->htmlChunks;
                $headChunks = $preamble->headChunks;
                if ($htmlChunks !== null) {
                    $destination->buffer .= implode('', $htmlChunks);
                    if ($headChunks !== null) {
                        $destination->buffer .= implode('', $headChunks);
                    } else {
                        $destination->buffer .= '<head>';
                    }
                } elseif ($headChunks !== null) {
                    $destination->buffer .= implode('', $headChunks);
                }
                $destination->buffer .= implode('', $renderState->charsetChunks);
                $renderState->charsetChunks = [];
                Resources::flushResources($destination, $renderState->preconnects);
                $renderState->preconnects = [];
                $destination->buffer .= implode('', $renderState->viewportChunks);
                $renderState->viewportChunks = [];
                Resources::flushResources($destination, $renderState->fontPreloads);
                $renderState->fontPreloads = [];
                Resources::flushResources($destination, $renderState->highImagePreloads);
                $renderState->highImagePreloads = [];
                Resources::flushStylesInPreamble($destination, $renderState);
                $destination->buffer .= implode('', $renderState->importMapChunks);
                $renderState->importMapChunks = [];
                Resources::flushResources($destination, $renderState->bootstrapScripts);
                Resources::flushResources($destination, $renderState->scripts);
                $renderState->scripts = [];
                Resources::flushResources($destination, $renderState->bulkPreloads);
                $renderState->bulkPreloads = [];
                if ($request->legacy || ($htmlChunks === null && $headChunks === null)) {
                    $resumableState->instructions |= 32;
                }
                $destination->buffer .= implode('', $renderState->hoistableChunks);
                $renderState->hoistableChunks = [];
                foreach ($completedPreambleSegments as $segments) {
                    foreach ($segments as $segment) {
                        self::flushSegment($request, $destination, $segment, null);
                    }
                }
                $preamble = $request->renderState->preamble;
                if ($preamble->htmlChunks !== null || $preamble->headChunks !== null) {
                    $destination->buffer .= '</head>';
                }
                if ($preamble->bodyChunks !== null) {
                    $destination->buffer .= implode('', $preamble->bodyChunks);
                }
                self::$flushingShell = !$request->legacy;
                self::flushSegment($request, $destination, $completedRootSegment, null);
                self::$flushingShell = false;
                $request->completedRootSegment = null;
                if ($request->allPendingTasks !== 0 || $request->clientRenderedBoundaries !== [] || $request->completedBoundaries !== []) {
                    if (($resumableState->instructions & 64) === 0) {
                        $resumableState->instructions |= 64;
                        $destination->buffer .= $renderState->startInlineScript;
                        if (($resumableState->instructions & 32) === 0) {
                            $resumableState->instructions |= 32;
                            $destination->buffer .= ' id="' . Dom::escapeText('_' . $resumableState->idPrefix . 'R_') . '"';
                        }
                        $destination->buffer .= '>' . Scripts::SHELL_TIME_RUNTIME_SCRIPT . '</script>';
                    }
                }
                self::writeBootstrap($destination, $renderState);
            }
            $renderState = $request->renderState;
            $destination->buffer .= implode('', $renderState->viewportChunks);
            $renderState->viewportChunks = [];
            Resources::flushResources($destination, $renderState->preconnects);
            $renderState->preconnects = [];
            Resources::flushResources($destination, $renderState->fontPreloads);
            $renderState->fontPreloads = [];
            Resources::flushResources($destination, $renderState->highImagePreloads);
            $renderState->highImagePreloads = [];
            Resources::preloadLateStyles($destination, $renderState);
            Resources::flushResources($destination, $renderState->scripts);
            $renderState->scripts = [];
            Resources::flushResources($destination, $renderState->bulkPreloads);
            $renderState->bulkPreloads = [];
            $destination->buffer .= implode('', $renderState->hoistableChunks);
            $renderState->hoistableChunks = [];
            $resumableState = $request->resumableState;
            foreach ($request->clientRenderedBoundaries as $boundary) {
                $out = $renderState->startInlineScript . '>';
                if (($resumableState->instructions & 4) === 0) {
                    $resumableState->instructions |= 4;
                    $out .= Scripts::CLIENT_RENDER_SCRIPT_1FULL;
                } else {
                    $out .= '$RX("';
                }
                $out .= $renderState->boundaryPrefix . dechex($boundary->rootSegmentID) . '"';
                if ($boundary->errorDigest !== null) {
                    $out .= ',' . Resources::escapeJSString($boundary->errorDigest);
                }
                $destination->buffer .= $out . ')</script>';
            }
            $request->clientRenderedBoundaries = [];
            $completed = $request->completedBoundaries;
            $request->completedBoundaries = [];
            foreach ($completed as $boundary) {
                self::flushCompletedBoundary($request, $destination, $boundary);
            }
            self::$flushingPartialBoundaries = true;
            foreach ($request->partialBoundaries as $boundary) {
                self::$flushedByteSize = $boundary->byteSize;
                foreach ($boundary->completedSegments as $segment) {
                    self::flushPartiallyCompletedSegment($request, $destination, $boundary, $segment);
                }
                $boundary->completedSegments = [];
                Resources::writeHoistablesForBoundary($destination, $boundary->contentState, $request->renderState);
            }
            $request->partialBoundaries = [];
            self::$flushingPartialBoundaries = false;
            $large = $request->completedBoundaries;
            $request->completedBoundaries = [];
            foreach ($large as $boundary) {
                self::flushCompletedBoundary($request, $destination, $boundary);
            }
        } finally {
            self::$flushingPartialBoundaries = false;
            if ($request->allPendingTasks === 0 && $request->clientRenderedBoundaries === [] && $request->completedBoundaries === []) {
                $request->flushScheduled = false;
                $resumableState = $request->resumableState;
                if ($resumableState->hasBody) {
                    $destination->buffer .= '</body>';
                }
                if ($resumableState->hasHtml) {
                    $destination->buffer .= '</html>';
                }
                $request->status = Request::CLOSED;
                $request->destination = null;
            }
        }
    }

    private static function startFlowing(Request $request, Destination $destination, ?JsValue &$fatal): void
    {
        if ($request->status === Request::ABORTING) {
            $request->status = Request::CLOSED;
            $error = $request->fatalError ?? JsUndefined::instance();
            if (self::isRecoverableError($error) && $error instanceof JsObject) {
                $error = self::cloneRecoverableErrorAsFatal($error);
            }
            $fatal = $error;
            return;
        }
        if ($request->status === Request::CLOSED || $request->destination !== null) {
            return;
        }
        $request->destination = $destination;
        try {
            self::flushCompletedQueues($request, $destination);
        } catch (\Throwable $e) {
            $error = Js::thrownValue($e);
            self::logRecoverableError($request, $error);
            self::fatalError($request, $error);
            $fatal = $error;
        }
    }

    private static function finishAbort(Request $request): void
    {
        try {
            $error = $request->fatalError ?? JsUndefined::instance();
            foreach ($request->abortableTasks->all() as $task) {
                self::finishAbortedTask($task, $request, $error);
            }
            $request->abortableTasks->clear();
            if ($request->destination !== null) {
                self::flushCompletedQueues($request, $request->destination);
            }
        } catch (\Throwable $e) {
            $error = Js::thrownValue($e);
            self::logRecoverableError($request, $error);
            self::fatalError($request, $error);
        }
    }

    public static function abort(Request $request, JsValue $reason): void
    {
        if ($request->aborted || ($request->status !== Request::OPEN && $request->status !== Request::OPENING)) {
            return;
        }
        $request->aborted = true;
        if ($reason instanceof JsObject && Js::get($reason, '$$typeof') === ReactSymbols::$recoverable) {
            $reason = self::createRecoverableError($reason);
        } elseif ($reason instanceof JsUndefined) {
            $reason = Js::makeError('The render was aborted by the server without a reason.');
        } elseif ($reason instanceof JsObject && Js::get($reason, 'then') instanceof JsFunction) {
            $reason = Js::makeError('The render was aborted by the server with a promise.');
        }
        $request->fatalError = $reason;
        foreach ($request->abortableTasks->all() as $task) {
            self::abortTask($task, $request);
        }
        if ($request->legacy) {
            self::finishAbort($request);
        } else {
            self::setImmediate(static function () use ($request): void {
                self::finishAbort($request);
            });
        }
    }

    private static function attachAbortSignal(Request $request, JsObject $signal): void
    {
        if (Js::get($signal, 'aborted')->toBoolean()) {
            self::abort($request, Js::get($signal, 'reason'));
            return;
        }
        $listener = Js::fn('', static function () use ($request, $signal): JsValue {
            self::abort($request, Js::get($signal, 'reason'));
            return JsUndefined::instance();
        });
        Js::call(Js::get($signal, 'addEventListener'), $signal, [new JsString('abort'), $listener]);
    }

    // Small JS helpers.

    /** JSON.stringify(string), as JS escapes it. */
    public static function jsonString(string $value): string
    {
        $out = '"';
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $c = $value[$i];
            $o = ord($c);
            if ($c === '"') {
                $out .= '\\"';
            } elseif ($c === '\\') {
                $out .= '\\\\';
            } elseif ($o < 0x20) {
                $out .= match ($c) {
                    "\x08" => '\\b',
                    "\f" => '\\f',
                    "\n" => '\\n',
                    "\r" => '\\r',
                    "\t" => '\\t',
                    default => sprintf('\\u%04x', $o),
                };
            } elseif ($o === 0xED && $i + 2 < $len && (ord($value[$i + 1]) & 0xE0) === 0xA0) {
                // A lone surrogate, stored CESU-8 style; JSON.stringify escapes it.
                $cp = (($o & 0x0F) << 12) | ((ord($value[$i + 1]) & 0x3F) << 6) | (ord($value[$i + 2]) & 0x3F);
                $out .= sprintf('\\u%04x', $cp);
                $i += 2;
            } else {
                $out .= $c;
            }
        }
        return $out . '"';
    }

    /** JSON.stringify(value) through the realm's JSON object. */
    public static function jsonStringify(JsValue $value): JsValue
    {
        $interpreter = Engine::getCurrentInterpreter();
        $json = $interpreter?->getGlobalEnv()->get('JSON');
        if (!$json instanceof JsObject) {
            return new JsString('');
        }
        return Js::call(Js::get($json, 'stringify'), $json, [$value]);
    }

    /** String(value), which unlike ToString accepts symbols. */
    public static function stringOf(JsValue $value): JsString
    {
        if ($value instanceof JsSymbol) {
            return new JsString($value->display());
        }
        return new JsString(Js::str($value));
    }

    private static ?JsObject $sharedNotPending = null;

    public static function sharedNotPendingObject(): JsObject
    {
        return self::$sharedNotPending ??= Js::object([
            'pending' => JsBoolean::of(false),
            'data' => JsNull::instance(),
            'method' => JsNull::instance(),
            'action' => JsNull::instance(),
        ]);
    }

    /**
     * React's createPostbackActionStateKey.
     *
     * @param array<int, mixed>|null $keyPath
     */
    public static function postbackActionStateKey(JsValue $permalink, ?array $keyPath, int $hookIndex): string
    {
        if (!$permalink instanceof JsUndefined) {
            return 'p' . Js::str($permalink);
        }
        $encode = static function (mixed $value) use (&$encode): string {
            if ($value === null) {
                return 'null';
            }
            if (is_int($value)) {
                return (string) $value;
            }
            if (is_string($value)) {
                return self::jsonString($value);
            }
            if (is_array($value)) {
                return '[' . implode(',', array_map($encode, $value)) . ']';
            }
            return 'null';
        };
        return 'k' . md5($encode([$keyPath, null, $hookIndex]));
    }

    /** True when a value is an element the renderer would render. */
    public static function isElement(JsValue $value): bool
    {
        return $value instanceof JsElement
            || ($value instanceof JsObject && Js::get($value, '$$typeof') === ReactSymbols::$element);
    }

    /** Unused by the port; keeps JsThrowable imported for callers that catch render errors. */
    public static function errorValue(JsThrowable $e): JsValue
    {
        return $e->jsValue;
    }
}
