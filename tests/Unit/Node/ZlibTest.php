<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Node;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class ZlibTest extends TestCase
{
    public function testCodecsAndStreamsMatchNodeOracle(): void
    {
        $engine = new Engine(node: true);
        $engine->execFile(__DIR__ . '/fixtures/primitives/zlib.mjs');
        self::assertNull($engine->eval('globalThis.__nodePrimitiveError'), $engine->getConsoleOutput());
        $result = $engine->eval('globalThis.__nodePrimitiveResult');
        self::assertIsString($result, 'Every codec stream must finish.');
        self::assertJsonStringEqualsJsonFile(__DIR__ . '/fixtures/primitives/zlib.json', $result);
    }

    public function testGzipOutputIsReadableByNativePhpZlib(): void
    {
        $engine = new Engine(node: true);
        $hex = $engine->eval('require("zlib").gzipSync(Buffer.from([0, 127, 128, 255])).toString("hex")');
        self::assertIsString($hex);
        $data = hex2bin($hex);
        self::assertIsString($data);
        self::assertSame("\0\x7f\x80\xff", gzdecode($data));
    }
}
