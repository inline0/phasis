'use strict';

const path = require('node:path');
const crypto = require('node:crypto');
let seed = 0x6a09e667;
function random() { seed = (Math.imul(seed, 1664525) + 1013904223) >>> 0; return seed; }
const segments = ['', '.', '..', '...', 'a', 'foo', 'a..', '.a', 'é', 'e\u0301', '😀', '\ud800', '\udfff', 'C:', 'D:', '?'];
const inputs = ['', '.', '..', '../..', '/', '//', '///', '/..', '/a/../../b', 'a//b/../',
  'C:\\', 'C:..', 'C:/a/../../b', '\\\\server\\share\\a\\..', '\\\\?\\C:\\a\\..',
  '\\\\?\\UNC\\server\\share\\a\\..', 'a\\..\\b', 'a/..\\b', '/\ud800/../\udfff/'];
for (let i = 0; i < 256; i++) {
  let input = '';
  const count = random() % 12 + 1;
  for (let j = 0; j < count; j++) {
    input += segments[random() % segments.length];
    input += ['/', '\\', '//', '\\\\', '', '/\\'][random() % 6];
  }
  inputs.push(input);
}
function units(value) {
  const result = [];
  for (let i = 0; i < value.length; i++) result.push(value.charCodeAt(i).toString(16));
  return result.join('.');
}
function digest(value) { return crypto.createHash('sha256').update(value).digest('hex'); }
const results = inputs.map(input => [
  units(path.posix.normalize(input)), units(path.win32.normalize(input)),
  units(path.posix.resolve('/base', input)), units(path.win32.resolve('C:\\base', input)),
]);
const long = '/base' + '/foobar'.repeat(10000) + '/../tail';
results.push([digest(path.posix.normalize(long)), digest(path.win32.normalize(long))]);
globalThis.pathNormalizationResult = results;
