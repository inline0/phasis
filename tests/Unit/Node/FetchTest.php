<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Node;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class FetchTest extends TestCase
{
    public function testFetchUsesRealmIntrinsicsWhenGlobalConstructorsAreReplaced(): void
    {
        $engine = new Engine(node: true);
        $engine->setExecutionBudget(15);
        try {
            $engine->eval(<<<'JS'
const NativeResponse = Response;
globalThis.Request = class { constructor() { throw new Error('global Request called'); } };
globalThis.Response = class { constructor() { throw new Error('global Response called'); } };
const http = require('node:http');
const result = [];
const server = http.createServer((req, res) => res.end('intrinsic'));
server.listen(0, '127.0.0.1', async () => {
  try {
    const response = await fetch('http://127.0.0.1:' + server.address().port);
    result.push(response instanceof NativeResponse, await response.text());
  } catch (error) { result.push(error.message); }
  finally { server.close(); }
});
JS);
            self::assertSame([true, 'intrinsic'], $engine->eval('result'));
        } finally {
            $engine->close();
        }
    }

    public function testFetchUsesAsyncHttpAndRetainsStreamedRequestAndResponseBytes(): void
    {
        $engine = new Engine(node: true);
        $engine->setExecutionBudget(15);
        $engine->eval(<<<'JS'
const http = require('http');
const result = [];
const server = http.createServer((req, res) => {
  if (req.url === '/redirect') { res.writeHead(302, { Location: '/stream' }); res.end(); return; }
  if (req.method === 'POST') {
    const chunks = [];
    req.on('data', chunk => chunks.push(chunk));
    req.on('end', () => res.end(Buffer.concat(chunks)));
    return;
  }
  res.write('first');
  setTimeout(() => res.end('last'), 5);
});
server.listen(0, '127.0.0.1', async () => {
  const base = 'http://127.0.0.1:' + server.address().port;
  try {
    const response = await fetch(base + '/redirect');
    result.push(response.redirected, response.url === base + '/stream', await response.text());
    try { response.headers.set('x-change', 'no'); } catch (error) { result.push(error.name); }
    const body = new ReadableStream({ start(controller) {
      controller.enqueue(new Uint8Array([0, 255]));
      setTimeout(() => { controller.enqueue(new Uint8Array([128, 65])); controller.close(); }, 5);
    } });
    const echo = await fetch(base + '/echo', { method: 'POST', body, duplex: 'half' });
    result.push(Buffer.from(await echo.arrayBuffer()).toString('hex'));
  } catch (error) { result.push(error.name + ':' + error.message); }
  finally { server.close(); }
});
JS);
        self::assertSame([true, true, 'firstlast', 'TypeError', '00ff8041'], $engine->eval('result'));
        $engine->reset();
    }
}
