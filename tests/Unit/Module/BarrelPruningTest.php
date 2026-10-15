<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Module;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class BarrelPruningTest extends TestCase
{
    use TempProject;

    /** @param array<string, string> $extra */
    private function barrel(bool $sideEffects, array $extra = []): string
    {
        return $this->project($extra + [
            'main.mjs' => "import { Alpha } from 'icons';\nconsole.log(Alpha);",
            'node_modules/icons/package.json' => json_encode(['name' => 'icons', 'module' => 'index.js', 'sideEffects' => $sideEffects]),
            'node_modules/icons/index.js' => "export { default as Alpha } from './alpha.js';\nexport * from './rest.js';",
            'node_modules/icons/alpha.js' => "export default 'alpha';",
            'node_modules/icons/rest.js' => "export const Beta = 'beta';\nexport const Gamma = 'gamma';",
        ]);
    }

    /** @return list<string> */
    private function loadedPackageFiles(Engine $engine, string $root): array
    {
        $prefix = $root . '/node_modules/icons/';
        $files = [];
        foreach ($engine->getInterpreter()->getModuleLoader()->loadedModules() as $key) {
            if (str_starts_with($key, $prefix)) {
                $files[] = substr($key, strlen($prefix));
            }
        }
        sort($files);
        return $files;
    }

    public function testSideEffectFreeBarrelLoadsOnlyTheUsedModule(): void
    {
        $root = $this->barrel(false);
        $engine = new Engine();
        $engine->execFile($root . '/main.mjs');
        $this->assertSame('alpha', $engine->getConsoleOutput());
        $this->assertSame(['alpha.js'], $this->loadedPackageFiles($engine, $root));
    }

    public function testExportStarIsFollowed(): void
    {
        $root = $this->barrel(false, ['main.mjs' => "import { Gamma } from 'icons';\nconsole.log(Gamma);"]);
        $engine = new Engine();
        $engine->execFile($root . '/main.mjs');
        $this->assertSame('gamma', $engine->getConsoleOutput());
        $this->assertSame(['rest.js'], $this->loadedPackageFiles($engine, $root));
    }

    public function testBarrelWithSideEffectsLoadsEverything(): void
    {
        $root = $this->barrel(true);
        $engine = new Engine();
        $engine->execFile($root . '/main.mjs');
        $this->assertSame(['alpha.js', 'index.js', 'rest.js'], $this->loadedPackageFiles($engine, $root));
    }
}
