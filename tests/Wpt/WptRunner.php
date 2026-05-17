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

        // Stub WebSocket transport for the WPT fixtures we currently
        // import. They cover constructor validation, property
        // defaults, close()-while-CONNECTING semantics, and a
        // handful of post-open assertions. None of them speak to a
        // live peer, so this transport synthesizes the open / close
        // events the harness expects:
        //
        //   - On construct, schedule an async `open` event. The
        //     handler is guarded — it skips emitting if the JS code
        //     already moved the socket out of CONNECTING (via
        //     close() during connect), so close-while-CONNECTING
        //     fixtures still observe their CLOSING → CLOSED flow.
        //   - close() schedules an async clean `close` event.
        $state = (object) ['closed' => false];
        $engine->setWebSocketTransport(static function (
            string $url,
            array $protocols,
            callable $emit,
        ) use ($state): array {
            $local = (object) ['closed' => false, 'opened' => false];
            \Phasis\Value\JsPromise::scheduleCallback(static function () use ($emit, $local): void {
                if ($local->closed) {
                    return;
                }
                $local->opened = true;
                $emit('open', []);
            });
            return [
                'send' => static function ($data, bool $isBinary) use ($emit, $local): void {
                    // Echo the payload back as a message event so
                    // the Send-* fixtures, which expect an echo
                    // peer, can observe the round-trip.
                    if ($local->closed) {
                        return;
                    }
                    \Phasis\Value\JsPromise::scheduleCallback(static function () use ($emit, $local, $data, $isBinary): void {
                        if ($local->closed) {
                            return;
                        }
                        $emit('message', ['data' => $data, 'isBinary' => $isBinary]);
                    });
                },
                'close' => static function (int $code = 1000, string $reason = '') use ($emit, $local): void {
                    if ($local->closed) {
                        return;
                    }
                    $local->closed = true;
                    \Phasis\Value\JsPromise::scheduleCallback(static function () use ($emit, $local, $code, $reason): void {
                        // If the connection never reached `open`,
                        // close-while-CONNECTING semantics apply:
                        // emit `error` then a clean `close` with
                        // wasClean=false. After `open`, close cleanly.
                        if (!$local->opened) {
                            $emit('error', ['message' => 'connection aborted by close()']);
                            $emit('close', [
                                'code' => 1006,
                                'reason' => '',
                                'wasClean' => false,
                            ]);
                        } else {
                            // 0 from the engine signals "no code on
                            // the wire" — the receiver surfaces it as
                            // 1005 in the close event.
                            $eventCode = $code === 0 ? 1005 : $code;
                            $emit('close', [
                                'code' => $eventCode,
                                'reason' => $reason,
                                'wasClean' => true,
                            ]);
                        }
                    });
                },
            ];
        });

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

        // Base URL the Request constructor uses to resolve relative
        // URLs in fixtures that hardcode paths like
        // `../resources/foo.py` (fetch fixtures) or `resources/foo.py`
        // (xhr fixtures). Our PHP test server routes `/resources/<X>`.
        // Pick a per-category base so both layouts resolve there.
        $category = basename(dirname($path));
        $baseUrl = match ($category) {
            // XHR fixtures reference `resources/X.py` directly — base
            // is the server root so the relative resolves cleanly.
            'xhr' => 'http://127.0.0.1:8765/',
            // WebSocket fixtures compare resolved URLs against
            // `new URL(input, location)`. Use the same value the
            // location stub reports so the two match.
            'websockets' => 'http://web-platform.test:8000/',
            // Fetch fixtures originally lived under `fetch/api/headers/`
            // and reference `../resources/...`. Base = /headers/ so
            // the `..` jump lands on /resources/.
            default => 'http://127.0.0.1:8765/headers/',
        };
        $engine->setGlobal('__phasisRequestBaseUrl', $baseUrl);

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
        // resolve straight against the upstream tree — unless a
        // category-specific override exists under `_helpers/`, which
        // wins so we can ship pre-substituted variants of the
        // server-template helpers (e.g. `/common/get-host-info.sub.js`).
        if (str_starts_with($relPath, '/')) {
            $category = basename(dirname($fixturePath));
            $override = dirname($fixturePath) . '/_helpers' . $relPath;
            if (is_file($override)) {
                return $override;
            }
            $upstreamAbs = dirname(__DIR__) . '/Wpt/upstream' . $relPath;
            $real = realpath($upstreamAbs);
            return $real !== false && is_file($real) ? $real : null;
        }
        // Per-category override directory. WebSocket fixtures pull
        // helpers from `constants.sub.js`, which the WPT server
        // normally fills in with template substitutions
        // (`{{host}}`, `{{ports[ws][0]}}`, …). We ship a resolved
        // copy under `fixtures/<category>/_helpers/` and consult
        // that first so the fixtures can run without the WPT server.
        $category = basename(dirname($fixturePath));
        $override = dirname($fixturePath) . '/_helpers/' . $relPath;
        if (is_file($override)) {
            return $override;
        }
        $direct = dirname($fixturePath) . '/' . $relPath;
        if (is_file($direct)) {
            return $direct;
        }
        // XHR fixtures reference helpers like `resources/X.js` that
        // live in the upstream xhr/resources directory.
        if ($category === 'xhr' && !str_contains($relPath, '..')) {
            $upstreamPeer = dirname(__DIR__) . '/Wpt/upstream/xhr/' . $relPath;
            if (is_file($upstreamPeer)) {
                return $upstreamPeer;
            }
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
            'fetch' => 'fetch/api',
            'compression' => 'compression',
            'console' => 'console',
            'timers' => 'html/webappapis/timers',
            'microtask-queuing' => 'html/webappapis/microtask-queuing',
            'urlpattern' => 'urlpattern',
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
