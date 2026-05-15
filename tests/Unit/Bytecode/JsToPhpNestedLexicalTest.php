<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the JsToPhp transpiler's nested block-scoped let/const
 * acceptance. The transpiler models locals as function-scoped PHP
 * slots, so it has to bail on genuine TDZ shapes (a read of the
 * declared name BEFORE its let/const). For every other nested-let
 * pattern — for-let counters, if-let blocks, lets following non-decl
 * statements in a block when the binding isn't read early — JsToPhp
 * should produce a compiled closure and stay on the fast path.
 *
 * Each test asserts both the runtime result AND that the function
 * actually went through phpCompiled (i.e. didn't silently fall back
 * to the tree-walker). This guards against future heuristics
 * accidentally widening the bailout and silently regressing perf.
 */
class JsToPhpNestedLexicalTest extends TestCase
{
    public function testForLetLoopCompiles(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
function sumTo(n) {
  var s = 0;
  for (let i = 0; i < n; i++) {
    s += i;
  }
  return s;
}
sumTo(10);
JS);
        $this->assertSame(45, $result);
        $this->assertFunctionPhpCompiled($engine, 'sumTo');
    }

    public function testForLetWithInnerConstCompiles(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
function f(n) {
  let r = 0;
  for (let i = 0; i < n; i++) {
    const v = i * 2;
    r += v;
  }
  return r;
}
f(10);
JS);
        $this->assertSame(90, $result);
        $this->assertFunctionPhpCompiled($engine, 'f');
    }

    public function testIfLetBlockCompilesWhenBindingDeclaredFirst(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
function f(n) {
  var s = 0;
  if (n > 0) {
    let x = n * 2;
    s = x;
  }
  return s;
}
f(5);
JS);
        $this->assertSame(10, $result);
        $this->assertFunctionPhpCompiled($engine, 'f');
    }

    public function testIfBlockWithStmtBeforeLetCompiles(): void
    {
        // The let is preceded by a non-decl statement but the
        // binding `y` is never read prior to its declaration —
        // safe to transpile.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
function f(n) {
  let s = 0;
  if (n > 0) {
    s = n;
    let y = n * 3;
    s += y;
  }
  return s;
}
f(2);
JS);
        $this->assertSame(8, $result);
        $this->assertFunctionPhpCompiled($engine, 'f');
    }

    public function testForBodyWithConstAfterStmtCompiles(): void
    {
        // Inside the for body, the per-iteration `k` const is
        // declared AFTER `s += 1` runs. `k` isn't read before its
        // const — safe.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
function f(n) {
  let s = 0;
  for (let i = 0; i < n; i++) {
    s += 1;
    const k = i * 2;
    s += k;
  }
  return s;
}
f(5);
JS);
        $this->assertSame(25, $result);
        $this->assertFunctionPhpCompiled($engine, 'f');
    }

    public function testIfElseWithLetInElseBranchCompiles(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
function f(n) {
  let s = 0;
  if (n > 5) {
    s = n;
  } else {
    s = 1;
    let extra = n * 2;
    s += extra;
  }
  return s;
}
f(3);
JS);
        $this->assertSame(7, $result);
        $this->assertFunctionPhpCompiled($engine, 'f');
    }

    public function testTdzReadBeforeDeclBailsToTreeWalker(): void
    {
        // Reading `x` before its `let x = 10;` is a spec TDZ
        // violation — must throw ReferenceError via the
        // tree-walker. JsToPhp must NOT compile this.
        $engine = new Engine();
        $threw = false;
        try {
            $engine->eval(<<<'JS'
function f(n) {
  let s = 0;
  if (n > 0) {
    s = x + 1;
    let x = 10;
  }
  return s;
}
f(3);
JS);
        } catch (\Throwable $e) {
            $threw = true;
            $this->assertStringContainsString(
                'before initialization',
                $e->getMessage(),
            );
        }
        $this->assertTrue($threw, 'TDZ violation must throw');

        // Confirm we did NOT compile-through to the PHP closure
        // path — the tree-walker's TDZ check has to be authoritative.
        $env = $engine->getGlobalEnv();
        $this->assertTrue($env->has('f'));
        $fn = $env->get('f');
        $this->assertInstanceOf(JsFunction::class, $fn);
        $this->assertNull(
            $fn->phpCompiled,
            'TDZ-shaped function must NOT be JsToPhp-compiled',
        );
        $this->assertTrue(
            $fn->phpCompileFailed,
            'TDZ-shaped function must be marked as compile-failed',
        );
    }

    public function testSelfReferentialConstInitBailsToTreeWalker(): void
    {
        // `const x = x + 1;` — the init reads the binding being
        // declared. Spec TDZ. Must bail.
        $engine = new Engine();
        $threw = false;
        try {
            $engine->eval(<<<'JS'
function f() {
  const x = x + 1;
  return x;
}
f();
JS);
        } catch (\Throwable) {
            $threw = true;
        }
        $this->assertTrue($threw, 'self-referential const must throw');

        $env = $engine->getGlobalEnv();
        $this->assertTrue($env->has('f'));
        $fn = $env->get('f');
        $this->assertInstanceOf(JsFunction::class, $fn);
        $this->assertNull(
            $fn->phpCompiled,
            'self-referential init must NOT compile',
        );
    }

    private function assertFunctionPhpCompiled(Engine $engine, string $name): void
    {
        $env = $engine->getGlobalEnv();
        $this->assertTrue(
            $env->has($name),
            "function $name should be globally bound",
        );
        $fn = $env->get($name);
        $this->assertInstanceOf(JsFunction::class, $fn);
        $this->assertNotNull(
            $fn->phpCompiled,
            "function $name should have been JsToPhp-compiled",
        );
        $this->assertFalse(
            $fn->phpCompileFailed,
            "function $name should not be marked compile-failed",
        );
    }
}
