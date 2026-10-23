<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

class CurrentProposalSemanticsTest extends TestCase
{
    public function testKeyedCombinatorsPreserveOrderAndIgnoreRepeatedSettlement(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
var output;
var callbacks = [];
function Custom(executor) { executor(value => output = value, reason => { throw reason; }); }
Custom.resolve = value => value;
var symbol = Symbol('key');
var input = { first: { then(resolve) { callbacks.push(resolve); } } };
input[symbol] = { then(resolve, reject) { reject('reason'); resolve('ignored'); } };
Promise.allSettledKeyed.call(Custom, input);
callbacks[0]('value');
callbacks[0]('ignored');
var keys = Reflect.ownKeys(output);
globalThis.verified = Object.getPrototypeOf(output) === null &&
    keys[0] === 'first' && keys[1] === symbol &&
    output.first.value === 'value' && output[symbol].reason === 'reason';
JS);
        self::assertTrue($engine->eval('verified'));
    }

    public function testPromiseTryCallsTheCallbackBeforeConstructingTheCapability(): void
    {
        $engine = new Engine();
        self::assertSame('callback,constructor,resolve', $engine->eval(<<<'JS'
var events = [];
function Custom(executor) {
    events.push('constructor');
    executor(value => events.push('resolve'), reason => events.push('reject'));
}
Promise.try.call(Custom, () => { events.push('callback'); return 1; });
events.join(',');
JS));
    }

    public function testResourceMethodIsCapturedAndEarlierInitializersAreDisposedOnThrow(): void
    {
        $engine = new Engine();
        self::assertSame('get,original', $engine->eval(<<<'JS'
var events = [];
var resource = { get [Symbol.dispose]() {
    events.push('get');
    return () => events.push('original');
} };
try {
    (() => {
        using first = resource, second = (() => {
            Object.defineProperty(resource, Symbol.dispose, { value: () => events.push('replacement') });
            throw new Error('initializer');
        })();
    })();
} catch (error) {}
events.join(',');
JS));
    }

    public function testAsyncDisposalWaitsForEachResourceAndDiscardsSyncReturnValue(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
var events = [];
var stack = new AsyncDisposableStack();
stack.use({ [Symbol.dispose]() {
    events.push('sync');
    return { get then() { throw new Error('must not inspect the sync return value'); } };
} });
stack.use({ async [Symbol.asyncDispose]() {
    events.push('before');
    await 0;
    events.push('after');
} });
stack.disposeAsync().then(() => events.push('done'));
JS);
        self::assertSame('before,after,sync,done', $engine->eval('events.join(",")'));
    }
}
