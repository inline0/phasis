<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Node;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use Phasis\Value\JsUndefined;
use PHPUnit\Framework\TestCase;

final class TimersTest extends TestCase
{
    public function testTimeoutRefreshPreservesIdentityAndMicrotaskOrdering(): void
    {
        $engine = new Engine(node: true);
        try {
            $engine->eval(<<<'JS'
const results = [];
let count = 0;
const timer = setTimeout(function (value) {
  results.push(this === timer, value, ++count);
  Promise.resolve().then(() => results.push('micro'));
  if (count === 1) this.refresh();
}, 1, 'argument');
const id = Number(timer);
results.push(timer.unref() === timer, timer.hasRef(), timer.ref() === timer, timer.hasRef());
const cancelled = setTimeout(() => results.push('cancelled'), 1000);
clearTimeout(Number(cancelled));
JS);
            self::assertSame(
                [true, false, true, true, true, 'argument', 1, 'micro', true, 'argument', 2, 'micro'],
                $engine->eval('results'),
            );
            self::assertTrue($engine->eval('id === Number(timer)'));
            self::assertSame(0, $engine->pendingTaskCount());
        } finally {
            $engine->close();
        }
    }

    public function testTopLevelAwaitCanResumeFromARealTimer(): void
    {
        $engine = new Engine(node: true);
        try {
            $engine->evalAsModule(<<<'JS'
import { setTimeout } from 'node:timers/promises';
globalThis.completedTimer = await setTimeout(1, 'done');
JS);
            self::assertSame('done', $engine->eval('completedTimer'));
        } finally {
            $engine->close();
        }
    }

    public function testReadyIoCannotStarveTimersAndCloseCancelsUnreferencedWork(): void
    {
        $engine = new Engine(node: true);
        $loop = $engine->getEventLoop();
        $polls = 0;
        $timerRan = false;
        $loop->addIoDriver('busy-test', static function (int $timeout) use (&$polls): bool {
            $polls++;
            return true;
        }, static fn (): bool => true);
        $loop->setTimeout(JsFunction::fromCallable('stop', static function () use ($loop, &$timerRan): JsUndefined {
            $timerRan = true;
            $loop->removeIoDriver('busy-test');
            return JsUndefined::instance();
        }), 0);
        try {
            $engine->runEventLoop();
            self::assertTrue($timerRan);
            self::assertGreaterThan(0, $polls);
            self::assertLessThan(100, $polls, 'A busy I/O source must yield to due timers.');
            $engine->eval('setTimeout(() => { throw new Error("unreferenced"); }, 60000).unref()');
            self::assertSame(1, $engine->pendingTaskCount());
            $engine->close();
            $engine->close();
            self::assertSame(0, $engine->pendingTaskCount());
        } finally {
            $engine->close();
        }
    }
}
