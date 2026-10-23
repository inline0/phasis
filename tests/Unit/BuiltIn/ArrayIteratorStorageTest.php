<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class ArrayIteratorStorageTest extends TestCase
{
    public function testArrayIteratorReadsLiveLengthAndInheritedHoles(): void
    {
        $engine = new Engine();
        self::assertSame('[1,7,9,true,true]', $engine->eval(<<<'JS'
(() => {
    const array = [1, , 3];
    const prototype = Object.create(Array.prototype);
    prototype[1] = 7;
    Object.setPrototypeOf(array, prototype);
    const iterator = array.values();
    const first = iterator.next().value;
    const second = iterator.next().value;
    array[2] = 9;
    const third = iterator.next().value;
    array.length = 0;
    const done = iterator.next().done;
    array.push(4);
    return JSON.stringify([first, second, third, done, iterator.next().done]);
})()
JS));
    }

    public function testKeysDoNotReadElementsAndNextAdvancesBeforeGetters(): void
    {
        $engine = new Engine();
        self::assertSame('[0,1,2,true]', $engine->eval(<<<'JS'
(() => {
    const array = [0, 2];
    const iterator = array.values();
    let inner;
    Object.defineProperty(array, '0', { get() {
        inner = iterator.next().value;
        return 1;
    }});
    const key = array.keys().next().value;
    const outer = iterator.next().value;
    return JSON.stringify([key, outer, inner, iterator.next().done]);
})()
JS));
    }

    public function testCompiledBindingKeepsCachedNextAndClosesAfterWrites(): void
    {
        $engine = new Engine();
        self::assertSame('[1,4,["next","set:1","set:4","return"]]', $engine->eval(<<<'JS'
(() => {
    const array = [1, 2];
    const iterator = array.values();
    const prototype = Object.getPrototypeOf(iterator);
    const originalNext = prototype.next;
    const log = [];
    Object.defineProperty(iterator, 'next', { get() { log.push('next'); return originalNext; } });
    iterator.return = function () { log.push('return'); return {}; };
    array[Symbol.iterator] = () => iterator;
    const target = { set value(value) {
        log.push('set:' + value);
        array[1] = 4;
        prototype.next = () => { throw new Error('replacement next'); };
    }};
    function assign(value, target) {
        'use strict';
        [target.value, target.value] = value;
    }
    assign(array, target);
    return JSON.stringify([array[0], array[1], log]);
})()
JS));
    }

    public function testResultPropertiesDoNotInvokeInheritedSetters(): void
    {
        $engine = new Engine();
        self::assertSame('[3,false,0,["value","done"]]', $engine->eval(<<<'JS'
(() => {
    let calls = 0;
    Object.defineProperty(Object.prototype, 'value', { __proto__: null, configurable: true, set() { calls++; } });
    Object.defineProperty(Object.prototype, 'done', { __proto__: null, configurable: true, set() { calls++; } });
    const result = [3].values().next();
    delete Object.prototype.value;
    delete Object.prototype.done;
    return JSON.stringify([result.value, result.done, calls, Object.keys(result)]);
})()
JS));
    }

    public function testCustomNextAndIteratorFactoriesAreObserved(): void
    {
        $engine = new Engine();
        self::assertSame('[[8,9],["iterator","next","next","return"]]', $engine->eval(<<<'JS'
(() => {
    const array = [1, 2];
    const log = [];
    const prototype = Object.getPrototypeOf(array.values());
    prototype.next = function () {
        log.push('next');
        return { done: false, value: log.length + 6 };
    };
    prototype.return = function () { log.push('return'); return {}; };
    const values = array[Symbol.iterator];
    array[Symbol.iterator] = function () { log.push('iterator'); return values.call(this); };
    function pair(value) { const [a, b] = value; return [a, b]; }
    return JSON.stringify([pair(array), log]);
})()
JS));
    }
}
