<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use Phasis\Runtime\AsyncContextStorage;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsPromise;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use PHPUnit\Framework\TestCase;

/**
 * TC39 Stage 3 `AsyncContext` proposal — `Variable` + `Snapshot` +
 * propagation across Promise.then, queueMicrotask, setTimeout /
 * setInterval, and async/await.
 */
class AsyncContextTest extends TestCase
{
    public function testVariableRunSetsValueInsideOnly(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const ctx = new AsyncContext.Variable({ defaultValue: 'none' });
globalThis.__before = ctx.get();
ctx.run('req-42', () => { globalThis.__inside = ctx.get(); });
globalThis.__after = ctx.get();
JS);
        $this->assertSame('none', $engine->eval('globalThis.__before'));
        $this->assertSame('req-42', $engine->eval('globalThis.__inside'));
        $this->assertSame('none', $engine->eval('globalThis.__after'));
    }

    public function testVariableGetReturnsDefaultValueWhenNotRunning(): void
    {
        $engine = new Engine();
        $val = $engine->eval(<<<'JS'
(function () {
    const ctx = new AsyncContext.Variable({ defaultValue: 'fallback' });
    return ctx.get();
})()
JS);
        $this->assertSame('fallback', $val);
    }

    public function testVariableGetReturnsUndefinedWhenNoDefault(): void
    {
        $engine = new Engine();
        $val = $engine->eval(<<<'JS'
(function () {
    const ctx = new AsyncContext.Variable();
    return typeof ctx.get();
})()
JS);
        $this->assertSame('undefined', $val);
    }

    public function testVariableRunForwardsExtraArgs(): void
    {
        $engine = new Engine();
        $val = $engine->eval(<<<'JS'
(function () {
    const ctx = new AsyncContext.Variable();
    return ctx.run('x', (a, b) => a + b, 2, 3);
})()
JS);
        $this->assertSame(5, $val);
    }

    public function testContextPropagatesAcrossPromiseThen(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const ctx = new AsyncContext.Variable({ defaultValue: 'D' });
ctx.run('A', () => {
    Promise.resolve().then(() => { globalThis.__seen = ctx.get(); });
});
JS);
        $this->assertSame('A', $engine->eval('globalThis.__seen'));
    }

    public function testPendingPromiseReactionsRetainRegistrationContext(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const context = new AsyncContext.Variable({ defaultValue: 'outside' });
const seen = [];
let resolve, reject;
const fulfilled = new Promise(done => { resolve = done; });
const rejected = new Promise((_, fail) => { reject = fail; });
context.run('first', () => fulfilled.then(() => seen.push('then:' + context.get())));
context.run('second', () => rejected.catch(() => seen.push('catch:' + context.get())));
context.run('await', async () => { await fulfilled; seen.push('await:' + context.get()); });
fulfilled.then(() => seen.push('outside:' + context.get()));
context.run('settlement', () => { resolve(); reject(); });
JS);
        self::assertSame(
            ['then:first', 'await:await', 'outside:outside', 'catch:second'],
            $engine->eval('seen'),
        );
        self::assertSame('outside', $engine->eval('context.get()'));
    }

    public function testNativePendingFinallySubscriptionsRetainRegistrationContext(): void
    {
        new Engine();
        $storage = AsyncContextStorage::active();
        $variable = new JsObject();
        $seen = [];
        $snapshot = $storage->snapshot();
        try {
            foreach (['resolve', 'reject'] as $settle) {
                $promise = new JsPromise();
                $storage->set($variable, new JsString('registered:' . $settle));
                $child = $promise->finallyHandler([JsFunction::fromCallable('', function () use ($storage, $variable, &$seen): JsUndefined {
                    $seen[] = $storage->getRaw($variable)?->toJsString();
                    return JsUndefined::instance();
                })]);
                $storage->set($variable, new JsString('settlement'));
                $promise->{$settle}(new JsString('original'));
                JsPromise::drainMicrotasks();
                self::assertSame($promise->getState(), $child->getState());
                self::assertSame('original', $child->getResolvedValue()->toJsString());
                self::assertSame('settlement', $storage->getRaw($variable)?->toJsString());
            }
            self::assertSame(['registered:resolve', 'registered:reject'], $seen);
        } finally {
            $storage->restore($snapshot);
        }
    }

    public function testContextPropagatesAcrossQueueMicrotask(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const ctx = new AsyncContext.Variable({ defaultValue: 'D' });
ctx.run('M', () => {
    queueMicrotask(() => { globalThis.__seen = ctx.get(); });
});
JS);
        $this->assertSame('M', $engine->eval('globalThis.__seen'));
    }

    public function testContextPropagatesAcrossSetTimeout(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const ctx = new AsyncContext.Variable({ defaultValue: 'D' });
ctx.run('T', () => {
    setTimeout(() => { globalThis.__seen = ctx.get(); }, 50);
});
JS);
        $this->assertSame('T', $engine->eval('globalThis.__seen'));
    }

    public function testContextPropagatesAcrossAwait(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const ctx = new AsyncContext.Variable({ defaultValue: 'D' });
ctx.run('AWAIT', async () => {
    await Promise.resolve();
    globalThis.__seen = ctx.get();
});
JS);
        $this->assertSame('AWAIT', $engine->eval('globalThis.__seen'));
    }

    public function testNestedRunsRestoreOuterValue(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const ctx = new AsyncContext.Variable({ defaultValue: 'D' });
globalThis.__seq = [];
ctx.run('outer', () => {
    globalThis.__seq.push('enter:' + ctx.get());
    ctx.run('inner', () => {
        globalThis.__seq.push('inside:' + ctx.get());
    });
    globalThis.__seq.push('after-inner:' + ctx.get());
});
globalThis.__seq.push('done:' + ctx.get());
JS);
        $this->assertSame(
            ['enter:outer', 'inside:inner', 'after-inner:outer', 'done:D'],
            $engine->eval('globalThis.__seq'),
        );
    }

    public function testTwoVariablesAreIndependent(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const a = new AsyncContext.Variable({ defaultValue: 'A0' });
const b = new AsyncContext.Variable({ defaultValue: 'B0' });
a.run('A1', () => {
    b.run('B1', () => {
        globalThis.__a = a.get();
        globalThis.__b = b.get();
    });
});
JS);
        $this->assertSame('A1', $engine->eval('globalThis.__a'));
        $this->assertSame('B1', $engine->eval('globalThis.__b'));
    }

    public function testSnapshotWrapCapturesContext(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const ctx = new AsyncContext.Variable({ defaultValue: 'D' });
let wrapped;
ctx.run('captured', () => {
    wrapped = AsyncContext.Snapshot.wrap(() => ctx.get());
});
ctx.run('different', () => {
    globalThis.__result = wrapped();
    globalThis.__current = ctx.get();
});
JS);
        $this->assertSame('captured', $engine->eval('globalThis.__result'));
        $this->assertSame('different', $engine->eval('globalThis.__current'));
    }

    public function testNameAndDefaultValueAccessors(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const ctx = new AsyncContext.Variable({ name: 'tracker', defaultValue: 42 });
globalThis.__name = ctx.name;
globalThis.__default = ctx.defaultValue;
JS);
        $this->assertSame('tracker', $engine->eval('globalThis.__name'));
        $this->assertSame(42, $engine->eval('globalThis.__default'));
    }

    public function testRunWithNonFunctionThrows(): void
    {
        $engine = new Engine();
        $threw = $engine->eval(<<<'JS'
(function () {
    const ctx = new AsyncContext.Variable();
    try { ctx.run('x', 'not-a-function'); return false; }
    catch (e) { return e instanceof TypeError; }
})()
JS);
        $this->assertTrue($threw);
    }

    public function testLazyAsyncContextIsExposed(): void
    {
        $engine = new Engine();
        $this->assertSame('object', $engine->eval('typeof AsyncContext'));
        $this->assertSame('function', $engine->eval('typeof AsyncContext.Variable'));
        $this->assertSame('function', $engine->eval('typeof AsyncContext.Snapshot'));
    }
}
