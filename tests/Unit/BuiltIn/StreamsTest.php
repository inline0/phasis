<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

class StreamsTest extends TestCase
{
    public function testGlobalsAreDefined(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            '[typeof ReadableStream, typeof WritableStream, typeof TransformStream,'
            . ' typeof ReadableStreamDefaultReader, typeof ReadableByteStreamController,'
            . ' typeof ReadableStreamBYOBReader, typeof ReadableStreamBYOBRequest,'
            . ' typeof CountQueuingStrategy, typeof ByteLengthQueuingStrategy,'
            . ' typeof WritableStreamDefaultController, typeof WritableStreamDefaultWriter,'
            . ' typeof TransformStreamDefaultController,'
            . ' typeof ReadableStreamDefaultController].join(",");'
        );
        $this->assertSame(
            'function,function,function,function,function,function,function,function,function,function,function,function,function',
            $result
        );
    }

    public function testReadableStreamBasicEnqueueAndRead(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'const rs = new ReadableStream({'
            . '  start(c) { c.enqueue("a"); c.enqueue("b"); c.enqueue("c"); c.close(); }'
            . '});'
            . 'const reader = rs.getReader();'
            . 'const out = [];'
            . '(async () => {'
            . '  while (true) {'
            . '    const r = await reader.read();'
            . '    if (r.done) break;'
            . '    out.push(r.value);'
            . '  }'
            . '})();'
            . 'out;'
        );
        $this->assertSame(['a', 'b', 'c'], $result);
    }

    public function testReadableStreamAsyncIteration(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'const rs = new ReadableStream({'
            . '  start(c) { c.enqueue(1); c.enqueue(2); c.enqueue(3); c.close(); }'
            . '});'
            . 'const out = [];'
            . '(async () => {'
            . '  for await (const v of rs) out.push(v);'
            . '})();'
            . 'out;'
        );
        $this->assertSame([1, 2, 3], $result);
    }

    public function testReadableStreamLockedWhileReader(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'const rs = new ReadableStream({ start(c) { c.enqueue("x"); c.close(); } });'
            . 'const beforeLocked = rs.locked;'
            . 'const r = rs.getReader();'
            . 'const afterLocked = rs.locked;'
            . 'r.releaseLock();'
            . 'const releasedLocked = rs.locked;'
            . '({ before: beforeLocked, after: afterLocked, released: releasedLocked });'
        );
        $this->assertFalse($result['before']);
        $this->assertTrue($result['after']);
        $this->assertFalse($result['released']);
    }

    public function testReadableStreamFromAsyncIterable(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'async function* gen() { yield "x"; yield "y"; yield "z"; }'
            . 'const rs = ReadableStream.from(gen());'
            . 'const out = [];'
            . '(async () => { for await (const v of rs) out.push(v); })();'
            . 'out;'
        );
        $this->assertSame(['x', 'y', 'z'], $result);
    }

    public function testReadableStreamFromSyncIterable(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'const rs = ReadableStream.from([1, 2, 3]);'
            . 'const out = [];'
            . '(async () => { for await (const v of rs) out.push(v); })();'
            . 'out;'
        );
        $this->assertSame([1, 2, 3], $result);
    }

    public function testWritableStreamWritesToSink(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'const collected = [];'
            . 'const ws = new WritableStream({ write(c) { collected.push(c); } });'
            . 'const w = ws.getWriter();'
            . 'w.write("a"); w.write("b"); w.write("c"); w.close();'
            . 'collected;'
        );
        $this->assertSame(['a', 'b', 'c'], $result);
    }

    public function testWritableStreamLocked(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'const ws = new WritableStream({ write(c) {} });'
            . 'const before = ws.locked;'
            . 'const w = ws.getWriter();'
            . 'const after = ws.locked;'
            . 'w.releaseLock();'
            . 'const released = ws.locked;'
            . '({ before, after, released });'
        );
        $this->assertFalse($result['before']);
        $this->assertTrue($result['after']);
        $this->assertFalse($result['released']);
    }

    public function testTransformStreamUppercases(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'const ts = new TransformStream({'
            . '  transform(c, controller) { controller.enqueue(c.toUpperCase()); }'
            . '});'
            . 'const src = new ReadableStream({ start(c) { c.enqueue("foo"); c.enqueue("bar"); c.close(); } });'
            . 'const out = [];'
            . '(async () => { for await (const v of src.pipeThrough(ts)) out.push(v); })();'
            . 'out;'
        );
        $this->assertSame(['FOO', 'BAR'], $result);
    }

    public function testPipeToFlowsAllChunks(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'const collected = [];'
            . 'const src = new ReadableStream({ start(c) { c.enqueue("a"); c.enqueue("b"); c.close(); } });'
            . 'const sink = new WritableStream({ write(chunk) { collected.push(chunk); } });'
            . 'src.pipeTo(sink);'
            . 'collected;'
        );
        $this->assertSame(['a', 'b'], $result);
    }

    public function testTeeProducesIdenticalBranches(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'const src = new ReadableStream({ start(c) { c.enqueue(1); c.enqueue(2); c.close(); } });'
            . 'const [a, b] = src.tee();'
            . 'const oa = []; const ob = [];'
            . '(async () => { for await (const v of a) oa.push(v); })();'
            . '(async () => { for await (const v of b) ob.push(v); })();'
            . '({ a: oa, b: ob });'
        );
        $this->assertSame([1, 2], $result['a']);
        $this->assertSame([1, 2], $result['b']);
    }

    public function testCancelMidStream(): void
    {
        $engine = new Engine();
        $engine->eval(
            'globalThis.state = { cancelReason: null };'
            . 'const src = new ReadableStream({'
            . '  start(c) { c.enqueue(1); c.enqueue(2); c.enqueue(3); },'
            . '  cancel(reason) { globalThis.state.cancelReason = reason; }'
            . '});'
            . 'const r = src.getReader();'
            . '(async () => { await r.read(); r.cancel("user-stop"); })();'
        );
        $result = $engine->eval('globalThis.state.cancelReason;');
        $this->assertSame('user-stop', $result);
    }

    public function testBackpressureBlocksPull(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'let pullCount = 0;'
            . 'const src = new ReadableStream({'
            . '  start(c) { c.enqueue("a"); c.enqueue("b"); c.enqueue("c"); },'
            . '  pull(c) { pullCount++; }'
            . '}, { highWaterMark: 1 });'
            . 'pullCount;'
        );
        // With hwm=1 and the start() enqueueing 3 chunks, the queue is over
        // hwm immediately so pull is never called during start.
        $this->assertSame(0, $result);
    }

    public function testReadableByteStreamEnqueueAndRead(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'const rs = new ReadableStream({'
            . '  type: "bytes",'
            . '  start(c) { c.enqueue(new Uint8Array([1,2,3])); c.close(); }'
            . '});'
            . 'const reader = rs.getReader();'
            . 'const out = [];'
            . '(async () => {'
            . '  while (true) {'
            . '    const r = await reader.read();'
            . '    if (r.done) break;'
            . '    out.push(Array.from(r.value));'
            . '  }'
            . '})();'
            . 'out;'
        );
        $this->assertSame([[1, 2, 3]], $result);
    }

    public function testReadableStreamBYOBReader(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'const rs = new ReadableStream({'
            . '  type: "bytes",'
            . '  start(c) { c.enqueue(new Uint8Array([10, 20, 30])); c.close(); }'
            . '});'
            . 'const reader = rs.getReader({ mode: "byob" });'
            . 'const buf = new Uint8Array(3);'
            . 'const captured = {};'
            . '(async () => {'
            . '  const r = await reader.read(buf);'
            . '  captured.len = r.value.byteLength;'
            . '  captured.done = r.done;'
            . '  captured.vals = Array.from(r.value);'
            . '})();'
            . 'captured;'
        );
        $this->assertSame(3, $result['len']);
        $this->assertFalse($result['done']);
        $this->assertSame([10, 20, 30], $result['vals']);
    }

    public function testByteLengthQueuingStrategy(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'const s = new ByteLengthQueuingStrategy({ highWaterMark: 1024 });'
            . '({ hwm: s.highWaterMark, size: s.size(new Uint8Array(5)) });'
        );
        $this->assertSame(1024, $result['hwm']);
        $this->assertSame(5, $result['size']);
    }

    public function testCountQueuingStrategy(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'const s = new CountQueuingStrategy({ highWaterMark: 4 });'
            . '({ hwm: s.highWaterMark, size: s.size("anything") });'
        );
        $this->assertSame(4, $result['hwm']);
        $this->assertSame(1, $result['size']);
    }

    public function testReadableStreamDesiredSize(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'let desired = null;'
            . 'const rs = new ReadableStream({'
            . '  start(c) { c.enqueue("a"); desired = c.desiredSize; }'
            . '}, { highWaterMark: 3 });'
            . 'desired;'
        );
        $this->assertEquals(2, $result);
    }

    public function testReaderClosedPromise(): void
    {
        $engine = new Engine();
        // Use a globalThis-attached state object so we can read it after
        // the engine drains the microtask queue.
        $engine->eval(
            'globalThis.state = { closed: false };'
            . 'const rs = new ReadableStream({ start(c) { c.close(); } });'
            . 'const r = rs.getReader();'
            . '(async () => { await r.closed; globalThis.state.closed = true; })();'
        );
        $result = $engine->eval('globalThis.state.closed;');
        $this->assertTrue($result);
    }

    public function testWriterReadyAndDesiredSize(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'const ws = new WritableStream({ write(c) {} }, { highWaterMark: 5 });'
            . 'const w = ws.getWriter();'
            . 'w.desiredSize;'
        );
        $this->assertEquals(5, $result);
    }

    public function testTransformStreamPropertiesAreReadableAndWritable(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'const ts = new TransformStream();'
            . '({ r: ts.readable.constructor.name, w: ts.writable.constructor.name });'
        );
        $this->assertSame('ReadableStream', $result['r']);
        $this->assertSame('WritableStream', $result['w']);
    }

    public function testIdentityTransformStream(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'const ts = new TransformStream();'
            . 'const src = new ReadableStream({ start(c) { c.enqueue("a"); c.enqueue("b"); c.close(); } });'
            . 'const out = [];'
            . '(async () => { for await (const v of src.pipeThrough(ts)) out.push(v); })();'
            . 'out;'
        );
        $this->assertSame(['a', 'b'], $result);
    }

    public function testCannotEnqueueAfterClose(): void
    {
        $engine = new Engine();
        $engine->eval(
            'globalThis.state = {};'
            . 'const rs = new ReadableStream({'
            . '  start(c) { c.close(); try { c.enqueue("oops"); } catch (_) { /* swallowed by start */ } }'
            . '});'
            . 'const r = rs.getReader();'
            . '(async () => { const out = await r.read(); globalThis.state.done = out.done; })();'
        );
        $result = $engine->eval('globalThis.state.done;');
        $this->assertTrue($result);
    }

    public function testErrorPropagatesToReader(): void
    {
        $engine = new Engine();
        $engine->eval(
            'globalThis.state = {};'
            . 'const err = new Error("boom");'
            . 'const rs = new ReadableStream({ start(c) { c.error(err); } });'
            . '(async () => { try { await rs.getReader().read(); } catch (e) { globalThis.state.msg = e.message; } })();'
        );
        $result = $engine->eval('globalThis.state.msg;');
        $this->assertSame('boom', $result);
    }

    public function testTwoReadersCannotBeAcquired(): void
    {
        $engine = new Engine();
        $result = $engine->eval(
            'let threw = false;'
            . 'const rs = new ReadableStream({ start(c) { c.close(); } });'
            . 'rs.getReader();'
            . 'try { rs.getReader(); } catch (e) { threw = true; }'
            . 'threw;'
        );
        $this->assertTrue($result);
    }
}
