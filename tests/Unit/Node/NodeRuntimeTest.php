<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Node;

use Phasis\Engine;
use Phasis\Node\NodeOptions;
use PHPUnit\Framework\TestCase;

final class NodeRuntimeTest extends TestCase
{
    public function testNodeGlobalsAreOptIn(): void
    {
        $engine = new Engine();
        self::assertSame('undefined,undefined,undefined', $engine->eval('[typeof process, typeof Buffer, typeof require].join(",")'));
        $engine->enableNode(new NodeOptions(env: ['NODE_ENV' => 'test']));
        self::assertSame('test:68656c6c6f', $engine->eval('process.env.NODE_ENV + ":" + Buffer.from("hello").toString("hex")'));
    }

    public function testBuiltinAliasesShareCommonJsAndModuleIdentity(): void
    {
        $engine = new Engine(node: true);
        self::assertTrue($engine->eval('require("buffer") === require("node:buffer")'));
        self::assertSame('node:buffer,buffer', $engine->eval('[require.resolve("node:buffer"), require.resolve("buffer")].join(",")'));
        $engine->evalAsModule(<<<'JS'
import first, { Buffer } from 'buffer';
import second, * as ns from 'node:buffer';
import * as plain from 'buffer';
globalThis.nodeInterop = first === second && first.Buffer === Buffer && ns === plain;
JS);
        self::assertTrue($engine->eval('nodeInterop'));
        self::assertTrue($engine->eval(<<<'JS'
process.getBuiltinModule('node:buffer') === require('buffer')
  && process.getBuiltinModule('unavailable-module') === undefined;
JS));
        self::assertSame('ERR_INVALID_ARG_TYPE', $engine->eval(<<<'JS'
try { process.getBuiltinModule(null); } catch (error) { error.code; }
JS));
    }

    public function testNextTicksRunAroundPromiseBatchesWithAsyncContext(): void
    {
        $engine = new Engine(node: true);
        $engine->eval(<<<'JS'
const { AsyncLocalStorage } = require('node:async_hooks');
const storage = new AsyncLocalStorage();
const order = [];
storage.run('context', () => {
  Promise.resolve().then(() => {
    order.push('promise:' + storage.getStore());
    process.nextTick(() => order.push('nested'));
  });
  process.nextTick(() => {
    order.push('tick:' + storage.getStore());
    process.nextTick(() => order.push('nested-tick'));
  });
  queueMicrotask(() => order.push('micro'));
});
JS);
        self::assertSame('tick:context,nested-tick,promise:context,micro,nested', $engine->eval('order.join(",")'));
        self::assertSame('undefined', $engine->eval('typeof storage.getStore()'));
    }

    public function testAsyncLocalStorageRestoresThrowsAndDistinguishesUndefinedFromDefault(): void
    {
        $engine = new Engine(node: true);
        $engine->eval(<<<'JS'
const { AsyncLocalStorage } = require('async_hooks');
const storage = new AsyncLocalStorage({ defaultValue: 'default', name: 'request' });
const result = [];
result.push(storage.getStore());
storage.run('outer', () => {
  result.push(storage.run(undefined, () => typeof storage.getStore()));
  try { storage.run('inner', () => { throw new Error('stop'); }); } catch {}
  result.push(storage.getStore());
});
result.push(storage.getStore(), storage.name);
JS);
        self::assertSame('default,undefined,outer,default,request', $engine->eval('result.join(",")'));
    }

    public function testWorkingDirectoryAndEnvironmentAreOwnedByTheRealm(): void
    {
        $cwd = getcwd();
        $engine = new Engine();
        $engine->enableNode(new NodeOptions(cwd: __DIR__, env: ['ONLY_THIS' => 'yes']));
        self::assertSame(__DIR__, $engine->eval('process.cwd()'));
        self::assertSame('yes', $engine->eval('process.env.ONLY_THIS'));
        $engine->eval('process.chdir(".."); process.env.ONLY_THIS = "changed";');
        self::assertSame(dirname(__DIR__), $engine->eval('process.cwd()'));
        self::assertSame($cwd, getcwd());
        $engine->reset();
        self::assertSame(__DIR__, $engine->eval('process.cwd()'));
        self::assertSame('yes', $engine->eval('process.env.ONLY_THIS'));
    }

    public function testCloseReleasesEveryResourceEvenIfOneCleanupFails(): void
    {
        $engine = new Engine(node: true);
        $runtime = $engine->getNodeRuntime();
        self::assertNotNull($runtime);
        $closed = [];
        $runtime->onClose(static function () use (&$closed): void {
            $closed[] = 'first';
        });
        $runtime->onClose(static function () use (&$closed): void {
            $closed[] = 'second';
            throw new \RuntimeException('cleanup failure');
        });
        try {
            $engine->close();
            self::fail('The cleanup exception must reach the host.');
        } catch (\RuntimeException $error) {
            self::assertSame('cleanup failure', $error->getMessage());
        }
        $engine->close();
        self::assertSame(['second', 'first'], $closed);
    }
}
