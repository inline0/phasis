<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * WHATWG DOM EventTarget + Event built-ins.
 *
 * Spec:
 *  - https://dom.spec.whatwg.org/#interface-eventtarget
 *  - https://dom.spec.whatwg.org/#interface-event
 *
 * These are the foundation event-dispatch classes that AbortSignal and
 * friends extend. Because Phasis has no DOM tree, dispatch is flat: there
 * is no capture/bubble walk across ancestors. `composedPath()` therefore
 * returns `[target]` and the only event phase a listener ever sees is
 * `AT_TARGET (2)`. The `capture` listener option is still honored as a
 * removal-key discriminator so addEventListener / removeEventListener
 * match WebIDL semantics, but it does not change firing order.
 *
 * Internal slots used:
 *  - On an EventTarget instance:
 *      [[IsEventTarget]] => true
 *      [[EventListeners]] => array<string, list<ListenerEntry>>
 *        where ListenerEntry is an associative array:
 *          callback   : JsValue (callable JsFunction OR object with
 *                       handleEvent method)
 *          capture    : bool
 *          once       : bool
 *          passive    : bool
 *          signal     : ?JsObject (AbortSignal, may be null)
 *          removed    : bool (tombstone flag set during dispatch when
 *                       removeEventListener fires inside another listener)
 *  - On an Event instance:
 *      [[IsEvent]]          => true
 *      [[Type]]             => string
 *      [[Bubbles]]          => bool
 *      [[Cancelable]]       => bool
 *      [[Composed]]         => bool
 *      [[Target]]           => ?JsObject
 *      [[CurrentTarget]]    => ?JsObject
 *      [[EventPhase]]       => int (NONE / AT_TARGET — no tree walk)
 *      [[DefaultPrevented]] => bool
 *      [[IsTrusted]]        => bool (always false for script-created events)
 *      [[TimeStamp]]        => float (DOMHighResTimeStamp, ms since origin)
 *      [[Dispatched]]       => bool (set true on first dispatchEvent)
 *      [[StopPropagation]]  => bool
 *      [[StopImmediate]]    => bool
 *
 * Decisions / known edge cases:
 *  - We do not implement legacy `Event.prototype.initEvent()` — modern
 *    code uses the constructor and the legacy method is only kept by
 *    UAs for historical reasons.
 *  - `passive: true` is parsed and stored, but since `preventDefault()`
 *    is a no-op outside of cancelable events anyway, there is nothing
 *    we can short-circuit. We honor the spec hint by ignoring
 *    preventDefault from a passive listener.
 *  - The `signal` option references an AbortSignal, which is not yet
 *    installed in this build. We tolerate that by probing
 *    `$signal->has('aborted')`. If the signal is already aborted at
 *    addEventListener time we drop the listener immediately; otherwise
 *    once AbortSignal ships and integrates with EventTarget it will
 *    invoke the engine-internal removal hook stored on the listener.
 *  - Errors thrown by a listener propagate; the spec says implementations
 *    should report-but-not-rethrow, but for a JS engine embedded in PHP
 *    a try/swallow would silently hide programming errors. We match
 *    Node/Deno behavior: propagate to the dispatchEvent caller.
 */
class EventTargetConstructor
{
    /** Internal-slot key for the EventTarget brand. */
    public const SLOT_IS_TARGET = '[[IsEventTarget]]';
    /** Internal-slot key for the listener map. */
    public const SLOT_LISTENERS = '[[EventListeners]]';

    /** Internal-slot key for the Event brand. */
    public const SLOT_IS_EVENT = '[[IsEvent]]';

    public static function install(Environment $env): void
    {
        $eventProto = self::createEventPrototype();
        $eventCtor = self::createEventConstructor($eventProto);
        $env->defineVar('Event', $eventCtor);

        $targetProto = self::createEventTargetPrototype();
        $targetCtor = self::createEventTargetConstructor($targetProto);
        $env->defineVar('EventTarget', $targetCtor);
    }

    // ------------------------------------------------------------------
    // EventTarget
    // ------------------------------------------------------------------

    private static function createEventTargetConstructor(JsObject $proto): JsFunction
    {
        $ctor = JsFunction::fromCallable(
            'EventTarget',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError("Constructor EventTarget requires 'new'");
                }
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsObject) {
                    $ntProto = $newTarget->get('prototype');
                    $this_->setPrototype($ntProto instanceof JsObject ? $ntProto : $proto);
                }
                self::brandAsEventTarget($this_);
                return $this_;
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
        return $ctor;
    }

    private static function createEventTargetPrototype(): JsObject
    {
        $proto = new JsObject();

        $proto->defineOwnProperty(
            'addEventListener',
            PropertyDescriptor::data(
                JsFunction::fromCallable(
                    'addEventListener',
                    static fn (JsValue $this_, array $args): JsValue
                        => self::addEventListener($this_, $args),
                    2,
                ),
                true,
                false,
                true,
            ),
        );

        $proto->defineOwnProperty(
            'removeEventListener',
            PropertyDescriptor::data(
                JsFunction::fromCallable(
                    'removeEventListener',
                    static fn (JsValue $this_, array $args): JsValue
                        => self::removeEventListener($this_, $args),
                    2,
                ),
                true,
                false,
                true,
            ),
        );

        $proto->defineOwnProperty(
            'dispatchEvent',
            PropertyDescriptor::data(
                JsFunction::fromCallable(
                    'dispatchEvent',
                    static fn (JsValue $this_, array $args): JsValue
                        => self::dispatchEvent($this_, $args),
                    1,
                ),
                true,
                false,
                true,
            ),
        );

        // Symbol.toStringTag = "EventTarget".
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('EventTarget'), false, false, true),
        );

        return $proto;
    }

    /**
     * Initialize the EventTarget internal slots on a freshly-constructed
     * (or subclass) instance. Idempotent so subclass constructors can
     * call this without breaking if super() already ran.
     */
    public static function brandAsEventTarget(JsObject $obj): void
    {
        if ($obj->getInternalProperty(self::SLOT_IS_TARGET) !== true) {
            $obj->setInternalProperty(self::SLOT_IS_TARGET, true);
            $obj->setInternalProperty(self::SLOT_LISTENERS, []);
        }
    }

    /**
     * Throw a TypeError if `$obj` was not constructed from EventTarget
     * (or a subclass that called the EventTarget constructor).
     */
    private static function requireEventTarget(JsValue $obj, string $method): JsObject
    {
        if (!$obj instanceof JsObject || $obj->getInternalProperty(self::SLOT_IS_TARGET) !== true) {
            throw new TypeError(
                "'$method' called on an object that does not implement interface EventTarget"
            );
        }
        return $obj;
    }

    /**
     * addEventListener(type, listener, options?).
     *
     * @param list<JsValue> $args
     */
    private static function addEventListener(JsValue $this_, array $args): JsValue
    {
        $self = self::requireEventTarget($this_, 'addEventListener');

        $type = TypeConversion::toString($args[0] ?? JsUndefined::instance());
        $listener = $args[1] ?? JsNull::instance();

        // Per spec: a null listener is allowed (and is a no-op).
        if ($listener instanceof JsNull || $listener instanceof JsUndefined) {
            return JsUndefined::instance();
        }
        // A listener must be either callable or an object (so handleEvent
        // can be looked up at dispatch time). Anything else is a TypeError
        // per WebIDL EventListener callback interface.
        if (!self::isAcceptableListener($listener)) {
            throw new TypeError(
                "Failed to execute 'addEventListener' on 'EventTarget': "
                . "parameter 2 is not of type 'EventListener'."
            );
        }

        [$capture, $once, $passive, $signal] = self::parseAddOptions($args[2] ?? JsUndefined::instance());

        // If the signal is already aborted, do nothing.
        if ($signal !== null && self::signalIsAborted($signal)) {
            return JsUndefined::instance();
        }

        /** @var array<string, list<array<string,mixed>>> $map */
        $map = $self->getInternalProperty(self::SLOT_LISTENERS) ?? [];

        // Per WebIDL, listeners are deduplicated by (type, callback, capture).
        // A second add with matching key is a no-op (the original options
        // — including once/passive/signal — are kept).
        if (isset($map[$type])) {
            foreach ($map[$type] as $entry) {
                if (
                    $entry['callback'] === $listener
                    && $entry['capture'] === $capture
                    && empty($entry['removed'])
                ) {
                    return JsUndefined::instance();
                }
            }
        }

        $map[$type][] = [
            'callback' => $listener,
            'capture' => $capture,
            'once' => $once,
            'passive' => $passive,
            'signal' => $signal,
            'removed' => false,
        ];
        $self->setInternalProperty(self::SLOT_LISTENERS, $map);
        return JsUndefined::instance();
    }

    /**
     * removeEventListener(type, listener, options?).
     *
     * @param list<JsValue> $args
     */
    private static function removeEventListener(JsValue $this_, array $args): JsValue
    {
        $self = self::requireEventTarget($this_, 'removeEventListener');

        $type = TypeConversion::toString($args[0] ?? JsUndefined::instance());
        $listener = $args[1] ?? JsNull::instance();
        $capture = self::parseCaptureOption($args[2] ?? JsUndefined::instance());

        /** @var array<string, list<array<string,mixed>>> $map */
        $map = $self->getInternalProperty(self::SLOT_LISTENERS) ?? [];
        if (!isset($map[$type])) {
            return JsUndefined::instance();
        }

        foreach ($map[$type] as $i => $entry) {
            if (
                $entry['callback'] === $listener
                && $entry['capture'] === $capture
                && empty($entry['removed'])
            ) {
                // Set a tombstone so an in-flight dispatch sees the
                // removal mid-iteration, then drop it from the list.
                $map[$type][$i]['removed'] = true;
                unset($map[$type][$i]);
                $map[$type] = array_values($map[$type]);
                if ($map[$type] === []) {
                    unset($map[$type]);
                }
                $self->setInternalProperty(self::SLOT_LISTENERS, $map);
                return JsUndefined::instance();
            }
        }
        return JsUndefined::instance();
    }

    /**
     * dispatchEvent(event).
     *
     * @param list<JsValue> $args
     */
    private static function dispatchEvent(JsValue $this_, array $args): JsValue
    {
        $self = self::requireEventTarget($this_, 'dispatchEvent');

        $event = $args[0] ?? JsNull::instance();
        if (!$event instanceof JsObject || $event->getInternalProperty(self::SLOT_IS_EVENT) !== true) {
            throw new TypeError(
                "Failed to execute 'dispatchEvent' on 'EventTarget': "
                . "parameter 1 is not of type 'Event'."
            );
        }
        if ($event->getInternalProperty('[[Dispatched]]') === true) {
            throw new TypeError(
                "Failed to execute 'dispatchEvent' on 'EventTarget': "
                . "The event is already being dispatched."
            );
        }

        // Mark dispatched, set target/currentTarget, flip phase to AT_TARGET.
        $event->setInternalProperty('[[Dispatched]]', true);
        $event->setInternalProperty('[[IsTrusted]]', false);
        $event->setInternalProperty('[[Target]]', $self);
        $event->setInternalProperty('[[CurrentTarget]]', $self);
        $event->setInternalProperty('[[EventPhase]]', 2); // AT_TARGET
        // Reset stop flags in case the event was reused (spec disallows
        // redispatch but defensive).
        $event->setInternalProperty('[[StopPropagation]]', false);
        $event->setInternalProperty('[[StopImmediate]]', false);

        try {
            $type = (string) $event->getInternalProperty('[[Type]]');
            /** @var array<string, list<array<string,mixed>>> $map */
            $map = $self->getInternalProperty(self::SLOT_LISTENERS) ?? [];
            if (isset($map[$type])) {
                // Snapshot the list. Per spec, listeners added DURING
                // dispatch are not invoked; removals during dispatch are
                // honored via the `removed` tombstone.
                $snapshot = $map[$type];
                foreach ($snapshot as $entry) {
                    // Stop on stopImmediatePropagation.
                    if ($event->getInternalProperty('[[StopImmediate]]') === true) {
                        break;
                    }

                    // Skip if removed since snapshot.
                    $current = self::findCurrentEntry($self, $type, $entry);
                    if ($current === null || !empty($current['removed'])) {
                        continue;
                    }

                    // Drop listeners whose signal aborted since add time.
                    $signal = $current['signal'] ?? null;
                    if ($signal instanceof JsObject && self::signalIsAborted($signal)) {
                        self::dropListener($self, $type, $entry);
                        continue;
                    }

                    // once: remove BEFORE invoking so re-entrant dispatches
                    // see the removal, matching browser behavior.
                    if (!empty($current['once'])) {
                        self::dropListener($self, $type, $entry);
                    }

                    self::invokeListener($current, $self, $event);
                }
            }
        } finally {
            // Per spec, after dispatch finishes currentTarget is null,
            // eventPhase is NONE, but target remains set.
            $event->setInternalProperty('[[CurrentTarget]]', null);
            $event->setInternalProperty('[[EventPhase]]', 0); // NONE
        }

        $defaultPrevented = $event->getInternalProperty('[[DefaultPrevented]]') === true;
        return JsBoolean::of(!$defaultPrevented);
    }

    /**
     * Invoke a single listener entry: call the function form, OR look up
     * `handleEvent` on the object form.
     *
     * @param array<string,mixed> $entry
     */
    private static function invokeListener(array $entry, JsObject $target, JsObject $event): void
    {
        /** @var JsValue $callback */
        $callback = $entry['callback'];

        // Per WebIDL, `this` is the EventTarget for a function listener,
        // and the listener object itself for the handleEvent form.
        if ($callback instanceof JsFunction) {
            $callback->call($target, [$event]);
            return;
        }
        if ($callback instanceof JsObject) {
            $handler = $callback->get('handleEvent');
            if ($handler instanceof JsFunction) {
                $handler->call($callback, [$event]);
            }
            // If handleEvent is missing or non-callable per spec we silently
            // skip; the WebIDL EventListener.handleEvent operation says
            // "if callable, call it" but does not throw.
        }
    }

    /**
     * Locate the live entry matching $needle in the listeners map for
     * $type. Used during dispatch to honor mid-flight remove/once edits.
     *
     * @param array<string,mixed> $needle
     * @return array<string,mixed>|null
     */
    private static function findCurrentEntry(JsObject $self, string $type, array $needle): ?array
    {
        /** @var array<string, list<array<string,mixed>>> $map */
        $map = $self->getInternalProperty(self::SLOT_LISTENERS) ?? [];
        if (!isset($map[$type])) {
            return null;
        }
        foreach ($map[$type] as $entry) {
            if (
                $entry['callback'] === $needle['callback']
                && $entry['capture'] === $needle['capture']
            ) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Remove a listener entry mid-dispatch (or eagerly when its signal
     * has aborted). Matches `removeEventListener` semantics without
     * re-walking through the public API.
     *
     * @param array<string,mixed> $needle
     */
    private static function dropListener(JsObject $self, string $type, array $needle): void
    {
        /** @var array<string, list<array<string,mixed>>> $map */
        $map = $self->getInternalProperty(self::SLOT_LISTENERS) ?? [];
        if (!isset($map[$type])) {
            return;
        }
        foreach ($map[$type] as $i => $entry) {
            if (
                $entry['callback'] === $needle['callback']
                && $entry['capture'] === $needle['capture']
            ) {
                $map[$type][$i]['removed'] = true;
                unset($map[$type][$i]);
                $map[$type] = array_values($map[$type]);
                if ($map[$type] === []) {
                    unset($map[$type]);
                }
                $self->setInternalProperty(self::SLOT_LISTENERS, $map);
                return;
            }
        }
    }

    /**
     * Parse the third arg of addEventListener — either a boolean
     * (treated as capture) or `{capture, once, passive, signal}`.
     *
     * @return array{0:bool, 1:bool, 2:bool, 3:?JsObject}
     */
    private static function parseAddOptions(JsValue $opts): array
    {
        if ($opts instanceof JsUndefined) {
            return [false, false, false, null];
        }
        if ($opts instanceof JsBoolean) {
            return [$opts->value, false, false, null];
        }
        if (!$opts instanceof JsObject) {
            // Per spec, anything else is coerced — primitives like numbers
            // are truthy / falsy in the `capture` slot, but mostly the
            // sensible thing is to treat as object-with-no-keys and so
            // pick all-false. Matches Node behavior on `0` / `"x"`.
            return [TypeConversion::toBoolean($opts), false, false, null];
        }
        $capture = TypeConversion::toBoolean($opts->get('capture'));
        $once = TypeConversion::toBoolean($opts->get('once'));
        $passive = TypeConversion::toBoolean($opts->get('passive'));

        $signal = null;
        if ($opts->has('signal')) {
            $sigVal = $opts->get('signal');
            // Accept anything that looks like an AbortSignal — duck-typed
            // since AbortSignal ships in a later phase.
            if ($sigVal instanceof JsObject && $sigVal->has('aborted')) {
                $signal = $sigVal;
            }
            // If `signal` was passed but isn't an object, browsers throw
            // "parameter signal is not of type AbortSignal". We follow.
            if ($signal === null && !($sigVal instanceof JsUndefined) && !($sigVal instanceof JsNull)) {
                throw new TypeError(
                    "Failed to execute 'addEventListener' on 'EventTarget': "
                    . "member signal is not of type AbortSignal."
                );
            }
        }
        return [$capture, $once, $passive, $signal];
    }

    /**
     * Parse the third arg of removeEventListener — only `capture` matters.
     */
    private static function parseCaptureOption(JsValue $opts): bool
    {
        if ($opts instanceof JsUndefined) {
            return false;
        }
        if ($opts instanceof JsBoolean) {
            return $opts->value;
        }
        if ($opts instanceof JsObject) {
            return TypeConversion::toBoolean($opts->get('capture'));
        }
        return TypeConversion::toBoolean($opts);
    }

    /**
     * Whether $listener can be used as an EventListener callback.
     */
    private static function isAcceptableListener(JsValue $listener): bool
    {
        // Per WebIDL EventListener is a callback INTERFACE: either a
        // direct callable, or any object (handleEvent looked up at call).
        return $listener instanceof JsFunction || $listener instanceof JsObject;
    }

    /**
     * Duck-typed AbortSignal probe: aborted is true.
     */
    private static function signalIsAborted(JsObject $signal): bool
    {
        if (!$signal->has('aborted')) {
            return false;
        }
        return TypeConversion::toBoolean($signal->get('aborted'));
    }

    // ------------------------------------------------------------------
    // Event
    // ------------------------------------------------------------------

    private static function createEventConstructor(JsObject $proto): JsFunction
    {
        $ctor = JsFunction::fromCallable(
            'Event',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError("Constructor Event requires 'new'");
                }
                // Per WebIDL the type argument is required.
                if (!isset($args[0])) {
                    throw new TypeError(
                        "Failed to construct 'Event': 1 argument required, but only 0 present."
                    );
                }

                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsObject) {
                    $ntProto = $newTarget->get('prototype');
                    $this_->setPrototype($ntProto instanceof JsObject ? $ntProto : $proto);
                }

                $type = TypeConversion::toString($args[0]);

                $bubbles = false;
                $cancelable = false;
                $composed = false;
                $init = $args[1] ?? JsUndefined::instance();
                if ($init instanceof JsObject) {
                    $bubbles = TypeConversion::toBoolean($init->get('bubbles'));
                    $cancelable = TypeConversion::toBoolean($init->get('cancelable'));
                    $composed = TypeConversion::toBoolean($init->get('composed'));
                }

                self::initEventSlots($this_, $type, $bubbles, $cancelable, $composed);
                return $this_;
            },
            1,
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

        // Phase constants (and same constants on the prototype, per WebIDL).
        foreach (self::phaseConstants() as $name => $value) {
            $desc = PropertyDescriptor::data(JsNumber::of((float) $value), false, true, false);
            $ctor->defineOwnProperty($name, $desc);
            $proto->defineOwnProperty($name, $desc);
        }
        return $ctor;
    }

    /** @return array<string,int> */
    private static function phaseConstants(): array
    {
        return [
            'NONE' => 0,
            'CAPTURING_PHASE' => 1,
            'AT_TARGET' => 2,
            'BUBBLING_PHASE' => 3,
        ];
    }

    /**
     * Initialize event internal slots after the JS-visible prototype is
     * already wired up.
     */
    private static function initEventSlots(
        JsObject $obj,
        string $type,
        bool $bubbles,
        bool $cancelable,
        bool $composed,
    ): void {
        $obj->setInternalProperty(self::SLOT_IS_EVENT, true);
        $obj->setInternalProperty('[[Type]]', $type);
        $obj->setInternalProperty('[[Bubbles]]', $bubbles);
        $obj->setInternalProperty('[[Cancelable]]', $cancelable);
        $obj->setInternalProperty('[[Composed]]', $composed);
        $obj->setInternalProperty('[[Target]]', null);
        $obj->setInternalProperty('[[CurrentTarget]]', null);
        $obj->setInternalProperty('[[EventPhase]]', 0); // NONE
        $obj->setInternalProperty('[[DefaultPrevented]]', false);
        $obj->setInternalProperty('[[IsTrusted]]', false);
        // Approximate DOMHighResTimeStamp by performance.now-equivalent.
        $obj->setInternalProperty('[[TimeStamp]]', self::nowMs());
        $obj->setInternalProperty('[[Dispatched]]', false);
        $obj->setInternalProperty('[[StopPropagation]]', false);
        $obj->setInternalProperty('[[StopImmediate]]', false);
    }

    private static function nowMs(): float
    {
        // Phasis has no realm-wide time origin baseline visible here; the
        // absolute timestamp is fine for an Event timestamp (browsers
        // also return ms since the document's time origin and we have no
        // document). Use microtime to avoid drift across hrtime epochs.
        return microtime(true) * 1000.0;
    }

    private static function createEventPrototype(): JsObject
    {
        $proto = new JsObject();

        // Read-only data attributes — accessor pair so `event.type = "x"`
        // is silently ignored in sloppy / TypeError in strict, matching
        // how browsers expose Event attributes.
        self::defineReadOnlyGetter($proto, 'type', '[[Type]]', 'string');
        self::defineReadOnlyGetter($proto, 'target', '[[Target]]', 'nullable-object');
        self::defineReadOnlyGetter($proto, 'currentTarget', '[[CurrentTarget]]', 'nullable-object');
        self::defineReadOnlyGetter($proto, 'eventPhase', '[[EventPhase]]', 'number');
        self::defineReadOnlyGetter($proto, 'bubbles', '[[Bubbles]]', 'boolean');
        self::defineReadOnlyGetter($proto, 'cancelable', '[[Cancelable]]', 'boolean');
        self::defineReadOnlyGetter($proto, 'defaultPrevented', '[[DefaultPrevented]]', 'boolean');
        self::defineReadOnlyGetter($proto, 'composed', '[[Composed]]', 'boolean');
        self::defineReadOnlyGetter($proto, 'isTrusted', '[[IsTrusted]]', 'boolean');
        self::defineReadOnlyGetter($proto, 'timeStamp', '[[TimeStamp]]', 'number');

        // composedPath() → no DOM tree, so always [target] when dispatched,
        // [] otherwise.
        $composedPath = JsFunction::fromCallable(
            'composedPath',
            static function (JsValue $this_): JsValue {
                $self = self::requireEvent($this_, 'composedPath');
                $target = $self->getInternalProperty('[[CurrentTarget]]');
                return self::singletonArray($target instanceof JsObject ? $target : null);
            },
            0,
        );
        $composedPath->setNonConstructable();
        $proto->defineOwnProperty(
            'composedPath',
            PropertyDescriptor::data($composedPath, true, false, true),
        );

        $stopProp = JsFunction::fromCallable(
            'stopPropagation',
            static function (JsValue $this_): JsValue {
                $self = self::requireEvent($this_, 'stopPropagation');
                $self->setInternalProperty('[[StopPropagation]]', true);
                return JsUndefined::instance();
            },
            0,
        );
        $stopProp->setNonConstructable();
        $proto->defineOwnProperty(
            'stopPropagation',
            PropertyDescriptor::data($stopProp, true, false, true),
        );

        $stopImm = JsFunction::fromCallable(
            'stopImmediatePropagation',
            static function (JsValue $this_): JsValue {
                $self = self::requireEvent($this_, 'stopImmediatePropagation');
                $self->setInternalProperty('[[StopPropagation]]', true);
                $self->setInternalProperty('[[StopImmediate]]', true);
                return JsUndefined::instance();
            },
            0,
        );
        $stopImm->setNonConstructable();
        $proto->defineOwnProperty(
            'stopImmediatePropagation',
            PropertyDescriptor::data($stopImm, true, false, true),
        );

        $preventDefault = JsFunction::fromCallable(
            'preventDefault',
            static function (JsValue $this_): JsValue {
                $self = self::requireEvent($this_, 'preventDefault');
                if ($self->getInternalProperty('[[Cancelable]]') === true) {
                    $self->setInternalProperty('[[DefaultPrevented]]', true);
                }
                return JsUndefined::instance();
            },
            0,
        );
        $preventDefault->setNonConstructable();
        $proto->defineOwnProperty(
            'preventDefault',
            PropertyDescriptor::data($preventDefault, true, false, true),
        );

        // Phase constants are added on top of this prototype by the
        // constructor builder so the constructor function exposes them too.

        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Event'), false, false, true),
        );
        return $proto;
    }

    /**
     * Define a read-only attribute getter on the Event prototype that
     * pulls $slot out of internal slots and re-boxes by $kind.
     */
    private static function defineReadOnlyGetter(
        JsObject $proto,
        string $name,
        string $slot,
        string $kind,
    ): void {
        $getter = JsFunction::fromCallable(
            'get ' . $name,
            static function (JsValue $this_) use ($name, $slot, $kind): JsValue {
                $self = self::requireEvent($this_, $name);
                $value = $self->getInternalProperty($slot);
                return self::boxSlotValue($value, $kind);
            },
            0,
        );
        $getter->setNonConstructable();
        $proto->defineOwnProperty(
            $name,
            PropertyDescriptor::accessor($getter, null, false, true),
        );
    }

    private static function boxSlotValue(mixed $value, string $kind): JsValue
    {
        return match ($kind) {
            'string' => new JsString((string) ($value ?? '')),
            'boolean' => JsBoolean::of((bool) $value),
            'number' => JsNumber::of((float) ($value ?? 0)),
            'nullable-object' => $value instanceof JsObject ? $value : JsNull::instance(),
            default => JsUndefined::instance(),
        };
    }

    private static function requireEvent(JsValue $obj, string $method): JsObject
    {
        if (!$obj instanceof JsObject || $obj->getInternalProperty(self::SLOT_IS_EVENT) !== true) {
            throw new TypeError(
                "'$method' called on an object that does not implement interface Event"
            );
        }
        return $obj;
    }

    /**
     * Build a 1-element JS array containing $item (or empty if null).
     * Resolves Array.prototype from the current realm so the result is a
     * real array — `Array.isArray(event.composedPath())` is true.
     */
    private static function singletonArray(?JsObject $item): JsValue
    {
        $arr = new \Phasis\Value\JsArray();
        if ($item !== null) {
            $arr->push($item);
        }
        return $arr;
    }
}
