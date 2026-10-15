<?php

declare(strict_types=1);

namespace Phasis\Tests\Jsx;

use Phasis\Engine;
use Phasis\BuiltIn\Jsx\React\ReactModule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * React three-way check: real React 19.3 (the vendored edge build in
 * tests/Jsx/vendor) running inside Phasis renders every React fixture
 * byte-equal with Node, and so with the native profile. This is also the
 * exact-React fallback: the same import map an application would use.
 *
 * Error messages are compared as errors only: the edge build's
 * renderToString is React's browser legacy build, whose production errors
 * are minified ("Minified React error #399"), while Node's are not.
 */
final class JsxReactThreeWayTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function fixtureProvider(): iterable
    {
        foreach (glob(__DIR__ . '/react/*.jsx') ?: [] as $file) {
            yield basename($file, '.jsx') => [$file];
        }
    }

    #[DataProvider('fixtureProvider')]
    public function testRealReactInPhasisMatchesNode(string $file): void
    {
        $expected = json_decode((string) file_get_contents(substr($file, 0, -4) . '.expected.txt'), true);
        $engine = new Engine();
        $dir = __DIR__ . '/vendor/react-' . ReactModule::VERSION . '/';
        $engine->setImportMap([
            'react' => $dir . 'react.mjs',
            'react/jsx-runtime' => $dir . 'jsx-runtime.mjs',
            'react-dom' => $dir . 'react-dom.mjs',
            'react-dom/server' => $dir . 'server.mjs',
            'react-dom/static' => $dir . 'static.mjs',
        ]);
        $actual = json_decode(ReactFixture::render($engine, $file), true);
        foreach (['string', 'static', 'prerender'] as $api) {
            if (str_starts_with($expected[$api], 'ERROR: ')) {
                $this->assertStringStartsWith('ERROR: ', $actual[$api], $api);
                continue;
            }
            $this->assertSame($expected[$api], $actual[$api], $api);
        }
    }
}
