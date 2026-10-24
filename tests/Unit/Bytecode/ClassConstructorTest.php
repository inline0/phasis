<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Exceptions\BudgetExceeded;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use PHPUnit\Framework\TestCase;

final class ClassConstructorTest extends TestCase
{
    public function testConstructorBodiesMatchTheNodeOracle(): void
    {
        $engine = $this->checkOracle(false);
        foreach (
            [
            'ConstructorBase', 'ConstructorDerived', 'ConstructorGrandchild', 'ConstructorReplacementBase',
            'ConstructorReplacement', 'ConstructorCapture', 'ConstructorHoisted', 'ConstructorPrivateBase',
            'ConstructorRest', 'ConstructorUndefined', 'ConstructorPrimitive', 'ConstructorObject',
            'ConstructorReturnBase', 'ConstructorThrowBase', 'ConstructorThrow', 'ConstructorNull',
            ] as $name
        ) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled, $name);
            self::assertFalse($function->compiled->canSkipEnvAlloc, $name);
            self::assertFalse($function->compiled->hasTailCalls, $name);
            self::assertNull($function->phpCompiled, $name);
        }
        foreach (
            [
            'ConstructorPrivateDerived', 'ConstructorBeforeThis', 'ConstructorDouble',
            'ConstructorNewTarget', 'ConstructorSpread',
            ] as $name
        ) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNull($function->compiled, $name);
        }
        self::assertSame(0, $engine->getInterpreter()->getCallStack()->depth());
    }

    public function testBudgetedConstructorsMatchTheSameNodeOracle(): void
    {
        $this->checkOracle(true);
    }

    public function testConstructorLiteralsUseTheCalleeRealm(): void
    {
        $foreign = new Engine();
        $foreign->eval(<<<'JS'
class ForeignBase { constructor(value) { this.array = [value]; this.object = {value}; } }
class ForeignDerived extends ForeignBase { constructor(value) { super(value); this.other = []; } }
new ForeignBase(0); new ForeignDerived(0);
JS);
        $caller = new Engine();
        foreach (['ForeignBase', 'ForeignDerived'] as $name) {
            $function = $foreign->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            $caller->getGlobalEnv()->defineVar($name, $function);
        }
        foreach (['ArrayPrototype', 'ObjectPrototype'] as $name) {
            $prototype = $name === 'ObjectPrototype'
                ? $foreign->getGlobalEnv()->get('__ObjectPrototype__')
                : $foreign->getGlobalEnv()->getIntrinsic($name);
            self::assertInstanceOf(JsObject::class, $prototype);
            $caller->getGlobalEnv()->defineVar('foreign' . $name, $prototype);
        }
        for ($pass = 0; $pass < 3; $pass++) {
            self::assertSame([true, true, true, true, true], $caller->eval(<<<'JS'
var base = new ForeignBase(1), derived = new ForeignDerived(2);
[Object.getPrototypeOf(base.array) === foreignArrayPrototype,
 Object.getPrototypeOf(base.object) === foreignObjectPrototype,
 Object.getPrototypeOf(derived.array) === foreignArrayPrototype,
 Object.getPrototypeOf(derived.object) === foreignObjectPrototype,
 Object.getPrototypeOf(derived.other) === foreignArrayPrototype];
JS));
        }
        foreach (['ForeignBase', 'ForeignDerived'] as $name) {
            $function = $foreign->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled);
        }
    }

    public function testSuperTargetIsCapturedBeforeArgumentSideEffects(): void
    {
        // ECMA-262 13.3.7.1 captures GetSuperConstructor before ArgumentListEvaluation.
        // Node 26.12.0 resolves this edge case later, so its result is not the oracle here.
        foreach ([false, true] as $budgeted) {
            $engine = new Engine();
            if ($budgeted) {
                $engine->setExecutionBudget(10);
            }
            self::assertSame([true, true, ['argument', 'original']], $engine->eval(<<<'JS'
const events = [];
class OriginalBase { constructor(value) { events.push('original'); this.value = value; } }
class OtherBase { constructor(value) { events.push('other'); this.other = value; } }
class Derived extends OriginalBase {
    constructor(value) { super((events.push('argument'), Object.setPrototypeOf(Derived, OtherBase), value)); }
}
const result = new Derived(2);
[result.value === 2, !('other' in result), events];
JS));
            $function = $engine->getGlobalEnv()->get('Derived');
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNull($function->compiled);
        }
    }

    public function testConstructorLoopsRespectBudgetsAndUnwind(): void
    {
        $engine = new Engine();
        $engine->setLimit('maxLoopIterations', PHP_INT_MAX);
        self::assertSame(3, $engine->eval(<<<'JS'
class LoopBase { constructor(limit) { this.limit = limit; } }
class LoopDerived extends LoopBase {
    constructor(limit) { super(limit); let count = 0; while (count < limit) count++; this.count = count; }
}
new LoopDerived(3).count;
JS));
        $function = $engine->getGlobalEnv()->get('LoopDerived');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNotNull($function->compiled);
        $engine->setExecutionBudget(0.002);
        try {
            $engine->eval('new LoopDerived(100000000)');
            self::fail('The constructor loop ignored its host budget.');
        } catch (BudgetExceeded) {
            self::assertSame(0, $engine->getInterpreter()->getCallStack()->depth());
        }
        $engine->setExecutionBudget(null);
        self::assertSame(4, $engine->eval('new LoopDerived(4).count'));
        self::assertSame(0, $engine->getInterpreter()->getCallStack()->depth());
    }

    private function checkOracle(bool $budgeted): Engine
    {
        $engine = new Engine();
        if ($budgeted) {
            $engine->setExecutionBudget(10);
        }
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/class-constructors.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/class-constructors.json',
            $engine->eval('JSON.stringify(constructorResults)'),
        );
        return $engine;
    }
}
