<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Exceptions\BudgetExceeded;
use Phasis\Exceptions\InternalError;
use Phasis\Value\JsFunction;
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
    public function testWhileLimitCountsOnlySuccessfulIterations(bool $ast, bool $continue): void
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
        self::assertSame([1, 2], $engine->eval(<<<'JS'
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
            foreach (['while', 'do-while', 'for'] as $loop) {
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
        $condition = '(conditions++, n < 2)';
        $statement = match ($loop) {
            'while' => 'while (' . $condition . ') ' . $body,
            'do-while' => 'do ' . $body . ' while (' . $condition . ');',
            default => 'for (; ' . $condition . ';) ' . $body,
        };
        $engine->eval('var iterations = 0, conditions = 0; function probe() { let n = 0; ' . $statement . ' return n; } probe();');
        $function = $engine->getGlobalEnv()->get('probe');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertSame(!$ast, $function->compiled !== null);
        $engine->eval('iterations = 0; conditions = 0;');
        $engine->setLimit('maxLoopIterations', $limit);
        $expire = true;
        $this->expectException(BudgetExceeded::class);
        try {
            $engine->eval('probe()');
        } finally {
            self::assertSame(1.0, $engine->getGlobalEnv()->get('iterations')->value);
            self::assertSame(1.0, $engine->getGlobalEnv()->get('conditions')->value);
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
    public function testEffectfulConditionsFallBackAndCountOnlySuccessfulIterations(bool $continue): void
    {
        $engine = new Engine();
        $body = 'n++;' . ($continue ? 'continue;' : '');
        $engine->eval(<<<'JS'
var conditions = 0;
function condition(n) { conditions++; return n < 1 ? 1 : 0; }
JS);
        $engine->eval('function probe() { function condition(value) { conditions++; return value < 1 ? 1 : 0; } '
            . 'let n = 0; while (condition(n)) {' . $body . '} return n; } probe();');
        $function = $engine->getGlobalEnv()->get('probe');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNull($function->phpCompiled);
        $engine->eval('conditions = 0');
        $engine->setLimit('maxLoopIterations', 1);
        self::assertSame(1, $engine->eval('probe()'));
        self::assertSame(2.0, $engine->getGlobalEnv()->get('conditions')->value);
        $engine->eval('conditions = 0');
        $engine->setLimit('maxLoopIterations', 0);
        $this->expectException(InternalError::class);
        try {
            $engine->eval('probe()');
        } finally {
            self::assertSame(1.0, $engine->getGlobalEnv()->get('conditions')->value);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function emptyLoopTiers(): iterable
    {
        foreach (['ast', 'bytecode', 'php'] as $tier) {
            yield $tier => [$tier];
        }
    }

    #[DataProvider('emptyLoopTiers')]
    public function testZeroLimitPermitsAnInitiallyFalseLoop(string $tier): void
    {
        $engine = new Engine();
        if ($tier === 'ast') {
            $engine->setExecutionBudget(10);
        }
        $engine->eval('var conditions = 0; function condition(n) { conditions++; return n; }');
        $loop = 'while (condition(n)) { n--; }';
        if ($tier === 'bytecode') {
            $loop = 'try {' . $loop . '} finally {}';
        }
        $engine->eval('function probe(n) { function condition(value) { conditions++; return value; } '
            . $loop . ' return n; } probe(1);');
        $function = $engine->getGlobalEnv()->get('probe');
        self::assertInstanceOf(JsFunction::class, $function);
        $engine->eval('conditions = 0');
        $engine->setLimit('maxLoopIterations', 0);
        if ($tier === 'php') {
            self::assertNull($function->phpCompiled);
            self::assertNotNull($function->compiled);
            self::assertSame(0, $engine->eval('probe(0)'));
        } else {
            self::assertSame($tier === 'bytecode', $function->compiled !== null);
            self::assertSame(0, $engine->eval('probe(0)'));
        }
        self::assertSame(1.0, $engine->getGlobalEnv()->get('conditions')->value);
    }

    public function testEffectfulBudgetActivationFallsBackBeforeTheIterationLimit(): void
    {
        $engine = new Engine();
        $expire = false;
        $engine->setGlobal('expireBudget', static function () use ($engine, &$expire): void {
            if ($expire) {
                $engine->setExecutionBudget(-1);
            }
        });
        $engine->eval('function probe() { function expireLocal() { expireBudget(); } '
            . 'let n = 0; while (n < 2) { n++; expireLocal(); } return n; } probe();');
        $function = $engine->getGlobalEnv()->get('probe');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNull($function->phpCompiled);
        $engine->setLimit('maxLoopIterations', 1);
        $expire = true;
        $this->expectException(BudgetExceeded::class);
        $engine->eval('probe()');
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
function middleLoop() { try { return innerLoop(); } finally {} }
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
