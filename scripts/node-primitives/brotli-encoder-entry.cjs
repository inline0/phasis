'use strict';
const encoder = require('./node_modules/brotli/build/encode.js');

module.exports = function compress(bytes, options = {}) {
  const input = encoder._malloc(Math.max(bytes.length, 1));
  const capacity = bytes.length + 1024;
  const output = encoder._malloc(capacity);
  try {
    encoder.HEAPU8.set(bytes, input);
    // Upstream's convenience wrapper passes input.length as capacity and rejects incompressible data.
    const length = encoder._encode(options.quality ?? 11, options.lgwin ?? 22, options.mode ?? 0, bytes.length, input, capacity, output);
    if (length === -1) throw new Error('Brotli compression failed');
    return encoder.HEAPU8.slice(output, output + length);
  } finally {
    encoder._free(input);
    encoder._free(output);
  }
};
