<?php

declare(strict_types=1);

namespace Phasis\Tests\Wpt;

use Phasis\Engine;

/**
 * Runs Web Platform Tests (WPT) `.any.js` fixtures through Phasis.
 *
 * WPT is the canonical conformance suite for everything WHATWG /
 * W3C — `url/`, `encoding/`, `html/webappapis/atob/`, `html/webappapis/
 * structured-clone/`, `hr-time/`. Each fixture under
 * `tests/Wpt/fixtures/` is a self-contained file that calls `test`,
 * `promise_test`, or `async_test` from the harness shim and uses
 * `assert_*` helpers to verify behavior.
 *
 * The shim (tests/Wpt/testharness-shim.js) is loaded once before
 * each fixture and routes each subtest's result through a host-
 * installed `__phasisWptReport(status, name, message)` callback
 * back into this runner.
 *
 * Result shape per fixture:
 *   [
 *     ['status' => 'PASS'|'FAIL', 'name' => string, 'message' => string],
 *     ...
 *   ]
 */
final class WptRunner
{
    private string $harnessSource;

    /** @var list<array{status: string, name: string, message: string}> */
    private array $results = [];

    public function __construct()
    {
        $shimPath = __DIR__ . '/testharness-shim.js';
        $source = file_get_contents($shimPath);
        if ($source === false) {
            throw new \RuntimeException("Failed to load harness shim at {$shimPath}");
        }
        $this->harnessSource = $source;
    }

    /**
     * Run one fixture, return its per-subtest results.
     *
     * @return list<array{status: string, name: string, message: string}>
     */
    public function runFile(string $path): array
    {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException("Failed to read fixture {$path}");
        }

        $this->results = [];

        $engine = new Engine();
        $engine->setGlobal('__phasisWptReport', function (
            string $status,
            string $name,
            string $message
        ): void {
            $this->results[] = [
                'status' => $status,
                'name' => $name,
                'message' => $message,
            ];
        });

        try {
            $engine->eval($this->harnessSource);
            $engine->eval($source);
        } catch (\Throwable $e) {
            // A fixture that errors before any subtest counts as a
            // single FAIL for the whole file. Spec fixtures that
            // throw at top level usually mean the harness boot or a
            // syntax-level issue.
            $this->results[] = [
                'status' => 'FAIL',
                'name' => '__file_level__',
                'message' => get_class($e) . ': ' . $e->getMessage(),
            ];
        }

        return $this->results;
    }

    /**
     * Discover every fixture under tests/Wpt/fixtures/<category>/.
     *
     * @return array<string, list<string>>  category => list of absolute paths
     */
    public static function discoverFixtures(): array
    {
        $root = __DIR__ . '/fixtures';
        $out = [];
        foreach (scandir($root) ?: [] as $category) {
            if ($category === '.' || $category === '..') {
                continue;
            }
            $dir = $root . '/' . $category;
            if (!is_dir($dir)) {
                continue;
            }
            $files = [];
            foreach (glob($dir . '/*.any.js') ?: [] as $file) {
                $files[] = $file;
            }
            sort($files);
            $out[$category] = $files;
        }
        return $out;
    }
}
