<?php

declare(strict_types=1);

namespace Phasis\Tests\Oracle;

use PHPUnit\Framework\TestCase;

final class Test262JsonTest extends TestCase
{
    public function testLoneSurrogateFailureStillProducesAJsonReport(): void
    {
        $directory = sys_get_temp_dir() . '/phasis-json-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $fixture = $directory . '/surrogate.js';
        $manifest = $directory . '/files.txt';
        file_put_contents($fixture, "/*---\nflags: [noStrict]\n---*/\nthrow '\\uD800';\n");
        file_put_contents($manifest, $fixture . "\n");
        try {
            $process = proc_open(
                [PHP_BINARY, dirname(__DIR__, 2) . '/bin/test262', '--json', '--jobs', '1', '--files-from', $manifest],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), (string) $stderr);
            $report = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $report['fail']);
            self::assertSame($fixture, $report['failures'][0]['path']);
            self::assertNotSame('', $report['failures'][0]['message']);
        } finally {
            unlink($fixture);
            unlink($manifest);
            rmdir($directory);
        }
    }
}
