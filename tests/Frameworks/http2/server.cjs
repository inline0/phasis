'use strict';
const http2 = require('http2');
const secure = !!process.env.PHASIS_HTTP2_TLS_CERT;
const server = secure ? http2.createSecureServer({
  key: require('fs').readFileSync(process.env.PHASIS_HTTP2_TLS_KEY),
  cert: require('fs').readFileSync(process.env.PHASIS_HTTP2_TLS_CERT),
}) : http2.createServer();
let completed = 0;
server.on('stream', (stream, headers) => {
  const chunks = [];
  stream.on('data', chunk => chunks.push(chunk));
  stream.on('end', () => {
    stream.respond({ ':status': 201, 'x-path': headers[':path'], 'x-compressed': headers['x-compressed'] }, { waitForTrailers: true });
    stream.on('wantTrailers', () => stream.sendTrailers({ 'x-finished': 'yes' }));
    stream.end(Buffer.concat(chunks));
  });
  stream.on('close', () => { if (++completed === 3) server.close(); });
});
server.listen(0, '127.0.0.1', () => {
  if (typeof __http2Ready === 'function') __http2Ready(server.address().port);
  else console.log(JSON.stringify({ ready: server.address().port }));
});
