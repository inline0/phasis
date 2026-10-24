<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArrayCallbackRealmTest extends TestCase
{
    /** @return iterable<string, array{bool, bool}> */
    public static function executionModes(): iterable
    {
        yield 'VM with numeric entry' => [false, true];
        yield 'VM with standard entry' => [false, false];
        yield 'AST with numeric entry' => [true, true];
        yield 'AST with standard entry' => [true, false];
    }

    #[DataProvider('executionModes')]
    public function testDenseCallbacksEnterTheirOwnRealmOnce(bool $budgeted, bool $numeric): void
    {
        $foreign = new Engine();
        $foreign->eval(<<<'JS'
var foreignCalls = 0;
Object.prototype.markForeign = function () { foreignCalls++; };
function foreignCallback(value) {
    const object = {value: value};
    const result = object.value;
    object.markForeign();
    return result;
}
foreignCallback(1);
foreignCalls = 0;
JS);
        $callback = $foreign->getGlobalEnv()->get('foreignCallback');
        self::assertInstanceOf(JsFunction::class, $callback);
        self::assertInstanceOf(\Closure::class, $callback->phpCompiled);
        self::assertInstanceOf(\Closure::class, $callback->phpCompiledNumeric);
        if (!$numeric) {
            $callback->phpCompiledNumeric = null;
        }
        $caller = new Engine();
        $caller->getGlobalEnv()->defineVar('foreignCallback', $callback);
        if ($budgeted) {
            $caller->setExecutionBudget(10);
        }
        $caller->eval(<<<'JS'
var wrongRealmCalls = 0;
Object.prototype.markForeign = function () { wrongRealmCalls++; };
function localMap() { const values = [1, 2]; return values.map(foreignCallback); }
function localReduce() { const values = [1, 2]; return values.reduce(foreignCallback, 0); }
function localEach() { const values = [1, 2]; values.forEach(foreignCallback); return wrongRealmCalls; }
function localFilter() { const values = [1, 2]; return values.filter(foreignCallback); }
JS);
        for ($pass = 0; $pass < 3; $pass++) {
            self::assertSame([[1, 2], 0, 0, [1, 2], 0], $caller->eval(<<<'JS'
[localMap(), localReduce(), localEach(), localFilter(), wrongRealmCalls];
JS));
            self::assertSame(8 * ($pass + 1), $foreign->eval('foreignCalls'));
        }
        if (!$budgeted) {
            foreach (['localMap', 'localReduce', 'localEach', 'localFilter'] as $name) {
                $wrapper = $caller->getGlobalEnv()->get($name);
                self::assertInstanceOf(JsFunction::class, $wrapper);
                self::assertNotNull($wrapper->compiled, $name . ' must exercise the VM callback path.');
            }
        }
        self::assertSame($numeric, $callback->phpCompiledNumeric !== null);
        self::assertInstanceOf(\Closure::class, $callback->phpCompiled);
        self::assertSame(0, $caller->getInterpreter()->getCallStack()->depth());
        self::assertSame(0, $foreign->getInterpreter()->getCallStack()->depth());
    }
}
