<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Node;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class Http2Test extends TestCase
{
    public function testMultiplexedBodiesHeaderCompressionTrailersAndPingMatchNode(): void
    {
        $expected = json_decode((string) file_get_contents(__DIR__ . '/fixtures/http2.json'), true, 512, JSON_THROW_ON_ERROR);
        $engine = new Engine(node: true);
        $engine->setLimit('maxLoopIterations', 1_000_000);
        try {
            $engine->execFile(__DIR__ . '/fixtures/http2.cjs');
            $actual = json_decode((string) $engine->eval('JSON.stringify(http2Result)'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($expected, $actual);
        } finally {
            $engine->getNodeRuntime()?->close();
        }
    }
}
