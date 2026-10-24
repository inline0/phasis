'use strict';

const net = require('net');
const tls = require('tls');
const { EventEmitter } = require('events');
const { Duplex, Readable, Writable } = require('stream');
const { Buffer } = require('buffer');
const constants = require('./portable/http2-constants.json');
const hpack = require('./portable/hpack.cjs');
const sensitiveHeaders = Symbol('nodejs.http2.sensitiveHeaders');
const preface = Buffer.from('PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n');
const settingNames = { headerTableSize: 1, enablePush: 2, maxConcurrentStreams: 3, initialWindowSize: 4, maxFrameSize: 5, maxHeaderListSize: 6, enableConnectProtocol: 8 };
const settingKeys = Object.fromEntries(Object.entries(settingNames).map(([name, id]) => [id, name]));
const defaults = { headerTableSize: 4096, enablePush: true, maxConcurrentStreams: 0xffffffff, initialWindowSize: 65535, maxFrameSize: 16384, maxHeaderListSize: 65535, enableConnectProtocol: false };
const connectionHeaders = new Set(['connection', 'upgrade', 'keep-alive', 'proxy-connection', 'transfer-encoding']);
function error(code, message) { return Object.assign(new Error(message), { code }); }
function integer(value, minimum, maximum, name) {
  if (!Number.isInteger(value) || value < minimum || value > maximum) throw new RangeError(`Invalid ${name}: ${value}`);
  return value;
}
function getPackedSettings(settings = {}) {
  const entries = Object.entries(settings).filter(([key]) => settingNames[key]);
  const buffer = Buffer.alloc(entries.length * 6);
  entries.forEach(([key, value], index) => {
    if (key === 'enablePush' || key === 'enableConnectProtocol') {
      if (typeof value !== 'boolean') throw new TypeError(`${key} must be a boolean.`);
      value = Number(value);
    }
    integer(value, key === 'maxFrameSize' ? 16384 : 0, key === 'initialWindowSize' ? 0x7fffffff : key === 'maxFrameSize' ? 0xffffff : 0xffffffff, key);
    buffer.writeUInt16BE(settingNames[key], index * 6);
    buffer.writeUInt32BE(value, index * 6 + 2);
  });
  return buffer;
}
function getUnpackedSettings(buffer) {
  buffer = Buffer.from(buffer);
  if (buffer.length % 6) throw error('ERR_HTTP2_INVALID_PACKED_SETTINGS_LENGTH', 'Settings buffer length must be a multiple of six.');
  const settings = {};
  for (let i = 0; i < buffer.length; i += 6) {
    const name = settingKeys[buffer.readUInt16BE(i)];
    if (!name) continue;
    const value = buffer.readUInt32BE(i + 2);
    settings[name] = name === 'enablePush' || name === 'enableConnectProtocol' ? !!value : value;
  }
  getPackedSettings(settings);
  return settings;
}
function headerList(headers) {
  const list = [], sensitive = headers[sensitiveHeaders] || [];
  for (const name of Object.keys(headers).sort((a, b) => Number(b.startsWith(':')) - Number(a.startsWith(':')))) {
    if (name !== name.toLowerCase() || !/^:?[!#$%&'*+.^_`|~0-9a-z-]+$/.test(name)) throw error('ERR_HTTP2_INVALID_HEADER_VALUE', 'Invalid HTTP/2 header name: ' + name);
    if (connectionHeaders.has(name) || name === 'te' && headers[name] !== 'trailers') throw error('ERR_HTTP2_INVALID_CONNECTION_HEADERS', 'HTTP/1 connection headers are forbidden in HTTP/2.');
    const values = Array.isArray(headers[name]) ? headers[name] : [headers[name]];
    for (const value of values) {
      if (value === undefined) continue;
      const text = String(value);
      if (/[\0\r\n]/.test(text)) throw error('ERR_HTTP2_INVALID_HEADER_VALUE', 'Invalid HTTP/2 header value.');
      list.push({ name, value: text, neverIndex: sensitive.includes(name) });
    }
  }
  return list;
}

class Http2Stream extends Duplex {
  constructor(session, id, options = {}) {
    super({ allowHalfOpen: true, autoDestroy: true, emitClose: true });
    this.session = session;
    this.id = id;
    this.pending = false;
    this.rstCode = 0;
    this.sentHeaders = undefined;
    this.sentTrailers = undefined;
    this.headersSent = false;
    this.endAfterHeaders = false;
    this.pushAllowed = session.type === constants.NGHTTP2_SESSION_SERVER && session.remoteSettings.enablePush;
    this._sendWindow = session.remoteSettings.initialWindowSize;
    this._receiveWindow = session.localSettings.initialWindowSize;
    this._pendingWrite = null;
    this._localEnded = false;
    this._remoteEnded = false;
    this._waitForTrailers = !!options.waitForTrailers;
    this._timeout = null;
    session._streams.set(id, this);
    this.once('close', () => { session._streams.delete(id); session._maybeClose(); if (this._timeout) clearTimeout(this._timeout); });
  }
  _read() {}
  _write(chunk, encoding, done) {
    if (this.session.type === constants.NGHTTP2_SESSION_SERVER && !this.headersSent) this.respond();
    this._pendingWrite = { data: Buffer.from(chunk), offset: 0, done };
    this._flushData();
  }
  _flushData() {
    const pending = this._pendingWrite;
    if (!pending || this.destroyed) return;
    while (pending.offset < pending.data.length && this._sendWindow > 0 && this.session._sendWindow > 0) {
      const length = Math.min(pending.data.length - pending.offset, this._sendWindow, this.session._sendWindow, this.session.remoteSettings.maxFrameSize);
      this.session._frame(0, 0, this.id, pending.data.subarray(pending.offset, pending.offset + length));
      pending.offset += length;
      this._sendWindow -= length;
      this.session._sendWindow -= length;
    }
    if (pending.offset === pending.data.length) { this._pendingWrite = null; pending.done(); }
  }
  _final(done) {
    if (this._localEnded) { done(); return; }
    if (this.session.type === constants.NGHTTP2_SESSION_SERVER && !this.headersSent) this.respond();
    if (this._waitForTrailers) { this._trailerCallback = done; this.emit('wantTrailers'); return; }
    this.session._frame(0, 1, this.id, Buffer.alloc(0));
    this._localEnded = true;
    done();
  }
  _destroy(reason, done) {
    if (!this._localEnded || !this._remoteEnded) {
      if (!this.session.destroyed && !this._resetReceived) this.session._reset(this.id, this.rstCode || (reason ? constants.NGHTTP2_INTERNAL_ERROR : constants.NGHTTP2_CANCEL));
    }
    if (this._pendingWrite) { const pending = this._pendingWrite; this._pendingWrite = null; pending.done(reason || error('ERR_STREAM_DESTROYED', 'HTTP/2 stream was destroyed.')); }
    done(reason);
  }
  respond(headers = {}, options = {}) {
    if (this.headersSent) throw error('ERR_HTTP2_HEADERS_SENT', 'Response has already been initiated.');
    headers = { ':status': 200, ...headers };
    integer(Number(headers[':status']), 200, 599, 'status');
    this.headersSent = true;
    this.sentHeaders = headers;
    this._waitForTrailers = !!options.waitForTrailers;
    this.endAfterHeaders = !!options.endStream;
    this.session._headers(this.id, headers, options.endStream ? 1 : 0);
    if (options.endStream) { this._localEnded = true; this.end(); }
  }
  additionalHeaders(headers) {
    if (this.headersSent) throw error('ERR_HTTP2_HEADERS_AFTER_RESPOND', 'Cannot send informational headers after the response.');
    integer(Number(headers[':status']), 100, 199, 'informational status');
    this.session._headers(this.id, headers, 0);
  }
  sendTrailers(headers) {
    if (!this._trailerCallback) throw error('ERR_HTTP2_TRAILERS_NOT_READY', 'Trailers cannot be sent until wantTrailers.');
    this.sentTrailers = headers;
    this.session._headers(this.id, headers, 1);
    this._localEnded = true;
    const done = this._trailerCallback; this._trailerCallback = null; done();
  }
  close(code = constants.NGHTTP2_NO_ERROR, callback) {
    if (callback) this.once('close', callback);
    this.rstCode = code;
    this.session._reset(this.id, code);
    this._resetReceived = true;
    this.destroy();
  }
  setTimeout(milliseconds, callback) {
    if (this._timeout) clearTimeout(this._timeout);
    if (callback) this.once('timeout', callback);
    this._timeout = milliseconds ? setTimeout(() => this.emit('timeout'), milliseconds) : null;
    return this;
  }
  priority(options = {}) {
    const buffer = Buffer.alloc(5);
    buffer.writeUInt32BE(((options.parent || 0) | (options.exclusive ? 0x80000000 : 0)) >>> 0);
    buffer[4] = integer(options.weight || 16, 1, 256, 'weight') - 1;
    if (!options.silent) this.session._frame(2, 0, this.id, buffer);
  }
  pushStream(headers, options, callback) {
    if (typeof options === 'function') { callback = options; options = {}; }
    if (!this.pushAllowed) { process.nextTick(callback, error('ERR_HTTP2_PUSH_DISABLED', 'HTTP/2 push is disabled.')); return; }
    const id = this.session._nextId; this.session._nextId += 2;
    const pushed = new Http2Stream(this.session, id, options || {});
    pushed._remoteEnded = true; pushed.push(null);
    this.session._headers(this.id, headers, 0, id);
    process.nextTick(callback, null, pushed, headers, 0);
  }
  respondWithFile(path, headers, options = {}) {
    const fs = require('fs');
    const stream = fs.createReadStream(path, options);
    stream.once('error', reason => options.onError ? options.onError(reason) : this.destroy(reason));
    stream.once('open', () => { this.respond(headers, options); stream.pipe(this); });
  }
}

class Http2Session extends EventEmitter {
  constructor(socket, server, options = {}, authority) {
    super();
    this.socket = socket;
    this.type = server ? constants.NGHTTP2_SESSION_SERVER : constants.NGHTTP2_SESSION_CLIENT;
    this.encrypted = !!socket.encrypted;
    this.alpnProtocol = this.encrypted ? socket.alpnProtocol : 'h2c';
    this.connecting = socket.connecting || !!socket._secureConnecting;
    this.closed = false;
    this.destroyed = false;
    this.remoteSettings = { ...defaults };
    this.localSettings = { ...defaults, ...options.settings };
    this.pendingSettingsAck = true;
    this._authority = authority;
    this._streams = new Map();
    this._nextId = server ? 2 : 1;
    this._lastPeerId = 0;
    this._sendWindow = 65535;
    this._receiveWindow = 65535;
    this._input = Buffer.alloc(0);
    this._prefaceReceived = !server;
    this._block = null;
    this._encoder = hpack.compressor.create({ table: { maxSize: 4096 } });
    this._decoder = hpack.decompressor.create({ table: { maxSize: this.localSettings.headerTableSize } });
    this._encoder.on('error', reason => this.destroy(reason, constants.NGHTTP2_COMPRESSION_ERROR));
    this._decoder.on('error', reason => this.destroy(reason, constants.NGHTTP2_COMPRESSION_ERROR));
    this._pings = new Map();
    this._settingsCallbacks = [null];
    socket.on('data', data => this._receive(data));
    socket.on('error', reason => this.destroy(reason));
    socket.on('close', () => {
      if (!this.destroyed) this.destroy();
      if (!this._closedEmitted) { this._closedEmitted = true; this.emit('close'); }
    });
    const connected = () => {
      this.connecting = false;
      this.alpnProtocol = this.encrypted ? socket.alpnProtocol : 'h2c';
      this.emit('connect', this, socket);
    };
    if (this.connecting) socket.once(this.encrypted ? 'secureConnect' : 'connect', connected);
    else process.nextTick(connected);
    if (!server) socket.write(preface);
    this._frame(4, 0, 0, getPackedSettings(options.settings || {}));
  }
  _frame(type, flags, id, payload) {
    if (this.destroyed || this.socket.destroyed || this.socket.writableEnded) return;
    const header = Buffer.alloc(9);
    header.writeUIntBE(payload.length, 0, 3); header[3] = type; header[4] = flags; header.writeUInt32BE(id & 0x7fffffff, 5);
    this.socket.write(Buffer.concat([header, payload]));
  }
  _headers(id, headers, flags, promisedId) {
    this._encoder.write(headerList(headers));
    const chunks = []; let chunk;
    while ((chunk = this._encoder.read()) !== null) chunks.push(chunk);
    let block = Buffer.concat(chunks);
    if (promisedId !== undefined) { const prefix = Buffer.alloc(4); prefix.writeUInt32BE(promisedId); block = Buffer.concat([prefix, block]); }
    const maximum = this.remoteSettings.maxFrameSize;
    for (let offset = 0, first = true; first || offset < block.length; offset += maximum, first = false) {
      const fragment = block.subarray(offset, offset + maximum);
      this._frame(first ? promisedId === undefined ? 1 : 5 : 9, (first ? flags : 0) | (offset + maximum >= block.length ? 4 : 0), id, fragment);
    }
  }
  _reset(id, code) { const payload = Buffer.alloc(4); payload.writeUInt32BE(code); this._frame(3, 0, id, payload); }
  _receive(data) {
    if (this.destroyed) return;
    this._input = Buffer.concat([this._input, data]);
    try {
      if (!this._prefaceReceived) {
        if (this._input.length < preface.length) return;
        if (!this._input.subarray(0, preface.length).equals(preface)) throw error('ERR_HTTP2_ERROR', 'Invalid HTTP/2 connection preface.');
        this._input = this._input.subarray(preface.length); this._prefaceReceived = true;
      }
      while (this._input.length >= 9 && !this.destroyed) {
        const length = this._input.readUIntBE(0, 3), type = this._input[3], flags = this._input[4], id = this._input.readUInt32BE(5) & 0x7fffffff;
        if (length > this.localSettings.maxFrameSize) throw error('ERR_HTTP2_FRAME_SIZE_ERROR', 'HTTP/2 frame exceeds maxFrameSize.');
        if (this._input.length < length + 9) return;
        const payload = this._input.subarray(9, length + 9); this._input = this._input.subarray(length + 9);
        if (this._block && (type !== 9 || id !== this._block.id)) throw error('ERR_HTTP2_ERROR', 'Expected a CONTINUATION frame.');
        this._handle(type, flags, id, payload);
      }
    } catch (reason) { this.destroy(reason, constants.NGHTTP2_PROTOCOL_ERROR); }
  }
  _handle(type, flags, id, payload) {
    let stream = this._streams.get(id);
    if (type === 4) {
      if (id !== 0 || payload.length % 6 || flags & 1 && payload.length) throw error('ERR_HTTP2_ERROR', 'Invalid SETTINGS frame.');
      if (flags & 1) {
        const callback = this._settingsCallbacks.shift(); this.pendingSettingsAck = this._settingsCallbacks.length > 0;
        if (callback) callback(null, this.localSettings, 0);
        this.emit('localSettings', this.localSettings); return;
      }
      const settings = getUnpackedSettings(payload);
      if (settings.initialWindowSize !== undefined) {
        const delta = settings.initialWindowSize - this.remoteSettings.initialWindowSize;
        for (const active of this._streams.values()) active._sendWindow += delta;
      }
      Object.assign(this.remoteSettings, settings);
      if (settings.headerTableSize !== undefined) this._encoder.updateTableSize(settings.headerTableSize);
      this._frame(4, 1, 0, Buffer.alloc(0)); this.emit('remoteSettings', this.remoteSettings);
      for (const active of this._streams.values()) active._flushData();
      return;
    }
    if (type === 6) {
      if (id !== 0 || payload.length !== 8) throw error('ERR_HTTP2_ERROR', 'Invalid PING frame.');
      if (!(flags & 1)) { this._frame(6, 1, 0, payload); this.emit('ping', payload); }
      else { const pending = this._pings.get(payload.toString('hex')); if (pending) { this._pings.delete(payload.toString('hex')); pending.callback(null, performance.now() - pending.start, payload); } }
      return;
    }
    if (type === 7) {
      if (id !== 0 || payload.length < 8) throw error('ERR_HTTP2_ERROR', 'Invalid GOAWAY frame.');
      this.closed = true;
      this.emit('goaway', payload.readUInt32BE(4), payload.readUInt32BE(0) & 0x7fffffff, payload.subarray(8));
      this._maybeClose(); return;
    }
    if (type === 8) {
      if (payload.length !== 4) throw error('ERR_HTTP2_ERROR', 'Invalid WINDOW_UPDATE frame.');
      const increment = payload.readUInt32BE() & 0x7fffffff;
      if (!increment) throw error('ERR_HTTP2_ERROR', 'WINDOW_UPDATE increment must be positive.');
      if (id === 0) { this._sendWindow += increment; if (this._sendWindow > 0x7fffffff) throw error('ERR_HTTP2_ERROR', 'Connection flow-control window overflow.'); }
      else if (stream) { stream._sendWindow += increment; if (stream._sendWindow > 0x7fffffff) { stream.close(constants.NGHTTP2_FLOW_CONTROL_ERROR); return; } }
      for (const active of this._streams.values()) active._flushData();
      return;
    }
    if (type === 1 || type === 5 || type === 9) {
      if (id === 0) throw error('ERR_HTTP2_ERROR', 'Header frame requires a stream.');
      let fragment = payload, promisedId;
      if (type !== 9 && flags & 8) { const padding = payload[0]; if (padding >= payload.length) throw error('ERR_HTTP2_ERROR', 'Invalid header padding.'); fragment = payload.subarray(1, payload.length - padding); }
      if (type === 1 && flags & 32) { if (fragment.length < 5) throw error('ERR_HTTP2_ERROR', 'Invalid header priority.'); fragment = fragment.subarray(5); }
      if (type === 5) { if (fragment.length < 4) throw error('ERR_HTTP2_ERROR', 'Invalid PUSH_PROMISE.'); promisedId = fragment.readUInt32BE() & 0x7fffffff; fragment = fragment.subarray(4); }
      if (type === 9) { if (!this._block) throw error('ERR_HTTP2_ERROR', 'Unexpected CONTINUATION.'); this._block.chunks.push(fragment); }
      else this._block = { id, flags, promisedId, chunks: [fragment] };
      if (flags & 4) { const block = this._block; this._block = null; this._receivedHeaders(block); }
      return;
    }
    if (type === 0) {
      if (!id) throw error('ERR_HTTP2_ERROR', 'DATA frame requires a stream.');
      if (!stream) { this._reset(id, constants.NGHTTP2_STREAM_CLOSED); return; }
      const padding = flags & 8 ? payload[0] : 0;
      if (flags & 8 && padding >= payload.length) throw error('ERR_HTTP2_ERROR', 'Invalid DATA padding.');
      const data = flags & 8 ? payload.subarray(1, payload.length - padding) : payload;
      this._receiveWindow -= payload.length; stream._receiveWindow -= payload.length;
      if (this._receiveWindow < 0 || stream._receiveWindow < 0) throw error('ERR_HTTP2_ERROR', 'Receive flow-control window exceeded.');
      if (data.length) stream.push(data);
      if (payload.length) {
        const update = Buffer.alloc(4); update.writeUInt32BE(payload.length);
        this._frame(8, 0, 0, update); this._frame(8, 0, id, update);
        this._receiveWindow += payload.length; stream._receiveWindow += payload.length;
      }
      if (flags & 1) { stream._remoteEnded = true; stream.push(null); }
      return;
    }
    if (type === 3) {
      if (!id || payload.length !== 4) throw error('ERR_HTTP2_ERROR', 'Invalid RST_STREAM frame.');
      if (stream) { stream.rstCode = payload.readUInt32BE(); stream._resetReceived = true; stream.destroy(stream.rstCode ? error('ERR_HTTP2_STREAM_ERROR', 'Stream closed with code ' + stream.rstCode) : undefined); }
      return;
    }
    if (type === 2 && (!id || payload.length !== 5)) throw error('ERR_HTTP2_ERROR', 'Invalid PRIORITY frame.');
  }
  _receivedHeaders(block) {
    this._decoder.write(Buffer.concat(block.chunks)); this._decoder.execute();
    if (this.destroyed) return;
    const headers = Object.create(null), raw = []; let entry, size = 0, ordinary = false;
    while ((entry = this._decoder.read()) !== null) {
      const name = entry.name, value = entry.value;
      size += name.length + value.length + 32;
      if (size > this.localSettings.maxHeaderListSize) throw error('ERR_HTTP2_ERROR', 'Header list is too large.');
      if (name.startsWith(':')) { if (ordinary || headers[name] !== undefined) throw error('ERR_HTTP2_ERROR', 'Invalid pseudo-header ordering.'); }
      else ordinary = true;
      headerList({ [name]: value }); raw.push(name, value);
      if (name === 'set-cookie') (headers[name] || (headers[name] = [])).push(value);
      else if (headers[name] === undefined) headers[name] = name === ':status' ? Number(value) : value;
      else headers[name] += (name === 'cookie' ? '; ' : ', ') + value;
    }
    const id = block.promisedId === undefined ? block.id : block.promisedId;
    let stream = this._streams.get(id);
    if (!stream) {
      const incomingParity = this.type === constants.NGHTTP2_SESSION_SERVER ? 1 : 0;
      if (id <= this._lastPeerId || id % 2 !== incomingParity) throw error('ERR_HTTP2_ERROR', 'Invalid peer stream identifier.');
      this._lastPeerId = id;
      stream = new Http2Stream(this, id);
      stream._receivedInitialHeaders = block.promisedId === undefined;
      if (block.promisedId !== undefined) { stream._localEnded = true; stream.end(); }
      this.emit('stream', stream, headers, block.flags, raw);
    } else if (this.type === constants.NGHTTP2_SESSION_CLIENT && !stream._receivedInitialHeaders) {
      if (headers[':status'] >= 200) { stream._receivedInitialHeaders = true; stream.emit('response', headers, block.flags, raw); }
      else stream.emit('headers', headers, block.flags, raw);
    } else stream.emit('trailers', headers, block.flags, raw);
    if (block.flags & 1 && block.promisedId === undefined) { stream._remoteEnded = true; stream.push(null); }
  }
  request(headers = {}, options = {}) {
    if (this.closed || this.destroyed) throw error('ERR_HTTP2_GOAWAY_SESSION', 'New streams cannot be created after GOAWAY.');
    headers = { ':method': 'GET', ':path': '/', ':scheme': this.encrypted ? 'https' : 'http', ':authority': this._authority.host, ...headers };
    if (headers[':method'] === 'CONNECT') { delete headers[':path']; delete headers[':scheme']; }
    const stream = new Http2Stream(this, this._nextId, options); this._nextId += 2;
    stream.sentHeaders = headers;
    stream.headersSent = true;
    const end = options.endStream === undefined ? ['GET', 'HEAD', 'DELETE'].includes(headers[':method']) : !!options.endStream;
    this._headers(stream.id, headers, end ? 1 : 0);
    if (end) { stream._localEnded = true; stream.end(); }
    return stream;
  }
  settings(settings, callback) {
    const payload = getPackedSettings(settings);
    Object.assign(this.localSettings, settings); this.pendingSettingsAck = true;
    this._settingsCallbacks.push(callback || null); this._frame(4, 0, 0, payload);
  }
  ping(payload, callback) {
    if (typeof payload === 'function') { callback = payload; payload = require('crypto').randomBytes(8); }
    payload = payload === undefined ? require('crypto').randomBytes(8) : Buffer.from(payload);
    if (payload.length !== 8) throw new RangeError('HTTP/2 PING payload must contain eight bytes.');
    if (typeof callback !== 'function') throw new TypeError('A ping callback is required.');
    this._pings.set(payload.toString('hex'), { callback, start: performance.now() }); this._frame(6, 0, 0, payload); return true;
  }
  goaway(code = 0, lastStreamId = this._lastPeerId, opaqueData = Buffer.alloc(0)) {
    const payload = Buffer.alloc(8); payload.writeUInt32BE(lastStreamId); payload.writeUInt32BE(code, 4);
    this._frame(7, 0, 0, Buffer.concat([payload, Buffer.from(opaqueData)]));
  }
  close(callback) { if (callback) this.once('close', callback); this.closed = true; this._maybeClose(); }
  _maybeClose() {
    if (this.closed && !this.destroyed && !this._transportClosing && this._streams.size === 0) {
      this._transportClosing = true; this.goaway(); this.socket.end();
    }
  }
  destroy(reason, code = constants.NGHTTP2_NO_ERROR) {
    if (this.destroyed) return;
    this.goaway(code); this.destroyed = true; this.closed = true;
    for (const stream of this._streams.values()) { stream._resetReceived = true; stream.destroy(reason); }
    this.socket.destroy();
    if (reason) this.emit('error', reason);
  }
  setTimeout(milliseconds, callback) { this.socket.setTimeout(milliseconds); if (callback) this.on('timeout', callback); this.socket.on('timeout', () => this.emit('timeout')); return this; }
  setLocalWindowSize(size) { integer(size, 0, 0x7fffffff, 'window size'); const increment = size - this._receiveWindow; if (increment > 0) { const value = Buffer.alloc(4); value.writeUInt32BE(increment); this._frame(8, 0, 0, value); this._receiveWindow = size; } }
  ref() { this.socket.ref(); return this; }
  unref() { this.socket.unref(); return this; }
}

class Http2ServerRequest extends Readable {
  constructor(stream, headers, rawHeaders) {
    super(); this.stream = stream; this.socket = stream.session.socket; this.connection = this.socket;
    this.headers = headers; this.rawHeaders = rawHeaders; this.trailers = {}; this.rawTrailers = [];
    this.method = headers[':method']; this.url = headers[':path']; this.authority = headers[':authority']; this.scheme = headers[':scheme'];
    this.httpVersion = '2.0'; this.httpVersionMajor = 2; this.httpVersionMinor = 0; this.complete = false; this.aborted = false;
    stream.on('data', data => { if (!this.push(data)) stream.pause(); });
    stream.on('end', () => { this.complete = true; this.push(null); });
    stream.on('trailers', (trailers, flags, raw) => { this.trailers = trailers; this.rawTrailers = raw; });
    stream.on('error', reason => this.destroy(reason));
  }
  _read() { this.stream.resume(); }
  setTimeout(milliseconds, callback) { this.stream.setTimeout(milliseconds, callback); return this; }
}
class Http2ServerResponse extends Writable {
  constructor(stream) {
    super(); this.stream = stream; this.socket = stream.session.socket; this.connection = this.socket;
    this.statusCode = 200; this.statusMessage = ''; this.sendDate = true; this.headersSent = false;
    this._headers = Object.create(null); this._trailers = null;
  }
  setHeader(name, value) { if (this.headersSent) throw error('ERR_HTTP2_HEADERS_SENT', 'Headers are already sent.'); headerList({ [name.toLowerCase()]: value }); this._headers[name.toLowerCase()] = value; return this; }
  appendHeader(name, value) { const previous = this.getHeader(name); return this.setHeader(name, previous === undefined ? value : [].concat(previous, value)); }
  getHeader(name) { return this._headers[name.toLowerCase()]; }
  getHeaders() { return { ...this._headers }; }
  getHeaderNames() { return Object.keys(this._headers); }
  hasHeader(name) { return this.getHeader(name) !== undefined; }
  removeHeader(name) { if (this.headersSent) throw error('ERR_HTTP2_HEADERS_SENT', 'Headers are already sent.'); delete this._headers[name.toLowerCase()]; }
  writeHead(code, message, headers) { this.statusCode = code; if (typeof message !== 'string') headers = message; if (headers) for (const [name, value] of Object.entries(headers)) this.setHeader(name, value); this.flushHeaders(); return this; }
  flushHeaders() {
    if (this.headersSent) return;
    if (this.sendDate && !this.hasHeader('date')) this.setHeader('date', new Date().toUTCString());
    this.headersSent = true; this.stream.respond({ ':status': this.statusCode, ...this._headers });
  }
  _write(data, encoding, done) { this.flushHeaders(); this.stream.write(data, encoding, done); }
  _final(done) { this.flushHeaders(); this.stream.end(done); }
  _destroy(reason, done) { if (reason) this.stream.destroy(reason); done(reason); }
  addTrailers(headers) { this._trailers = headers; this.stream._waitForTrailers = true; this.stream.once('wantTrailers', () => this.stream.sendTrailers(headers)); }
  setTimeout(milliseconds, callback) { this.stream.setTimeout(milliseconds, callback); return this; }
  writeContinue() { this.stream.additionalHeaders({ ':status': 100 }); }
  writeEarlyHints(headers) { this.stream.additionalHeaders({ ':status': 103, ...headers }); }
}
function attachServer(server, options, event) {
  server._http2Sessions = new Set();
  server.on(event, socket => {
    if (socket.encrypted && socket.alpnProtocol !== 'h2') {
      if (!server.emit('unknownProtocol', socket)) socket.destroy();
      return;
    }
    const session = new Http2Session(socket, true, options);
    server._http2Sessions.add(session); session.once('close', () => server._http2Sessions.delete(session));
    session.on('error', reason => { server.emit('sessionError', reason, session); });
    session.on('stream', (stream, headers, flags, raw) => {
      server.emit('stream', stream, headers, flags, raw);
      if (server.listenerCount('request')) server.emit('request', new Http2ServerRequest(stream, headers, raw), new Http2ServerResponse(stream));
    });
    server.emit('session', session);
  });
  server.updateSettings = settings => { options.settings = { ...options.settings, ...settings }; for (const session of server._http2Sessions) session.settings(settings); };
  return server;
}
class Http2Server extends net.Server {
  constructor(options = {}, handler) { if (typeof options === 'function') { handler = options; options = {}; } super(options); attachServer(this, options, 'connection'); if (handler) this.on('request', handler); }
}
class Http2SecureServer extends tls.Server {
  constructor(options = {}, handler) { super({ ...options, ALPNProtocols: ['h2'] }); attachServer(this, options, 'secureConnection'); if (handler) this.on('request', handler); }
}
function connect(authority, options = {}, listener) {
  if (typeof options === 'function') { listener = options; options = {}; }
  const url = authority instanceof URL ? authority : new URL(authority);
  if (!['http:', 'https:'].includes(url.protocol)) throw error('ERR_HTTP2_UNSUPPORTED_PROTOCOL', 'Unsupported HTTP/2 protocol.');
  const config = { ...options, host: url.hostname.replace(/^\[|\]$/g, ''), port: Number(url.port || (url.protocol === 'https:' ? 443 : 80)) };
  const socket = options.createConnection ? options.createConnection(url, config) : url.protocol === 'https:'
    ? tls.connect({ ...config, servername: options.servername || config.host, ALPNProtocols: ['h2'] }) : net.connect(config);
  const session = new Http2Session(socket, false, options, url);
  if (listener) session.once('connect', listener);
  return session;
}
module.exports = {
  constants, sensitiveHeaders, connect, Http2ServerRequest, Http2ServerResponse,
  createServer: (options, handler) => new Http2Server(options, handler),
  createSecureServer: (options, handler) => new Http2SecureServer(options, handler),
  getDefaultSettings: () => ({ ...defaults }), getPackedSettings, getUnpackedSettings,
};
