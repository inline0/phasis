<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

/** Iterator composition, closing, and suspension regression coverage. */
class IteratorMethodsTest extends TestCase
{
    public function testChunksWindowsAndJoinComposeWithoutSharingResultArrays(): void
    {
        $engine = new Engine();
        self::assertSame('1:2|3:4|5', $engine->eval(<<<'JS'
[1, 2, 3, 4, 5].values().chunks(2).map(chunk => chunk.join(':')).join('|')
JS));
        self::assertSame('[[1,2],[2,3],[3,4]]', $engine->eval(<<<'JS'
JSON.stringify([1, 2, 3, 4].values().windows(2).toArray())
JS));
        self::assertSame('[[1,2]]', $engine->eval(<<<'JS'
JSON.stringify([1, 2].values().windows(3, 'allow-partial').toArray())
JS));
        self::assertSame('[]', $engine->eval(<<<'JS'
JSON.stringify([1, 2].values().windows(3).toArray())
JS));
    }

    public function testValidationClosesBeforeLookingUpNextAndPreservesOriginalError(): void
    {
        $engine = new Engine();
        self::assertSame('RangeError:return', $engine->eval(<<<'JS'
(() => {
    const log = [];
    const iterator = {
        get next() { log.push('next'); },
        get return() { log.push('return'); throw new Error('cleanup'); }
    };
    try {
        Iterator.prototype.includes.call(iterator, 1, Number.MAX_SAFE_INTEGER + 1);
    } catch (error) {
        return error.name + ':' + log.join(',');
    }
})()
JS));
    }

    public function testIncludesUsesSameValueZeroAndClosesAfterSkippingMatches(): void
    {
        $engine = new Engine();
        self::assertSame('[true,3,1]', $engine->eval(<<<'JS'
(() => {
    let steps = 0;
    let closes = 0;
    const iterator = {
        __proto__: Iterator.prototype,
        next() { steps++; return { value: NaN, done: false }; },
        return() { closes++; return {}; }
    };
    return JSON.stringify([iterator.includes(NaN, 2), steps, closes]);
})()
JS));
    }

    public function testZipClosesRemainingIteratorsInReverseOrderAfterNextThrows(): void
    {
        $engine = new Engine();
        self::assertSame('original:c,a', $engine->eval(<<<'JS'
(() => {
    const log = [];
    const make = name => ({
        next() {
            if (name === 'b') throw new Error('original');
            return { value: name, done: false };
        },
        return() { log.push(name); throw new Error('cleanup'); }
    });
    const iterator = Iterator.zip([make('a'), make('b'), make('c')]);
    try { iterator.next(); } catch (error) {
        return error.message + ':' + log.join(',');
    }
})()
JS));
    }

    public function testStrictZipDoesNotReadRemainingValuesWhenCheckingExhaustion(): void
    {
        $engine = new Engine();
        self::assertSame('TypeError:1', $engine->eval(<<<'JS'
(() => {
    let closes = 0;
    const iterator = Iterator.zip([
        { next() { return { done: true }; } },
        {
            next() {
                return { done: false, get value() { throw new Error('unexpected value'); } };
            },
            return() { closes++; return {}; }
        }
    ], { mode: 'strict' });
    try { iterator.next(); } catch (error) { return error.name + ':' + closes; }
})()
JS));
    }

    public function testZipKeyedPreservesSymbolKeysAndCreatesNullPrototypeResults(): void
    {
        $engine = new Engine();
        self::assertSame('[true,1,2,9,3]', $engine->eval(<<<'JS'
(() => {
    const key = Symbol('key');
    const iterator = Iterator.zipKeyed({ a: [1], [key]: [2, 3] }, {
        mode: 'longest', padding: { a: 9 }
    });
    const first = iterator.next().value;
    const second = iterator.next().value;
    return JSON.stringify([
        Object.getPrototypeOf(first) === null, first.a, first[key], second.a, second[key]
    ]);
})()
JS));
    }

    public function testHelperReturnDistinguishesUnstartedAndSuspendedStates(): void
    {
        $engine = new Engine();
        self::assertSame('done,TypeError', $engine->eval(<<<'JS'
(() => {
    const log = [];
    for (const started of [false, true]) {
        let helper;
        const iterator = {
            next() { return { value: 1, done: false }; },
            return() {
                try { log.push(helper.next().done ? 'done' : 'value'); }
                catch (error) { log.push(error.name); }
                return {};
            }
        };
        helper = Iterator.zip([iterator]);
        if (started) helper.next();
        helper.return();
    }
    return log.join(',');
})()
JS));
    }

    public function testAsyncDisposeRejectsLookupErrorsAndDiscardsReturnValues(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const prototype = Object.getPrototypeOf(Object.getPrototypeOf((async function* () {}).prototype));
const dispose = prototype[Symbol.asyncDispose];
const observed = [];
dispose.call({ get return() { throw new Error('lookup'); } })
    .catch(error => observed.push(error.message));
dispose.call({ return() { return 42; } })
    .then(value => observed.push(typeof value));
JS);
        self::assertSame('lookup,undefined', $engine->eval('observed.join(",")'));
    }
}
