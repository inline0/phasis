'use strict';
const zlib = require('node:zlib');
const { Readable, Writable } = require('node:stream');
const { pipeline } = require('node:stream/promises');
const { promisify } = require('node:util');
const upstream = require('./zlib-inputs.json');

require('./helpers.cjs').run(async () => {
  const result = [];
  const input = Buffer.from('hello 🌍\0 binary\xff hello hello');
  for (const [compress, decompress] of [['gzip', 'gunzip'], ['deflate', 'inflate'], ['deflateRaw', 'inflateRaw'], ['brotliCompress', 'brotliDecompress']]) {
    const encoded = zlib[compress + 'Sync'](input);
    result.push(['roundtrip', compress, zlib[decompress + 'Sync'](encoded).equals(input), zlib[decompress + 'Sync'](Buffer.from(upstream[compress], 'base64')).equals(input)]);
    result.push(['callback', compress, (await promisify(zlib[decompress])(await promisify(zlib[compress])(input))).equals(input)]);
  }
  result.push(['unzip', zlib.unzipSync(Buffer.from(upstream.gzip, 'base64')).equals(input), zlib.unzipSync(Buffer.from(upstream.deflate, 'base64')).equals(input)]);
  const dictionary = Buffer.from('hello world universe');
  const dictCompressed = zlib.deflateSync('hello world world', { dictionary });
  result.push(['dictionary', zlib.inflateSync(dictCompressed, { dictionary }).toString()]);
  for (const [name, data] of [['invalid', Buffer.from('invalid')], ['truncated', Buffer.from(upstream.gzip, 'base64').subarray(0, -3)]]) {
    try { zlib.gunzipSync(data); }
    catch (error) { result.push(['error', name, error.code, error.errno]); }
  }
  try { zlib.gunzipSync(Buffer.from(upstream.gzip, 'base64'), { maxOutputLength: 5 }); }
  catch (error) { result.push(['limit', error.code]); }
  const joined = Buffer.concat([Buffer.from(upstream.gzip, 'base64'), Buffer.from(upstream.gzip, 'base64')]);
  result.push(['concatenated', zlib.gunzipSync(joined).equals(Buffer.concat([input, input]))]);
  result.push(['checksum', zlib.crc32('hello'), zlib.crc32('world', zlib.crc32('hello'))]);

  for (const [name, data] of [['Gunzip', upstream.gzip], ['Inflate', upstream.deflate], ['BrotliDecompress', upstream.brotliCompress]]) {
    const encoded = Buffer.from(data, 'base64');
    const chunks = Array.from(encoded, byte => Buffer.from([byte]));
    const decoder = zlib['create' + name]();
    const output = [];
    await pipeline(Readable.from(chunks), decoder, new Writable({ write(chunk, encoding, done) { output.push(chunk); done(); } }));
    result.push(['stream', name, Buffer.concat(output).equals(input), decoder.bytesWritten === encoded.length, decoder.destroyed]);
  }
  const gzip = zlib.createGzip({ chunkSize: 64 });
  const encoded = [];
  gzip.on('data', chunk => encoded.push(chunk));
  gzip.write('first');
  await new Promise((resolve, reject) => gzip.flush(zlib.constants.Z_SYNC_FLUSH, error => error ? reject(error) : resolve()));
  const hadOutput = encoded.length > 0;
  await new Promise((resolve, reject) => { gzip.on('error', reject); gzip.on('end', resolve); gzip.end('second'); });
  result.push(['flush', hadOutput, zlib.gunzipSync(Buffer.concat(encoded)).toString()]);
  const info = zlib.gunzipSync(Buffer.from(upstream.gzip, 'base64'), { info: true });
  result.push(['info', info.buffer.equals(input), info.engine.bytesWritten === Buffer.from(upstream.gzip, 'base64').length]);
  return result;
});
