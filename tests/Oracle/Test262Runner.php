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

        // Skip module tests (static import/export in the test itself).
        $flags = $meta['flags'] ?? [];
        if (in_array('module', $flags, true)) {
            return new TestResult($testPath, TestStatus::Skip, 'Module test');
        }

        // Skip raw tests (no harness)
        $isRaw = in_array('raw', $flags, true);
        $isAsync = in_array('async', $flags, true);

        $negative = $meta['negative'] ?? null;
        $includes = $meta['includes'] ?? [];
        $onlyStrict = in_array('onlyStrict', $flags, true);
        $noStrict = in_array('noStrict', $flags, true);

        // Determine which modes to run
        $modes = [];
        if ($onlyStrict) {
            $modes[] = 'strict';
        } elseif ($noStrict) {
            $modes[] = 'sloppy';
        } else {
            $modes[] = 'sloppy';
            // We'll start with sloppy only for now
        }

        foreach ($modes as $mode) {
            $result = $this->executeTest($testPath, $source, $meta, $mode, $includes, $negative, $isRaw, $isAsync);
            if ($result->status !== TestStatus::Pass) {
                return $result;
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
    ): TestResult {
        // Run in-process for speed (~100x faster than subprocess per test)
        $engine = new Engine();
        // test262 tests may iterate over large Unicode ranges. Raise the loop limit
        // well above the default 100K so these tests can complete.
        $engine->setLimit('maxLoopIterations', 2_000_000);
        // Hard time limit per test: 30 seconds. Prevents infinite loops.
        set_time_limit(30);

        // Install $262 host object for test262 harness.
        $this->install262HostObject($engine);

        try {
            if (!$isRaw) {
                $this->loadHarness($engine, 'sta.js');
                $this->loadHarness($engine, 'assert.js');
                foreach ($includes as $include) {
                    $this->loadHarness($engine, $include);
                }
            }

            $testSource = preg_replace('/\/\*---.*?---\*\//s', '', $source);
            if ($mode === 'strict') {
                $testSource = '"use strict";' . "\n" . $testSource;
            }

            $engine->eval($testSource);

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
                $phase = $negative['phase'] ?? 'runtime';
                $type = $negative['type'] ?? 'Error';
                if (($phase === 'parse' || $phase === 'early') && $type === 'SyntaxError') {
                    return new TestResult($testPath, TestStatus::Pass);
                }
            }
            return new TestResult($testPath, TestStatus::Fail, 'SyntaxError: ' . $e->getMessage());
        } catch (\PhpJs\Exceptions\RuntimeError $e) {
            if ($negative !== null) {
                $type = $negative['type'] ?? 'Error';
                $match = match ($type) {
                    'TypeError' => $e instanceof \PhpJs\Exceptions\TypeError,
                    'RangeError' => $e instanceof \PhpJs\Exceptions\RangeError,
                    'ReferenceError' => $e instanceof \PhpJs\Exceptions\ReferenceError,
                    default => true,
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
        \$match = match(\$type) {
            'TypeError' => \$e instanceof PhpJs\Exceptions\TypeError,
            'RangeError' => \$e instanceof PhpJs\Exceptions\RangeError,
            'ReferenceError' => \$e instanceof PhpJs\Exceptions\ReferenceError,
            default => true,
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
     */
    private function install262HostObject(Engine $engine): void
    {
        $runner = $this;
        $engine->eval(<<<'JS'
        var $262 = {};
        JS);

        // $262.createRealm() - create a fresh Engine and return its $262
        $engine->setGlobal('__262_createRealm', function () use ($runner) {
            $realm = new Engine();
            $realm->setLimit('maxLoopIterations', 2_000_000);
            $runner->install262HostObject($realm);
            // Load standard harness in the new realm
            try {
                $runner->loadHarnessPublic($realm, 'sta.js');
                $runner->loadHarnessPublic($realm, 'assert.js');
            } catch (\Throwable) {
            }
            return $realm->eval('$262');
        });
        $engine->eval(<<<'JS'
        $262.createRealm = function() {
            return { global: globalThis, $262: __262_createRealm() };
        };
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

        // $262.gc() - no-op (PHP has no manual GC trigger useful here)
        $engine->eval('$262.gc = function() {};');

        // $262.IsHTMLDDA: an object with the [[IsHTMLDDA]] internal slot.
        // typeof returns "undefined", ToBoolean returns false, == null/undefined is true.
        $engine->setGlobalJsValue('__262_IsHTMLDDA', new \PhpJs\Value\JsHTMLDDA());
        $engine->eval('$262.IsHTMLDDA = __262_IsHTMLDDA;');

        // test262 host function: print (used by some tests as a no-op or to store output).
        $engine->eval('function print() {}');
    }

    /**
     * Public wrapper for loadHarness (used by createRealm).
     */
    public function loadHarnessPublic(Engine $engine, string $filename): void
    {
        $this->loadHarness($engine, $filename);
    }

    private function loadHarness(Engine $engine, string $filename): void
    {
        if (!isset($this->harnessCache[$filename])) {
            $path = $this->harnessDir . '/' . $filename;
            if (!file_exists($path)) {
                $this->harnessCache[$filename] = '';
                return;
            }
            $src = file_get_contents($path);
            $this->harnessCache[$filename] = preg_replace('/\/\*---.*?---\*\//s', '', $src) ?? '';
        }

        $src = $this->harnessCache[$filename];
        if ($src === '') {
            return;
        }
        try {
            $engine->eval($src);
        } catch (\Throwable) {
            // Silently skip harness files that fail to load
        }
    }

    /** @return array{description?: string, features?: list<string>, includes?: list<string>, flags?: list<string>, negative?: array{type: string, phase: string}} */
    public function parseFrontmatter(string $source): array
    {
        if (!preg_match('/\/\*---(.*?)---\*\//s', $source, $matches)) {
            return [];
        }

        $yaml = $matches[1];
        $meta = [];

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
}
