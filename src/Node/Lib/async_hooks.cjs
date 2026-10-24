'use strict';

const host = __phasisNodeHost;
let sequence = 1;
let activeId = 1;
let triggerId = 0;
const hooks = new Set();

class AsyncLocalStorage {
  constructor(options = {}) {
    this._key = {};
    this._defaultValue = options.defaultValue;
    this._name = options.name || '';
  }
  get name() { return this._name; }
  getStore() {
    return host.contextHas(this._key) ? host.contextGet(this._key) : this._defaultValue;
  }
  run(store, callback, ...args) {
    return host.contextRun(this._key, store, callback, ...args);
  }
  exit(callback, ...args) { return this.run(undefined, callback, ...args); }
  enterWith(store) { host.contextSet(this._key, store); }
  disable() {
    host.contextDelete(this._key);
    this._key = {};
  }
  static bind(callback) {
    const snapshot = host.contextCapture();
    return function (...args) { return host.contextRunSnapshot(snapshot, callback, this, ...args); };
  }
  static snapshot() {
    const snapshot = host.contextCapture();
    return (callback, ...args) => host.contextRunSnapshot(snapshot, callback, undefined, ...args);
  }
}

function invokeHook(name, ...args) {
  for (const hook of hooks) {
    if (typeof hook[name] === 'function') hook[name](...args);
  }
}

class AsyncResource {
  constructor(type, options = {}) {
    if (typeof type !== 'string') throw new TypeError('The type argument must be a string');
    if (typeof options === 'number') options = { triggerAsyncId: options };
    this._id = ++sequence;
    this._trigger = options.triggerAsyncId === undefined ? activeId : options.triggerAsyncId;
    this._snapshot = host.contextCapture();
    this._destroyed = false;
    invokeHook('init', this._id, type, this._trigger, this);
  }
  asyncId() { return this._id; }
  triggerAsyncId() { return this._trigger; }
  runInAsyncScope(callback, thisArg, ...args) {
    const previousId = activeId;
    const previousTrigger = triggerId;
    activeId = this._id;
    triggerId = this._trigger;
    invokeHook('before', this._id);
    try { return host.contextRunSnapshot(this._snapshot, callback, thisArg, ...args); }
    finally {
      invokeHook('after', this._id);
      activeId = previousId;
      triggerId = previousTrigger;
    }
  }
  emitDestroy() {
    if (!this._destroyed) { this._destroyed = true; invokeHook('destroy', this._id); }
    return this;
  }
  bind(callback, thisArg) {
    const resource = this;
    return function (...args) { return resource.runInAsyncScope(callback, thisArg === undefined ? this : thisArg, ...args); };
  }
  static bind(callback, type = callback.name || 'bound-anonymous-fn', thisArg) {
    return new AsyncResource(type).bind(callback, thisArg);
  }
}

module.exports = {
  AsyncLocalStorage, AsyncResource,
  executionAsyncId: () => activeId,
  triggerAsyncId: () => triggerId,
  createHook(callbacks) {
    return { enable() { hooks.add(callbacks); return this; }, disable() { hooks.delete(callbacks); return this; } };
  },
};
