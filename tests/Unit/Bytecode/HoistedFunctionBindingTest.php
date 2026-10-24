<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class HoistedFunctionBindingTest extends TestCase
{
    public function testHoistedFunctionsMatchTheNodeOracleAcrossWarmActivations(): void
    {
        $engine = $this->checkOracle(false);
        foreach (['localOnly', 'siblings', 'duplicateDeclarations', 'varRedeclaration', 'varReplacement', 'strictBindings'] as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotEmpty($function->compiled?->omittedFunctionBindings, $name);
        }
        foreach (['recursive', 'parameterCollision', 'restCollision', 'defaultCollision', 'classEval'] as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertSame([], $function->compiled?->omittedFunctionBindings ?? [], $name);
        }
    }

    public function testBudgetedHoistsMatchTheSameNodeOracle(): void
    {
        $this->checkOracle(true);
    }

    public function testUnobservedFunctionBindingsDoNotKeepTheirActivationsAlive(): void
    {
        foreach ([false, true] as $freshClosure) {
            $engine = new Engine();
            $engine->eval(<<<'JS'
function makeCycleFactory() {
    return function cycleFactory() {
        const token = {};
        function helper() { return token; }
        return token;
    };
}
var cycleFactory = makeCycleFactory();
cycleFactory();
cycleFactory();
JS);
            if ($freshClosure) {
                $engine->eval('cycleFactory = makeCycleFactory();');
            }
            $enabled = gc_enabled();
            gc_collect_cycles();
            gc_disable();
            try {
                $engine->eval('var cycleProbe = cycleFactory();');
                $token = $engine->getGlobalEnv()->get('cycleProbe');
                $reference = \WeakReference::create($token);
                unset($token);
                $engine->eval('cycleProbe = null;');
                self::assertNull($reference->get(), 'An unobserved helper must not retain its activation.');
            } finally {
                $engine->reset();
                if ($enabled) {
                    gc_enable();
                }
                gc_collect_cycles();
            }
        }
    }

    public function testEscapingFunctionsKeepTheirCalleeRealmAndSeparateCaptures(): void
    {
        $foreign = new Engine();
        $foreign->eval(<<<'JS'
function createHelper(value) {
    function helper() { return value; }
    return helper;
}
createHelper(0);
JS);
        $function = $foreign->getGlobalEnv()->get('createHelper');
        self::assertInstanceOf(JsFunction::class, $function);
        $caller = new Engine();
        $caller->getGlobalEnv()->defineVar('createHelper', $function);
        $prototype = $function->getPrototype();
        self::assertNotNull($prototype);
        $caller->getGlobalEnv()->defineVar('foreignFunctionPrototype', $prototype);
        for ($pass = 0; $pass < 3; $pass++) {
            self::assertSame([3, 7, false, true, true], $caller->eval(<<<'JS'
var firstHelper = createHelper(3);
var secondHelper = createHelper(7);
[firstHelper(), secondHelper(), firstHelper === secondHelper,
    Object.getPrototypeOf(firstHelper) === foreignFunctionPrototype,
    Object.getPrototypeOf(firstHelper) !== Function.prototype];
JS));
        }
        self::assertNotEmpty($function->compiled?->omittedFunctionBindings);
        $caller->setExecutionBudget(5);
        self::assertSame(11, $caller->eval('createHelper(11)();'));
        $caller->setExecutionBudget(null);
        self::assertSame(13, $caller->eval('createHelper(13)();'));
    }

    private function checkOracle(bool $budgeted): Engine
    {
        $engine = new Engine();
        if ($budgeted) {
            $engine->setExecutionBudget(10);
        }
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/hoist-bindings.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/hoist-bindings.json',
            $engine->eval('JSON.stringify(hoistBindingResult)'),
        );
        return $engine;
    }
}
