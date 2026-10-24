'use strict';

const path = require('node:path');
const qs = require('node:querystring');
const url = require('node:url');
const util = require('node:util');
const assert = require('node:assert');
const out = {};

out.paths = [path.posix.normalize('/foo/../bar//'), path.posix.relative('/a/b', '/a/c/d'),
  path.win32.resolve('C:\\a\\b', '..\\c'), path.win32.relative('C:\\foo\\bar', 'C:\\foo\\baz'),
  path.win32.parse('\\\\server\\share\\dir\\file.txt'), path.posix.parse('/a/.config'),
  path.format({ dir: '/a', name: 'b', ext: 'txt' }), path.matchesGlob('src/a.js', 'src/*.js'),
  path.matchesGlob('src/a/b.js', 'src/*.js'), path.matchesGlob('.secret', '*')];
out.query = [qs.parse('x=1&x=2&space=a+b&bad=%E0%A4%A'),
  qs.stringify({ x: ['a b', 'x+y'], n: 4, nil: null, nested: {} }),
  qs.parse('a=1;b=2;c=3', ';', '=', { maxKeys: 2 }),
  Object.getPrototypeOf(qs.parse('__proto__=x&constructor=y')) === null];
out.urls = [url.parse('https://a:b@example.com:8443/a%20b?q=1#hash', true),
  url.resolve('http://example.com/a/b', '../c?d'),
  url.format({ protocol: 'https:', hostname: 'example.com', pathname: '/a b', query: { q: 'x y' } }),
  url.domainToASCII('münich.example'), url.domainToUnicode('xn--mnich-kva.example'),
  url.pathToFileURL('/tmp/a #%.txt').href, url.fileURLToPath('file:///tmp/a%20b'),
  url.fileURLToPath('file:///C:/Users/A%20B', { windows: true }),
  url.fileURLToPathBuffer('file:///tmp/%FF%FE').toString('hex'),
  url.format(new URL('https://user:pass@xn--mnich-kva.example/a?q=1#h'), { unicode: true, auth: false, fragment: false }),
  url.urlToHttpOptions(new URL('http://a:b@[::1]:8080/x?q=1'))];
out.format = [util.format('%s %d %i %f %% %j', 'hello', 3, '4x', '5.2x', { a: 1 }),
  util.formatWithOptions({ depth: 0 }, '%O', { a: { b: 1 } }),
  util.formatWithOptions({ numericSeparator: true }, '%d %i', 1234567.123456, 1234567n),
  util.format('%s', { a: { b: 1 } }), util.format('%d %i %f', Symbol(), Symbol(), Symbol()),
  util.format('%s %d', -0, 1n), util.stripVTControlCharacters('\u001b[31mred\u001b[0m')];
const circular = {}; circular.self = circular;
out.format.push(util.format('%j', circular));
try { util.format('%j', 1n); } catch (error) { out.format.push(error.name); }
out.assertions = [];
assert.deepStrictEqual(new Map([[{ a: 1 }, new Set([1, 2])]]), new Map([[{ a: 1 }, new Set([2, 1])]]));
for (const fn of [() => assert.strictEqual(0, -0), () => assert.deepStrictEqual({ a: 1 }, { a: '1' }),
  () => assert.throws(() => {}, TypeError), () => assert.match('abc', /z/)]) {
  try { fn(); out.assertions.push('unexpected pass'); } catch (error) { out.assertions.push([error.name, error.code]); }
}
out.deep = [util.isDeepStrictEqual(new Set([1, 2]), new Set([2, 1])), util.isDeepStrictEqual({ a: 1 }, { a: '1' })];
const customized = () => { throw new Error('should use the custom implementation'); };
customized[Symbol.for('nodejs.util.promisify.custom')] = async () => 42;
out.promisify = util.promisify(customized) === customized[Symbol.for('nodejs.util.promisify.custom')];
globalThis.portableResult = out;
