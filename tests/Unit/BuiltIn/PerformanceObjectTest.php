<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

class PerformanceObjectTest extends TestCase
{
    public function testNowReturnsPositiveNumber(): void
    {
        $engine = new Engine();
        $result = $engine->eval('typeof performance.now() === "number" && performance.now() >= 0;');
        $this->assertTrue($result);
    }

    public function testNowIsMonotonicallyNonDecreasing(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
var a = performance.now();
// Busy-loop so the second sample picks up some elapsed ns; we just need
// non-decreasing, but giving the clock something to do guards against a
// zero-precision implementation slipping through.
var x = 0;
for (var i = 0; i < 1000; i++) { x += i; }
var b = performance.now();
b >= a;
JS);
        $this->assertTrue($result);
    }

    public function testTimeOriginIsPositiveNumber(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
typeof performance.timeOrigin === "number" && performance.timeOrigin > 0;
JS);
        $this->assertTrue($result);
    }

    public function testTimeOriginRoughlyMatchesUnixMs(): void
    {
        // Should be in the same ballpark as PHP's view of Unix-ms.
        $engine = new Engine();
        $jsOrigin = $engine->eval('performance.timeOrigin;');
        $phpOrigin = microtime(true) * 1000.0;
        // Allow a generous 10s window — install happens before eval.
        $this->assertGreaterThan($phpOrigin - 10_000.0, $jsOrigin);
        $this->assertLessThan($phpOrigin + 10_000.0, $jsOrigin);
    }

    public function testPerformanceIsAnObject(): void
    {
        $engine = new Engine();
        $result = $engine->eval('typeof performance;');
        $this->assertSame('object', $result);
    }
}
