'use strict';

const util = require('./portable/util-base.cjs');
const custom = Symbol.for('nodejs.util.promisify.custom');
const basePromisify = util.promisify;

util.promisify = function promisify(original) {
  if (typeof original !== 'function') {
    const error = new TypeError('The "original" argument must be of type function. Received ' + typeof original);
    error.code = 'ERR_INVALID_ARG_TYPE';
    throw error;
  }
  if (original[custom] !== undefined) {
    if (typeof original[custom] !== 'function') throw new TypeError('The "util.promisify.custom" property must be of type function.');
    return original[custom];
  }
  const result = basePromisify(original);
  Object.defineProperty(result, custom, { value: result, configurable: true });
  return result;
};
Object.defineProperty(util.promisify, 'custom', { value: custom });
util.TextEncoder = globalThis.TextEncoder;
util.TextDecoder = globalThis.TextDecoder;
util.inspect.custom = Symbol.for('nodejs.util.inspect.custom');
util.format = function format(...args) { return require('./portable/format.cjs').format(util.inspect, undefined, args); };
util.formatWithOptions = function formatWithOptions(options, ...args) {
  if (options === null || typeof options !== 'object') {
    const error = new TypeError('The "inspectOptions" argument must be of type object.');
    error.code = 'ERR_INVALID_ARG_TYPE';
    throw error;
  }
  return require('./portable/format.cjs').format(util.inspect, options, args);
};
util.stripVTControlCharacters = function stripVTControlCharacters(value) {
  if (typeof value !== 'string') throw new TypeError('The "str" argument must be of type string.');
  return value.replace(/[\u001b\u009b][[\]()#;?]*(?:(?:(?:[a-zA-Z\d]*(?:;[-a-zA-Z\d\/#&.:=?%@~_]+)*)?\u0007)|(?:(?:\d{1,4}(?:;\d{0,4})*)?[\dA-PR-TZcf-nq-uy=><~]))/g, '');
};
util.isDeepStrictEqual = function isDeepStrictEqual(a, b) {
  try { require('./assert.cjs').deepStrictEqual(a, b); return true; } catch (error) {
    if (error && error.code === 'ERR_ASSERTION') return false;
    throw error;
  }
};
module.exports = util;
