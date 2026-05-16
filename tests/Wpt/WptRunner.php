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

        $engine = new Engine(eager: true);
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
            // Parse `// META: script=<rel-path>` directives and load
            // each one before the fixture body. Paths are relative to
            // the fixture file. WPT uses these to share helpers
            // (e.g. WebCryptoAPI/util/helpers.js).
            foreach (self::parseMetaScripts($source) as $rel) {
                $scriptPath = self::resolveMetaScriptPath($path, $rel);
                if ($scriptPath === null) {
                    continue;
                }
                $scriptSource = @file_get_contents($scriptPath);
                if ($scriptSource !== false) {
                    $engine->eval($scriptSource);
                }
            }
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
    /**
     * Extract every `// META: script=<path>` directive from a WPT
     * fixture's source. WPT uses these as the equivalent of script
     * tags — the harness loads each referenced file before the
     * fixture body so its helpers (`equalBuffers`, `copyBuffer`,
     * etc.) are in scope.
     *
     * @return list<string>
     */
    private static function parseMetaScripts(string $source): array
    {
        $scripts = [];
        if (preg_match_all('~^// META: script=(\S+)~m', $source, $matches)) {
            foreach ($matches[1] as $path) {
                $scripts[] = $path;
            }
        }
        return $scripts;
    }

    /**
     * Resolve a `// META: script=` path against a fixture. Two
     * lookup strategies, in order:
     *   1. Relative to the fixture (matches WPT's own layout when
     *      the helper lives alongside the test).
     *   2. Relative to the upstream `tests/Wpt/upstream/` tree at
     *      a parallel location — fixtures imported into
     *      `tests/Wpt/fixtures/<category>/` reference helpers that
     *      stayed in `tests/Wpt/upstream/<area>/util/`.
     *
     * Returns the resolved absolute path, or null if neither
     * candidate exists.
     */
    private static function resolveMetaScriptPath(string $fixturePath, string $relPath): ?string
    {
        // Absolute-from-WPT-root paths (e.g. `/common/subset-tests.js`)
        // resolve straight against the upstream tree.
        if (str_starts_with($relPath, '/')) {
            $upstreamAbs = dirname(__DIR__) . '/Wpt/upstream' . $relPath;
            $real = realpath($upstreamAbs);
            return $real !== false && is_file($real) ? $real : null;
        }
        $direct = dirname($fixturePath) . '/' . $relPath;
        if (is_file($direct)) {
            return $direct;
        }
        // Imported fixtures retain their upstream-relative script
        // paths (e.g. `../util/helpers.js`) even though we've
        // flattened them into `tests/Wpt/fixtures/<cat>/`. Resolve
        // against the upstream tree.
        //
        // Critical subtlety: multiple upstream subdirs can contain
        // identically-named files (e.g. `encrypt_decrypt/rsa.js` AND
        // `sign_verify/rsa.js` — completely different contents). To
        // pick the right one we map each imported fixture to the
        // upstream subdir it came from. The map keys are the
        // fixture-file basename after `.any.js` is stripped.
        static $categoryMap = [
            'crypto' => 'WebCryptoAPI',
            'xhr' => 'xhr',
        ];
        static $fixtureSubdir = [
            // crypto/<fixture> → WebCryptoAPI/<subdir>/
            'aes_cbc' => 'encrypt_decrypt',
            'aes_ctr' => 'encrypt_decrypt',
            'aes_gcm' => 'encrypt_decrypt',
            'rsa_oaep' => 'encrypt_decrypt',
            'digest' => 'digest',
            'hmac' => 'sign_verify',
            'ecdsa' => 'sign_verify',
            'rsa_pkcs' => 'sign_verify',
            'rsa_pss' => 'sign_verify',
            'hkdf' => 'derive_bits_keys',
            'pbkdf2' => 'derive_bits_keys',
            'ecdh_bits' => 'derive_bits_keys',
            'ecdh_keys' => 'derive_bits_keys',
        ];
        $category = basename(dirname($fixturePath));
        if (!isset($categoryMap[$category])) {
            return null;
        }
        $upstreamRoot = dirname(__DIR__) . '/Wpt/upstream/' . $categoryMap[$category];
        $base = preg_replace('/\.any\.js$/', '', basename($fixturePath));
        // First try the fixture's own upstream subdir — the
        // canonical, unambiguous match for relative paths.
        if (isset($fixtureSubdir[$base])) {
            $candidate = realpath($upstreamRoot . '/' . $fixtureSubdir[$base] . '/' . $relPath);
            if ($candidate !== false && is_file($candidate)) {
                return $candidate;
            }
        }
        // Fallback: try the upstream root, then every other subdir.
        // Used for relative paths that walk OUT of the fixture's
        // home subdir (`../util/helpers.js`).
        $candidate = realpath($upstreamRoot . '/' . $relPath);
        if ($candidate !== false && is_file($candidate)) {
            return $candidate;
        }
        foreach (glob($upstreamRoot . '/*', GLOB_ONLYDIR) ?: [] as $subdir) {
            $candidate = realpath($subdir . '/' . $relPath);
            if ($candidate !== false && is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

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
