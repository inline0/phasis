'use strict';

const http = require('http');
const tls = require('tls');
const { urlToHttpOptions } = require('url');

class Agent extends http.Agent {
  constructor(options = {}) {
    super(options);
    this.defaultPort = 443;
    this.protocol = 'https:';
  }
  createConnection(options, callback) { return tls.connect(options, callback); }
  getName(options = {}) {
    return super.getName(options) + ':' + ['servername', 'ca', 'cert', 'key', 'pfx', 'rejectUnauthorized', 'minVersion', 'maxVersion']
      .map(name => String(options[name] === undefined ? '' : options[name])).join(':');
  }
}
const globalAgent = new Agent({ keepAlive: true, scheduling: 'lifo', timeout: 5000 });

class Server extends http.Server {
  constructor(options = {}, listener) {
    if (typeof options === 'function') { listener = options; options = {}; }
    super(options, listener);
    this._tlsOptions = { ...options, isServer: true };
  }
  _createSocket(handle) { return tls.Server.prototype._createSocket.call(this, handle); }
  setSecureContext(options) { return tls.Server.prototype.setSecureContext.call(this, options); }
}
function request(input, options, callback) {
  if (typeof options === 'function') { callback = options; options = undefined; }
  const base = typeof input === 'string' || input instanceof URL ? urlToHttpOptions(input instanceof URL ? input : new URL(input)) : input || {};
  const config = { ...base, ...options };
  config.protocol = config.protocol || 'https:';
  if (config.protocol !== 'https:') throw Object.assign(new TypeError(`Protocol "${config.protocol}" not supported. Expected "https:"`), { code: 'ERR_INVALID_PROTOCOL' });
  config.agent = config.agent === undefined ? globalAgent : config.agent === false ? new Agent() : config.agent;
  return new http.ClientRequest(config, callback);
}
function get(input, options, callback) { const result = request(input, options, callback); result.end(); return result; }
module.exports = { Agent, Server, globalAgent, request, get, createServer: (options, listener) => new Server(options, listener) };
