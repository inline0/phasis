const W = WebAssembly;
const show = (label, f) => { try { const r = f(); console.log(label, 'ok', String(r)); } catch (e) { console.log(label, e.constructor.name + ': ' + e.message); } };
const d = (o, k) => { const x = Object.getOwnPropertyDescriptor(o, k); return JSON.stringify({ w: x.writable, e: x.enumerable, c: x.configurable, v: typeof x.value, g: typeof x.get }); };
console.log(typeof W, Object.prototype.toString.call(W), d(globalThis, 'WebAssembly'), d(W, 'Memory'), d(W, Symbol.toStringTag));
console.log(Object.getOwnPropertyNames(W.Memory.prototype).join(','), W.Memory.length, W.Memory.name);
console.log(d(W.Memory.prototype, 'grow'), d(W.Memory.prototype, 'buffer'), d(W.Memory.prototype, Symbol.toStringTag), d(W.Memory, 'prototype'), d(W.Memory.prototype, 'constructor'));
console.log(W.Memory.prototype.grow.length, W.Memory.prototype.grow.name, Object.getOwnPropertyDescriptor(W.Memory.prototype, 'buffer').get.name, Object.keys(W.Memory.prototype).join(','));
const m = new W.Memory({ shared: true, initial: 1, maximum: 2 });
const b = m.buffer;
console.log(b.constructor.name, b.byteLength, Object.isFrozen(b), Object.isExtensible(b), m.buffer === b, b.growable, b.maxByteLength);
console.log(m.grow(1), b.byteLength, m.buffer === b, m.buffer.byteLength);
new Uint8Array(b)[5] = 7; console.log(new Uint8Array(m.buffer)[5]);
new Uint8Array(m.buffer)[70000] = 9; console.log(new Uint8Array(m.buffer)[70000], b.byteLength);
const n = new W.Memory({ initial: 1 }); const nb = n.buffer;
console.log(nb.constructor.name, Object.isFrozen(nb), nb.resizable, nb.maxByteLength);
new Uint8Array(nb)[3] = 4;
console.log(n.grow(0), nb.detached, nb.byteLength, n.buffer === nb, new Uint8Array(n.buffer)[3]);
const nb2 = n.buffer; n.grow(1); console.log(nb2.detached, nb2.byteLength, n.buffer.byteLength, new Uint8Array(n.buffer)[3]);
for (const [i, desc] of [undefined, {}, { initial: -1 }, { initial: 1, maximum: 0 }, { shared: true, initial: 1 }, { initial: 65537 }, { initial: 1, maximum: 65537 }, { initial: 'abc' }, { initial: 1.7 }, { initial: 2 ** 32 }, { initial: Infinity }, { initial: 1, maximum: -1 }, { initial: 1, maximum: undefined }, { initial: 1, shared: 'yes', maximum: 1 }, { initial: 0 }, 5].entries()) {
  show('desc' + i, () => { const x = new W.Memory(desc); return x.buffer.constructor.name + ' ' + x.buffer.byteLength; });
}
show('call', () => W.Memory({ initial: 1 }));
for (const v of [-1, NaN, undefined, '1', 2 ** 32, 70000]) show('grow ' + String(v), () => new W.Memory({ initial: 1 }).grow(v));
show('max', () => new W.Memory({ initial: 2, maximum: 2 }).grow(1));
show('transfer', () => n.buffer.transfer());
show('transferFixed', () => n.buffer.transferToFixedLength());
try { structuredClone(n.buffer, { transfer: [n.buffer] }); } catch (e) { console.log('clone-transfer', e.name, n.buffer.detached); }
show('clone', () => structuredClone(n.buffer).byteLength);
show('recv-buffer', () => Object.getOwnPropertyDescriptor(W.Memory.prototype, 'buffer').get.call({}));
show('recv-grow', () => W.Memory.prototype.grow.call({}, 1));
show('resizable', () => n.buffer.resizable + ' ' + m.buffer.growable);
show('sloppy-set', () => { const s = m.buffer; s.x = 1; return s.x; });
show('strict-set', () => { 'use strict'; const s = m.buffer; s.x = 1; return s.x; });
show('tag', () => Object.prototype.toString.call(m));
for (const name of ['CompileError', 'LinkError', 'RuntimeError']) {
  const E = W[name];
  console.log(name, d(W, name), E.length, E.name, Object.getPrototypeOf(E) === Error, Object.getPrototypeOf(E.prototype) === Error.prototype, E.prototype.name, JSON.stringify(E.prototype.message), String(new E('m')), E('x').message, new E('m', { cause: 1 }).cause, new E() instanceof Error, Object.prototype.toString.call(new E()));
}
class Sub extends W.Memory {}
const sub = new Sub({ initial: 1 });
console.log(sub instanceof Sub, sub instanceof W.Memory, sub.buffer.byteLength);
