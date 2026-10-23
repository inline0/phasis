<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Object\PropertyDescriptor;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNumber;
use PHPUnit\Framework\TestCase;

class GlobalProgramBindingsTest extends TestCase
{
    public function testDynamicFunctionReadsAndWritesLiveProgramVariables(): void
    {
        $engine = new Engine();
        self::assertSame('2,3,3', $engine->eval(<<<'JS'
var value = 1;
var read = new Function('return value');
value = 2;
var before = read();
new Function('value = 3')();
[before, value, globalThis.value].join();
JS));
        self::assertSame(3, $engine->eval('value'));
    }

    public function testIndirectEvalAndHostCodeShareTheGlobalObjectBinding(): void
    {
        $engine = new Engine();
        $engine->setGlobal('updateGlobal', function () use ($engine): void {
            $engine->getGlobalEnv()->get('globalThis')->set('value', JsNumber::of(4.0));
        });
        self::assertSame('2,4', $engine->eval(<<<'JS'
var value = 1;
(0, eval)('value = 2');
var before = value;
updateGlobal();
[before, value].join();
JS));
    }

    public function testCompiledFunctionsReReadObjectBackedGlobalValues(): void
    {
        $engine = new Engine();
        self::assertSame('0,1,2', $engine->eval(<<<'JS'
var value = 0;
var increment = new Function('this.value += 1');
function readValues() {
    var result = [];
    for (var i = 0; i < 3; i++) {
        result.push(value);
        increment();
    }
    return result.join();
}
readValues();
JS));
    }

    public function testCompiledFunctionsNeverCacheGlobalAccessorResults(): void
    {
        $engine = new Engine();
        $next = 0;
        $getter = JsFunction::fromCallable('get changing', function () use (&$next): JsNumber {
            return JsNumber::of((float) ++$next);
        });
        $engine->getGlobalEnv()->get('globalThis')->defineOwnProperty(
            'changing',
            PropertyDescriptor::accessor(get: $getter, set: null, enumerable: false, configurable: true),
        );
        self::assertSame('1,2,3', $engine->eval(<<<'JS'
function readAccessor() {
    var result = [];
    for (var i = 0; i < 3; i++) result.push(changing);
    return result.join();
}
readAccessor();
JS));
    }
}
