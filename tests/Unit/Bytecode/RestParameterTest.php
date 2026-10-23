<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Exceptions\BudgetExceeded;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use PHPUnit\Framework\TestCase;

final class RestParameterTest extends TestCase
{
    public function testRestFunctionsAndCapturedBindingsMatchTheNodeOracle(): void
    {
        $engine = $this->checkOracle(false);
        $compiledNames = [
            'restOnly', 'restPrefix', 'restCaptured', 'restVarRedeclared', 'restHoisted', 'restDefaults',
            'strictRestArguments', 'restArrow', 'restMutation', 'restObjectIdentity',
            'restLegacy',
        ];
        foreach ($compiledNames as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled, $name);
            self::assertTrue($function->compiled->parametersFromEnvironment, $name);
        }
        $captured = $engine->getGlobalEnv()->get('restCaptured');
        self::assertInstanceOf(JsFunction::class, $captured);
        self::assertSame(['count' => 'let'], $captured->compiled?->capturedEnvironmentBindings);
        foreach (['restDefaults', 'strictRestArguments'] as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNull($function->compiled?->capturedEnvironmentBindings, $name);
        }
        foreach (['restArgumentSnapshot', 'restPattern', 'restEval', 'restWith'] as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNull($function->compiled, $name);
        }
    }

    public function testBudgetedRestFunctionsMatchTheSameNodeOracle(): void
    {
        $this->checkOracle(true);
    }

    public function testRestArraysUseTheCalleeRealmAcrossCalls(): void
    {
        $foreign = new Engine();
        $foreign->eval('function foreignRest(...values) { return values; }');
        $function = $foreign->getGlobalEnv()->get('foreignRest');
        $prototype = $foreign->getGlobalEnv()->getIntrinsic('ArrayPrototype');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertInstanceOf(JsObject::class, $prototype);
        $caller = new Engine();
        $caller->getGlobalEnv()->defineVar('foreignRest', $function);
        $caller->getGlobalEnv()->defineVar('foreignArrayPrototype', $prototype);
        for ($pass = 0; $pass < 3; $pass++) {
            self::assertSame([true, true, 2], $caller->eval(<<<'JS'
var result = foreignRest(1, 2);
[Object.getPrototypeOf(result) === foreignArrayPrototype,
    Object.getPrototypeOf(result) !== Array.prototype, result.length];
JS));
        }
        self::assertNotNull($function->compiled);
    }

    public function testBudgetTransitionsAndCallStackTrackingRemainActive(): void
    {
        $engine = new Engine();
        $engine->setLimit('maxLoopIterations', PHP_INT_MAX);
        $engine->setGlobal('restDepth', fn (): int => $engine->getInterpreter()->getCallStack()->depth());
        self::assertGreaterThanOrEqual(9, $engine->eval(<<<'JS'
function restRecurse(depth, ...values) {
    if (depth === 0) return restDepth();
    return restRecurse(depth - 1, values.length);
}
restRecurse(8, 1, 2);
JS));
        self::assertSame(0, $engine->getInterpreter()->getCallStack()->depth());
        self::assertSame(6, $engine->eval(<<<'JS'
function restLoop(limit, ...values) {
    let total = 0;
    for (let index = 0; index < limit; index++) total += values.length;
    return total;
}
restLoop(3, 1, 2);
JS));
        $engine->setExecutionBudget(0.002);
        $exceeded = false;
        try {
            $engine->eval('restLoop(100000000, 1, 2);');
        } catch (BudgetExceeded) {
            $exceeded = true;
        }
        self::assertTrue($exceeded);
        self::assertSame(0, $engine->getInterpreter()->getCallStack()->depth());
        $engine->setExecutionBudget(null);
        self::assertSame(6, $engine->eval('restLoop(2, 1, 2, 3);'));
    }

    public function testRetainedCapturesKeepTheirScopesAcrossBudgetTransitions(): void
    {
        $engine = new Engine();
        self::assertSame([1, 2], $engine->eval(<<<'JS'
function restScope(first, ...values) {
    let value = first;
    return () => [value, values.length];
}
restScope(1, 2, 3)();
JS));
        $engine->setExecutionBudget(5);
        self::assertSame([4, 1], $engine->eval('var retainedRestScope = restScope(4, 5); retainedRestScope();'));
        $engine->setExecutionBudget(null);
        self::assertSame([6, 3], $engine->eval('restScope(6, 7, 8, 9)();'));
        self::assertSame([4, 1], $engine->eval('retainedRestScope();'));
    }

    private function checkOracle(bool $budgeted): Engine
    {
        $engine = new Engine();
        if ($budgeted) {
            $engine->setExecutionBudget(10);
        }
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/rest-parameters.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/rest-parameters.json',
            $engine->eval('JSON.stringify(restParameterResult)'),
        );
        return $engine;
    }
}
