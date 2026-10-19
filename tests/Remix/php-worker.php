<?php

declare(strict_types=1);

use Phasis\Engine;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$kind = $argv[1] ?? '';
$target = $argv[2] ?? '';
$engine = new Engine();
$engine->setExecutionBudget(5.0);
$encoded = json_encode($target, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
try {
    if ($kind === 'import') {
        $source = "import * as namespace from {$encoded};\n"
            . 'globalThis.__remixJson = JSON.stringify(Object.keys(namespace).sort().map(key => [key, typeof namespace[key]]));';
    } elseif ($kind === 'behavior' && preg_match('~^cases/[a-z0-9-]+\.mjs$~', $target)) {
        $encoded = json_encode('./' . $target, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $source = "import { run } from {$encoded};\n"
            . 'globalThis.__remixSettled = false;'
            . 'Promise.resolve(run()).then(value => {'
            . 'globalThis.__remixJson = JSON.stringify(value); globalThis.__remixSettled = true;'
            . '}, error => { globalThis.__remixRejected = String(error); globalThis.__remixSettled = true; });';
    } else {
        throw new RuntimeException('Unknown Remix probe');
    }
    $engine->evalAsModule($source, __DIR__ . '/__probe.mjs');
    $engine->runEventLoop();
    if ($kind === 'behavior') {
        if ($engine->eval('globalThis.__remixSettled') !== true) {
            throw new RuntimeException('Behavior promise did not settle');
        }
        $rejected = $engine->eval('globalThis.__remixRejected');
        if ($rejected !== null) {
            throw new RuntimeException((string) $rejected);
        }
    }
    $json = $engine->eval('globalThis.__remixJson');
    if (!is_string($json)) {
        throw new RuntimeException('Probe did not produce JSON');
    }
    $result = ['status' => 'ok', 'value' => json_decode($json, true, flags: JSON_THROW_ON_ERROR)];
} catch (Throwable $error) {
    $result = [
        'status' => 'error',
        'name' => get_class($error),
        'message' => str_replace(__DIR__, '<fixture>', $error->getMessage()),
    ];
}
echo 'PHASIS_REMIX_RESULT:', json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
