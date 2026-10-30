<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Module;

use Phasis\Engine;
use Phasis\HtmlRenderOptions;
use Phasis\Module\ModuleOptions;
use Phasis\Parser\Jsx\JsxMode;
use Phasis\Parser\Jsx\JsxOptions;
use Phasis\Parser\Parser;
use Phasis\RenderProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParseCacheTest extends TestCase
{
    use TempProject;

    /** @return list<string> */
    private static function entries(string $dir): array
    {
        $files = glob($dir . '/*/*.ast') ?: [];
        sort($files);
        return $files;
    }

    public function testParsedModulesAreReusedAndInvalidatedBySource(): void
    {
        $root = $this->project([
            'App.jsx' => "import { label } from './label.js';\nexport default () => <p>{label}</p>;",
            'label.js' => "export const label = 'one';",
        ]);
        $cache = $root . '/.cache';
        $render = static function () use ($root, $cache): string {
            $engine = new Engine();
            $engine->setModuleOptions(new ModuleOptions(parseCacheDir: $cache));
            return $engine->render($root . '/App.jsx');
        };
        $this->assertSame('<p>one</p>', $render());
        $first = self::entries($cache);
        $this->assertCount(2, $first);
        $this->assertSame('<p>one</p>', $render());
        $this->assertSame($first, self::entries($cache));

        file_put_contents($root . '/label.js', "export const label = 'two';");
        $this->assertSame('<p>two</p>', $render());
        $this->assertCount(3, self::entries($cache));
    }

    public function testLegacyPrecompiledTemplatesAreReparsedAndCached(): void
    {
        $source = 'export default () => <svg><a><a strokeWidth="">x</a></a></svg>;';
        $root = $this->project(['App.jsx' => $source]);
        $cache = $root . '/.cache';
        $jsx = new JsxOptions(mode: JsxMode::Precompile, importSource: 'phasis');
        $options = ['env' => 'production', 'jsx' => $jsx, 'typescript' => false, 'tsx' => false];
        $key = sha1("phasis-ast-2\0module\0" . serialize($options) . "\0" . sha1($source));
        $directory = $cache . '/' . substr($key, 0, 2);
        mkdir($directory, 0775, true);
        $legacySource = <<<'JS'
import { jsxTemplate } from 'phasis/jsx-precompile';
import { jsx } from 'phasis/jsx-runtime';
const tpl = ['<a strokeWidth>x</a>', {p:'html', f:[0], s:[], a:[]}];
export default () => jsx('svg', {children:jsx('a', {children:jsxTemplate(tpl)})});
JS;
        $legacy = ['program' => Parser::parseSource($legacySource, 'module'), 'jsxImportSource' => 'phasis'];
        file_put_contents($directory . '/' . $key . '.ast', serialize($legacy));

        $render = static function () use ($root, $cache, $jsx): string {
            $engine = new Engine();
            $engine->setModuleOptions(new ModuleOptions(parseCacheDir: $cache));
            $engine->setJsxOptions($jsx);
            try {
                return $engine->render($root . '/App.jsx', [], 'default', RenderProfile::Html, new HtmlRenderOptions(['stroke-width']));
            } finally {
                $engine->close();
            }
        };
        $this->assertSame('<svg><a><a stroke-width="">x</a></a></svg>', $render());
        $entries = self::entries($cache);
        $this->assertCount(2, $entries);
        $this->assertSame('<svg><a><a stroke-width="">x</a></a></svg>', $render());
        $this->assertSame($entries, self::entries($cache));
    }

    public function testPrecompiledCacheRetainsOptionIndependentStaticParts(): void
    {
        $root = $this->project(['App.jsx' => 'export default () => <main><p title="&gt;">"text"</p></main>;']);
        $cache = $root . '/.cache';
        $jsx = new JsxOptions(mode: JsxMode::Precompile, importSource: 'phasis');
        foreach ([false, true, false, true] as $enabled) {
            $engine = new Engine();
            $engine->setModuleOptions(new ModuleOptions(parseCacheDir: $cache));
            $engine->setJsxOptions($jsx);
            $options = new HtmlRenderOptions(literalTextQuotes: $enabled, escapeAttributeGreaterThan: $enabled);
            $expected = $enabled ? '<main><p title="&gt;">"text"</p></main>' : '<main><p title=">">&quot;text&quot;</p></main>';
            $this->assertSame($expected, $engine->render($root . '/App.jsx', [], 'default', RenderProfile::Html, $options));
            $engine->close();
        }
        $this->assertCount(1, self::entries($cache));
    }

    public function testTypeScriptCacheDoesNotBypassJavaScriptSyntaxValidation(): void
    {
        $source = 'export const answer: number = 42;';
        $root = $this->project(['entry.ts' => $source, 'entry.js' => $source]);
        $options = new ModuleOptions(parseCacheDir: $root . '/.cache');
        foreach ([1, 2] as $attempt) {
            $engine = new Engine();
            $engine->setModuleOptions($options);
            $this->assertSame(42, $engine->getExport($root . '/entry.ts', 'answer'));
            $engine->close();
        }
        $engine = new Engine();
        $engine->setModuleOptions($options);
        $this->expectException(\Phasis\Exceptions\SyntaxError::class);
        try {
            $engine->getExport($root . '/entry.js', 'answer');
        } finally {
            $engine->close();
        }
    }

    /** @return iterable<string, array{bool, string}> */
    public static function typedCacheGoals(): iterable
    {
        yield 'module' => [false, 'export const id = <T>(value: T) => value;'];
        yield 'commonjs' => [true, 'module.exports = <T>(value: T) => value;'];
    }

    #[DataProvider('typedCacheGoals')]
    public function testTypeScriptCacheDoesNotBypassTsxGrammar(bool $commonjs, string $source): void
    {
        $root = $this->project([
            'entry.ts' => $source,
            'entry.tsx' => $source,
            'package.json' => '{"type":"commonjs"}',
        ]);
        foreach (['ts', 'tsx'] as $extension) {
            $engine = new Engine(node: $commonjs);
            $engine->setModuleOptions(new ModuleOptions(nodeCompatible: $commonjs, parseCacheDir: $root . '/.cache'));
            $loader = $engine->getInterpreter()->getModuleLoader();
            // Direct loader users may rely on the filename's implicit JSX mode.
            $loader->setJsxOptions(null);
            if ($extension === 'tsx') {
                $this->expectException(\Phasis\Exceptions\SyntaxError::class);
            }
            try {
                $loader->loadModule($root . '/entry.' . $extension);
            } finally {
                $engine->close();
            }
        }
    }
}
