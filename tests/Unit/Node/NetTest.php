<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Node;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class NetTest extends TestCase
{
    public function testRefusedConnectionReportsTheHostSocketError(): void
    {
        $engine = new Engine(node: true);
        $engine->eval(<<<'JS'
const net = require('net');
const result = [];
const server = net.createServer().listen(0, '127.0.0.1', () => {
  const port = server.address().port;
  server.close(() => {
    const client = net.connect(port, '127.0.0.1');
    client.on('error', error => result.push(error.code, error.errno, error.syscall));
  });
});
JS);
        self::assertSame(['ECONNREFUSED', -SOCKET_ECONNREFUSED, 'connect'], $engine->eval('result'));
        $engine->reset();
    }

    public function testRealTcpStreamingAndCloseLifecycle(): void
    {
        $engine = new Engine(node: true);
        $engine->setExecutionBudget(10);
        $engine->eval(<<<'JS'
const net = require('net');
const result = [];
const server = net.createServer(socket => {
  result.push(socket.remoteAddress === '127.0.0.1');
  socket.on('data', data => socket.write(data));
  socket.on('end', () => socket.end());
});
server.listen(0, '127.0.0.1', () => {
  const client = net.connect(server.address().port, '127.0.0.1', () => {
    client.setNoDelay();
    client.write(Buffer.from([0, 255, 128, 65]));
  });
  const chunks = [];
  client.on('data', data => { chunks.push(data); client.end(); });
  client.on('end', () => result.push(Buffer.concat(chunks).toString('hex')));
  client.on('close', () => server.close(() => result.push('closed')));
});
JS);
        self::assertSame([true, '00ff8041', 'closed'], $engine->eval('result'));
        $engine->reset();
    }

    public function testListenErrorsAndReferenceLifetime(): void
    {
        $engine = new Engine(node: true);
        $engine->setExecutionBudget(10);
        $engine->eval(<<<'JS'
const net = require('net');
const result = [];
const first = net.createServer().listen(0, '127.0.0.1', () => {
  const second = net.createServer();
  second.on('error', error => { result.push(error.code); first.close(); });
  second.listen(first.address().port, '127.0.0.1');
});
JS);
        self::assertSame(['EADDRINUSE'], $engine->eval('result'));
        $engine->eval('globalThis.unreferenced = require("net").createServer().listen(0, "127.0.0.1").unref();');
        self::assertTrue($engine->eval('unreferenced.listening'));
        $engine->reset();
    }
}
