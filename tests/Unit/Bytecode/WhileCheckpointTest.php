<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Exceptions\BudgetExceeded;
use Phasis\Exceptions\InternalError;
use Phasis\Value\JsFunction;
use Phasis\Value\JsUndefined;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WhileCheckpointTest extends TestCase
{
    /** @return iterable<string, array{bool, bool}> */
    public static function loopModes(): iterable
    {
        foreach ([false, true] as $ast) {
            foreach ([false, true] as $continue) {
                yield ($ast ? 'ast' : 'bytecode') . ($continue ? '-continue' : '-backedge') => [$ast, $continue];
            }
        }
    }

    #[DataProvider('loopModes')]
    public function testWhileLimitPrecedesTheFinalFalseCondition(bool $ast, bool $continue): void
    {
        $engine = new Engine();
        if ($ast) {
            $engine->setExecutionBudget(10);
        }
        $body = 'n++;' . ($continue ? 'continue;' : '');
        $engine->eval('var conditions = 0; function probe() { let n = 0; while ((conditions++, n < 1)) {' . $body . '} return n; } probe();');
        $function = $engine->getGlobalEnv()->get('probe');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertSame(!$ast, $function->compiled !== null);
        $engine->setLimit('maxLoopIterations', 1);
        self::assertSame(['Maximum loop iterations exceeded', 1], $engine->eval(<<<'JS'
conditions = 0;
var result;
try { result = probe(); } catch (error) { result = error.message; }
[result, conditions];
JS));
        self::assertSame(0, $engine->getInterpreter()->getCallStack()->depth());
    }

    /** @return iterable<string, array{bool, string, int}> */
    public static function budgetModes(): iterable
    {
        foreach ([false, true] as $ast) {
            foreach (['while', 'do-while'] as $loop) {
                foreach ([1, 1000] as $limit) {
                    yield ($ast ? 'ast-' : 'bytecode-') . $loop . '-' . $limit => [$ast, $loop, $limit];
                }
            }
        }
    }

    #[DataProvider('budgetModes')]
    public function testActivatedBudgetPrecedesLoopLimitsAndTheNextBody(bool $ast, string $loop, int $limit): void
    {
        $engine = new Engine();
        if ($ast) {
            $engine->setExecutionBudget(10);
        }
        $expire = false;
        $engine->setGlobal('expireBudget', static function () use ($engine, &$expire): void {
            if ($expire) {
                $engine->setExecutionBudget(-1);
            }
        });
        $body = '{ n++; iterations++; expireBudget(); }';
        $statement = $loop === 'while' ? 'while (n < 2) ' . $body : 'do ' . $body . ' while (n < 2);';
        $engine->eval('var iterations = 0; function probe() { let n = 0; ' . $statement . ' return n; } probe();');
        $function = $engine->getGlobalEnv()->get('probe');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertSame(!$ast, $function->compiled !== null);
        $engine->eval('iterations = 0');
        $engine->setLimit('maxLoopIterations', $limit);
        $expire = true;
        $this->expectException(BudgetExceeded::class);
        try {
            $engine->eval('probe()');
        } finally {
            self::assertSame(1.0, $engine->getGlobalEnv()->get('iterations')->value);
            self::assertSame(0, $engine->getInterpreter()->getCallStack()->depth());
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function phpModes(): iterable
    {
        yield 'backedge' => [false];
        yield 'continue' => [true];
    }

    #[DataProvider('phpModes')]
    public function testGeneratedPhpChecksBeforeConditionPreludes(bool $continue): void
    {
        $engine = new Engine();
        $body = 'n++;' . ($continue ? 'continue;' : '');
        $engine->eval(<<<'JS'
var conditions = 0;
function condition(n) { conditions++; return n < 1 ? 1 : 0; }
JS);
        $engine->eval('function probe() { let n = 0; while (condition(n)) {' . $body . '} return n; } probe();');
        $function = $engine->getGlobalEnv()->get('probe');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertInstanceOf(\Closure::class, $function->phpCompiled);
        $engine->eval('conditions = 0');
        $engine->setLimit('maxLoopIterations', 1);
        $this->expectException(InternalError::class);
        try {
            ($function->phpCompiled)([], JsUndefined::instance(), $function->getClosure(), $engine->getInterpreter(), []);
        } finally {
            self::assertSame(1.0, $engine->getGlobalEnv()->get('conditions')->value);
        }
    }

    public function testGeneratedPhpBudgetPrecedesTheIterationLimit(): void
    {
        $engine = new Engine();
        $expire = false;
        $engine->setGlobal('expireBudget', static function () use ($engine, &$expire): void {
            if ($expire) {
                $engine->setExecutionBudget(-1);
            }
        });
        $engine->eval('function probe() { let n = 0; while (n < 2) { n++; expireBudget(); } return n; } probe();');
        $function = $engine->getGlobalEnv()->get('probe');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertInstanceOf(\Closure::class, $function->phpCompiled);
        $engine->setLimit('maxLoopIterations', 1);
        $expire = true;
        $this->expectException(BudgetExceeded::class);
        ($function->phpCompiled)([], JsUndefined::instance(), $function->getClosure(), $engine->getInterpreter(), []);
    }

    public function testBudgetAbortUnwindsInlineFramesAndBypassesJsCatch(): void
    {
        $engine = new Engine();
        $expire = false;
        $engine->setGlobal('expireBudget', static function () use ($engine, &$expire): void {
            if ($expire) {
                $engine->setExecutionBudget(-1);
            }
        });
        self::assertSame(2, $engine->eval(<<<'JS'
var iterations = 0, caught = false;
function innerLoop() { let n = 0; while (n < 2) { n++; iterations++; expireBudget(); } return n; }
function middleLoop() { return innerLoop(); }
function outerLoop() { try { return middleLoop(); } catch (error) { caught = true; return -1; } }
outerLoop();
JS));
        foreach (['innerLoop', 'middleLoop', 'outerLoop'] as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled, $name);
        }
        $expire = true;
        try {
            $engine->eval('outerLoop()');
            self::fail('The expired host budget was ignored.');
        } catch (BudgetExceeded) {
            self::assertFalse($engine->getGlobalEnv()->get('caught')->value);
            self::assertSame(0, $engine->getInterpreter()->getCallStack()->depth());
        }
        $engine->setExecutionBudget(null);
        $expire = false;
        self::assertSame([2, false], $engine->eval('[outerLoop(), caught]'));
        self::assertSame(0, $engine->getInterpreter()->getCallStack()->depth());
    }
}
