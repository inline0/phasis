<?php

declare(strict_types=1);

namespace Phasis\Tests\Jsx;

use Phasis\Engine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Module resolution oracle: each fixture app runs unbundled in Phasis and
 * must print exactly what the same app prints when esbuild bundles it
 * (configured like Phasis's defaults) and Node runs the bundle.
 * Regenerate with `node tests/Jsx/oracle/jsx-oracle.ts resolution`.
 */
final class JsxResolutionOracleTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function fixtureProvider(): iterable
    {
        foreach (glob(__DIR__ . '/resolution/*', GLOB_ONLYDIR) ?: [] as $dir) {
            yield basename($dir) => [$dir];
        }
    }

    #[DataProvider('fixtureProvider')]
    public function testMatchesBundledNode(string $dir): void
    {
        $expected = (string) file_get_contents($dir . '.expected.txt');
        $engine = new Engine();
        $engine->setAssetResolver(static fn (string $file): string => '/assets/' . basename($file));
        $engine->execFile($dir . '/main.mjs');
        $this->assertSame(rtrim($expected, "\n"), rtrim($engine->getConsoleOutput(), "\n"));
    }
}
