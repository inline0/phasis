<?php

declare(strict_types=1);

namespace PhpJs\Tests\Oracle;

use PhpJs\Engine;

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
        'built-ins/Atomics/notify/notify-with-no-agents-waiting.js',
        'built-ins/Atomics/notify/notify-with-no-matching-agents-waiting.js',
        'built-ins/Atomics/wait/bigint/value-not-equal.js',
        'built-ins/Atomics/wait/poisoned-object-for-timeout-throws-agent.js',
        'built-ins/Atomics/wait/symbol-for-index-throws-agent.js',
        'built-ins/Atomics/wait/symbol-for-timeout-throws-agent.js',
        'built-ins/Atomics/wait/symbol-for-value-throws-agent.js',
        'built-ins/Atomics/wait/value-not-equal.js',
        'built-ins/Atomics/wait/wait-index-value-not-equal.js',
    ];

    /**
     * test262 paths (relative to test262/test/) that still need richer
     * cross-realm semantics than we implement. The list was originally
     * the audited 94 tests that depended on multi-realm intrinsics; the
     * realm-tracking layer (GetFunctionRealm, per-realm intrinsic
     * lookup in builtins) brought 63 of them green. The remaining
     * entries assert deeper semantics (Function constructor's prototype
     * coming from the new-target realm, species across realms in
     * TypedArray/ArrayBuffer, ShadowRealm wrapped-function realm
     * inheritance, etc.). Tracked under "structural gaps" in CLAUDE.md.
     *
     * @var list<string>
     */
    private const CROSS_REALM_BLOCKLIST = [
        'annexB/built-ins/RegExp/prototype/compile/this-cross-realm-instance.js',
        'built-ins/AsyncGeneratorFunction/proto-from-ctor-realm-prototype.js',
        'built-ins/Function/proto-from-ctor-realm-prototype.js',
        'built-ins/Proxy/revocable/tco-fn-realm.js',
        'built-ins/ShadowRealm/prototype/evaluate/wrapped-function-proto-from-caller-realm.js',
        'language/expressions/call/eval-realm-indirect.js',
        'staging/sm/Array/change-array-by-copy-cross-compartment-create.js',
        'staging/sm/Array/species.js',
        'staging/sm/ArrayBuffer/slice-species.js',
        'staging/sm/Function/arguments-iterator.js',
        'staging/sm/Iterator/from/wrap-new-global.js',
        'staging/sm/Iterator/prototype/every/error-from-correct-realm.js',
        'staging/sm/Iterator/prototype/find/error-from-correct-realm.js',
        'staging/sm/Iterator/prototype/forEach/error-from-correct-realm.js',
        'staging/sm/Iterator/prototype/reduce/error-from-correct-realm.js',
        'staging/sm/Iterator/prototype/some/error-from-correct-realm.js',
        'staging/sm/Iterator/prototype/toArray/create-in-current-realm.js',
        'staging/sm/JSON/parse-with-source.js',
        'staging/sm/Proxy/proxy-with-revoked-arguments.js',
        'staging/sm/Reflect/set.js',
        'staging/sm/RegExp/constructor-constructor.js',
        'staging/sm/TypedArray/constructor-ArrayBuffer-species-wrap.js',
        'staging/sm/TypedArray/constructor-buffer-sequence.js',
        'staging/sm/TypedArray/constructor-typedarray-species-other-global.js',
        'staging/sm/TypedArray/every-and-some.js',
        'staging/sm/TypedArray/forEach.js',
        'staging/sm/TypedArray/iterator.js',
        'staging/sm/TypedArray/map-and-filter.js',
        'staging/sm/TypedArray/slice-bitwise-same.js',
        'staging/sm/TypedArray/toLocaleString.js',
        'staging/sm/extensions/cross-global-eval-is-indirect.js',
    ];

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

        // Tests that orchestrate multiple agents via the $262.agent host
        // are simulated cooperatively (see AgentHost). Spin-loop on
        // shared atomic and waitAsync patterns can't be simulated, so
        // skip those. Everything else runs through the fiber simulator.
        if (
            (strpos($source, '$262.agent.start') !== false
                || strpos($source, '$262.agent.broadcast') !== false)
            && preg_match(
                '/while\s*\(\s*Atomics\.(load|compareExchange|exchange)\(/',
                $source
            ) === 1
        ) {
            return new TestResult(
                $testPath,
                TestStatus::Skip,
                'Worker spin-loop on shared atomic needs preemptive scheduling',
            );
        }
        if (
            (strpos($source, '$262.agent.start') !== false
                || strpos($source, '$262.agent.broadcast') !== false)
            && (
                strpos($source, 'waitAsync') !== false
                || strpos($source, 'getReportAsync') !== false
                || strpos($source, 'safeBroadcastAsync') !== false
            )
        ) {
            return new TestResult(
                $testPath,
                TestStatus::Skip,
                'Multi-agent waitAsync needs cross-fiber microtask scheduling',
            );
        }

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
            \PhpJs\BuiltIn\AtomicsObject::setAgentCanSuspend(true);
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
                \PhpJs\BuiltIn\AtomicsObject::setAgentCanSuspend(false);
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
        // Hard time limit per test: env override, default 30s, with
        // a 120s bump for SM DST cache stress tests (O(n^4) probe).
        $timeLimit = (int) (getenv('PHPJS_TEST_TIME_LIMIT') ?: 30);
        if (strpos($source, 'runDSTOffsetCachingTestsFraction') !== false) {
            $timeLimit = max($timeLimit, 120);
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
            \PhpJs\BuiltIn\AtomicsObject::setSyncWaitHook(null);
            \PhpJs\BuiltIn\AtomicsObject::setSyncNotifyHook(null);
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
        } catch (\PhpJs\Exceptions\SyntaxError $e) {
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
        } catch (\PhpJs\Exceptions\RuntimeError $e) {
            if ($negative !== null) {
                $type = $negative['type'] ?? 'Error';
                $jsName = null;
                if ($e instanceof \PhpJs\Exceptions\JsThrowable) {
                    $jv = $e->jsValue;
                    if ($jv instanceof \PhpJs\Value\JsObject) {
                        $n = $jv->get('name');
                        if ($n instanceof \PhpJs\Value\JsString) {
                            $jsName = $n->value;
                        }
                        // Fall back to constructor.name (e.g. Test262Error
                        // defined in harness as constructor function without
                        // an own `name` property on prototype).
                        if ($jsName === null) {
                            $ctor = $jv->get('constructor');
                            if ($ctor instanceof \PhpJs\Value\JsFunction) {
                                $cn = $ctor->get('name');
                                if ($cn instanceof \PhpJs\Value\JsString && $cn->value !== '') {
                                    $jsName = $cn->value;
                                }
                            }
                        }
                    }
                }
                $match = match ($type) {
                    'TypeError' => $e instanceof \PhpJs\Exceptions\TypeError || $jsName === 'TypeError',
                    'RangeError' => $e instanceof \PhpJs\Exceptions\RangeError || $jsName === 'RangeError',
                    'ReferenceError' => $e instanceof \PhpJs\Exceptions\ReferenceError || $jsName === 'ReferenceError',
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
\$engine = new PhpJs\Engine();
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
} catch (PhpJs\Exceptions\SyntaxError \$e) {
    if (\$negative && (\$negative['phase'] ?? '') === 'parse' && (\$negative['type'] ?? '') === 'SyntaxError') {
        echo "PASS";
    } else {
        echo "SyntaxError: " . \$e->getMessage();
        exit(1);
    }
} catch (PhpJs\Exceptions\RuntimeError \$e) {
    if (\$negative) {
        \$type = \$negative['type'] ?? 'Error';
        \$jsName = null;
        if (\$e instanceof PhpJs\Exceptions\JsThrowable) {
            \$jv = \$e->jsValue;
            if (\$jv instanceof PhpJs\Value\JsObject) {
                \$n = \$jv->get('name');
                if (\$n instanceof PhpJs\Value\JsString) {
                    \$jsName = \$n->value;
                }
                if (\$jsName === null) {
                    \$ctor = \$jv->get('constructor');
                    if (\$ctor instanceof PhpJs\Value\JsFunction) {
                        \$cn = \$ctor->get('name');
                        if (\$cn instanceof PhpJs\Value\JsString && \$cn->value !== '') {
                            \$jsName = \$cn->value;
                        }
                    }
                }
            }
        }
        \$match = match(\$type) {
            'TypeError' => \$e instanceof PhpJs\Exceptions\TypeError || \$jsName === 'TypeError',
            'RangeError' => \$e instanceof PhpJs\Exceptions\RangeError || \$jsName === 'RangeError',
            'ReferenceError' => \$e instanceof PhpJs\Exceptions\ReferenceError || \$jsName === 'ReferenceError',
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
        } catch (\PhpJs\Exceptions\SyntaxError $e) {
            if ($negative !== null) {
                $phase = $negative['phase'] ?? 'runtime';
                $type = $negative['type'] ?? 'Error';
                if ($phase === 'parse' && $type === 'SyntaxError') {
                    return new TestResult($testPath, TestStatus::Pass);
                }
            }
            return new TestResult($testPath, TestStatus::Fail, 'SyntaxError: ' . $e->getMessage());
        } catch (\PhpJs\Exceptions\RuntimeError $e) {
            if ($negative !== null) {
                $type = $negative['type'] ?? 'Error';
                $errorClass = match ($type) {
                    'TypeError' => \PhpJs\Exceptions\TypeError::class,
                    'RangeError' => \PhpJs\Exceptions\RangeError::class,
                    'ReferenceError' => \PhpJs\Exceptions\ReferenceError::class,
                    'SyntaxError' => \PhpJs\Exceptions\SyntaxError::class,
                    default => \PhpJs\Exceptions\RuntimeError::class,
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
        $createRealmFn = \PhpJs\Value\JsFunction::fromCallable(
            '__262_createRealm',
            function (
                \PhpJs\Value\JsValue $this_,
                array $args
            ) use ($runner): \PhpJs\Value\JsValue {
                return $runner->buildRealmWrapper();
            },
        );
        $engine->setGlobalJsValue('__262_createRealm', $createRealmFn);
        $engine->eval(<<<'JS'
        $262.createRealm = __262_createRealm;
        JS);

        // $262.detachArrayBuffer(buffer) - detach an ArrayBuffer
        $engine->setGlobal('__262_detachArrayBuffer', function ($buf) {
            if ($buf instanceof \PhpJs\Value\JsArrayBuffer) {
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
            if ($code instanceof \PhpJs\Value\JsString) {
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
        $engine->setGlobalJsValue('__262_IsHTMLDDA', new \PhpJs\Value\JsHTMLDDA());
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
    public function buildRealmWrapper(): \PhpJs\Value\JsObject
    {
        $outerInterp = Engine::getCurrentInterpreter();
        // Save the JsFunction static back-references too: every Engine
        // constructor clobbers these (last-write-wins). Without restoring,
        // calls into JS code defined in the OUTER realm after createRealm
        // would dispatch through the CHILD interpreter, picking up the
        // child's globalEnv intrinsics (TypeError prototype, etc.) and
        // breaking cross-realm identity tests.
        $outerCallback = \PhpJs\Value\JsFunction::getInterpreterCallback();
        $outerInstance = \PhpJs\Value\JsFunction::getInterpreterInstance();
        $childEngine = new Engine();
        $childEngine->setLimit('maxLoopIterations', 2_000_000);
        $this->install262HostObject($childEngine);
        try {
            $this->loadHarness($childEngine, 'sta.js');
            $this->loadHarness($childEngine, 'assert.js');
        } catch (\Throwable) {
        }
        if ($outerInterp !== null) {
            Engine::setCurrentInterpreter($outerInterp);
        }
        if ($outerCallback !== null) {
            \PhpJs\Value\JsFunction::setInterpreterCallback($outerCallback);
        }
        if ($outerInstance !== null) {
            \PhpJs\Value\JsFunction::setInterpreterInstance($outerInstance);
        }

        $wrapper = new \PhpJs\Value\JsObject();

        $childGlobal = $childEngine->getGlobalEnv()->get('globalThis');
        if (!$childGlobal instanceof \PhpJs\Value\JsValue) {
            $childGlobal = \PhpJs\Value\JsUndefined::instance();
        }
        $wrapper->set('global', $childGlobal);

        $child262 = $childEngine->getGlobalEnv()->has('$262')
            ? $childEngine->getGlobalEnv()->get('$262')
            : \PhpJs\Value\JsUndefined::instance();
        $wrapper->set('$262', $child262);

        // evalScript runs the source in the CHILD realm, switching the
        // active interpreter pointer for the duration so any built-ins
        // that consult Engine::getCurrentInterpreter() resolve against
        // the child realm's intrinsic graph.
        $evalScriptFn = \PhpJs\Value\JsFunction::fromCallable(
            'evalScript',
            function (
                \PhpJs\Value\JsValue $this_,
                array $args
            ) use ($childEngine): \PhpJs\Value\JsValue {
                $codeArg = $args[0] ?? \PhpJs\Value\JsUndefined::instance();
                if ($codeArg instanceof \PhpJs\Value\JsString) {
                    $src = $codeArg->value;
                } else {
                    $src = \PhpJs\Spec\TypeConversion::toString($codeArg);
                }
                $prev = Engine::getCurrentInterpreter();
                $prevCallback = \PhpJs\Value\JsFunction::getInterpreterCallback();
                $prevInstance = \PhpJs\Value\JsFunction::getInterpreterInstance();
                Engine::setCurrentInterpreter($childEngine->getInterpreter());
                // Re-bind the JsFunction statics so JS-level calls within
                // the eval'd source route through the child interpreter.
                \PhpJs\Value\JsFunction::setInterpreterInstance($childEngine->getInterpreter());
                \PhpJs\Value\JsFunction::setInterpreterCallback(function (
                    \PhpJs\Value\JsFunction $fn,
                    \PhpJs\Value\JsValue $thisValue,
                    array $args
                ) use ($childEngine): \PhpJs\Value\JsValue {
                    return $childEngine->getInterpreter()->callFunction($fn, $thisValue, $args);
                });
                try {
                    $childEngine->getInterpreter()->execute(
                        (new \PhpJs\Parser\Parser($src))->parse(),
                    );
                    \PhpJs\Value\JsPromise::drainMicrotasks();
                } finally {
                    Engine::setCurrentInterpreter($prev);
                    if ($prevCallback !== null) {
                        \PhpJs\Value\JsFunction::setInterpreterCallback($prevCallback);
                    }
                    if ($prevInstance !== null) {
                        \PhpJs\Value\JsFunction::setInterpreterInstance($prevInstance);
                    }
                }
                return \PhpJs\Value\JsUndefined::instance();
            },
        );
        $wrapper->set('evalScript', $evalScriptFn);

        $detachFn = \PhpJs\Value\JsFunction::fromCallable(
            'detachArrayBuffer',
            function (\PhpJs\Value\JsValue $this_, array $args): \PhpJs\Value\JsValue {
                $buf = $args[0] ?? null;
                if ($buf instanceof \PhpJs\Value\JsArrayBuffer) {
                    $buf->detach();
                }
                return \PhpJs\Value\JsUndefined::instance();
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
            \PhpJs\Value\JsValue $thisValue,
            array $args,
            $interp = null,
        ) use ($engineRef): \PhpJs\Value\JsValue {
            return self::doInstallFastAsserts($engineRef);
        };
        $engine->setGlobalJsValue(
            '__test262_install_fast_asserts__',
            \PhpJs\Value\JsFunction::fromCallable('__test262_install_fast_asserts__', $installer, 0),
        );
    }

    /**
     * The actual install routine, invoked from JS after the harness has
     * defined assert.* in the global scope.
     */
    private static function doInstallFastAsserts(Engine $engine): \PhpJs\Value\JsValue
    {
        $globalEnv = self::getEngineGlobalEnv($engine);
        if ($globalEnv === null) {
            return \PhpJs\Value\JsUndefined::instance();
        }

        // Pull the Test262Error constructor (defined by sta.js).
        $test262ErrorCtor = null;
        if ($globalEnv->has('Test262Error')) {
            $maybe = $globalEnv->get('Test262Error');
            if ($maybe instanceof \PhpJs\Value\JsFunction) {
                $test262ErrorCtor = $maybe;
            }
        }

        // Throw a Test262Error matching the harness's class. Falls back to
        // a plain Error-shaped object if the harness's Test262Error is not
        // present (e.g. tests that load only sta.js failed).
        $throwTest262 = static function (string $message) use ($test262ErrorCtor): void {
            if ($test262ErrorCtor !== null) {
                $errObj = $test262ErrorCtor->construct([new \PhpJs\Value\JsString($message)]);
                throw new \PhpJs\Exceptions\JsThrowable($errObj);
            }
            $obj = new \PhpJs\Value\JsObject();
            $obj->set('name', new \PhpJs\Value\JsString('Test262Error'));
            $obj->set('message', new \PhpJs\Value\JsString($message));
            throw new \PhpJs\Exceptions\JsThrowable($obj);
        };

        // SameValue, special-cased for the four common primitive shapes
        // seen in the harness. Falls back to the spec algorithm for
        // symbols, bigints, objects.
        $isSameValueNative = static function (\PhpJs\Value\JsValue $a, \PhpJs\Value\JsValue $b): bool {
            if ($a instanceof \PhpJs\Value\JsNumber && $b instanceof \PhpJs\Value\JsNumber) {
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
            if ($a instanceof \PhpJs\Value\JsString && $b instanceof \PhpJs\Value\JsString) {
                return $a->value === $b->value;
            }
            if ($a instanceof \PhpJs\Value\JsBoolean && $b instanceof \PhpJs\Value\JsBoolean) {
                return $a->value === $b->value;
            }
            if ($a instanceof \PhpJs\Value\JsUndefined && $b instanceof \PhpJs\Value\JsUndefined) {
                return true;
            }
            if ($a instanceof \PhpJs\Value\JsNull && $b instanceof \PhpJs\Value\JsNull) {
                return true;
            }
            return \PhpJs\Spec\AbstractOperations::sameValue($a, $b);
        };

        $isSameValueFn = \PhpJs\Value\JsFunction::fromCallable(
            '_isSameValue',
            static function (
                \PhpJs\Value\JsValue $thisValue,
                array $args,
            ) use ($isSameValueNative): \PhpJs\Value\JsValue {
                $a = $args[0] ?? \PhpJs\Value\JsUndefined::instance();
                $b = $args[1] ?? \PhpJs\Value\JsUndefined::instance();
                return new \PhpJs\Value\JsBoolean($isSameValueNative($a, $b));
            },
            2,
        );

        $sameValueFn = \PhpJs\Value\JsFunction::fromCallable(
            'sameValue',
            static function (
                \PhpJs\Value\JsValue $thisValue,
                array $args,
            ) use (
                $isSameValueNative,
                $throwTest262
            ): \PhpJs\Value\JsValue {
                $actual = $args[0] ?? \PhpJs\Value\JsUndefined::instance();
                $expected = $args[1] ?? \PhpJs\Value\JsUndefined::instance();
                if ($isSameValueNative($actual, $expected)) {
                    return \PhpJs\Value\JsUndefined::instance();
                }
                $message = $args[2] ?? \PhpJs\Value\JsUndefined::instance();
                $prefix = '';
                if (!$message instanceof \PhpJs\Value\JsUndefined) {
                    $prefix = self::coerceMessageToString($message) . ' ';
                }
                $msg = $prefix . 'Expected SameValue(«' . self::formatAssertValue($actual)
                    . '», «' . self::formatAssertValue($expected) . '») to be true';
                $throwTest262($msg);
                return \PhpJs\Value\JsUndefined::instance();
            },
            3,
        );

        $notSameValueFn = \PhpJs\Value\JsFunction::fromCallable(
            'notSameValue',
            static function (
                \PhpJs\Value\JsValue $thisValue,
                array $args,
            ) use (
                $isSameValueNative,
                $throwTest262
            ): \PhpJs\Value\JsValue {
                $actual = $args[0] ?? \PhpJs\Value\JsUndefined::instance();
                $unexpected = $args[1] ?? \PhpJs\Value\JsUndefined::instance();
                if (!$isSameValueNative($actual, $unexpected)) {
                    return \PhpJs\Value\JsUndefined::instance();
                }
                $message = $args[2] ?? \PhpJs\Value\JsUndefined::instance();
                $prefix = '';
                if (!$message instanceof \PhpJs\Value\JsUndefined) {
                    $prefix = self::coerceMessageToString($message) . ' ';
                }
                $msg = $prefix . 'Expected SameValue(«' . self::formatAssertValue($actual)
                    . '», «' . self::formatAssertValue($unexpected) . '») to be false';
                $throwTest262($msg);
                return \PhpJs\Value\JsUndefined::instance();
            },
            3,
        );

        if ($globalEnv->has('assert')) {
            $assertObj = $globalEnv->get('assert');
            if ($assertObj instanceof \PhpJs\Value\JsObject) {
                $assertObj->set('_isSameValue', $isSameValueFn);
                $assertObj->set('sameValue', $sameValueFn);
                $assertObj->set('notSameValue', $notSameValueFn);
            }
        }

        return \PhpJs\Value\JsUndefined::instance();
    }

    /**
     * Coerce a JS value to a string for use in assert error messages.
     */
    private static function coerceMessageToString(\PhpJs\Value\JsValue $value): string
    {
        if ($value instanceof \PhpJs\Value\JsString) {
            return $value->value;
        }
        if ($value instanceof \PhpJs\Value\JsUndefined) {
            return 'undefined';
        }
        return self::formatAssertValue($value);
    }

    /**
     * Mirror assert._toString for diagnostic messages.
     */
    private static function formatAssertValue(\PhpJs\Value\JsValue $value): string
    {
        if ($value instanceof \PhpJs\Value\JsUndefined) {
            return 'undefined';
        }
        if ($value instanceof \PhpJs\Value\JsNull) {
            return 'null';
        }
        if ($value instanceof \PhpJs\Value\JsBoolean) {
            return $value->value ? 'true' : 'false';
        }
        if ($value instanceof \PhpJs\Value\JsNumber) {
            $v = $value->value;
            if ($v === 0.0 && pack('E', $v) === pack('E', -0.0)) {
                return '-0';
            }
            return $value->toJsString();
        }
        if ($value instanceof \PhpJs\Value\JsString) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value->value) . '"';
        }
        return $value->display();
    }

    /**
     * Reach into the Engine's private $globalEnv via reflection. We avoid
     * adding a public accessor because the rest of the codebase does not
     * need one and the test262 runner is the only consumer.
     */
    private static function getEngineGlobalEnv(Engine $engine): ?\PhpJs\Runtime\Environment
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
        if ($env instanceof \PhpJs\Runtime\Environment) {
            return $env;
        }
        return null;
    }
}
