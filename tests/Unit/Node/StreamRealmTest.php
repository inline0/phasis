<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Node;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class StreamRealmTest extends TestCase
{
    public function testStreamControllersAndReadersKeepTheirRealmAfterVmCreationAndGlobalReplacement(): void
    {
        $engine = new Engine(node: true);
        try {
            self::assertSame(array_fill(0, 11, true), $engine->eval(<<<'JS'
const names = ['ReadableStream', 'ReadableStreamDefaultController', 'ReadableStreamDefaultReader',
  'ReadableByteStreamController', 'ReadableStreamBYOBReader', 'ReadableStreamBYOBRequest',
  'WritableStream', 'WritableStreamDefaultController', 'WritableStreamDefaultWriter',
  'TransformStream', 'TransformStreamDefaultController'];
const constructors = Object.fromEntries(names.map(name => [name, globalThis[name]]));
require('vm').runInNewContext('0');
for (const name of names) globalThis[name] = function replaced() { throw new Error(name); };
const results = [];
const check = (value, name) => results.push(Object.getPrototypeOf(value) === constructors[name].prototype);
let controller;
const readable = new constructors.ReadableStream({start(value) { controller = value; }});
check(readable, 'ReadableStream');
check(controller, 'ReadableStreamDefaultController');
check(readable.getReader(), 'ReadableStreamDefaultReader');
let byteController;
const bytes = new constructors.ReadableStream({type: 'bytes', start(value) { byteController = value; }});
check(byteController, 'ReadableByteStreamController');
const reader = bytes.getReader({mode: 'byob'});
check(reader, 'ReadableStreamBYOBReader');
reader.read(new Uint8Array(1));
const request = byteController.byobRequest;
check(request, 'ReadableStreamBYOBRequest');
request.respond(1);
byteController.close();
let writableController;
const writable = new constructors.WritableStream({start(value) { writableController = value; }});
check(writable, 'WritableStream');
check(writableController, 'WritableStreamDefaultController');
check(writable.getWriter(), 'WritableStreamDefaultWriter');
let transformController;
const transform = new constructors.TransformStream({start(value) { transformController = value; }});
check(transform, 'TransformStream');
check(transformController, 'TransformStreamDefaultController');
results;
JS));
        } finally {
            $engine->getNodeRuntime()?->close();
        }
    }

    public function testOuterControllerPrototypeReplacementStillInterceptsEnqueueAfterVmCreation(): void
    {
        $engine = new Engine(node: true);
        try {
            self::assertSame(['intercepted', 'intercepted'], $engine->eval(<<<'JS'
const prototype = ReadableStreamDefaultController.prototype;
require('vm').runInNewContext('0');
const errors = [];
prototype.enqueue = function () { throw new Error('intercepted'); };
for (let i = 0; i < 2; i++) {
  new ReadableStream({start(controller) {
    try { controller.enqueue(1); } catch (error) { errors.push(error.message); }
    controller.close();
  }});
}
errors;
JS));
        } finally {
            $engine->getNodeRuntime()?->close();
        }
    }
}
