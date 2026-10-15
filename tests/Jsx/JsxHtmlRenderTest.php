<?php

declare(strict_types=1);

namespace Phasis\Tests\Jsx;

use Phasis\Engine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * HTML render oracle: every fixture in tests/Jsx/html renders through the
 * HTML profile byte-identical to preact-render-to-string 6.8.1 in Node
 * (renderToString and renderToStringAsync, via the shared _driver.mjs).
 *
 * Regenerate expectations with `node tests/Jsx/oracle/jsx-oracle.mjs html`.
 */
final class JsxHtmlRenderTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function fixtureProvider(): iterable
    {
        foreach (glob(__DIR__ . '/html/*.jsx') ?: [] as $file) {
            yield basename($file, '.jsx') => [$file];
        }
    }

    #[DataProvider('fixtureProvider')]
    public function testMatchesPreactRenderToString(string $file): void
    {
        $expected = (string) file_get_contents(substr($file, 0, -4) . '.expected.txt');
        $this->assertSame(rtrim($expected, "\n"), HtmlFixture::render(new Engine(), $file));
    }
}
