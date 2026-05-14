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
