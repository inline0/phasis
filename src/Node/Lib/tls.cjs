'use strict';

const net = require('net');
const { Buffer } = require('buffer');
const host = __phasisNodeHost.net;

function call(operation, ...args) {
  const result = host.call(operation, ...args);
  if (result.error) throw Object.assign(new Error(result.error.message), result.error);
  return result.value;
}
function pem(value) {
  return Array.isArray(value) ? value.map(pem).join('\n') : Buffer.isBuffer(value) ? value.toString() : value;
}
function configuration(options = {}) {
  const result = { ...(options.secureContext ? options.secureContext.context : {}), ...options };
  for (const name of ['ca', 'cert', 'key']) if (result[name] !== undefined) result[name] = pem(result[name]);
  if (result.key && typeof result.key === 'object') {
    result.passphrase = result.key.passphrase === undefined ? result.passphrase : result.key.passphrase;
    result.key = pem(result.key.pem);
  }
  if (Buffer.isBuffer(result.ALPNProtocols)) {
    const protocols = [];
    for (let offset = 0; offset < result.ALPNProtocols.length;) {
      const length = result.ALPNProtocols[offset++];
      if (offset + length > result.ALPNProtocols.length) throw new TypeError('Invalid ALPN protocol list.');
      protocols.push(result.ALPNProtocols.toString('ascii', offset, offset + length));
      offset += length;
    }
    result.ALPNProtocols = protocols;
  }
  if (result.checkServerIdentity !== undefined && typeof result.checkServerIdentity !== 'function') throw new TypeError('checkServerIdentity must be a function.');
  result.customIdentityCheck = typeof result.checkServerIdentity === 'function';
  return result;
}
class SecureContext {
  constructor(options = {}) { this.context = configuration(options); }
}
function createSecureContext(options) { return new SecureContext(options); }

function dnsMatch(hostname, pattern) {
  hostname = hostname.toLowerCase().replace(/\.$/, '');
  pattern = pattern.toLowerCase().replace(/\.$/, '');
  if (!pattern.includes('*')) return hostname === pattern;
  const hostParts = hostname.split('.'), parts = pattern.split('.');
  return parts.length >= 3 && parts.length === hostParts.length && parts[0] === '*' &&
    !hostParts[0].startsWith('xn--') && hostParts.slice(1).join('.') === parts.slice(1).join('.');
}
function ip(value) {
  return value.includes(':') ? new URL('http://[' + value.replace(/^\[|\]$/g, '') + ']').hostname : value;
}
function checkServerIdentity(hostname, cert) {
  hostname = String(hostname);
  const names = String(cert.subjectaltname || '').split(/,\s*/);
  const ips = names.filter(name => name.startsWith('IP Address:')).map(name => name.slice(11));
  const dns = names.filter(name => name.startsWith('DNS:')).map(name => name.slice(4));
  let matched;
  if (net.isIP(hostname)) matched = ips.some(address => ip(address) === ip(hostname));
  else if (dns.length) matched = dns.some(name => dnsMatch(hostname, name));
  else {
    const common = cert.subject && cert.subject.CN;
    matched = (Array.isArray(common) ? common : [common]).some(name => typeof name === 'string' && dnsMatch(hostname, name));
  }
  if (matched) return;
  const reason = `Host: ${hostname}. is not in the cert's altnames: ${cert.subjectaltname || cert.subject && cert.subject.CN || ''}`;
  return Object.assign(new Error('Hostname/IP does not match certificate\'s altnames: ' + reason), {
    code: 'ERR_TLS_CERT_ALTNAME_INVALID', reason, host: hostname, cert,
  });
}

class TLSSocket extends net.Socket {
  constructor(socket, options = {}) {
    if (socket && !(socket instanceof net.Socket)) { options = socket; socket = undefined; }
    const config = configuration(options);
    if (socket && socket._handle !== null) config.handle = socket._handle;
    super(config);
    if (socket && socket._handle !== null) socket._handle = null;
    this.encrypted = true;
    this.authorized = false;
    this.authorizationError = null;
    this._tlsOptions = config;
    this._secureEstablished = false;
    this._secureConnecting = true;
    this._tlsStarted = false;
    this._tlsInfo = null;
    this.servername = config.servername || false;
    this.alpnProtocol = false;
    if (this._handle !== null) this._startTls();
  }
  _startTls() {
    if (this._tlsStarted || this._handle === null) return;
    this._tlsStarted = true;
    try { call('startTls', this._handle, this._tlsOptions); }
    catch (error) { process.nextTick(() => this.destroy(error)); }
  }
  _native(event, value) {
    if (event === 'connect') this._startTls();
    if (event !== 'secure') return super._native(event, value);
    this._tlsInfo = value;
    this.alpnProtocol = value.alpnProtocol;
    this.authorized = value.authorized;
    this.authorizationError = value.authorizationError;
    if (!this._tlsOptions.isServer) {
      const check = this._tlsOptions.checkServerIdentity || checkServerIdentity;
      const error = check(this._tlsOptions.servername || this._tlsOptions.host || 'localhost', this.getPeerCertificate());
      if (error) {
        this.authorized = false;
        this.authorizationError = error.code;
        if (this._tlsOptions.rejectUnauthorized !== false) { this.destroy(error); return; }
      }
    }
    this._secureConnecting = false;
    this._secureEstablished = true;
    this.emit('secure');
    if (!this._tlsOptions.isServer) this.emit('secureConnect');
  }
  _write(chunk, encoding, done) {
    if (!this._secureEstablished) { this.once('secure', () => super._write(chunk, encoding, done)); return; }
    super._write(chunk, encoding, done);
  }
  _final(done) {
    if (!this._secureEstablished) { this.once('secure', () => super._final(done)); return; }
    super._final(done);
  }
  getPeerCertificate(detailed = false) {
    if (!this._tlsInfo) return {};
    const cert = { ...this._tlsInfo.certificate };
    if (cert.rawBase64) { cert.raw = Buffer.from(cert.rawBase64, 'base64'); delete cert.rawBase64; }
    if (detailed && cert.issuer && cert.subject && JSON.stringify(cert.issuer) === JSON.stringify(cert.subject)) cert.issuerCertificate = cert;
    return cert;
  }
  getProtocol() { return this._tlsInfo ? this._tlsInfo.protocol : null; }
  getCipher() { return this._tlsInfo ? { ...this._tlsInfo.cipher } : undefined; }
}

class Server extends net.Server {
  constructor(options = {}, listener) {
    if (typeof options === 'function') { listener = options; options = {}; }
    super(options);
    this._tlsOptions = configuration({ ...options, isServer: true });
    if (listener) this.on('secureConnection', listener);
  }
  _createSocket(handle) {
    const socket = new TLSSocket(undefined, { ...this._tlsOptions, handle });
    socket.once('secure', () => this.emit('secureConnection', socket));
    socket.on('error', error => { if (!socket._secureEstablished) this.emit('tlsClientError', error, socket); });
    return socket;
  }
  setSecureContext(options) { this._tlsOptions = configuration({ ...options, isServer: true }); }
}
function connect(...args) {
  let options;
  if (args[0] !== null && typeof args[0] === 'object') options = { ...args[0] };
  else {
    options = { port: args[0] };
    let index = 1;
    if (typeof args[index] === 'string') options.host = args[index++];
    if (args[index] !== null && typeof args[index] === 'object') Object.assign(options, args[index]);
  }
  options.host = options.host || options.hostname || 'localhost';
  const callback = typeof args[args.length - 1] === 'function' ? args[args.length - 1] : undefined;
  const socket = new TLSSocket(options.socket, options);
  if (callback) socket.once('secureConnect', callback);
  if (socket._handle === null) socket.connect(options);
  return socket;
}
module.exports = {
  TLSSocket, Server, SecureContext, connect, createConnection: connect, createSecureContext, checkServerIdentity,
  createServer: (options, listener) => new Server(options, listener),
  DEFAULT_MIN_VERSION: 'TLSv1.2', DEFAULT_MAX_VERSION: 'TLSv1.3',
};
