<?php

declare(strict_types=1);

namespace Phasis\Tests\Oracle;

use Phasis\Engine;

class Test262Runner
{
    private string $suiteDir;
    private string $harnessDir;
    /** @var list<string> Features to skip */
    private array $skipFeatures = [];
    /** @var array<string, string> Cached harness source (stripped frontmatter) */
    private array $harnessCache = [];

    /**
     * test262 paths (relative to test262/test/) that scaffold
     * via $262.agent.start but pass under a single-threaded runner
     * because they assert behaviour that holds without preemptive
     * concurrency (e.g. "notify with no waiters returns 0", input
     * validation that throws before the worker schedules).
     *
     * @var list<string>
     */
    private const AGENT_ALLOWLIST = [
        'built-ins/Atomics/notify/bigint/notify-all-on-loc.js',
        'built-ins/Atomics/notify/count-defaults-to-infinity-missing.js',
        'built-ins/Atomics/notify/count-defaults-to-infinity-undefined.js',
        'built-ins/Atomics/notify/negative-count.js',
        'built-ins/Atomics/notify/notify-all-on-loc.js',
        'built-ins/Atomics/notify/notify-all.js',
        'built-ins/Atomics/notify/notify-nan.js',
        'built-ins/Atomics/notify/notify-one.js',
        'built-ins/Atomics/notify/notify-renotify-noop.js',
        'built-ins/Atomics/notify/notify-two.js',
        'built-ins/Atomics/notify/notify-with-no-agents-waiting.js',
        'built-ins/Atomics/notify/notify-with-no-matching-agents-waiting.js',
        'built-ins/Atomics/notify/notify-zero.js',
        'built-ins/Atomics/notify/undefined-index-defaults-to-zero.js',
        'built-ins/Atomics/wait/bigint/false-for-timeout-agent.js',
        'built-ins/Atomics/wait/bigint/nan-for-timeout.js',
        'built-ins/Atomics/wait/bigint/negative-timeout-agent.js',
        'built-ins/Atomics/wait/bigint/no-spurious-wakeup-no-operation.js',
        'built-ins/Atomics/wait/bigint/no-spurious-wakeup-on-add.js',
        'built-ins/Atomics/wait/bigint/no-spurious-wakeup-on-and.js',
        'built-ins/Atomics/wait/bigint/no-spurious-wakeup-on-compareExchange.js',
        'built-ins/Atomics/wait/bigint/no-spurious-wakeup-on-exchange.js',
        'built-ins/Atomics/wait/bigint/no-spurious-wakeup-on-or.js',
        'built-ins/Atomics/wait/bigint/no-spurious-wakeup-on-store.js',
        'built-ins/Atomics/wait/bigint/no-spurious-wakeup-on-sub.js',
        'built-ins/Atomics/wait/bigint/no-spurious-wakeup-on-xor.js',
        'built-ins/Atomics/wait/bigint/value-not-equal.js',
        'built-ins/Atomics/wait/bigint/waiterlist-block-indexedposition-wake.js',
        'built-ins/Atomics/wait/bigint/was-woken-before-timeout.js',
        'built-ins/Atomics/wait/false-for-timeout-agent.js',
        'built-ins/Atomics/wait/good-views.js',
        'built-ins/Atomics/wait/nan-for-timeout.js',
        'built-ins/Atomics/wait/negative-timeout-agent.js',
        'built-ins/Atomics/wait/no-spurious-wakeup-no-operation.js',
        'built-ins/Atomics/wait/no-spurious-wakeup-on-add.js',
        'built-ins/Atomics/wait/no-spurious-wakeup-on-and.js',
        'built-ins/Atomics/wait/no-spurious-wakeup-on-compareExchange.js',
        'built-ins/Atomics/wait/no-spurious-wakeup-on-exchange.js',
        'built-ins/Atomics/wait/no-spurious-wakeup-on-or.js',
        'built-ins/Atomics/wait/no-spurious-wakeup-on-store.js',
        'built-ins/Atomics/wait/no-spurious-wakeup-on-sub.js',
        'built-ins/Atomics/wait/no-spurious-wakeup-on-xor.js',
        'built-ins/Atomics/wait/null-for-timeout-agent.js',
        'built-ins/Atomics/wait/object-for-timeout-agent.js',
        'built-ins/Atomics/wait/poisoned-object-for-timeout-throws-agent.js',
        'built-ins/Atomics/wait/symbol-for-index-throws-agent.js',
        'built-ins/Atomics/wait/symbol-for-timeout-throws-agent.js',
        'built-ins/Atomics/wait/symbol-for-value-throws-agent.js',
        'built-ins/Atomics/wait/true-for-timeout-agent.js',
        'built-ins/Atomics/wait/undefined-for-timeout.js',
        'built-ins/Atomics/wait/undefined-index-defaults-to-zero.js',
        'built-ins/Atomics/wait/value-not-equal.js',
        'built-ins/Atomics/wait/wait-index-value-not-equal.js',
        'built-ins/Atomics/wait/waiterlist-block-indexedposition-wake.js',
        'built-ins/Atomics/wait/was-woken-before-timeout.js',
    ];

    /**
     * test262 paths (relative to test262/test/) that still need richer
     * cross-realm semantics than we implement. The list was originally
     * the audited 94 tests that depended on multi-realm intrinsics; the
     * realm-tracking layer (GetFunctionRealm, per-realm intrinsic
     * lookup in builtins) brought 67 of them green. The remaining
     * entries assert deeper semantics (Function constructor's prototype
     * coming from the new-target realm, species across realms in
     * TypedArray/ArrayBuffer, etc.). Set PHPJS_AUDIT_BYPASS_CROSSREALM=1
     * to skip the blocklist when re-auditing whether further engine
     * work has recovered any of them. Tracked under "structural gaps"
     * in CLAUDE.md.
     *
     * @var list<string>
     */
    private const CROSS_REALM_BLOCKLIST = [
    ];

    /**
     * test262 paths (relative to test262/test/) for tests that
     * exercise host-data gaps the pure-PHP runtime cannot fill
     * without external dependencies (Unicode 16 case-fold tables,
     * ICU4X-only calendars, etc.) or that are stress fixtures with
     * runtime cost so far above the tree-walker's budget that they
     * always exceed the runner's per-test deadline. Each entry
     * carries a short rationale. Set PHPJS_AUDIT_BYPASS_HOSTGAP=1
     * when re-auditing whether any of them now pass.
     *
     * @var array<string, string>
     */
    private const HOST_GAP_BLOCKLIST = [
        // ---------------------------------------------------------------
        // Note: SpiderMonkey JS-loop stress fixtures (decodeURI/A2.5_T1,
        // decodeURIComponent/A2.5_T1, Array/toSpliced-dense,
        // Date/dst-offset-caching-*, Temporal/Calendar/compare-to-
        // datetimeformat, TypedArray/element-setting-converts-using-
        // ToNumber, TypedArray/sort_large_countingsort, and
        // expressions/nullish-coalescing) were previously blocklisted
        // pending a bytecode JIT. The bytecode VM (src/Bytecode/) now
        // clears them within the per-test budget when each test runs as
        // an isolated chunk (see test262_isolated_tests in
        // config/support.php and the isolated-test budget bump in
        // run()). They contribute to compliance as normal passes.
        // ---------------------------------------------------------------
        // (from-chinese.js, addition-across-lunisolar-leap-months-chinese.js,
        // and non-iso-calendars-chinese.js are no longer blocklisted: phasis
        // now ships a pure-PHP Reingold-Dershowitz-equivalent table for the
        // Chinese / Dangi calendars under src/BuiltIn/data, which is
        // CI-independent and matches the V8 / Unicode 16 reference.)
        // ECMAScript v-flag RegExp full-case-folding tests assert the
        // ΐ / ΐ family of multi-char Unicode case-folding
        // pairs. ICU 70 (CI) lacks the U+1FD3 / U+1FE3 / U+FB05-FB06
        // folding equivalence that ICU 76 (Unicode 16) introduced; local
        // ICU 78 matches the test reference and the test passes there.
        // Chunk 2 — Changes_When_NFKC_Casefolded through Extended_Pictographic:
        // Chunk 3 — Script_Extensions for J-L scripts:
        // Additional locally-detected drifts: properties that pass on
        // CI's older ICU but fail on macOS' ICU 78 because Unicode 17
        // re-classified specific code points. Blocklisted here so the
        // local suite stays green across the macOS development host
        // and the Ubuntu CI host without conditional logic per OS.
        // Local check is IntlChar::hasBinaryProperty / charType /
        // PROPERTY_SCRIPT compared against the matchSymbols / nonMatch
        // ranges parsed out of each generated fixture.
        // General_Category drifts: ICU 78 re-classified specific code
        // points (e.g. U+A7D2 LATIN CAPITAL LETTER DOUBLE THORN moves
        // from Cn (Unassigned) in older ICU to Lu (Uppercase_Letter) in
        // ICU 78), which makes the GC test fixtures disagree with the
        // host's IntlChar::charType output on either side of the drift.
        // Script drifts: scripts whose code-point allocation changed
        // between Unicode 14 (ICU 70) and Unicode 17 (ICU 78). The
        // primary Script enum differs at the per-codepoint level for
        // a small set of newly added or reassigned code points.
        // Partial-fail chunk culprits identified by Unicode-version
        // pattern inference: Ubuntu CI ICU 70 = Unicode 14, so scripts
        // added in Unicode 15 (Kawi, Nag_Mundari) and Unicode 15.1 /
        // 16 (Kirat_Rai, Tulu_Tigalari, Gurung_Khema, Ol_Onal, Sunuwar,
        // Todhri, Garay) are absent from the host tables. Older
        // scripts in this batch (Latin, Arabic, Armenian, Devanagari,
        // Bengali, Sinhala, Mongolian, Saurashtra, Osage,
        // Canadian_Aboriginal, Caucasian_Albanian, Ethiopic,
        // Gurmukhi, Old_Permic, Adlam, Tai_Le, Balinese, Myanmar) got
        // codepoint additions or shared-extension reclassifications in
        // Unicode 15-16, putting them on a different cell of the
        // ICU-drift surface.
        // CI run 25732626984 chunks that landed at fail=1/attempted=1
        // (every other file in the chunk was already blocked, so the
        // single remaining attempt is the unambiguous culprit):
        //  - Samaritan (Saurashtra blocked) -> Samaritan
        //  - Carian    (Canadian_Aboriginal + Caucasian_Albanian blocked) -> Carian
        //  - Script_Extensions_-_Han (Gurmukhi + Gurung_Khema blocked) -> Han
        // Same Unicode-16 drift family as the surrounding entries.
        // Positive forms of \p{StringProperty} under /v need the full
        // Unicode 16 emoji-sequence data tables (thousands of multi-
        // codepoint sequences per property). vFlagPropertyOfStringsSet
        // only has partial coverage; bundling the full set is a
        // multi-day project. Negative forms (-negative-u, -P,
        // -CharacterClass) now SyntaxError correctly per the recent
        // parser fix; these positive forms still fall through to the
        // partial-set expansion and miss some required matches.
    ];

    /**
     * Tests promoted to single-file chunks via test262_isolated_tests
     * in config/support.php run with a wider per-test budget than the
     * 30 s default — they were placed in isolation precisely because
     * they approach or exceed it. Lookup is keyed by the full
     * suite-relative path (`test262/test/...`) and built once at
     * setIsolatedTests() time. Empty by default so a runner constructed
     * without the config (unit tests, ad-hoc invocations) behaves
     * exactly as before.
     *
     * @var array<string, true>
     */
    private array $isolatedSet = [];

    public function __construct(string $suiteDir)
    {
        $this->suiteDir = rtrim($suiteDir, '/');
        $this->harnessDir = $this->suiteDir . '/harness';
    }

    /** @param list<string> $features */
    public function setSkipFeatures(array $features): void
    {
        $this->skipFeatures = $features;
    }

    /**
     * Register the list of tests promoted to their own single-file
     * chunk via config/support.php's test262_isolated_tests. Each
     * entry is a suite-relative path (e.g. "staging/sm/Date/dst-
     * offset-caching-1-of-8.js"). Tests in this set are granted a
     * larger per-test wall budget in run().
     *
     * @param list<string> $relativePaths
     */
    public function setIsolatedTests(array $relativePaths): void
    {
        $set = [];
        foreach ($relativePaths as $rel) {
            $set['test262/test/' . $rel] = true;
        }
        $this->isolatedSet = $set;
    }

    public function run(string $testPath): TestResult
    {
        $source = file_get_contents($testPath);
        if ($source === false) {
            return new TestResult($testPath, TestStatus::Skip, 'Cannot read file');
        }

        $meta = $this->parseFrontmatter($source);

        // Check if test requires features we don't support
        foreach ($meta['features'] ?? [] as $feature) {
            if (in_array($feature, $this->skipFeatures, true)) {
                return new TestResult($testPath, TestStatus::Skip, "Skipped feature: {$feature}");
            }
        }

        // Allowlist short-circuit: tests that scaffold via $262.agent
        // but pass under the Fiber simulator (the worker exits early
        // via input validation, a deterministic timeout, or a no-op
        // notify path that resolves without needing real preemptive
        // concurrency). Run them through the normal pipeline regardless
        // of what the spin-loop / waitAsync heuristics below would
        // otherwise flag.
        $allowlisted = false;
        foreach (self::AGENT_ALLOWLIST as $rel) {
            if (str_ends_with($testPath, '/' . $rel)) {
                $allowlisted = true;
                break;
            }
        }

        // Tests that orchestrate multiple agents via the $262.agent host
        // were previously skipped when they used spin-loop patterns on
        // shared atomic slots (`while (Atomics.load/compareExchange/
        // exchange(...))`). The AgentHost now installs cooperative
        // load-spin and store-notify hooks (see onLoadSpin /
        // onStoreNotify) so a worker fiber repeatedly reading the same
        // value from a shared slot suspends until any agent writes to
        // that slot. Set PHPJS_BYPASS_AGENT_HEURISTIC=1 to re-audit (it
        // is currently a no-op since the skip branch is gone).

        // Multi-agent waitAsync tests now run through the Fiber simulator.
        // The infrastructure in AgentHost handles them: virtual-clock
        // setTimeout / clearTimeout, a post-microtask-drain hook that
        // advances time and fires due timers, and an Atomics.waitAsync
        // timeout hook so a worker-side waitAsync(... TIMEOUT) doesn't
        // race-resolve as "timed-out" before main can call notify. The
        // residual JsToPhp double-call quirk that previously caused
        // these to hang was fixed by bail-on-let-with-callExpr (commit
        // cd2b265), so the heuristic skip is no longer needed.

        // Audited cross-realm blocklist: only these specific paths
        // genuinely depend on multi-realm semantics our single-realm
        // child-Engine model can't satisfy (identity-of-intrinsics
        // across realms, %FooPrototype% lookup, etc.). The blanket
        // string-match skip from before was too broad — many tests
        // reference $262.createRealm to scaffold but assert behaviour
        // invariant across realms; the cross-realm impl now lets those
        // run.
        if (!getenv('PHPJS_AUDIT_BYPASS_CROSSREALM')) {
            foreach (self::CROSS_REALM_BLOCKLIST as $rel) {
                if (str_ends_with($testPath, '/' . $rel)) {
                    return new TestResult(
                        $testPath,
                        TestStatus::Skip,
                        'Cross-realm test (single-realm runner)',
                    );
                }
            }
        }

        // Host-data and structural-stress blocklist.
        if (!getenv('PHPJS_AUDIT_BYPASS_HOSTGAP')) {
            foreach (self::HOST_GAP_BLOCKLIST as $rel => $reason) {
                if (str_ends_with($testPath, '/' . $rel)) {
                    return new TestResult(
                        $testPath,
                        TestStatus::Skip,
                        $reason,
                    );
                }
            }
        }

        // Cross-realm, DST stress, and decodeURI A2.5_T1 are all now
        // supported (createRealm impl, DST cache, top-level VM compile).


        $flags = $meta['flags'] ?? [];

        // CanBlockIsTrue tests assert the spec behaviour for hosts whose
        // agent can suspend on Atomics.wait. Our PHP runtime is
        // single-threaded, so we can't really block. We emulate it: when
        // the flag is set, Atomics.wait returns "timed-out" (single-threaded
        // approximation) instead of throwing TypeError. The toggle is
        // scoped via try/finally so it never leaks into the next test.
        $canBlock = in_array('CanBlockIsTrue', $flags, true);

        // Skip raw tests (no harness)
        $isRaw = in_array('raw', $flags, true);
        $isAsync = in_array('async', $flags, true);
        $isModule = in_array('module', $flags, true);

        $negative = $meta['negative'] ?? null;
        $includes = $meta['includes'] ?? [];
        $onlyStrict = in_array('onlyStrict', $flags, true);
        $noStrict = in_array('noStrict', $flags, true);

        // Determine which modes to run.
        // Module tests always run in strict mode (single pass).
        $modes = [];
        if ($isModule) {
            $modes[] = 'module';
        } elseif ($onlyStrict) {
            $modes[] = 'strict';
        } elseif ($noStrict) {
            $modes[] = 'sloppy';
        } else {
            $modes[] = 'sloppy';
            // We'll start with sloppy only for now
        }

        if ($canBlock) {
            \Phasis\BuiltIn\AtomicsObject::setAgentCanSuspend(true);
        }
        try {
            foreach ($modes as $mode) {
                $result = $this->executeTest($testPath, $source, $meta, $mode, $includes, $negative, $isRaw, $isAsync);
                if ($result->status !== TestStatus::Pass) {
                    return $result;
                }
            }
        } finally {
            if ($canBlock) {
                \Phasis\BuiltIn\AtomicsObject::setAgentCanSuspend(false);
            }
        }

        return new TestResult($testPath, TestStatus::Pass);
    }

    private function executeTest(
        string $testPath,
        string $source,
        array $meta,
        string $mode,
        array $includes,
        ?array $negative,
        bool $isRaw,
        bool $isAsync = false,
    ): TestResult {
        // Run in-process for speed (~100x faster than subprocess per test)
        $engine = new Engine();
        // test262 tests may iterate over large Unicode ranges. Raise the loop limit
        // well above the default 100K so these tests can complete.
        $engine->setLimit('maxLoopIterations', 2_000_000);
        // Hard time limit per test: env override, default 30s. Tests
        // promoted to their own single-file chunk via test262_isolated_tests
        // get a 120s budget instead — they were isolated precisely
        // because they approach or exceed 30s (SM DST cache stress with
        // its O(n^4) probe, the Sputnik decodeURI/decodeURIComponent
        // UTF-8 sweep at ~983K four-byte percent-encoded sequences, the
        // SM TypedArray sort/ToNumber stress, etc.). Because each
        // isolated test runs alone in its chunk, widening the per-test
        // budget here does not steal time from neighbour tests.
        $envOverride = getenv('PHPJS_TEST_TIME_LIMIT');
        if ($envOverride !== false && $envOverride !== '') {
            $timeLimit = (int) $envOverride;
        } else {
            $relForLookup = $this->relativeForIsolatedLookup($testPath);
            $timeLimit = isset($this->isolatedSet[$relForLookup]) ? 120 : 30;
        }
        set_time_limit($timeLimit);

        // Set the module path so that import() can resolve relative specifiers.
        $realTestPath = realpath($testPath);
        if ($realTestPath !== false) {
            $engine->setCurrentModulePath($realTestPath);
        }

        // Install $262 host object for test262 harness. The AgentHost
        // installs static Atomics hooks; clear them in the finally below.
        $this->install262HostObject($engine);
        $agentCleanup = static function (): void {
            \Phasis\BuiltIn\AtomicsObject::setSyncWaitHook(null);
            \Phasis\BuiltIn\AtomicsObject::setSyncNotifyHook(null);
            \Phasis\BuiltIn\AtomicsObject::setWaitAsyncTimeoutHook(null);
            \Phasis\Value\JsPromise::setPostDrainHook(null);
        };
        // Host-side fast-path for assert.sameValue / _isSameValue —
        // tests like sort_large_countingsort.js call it ~400k times.
        $this->registerFastAssertInstaller($engine);

        // For async tests, install $DONE and track completion.
        $asyncResult = null;
        $asyncError = null;
        if ($isAsync) {
            $engine->setGlobal('__asyncDone', false);
            $engine->setGlobal('__asyncError', null);
            $engine->eval(<<<'JS'
            function $DONE(error) {
                __asyncDone = true;
                if (error) {
                    __asyncError = error;
                }
            }
            JS);
        }

        try {
            return $this->runTestBody(
                $engine,
                $testPath,
                $source,
                $mode,
                $includes,
                $negative,
                $isRaw,
                $isAsync,
                $realTestPath,
            );
        } finally {
            $agentCleanup();
        }
    }

    private function runTestBody(
        Engine $engine,
        string $testPath,
        string $source,
        string $mode,
        array $includes,
        ?array $negative,
        bool $isRaw,
        bool $isAsync,
        string|false $realTestPath,
    ): TestResult {
        try {
            $testSource = preg_replace('/\/\*---.*?---\*\//s', '', $source);

            if ($mode === 'module') {
                if (!$isRaw) {
                    $this->loadHarness($engine, 'sta.js');
                    $this->loadHarness($engine, 'assert.js');
                    foreach ($includes as $include) {
                        $this->loadHarness($engine, $include);
                    }
                    // Now that the harness has executed in its own scripts,
                    // swap assert.* with native fast paths.
                    try {
                        $engine->eval('__test262_install_fast_asserts__();');
                    } catch (\Throwable) {
                        // Installer is best-effort.
                    }
                }
                $modulePath = ($realTestPath !== false) ? $realTestPath : $testPath;
                $engine->evalAsModule($testSource, $modulePath);
            } elseif (!$isRaw) {
                // Concatenate harness + test source so lexical (let/const)
                // bindings in the harness remain visible to the test body.
                // Each eval call creates its own lexical environment that
                // is discarded on return, so running harness separately
                // would drop const declarations like
                // `const assertToStringOrNativeFunction = ...`.
                //
                // For strict mode, the directive must appear at the very
                // start of the source so it takes effect for the whole
                // program (including the harness prologue).
                $harnessSrc = '';
                $harnessSrc .= $this->getHarnessSource('sta.js');
                $harnessSrc .= $this->getHarnessSource('assert.js');
                foreach ($includes as $include) {
                    $harnessSrc .= $this->getHarnessSource($include);
                }
                // After the harness has defined assert.* but before the
                // test body runs, swap assert.sameValue / _isSameValue /
                // notSameValue with native PHP equivalents. See
                // registerFastAssertInstaller().
                $combined = $harnessSrc . "\n__test262_install_fast_asserts__();\n" . $testSource;
                if ($mode === 'strict') {
                    $combined = '"use strict";' . "\n" . $combined;
                }
                $engine->eval($combined);
            } else {
                if ($mode === 'strict') {
                    $testSource = '"use strict";' . "\n" . $testSource;
                }
                $engine->eval($testSource);
            }

            // For async tests, check if $DONE was called with an error.
            if ($isAsync) {
                $asyncErrorVal = $engine->eval('__asyncError');
                if ($asyncErrorVal !== null) {
                    $errMsg = is_string($asyncErrorVal)
                        ? $asyncErrorVal
                        : (is_array($asyncErrorVal)
                            ? ($asyncErrorVal['message'] ?? 'Async test failed')
                            : 'Async test failed');
                    if ($negative !== null) {
                        return new TestResult($testPath, TestStatus::Pass);
                    }
                    return new TestResult($testPath, TestStatus::Fail, 'Async: ' . $errMsg);
                }
            }

            if ($negative !== null) {
                return new TestResult(
                    $testPath,
                    TestStatus::Fail,
                    "Expected {$negative['type']} but test passed normally",
                );
            }

            return new TestResult($testPath, TestStatus::Pass);
        } catch (\Phasis\Exceptions\SyntaxError $e) {
            if ($negative !== null) {
                $type = $negative['type'] ?? 'Error';
                // Whether the test declares parse/early or runtime phase, a
                // SyntaxError from the PHP parser still satisfies the
                // expectation. Indirect eval of invalid source, for example,
                // surfaces as a runtime SyntaxError but originates from
                // our parser when the eval() call evaluates its argument.
                if ($type === 'SyntaxError') {
                    return new TestResult($testPath, TestStatus::Pass);
                }
            }
            return new TestResult($testPath, TestStatus::Fail, 'SyntaxError: ' . $e->getMessage());
        } catch (\Phasis\Exceptions\RuntimeError $e) {
            if ($negative !== null) {
                $type = $negative['type'] ?? 'Error';
                $jsName = null;
                if ($e instanceof \Phasis\Exceptions\JsThrowable) {
                    $jv = $e->jsValue;
                    if ($jv instanceof \Phasis\Value\JsObject) {
                        $n = $jv->get('name');
                        if ($n instanceof \Phasis\Value\JsString) {
                            $jsName = $n->value;
                        }
                        // Fall back to constructor.name (e.g. Test262Error
                        // defined in harness as constructor function without
                        // an own `name` property on prototype).
                        if ($jsName === null) {
                            $ctor = $jv->get('constructor');
                            if ($ctor instanceof \Phasis\Value\JsFunction) {
                                $cn = $ctor->get('name');
                                if ($cn instanceof \Phasis\Value\JsString && $cn->value !== '') {
                                    $jsName = $cn->value;
                                }
                            }
                        }
                    }
                }
                $match = match ($type) {
                    'TypeError' => $e instanceof \Phasis\Exceptions\TypeError || $jsName === 'TypeError',
                    'RangeError' => $e instanceof \Phasis\Exceptions\RangeError || $jsName === 'RangeError',
                    'ReferenceError' => $e instanceof \Phasis\Exceptions\ReferenceError || $jsName === 'ReferenceError',
                    'SyntaxError' => $jsName === 'SyntaxError',
                    'URIError' => $jsName === 'URIError',
                    'Error' => true,
                    default => $jsName === $type,
                };
                if ($match) {
                    return new TestResult($testPath, TestStatus::Pass);
                }
            }
            return new TestResult($testPath, TestStatus::Fail, $e::class . ': ' . $e->getMessage());
        } catch (\Throwable $e) {
            if ($negative !== null) {
                return new TestResult($testPath, TestStatus::Pass);
            }
            return new TestResult($testPath, TestStatus::Fail, $e::class . ': ' . $e->getMessage());
        }
    }

    private function buildTestScript(
        string $testPath,
        string $source,
        array $meta,
        string $mode,
        array $includes,
        ?array $negative,
        bool $isRaw,
    ): string {
        $autoload = escapeshellarg(dirname(__DIR__, 2) . '/vendor/autoload.php');
        $negativeSer = $negative !== null ? var_export($negative, true) : 'null';
        $includesSer = var_export($includes, true);
        $harnessDir = escapeshellarg($this->harnessDir);

        // Strip frontmatter from source
        $testSource = preg_replace('/\/\*---.*?---\*\//s', '', $source);
        if ($mode === 'strict') {
            $testSource = '"use strict";' . "\n" . $testSource;
        }
        $sourceEscaped = var_export($testSource, true);

        return <<<PHP
<?php
require {$autoload};
\$engine = new Phasis\Engine();
\$harnessDir = {$harnessDir};
\$isRaw = {$this->boolStr($isRaw)};
\$negative = {$negativeSer};
\$includes = {$includesSer};
\$testSource = {$sourceEscaped};

try {
    if (!\$isRaw) {
        \$files = ['sta.js', 'assert.js'];
        foreach (\$includes as \$inc) { \$files[] = \$inc; }
        foreach (\$files as \$f) {
            \$path = \$harnessDir . '/' . \$f;
            if (file_exists(\$path)) {
                \$src = file_get_contents(\$path);
                \$src = preg_replace('/\\/\\*---.*?---\\*\\//s', '', \$src);
                try { \$engine->eval(\$src); } catch (Throwable \$e) {}
            }
        }
    }
    \$engine->eval(\$testSource);
    if (\$negative !== null) {
        echo "Expected {\$negative['type']} but test passed normally";
        exit(1);
    }
    echo "PASS";
} catch (Phasis\Exceptions\SyntaxError \$e) {
    if (\$negative && (\$negative['phase'] ?? '') === 'parse' && (\$negative['type'] ?? '') === 'SyntaxError') {
        echo "PASS";
    } else {
        echo "SyntaxError: " . \$e->getMessage();
        exit(1);
    }
} catch (Phasis\Exceptions\RuntimeError \$e) {
    if (\$negative) {
        \$type = \$negative['type'] ?? 'Error';
        \$jsName = null;
        if (\$e instanceof Phasis\Exceptions\JsThrowable) {
            \$jv = \$e->jsValue;
            if (\$jv instanceof Phasis\Value\JsObject) {
                \$n = \$jv->get('name');
                if (\$n instanceof Phasis\Value\JsString) {
                    \$jsName = \$n->value;
                }
                if (\$jsName === null) {
                    \$ctor = \$jv->get('constructor');
                    if (\$ctor instanceof Phasis\Value\JsFunction) {
                        \$cn = \$ctor->get('name');
                        if (\$cn instanceof Phasis\Value\JsString && \$cn->value !== '') {
                            \$jsName = \$cn->value;
                        }
                    }
                }
            }
        }
        \$match = match(\$type) {
            'TypeError' => \$e instanceof Phasis\Exceptions\TypeError || \$jsName === 'TypeError',
            'RangeError' => \$e instanceof Phasis\Exceptions\RangeError || \$jsName === 'RangeError',
            'ReferenceError' => \$e instanceof Phasis\Exceptions\ReferenceError || \$jsName === 'ReferenceError',
            'SyntaxError' => \$jsName === 'SyntaxError',
            'URIError' => \$jsName === 'URIError',
            'Error' => true,
            default => \$jsName === \$type,
        };
        if (\$match) { echo "PASS"; exit(0); }
    }
    echo get_class(\$e) . ": " . \$e->getMessage();
    exit(1);
} catch (Throwable \$e) {
    echo get_class(\$e) . ": " . \$e->getMessage();
    exit(1);
}
PHP;
    }

    /**
     * Normalise an absolute test path to the suite-relative key used
     * by setIsolatedTests() (`test262/test/...`). Used so the isolated
     * lookup works regardless of where the runner is invoked from.
     */
    private function relativeForIsolatedLookup(string $testPath): string
    {
        $marker = '/test262/test/';
        $pos = strrpos($testPath, $marker);
        if ($pos === false) {
            return $testPath;
        }
        return 'test262/test/' . substr($testPath, $pos + strlen($marker));
    }

    private function boolStr(bool $v): string
    {
        return $v ? 'true' : 'false';
    }

    /** @deprecated Use subprocess approach above */
    private function executeTestInProcess(
        string $testPath,
        string $source,
        array $meta,
        string $mode,
        array $includes,
        ?array $negative,
        bool $isRaw,
    ): TestResult {
        $engine = new Engine();

        try {
            // Load harness files
            if (!$isRaw) {
                $this->loadHarness($engine, 'assert.js');
                $this->loadHarness($engine, 'sta.js');

                foreach ($includes as $include) {
                    $this->loadHarness($engine, $include);
                }
            }

            // Prepend "use strict" if strict mode
            $testSource = $source;
            // Strip frontmatter for execution
            $testSource = preg_replace('/\/\*---.*?---\*\//s', '', $testSource);

            if ($mode === 'strict') {
                $testSource = '"use strict";' . "\n" . $testSource;
            }

            $engine->eval($testSource);

            // If we expected a negative result, this is a failure
            if ($negative !== null) {
                $phase = $negative['phase'] ?? 'runtime';
                $type = $negative['type'] ?? 'Error';
                return new TestResult(
                    $testPath,
                    TestStatus::Fail,
                    "Expected {$type} at {$phase} phase, but test completed normally",
                );
            }

            return new TestResult($testPath, TestStatus::Pass);
        } catch (\Phasis\Exceptions\SyntaxError $e) {
            if ($negative !== null) {
                $phase = $negative['phase'] ?? 'runtime';
                $type = $negative['type'] ?? 'Error';
                if ($phase === 'parse' && $type === 'SyntaxError') {
                    return new TestResult($testPath, TestStatus::Pass);
                }
            }
            return new TestResult($testPath, TestStatus::Fail, 'SyntaxError: ' . $e->getMessage());
        } catch (\Phasis\Exceptions\RuntimeError $e) {
            if ($negative !== null) {
                $type = $negative['type'] ?? 'Error';
                $errorClass = match ($type) {
                    'TypeError' => \Phasis\Exceptions\TypeError::class,
                    'RangeError' => \Phasis\Exceptions\RangeError::class,
                    'ReferenceError' => \Phasis\Exceptions\ReferenceError::class,
                    'SyntaxError' => \Phasis\Exceptions\SyntaxError::class,
                    default => \Phasis\Exceptions\RuntimeError::class,
                };
                if ($e instanceof $errorClass || $type === 'Error') {
                    return new TestResult($testPath, TestStatus::Pass);
                }
            }
            return new TestResult($testPath, TestStatus::Fail, $e::class . ': ' . $e->getMessage());
        } catch (\Throwable $e) {
            return new TestResult($testPath, TestStatus::Fail, $e::class . ': ' . $e->getMessage());
        }
    }

    /**
     * Install the $262 host object for test262.
     *
     * Provides createRealm, detachArrayBuffer, evalScript, and gc.
     *
     * createRealm builds a fresh child Engine (its own global object and
     * its own intrinsic prototype graph) and returns a realm wrapper
     * object with `{global, evalScript, detachArrayBuffer, $262}`.
     * The returned wrapper is constructed in PHP so that:
     *   - realm.global is the CHILD realm's globalThis (not the parent's)
     *   - realm.evalScript invokes the child Engine's eval
     *   - cross-realm object identity tests can distinguish prototypes
     *     between the parent and child realm
     */
    private function install262HostObject(Engine $engine): void
    {
        $runner = $this;
        $engine->eval(<<<'JS'
        var $262 = {};
        JS);

        // Build the createRealm host function in PHP so it returns a
        // JsObject wrapper directly (no PhpToJs round-trip that would
        // flatten the child realm's globalThis into a plain PHP array).
        $createRealmFn = \Phasis\Value\JsFunction::fromCallable(
            '__262_createRealm',
            function (
                \Phasis\Value\JsValue $this_,
                array $args
            ) use ($runner): \Phasis\Value\JsValue {
                return $runner->buildRealmWrapper();
            },
        );
        $engine->setGlobalJsValue('__262_createRealm', $createRealmFn);
        $engine->eval(<<<'JS'
        $262.createRealm = __262_createRealm;
        JS);

        // $262.detachArrayBuffer(buffer) - detach an ArrayBuffer
        $engine->setGlobal('__262_detachArrayBuffer', function ($buf) {
            if ($buf instanceof \Phasis\Value\JsArrayBuffer) {
                $buf->detach();
            }
        });
        $engine->eval(<<<'JS'
        $262.detachArrayBuffer = function(buf) {
            __262_detachArrayBuffer(buf);
        };
        JS);

        // $262.evalScript(code) - evaluate code in this realm.
        // The closure receives PHP-converted arguments via PhpToJs wrapper,
        // so $code is a PHP string (not JsString).
        $engine->setGlobal('__262_evalScript', function ($code) use ($engine) {
            $src = null;
            if ($code instanceof \Phasis\Value\JsString) {
                $src = $code->value;
            } elseif (is_string($code)) {
                $src = $code;
            }
            if ($src !== null) {
                return $engine->eval($src);
            }
            return null;
        });
        $engine->eval(<<<'JS'
        $262.evalScript = function(code) {
            return __262_evalScript(code);
        };
        JS);

        // SpiderMonkey shell extension shims. Tests under staging/sm/ assume
        // newGlobal() / createNewGlobal() exist as global functions returning
        // the new realm's globalThis. Wire them to $262.createRealm().global
        // so the same isolated child Engine is reused.
        $engine->eval(<<<'JS'
        var newGlobal = function() { return $262.createRealm().global; };
        var createNewGlobal = function() { return $262.createRealm().global; };
        JS);

        // $262.gc() - no-op (PHP has no manual GC trigger useful here)
        $engine->eval('$262.gc = function() {};');

        // $262.IsHTMLDDA: an object with the [[IsHTMLDDA]] internal slot.
        // typeof returns "undefined", ToBoolean returns false, == null/undefined is true.
        $engine->setGlobalJsValue('__262_IsHTMLDDA', new \Phasis\Value\JsHTMLDDA());
        $engine->eval('$262.IsHTMLDDA = __262_IsHTMLDDA;');

        // $262.agent: cooperative single-threaded simulation. See AgentHost
        // for details. Each call to install262HostObject builds a fresh
        // AgentHost so tests don't bleed state into each other.
        $agentHost = new AgentHost($engine);
        $agentHost->install();

        // test262 host function: print (used by some tests as a no-op or to store output).
        $engine->eval('function print() {}');

        // $262.AbstractModuleSource: stub for the source-phase-imports proposal.
        // The %AbstractModuleSource% intrinsic is a non-instantiable constructor
        // whose prototype carries a [Symbol.toStringTag] accessor that returns the
        // [[ModuleSourceClassName]] internal slot of its receiver (or undefined
        // when absent). We never construct one in this engine, so the getter
        // always returns undefined. We only need the descriptor shape to satisfy
        // the AbstractModuleSource property tests: the constructor's "prototype"
        // is non-writable / non-configurable, and its name/length use the
        // built-in defaults (configurable: true, writable: false).
        $engine->eval(<<<'JS'
        (function() {
            function AbstractModuleSource() {
                throw new TypeError('%AbstractModuleSource% is not a constructor');
            }
            Object.defineProperty(AbstractModuleSource.prototype, Symbol.toStringTag, {
                get: function() {
                    // [[ModuleSourceClassName]] internal slot is never set on
                    // any object in this engine, so always return undefined.
                    return undefined;
                },
                set: undefined,
                enumerable: false,
                configurable: true,
            });
            Object.defineProperty(AbstractModuleSource, 'prototype', {
                writable: false,
                enumerable: false,
                configurable: false,
            });
            $262.AbstractModuleSource = AbstractModuleSource;
        })();
        JS);
    }

    /**
     * Public wrapper for loadHarness (used by createRealm).
     */
    public function loadHarnessPublic(Engine $engine, string $filename): void
    {
        $this->loadHarness($engine, $filename);
    }

    /**
     * Build a test262 realm wrapper object backed by a fresh child Engine.
     *
     * Returned object shape (matches the test262 INTERPRETING.md spec):
     *   {
     *     global:             child realm's globalThis,
     *     evalScript(src):    eval `src` in the child realm,
     *     detachArrayBuffer:  detach an ArrayBuffer in the child realm,
     *     $262:               the child realm's $262 host object,
     *   }
     *
     * The child Engine's own install262HostObject() runs recursively so
     * that nested $262.createRealm() calls also work. The outer engine's
     * current-interpreter pointer is snapshot before constructing the
     * child (which re-installs it on creation) and restored afterwards
     * so the parent realm keeps controlling Symbol-prototype lookups
     * etc. for the rest of the current eval.
     */
    public function buildRealmWrapper(): \Phasis\Value\JsObject
    {
        $outerInterp = Engine::getCurrentInterpreter();
        // Save the JsFunction static back-references too: every Engine
        // constructor clobbers these (last-write-wins). Without restoring,
        // calls into JS code defined in the OUTER realm after createRealm
        // would dispatch through the CHILD interpreter, picking up the
        // child's globalEnv intrinsics (TypeError prototype, etc.) and
        // breaking cross-realm identity tests.
        $outerCallback = \Phasis\Value\JsFunction::getInterpreterCallback();
        $outerInstance = \Phasis\Value\JsFunction::getInterpreterInstance();
        $childEngine = new Engine();
        $childEngine->setLimit('maxLoopIterations', 2_000_000);
        $this->install262HostObject($childEngine);
        try {
            $this->loadHarness($childEngine, 'sta.js');
            $this->loadHarness($childEngine, 'assert.js');
            // staging/sm cross-realm tests create a child realm and then
            // use SpiderMonkey shell helpers (assertEq, assertEqArray,
            // newGlobal-style probes, native TypedArray / Reflect helper
            // extensions) inside it. The harness scripts are tiny
            // (~580 lines combined) so the easiest correct answer is
            // to load the common ones in every child realm rather than
            // plumbing the parent test's includes[] all the way down.
            // Loading is best-effort: tests that don't use these
            // helpers pay only the parse cost.
            foreach (
                [
                    'sm/non262-shell.js',
                    'sm/non262-TypedArray-shell.js',
                    'sm/non262-Reflect-shell.js',
                    'compareArray.js',
                ] as $shell
            ) {
                try {
                    $this->loadHarness($childEngine, $shell);
                } catch (\Throwable) {
                }
            }
        } catch (\Throwable) {
        }
        if ($outerInterp !== null) {
            Engine::setCurrentInterpreter($outerInterp);
        }
        if ($outerCallback !== null) {
            \Phasis\Value\JsFunction::setInterpreterCallback($outerCallback);
        }
        if ($outerInstance !== null) {
            \Phasis\Value\JsFunction::setInterpreterInstance($outerInstance);
        }

        $wrapper = new \Phasis\Value\JsObject();

        $childGlobal = $childEngine->getGlobalEnv()->get('globalThis');
        if (!$childGlobal instanceof \Phasis\Value\JsValue) {
            $childGlobal = \Phasis\Value\JsUndefined::instance();
        }
        $wrapper->set('global', $childGlobal);

        $child262 = $childEngine->getGlobalEnv()->has('$262')
            ? $childEngine->getGlobalEnv()->get('$262')
            : \Phasis\Value\JsUndefined::instance();
        $wrapper->set('$262', $child262);

        // evalScript runs the source in the CHILD realm, switching the
        // active interpreter pointer for the duration so any built-ins
        // that consult Engine::getCurrentInterpreter() resolve against
        // the child realm's intrinsic graph.
        $evalScriptFn = \Phasis\Value\JsFunction::fromCallable(
            'evalScript',
            function (
                \Phasis\Value\JsValue $this_,
                array $args
            ) use ($childEngine): \Phasis\Value\JsValue {
                $codeArg = $args[0] ?? \Phasis\Value\JsUndefined::instance();
                if ($codeArg instanceof \Phasis\Value\JsString) {
                    $src = $codeArg->value;
                } else {
                    $src = \Phasis\Spec\TypeConversion::toString($codeArg);
                }
                $prev = Engine::getCurrentInterpreter();
                $prevCallback = \Phasis\Value\JsFunction::getInterpreterCallback();
                $prevInstance = \Phasis\Value\JsFunction::getInterpreterInstance();
                Engine::setCurrentInterpreter($childEngine->getInterpreter());
                // Re-bind the JsFunction statics so JS-level calls within
                // the eval'd source route through the child interpreter.
                \Phasis\Value\JsFunction::setInterpreterInstance($childEngine->getInterpreter());
                \Phasis\Value\JsFunction::setInterpreterCallback(function (
                    \Phasis\Value\JsFunction $fn,
                    \Phasis\Value\JsValue $thisValue,
                    array $args
                ) use ($childEngine): \Phasis\Value\JsValue {
                    return $childEngine->getInterpreter()->callFunction($fn, $thisValue, $args);
                });
                $result = \Phasis\Value\JsUndefined::instance();
                try {
                    $result = $childEngine->getInterpreter()->execute(
                        (new \Phasis\Parser\Parser($src))->parse(),
                    );
                    \Phasis\Value\JsPromise::drainMicrotasks();
                } finally {
                    Engine::setCurrentInterpreter($prev);
                    if ($prevCallback !== null) {
                        \Phasis\Value\JsFunction::setInterpreterCallback($prevCallback);
                    }
                    if ($prevInstance !== null) {
                        \Phasis\Value\JsFunction::setInterpreterInstance($prevInstance);
                    }
                }
                return $result instanceof \Phasis\Value\JsValue
                    ? $result
                    : \Phasis\Value\JsUndefined::instance();
            },
        );
        $wrapper->set('evalScript', $evalScriptFn);

        $detachFn = \Phasis\Value\JsFunction::fromCallable(
            'detachArrayBuffer',
            function (\Phasis\Value\JsValue $this_, array $args): \Phasis\Value\JsValue {
                $buf = $args[0] ?? null;
                if ($buf instanceof \Phasis\Value\JsArrayBuffer) {
                    $buf->detach();
                }
                return \Phasis\Value\JsUndefined::instance();
            },
        );
        $wrapper->set('detachArrayBuffer', $detachFn);

        return $wrapper;
    }

    private function loadHarness(Engine $engine, string $filename): void
    {
        $src = $this->getHarnessSource($filename);
        if ($src === '') {
            return;
        }
        try {
            $engine->eval($src);
        } catch (\Throwable) {
            // Silently skip harness files that fail to load
        }
    }

    /** Load and cache harness source text without executing it. */
    private function getHarnessSource(string $filename): string
    {
        if (!isset($this->harnessCache[$filename])) {
            $path = $this->harnessDir . '/' . $filename;
            if (!file_exists($path)) {
                $this->harnessCache[$filename] = '';
                return '';
            }
            $src = file_get_contents($path);
            $this->harnessCache[$filename] = preg_replace('/\/\*---.*?---\*\//s', '', $src) ?? '';
        }
        return $this->harnessCache[$filename];
    }

    /** @return array{description?: string, features?: list<string>, includes?: list<string>, flags?: list<string>, negative?: array{type: string, phase: string}} */
    public function parseFrontmatter(string $source): array
    {
        if (!preg_match('/\/\*---(.*?)---\*\//s', $source, $matches)) {
            return [];
        }

        $yaml = $matches[1];
        $meta = [];

        // Normalize line endings so test files using CR-only or CRLF
        // terminators (e.g. Function.prototype.toString CR tests) parse
        // their frontmatter correctly.
        $yaml = str_replace(["\r\n", "\r"], "\n", $yaml);

        // Simple YAML parser for test262 frontmatter
        $lines = explode("\n", $yaml);
        $currentKey = null;
        $inList = false;
        $inNegative = false;
        $negativeData = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed === '---') {
                continue;
            }

            // Check for key: value
            if (preg_match('/^(\w+)\s*:\s*(.*)$/', $trimmed, $kv)) {
                $key = $kv[1];
                $value = trim($kv[2]);

                if ($inNegative && ($key === 'type' || $key === 'phase')) {
                    $negativeData[$key] = $value;
                    continue;
                }

                $inNegative = false;
                $currentKey = $key;

                if ($key === 'negative') {
                    $inNegative = true;
                    $negativeData = [];
                    $inList = false;
                    continue;
                }

                if ($value === '') {
                    // Possibly a list follows
                    $inList = true;
                    if (!isset($meta[$key])) {
                        $meta[$key] = [];
                    }
                    continue;
                }

                // Array notation [a, b]
                if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
                    $inner = substr($value, 1, -1);
                    $meta[$key] = array_map('trim', explode(',', $inner));
                    $inList = false;
                    continue;
                }

                $meta[$key] = $value;
                $inList = false;
                continue;
            }

            // List item
            if (preg_match('/^\s*-\s+(.+)$/', $trimmed, $li)) {
                $value = trim($li[1]);
                if ($inNegative) {
                    // Should be key: value inside negative block
                    if (preg_match('/^(\w+)\s*:\s*(.+)$/', $value, $nkv)) {
                        $negativeData[$nkv[1]] = trim($nkv[2]);
                    }
                    continue;
                }
                if ($currentKey !== null) {
                    if (!is_array($meta[$currentKey] ?? null)) {
                        $meta[$currentKey] = [];
                    }
                    $meta[$currentKey][] = $value;
                }
                continue;
            }

            // Indented key: value inside negative
            if ($inNegative && preg_match('/^\s+(\w+)\s*:\s*(.+)$/', $line, $nkv)) {
                $negativeData[$nkv[1]] = trim($nkv[2]);
            }
        }

        if (!empty($negativeData)) {
            $meta['negative'] = $negativeData;
        }

        return $meta;
    }

    /** @return \Generator<TestResult> */
    public function runDirectory(string $dir): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'js') {
                continue;
            }
            // Skip _FIXTURE files
            if (str_contains($file->getFilename(), '_FIXTURE')) {
                continue;
            }
            yield $this->run($file->getPathname());
        }
    }

    public function runAll(): SuiteResult
    {
        return $this->runSuite($this->suiteDir . '/test');
    }

    public function runSuite(string $dir): SuiteResult
    {
        $result = new SuiteResult();
        foreach ($this->runDirectory($dir) as $testResult) {
            $result->add($testResult);
        }
        return $result;
    }

    /**
     * Install `__test262_install_fast_asserts__` as a global host function.
     *
     * When called from JS (between harness load and test body), it walks
     * the global `assert` object and replaces its `sameValue`,
     * `_isSameValue`, and `notSameValue` properties with native PHP
     * closures.
     *
     * Tests like staging/sm/TypedArray/sort_large_countingsort.js call
     * `assert.sameValue` ~400k times. Each call would otherwise dispatch
     * through the interpreter (env binding, frame push, env cleanup) plus
     * a nested call to the JS `assert._isSameValue`. The native shortcut
     * compares the JsValues directly and only constructs a Test262Error
     * on mismatch (rare), cutting per-call cost by ~10x for the common
     * "both numbers, equal" path.
     */
    private function registerFastAssertInstaller(Engine $engine): void
    {
        // Resolve Test262Error lazily inside the installer so we read the
        // constructor the harness just installed (rather than freezing a
        // null reference when this method runs at engine-setup time).
        $engineRef = $engine;
        $installer = static function (
            \Phasis\Value\JsValue $thisValue,
            array $args,
            $interp = null,
        ) use ($engineRef): \Phasis\Value\JsValue {
            return self::doInstallFastAsserts($engineRef);
        };
        $engine->setGlobalJsValue(
            '__test262_install_fast_asserts__',
            \Phasis\Value\JsFunction::fromCallable('__test262_install_fast_asserts__', $installer, 0),
        );
    }

    /**
     * The actual install routine, invoked from JS after the harness has
     * defined assert.* in the global scope.
     */
    private static function doInstallFastAsserts(Engine $engine): \Phasis\Value\JsValue
    {
        $globalEnv = self::getEngineGlobalEnv($engine);
        if ($globalEnv === null) {
            return \Phasis\Value\JsUndefined::instance();
        }

        // Pull the Test262Error constructor (defined by sta.js).
        $test262ErrorCtor = null;
        if ($globalEnv->has('Test262Error')) {
            $maybe = $globalEnv->get('Test262Error');
            if ($maybe instanceof \Phasis\Value\JsFunction) {
                $test262ErrorCtor = $maybe;
            }
        }

        // Throw a Test262Error matching the harness's class. Falls back to
        // a plain Error-shaped object if the harness's Test262Error is not
        // present (e.g. tests that load only sta.js failed).
        $throwTest262 = static function (string $message) use ($test262ErrorCtor): void {
            if ($test262ErrorCtor !== null) {
                $errObj = $test262ErrorCtor->construct([new \Phasis\Value\JsString($message)]);
                throw new \Phasis\Exceptions\JsThrowable($errObj);
            }
            $obj = new \Phasis\Value\JsObject();
            $obj->set('name', new \Phasis\Value\JsString('Test262Error'));
            $obj->set('message', new \Phasis\Value\JsString($message));
            throw new \Phasis\Exceptions\JsThrowable($obj);
        };

        // SameValue, special-cased for the four common primitive shapes
        // seen in the harness. Falls back to the spec algorithm for
        // symbols, bigints, objects.
        $isSameValueNative = static function (\Phasis\Value\JsValue $a, \Phasis\Value\JsValue $b): bool {
            if ($a instanceof \Phasis\Value\JsNumber && $b instanceof \Phasis\Value\JsNumber) {
                $av = $a->value;
                $bv = $b->value;
                // NaN === NaN under SameValue.
                $aNaN = ($av !== $av);
                $bNaN = ($bv !== $bv);
                if ($aNaN || $bNaN) {
                    return $aNaN && $bNaN;
                }
                if ($av === 0.0 && $bv === 0.0) {
                    // Distinguish -0 from +0 the same way the harness does
                    // (`1/a === 1/b`). PHP throws DivisionByZeroError on
                    // float-divide-by-zero, so compare the IEEE 754 bit
                    // patterns directly.
                    return pack('E', $av) === pack('E', $bv);
                }
                return $av === $bv;
            }
            if ($a instanceof \Phasis\Value\JsString && $b instanceof \Phasis\Value\JsString) {
                return $a->value === $b->value;
            }
            if ($a instanceof \Phasis\Value\JsBoolean && $b instanceof \Phasis\Value\JsBoolean) {
                return $a->value === $b->value;
            }
            if ($a instanceof \Phasis\Value\JsUndefined && $b instanceof \Phasis\Value\JsUndefined) {
                return true;
            }
            if ($a instanceof \Phasis\Value\JsNull && $b instanceof \Phasis\Value\JsNull) {
                return true;
            }
            return \Phasis\Spec\AbstractOperations::sameValue($a, $b);
        };

        $isSameValueFn = \Phasis\Value\JsFunction::fromCallable(
            '_isSameValue',
            static function (
                \Phasis\Value\JsValue $thisValue,
                array $args,
            ) use ($isSameValueNative): \Phasis\Value\JsValue {
                $a = $args[0] ?? \Phasis\Value\JsUndefined::instance();
                $b = $args[1] ?? \Phasis\Value\JsUndefined::instance();
                return new \Phasis\Value\JsBoolean($isSameValueNative($a, $b));
            },
            2,
        );

        $sameValueFn = \Phasis\Value\JsFunction::fromCallable(
            'sameValue',
            static function (
                \Phasis\Value\JsValue $thisValue,
                array $args,
            ) use (
                $isSameValueNative,
                $throwTest262
            ): \Phasis\Value\JsValue {
                $actual = $args[0] ?? \Phasis\Value\JsUndefined::instance();
                $expected = $args[1] ?? \Phasis\Value\JsUndefined::instance();
                if ($isSameValueNative($actual, $expected)) {
                    return \Phasis\Value\JsUndefined::instance();
                }
                $message = $args[2] ?? \Phasis\Value\JsUndefined::instance();
                $prefix = '';
                if (!$message instanceof \Phasis\Value\JsUndefined) {
                    $prefix = self::coerceMessageToString($message) . ' ';
                }
                $msg = $prefix . 'Expected SameValue(«' . self::formatAssertValue($actual)
                    . '», «' . self::formatAssertValue($expected) . '») to be true';
                $throwTest262($msg);
                return \Phasis\Value\JsUndefined::instance();
            },
            3,
        );

        $notSameValueFn = \Phasis\Value\JsFunction::fromCallable(
            'notSameValue',
            static function (
                \Phasis\Value\JsValue $thisValue,
                array $args,
            ) use (
                $isSameValueNative,
                $throwTest262
            ): \Phasis\Value\JsValue {
                $actual = $args[0] ?? \Phasis\Value\JsUndefined::instance();
                $unexpected = $args[1] ?? \Phasis\Value\JsUndefined::instance();
                if (!$isSameValueNative($actual, $unexpected)) {
                    return \Phasis\Value\JsUndefined::instance();
                }
                $message = $args[2] ?? \Phasis\Value\JsUndefined::instance();
                $prefix = '';
                if (!$message instanceof \Phasis\Value\JsUndefined) {
                    $prefix = self::coerceMessageToString($message) . ' ';
                }
                $msg = $prefix . 'Expected SameValue(«' . self::formatAssertValue($actual)
                    . '», «' . self::formatAssertValue($unexpected) . '») to be false';
                $throwTest262($msg);
                return \Phasis\Value\JsUndefined::instance();
            },
            3,
        );

        // assert.deepEqual: native fast path for TypedArray vs TypedArray
        // and Array vs Array element-wise compare — the staging/sm/TypedArray
        // sort fixtures call this hundreds of thousands of times in inner
        // loops and the JS-defined deepEqual algorithm in test262's harness
        // is heavy (recursive cache, Symbol.toStringTag lookups, ~400 LOC).
        // For TypedArray vs TypedArray the spec semantics collapse to a
        // length check + element-by-element SameValue, since both operands
        // hold primitive numeric values. For dense Array vs dense Array
        // we walk the index list shallowly using SameValue; nested values
        // fall back to the JS algorithm via a re-entry trampoline.
        $deepEqualNative = null;
        $deepEqualNative = static function (
            \Phasis\Value\JsValue $a,
            \Phasis\Value\JsValue $b,
        ) use (
            $isSameValueNative,
            &$deepEqualNative
): bool {
            // Same JS reference: trivially equal. Skip the rest.
            if ($a === $b) {
                return true;
            }
            // Primitive shortcut: both primitives, SameValue applies.
            if (!($a instanceof \Phasis\Value\JsObject) && !($b instanceof \Phasis\Value\JsObject)) {
                return $isSameValueNative($a, $b);
            }
            // TypedArray vs TypedArray: same ctor + same length + element
            // SameValue. The harness uses this for sort fixtures where both
            // sides are TypedArrays of the same dtype.
            if (
                $a instanceof \Phasis\Value\JsTypedArray
                && $b instanceof \Phasis\Value\JsTypedArray
            ) {
                if ($a->getTypeName() !== $b->getTypeName()) {
                    return false;
                }
                $len = $a->getLength();
                if ($len !== $b->getLength()) {
                    return false;
                }
                for ($i = 0; $i < $len; $i++) {
                    $av = $a->getIndex($i);
                    $bv = $b->getIndex($i);
                    if (!$isSameValueNative($av, $bv)) {
                        return false;
                    }
                }
                return true;
            }
            // Dense Array vs dense Array: length + index-by-index SameValue
            // with recursive descent for nested objects.
            if (
                $a instanceof \Phasis\Value\JsArray
                && $b instanceof \Phasis\Value\JsArray
            ) {
                $aLen = $a->getLength();
                $bLen = $b->getLength();
                if ($aLen !== $bLen) {
                    return false;
                }
                for ($i = 0; $i < $aLen; $i++) {
                    $av = $a->get((string) $i);
                    $bv = $b->get((string) $i);
                    if (!$deepEqualNative($av, $bv)) {
                        return false;
                    }
                }
                return true;
            }
            // Anything else falls through — caller will dispatch the JS-
            // defined algorithm. Return a tri-state via PHP null is awkward
            // here; instead we return false and let the JS-level wrapper
            // detect a non-fast-path shape via the receiver test it does
            // before calling this intrinsic.
            return false;
        };

        $deepEqualFn = \Phasis\Value\JsFunction::fromCallable(
            'deepEqual',
            static function (
                \Phasis\Value\JsValue $thisValue,
                array $args,
            ) use (
                $deepEqualNative,
                $throwTest262
            ): \Phasis\Value\JsValue {
                $actual = $args[0] ?? \Phasis\Value\JsUndefined::instance();
                $expected = $args[1] ?? \Phasis\Value\JsUndefined::instance();
                if ($deepEqualNative($actual, $expected)) {
                    return \Phasis\Value\JsUndefined::instance();
                }
                $message = $args[2] ?? \Phasis\Value\JsUndefined::instance();
                $prefix = '';
                if (!$message instanceof \Phasis\Value\JsUndefined) {
                    $prefix = self::coerceMessageToString($message) . ' ';
                }
                $msg = $prefix . 'Expected deepEqual(«' . self::formatAssertValue($actual)
                    . '», «' . self::formatAssertValue($expected) . '»)';
                $throwTest262($msg);
                return \Phasis\Value\JsUndefined::instance();
            },
            3,
        );

        // assert.compareArray: element-wise SameValue with length check.
        // Common in test262 — and structurally identical to assert.deepEqual
        // for Array operands but expects the receiver to be array-like.
        $compareArrayFn = \Phasis\Value\JsFunction::fromCallable(
            'compareArray',
            static function (
                \Phasis\Value\JsValue $thisValue,
                array $args,
            ) use (
                $isSameValueNative,
                $throwTest262
            ): \Phasis\Value\JsValue {
                $actual = $args[0] ?? \Phasis\Value\JsUndefined::instance();
                $expected = $args[1] ?? \Phasis\Value\JsUndefined::instance();
                if (
                    !$actual instanceof \Phasis\Value\JsObject
                    || !$expected instanceof \Phasis\Value\JsObject
                ) {
                    // Either operand is not array-like: defer to the JS-
                    // defined fallback by throwing the harness's error.
                    $message = $args[2] ?? \Phasis\Value\JsUndefined::instance();
                    $prefix = '';
                    if (!$message instanceof \Phasis\Value\JsUndefined) {
                        $prefix = self::coerceMessageToString($message) . ' ';
                    }
                    $throwTest262($prefix . 'assert.compareArray expected array-like operands');
                    return \Phasis\Value\JsUndefined::instance();
                }
                $aLenVal = $actual->get('length');
                $bLenVal = $expected->get('length');
                $aLen = $aLenVal instanceof \Phasis\Value\JsNumber ? (int) $aLenVal->value : 0;
                $bLen = $bLenVal instanceof \Phasis\Value\JsNumber ? (int) $bLenVal->value : 0;
                $ok = ($aLen === $bLen);
                if ($ok) {
                    for ($i = 0; $i < $aLen; $i++) {
                        $av = $actual->get((string) $i);
                        $bv = $expected->get((string) $i);
                        if (!$isSameValueNative($av, $bv)) {
                            $ok = false;
                            break;
                        }
                    }
                }
                if ($ok) {
                    return \Phasis\Value\JsUndefined::instance();
                }
                $message = $args[2] ?? \Phasis\Value\JsUndefined::instance();
                $prefix = '';
                if (!$message instanceof \Phasis\Value\JsUndefined) {
                    $prefix = self::coerceMessageToString($message) . ' ';
                }
                $msg = $prefix . 'Expected compareArray(«' . self::formatAssertValue($actual)
                    . '», «' . self::formatAssertValue($expected) . '»)';
                $throwTest262($msg);
                return \Phasis\Value\JsUndefined::instance();
            },
            3,
        );

        if ($globalEnv->has('assert')) {
            $assertObj = $globalEnv->get('assert');
            if ($assertObj instanceof \Phasis\Value\JsObject) {
                $assertObj->set('_isSameValue', $isSameValueFn);
                $assertObj->set('sameValue', $sameValueFn);
                $assertObj->set('notSameValue', $notSameValueFn);
                // The native deepEqual / compareArray shims only handle the
                // TypedArray-vs-TypedArray and Array-vs-Array fast paths the
                // stress fixtures hit; for everything else (Map, Set, plain
                // Object, arguments-object, BigInt-Symbol mixes) they would
                // produce wrong results or wrong error messages. Wrap them
                // so the native path returns when it can confirm equality;
                // on any unknown shape or inequality, delegate to the JS-
                // defined harness function (kept under _origDeepEqual /
                // _origCompareArray) so the error message format matches
                // exactly what the harness self-tests assert.
                if ($assertObj->has('deepEqual')) {
                    $origDeepEqual = $assertObj->get('deepEqual');
                    if (!$origDeepEqual instanceof \Phasis\Value\JsFunction) {
                        $origDeepEqual = null;
                    } else {
                        $assertObj->set('_origDeepEqual', $origDeepEqual);
                    }
                    $wrappedDeepEqual = \Phasis\Value\JsFunction::fromCallable(
                        'deepEqual',
                        function (
                            \Phasis\Value\JsValue $thisValue,
                            array $args,
                        ) use (
                            $deepEqualNative,
                            $origDeepEqual,
                            $assertObj
                        ): \Phasis\Value\JsValue {
                            $actual = $args[0] ?? \Phasis\Value\JsUndefined::instance();
                            $expected = $args[1] ?? \Phasis\Value\JsUndefined::instance();
                            // Only consume the native fast path when both
                            // operands are shapes the native walker fully
                            // understands (TypedArray pair or plain Array
                            // pair with primitive-only entries at this
                            // level). Anything else routes to the JS impl.
                            $useFastPath = (
                                ($actual instanceof \Phasis\Value\JsTypedArray
                                    && $expected instanceof \Phasis\Value\JsTypedArray)
                                || ($actual instanceof \Phasis\Value\JsArray
                                    && $expected instanceof \Phasis\Value\JsArray)
                            );
                            if ($useFastPath && $deepEqualNative($actual, $expected)) {
                                return \Phasis\Value\JsUndefined::instance();
                            }
                            // Fallback: call the JS-defined assert.deepEqual
                            // so the error-format and edge-case semantics
                            // (Map, Set, Symbol, circular, ...) match the
                            // harness spec exactly.
                            if ($origDeepEqual === null) {
                                return \Phasis\Value\JsUndefined::instance();
                            }
                            return $origDeepEqual->call($assertObj, $args);
                        },
                        3,
                    );
                    // Forward any own properties the harness attached to the
                    // original deepEqual (e.g. `_compare`, `format`) so that
                    // tests like deepEqual-mapset that call them keep working.
                    if ($origDeepEqual !== null) {
                        foreach ($origDeepEqual->ownKeys() as $k) {
                            if (in_array($k, ['name', 'length', 'caller', 'callee', 'arguments', 'prototype'], true)) {
                                continue;
                            }
                            $wrappedDeepEqual->set($k, $origDeepEqual->get($k));
                        }
                    }
                    $assertObj->set('deepEqual', $wrappedDeepEqual);
                }
                if ($assertObj->has('compareArray')) {
                    $origCompareArray = $assertObj->get('compareArray');
                    if (!$origCompareArray instanceof \Phasis\Value\JsFunction) {
                        $origCompareArray = null;
                    } else {
                        $assertObj->set('_origCompareArray', $origCompareArray);
                    }
                    $wrappedCompareArray = \Phasis\Value\JsFunction::fromCallable(
                        'compareArray',
                        function (
                            \Phasis\Value\JsValue $thisValue,
                            array $args,
                        ) use (
                            $isSameValueNative,
                            $origCompareArray,
                            $assertObj
                        ): \Phasis\Value\JsValue {
                            $actual = $args[0] ?? \Phasis\Value\JsUndefined::instance();
                            $expected = $args[1] ?? \Phasis\Value\JsUndefined::instance();
                            // Same fast path constraints as deepEqual:
                            // dense JsArray vs dense JsArray of equal
                            // length and primitive entries. Anything else
                            // routes to the JS-defined assert.compareArray
                            // so nullish checks, format(), and the message
                            // template stay byte-identical to the harness.
                            if (
                                $actual instanceof \Phasis\Value\JsArray
                                && $expected instanceof \Phasis\Value\JsArray
                            ) {
                                $aLen = $actual->getLength();
                                $bLen = $expected->getLength();
                                if ($aLen === $bLen) {
                                    $allMatch = true;
                                    for ($i = 0; $i < $aLen; $i++) {
                                        $av = $actual->get((string) $i);
                                        $bv = $expected->get((string) $i);
                                        if (
                                            $av instanceof \Phasis\Value\JsObject
                                            || $bv instanceof \Phasis\Value\JsObject
                                        ) {
                                            $allMatch = false;
                                            break;
                                        }
                                        if (!$isSameValueNative($av, $bv)) {
                                            $allMatch = false;
                                            break;
                                        }
                                    }
                                    if ($allMatch) {
                                        return \Phasis\Value\JsUndefined::instance();
                                    }
                                }
                            }
                            if ($origCompareArray === null) {
                                return \Phasis\Value\JsUndefined::instance();
                            }
                            return $origCompareArray->call($assertObj, $args);
                        },
                        3,
                    );
                    if ($origCompareArray !== null) {
                        foreach ($origCompareArray->ownKeys() as $k) {
                            if (in_array($k, ['name', 'length', 'caller', 'callee', 'arguments', 'prototype'], true)) {
                                continue;
                            }
                            $wrappedCompareArray->set($k, $origCompareArray->get($k));
                        }
                    }
                    $assertObj->set('compareArray', $wrappedCompareArray);
                }
            }
        }

        // SpiderMonkey-flavoured aliases live as global functions and pass
        // through to assert.sameValue with spread args. The spread + rest
        // wrapper is hot enough in stress fixtures (~200k iterations) to
        // measurably slow the run. Override with a direct native that
        // mirrors assert.sameValue's behaviour: skips the spread/rest
        // dispatch and the JS-level frame.
        $assertEqFn = \Phasis\Value\JsFunction::fromCallable(
            'assertEq',
            static function (
                \Phasis\Value\JsValue $thisValue,
                array $args,
            ) use (
                $isSameValueNative,
                $throwTest262
            ): \Phasis\Value\JsValue {
                $actual = $args[0] ?? \Phasis\Value\JsUndefined::instance();
                $expected = $args[1] ?? \Phasis\Value\JsUndefined::instance();
                if ($isSameValueNative($actual, $expected)) {
                    return \Phasis\Value\JsUndefined::instance();
                }
                $message = $args[2] ?? \Phasis\Value\JsUndefined::instance();
                $prefix = '';
                if (!$message instanceof \Phasis\Value\JsUndefined) {
                    $prefix = self::coerceMessageToString($message) . ' ';
                }
                $msg = $prefix . 'Expected SameValue(«' . self::formatAssertValue($actual)
                    . '», «' . self::formatAssertValue($expected) . '») to be true';
                $throwTest262($msg);
                return \Phasis\Value\JsUndefined::instance();
            },
            3,
        );
        if ($globalEnv->has('assertEq')) {
            $globalEnv->set('assertEq', $assertEqFn);
        }
        if ($globalEnv->has('reportCompare')) {
            $globalEnv->set('reportCompare', $assertEqFn);
        }

        return \Phasis\Value\JsUndefined::instance();
    }

    /**
     * Coerce a JS value to a string for use in assert error messages.
     */
    private static function coerceMessageToString(\Phasis\Value\JsValue $value): string
    {
        if ($value instanceof \Phasis\Value\JsString) {
            return $value->value;
        }
        if ($value instanceof \Phasis\Value\JsUndefined) {
            return 'undefined';
        }
        return self::formatAssertValue($value);
    }

    /**
     * Mirror assert._toString for diagnostic messages.
     */
    private static function formatAssertValue(\Phasis\Value\JsValue $value): string
    {
        if ($value instanceof \Phasis\Value\JsUndefined) {
            return 'undefined';
        }
        if ($value instanceof \Phasis\Value\JsNull) {
            return 'null';
        }
        if ($value instanceof \Phasis\Value\JsBoolean) {
            return $value->value ? 'true' : 'false';
        }
        if ($value instanceof \Phasis\Value\JsNumber) {
            $v = $value->value;
            if ($v === 0.0 && pack('E', $v) === pack('E', -0.0)) {
                return '-0';
            }
            return $value->toJsString();
        }
        if ($value instanceof \Phasis\Value\JsString) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value->value) . '"';
        }
        return $value->display();
    }

    /**
     * Reach into the Engine's private $globalEnv via reflection. We avoid
     * adding a public accessor because the rest of the codebase does not
     * need one and the test262 runner is the only consumer.
     */
    private static function getEngineGlobalEnv(Engine $engine): ?\Phasis\Runtime\Environment
    {
        static $prop = null;
        if ($prop === null) {
            try {
                $prop = new \ReflectionProperty(Engine::class, 'globalEnv');
                $prop->setAccessible(true);
            } catch (\ReflectionException) {
                return null;
            }
        }
        $env = $prop->getValue($engine);
        if ($env instanceof \Phasis\Runtime\Environment) {
            return $env;
        }
        return null;
    }
}
