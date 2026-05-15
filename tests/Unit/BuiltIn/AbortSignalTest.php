<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

/**
 * AbortSignal coverage for Phase 4 of the Fetch Pack.
 *
 * AbortSignal extends EventTarget — it inherits addEventListener /
 * dispatchEvent and adds:
 *   - aborted (bool, read-only)
 *   - reason (any, read-only)
 *   - onabort (function | null, settable event-handler IDL attribute)
 *   - throwIfAborted()
 *   - statics: AbortSignal.abort(reason?), AbortSignal.timeout(ms),
 *     AbortSignal.any(signals).
 *
 * Phasis limitation, documented here:
 *   AbortSignal.timeout(ms) is "lazy" — Phasis has no native setTimeout
 *   integration, so the signal stores an absolute deadline and trips
 *   the aborted state on the next attribute read after the deadline
 *   elapses. Tests cover that by usleep()ing past the deadline before
 *   probing the signal.
 */
class AbortSignalTest extends TestCase
{
    public function testAbortSignalGlobalIsDefined(): void
    {
        $engine = new Engine();
        $this->assertSame('function', $engine->eval('typeof AbortSignal;'));
    }

    public function testAbortSignalIsNotPubliclyConstructable(): void
    {
        // Per WebIDL, AbortSignal has no public constructor (`new AbortSignal()`
        // is an illegal-constructor TypeError). The statics are the only
        // legitimate way to mint one from script.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
let caught = false;
try { new AbortSignal(); } catch (e) { caught = e instanceof TypeError; }
caught;
JS);
        $this->assertTrue($result);
    }

    public function testSignalIsAlsoEventTarget(): void
    {
        // AbortSignal.prototype's [[Prototype]] must be EventTarget.prototype
        // for the WebIDL inheritance chain to work — `signal instanceof
        // EventTarget` is the user-visible probe libraries rely on.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const s = new AbortController().signal;
[s instanceof AbortSignal, s instanceof EventTarget];
JS);
        $this->assertSame([true, true], $result);
    }

    public function testThrowIfAbortedThrowsWhenAborted(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const c = new AbortController();
c.abort("nope");
let thrown = null;
try { c.signal.throwIfAborted(); } catch (e) { thrown = e; }
thrown;
JS);
        $this->assertSame('nope', $result);
    }

    public function testThrowIfAbortedIsNoOpWhenNotAborted(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const s = new AbortController().signal;
let threw = false;
try { s.throwIfAborted(); } catch (e) { threw = true; }
[threw, s.throwIfAborted() === undefined];
JS);
        $this->assertSame([false, true], $result);
    }

    public function testOnAbortCallbackFiresOnAbort(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const c = new AbortController();
let captured = null;
c.signal.onabort = (e) => { captured = e.type; };
c.abort();
captured;
JS);
        $this->assertSame('abort', $result);
    }

    public function testOnAbortCanBeReadBack(): void
    {
        // Event-handler IDL attribute: setter stores, getter returns it
        // (or null if never set / cleared with null).
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const s = new AbortController().signal;
const before = s.onabort;
const fn = () => {};
s.onabort = fn;
const after = s.onabort;
s.onabort = null;
const cleared = s.onabort;
[before, after === fn, cleared];
JS);
        $this->assertSame([null, true, null], $result);
    }

    public function testOnAbortAndAddEventListenerBothFire(): void
    {
        // Per spec, onabort and any addEventListener('abort', ...) BOTH
        // fire on the same abort.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const c = new AbortController();
const calls = [];
c.signal.addEventListener("abort", () => calls.push("listener"));
c.signal.onabort = () => calls.push("onabort");
c.abort();
calls.sort();
JS);
        $this->assertSame(['listener', 'onabort'], $result);
    }

    public function testStaticAbortReturnsPreAbortedSignal(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const s = AbortSignal.abort();
[s.aborted, s.reason instanceof DOMException, s.reason.name];
JS);
        $this->assertSame([true, true, 'AbortError'], $result);
    }

    public function testStaticAbortWithCustomReason(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const r = { tag: "boom" };
const s = AbortSignal.abort(r);
[s.aborted, s.reason === r];
JS);
        $this->assertSame([true, true], $result);
    }

    public function testStaticAbortReturnsAbortSignalInstance(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const s = AbortSignal.abort();
[s instanceof AbortSignal, s instanceof EventTarget];
JS);
        $this->assertSame([true, true], $result);
    }

    public function testStaticAnyAbortsWhenAnySourceAborts(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const a = new AbortController();
const b = new AbortController();
const any = AbortSignal.any([a.signal, b.signal]);
const before = any.aborted;
b.abort("b-reason");
[before, any.aborted, any.reason];
JS);
        $this->assertSame([false, true, 'b-reason'], $result);
    }

    public function testStaticAnyWithAlreadyAbortedSourceIsPreAborted(): void
    {
        // Per spec, if any source signal is already aborted at .any()
        // call time, the resulting signal is returned in a pre-aborted
        // state with that source's reason.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const a = new AbortController();
a.abort("first");
const b = new AbortController();
const any = AbortSignal.any([a.signal, b.signal]);
[any.aborted, any.reason];
JS);
        $this->assertSame([true, 'first'], $result);
    }

    public function testStaticAnyDispatchesAbortEventOnDependent(): void
    {
        // Aborting a source must fire `abort` on the dependent — that's
        // how `fetch(url, { signal: AbortSignal.any([...]) })` knows the
        // composite was tripped.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const a = new AbortController();
const any = AbortSignal.any([a.signal]);
let fired = 0;
any.addEventListener("abort", () => fired++);
a.abort();
fired;
JS);
        $this->assertSame(1, $result);
    }

    public function testStaticAnyWithEmptyIterableIsNonAborted(): void
    {
        // Per spec, an empty iterable produces a non-aborted signal —
        // it has no sources to trip from, but still satisfies the type.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const s = AbortSignal.any([]);
[s.aborted, s instanceof AbortSignal];
JS);
        $this->assertSame([false, true], $result);
    }

    public function testStaticAnyRejectsNonSignalElements(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
let caught = false;
try { AbortSignal.any([1, 2]); } catch (e) { caught = e instanceof TypeError; }
caught;
JS);
        $this->assertTrue($result);
    }

    public function testStaticAnyAcceptsIterableProtocolNotJustArray(): void
    {
        // .any() accepts any iterable, not just an array — Symbol.iterator
        // is sufficient.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const a = new AbortController().signal;
const b = new AbortController().signal;
function* gen() { yield a; yield b; }
const s = AbortSignal.any(gen());
s.aborted;
JS);
        $this->assertFalse($result);
    }

    public function testStaticTimeoutEventuallyAborts(): void
    {
        // Spec: AbortSignal.timeout(ms) schedules the abort via the
        // event loop — the signal is NOT aborted synchronously, even
        // for timeout(0). Engine::eval drains the loop before
        // returning, so by the time we read globalThis.__out the
        // timer has fired and the listener has captured the state.
        $engine = new Engine();
        $engine->eval(<<<'JS'
const sig = AbortSignal.timeout(0);
sig.addEventListener('abort', () => {
    globalThis.__out = [sig.aborted, sig.reason instanceof DOMException, sig.reason.name];
});
JS);
        $this->assertSame(
            [true, true, 'TimeoutError'],
            $engine->eval('globalThis.__out'),
        );
    }

    public function testStaticTimeoutWithFutureDeadlineIsNotImmediatelyAborted(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const s = AbortSignal.timeout(60_000);  // a minute out
s.aborted;
JS);
        $this->assertFalse($result);
    }

    public function testStaticTimeoutRejectsNegative(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
let caught = false;
try { AbortSignal.timeout(-1); } catch (e) { caught = e instanceof TypeError; }
caught;
JS);
        $this->assertTrue($result);
    }

    public function testAbortedAndReasonAreReadOnly(): void
    {
        // Both are accessor-only attributes (getter without setter). In
        // sloppy mode the assignment silently fails; in strict mode it
        // throws. We test the strict-mode case to be precise.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
"use strict";
const s = new AbortController().signal;
let threw = 0;
try { s.aborted = true; } catch (e) { threw++; }
try { s.reason  = "x"; } catch (e) { threw++; }
[s.aborted, s.reason === undefined, threw];
JS);
        $this->assertSame([false, true, 2], $result);
    }

    public function testListenerOptionsSignalRemovesListenerWhenSignalAborts(): void
    {
        // EventTarget's addEventListener accepts `{ signal }` — if that
        // signal aborts before the listener would have fired, the
        // listener is auto-removed. This exercises the AbortSignal x
        // EventTarget integration end-to-end.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const t = new EventTarget();
const remover = new AbortController();
let fired = 0;
t.addEventListener("p", () => { fired++; }, { signal: remover.signal });
t.dispatchEvent(new Event("p"));
remover.abort();
t.dispatchEvent(new Event("p"));
fired;
JS);
        $this->assertSame(1, $result);
    }

    public function testTimeoutFiresAbortEventExactlyOnce(): void
    {
        // The abort event must fire exactly once when the timer
        // deadline expires — repeated reads of `.aborted` or
        // `.reason` post-fire must not retrigger the algorithm.
        $engine = new Engine();
        $engine->eval(<<<'JS'
const s = AbortSignal.timeout(0);
globalThis.__fired = 0;
s.addEventListener("abort", () => {
    globalThis.__fired++;
    // Re-read after the event has fired; must not retrigger.
    void s.aborted;
    void s.reason;
});
JS);
        // After eval drained the loop, the timer has fired and the
        // listener has run exactly once.
        $this->assertSame(1, $engine->eval('globalThis.__fired'));
        // Subsequent reads still report aborted, still don't refire.
        $engine->eval('globalThis.__last = AbortSignal.timeout(0).aborted;');
        $this->assertSame(1, $engine->eval('globalThis.__fired'));
    }
}
