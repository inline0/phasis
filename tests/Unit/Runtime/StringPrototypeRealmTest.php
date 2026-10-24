<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class StringPrototypeRealmTest extends TestCase
{
    public function testCompiledStringLookupsUseTheCalleeRealm(): void
    {
        $this->checkRealmLookups(false);
    }

    public function testInterpretedStringLookupsUseTheCalleeRealm(): void
    {
        $this->checkRealmLookups(true);
    }

    private function checkRealmLookups(bool $budgeted): void
    {
        $foreign = new Engine();
        $caller = new Engine();
        foreach ([$foreign, $caller] as $index => $engine) {
            if ($budgeted) {
                $engine->setExecutionBudget(10);
            }
            $engine->setGlobal('marker', $index + 1);
            $engine->eval(<<<'JS'
String.prototype.realmMethod = function () { return marker; };
Object.defineProperty(String.prototype, 'realmProperty', {
    configurable: true,
    get: function () { 'use strict'; return marker + ':' + typeof this + ':' + this; }
});
function namedMethod(text) { return text.realmMethod(); }
function computedMethod(text, key) { return text[key](); }
function namedProperty(text) { return text.realmProperty; }
function computedProperty(text, key) { return text[key]; }
function readCode(text) { return text.charCodeAt(0); }
function callBack(text, callback) { return [callback(text), text.realmProperty]; }
namedMethod('x');
computedMethod('x', 'realmMethod');
namedProperty('x');
computedProperty('x', 'realmProperty');
readCode('x');
callBack('x', namedMethod);
JS);
        }
        foreach (['namedMethod', 'computedMethod', 'namedProperty', 'computedProperty', 'readCode', 'callBack'] as $name) {
            $function = $foreign->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            if (!$budgeted) {
                self::assertNotNull($function->compiled, $name);
            }
            $caller->setGlobalJsValue('foreign' . ucfirst($name), $function);
        }
        $caller->eval('String.prototype.charCodeAt = () => 999; const foreignContainer = { read: foreignNamedMethod };');
        self::assertSame(
            [1, 1, '1:string:x', '1:string:x', 120, 1, [2, '1:string:x'], 2, '2:string:x', 999],
            $caller->eval(<<<'JS'
[
    foreignNamedMethod('x'),
    foreignComputedMethod('x', 'realmMethod'),
    foreignNamedProperty('x'),
    foreignComputedProperty('x', 'realmProperty'),
    foreignReadCode('x'),
    foreignContainer.read('x'),
    foreignCallBack('x', namedMethod),
    namedMethod('x'),
    namedProperty('x'),
    readCode('x')
]
JS),
        );
        self::assertSame([1, '1:string:x', 120], $foreign->eval("[namedMethod('x'), namedProperty('x'), readCode('x')]"));
    }
}
