<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class TailCallTest extends TestCase
{
    public function testCompiledProtectedCallsRetainTheirCatchAndCatchTailsRecur(): void
    {
        $engine = new Engine();
        self::assertSame(['0', '8', 'finally', '9'], $engine->eval(<<<'JS'
'use strict';
function fail(value) { throw new Error(String(value)); }
function recover(count) {
    try { return fail(count); }
    catch (error) {
        if (count === 0) return error.message;
        return recover(count - 1);
    }
}
function nested() {
    try {
        try { return fail(7); }
        catch (first) { return fail(8); }
    } catch (second) { return second.message; }
}
const order = [recover(2000), nested()];
function finalized() {
    try { return fail(9); }
    finally { order.push('finally'); }
}
try { finalized(); } catch (error) { order.push(error.message); }
order;
JS));
        foreach (['recover', 'nested'] as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled, $name . ' must use bytecode.');
        }
    }

    public function testStrictTailCallsDoNotGrowTheStackThroughCompiledCallers(): void
    {
        $engine = new Engine();
        self::assertSame(20000, $engine->eval(<<<'JS'
'use strict';
function tail(count, total) {
  if (count === 0) return total;
  return tail(count - 1, total + 1);
}
function wrapper() { let result = tail(10000, 0); result += tail(10000, 0); return result; }
wrapper();
JS));
        $function = $engine->getGlobalEnv()->get('tail');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNotNull($function->compiled);
        self::assertTrue($function->compiled->canSkipEnvAlloc);
        self::assertTrue($function->compiled->hasTailCalls);
    }

    public function testTailReturningConstructorsAndMethodsKeepTheirCompletionValues(): void
    {
        $engine = new Engine();
        self::assertSame([4, 5, 6, 7], $engine->eval(<<<'JS'
function value(n) { return n; }
function result(n) { return { value: n }; }
function TailObject(n) { 'use strict'; return result(n); }
function TailPrimitive(n) { 'use strict'; this.value = n; return value(9); }
function invoke(n) { 'use strict'; return this.base + value(n); }
function factory(n) { return new TailObject(n).value + new TailPrimitive(n).value; }
const receiver = { base: 1, invoke };
const first = factory(1);
const second = factory(2);
[new TailObject(4).value, new TailPrimitive(5).value, receiver.invoke(5), receiver.invoke(6)];
JS));
    }

    public function testTailMethodsPreserveReceiverAndLookupBeforeArguments(): void
    {
        $engine = new Engine();
        self::assertSame(['get', 'argument', 'get', 'argument', 7, 7, 'caught'], $engine->eval(<<<'JS'
'use strict';
const order = [];
const object = { value: 4, get method() { order.push('get'); return function (x) { return this.value + x; }; } };
function argument() { order.push('argument'); return 3; }
function call() { return object.method(argument()); }
function fail() { throw new Error('caught'); }
function protectedCall() { try { return fail(); } catch (error) { return error.message; } }
order.push(call(), call(), protectedCall());
order;
JS));
    }

    public function testConditionalLogicalAndSequenceTailPositionsKeepTheirEvaluationOrder(): void
    {
        $engine = new Engine();
        self::assertSame([10000, false, true, 0, 10000, 2, 'leftright'], $engine->eval(<<<'JS'
'use strict';
let visits = 0;
let order = '';
function conditional(n, sum) { return n === 0 ? sum : conditional(n - 1, sum + 1); }
function andTail(n) { return n > 0 && andTail(n - 1); }
function orTail(n) { return n === 0 || orTail(n - 1); }
function nullishTail(n) { return (n === 0 ? 0 : null) ?? nullishTail(n - 1); }
function sequenceTail(n) { return n === 0 ? visits : (visits = visits + 1, sequenceTail(n - 1)); }
function left() { order += 'left'; return 0; }
function right() { order += 'right'; return 2; }
const arrow = () => left() || right();
[conditional(10000, 0), andTail(10000), orTail(10000), nullishTail(10000), sequenceTail(10000), arrow(), order];
JS));
        foreach (['conditional', 'andTail', 'orTail', 'nullishTail', 'sequenceTail', 'arrow'] as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled, $name . ' must use bytecode.');
        }
    }
}
