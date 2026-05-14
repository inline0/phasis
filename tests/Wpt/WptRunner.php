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

        // Synchronous microtask drain — the WPT shim uses this to flush
        // Promise continuations between a fixture's `.then(cb)` and a
        // subsequent assertion that reads the result. Phasis Promises
        // resolve synchronously but their `.then` continuations are
        // microtasks, so the shim needs an explicit drain.
        $engine->setGlobal('__phasisDrainMicrotasks', function (): void {
            \Phasis\Value\JsPromise::drainMicrotasks();
        });

        // Base URL the Request constructor uses to resolve relative URLs
        // in fixtures that hardcode paths like `../resources/foo.py`.
        // The fixtures live under `fetch/api/headers/`, so a relative
        // `../resources/...` resolves into `fetch/api/resources/...`.
        // Our PHP server, by contrast, routes `/resources/<name>`. To
        // bridge the two we set the base such that the `..` jump lands
        // directly on `/resources/`.
        $engine->setGlobal(
            '__phasisRequestBaseUrl',
            'http://127.0.0.1:8765/headers/'
        );

        // Synchronous bytes accessor for Blob/File — works around the
        // headless runner not being able to await Blob.text(). Returns
        // a fresh Uint8Array over the Blob's raw bytes (so callers can
        // round-trip binary cleanly — JsString would lose 0x80+ bytes
        // through UTF-8 normalization).
        $engine->setGlobalJsValue(
            '__phasisBlobBytes',
            \Phasis\Value\JsFunction::fromCallable(
                '__phasisBlobBytes',
                static function (\Phasis\Value\JsValue $this_, array $args): \Phasis\Value\JsValue {
                    $blob = $args[0] ?? null;
                    if (
                        $blob instanceof \Phasis\Value\JsObject
                        && $blob->getInternalProperty('[[IsBlob]]') === true
                    ) {
                        $bytes = $blob->getInternalProperty('[[BlobBytes]]');
                        return \Phasis\BuiltIn\TextEncoderConstructor::makeUint8Array(
                            is_string($bytes) ? $bytes : ''
                        );
                    }
                    return \Phasis\Value\JsNull::instance();
                },
                1,
            )
        );

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
