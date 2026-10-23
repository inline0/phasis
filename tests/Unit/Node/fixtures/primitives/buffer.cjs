'use strict';
const buffer = require('buffer');
const { Buffer } = buffer;
const { StringDecoder } = require('string_decoder');
require('./helpers.cjs').run(async () => {
  const output = [];
  for (const [text, encoding] of [['hello 🌍', 'utf8'], ['a\ud800z', 'utf8'], ['00ff1080f', 'hex'], ['SGVsbG8_', 'base64url'], ['é\uffff', 'latin1'], ['hello', 'utf16le']]) {
    const value = Buffer.from(text, encoding);
    output.push([encoding, value.toString('hex'), value.toString('base64url'), Buffer.byteLength(text, encoding), value instanceof Uint8Array, Buffer.isBuffer(value)]);
  }
  const backing = new Uint8Array([1, 2, 3, 4]);
  const shared = Buffer.from(backing.buffer, 1, 2);
  shared[0] = 8;
  const copy = Buffer.from(shared);
  const slice = shared.slice(1);
  slice[0] = 9;
  output.push(['views', [...backing], [...shared], [...copy], Buffer.isBuffer(slice)]);
  const integer = Buffer.alloc(24);
  integer.writeUInt32LE(4294967295, 0);
  integer.writeInt16BE(-1234, 4);
  integer.writeBigUInt64LE(18446744073709551615n, 8);
  integer.writeDoubleBE(-0, 16);
  output.push(['numbers', integer.toString('hex'), integer.readUInt32LE(), integer.readInt16BE(4), String(integer.readBigUInt64LE(8)), Object.is(integer.readDoubleBE(16), -0)]);
  const target = Buffer.alloc(8, 'ab');
  Buffer.from('XYZ').copy(target, 2, 0, 2);
  output.push(['mutations', target.toString(), target.indexOf('XY'), target.lastIndexOf('ab'), target.includes(Buffer.from('XY')), Buffer.compare(Buffer.from('a'), Buffer.from('b'))]);
  const largeFill = Buffer.alloc(1024 * 100, '.');
  const patternFill = Buffer.alloc(1024 * 100 + 1, 'é!');
  output.push(['large-fill', largeFill.length, largeFill.toString().length, largeFill[0], largeFill[largeFill.length - 1], patternFill.subarray(-7).toString('hex')]);
  const overlappingFill = Buffer.from([1, 2, 3, 4, 5, 6]);
  overlappingFill.fill(overlappingFill.subarray(1, 3), 0);
  output.push(['overlapping-fill', [...overlappingFill]]);
  const longString = '.'.repeat(102400);
  output.push(['large-utf8', Buffer.byteLength(longString), Buffer.from(longString).toString() === longString]);
  const limitedUtf8 = Buffer.alloc(7, 0x21);
  const shortWrite = limitedUtf8.write('🌍a', 1, 3, 'utf8');
  const wholeWrite = limitedUtf8.write('🌍a', 1, 5, 'utf8');
  output.push(['utf8-write-boundaries', shortWrite, wholeWrite, limitedUtf8.toString('hex'), Buffer.from('a\ud800z').toString('hex')]);
  output.push(['raw-bytes', Buffer.copyBytesFrom(new Uint16Array([0x1234, 0xabcd]), 1, 1).toString('hex')]);
  const decoder = new StringDecoder('utf8');
  const unicode = Buffer.from('a🌍b');
  output.push(['decode', decoder.write(unicode.subarray(0, 3)), decoder.write(unicode.subarray(3, 4)), decoder.end(unicode.subarray(4))]);
  const incomplete = new StringDecoder('utf8');
  output.push(['decode-incomplete', incomplete.write(Buffer.from([0xf0, 0x9f])), incomplete.end()]);
  output.push(['validity', buffer.isUtf8(Buffer.from('🌍')), buffer.isUtf8(Buffer.from([0xff])), buffer.isAscii(Buffer.from('ascii')), buffer.isAscii(Buffer.from('é'))]);
  for (const action of [() => Buffer.alloc(-1), () => Buffer.from(2), () => Buffer.alloc(2).readUInt32LE(), () => Buffer.copyBytesFrom(new DataView(new ArrayBuffer(2)))]) {
    try { action(); output.push('unexpected-success'); } catch (error) { output.push(error.name); }
  }
  return output;
});
