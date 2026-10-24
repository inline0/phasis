<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ObjectLiteralRealmTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function modes(): iterable
    {
        yield 'compiled' => [false];
        yield 'budgeted-ast' => [true];
    }

    #[DataProvider('modes')]
    public function testForeignFunctionsAndConstructorsUseTheirOwnPrototype(bool $budgeted): void
    {
        $foreign = new Engine();
        $foreign->eval(<<<'JS'
function foreignLiteral(callback) { return [{}, callback(), {}]; }
var foreignMarkCalls = 0;
Object.prototype.markForeignLiteral = function () { this.observed = 31; foreignMarkCalls++; };
function foreignPhpLiteral(value) {
    const object = {observed: 0, value: value};
    const result = object.value;
    object.markForeignLiteral();
    return result;
}
class ForeignRecord { constructor() { this.value = {}; } }
foreignLiteral(() => ({})); foreignPhpLiteral(1); new ForeignRecord();
JS);
        $prototype = $foreign->getGlobalEnv()->get('__ObjectPrototype__');
        self::assertInstanceOf(JsObject::class, $prototype);
        $caller = new Engine();
        if ($budgeted) {
            $caller->setExecutionBudget(10);
        }
        foreach (['foreignLiteral', 'foreignPhpLiteral', 'ForeignRecord'] as $name) {
            $function = $foreign->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            $caller->getGlobalEnv()->defineVar($name, $function);
        }
        $caller->getGlobalEnv()->defineVar('foreignObjectPrototype', $prototype);
        $caller->eval(<<<'JS'
var wrongRealmCalls = 0;
Object.prototype.markForeignLiteral = function () { this.observed = 73; wrongRealmCalls++; };
Object.prototype.foreignMethod = foreignPhpLiteral;
function localLiteral() { return {}; }
function localStandard(value) { foreignPhpLiteral(value); return value; }
function localNumeric(value) { return foreignPhpLiteral(value) + 0; }
function localMethod(value) {
    const receiver = {value: value};
    receiver.foreignMethod(value);
    return value;
}
localLiteral();
JS);
        $foreign->eval('foreignMarkCalls = 0');
        for ($pass = 0; $pass < 3; $pass++) {
            self::assertSame([true, true, true, true, true, true, 1, 1, 1, 0], $caller->eval(<<<'JS'
var values = foreignLiteral(localLiteral);
[Object.getPrototypeOf(values[0]) === foreignObjectPrototype,
 Object.getPrototypeOf(values[1]) === Object.prototype,
 Object.getPrototypeOf(values[2]) === foreignObjectPrototype,
 Object.getPrototypeOf(new ForeignRecord().value) === foreignObjectPrototype,
 foreignPhpLiteral(1) === 1,
 Object.getPrototypeOf(localLiteral()) === Object.prototype,
 localStandard(1), localNumeric(1), localMethod(1), wrongRealmCalls];
JS));
            self::assertSame(($pass + 1) * 4, $foreign->eval('foreignMarkCalls'));
        }
        $phpFunction = $foreign->getGlobalEnv()->get('foreignPhpLiteral');
        self::assertInstanceOf(JsFunction::class, $phpFunction);
        self::assertInstanceOf(\Closure::class, $phpFunction->phpCompiled);
        self::assertInstanceOf(\Closure::class, $phpFunction->phpCompiledNumeric);
        $phpFunction->phpCompiledNumeric = null;
        self::assertSame([1, 0], $caller->eval('[localNumeric(1), wrongRealmCalls]'));
        if (!$budgeted) {
            foreach (['localStandard', 'localNumeric', 'localMethod'] as $name) {
                $wrapper = $caller->getGlobalEnv()->get($name);
                self::assertInstanceOf(JsFunction::class, $wrapper);
                self::assertInstanceOf(\Closure::class, $wrapper->phpCompiled, $name);
            }
        }
        self::assertSame(0, $caller->getInterpreter()->getCallStack()->depth());
        self::assertSame(0, $foreign->getInterpreter()->getCallStack()->depth());
    }
}
