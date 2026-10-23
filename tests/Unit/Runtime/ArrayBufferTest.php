<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use Phasis\Exceptions\RangeError;
use PHPUnit\Framework\TestCase;

class ArrayBufferTest extends TestCase
{
    public function testHugeAllocationThrowsRangeErrorInsteadOfFatal(): void
    {
        $engine = new Engine();

        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('Invalid array buffer length');

        $engine->eval('new ArrayBuffer(7 * 1125899906842624);');
    }

    public function testLengthUsesToIndexValidation(): void
    {
        $engine = new Engine();

        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('Invalid index');

        $engine->eval('new ArrayBuffer(Infinity);');
    }

    public function testImmutableSnapshotsKeepIndependentStorageAndRejectWritesBeforeCoercion(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
            const source = new Uint8Array([1, 2, 3]);
            const snapshot = source.buffer.sliceToImmutable(1);
            source[1] = 9;
            const bytes = new Uint8Array(snapshot);
            let coercions = 0;
            const poison = {valueOf() { coercions++; throw new Error('coerced'); }};
            const mutations = [
                () => bytes.fill(poison),
                () => bytes.set([], poison),
                () => new DataView(snapshot).setUint8(poison, poison),
                () => Atomics.add(bytes, poison, poison),
                () => { 'use strict'; bytes[0] = poison; },
                () => snapshot.transfer(),
            ];
            const errors = mutations.map(mutate => {
                try { mutate(); return false; } catch (e) { return e instanceof TypeError; }
            });
            const desc = Object.getOwnPropertyDescriptor(bytes, '0');
            JSON.stringify([
                snapshot.immutable, snapshot.resizable, Array.from(bytes), errors, coercions,
                desc.writable, desc.configurable, Reflect.set(bytes, '0', poison),
                Reflect.defineProperty(bytes, '0', {value: 2}),
                Reflect.defineProperty(bytes, '0', {value: 4}), Array.from(bytes.subarray(1)),
            ]);
            JS);

        self::assertSame(
            '[true,false,[2,3],[true,true,true,true,true,true],0,false,false,false,true,false,[3]]',
            $result,
        );
    }

    public function testImmutableTransferIgnoresSpeciesAndZeroFillsGrowth(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
            const source = new Uint8Array([7, 8]).buffer;
            Object.defineProperty(source, 'constructor', {get() {throw new Error('species');}});
            const result = source.transferToImmutable(4);
            JSON.stringify([source.detached, result.immutable, Array.from(new Uint8Array(result))]);
            JS);

        self::assertSame('[true,true,[7,8,0,0]]', $result);
    }

    public function testAtomicAccessSeparatesWitnessLengthFromStorageRevalidation(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
            const buffer = new ArrayBuffer(4, {maxByteLength: 8});
            const view = new Int32Array(buffer);
            const notified = Atomics.notify(view, {valueOf() {buffer.resize(0); return 0;}});
            buffer.resize(4);
            let calls = 0;
            const value = {valueOf() {calls++; buffer.resize(0); return 12;}};
            let rejected = false;
            try { Atomics.store(view, 0, value); } catch (e) { rejected = e instanceof RangeError; }
            buffer.resize(4);
            let storedCalls = 0;
            Atomics.store(view, 0, {valueOf() {storedCalls++; return 5;}});
            JSON.stringify([notified, calls, rejected, storedCalls, view[0]]);
            JS);

        self::assertSame('[0,1,true,1,5]', $result);
    }

    public function testBase64StopsWhenDestinationIsFullButRejectsMalformedPadding(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
            const bytes = new Uint8Array(3);
            const full = bytes.setFromBase64('AQID#');
            const empty = new Uint8Array(0).setFromBase64('#');
            let rejected = 0;
            for (const input of ['=', 'A=', 'AAAA=']) {
                try { Uint8Array.fromBase64(input, {lastChunkHandling: 'stop-before-partial'}); }
                catch (e) { if (e instanceof SyntaxError) rejected++; }
            }
            JSON.stringify([full, empty, Array.from(bytes), rejected]);
            JS);

        self::assertSame(
            '[{"read":4,"written":3},{"read":0,"written":0},[1,2,3],3]',
            $result,
        );
    }

    public function testAtomicOperandsAreReducedBeforeHostIntegerArithmetic(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
            const huge = 9223372036854774784;
            const bytes = new Uint32Array([4294967295]);
            const added = Atomics.add(bytes, 0, huge);
            const sum = bytes[0];
            bytes[0] = 4294967295;
            const subtracted = Atomics.sub(bytes, 0, huge);
            const difference = bytes[0];
            const stored = Atomics.store(bytes, 0, 1e20);
            const storedBits = bytes[0];
            const exchanged = Atomics.exchange(bytes, 0, huge);
            const compared = Atomics.compareExchange(bytes, 0, huge, 1e20);
            const replacedBits = bytes[0];
            const nonFinite = [Infinity, -Infinity, NaN].map(value => {
                bytes[0] = 3;
                const old = Atomics.add(bytes, 0, value);
                const unchanged = bytes[0];
                bytes[0] = 0;
                Atomics.compareExchange(bytes, 0, value, 7);
                return [old, unchanged, bytes[0], Atomics.isLockFree(value)];
            });
            JSON.stringify([
                added, sum, subtracted, difference, stored, storedBits,
                exchanged, compared, replacedBits, nonFinite,
            ]);
            JS);

        self::assertSame(
            '[4294967295,4294966271,4294967295,1023,100000000000000000000,1661992960,'
            . '1661992960,4294966272,1661992960,[[3,3,7,false],[3,3,7,false],[3,3,7,false]]]',
            $result,
        );
    }

    public function testSliceCopiesLiveBytesAfterSpeciesAndPreservesUncopiedDestination(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
            const source = new ArrayBuffer(4, {maxByteLength: 8});
            const view = new Uint8Array(source);
            view.set([1, 2, 3, 4]);
            source.constructor = {[Symbol.species]: function(length) {
                source.resize(2);
                view[1] = 9;
                const result = new ArrayBuffer(length);
                new Uint8Array(result).fill(7);
                return result;
            }};
            const copied = Array.from(new Uint8Array(source.slice(1)));
            delete source.constructor;
            source.resize(4);
            view.set([1, 2, 3, 4]);
            const shrunk = source.slice({valueOf() {source.resize(1); return 0;}});
            source.resize(4);
            view.set([1, 2, 3, 4]);
            const grown = source.slice({valueOf() {source.resize(8); return -1;}});
            JSON.stringify([copied, Array.from(new Uint8Array(shrunk)), Array.from(new Uint8Array(grown))]);
            JS);

        self::assertSame('[[9,7,7],[1,0,0,0],[4]]', $result);
    }

    public function testTransfersBrandBeforeCoercionAndUseIntrinsicPrototypes(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
            class Derived extends ArrayBuffer {}
            const outcomes = [];
            for (const method of ['transfer', 'transferToFixedLength', 'transferToImmutable']) {
                const poison = {valueOf() {throw 42;}};
                try { ArrayBuffer.prototype[method].call(new SharedArrayBuffer(1), poison); }
                catch (error) { outcomes.push(error instanceof TypeError); }
                const detached = new ArrayBuffer(1);
                detached.transfer();
                try { detached[method](poison); }
                catch (error) { outcomes.push(error === 42); }
                const source = new Derived(2);
                Object.defineProperty(source, 'constructor', {get() {throw new Error('species');}});
                const result = source[method](3);
                outcomes.push(Object.getPrototypeOf(result) === ArrayBuffer.prototype);
            }
            const source = new ArrayBuffer(2, {maxByteLength: 2});
            try { source.transfer(3); } catch (error) { outcomes.push(error instanceof RangeError); }
            outcomes.push(!source.detached);
            const fixed = source.transferToFixedLength(3);
            outcomes.push(fixed.byteLength === 3 && !fixed.resizable);
            JSON.stringify(outcomes);
            JS);

        self::assertSame('[true,true,true,true,true,true,true,true,true,true,true,true]', $result);
    }
}
