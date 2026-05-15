<?php

declare(strict_types=1);

namespace Phasis\Tests\Popular;

use Phasis\Engine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Smoke-tests for popular pure-JS packages running under Phasis.
 *
 * Each scenario lives in tests/Popular/<package>/ as a triple:
 *   <package>.js  — vendored upstream library (UMD)
 *   runner.js     — exercises the library and emits deterministic
 *                   output via console.log
 *   oracle.txt    — committed Node.js (V8) output for the same runner
 *
 * The test concatenates library + runner, executes through a fresh
 * Phasis\Engine with console.log routed into a PHP buffer, and
 * asserts the captured output is byte-equal to the committed oracle.
 *
 * A drift means either the library hit a Phasis spec gap or Node was
 * regenerated against a different library version. The oracle is the
 * source of truth — if you bump the vendored library, regenerate
 * the oracle via `node tests/Popular/gen-oracle.js tests/Popular/<pkg>`.
 */
class PopularPackagesTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function packageProvider(): iterable
    {
        $base = __DIR__;
        foreach (scandir($base) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $dir = $base . '/' . $entry;
            if (!is_dir($dir)) {
                continue;
            }
            if (!is_file($dir . '/runner.js') || !is_file($dir . '/oracle.txt')) {
                continue;
            }
            yield $entry => [$entry];
        }
    }

    #[DataProvider('packageProvider')]
    public function testRunnerMatchesOracle(string $package): void
    {
        $dir = __DIR__ . '/' . $package;

        $libraryFile = $this->findLibrary($dir);
        $this->assertNotNull(
            $libraryFile,
            "no library .js file found in tests/Popular/{$package}",
        );

        $library = (string) file_get_contents($libraryFile);
        $runner = (string) file_get_contents($dir . '/runner.js');
        $oracle = (string) file_get_contents($dir . '/oracle.txt');

        $captured = '';
        $engine = new Engine(eager: true);
        $engine->setGlobal('__phasisCapture', function (string $line) use (&$captured): void {
            $captured .= $line . "\n";
        });
        $engine->eval(<<<'JS'
        globalThis.console = {
            log: function () {
                var parts = [];
                for (var i = 0; i < arguments.length; i++) {
                    parts.push(String(arguments[i]));
                }
                __phasisCapture(parts.join(' '));
            },
            warn: function () { /* no-op */ },
            error: function () { /* no-op */ },
        };
        JS);

        $engine->eval($library . "\n;\n" . $runner);

        $this->assertSame(
            $oracle,
            $captured,
            "Phasis output for {$package} diverged from Node oracle "
            . "(tests/Popular/{$package}/oracle.txt). Regenerate via "
            . "`node tests/Popular/gen-oracle.js tests/Popular/{$package}` "
            . "if the vendored library was bumped; otherwise this is "
            . "a spec gap in Phasis.",
        );
    }

    private function findLibrary(string $dir): ?string
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if (
                !str_ends_with($entry, '.js')
                || $entry === 'runner.js'
                || str_starts_with($entry, 'oracle')
            ) {
                continue;
            }
            return $dir . '/' . $entry;
        }
        return null;
    }
}
