<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

class GlobalObjectEnvironmentTest extends TestCase
{
    public function testGlobalVarCreatesAnOwnPropertyWithoutInvokingInheritedAccessors(): void
    {
        foreach (['', '"use strict";'] as $directive) {
            $engine = new Engine();
            $engine->eval(<<<'JS'
var calls = 0;
Object.defineProperty(Object.prototype, 'inheritedDeclaration', {
    get() { calls++; return 100; },
    set(value) { calls++; },
    configurable: true
});
JS);
            self::assertSame('2|0|true|true|false', $engine->eval($directive . <<<'JS'

var inheritedDeclaration = 2;
var object = { inheritedDeclaration };
var descriptor = Object.getOwnPropertyDescriptor(globalThis, 'inheritedDeclaration');
[object.inheritedDeclaration, calls, descriptor.writable, descriptor.enumerable, descriptor.configurable].join('|');
JS));
        }
    }

    public function testGlobalEvalVarCreatesAConfigurableOwnProperty(): void
    {
        foreach (['eval', '(0, eval)'] as $evaluate) {
            $engine = new Engine();
            $engine->eval(<<<'JS'
var calls = 0;
Object.defineProperty(Object.prototype, 'inheritedEvalDeclaration', {
    get() { calls++; return 100; },
    set(value) { calls++; },
    configurable: true
});
JS);
            $engine->eval($evaluate . '("var inheritedEvalDeclaration = 3;");');
            self::assertSame('3|0|true|true|true', $engine->eval(<<<'JS'
var descriptor = Object.getOwnPropertyDescriptor(globalThis, 'inheritedEvalDeclaration');
[inheritedEvalDeclaration, calls, descriptor.writable, descriptor.enumerable, descriptor.configurable].join('|');
JS));
        }
    }

    public function testGlobalVarWithoutAnInitializerPreservesAnExistingOwnAccessor(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
Object.defineProperty(globalThis, 'existingDeclaration', {
    get() { return 7; },
    configurable: true
});
JS);
        self::assertSame('7|function|false|true', $engine->eval(<<<'JS'
var existingDeclaration;
var descriptor = Object.getOwnPropertyDescriptor(globalThis, 'existingDeclaration');
[existingDeclaration, typeof descriptor.get, descriptor.enumerable, descriptor.configurable].join('|');
JS));
    }

    public function testGlobalAndEvalVarsShadowInheritedNonWritableProperties(): void
    {
        $sources = [
            'script' => 'var inheritedReadOnly = 5;',
            'strict-script' => '"use strict"; var inheritedReadOnly = 5;',
            'direct-eval' => 'eval("var inheritedReadOnly = 5;");',
            'indirect-eval' => '(0, eval)("var inheritedReadOnly = 5;");',
        ];
        foreach ($sources as $mode => $source) {
            $engine = new Engine();
            $engine->eval(<<<'JS'
Object.defineProperty(Object.prototype, 'inheritedReadOnly', {
    value: 100,
    writable: false,
    configurable: false
});
JS);
            $engine->eval($source);
            self::assertSame([5, true, true, str_ends_with($mode, 'eval')], $engine->eval(<<<'JS'
var descriptor = Object.getOwnPropertyDescriptor(globalThis, 'inheritedReadOnly');
[inheritedReadOnly, descriptor.writable, descriptor.enumerable, descriptor.configurable];
JS));
        }
    }

    public function testInheritedProxyBindingsUseTheGlobalReceiverAndStayLive(): void
    {
        foreach (['', '"use strict";'] as $directive) {
            $engine = new Engine();
            self::assertSame('7|12|false|true', $engine->eval($directive . <<<'JS'

var observed = [];
var stored = 4;
var objectGlobal = globalThis;
Object.setPrototypeOf(objectGlobal, new Proxy(Object.getPrototypeOf(objectGlobal), {
    has(target, key) {
        return key === 'inheritedValue' || Reflect.has(target, key);
    },
    get(target, key, receiver) {
        if (key === 'inheritedValue') {
            observed.push(receiver === objectGlobal);
            return stored;
        }
        return Reflect.get(target, key, receiver);
    },
    set(target, key, value, receiver) {
        if (key === 'inheritedValue') {
            observed.push(receiver === objectGlobal);
            stored = value;
            return true;
        }
        return Reflect.set(target, key, value, receiver);
    }
}));
inheritedValue += 3;
var first = inheritedValue;
stored = 12;
[first, inheritedValue, Object.hasOwn(objectGlobal, 'inheritedValue'), observed.every(Boolean)].join('|');
JS));
        }
    }

    public function testRejectedInheritedWritesRespectStrictnessWithoutCreatingBindings(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
Object.setPrototypeOf(globalThis, new Proxy(Object.getPrototypeOf(globalThis), {
    has(target, key) { return key === 'blockedValue' || Reflect.has(target, key); },
    get(target, key, receiver) {
        return key === 'blockedValue' ? 42 : Reflect.get(target, key, receiver);
    },
    set(target, key, value, receiver) {
        return key === 'blockedValue' ? false : Reflect.set(target, key, value, receiver);
    }
}));
blockedValue = 1;
JS);
        self::assertSame(42, $engine->eval('blockedValue'));
        self::assertFalse($engine->eval('Object.hasOwn(globalThis, "blockedValue")'));
        self::assertTrue($engine->eval(<<<'JS'
'use strict';
try { blockedValue = 2; }
catch (error) { error.constructor === TypeError; }
JS));
    }
}
