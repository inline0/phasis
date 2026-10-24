<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Node;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class BroadcastChannelTest extends TestCase
{
    public function testMessagesAreQueuedClonedAndScopedToOtherSameNameChannels(): void
    {
        $engine = new Engine(node: true);
        $engine->eval(<<<'JS'
const sender = new BroadcastChannel('example');
const first = new BroadcastChannel('example');
const second = new BroadcastChannel('example');
const other = new BroadcastChannel('other');
const events = [];
sender.onmessage = () => events.push('self');
other.onmessage = () => events.push('other');
first.addEventListener('message', event => {
  events.push('first:' + event.data.value + ':' + (event.target === first));
  event.data.value = 'receiver mutation';
}, {once: true});
second.onmessage = function (event) {
  events.push('second:' + event.data.value + ':' + (this === second));
};
const data = {value: 'original'};
sender.postMessage(data);
data.value = 'sender mutation';
events.push('synchronous');
queueMicrotask(() => events.push('microtask'));
JS);
        self::assertSame(
            ['synchronous', 'microtask', 'first:original:true', 'second:original:true'],
            $engine->eval('events'),
        );
        $engine->eval('for (const channel of [sender, first, second, other]) channel.close();');
        self::assertSame(0, $engine->getEventLoop()->pendingTaskCount());
    }

    public function testCloseCancelsDeliveryAndValidationUsesRealDomExceptions(): void
    {
        $engine = new Engine(node: true);
        $engine->eval(<<<'JS'
const sender = new BroadcastChannel('closed');
const recipient = new BroadcastChannel('closed');
let delivered = false;
recipient.onmessage = () => { delivered = true; };
sender.postMessage('cancel');
recipient.close();
const errors = [];
try { sender.postMessage(() => {}); } catch (error) { errors.push(error.name, error instanceof DOMException); }
sender.close();
try { sender.postMessage('no'); } catch (error) { errors.push(error.name, error instanceof DOMException); }
const handles = [sender.ref() === sender, sender.unref() === sender];
JS);
        self::assertFalse($engine->eval('delivered'));
        self::assertSame(['DataCloneError', true, 'InvalidStateError', true], $engine->eval('errors'));
        self::assertSame([true, true], $engine->eval('handles'));
        self::assertSame(0, $engine->getEventLoop()->pendingTaskCount());
    }

    public function testReplacingOnmessagePreservesItsListenerPosition(): void
    {
        $engine = new Engine(node: true);
        $engine->eval(<<<'JS'
const sender = new BroadcastChannel('handlers');
const recipient = new BroadcastChannel('handlers');
const order = [];
recipient.onmessage = () => order.push('old');
recipient.addEventListener('message', () => order.push('listener'));
recipient.onmessage = () => order.push('replacement');
sender.postMessage(1);
JS);
        self::assertSame(['replacement', 'listener'], $engine->eval('order'));
        $engine->eval(<<<'JS'
recipient.onmessage = null;
recipient.onmessage = () => order.push('new position');
sender.postMessage(2);
JS);
        self::assertSame(['replacement', 'listener', 'listener', 'new position'], $engine->eval('order'));
        $engine->eval('sender.close(); recipient.close();');
    }
}
