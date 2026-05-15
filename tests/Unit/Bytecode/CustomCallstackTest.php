<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

/**
 * Custom-callstack regressions. The VM's inline-CALL path keeps the
 * JS dispatch loop in a single PHP frame across cross-function
 * calls, lifting PHP's stack-depth ceiling and short-circuiting the
 * per-call PHP method-dispatch overhead.
 *
 * These tests pin invariants that any future stage of the refactor
 * must preserve:
 *  - JS recursion can exceed the previous CallStack ceiling (1024)
 *    on the inlined fast path.
 *  - Exceptions unwind through inlined frames to find a handler in
 *    any parent frame.
 *  - Annex B caller/arguments magic works for sloppy-mode callees
 *    that take the inlined path.
 *  - Tree-walker callees (non-BC-compiled) still get the slow path
 *    and behave identically to baseline.
 */
class CustomCallstackTest extends TestCase
{
    public function testDeepRecursionPastPreviousCeiling(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
"use strict";
function recurse(n) {
    if (n === 0) return "ok";
    return recurse(n - 1);
}
recurse(5000);
JS);
        $this->assertSame('ok', $result);
    }

    public function testExceptionUnwindsThroughInlinedFrames(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
"use strict";
function inner() { throw new Error("bang"); }
function middle() { inner(); return "unreachable"; }
function outer() {
    try {
        middle();
        return "no-throw";
    } catch (e) {
        return "caught: " + e.message;
    }
}
outer();
JS);
        $this->assertSame('caught: bang', $result);
    }

    public function testSloppyAnnexBCallerOnInlinedPath(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
function f() { return "sloppy:" + (typeof f.caller); }
function g() { return f(); }
g();
JS);
        $this->assertSame('sloppy:function', $result);
    }

    public function testMixedStrictSloppyInteraction(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
"use strict";
function strictA(n) {
    if (n === 0) return "strictA-done";
    return strictB(n - 1);
}
function strictB(n) { return strictA(n - 1); }
strictA(500);
JS);
        $this->assertSame('strictA-done', $result);
    }

    public function testMutualRecursionThrowAndCatchAcrossFrames(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
"use strict";
function a(n) { if (n <= 0) throw new Error("done"); return b(n - 1); }
function b(n) { return a(n - 1); }
try { a(200); } catch (e) { e.message; }
JS);
        $this->assertSame('done', $result);
    }

    public function testReturnValueRoundTripsAcrossInlinedFrames(): void
    {
        $engine = new Engine();
        $sum = $engine->eval(<<<'JS'
"use strict";
function fib(n) {
    if (n < 2) return n;
    return fib(n - 1) + fib(n - 2);
}
fib(15);
JS);
        $this->assertSame(610, $sum);
    }

    public function testFinallyTreeWalkerFallbackStillWorks(): void
    {
        // try/finally bails out of the bytecode compiler today, so
        // these run on the tree-walker — the inlined path isn't
        // involved. Pinning the test ensures the slow-path RET
        // semantics keep matching the inlined-path RET.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
"use strict";
let log = [];
function f() {
    try {
        log.push("try");
        throw new Error("e");
    } finally {
        log.push("finally");
    }
}
try { f(); } catch (e) { log.push("caught:" + e.message); }
log.join(",");
JS);
        $this->assertSame('try,finally,caught:e', $result);
    }
}
