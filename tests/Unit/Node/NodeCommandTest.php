<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Node;

use Phasis\Tests\Unit\Module\TempProject;
use PHPUnit\Framework\TestCase;

final class NodeCommandTest extends TestCase
{
    use TempProject;

    public function testEntryFileReceivesArgumentsAndUsesTheCommonJsMainModule(): void
    {
        $root = $this->project([
            'value.cjs' => 'module.exports = "loaded";',
            'entry.cjs' => <<<'JS'
console.log('%s:%s:%s', require('./value.cjs'), require.main === module, process.argv[2]);
console.error('stderr');
setTimeout(() => { console.log('timer'); process.exitCode = 7; }, 1);
JS,
        ]);
        [$code, $stdout, $stderr] = $this->command(['--node', $root . '/entry.cjs', '--node']);
        self::assertSame(7, $code);
        self::assertSame("loaded:true:--node\ntimer\n", $stdout);
        self::assertSame("stderr\n", $stderr);
    }

    public function testEvaluationSupportsExplicitExitAndDefaultModeRemainsIsolated(): void
    {
        [$code, $stdout, $stderr] = $this->command([
            '--node', '-e', 'console.log(process.argv[1]); process.exit(9); console.log("unreachable");', 'argument',
        ]);
        self::assertSame([9, "argument\n", ''], [$code, $stdout, $stderr]);
        self::assertSame([0, "undefined\n", ''], $this->command(['-e', 'console.log(typeof process)']));
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string, string}
     */
    private function command(array $arguments): array
    {
        $root = dirname(__DIR__, 3);
        $process = proc_open(
            [PHP_BINARY, $root . '/bin/phasis', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            [...getenv(), 'XDEBUG_MODE' => 'off'],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = $stderr = '';
        $deadline = microtime(true) + 30;
        try {
            do {
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $stdout .= stream_get_contents($pipes[1]);
                    $stderr .= stream_get_contents($pipes[2]);
                    return [$status['exitcode'], $stdout, $stderr];
                }
                usleep(1000);
            } while (microtime(true) < $deadline);
            self::fail('CLI subprocess exceeded 30 seconds.');
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }
}
