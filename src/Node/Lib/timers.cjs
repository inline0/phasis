'use strict';

const host = __phasisNodeHost;
const handles = new Map();
let sequence = 0;
function check(callback) {
  if (typeof callback !== 'function') throw Object.assign(new TypeError('The callback argument must be a function'), { code: 'ERR_INVALID_ARG_TYPE' });
}
function delayValue(value) {
  value = Number(value);
  return value >= 1 && value <= 2147483647 ? Math.trunc(value) : 1;
}
class Timeout {
  constructor(callback, delay, args, repeating = false) {
    this._id = ++sequence;
    this._callback = callback;
    this._delay = delayValue(delay);
    this._args = args;
    this._repeating = repeating;
    this._referenced = true;
    this._nativeId = null;
    this._destroyed = false;
    this._generation = 0;
    this._schedule();
  }
  _schedule() {
    const generation = ++this._generation;
    this._destroyed = false;
    handles.set(this._id, this);
    this._nativeId = host.timer(this._repeating ? 'interval' : 'timeout', () => {
      if (!this._repeating) { this._nativeId = null; handles.delete(this._id); this._destroyed = true; }
      this._callback.apply(this, this._args);
    }, this._delay);
    host.timer('ref', this._nativeId, this._referenced);
  }
  ref() { this._referenced = true; if (this._nativeId !== null) host.timer('ref', this._nativeId, true); return this; }
  unref() { this._referenced = false; if (this._nativeId !== null) host.timer('ref', this._nativeId, false); return this; }
  hasRef() { return this._referenced; }
  refresh() { if (this._nativeId !== null) host.timer('clear', this._nativeId); this._schedule(); return this; }
  close() { if (this._nativeId !== null) host.timer('clear', this._nativeId); this._nativeId = null; this._destroyed = true; handles.delete(this._id); return this; }
  [Symbol.toPrimitive]() { return this._id; }
  [Symbol.dispose]() { this.close(); }
}
class Immediate {
  constructor(callback, args) {
    this._id = ++sequence;
    this._referenced = true;
    this._destroyed = false;
    handles.set(this._id, this);
    this._nativeId = host.timer('immediate', () => {
      this._nativeId = null;
      this._destroyed = true;
      handles.delete(this._id);
      callback.apply(this, args);
    });
  }
  ref() { this._referenced = true; if (this._nativeId !== null) host.timer('ref', this._nativeId, true); return this; }
  unref() { this._referenced = false; if (this._nativeId !== null) host.timer('ref', this._nativeId, false); return this; }
  hasRef() { return this._referenced; }
  [Symbol.dispose]() { clearImmediate(this); }
}
function clearTimeout(handle) {
  if (typeof handle === 'number' || typeof handle === 'string') handle = handles.get(Number(handle));
  if (handle instanceof Timeout) handle.close();
}
function clearImmediate(handle) {
  if (!(handle instanceof Immediate)) return;
  if (handle._nativeId !== null) host.timer('clear', handle._nativeId);
  handle._nativeId = null; handle._destroyed = true; handles.delete(handle._id);
}
function setTimeout(callback, delay, ...args) { check(callback); return new Timeout(callback, delay, args); }
function setInterval(callback, delay, ...args) { check(callback); return new Timeout(callback, delay, args, true); }
function setImmediate(callback, ...args) { check(callback); return new Immediate(callback, args); }
module.exports = { setTimeout, clearTimeout, setInterval, clearInterval: clearTimeout, setImmediate, clearImmediate };
