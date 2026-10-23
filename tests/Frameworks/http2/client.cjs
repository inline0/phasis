'use strict';
const http2 = require('http2');
const crypto = require('crypto');
const results = [];
const secure = !!process.env.PHASIS_HTTP2_TLS_CERT;
const client = http2.connect((secure ? 'https://' : 'http://') + '127.0.0.1:' + process.argv[2], secure ? {
  ca: require('fs').readFileSync(process.env.PHASIS_HTTP2_TLS_CERT), servername: 'localhost',
} : {});
client.on('connect', () => {
  for (let i = 0; i < 3; i++) {
    const request = client.request({ ':method': 'POST', ':path': '/peer/' + i, 'x-compressed': 'shared header value across streams' });
    const chunks = [], result = { id: i };
    request.on('response', headers => { result.status = headers[':status']; result.path = headers['x-path']; result.header = headers['x-compressed']; });
    request.on('trailers', headers => { result.trailer = headers['x-finished']; });
    request.on('data', chunk => chunks.push(chunk));
    request.on('end', () => {
      const data = Buffer.concat(chunks); result.bytes = data.length; result.sha256 = crypto.createHash('sha256').update(data).digest('hex');
      results.push(result);
      if (results.length === 3) {
        const serialized = JSON.stringify({ results: results.sort((a, b) => a.id - b.id) });
        if (typeof __http2Report === 'function') __http2Report(serialized); else console.log(serialized);
        client.close();
      }
    });
    request.end(Buffer.alloc(70000 + i, 65 + i));
  }
});
