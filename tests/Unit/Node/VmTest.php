<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Node;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class VmTest extends TestCase
{
    public function testContextsAndScriptsMatchNodeOracle(): void
    {
        $engine = new Engine(node: true);
        $engine->setExecutionBudget(30);
        $engine->execFile(__DIR__ . '/fixtures/primitives/vm.mjs');
        self::assertNull($engine->eval('globalThis.__nodePrimitiveError'), $engine->getConsoleOutput());
        $result = $engine->eval('globalThis.__nodePrimitiveResult');
        self::assertIsString($result, 'Outer microtasks, nextTicks and timers must keep running.');
        self::assertJsonStringEqualsJsonFile(__DIR__ . '/fixtures/primitives/vm.json', $result);
    }

    public function testContextTimeoutDoesNotPoisonParentOrLaterRuns(): void
    {
        $engine = new Engine(node: true);
        self::assertSame('ERR_SCRIPT_EXECUTION_TIMEOUT:7:9', $engine->eval(<<<'JS'
const vm = require('vm');
let code;
try { vm.runInThisContext('while (true) {}', { timeout: 1 }); } catch (error) { code = error.code; }
[code, vm.runInThisContext('3 + 4'), vm.runInNewContext('4 + 5')].join(':');
JS));
    }

    public function testUnreferencedContextCanBeCollectedWhileItsParentRuntimeRemainsAlive(): void
    {
        $engine = new Engine(node: true);
        $sandbox = new \Phasis\Value\JsObject();
        $reference = \WeakReference::create($sandbox);
        $engine->setGlobalJsValue('temporarySandbox', $sandbox);
        $engine->eval("require('vm').createContext(temporarySandbox);");
        $engine->setGlobalJsValue('temporarySandbox', \Phasis\Value\JsUndefined::instance());
        unset($sandbox);
        // Native intrinsic defaults may retain the most recently installed
        // realm. Older unreachable sandboxes must not accumulate in the registry.
        $engine->eval("for (let index = 0; index < 3; index++) require('vm').createContext({});");
        gc_collect_cycles();
        self::assertNull($reference->get());
        self::assertSame(3, $engine->eval("require('vm').runInNewContext('1 + 2')"));
    }
}
