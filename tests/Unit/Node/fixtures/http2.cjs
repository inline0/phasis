'use strict';

const http2 = require('http2');
const crypto = require('crypto');
const result = { responses: [], ping: false, errors: [] };
globalThis.http2Result = result;
const server = http2.createServer();
server.on('sessionError', error => result.errors.push(error.message));
server.on('stream', (stream, headers) => {
  const chunks = [];
  stream.on('error', error => result.errors.push(error.message));
  stream.on('data', chunk => chunks.push(chunk));
  stream.on('end', () => {
    stream.respond({ ':status': 201, 'x-echo': headers['x-repeat'], 'set-cookie': ['a=1', 'b=2'] }, { waitForTrailers: true });
    stream.on('wantTrailers', () => stream.sendTrailers({ 'x-complete': 'yes' }));
    stream.end(Buffer.concat(chunks));
  });
});
server.listen(0, '127.0.0.1', () => {
  const client = http2.connect('http://127.0.0.1:' + server.address().port);
  client.on('error', error => { result.errors.push(error.message); client.destroy(); server.close(); });
  client.on('connect', () => {
    client.ping(Buffer.from('abcdefgh'), (error, duration, payload) => {
      if (error) result.errors.push(error.message);
      else result.ping = payload.toString() === 'abcdefgh' && duration >= 0;
    });
    for (let i = 0; i < 3; i++) {
      const request = client.request({ ':method': 'POST', ':path': '/body/' + i, 'x-repeat': 'header compression across requests' });
      const chunks = [], response = { id: i };
      request.on('error', error => result.errors.push(error.message));
      request.on('response', headers => { response.status = headers[':status']; response.echo = headers['x-echo']; response.cookies = headers['set-cookie']; });
      request.on('trailers', trailers => { response.trailer = trailers['x-complete']; });
      request.on('data', chunk => chunks.push(chunk));
      request.on('end', () => {
        const body = Buffer.concat(chunks);
        response.length = body.length; response.hash = crypto.createHash('sha256').update(body).digest('hex');
        result.responses.push(response);
        if (result.responses.length === 3) { result.responses.sort((a, b) => a.id - b.id); client.close(); server.close(); }
      });
      request.end(Buffer.alloc(100000 + i * 111, 65 + i));
    }
  });
});
