'use strict';

const { Minimatch } = require('./minimatch.cjs');
const { validateString } = require('./validators.cjs');
const cache = new Map();

exports.matchGlobPattern = function matchGlobPattern(path, pattern, windows = process.platform === 'win32') {
  validateString(path, 'path');
  validateString(pattern, 'pattern');
  const key = (windows ? 'win32:' : 'posix:') + pattern;
  let matcher = cache.get(key);
  if (!matcher) {
    matcher = new Minimatch(pattern, {
      nocase: process.platform === 'win32' || process.platform === 'darwin',
      windowsPathsNoEscape: true,
      nonegate: true,
      nocomment: true,
      optimizationLevel: 2,
      platform: windows ? 'win32' : 'posix',
      nocaseMagicOnly: true,
    });
    cache.set(key, matcher);
    if (cache.size >= 250) cache.delete(cache.keys().next().value);
  }
  return matcher.match(path);
};
