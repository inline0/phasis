'use strict';

const { Buffer } = require('buffer');
const { Readable, Writable } = require('stream');
const { EventEmitter } = require('events');
const pathModule = require('path');
const host = __phasisNodeHost.fs;
const fs = module.exports;
const customPromisify = Symbol.for('nodejs.util.promisify.custom');

function coded(ErrorClass, code, message) {
  return Object.assign(new ErrorClass(message), { code });
}
function argument(name, expected) {
  throw coded(TypeError, 'ERR_INVALID_ARG_TYPE', `The "${name}" argument must be ${expected}`);
}
function integer(value, name, min = 0, max = Number.MAX_SAFE_INTEGER) {
  if (typeof value !== 'number') argument(name, 'of type number');
  if (!Number.isInteger(value) || value < min || value > max) {
    throw coded(RangeError, 'ERR_OUT_OF_RANGE', `The value of "${name}" is out of range. It must be >= ${min} and <= ${max}. Received ${value}`);
  }
  return value;
}
function pathValue(value) {
  if (value instanceof URL) {
    if (value.protocol !== 'file:') throw coded(TypeError, 'ERR_INVALID_URL_SCHEME', 'The URL must be of scheme file');
    if (value.hostname && value.hostname !== 'localhost') throw coded(TypeError, 'ERR_INVALID_FILE_URL_HOST', 'File URL host must be "localhost" or empty');
    if (/%2f|%5c/i.test(value.pathname)) throw coded(TypeError, 'ERR_INVALID_FILE_URL_PATH', 'File URL path must not include encoded path separators');
    value = decodeURIComponent(value.pathname);
  } else if (Buffer.isBuffer(value)) value = value.toString();
  if (typeof value !== 'string') argument('path', 'a string, Buffer, or URL');
  if (value.includes('\0')) throw coded(TypeError, 'ERR_INVALID_ARG_VALUE', 'The argument \'path\' must be a string, Uint8Array, or URL without null bytes');
  return value;
}
function options(value, defaults = {}) {
  if (value == null) return { ...defaults };
  if (typeof value === 'string') return { ...defaults, encoding: value };
  if (typeof value !== 'object') argument('options', 'a string or object');
  return { ...defaults, ...value };
}
function encoding(value) {
  if (value != null && value !== 'buffer' && !Buffer.isEncoding(value)) {
    throw coded(TypeError, 'ERR_INVALID_ARG_VALUE', `The argument 'encoding' is invalid. Received '${value}'`);
  }
  return value;
}
function decode(value, selected) {
  const result = Buffer.from(value.buffer, value.byteOffset, value.byteLength);
  return selected == null || selected === 'buffer' ? result : result.toString(encoding(selected));
}
function view(value) {
  if (!ArrayBuffer.isView(value)) argument('buffer', 'a Buffer, TypedArray, or DataView');
  return new Uint8Array(value.buffer, value.byteOffset, value.byteLength);
}
function modeValue(value, fallback = 0o666) {
  if (value === undefined) return fallback;
  if (typeof value === 'string' && /^[0-7]+$/.test(value)) value = parseInt(value, 8);
  return integer(value, 'mode', 0, 0xffffffff);
}
function call(operation, ...args) {
  const result = host.call(operation, ...args);
  if (result.error) {
    const { message, ...properties } = result.error;
    throw Object.assign(new Error(message), properties);
  }
  return result.value;
}
function callback(value) {
  if (typeof value !== 'function') argument('cb', 'of type function');
  return value;
}
function later(fn, args, cb, map = value => [value]) {
  callback(cb);
  process.nextTick(() => {
    let result;
    try { result = map(fn(...args)); }
    catch (error) { cb(error); return; }
    cb(null, ...result);
  });
}
function abort(signal) {
  if (signal?.aborted) throw Object.assign(new Error('The operation was aborted', { cause: signal.reason }), { name: 'AbortError', code: 'ABORT_ERR' });
}

const darwin = process.platform === 'darwin';
const constants = fs.constants = Object.freeze({
  F_OK: 0, R_OK: 4, W_OK: 2, X_OK: 1,
  O_RDONLY: 0, O_WRONLY: 1, O_RDWR: 2,
  O_CREAT: darwin ? 512 : 64, O_EXCL: darwin ? 2048 : 128,
  O_TRUNC: darwin ? 1024 : 512, O_APPEND: darwin ? 8 : 1024,
  O_NONBLOCK: darwin ? 4 : 2048, O_NOCTTY: darwin ? 131072 : 256,
  O_DIRECTORY: darwin ? 1048576 : 65536, O_NOFOLLOW: darwin ? 256 : 131072,
  O_SYNC: darwin ? 128 : 1052672, O_DSYNC: darwin ? 4194304 : 4096,
  S_IFMT: 0o170000, S_IFREG: 0o100000, S_IFDIR: 0o040000,
  S_IFCHR: 0o020000, S_IFBLK: 0o060000, S_IFIFO: 0o010000,
  S_IFLNK: 0o120000, S_IFSOCK: 0o140000,
  S_IRWXU: 0o700, S_IRUSR: 0o400, S_IWUSR: 0o200, S_IXUSR: 0o100,
  S_IRWXG: 0o070, S_IRGRP: 0o040, S_IWGRP: 0o020, S_IXGRP: 0o010,
  S_IRWXO: 0o007, S_IROTH: 0o004, S_IWOTH: 0o002, S_IXOTH: 0o001,
  COPYFILE_EXCL: 1, COPYFILE_FICLONE: 2, COPYFILE_FICLONE_FORCE: 4,
  UV_FS_COPYFILE_EXCL: 1, UV_FS_COPYFILE_FICLONE: 2, UV_FS_COPYFILE_FICLONE_FORCE: 4,
  UV_FS_SYMLINK_DIR: 1, UV_FS_SYMLINK_JUNCTION: 2,
});
for (const name of ['F_OK', 'R_OK', 'W_OK', 'X_OK']) fs[name] = constants[name];
function flagValue(flags = 'r') {
  if (typeof flags === 'number') {
    integer(flags, 'flags', 0, 0xffffffff);
    const access = flags & 3;
    const create = (flags & constants.O_CREAT) !== 0;
    const exclusive = (flags & constants.O_EXCL) !== 0;
    const append = (flags & constants.O_APPEND) !== 0;
    const truncate = (flags & constants.O_TRUNC) !== 0;
    // c/c+ preserve existing contents while allowing O_CREAT without O_TRUNC.
    const base = append ? 'a' : truncate ? 'w' : create ? 'c' : 'r';
    return base + (exclusive && create ? 'x' : '') + (access === 2 || (access === 1 && base === 'r') ? '+' : '');
  }
  if (typeof flags !== 'string') argument('flags', 'a string or number');
  if (!['r', 'r+', 'rs', 'rs+', 'sr', 'sr+', 'w', 'wx', 'xw', 'w+', 'wx+', 'xw+', 'a', 'ax', 'xa', 'a+', 'ax+', 'xa+', 'as', 'as+'].includes(flags)) {
    throw coded(TypeError, 'ERR_INVALID_ARG_VALUE', `The argument 'flags' is invalid. Received '${flags}'`);
  }
  return flags.replace('s', '').replace(/^x([wa])/, '$1x');
}
fs.openSync = (path, flags, mode) => call('open', pathValue(path), flagValue(flags), modeValue(mode));
fs.closeSync = fd => call('close', integer(fd, 'fd', 0, 0x7fffffff));
fs.open = function (path, flags, mode, cb) {
  if (typeof flags === 'function') { cb = flags; flags = 'r'; mode = undefined; }
  else if (typeof mode === 'function') { cb = mode; mode = undefined; }
  later(fs.openSync, [path, flags, mode], cb);
};
fs.close = (fd, cb = () => {}) => later(fs.closeSync, [fd], cb, () => []);

function readArguments(buffer, offset, length, position) {
  if (!ArrayBuffer.isView(buffer)) {
    const config = options(buffer);
    buffer = config.buffer ?? Buffer.alloc(16384);
    offset = config.offset ?? 0; length = config.length ?? buffer.byteLength - offset; position = config.position ?? null;
  } else if (offset !== null && typeof offset === 'object') {
    const config = offset;
    offset = config.offset ?? 0; length = config.length ?? buffer.byteLength - offset; position = config.position ?? null;
  } else {
    offset ??= 0; length ??= buffer.byteLength - offset; position ??= null;
  }
  const bytes = view(buffer);
  integer(offset, 'offset', 0, bytes.byteLength);
  integer(length, 'length', 0, bytes.byteLength - offset);
  if (position != null && position !== -1) integer(position, 'position');
  return { buffer, bytes, offset, length, position };
}
fs.readSync = function (fd, buffer, offset, length, position) {
  integer(fd, 'fd', 0, 0x7fffffff);
  const config = readArguments(buffer, offset, length, position);
  return call('read', fd, config.bytes, config.offset, config.length, config.position);
};
fs.read = function (fd, ...args) {
  const cb = callback(args.pop());
  const config = readArguments(...args);
  later(fs.readSync, [fd, config.buffer, config.offset, config.length, config.position], cb, bytesRead => [bytesRead, config.buffer]);
};
fs.writeSync = function (fd, data, offset, length, position) {
  integer(fd, 'fd', 0, 0x7fffffff);
  if (typeof data === 'string') {
    position = offset ?? null;
    data = Buffer.from(data, encoding(length) ?? 'utf8');
    offset = 0; length = data.length;
  } else if (offset !== null && typeof offset === 'object') {
    const config = offset;
    offset = config.offset ?? 0; length = config.length ?? data.byteLength - offset; position = config.position ?? null;
  } else {
    offset ??= 0; length ??= data.byteLength - offset; position ??= null;
  }
  const bytes = view(data);
  integer(offset, 'offset', 0, bytes.byteLength);
  integer(length, 'length', 0, bytes.byteLength - offset);
  if (position != null && position !== -1) integer(position, 'position');
  return call('write', fd, bytes, offset, length, position);
};
fs.write = function (fd, data, ...args) {
  const cb = callback(args.pop());
  later(fs.writeSync, [fd, data, ...args], cb, bytesWritten => [bytesWritten, data]);
};
for (const operation of ['read', 'write']) {
  const resultName = operation === 'read' ? 'bytesRead' : 'bytesWritten';
  fs[operation][customPromisify] = (...args) => new Promise((resolve, reject) => fs[operation](...args, (error, count, buffer) => error ? reject(error) : resolve({ [resultName]: count, buffer })));
  fs[operation + 'vSync'] = (fd, buffers, position = null) => {
    if (!Array.isArray(buffers)) argument('buffers', 'an array');
    let total = 0;
    for (const buffer of buffers) {
      const count = fs[operation + 'Sync'](fd, buffer, 0, buffer.byteLength, position == null ? null : position + total);
      total += count;
      if (operation === 'read' && count < buffer.byteLength) break;
    }
    return total;
  };
  fs[operation + 'v'] = (fd, buffers, position, cb) => {
    if (typeof position === 'function') { cb = position; position = null; }
    later(fs[operation + 'vSync'], [fd, buffers, position], cb, count => [count, buffers]);
  };
  fs[operation + 'v'][customPromisify] = (...args) => new Promise((resolve, reject) => fs[operation + 'v'](...args, (error, count, buffers) => error ? reject(error) : resolve({ [resultName]: count, buffers })));
}

fs.readFileSync = function (file, value) {
  const config = options(value, { encoding: null, flag: 'r' });
  encoding(config.encoding); abort(config.signal);
  const owns = typeof file !== 'number';
  const fd = owns ? fs.openSync(file, config.flag) : integer(file, 'fd', 0, 0x7fffffff);
  try { return decode(call('readAll', fd), config.encoding); }
  finally { if (owns) fs.closeSync(fd); }
};
fs.writeFileSync = function (file, data, value) {
  const config = options(value, { encoding: 'utf8', flag: 'w', mode: 0o666 });
  encoding(config.encoding); abort(config.signal);
  const bytes = typeof data === 'string' ? Buffer.from(data, config.encoding) : view(data);
  const owns = typeof file !== 'number';
  const fd = owns ? fs.openSync(file, config.flag, config.mode) : integer(file, 'fd', 0, 0x7fffffff);
  try {
    let offset = 0;
    while (offset < bytes.byteLength) {
      const count = fs.writeSync(fd, bytes, offset, bytes.byteLength - offset, null);
      if (!count) throw coded(Error, 'EIO', 'EIO: i/o error, write');
      offset += count;
    }
    if (config.flush) fs.fsyncSync(fd);
  } finally { if (owns) fs.closeSync(fd); }
};
fs.appendFileSync = (file, data, value) => fs.writeFileSync(file, data, options(value, { flag: 'a' }));

class Stats {
  constructor(data, bigint = false) {
    if (!data) return;
    for (const [key, value] of Object.entries(data)) this[key] = bigint ? BigInt(Math.trunc(value)) : value;
    for (const key of ['atime', 'mtime', 'ctime', 'birthtime']) {
      this[key] = new Date(Number(this[key + 'Ms']));
      if (bigint) this[key + 'Ns'] = this[key + 'Ms'] * 1000000n;
    }
  }
  _is(type) { return Number(this.mode) % 0x10000 & constants.S_IFMT ? (Number(this.mode) & constants.S_IFMT) === type : false; }
  isFile() { return this._is(constants.S_IFREG); }
  isDirectory() { return this._is(constants.S_IFDIR); }
  isBlockDevice() { return this._is(constants.S_IFBLK); }
  isCharacterDevice() { return this._is(constants.S_IFCHR); }
  isSymbolicLink() { return this._is(constants.S_IFLNK); }
  isFIFO() { return this._is(constants.S_IFIFO); }
  isSocket() { return this._is(constants.S_IFSOCK); }
}
fs.Stats = Stats;
for (const operation of ['stat', 'lstat', 'fstat']) {
  fs[operation + 'Sync'] = (path, value) => {
    const config = options(value);
    try {
      return new Stats(call(operation, operation === 'fstat' ? integer(path, 'fd', 0, 0x7fffffff) : pathValue(path)), config.bigint);
    } catch (error) {
      if (config.throwIfNoEntry === false && (error.code === 'ENOENT' || error.code === 'ENOTDIR')) return undefined;
      throw error;
    }
  };
}
class Dirent extends Stats {
  constructor(name, mode, parentPath) {
    super(); this.name = name; this.parentPath = parentPath;
    Object.defineProperty(this, 'mode', { value: mode });
  }
}
fs.Dirent = Dirent;
fs.readdirSync = function (path, value) {
  path = pathValue(path);
  const config = options(value, { encoding: 'utf8' });
  encoding(config.encoding);
  const result = [];
  const walk = (current, prefix) => {
    const entries = call('readdir', current);
    for (const entry of entries) {
      const name = decode(entry.name, config.encoding);
      const rawName = decode(entry.name, 'utf8');
      result.push(config.withFileTypes ? new Dirent(name, entry.mode, current) : (prefix ? (config.encoding === 'buffer' ? Buffer.from(prefix + rawName) : prefix + name) : name));
    }
    if (config.recursive) for (const entry of entries) {
      if ((entry.mode & constants.S_IFMT) === constants.S_IFDIR) {
        const name = decode(entry.name, 'utf8');
        walk(pathModule.join(current, name), prefix + name + pathModule.sep);
      }
    }
  };
  walk(path, '');
  return result;
};
fs.existsSync = path => { try { return call('exists', pathValue(path)); } catch { return false; } };
fs.exists = (path, cb) => { callback(cb); process.nextTick(() => cb(fs.existsSync(path))); };
fs.accessSync = (path, mode = 0) => call('access', pathValue(path), integer(mode, 'mode', 0, 7));
fs.mkdirSync = (path, value) => {
  const config = typeof value === 'number' || typeof value === 'string' ? { mode: value } : options(value);
  return call('mkdir', pathValue(path), modeValue(config.mode, 0o777), !!config.recursive);
};
fs.mkdtempSync = (prefix, value) => decode(call('mkdtemp', pathValue(prefix)), encoding(options(value, { encoding: 'utf8' }).encoding));
for (const operation of ['realpath', 'readlink']) {
  fs[operation + 'Sync'] = (path, value) => decode(call(operation, pathValue(path)), encoding(options(value, { encoding: 'utf8' }).encoding));
}
for (const operation of ['rename', 'link']) fs[operation + 'Sync'] = (first, second) => call(operation, pathValue(first), pathValue(second));
fs.symlinkSync = (target, path, type) => call('symlink', pathValue(target), pathValue(path), type);
fs.copyFileSync = (source, destination, mode = 0) => call('copyFile', pathValue(source), pathValue(destination), integer(mode, 'mode', 0, 7));
fs.unlinkSync = path => call('unlink', pathValue(path));
fs.rmdirSync = (path, value) => {
  if (options(value).recursive) return fs.rmSync(path, { ...value, recursive: true });
  return call('rmdir', pathValue(path));
};
fs.rmSync = function (path, value) {
  path = pathValue(path);
  const config = options(value);
  let stat;
  try { stat = fs.lstatSync(path); }
  catch (error) { if (config.force && error.code === 'ENOENT') return; throw error; }
  if (!stat.isDirectory()) return fs.unlinkSync(path);
  if (!config.recursive) throw Object.assign(coded(Error, 'ERR_FS_EISDIR', `Path is a directory: rm returned EISDIR (is a directory) ${path}`), { syscall: 'rm', path });
  for (const name of fs.readdirSync(path)) fs.rmSync(pathModule.join(path, name), config);
  return call('rmdir', path);
};
fs.chmodSync = (path, mode) => call('chmod', pathValue(path), modeValue(mode));
for (const operation of ['chown', 'lchown']) fs[operation + 'Sync'] = (path, uid, gid) => call(operation, pathValue(path), integer(uid, 'uid', -1, 0xffffffff), integer(gid, 'gid', -1, 0xffffffff));
function timeValue(value) {
  if (value instanceof Date) value = value.getTime() / 1000;
  else if (typeof value === 'string' && value.trim()) value = Number(value);
  if (typeof value !== 'number' || !Number.isFinite(value)) argument('time', 'a number, numeric string, or Date');
  return value < 0 ? Date.now() / 1000 : value;
}
fs.utimesSync = (path, atime, mtime) => call('utimes', pathValue(path), timeValue(atime), timeValue(mtime));
fs.ftruncateSync = (fd, length = 0) => call('ftruncate', integer(fd, 'fd', 0, 0x7fffffff), integer(Math.max(length, 0), 'len'));
fs.truncateSync = function (path, length = 0) {
  if (typeof path === 'number') return fs.ftruncateSync(path, length);
  const fd = fs.openSync(path, 'r+');
  try { fs.ftruncateSync(fd, length); } finally { fs.closeSync(fd); }
};
for (const operation of ['fsync', 'fdatasync']) fs[operation + 'Sync'] = fd => call(operation, integer(fd, 'fd', 0, 0x7fffffff));
fs.cpSync = function (source, destination, value) {
  source = pathValue(source); destination = pathValue(destination);
  const config = options(value, { force: true });
  if (config.filter && !config.filter(source, destination)) return;
  const stat = (config.dereference ? fs.statSync : fs.lstatSync)(source);
  if (stat.isDirectory()) {
    if (!config.recursive) throw coded(Error, 'ERR_FS_EISDIR', `Path is a directory: ${source}`);
    const relative = pathModule.relative(pathModule.resolve(source), pathModule.resolve(destination));
    if (relative === '' || (!relative.startsWith('..' + pathModule.sep) && relative !== '..' && !pathModule.isAbsolute(relative))) {
      throw coded(Error, 'ERR_FS_CP_EINVAL', 'Cannot copy a directory into itself');
    }
    fs.mkdirSync(destination, { recursive: true, mode: Number(stat.mode) & 0o777 });
    for (const name of fs.readdirSync(source)) fs.cpSync(pathModule.join(source, name), pathModule.join(destination, name), config);
  } else {
    const targetExists = fs.lstatSync(destination, { throwIfNoEntry: false });
    if (targetExists && !config.force) {
      if (config.errorOnExist) throw coded(Error, 'ERR_FS_CP_EEXIST', `Target already exists: ${destination}`);
      return;
    }
    fs.mkdirSync(pathModule.dirname(destination), { recursive: true });
    if (stat.isSymbolicLink()) {
      if (targetExists) fs.unlinkSync(destination);
      let target = fs.readlinkSync(source);
      if (!config.verbatimSymlinks) target = pathModule.resolve(pathModule.dirname(source), target);
      fs.symlinkSync(target, destination);
    } else fs.copyFileSync(source, destination, config.mode ?? 0);
  }
  if (config.preserveTimestamps && !stat.isSymbolicLink()) fs.utimesSync(destination, stat.atime, stat.mtime);
};

const noResult = new Set(['writeFile', 'appendFile', 'access', 'copyFile', 'cp', 'rename', 'link', 'symlink', 'unlink', 'rm', 'rmdir', 'chmod', 'chown', 'lchown', 'utimes', 'truncate', 'ftruncate', 'fsync', 'fdatasync']);
for (const name of ['readFile', 'writeFile', 'appendFile', 'stat', 'lstat', 'fstat', 'readdir', 'access', 'mkdir', 'mkdtemp', 'realpath', 'readlink', 'copyFile', 'cp', 'rename', 'link', 'symlink', 'unlink', 'rm', 'rmdir', 'chmod', 'chown', 'lchown', 'utimes', 'truncate', 'ftruncate', 'fsync', 'fdatasync']) {
  fs[name] = (...args) => later(fs[name + 'Sync'], args.slice(0, -1), callback(args.at(-1)), noResult.has(name) ? () => [] : value => [value]);
}
fs.realpath.native = fs.realpath;
fs.realpathSync.native = fs.realpathSync;

class ReadStream extends Readable {
  constructor(path, value) {
    const config = options(value);
    super({ ...config, highWaterMark: config.highWaterMark ?? 64 * 1024, autoDestroy: config.autoClose !== false });
    this.path = path;
    this.flags = config.flags ?? 'r';
    this.mode = modeValue(config.mode);
    this.start = config.start;
    this.end = config.end ?? Infinity;
    if (this.start !== undefined) integer(this.start, 'start');
    if (this.end !== Infinity) integer(this.end, 'end', this.start ?? 0);
    this.pos = this.start;
    this.bytesRead = 0;
    this.autoClose = config.autoClose !== false;
    this._fs = config.fs ?? fs;
    this._handle = config.fd instanceof FileHandle ? config.fd : null;
    this.fd = this._handle ? this._handle.fd : config.fd ?? null;
    if (this.fd === null) pathValue(path);
    else integer(this.fd, 'fd', 0, 0x7fffffff);
  }
  get pending() { return this.fd === null; }
  _construct(done) {
    if (this.fd !== null) { done(); return; }
    this._fs.open(this.path, this.flags, this.mode, (error, fd) => {
      if (error) { done(error); return; }
      this.fd = fd; this.emit('open', fd); this.emit('ready'); done();
    });
  }
  _read(size) {
    if (this.destroyed) return;
    const position = this.pos ?? this.bytesRead;
    const length = Math.min(size, this.end - position + 1);
    if (length <= 0) { this.push(null); return; }
    const buffer = Buffer.allocUnsafe(length);
    this._fs.read(this.fd, buffer, 0, length, this.pos ?? null, (error, count) => {
      if (error) { this.destroy(error); return; }
      if (this.pos !== undefined) this.pos += count;
      this.bytesRead += count;
      this.push(count ? buffer.subarray(0, count) : null);
    });
  }
  _destroy(error, done) { closeStream(this, error, done); }
  close(cb) { if (cb) this.once('close', cb); this.autoClose = true; this.destroy(); }
}
class WriteStream extends Writable {
  constructor(path, value) {
    const config = options(value);
    super({ ...config, autoDestroy: config.autoClose !== false });
    this.path = path; this.flags = config.flags ?? 'w'; this.mode = modeValue(config.mode);
    this.start = config.start;
    if (this.start !== undefined) integer(this.start, 'start');
    this.pos = this.start; this.bytesWritten = 0;
    this.autoClose = config.autoClose !== false; this.flush = config.flush === true;
    this._fs = config.fs ?? fs;
    this._handle = config.fd instanceof FileHandle ? config.fd : null;
    this.fd = this._handle ? this._handle.fd : config.fd ?? null;
    if (this.fd === null) pathValue(path);
    else integer(this.fd, 'fd', 0, 0x7fffffff);
  }
  get pending() { return this.fd === null; }
  _construct(done) { ReadStream.prototype._construct.call(this, done); }
  _write(chunk, selectedEncoding, done) {
    const bytes = typeof chunk === 'string' ? Buffer.from(chunk, selectedEncoding) : chunk;
    let offset = 0;
    const write = () => this._fs.write(this.fd, bytes, offset, bytes.length - offset, this.pos ?? null, (error, count) => {
      if (error) { done(error); return; }
      if (!count && offset < bytes.length) { done(coded(Error, 'EIO', 'EIO: i/o error, write')); return; }
      offset += count; this.bytesWritten += count;
      if (this.pos !== undefined) this.pos += count;
      if (offset < bytes.length) write(); else done();
    });
    write();
  }
  _writev(chunks, done) { this._write(Buffer.concat(chunks.map(entry => entry.chunk)), 'buffer', done); }
  _final(done) { if (this.flush) this._fs.fsync(this.fd, done); else done(); }
  _destroy(error, done) { closeStream(this, error, done); }
  close(cb) { if (cb) this.once('close', cb); this.autoClose = true; this.end(); }
  destroySoon() { this.end(); }
}
function closeStream(stream, error, done) {
  if (!stream.autoClose || stream.fd === null) { done(error); return; }
  const fd = stream.fd; stream.fd = null;
  if (stream._handle) stream._handle.close().then(() => done(error), closeError => done(error || closeError));
  else stream._fs.close(fd, closeError => done(error || closeError));
}
fs.ReadStream = fs.FileReadStream = ReadStream;
fs.WriteStream = fs.FileWriteStream = WriteStream;
fs.createReadStream = (path, value) => new ReadStream(path, value);
fs.createWriteStream = (path, value) => new WriteStream(path, value);

class Dir {
  constructor(path, config) { this.path = path; this._entries = fs.readdirSync(path, { ...config, withFileTypes: true }); this._closed = false; }
  readSync() {
    if (this._closed) throw coded(Error, 'ERR_DIR_CLOSED', 'Directory handle was closed');
    return this._entries.shift() ?? null;
  }
  read(cb) {
    if (cb !== undefined) { later(() => this.readSync(), [], cb); return; }
    return Promise.resolve().then(() => this.readSync());
  }
  closeSync() {
    if (this._closed) throw coded(Error, 'ERR_DIR_CLOSED', 'Directory handle was closed');
    this._closed = true; this._entries = [];
  }
  close(cb) {
    if (cb !== undefined) { later(() => this.closeSync(), [], cb, () => []); return; }
    return Promise.resolve().then(() => this.closeSync());
  }
  async *[Symbol.asyncIterator]() {
    try { let entry; while ((entry = await this.read()) !== null) yield entry; }
    finally { if (!this._closed) await this.close(); }
  }
  [Symbol.asyncDispose]() { return this.close(); }
  [Symbol.dispose]() { this.closeSync(); }
}
fs.Dir = Dir;
fs.opendirSync = (path, value) => new Dir(pathValue(path), options(value));
fs.opendir = (...args) => later(fs.opendirSync, args.slice(0, -1), callback(args.at(-1)));

const promises = fs.promises = { constants };
for (const name of ['readFile', 'writeFile', 'appendFile', 'stat', 'lstat', 'readdir', 'access', 'mkdir', 'mkdtemp', 'realpath', 'readlink', 'copyFile', 'cp', 'rename', 'link', 'symlink', 'unlink', 'rm', 'rmdir', 'chmod', 'chown', 'lchown', 'utimes', 'truncate', 'opendir']) {
  promises[name] = (...args) => new Promise((resolve, reject) => fs[name](...args, (error, value) => error ? reject(error) : resolve(value)));
}
class FileHandle extends EventEmitter {
  constructor(fd) { super(); this._fd = fd; this._closing = null; }
  get fd() { return this._fd; }
  close() {
    if (this._closing) return this._closing;
    if (this._fd === -1) return Promise.resolve();
    const fd = this._fd; this._fd = -1;
    this._closing = new Promise((resolve, reject) => fs.close(fd, error => {
      if (error) { reject(error); return; }
      this.emit('close'); resolve();
    }));
    return this._closing;
  }
  _check(syscall) { if (this._fd === -1) throw Object.assign(coded(Error, 'EBADF', 'file closed'), { errno: -9, syscall }); }
  async read(...args) { this._check('read'); return fs.read[customPromisify](this._fd, ...args); }
  async write(...args) { this._check('write'); return fs.write[customPromisify](this._fd, ...args); }
  async readv(...args) { this._check('readv'); return fs.readv[customPromisify](this._fd, ...args); }
  async writev(...args) { this._check('writev'); return fs.writev[customPromisify](this._fd, ...args); }
  createReadStream(value) { this._check('read'); return new ReadStream(undefined, { ...options(value), fd: this }); }
  createWriteStream(value) { this._check('write'); return new WriteStream(undefined, { ...options(value), fd: this }); }
  readableWebStream(value = {}) { return Readable.toWeb(this.createReadStream({ autoClose: !!value.autoClose })); }
  [Symbol.asyncDispose]() { return this.close(); }
}
for (const [method, operation] of Object.entries({ readFile: 'readFile', writeFile: 'writeFile', appendFile: 'appendFile', stat: 'fstat', truncate: 'ftruncate', sync: 'fsync', datasync: 'fdatasync' })) {
  FileHandle.prototype[method] = async function (...args) {
    this._check(method);
    return new Promise((resolve, reject) => fs[operation](this._fd, ...args, (error, value) => error ? reject(error) : resolve(value)));
  };
}
promises.open = (path, flags, mode) => new Promise((resolve, reject) => fs.open(path, flags, mode, (error, fd) => error ? reject(error) : resolve(new FileHandle(fd))));
promises.readFile = (file, value) => file instanceof FileHandle ? file.readFile(value) : new Promise((resolve, reject) => fs.readFile(file, value, (error, data) => error ? reject(error) : resolve(data)));
for (const name of ['writeFile', 'appendFile']) {
  promises[name] = async (file, data, value) => {
    if (file instanceof FileHandle) return file[name](data, value);
    if (typeof data !== 'string' && !ArrayBuffer.isView(data) && data != null && (data[Symbol.asyncIterator] || data[Symbol.iterator])) {
      const config = options(value, { flag: name === 'appendFile' ? 'a' : 'w', mode: 0o666 });
      const handle = await promises.open(file, config.flag, config.mode);
      try { for await (const chunk of data) { abort(config.signal); await handle.writeFile(chunk, config); } }
      finally { await handle.close(); }
      return;
    }
    return new Promise((resolve, reject) => fs[name](file, data, value, error => error ? reject(error) : resolve()));
  };
}
