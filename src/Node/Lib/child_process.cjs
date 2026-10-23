'use strict';

const { EventEmitter } = require('events');
const { Readable, Writable } = require('stream');
const { Buffer } = require('buffer');
const { fileURLToPath } = require('url');
const host = __phasisNodeHost.child_process;
const signals = require('os').constants.signals;
const promisify = Symbol.for('nodejs.util.promisify.custom');

function failure(code, message, Constructor = TypeError) {
  return Object.assign(new Constructor(message), { code });
}
function unavailable(name) {
  throw failure('ERR_FEATURE_UNAVAILABLE_ON_PLATFORM', `${name} is unavailable through PHP process streams`, Error);
}
function text(value, name) {
  if (typeof value !== 'string') throw failure('ERR_INVALID_ARG_TYPE', `The "${name}" argument must be of type string`);
  if (value.includes('\0')) throw failure('ERR_INVALID_ARG_VALUE', `The argument '${name}' must be a string without null bytes`);
  return value;
}
function signalNumber(value = 'SIGTERM') {
  if (typeof value === 'number' && Number.isInteger(value) && value >= 0 && value <= 64) return value;
  const number = signals[String(value).toUpperCase()];
  if (number === undefined) throw failure('ERR_UNKNOWN_SIGNAL', `Unknown signal: ${value}`);
  return number;
}
function nativeError(value, file, args) {
  const error = Object.assign(new Error(value.message), value);
  if (file !== undefined) { error.path = file; error.spawnargs = args; }
  return error;
}
function call(name, ...args) {
  const result = host.call(name, ...args);
  if (result.error) throw nativeError(result.error);
  return result.value;
}
function normalize(file, args, options) {
  text(file, 'file');
  if (!file.length) throw failure('ERR_INVALID_ARG_VALUE', "The argument 'file' cannot be empty");
  if (args === undefined || args === null) args = [];
  else if (!Array.isArray(args)) { options = args; args = []; }
  if (options === undefined || options === null) options = {};
  if (typeof options !== 'object') throw failure('ERR_INVALID_ARG_TYPE', 'The "options" argument must be of type object');
  args = args.map((value, i) => text(String(value), `args[${i}]`));
  let cwd = options.cwd === undefined || options.cwd === null ? process.cwd() : options.cwd;
  if (cwd instanceof URL) cwd = fileURLToPath(cwd);
  if (Buffer.isBuffer(cwd)) cwd = cwd.toString();
  text(cwd, 'options.cwd');
  const env = {};
  const source = options.env === undefined || options.env === null ? process.env : options.env;
  for (const key in source) if (source[key] !== undefined) env[text(key, 'options.env')] = text(String(source[key]), `options.env.${key}`);
  for (const name of ['timeout', 'maxBuffer']) {
    if (options[name] !== undefined && (typeof options[name] !== 'number' || options[name] < 0 || Number.isNaN(options[name]))) {
      throw failure('ERR_OUT_OF_RANGE', `The value of "options.${name}" is out of range`, RangeError);
    }
  }
  if (options.detached) unavailable('Detached process sessions');
  if (options.signal !== undefined && !(options.signal instanceof AbortSignal)) throw failure('ERR_INVALID_ARG_TYPE', 'The "options.signal" property must be an instance of AbortSignal');
  if (options.uid !== undefined || options.gid !== undefined) unavailable('Child uid/gid');
  if (options.argv0 !== undefined && options.argv0 !== file) unavailable('Custom child argv0');
  let stdio = options.stdio === undefined ? 'pipe' : options.stdio;
  if (typeof stdio === 'string') stdio = [stdio, stdio, stdio];
  if (!Array.isArray(stdio)) throw failure('ERR_INVALID_ARG_VALUE', 'The argument stdio is invalid');
  if (stdio.length > 3 && stdio.slice(3).some((value) => value !== null && value !== undefined && value !== 'ignore')) unavailable('Additional child stdio and IPC');
  stdio = [0, 1, 2].map((fd) => {
    let value = stdio[fd];
    if (value === null || value === undefined) value = 'pipe';
    if (value === 'inherit') return fd;
    if (value && typeof value === 'object') value = value.fd;
    if (value === 'pipe' || value === 'overlapped' || value === 'ignore' || value === 0 || value === 1 || value === 2) return value;
    if (typeof value === 'number') unavailable('Inherited file descriptors other than standard streams');
    throw failure('ERR_INVALID_ARG_VALUE', `The argument stdio is invalid. Received ${value}`);
  });
  const originalFile = file;
  const originalArgs = args;
  if (options.shell) {
    const command = [file, ...args].join(' ');
    file = typeof options.shell === 'string' ? text(options.shell, 'options.shell') : '/bin/sh';
    args = ['-c', command];
  }
  let input = options.input;
  if (typeof input === 'string') input = Buffer.from(input, options.encoding && options.encoding !== 'buffer' ? options.encoding : 'utf8');
  else if (input !== undefined) {
    if (!ArrayBuffer.isView(input)) throw failure('ERR_INVALID_ARG_TYPE', 'The "options.input" property must be a string or an ArrayBufferView');
    input = Buffer.from(input.buffer, input.byteOffset, input.byteLength);
  }
  const encoding = options.encoding;
  if (encoding !== undefined && encoding !== null && encoding !== 'buffer' && !Buffer.isEncoding(encoding)) throw failure('ERR_UNKNOWN_ENCODING', `Unknown encoding: ${encoding}`);
  return {
    file, args, cwd, env, stdio, input, encoding,
    timeout: options.timeout === undefined ? 0 : options.timeout,
    maxBuffer: options.maxBuffer === undefined ? 1024 * 1024 : options.maxBuffer,
    killSignal: signalNumber(options.killSignal),
    signal: options.signal,
    originalFile, originalArgs,
  };
}
function decode(bytes, encoding) {
  if (bytes === null) return null;
  const buffer = Buffer.from(bytes);
  return encoding && encoding !== 'buffer' ? buffer.toString(encoding) : buffer;
}
function spawnSync(file, args, options) {
  const config = normalize(file, args, options);
  const native = host.call('spawnSync', config);
  let result;
  if (native.error) result = { pid: 0, output: null, stdout: null, stderr: null, status: null, signal: null, error: native.error };
  else {
    result = native.value;
    result.stdout = config.stdio[1] === 'pipe' || config.stdio[1] === 'overlapped' ? decode(result.stdout, config.encoding) : null;
    result.stderr = config.stdio[2] === 'pipe' || config.stdio[2] === 'overlapped' ? decode(result.stderr, config.encoding) : null;
    result.output = [null, result.stdout, result.stderr];
  }
  if (result.error) result.error = nativeError(result.error, config.file, config.args);
  return result;
}
function syncFailure(result, command) {
  if (result.error) return Object.assign(result.error, result);
  const error = new Error(`Command failed: ${command}${result.stderr && result.stderr.length ? `\n${result.stderr}` : ''}`);
  return Object.assign(error, result);
}
function execFileSync(file, args, options) {
  const result = spawnSync(file, args, options);
  if (result.error || result.status !== 0) throw syncFailure(result, file);
  return result.stdout;
}
function execSync(command, options) {
  text(command, 'command');
  const result = spawnSync(command, [], { ...options, shell: options && options.shell || true });
  if (result.error || result.status !== 0) throw syncFailure(result, command);
  return result.stdout;
}

class ChildReadable extends Readable {
  constructor(child, fd) { super({ autoDestroy: true }); this._child = child; this._fd = fd; }
  _read() { if (!this._child._closed) call('pause', this._child._id, this._fd, false); }
  _destroy(error, callback) {
    if (!this._child._closed) call('closePipe', this._child._id, this._fd);
    callback(error);
  }
  ref() { if (!this._child._closed) call('ref', this._child._id, true, this._fd); return this; }
  unref() { if (!this._child._closed) call('ref', this._child._id, false, this._fd); return this; }
}
class ChildWritable extends Writable {
  constructor(child) { super({ autoDestroy: true }); this._child = child; }
  _write(chunk, encoding, callback) {
    try { call('write', this._child._id, chunk, (error) => callback(error ? nativeError(error) : undefined)); }
    catch (error) { callback(error); }
  }
  _final(callback) {
    try { call('end', this._child._id); callback(); } catch (error) { callback(error); }
  }
  _destroy(error, callback) {
    if (!this._child._closed) call('closePipe', this._child._id, 0);
    callback(error);
  }
  ref() { if (!this._child._closed) call('ref', this._child._id, true, 0); return this; }
  unref() { if (!this._child._closed) call('ref', this._child._id, false, 0); return this; }
}
class ChildProcess extends EventEmitter {
  constructor() {
    super();
    this.connected = false;
    this.killed = false;
    this.exitCode = null;
    this.signalCode = null;
    this.spawnargs = [];
    this.spawnfile = undefined;
    this.pid = undefined;
    this.stdin = null;
    this.stdout = null;
    this.stderr = null;
    this.stdio = [null, null, null];
    this._id = null;
    this._closed = false;
  }
  spawn(options) {
    if (!options || typeof options.file !== 'string') throw failure('ERR_INVALID_ARG_TYPE', 'The options.file property must be a string');
    const config = normalize(options.file, (options.args || []).slice(1), options);
    this._start(config);
    return this._spawnError ? this._spawnError.errno : 0;
  }
  _start(config) {
    this.spawnfile = config.file;
    this.spawnargs = [config.file, ...config.args];
    const result = host.call('spawn', config, (event, value) => this._onNative(event, value));
    if (result.error) {
      this._spawnError = nativeError(result.error, config.file, config.args);
      this._closed = true;
      process.nextTick(() => { this.emit('error', this._spawnError); this.emit('close', this._spawnError.errno, null); });
      return;
    }
    this._id = result.value.id;
    this.pid = result.value.pid;
    for (const fd of result.value.pipes) this.stdio[fd] = fd === 0 ? new ChildWritable(this) : new ChildReadable(this, fd);
    [this.stdin, this.stdout, this.stderr] = this.stdio;
    if (config.timeout) {
      this._timer = setTimeout(() => { this.kill(config.killSignal); }, config.timeout);
      this._timer.unref();
    }
    if (config.signal !== undefined) {
      const signal = config.signal;
      if (!(signal instanceof AbortSignal)) throw failure('ERR_INVALID_ARG_TYPE', 'The "options.signal" property must be an instance of AbortSignal');
      const abort = () => {
        if (this.kill(config.killSignal)) {
          const error = Object.assign(new Error('The operation was aborted'), { name: 'AbortError', code: 'ABORT_ERR', cause: signal.reason });
          this.emit('error', error);
        }
      };
      if (signal.aborted) process.nextTick(abort);
      else { signal.addEventListener('abort', abort, { once: true }); this.once('close', () => signal.removeEventListener('abort', abort)); }
    }
  }
  _onNative(event, value) {
    if (event === 'spawn') this.emit('spawn');
    else if (event === 'data') {
      const stream = this.stdio[value.fd];
      if (stream && !stream.destroyed && !stream.push(Buffer.from(value.data))) call('pause', this._id, value.fd, true);
    } else if (event === 'end') {
      const stream = this.stdio[value];
      if (stream && !stream.destroyed) stream.push(null);
    } else if (event === 'exit') {
      this.exitCode = value.code;
      this.signalCode = value.signal;
      if (this._timer) clearTimeout(this._timer);
      if (this.stdin) this.stdin.destroy();
      this.emit('exit', value.code, value.signal);
      process.nextTick(() => {
        for (const stream of [this.stdout, this.stderr]) if (stream && !stream.readableFlowing) stream.resume();
      });
    } else if (event === 'close') {
      this._closed = true;
      process.nextTick(() => this.emit('close', value.code, value.signal));
    }
  }
  kill(signal) {
    const number = signalNumber(signal);
    if (this._id === null || this._closed) return false;
    const killed = call('kill', this._id, number);
    if (killed) this.killed = true;
    return killed;
  }
  ref() { if (!this._closed && this._id !== null) call('ref', this._id, true); return this; }
  unref() { if (!this._closed && this._id !== null) call('ref', this._id, false); return this; }
  [Symbol.dispose]() { this.kill(); }
}
function spawn(file, args, options) {
  const config = normalize(file, args, options);
  const child = new ChildProcess();
  child._start(config);
  return child;
}
function execFile(file, args, options, callback) {
  if (typeof args === 'function') { callback = args; args = []; options = {}; }
  else if (!Array.isArray(args)) { callback = options; options = args; args = []; }
  else if (typeof options === 'function') { callback = options; options = {}; }
  options = options || {};
  if (callback !== undefined && typeof callback !== 'function') throw failure('ERR_INVALID_ARG_TYPE', 'The "callback" argument must be of type function');
  const config = normalize(file, args, options);
  const child = new ChildProcess();
  child._start(config);
  const chunks = [[], []];
  const lengths = [0, 0];
  let error;
  for (const [index, stream] of [child.stdout, child.stderr].entries()) if (stream) stream.on('data', (chunk) => {
    const remaining = config.maxBuffer - lengths[index];
    lengths[index] += chunk.length;
    if (chunk.length > remaining && !error) {
      error = failure('ERR_CHILD_PROCESS_STDIO_MAXBUFFER', `${index === 0 ? 'stdout' : 'stderr'} maxBuffer length exceeded`, RangeError);
      chunks[index].push(chunk.subarray(0, Math.max(0, remaining)));
      child.kill(config.killSignal);
    } else if (!error) chunks[index].push(chunk);
  });
  child.on('error', (value) => { error = value; });
  child.once('close', (code, signal) => {
    const encoding = options.encoding === undefined ? 'utf8' : options.encoding;
    const stdout = decode(Buffer.concat(chunks[0]), encoding);
    const stderr = decode(Buffer.concat(chunks[1]), encoding);
    if (!error && (code !== 0 || signal !== null)) error = Object.assign(new Error(`Command failed: ${[file, ...args].join(' ')}${stderr.length ? `\n${stderr}` : ''}`), { code, signal, killed: child.killed });
    if (error) { error.cmd = [file, ...args].join(' '); error.stdout = stdout; error.stderr = stderr; }
    if (callback) callback(error || null, stdout, stderr);
  });
  return child;
}
function exec(command, options, callback) {
  if (typeof options === 'function') { callback = options; options = {}; }
  text(command, 'command');
  return execFile(command, [], { ...options, shell: options && options.shell || true }, callback);
}
for (const method of [exec, execFile]) Object.defineProperty(method, promisify, { value: (...args) => {
  let child;
  const promise = new Promise((resolve, reject) => {
    child = method(...args, (error, stdout, stderr) => error ? reject(error) : resolve({ stdout, stderr }));
  });
  promise.child = child;
  return promise;
}, configurable: true });
function fork() { unavailable('IPC-backed child_process.fork'); }

module.exports = { ChildProcess, spawn, spawnSync, exec, execSync, execFile, execFileSync, fork };
