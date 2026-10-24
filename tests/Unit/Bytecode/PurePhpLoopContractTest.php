<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Exceptions\BudgetExceeded;
use Phasis\Exceptions\InternalError;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNumber;
use Phasis\Value\JsUndefined;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PurePhpLoopContractTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function loops(): iterable
    {
        yield 'for' => ['for (; count < amount;) { count++; }', 0];
        yield 'while' => ['while (count < amount) { count++; }', 0];
        yield 'do-while' => ['do { count++; } while (count < amount);', 1];
    }

    #[DataProvider('loops')]
    public function testDirectCompiledLoopsRetainCountsAndDeadlineChecks(string $loop, int $empty): void
    {
        $engine = new Engine();
        $engine->eval('function pure(amount) { let count = 0; ' . $loop . ' return count; } pure(1);');
        $function = $engine->getGlobalEnv()->get('pure');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertInstanceOf(\Closure::class, $function->phpCompiled);
        $engine->setLimit('maxLoopIterations', 1);
        self::assertSame((float) $empty, $this->invoke($engine, $function, 0));
        self::assertSame(1.0, $this->invoke($engine, $function, 1));
        try {
            $this->invoke($engine, $function, 2);
            self::fail('The compiled loop ignored its iteration limit.');
        } catch (InternalError $error) {
            self::assertStringContainsString('Maximum loop iterations', $error->getMessage());
        }
        $engine->setExecutionBudget(-1);
        $this->expectException(BudgetExceeded::class);
        $this->invoke($engine, $function, 0);
    }

    private function invoke(Engine $engine, JsFunction $function, int $amount): float
    {
        $result = ($function->phpCompiled)(
            [JsNumber::of($amount)],
            JsUndefined::instance(),
            $function->getClosure(),
            $engine->getInterpreter(),
            $function->phpCompiledNodes,
        );
        self::assertInstanceOf(JsNumber::class, $result);
        return $result->value;
    }
}
