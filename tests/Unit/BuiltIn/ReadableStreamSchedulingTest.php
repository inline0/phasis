<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class ReadableStreamSchedulingTest extends TestCase
{
    public function testPullCompletionLetsReadersObserveEachReusedChunk(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const observations = [];
(async () => {
    for (const type of ['default', 'bytes']) {
        const log = [];
        let count = 0;
        const scratch = new Uint8Array(4);
        const stream = new ReadableStream({
            ...type === 'bytes' ? { type: 'bytes' } : {},
            pull(controller) {
                const value = ++count;
                log.push('pull:' + value);
                const data = type === 'bytes' ? new Uint8Array(4) : scratch;
                data.fill(value);
                controller.enqueue(data);
                if (count === 2) controller.close();
            },
        });
        log.push('constructed');
        const reader = stream.getReader();
        const reading = (async () => {
            while (true) {
                const { done, value } = await reader.read();
                if (done) {
                    log.push('done');
                    break;
                }
                log.push('read:' + value[0]);
            }
        })();
        queueMicrotask(() => log.push('checkpoint'));
        await reading;
        observations.push(log);
    }
})();
JS);
        $expected = ['constructed', 'pull:1', 'checkpoint', 'read:1', 'pull:2', 'read:2', 'done'];
        self::assertSame([$expected, $expected], $engine->eval('observations'));
    }

    public function testStartSettlesInOneMicrotaskForBothPromiseStates(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const startObservations = [];
(async () => {
    for (const pending of [false, true]) {
        const log = [];
        const stream = new ReadableStream({
            start() {
                log.push('start');
                return pending ? Promise.resolve().then(() => log.push('start resolved')) : undefined;
            },
            pull(controller) {
                log.push('pull');
                controller.close();
            },
        });
        log.push('constructed');
        queueMicrotask(() => log.push('checkpoint'));
        await stream.getReader().read();
        log.push('read');
        startObservations.push(log);
    }
})();
JS);
        self::assertSame([
            ['start', 'constructed', 'pull', 'checkpoint', 'read'],
            ['start', 'constructed', 'start resolved', 'checkpoint', 'pull', 'read'],
        ], $engine->eval('startObservations'));
    }
}
