<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * WHATWG DOM AbortController + AbortSignal.
 *
 *  - AbortController: https://dom.spec.whatwg.org/#interface-abortcontroller
 *  - AbortSignal:     https://dom.spec.whatwg.org/#interface-AbortSignal
 *
 * `AbortSignal` extends `EventTarget` — its prototype's [[Prototype]] is
 * set to `EventTarget.prototype` so `signal instanceof EventTarget`
 * evaluates to true and `addEventListener` / `removeEventListener` /
 * `dispatchEvent` are inherited.
 *
 * Internal slots used on an AbortSignal instance:
 *  - [[IsAbortSignal]]    : true (brand check)
 *  - [[IsEventTarget]]    : true (also branded as EventTarget so
 *                           EventTarget.prototype methods accept it)
 *  - [[EventListeners]]   : managed by EventTargetConstructor
 *  - [[Aborted]]          : bool (lazy — see [[TimeoutDeadline]] note)
 *  - [[AbortReason]]      : JsValue (undefined until aborted)
 *  - [[AbortAlgorithms]]  : list<callable(): void> — internal callbacks
 *                           fired on abort (used by AbortSignal.any to
 *                           propagate from sources to dependents)
 *  - [[Dependent]]        : bool — true for signals returned from .any()
 *  - [[SourceSignals]]    : list<\WeakReference<JsObject>> for dependents
 *  - [[DependentSignals]] : list<\WeakReference<JsObject>> for sources
 *  - [[TimeoutDeadline]]  : ?float (absolute microtime() timestamp in
 *                           seconds — Phasis has no native setTimeout,
 *                           so timeout signals abort lazily on read).
 *
 * Timeout semantics (Phasis limitation):
 *  AbortSignal.timeout(ms) does NOT fire on a real event loop because
 *  Phasis has no host setTimeout integration. Instead the signal stores
 *  an absolute deadline; `aborted`, `reason`, and `throwIfAborted()`
 *  check the wall clock on every access and lazily transition into the
 *  aborted state. Once tripped, the abort algorithm runs (dispatching
 *  an `abort` event, etc.), and any subsequent reads see the aborted
 *  state cached in [[Aborted]]. This is sufficient for synchronous
 *  test cases and for fetch transports that poll the signal during a
 *  long-running operation. It is NOT 100% spec — an abort event will
 *  not fire on its own while no JS is running.
 *
 * any() semantics:
 *  AbortSignal.any(signals) returns a new dependent signal. For each
 *  source signal:
 *    - if it is already aborted at construction time, the result is
 *      pre-aborted with that signal's reason (linked-source iteration
 *      stops at the first already-aborted source);
 *    - otherwise an algorithm is registered on the source that fires
 *      the result with the source's reason on first abort.
 *  Sources and dependents reference each other via \WeakReference so
 *  signal-cycle GC behaves the way the spec requires (a dependent
 *  signal can be collected even while its source signals are still
 *  reachable from JS).
 */
class AbortControllerConstructor
{
    public const SLOT_IS_SIGNAL = '[[IsAbortSignal]]';
    public const SLOT_ABORTED = '[[Aborted]]';
    public const SLOT_REASON = '[[AbortReason]]';
    public const SLOT_ALGORITHMS = '[[AbortAlgorithms]]';
    public const SLOT_DEPENDENT = '[[Dependent]]';
    public const SLOT_SOURCES = '[[SourceSignals]]';
    public const SLOT_DEPENDENTS = '[[DependentSignals]]';
    public const SLOT_TIMEOUT_DEADLINE = '[[TimeoutDeadline]]';

    public const SLOT_IS_CONTROLLER = '[[IsAbortController]]';
    public const SLOT_LINKED_SIGNAL = '[[LinkedSignal]]';

    private static ?Environment $env = null;

    public static function install(Environment $env): void
    {
        self::$env = $env;

        // Locate EventTarget.prototype for the prototype-chain wire-in.
        // EventTargetConstructor::install() runs before us in Engine.php,
        // but be defensive against rearrangement.
        $eventTargetProto = null;
        if ($env->has('EventTarget')) {
            $etCtor = $env->get('EventTarget');
            if ($etCtor instanceof JsObject) {
                $maybeProto = $etCtor->get('prototype');
                if ($maybeProto instanceof JsObject) {
                    $eventTargetProto = $maybeProto;
                }
            }
        }

        $signalProto = self::buildSignalPrototype($eventTargetProto);

        $signalCtor = self::buildSignalConstructor($signalProto, $eventTargetProto);
        $controllerCtor = self::buildControllerConstructor($signalProto);

        $env->defineVar('AbortController', $controllerCtor);
        $env->defineVar('AbortSignal', $signalCtor);
    }

    // ==================================================================
    // AbortSignal constructor + statics
    // ==================================================================

    private static function buildSignalConstructor(JsObject $proto, ?JsObject $eventTargetProto): JsFunction
    {
        // Per spec, AbortSignal has no public constructor — `new AbortSignal()`
        // throws a TypeError ("Illegal constructor"). The statics are the
        // only legal way to mint a signal from script.
        $ctor = JsFunction::fromCallable(
            'AbortSignal',
            function (JsValue $this_, array $args): JsValue {
                throw new TypeError(
                    "Failed to construct 'AbortSignal': Illegal constructor"
                );
            },
            0,
        );
        $ctor->setConstructable();

        $ctor->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data($proto, false, false, false),
        );
        $proto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($ctor, true, false, true),
        );

        // Hoist [[Prototype]] of AbortSignal.prototype to EventTarget.prototype
        // (idempotent — buildSignalPrototype already did this, but the
        // public constructor function's [[Prototype]] should also chain to
        // EventTarget so `AbortSignal.__proto__ === EventTarget` and the
        // static methods inherit through the constructor hierarchy too).
        if ($eventTargetProto !== null) {
            $eventTargetCtor = $eventTargetProto->get('constructor');
            if ($eventTargetCtor instanceof JsFunction) {
                // JsFunction extends JsObject so setPrototype accepts it.
                $ctor->setPrototype($eventTargetCtor);
            }
        }

        // --- AbortSignal.abort(reason?) ---------------------------------
        $abortStatic = JsFunction::fromCallable(
            'abort',
            static function (JsValue $this_, array $args) use ($proto): JsValue {
                $reason = $args[0] ?? JsUndefined::instance();
                $signal = self::makeSignal($proto);
                self::signalAbort($signal, $reason);
                return $signal;
            },
            0,
        );
        $abortStatic->setNonConstructable();
        $ctor->defineOwnProperty(
            'abort',
            PropertyDescriptor::data($abortStatic, true, false, true),
        );

        // --- AbortSignal.timeout(ms) ------------------------------------
        $timeoutStatic = JsFunction::fromCallable(
            'timeout',
            static function (JsValue $this_, array $args) use ($proto): JsValue {
                $ms = TypeConversion::toNumber($args[0] ?? JsUndefined::instance());
                if (!is_finite($ms) || $ms < 0) {
                    throw new TypeError(
                        "Failed to execute 'timeout' on 'AbortSignal': "
                        . 'Value is not a valid milliseconds duration.'
                    );
                }
                $signal = self::makeSignal($proto);
                // Store absolute deadline in seconds (microtime base).
                $deadline = microtime(true) + ($ms / 1000.0);
                $signal->setInternalProperty(self::SLOT_TIMEOUT_DEADLINE, $deadline);
                // 0ms timeouts: abort immediately so behaviour matches a
                // setTimeout(0) draining at next microtask boundary.
                if ($ms === 0.0) {
                    self::tripTimeoutIfDue($signal);
                }
                return $signal;
            },
            1,
        );
        $timeoutStatic->setNonConstructable();
        $ctor->defineOwnProperty(
            'timeout',
            PropertyDescriptor::data($timeoutStatic, true, false, true),
        );

        // --- AbortSignal.any(signals) -----------------------------------
        $anyStatic = JsFunction::fromCallable(
            'any',
            static function (JsValue $this_, array $args) use ($proto): JsValue {
                $iter = $args[0] ?? JsUndefined::instance();
                $sources = self::collectSignalIterable($iter);

                $resultSignal = self::makeSignal($proto);
                $resultSignal->setInternalProperty(self::SLOT_DEPENDENT, true);

                // Walk sources in iteration order. If any source is already
                // aborted, the result aborts immediately with that source's
                // reason and we stop linking further sources (matches the
                // spec's create-a-dependent-abort-signal algorithm).
                foreach ($sources as $src) {
                    if (self::isSignalAborted($src)) {
                        self::signalAbort($resultSignal, self::signalReason($src));
                        return $resultSignal;
                    }
                }

                // None aborted yet: link them all. Use \WeakReference for
                // both directions so a dependent that goes out of scope
                // can be GC'd even while its sources are still live.
                /** @var list<\WeakReference<JsObject>> $sourceRefs */
                $sourceRefs = [];
                $resultRef = \WeakReference::create($resultSignal);

                foreach ($sources as $src) {
                    $sourceRefs[] = \WeakReference::create($src);

                    // Add algorithm on the source: when it aborts, propagate
                    // its reason to the (still-live) dependent.
                    self::addAbortAlgorithm($src, function () use ($resultRef, $src): void {
                        $dependent = $resultRef->get();
                        if ($dependent === null) {
                            return; // dependent already GC'd
                        }
                        if (self::isSignalAborted($dependent)) {
                            return; // some other source already aborted us
                        }
                        self::signalAbort($dependent, self::signalReason($src));
                    });

                    // Track dependent on the source so it shows up in the
                    // [[DependentSignals]] linked-set — purely informational
                    // for now (no spec-visible API), kept as a weak ref so
                    // it does not retain the dependent.
                    /** @var list<\WeakReference<JsObject>> $deps */
                    $deps = $src->getInternalProperty(self::SLOT_DEPENDENTS) ?? [];
                    $deps[] = $resultRef;
                    $src->setInternalProperty(self::SLOT_DEPENDENTS, $deps);
                }

                $resultSignal->setInternalProperty(self::SLOT_SOURCES, $sourceRefs);
                return $resultSignal;
            },
            1,
        );
        $anyStatic->setNonConstructable();
        $ctor->defineOwnProperty(
            'any',
            PropertyDescriptor::data($anyStatic, true, false, true),
        );

        return $ctor;
    }

    private static function buildSignalPrototype(?JsObject $eventTargetProto): JsObject
    {
        $proto = new JsObject($eventTargetProto);

        // ---- aborted getter --------------------------------------------
        $abortedGetter = JsFunction::fromCallable(
            'get aborted',
            static function (JsValue $this_): JsValue {
                $self = self::requireSignal($this_, 'aborted');
                self::tripTimeoutIfDue($self);
                return JsBoolean::of((bool) $self->getInternalProperty(self::SLOT_ABORTED));
            },
            0,
        );
        $abortedGetter->setNonConstructable();
        $proto->defineOwnProperty(
            'aborted',
            PropertyDescriptor::accessor($abortedGetter, null, false, true),
        );

        // ---- reason getter ---------------------------------------------
        $reasonGetter = JsFunction::fromCallable(
            'get reason',
            static function (JsValue $this_): JsValue {
                $self = self::requireSignal($this_, 'reason');
                self::tripTimeoutIfDue($self);
                $reason = $self->getInternalProperty(self::SLOT_REASON);
                return $reason instanceof JsValue ? $reason : JsUndefined::instance();
            },
            0,
        );
        $reasonGetter->setNonConstructable();
        $proto->defineOwnProperty(
            'reason',
            PropertyDescriptor::accessor($reasonGetter, null, false, true),
        );

        // ---- onabort (read/write event-handler attribute) --------------
        // Per WebIDL event-handler IDL attributes are accessor properties
        // that get/set an internal slot. We store the current handler in
        // a separate slot and install an addEventListener-equivalent
        // bridge that dispatches to it from the abort event.
        $onAbortGetter = JsFunction::fromCallable(
            'get onabort',
            static function (JsValue $this_): JsValue {
                $self = self::requireSignal($this_, 'onabort');
                $h = $self->getInternalProperty('[[OnAbort]]');
                return $h instanceof JsValue ? $h : JsNull::instance();
            },
            0,
        );
        $onAbortGetter->setNonConstructable();

        $onAbortSetter = JsFunction::fromCallable(
            'set onabort',
            static function (JsValue $this_, array $args): JsValue {
                $self = self::requireSignal($this_, 'onabort');
                $value = $args[0] ?? JsNull::instance();
                // Per WebIDL only callable values activate the handler;
                // anything else nulls it out (but the attribute still
                // remembers null so subsequent reads return null).
                if ($value instanceof JsFunction) {
                    $self->setInternalProperty('[[OnAbort]]', $value);
                } else {
                    $self->setInternalProperty('[[OnAbort]]', JsNull::instance());
                }
                return JsUndefined::instance();
            },
            1,
        );
        $onAbortSetter->setNonConstructable();

        $proto->defineOwnProperty(
            'onabort',
            PropertyDescriptor::accessor($onAbortGetter, $onAbortSetter, false, true),
        );

        // ---- throwIfAborted() -----------------------------------------
        $throwIfAbortedFn = JsFunction::fromCallable(
            'throwIfAborted',
            static function (JsValue $this_): JsValue {
                $self = self::requireSignal($this_, 'throwIfAborted');
                self::tripTimeoutIfDue($self);
                if ($self->getInternalProperty(self::SLOT_ABORTED) === true) {
                    $reason = $self->getInternalProperty(self::SLOT_REASON);
                    if (!$reason instanceof JsValue) {
                        $reason = self::defaultAbortReason();
                    }
                    // Throw the JS value directly. Phasis's exception
                    // bridge will surface this as a JS-level throw.
                    throw new \Phasis\Exceptions\JsThrowable($reason);
                }
                return JsUndefined::instance();
            },
            0,
        );
        $throwIfAbortedFn->setNonConstructable();
        $proto->defineOwnProperty(
            'throwIfAborted',
            PropertyDescriptor::data($throwIfAbortedFn, true, false, true),
        );

        // Symbol.toStringTag = "AbortSignal"
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('AbortSignal'), false, false, true),
        );

        return $proto;
    }

    // ==================================================================
    // AbortController constructor
    // ==================================================================

    private static function buildControllerConstructor(JsObject $signalProto): JsFunction
    {
        $controllerProto = self::buildControllerPrototype($signalProto);

        $ctor = JsFunction::fromCallable(
            'AbortController',
            function (JsValue $this_, array $args) use ($controllerProto, $signalProto): JsValue {
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError(
                        "Failed to construct 'AbortController': Please use the 'new' operator"
                    );
                }
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsObject) {
                    $ntProto = $newTarget->get('prototype');
                    $this_->setPrototype(
                        $ntProto instanceof JsObject ? $ntProto : $controllerProto
                    );
                }

                $signal = self::makeSignal($signalProto);
                $this_->setInternalProperty(self::SLOT_IS_CONTROLLER, true);
                $this_->setInternalProperty(self::SLOT_LINKED_SIGNAL, $signal);
                return $this_;
            },
            0,
        );
        $ctor->setConstructable();

        $ctor->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data($controllerProto, false, false, false),
        );
        $controllerProto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($ctor, true, false, true),
        );

        return $ctor;
    }

    private static function buildControllerPrototype(JsObject $signalProto): JsObject
    {
        $proto = new JsObject();

        // signal getter
        $signalGetter = JsFunction::fromCallable(
            'get signal',
            static function (JsValue $this_): JsValue {
                $self = self::requireController($this_, 'signal');
                $sig = $self->getInternalProperty(self::SLOT_LINKED_SIGNAL);
                return $sig instanceof JsObject ? $sig : JsUndefined::instance();
            },
            0,
        );
        $signalGetter->setNonConstructable();
        $proto->defineOwnProperty(
            'signal',
            PropertyDescriptor::accessor($signalGetter, null, false, true),
        );

        // abort(reason?)
        $abortFn = JsFunction::fromCallable(
            'abort',
            static function (JsValue $this_, array $args): JsValue {
                $self = self::requireController($this_, 'abort');
                $reason = $args[0] ?? JsUndefined::instance();
                $signal = $self->getInternalProperty(self::SLOT_LINKED_SIGNAL);
                if ($signal instanceof JsObject) {
                    self::signalAbort($signal, $reason);
                }
                return JsUndefined::instance();
            },
            0,
        );
        $proto->defineOwnProperty(
            'abort',
            PropertyDescriptor::data($abortFn, true, false, true),
        );

        // Symbol.toStringTag = "AbortController"
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('AbortController'), false, false, true),
        );

        return $proto;
    }

    // ==================================================================
    // Internal helpers
    // ==================================================================

    /** Allocate a fresh, non-aborted AbortSignal instance. */
    private static function makeSignal(JsObject $proto): JsObject
    {
        $signal = new JsObject($proto);
        $signal->setInternalProperty(self::SLOT_IS_SIGNAL, true);
        $signal->setInternalProperty(self::SLOT_ABORTED, false);
        $signal->setInternalProperty(self::SLOT_REASON, JsUndefined::instance());
        $signal->setInternalProperty(self::SLOT_ALGORITHMS, []);
        $signal->setInternalProperty(self::SLOT_DEPENDENT, false);
        $signal->setInternalProperty(self::SLOT_SOURCES, []);
        $signal->setInternalProperty(self::SLOT_DEPENDENTS, []);
        $signal->setInternalProperty(self::SLOT_TIMEOUT_DEADLINE, null);
        // Brand as EventTarget so inherited addEventListener/dispatchEvent
        // accept this receiver. EventTargetConstructor::brandAsEventTarget
        // initialises [[EventListeners]] alongside the brand.
        EventTargetConstructor::brandAsEventTarget($signal);
        return $signal;
    }

    /**
     * Spec: signal abort algorithm.
     * 1. If signal is already aborted, return (re-entrant abort is a no-op).
     * 2. Set [[Aborted]] = true, [[AbortReason]] = reason ?? default.
     * 3. Run every registered abort algorithm.
     * 4. Dispatch an `abort` event on the signal.
     * 5. Invoke onabort if set.
     */
    private static function signalAbort(JsObject $signal, JsValue $reason): void
    {
        if ($signal->getInternalProperty(self::SLOT_ABORTED) === true) {
            return;
        }
        if ($reason instanceof JsUndefined) {
            $reason = self::defaultAbortReason();
        }
        $signal->setInternalProperty(self::SLOT_ABORTED, true);
        $signal->setInternalProperty(self::SLOT_REASON, $reason);

        // Run internal abort algorithms FIRST (these are the bridges
        // registered by AbortSignal.any sources). Per spec they run
        // before the event dispatch so dependents see the same reason
        // an addEventListener('abort') handler would.
        /** @var list<\Closure(): void> $algorithms */
        $algorithms = $signal->getInternalProperty(self::SLOT_ALGORITHMS) ?? [];
        // Snapshot and clear: algorithms should not double-run if an
        // algorithm itself ends up calling signalAbort on the same
        // signal (which is a no-op anyway thanks to the guard above,
        // but defence-in-depth).
        $signal->setInternalProperty(self::SLOT_ALGORITHMS, []);
        foreach ($algorithms as $algo) {
            $algo();
        }

        // Build and dispatch an abort event. We call the engine's Event
        // constructor through the env so subclassing / Event.prototype
        // mutations are respected, with a fallback to a hand-built event
        // object if Event is somehow not installed yet.
        $event = self::buildAbortEvent();
        // Per spec the user-agent-fired abort event is a trusted event.
        $event->setInternalProperty('[[IsTrusted]]', true);
        $dispatch = $signal->get('dispatchEvent');
        if ($dispatch instanceof JsFunction) {
            $dispatch->call($signal, [$event]);
        }

        // Fire onabort with the same event (per WebIDL: an event-handler
        // IDL attribute on the same target as an addEventListener call).
        $handler = $signal->getInternalProperty('[[OnAbort]]');
        if ($handler instanceof JsFunction) {
            $handler->call($signal, [$event]);
        }
    }

    /**
     * Construct an Event("abort") via the public Event constructor so
     * subclass / prototype mutations are honoured. Falls back to a
     * synthetic event object branded with [[IsEvent]] for environments
     * where Event somehow is not yet installed.
     */
    private static function buildAbortEvent(): JsObject
    {
        $env = self::$env;
        if ($env !== null && $env->has('Event')) {
            $eventCtor = $env->get('Event');
            if ($eventCtor instanceof JsFunction) {
                $result = $eventCtor->construct([new JsString('abort')]);
                if ($result instanceof JsObject) {
                    return $result;
                }
            }
        }
        // Fallback: synthetic event with the slots dispatchEvent needs.
        $obj = new JsObject();
        $obj->setInternalProperty(EventTargetConstructor::SLOT_IS_EVENT, true);
        $obj->setInternalProperty('[[Type]]', 'abort');
        $obj->setInternalProperty('[[Bubbles]]', false);
        $obj->setInternalProperty('[[Cancelable]]', false);
        $obj->setInternalProperty('[[Composed]]', false);
        $obj->setInternalProperty('[[Target]]', null);
        $obj->setInternalProperty('[[CurrentTarget]]', null);
        $obj->setInternalProperty('[[EventPhase]]', 0);
        $obj->setInternalProperty('[[DefaultPrevented]]', false);
        $obj->setInternalProperty('[[IsTrusted]]', false);
        $obj->setInternalProperty('[[TimeStamp]]', microtime(true) * 1000.0);
        $obj->setInternalProperty('[[Dispatched]]', false);
        $obj->setInternalProperty('[[StopPropagation]]', false);
        $obj->setInternalProperty('[[StopImmediate]]', false);
        return $obj;
    }

    /**
     * If $signal carries a timeout deadline that has now elapsed,
     * transition it into the aborted state with a TimeoutError DOMException.
     */
    private static function tripTimeoutIfDue(JsObject $signal): void
    {
        if ($signal->getInternalProperty(self::SLOT_ABORTED) === true) {
            return;
        }
        $deadline = $signal->getInternalProperty(self::SLOT_TIMEOUT_DEADLINE);
        if (!is_float($deadline) && !is_int($deadline)) {
            return;
        }
        if (microtime(true) < (float) $deadline) {
            return;
        }
        $reason = self::makeDomException(
            'signal timed out',
            'TimeoutError',
        );
        self::signalAbort($signal, $reason);
    }

    /**
     * Register an internal abort algorithm. Fires (in registration order)
     * the first time the signal aborts. Used by AbortSignal.any() to
     * propagate from sources to dependents.
     *
     * @param \Closure(): void $algo
     */
    private static function addAbortAlgorithm(JsObject $signal, \Closure $algo): void
    {
        // Already-aborted source: run immediately (matches spec
        // "if signal is aborted, then run algorithm" guard).
        if ($signal->getInternalProperty(self::SLOT_ABORTED) === true) {
            $algo();
            return;
        }
        /** @var list<\Closure(): void> $list */
        $list = $signal->getInternalProperty(self::SLOT_ALGORITHMS) ?? [];
        $list[] = $algo;
        $signal->setInternalProperty(self::SLOT_ALGORITHMS, $list);
    }

    private static function isSignalAborted(JsObject $signal): bool
    {
        self::tripTimeoutIfDue($signal);
        return $signal->getInternalProperty(self::SLOT_ABORTED) === true;
    }

    private static function signalReason(JsObject $signal): JsValue
    {
        $reason = $signal->getInternalProperty(self::SLOT_REASON);
        return $reason instanceof JsValue ? $reason : JsUndefined::instance();
    }

    /**
     * Build the spec default abort reason: a DOMException("signal is
     * aborted without reason", "AbortError"). If DOMException isn't
     * installed (highly unlikely — it's a hard dep) fall back to a
     * plain Error-shaped object so reads of `.name` / `.message` still
     * give useful output.
     */
    private static function defaultAbortReason(): JsObject
    {
        return self::makeDomException(
            'signal is aborted without reason',
            'AbortError',
        );
    }

    private static function makeDomException(string $message, string $name): JsObject
    {
        $env = self::$env;
        if ($env !== null) {
            return DomExceptionConstructor::create($env, $message, $name);
        }
        // Last-ditch fallback shape — should never run in practice.
        $obj = new JsObject();
        $obj->defineOwnProperty(
            'name',
            PropertyDescriptor::data(new JsString($name), true, false, true),
        );
        $obj->defineOwnProperty(
            'message',
            PropertyDescriptor::data(new JsString($message), true, false, true),
        );
        return $obj;
    }

    /**
     * Coerce an iterable of values into a list of AbortSignal instances.
     * Throws TypeError on a non-iterable, on an element that isn't a
     * signal, or if iterator-step returns a non-object.
     *
     * @return list<JsObject>
     */
    private static function collectSignalIterable(JsValue $iterable): array
    {
        if (!$iterable instanceof JsObject) {
            throw new TypeError(
                "Failed to execute 'any' on 'AbortSignal': "
                . 'argument is not iterable.'
            );
        }

        // Fast path: JsArray is iterable by index. Avoids allocating an
        // iterator wrapper for the common case `AbortSignal.any([a, b])`.
        if ($iterable instanceof JsArray) {
            $out = [];
            $len = $iterable->getLength();
            for ($i = 0; $i < $len; $i++) {
                $element = $iterable->get((string) $i);
                $out[] = self::asSignal($element);
            }
            return $out;
        }

        $iteratorMethod = $iterable->getBySymbol(SymbolConstructor::iterator());
        if (!$iteratorMethod instanceof JsFunction) {
            throw new TypeError(
                "Failed to execute 'any' on 'AbortSignal': "
                . 'argument is not iterable.'
            );
        }
        $iterator = $iteratorMethod->call($iterable, []);
        if (!$iterator instanceof JsObject) {
            throw new TypeError(
                "Failed to execute 'any' on 'AbortSignal': "
                . 'iterator must return an object.'
            );
        }
        $next = $iterator->get('next');
        if (!$next instanceof JsFunction) {
            throw new TypeError(
                "Failed to execute 'any' on 'AbortSignal': "
                . 'iterator missing next().'
            );
        }

        $out = [];
        while (true) {
            $step = $next->call($iterator, []);
            if (!$step instanceof JsObject) {
                throw new TypeError(
                    "Failed to execute 'any' on 'AbortSignal': "
                    . 'iterator result must be an object.'
                );
            }
            if (TypeConversion::toBoolean($step->get('done'))) {
                break;
            }
            $out[] = self::asSignal($step->get('value'));
        }
        return $out;
    }

    private static function asSignal(JsValue $value): JsObject
    {
        if (!$value instanceof JsObject || $value->getInternalProperty(self::SLOT_IS_SIGNAL) !== true) {
            throw new TypeError(
                "Failed to execute 'any' on 'AbortSignal': "
                . 'iterator value is not an AbortSignal.'
            );
        }
        return $value;
    }

    private static function requireSignal(JsValue $obj, string $accessor): JsObject
    {
        if (!$obj instanceof JsObject || $obj->getInternalProperty(self::SLOT_IS_SIGNAL) !== true) {
            throw new TypeError(
                "'$accessor' called on an object that does not implement interface AbortSignal"
            );
        }
        return $obj;
    }

    private static function requireController(JsValue $obj, string $accessor): JsObject
    {
        if (!$obj instanceof JsObject || $obj->getInternalProperty(self::SLOT_IS_CONTROLLER) !== true) {
            throw new TypeError(
                "'$accessor' called on an object that does not implement interface AbortController"
            );
        }
        return $obj;
    }
}
