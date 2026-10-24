<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Phasis\Engine;
use Phasis\Node\NodeOptions;

$options = getopt('', ['file:', 'grep:', 'output:', 'budget:', 'timeout-scale:']);
if (in_array('--timeout-scale', $argv, true) && !array_key_exists('timeout-scale', $options)) {
    throw new InvalidArgumentException('--timeout-scale requires a numeric value.');
}
$scaleEnvironment = getenv('PHASIS_FRAMEWORK_TIMEOUT_SCALE');
$rawScale = $options['timeout-scale'] ?? ($scaleEnvironment === false ? '1' : $scaleEnvironment);
if (!is_string($rawScale) || !is_numeric($rawScale) || !is_finite((float) $rawScale) || (float) $rawScale <= 0 || (float) $rawScale > 100) {
    throw new InvalidArgumentException('--timeout-scale must be a finite positive number at most 100.');
}
$timeoutScale = (float) $rawScale;
$root = __DIR__ . '/upstream';
$pinned = json_decode((string) file_get_contents(__DIR__ . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$files = [];
$manifest = [];
foreach ($pinned['files'] as $entry) {
    if (isset($options['file']) && $entry['path'] !== $options['file']) {
        continue;
    }
    $file = $root . '/' . $entry['path'];
    if (!is_file($file)) {
        throw new RuntimeException('Missing upstream Express test: ' . $file);
    }
    if (hash_file('sha256', $file) !== $entry['sha256']) {
        throw new RuntimeException('Upstream test checksum changed: ' . $entry['path']);
    }
    $files[] = $file;
    $manifest[] = $entry;
}
if ($files === []) {
    throw new RuntimeException('The requested file is not part of the pinned Express suite.');
}
$metadata = json_decode((string) file_get_contents(__DIR__ . '/upstream.json'), true, 512, JSON_THROW_ON_ERROR);
$engine = new Engine();
$engine->enableNode(new NodeOptions(cwd: $root, argv: ['phasis', __FILE__]));
$engine->setLimit('maxLoopIterations', 1_000_000);
if (isset($options['budget'])) {
    $engine->setExecutionBudget((float) $options['budget']);
}
$result = null;
$currentTest = null;
$checkpoint = function () use (&$result, &$currentTest, $options): void {
    if (!isset($options['output'])) {
        return;
    }
    $partial = ($result ?? []) + ['currentTest' => $currentTest];
    file_put_contents((string) $options['output'] . '.partial.json', json_encode(
        $partial,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
    ) . "\n");
};
$engine->setGlobal('__expressCurrent', function (string $title, string $file) use (&$currentTest, $checkpoint): void {
    $currentTest = ['title' => $title, 'file' => $file];
    $checkpoint();
});
$engine->setGlobal('__expressFinished', function (string $serialized) use (&$result, $engine, $checkpoint): void {
    $result = json_decode($serialized, true, 512, JSON_THROW_ON_ERROR);
    $checkpoint();
    $engine->getNodeRuntime()?->close();
});
$engine->setGlobal('__expressProgress', function (string $serialized) use (&$result, $checkpoint): void {
    $result = json_decode($serialized, true, 512, JSON_THROW_ON_ERROR);
    $checkpoint();
});
$engine->setGlobal('__expressFiles', $files);
$engine->setGlobal('__expressRunner', __DIR__ . '/runner.cjs');
$engine->setGlobal('__expressOptions', [
    'timeoutScale' => $timeoutScale,
    'grep' => $options['grep'] ?? null,
    'expectedTests' => array_sum(array_column($manifest, 'tests')),
    'expectedCases' => array_merge(...array_column($manifest, 'cases')),
    'fileNames' => array_combine($files, array_column($manifest, 'path')),
]);
try {
    $engine->eval('require(__expressRunner)(__expressFiles, __expressOptions);');
} catch (Throwable $error) {
    $result ??= [
        'complete' => false,
        'pass' => 0,
        'fail' => 0,
        'pending' => 0,
        'tests' => [],
        'loadErrors' => [],
        'asynchronousErrors' => [],
    ];
    $result['complete'] = false;
    $result['loadErrors'][] = ['error' => ['name' => $error::class, 'message' => $error->getMessage()]];
    $result['interruptedTest'] = $currentTest;
}
if ($result === null) {
    $result = json_decode((string) $engine->eval('JSON.stringify(globalThis.__expressReport || {})'), true, 512, JSON_THROW_ON_ERROR);
    $result['complete'] = false;
    $result['completionError'] = 'The upstream runner did not signal completion.';
}
$engine->getNodeRuntime()?->close();
$result = [
    'runtime' => 'phasis',
    'php' => PHP_VERSION,
    'source' => $metadata,
    'filtered' => isset($options['file']) || isset($options['grep']),
    'manifest' => $manifest,
    'timeoutScale' => $timeoutScale,
] + $result;
$serialized = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
if (isset($options['output'])) {
    file_put_contents((string) $options['output'], $serialized);
} else {
    echo $serialized;
}
exit(($result['complete'] ?? false) && ($result['fail'] ?? 0) === 0 && ($result['pending'] ?? 0) === 0
    && ($result['loadErrors'] ?? []) === [] && ($result['asynchronousErrors'] ?? []) === [] ? 0 : 1);
