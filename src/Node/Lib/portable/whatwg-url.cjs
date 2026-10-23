'use strict';

const punycode = require('./punycode.cjs');
const { validateString } = require('./validators.cjs');
const { ERR_INVALID_ARG_TYPE } = require('./errors.cjs').codes;

exports.URL = globalThis.URL;
exports.URLSearchParams = globalThis.URLSearchParams;
exports.unsafeProtocol = new Set(['javascript', 'javascript:']);
exports.hostlessProtocol = new Set(['javascript', 'javascript:']);
exports.slashedProtocol = new Set(['http', 'https', 'ftp', 'gopher', 'file', 'ws', 'wss'].flatMap((value) => [value, value + ':']));

exports.domainToASCII = function domainToASCII(domain) {
  if (arguments.length === 0) throw new ERR_INVALID_ARG_TYPE('domain', 'string', undefined);
  const text = String(domain).split(/[/?#]/, 1)[0];
  if (text.includes('@') || text.includes(':') && !/^\[[\da-f:.]+\]$/i.test(text)) return '';
  try {
    const ascii = /^[\x00-\x7F]*$/.test(text) && !text.toLowerCase().includes('xn--') ? text : require('./tr46.cjs').toASCII(text, {
      checkBidi: true, checkJoiners: true, transitionalProcessing: false, verifyDNSLength: false,
    });
    return ascii ? new URL('http://' + ascii).hostname : '';
  } catch { return ''; }
};

exports.domainToUnicode = function domainToUnicode(domain) {
  const ascii = exports.domainToASCII(domain);
  return ascii ? punycode.toUnicode(ascii) : '';
};

function error(code, message) {
  const result = new TypeError(message);
  result.code = code;
  return result;
}

function fileUrl(input, windows) {
  const url = typeof input === 'string' ? new URL(input) : input;
  if (!(url instanceof URL)) throw new ERR_INVALID_ARG_TYPE('path', 'string or an instance of URL', input);
  if (url.protocol !== 'file:') throw error('ERR_INVALID_URL_SCHEME', 'The URL must be of scheme file');
  if (/%2f/i.test(url.pathname) || (windows && /%5c/i.test(url.pathname))) {
    throw error('ERR_INVALID_FILE_URL_PATH', 'File URL path must not include encoded / or \\ characters');
  }
  if (!windows && url.hostname !== '') {
    throw error('ERR_INVALID_FILE_URL_HOST', 'File URL host must be "localhost" or empty on ' + process.platform);
  }
  return url;
}

function windowsOption(options) {
  if (options && options.windows !== undefined && typeof options.windows !== 'boolean') {
    throw new ERR_INVALID_ARG_TYPE('options.windows', 'boolean', options.windows);
  }
  return options && options.windows !== undefined ? options.windows : process.platform === 'win32';
}

exports.fileURLToPath = function fileURLToPath(input, options) {
  const windows = windowsOption(options);
  const url = fileUrl(input, windows);
  let path = decodeURIComponent(url.pathname);
  if (!windows) return path;
  path = path.replaceAll('/', '\\');
  if (url.hostname) return '\\\\' + exports.domainToUnicode(url.hostname) + path;
  if (!/^\\[a-z]:/i.test(path)) throw error('ERR_INVALID_FILE_URL_PATH', 'File URL path must be absolute');
  return path.slice(1);
};

exports.fileURLToPathBuffer = function fileURLToPathBuffer(input, options) {
  const windows = windowsOption(options);
  const url = fileUrl(input, windows);
  if (windows) return Buffer.from(exports.fileURLToPath(url, options));
  const chunks = [];
  const text = url.pathname;
  let start = 0;
  for (let i = 0; i < text.length; i++) {
    if (text[i] === '%' && /^[\da-f]{2}$/i.test(text.slice(i + 1, i + 3))) {
      if (i > start) chunks.push(Buffer.from(text.slice(start, i)));
      chunks.push(Buffer.from([parseInt(text.slice(i + 1, i + 3), 16)]));
      i += 2;
      start = i + 1;
    }
  }
  if (start < text.length) chunks.push(Buffer.from(text.slice(start)));
  return Buffer.concat(chunks);
};

exports.pathToFileURL = function pathToFileURL(input, options) {
  validateString(input, 'path');
  const windows = windowsOption(options);
  const path = require('../path.cjs');
  let resolved = (windows ? path.win32 : path.posix).resolve(input);
  const trailingSlash = /[\\/]$/.test(input);
  if (trailingSlash && !/[\\/]$/.test(resolved)) resolved += windows ? '\\' : '/';
  if (windows) {
    resolved = resolved.replaceAll('\\', '/');
    if (resolved.startsWith('//')) {
      const pieces = resolved.slice(2).split('/');
      const host = pieces.shift();
      return new URL('file://' + host + '/' + pieces.map(encodeURIComponent).join('/'));
    }
    resolved = '/' + resolved;
  }
  const encoded = resolved.split('/').map(encodeURIComponent).join('/').replace(/^\/([a-z])%3A/i, '/$1:');
  return new URL('file://' + encoded);
};

exports.urlToHttpOptions = function urlToHttpOptions(url) {
  const result = { ...url, protocol: url.protocol, hostname: url.hostname.startsWith('[') ? url.hostname.slice(1, -1) : url.hostname,
    hash: url.hash, search: url.search, pathname: url.pathname, path: url.pathname + url.search, href: url.href };
  if (url.port !== '') result.port = Number(url.port);
  if (url.username || url.password) result.auth = decodeURIComponent(url.username) + ':' + decodeURIComponent(url.password);
  return result;
};

exports.format = function format(href, fragment, unicode, search, auth) {
  const url = new URL(href);
  if (!fragment) url.hash = '';
  if (!search) url.search = '';
  if (!auth) { url.username = ''; url.password = ''; }
  if (!unicode || !url.hostname) return url.href;
  const credentials = url.username || url.password ? url.username + (url.password ? ':' + url.password : '') + '@' : '';
  const offset = url.protocol.length + 2 + credentials.length;
  return url.href.slice(0, offset) + exports.domainToUnicode(url.hostname) + url.href.slice(offset + url.hostname.length);
};
