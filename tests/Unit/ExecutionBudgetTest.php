<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit;

use Phasis\Engine;
use Phasis\Exceptions\BudgetExceeded;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExecutionBudgetTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function runawayCode(): iterable
    {
        yield 'program loop' => ['for (;;) {}'];
        yield 'swallowed in a loop' => ['for (;;) { try { while (true) {} } catch (e) {} }'];
        yield 'numeric function' => ['(function () { var n = 0; for (;;) n++; })()'];
        yield 'function with finally' => ['(function () { try { for (;;) {} } finally {} })()'];
        yield 'recursion' => ['function f(n) { return n > 0 ? f(n - 1) + f(n - 1) : 1; } f(40)'];
        yield 'generator' => ['for (const v of (function* () { for (;;) yield 1; })()) {}'];
        yield 'indirect eval' => ['(0, eval)("for (;;) { for (;;) {} }")'];
        yield 'function constructor' => ['Function("for (;;) {}")()'];
        yield 'shadow realm' => ['try { new ShadowRealm().evaluate("for (;;) { for (;;) {} }"); } catch (e) {}'];
    }

    #[DataProvider('runawayCode')]
    public function testABudgetStopsCodeThatWouldRunForever(string $source): void
    {
        $engine = new Engine();
        $engine->setLimit('maxLoopIterations', PHP_INT_MAX);
        $engine->setExecutionBudget(0.05);

        try {
            $engine->eval($source);
            self::fail('Evaluation finished');
        } catch (BudgetExceeded) {
        }

        self::assertTrue($engine->budgetExceeded());
    }

    public function testAnExceededBudgetKeepsThrowingUntilItIsReset(): void
    {
        $engine = new Engine();
        $engine->setLimit('maxLoopIterations', PHP_INT_MAX);
        $engine->setExecutionBudget(0.01);
        try {
            $engine->eval('for (;;) {}');
        } catch (BudgetExceeded) {
        }

        try {
            $engine->eval('(function () {})()');
            self::fail('A call ran after the budget was exceeded');
        } catch (BudgetExceeded) {
        }

        $engine->setExecutionBudget(null);
        self::assertFalse($engine->budgetExceeded());
        self::assertSame(2, $engine->eval('(function () { return 2; })()'));
    }

    public function testTheBudgetAndTimerSwitchSurviveAReset(): void
    {
        $engine = new Engine();
        $engine->setLimit('maxLoopIterations', PHP_INT_MAX);
        $engine->setExecutionBudget(0.05);
        $engine->setTimersEnabled(false);
        $engine->reset();

        $engine->eval('globalThis.fired = false; setTimeout(() => { fired = true; }, 0);');
        self::assertFalse($engine->eval('fired'));

        $this->expectException(BudgetExceeded::class);
        $engine->eval('for (;;) {}');
    }

    public function testLastLocationNamesTheStatementThatThrew(): void
    {
        $engine = new Engine();
        $engine->setExecutionBudget(5.0);
        try {
            $engine->eval("let a = 1;\nlet b = 2;\n  missing();\n");
            self::fail('Evaluation finished');
        } catch (\Throwable) {
        }

        self::assertSame(['line' => 3, 'column' => 3], $engine->lastLocation());
    }

    public function testDisabledTimersNeverFire(): void
    {
        $engine = new Engine();
        $engine->setTimersEnabled(false);
        $engine->eval('globalThis.log = []; setTimeout(() => log.push("timer"), 0); Promise.resolve().then(() => log.push("microtask"));');
        $engine->runEventLoop();

        self::assertSame('microtask', $engine->eval('log.join()'));
    }
}
