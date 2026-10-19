<?php

declare(strict_types=1);

namespace Phasis\Tests\Popular;

use Phasis\Tests\Remix\CompatibilityRunner;

use PHPUnit\Framework\TestCase;

final class RemixCompatibilityTest extends TestCase
{
    public function testPinnedPackageDoesNotRegressAgainstNode(): void
    {
        if (!is_file(dirname(__DIR__) . '/Remix/node_modules/remix/package.json')) {
            self::markTestSkipped('Install the Remix fixture with npm ci --ignore-scripts in tests/Remix.');
        }
        $report = (new CompatibilityRunner())->run();
        self::assertCount(99, $report['inventory']['runtime']);
        self::assertCount(112, $report['results']);
        self::assertSame([], CompatibilityRunner::regressions($report), json_encode($report, JSON_INVALID_UTF8_SUBSTITUTE));
        foreach ($report['results'] as $result) {
            if ($result['kind'] === 'behavior') {
                self::assertNotSame('blocked', $result['status'], 'A behavior fixture must work in Node: ' . $result['id']);
            }
        }
    }
}
