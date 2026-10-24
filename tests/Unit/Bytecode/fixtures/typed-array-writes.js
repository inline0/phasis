function strictStore(view, key, value) { 'use strict'; return view[key] = value; }
function sloppyStore(view, key, value) { return view[key] = value; }
function numberText(value) { return Object.is(value, -0) ? '-0' : String(value); }
const typedArrayWriteResult = [];
const constructors = [Int8Array, Uint8Array, Uint8ClampedArray, Int16Array, Uint16Array,
  Int32Array, Uint32Array, Float16Array, Float32Array, Float64Array, BigInt64Array, BigUint64Array];
for (const Constructor of constructors) {
  const bigint = Constructor === BigInt64Array || Constructor === BigUint64Array;
  const values = bigint ? [1n, -1n, 18446744073709551623n] : [1.5, -1, 257, NaN, Infinity, -Infinity, -0];
  for (const value of values) {
    const view = new Constructor(2);
    const returned = strictStore(view, 0, value);
    typedArrayWriteResult.push([Constructor.name, numberText(value), Object.is(returned, value),
      numberText(view[0]), numberText(view[1])]);
  }
}
for (const store of [strictStore, sloppyStore]) {
  for (const key of [-0, '0', '-0', 1, 3, 1.5, -1, NaN, Infinity, 9007199254740991, '01']) {
    const view = new Uint8Array(2);
    const effects = [];
    const value = {valueOf() { effects.push('value'); return 13; }};
    const returned = store(view, key, value);
    typedArrayWriteResult.push([store.name, numberText(key), returned === value, effects,
      numberText(view[0]), numberText(view[1]), Object.prototype.hasOwnProperty.call(view, key)]);
  }
  for (const key of [0, 9]) {
    const effects = [];
    let error;
    try { store(new Uint8Array(1), key, {valueOf() { effects.push('value'); throw new RangeError('value'); }}); }
    catch (caught) { error = caught.name; }
    typedArrayWriteResult.push(['throw', store.name, key, effects, error]);
    try { store(new BigInt64Array(1), key, 3); }
    catch (caught) { typedArrayWriteResult.push(['bigint-number', key, caught.name]); }
    try { store(new Uint8Array(1), key, 3n); }
    catch (caught) { typedArrayWriteResult.push(['number-bigint', key, caught.name]); }
  }
  const effects = [];
  const view = new Uint8Array(2);
  const key = {[Symbol.toPrimitive](hint) { effects.push('key:' + hint); return '1'; }};
  store(view, key, {valueOf() { effects.push('value'); return 19; }});
  typedArrayWriteResult.push(['key-order', effects, view[1]]);
  const buffer = new ArrayBuffer(4);
  const detached = new Uint8Array(buffer);
  let conversions = 0;
  const value = {valueOf() { conversions++; structuredClone(buffer, {transfer:[buffer]}); return 27; }};
  const result = store(detached, 0, value);
  typedArrayWriteResult.push(['detach', result === value, conversions, buffer.byteLength, numberText(detached[0])]);
  const resizable = new ArrayBuffer(4, {maxByteLength:8});
  const fixed = new Uint8Array(resizable, 0, 4);
  store(fixed, 0, {valueOf() { resizable.resize(1); return 23; }});
  typedArrayWriteResult.push(['shrink', fixed.length, numberText(fixed[0]), new Uint8Array(resizable)[0]]);
  const tracking = new Uint8Array(resizable);
  store(tracking, 5, {valueOf() { resizable.resize(8); return 29; }});
  typedArrayWriteResult.push(['grow', tracking.length, tracking[5]]);
}
for (const store of [strictStore, sloppyStore]) {
  const buffer = new ArrayBuffer(8, {maxByteLength: 12});
  const offset = new Uint8Array(buffer, 2, 3);
  store(offset, 0, 257.75);
  store(offset, 2, -257.75);
  typedArrayWriteResult.push(['offset-numbers', store.name, Array.from(new Uint8Array(buffer))]);
  buffer.resize(1);
  store(offset, 0, 31);
  typedArrayWriteResult.push(['number-oob', store.name, Array.from(new Uint8Array(buffer))]);
  buffer.resize(12);
  store(offset, 1, 511);
  const tracking = new Uint8Array(buffer);
  store(tracking, 11, -1);
  typedArrayWriteResult.push(['number-regrown', store.name, Array.from(tracking)]);
  structuredClone(buffer, {transfer: [buffer]});
  store(offset, 0, 73);
  typedArrayWriteResult.push(['number-detached', store.name, offset.length]);
}
