'use strict';

const { HTTPParser: Parser, methods } = require('./http-parser.cjs');
const { executionAsyncId } = require('async_hooks');
const { Buffer } = require('buffer');

class HTTPParser {
  constructor() {
    this._parser = null;
    this._paused = false;
    this._pending = [];
    this._asyncId = executionAsyncId();
  }
  initialize(type, resource, maxHeaderSize = 0) {
    if (type !== HTTPParser.REQUEST && type !== HTTPParser.RESPONSE) throw new TypeError('Invalid HTTP parser type');
    this._parser = new Parser(type === HTTPParser.REQUEST ? Parser.REQUEST : Parser.RESPONSE);
    if (maxHeaderSize) this._parser.maxHeaderSize = maxHeaderSize;
    this._paused = false;
    this._pending = [];
    this._parser[Parser.kOnHeaders] = (headers, url) => this._call(HTTPParser.kOnHeaders, headers, url);
    this._parser[Parser.kOnHeadersComplete] = info => this._call(HTTPParser.kOnHeadersComplete, info.versionMajor, info.versionMinor, info.headers, info.method, info.url, info.statusCode, info.statusMessage, info.upgrade, info.shouldKeepAlive);
    this._parser[Parser.kOnBody] = chunk => this._call(HTTPParser.kOnBody, chunk);
    this._parser[Parser.kOnMessageComplete] = () => this._call(HTTPParser.kOnMessageComplete);
    for (const state of ['REQUEST_LINE', 'RESPONSE_LINE', 'HEADER', 'BODY_CHUNKHEAD', 'BODY_CHUNK', 'BODY_CHUNKEMPTYLINE', 'BODY_CHUNKTRAILERS', 'BODY_RAW', 'BODY_SIZED']) {
      const execute = this._parser[state];
      this._parser[state] = (...args) => this._paused || execute.apply(this._parser, args);
    }
  }
  _call(index, ...args) { return typeof this[index] === 'function' ? this[index](...args) : undefined; }
  execute(bytes) {
    if (!this._parser) throw new Error('HTTP parser has not been initialized');
    if (this._paused) { this._pending.push(Buffer.from(bytes)); return 0; }
    const result = this._parser.execute(bytes);
    if (typeof result === 'number' && result < bytes.length && this._paused) this._pending.push(Buffer.from(bytes.subarray(result)));
    return result;
  }
  finish() { return this._parser && this._parser.finish(); }
  pause() { this._paused = true; }
  resume() { this._paused = false; while (!this._paused && this._pending.length) this.execute(this._pending.shift()); }
  free() { this._parser = null; this._pending = []; }
  close() { this.free(); }
  getAsyncId() { return this._asyncId; }
}
Object.assign(HTTPParser, { REQUEST: 1, RESPONSE: 2, kOnMessageBegin: 0, kOnHeaders: 1, kOnHeadersComplete: 2, kOnBody: 3, kOnMessageComplete: 4, kOnExecute: 5, kOnTimeout: 6 });
module.exports = { HTTPParser, methods };
