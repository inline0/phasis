<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class TailCallTest extends TestCase
{
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
        self::assertFalse($function->compiled->canSkipEnvAlloc);
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
