<?php

declare(strict_types=1);

namespace Phasis\Tests\Jsx;

use Phasis\Engine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Package smoke tests: popular npm packages, loaded unbundled from the
 * pinned toolchain in tests/Jsx/oracle/node_modules, must behave as in
 * Node. Needs `npm ci` in tests/Jsx/oracle; regenerate expectations with
 * `node tests/Jsx/oracle/jsx-oracle.mjs smoke`.
 */
final class JsxPackageSmokeTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function fixtureProvider(): iterable
    {
        foreach (glob(__DIR__ . '/oracle/smoke/*.mjs') ?: [] as $file) {
            yield basename($file, '.mjs') => [$file];
        }
    }

    #[DataProvider('fixtureProvider')]
    public function testMatchesNode(string $file): void
    {
        if (!is_dir(__DIR__ . '/oracle/node_modules')) {
            $this->markTestSkipped('Run `npm ci` in tests/Jsx/oracle to install the pinned packages.');
        }
        $expected = (string) file_get_contents(substr($file, 0, -4) . '.expected.txt');
        $engine = new Engine();
        $engine->execFile($file);
        $this->assertSame(rtrim($expected, "\n"), rtrim($engine->getConsoleOutput(), "\n"));
    }

    /**
     * Barrel pruning: importing one lucide-react icon loads exactly that
     * icon's static import closure, never the 1,883-icon barrel entry.
     *
     * The PRD aimed for fewer than 10 modules. In the pinned lucide-react
     * 1.56.0 the closure of one icon is 13 modules (the icon plus the shared
     * createLucideIcon helpers, as esbuild's metafile for
     * icons/message-circle.mjs shows), so 13 is the minimum any ES module
     * loader can reach. The test pins that minimum.
     */
    public function testOneLucideIconLoadsOnlyItsImportClosure(): void
    {
        if (!is_dir(__DIR__ . '/oracle/node_modules')) {
            $this->markTestSkipped('Run `npm ci` in tests/Jsx/oracle to install the pinned packages.');
        }
        $engine = new Engine();
        $engine->evalAsModule(
            "import { MessageCircle } from 'lucide-react';\nglobalThis.icon = typeof MessageCircle;",
            __DIR__ . '/oracle/smoke/__one_icon.mjs',
        );
        $prefix = realpath(__DIR__ . '/oracle/node_modules/lucide-react') . '/dist/esm/';
        $loaded = [];
        foreach ($engine->getInterpreter()->getModuleLoader()->loadedModules() as $key) {
            if (str_starts_with($key, $prefix)) {
                $loaded[] = substr($key, strlen($prefix));
            }
        }
        sort($loaded);
        $closure = [
            'Icon.mjs',
            'context.mjs',
            'createLucideIcon.mjs',
            'icons/message-circle.mjs',
            'shared/src/build/buildLucideIconForReact.mjs',
            'shared/src/build/buildLucideIconNode.mjs',
            'shared/src/build/defaultAttributes.mjs',
            'shared/src/utils/hasA11yProp.mjs',
            'shared/src/utils/mergeClasses.mjs',
            'shared/src/utils/toCamelCase.mjs',
            'shared/src/utils/toKebabCase.mjs',
            'shared/src/utils/toLucideIconData.mjs',
            'shared/src/utils/toPascalCase.mjs',
        ];
        $this->assertSame($closure, $loaded);
        $this->assertSame('object', $engine->eval('globalThis.icon'));
    }
}
