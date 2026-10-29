<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Module;

use Phasis\Engine;
use Phasis\Module\ModuleOptions;
use PHPUnit\Framework\TestCase;

final class PackageDirectoriesTest extends TestCase
{
    use TempProject;

    /** A package copied outside node_modules, imported through an import map as a build would map it. */
    private function vendored(): string
    {
        return $this->project([
            'main.mjs' => "import { label } from 'badge';\nimport { Alpha } from 'icons';\nconsole.log(label(), Alpha);",
            'build/packages/badge/package.json' => json_encode(['name' => 'badge']),
            'build/packages/badge/index.js' => implode("\n", [
                "'use strict';",
                "if (process.env.NODE_ENV === 'production') {",
                "  module.exports = require('./badge.production.js');",
                '} else {',
                "  module.exports = require('./badge.development.js');",
                '}',
            ]),
            'build/packages/badge/badge.production.js' => "exports.label = () => 'pro';",
            'build/packages/icons/package.json' => json_encode(['name' => 'icons', 'sideEffects' => false]),
            'build/packages/icons/index.js' => "export { default as Alpha } from './alpha.js';\nexport * from './rest.js';",
            'build/packages/icons/alpha.js' => "export default 'alpha';",
            'build/packages/icons/rest.js' => "export const Beta = 'beta';",
        ]);
    }

    private function engine(string $root, bool $packages): Engine
    {
        $engine = new Engine();
        $engine->setModuleOptions(new ModuleOptions(packageDirectories: $packages ? [$root . '/build/packages'] : []));
        $engine->setImportMap([
            'badge' => $root . '/build/packages/badge/index.js',
            'icons' => $root . '/build/packages/icons/index.js',
        ]);
        return $engine;
    }

    public function testCopiedPackagesLoadAsInstalledPackages(): void
    {
        $root = $this->vendored();
        $engine = $this->engine($root, true);
        $engine->execFile($root . '/main.mjs');
        $this->assertSame('pro alpha', $engine->getConsoleOutput());
        $loaded = $engine->getInterpreter()->getModuleLoader()->loadedModules();
        $this->assertNotContains($root . '/build/packages/icons/rest.js', $loaded, 'The side-effect-free barrel is pruned.');
    }

    public function testOutsidePackageDirectoriesAJsFileIsApplicationCode(): void
    {
        $root = $this->vendored();
        $engine = $this->engine($root, false);
        $this->expectExceptionMessage("does not export 'label'");
        $engine->execFile($root . '/main.mjs');
    }

    public function testNodeModulesStayPackageCode(): void
    {
        $options = new ModuleOptions(packageDirectories: ['/srv/site/build/packages/']);
        $this->assertTrue($options->isPackageFile('/srv/site/node_modules/badge/index.js'));
        $this->assertTrue($options->isPackageFile('/srv/site/build/packages/badge/index.js'));
        $this->assertFalse($options->isPackageFile('/srv/site/build/packages-other/badge/index.js'));
        $this->assertFalse($options->isPackageFile('/srv/site/src/app.js'));
        $this->assertSame(['/srv/site/build/packages/'], $options->forNode()->packageDirectories);
    }
}
