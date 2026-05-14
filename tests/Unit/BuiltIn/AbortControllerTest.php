<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

/**
 * AbortController coverage for Phase 4 of the Fetch Pack.
 *
 * AbortController is the user-facing handle that owns an AbortSignal:
 *  - controller.signal returns a freshly minted AbortSignal
 *  - controller.abort(reason?) flips signal.aborted to true
 *  - calling abort() a second time is a no-op (idempotent)
 *
 * AbortSignal-side semantics (event dispatch, statics, etc.) are
 * covered separately in AbortSignalTest.
 */
class AbortControllerTest extends TestCase
{
    public function testAbortControllerGlobalIsDefined(): void
    {
        $engine = new Engine();
        $this->assertSame('function', $engine->eval('typeof AbortController;'));
    }

    public function testControllerExposesFreshSignal(): void
    {
        // Each `new AbortController()` produces a paired signal whose
        // .aborted is false and .reason is undefined.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const c = new AbortController();
const s = c.signal;
[s.aborted, s.reason === undefined, typeof s, c.signal === s];
JS);
        $this->assertSame([false, true, 'object', true], $result);
    }

    public function testAbortFlipsSignalAborted(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const c = new AbortController();
const before = c.signal.aborted;
c.abort();
const after = c.signal.aborted;
[before, after];
JS);
        $this->assertSame([false, true], $result);
    }

    public function testAbortWithCustomReasonPreservesIt(): void
    {
        // Per spec, signal.reason === reason argument when one is supplied
        // (no defaulting to DOMException kicks in).
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const c = new AbortController();
const reason = { kind: "boom" };
c.abort(reason);
[c.signal.reason === reason, c.signal.reason.kind];
JS);
        $this->assertSame([true, 'boom'], $result);
    }

    public function testAbortWithoutReasonDefaultsToAbortErrorDomException(): void
    {
        // Per spec, an abort() with no argument MUST default to a
        // DOMException whose name is "AbortError".
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const c = new AbortController();
c.abort();
const r = c.signal.reason;
[r instanceof DOMException, r.name, typeof r.message === "string"];
JS);
        $this->assertSame([true, 'AbortError', true], $result);
    }

    public function testReentrantAbortIsNoOp(): void
    {
        // The spec disallows a second abort from overwriting reason or
        // re-firing listeners. The first abort wins.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const c = new AbortController();
let fired = 0;
c.signal.addEventListener("abort", () => { fired++; });
c.abort("first");
c.abort("second");
[c.signal.reason, fired];
JS);
        $this->assertSame(['first', 1], $result);
    }

    public function testAbortControllerHasNoOwnArguments(): void
    {
        // The constructor takes no arguments; calling it with extras
        // is allowed but does not affect state.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const c = new AbortController("ignored", "args");
[c.signal.aborted, typeof c.signal];
JS);
        $this->assertSame([false, 'object'], $result);
    }

    public function testAbortControllerRequiresNew(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
let caught = false;
try { AbortController(); } catch (e) { caught = e instanceof TypeError; }
caught;
JS);
        $this->assertTrue($result);
    }

    public function testSignalGetterReturnsTheSameInstanceAcrossReads(): void
    {
        // Per spec, controller.signal is a settable[/cached] slot, not a
        // factory; repeated reads must return the same JS object so an
        // addEventListener attached before abort is honored after abort.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const c = new AbortController();
const a = c.signal;
const b = c.signal;
a === b;
JS);
        $this->assertTrue($result);
    }

    public function testAbortFromControllerFiresSignalEventListener(): void
    {
        // The listener is attached to signal, then controller.abort() is
        // what triggers it — controller dispatch is mediated through the
        // signal.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
const c = new AbortController();
const seen = [];
c.signal.addEventListener("abort", (e) => seen.push(["type", e.type]));
c.abort();
seen;
JS);
        $this->assertSame([['type', 'abort']], $result);
    }
}
