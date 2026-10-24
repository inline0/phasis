'use strict';

const { Duplex } = require('stream');
const { EventEmitter } = require('events');
const { Buffer } = require('buffer');
const host = __phasisNodeHost.net;

function call(operation, ...args) {
  const result = host.call(operation, ...args);
  if (result.error) throw Object.assign(new Error(result.error.message), result.error);
  return result.value;
}

function normalize(args) {
  let options;
  if (args[0] !== null && typeof args[0] === 'object') options = { ...args[0] };
  else if (typeof args[0] === 'string' && !/^\d+$/.test(args[0])) options = { path: args[0] };
  else options = { port: args[0] === undefined ? 0 : Number(args[0]) };
  if (typeof args[1] === 'string' && options.path === undefined) options.host = args[1];
  if (typeof args[1] === 'number') options.backlog = args[1];
  if (typeof args[2] === 'number') options.backlog = args[2];
  const callback = typeof args[args.length - 1] === 'function' ? args[args.length - 1] : undefined;
  if (options.path === undefined && (!Number.isInteger(options.port) || options.port < 0 || options.port > 65535)) {
    throw Object.assign(new RangeError('Port should be >= 0 and < 65536'), { code: 'ERR_SOCKET_BAD_PORT' });
  }
  return [options, callback];
}

class Socket extends Duplex {
  constructor(options = {}) {
    super({ ...options, allowHalfOpen: !!options.allowHalfOpen, autoDestroy: true, emitClose: true });
    this._handle = null;
    this.connecting = false;
    this.pending = true;
    this.bytesRead = 0;
    this.bytesWritten = 0;
    this.timeout = 0;
    this._timer = null;
    this._referenced = true;
    this._noDelay = true;
    this._keepAlive = false;
    this._onNative = (event, value) => this._native(event, value);
    if (options.handle !== undefined) {
      this._handle = options.handle;
      this.pending = false;
      call('callback', this._handle, this._onNative);
      call('pause', this._handle, !!options.pauseOnCreate);
    }
    if (options.signal) {
      const abort = () => this.destroy(Object.assign(new Error('The operation was aborted'), { name: 'AbortError', code: 'ABORT_ERR', cause: options.signal.reason }));
      if (options.signal.aborted) process.nextTick(abort);
      else { options.signal.addEventListener('abort', abort, { once: true }); this.once('close', () => options.signal.removeEventListener('abort', abort)); }
    }
  }
  connect(...args) {
    const [options, callback] = normalize(args);
    if (callback) this.once('connect', callback);
    if (this._handle !== null) throw Object.assign(new Error('Socket is already connected'), { code: 'ERR_SOCKET_IS_CONNECTED' });
    this.connecting = true;
    this.pending = true;
    try {
      this._handle = call('connect', options, this._onNative);
      call('ref', this._handle, this._referenced);
    } catch (error) { process.nextTick(() => this.destroy(error)); }
    return this;
  }
  _native(event, value) {
    if (event === 'connect') {
      this.connecting = false;
      this.pending = false;
      this._touch();
      this.emit('connect');
      this.emit('ready');
    } else if (event === 'data') {
      this._touch();
      this.bytesRead += value.length;
      if (!this.push(Buffer.from(value)) && this._handle !== null) call('pause', this._handle, true);
    } else if (event === 'end') {
      this.push(null);
    } else if (event === 'error') {
      this.destroy(Object.assign(new Error(value.message), value));
    } else if (event === 'close') {
      this._handle = null;
      this.connecting = false;
      if (!this.destroyed) this.destroy();
    }
  }
  _read() { if (this._handle !== null) call('pause', this._handle, false); }
  _write(chunk, encoding, callback) {
    if (this.connecting) { this.once('connect', () => this._write(chunk, encoding, callback)); return; }
    if (this._handle === null) { callback(Object.assign(new Error('Socket is closed'), { code: 'ERR_SOCKET_CLOSED' })); return; }
    if (!Buffer.isBuffer(chunk)) chunk = Buffer.from(chunk, encoding);
    this.bytesWritten += chunk.length;
    this._touch();
    try { call('write', this._handle, chunk, callback); } catch (error) { callback(error); }
  }
  _final(callback) {
    if (this.connecting) { this.once('connect', () => this._final(callback)); return; }
    try { if (this._handle !== null) call('end', this._handle); callback(); }
    catch (error) { callback(error); }
  }
  _destroy(error, callback) {
    if (this._timer !== null) clearTimeout(this._timer);
    const handle = this._handle;
    this._handle = null;
    this.connecting = false;
    if (handle !== null) call('close', handle);
    callback(error);
  }
  _touch() {
    if (this._timer !== null) clearTimeout(this._timer);
    if (this.timeout > 0 && !this.destroyed) {
      this._timer = setTimeout(() => { this._timer = null; this.emit('timeout'); }, this.timeout);
      if (this._timer.unref) this._timer.unref();
    }
  }
  setTimeout(timeout, callback) {
    if (typeof timeout !== 'number' || timeout < 0 || !Number.isFinite(timeout)) throw new RangeError('timeout must be a non-negative number');
    this.timeout = timeout;
    if (callback) this.once('timeout', callback);
    this._touch();
    return this;
  }
  setNoDelay(enabled = true) { this._noDelay = !!enabled; if (this._handle !== null) call('noDelay', this._handle, this._noDelay); return this; }
  setKeepAlive(enabled = false, initialDelay = 0) { this._keepAlive = !!enabled; if (this._handle !== null) call('keepAlive', this._handle, this._keepAlive, initialDelay); return this; }
  ref() { this._referenced = true; if (this._handle !== null) call('ref', this._handle, true); return this; }
  unref() { this._referenced = false; if (this._handle !== null) call('ref', this._handle, false); return this; }
  address() { return this._handle === null ? {} : call('address', this._handle, false) || {}; }
  _remote() { return this._handle === null ? {} : call('address', this._handle, true) || {}; }
  get localAddress() { return this.address().address; }
  get localPort() { return this.address().port; }
  get localFamily() { return this.address().family; }
  get remoteAddress() { return this._remote().address; }
  get remotePort() { return this._remote().port; }
  get remoteFamily() { return this._remote().family; }
  get readyState() { return this.connecting ? 'opening' : this.destroyed ? 'closed' : this.readable && this.writable ? 'open' : this.readable ? 'readOnly' : this.writable ? 'writeOnly' : 'closed'; }
  get bufferSize() { return this.writableLength; }
  destroySoon() { if (this.writableFinished) this.destroy(); else this.end(() => this.destroy()); return this; }
  resetAndDestroy() { return this.destroy(); }
}

class Server extends EventEmitter {
  constructor(options = {}, listener) {
    super();
    if (typeof options === 'function') { listener = options; options = {}; }
    this._options = options;
    this._handle = null;
    this._sockets = new Set();
    this._closing = false;
    this._referenced = true;
    this.listening = false;
    if (listener) this.on('connection', listener);
  }
  listen(...args) {
    if (this._handle !== null) throw Object.assign(new Error('Listen method has been called more than once without closing'), { code: 'ERR_SERVER_ALREADY_LISTEN' });
    const [options, callback] = normalize(args);
    if (callback) this.once('listening', callback);
    this._closing = false;
    try {
      this._handle = call('listen', options, (event, value) => {
        if (event === 'connection') {
          const socket = this._createSocket(value);
          socket.server = this;
          if (this.maxConnections !== undefined && this._sockets.size >= this.maxConnections) {
            this.emit('drop', { localAddress: socket.localAddress, localPort: socket.localPort, localFamily: socket.localFamily, remoteAddress: socket.remoteAddress, remotePort: socket.remotePort, remoteFamily: socket.remoteFamily });
            socket.destroy();
            return;
          }
          this._sockets.add(socket);
          socket.once('close', () => { this._sockets.delete(socket); this._maybeClose(); });
          this.emit('connection', socket);
        }
      });
      call('ref', this._handle, this._referenced);
      this.listening = true;
      process.nextTick(() => { if (this.listening) this.emit('listening'); });
      if (options.signal) {
        const close = () => this.close();
        if (options.signal.aborted) process.nextTick(close);
        else { options.signal.addEventListener('abort', close, { once: true }); this.once('close', () => options.signal.removeEventListener('abort', close)); }
      }
    } catch (error) { process.nextTick(() => this.emit('error', error)); }
    return this;
  }
  address() { return this._handle === null ? null : call('address', this._handle, false); }
  _createSocket(handle) { return new Socket({ ...this._options, handle, pauseOnCreate: this._options.pauseOnConnect }); }
  close(callback) {
    if (callback) this.once('close', callback);
    if (!this.listening) {
      const error = Object.assign(new Error('Server is not running.'), { code: 'ERR_SERVER_NOT_RUNNING' });
      process.nextTick(() => this.emit('close', error));
      return this;
    }
    this.listening = false;
    this._closing = true;
    const handle = this._handle;
    this._handle = null;
    if (handle !== null) call('close', handle);
    this._maybeClose();
    return this;
  }
  _maybeClose() { if (this._closing && this._sockets.size === 0) { this._closing = false; process.nextTick(() => this.emit('close')); } }
  getConnections(callback) { process.nextTick(callback, null, this._sockets.size); }
  ref() { this._referenced = true; if (this._handle !== null) call('ref', this._handle, true); return this; }
  unref() { this._referenced = false; if (this._handle !== null) call('ref', this._handle, false); return this; }
  [Symbol.asyncDispose]() { return new Promise((resolve, reject) => this.close(error => error && error.code !== 'ERR_SERVER_NOT_RUNNING' ? reject(error) : resolve())); }
}

const isIP = input => typeof input === 'string' ? call('isIP', input) : 0;
module.exports = {
  Socket, Stream: Socket, Server,
  createServer: (options, listener) => new Server(options, listener),
  createConnection: (...args) => new Socket(typeof args[0] === 'object' ? args[0] : {}).connect(...args),
  connect: (...args) => new Socket(typeof args[0] === 'object' ? args[0] : {}).connect(...args),
  isIP, isIPv4: input => isIP(input) === 4, isIPv6: input => isIP(input) === 6,
};
