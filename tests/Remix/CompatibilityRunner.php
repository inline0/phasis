<?php

declare(strict_types=1);

namespace Phasis\Tests\Remix;

final class CompatibilityRunner
{
    /** @var resource|null */
    private $activeWorker = null;

    public function __construct()
    {
        register_shutdown_function($this->stopWorker(...));
    }

    public function run(): array
    {
        $oracleFile = __DIR__ . '/oracle.json';
        $oracle = json_decode((string) file_get_contents($oracleFile), true, flags: JSON_THROW_ON_ERROR);
        $installedFile = __DIR__ . '/node_modules/remix/package.json';
        if (!is_file($installedFile)) {
            throw new \RuntimeException('Install the pinned Remix fixture: cd tests/Remix && npm ci --ignore-scripts');
        }
        $installed = json_decode((string) file_get_contents($installedFile), true, flags: JSON_THROW_ON_ERROR);
        if ($installed['version'] !== $oracle['package']['version']) {
            throw new \RuntimeException('Installed Remix version does not match the pinned oracle');
        }
        $runtime = [];
        foreach ($installed['exports'] as $name => $entry) {
            $target = is_string($entry) ? $entry : ($entry['default'] ?? null);
            if (is_string($target) && str_ends_with($target, '.js')) {
                $runtime[] = $name === '.' ? 'remix' : 'remix' . substr($name, 1);
            }
        }
        sort($runtime);
        if ($runtime !== $oracle['inventory']['runtime']) {
            throw new \RuntimeException('Remix runtime export inventory changed; regenerate and review the Node oracle');
        }
        $counts = ['pass' => 0, 'fail' => 0, 'blocked' => 0];
        $results = [];
        foreach ($oracle['probes'] as $probe) {
            if ($probe['kind'] === 'behavior') {
                $hash = hash_file('sha256', __DIR__ . '/' . $probe['target']);
                if ($hash !== $probe['sourceSha256']) {
                    throw new \RuntimeException('Behavior fixture changed without regenerating its oracle: ' . $probe['id']);
                }
            }
            if ($probe['oracle']['status'] !== 'ok') {
                $result = ['id' => $probe['id'], 'kind' => $probe['kind'], 'status' => 'blocked', 'oracle' => $probe['oracle']];
            } else {
                $actual = $this->runWorker($probe['kind'], $probe['target']);
                $equal = $actual['status'] === 'ok' && $actual['value'] === $probe['oracle']['value'];
                $result = [
                    'id' => $probe['id'], 'kind' => $probe['kind'], 'status' => $equal ? 'pass' : 'fail',
                    'expected' => $probe['oracle']['value'], 'actual' => $actual,
                ];
            }
            $counts[$result['status']]++;
            $results[] = $result;
        }
        return [
            'package' => $oracle['package'],
            'phpVersion' => PHP_VERSION,
            'oracleNodeVersion' => $oracle['nodeVersion'],
            'oracleSha256' => hash_file('sha256', $oracleFile),
            'inventory' => $oracle['inventory'],
            'counts' => $counts,
            'allProbesPassed' => $counts['fail'] === 0 && $counts['blocked'] === 0,
            'coverageNote' => 'Import checks verify export names and types, not every API. Behavior probes cover only the listed scenarios. '
                . 'Browser hydration, external services and the complete upstream test suite are not covered.',
            'results' => $results,
        ];
    }

    public static function regressions(array $report): array
    {
        $baseline = json_decode((string) file_get_contents(__DIR__ . '/baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        if ($report['oracleSha256'] !== $baseline['oracleSha256']) {
            throw new \RuntimeException('Oracle changed without reviewing the Remix baseline');
        }
        $passed = [];
        foreach ($report['results'] as $result) {
            if ($result['status'] === 'pass') {
                $passed[] = $result['id'];
            }
        }
        return array_values(array_diff($baseline['requiredPasses'], $passed));
    }

    private function runWorker(string $kind, string $target): array
    {
        $pipes = [];
        $this->activeWorker = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=256M', '-d', 'pcre.backtrack_limit=200000', __DIR__ . '/php-worker.php', $kind, $target],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($this->activeWorker)) {
            throw new \RuntimeException('Could not start the Remix probe worker');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $started = microtime(true);
        try {
            do {
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                $running = proc_get_status($this->activeWorker)['running'];
                if ($running && microtime(true) - $started > 10.0) {
                    return ['status' => 'error', 'name' => 'WorkerTimeout', 'message' => 'Remix probe exceeded 10 seconds'];
                }
                if ($running) {
                    usleep(10000);
                }
            } while ($running);
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            foreach (array_reverse(explode("\n", $stdout)) as $line) {
                if (str_starts_with($line, 'PHASIS_REMIX_RESULT:')) {
                    return json_decode(substr($line, strlen('PHASIS_REMIX_RESULT:')), true, flags: JSON_THROW_ON_ERROR);
                }
            }
            return [
                'status' => 'error', 'name' => 'WorkerFailure',
                'message' => str_replace(__DIR__, '<fixture>', trim(substr($stderr . $stdout, 0, 2000))),
            ];
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->stopWorker();
        }
    }

    public function stopWorker(): void
    {
        if (!is_resource($this->activeWorker)) {
            return;
        }
        if (proc_get_status($this->activeWorker)['running']) {
            proc_terminate($this->activeWorker);
            $deadline = microtime(true) + 0.5;
            while (proc_get_status($this->activeWorker)['running'] && microtime(true) < $deadline) {
                usleep(10000);
            }
            if (proc_get_status($this->activeWorker)['running']) {
                proc_terminate($this->activeWorker, 9);
            }
        }
        proc_close($this->activeWorker);
        $this->activeWorker = null;
    }
}
