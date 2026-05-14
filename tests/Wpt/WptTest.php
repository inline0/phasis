<?php

declare(strict_types=1);

namespace Phasis\Tests\Wpt;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PHPUnit gate over Web Platform Tests fixtures.
 *
 * Auto-discovers every `tests/Wpt/fixtures/<category>/*.any.js` and
 * runs it through Phasis. Each fixture is one PHPUnit test that
 * passes iff every subtest inside the fixture passes.
 *
 * The fixtures themselves are sourced from web-platform-tests/wpt
 * with the spec subtree-of-origin noted at the top of each file.
 * When a fixture is updated, regenerate it from the upstream source.
 */
final class WptTest extends TestCase
{
    /** PID of the WPT test server when fetch-bearing fixtures need it. */
    private static ?int $serverPid = null;

    public static function setUpBeforeClass(): void
    {
        // Start the fetch test server once for the whole suite so HTTP-
        // touching fixtures can resolve `http://127.0.0.1:8765/...` URLs.
        // Tests that don't need fetch just ignore it.
        $root = dirname(__DIR__, 2);
        $logFile = sys_get_temp_dir() . '/phasis-wpt-srv-phpunit.log';
        $cmd = sprintf(
            '/usr/bin/env php -S 127.0.0.1:8765 -t %s %s > %s 2>&1 & echo $!',
            escapeshellarg($root . '/tests/Wpt'),
            escapeshellarg($root . '/tests/Wpt/fetch-server.php'),
            escapeshellarg($logFile),
        );
        self::$serverPid = (int) trim((string) shell_exec($cmd));
        usleep(400_000);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverPid !== null && self::$serverPid > 0) {
            @posix_kill(self::$serverPid, SIGTERM);
            self::$serverPid = null;
        }
    }

    /** @return iterable<string, array{string}> */
    public static function fixtureProvider(): iterable
    {
        $categories = WptRunner::discoverFixtures();
        foreach ($categories as $category => $files) {
            foreach ($files as $file) {
                $label = $category . '/' . basename($file);
                yield $label => [$file];
            }
        }
    }

    #[DataProvider('fixtureProvider')]
    public function testFixture(string $path): void
    {
        $runner = new WptRunner();
        $results = $runner->runFile($path);

        $this->assertNotEmpty(
            $results,
            "Fixture {$path} produced no subtest results — harness boot failure?"
        );

        $failures = array_filter(
            $results,
            static fn (array $r): bool => $r['status'] !== 'PASS'
        );

        if ($failures !== []) {
            $lines = ["{$path}: " . count($failures) . " of " . count($results) . " subtests failed"];
            foreach ($failures as $f) {
                $lines[] = "  - [{$f['status']}] {$f['name']}";
                if ($f['message'] !== '') {
                    $lines[] = "      " . $f['message'];
                }
            }
            $this->fail(implode("\n", $lines));
        }

        $this->assertCount(count($results), $results); // record the count
    }
}
