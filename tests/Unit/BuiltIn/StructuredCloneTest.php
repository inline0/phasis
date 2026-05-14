<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the global structuredClone(value, options?) function.
 *
 * Covers the HTML §2.7 StructuredClone algorithm:
 *   https://html.spec.whatwg.org/multipage/structured-data.html#structured-cloning
 *
 * NOTE on DataCloneError: this worktree does not yet wire up
 * DOMException as a global (Subagent A is implementing that
 * concurrently). The implementation under test probes for the
 * DOMException constructor at runtime and falls back to a JS
 * Error-shaped object with `name === 'DataCloneError'`. The tests
 * below assert on `e.name === 'DataCloneError'` so they remain
 * green both before and after DOMException lands; the reviewer
 * can later add `e instanceof DOMException` once it exists.
 */
class StructuredCloneTest extends TestCase
{
    private function eval(string $source): mixed
    {
        $engine = new Engine();
        return $engine->eval($source);
    }

    public function testPrimitivesPassThroughUnchanged(): void
    {
        self::assertSame(
            true,
            $this->eval(<<<'JS'
                const cases = [undefined, null, true, false, 0, -0, 42, 3.14, NaN, Infinity, -Infinity, "", "hello", 1n, 9007199254740993n];
                let ok = true;
                for (const v of cases) {
                    const c = structuredClone(v);
                    // Use Object.is so NaN === NaN succeeds.
                    if (!Object.is(c, v)) {
                        ok = false;
                        break;
                    }
                }
                ok;
            JS),
        );
    }

    public function testDeepClonesPlainObject(): void
    {
        self::assertSame(
            true,
            $this->eval(<<<'JS'
                const a = {x: 1, nested: {y: 2, deeper: {z: 3}}};
                const b = structuredClone(a);
                b.nested.deeper.z = 99;
                a !== b && b.x === 1 && b.nested.y === 2 && b.nested.deeper.z === 99 && a.nested.deeper.z === 3;
            JS),
        );
    }

    public function testCloneArrayDeeply(): void
    {
        self::assertSame(
            true,
            $this->eval(<<<'JS'
                const a = [1, 2, {n: true}, [10, 20]];
                const b = structuredClone(a);
                b[2].n = false;
                b[3][0] = 999;
                Array.isArray(b) && b.length === 4 && a !== b && a[2].n === true && a[3][0] === 10 && b[2].n === false && b[3][0] === 999;
            JS),
        );
    }

    public function testCycleIsPreserved(): void
    {
        self::assertSame(
            true,
            $this->eval(<<<'JS'
                const a = {};
                a.self = a;
                const b = structuredClone(a);
                b !== a && b.self === b;
            JS),
        );
    }

    public function testSharedSubgraphIdentityPreserved(): void
    {
        self::assertSame(
            true,
            $this->eval(<<<'JS'
                const shared = {n: 1};
                const a = {p: shared, q: shared};
                const b = structuredClone(a);
                b.p === b.q && b.p !== shared;
            JS),
        );
    }

    public function testDateClonePreservesTimeValue(): void
    {
        self::assertSame(
            true,
            $this->eval(<<<'JS'
                const d = new Date(1700000000000);
                const c = structuredClone(d);
                c instanceof Date && c !== d && c.getTime() === d.getTime();
            JS),
        );
    }

    public function testRegExpClonePreservesSourceAndFlags(): void
    {
        self::assertSame(
            true,
            $this->eval(<<<'JS'
                const r = /abc.*xyz/gim;
                r.lastIndex = 5;
                const c = structuredClone(r);
                c instanceof RegExp && c !== r && c.source === r.source && c.flags === r.flags && c.lastIndex === 0;
            JS),
        );
    }

    public function testMapCloneRecurses(): void
    {
        self::assertSame(
            true,
            $this->eval(<<<'JS'
                const m = new Map();
                const obj = {x: 1};
                m.set('a', obj);
                m.set(42, 'forty-two');
                const c = structuredClone(m);
                c instanceof Map && c !== m && c.size === 2 && c.get('a').x === 1 && c.get('a') !== obj && c.get(42) === 'forty-two';
            JS),
        );
    }

    public function testSetCloneRecurses(): void
    {
        self::assertSame(
            true,
            $this->eval(<<<'JS'
                const inner = {a: 1};
                const s = new Set([1, "two", inner]);
                const c = structuredClone(s);
                let containsInner = false;
                for (const v of c) {
                    if (typeof v === 'object' && v !== null && v.a === 1 && v !== inner) {
                        containsInner = true;
                    }
                }
                c instanceof Set && c !== s && c.size === 3 && c.has(1) && c.has("two") && containsInner;
            JS),
        );
    }

    public function testErrorClonePreservesShape(): void
    {
        self::assertSame(
            true,
            $this->eval(<<<'JS'
                const inner = new Error('inner');
                const outer = new TypeError('outer', {cause: inner});
                const c = structuredClone(outer);
                c instanceof Error
                    && c !== outer
                    && c.name === 'TypeError'
                    && c.message === 'outer'
                    && c.cause instanceof Error
                    && c.cause !== inner
                    && c.cause.message === 'inner';
            JS),
        );
    }

    public function testArrayBufferAndUint8ArrayClone(): void
    {
        self::assertSame(
            true,
            $this->eval(<<<'JS'
                const ab = new ArrayBuffer(4);
                const view = new Uint8Array(ab);
                view[0] = 0x11; view[1] = 0x22; view[2] = 0x33; view[3] = 0x44;
                const cView = structuredClone(view);
                // Mutating the clone must not touch the original.
                cView[0] = 0x99;
                cView instanceof Uint8Array && cView.buffer !== ab && cView.length === 4
                    && view[0] === 0x11 && view[1] === 0x22 && view[2] === 0x33 && view[3] === 0x44
                    && cView[0] === 0x99 && cView[1] === 0x22 && cView[2] === 0x33 && cView[3] === 0x44;
            JS),
        );
    }

    public function testTransferDetachesOriginal(): void
    {
        self::assertSame(
            true,
            $this->eval(<<<'JS'
                const ab = new ArrayBuffer(8);
                new Uint8Array(ab).set([1,2,3,4,5,6,7,8]);
                const clone = structuredClone(ab, {transfer: [ab]});
                // Spec: after transfer, source buffer is detached and reports length 0.
                clone instanceof ArrayBuffer && clone !== ab && clone.byteLength === 8 && ab.byteLength === 0
                    && new Uint8Array(clone)[0] === 1 && new Uint8Array(clone)[7] === 8;
            JS),
        );
    }

    public function testTransferredBufferSharedAcrossGraph(): void
    {
        self::assertSame(
            true,
            $this->eval(<<<'JS'
                // When a buffer is in the transfer list and referenced more than
                // once in the value graph, every reference in the clone must
                // point at the single new (transferred) buffer.
                const ab = new ArrayBuffer(4);
                new Uint8Array(ab)[0] = 0x55;
                const value = {a: ab, b: new Uint8Array(ab)};
                const c = structuredClone(value, {transfer: [ab]});
                c.a instanceof ArrayBuffer && c.b instanceof Uint8Array && c.b.buffer === c.a && c.b[0] === 0x55 && ab.byteLength === 0;
            JS),
        );
    }

    public function testThrowsOnFunction(): void
    {
        self::assertSame(
            'DataCloneError',
            $this->eval(<<<'JS'
                let name = null;
                try {
                    structuredClone(function () {});
                } catch (e) {
                    name = e.name;
                }
                name;
            JS),
        );
    }

    public function testThrowsOnSymbol(): void
    {
        self::assertSame(
            'DataCloneError',
            $this->eval(<<<'JS'
                let name = null;
                try {
                    structuredClone(Symbol('x'));
                } catch (e) {
                    name = e.name;
                }
                name;
            JS),
        );
    }

    public function testThrowsOnWellKnownSymbol(): void
    {
        self::assertSame(
            'DataCloneError',
            $this->eval(<<<'JS'
                let name = null;
                try {
                    structuredClone(Symbol.iterator);
                } catch (e) {
                    name = e.name;
                }
                name;
            JS),
        );
    }

    public function testThrowsOnWeakMap(): void
    {
        self::assertSame(
            'DataCloneError',
            $this->eval(<<<'JS'
                let name = null;
                try {
                    structuredClone(new WeakMap());
                } catch (e) {
                    name = e.name;
                }
                name;
            JS),
        );
    }

    public function testThrowsOnWeakSet(): void
    {
        self::assertSame(
            'DataCloneError',
            $this->eval(<<<'JS'
                let name = null;
                try {
                    structuredClone(new WeakSet());
                } catch (e) {
                    name = e.name;
                }
                name;
            JS),
        );
    }

    public function testThrowsOnWeakRef(): void
    {
        self::assertSame(
            'DataCloneError',
            $this->eval(<<<'JS'
                let name = null;
                try {
                    structuredClone(new WeakRef({}));
                } catch (e) {
                    name = e.name;
                }
                name;
            JS),
        );
    }

    public function testThrowsOnPromise(): void
    {
        self::assertSame(
            'DataCloneError',
            $this->eval(<<<'JS'
                let name = null;
                try {
                    structuredClone(Promise.resolve(1));
                } catch (e) {
                    name = e.name;
                }
                name;
            JS),
        );
    }

    public function testThrowsOnObjectWithFunctionValue(): void
    {
        self::assertSame(
            'DataCloneError',
            $this->eval(<<<'JS'
                let name = null;
                try {
                    structuredClone({fn: () => 42});
                } catch (e) {
                    name = e.name;
                }
                name;
            JS),
        );
    }

    public function testThrowsOnDetachedArrayBuffer(): void
    {
        self::assertSame(
            'DataCloneError',
            $this->eval(<<<'JS'
                const ab = new ArrayBuffer(4);
                // Detach via transfer.
                structuredClone(ab, {transfer: [ab]});
                let name = null;
                try {
                    structuredClone(ab);
                } catch (e) {
                    name = e.name;
                }
                name;
            JS),
        );
    }

    public function testPropertyAttributesAreDefaultDataDescriptors(): void
    {
        // Per spec, even non-configurable/non-writable source properties
        // become default-attribute data descriptors on the clone.
        self::assertSame(
            true,
            $this->eval(<<<'JS'
                const a = {};
                Object.defineProperty(a, 'frozen', {value: 7, writable: false, enumerable: true, configurable: false});
                const b = structuredClone(a);
                const desc = Object.getOwnPropertyDescriptor(b, 'frozen');
                desc.value === 7 && desc.writable === true && desc.enumerable === true && desc.configurable === true;
            JS),
        );
    }

    public function testSkipsNonEnumerableAndSymbolProperties(): void
    {
        self::assertSame(
            true,
            $this->eval(<<<'JS'
                const sym = Symbol('s');
                const a = {visible: 1};
                Object.defineProperty(a, 'hidden', {value: 'x', enumerable: false});
                a[sym] = 'sym-value';
                const b = structuredClone(a);
                b.visible === 1 && !('hidden' in b) && b[sym] === undefined;
            JS),
        );
    }
}
