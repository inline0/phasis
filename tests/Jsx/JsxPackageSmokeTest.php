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
}
