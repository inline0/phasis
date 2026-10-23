'use strict';

const http = require('http');
const https = require('https');
const { Buffer } = require('buffer');
const { Readable } = require('stream');
const zlib = require('zlib');

module.exports = async function fetchTransport(request, originalBytes, cookieGet, cookieSet, finalize, Response) {
  const signal = request.signal;
  const headers = new Headers(request.headers);
  if (!headers.has('accept')) headers.set('accept', '*/*');
  if (!headers.has('accept-language')) headers.set('accept-language', '*');
  if (!headers.has('user-agent')) headers.set('user-agent', 'phasis');
  if (!headers.has('accept-encoding')) headers.set('accept-encoding', 'gzip, deflate, br');
  let url = new URL(request.url), method = request.method, body = request.body;
  let replay = originalBytes === null ? null : Buffer.from(originalBytes);
  for (let hops = 0; ; hops++) {
    if (signal.aborted) throw signal.reason;
    if (!['http:', 'https:'].includes(url.protocol)) throw new TypeError('fetch failed: unsupported URL scheme');
    if (url.username || url.password) throw new TypeError('Request cannot be constructed from a URL that includes credentials');
    const outgoing = {};
    for (const [name, value] of headers) outgoing[name] = value;
    const cookie = cookieGet(url.href);
    if (cookie) outgoing.cookie = outgoing.cookie ? outgoing.cookie + '; ' + cookie : cookie;
    if (body && replay !== null && !headers.has('content-length')) outgoing['content-length'] = String(replay.length);
    const response = await new Promise((resolve, reject) => {
      let responseStarted = false;
      const transport = url.protocol === 'https:' ? https : http;
      const req = transport.request(url, { method, headers: outgoing }, incoming => {
        responseStarted = true;
        for (let i = 0; i < incoming.rawHeaders.length; i += 2) if (incoming.rawHeaders[i].toLowerCase() === 'set-cookie') cookieSet(url.href, incoming.rawHeaders[i + 1]);
        resolve(incoming);
      });
      const abort = () => { const error = signal.reason; req.destroy(error instanceof Error ? error : new Error('The operation was aborted')); if (req.res) req.res.destroy(error); reject(error); };
      signal.addEventListener('abort', abort, { once: true });
      req.once('close', () => signal.removeEventListener('abort', abort));
      req.on('error', error => { if (!responseStarted) reject(signal.aborted ? signal.reason : new TypeError('fetch failed', { cause: error })); });
      if (!body) { req.end(); return; }
      const reader = body.getReader();
      const pump = async () => {
        try {
          for (;;) {
            const { value, done } = await reader.read();
            if (done) break;
            if (!(value instanceof Uint8Array)) throw new TypeError('ReadableStream body chunks must be Uint8Array');
            if (!req.write(Buffer.from(value))) await new Promise((resolve, reject) => { req.once('drain', resolve); req.once('error', reject); });
          }
          req.end();
        } catch (error) { req.destroy(error); reject(error); }
        finally { reader.releaseLock(); }
      };
      pump();
    });
    const status = response.statusCode;
    const location = response.headers.location;
    if ([301, 302, 303, 307, 308].includes(status) && location && request.redirect !== 'manual') {
      response.resume();
      if (request.redirect === 'error') throw new TypeError('fetch failed: unexpected redirect');
      if (hops >= 20) throw new TypeError('fetch failed: redirect count exceeded');
      const next = new URL(location, url);
      if (next.origin !== url.origin) for (const name of ['authorization', 'proxy-authorization', 'cookie', 'host']) headers.delete(name);
      if (((status === 301 || status === 302) && method === 'POST') || (status === 303 && method !== 'GET' && method !== 'HEAD')) {
        method = 'GET'; body = null; replay = null;
        for (const name of ['content-encoding', 'content-language', 'content-location', 'content-type', 'content-length']) headers.delete(name);
      } else if (body) {
        if (replay === null) throw new TypeError('fetch failed: cannot replay a streamed request body');
        body = new ReadableStream({ start(controller) { controller.enqueue(new Uint8Array(replay)); controller.close(); } });
      }
      url = next;
      continue;
    }
    let stream = response;
    const encodings = String(response.headers['content-encoding'] || '').split(',').map(value => value.trim()).filter(Boolean).reverse();
    if (method !== 'HEAD' && ![204, 205, 304].includes(status)) {
      for (const encoding of encodings) {
        const decoder = encoding === 'gzip' || encoding === 'x-gzip' ? zlib.createGunzip() : encoding === 'deflate' ? zlib.createInflate() : encoding === 'br' ? zlib.createBrotliDecompress() : null;
        if (decoder) { const source = stream; source.on('error', error => decoder.destroy(error)); stream = source.pipe(decoder); }
      }
    }
    const headerPairs = [];
    for (let i = 0; i < response.rawHeaders.length; i += 2) headerPairs.push([response.rawHeaders[i], response.rawHeaders[i + 1]]);
    const nullBody = method === 'HEAD' || [204, 205, 304].includes(status);
    if (nullBody) response.resume();
    const result = new Response(nullBody ? null : Readable.toWeb(stream), { status, statusText: response.statusMessage || '', headers: headerPairs });
    return finalize(result, url.href, hops > 0);
  }
};
