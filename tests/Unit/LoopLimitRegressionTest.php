<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit;

use Phasis\Engine;
use Phasis\Exceptions\InternalError;
use Phasis\Exceptions\RuntimeError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LoopLimitRegressionTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function runawayLoops(): iterable
    {
        yield 'program for' => ['for (;;) {}'];
        yield 'program while' => ['while (true) {}'];
        yield 'program do while' => ['do {} while (true);'];
        yield 'numeric function for' => ['(function () { var n = 0; for (var i = 0; i < 10; i++) n++; return n; })()'];
        yield 'numeric function while' => ['(function () { var n = 0; while (n < 10) n++; return n; })()'];
        yield 'numeric function do while' => ['(function () { var n = 0; do { n++; } while (n < 10); return n; })()'];
        yield 'vm function for' => ['(function () { try { for (;;) {} } finally {} })()'];
        yield 'vm function while' => ['(function () { try { while (true) {} } finally {} })()'];
        yield 'vm function do while' => ['(function () { try { do {} while (true); } finally {} })()'];
        yield 'tree walker' => ['let n = 0; while (true) { n++; }'];
        yield 'continue' => ['(function () { for (;;) { continue; } })()'];
        yield 'generator resumes' => ['var iterator = (function* () { while (true) { yield 1; } })(); iterator.next(); '
            . 'iterator.next(); iterator.next(); iterator.next();'];
    }

    #[DataProvider('runawayLoops')]
    public function testEveryExecutionPathEnforcesTheLoopLimit(string $source): void
    {
        $engine = new Engine();
        $engine->setLimit('maxLoopIterations', 3);
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('Maximum loop iterations exceeded');
        $engine->eval($source);
    }

    public function testLoopBudgetsResetForEachEntryAndInlineCall(): void
    {
        $engine = new Engine();
        $engine->setLimit('maxLoopIterations', 3);
        self::assertSame(9, $engine->eval('(function () { var count = 0; for (var i = 0; i < 3; i++) { for (var j = 0; j < 3; '
            . 'j++) count++; } return count; })()'));
        self::assertSame(9, $engine->eval('(function () { function inner() { try { var count = 0; for (var j = 0; j < 3; j++) '
            . 'count++; return count; } finally {} } var total = 0; for (var i = 0; i < 3; i++) '
            . 'total += inner(); return total; })()'));
        self::assertSame(3, $engine->eval('let count = 0; for (let i = 0; i < 3; i++) count++; count'));
    }

    public function testLimitErrorsCanBeCaughtAndEngineCanBeReused(): void
    {
        $engine = new Engine();
        $engine->setLimit('maxLoopIterations', 3);
        self::assertSame(
            'Maximum loop iterations exceeded',
            $engine->eval('(function () { try { while (true) {} } catch (e) { return e.message; } })()'),
        );
        self::assertSame(3, $engine->eval('(function () { var count = 0; do { count++; } while (count < 3); return count; })()'));
        $engine->setLimit('maxLoopIterations', 5);
        self::assertSame(5, $engine->eval('(function () { var count = 0; while (count < 5) count++; return count; })()'));
        $engine->reset();
        self::assertSame('Maximum loop iterations exceeded', $engine->eval('try { while (true) {} } catch (e) { e.message; }'));
    }

    public function testConstructorsKeepTheirThisBindingInsideLimitedLoops(): void
    {
        $engine = new Engine();
        $engine->setLimit('maxLoopIterations', 3);
        self::assertSame(3, $engine->eval('var Factory = new Function("a,b", "this.value = a + b;"); (function () { var object; '
            . 'for (var i = 0; i < 3; i++) object = new Factory(1, 2); return object.value; })()'));
    }

    public function testZeroLimitPermitsAnEmptyLoop(): void
    {
        $engine = new Engine();
        $engine->setLimit('maxLoopIterations', 0);
        self::assertSame(1, $engine->eval('while (false) {} 1;'));
        self::assertSame(1, $engine->eval('let value = 1; for (let i = 0; i < 0; i++) {} value;'));
        $this->expectException(InternalError::class);
        $engine->eval('do {} while (false);');
    }

    public function testUnknownLimitNamesAreRejected(): void
    {
        $engine = new Engine();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown limit: maxLoopIteration');
        $engine->setLimit('maxLoopIteration', 1);
    }

    public function testNegativeLimitsAreRejected(): void
    {
        $engine = new Engine();
        $this->expectException(\InvalidArgumentException::class);
        $engine->setLimit('maxLoopIterations', -1);
    }
}
