<?php

declare(strict_types=1);

namespace Phasis\Tests\Jsx;

use Phasis\Engine;
use Phasis\Exceptions\SyntaxError;
use Phasis\Parser\Jsx\JsxOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Lowering oracle: each fixture runs against a recording JSX runtime that
 * prints every jsx/jsxs/createElement call. Phasis loads the unbundled
 * .jsx through its own module resolution; the expected trace comes from
 * esbuild + Node. Regenerate with `node tests/Jsx/oracle/jsx-oracle.ts lower`.
 */
final class JsxLowerOracleTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function fixtureProvider(): iterable
    {
        foreach (glob(__DIR__ . '/lower/*.jsx') ?: [] as $file) {
            yield basename($file, '.jsx') => [$file];
        }
    }

    #[DataProvider('fixtureProvider')]
    public function testMatchesEsbuild(string $file): void
    {
        $expected = (string) file_get_contents(substr($file, 0, -4) . '.expected.txt');
        $this->assertSame(rtrim($expected, "\n"), rtrim(self::trace($file), "\n"));
    }

    public static function trace(string $file): string
    {
        $recorder = __DIR__ . '/oracle/recorder';
        $engine = new Engine();
        $engine->setImportMap([
            'recorder' => $recorder . '/index.js',
            'recorder/jsx-runtime' => $recorder . '/jsx-runtime.js',
        ]);
        $engine->setJsxOptions(new JsxOptions(importSource: 'recorder'));
        try {
            $engine->execFile($file);
        } catch (SyntaxError $e) {
            return 'SyntaxError: ' . preg_replace('/ at line \d+, column \d+$/', '', $e->getMessage());
        }
        return $engine->getConsoleOutput();
    }
}
