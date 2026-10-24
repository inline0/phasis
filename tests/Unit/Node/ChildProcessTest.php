<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Node;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class ChildProcessTest extends TestCase
{
    public function testNativeChildrenAndPipesMatchNodeOracle(): void
    {
        $engine = new Engine(node: true);
        $engine->setExecutionBudget(30);
        try {
            $engine->execFile(__DIR__ . '/fixtures/primitives/child-process.mjs');
            self::assertNull($engine->eval('globalThis.__nodePrimitiveError'), $engine->getConsoleOutput());
            $result = $engine->eval('globalThis.__nodePrimitiveResult');
            self::assertIsString($result, 'All child processes must exit and close their pipes.');
            self::assertJsonStringEqualsJsonFile(__DIR__ . '/fixtures/primitives/child-process.json', $result);
        } finally {
            $engine->getNodeRuntime()?->close();
        }
    }

    public function testResetKillsAndReapsUnreferencedChildren(): void
    {
        $engine = new Engine(node: true);
        try {
            $pid = $engine->eval(<<<'JS'
globalThis.child = require('child_process').spawn('/bin/sleep', ['60'], { stdio: 'ignore' });
child.unref(); child.pid;
JS);
            self::assertIsInt($pid);
            self::assertTrue(posix_kill($pid, 0));
            $engine->reset();
            self::assertFalse(posix_kill($pid, 0), 'Reset must reap the actual native process, not merely forget its handle.');
        } finally {
            $engine->getNodeRuntime()?->close();
        }
    }
}
