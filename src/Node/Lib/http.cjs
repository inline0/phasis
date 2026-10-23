'use strict';

const net = require('net');
const { Readable, Writable } = require('stream');
const { EventEmitter } = require('events');
const { Buffer } = require('buffer');
const { urlToHttpOptions } = require('url');
const { HTTPParser } = require('./http-parser.cjs');
const { methods: METHODS, status: STATUS_CODES } = require('./http-constants.cjs');
// QUERY was standardized after the parser's method table was released.
if (!HTTPParser.methods.includes('QUERY')) HTTPParser.methods.push('QUERY');
const token = /^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/;
const invalidValue = /[^\t\x20-\x7e\x80-\xff]/;

function coded(ErrorType, code, message) { return Object.assign(new ErrorType(message), { code }); }
function validateHeaderName(name, label = 'Header name') {
  if (typeof name !== 'string' || !token.test(name)) throw coded(TypeError, 'ERR_INVALID_HTTP_TOKEN', `${label} must be a valid HTTP token ["${name}"]`);
}
function validateHeaderValue(name, value) {
  if (value === undefined) throw coded(TypeError, 'ERR_HTTP_INVALID_HEADER_VALUE', `Invalid value "undefined" for header "${name}"`);
  if (invalidValue.test(String(value))) throw coded(TypeError, 'ERR_INVALID_CHAR', `Invalid character in header content ["${name}"]`);
}
const singletonHeaders = new Set(['age', 'authorization', 'content-length', 'content-type', 'etag', 'expires', 'from', 'host', 'if-modified-since', 'if-unmodified-since', 'last-modified', 'location', 'max-forwards', 'proxy-authorization', 'referer', 'retry-after', 'server', 'user-agent']);
function headersObject(raw, distinct = false, joinDuplicates = false) {
  const headers = {};
  for (let i = 0; i < raw.length; i += 2) {
    const key = raw[i].toLowerCase(), value = raw[i + 1];
    if (distinct || key === 'set-cookie') (headers[key] || (headers[key] = [])).push(value);
    else if (headers[key] === undefined) headers[key] = value;
    else if (!singletonHeaders.has(key) || joinDuplicates) headers[key] += (key === 'cookie' ? '; ' : ', ') + value;
  }
  return headers;
}
function validateIncoming(raw, request) {
  let contentLength = false;
  const encodings = [];
  for (let i = 0; i < raw.length; i += 2) {
    const name = raw[i], value = raw[i + 1];
    if (!token.test(name) || invalidValue.test(value)) throw coded(Error, 'HPE_INVALID_HEADER_TOKEN', 'Parse Error: Invalid header token');
    if (name.toLowerCase() === 'content-length') {
      if (contentLength || !/^\d+$/.test(value)) throw coded(Error, 'HPE_UNEXPECTED_CONTENT_LENGTH', 'Parse Error: Invalid Content-Length');
      contentLength = true;
    }
    if (name.toLowerCase() === 'transfer-encoding') {
      encodings.push(...value.toLowerCase().split(',').map(value => value.trim()));
    }
  }
  if (contentLength && encodings.length) throw coded(Error, 'HPE_UNEXPECTED_CONTENT_LENGTH', 'Parse Error: Content-Length cannot accompany Transfer-Encoding');
  const chunked = encodings[encodings.length - 1] === 'chunked';
  if (encodings.some(value => !token.test(value)) ||
      encodings.slice(0, -1).includes('chunked') || request && encodings.length && !chunked) {
    throw coded(Error, 'HPE_INVALID_TRANSFER_ENCODING', 'Parse Error: Invalid Transfer-Encoding');
  }
  return chunked;
}

class IncomingMessage extends Readable {
  constructor(socket) {
    super({ autoDestroy: true });
    this.socket = socket;
    this.httpVersionMajor = 1;
    this.httpVersionMinor = 1;
    this.httpVersion = '1.1';
    this.complete = false;
    this.aborted = false;
    this.headers = {};
    this.rawHeaders = [];
    this.trailers = {};
    this.rawTrailers = [];
    this.url = '';
    this.method = null;
    this.statusCode = null;
    this.statusMessage = null;
  }
  get connection() { return this.socket; }
  set connection(value) { this.socket = value; }
  get headersDistinct() { return headersObject(this.rawHeaders, true); }
  get trailersDistinct() { return headersObject(this.rawTrailers, true); }
  _read() { if (this.socket && !this.socket.destroyed) this.socket.resume(); }
  _destroy(error, callback) {
    if (!this.complete && !this.aborted) { this.aborted = true; this.emit('aborted'); }
    if (!this.complete && this.socket && !this.socket.destroyed) this.socket.destroy(error);
    callback(error);
  }
  setTimeout(timeout, callback) { this.socket.setTimeout(timeout); if (callback) this.on('timeout', callback); return this; }
  _dump() { this.removeAllListeners('data'); this.resume(); }
}

class OutgoingMessage extends Writable {
  constructor() {
    super({ decodeStrings: true, autoDestroy: false, emitClose: true });
    this._headers = Object.create(null);
    this._header = null;
    this._headerSent = false;
    this._trailer = '';
    this._pendingOutput = [];
    this._active = true;
    this.socket = null;
    this.sendDate = false;
    this.shouldKeepAlive = true;
    this.useChunkedEncodingByDefault = true;
    this.chunkedEncoding = false;
    this._hasBody = true;
    this._removedConnection = false;
    this._removedContLen = false;
    this._removedTE = false;
    this._contentLength = null;
    this._bodyBytes = 0;
    this.strictContentLength = false;
  }
  get connection() { return this.socket; }
  set connection(value) { this.socket = value; }
  get headersSent() { return this._header !== null; }
  get finished() { return this.writableEnded; }
  get writableLength() { return super.writableLength + (this.socket ? this.socket.writableLength : 0); }
  _assertHeaders(action) { if (this._header !== null) throw coded(Error, 'ERR_HTTP_HEADERS_SENT', `Cannot ${action} headers after they are sent to the client`); }
  setHeader(name, value) {
    this._assertHeaders('set'); validateHeaderName(name);
    for (const item of Array.isArray(value) ? value : [value]) validateHeaderValue(name, item);
    this._headers[name.toLowerCase()] = [name, value];
    return this;
  }
  appendHeader(name, value) {
    if (!this.hasHeader(name)) return this.setHeader(name, value);
    const old = this.getHeader(name);
    return this.setHeader(name, [].concat(old, value));
  }
  setHeaders(headers) { if (!headers || typeof headers.entries !== 'function') throw new TypeError('headers must be a Map or Headers'); for (const [key, value] of headers) this.setHeader(key, value); return this; }
  getHeader(name) { if (typeof name !== 'string') throw new TypeError('name must be a string'); const pair = this._headers[name.toLowerCase()]; return pair && pair[1]; }
  getHeaderNames() { return Object.keys(this._headers); }
  getRawHeaderNames() { return Object.values(this._headers).map(pair => pair[0]); }
  getHeaders() { const out = Object.create(null); for (const key of Object.keys(this._headers)) out[key] = this._headers[key][1]; return out; }
  hasHeader(name) { if (typeof name !== 'string') throw new TypeError('name must be a string'); return Object.prototype.hasOwnProperty.call(this._headers, name.toLowerCase()); }
  removeHeader(name) {
    this._assertHeaders('remove');
    const key = String(name).toLowerCase(); delete this._headers[key];
    if (key === 'connection') this._removedConnection = true;
    if (key === 'content-length') this._removedContLen = true;
    if (key === 'transfer-encoding') this._removedTE = true;
    if (key === 'date') this.sendDate = false;
  }
  _storeHeader(firstLine) {
    if (this.sendDate && !this.hasHeader('date')) this.setHeader('Date', new Date().toUTCString());
    const status = this.statusCode;
    if (this.req && (this.req.method === 'HEAD' || status < 200 || status === 204 || status === 304)) this._hasBody = false;
    if (status === 204 || status === 304 || status < 200) {
      delete this._headers['transfer-encoding'];
      if (status !== 304) delete this._headers['content-length'];
    }
    if (this.hasHeader('content-length')) this._contentLength = Number(this.getHeader('content-length'));
    if (!this.hasHeader('content-length') && !this.hasHeader('transfer-encoding') && this._hasBody) {
      if (this.useChunkedEncodingByDefault && !this._removedTE) this.setHeader('Transfer-Encoding', 'chunked');
      else if (this._contentLength === null) this.shouldKeepAlive = false;
    }
    this.chunkedEncoding = /(?:^|,)\s*chunked\s*$/i.test(String(this.getHeader('transfer-encoding') || '')) && this._hasBody;
    if (this._hasBody && this.hasHeader('transfer-encoding') && !this.chunkedEncoding) this.shouldKeepAlive = false;
    if (this.hasHeader('connection')) {
      if (/\bclose\b/i.test(String(this.getHeader('connection')))) this.shouldKeepAlive = false;
    } else if (!this._removedConnection) this.setHeader('Connection', this.shouldKeepAlive ? 'keep-alive' : 'close');
    if (this.req && this.shouldKeepAlive && !this.hasHeader('keep-alive') && this._keepAliveTimeout) this.setHeader('Keep-Alive', `timeout=${Math.floor(this._keepAliveTimeout / 1000)}`);
    let header = firstLine;
    for (const [name, value] of Object.values(this._headers)) {
      const values = Array.isArray(value) ? value : [value];
      if (name.toLowerCase() === 'cookie') header += name + ': ' + values.join('; ') + '\r\n';
      else for (const item of values) header += name + ': ' + item + '\r\n';
    }
    this._header = header + '\r\n';
  }
  _send(data, callback) {
    if (!this._active || !this.socket) { this._pendingOutput.push([data, callback]); return; }
    if (this.socket.destroyed) { if (callback) process.nextTick(callback, coded(Error, 'ERR_STREAM_DESTROYED', 'Cannot call write after a stream was destroyed')); return; }
    this.socket.write(data, callback);
  }
  _activate(socket) {
    this.socket = socket;
    this._active = true;
    const pending = this._pendingOutput.splice(0);
    for (const [data, callback] of pending) this._send(data, callback);
  }
  _flushHeader() { if (this._header === null) this._implicitHeader(); if (!this._headerSent) { this._headerSent = true; this._send(Buffer.from(this._header, 'latin1')); } }
  flushHeaders() { this._flushHeader(); }
  _write(chunk, encoding, callback) {
    this._flushHeader();
    if (!this._hasBody || chunk.length === 0) { process.nextTick(callback); return; }
    if (!Buffer.isBuffer(chunk)) chunk = Buffer.from(chunk, encoding);
    this._bodyBytes += chunk.length;
    if (this.strictContentLength && this._contentLength !== null && this._bodyBytes > this._contentLength) { callback(coded(Error, 'ERR_HTTP_CONTENT_LENGTH_MISMATCH', 'Response body length does not match Content-Length')); return; }
    if (this.chunkedEncoding) this._send(Buffer.concat([Buffer.from(chunk.length.toString(16) + '\r\n'), chunk, Buffer.from('\r\n')]), callback);
    else this._send(chunk, callback);
  }
  write(chunk, encoding, callback) { if (this._header === null) this._implicitHeader(); return super.write(chunk, encoding, callback); }
  end(chunk, encoding, callback) {
    if (typeof chunk === 'function') { callback = chunk; chunk = undefined; encoding = undefined; }
    else if (typeof encoding === 'function') { callback = encoding; encoding = undefined; }
    if (this._header === null && !this.hasHeader('content-length') && !this.hasHeader('transfer-encoding') && !this._removedContLen) {
      if (chunk !== undefined && chunk !== null) this.setHeader('Content-Length', Buffer.isBuffer(chunk) || chunk instanceof Uint8Array ? chunk.length : Buffer.byteLength(chunk, encoding));
      else if (this.req || !['GET', 'HEAD', 'DELETE', 'OPTIONS', 'TRACE', 'CONNECT'].includes(this.method)) this.setHeader('Content-Length', 0);
      else { this.useChunkedEncodingByDefault = false; this._contentLength = 0; }
    }
    if (this._header === null) this._implicitHeader();
    return super.end(chunk, encoding, callback);
  }
  _final(callback) {
    this._flushHeader();
    if (this.strictContentLength && this._hasBody && this._contentLength !== null && this._bodyBytes !== this._contentLength) { callback(coded(Error, 'ERR_HTTP_CONTENT_LENGTH_MISMATCH', 'Response body length does not match Content-Length')); return; }
    if (this.chunkedEncoding) this._send(Buffer.from('0\r\n' + this._trailer + '\r\n', 'latin1'), callback);
    else this._send(Buffer.alloc(0), callback);
  }
  addTrailers(headers) { for (const [name, value] of Object.entries(headers)) { validateHeaderName(name); validateHeaderValue(name, value); this._trailer += name + ': ' + value + '\r\n'; } }
  setTimeout(timeout, callback) { if (callback) this.on('timeout', callback); if (this.socket) this.socket.setTimeout(timeout); else this.once('socket', socket => socket.setTimeout(timeout)); return this; }
  _destroy(error, callback) { if (this.socket && !this.socket.destroyed) this.socket.destroy(error); callback(error); }
}

class ServerResponse extends OutgoingMessage {
  constructor(req) {
    super();
    this.req = req;
    this.statusCode = 200;
    this.statusMessage = undefined;
    this.sendDate = true;
    this.shouldKeepAlive = req.httpVersionMajor === 1 && req.httpVersionMinor === 1 ? !/\bclose\b/i.test(req.headers.connection || '') : /\bkeep-alive\b/i.test(req.headers.connection || '');
    this.useChunkedEncodingByDefault = req.httpVersionMinor >= 1;
    this._hasBody = req.method !== 'HEAD';
  }
  assignSocket(socket) { socket._httpMessage = this; this._activate(socket); }
  detachSocket(socket) { if (socket._httpMessage === this) socket._httpMessage = null; this.socket = null; }
  _implicitHeader() { this.writeHead(this.statusCode); }
  writeHead(statusCode, statusMessage, headers) {
    this._assertHeaders('write');
    const code = Number(statusCode);
    if (!Number.isInteger(code) || code < 100 || code > 999) throw coded(RangeError, 'ERR_HTTP_INVALID_STATUS_CODE', `Invalid status code: ${statusCode}`);
    this.statusCode = code;
    if (typeof statusMessage === 'string') this.statusMessage = statusMessage;
    else { headers = statusMessage; this.statusMessage = this.statusMessage || STATUS_CODES[code] || 'unknown'; }
    if (invalidValue.test(this.statusMessage)) throw coded(TypeError, 'ERR_INVALID_CHAR', 'Invalid character in statusMessage');
    if (Array.isArray(headers)) { for (let i = 0; i < headers.length; i += 2) this.appendHeader(headers[i], headers[i + 1]); }
    else if (headers) for (const [name, value] of Object.entries(headers)) this.setHeader(name, value);
    this._storeHeader(`HTTP/1.1 ${code} ${this.statusMessage}\r\n`);
    return this;
  }
  writeContinue(callback) { this._send(Buffer.from('HTTP/1.1 100 Continue\r\n\r\n'), callback); }
  writeProcessing(callback) { this._send(Buffer.from('HTTP/1.1 102 Processing\r\n\r\n'), callback); }
  writeEarlyHints(hints, callback) { this.writeInformation(103, hints, callback); }
  writeInformation(code, headers, callback) {
    let text = `HTTP/1.1 ${code} ${STATUS_CODES[code] || 'unknown'}\r\n`;
    for (const [name, value] of Object.entries(headers || {})) { validateHeaderName(name); validateHeaderValue(name, value); text += name + ': ' + (Array.isArray(value) ? value.join(', ') : value) + '\r\n'; }
    this._send(Buffer.from(text + '\r\n', 'latin1'), callback);
  }
}

function fillIncoming(message, info, joinDuplicateHeaders) {
  message.httpVersionMajor = info.versionMajor;
  message.httpVersionMinor = info.versionMinor;
  message.httpVersion = info.versionMajor + '.' + info.versionMinor;
  message.rawHeaders = info.headers.slice();
  message.headers = headersObject(message.rawHeaders, false, joinDuplicateHeaders);
  message.url = info.url || '';
  message.method = info.method === undefined ? null : HTTPParser.methods[info.method];
  message.statusCode = info.statusCode === undefined ? null : info.statusCode;
  message.statusMessage = info.statusMessage;
}

class Server extends net.Server {
  constructor(options = {}, listener) {
    if (typeof options === 'function') { listener = options; options = {}; }
    super({ ...options, allowHalfOpen: true });
    this._httpOptions = options;
    this.httpAllowHalfOpen = false;
    this.timeout = 0;
    this.keepAliveTimeout = options.keepAliveTimeout === undefined ? 5000 : options.keepAliveTimeout;
    this.keepAliveTimeoutBuffer = 1000;
    this.requestTimeout = options.requestTimeout === undefined ? 300000 : options.requestTimeout;
    this.headersTimeout = options.headersTimeout === undefined ? Math.min(60000, this.requestTimeout) : options.headersTimeout;
    this.maxHeadersCount = null;
    this.maxRequestsPerSocket = 0;
    this.on('connection', socket => this._connection(socket));
    if (listener) this.on('request', listener);
  }
  _connection(socket) {
    const parser = new HTTPParser(HTTPParser.REQUEST);
    parser.maxHeaderSize = this._httpOptions.maxHeaderSize || 16384;
    const responses = [];
    let incoming = null, upgraded = null;
    socket._httpResponses = responses;
    socket._httpIdle = true;
    if (this.timeout) socket.setTimeout(this.timeout);
    socket.on('timeout', () => {
      const handled = (incoming && incoming.emit('timeout')) | (socket._httpMessage && socket._httpMessage.emit('timeout')) | this.emit('timeout', socket);
      if (!handled) socket.destroy();
    });
    parser[HTTPParser.kOnHeadersComplete] = info => {
      parser.isChunked = validateIncoming(info.headers, true);
      const req = incoming = new (this._httpOptions.IncomingMessage || IncomingMessage)(socket);
      fillIncoming(req, info, this._httpOptions.joinDuplicateHeaders);
      socket._httpIdle = false;
      socket.setTimeout(this.timeout);
      if (info.upgrade) { upgraded = req; return 2; }
      const res = new (this._httpOptions.ServerResponse || ServerResponse)(req);
      res._keepAliveTimeout = this.keepAliveTimeout;
      res.socket = socket;
      res._active = responses.length === 0;
      responses.push(res);
      if (res._active) socket._httpMessage = res;
      res.once('finish', () => {
        if (!req.readableEnded) req._dump();
        const index = responses.indexOf(res); if (index !== -1) responses.splice(index, 1);
        res.emit('close');
        if (!res.shouldKeepAlive) socket.end();
        else if (responses.length) responses[0].assignSocket(socket);
        else {
          socket._httpMessage = null;
          socket._httpIdle = true;
          if (!this.listening) socket.end();
          else socket.setTimeout(this.keepAliveTimeout + this.keepAliveTimeoutBuffer);
        }
      });
      if (/^100-continue$/i.test(req.headers.expect || '')) {
        if (this.listenerCount('checkContinue')) this.emit('checkContinue', req, res);
        else { res.writeContinue(); this.emit('request', req, res); }
      } else if (req.headers.expect) {
        if (this.listenerCount('checkExpectation')) this.emit('checkExpectation', req, res);
        else { res.statusCode = 417; res.end(); }
      } else this.emit('request', req, res);
      return false;
    };
    parser[HTTPParser.kOnBody] = chunk => { if (incoming && !incoming.push(Buffer.from(chunk))) socket.pause(); };
    parser[HTTPParser.kOnHeaders] = headers => { if (incoming) { incoming.rawTrailers = headers.slice(); incoming.trailers = headersObject(headers); } };
    parser[HTTPParser.kOnMessageComplete] = () => { if (incoming) { incoming.complete = true; incoming.push(null); } };
    const onData = data => {
      let parsed;
      try { parsed = parser.execute(data); } catch (error) { onError(error); return; }
      if (parsed instanceof Error) { onError(parsed); return; }
      if (upgraded) {
        socket.removeListener('data', onData);
        socket.removeListener('end', onEnd);
        const event = upgraded.method === 'CONNECT' ? 'connect' : 'upgrade';
        if (this.listenerCount(event)) this.emit(event, upgraded, socket, data.subarray(parsed));
        else socket.destroy();
      }
    };
    const onError = error => {
      if (!this.emit('clientError', error, socket)) {
        if (socket.writable && !socket.destroyed) socket.end('HTTP/1.1 400 Bad Request\r\nConnection: close\r\nContent-Length: 0\r\n\r\n');
        else socket.destroy();
      }
    };
    const onEnd = () => {
      const error = parser.finish();
      if (error) { onError(error); return; }
      if (!this.httpAllowHalfOpen || !responses.length) socket.end();
      else responses[responses.length - 1].shouldKeepAlive = false;
    };
    socket.on('data', onData);
    socket.on('end', onEnd);
    socket.on('error', onError);
    socket.on('close', () => {
      if (incoming && !incoming.complete && !incoming.destroyed) incoming.destroy(coded(Error, 'ECONNRESET', 'aborted'));
      for (const response of responses) if (!response.destroyed) response.destroy();
    });
  }
  setTimeout(timeout = 0, callback) { this.timeout = timeout; if (callback) this.on('timeout', callback); return this; }
  close(callback) { super.close(callback); this.closeIdleConnections(); return this; }
  closeAllConnections() { for (const socket of this._sockets) socket.destroy(); }
  closeIdleConnections() { for (const socket of this._sockets) if (socket._httpIdle) socket.end(); }
}

class Agent extends EventEmitter {
  constructor(options = {}) {
    super();
    this.options = { ...options };
    this.defaultPort = 80;
    this.protocol = 'http:';
    this.keepAlive = !!options.keepAlive;
    this.keepAliveMsecs = options.keepAliveMsecs || 1000;
    this.maxSockets = options.maxSockets || Infinity;
    this.maxTotalSockets = options.maxTotalSockets || Infinity;
    this.maxFreeSockets = options.maxFreeSockets === undefined ? 256 : options.maxFreeSockets;
    this.scheduling = options.scheduling || 'lifo';
    this.sockets = Object.create(null);
    this.freeSockets = Object.create(null);
    this.requests = Object.create(null);
    this.totalSocketCount = 0;
  }
  getName(options = {}) { return `${options.host || 'localhost'}:${options.port || this.defaultPort}:${options.localAddress || ''}:${options.family || ''}`; }
  createConnection(options, callback) { return net.createConnection(options, callback); }
  addRequest(request, options) {
    const name = this.getName(options);
    const free = this.freeSockets[name] || [];
    let socket;
    while (free.length && !socket) { const candidate = this.scheduling === 'fifo' ? free.shift() : free.pop(); if (!candidate.destroyed) socket = candidate; }
    if (!free.length) delete this.freeSockets[name];
    if (socket) { socket.ref(); this.reuseSocket(socket, request); }
    else if ((this.sockets[name] || []).length >= this.maxSockets || this.totalSocketCount >= this.maxTotalSockets) { (this.requests[name] || (this.requests[name] = [])).push([request, options]); return; }
    else {
      socket = this.createConnection({ ...this.options, ...options });
      this.totalSocketCount++;
      socket.once('close', () => {
        this.totalSocketCount--;
        this.removeSocket(socket, options);
        const pending = this.requests[name];
        if (pending && pending.length) { const next = pending.shift(); if (!pending.length) delete this.requests[name]; this.addRequest(...next); }
      });
    }
    (this.sockets[name] || (this.sockets[name] = [])).push(socket);
    request.onSocket(socket);
  }
  removeSocket(socket, options) {
    const name = this.getName(options);
    for (const map of [this.sockets, this.freeSockets]) {
      const list = map[name]; if (!list) continue;
      const index = list.indexOf(socket); if (index !== -1) list.splice(index, 1);
      if (!list.length) delete map[name];
    }
  }
  keepSocketAlive(socket) { socket.setKeepAlive(true, this.keepAliveMsecs); socket.unref(); return true; }
  reuseSocket(socket, request) { request.reusedSocket = true; socket.ref(); }
  _free(socket, request, options) {
    this.removeSocket(socket, options);
    const name = this.getName(options), pending = this.requests[name];
    if (pending && pending.length && !socket.destroyed) {
      const [next] = pending.shift(); if (!pending.length) delete this.requests[name];
      (this.sockets[name] || (this.sockets[name] = [])).push(socket);
      socket.ref(); next.onSocket(socket); return;
    }
    if (!this.keepAlive || !request.shouldKeepAlive || socket.destroyed || (this.freeSockets[name] || []).length >= this.maxFreeSockets || !this.keepSocketAlive(socket)) { socket.destroy(); return; }
    (this.freeSockets[name] || (this.freeSockets[name] = [])).push(socket);
    socket.emit('free');
  }
  destroy() { for (const map of [this.sockets, this.freeSockets]) for (const sockets of Object.values(map)) for (const socket of sockets) socket.destroy(); }
}
const globalAgent = new Agent({ keepAlive: true, scheduling: 'lifo', timeout: 5000 });

function requestOptions(input, options) {
  if (typeof input === 'string' || input instanceof URL) return { ...urlToHttpOptions(input instanceof URL ? input : new URL(input)), ...options };
  return { ...(input || {}), ...options };
}
class ClientRequest extends OutgoingMessage {
  constructor(input, options, callback) {
    super();
    if (typeof options === 'function') { callback = options; options = undefined; }
    options = requestOptions(input, options);
    this.protocol = options.protocol || 'http:';
    this.agent = options.agent === undefined ? globalAgent : options.agent === false ? new Agent() : options.agent;
    if (this.agent && this.protocol !== this.agent.protocol) throw coded(TypeError, 'ERR_INVALID_PROTOCOL', `Protocol "${this.protocol}" not supported. Expected "${this.agent.protocol}"`);
    this.method = String(options.method || 'GET').toUpperCase();
    if (!token.test(this.method)) throw coded(TypeError, 'ERR_INVALID_HTTP_TOKEN', 'Method must be a valid HTTP token');
    this.path = options.path || '/';
    if (/[^\x21-\xff]/.test(this.path)) throw coded(TypeError, 'ERR_UNESCAPED_CHARACTERS', 'Request path contains unescaped characters');
    this.host = options.hostname || options.host || 'localhost';
    const port = Number(options.port || (this.agent && this.agent.defaultPort) || 80);
    this._options = { ...options, host: this.host, port, path: options.socketPath };
    this.reusedSocket = false;
    this.aborted = false;
    this.res = null;
    this.shouldKeepAlive = !!(this.agent && this.agent.keepAlive);
    this.useChunkedEncodingByDefault = !['GET', 'HEAD', 'DELETE', 'OPTIONS', 'TRACE', 'CONNECT'].includes(this.method);
    if (Array.isArray(options.headers)) { for (let i = 0; i < options.headers.length; i += 2) this.appendHeader(options.headers[i], options.headers[i + 1]); }
    else if (options.headers) for (const [key, value] of Object.entries(options.headers)) this.setHeader(key, value);
    if (options.setHost !== false && !this.hasHeader('host')) this.setHeader('Host', (this.host.includes(':') ? '[' + this.host + ']' : this.host) + (port === (this.agent ? this.agent.defaultPort : 80) ? '' : ':' + port));
    if (options.auth && !this.hasHeader('authorization')) this.setHeader('Authorization', 'Basic ' + Buffer.from(options.auth).toString('base64'));
    if (callback) this.once('response', callback);
    if (options.signal) {
      const abort = () => this.destroy(Object.assign(new Error('The operation was aborted'), { name: 'AbortError', code: 'ABORT_ERR', cause: options.signal.reason }));
      if (options.signal.aborted) process.nextTick(abort);
      else { options.signal.addEventListener('abort', abort, { once: true }); this.once('close', () => options.signal.removeEventListener('abort', abort)); }
    }
    if (options.timeout) this.setTimeout(options.timeout);
    if (options.createConnection) this.onSocket(options.createConnection(this._options));
    else if (this.agent) this.agent.addRequest(this, this._options);
    else this.onSocket(net.createConnection(this._options));
  }
  _implicitHeader() { this._storeHeader(`${this.method} ${this.path} HTTP/1.1\r\n`); }
  onSocket(socket) {
    process.nextTick(() => {
      if (this.destroyed) { socket.destroy(); return; }
      const parser = new HTTPParser(HTTPParser.RESPONSE);
      parser.maxHeaderSize = this._options.maxHeaderSize || 16384;
      let response = null, upgraded = false, finished = false;
      socket._httpMessage = this;
      const cleanup = () => {
        socket.removeListener('data', onData); socket.removeListener('end', onEnd); socket.removeListener('error', onError); socket.removeListener('close', onClose); socket.removeListener('timeout', onTimeout);
      };
      const complete = () => {
        if (finished) return; finished = true; cleanup();
        if (this.agent) this.agent._free(socket, this, this._options); else socket.destroy();
        this.emit('close');
      };
      parser[HTTPParser.kOnHeadersComplete] = info => {
        parser.isChunked = validateIncoming(info.headers, false);
        info.shouldKeepAlive = parser.shouldKeepAlive();
        response = new IncomingMessage(socket); fillIncoming(response, info, this._options.joinDuplicateHeaders); response.req = this;
        if (info.statusCode < 200 && info.statusCode !== 101) {
          if (info.statusCode === 100) this.emit('continue');
          this.emit('information', { statusCode: info.statusCode, statusMessage: info.statusMessage, httpVersion: response.httpVersion, httpVersionMajor: info.versionMajor, httpVersionMinor: info.versionMinor, headers: response.headers, rawHeaders: response.rawHeaders });
          response = null; return true;
        }
        this.res = response;
        if (!info.shouldKeepAlive || /\bclose\b/i.test(response.headers.connection || '')) this.shouldKeepAlive = false;
        if (info.upgrade || this.method === 'CONNECT') { upgraded = true; return 2; }
        response.once('end', complete);
        if (!this.emit('response', response)) response._dump();
        return this.method === 'HEAD';
      };
      parser[HTTPParser.kOnBody] = chunk => { if (response && !response.push(Buffer.from(chunk))) socket.pause(); };
      parser[HTTPParser.kOnHeaders] = headers => { if (response) { response.rawTrailers = headers.slice(); response.trailers = headersObject(headers); } };
      parser[HTTPParser.kOnMessageComplete] = () => { if (response) { response.complete = true; response.push(null); } };
      const onData = data => {
        let parsed; try { parsed = parser.execute(data); } catch (error) { onError(error); return; }
        if (parsed instanceof Error) { onError(parsed); return; }
        if (upgraded) { cleanup(); const event = this.method === 'CONNECT' ? 'connect' : 'upgrade'; if (this.listenerCount(event)) this.emit(event, response, socket, data.subarray(parsed)); else socket.destroy(); }
      };
      const onError = error => { cleanup(); this.destroy(error); };
      const onEnd = () => {
        const error = parser.finish();
        if (!response || error) onError(coded(Error, 'ECONNRESET', 'socket hang up'));
      };
      const onClose = () => {
        if (finished) return;
        cleanup();
        if (response && !response.complete) response.destroy(coded(Error, 'ECONNRESET', 'aborted'));
        else if (!response) this.destroy(coded(Error, 'ECONNRESET', 'socket hang up'));
      };
      const onTimeout = () => this.emit('timeout');
      socket.on('data', onData); socket.on('end', onEnd); socket.on('error', onError); socket.on('close', onClose); socket.on('timeout', onTimeout);
      this._activate(socket);
      this.emit('socket', socket);
    });
  }
  _destroy(error, callback) {
    if (!error && !this.res && (this.socket || !this.aborted)) error = coded(Error, 'ECONNRESET', 'socket hang up');
    super._destroy(error, callback);
  }
  abort() { if (!this.aborted) { this.aborted = true; process.nextTick(() => this.emit('abort')); this.destroy(); } }
  setNoDelay(value = true) { if (this.socket) this.socket.setNoDelay(value); else this.once('socket', socket => socket.setNoDelay(value)); return this; }
  setSocketKeepAlive(value = false, delay = 0) { if (this.socket) this.socket.setKeepAlive(value, delay); else this.once('socket', socket => socket.setKeepAlive(value, delay)); return this; }
}

function request(input, options, callback) { return new ClientRequest(input, options, callback); }
function get(input, options, callback) { const req = request(input, options, callback); req.end(); return req; }
module.exports = {
  METHODS, STATUS_CODES, IncomingMessage, OutgoingMessage, ServerResponse, Server, Agent, ClientRequest,
  globalAgent, request, get, createServer: (options, listener) => new Server(options, listener),
  validateHeaderName, validateHeaderValue, maxHeaderSize: 16384,
};
