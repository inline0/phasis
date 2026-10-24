<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Bytecode\Bailout;
use Phasis\Bytecode\JsToPhp;
use Phasis\Engine;
use Phasis\Runtime\Environment;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNumber;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CallerActivationTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, list<string>, list<string>}> */
    public static function observers(): iterable
    {
        yield 'terminal-caller' => [
            <<<'JS'
function observe() {
    return observe.caller === outer;
}
function outer() {
    return observe();
}
JS,
            'outer()', 'true', ['outer'], [],
        ];
        yield 'terminal-arguments' => [
            <<<'JS'
function observe() {
    return outer.arguments[0];
}
function outer(value) {
    return observe();
}
JS,
            'outer(7)', '7', ['outer'], [],
        ];
        yield 'discarded-call' => [
            <<<'JS'
var seen = false;
function observe() {
    seen = observe.caller === outer && outer.arguments[0] === 7;
}
function outer(value) {
    observe();
    return value;
}
function output() {
    seen = false;
    return [outer(7), seen];
}
JS,
            'output()', '[7,true]', ['outer'], [],
        ];
        yield 'strict-barrier' => [
            <<<'JS'
var seen = false;
function observe() {
    seen = observe.caller === null;
}
function outer(value) {
    'use strict';
    observe();
    return value;
}
function output() {
    seen = false;
    outer(7);
    return seen;
}
JS,
            'output()', 'true', ['outer'], [],
        ];
        yield 'method-getter' => [
            <<<'JS'
var getters = 0, methods = 0, seen = false;
function getter() {
    getters++;
    seen = getter.caller === outer && outer.arguments[0] === 7;
    return method;
}
function method(value) {
    methods++;
    return seen && method.caller === outer ? value : 0;
}
Object.defineProperty(Object.prototype, 'observe', { get: getter, configurable: true });
function outer(value) {
    const object = { value: value };
    return object.observe(value);
}
function output() {
    getters = methods = 0;
    seen = false;
    return [outer(7), getters, methods];
}
JS,
            'output()', '[7,1,1]', ['outer'], [],
        ];
        yield 'constructor' => [
            <<<'JS'
var seen = false, calls = 0;
function Ctor() {
    calls++;
    seen = Ctor.caller === outer && outer.arguments[0] === 7;
}
function outer(value) {
    const ignored = new Ctor();
    return value;
}
function output() {
    seen = false;
    calls = 0;
    return [outer(7), seen, calls];
}
JS,
            'output()', '[7,true,1]', ['outer'], [],
        ];
        yield 'numeric-constructor-chain' => [
            <<<'JS'
var seen = false;
function Ctor() {
    seen = Ctor.caller === bridge && bridge.caller === outer && bridge.arguments[0] === 7;
}
function bridge(value) {
    const ignored = new Ctor();
    return value;
}
function outer(value) {
    return bridge(value) + 1;
}
function output() {
    seen = false;
    return [outer(7), seen];
}
JS,
            'output()', '[8,true]', ['outer', 'bridge'], [],
        ];
        yield 'numeric-callback-chain' => [
            <<<'JS'
var seen = false;
function observe() {
    seen = observe.caller === bridge && bridge.caller === outer && bridge.arguments[0] === 7;
}
function bridge(value) {
    observe();
    return value;
}
function outer(value) {
    return bridge(value) + 1;
}
function output() {
    seen = false;
    return [outer(7), seen];
}
JS,
            'output()', '[8,true]', ['outer', 'bridge'], [],
        ];
        yield 'free-binding-getter' => [
            <<<'JS'
var seen = false, reads = 0;
function getter() {
    reads++;
    seen = getter.caller === outer && outer.arguments[0] === 7;
    return 3;
}
Object.defineProperty(globalThis, 'observed', { get: getter, configurable: true });
function outer(value) {
    return observed;
}
function output() {
    seen = false;
    reads = 0;
    return [outer(7), seen, reads];
}
JS,
            'output()', '[3,true,1]', [], ['outer'],
        ];
        yield 'member-getter' => [
            <<<'JS'
var seen = false, reads = 0;
function getter() {
    reads++;
    seen = getter.caller === outer && outer.arguments[0] === 7;
    return 3;
}
Object.defineProperty(Object.prototype, 'observed', { get: getter, configurable: true });
function outer(value) {
    const object = {};
    return object.observed;
}
function output() {
    seen = false;
    reads = 0;
    return [outer(7), seen, reads];
}
JS,
            'output()', '[3,true,1]', [], ['outer'],
        ];
        yield 'while-condition' => [
            <<<'JS'
var seen = false;
function condition() {
    seen = condition.caller === outer && outer.arguments[0] === 7;
    return false;
}
function outer(value) {
    while (condition()) {
        break;
    }
    return seen;
}
JS,
            'outer(7)', 'true', ['outer'], [],
        ];
        yield 'for-condition' => [
            <<<'JS'
var seen = false;
function condition() {
    seen = condition.caller === outer && outer.arguments[0] === 7;
    return false;
}
function outer(value) {
    for (; condition();) {
        break;
    }
    return seen;
}
JS,
            'outer(7)', 'true', ['outer'], [],
        ];
        yield 'do-condition' => [
            <<<'JS'
var seen = false;
function condition() {
    seen = condition.caller === outer && outer.arguments[0] === 7;
    return false;
}
function outer(value) {
    do {
    } while (condition());
    return seen;
}
JS,
            'outer(7)', 'true', ['outer'], [],
        ];
        yield 'eval-frame-chain' => [
            <<<'JS'
function observe() {
    return observe.caller.caller === outer;
}
function bridge() {
    return eval('observe()');
}
function outer() {
    return bridge();
}
JS,
            'outer()', 'true', ['outer'], [],
        ];
    }

    #[DataProvider('observers')]
    public function testObservableBoundariesKeepCallerAndArgumentRecords(
        string $definitions,
        string $expression,
        string $expected,
        array $declined,
        array $guarded,
    ): void {
        $engine = new Engine();
        $engine->eval($definitions);
        for ($pass = 0; $pass < 2; $pass++) {
            self::assertSame($expected, $engine->eval('JSON.stringify(' . $expression . ')'));
        }
        foreach ($declined as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNull($function->phpCompiled, $name);
            self::assertNull($function->phpCompiledNumeric, $name);
        }
        foreach ($guarded as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertInstanceOf(\Closure::class, $function->phpCompiled);
            $before = $engine->getGlobalEnv()->get('reads')->value;
            $previous = Engine::getCurrentInterpreter();
            Engine::setCurrentInterpreter($engine->getInterpreter());
            try {
                ($function->phpCompiled)(
                    [JsNumber::of(7)],
                    JsUndefined::instance(),
                    $function->getClosure(),
                    $engine->getInterpreter(),
                    $function->phpCompiledNodes,
                );
                self::fail('A getter must run through a tracked activation.');
            } catch (Bailout) {
                self::assertSame($before, $engine->getGlobalEnv()->get('reads')->value);
            } finally {
                Engine::setCurrentInterpreter($previous);
            }
        }
    }

    public function testCachedEntriesRejectHostEnvironmentReceiversAndAncestorsBeforeVirtualReads(): void
    {
        $engine = new Engine();
        $engine->eval('var captured = 7; function read() { return captured; } read();');
        $function = $engine->getGlobalEnv()->get('read');
        self::assertInstanceOf(JsFunction::class, $function);
        $host = new class ($engine->getGlobalEnv()) extends Environment {
            public int $reads = 0;

            public function getDataBinding(string $name): ?JsValue
            {
                ++$this->reads;
                return parent::getDataBinding($name);
            }
        };
        $host->defineVar('captured', JsNumber::of(7));
        $previous = Engine::getCurrentInterpreter();
        Engine::setCurrentInterpreter($engine->getInterpreter());
        try {
            foreach ([$host, $host->createChild()] as $scope) {
                self::assertFalse($scope->hasPlainChain());
                $function->closure = $scope;
                self::assertNull(JsToPhp::compile($function));
                foreach ([$function->phpCompiled, $function->phpCompiledNumeric] as $entry) {
                    self::assertInstanceOf(\Closure::class, $entry);
                    try {
                        $entry([], JsUndefined::instance(), $scope, $engine->getInterpreter(), []);
                        self::fail('A cached entry must reject a host environment chain.');
                    } catch (Bailout) {
                        self::assertSame(0, $host->reads);
                    }
                }
            }
        } finally {
            Engine::setCurrentInterpreter($previous);
        }
    }
}
