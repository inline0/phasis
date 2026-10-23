<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

class GlobalObjectEnvironmentTest extends TestCase
{
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
