function indexedRead(view, key) { return [view[key]]; }
function readText(value) { return typeof value + ':' + (Object.is(value, -0) ? '-0' : String(value)); }
const typedArrayReadResult = [];
const constructors = [Int8Array, Uint8Array, Uint8ClampedArray, Int16Array, Uint16Array,
  Int32Array, Uint32Array, Float16Array, Float32Array, Float64Array, BigInt64Array, BigUint64Array];
for (const Constructor of constructors) {
  const bigint = Constructor === BigInt64Array || Constructor === BigUint64Array;
  const view = new Constructor(bigint ? [1n, -1n, 257n] : [7, -0, 257]);
  for (const key of [-0, 0, 1, 2, 3, 0.5, -1, NaN, Infinity, -Infinity, 9007199254740991,
    1e16, 1e20, 1e21, 9223372036854775807]) {
    typedArrayReadResult.push([Constructor.name, readText(key), readText(indexedRead(view, key)[0])]);
  }
}

const effects = [];
const view = new Uint8Array([17, 19]);
const prototype = Object.create(Uint8Array.prototype);
Object.setPrototypeOf(view, prototype);
for (const name of ['0', '2', '-0', 'NaN', 'Infinity']) {
  Object.defineProperty(prototype, name, {get() { effects.push('invalid:' + name); return 99; }});
}
Object.defineProperty(prototype, '01', {get() { effects.push('ordinary:' + (this === view)); return 31; }});
prototype['1000000000000000000000'] = 37;
const symbol = Symbol('value');
prototype[symbol] = 41;
for (const key of [0, -0, 2, NaN, Infinity, '0', '-0', '01', '1000000000000000000000', symbol]) {
  typedArrayReadResult.push(['prototype', readText(key), readText(indexedRead(view, key)[0])]);
}
const objectKey = {[Symbol.toPrimitive](hint) { effects.push('key:' + hint); return '1'; }};
typedArrayReadResult.push(['object-key', readText(indexedRead(view, objectKey)[0]), effects]);

const proxyEffects = [];
const proxy = new Proxy(view, {get(target, key, receiver) {
  proxyEffects.push(String(key));
  return key === '0' ? 53 : Reflect.get(target, key, receiver);
}});
typedArrayReadResult.push(['proxy', indexedRead(proxy, 0)[0], proxyEffects]);

const buffer = new ArrayBuffer(8, {maxByteLength: 16});
new Uint16Array(buffer).set([11, 22, 33, 44]);
const fixed = new Uint16Array(buffer, 2, 2);
const tracking = new Uint16Array(buffer, 2);
typedArrayReadResult.push(['before-resize', indexedRead(fixed, 0)[0], indexedRead(fixed, 1)[0],
  indexedRead(tracking, 2)[0]]);
buffer.resize(3);
typedArrayReadResult.push(['shrunk', fixed.length, tracking.length, readText(indexedRead(fixed, 0)[0]),
  readText(indexedRead(tracking, 0)[0])]);
buffer.resize(12);
typedArrayReadResult.push(['grown', fixed.length, tracking.length, indexedRead(fixed, 0)[0],
  indexedRead(fixed, 1)[0], indexedRead(tracking, 2)[0]]);
const shrinkingKey = {[Symbol.toPrimitive]() { buffer.resize(2); return '0'; }};
typedArrayReadResult.push(['coercion-resize', readText(indexedRead(fixed, shrinkingKey)[0]),
  readText(indexedRead(tracking, 0)[0])]);

const detachedBuffer = new ArrayBuffer(2);
const detached = new Uint8Array(detachedBuffer);
detached[0] = 61;
const detachingKey = {[Symbol.toPrimitive]() {
  structuredClone(detachedBuffer, {transfer: [detachedBuffer]});
  return '0';
}};
typedArrayReadResult.push(['coercion-detach', readText(indexedRead(detached, detachingKey)[0]),
  readText(indexedRead(detached, 0)[0]), readText(indexedRead(detached, '0')[0])]);
