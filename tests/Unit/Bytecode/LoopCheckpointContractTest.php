<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Exceptions\BudgetExceeded;
use Phasis\Exceptions\RuntimeError;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNumber;
use Phasis\Value\JsUndefined;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LoopCheckpointContractTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function tiersAndLoops(): iterable
    {
        foreach (['ast', 'bytecode', 'php'] as $tier) {
            foreach (['for', 'while', 'do-while'] as $loop) {
                yield $tier . '-' . $loop => [$tier, $loop];
            }
        }
    }

    #[DataProvider('tiersAndLoops')]
    public function testExactAndZeroLimitsCountOnlyExecutedBodies(string $tier, string $loop): void
    {
        $engine = new Engine();
        $function = $this->prepare($engine, $tier, $loop, 'count++;');
        $engine->setLimit('maxLoopIterations', 5);
        self::assertSame(5.0, $this->invoke($engine, $function, $tier, 5));
        $engine->setLimit('maxLoopIterations', 0);
        if ($loop === 'do-while') {
            $this->expectException(RuntimeError::class);
            $this->invoke($engine, $function, $tier, 0);
        } else {
            self::assertSame(0.0, $this->invoke($engine, $function, $tier, 0));
        }
    }

    #[DataProvider('tiersAndLoops')]
    public function testBudgetCheckpointAroundTheFinalFalseCondition(string $tier, string $loop): void
    {
        $engine = new Engine();
        $expire = false;
        $calls = 0;
        $engine->setGlobal('expireBudget', static function () use ($engine, &$expire, &$calls): void {
            if ($expire) {
                $calls++;
                $engine->setExecutionBudget(-1);
            }
        });
        $function = $this->prepare($engine, $tier, $loop, 'count++; expireBudget();');
        $engine->setLimit('maxLoopIterations', 1);
        $expire = true;
        $exceeded = false;
        $result = null;
        try {
            $result = $this->invoke($engine, $function, $tier, 1);
        } catch (BudgetExceeded) {
            $exceeded = true;
        }
        self::assertSame($loop !== 'do-while', $exceeded);
        if ($loop === 'do-while') {
            self::assertSame(1.0, $result);
        }
        self::assertSame(1, $calls);
        self::assertSame(0, $engine->getInterpreter()->getCallStack()->depth());
        $engine->setExecutionBudget(null);
    }

    private function prepare(Engine $engine, string $tier, string $loop, string $body): JsFunction
    {
        if ($tier === 'ast') {
            $engine->setExecutionBudget(10);
        }
        $helper = '';
        if (str_contains($body, 'expireBudget();')) {
            $helper = 'function expireLocal() { expireBudget(); } ';
            $body = str_replace('expireBudget();', 'expireLocal();', $body);
        }
        $statement = match ($loop) {
            'for' => 'for (; count < amount;) {' . $body . '}',
            'while' => 'while (count < amount) {' . $body . '}',
            default => 'do {' . $body . '} while (count < amount);',
        };
        if ($tier === 'bytecode') {
            $statement = 'try {' . $statement . '} finally {}';
        }
        $engine->eval('function probe(amount) { ' . $helper . 'let count = 0; ' . $statement . ' return count; } probe(1);');
        $function = $engine->getGlobalEnv()->get('probe');
        self::assertInstanceOf(JsFunction::class, $function);
        if ($tier === 'php') {
            self::assertInstanceOf(\Closure::class, $function->phpCompiled);
        } else {
            self::assertSame($tier === 'bytecode', $function->compiled !== null);
            self::assertNull($function->phpCompiled);
        }
        return $function;
    }

    private function invoke(Engine $engine, JsFunction $function, string $tier, int $amount): float
    {
        if ($tier === 'php') {
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
        return (float) $engine->eval('probe(' . $amount . ')');
    }
}
