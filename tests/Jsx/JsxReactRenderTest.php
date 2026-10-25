<?php

declare(strict_types=1);

namespace Phasis\Tests\Jsx;

use Phasis\Engine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * React render oracle: every fixture in tests/Jsx/react renders through
 * the native React 19.3 profile byte-identical to react-dom@19.3.1 in Node
 * (renderToString, renderToStaticMarkup and prerender, via the shared
 * _driver.mjs). Engine::render() must return the prerender output.
 *
 * Regenerate expectations with `node tests/Jsx/oracle/jsx-oracle.mjs react`.
 */
final class JsxReactRenderTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function fixtureProvider(): iterable
    {
        foreach (glob(__DIR__ . '/react/*.{jsx,tsx}', GLOB_BRACE) ?: [] as $file) {
            yield pathinfo($file, PATHINFO_FILENAME) => [$file];
        }
    }

    #[DataProvider('fixtureProvider')]
    public function testMatchesReactDom(string $file): void
    {
        $expected = (string) file_get_contents(substr($file, 0, -4) . '.expected.txt');
        $this->assertSame(rtrim($expected, "\n"), ReactFixture::render(new Engine(), $file));
    }

    #[DataProvider('fixtureProvider')]
    public function testEngineRenderMatchesPrerender(string $file): void
    {
        $source = (string) file_get_contents($file);
        if (str_contains($source, 'export const options')) {
            $this->markTestSkipped('Render options (identifierPrefix) are not Engine::render() parameters.');
        }
        $expected = json_decode((string) file_get_contents(substr($file, 0, -4) . '.expected.txt'), true);
        $props = [];
        if (preg_match('/export const props(?:\s*:\s*\w+)? = (\{.*\});/', $source, $m) === 1) {
            $props = json_decode((string) preg_replace('/(\w+):/', '"$1":', str_replace("'", '"', $m[1])), true) ?? [];
        }
        $engine = new Engine();
        if (str_starts_with($expected['prerender'], 'ERROR: ')) {
            try {
                $engine->render($file, $props);
                $this->fail('Engine::render() should have thrown');
            } catch (\Phasis\Exceptions\RuntimeError $e) {
                $this->assertStringContainsString(substr($expected['prerender'], 7), $e->getMessage());
            }
            return;
        }
        $this->assertSame($expected['prerender'], $engine->render($file, $props));
    }
}
