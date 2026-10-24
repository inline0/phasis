<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class MessageEventTest extends TestCase
{
    public function testMessageEventPreservesDataAndDispatchesAsAnEvent(): void
    {
        $engine = new Engine();
        self::assertSame([true, true, 'x', 'origin', '123', null, true, true], $engine->eval(<<<'JS'
const data = {name: 'x'};
const event = new MessageEvent('message', {data, origin: 'origin', lastEventId: 123});
const target = new EventTarget();
let observed;
target.addEventListener('message', value => {observed = value;});
target.dispatchEvent(event);
[
    event instanceof Event, event instanceof MessageEvent, event.data.name,
    event.origin, event.lastEventId, event.source, Object.isFrozen(event.ports), observed === event
];
JS));
    }

    public function testDefaultsAndIncompatibleReceiverChecks(): void
    {
        $engine = new Engine();
        self::assertSame([null, '', '', 0, false, 'TypeError', 'TypeError'], $engine->eval(<<<'JS'
const event = new MessageEvent('message');
const values = [event.data, event.origin, event.lastEventId, event.ports.length, event.isTrusted];
try { Object.getOwnPropertyDescriptor(MessageEvent.prototype, 'data').get.call(new Event('x')); }
catch (error) { values.push(error.name); }
try { new MessageEvent('message', {source: {}}); } catch (error) { values.push(error.name); }
values;
JS));
    }
}
