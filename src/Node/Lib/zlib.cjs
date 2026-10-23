'use strict';

const { Transform } = require('stream');
const { Buffer } = require('buffer');
const host = __phasisNodeHost.zlib;
const zlib = module.exports;
const constants = zlib.constants = Object.freeze({
  Z_NO_FLUSH: 0, Z_PARTIAL_FLUSH: 1, Z_SYNC_FLUSH: 2, Z_FULL_FLUSH: 3, Z_FINISH: 4, Z_BLOCK: 5,
  Z_OK: 0, Z_STREAM_END: 1, Z_NEED_DICT: 2, Z_ERRNO: -1, Z_STREAM_ERROR: -2,
  Z_DATA_ERROR: -3, Z_MEM_ERROR: -4, Z_BUF_ERROR: -5, Z_VERSION_ERROR: -6,
  Z_NO_COMPRESSION: 0, Z_BEST_SPEED: 1, Z_BEST_COMPRESSION: 9, Z_DEFAULT_COMPRESSION: -1,
  Z_FILTERED: 1, Z_HUFFMAN_ONLY: 2, Z_RLE: 3, Z_FIXED: 4, Z_DEFAULT_STRATEGY: 0,
  DEFLATE: 1, INFLATE: 2, GZIP: 3, GUNZIP: 4, DEFLATERAW: 5, INFLATERAW: 6, UNZIP: 7,
  BROTLI_DECODE: 8, BROTLI_ENCODE: 9,
  Z_MIN_WINDOWBITS: 8, Z_MAX_WINDOWBITS: 15, Z_DEFAULT_WINDOWBITS: 15,
  Z_MIN_CHUNK: 64, Z_MAX_CHUNK: Infinity, Z_DEFAULT_CHUNK: 16384,
  Z_MIN_MEMLEVEL: 1, Z_MAX_MEMLEVEL: 9, Z_DEFAULT_MEMLEVEL: 8,
  Z_MIN_LEVEL: -1, Z_MAX_LEVEL: 9, Z_DEFAULT_LEVEL: -1,
  BROTLI_OPERATION_PROCESS: 0, BROTLI_OPERATION_FLUSH: 1, BROTLI_OPERATION_FINISH: 2, BROTLI_OPERATION_EMIT_METADATA: 3,
  BROTLI_PARAM_MODE: 0, BROTLI_MODE_GENERIC: 0, BROTLI_MODE_TEXT: 1, BROTLI_MODE_FONT: 2, BROTLI_DEFAULT_MODE: 0,
  BROTLI_PARAM_QUALITY: 1, BROTLI_MIN_QUALITY: 0, BROTLI_MAX_QUALITY: 11, BROTLI_DEFAULT_QUALITY: 11,
  BROTLI_PARAM_LGWIN: 2, BROTLI_MIN_WINDOW_BITS: 10, BROTLI_MAX_WINDOW_BITS: 24, BROTLI_DEFAULT_WINDOW: 22,
  BROTLI_PARAM_LGBLOCK: 3, BROTLI_MIN_INPUT_BLOCK_BITS: 16, BROTLI_MAX_INPUT_BLOCK_BITS: 24,
  BROTLI_PARAM_DISABLE_LITERAL_CONTEXT_MODELING: 4, BROTLI_PARAM_SIZE_HINT: 5,
  BROTLI_PARAM_LARGE_WINDOW: 6, BROTLI_PARAM_NPOSTFIX: 7, BROTLI_PARAM_NDIRECT: 8,
  BROTLI_DECODER_RESULT_ERROR: 0, BROTLI_DECODER_RESULT_SUCCESS: 1,
  BROTLI_DECODER_RESULT_NEEDS_MORE_INPUT: 2, BROTLI_DECODER_RESULT_NEEDS_MORE_OUTPUT: 3,
  BROTLI_DECODER_PARAM_DISABLE_RING_BUFFER_REALLOCATION: 0, BROTLI_DECODER_PARAM_LARGE_WINDOW: 1,
});
for (const [name, value] of Object.entries(constants)) if (name.startsWith('Z_')) zlib[name] = value;
zlib.codes = {};
for (const name of ['Z_OK', 'Z_STREAM_END', 'Z_NEED_DICT', 'Z_ERRNO', 'Z_STREAM_ERROR', 'Z_DATA_ERROR', 'Z_MEM_ERROR', 'Z_BUF_ERROR', 'Z_VERSION_ERROR']) {
  zlib.codes[name] = constants[name]; zlib.codes[constants[name]] = name;
}
Object.freeze(zlib.codes);

function failure(code, message, Class = Error) { return Object.assign(new Class(message), { code }); }
function bytes(value) {
  if (typeof value === 'string') return Buffer.from(value);
  if (value instanceof ArrayBuffer) return new Uint8Array(value);
  if (ArrayBuffer.isView(value)) return new Uint8Array(value.buffer, value.byteOffset, value.byteLength);
  throw failure('ERR_INVALID_ARG_TYPE', 'The "buffer" argument must be of type string or an instance of Buffer, TypedArray, DataView, or ArrayBuffer', TypeError);
}
function config(value) {
  if (value == null) return {};
  if (typeof value !== 'object') throw failure('ERR_INVALID_ARG_TYPE', 'The "options" argument must be of type object', TypeError);
  const result = { ...value };
  for (const [name, min, max] of [['level', -1, 9], ['windowBits', 8, 15], ['memLevel', 1, 9], ['strategy', 0, 4], ['chunkSize', 64, Infinity], ['flush', 0, 5], ['finishFlush', 0, 5], ['maxOutputLength', 1, Number.MAX_SAFE_INTEGER]]) {
    const entry = result[name];
    if (entry !== undefined && (typeof entry !== 'number' || !Number.isInteger(entry) || entry < min || entry > max)) {
      throw failure('ERR_OUT_OF_RANGE', `The value of "options.${name}" is out of range. It must be >= ${min} and <= ${max}. Received ${entry}`, RangeError);
    }
  }
  if (result.dictionary !== undefined) result.dictionary = bytes(result.dictionary);
  return result;
}
function call(operation, ...args) {
  const result = host.call(operation, ...args);
  if (result.error) {
    const { message, ...properties } = result.error;
    throw Object.assign(new Error(message), properties);
  }
  return result.value;
}
function buffer(value) { return Buffer.from(value.buffer, value.byteOffset, value.byteLength); }
function outputLimit(length, options) {
  if (length > (options.maxOutputLength ?? Infinity)) throw failure('ERR_BUFFER_TOO_LARGE', `Cannot create a Buffer larger than ${options.maxOutputLength} bytes`, RangeError);
}

class ZlibBase extends Transform {
  constructor(mode, options) {
    options = config(options);
    super(options);
    this._mode = mode; this._options = options;
    this._codec = call('create', mode, options);
    this._handle = this._codec;
    this.bytesWritten = 0; this._outputLength = 0;
    this._flushFlag = options.flush ?? constants.Z_NO_FLUSH;
    this._finishFlushFlag = options.finishFlush ?? constants.Z_FINISH;
  }
  _transform(chunk, encoding, done) {
    try {
      const result = call('process', this._codec, bytes(chunk), this._flushFlag);
      this.bytesWritten = result.consumed;
      this._outputLength += result.bytes.length;
      outputLimit(this._outputLength, this._options);
      if (result.bytes.length) this.push(buffer(result.bytes));
      done();
    } catch (error) { done(error); }
  }
  _flush(done) {
    try {
      const result = call('process', this._codec, Buffer.alloc(0), this._finishFlushFlag);
      this.bytesWritten = result.consumed;
      this._outputLength += result.bytes.length;
      outputLimit(this._outputLength, this._options);
      if (result.bytes.length) this.push(buffer(result.bytes));
      done();
    } catch (error) { done(error); }
  }
  _destroy(error, done) {
    if (this._handle !== null) call('close', this._codec);
    this._handle = null;
    done(error);
  }
  close(cb) { if (cb) this.once('close', cb); this.destroy(); }
  reset() { call('reset', this._codec); this.bytesWritten = 0; this._outputLength = 0; }
  flush(kind, cb) {
    if (typeof kind === 'function') { cb = kind; kind = constants.Z_FULL_FLUSH; }
    kind ??= constants.Z_FULL_FLUSH;
    if (this.writableFinished) { if (cb) process.nextTick(cb); return; }
    this.write(Buffer.alloc(0), () => {
      try {
        const result = call('process', this._codec, Buffer.alloc(0), kind);
        if (result.bytes.length) this.push(buffer(result.bytes));
        if (cb) cb();
      } catch (error) { if (cb) cb(error); else this.destroy(error); }
    });
  }
  params(level, strategy, cb) {
    if (level === (this._options.level ?? -1) && strategy === (this._options.strategy ?? 0)) { process.nextTick(cb); return; }
    const error = failure('ERR_FEATURE_UNAVAILABLE_ON_PLATFORM', 'PHP zlib does not expose deflateParams');
    if (cb) process.nextTick(cb, error); else throw error;
  }
  _processChunk(chunk, flush, cb) {
    if (cb) { this._flushFlag = flush; this.write(chunk, cb); return; }
    try {
      const result = call('process', this._codec, bytes(chunk), flush);
      this.bytesWritten = result.consumed;
      outputLimit(result.bytes.length, this._options);
      return buffer(result.bytes);
    } finally { this.close(); }
  }
}
zlib.ZlibBase = ZlibBase;
for (const name of ['Deflate', 'Inflate', 'Gzip', 'Gunzip', 'DeflateRaw', 'InflateRaw', 'Unzip']) {
  const Constructor = class extends ZlibBase { constructor(options) { super(name, options); } };
  Object.defineProperty(Constructor, 'name', { value: name });
  zlib[name] = Constructor;
  zlib['create' + name] = options => new Constructor(options);
  const operation = name[0].toLowerCase() + name.slice(1);
  zlib[operation + 'Sync'] = (input, value) => {
    const options = config(value);
    const data = bytes(input);
    const stream = new Constructor(options);
    const result = stream._processChunk(data, options.finishFlush ?? constants.Z_FINISH);
    return options.info ? { buffer: result, engine: stream } : result;
  };
  zlib[operation] = (input, value, cb) => {
    if (typeof value === 'function') { cb = value; value = undefined; }
    if (typeof cb !== 'function') throw failure('ERR_INVALID_ARG_TYPE', 'The "callback" argument must be of type function', TypeError);
    const data = bytes(input); const options = config(value);
    process.nextTick(() => {
      let result;
      try { result = zlib[operation + 'Sync'](data, options); }
      catch (error) { cb(error); return; }
      cb(null, result);
    });
  };
}

zlib.crc32 = (input, value = 0) => {
  const data = bytes(input);
  let crc = (value ^ 0xffffffff) >>> 0;
  for (const byte of data) {
    crc ^= byte;
    for (let bit = 0; bit < 8; bit++) crc = crc >>> 1 ^ (crc & 1 ? 0xedb88320 : 0);
  }
  return (crc ^ 0xffffffff) >>> 0;
};

function brotliOptions(value) {
  const options = config(value);
  const params = options.params ?? {};
  for (const [name, min, max] of [[constants.BROTLI_PARAM_MODE, 0, 2], [constants.BROTLI_PARAM_QUALITY, 0, 11], [constants.BROTLI_PARAM_LGWIN, 10, 24]]) {
    if (params[name] !== undefined && (!Number.isInteger(params[name]) || params[name] < min || params[name] > max)) {
      throw failure('ERR_BROTLI_INVALID_PARAM', `Invalid Brotli parameter: ${name}`, RangeError);
    }
  }
  return options;
}
function brotli(input, options, decompress) {
  try {
    const result = decompress
      ? require('./brotli-decoder.cjs')(bytes(input))
      : require('./brotli-encoder.cjs')(bytes(input), {
        quality: options.params?.[constants.BROTLI_PARAM_QUALITY],
        lgwin: options.params?.[constants.BROTLI_PARAM_LGWIN],
        mode: options.params?.[constants.BROTLI_PARAM_MODE],
      });
    outputLimit(result.length, options);
    return buffer(result);
  } catch (error) {
    if (error.code) throw error;
    if (/unexpected end/i.test(error.message)) throw Object.assign(failure('Z_BUF_ERROR', 'unexpected end of file'), { errno: -5 });
    throw Object.assign(failure('ERR_BROTLI_COMPRESSION_FAILED', error.message || 'Brotli codec failed'), { errno: -1 });
  }
}
class BrotliBase extends Transform {
  constructor(decompress, value) {
    const options = brotliOptions(value);
    super(options);
    this._options = options; this._decompress = decompress;
    this._chunks = []; this.bytesWritten = 0; this._handle = {};
  }
  _transform(chunk, encoding, done) {
    const data = Buffer.from(bytes(chunk));
    this._chunks.push(data); this.bytesWritten += data.length; done();
  }
  _flush(done) {
    try {
      const data = Buffer.concat(this._chunks); this._chunks = [];
      this.push(brotli(data, this._options, this._decompress)); done();
    } catch (error) { done(error); }
  }
  _destroy(error, done) { this._chunks = []; this._handle = null; done(error); }
  close(cb) { if (cb) this.once('close', cb); this.destroy(); }
  reset() { this._chunks = []; this.bytesWritten = 0; }
  flush(kind, cb) {
    if (typeof kind === 'function') { cb = kind; kind = constants.BROTLI_OPERATION_FLUSH; }
    if (kind === constants.BROTLI_OPERATION_FINISH) { this.end(cb); return; }
    const error = failure('ERR_FEATURE_UNAVAILABLE_ON_PLATFORM', 'The bundled Brotli codec requires complete frames and cannot flush a partial frame');
    if (cb) process.nextTick(cb, error); else throw error;
  }
}
for (const [name, decompress] of [['BrotliCompress', false], ['BrotliDecompress', true]]) {
  const Constructor = class extends BrotliBase { constructor(options) { super(decompress, options); } };
  Object.defineProperty(Constructor, 'name', { value: name });
  zlib[name] = Constructor;
  zlib['create' + name] = options => new Constructor(options);
  const operation = name[0].toLowerCase() + name.slice(1);
  zlib[operation + 'Sync'] = (input, value) => {
    const options = brotliOptions(value);
    const result = brotli(input, options, decompress);
    if (!options.info) return result;
    const engine = new Constructor(options); engine.bytesWritten = bytes(input).length; engine.close();
    return { buffer: result, engine };
  };
  zlib[operation] = (input, value, cb) => {
    if (typeof value === 'function') { cb = value; value = undefined; }
    if (typeof cb !== 'function') throw failure('ERR_INVALID_ARG_TYPE', 'The "callback" argument must be of type function', TypeError);
    const data = bytes(input); const options = brotliOptions(value);
    process.nextTick(() => {
      let result;
      try { result = zlib[operation + 'Sync'](data, options); }
      catch (error) { cb(error); return; }
      cb(null, result);
    });
  };
}
