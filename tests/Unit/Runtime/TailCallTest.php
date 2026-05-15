<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

/**
 * Strict-mode proper-tail-call coverage for shapes that aren't a bare
 * `return identifier(args)` — specifically the spec-mandated test262
 * case `return getF()(args)` (call to the result of a call). Plus a
 * regression guard for the double-evaluation pitfall the
 * CallExpression-callee handling has to avoid.
 */
class TailCallTest extends TestCase
{
    public function testReturnCallOfCallTCOs(): void
    {
        // test262: language/expressions/call/tco-call-args.js. Strict-
        // mode tail position with callee = CallExpression. Must
        // trampoline through TailCallThunk, NOT grow the PHP stack.
        $engine = new Engine();
        $callCount = $engine->eval(<<<'JS'
"use strict";
var callCount = 0;
(function f(n) {
  if (n === 0) {
    callCount += 1
    return;
  }
  function getF() { return f; }
  return getF()(n - 1);
}(50000));
callCount;
JS);
        $this->assertSame(1, $callCount);
    }

    public function testReturnDirectSelfRecursionStillTCOs(): void
    {
        // The simpler form that has always worked — guard against
        // accidentally regressing it while fixing the harder form.
        $engine = new Engine();
        $callCount = $engine->eval(<<<'JS'
"use strict";
var callCount = 0;
(function f(n) {
  if (n === 0) {
    callCount += 1;
    return;
  }
  return f(n - 1);
}(50000));
callCount;
JS);
        $this->assertSame(1, $callCount);
    }

    public function testCallInTailPositionDoesNotDoubleEvaluateInnerCall(): void
    {
        // The MOTIVATING scenario for the b9b2853 tightening: when TCO
        // evaluates a side-effecting callee and the callee resolves to a
        // shape that's ineligible for the thunk, the side effect must
        // STILL fire only once. Specifically: `return foo().then(...)` —
        // foo() must execute exactly once even though `.then` is a
        // native method that can't be tail-called.
        $engine = new Engine();
        $sideEffectCount = $engine->eval(<<<'JS'
"use strict";
var n = 0;
function foo() { n++; return Promise.resolve(42); }
async function run() { return foo().then(x => x).then(x => x); }
run();
n;
JS);
        $this->assertSame(1, $sideEffectCount);
    }
}
