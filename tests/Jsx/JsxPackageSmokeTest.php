<?php

declare(strict_types=1);

namespace Phasis\Tests\Jsx;

use Phasis\Engine;
use Phasis\Module\ModuleOptions;
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
    /**
     * Every package, with modules loading in bundle order and with
     * deferred loading of side-effect-free modules.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function fixtureProvider(): iterable
    {
        foreach (glob(__DIR__ . '/oracle/smoke/*.mjs') ?: [] as $file) {
            yield basename($file, '.mjs') => [$file, false];
            yield basename($file, '.mjs') . ' (deferred loading)' => [$file, true];
        }
    }

    #[DataProvider('fixtureProvider')]
    public function testMatchesNode(string $file, bool $deferred): void
    {
        if (!is_dir(__DIR__ . '/oracle/node_modules')) {
            $this->markTestSkipped('Run `npm ci` in tests/Jsx/oracle to install the pinned packages.');
        }
        $expected = (string) file_get_contents(substr($file, 0, -4) . '.expected.txt');
        $engine = new Engine();
        $engine->setModuleOptions(new ModuleOptions(deferSideEffectFreeModules: $deferred));
        $engine->execFile($file);
        $this->assertSame(rtrim($expected, "\n"), rtrim($engine->getConsoleOutput(), "\n"));
    }

    /**
     * Barrel pruning: by default, importing one lucide-react icon loads
     * exactly the icon's static import closure, 13 modules, never the
     * 1,883-icon barrel entry. That is what a bundler includes too, and
     * the modules run in bundle order.
     */
    public function testOneLucideIconLoadsItsImportClosure(): void
    {
        if (!is_dir(__DIR__ . '/oracle/node_modules')) {
            $this->markTestSkipped('Run `npm ci` in tests/Jsx/oracle to install the pinned packages.');
        }
        $engine = new Engine();
        $engine->evalAsModule(
            "import { MessageCircle } from 'lucide-react';\nglobalThis.icon = typeof MessageCircle;",
            __DIR__ . '/oracle/smoke/__one_icon.mjs',
        );
        $this->assertSame($this->sorted([...self::RENDERED_MODULES, ...self::NEVER_CALLED_MODULES]), $this->lucideModules($engine));
        $this->assertSame('object', $engine->eval('globalThis.icon'));
    }

    /**
     * PRD acceptance criterion 4: with deferred loading on, importing one
     * lucide-react icon loads fewer than 10 modules. lucide declares
     * `"sideEffects": false`, so each module loads when one of its
     * bindings is first read: the import loads the icon, the
     * createLucideIcon factory it calls and the two string helpers that
     * name the component. Rendering loads the 7 more modules the render
     * runs; the 2 the icon never calls are never loaded.
     */
    public function testDeferredLoadingLoadsFewerThanTenModulesForOneIcon(): void
    {
        if (!is_dir(__DIR__ . '/oracle/node_modules')) {
            $this->markTestSkipped('Run `npm ci` in tests/Jsx/oracle to install the pinned packages.');
        }
        $engine = new Engine();
        $engine->setModuleOptions(new ModuleOptions(deferSideEffectFreeModules: true));
        $engine->evalAsModule(
            "import { MessageCircle } from 'lucide-react';\nglobalThis.icon = typeof MessageCircle;",
            __DIR__ . '/oracle/smoke/__one_icon.mjs',
        );
        $imported = $this->lucideModules($engine);
        $this->assertSame([
            'createLucideIcon.mjs',
            'icons/message-circle.mjs',
            'shared/src/utils/toCamelCase.mjs',
            'shared/src/utils/toPascalCase.mjs',
        ], $imported);
        $this->assertLessThan(10, count($imported));
        $this->assertSame('object', $engine->eval('globalThis.icon'));

        $html = $engine->renderSource(
            "import { MessageCircle } from 'lucide-react';\nexport default () => <MessageCircle size={16} />;",
            [],
            'default',
            __DIR__ . '/oracle/smoke/__one_icon_render.jsx',
        );
        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"', $html);
        $this->assertSame(self::RENDERED_MODULES, $this->lucideModules($engine));
    }

    /** The lucide-react modules rendering one icon runs. */
    private const RENDERED_MODULES = [
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
        'shared/src/utils/toPascalCase.mjs',
    ];

    /** Imported by createLucideIcon for icons built from a name, which this icon is not. */
    private const NEVER_CALLED_MODULES = [
        'shared/src/utils/toKebabCase.mjs',
        'shared/src/utils/toLucideIconData.mjs',
    ];

    /**
     * @param list<string> $modules
     * @return list<string>
     */
    private function sorted(array $modules): array
    {
        sort($modules);
        return $modules;
    }

    /** @return list<string> lucide-react modules loaded so far, relative to dist/esm. */
    private function lucideModules(Engine $engine): array
    {
        $prefix = realpath(__DIR__ . '/oracle/node_modules/lucide-react') . '/dist/esm/';
        $loaded = [];
        foreach ($engine->getInterpreter()->getModuleLoader()->loadedModules() as $key) {
            if (str_starts_with($key, $prefix)) {
                $loaded[] = substr($key, strlen($prefix));
            }
        }
        sort($loaded);
        return $loaded;
    }
}
