'use strict';

const EventEmitter = require('events');
const host = __phasisNodeHost;
const process = globalThis.process;
Object.setPrototypeOf(process, EventEmitter.prototype);
EventEmitter.call(process);

process.cwd = () => host.cwd();
process.chdir = (path) => host.chdir(path);
process.nextTick = (callback, ...args) => host.nextTick(callback, ...args);
process.hrtime = (previous) => {
  const now = host.hrtime();
  if (previous === undefined) return now;
  let seconds = now[0] - previous[0];
  let nanoseconds = now[1] - previous[1];
  if (nanoseconds < 0) { seconds--; nanoseconds += 1e9; }
  return [seconds, nanoseconds];
};
process.hrtime.bigint = () => host.hrtimeBigInt();
process.uptime = () => host.uptime();
process.memoryUsage = () => host.memoryUsage();
process.memoryUsage.rss = () => host.memoryUsage().rss;
process.pid = host.pid();
process.ppid = host.ppid();
process.execArgv = [];
for (const [name, fd] of [['stdin', 0], ['stdout', 1], ['stderr', 2]]) {
  Object.defineProperty(process, name, { configurable: true, enumerable: true, get() {
    const streams = require('./stdio.cjs');
    const stream = fd === 0 ? new streams.ReadStream(fd) : new streams.WriteStream(fd);
    Object.defineProperty(process, name, { value: stream, configurable: true, enumerable: true });
    return stream;
  } });
}
const builtinNames = new Set(host.builtinNames());
process.getBuiltinModule = (name) => {
  if (typeof name !== 'string') throw Object.assign(new TypeError('The id argument must be a string'), { code: 'ERR_INVALID_ARG_TYPE' });
  const canonical = name.startsWith('node:') ? name.slice(5) : name;
  return builtinNames.has(canonical) ? host.builtin(canonical) : undefined;
};
process.emitWarning = (warning, type, code) => {
  if (!(warning instanceof Error)) {
    const error = new Error(String(warning));
    if (typeof type === 'object' && type !== null) {
      error.name = type.type || 'Warning';
      if (type.code !== undefined) error.code = type.code;
    } else {
      error.name = type || 'Warning';
      if (code !== undefined) error.code = code;
    }
    warning = error;
  }
  process.nextTick(() => {
    if (!process.emit('warning', warning)) console.warn(warning.toString());
  });
};
module.exports = process;
