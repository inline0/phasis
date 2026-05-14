<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

/**
 * EventTarget + Event coverage for Phase 1 of the Fetch Pack.
 *
 * These are foundation classes (AbortSignal, fetch, etc. extend them),
 * so the test surface focuses on the WHATWG-mandated invariants the
 * downstream subclasses depend on: registration semantics, dispatch
 * ordering, listener dedup, mid-dispatch removal, once/passive options,
 * handleEvent dispatch form, and Event constructor init dict parsing.
 */
class EventTargetTest extends TestCase
{
    public function testAddAndDispatchFiresListener(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const t = new EventTarget();
let fired = 0;
let receivedType = null;
t.addEventListener("ping", e => { fired++; receivedType = e.type; });
t.dispatchEvent(new Event("ping"));
t.dispatchEvent(new Event("ping"));
[fired, receivedType];
JS);
        $this->assertSame([2, 'ping'], $result);
    }

    public function testRemoveEventListenerStopsFiring(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const t = new EventTarget();
let fired = 0;
const fn = () => { fired++; };
t.addEventListener("p", fn);
t.dispatchEvent(new Event("p"));
t.removeEventListener("p", fn);
t.dispatchEvent(new Event("p"));
fired;
JS);
        $this->assertSame(1, $result);
    }

    public function testOnceOptionAutoRemovesAfterFirstFire(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const t = new EventTarget();
let fired = 0;
t.addEventListener("x", () => { fired++; }, { once: true });
t.dispatchEvent(new Event("x"));
t.dispatchEvent(new Event("x"));
t.dispatchEvent(new Event("x"));
fired;
JS);
        $this->assertSame(1, $result);
    }

    public function testHandleEventObjectListener(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const t = new EventTarget();
const log = [];
const listener = {
  handleEvent(e) { log.push(["got", e.type, this === listener]); }
};
t.addEventListener("evt", listener);
t.dispatchEvent(new Event("evt"));
log;
JS);
        $this->assertSame([['got', 'evt', true]], $result);
    }

    public function testPreventDefaultOnlyAffectsCancelable(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const t = new EventTarget();
t.addEventListener("c", e => { e.preventDefault(); });
t.addEventListener("n", e => { e.preventDefault(); });

const cancelable = new Event("c", { cancelable: true });
const notCancelable = new Event("n");

const cReturn = t.dispatchEvent(cancelable);
const nReturn = t.dispatchEvent(notCancelable);

[
  cancelable.defaultPrevented,
  notCancelable.defaultPrevented,
  cReturn,
  nReturn,
];
JS);
        // Cancelable: defaultPrevented = true, dispatchEvent returns false.
        // Non-cancelable: preventDefault is a no-op, both stay true.
        $this->assertSame([true, false, false, true], $result);
    }

    public function testStopImmediatePropagationBlocksLaterListeners(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const t = new EventTarget();
const log = [];
t.addEventListener("z", e => { log.push("a"); e.stopImmediatePropagation(); });
t.addEventListener("z", () => { log.push("b"); });
t.addEventListener("z", () => { log.push("c"); });
t.dispatchEvent(new Event("z"));
log;
JS);
        $this->assertSame(['a'], $result);
    }

    public function testEventConstructorInitDictSetsAttributes(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const e1 = new Event("a");
const e2 = new Event("b", { bubbles: true, cancelable: true, composed: true });
const e3 = new Event("c", { bubbles: true });
[
  [e1.type, e1.bubbles, e1.cancelable, e1.composed, e1.defaultPrevented],
  [e2.type, e2.bubbles, e2.cancelable, e2.composed],
  [e3.type, e3.bubbles, e3.cancelable, e3.composed],
];
JS);
        $this->assertSame([
            ['a', false, false, false, false],
            ['b', true, true, true],
            ['c', true, false, false],
        ], $result);
    }

    public function testListenersAreDeduplicatedByTypeCallbackCapture(): void
    {
        // Per WebIDL, repeated addEventListener(type, fn) with matching
        // (type, callback, capture) is a no-op.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const t = new EventTarget();
let fired = 0;
const fn = () => { fired++; };
t.addEventListener("x", fn);
t.addEventListener("x", fn);
t.addEventListener("x", fn);
t.dispatchEvent(new Event("x"));
fired;
JS);
        $this->assertSame(1, $result);
    }

    public function testDispatchEventThrowsOnSecondDispatch(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const t = new EventTarget();
const e = new Event("x");
t.dispatchEvent(e);
let msg = null;
try {
  t.dispatchEvent(e);
} catch (err) {
  msg = err instanceof TypeError;
}
msg;
JS);
        $this->assertTrue($result);
    }

    public function testDispatchEventRequiresEvent(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const t = new EventTarget();
let caught = false;
try { t.dispatchEvent(null); } catch (e) { caught = e instanceof TypeError; }
caught;
JS);
        $this->assertTrue($result);
    }

    public function testTargetAndCurrentTargetSetDuringDispatch(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const t = new EventTarget();
let captured = null;
t.addEventListener("x", function (e) {
  captured = [e.target === t, e.currentTarget === t, e.eventPhase];
});
const evt = new Event("x");
t.dispatchEvent(evt);
// After dispatch returns: target stays; currentTarget is null; phase is NONE.
[
  captured,
  evt.target === t,
  evt.currentTarget === null,
  evt.eventPhase,
];
JS);
        $this->assertSame(
            [
                [true, true, 2 /* AT_TARGET */],
                true,
                true,
                0, /* NONE */
            ],
            $result,
        );
    }

    public function testCapturePassedAsBooleanIsEquivalentToOptionsCapture(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const t = new EventTarget();
let fired = 0;
const fn = () => { fired++; };
// Add with capture=true (boolean form); removing with capture=true must work.
t.addEventListener("x", fn, true);
t.dispatchEvent(new Event("x"));
t.removeEventListener("x", fn, { capture: true });
t.dispatchEvent(new Event("x"));
fired;
JS);
        $this->assertSame(1, $result);
    }

    public function testPhaseConstantsExposedOnConstructorAndPrototype(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
[
  Event.NONE, Event.CAPTURING_PHASE, Event.AT_TARGET, Event.BUBBLING_PHASE,
  Event.prototype.NONE, Event.prototype.AT_TARGET,
];
JS);
        $this->assertSame([0, 1, 2, 3, 0, 2], $result);
    }

    public function testComposedPathReturnsTargetWhenDispatching(): void
    {
        // Phasis has no DOM tree, so composedPath inside a dispatching
        // listener is `[currentTarget]`. Outside dispatch it is `[]`.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const t = new EventTarget();
let pathDuring = null;
t.addEventListener("x", e => {
  pathDuring = e.composedPath();
});
const e = new Event("x");
const pathBefore = e.composedPath();
t.dispatchEvent(e);
const pathAfter = e.composedPath();
[
  pathBefore.length,
  pathDuring.length,
  pathDuring[0] === t,
  pathAfter.length,
];
JS);
        $this->assertSame([0, 1, true, 0], $result);
    }

    public function testSubclassExtendsEventTarget(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
class Bus extends EventTarget {
  constructor() { super(); this.tag = "bus"; }
}
const b = new Bus();
let fired = 0;
b.addEventListener("ping", () => { fired++; });
b.dispatchEvent(new Event("ping"));
[fired, b instanceof EventTarget, b instanceof Bus, b.tag];
JS);
        $this->assertSame([1, true, true, 'bus'], $result);
    }

    public function testEventConstructorRequiresType(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
let caught = false;
try { new Event(); } catch (e) { caught = e instanceof TypeError; }
caught;
JS);
        $this->assertTrue($result);
    }
}
