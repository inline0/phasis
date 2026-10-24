'use strict';

const host = __phasisNodeHost.crypto;
const { Buffer } = require('buffer');
const { Transform } = require('stream');
const webcrypto = globalThis.crypto;

function fail(code, message, Constructor = TypeError) {
  const error = new Constructor(message);
  error.code = code;
  throw error;
}
function integer(value, name, minimum, maximum) {
  if (typeof value !== 'number') fail('ERR_INVALID_ARG_TYPE', `The "${name}" argument must be of type number.`);
  if (!Number.isInteger(value) || value < minimum || value > maximum) {
    fail('ERR_OUT_OF_RANGE', `The value of "${name}" is out of range. It must be >= ${minimum} and <= ${maximum}. Received ${value}`, RangeError);
  }
  return value;
}
function bytes(value, encoding, name = 'data', strings = true) {
  if (strings && typeof value === 'string') return Buffer.from(value, encoding);
  if (ArrayBuffer.isView(value)) return Buffer.from(value.buffer, value.byteOffset, value.byteLength);
  if (value instanceof ArrayBuffer || typeof SharedArrayBuffer !== 'undefined' && value instanceof SharedArrayBuffer) return Buffer.from(value);
  fail('ERR_INVALID_ARG_TYPE', `The "${name}" argument must be a string, Buffer, TypedArray, DataView, or ArrayBuffer.`);
}
function callback(value) {
  if (typeof value !== 'function') fail('ERR_INVALID_ARG_TYPE', 'The "callback" argument must be of type function.');
  return value;
}
function asyncResult(fn, done) {
  process.nextTick(() => {
    let value;
    try { value = fn(); } catch (error) { done(error); return; }
    done(null, value);
  });
}
function algorithm(value) {
  if (typeof value !== 'string') fail('ERR_INVALID_ARG_TYPE', 'The "algorithm" argument must be of type string.');
  return value;
}
function options(value) {
  if (value !== undefined && (value === null || typeof value !== 'object')) fail('ERR_INVALID_ARG_TYPE', 'The "options" argument must be of type object.');
  return value || {};
}

class Hash extends Transform {
  constructor(name, configuration) {
    super(configuration);
    const opts = options(configuration);
    if (opts.outputLength !== undefined) {
      integer(opts.outputLength, 'options.outputLength', 0, 0xffffffff);
      if (opts.outputLength !== host.hashLength(algorithm(name))) fail('ERR_OSSL_EVP_NOT_XOF_OR_INVALID_LENGTH', 'Variable-length digests are unavailable from this PHP cryptography provider.', Error);
    }
    this._hash = host.createHash(algorithm(name));
    this._digested = false;
  }
  update(data, encoding) {
    if (this._digested) fail('ERR_CRYPTO_HASH_FINALIZED', 'Digest already called', Error);
    host.updateHash(this._hash, bytes(data, encoding));
    return this;
  }
  digest(encoding) {
    if (this._digested) fail('ERR_CRYPTO_HASH_FINALIZED', 'Digest already called', Error);
    const result = Buffer.from(host.digestHash(this._hash));
    this._digested = true;
    return encoding && encoding !== 'buffer' ? result.toString(encoding) : result;
  }
  copy(configuration) {
    if (this._digested) fail('ERR_CRYPTO_HASH_FINALIZED', 'Digest already called', Error);
    const opts = options(configuration);
    if (opts.outputLength !== undefined) {
      integer(opts.outputLength, 'options.outputLength', 0, 0xffffffff);
      fail('ERR_OSSL_EVP_NOT_XOF_OR_INVALID_LENGTH', 'Changing digest length is unavailable from this PHP cryptography provider.', Error);
    }
    const copy = Object.create(Hash.prototype);
    Transform.call(copy, configuration);
    copy._hash = host.copyHash(this._hash);
    copy._digested = false;
    return copy;
  }
  _transform(chunk, encoding, done) {
    try { this.update(chunk, encoding); done(); } catch (error) { done(error); }
  }
  _flush(done) {
    try { this.push(this.digest()); done(); } catch (error) { done(error); }
  }
}

class Hmac extends Transform {
  constructor(name, key, configuration) {
    super(configuration);
    const opts = options(configuration);
    this._hash = host.createHash(algorithm(name), bytes(key, opts.encoding, 'key'));
    this._digested = false;
  }
  update(data, encoding) { return Hash.prototype.update.call(this, data, encoding); }
  digest(encoding) {
    if (this._digested) return encoding && encoding !== 'buffer' ? '' : Buffer.alloc(0);
    return Hash.prototype.digest.call(this, encoding);
  }
  _transform(chunk, encoding, done) { return Hash.prototype._transform.call(this, chunk, encoding, done); }
  _flush(done) { return Hash.prototype._flush.call(this, done); }
}

function randomBytes(size, done) {
  integer(size, 'size', 0, 0x7fffffff);
  if (done === undefined) return Buffer.from(host.randomBytes(size));
  callback(done);
  asyncResult(() => Buffer.from(host.randomBytes(size)), done);
}
function randomFillSync(buffer, offset = 0, size) {
  const view = bytes(buffer, undefined, 'buf', false);
  const width = buffer.BYTES_PER_ELEMENT || 1;
  integer(offset, 'offset', 0, view.length / width);
  if (size === undefined) size = view.length / width - offset;
  integer(size, 'size', 0, view.length / width - offset);
  view.set(host.randomBytes(size * width), offset * width);
  return buffer;
}
function randomFill(buffer, offset, size, done) {
  if (typeof offset === 'function') { done = offset; offset = 0; size = undefined; }
  else if (typeof size === 'function') { done = size; size = undefined; }
  callback(done);
  const view = bytes(buffer, undefined, 'buf', false);
  const width = buffer.BYTES_PER_ELEMENT || 1;
  if (offset === undefined) offset = 0;
  integer(offset, 'offset', 0, view.length / width);
  if (size === undefined) size = view.length / width - offset;
  integer(size, 'size', 0, view.length / width - offset);
  asyncResult(() => randomFillSync(buffer, offset, size), done);
}
function randomInt(min, max, done) {
  if (typeof max === 'function') { done = max; max = undefined; }
  if (max === undefined) { max = min; min = 0; }
  integer(min, 'min', Number.MIN_SAFE_INTEGER, Number.MAX_SAFE_INTEGER);
  integer(max, 'max', min + 1, Number.MAX_SAFE_INTEGER);
  if (max - min > 0xffffffffffff) fail('ERR_OUT_OF_RANGE', 'The value of "max - min" must be <= 281474976710655.', RangeError);
  if (done === undefined) return host.randomInt(min, max);
  callback(done);
  asyncResult(() => host.randomInt(min, max), done);
}
function timingSafeEqual(a, b) {
  a = bytes(a, undefined, 'buf1', false);
  b = bytes(b, undefined, 'buf2', false);
  if (a.length !== b.length) fail('ERR_CRYPTO_TIMING_SAFE_EQUAL_LENGTH', 'Input buffers must have the same byte length', RangeError);
  return host.timingSafeEqual(a, b);
}
function pbkdf2Sync(password, salt, iterations, keylen, digest) {
  password = bytes(password, undefined, 'password');
  salt = bytes(salt, undefined, 'salt');
  integer(iterations, 'iterations', 1, 0x7fffffff);
  integer(keylen, 'keylen', 0, 0x7fffffff);
  return Buffer.from(host.pbkdf2(password, salt, iterations, keylen, algorithm(digest)));
}
function pbkdf2(password, salt, iterations, keylen, digest, done) {
  callback(done);
  password = bytes(password, undefined, 'password');
  salt = bytes(salt, undefined, 'salt');
  integer(iterations, 'iterations', 1, 0x7fffffff);
  integer(keylen, 'keylen', 0, 0x7fffffff);
  algorithm(digest);
  asyncResult(() => pbkdf2Sync(password, salt, iterations, keylen, digest), done);
}
function hkdfSync(digest, ikm, salt, info, keylen) {
  algorithm(digest);
  ikm = bytes(ikm, undefined, 'ikm');
  salt = bytes(salt, undefined, 'salt');
  info = bytes(info, undefined, 'info');
  integer(keylen, 'keylen', 0, 0x7fffffff);
  if (info.length > 1024) fail('ERR_OUT_OF_RANGE', 'The value of "info" must not contain more than 1024 bytes.', RangeError);
  if (keylen > 255 * host.hashLength(digest)) fail('ERR_CRYPTO_INVALID_KEYLEN', 'Invalid key length', RangeError);
  const result = host.hkdf(digest, ikm, salt, info, keylen);
  return result.buffer.slice(result.byteOffset, result.byteOffset + result.byteLength);
}
function hkdf(digest, ikm, salt, info, keylen, done) {
  callback(done);
  algorithm(digest);
  ikm = bytes(ikm, undefined, 'ikm');
  salt = bytes(salt, undefined, 'salt');
  info = bytes(info, undefined, 'info');
  integer(keylen, 'keylen', 0, 0x7fffffff);
  if (info.length > 1024) fail('ERR_OUT_OF_RANGE', 'The value of "info" must not contain more than 1024 bytes.', RangeError);
  if (keylen > 255 * host.hashLength(digest)) fail('ERR_CRYPTO_INVALID_KEYLEN', 'Invalid key length', RangeError);
  asyncResult(() => hkdfSync(digest, ikm, salt, info, keylen), done);
}

module.exports = {
  Hash, Hmac,
  createHash: (name, configuration) => new Hash(name, configuration),
  createHmac: (name, key, configuration) => new Hmac(name, key, configuration),
  hash(name, data, configuration = 'hex') {
    const opts = typeof configuration === 'string' ? { outputEncoding: configuration } : options(configuration);
    return new Hash(name, opts.outputLength === undefined ? undefined : { outputLength: opts.outputLength }).update(data).digest(opts.outputEncoding || 'hex');
  },
  getHashes: () => host.hashes(),
  randomBytes, randomFill, randomFillSync, randomInt,
  pseudoRandomBytes: randomBytes, rng: randomBytes, prng: randomBytes,
  randomUUID(configuration) {
    const opts = options(configuration);
    if (opts.disableEntropyCache !== undefined && typeof opts.disableEntropyCache !== 'boolean') fail('ERR_INVALID_ARG_TYPE', 'The "options.disableEntropyCache" property must be of type boolean.');
    return webcrypto.randomUUID();
  },
  timingSafeEqual, pbkdf2, pbkdf2Sync, hkdf, hkdfSync,
  webcrypto, subtle: webcrypto.subtle,
  getRandomValues: (array) => webcrypto.getRandomValues(array),
};
