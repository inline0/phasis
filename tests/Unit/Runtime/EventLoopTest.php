<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use Phasis\Exceptions\TypeError;
use PHPUnit\Framework\TestCase;

/**
 * setTimeout / setInterval / clearTimeout / clearInterval /
 * queueMicrotask semantics, plus the Engine-level runEventLoop /
 * tickEventLoop / pendingTaskCount embedder surface.
 *
 * The loop is virtual-time when driven via Engine::eval —
 * `setTimeout(cb, 5000)` does not sleep PHP; the loop advances its
 * clock to the deadline so the program finishes immediately. Real-
 * time embedders drive the loop themselves via tickEventLoop().
 */
class EventLoopTest extends TestCase
{
    public function testQueueMicrotaskRunsAfterCurrentTaskBeforeReturn(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
            globalThis.__log = [];
            queueMicrotask(() => globalThis.__log.push('micro'));
            globalThis.__log.push('sync');
        JS);
        $this->assertSame(['sync', 'micro'], $engine->eval('globalThis.__log'));
    }

    public function testSetTimeoutZeroFiresAfterSyncCode(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
            globalThis.__log = [];
            setTimeout(() => globalThis.__log.push('timer'), 0);
            globalThis.__log.push('sync');
        JS);
        $this->assertSame(['sync', 'timer'], $engine->eval('globalThis.__log'));
    }

    public function testTimersFireInDeadlineOrderNotInsertionOrder(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
            globalThis.__log = [];
            setTimeout(() => globalThis.__log.push('100'), 100);
            setTimeout(() => globalThis.__log.push('10'),  10);
            setTimeout(() => globalThis.__log.push('50'),  50);
        JS);
        $this->assertSame(['10', '50', '100'], $engine->eval('globalThis.__log'));
    }

    public function testClearTimeoutCancelsPendingTimer(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
            globalThis.__log = [];
            const id = setTimeout(() => globalThis.__log.push('nope'), 0);
            clearTimeout(id);
            globalThis.__log.push('done');
        JS);
        $this->assertSame(['done'], $engine->eval('globalThis.__log'));
    }

    public function testSetIntervalFiresRepeatedlyUntilClearInterval(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
            globalThis.__count = 0;
            const id = setInterval(() => {
                globalThis.__count++;
                if (globalThis.__count >= 3) clearInterval(id);
            }, 10);
        JS);
        $this->assertSame(3, $engine->eval('globalThis.__count'));
    }

    public function testMicrotasksDrainBeforeNextMacrotask(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
            globalThis.__log = [];
            setTimeout(() => globalThis.__log.push('timer'), 0);
            queueMicrotask(() => globalThis.__log.push('micro'));
            globalThis.__log.push('sync');
        JS);
        $this->assertSame(['sync', 'micro', 'timer'], $engine->eval('globalThis.__log'));
    }

    public function testNestedSetTimeoutSchedulesNewMacrotask(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
            globalThis.__log = [];
            setTimeout(() => {
                globalThis.__log.push('a');
                setTimeout(() => globalThis.__log.push('b'), 0);
                globalThis.__log.push('a-after-schedule');
            }, 0);
        JS);
        $this->assertSame(['a', 'a-after-schedule', 'b'], $engine->eval('globalThis.__log'));
    }

    public function testPromiseHandlerOrderingRelativeToTimers(): void
    {
        // Promise.then is a microtask; setTimeout is a macrotask.
        // Microtasks drain to completion between macrotasks.
        $engine = new Engine();
        $engine->eval(<<<'JS'
            globalThis.__log = [];
            Promise.resolve().then(() => globalThis.__log.push('promise'));
            setTimeout(() => globalThis.__log.push('timer'), 0);
            globalThis.__log.push('sync');
        JS);
        $this->assertSame(['sync', 'promise', 'timer'], $engine->eval('globalThis.__log'));
    }

    public function testEngineEvalRunsLoopToCompletion(): void
    {
        // After eval returns, no pending tasks should remain — the
        // virtual clock should have advanced past every scheduled
        // deadline.
        $engine = new Engine();
        $engine->eval('setTimeout(() => {}, 99999);');
        $this->assertSame(0, $engine->pendingTaskCount());
    }

    public function testRunawaySetIntervalIsBounded(): void
    {
        // An interval that never clears must NOT hang Engine::eval.
        // The runUntilEmpty iteration cap throws so the embedder
        // gets a clear error instead of a wedge.
        $engine = new Engine();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('runaway setInterval');
        $engine->eval('setInterval(() => {}, 10);');
    }

    public function testSetTimeoutWithNonFunctionThrowsTypeError(): void
    {
        $engine = new Engine();
        $this->expectException(TypeError::class);
        $engine->eval('setTimeout("not a function", 0);');
    }

    public function testQueueMicrotaskWithNonFunctionThrowsTypeError(): void
    {
        $engine = new Engine();
        $this->expectException(TypeError::class);
        $engine->eval('queueMicrotask("not a function");');
    }

    public function testClearOnUnknownIdIsSilent(): void
    {
        // clearTimeout / clearInterval on a stale or unknown id is
        // a no-op per spec; must not throw.
        $engine = new Engine();
        $engine->eval(<<<'JS'
            clearTimeout(99999);
            clearInterval(99999);
            globalThis.__ok = true;
        JS);
        $this->assertTrue($engine->eval('globalThis.__ok'));
    }

    public function testAwaitDelayPattern(): void
    {
        // The standard `await new Promise(r => setTimeout(r, ms))`
        // delay pattern. Verifies that the await suspension resumes
        // via the timer-scheduled microtask.
        $engine = new Engine();
        $engine->eval(<<<'JS'
            globalThis.__seq = [];
            (async function () {
                globalThis.__seq.push('before');
                await new Promise(resolve => setTimeout(resolve, 50));
                globalThis.__seq.push('after');
            })();
        JS);
        $this->assertSame(['before', 'after'], $engine->eval('globalThis.__seq'));
    }

    public function testLazyAccessorRegistersTimerNames(): void
    {
        // Before any read, the names should be present on globalThis
        // (lazy placeholder), and typeof should report 'function'.
        $engine = new Engine();
        $this->assertSame(
            'function,function,function,function,function',
            $engine->eval(
                '[typeof setTimeout, typeof setInterval, typeof clearTimeout, typeof clearInterval, typeof queueMicrotask].join(",")'
            ),
        );
    }

    public function testPendingTaskCountFallsToZeroAfterEval(): void
    {
        // The virtual clock advances past any scheduled deadline so
        // every timer fires before eval returns. Even a "five-minute"
        // delay drains immediately.
        $engine = new Engine();
        $engine->eval('for (let i = 0; i < 3; i++) setTimeout(() => {}, 60000 + i);');
        $this->assertSame(0, $engine->pendingTaskCount());
    }
}
