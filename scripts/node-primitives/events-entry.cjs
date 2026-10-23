'use strict';

const Base = require('./node_modules/events/events.js');
const capture = Symbol('captureRejections');
const rejection = Symbol.for('nodejs.rejection');
const errorMonitor = Symbol('events.errorMonitor');
const targetLimits = new WeakMap();
let defaultCapture = false;
function EventEmitter(options) {
  Base.call(this);
  const value = options?.captureRejections;
  if (value !== undefined && typeof value !== 'boolean') throw new TypeError('The "options.captureRejections" property must be boolean.');
  this[capture] = value ?? defaultCapture;
}
Object.setPrototypeOf(EventEmitter, Base);
EventEmitter.prototype = Base.prototype;
Object.defineProperty(EventEmitter.prototype, 'constructor', { value: EventEmitter, writable: true, configurable: true });
const emit = Base.prototype.emit;
EventEmitter.prototype.emit = function (name, ...args) {
  if (name === 'error') emit.call(this, errorMonitor, ...args);
  if (!this[capture]) return emit.call(this, name, ...args);
  const listeners = this.rawListeners(name);
  if (!listeners.length) return emit.call(this, name, ...args);
  for (const listener of listeners) {
    const result = Reflect.apply(listener, this, args);
    if (result !== null && (typeof result === 'object' || typeof result === 'function')) {
      try {
        if (typeof result.then === 'function') result.then(undefined, (error) => queueMicrotask(() => {
          if (typeof this[rejection] === 'function') this[rejection](error, name, ...args);
          else {
            const previous = this[capture];
            this[capture] = false;
            try { this.emit('error', error); } finally { this[capture] = previous; }
          }
        }));
      } catch (error) { this.emit('error', error); }
    }
  }
  return true;
};
const listenerCount = Base.prototype.listenerCount;
EventEmitter.prototype.listenerCount = function (name, listener) {
  return listener === undefined ? listenerCount.call(this, name) : this.rawListeners(name).filter((item) => item === listener || item.listener === listener).length;
};
Object.defineProperty(EventEmitter, 'captureRejections', {
  enumerable: true,
  get() { return defaultCapture; },
  set(value) { if (typeof value !== 'boolean') throw new TypeError('captureRejections must be boolean.'); defaultCapture = value; },
});
function abortError(signal) {
  const error = new Error('The operation was aborted', { cause: signal?.reason });
  error.name = 'AbortError';
  error.code = 'ABORT_ERR';
  return error;
}
function validateSignal(signal) {
  if (signal !== undefined && (!signal || typeof signal.aborted !== 'boolean' || typeof signal.addEventListener !== 'function')) throw new TypeError('The "signal" argument must be an AbortSignal.');
}
function listen(target, name, listener, once = false) {
  if (typeof target.on === 'function') {
    target[once ? 'once' : 'on'](name, listener);
    return () => target.removeListener(name, listener);
  }
  if (typeof target.addEventListener === 'function') {
    target.addEventListener(name, listener, { once });
    return () => target.removeEventListener(name, listener);
  }
  throw new TypeError('The emitter must be an EventEmitter or EventTarget.');
}
function once(emitter, name, options = {}) {
  const signal = options.signal;
  validateSignal(signal);
  return new Promise((resolve, reject) => {
    if (signal?.aborted) { reject(abortError(signal)); return; }
    const removers = [];
    const cleanup = () => { for (const remove of removers) remove(); };
    removers.push(listen(emitter, name, (...args) => { cleanup(); resolve(args); }, true));
    if (name !== 'error' && typeof emitter.on === 'function') removers.push(listen(emitter, 'error', (error) => { cleanup(); reject(error); }, true));
    if (signal) removers.push(listen(signal, 'abort', () => { cleanup(); reject(abortError(signal)); }, true));
  });
}
function on(emitter, name, options = {}) {
  const signal = options.signal;
  validateSignal(signal);
  if (signal?.aborted) throw abortError(signal);
  const high = options.highWaterMark ?? options.highWatermark ?? Number.MAX_SAFE_INTEGER;
  const low = options.lowWaterMark ?? options.lowWatermark ?? 1;
  if (!Number.isInteger(high) || high < 1 || !Number.isInteger(low) || low < 1) throw new RangeError('Invalid event iterator water mark.');
  const values = [];
  const waiters = [];
  const removers = [];
  let error;
  let done = false;
  let paused = false;
  function cleanup() { for (const remove of removers) remove(); }
  function finish(reason) {
    done = true;
    error = reason;
    cleanup();
    while (waiters.length) {
      const waiter = waiters.shift();
      if (error) { waiter.reject(error); error = undefined; }
      else waiter.resolve({ value: undefined, done: true });
    }
  }
  removers.push(listen(emitter, name, (...args) => {
    if (waiters.length) waiters.shift().resolve({ value: args, done: false });
    else {
      values.push(args);
      if (!paused && values.length > high) { paused = true; emitter.pause(); }
    }
  }));
  if (name !== 'error' && typeof emitter.on === 'function') removers.push(listen(emitter, 'error', finish));
  if (signal) removers.push(listen(signal, 'abort', () => finish(abortError(signal)), true));
  for (const event of options.close || []) removers.push(listen(emitter, event, () => finish(), true));
  return {
    next() {
      if (values.length) {
        const value = values.shift();
        if (paused && values.length < low) { paused = false; emitter.resume(); }
        return Promise.resolve({ value, done: false });
      }
      if (error) { const reason = error; error = undefined; return Promise.reject(reason); }
      if (done) return Promise.resolve({ value: undefined, done: true });
      return new Promise((resolve, reject) => waiters.push({ resolve, reject }));
    },
    return() { finish(); return Promise.resolve({ value: undefined, done: true }); },
    throw(reason) { if (!(reason instanceof Error)) throw new TypeError('The "error" argument must be an Error.'); finish(reason); return Promise.resolve({ value: undefined, done: true }); },
    [Symbol.asyncIterator]() { return this; },
  };
}
function addAbortListener(signal, listener) {
  validateSignal(signal);
  if (!signal) throw new TypeError('The "signal" argument must be an AbortSignal.');
  if (typeof listener !== 'function') throw new TypeError('The "listener" argument must be a function.');
  let dispose;
  if (signal.aborted) queueMicrotask(() => listener());
  else dispose = listen(signal, 'abort', listener, true);
  return { [Symbol.dispose]() { dispose?.(); dispose = undefined; } };
}
EventEmitter.EventEmitter = EventEmitter;
EventEmitter.captureRejectionSymbol = rejection;
EventEmitter.errorMonitor = errorMonitor;
EventEmitter.once = once;
EventEmitter.on = on;
EventEmitter.addAbortListener = addAbortListener;
EventEmitter.getEventListeners = (emitter, name) => {
  if (typeof emitter.listeners === 'function') return emitter.listeners(name);
  if (typeof globalThis.__phasisNodeHost?.eventListeners === 'function') return globalThis.__phasisNodeHost.eventListeners(emitter, name);
  throw new TypeError('EventTarget listener inspection requires the host listener adapter.');
};
EventEmitter.getMaxListeners = (emitter) => typeof emitter.getMaxListeners === 'function' ? emitter.getMaxListeners() : targetLimits.get(emitter) ?? EventEmitter.defaultMaxListeners;
EventEmitter.setMaxListeners = (count, ...targets) => {
  if (typeof count !== 'number' || count < 0 || Number.isNaN(count)) throw new RangeError('The "n" argument is out of range.');
  if (!targets.length) EventEmitter.defaultMaxListeners = count;
  for (const target of targets) {
    if (typeof target.setMaxListeners === 'function') target.setMaxListeners(count);
    else if (typeof target.addEventListener === 'function') targetLimits.set(target, count);
    else throw new TypeError('The target must be an EventEmitter or EventTarget.');
  }
};
class EventEmitterAsyncResource extends EventEmitter {
  constructor(options = {}) {
    if (typeof options === 'string') options = { name: options };
    super(options);
    const { AsyncResource } = require('async_hooks');
    this.asyncResource = new AsyncResource(options.name || new.target.name, options);
  }
  emit(name, ...args) { return this.asyncResource.runInAsyncScope(super.emit, this, name, ...args); }
  emitDestroy() { this.asyncResource.emitDestroy(); }
  get asyncId() { return this.asyncResource.asyncId(); }
  get triggerAsyncId() { return this.asyncResource.triggerAsyncId(); }
}
EventEmitter.EventEmitterAsyncResource = EventEmitterAsyncResource;
module.exports = EventEmitter;
