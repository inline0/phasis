<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Node;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    public function testClientDisconnectClosesAnUnfinishedServerResponse(): void
    {
        $engine = new Engine(node: true);
        $engine->eval(<<<'JS'
const http = require('http');
const result = [];
const server = http.createServer((req, res) => {
  res.on('close', () => {
    result.push(res.writableFinished, res.destroyed);
    server.close();
  });
  res.write('streaming');
});
server.listen(0, '127.0.0.1', () => {
  const req = http.get({ host: '127.0.0.1', port: server.address().port }, res => {
    res.once('data', () => req.destroy());
    res.on('error', () => {});
  });
});
JS);
        self::assertSame([false, true], $engine->eval('result'));
        $engine->reset();
    }

    public function testHttpStreamingHeadersTrailersAndBinaryBodies(): void
    {
        $engine = new Engine(node: true);
        $engine->setExecutionBudget(15);
        $engine->eval(<<<'JS'
const http = require('http');
const result = [];
const server = http.createServer((req, res) => {
  const body = [];
  req.on('data', chunk => body.push(chunk));
  req.on('end', () => {
    result.push([req.method, req.url, req.headers['x-custom'], Buffer.concat(body).toString('hex')]);
    res.setHeader('Set-Cookie', ['a=1', 'b=2']);
    res.write(Buffer.from([255, 0]));
    res.addTrailers({ 'X-Checksum': 'yes' });
    res.end('done');
  });
}).listen(0, '127.0.0.1', () => {
  const options = { host: '127.0.0.1', port: server.address().port, method: 'POST', path: '/echo?x=1', headers: { 'X-Custom': 'value' } };
  const req = http.request(options, res => {
    const chunks = [];
    res.on('data', chunk => chunks.push(chunk));
    res.on('end', () => {
      result.push([res.statusCode, res.headers['set-cookie'], Buffer.concat(chunks).toString('hex'), res.trailers['x-checksum']]);
      server.close();
    });
  });
  req.write(Buffer.from([128, 255]));
  req.end('ok');
});
JS);
        self::assertSame([
            ['POST', '/echo?x=1', 'value', '80ff6f6b'],
            [200, ['a=1', 'b=2'], 'ff00646f6e65', 'yes'],
        ], $engine->eval('result'));
        $engine->reset();
    }

    public function testKeepAliveReusesRealConnectionAndHeadHasNoBody(): void
    {
        $engine = new Engine(node: true);
        $engine->setExecutionBudget(15);
        $engine->eval(<<<'JS'
const http = require('http');
const result = [];
let connections = 0;
const agent = new http.Agent({ keepAlive: true });
const server = http.createServer((req, res) => res.end('body'));
server.on('connection', () => connections++);
server.listen(0, '127.0.0.1', () => {
  const options = { host: '127.0.0.1', port: server.address().port, agent };
  http.get(options, res => {
    res.resume();
    res.on('end', () => {
      const req = http.request({ ...options, method: 'HEAD' }, res => {
        let text = '';
        res.on('data', chunk => text += chunk);
        res.on('end', () => {
          result.push(connections, req.reusedSocket, text, res.headers['content-length']);
          agent.destroy();
          server.close();
        });
      });
      req.end();
    });
  });
});
JS);
        self::assertSame([1, true, '', '4'], $engine->eval('result'));
        $engine->reset();
    }
}
