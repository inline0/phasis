'use strict';

const channels = new Set();
const states = new WeakMap();
const addListener = EventTarget.prototype.addEventListener;
const removeListener = EventTarget.prototype.removeEventListener;
const dispatch = EventTarget.prototype.dispatchEvent;
const clone = structuredClone;
const schedule = setTimeout;
const cancel = clearTimeout;

function stateOf(channel) {
  const state = states.get(channel);
  if (!state) throw new TypeError('Value of this must be of type BroadcastChannel');
  return state;
}

class BroadcastChannel extends EventTarget {
  constructor(name) {
    if (arguments.length === 0) throw new TypeError('BroadcastChannel requires a name');
    super();
    if (typeof name === 'symbol') throw new TypeError('Cannot convert a Symbol value to a string');
    states.set(this, { name: String(name), closed: false, referenced: true, pending: new Set(), handlers: new Map() });
    channels.add(this);
  }

  get name() { return stateOf(this).name; }

  postMessage(message) {
    const state = stateOf(this);
    if (arguments.length === 0) throw new TypeError('BroadcastChannel.postMessage requires a message');
    if (state.closed) throw new DOMException('BroadcastChannel is closed.', 'InvalidStateError');
    const snapshot = clone(message);
    for (const channel of channels) {
      const recipient = stateOf(channel);
      if (channel === this || recipient.closed || recipient.name !== state.name) continue;
      const data = clone(snapshot);
      const timer = schedule(() => {
        recipient.pending.delete(timer);
        if (recipient.closed) return;
        dispatch.call(channel, new MessageEvent('message', { data }));
      }, 0);
      recipient.pending.add(timer);
      if (!recipient.referenced) timer.unref();
    }
  }

  close() {
    const state = stateOf(this);
    state.closed = true;
    channels.delete(this);
    for (const timer of state.pending) cancel(timer);
    state.pending.clear();
  }

  ref() {
    const state = stateOf(this);
    state.referenced = true;
    for (const timer of state.pending) timer.ref();
    return this;
  }

  unref() {
    const state = stateOf(this);
    state.referenced = false;
    for (const timer of state.pending) timer.unref();
    return this;
  }
}

for (const type of ['message', 'messageerror']) {
  Object.defineProperty(BroadcastChannel.prototype, 'on' + type, {
    enumerable: true, configurable: true,
    get() { return stateOf(this).handlers.get(type)?.callback ?? null; },
    set(callback) {
      const state = stateOf(this);
      const previous = state.handlers.get(type);
      if (typeof callback !== 'function') {
        if (previous) removeListener.call(this, type, previous.listener);
        state.handlers.delete(type);
      } else if (previous) {
        previous.callback = callback;
      } else {
        const entry = { callback, listener: event => entry.callback.call(this, event) };
        state.handlers.set(type, entry);
        addListener.call(this, type, entry.listener);
      }
    },
  });
}
Object.defineProperty(BroadcastChannel.prototype, Symbol.toStringTag, { value: 'BroadcastChannel', configurable: true });
module.exports = { BroadcastChannel, closeAll() { for (const channel of channels) channel.close(); } };
