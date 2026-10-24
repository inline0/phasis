<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Node;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class AbortRealmTest extends TestCase
{
    public function testSignalsRetainIntrinsicEventsAndReasonsAcrossVmRealmsAndGlobalReplacement(): void
    {
        $engine = new Engine(node: true);
        try {
            $engine->eval(<<<'JS'
const OriginalEvent = Event;
const OriginalException = DOMException;
const early = new AbortController();
require('node:vm').runInNewContext('globalThis.Event = class Event {}; globalThis.DOMException = class DOMException {};');
const late = new AbortController();
const events = [];
early.signal.addEventListener('abort', event => events.push(event instanceof OriginalEvent));
late.signal.addEventListener('abort', event => events.push(event instanceof OriginalEvent));
globalThis.Event = class ReplacementEvent {};
globalThis.DOMException = class ReplacementException {};
early.abort();
late.abort('explicit reason');
const immediate = AbortSignal.abort();
const timeout = AbortSignal.timeout(0);
timeout.addEventListener('abort', event => events.push(event instanceof OriginalEvent));
const reasons = [early.signal.reason instanceof OriginalException, early.signal.reason.name,
    late.signal.reason, immediate.reason instanceof OriginalException];
JS);
            self::assertSame([true, true, true], $engine->eval('events'));
            self::assertSame([true, 'AbortError', 'explicit reason', true], $engine->eval('reasons'));
            self::assertSame([true, 'TimeoutError'], $engine->eval(
                '[timeout.reason instanceof OriginalException, timeout.reason.name]',
            ));
        } finally {
            $engine->getNodeRuntime()?->close();
        }
    }
}
