<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Module;

use Phasis\Engine;
use Phasis\Exceptions\JsThrowable;
use Phasis\Module\ModuleOptions;
use PHPUnit\Framework\TestCase;

/**
 * With ModuleOptions::$deferSideEffectFreeModules, modules that their
 * package declares free of side effects load when one of their imported
 * bindings is first read, not before the importer runs.
 */
final class DeferredLoadingTest extends TestCase
{
    use TempProject;

    /** @param array<string, string> $extra */
    private function package(array $extra = [], mixed $sideEffects = false): string
    {
        return $this->project($extra + [
            'node_modules/kit/package.json' => json_encode(['name' => 'kit', 'module' => 'index.mjs', 'sideEffects' => $sideEffects]),
            'node_modules/kit/index.mjs' => "import { helper } from './helper.mjs';\nimport { unused } from './unused.mjs';\n"
                . "export function useHelper() { return helper(); }\nexport function useUnused() { return unused(); }\n",
            'node_modules/kit/helper.mjs' => "import { format } from './format.mjs';\nexport const helper = () => format('helper');\n",
            'node_modules/kit/format.mjs' => "export const format = (s) => '[' + s + ']';\n",
            'node_modules/kit/unused.mjs' => "export const unused = () => 'unused';\n",
        ]);
    }

    private function engine(): Engine
    {
        $engine = new Engine();
        $engine->setModuleOptions(new ModuleOptions(deferSideEffectFreeModules: true));
        return $engine;
    }

    /** @return list<string> */
    private function loaded(Engine $engine, string $root): array
    {
        $prefix = $root . '/node_modules/kit/';
        $files = [];
        foreach ($engine->getInterpreter()->getModuleLoader()->loadedModules() as $key) {
            if (str_starts_with($key, $prefix)) {
                $files[] = substr($key, strlen($prefix));
            }
        }
        sort($files);
        return $files;
    }

    public function testModulesLoadWhenABindingIsFirstRead(): void
    {
        $root = $this->package(['main.mjs' => "import { useHelper } from 'kit';\nglobalThis.out = useHelper();"]);
        $engine = $this->engine();
        $engine->execFile($root . '/main.mjs');
        $this->assertSame('[helper]', $engine->eval('globalThis.out'));
        $this->assertSame(['format.mjs', 'helper.mjs', 'index.mjs'], $this->loaded($engine, $root));
    }

    public function testNothingLoadsUntilUsed(): void
    {
        $root = $this->package(['main.mjs' => "import { useHelper } from 'kit';\nglobalThis.later = () => useHelper();"]);
        $engine = $this->engine();
        $engine->execFile($root . '/main.mjs');
        $this->assertSame([], $this->loaded($engine, $root));
        $this->assertSame('[helper]', $engine->eval('globalThis.later()'));
        $this->assertSame(['format.mjs', 'helper.mjs', 'index.mjs'], $this->loaded($engine, $root));
    }

    public function testPackageWithSideEffectsLoadsEagerly(): void
    {
        $root = $this->package(['main.mjs' => "import { useHelper } from 'kit';\nglobalThis.later = () => useHelper();"], true);
        $engine = $this->engine();
        $engine->execFile($root . '/main.mjs');
        $this->assertSame(['format.mjs', 'helper.mjs', 'index.mjs', 'unused.mjs'], $this->loaded($engine, $root));
    }

    public function testSideEffectListKeepsListedFilesEager(): void
    {
        $root = $this->package(['main.mjs' => "import { useHelper } from 'kit';\nglobalThis.later = () => useHelper();"], ['./unused.mjs']);
        $engine = $this->engine();
        $engine->execFile($root . '/main.mjs');
        // index.mjs is side-effect free, so it waits; once it runs, unused.mjs loads with it.
        $this->assertSame([], $this->loaded($engine, $root));
        $engine->eval('globalThis.later()');
        $this->assertSame(['format.mjs', 'helper.mjs', 'index.mjs', 'unused.mjs'], $this->loaded($engine, $root));
    }

    public function testDeferralIsOffByDefault(): void
    {
        $root = $this->package(['main.mjs' => "import { useHelper } from 'kit';\nglobalThis.later = () => useHelper();"]);
        $engine = new Engine();
        $engine->execFile($root . '/main.mjs');
        $this->assertSame(['format.mjs', 'helper.mjs', 'index.mjs', 'unused.mjs'], $this->loaded($engine, $root));
    }

    public function testNamespaceImportsLoadEagerly(): void
    {
        $root = $this->package(['main.mjs' => "import * as kit from 'kit';\nglobalThis.later = () => kit.useHelper();"]);
        $engine = $this->engine();
        $engine->execFile($root . '/main.mjs');
        $this->assertContains('index.mjs', $this->loaded($engine, $root));
    }

    public function testTopLevelAwaitLoadsEagerly(): void
    {
        $root = $this->package([
            'main.mjs' => "import { ready } from 'kit/ready.mjs';\nglobalThis.out = ready;",
            'node_modules/kit/ready.mjs' => "export const ready = await Promise.resolve('ready');\n",
        ]);
        $engine = $this->engine();
        $engine->execFile($root . '/main.mjs');
        $this->assertSame('ready', $engine->eval('globalThis.out'));
    }

    public function testMissingModuleStillFailsAtLinkTime(): void
    {
        $root = $this->package(['main.mjs' => "import { nope } from 'kit/missing.mjs';\nglobalThis.later = () => nope;"]);
        $engine = $this->engine();
        $this->expectExceptionMessage("Cannot find module 'kit/missing.mjs'");
        $engine->execFile($root . '/main.mjs');
    }

    public function testMissingExportThrowsOnFirstRead(): void
    {
        $root = $this->package(['main.mjs' => "import { nope } from 'kit';\nglobalThis.later = () => nope;"]);
        $engine = $this->engine();
        $engine->execFile($root . '/main.mjs');
        try {
            $engine->eval('globalThis.later()');
            $this->fail('Reading a missing export should throw');
        } catch (\Phasis\Exceptions\SyntaxError $error) {
            $this->assertStringContainsString("does not export 'nope'", $error->getMessage());
        }
    }

    public function testEvaluationErrorsSurfaceOnFirstReadAndStay(): void
    {
        $root = $this->package([
            'main.mjs' => "import { boom } from 'kit/boom.mjs';\nglobalThis.read = () => { try { return boom; } catch (e) { return e.message; } };",
            'node_modules/kit/boom.mjs' => "throw new Error('kaboom');\nexport const boom = 1;\n",
        ]);
        $engine = $this->engine();
        $engine->execFile($root . '/main.mjs');
        $this->assertSame('kaboom', $engine->eval('globalThis.read()'));
        $this->assertSame('kaboom', $engine->eval('globalThis.read()'));
    }

    public function testLiveBindingsStayLive(): void
    {
        $root = $this->package([
            'main.mjs' => "import { count, bump } from 'kit/counter.mjs';\nglobalThis.read = () => { bump(); bump(); return count; };",
            'node_modules/kit/counter.mjs' => "export let count = 0;\nexport function bump() { count++; }\n",
        ]);
        $engine = $this->engine();
        $engine->execFile($root . '/main.mjs');
        $this->assertSame(2, $engine->eval('globalThis.read()'));
    }

    public function testCyclesBackToAnEagerModuleWork(): void
    {
        $root = $this->package([
            'main.mjs' => "import { a } from 'kit/a.mjs';\nglobalThis.out = a();",
            'node_modules/kit/a.mjs' => "import { b } from './b.mjs';\nexport const name = 'a';\nexport function a() { return b(); }\n",
            'node_modules/kit/b.mjs' => "import { name } from './a.mjs';\nexport function b() { return 'b sees ' + name; }\n",
        ]);
        $engine = $this->engine();
        $engine->execFile($root . '/main.mjs');
        $this->assertSame('b sees a', $engine->eval('globalThis.out'));
    }

    public function testApplicationModulesAreNeverDeferred(): void
    {
        $root = $this->project([
            'main.mjs' => "import { x } from './local.mjs';\nglobalThis.later = () => x;",
            'local.mjs' => "globalThis.ranLocal = true;\nexport const x = 1;\n",
            'package.json' => json_encode(['sideEffects' => false]),
        ]);
        $engine = $this->engine();
        $engine->execFile($root . '/main.mjs');
        $this->assertTrue($engine->eval('globalThis.ranLocal'));
    }

    public function testErrorsAreJsThrowables(): void
    {
        $root = $this->package([
            'main.mjs' => "import { boom } from 'kit/boom.mjs';\nglobalThis.read = () => boom;",
            'node_modules/kit/boom.mjs' => "throw new TypeError('bad');\nexport const boom = 1;\n",
        ]);
        $engine = $this->engine();
        $engine->execFile($root . '/main.mjs');
        $this->expectException(JsThrowable::class);
        $engine->eval('globalThis.read()');
    }
}
