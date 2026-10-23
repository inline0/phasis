<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Node;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class FileSystemTest extends TestCase
{
    public function testOverlongPathsRetainTheHostError(): void
    {
        $engine = new Engine(node: true);
        $engine->setGlobal('longPath', sys_get_temp_dir() . str_repeat('/long-component', PHP_MAXPATHLEN));
        $result = $engine->eval(<<<'JS'
            JSON.stringify(['statSync', 'lstatSync', 'accessSync'].map(function (method) {
                try {
                    require('fs')[method](longPath);
                    return null;
                } catch (error) {
                    return [error.code, error.errno, error.syscall, error.path === longPath];
                }
            }));
            JS);
        $number = defined('SOCKET_ENAMETOOLONG') ? constant('SOCKET_ENAMETOOLONG') : (PHP_OS_FAMILY === 'Darwin' ? 63 : 36);
        self::assertSame([
            ['ENAMETOOLONG', -$number, 'stat', true],
            ['ENAMETOOLONG', -$number, 'lstat', true],
            ['ENAMETOOLONG', -$number, 'access', true],
        ], json_decode($result, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testFileSystemMatchesNodeOracle(): void
    {
        $engine = new Engine(node: true);
        $engine->setExecutionBudget(30);
        $engine->execFile(__DIR__ . '/fixtures/primitives/fs.mjs');
        self::assertNull($engine->eval('globalThis.__nodePrimitiveError'), $engine->getConsoleOutput());
        $result = $engine->eval('globalThis.__nodePrimitiveResult');
        self::assertIsString($result, 'All asynchronous filesystem operations must finish.');
        self::assertJsonStringEqualsJsonFile(__DIR__ . '/fixtures/primitives/fs.json', $result);
    }

    public function testEngineResetClosesOwnedFileDescriptors(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phasis-fs-cleanup-');
        self::assertIsString($path);
        try {
            $engine = new Engine(node: true);
            $engine->setGlobal('testPath', $path);
            $engine->eval('globalThis.testDescriptor = require("fs").openSync(testPath, "w+");');
            $owned = array_filter(get_resources('stream'), static fn ($stream): bool => (stream_get_meta_data($stream)['uri'] ?? null) === $path);
            self::assertCount(1, $owned);
            $descriptor = $engine->eval('testDescriptor');
            $engine->reset();
            foreach ($owned as $stream) {
                self::assertFalse(is_resource($stream));
            }
            $engine->setGlobal('testDescriptor', $descriptor);
            self::assertSame('EBADF', $engine->eval('try { require("fs").closeSync(testDescriptor); } catch (error) { error.code; }'));
        } finally {
            unlink($path);
        }
    }
}
