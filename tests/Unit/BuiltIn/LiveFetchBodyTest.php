<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class LiveFetchBodyTest extends TestCase
{
    public function testResponseRetainsALiveStreamAndWaitsForItsProducer(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
var result;
const transform = new TransformStream();
const response = new Response(transform.readable);
const writer = transform.writable.getWriter();
const identity = response.body === transform.readable;
const text = response.text();
(async () => {
    await writer.write(new TextEncoder().encode('hello'));
    await Promise.resolve();
    await writer.write(new TextEncoder().encode(' world'));
    await writer.close();
})();
text.then(value => { result = [identity, value, response.bodyUsed, response.body.locked]; });
JS);
        self::assertSame([true, 'hello world', true, true], $engine->eval('result'));
    }

    public function testResponseClonesTeeFutureChunksWithIndependentByteArrays(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
var result;
let controller;
const chunk = new Uint8Array([65]);
const response = new Response(new ReadableStream({start(value) {controller = value;}}));
const clone = response.clone();
Promise.all([response.body.getReader().read(), clone.body.getReader().read()]).then(([a, b]) => {
    a.value[0] = 66;
    result = [a.value === chunk, b.value === chunk, a.value[0], b.value[0]];
});
queueMicrotask(() => {controller.enqueue(chunk); controller.close();});
JS);
        self::assertSame([true, false, 66, 65], $engine->eval('result'));
    }

    public function testStreamFailuresRejectConsumersWithTheOriginalReason(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
var result;
const reason = new Error('producer failed');
const errorResponse = new Response(new ReadableStream({start(controller) {
    queueMicrotask(() => controller.error(reason));
}}));
const invalidResponse = new Response(new ReadableStream({start(controller) {
    controller.enqueue('not bytes'); controller.close();
}}));
Promise.all([
    errorResponse.text().catch(error => error === reason),
    invalidResponse.arrayBuffer().catch(error => error.name),
]).then(value => { result = value; });
JS);
        self::assertSame([true, 'TypeError'], $engine->eval('result'));
    }

    public function testRequestConstructionTransfersALiveBodyAndCloneKeepsBothBranches(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
var result;
let controller;
const source = new Request('https://example.com', {
    method: 'POST', body: new ReadableStream({start(value) {controller = value;}}), duplex: 'half'
});
const request = new Request(source);
const clone = request.clone();
Promise.all([request.text(), clone.text()]).then(values => {
    result = [source.bodyUsed, source.body.locked, ...values];
});
queueMicrotask(() => {controller.enqueue(new TextEncoder().encode('payload')); controller.close();});
JS);
        self::assertSame([true, true, 'payload', 'payload'], $engine->eval('result'));
    }
}
