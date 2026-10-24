'use strict';

const exported = require('./node_modules/buffer/index.js');
const { Buffer } = exported;
const from = Buffer.from;
const byteLength = Buffer.byteLength;
const isEncoding = Buffer.isEncoding;
const toString = Buffer.prototype.toString;
const write = Buffer.prototype.write;
const normalize = (encoding) => typeof encoding === 'string' && encoding.toLowerCase() === 'base64url' ? 'base64' : encoding;
Buffer.from = function (value, encodingOrOffset, length) {
  return from(value, normalize(encodingOrOffset), length);
};
Buffer.byteLength = function (value, encoding) { return byteLength(value, normalize(encoding)); };
Buffer.isEncoding = function (encoding) { return isEncoding(normalize(encoding)); };
Buffer.prototype.toString = function (encoding, start, end) {
  if (!encoding || typeof encoding === 'string' && /^(utf8|utf-8)$/i.test(encoding)) {
    if (start === undefined || start < 0) start = 0;
    if (start > this.length) return '';
    if (end === undefined || end > this.length) end = this.length;
    if (end <= 0) return '';
    end >>>= 0; start >>>= 0;
    if (end <= start) return '';
    // Node consumes incomplete UTF-8 prefixes as one replacement and retains BOMs.
    return new TextDecoder('utf-8', { ignoreBOM: true }).decode(new Uint8Array(this.buffer, this.byteOffset + start, end - start));
  }
  const result = toString.call(this, normalize(encoding), start, end);
  return typeof encoding === 'string' && encoding.toLowerCase() === 'base64url'
    ? result.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '') : result;
};
Buffer.prototype.write = function (string, offset, length, encoding) {
  return write.call(this, string, normalize(offset), normalize(length), normalize(encoding));
};
Buffer.copyBytesFrom = function (view, offset = 0, length) {
  if (!ArrayBuffer.isView(view) || view instanceof DataView) throw new TypeError('The "view" argument must be a TypedArray.');
  length ??= Math.max(view.length - offset, 0);
  for (const [name, value] of [['offset', offset], ['length', length]]) {
    if (!Number.isInteger(value) || value < 0) throw new RangeError(`The "${name}" argument is out of range.`);
  }
  const start = Math.min(offset, view.length);
  const count = Math.min(length, view.length - start);
  return Buffer.from(new Uint8Array(view.buffer, view.byteOffset + start * view.BYTES_PER_ELEMENT, count * view.BYTES_PER_ELEMENT));
};
Buffer.prototype.readBigUint64LE = Buffer.prototype.readBigUInt64LE;
Buffer.prototype.readBigUint64BE = Buffer.prototype.readBigUInt64BE;
Buffer.prototype.writeBigUint64LE = Buffer.prototype.writeBigUInt64LE;
Buffer.prototype.writeBigUint64BE = Buffer.prototype.writeBigUInt64BE;
exported.Blob = globalThis.Blob;
exported.File = globalThis.File;
exported.atob = globalThis.atob;
exported.btoa = globalThis.btoa;
exported.constants = Object.freeze({ MAX_LENGTH: exported.kMaxLength, MAX_STRING_LENGTH: 536870888 });
exported.isAscii = function (input) {
  if (!ArrayBuffer.isView(input) && !(input instanceof ArrayBuffer)) throw new TypeError('The "input" argument must be an ArrayBuffer or ArrayBufferView.');
  const bytes = input instanceof ArrayBuffer ? new Uint8Array(input) : new Uint8Array(input.buffer, input.byteOffset, input.byteLength);
  return bytes.every((byte) => byte < 128);
};
exported.isUtf8 = function (input) {
  if (!ArrayBuffer.isView(input) && !(input instanceof ArrayBuffer)) throw new TypeError('The "input" argument must be an ArrayBuffer or ArrayBufferView.');
  try { new TextDecoder('utf-8', { fatal: true }).decode(input); return true; } catch { return false; }
};
module.exports = exported;
